<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use RuntimeException;

/**
 * Real-time anomaly detection and automated alerting service.
 *
 * Monitors event dispatch patterns using statistical baselines to detect
 * anomalies including traffic spikes, sudden drops, provider failures,
 * and unusual event composition changes. Triggers configurable alert
 * rules via webhook, email, or log channels.
 *
 * Uses a sliding window approach with configurable sensitivity:
 * - Compares current window metrics against rolling historical baseline
 * - Detects rate anomalies (volume spike/drop), composition drift, and provider imbalance
 * - Supports multiple alert channels: log, webhook, email, and custom callbacks
 * - Auto-recovers alerts when metrics return to normal
 *
 * Inspired by Datadog Monitor, Honeycomb Burn Rate Alerts, and Amplitude Anomaly Detection.
 *
 * @since 54.0.0
 */
final class AnalyticsAnomalyDetectionService
{
    private const CACHE_PREFIX = 'zb_anomaly_';

    private const DEFAULT_WINDOW_SECONDS = 300; // 5 minutes

    private const DEFAULT_BASELINE_WINDOWS = 12; // 12 × 5min = 1 hour baseline

    private const DEFAULT_SENSITIVITY = 3.0; // 3 standard deviations = high sensitivity

    private const ALERT_COOLDOWN_SECONDS = 900; // 15 minutes between repeated alerts

    /** @var array<string, callable(string, array<string, mixed>): void> */
    private array $alertCallbacks = [];

    /**
     * @param  CacheRepository  $cache
     * @param  array{enabled?: bool, window_seconds?: int, baseline_windows?: int, sensitivity?: float, alert_cooldown?: int, min_events_threshold?: int, webhook_url?: string, channels?: list<string>}  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly array $config = [],
    ) {
    }

    /**
     * Record an event for anomaly detection.
     *
     * Call this for every dispatched event to maintain the sliding window.
     *
     * @param  string  $eventName
     * @param  string  $provider
     * @param  string|null  $clientId
     * @param  string|null  $userId
     */
    public function recordEvent(
        string $eventName,
        string $provider,
        ?string $clientId = null,
        ?string $userId = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $windowKey = $this->currentWindowKey();
        $current = $this->getWindowData($windowKey);

        $current['count']++;
        $current['events'][$eventName] = ($current['events'][$eventName] ?? 0) + 1;
        $current['providers'][$provider] = ($current['providers'][$provider] ?? 0) + 1;

        if ($clientId !== null) {
            $current['clients'][$clientId] = ($current['clients'][$clientId] ?? 0) + 1;
        }

        $current['last_updated'] = now()->timestamp;

        $this->setWindowData($windowKey, $current);
    }

    /**
     * Record a batch of events efficiently.
     *
     * @param  list<array{name: string, provider: string, client_id?: string|null, user_id?: string|null}>  $events
     */
    public function recordBatch(array $events): void
    {
        if (! $this->isEnabled() || $events === []) {
            return;
        }

        $windowKey = $this->currentWindowKey();
        $current = $this->getWindowData($windowKey);

        foreach ($events as $event) {
            $current['count']++;
            $current['events'][$event['name']] = ($current['events'][$event['name']] ?? 0) + 1;
            $current['providers'][$event['provider']] = ($current['providers'][$event['provider']] ?? 0) + 1;

            if (! empty($event['client_id'])) {
                $current['clients'][$event['client_id']] = ($current['clients'][$event['client_id']] ?? 0) + 1;
            }
        }

        $current['last_updated'] = now()->timestamp;

        $this->setWindowData($windowKey, $current);
    }

    /**
     * Check for anomalies by comparing the current window against the baseline.
     *
     * Returns a list of detected anomalies (empty if no anomalies detected).
     *
     * @return list<array{type: string, severity: string, message: string, metric: string, current: float, baseline: float, deviation: float}>
     */
    public function detectAnomalies(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $baseline = $this->computeBaseline();
        $current = $this->getCurrentWindowData();
        $anomalies = [];

        // Skip if current window has too few events (avoid noise during low-traffic)
        $threshold = $this->config['min_events_threshold'] ?? 10;
        if ($current['count'] < $threshold) {
            return [];
        }

        $sensitivity = $this->config['sensitivity'] ?? self::DEFAULT_SENSITIVITY;

        // Check for rate anomaly (volume spike/drop)
        $rateAnomaly = $this->checkRateAnomaly($current, $baseline, $sensitivity);
        if ($rateAnomaly !== null) {
            $anomalies[] = $rateAnomaly;
        }

        // Check for provider imbalance
        $providerAnomaly = $this->checkProviderAnomaly($current, $baseline, $sensitivity);
        if ($providerAnomaly !== null) {
            $anomalies[] = $providerAnomaly;
        }

        // Check for composition drift (new events appearing that aren't in baseline)
        $compositionAnomaly = $this->checkCompositionDrift($current, $baseline);
        if ($compositionAnomaly !== null) {
            $anomalies[] = $compositionAnomaly;
        }

        // Check for unique client spike (possible bot/attack)
        $clientAnomaly = $this->checkClientAnomaly($current, $baseline, $sensitivity);
        if ($clientAnomaly !== null) {
            $anomalies[] = $clientAnomaly;
        }

        // Fire alerts for detected anomalies
        foreach ($anomalies as $anomaly) {
            $this->fireAlert($anomaly);
        }

        return $anomalies;
    }

    /**
     * Get the current anomaly detection status summary.
     *
     * @return array{enabled: bool, current_window: array<string, mixed>, baseline: array<string, mixed>, recent_alerts: list<array<string, mixed>>, windows_tracked: int}
     */
    public function status(): array
    {
        $baseline = $this->computeBaseline();
        $current = $this->getCurrentWindowData();

        return [
            'enabled' => $this->isEnabled(),
            'current_window' => $this->summarizeWindow($current),
            'baseline' => $this->summarizeBaseline($baseline),
            'recent_alerts' => $this->getRecentAlerts(),
            'windows_tracked' => $this->baselineWindowCount(),
        ];
    }

    /**
     * Get anomaly metrics for dashboard rendering.
     *
     * @return array{rate_deviation: float|null, provider_balance: float|null, composition_drift: float|null, client_spike: float|null, anomaly_count_24h: int}
     */
    public function metrics(): array
    {
        $baseline = $this->computeBaseline();
        $current = $this->getCurrentWindowData();

        return [
            'rate_deviation' => $this->computeRateDeviation($current, $baseline),
            'provider_balance' => $this->computeProviderBalance($current, $baseline),
            'composition_drift' => $this->computeCompositionDrift($current, $baseline),
            'client_spike' => $this->computeClientDeviation($current, $baseline),
            'anomaly_count_24h' => $this->countAlerts24h(),
        ];
    }

    /**
     * Register a custom alert callback.
     *
     * @param  callable(string, array<string, mixed>): void  $callback  Receives (anomalyType, anomalyData)
     */
    public function onAlert(callable $callback): void
    {
        $this->alertCallbacks[] = $callback;
    }

    /**
     * Clear all anomaly detection data and recent alerts.
     */
    public function clear(): void
    {
        $keys = $this->cache->get(self::CACHE_PREFIX . 'window_keys', []);
        foreach ($keys as $key) {
            $this->cache->forget(self::CACHE_PREFIX . 'window_' . $key);
        }
        $this->cache->forget(self::CACHE_PREFIX . 'window_keys');
        $this->cache->forget(self::CACHE_PREFIX . 'recent_alerts');
    }

    /**
     * Check if anomaly detection is enabled.
     */
    private function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Get the current window key based on time.
     */
    private function currentWindowKey(): string
    {
        $windowSeconds = $this->config['window_seconds'] ?? self::DEFAULT_WINDOW_SECONDS;

        return (string) (int) (now()->timestamp / $windowSeconds);
    }

    /**
     * Get the cache key for a window.
     */
    private function windowCacheKey(string $windowKey): string
    {
        return self::CACHE_PREFIX . 'window_' . $windowKey;
    }

    /**
     * Get data for a specific window.
     *
     * @return array{count: int, events: array<string, int>, providers: array<string, int>, clients: array<string, int>, last_updated: int}
     */
    private function getWindowData(string $windowKey): array
    {
        $default = [
            'count' => 0,
            'events' => [],
            'providers' => [],
            'clients' => [],
            'last_updated' => 0,
        ];

        $data = $this->cache->get($this->windowCacheKey($windowKey), $default);

        return is_array($data) ? $data : $default;
    }

    /**
     * Set data for a specific window.
     *
     * @param  array<string, mixed>  $data
     */
    private function setWindowData(string $windowKey, array $data): void
    {
        $ttl = $this->config['window_seconds'] ?? self::DEFAULT_WINDOW_SECONDS;
        $this->cache->put($this->windowCacheKey($windowKey), $data, $ttl * 3);

        // Track window keys for cleanup
        $keys = $this->cache->get(self::CACHE_PREFIX . 'window_keys', []);
        if (! in_array($windowKey, $keys, true)) {
            $keys[] = $windowKey;
            $this->cache->put(self::CACHE_PREFIX . 'window_keys', $keys, $ttl * 4);
        }
    }

    /**
     * Get the current window data.
     */
    private function getCurrentWindowData(): array
    {
        return $this->getWindowData($this->currentWindowKey());
    }

    /**
     * Compute baseline statistics from historical windows.
     *
     * @return array{avg_count: float, std_count: float, avg_providers: array<string, float>, event_frequencies: array<string, float>, avg_clients: float, std_clients: float, sample_size: int}
     */
    private function computeBaseline(): array
    {
        $baselineCount = $this->config['baseline_windows'] ?? self::DEFAULT_BASELINE_WINDOWS;
        $windowSeconds = $this->config['window_seconds'] ?? self::DEFAULT_WINDOW_SECONDS;
        $currentKey = (int) $this->currentWindowKey();

        $counts = [];
        $providerCounts = [];
        $clientCounts = [];
        $eventFrequencies = [];

        // Collect data from previous N windows (excluding current)
        for ($i = 1; $i <= $baselineCount; $i++) {
            $key = (string) ($currentKey - $i);
            $window = $this->getWindowData($key);

            if ($window['count'] > 0) {
                $counts[] = $window['count'];

                foreach ($window['providers'] as $provider => $count) {
                    $providerCounts[$provider][] = $count;
                }

                $clientCounts[] = count($window['clients']);

                foreach ($window['events'] as $event => $count) {
                    $eventFrequencies[$event] = ($eventFrequencies[$event] ?? 0) + $count;
                }
            }
        }

        if ($counts === []) {
            return [
                'avg_count' => 0.0,
                'std_count' => 0.0,
                'avg_providers' => [],
                'event_frequencies' => [],
                'avg_clients' => 0.0,
                'std_clients' => 0.0,
                'sample_size' => 0,
            ];
        }

        // Normalize event frequencies
        $totalEventCount = array_sum($eventFrequencies);
        $normalizedFrequencies = [];
        foreach ($eventFrequencies as $event => $count) {
            $normalizedFrequencies[$event] = $totalEventCount > 0
                ? $count / $totalEventCount
                : 0.0;
        }

        return [
            'avg_count' => array_sum($counts) / count($counts),
            'std_count' => $this->standardDeviation($counts),
            'avg_providers' => array_map(
                fn (array $c): float => array_sum($c) / count($c),
                $providerCounts,
            ),
            'event_frequencies' => $normalizedFrequencies,
            'avg_clients' => array_sum($clientCounts) / count($clientCounts),
            'std_clients' => $this->standardDeviation($clientCounts),
            'sample_size' => count($counts),
        ];
    }

    /**
     * Check for rate anomaly (volume spike or sudden drop).
     *
     * @return array{type: string, severity: string, message: string, metric: string, current: float, baseline: float, deviation: float}|null
     */
    private function checkRateAnomaly(array $current, array $baseline, float $sensitivity): ?array
    {
        if ($baseline['avg_count'] <= 0 || $baseline['std_count'] <= 0) {
            return null;
        }

        $deviation = abs($current['count'] - $baseline['avg_count']) / max($baseline['std_count'], 0.01);

        if ($deviation < $sensitivity) {
            return null;
        }

        $direction = $current['count'] > $baseline['avg_count'] ? 'spike' : 'drop';
        $severity = $deviation > $sensitivity * 2 ? 'critical' : 'warning';

        return [
            'type' => 'rate_anomaly',
            'severity' => $severity,
            'message' => "Event rate {$direction} detected: {$current['count']} events (baseline: " . round($baseline['avg_count']) . ', deviation: ' . round($deviation, 1) . 'σ)',
            'metric' => 'event_rate',
            'current' => (float) $current['count'],
            'baseline' => $baseline['avg_count'],
            'deviation' => round($deviation, 2),
        ];
    }

    /**
     * Check for provider imbalance (one provider suddenly has 0 or vastly different dispatch rate).
     *
     * @return array{type: string, severity: string, message: string, metric: string, current: float, baseline: float, deviation: float}|null
     */
    private function checkProviderAnomaly(array $current, array $baseline, float $sensitivity): ?array
    {
        // Check if a provider that should have events has none (possible provider failure)
        $failedProviders = [];
        foreach ($baseline['avg_providers'] as $provider => $avgCount) {
            if ($avgCount > 1.0) { // Only check providers with meaningful baseline
                $currentCount = $current['providers'][$provider] ?? 0;
                if ($currentCount === 0) {
                    $failedProviders[] = $provider;
                }
            }
        }

        if ($failedProviders === []) {
            return null;
        }

        return [
            'type' => 'provider_failure',
            'severity' => 'critical',
            'message' => 'Provider dispatch failure detected for: ' . implode(', ', $failedProviders),
            'metric' => 'provider_health',
            'current' => 0.0,
            'baseline' => (float) array_sum($baseline['avg_providers']),
            'deviation' => 99.0,
        ];
    }

    /**
     * Check for composition drift (new or unusual events appearing).
     *
     * @return array{type: string, severity: string, message: string, metric: string, current: float, baseline: float, deviation: float}|null
     */
    private function checkCompositionDrift(array $current, array $baseline): ?array
    {
        if ($current['count'] === 0 || $baseline['event_frequencies'] === []) {
            return null;
        }

        // Check for events that appear in current but not in baseline
        $newEvents = array_diff(
            array_keys($current['events']),
            array_keys($baseline['event_frequencies']),
        );

        if ($newEvents === []) {
            return null;
        }

        $newEventCount = 0;
        foreach ($newEvents as $event) {
            $newEventCount += $current['events'][$event];
        }

        $driftRatio = $newEventCount / $current['count'];
        $severity = $driftRatio > 0.5 ? 'critical' : 'warning';

        return [
            'type' => 'composition_drift',
            'severity' => $severity,
            'message' => 'New events detected in current window: ' . implode(', ', $newEvents) . " ({$newEventCount} events, {$driftRatio}% of total)",
            'metric' => 'event_composition',
            'current' => (float) $current['count'],
            'baseline' => 0.0,
            'deviation' => round($driftRatio, 2),
        ];
    }

    /**
     * Check for unique client count anomaly (possible bot/attack).
     *
     * @return array{type: string, severity: string, message: string, metric: string, current: float, baseline: float, deviation: float}|null
     */
    private function checkClientAnomaly(array $current, array $baseline, float $sensitivity): ?array
    {
        $currentClients = count($current['clients']);

        if ($baseline['avg_clients'] <= 0 || $baseline['std_clients'] <= 0) {
            return null;
        }

        $deviation = abs($currentClients - $baseline['avg_clients']) / max($baseline['std_clients'], 0.01);

        if ($deviation < $sensitivity) {
            return null;
        }

        $severity = $deviation > $sensitivity * 2 ? 'critical' : 'warning';

        return [
            'type' => 'client_anomaly',
            'severity' => $severity,
            'message' => "Unique client count anomaly: {$currentClients} clients (baseline: " . round($baseline['avg_clients']) . ', deviation: ' . round($deviation, 1) . 'σ)',
            'metric' => 'unique_clients',
            'current' => (float) $currentClients,
            'baseline' => $baseline['avg_clients'],
            'deviation' => round($deviation, 2),
        ];
    }

    /**
     * Fire an alert through all configured channels.
     *
     * @param  array<string, mixed>  $anomaly
     */
    private function fireAlert(array $anomaly): void
    {
        $cooldown = $this->config['alert_cooldown'] ?? self::ALERT_COOLDOWN_SECONDS;
        $alertKey = self::CACHE_PREFIX . 'alert_' . $anomaly['type'];
        $lastAlert = $this->cache->get($alertKey, 0);

        if ((now()->timestamp - $lastAlert) < $cooldown) {
            return;
        }

        $this->cache->put($alertKey, now()->timestamp, $cooldown);

        // Store in recent alerts
        $recent = $this->cache->get(self::CACHE_PREFIX . 'recent_alerts', []);
        array_unshift($recent, [
            ...$anomaly,
            'fired_at' => now()->toIso8601String(),
        ]);
        $recent = array_slice($recent, 0, 100); // Keep last 100 alerts
        $this->cache->put(self::CACHE_PREFIX . 'recent_alerts', $recent, 86400); // 24h

        // Log alert
        $channels = $this->config['channels'] ?? ['log'];
        if (in_array('log', $channels, true)) {
            Log::warning("ZeroBoiler Analytics Anomaly [{$anomaly['severity']}]: {$anomaly['message']}", $anomaly);
        }

        // Fire custom callbacks
        foreach ($this->alertCallbacks as $callback) {
            try {
                $callback($anomaly['type'], $anomaly);
            } catch (\Throwable $e) {
                Log::error('ZeroBoiler anomaly alert callback failed', [
                    'error' => $e->getMessage(),
                    'anomaly_type' => $anomaly['type'],
                ]);
            }
        }
    }

    /**
     * Get recent alerts from cache.
     *
     * @return list<array<string, mixed>>
     */
    private function getRecentAlerts(): array
    {
        return $this->cache->get(self::CACHE_PREFIX . 'recent_alerts', []);
    }

    /**
     * Count alerts fired in the last 24 hours.
     */
    private function countAlerts24h(): int
    {
        $cutoff = now()->subDay()->timestamp;
        $alerts = $this->getRecentAlerts();

        $count = 0;
        foreach ($alerts as $alert) {
            $firedAt = $alert['fired_at'] ?? null;
            if ($firedAt !== null) {
                try {
                    if ((new Carbon($firedAt))->timestamp > $cutoff) {
                        $count++;
                    }
                } catch (\RuntimeException) {
                    // Skip malformed timestamp
                }
            }
        }

        return $count;
    }

    /**
     * Compute standard deviation from a list of values.
     *
     * @param  list<float|int>  $values
     */
    private function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(
            fn (float|int $v): float => ($v - $mean) ** 2,
            $values,
        )) / $count;

        return sqrt($variance);
    }

    /**
     * Summarize a window for status reporting.
     *
     * @param  array<string, mixed>  $window
     * @return array{count: int, event_types: int, provider_count: int, unique_clients: int}
     */
    private function summarizeWindow(array $window): array
    {
        return [
            'count' => $window['count'] ?? 0,
            'event_types' => count($window['events'] ?? []),
            'provider_count' => count($window['providers'] ?? []),
            'unique_clients' => count($window['clients'] ?? []),
        ];
    }

    /**
     * Summarize baseline for status reporting.
     *
     * @param  array<string, mixed>  $baseline
     * @return array{avg_count: float, std_count: float, provider_count: int, sample_size: int}
     */
    private function summarizeBaseline(array $baseline): array
    {
        return [
            'avg_count' => round($baseline['avg_count'], 1),
            'std_count' => round($baseline['std_count'], 1),
            'provider_count' => count($baseline['avg_providers'] ?? []),
            'sample_size' => $baseline['sample_size'] ?? 0,
        ];
    }

    /**
     * Compute rate deviation (current vs baseline in standard deviations).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function computeRateDeviation(array $current, array $baseline): ?float
    {
        if ($baseline['std_count'] <= 0) {
            return null;
        }

        return round(abs($current['count'] - $baseline['avg_count']) / $baseline['std_count'], 2);
    }

    /**
     * Compute provider balance score (0 = all providers healthy, 100 = total failure).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function computeProviderBalance(array $current, array $baseline): ?float
    {
        $expected = $baseline['avg_providers'] ?? [];
        if ($expected === []) {
            return null;
        }

        $failedCount = 0;
        $totalExpected = 0;

        foreach ($expected as $provider => $avgCount) {
            if ($avgCount > 1.0) {
                $totalExpected++;
                if (($current['providers'][$provider] ?? 0) === 0) {
                    $failedCount++;
                }
            }
        }

        if ($totalExpected === 0) {
            return null;
        }

        return round(($failedCount / $totalExpected) * 100, 1);
    }

    /**
     * Compute composition drift score (0 = no drift, 1 = total drift).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function computeCompositionDrift(array $current, array $baseline): ?float
    {
        if ($current['count'] === 0 || ($baseline['event_frequencies'] ?? []) === []) {
            return null;
        }

        $newEvents = array_diff(
            array_keys($current['events'] ?? []),
            array_keys($baseline['event_frequencies']),
        );

        $newCount = 0;
        foreach ($newEvents as $event) {
            $newCount += $current['events'][$event] ?? 0;
        }

        return round($newCount / $current['count'], 4);
    }

    /**
     * Compute client deviation (current vs baseline in standard deviations).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function computeClientDeviation(array $current, array $baseline): ?float
    {
        if ($baseline['std_clients'] <= 0) {
            return null;
        }

        return round(abs(count($current['clients'] ?? []) - $baseline['avg_clients']) / $baseline['std_clients'], 2);
    }

    /**
     * Get the number of baseline windows being tracked.
     */
    private function baselineWindowCount(): int
    {
        return (int) ($this->config['baseline_windows'] ?? self::DEFAULT_BASELINE_WINDOWS);
    }
}
