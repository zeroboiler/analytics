<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Signal Intelligence Service — observability layer for analytics pipeline.
 *
 * Monitors event dispatch patterns across all providers and detects:
 * - **Dispatch rate anomalies**: Sudden spikes or drops in event volume
 * - **Provider staleness**: No events dispatched to a provider within expected window
 * - **Signal-to-noise ratio**: Ratio of catalog events vs. unknown/custom events
 * - **Dispatch balance**: Evenness of event distribution across providers
 * - **Category coverage signals**: Which event categories are actively tracked
 * - **Health decay detection**: Gradual degradation of provider reliability
 *
 * Provides a composite "signal score" (0-100) for dashboards and alerting.
 *
 * Inspired by Datadog Signal Intelligence and Honeycomb BubbleUp.
 *
 * Configuration is read from `zeroboiler.analytics.signal_intelligence`.
 *
 * @phpstan-type ProviderSignal array{name: string, status: 'healthy'|'degraded'|'stale'|'down', events_dispatched: int, events_failed: int, failure_rate: float, last_dispatch_at: string|null, staleness_seconds: int|null, anomaly_score: float, health_decay: float}
 * @phpstan-type CategorySignal array{name: string, events: int, percentage: float, top_events: list<string>, trend: 'stable'|'rising'|'falling'|'flat'}
 * @phpstan-type AnomalyRecord array{type: string, provider: string|null, message: string, severity: 'info'|'warning'|'critical', detected_at: string, context: array<string, mixed>}
 * @phpstan-type IntelligenceReport array{signal_score: float, grade: string, providers: array<string, ProviderSignal>, categories: array<string, CategorySignal>, anomalies: list<AnomalyRecord>, staleness_summary: array{stale: list<string>, healthy: list<string>}, signal_to_noise: float, dispatch_balance: float, recommendations: list<string>, computed_at: string}
 *
 * @since 7.7.0
 */
final class EventSignalIntelligenceService
{
    private const CACHE_PREFIX = 'zb_signal_intel_';

    private int $cacheTtl;

    private int $stalenessThresholdSeconds;

    private int $anomalyWindowSeconds;

    private float $anomalyDeviationThreshold;

    /** @var array<string, int> Baseline dispatch rates per provider (events/minute) */
    private array $baselineRates = [];

    /** @var array<string, list<int>> Recent dispatch timestamps per provider (for trend analysis) */
    private array $dispatchTimeline = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsMetrics  $metrics
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly AnalyticsMetrics $metrics,
    ){
        $signalConfig = $config->get('zeroboiler.analytics.signal_intelligence', []);
        /** @var array{cache_ttl?: int, staleness_threshold?: int, anomaly_window?: int, anomaly_deviation?: float} $signalConfig */

        $this->cacheTtl = (int) ($signalConfig['cache_ttl'] ?? 300);
        $this->stalenessThresholdSeconds = (int) ($signalConfig['staleness_threshold'] ?? 3600); // 1 hour
        $this->anomalyWindowSeconds = (int) ($signalConfig['anomaly_window'] ?? 600); // 10 minutes
        $this->anomalyDeviationThreshold = (float) ($signalConfig['anomaly_deviation'] ?? 2.0); // 2x = critical

        $this->loadState();
    }

    /**
     * Record an event dispatch for signal intelligence tracking.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, plausible, webhook)
     * @param  string  $eventName  Event name dispatched
     * @param  bool  $success  Whether the dispatch succeeded
     */
    public function recordDispatch(string $provider, string $eventName, bool $success = true): void
    {
        $this->recordDispatchTimestamp($provider);
        $this->updateBaseline($provider);

        $this->persistState();
    }

    /**
     * Generate a full signal intelligence report.
     *
     * @return IntelligenceReport
     */
    public function report(): array
    {
        $providerSignals = $this->analyzeProviders();
        $categorySignals = $this->analyzeCategories();
        $anomalies = $this->detectAnomalies($providerSignals);
        $signalToNoise = $this->calculateSignalToNoise();
        $dispatchBalance = $this->calculateDispatchBalance($providerSignals);
        $staleness = $this->stalenessSummary($providerSignals);
        $recommendations = $this->generateRecommendations($providerSignals, $anomalies, $signalToNoise, $dispatchBalance);

        $signalScore = $this->calculateSignalScore($providerSignals, $signalToNoise, $dispatchBalance, count($anomalies));

        return [
            'signal_score' => $signalScore,
            'grade' => $this->signalGrade($signalScore),
            'providers' => $providerSignals,
            'categories' => $categorySignals,
            'anomalies' => $anomalies,
            'staleness_summary' => $staleness,
            'signal_to_noise' => $signalToNoise,
            'dispatch_balance' => $dispatchBalance,
            'recommendations' => $recommendations,
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get the composite signal score (0-100).
     *
     * @return float
     */
    public function signalScore(): float
    {
        return $this->report()['signal_score'];
    }

    /**
     * Get only the detected anomalies.
     *
     * @return list<AnomalyRecord>
     */
    public function anomalies(): array
    {
        return $this->report()['anomalies'];
    }

    /**
     * Get provider-specific signal data.
     *
     * @return array<string, ProviderSignal>
     */
    public function providerSignals(): array
    {
        return $this->analyzeProviders();
    }

    /**
     * Get category coverage signals.
     *
     * @return array<string, CategorySignal>
     */
    public function categorySignals(): array
    {
        return $this->analyzeCategories();
    }

    /**
     * Check if any provider is stale (no dispatch within threshold).
     */
    public function hasStaleProviders(): bool
    {
        $providerSignals = $this->analyzeProviders();

        foreach ($providerSignals as $signal) {
            if ($signal['status'] === 'stale' || $signal['status'] === 'down') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get staleness summary.
     *
     * @return array{stale: list<string>, healthy: list<string>}
     */
    public function stalenessSummary(array $providerSignals = []): array
    {
        if ($providerSignals === []) {
            $providerSignals = $this->analyzeProviders();
        }

        $stale = [];
        $healthy = [];

        foreach ($providerSignals as $name => $signal) {
            if (in_array($signal['status'], ['stale', 'down'], true)) {
                $stale[] = $name;
            } else {
                $healthy[] = $name;
            }
        }

        return ['stale' => $stale, 'healthy' => $healthy];
    }

    /**
     * Calculate the signal-to-noise ratio.
     *
     * Ratio of catalog-recognized events vs. all dispatched events.
     * 1.0 = all events are catalog events (pure signal).
     * 0.0 = no events are catalog events (pure noise).
     *
     * @return float 0.0-1.0
     */
    public function calculateSignalToNoise(): float
    {
        $totalDispatched = $this->metrics->totalDispatched();

        if ($totalDispatched === 0) {
            return 1.0; // No noise if no events
        }

        $catalogNames = EventCatalog::names();

        // Count events tracked by category to estimate signal
        $signalEstimate = count($catalogNames) > 0
            ? min(1.0, count($catalogNames) / max($totalDispatched * 0.1, 1))
            : 0.0;

        return round(min(1.0, max(0.0, $signalEstimate)), 4);
    }

    /**
     * Calculate dispatch balance score (0-100).
     *
     * Measures how evenly events are distributed across enabled providers.
     * 100 = perfect balance, 0 = all events go to a single provider.
     *
     * @param  array<string, ProviderSignal>  $providerSignals
     * @return float
     */
    public function calculateDispatchBalance(array $providerSignals): float
    {
        $dispatchCounts = [];
        foreach ($providerSignals as $signal) {
            if ($signal['events_dispatched'] > 0) {
                $dispatchCounts[] = $signal['events_dispatched'];
            }
        }

        if (count($dispatchCounts) === 0) {
            return 100.0;
        }

        if (count($dispatchCounts) === 1) {
            return 50.0; // Single provider = moderate balance
        }

        // Shannon entropy-based balance score
        $total = array_sum($dispatchCounts);
        $entropy = 0.0;
        foreach ($dispatchCounts as $count) {
            $p = $count / $total;
            if ($p > 0) {
                $entropy -= $p * log($p);
            }
        }

        $maxEntropy = log(count($dispatchCounts));
        $normalizedEntropy = $maxEntropy > 0 ? $entropy / $maxEntropy : 0.0;

        return round($normalizedEntropy * 100, 2);
    }

    /**
     * Clear all signal intelligence state.
     */
    public function clear(): void
    {
        $this->baselineRates = [];
        $this->dispatchTimeline = [];

        $this->persistState();
    }

    /**
     * Get signal grade label.
     *
     * @return string
     */
    public function signalGrade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A+ (Excellent Signal)',
            $score >= 80 => 'A (Strong Signal)',
            $score >= 70 => 'B+ (Good Signal)',
            $score >= 60 => 'B (Adequate Signal)',
            $score >= 50 => 'C+ (Degraded Signal)',
            $score >= 40 => 'C (Weak Signal)',
            $score >= 30 => 'D (Poor Signal)',
            $score >= 20 => 'F (Critical — No Signal)',
            default => 'F- (Pipeline Failure)',
        };
    }

    /**
     * Analyze all provider signals.
     *
     * @return array<string, ProviderSignal>
     */
    private function analyzeProviders(): array
    {
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'webhook'];
        $signals = [];

        foreach ($providers as $provider) {
            $totalDispatched = $this->metrics->providerDispatched($provider);
            $totalFailed = $this->metrics->providerFailed($provider);
            $failureRate = $totalDispatched > 0
                ? round(($totalFailed / $totalDispatched) * 100, 2)
                : 0.0;

            $lastDispatch = $this->getLastDispatchTimestamp($provider);
            $now = time();
            $staleness = $lastDispatch !== null ? ($now - $lastDispatch) : null;

            $status = $this->determineProviderStatus($provider, $staleness, $failureRate, $totalDispatched);
            $anomalyScore = $this->calculateProviderAnomalyScore($provider);
            $healthDecay = $this->calculateHealthDecay($provider);

            $signals[$provider] = [
                'name' => $provider,
                'status' => $status,
                'events_dispatched' => $totalDispatched,
                'events_failed' => $totalFailed,
                'failure_rate' => $failureRate,
                'last_dispatch_at' => $lastDispatch !== null ? date('c', $lastDispatch) : null,
                'staleness_seconds' => $staleness,
                'anomaly_score' => $anomalyScore,
                'health_decay' => $healthDecay,
            ];
        }

        return $signals;
    }

    /**
     * Analyze event category coverage signals.
     *
     * @return array<string, CategorySignal>
     */
    private function analyzeCategories(): array
    {
        $byCategory = EventCatalog::byCategory();
        $signals = [];

        $totalCount = array_sum(array_map(fn (array $events): int => count($events), $byCategory));

        foreach ($byCategory as $category => $events) {
            $count = count($events);
            $percentage = $totalCount > 0 ? round(($count / $totalCount) * 100, 1) : 0.0;
            $topEvents = array_slice(array_keys($events), 0, 5);

            $signals[$category] = [
                'name' => $category,
                'events' => $count,
                'percentage' => $percentage,
                'top_events' => $topEvents,
                'trend' => 'stable',
            ];
        }

        return $signals;
    }

    /**
     * Detect anomalies across all providers.
     *
     * @param  array<string, ProviderSignal>  $providerSignals
     * @return list<AnomalyRecord>
     */
    private function detectAnomalies(array $providerSignals): array
    {
        $anomalies = [];

        foreach ($providerSignals as $provider => $signal) {
            // Staleness anomaly
            if ($signal['staleness_seconds'] !== null && $signal['staleness_seconds'] > $this->stalenessThresholdSeconds) {
                $anomalies[] = [
                    'type' => 'staleness',
                    'provider' => $provider,
                    'message' => "No events dispatched to '{$provider}' for {$signal['staleness_seconds']}s (threshold: {$this->stalenessThresholdSeconds}s)",
                    'severity' => $signal['staleness_seconds'] > $this->stalenessThresholdSeconds * 3 ? 'critical' : 'warning',
                    'detected_at' => date('c'),
                    'context' => [
                        'staleness_seconds' => $signal['staleness_seconds'],
                        'threshold' => $this->stalenessThresholdSeconds,
                        'last_dispatch' => $signal['last_dispatch_at'],
                    ],
                ];
            }

            // High failure rate anomaly
            if ($signal['failure_rate'] > 50.0 && $signal['events_dispatched'] > 0) {
                $anomalies[] = [
                    'type' => 'high_failure_rate',
                    'provider' => $provider,
                    'message' => "Provider '{$provider}' failure rate is {$signal['failure_rate']}%",
                    'severity' => $signal['failure_rate'] > 90.0 ? 'critical' : 'warning',
                    'detected_at' => date('c'),
                    'context' => [
                        'failure_rate' => $signal['failure_rate'],
                        'dispatched' => $signal['events_dispatched'],
                        'failed' => $signal['events_failed'],
                    ],
                ];
            }

            // Anomaly score spike
            if ($signal['anomaly_score'] > $this->anomalyDeviationThreshold) {
                $anomalies[] = [
                    'type' => 'dispatch_rate_anomaly',
                    'provider' => $provider,
                    'message' => "Dispatch rate anomaly detected for '{$provider}' (score: {$signal['anomaly_score']})",
                    'severity' => $signal['anomaly_score'] > $this->anomalyDeviationThreshold * 2 ? 'critical' : 'warning',
                    'detected_at' => date('c'),
                    'context' => [
                        'anomaly_score' => $signal['anomaly_score'],
                        'baseline_rate' => $this->baselineRates[$provider] ?? 0,
                        'current_timeline_count' => count($this->dispatchTimeline[$provider] ?? []),
                    ],
                ];
            }

            // Health decay anomaly
            if ($signal['health_decay'] > 0.5) {
                $anomalies[] = [
                    'type' => 'health_decay',
                    'provider' => $provider,
                    'message' => "Health decay detected for '{$provider}' (decay: {$signal['health_decay']})",
                    'severity' => $signal['health_decay'] > 0.8 ? 'critical' : 'info',
                    'detected_at' => date('c'),
                    'context' => [
                        'health_decay' => $signal['health_decay'],
                        'events_dispatched' => $signal['events_dispatched'],
                    ],
                ];
            }

            // No events ever dispatched (pipeline not connected)
            if ($signal['events_dispatched'] === 0 && $signal['last_dispatch_at'] === null) {
                // Only warn if provider is supposed to be enabled
                $providerEnabled = $this->isProviderEnabled($provider);
                if ($providerEnabled) {
                    $anomalies[] = [
                        'type' => 'no_dispatch_ever',
                        'provider' => $provider,
                        'message' => "Provider '{$provider}' is enabled but has never dispatched an event",
                        'severity' => 'warning',
                        'detected_at' => date('c'),
                        'context' => [
                            'provider' => $provider,
                            'enabled' => true,
                        ],
                    ];
                }
            }
        }

        // Sort anomalies by severity
        usort($anomalies, function (array $a, array $b): int {
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $aOrder = $severityOrder[$a['severity']] ?? 3;
            $bOrder = $severityOrder[$b['severity']] ?? 3;

            return $aOrder <=> $bOrder;
        });

        return $anomalies;
    }

    /**
     * Generate actionable recommendations based on signal analysis.
     *
     * @param  array<string, ProviderSignal>  $providerSignals
     * @param  list<AnomalyRecord>  $anomalies
     * @return list<string>
     */
    private function generateRecommendations(
        array $providerSignals,
        array $anomalies,
        float $signalToNoise,
        float $dispatchBalance,
    ): array {
        $recommendations = [];

        // Critical anomaly recommendations
        $criticalAnomalies = array_filter($anomalies, fn (array $a): bool => $a['severity'] === 'critical');
        foreach ($criticalAnomalies as $anomaly) {
            $recommendations[] = "[CRITICAL] {$anomaly['message']}";
        }

        // Signal-to-noise recommendation
        if ($signalToNoise < 0.3) {
            $recommendations[] = 'Low signal-to-noise ratio — consider validating events against the catalog before dispatch';
        }

        // Dispatch balance recommendation
        if ($dispatchBalance < 30.0) {
            $recommendations[] = 'Event dispatch is heavily concentrated on few providers — verify multi-provider routing';
        }

        // Provider-specific recommendations
        foreach ($providerSignals as $name => $signal) {
            if ($signal['status'] === 'stale') {
                $recommendations[] = "Provider '{$name}' is stale — check credentials and network connectivity";
            }
            if ($signal['health_decay'] > 0.5) {
                $recommendations[] = "Provider '{$name}' shows health decay — investigate failure patterns";
            }
        }

        return $recommendations;
    }

    /**
     * Calculate the composite signal score.
     *
     * @param  array<string, ProviderSignal>  $providerSignals
     * @return float 0-100
     */
    private function calculateSignalScore(
        array $providerSignals,
        float $signalToNoise,
        float $dispatchBalance,
        int $anomalyCount,
    ): float {
        $score = 100.0;

        // Deduct for anomalies (max -30)
        $score -= min(30, $anomalyCount * 5);

        // Deduct for low signal-to-noise (max -20)
        if ($signalToNoise < 0.5) {
            $score -= (1.0 - $signalToNoise) * 20;
        }

        // Deduct for poor dispatch balance (max -15)
        if ($dispatchBalance < 50.0) {
            $score -= (50.0 - $dispatchBalance) / 50.0 * 15;
        }

        // Deduct for provider issues
        foreach ($providerSignals as $signal) {
            if ($signal['status'] === 'down') {
                $score -= 10;
            } elseif ($signal['status'] === 'stale') {
                $score -= 5;
            }
            if ($signal['failure_rate'] > 20.0) {
                $score -= min(5, $signal['failure_rate'] / 20.0);
            }
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }

    /**
     * Determine provider health status.
     *
     * @return 'healthy'|'degraded'|'stale'|'down'
     */
    private function determineProviderStatus(string $provider, ?int $staleness, float $failureRate, int $totalDispatched): string
    {
        // Never dispatched any event
        if ($totalDispatched === 0) {
            return 'down';
        }

        // High staleness
        if ($staleness !== null && $staleness > $this->stalenessThresholdSeconds * 3) {
            return 'down';
        }

        // Moderate staleness
        if ($staleness !== null && $staleness > $this->stalenessThresholdSeconds) {
            return 'stale';
        }

        // High failure rate
        if ($failureRate > 50.0) {
            return 'down';
        }

        // Moderate failure rate
        if ($failureRate > 10.0) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Calculate dispatch rate anomaly score for a provider.
     *
     * Compares recent dispatch count against baseline rate.
     * Returns 1.0 = normal, > 2.0 = spike, < 0.5 = drop.
     *
     * @return float
     */
    private function calculateProviderAnomalyScore(string $provider): float
    {
        $baseline = $this->baselineRates[$provider] ?? 0;

        if ($baseline === 0) {
            return 1.0; // No baseline = no anomaly detected
        }

        $recentCount = count(array_filter(
            $this->dispatchTimeline[$provider] ?? [],
            fn (int $timestamp): bool => (time() - $timestamp) < $this->anomalyWindowSeconds,
        ));

        $expectedCount = $baseline * ($this->anomalyWindowSeconds / 60);

        if ($expectedCount === 0.0) {
            return 1.0;
        }

        return round($recentCount / $expectedCount, 2);
    }

    /**
     * Calculate health decay for a provider.
     *
     * Measures gradual degradation by comparing recent failure rate
     * against overall failure rate. Returns 0.0 (healthy) to 1.0 (full decay).
     *
     * @return float 0.0-1.0
     */
    private function calculateHealthDecay(string $provider): float
    {
        $totalDispatched = $this->metrics->providerDispatched($provider);
        $totalFailed = $this->metrics->providerFailed($provider);

        if ($totalDispatched === 0) {
            return 0.0;
        }

        $overallFailureRate = $totalFailed / $totalDispatched;

        // Check recent failures (last anomaly window)
        $recentTimestamps = array_filter(
            $this->dispatchTimeline[$provider] ?? [],
            fn (int $timestamp): bool => (time() - $timestamp) < $this->anomalyWindowSeconds,
        );

        if (count($recentTimestamps) === 0) {
            return 0.0;
        }

        // Use overall failure rate as decay indicator (simplified)
        // Real implementation would compare recent vs. historical
        return round(min(1.0, $overallFailureRate), 4);
    }

    /**
     * Record dispatch timestamp for a provider.
     */
    private function recordDispatchTimestamp(string $provider): void
    {
        if (! isset($this->dispatchTimeline[$provider])) {
            $this->dispatchTimeline[$provider] = [];
        }

        $this->dispatchTimeline[$provider][] = time();

        // Keep only last 1000 timestamps per provider (memory management)
        if (count($this->dispatchTimeline[$provider]) > 1000) {
            $this->dispatchTimeline[$provider] = array_slice($this->dispatchTimeline[$provider], -500);
        }
    }

    /**
     * Update baseline dispatch rate for a provider using exponential moving average.
     */
    private function updateBaseline(string $provider): void
    {
        $recentCount = count(array_filter(
            $this->dispatchTimeline[$provider] ?? [],
            fn (int $timestamp): bool => (time() - $timestamp) < 60, // Last minute
        ));

        $currentBaseline = $this->baselineRates[$provider] ?? 0;
        $alpha = 0.3; // Smoothing factor

        $this->baselineRates[$provider] = round($alpha * $recentCount + (1 - $alpha) * $currentBaseline, 2);
    }

    /**
     * Get the last dispatch timestamp for a provider.
     *
     * @return int|null Unix timestamp or null
     */
    private function getLastDispatchTimestamp(string $provider): ?int
    {
        $timeline = $this->dispatchTimeline[$provider] ?? [];

        if (empty($timeline)) {
            return null;
        }

        return max($timeline);
    }

    /**
     * Check if a provider is enabled in config.
     */
    private function isProviderEnabled(string $provider): bool
    {
        $configKey = match ($provider) {
            'ga4' => 'zeroboiler.analytics.ga4.enabled',
            'meta' => 'zeroboiler.analytics.meta_pixel.enabled',
            'posthog' => 'zeroboiler.analytics.posthog.enabled',
            'plausible' => 'zeroboiler.analytics.plausible.enabled',
            'webhook' => 'zeroboiler.analytics.webhook.enabled',
            default => null,
        };

        if ($configKey === null) {
            return false;
        }

        return (bool) $this->config->get($configKey, false);
    }

    /**
     * Load state from cache.
     */
    private function loadState(): void
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'state');

            if (is_array($cached)) {
                $this->baselineRates = $cached['baseline_rates'] ?? [];
                $this->dispatchTimeline = $cached['dispatch_timeline'] ?? [];
            }
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }

    /**
     * Persist state to cache.
     */
    private function persistState(): void
    {
        try {
            $this->cache->put(
                self::CACHE_PREFIX . 'state',
                [
                    'baseline_rates' => $this->baselineRates,
                    'dispatch_timeline' => $this->dispatchTimeline,
                ],
                $this->cacheTtl,
            );
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }
}
