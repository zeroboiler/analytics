<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Delivery Confirmation Service.
 *
 * Tracks whether analytics events are successfully delivered to each
 * configured provider. Maintains per-provider delivery counts, failure counts,
 * response time metrics, and a composite delivery reliability score (0-100).
 *
 * Inspired by Segment's delivery confirmation, Mixpanel's event verification,
 * and Amplitude's event monitoring dashboard.
 *
 * Features:
 * - Per-provider delivery success/failure tracking
 * - Response latency measurement (p50, p95, p99)
 * - Composite reliability score (0-100) with A-F grading
 * - Event delivery receipt recording
 * - Provider outage detection via failure rate spike
 * - Cache-backed with configurable TTL and retention window
 * - Delivery SLA monitoring
 *
 * Configuration is read from `zeroboiler.analytics.delivery_confirmation`.
 *
 * @since 9.0.0
 */
final class EventDeliveryConfirmationService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_analytics_delivery_';

    /** Reliability grade thresholds */
    private const GRADE_A = 95;
    private const GRADE_B = 85;
    private const GRADE_C = 70;
    private const GRADE_D = 50;

    private bool $enabled;

    private int $cacheTtl;

    private int $retentionWindow;

    private int $outageThreshold;

    private float $slaTarget;

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $deliveryConfig = $config->get('zeroboiler.analytics.delivery_confirmation', []);
        /** @var array{enabled?: bool, cache_ttl?: int, retention_window?: int, outage_threshold?: int, sla_target?: float} $deliveryConfig */

        $this->enabled = (bool) ($deliveryConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($deliveryConfig['cache_ttl'] ?? 3600);
        $this->retentionWindow = (int) ($deliveryConfig['retention_window'] ?? 86400);
        $this->outageThreshold = (int) ($deliveryConfig['outage_threshold'] ?? 10);
        $this->slaTarget = (float) ($deliveryConfig['sla_target'] ?? 99.5);
    }

    /**
     * Record a successful event delivery to a provider.
     *
     * @param  string  $provider  Provider name (ga4, gtm, meta, plausible, posthog)
     * @param  string  $eventName  Event name
     * @param  int  $responseTimeMs  Response time in milliseconds
     * @param  string|null  $eventId  Optional event ID for dedup
     */
    public function recordSuccess(
        string $provider,
        string $eventName,
        int $responseTimeMs,
        ?string $eventId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $this->incrementCounter($provider, 'success_count');
        $this->recordResponseTime($provider, $responseTimeMs);
        $this->recordRecentDelivery($provider, $eventName, true, $responseTimeMs, $eventId);

        if ($eventId !== null) {
            $this->recordReceipt($eventId, $provider, true, $responseTimeMs);
        }

        Log::debug('ZeroBoiler Analytics: delivery confirmed', [
            'provider' => $provider,
            'event' => $eventName,
            'response_ms' => $responseTimeMs,
        ]);
    }

    /**
     * Record a failed event delivery to a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $eventName  Event name
     * @param  string  $errorMessage  Error message
     * @param  int|null  $statusCode  HTTP status code (if applicable)
     * @param  string|null  $eventId  Optional event ID for dedup
     */
    public function recordFailure(
        string $provider,
        string $eventName,
        string $errorMessage,
        ?int $statusCode = null,
        ?string $eventId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $this->incrementCounter($provider, 'failure_count');
        $this->incrementCounter($provider, 'consecutive_failures');
        $this->recordRecentDelivery($provider, $eventName, false, null, $eventId);

        if ($eventId !== null) {
            $this->recordReceipt($eventId, $provider, false, null, $errorMessage);
        }

        Log::warning('ZeroBoiler Analytics: delivery failure', [
            'provider' => $provider,
            'event' => $eventName,
            'error' => $errorMessage,
            'status' => $statusCode,
        ]);

        // Check for potential provider outage
        $consecutive = $this->getCounter($provider, 'consecutive_failures');
        if ($consecutive >= $this->outageThreshold) {
            Log::error('ZeroBoiler Analytics: provider outage detected', [
                'provider' => $provider,
                'consecutive_failures' => $consecutive,
                'threshold' => $this->outageThreshold,
            ]);
        }
    }

    /**
     * Record a delivery receipt for a specific event ID.
     *
     * Enables querying whether a specific event was delivered successfully.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $provider  Provider name
     * @param  bool  $success  Whether delivery succeeded
     * @param  int|null  $responseTimeMs  Response time (null on failure)
     * @param  string|null  $errorMessage  Error message (null on success)
     */
    public function recordReceipt(
        string $eventId,
        string $provider,
        bool $success,
        ?int $responseTimeMs = null,
        ?string $errorMessage = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $receipts = $this->cache->get(self::CACHE_PREFIX.'receipts_'.$eventId, []);
        $receipts[$provider] = [
            'success' => $success,
            'response_time_ms' => $responseTimeMs,
            'error' => $errorMessage,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->cache->put(
            self::CACHE_PREFIX.'receipts_'.$eventId,
            $receipts,
            $this->cacheTtl,
        );
    }

    /**
     * Check if a specific event was delivered to all enabled providers.
     *
     * @param  string  $eventId  Unique event identifier
     * @return array{delivered: bool, providers: array<string, array{success: bool, response_time_ms: int|null, error: string|null, timestamp: string|null}>}
     */
    public function checkReceipt(string $eventId): array
    {
        $receipts = $this->cache->get(self::CACHE_PREFIX.'receipts_'.$eventId, []);
        $enabledProviders = $this->getEnabledProviders();

        $allDelivered = true;
        $providerStatus = [];

        foreach ($enabledProviders as $provider) {
            if (isset($receipts[$provider])) {
                $providerStatus[$provider] = $receipts[$provider];
                if (! $receipts[$provider]['success']) {
                    $allDelivered = false;
                }
            } else {
                $allDelivered = false;
                $providerStatus[$provider] = [
                    'success' => false,
                    'response_time_ms' => null,
                    'error' => 'pending',
                    'timestamp' => null,
                ];
            }
        }

        return [
            'delivered' => $allDelivered,
            'providers' => $providerStatus,
        ];
    }

    /**
     * Get the delivery reliability score (0-100).
     *
     * Computed from the ratio of successful to total delivery attempts
     * across all providers, with penalties for consecutive failures and
     * SLA misses.
     *
     * @return array{score: int, grade: string, sla_met: bool, providers: array<string, array{score: int, success_count: int, failure_count: int, rate: float}>}
     */
    public function getReliabilityScore(): array
    {
        if (! $this->enabled) {
            return ['score' => 100, 'grade' => 'A', 'sla_met' => true, 'providers' => []];
        }

        $providers = $this->getEnabledProviders();
        $providerScores = [];
        $totalRate = 0.0;
        $providerCount = 0;

        foreach ($providers as $provider) {
            $success = $this->getCounter($provider, 'success_count');
            $failure = $this->getCounter($provider, 'failure_count');
            $total = $success + $failure;

            if ($total === 0) {
                $rate = 1.0;
                $score = 100;
            } else {
                $rate = $success / $total;
                $consecutive = $this->getCounter($provider, 'consecutive_failures');

                // Penalty for consecutive failures (max 20 points)
                $consecutivePenalty = min($consecutive * 2, 20);
                $score = (int) round($rate * 100 - $consecutivePenalty);
                $score = max(0, min(100, $score));
            }

            $providerScores[$provider] = [
                'score' => $score,
                'success_count' => $success,
                'failure_count' => $failure,
                'rate' => round($rate, 4),
            ];

            $totalRate += $rate;
            $providerCount++;
        }

        $averageRate = $providerCount > 0 ? $totalRate / $providerCount : 1.0;
        $overallScore = $providerCount > 0
            ? (int) round(array_sum(array_column($providerScores, 'score')) / $providerCount)
            : 100;

        $grade = $this->calculateGrade($overallScore);
        $slaMet = $averageRate * 100 >= $this->slaTarget;

        return [
            'score' => $overallScore,
            'grade' => $grade,
            'sla_met' => $slaMet,
            'sla_target' => $this->slaTarget,
            'actual_rate' => round($averageRate * 100, 2),
            'providers' => $providerScores,
        ];
    }

    /**
     * Get response time percentiles for a provider.
     *
     * @param  string  $provider  Provider name
     * @return array{p50: int|null, p95: int|null, p99: int|null, avg: int|null, min: int|null, max: int|null, samples: int}
     */
    public function getResponseTimeStats(string $provider): array
    {
        if (! $this->enabled) {
            return ['p50' => null, 'p95' => null, 'p99' => null, 'avg' => null, 'min' => null, 'max' => null, 'samples' => 0];
        }

        $times = $this->cache->get(self::CACHE_PREFIX.'response_times_'.$provider, []);

        if (empty($times)) {
            return ['p50' => null, 'p95' => null, 'p99' => null, 'avg' => null, 'min' => null, 'max' => null, 'samples' => 0];
        }

        sort($times);

        $count = count($times);

        return [
            'p50' => $times[(int) floor($count * 0.5)] ?? null,
            'p95' => $times[(int) floor($count * 0.95)] ?? null,
            'p99' => $times[(int) floor($count * 0.99)] ?? null,
            'avg' => (int) round(array_sum($times) / $count),
            'min' => $times[0] ?? null,
            'max' => $times[$count - 1] ?? null,
            'samples' => $count,
        ];
    }

    /**
     * Check if a provider is currently in an outage state.
     *
     * A provider is considered in outage when consecutive failures
     * exceed the configured threshold.
     *
     * @param  string  $provider  Provider name
     */
    public function isProviderInOutage(string $provider): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->getCounter($provider, 'consecutive_failures') >= $this->outageThreshold;
    }

    /**
     * Reset consecutive failure counter for a provider.
     *
     * Called after a successful delivery to reset the outage detection.
     *
     * @param  string  $provider  Provider name
     */
    public function resetConsecutiveFailures(string $provider): void
    {
        $this->cache->put(self::CACHE_PREFIX.'counter_'.$provider.'_consecutive_failures', 0, $this->cacheTtl);
    }

    /**
     * Get recent delivery history for a provider.
     *
     * Returns the most recent delivery attempts with their status.
     *
     * @param  string  $provider  Provider name
     * @param  int  $limit  Maximum number of entries to return
     * @return list<array{event: string, success: bool, response_time_ms: int|null, timestamp: string}>
     */
    public function getRecentDeliveries(string $provider, int $limit = 50): array
    {
        if (! $this->enabled) {
            return [];
        }

        $deliveries = $this->cache->get(self::CACHE_PREFIX.'recent_'.$provider, []);

        return array_slice($deliveries, -$limit);
    }

    /**
     * Get comprehensive delivery dashboard data.
     *
     * Aggregates reliability scores, response time stats, outage status,
     * and recent delivery history for all providers.
     *
     * @return array{reliability: array{score: int, grade: string, sla_met: bool, sla_target: float, actual_rate: float}, providers: array<string, array{score: int, rate: float, success_count: int, failure_count: int, in_outage: bool, response_times: array{p50: int|null, p95: int|null, p99: int|null, avg: int|null, samples: int}, recent_failures: int}>, events_tracked: int, period_seconds: int}
     */
    public function getDeliveryDashboard(): array
    {
        $reliability = $this->getReliabilityScore();
        $providerDetails = [];

        foreach ($reliability['providers'] as $provider => $data) {
            $responseTimes = $this->getResponseTimeStats($provider);
            $recentDeliveries = $this->getRecentDeliveries($provider, 100);

            // Count recent failures (last 100 deliveries)
            $recentFailures = 0;
            foreach ($recentDeliveries as $delivery) {
                if (! $delivery['success']) {
                    $recentFailures++;
                }
            }

            $providerDetails[$provider] = [
                'score' => $data['score'],
                'rate' => $data['rate'],
                'success_count' => $data['success_count'],
                'failure_count' => $data['failure_count'],
                'in_outage' => $this->isProviderInOutage($provider),
                'response_times' => [
                    'p50' => $responseTimes['p50'],
                    'p95' => $responseTimes['p95'],
                    'p99' => $responseTimes['p99'],
                    'avg' => $responseTimes['avg'],
                    'samples' => $responseTimes['samples'],
                ],
                'recent_failures' => $recentFailures,
            ];
        }

        $totalEvents = 0;
        foreach ($providerDetails as $details) {
            $totalEvents += $details['success_count'] + $details['failure_count'];
        }

        return [
            'reliability' => [
                'score' => $reliability['score'],
                'grade' => $reliability['grade'],
                'sla_met' => $reliability['sla_met'],
                'sla_target' => $reliability['sla_target'],
                'actual_rate' => $reliability['actual_rate'],
            ],
            'providers' => $providerDetails,
            'events_tracked' => $totalEvents,
            'period_seconds' => $this->cacheTtl,
        ];
    }

    /**
     * Clear all delivery tracking data for a provider.
     *
     * @param  string|null  $provider  Provider name (null = all providers)
     */
    public function clearStats(?string $provider = null): void
    {
        $providers = $provider !== null ? [$provider] : $this->getEnabledProviders();

        foreach ($providers as $p) {
            $this->cache->forget(self::CACHE_PREFIX.'counter_'.$p.'_success_count');
            $this->cache->forget(self::CACHE_PREFIX.'counter_'.$p.'_failure_count');
            $this->cache->forget(self::CACHE_PREFIX.'counter_'.$p.'_consecutive_failures');
            $this->cache->forget(self::CACHE_PREFIX.'response_times_'.$p);
            $this->cache->forget(self::CACHE_PREFIX.'recent_'.$p);
        }
    }

    /**
     * Get a list of currently enabled provider names.
     *
     * @return list<string>
     */
    public function getEnabledProviders(): array
    {
        $providers = [];

        if ($this->manager->ga4()->isEnabled()) {
            $providers[] = 'ga4';
        }

        if ($this->manager->gtm()->isEnabled()) {
            $providers[] = 'gtm';
        }

        if ($this->manager->meta()->isEnabled()) {
            $providers[] = 'meta';
        }

        if ($this->manager->plausible()->isEnabled()) {
            $providers[] = 'plausible';
        }

        if ($this->manager->posthog()->isEnabled()) {
            $providers[] = 'posthog';
        }

        return $providers;
    }

    /**
     * Calculate a reliability grade from a score.
     *
     * @param  int  $score  Score (0-100)
     * @return string Grade (A-F)
     */
    private function calculateGrade(int $score): string
    {
        if ($score >= self::GRADE_A) {
            return 'A';
        }

        if ($score >= self::GRADE_B) {
            return 'B';
        }

        if ($score >= self::GRADE_C) {
            return 'C';
        }

        if ($score >= self::GRADE_D) {
            return 'D';
        }

        return 'F';
    }

    /**
     * Increment a counter for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $counter  Counter name
     */
    private function incrementCounter(string $provider, string $counter): void
    {
        $key = self::CACHE_PREFIX.'counter_'.$provider.'_'.$counter;
        $current = $this->cache->get($key, 0);
        $this->cache->put($key, $current + 1, $this->cacheTtl);
    }

    /**
     * Get a counter value for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $counter  Counter name
     */
    private function getCounter(string $provider, string $counter): int
    {
        return (int) $this->cache->get(self::CACHE_PREFIX.'counter_'.$provider.'_'.$counter, 0);
    }

    /**
     * Record a response time sample for a provider.
     *
     * Keeps up to 1000 samples for percentile calculation.
     *
     * @param  string  $provider  Provider name
     * @param  int  $responseTimeMs  Response time in milliseconds
     */
    private function recordResponseTime(string $provider, int $responseTimeMs): void
    {
        $key = self::CACHE_PREFIX.'response_times_'.$provider;
        $times = $this->cache->get($key, []);
        $times[] = $responseTimeMs;

        // Keep only last 1000 samples
        if (count($times) > 1000) {
            $times = array_slice($times, -1000);
        }

        $this->cache->put($key, $times, $this->cacheTtl);

        // Reset consecutive failures on success
        $this->resetConsecutiveFailures($provider);
    }

    /**
     * Record a recent delivery attempt.
     *
     * Keeps up to 500 recent deliveries per provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $eventName  Event name
     * @param  bool  $success  Whether delivery succeeded
     * @param  int|null  $responseTimeMs  Response time
     * @param  string|null  $eventId  Event ID
     */
    private function recordRecentDelivery(
        string $provider,
        string $eventName,
        bool $success,
        ?int $responseTimeMs,
        ?string $eventId,
    ): void {
        $key = self::CACHE_PREFIX.'recent_'.$provider;
        $deliveries = $this->cache->get($key, []);
        $deliveries[] = [
            'event' => $eventName,
            'success' => $success,
            'response_time_ms' => $responseTimeMs,
            'timestamp' => now()->toIso8601String(),
            'event_id' => $eventId,
        ];

        // Keep only last 500 entries
        if (count($deliveries) > 500) {
            $deliveries = array_slice($deliveries, -500);
        }

        $this->cache->put($key, $deliveries, $this->cacheTtl);
    }
}
