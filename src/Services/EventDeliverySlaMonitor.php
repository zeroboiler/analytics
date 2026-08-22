<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event delivery SLA monitor — proactive per-provider SLA tracking and alerting.
 *
 * Monitors event delivery performance against configurable SLA targets per provider.
 * Tracks delivery success rate, latency percentiles, and error rates over configurable
 * time windows. Generates SLA status reports and can trigger alerts when thresholds
 * are breached.
 *
 * SLA dimensions tracked:
 * - **Availability**: Success rate percentage (target: 99.9%+)
 * - **Latency**: P50, P95, P99 dispatch latency in milliseconds
 * - **Error rate**: Percentage of failed dispatches
 * - **Throughput**: Events per second per provider
 *
 * Each provider can have its own SLA targets. The monitor maintains a sliding
 * window of dispatch metrics in cache and computes status per window.
 *
 * Status levels:
 * - **healthy**: All SLA targets are met
 * - **degraded**: One or more targets approaching breach (< 5% margin)
 * - **breached**: One or more targets violated
 * - **unknown**: Insufficient data to determine status
 *
 * Inspired by Stripe's SLA monitoring, Datadog's SLA tracking, and
 * PostHog's reliability dashboard.
 *
 * Configuration: `zeroboiler.analytics.sla`
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderHealthMonitor
 * @see \ZeroBoiler\Analytics\Services\AnalyticsEventReliabilityService
 *
 * @since 153.0.0
 */
final class EventDeliverySlaMonitor
{
    /** @var string Cache key prefix for SLA data */
    private const CACHE_PREFIX = 'zb_sla_';

    /** @var int Default TTL for SLA metrics (5 minutes) */
    private const DEFAULT_TTL = 300;

    /** @var int Maximum number of latency samples per provider per window */
    private const MAX_SAMPLES = 5000;

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    /** @var int Window duration in seconds for SLA calculations */
    private int $windowSeconds;

    /** @var float Default availability target (0.0-1.0) */
    private float $defaultAvailabilityTarget;

    /** @var float Default latency P95 target in milliseconds */
    private float $defaultLatencyTarget;

    /** @var float Default error rate maximum (0.0-1.0) */
    private float $defaultErrorRateMax;

    /** @var array<string, array{availability?: float, latency_p95?: float, error_rate?: float}> Per-provider SLA targets */
    private array $providerTargets;

    /** @var list<string> Providers to monitor (empty = all) */
    private array $monitoredProviders;

    /**
     * @param  CacheRepository  $cache  Cache repository for SLA metrics
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $slaConfig = $config->get('zeroboiler.analytics.sla', []);
        /** @var array{enabled?: bool, ttl?: int, window_seconds?: int, default_availability?: float, default_latency_p95?: float, default_error_rate_max?: float, provider_targets?: array<string, array{availability?: float, latency_p95?: float, error_rate?: float}>, monitored_providers?: list<string>} $slaConfig */
        $this->enabled = (bool) ($slaConfig['enabled'] ?? true);
        $this->ttl = (int) ($slaConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->windowSeconds = (int) ($slaConfig['window_seconds'] ?? 300);
        $this->defaultAvailabilityTarget = (float) ($slaConfig['default_availability'] ?? 0.999);
        $this->defaultLatencyTarget = (float) ($slaConfig['default_latency_p95'] ?? 500.0);
        $this->defaultErrorRateMax = (float) ($slaConfig['default_error_rate_max'] ?? 0.01);
        $this->providerTargets = (array) ($slaConfig['provider_targets'] ?? []);
        $this->monitoredProviders = (array) ($slaConfig['monitored_providers'] ?? []);
    }

    /**
     * Record a successful dispatch for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     */
    public function recordSuccess(string $provider, float $latencyMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->appendToWindow($provider, [
            'status' => 'success',
            'latency_ms' => $latencyMs,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Record a failed dispatch for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string|null  $errorMessage  Optional error message
     */
    public function recordFailure(string $provider, ?string $errorMessage = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->appendToWindow($provider, [
            'status' => 'failure',
            'latency_ms' => 0.0,
            'error' => $errorMessage,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Get SLA status for a specific provider.
     *
     * @param  string  $provider  Provider name
     * @return array{status: string, availability: float, latency_p50: float, latency_p95: float, latency_p99: float, error_rate: float, throughput_eps: float, total_events: int, targets: array{availability: float, latency_p95: float, error_rate: float}, breaches: list<string>}
     */
    public function getStatus(string $provider): array
    {
        $window = $this->getWindow($provider);
        $targets = $this->getTargets($provider);

        if ($window === []) {
            return [
                'status' => 'unknown',
                'availability' => 0.0,
                'latency_p50' => 0.0,
                'latency_p95' => 0.0,
                'latency_p99' => 0.0,
                'error_rate' => 0.0,
                'throughput_eps' => 0.0,
                'total_events' => 0,
                'targets' => $targets,
                'breaches' => [],
            ];
        }

        $latencies = array_column(array_filter($window, fn (array $e): bool => $e['status'] === 'success'), 'latency_ms');
        $successes = count($latencies);
        $failures = count($window) - $successes;
        $total = count($window);

        $availability = $total > 0 ? $successes / $total : 1.0;
        $errorRate = $total > 0 ? $failures / $total : 0.0;

        sort($latencies);
        $latencyP50 = $this->percentile($latencies, 50);
        $latencyP95 = $this->percentile($latencies, 95);
        $latencyP99 = $this->percentile($latencies, 99);

        $oldest = $window[0]['timestamp'] ?? microtime(true);
        $newest = $window[array_key_last($window)]['timestamp'] ?? microtime(true);
        $duration = max($newest - $oldest, 0.001);
        $throughputEps = $total / $duration;

        $breaches = $this->detectBreaches($availability, $latencyP95, $errorRate, $targets);
        $degradedWarnings = $this->detectDegraded($availability, $latencyP95, $errorRate, $targets);

        $status = $breaches !== [] ? 'breached' : ($degradedWarnings !== [] ? 'degraded' : 'healthy');

        return [
            'status' => $status,
            'availability' => round($availability, 6),
            'latency_p50' => round($latencyP50, 2),
            'latency_p95' => round($latencyP95, 2),
            'latency_p99' => round($latencyP99, 2),
            'error_rate' => round($errorRate, 6),
            'throughput_eps' => round($throughputEps, 2),
            'total_events' => $total,
            'targets' => $targets,
            'breaches' => $breaches,
        ];
    }

    /**
     * Get SLA status for all monitored providers.
     *
     * @return array<string, array{status: string, availability: float, latency_p95: float, error_rate: float, breaches: list<string>}>
     */
    public function getAllStatus(): array
    {
        $providers = $this->monitoredProviders;

        if ($providers === []) {
            $providers = [
                'ga4', 'gtm', 'meta', 'plausible', 'posthog',
                'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin',
            ];
        }

        $result = [];
        foreach ($providers as $provider) {
            $status = $this->getStatus($provider);
            $result[$provider] = [
                'status' => $status['status'],
                'availability' => $status['availability'],
                'latency_p95' => $status['latency_p95'],
                'error_rate' => $status['error_rate'],
                'breaches' => $status['breaches'],
            ];
        }

        return $result;
    }

    /**
     * Check if any provider has an active SLA breach.
     */
    public function hasBreaches(): bool
    {
        $all = $this->getAllStatus();

        foreach ($all as $providerStatus) {
            if ($providerStatus['status'] === 'breached') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get SLA summary for the overview command.
     *
     * @return array{enabled: bool, providers_total: int, healthy: int, degraded: int, breached: int, unknown: int, default_targets: array{availability: float, latency_p95: float, error_rate: float}}
     */
    public function getSummary(): array
    {
        $all = $this->getAllStatus();
        $counts = ['healthy' => 0, 'degraded' => 0, 'breached' => 0, 'unknown' => 0];

        foreach ($all as $status) {
            $counts[$status['status']]++;
        }

        return [
            'enabled' => $this->enabled,
            'providers_total' => count($all),
            'healthy' => $counts['healthy'],
            'degraded' => $counts['degraded'],
            'breached' => $counts['breached'],
            'unknown' => $counts['unknown'],
            'default_targets' => [
                'availability' => $this->defaultAvailabilityTarget,
                'latency_p95' => $this->defaultLatencyTarget,
                'error_rate' => $this->defaultErrorRateMax,
            ],
        ];
    }

    /**
     * Clear all SLA data for a specific provider.
     */
    public function clear(string $provider): void
    {
        $cacheKey = self::CACHE_PREFIX . $provider;
        $this->cache->forget($cacheKey);
    }

    /**
     * Clear all SLA data for all providers.
     */
    public function clearAll(): void
    {
        $providers = $this->monitoredProviders;

        if ($providers === []) {
            $providers = [
                'ga4', 'gtm', 'meta', 'plausible', 'posthog',
                'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin',
            ];
        }

        foreach ($providers as $provider) {
            $this->clear($provider);
        }
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Append a dispatch record to the sliding window.
     *
     * @param  string  $provider  Provider name
     * @param  array{status: string, latency_ms: float, timestamp: float, error?: string|null}  $record  Dispatch record
     */
    private function appendToWindow(string $provider, array $record): void
    {
        $cacheKey = self::CACHE_PREFIX . $provider;
        $window = $this->cache->get($cacheKey, []);
        if (! is_array($window)) {
            $window = [];
        }

        $window[] = $record;

        // Trim samples outside the window
        $cutoff = microtime(true) - $this->windowSeconds;
        $window = array_filter($window, fn (array $e): bool => $e['timestamp'] >= $cutoff);
        $window = array_values($window);

        // Enforce max samples
        if (count($window) > self::MAX_SAMPLES) {
            $window = array_slice($window, -self::MAX_SAMPLES);
        }

        $this->cache->put($cacheKey, $window, $this->ttl);
    }

    /**
     * Get the current sliding window for a provider.
     *
     * @param  string  $provider  Provider name
     * @return list<array{status: string, latency_ms: float, timestamp: float, error?: string|null}>
     */
    private function getWindow(string $provider): array
    {
        $cacheKey = self::CACHE_PREFIX . $provider;
        $window = $this->cache->get($cacheKey, []);

        if (! is_array($window)) {
            return [];
        }

        // Trim to current window
        $cutoff = microtime(true) - $this->windowSeconds;
        $window = array_filter($window, fn (array $e): bool => $e['timestamp'] >= $cutoff);

        return array_values($window);
    }

    /**
     * Get SLA targets for a specific provider.
     *
     * @return array{availability: float, latency_p95: float, error_rate: float}
     */
    private function getTargets(string $provider): array
    {
        $providerTarget = $this->providerTargets[$provider] ?? [];

        return [
            'availability' => (float) ($providerTarget['availability'] ?? $this->defaultAvailabilityTarget),
            'latency_p95' => (float) ($providerTarget['latency_p95'] ?? $this->defaultLatencyTarget),
            'error_rate' => (float) ($providerTarget['error_rate'] ?? $this->defaultErrorRateMax),
        ];
    }

    /**
     * Detect SLA breaches.
     *
     * @return list<string> List of breach descriptions
     */
    private function detectBreaches(float $availability, float $latencyP95, float $errorRate, array $targets): array
    {
        $breaches = [];

        if ($availability < $targets['availability']) {
            $breaches[] = 'availability: ' . round($availability * 100, 2) . '% < ' . round($targets['availability'] * 100, 2) . '%';
        }

        if ($latencyP95 > $targets['latency_p95']) {
            $breaches[] = 'latency_p95: ' . round($latencyP95, 1) . 'ms > ' . round($targets['latency_p95'], 1) . 'ms';
        }

        if ($errorRate > $targets['error_rate']) {
            $breaches[] = 'error_rate: ' . round($errorRate * 100, 2) . '% > ' . round($targets['error_rate'] * 100, 2) . '%';
        }

        return $breaches;
    }

    /**
     * Detect SLA degradation (approaching breach within 5% margin).
     *
     * @return list<string> List of degradation warnings
     */
    private function detectDegraded(float $availability, float $latencyP95, float $errorRate, array $targets): array
    {
        $warnings = [];
        $margin = 0.05;

        $availabilityThreshold = $targets['availability'] * (1 - $margin);
        if ($availability >= $availabilityThreshold && $availability < $targets['availability']) {
            $warnings[] = 'availability approaching target: ' . round($availability * 100, 2) . '%';
        }

        $latencyThreshold = $targets['latency_p95'] * (1 - $margin);
        if ($latencyP95 >= $latencyThreshold && $latencyP95 <= $targets['latency_p95']) {
            $warnings[] = 'latency_p95 approaching target: ' . round($latencyP95, 1) . 'ms';
        }

        $errorThreshold = $targets['error_rate'] * (1 - $margin);
        if ($errorRate >= $errorThreshold && $errorRate <= $targets['error_rate']) {
            $warnings[] = 'error_rate approaching target: ' . round($errorRate * 100, 2) . '%';
        }

        return $warnings;
    }

    /**
     * Calculate percentile from sorted array of values.
     */
    private function percentile(array $sorted, float $percent): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (count($sorted) - 1) * ($percent / 100.0);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper || $upper >= count($sorted)) {
            return (float) ($sorted[$lower] ?? 0.0);
        }

        $weight = $index - $lower;

        return (float) $sorted[$lower] * (1 - $weight) + (float) $sorted[$upper] * $weight;
    }
}
