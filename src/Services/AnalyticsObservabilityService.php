<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics Observability Service.
 *
 * Provides dispatch-level observability for the analytics pipeline.
 * Tracks per-provider dispatch latency, success/failure rates, error budgets,
 * and dispatch volume histograms. Designed for SaaS production monitoring
 * dashboards and alerting integrations.
 *
 * This complements EventSignalIntelligenceService (which focuses on event
 * patterns and anomalies) by focusing on the operational health of the
 * dispatch pipeline itself.
 *
 * Inspired by OpenTelemetry metrics, Segment's Observability API, and
 * Datadog's dispatch monitoring.
 *
 * Configuration: `zeroboiler.analytics.observability`
 *
 * @see \ZeroBoiler\Analytics\Services\EventSignalIntelligenceService
 * @see \ZeroBoiler\Analytics\Services\ProviderHealthMonitor
 *
 * @since 18.0.0
 */
final class AnalyticsObservabilityService
{
    /** @var string Cache key prefix for all observability data */
    private const CACHE_PREFIX = 'zb_obs_';

    /** @var int Default TTL for metrics aggregation (5 minutes) */
    private const DEFAULT_TTL = 300;

    /** @var int Maximum number of latency samples per provider per window */
    private const MAX_SAMPLES = 1000;

    /** @var int Maximum dispatch error log entries per provider */
    private const MAX_ERROR_LOG = 100;

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    /** @var array<string, bool> Providers to observe (empty = all) */
    private array $observedProviders;

    /** @var float Error budget threshold (0.0-1.0, default 0.01 = 1% failure rate allowed) */
    private float $errorBudgetThreshold;

    /** @var float Slow dispatch threshold in milliseconds */
    private float $slowDispatchThreshold;

    /** @var int Maximum histogram buckets for latency tracking */
    private int $latencyBuckets;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $obsConfig = $config->get('zeroboiler.analytics.observability', []);
        /** @var array{enabled?: bool, ttl?: int, providers?: list<string>, error_budget_threshold?: float, slow_dispatch_ms?: float, latency_buckets?: int} $obsConfig */
        $this->enabled = (bool) ($obsConfig['enabled'] ?? true);
        $this->ttl = (int) ($obsConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->observedProviders = (array) ($obsConfig['providers'] ?? []);
        $this->errorBudgetThreshold = (float) ($obsConfig['error_budget_threshold'] ?? 0.01);
        $this->slowDispatchThreshold = (float) ($obsConfig['slow_dispatch_ms'] ?? 1000.0);
        $this->latencyBuckets = (int) ($obsConfig['latency_buckets'] ?? 50);
    }

    /**
     * Check if observability is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a successful dispatch for a provider.
     *
     * Tracks latency, increments success counter, and updates the
     * rolling histogram for the provider.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, plausible, mixpanel, amplitude, webhook)
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     * @param  string|null  $eventName  Optional event name for per-event tracking
     */
    public function recordSuccess(string $provider, float $latencyMs, ?string $eventName = null): void
    {
        if (! $this->shouldObserve($provider)) {
            return;
        }

        $this->incrementCounter("{$provider}_success");
        $this->recordLatency($provider, $latencyMs);

        if ($eventName !== null) {
            $this->incrementCounter("{$provider}_event_{$eventName}_success");
            $this->recordLatency("{$provider}_{$eventName}", $latencyMs);
        }
    }

    /**
     * Record a failed dispatch for a provider.
     *
     * Tracks error count, records the error type, and checks error budget.
     *
     * @param  string  $provider  Provider name
     * @param  string  $errorType  Error classification (timeout, http_error, validation_error, network_error, unknown)
     * @param  string|null  $errorMessage  Optional human-readable error message
     * @param  string|null  $eventName  Optional event name
     */
    public function recordFailure(string $provider, string $errorType, ?string $errorMessage = null, ?string $eventName = null): void
    {
        if (! $this->shouldObserve($provider)) {
            return;
        }

        $this->incrementCounter("{$provider}_failure");
        $this->incrementCounter("{$provider}_error_{$errorType}");
        $this->appendErrorLog($provider, $errorType, $errorMessage);

        if ($eventName !== null) {
            $this->incrementCounter("{$provider}_event_{$eventName}_failure");
        }
    }

    /**
     * Record a filtered/discarded event.
     *
     * Tracks events that were dropped by the pipeline (consent filter,
     * deduplication, sampling, etc.).
     *
     * @param  string  $filterName  Name of the filter that discarded the event
     * @param  string|null  $eventName  Optional event name
     */
    public function recordFiltered(string $filterName, ?string $eventName = null): void
    {
        $this->incrementCounter("filter_{$filterName}_filtered");

        if ($eventName !== null) {
            $this->incrementCounter("filter_{$filterName}_{$eventName}_filtered");
        }
    }

    /**
     * Get the observability dashboard for all providers.
     *
     * Returns a comprehensive summary of dispatch health metrics
     * for all observed providers.
     *
     * @return array{enabled: bool, providers: array<string, array{total: int, success: int, failure: int, success_rate: float, avg_latency_ms: float, p50_latency_ms: float, p95_latency_ms: float, p99_latency_ms: float, slow_dispatches: int, error_budget_remaining: float, error_budget_breached: bool, recent_errors: list<array{type: string, message: string|null, timestamp: int}>}>, summary: array{total_dispatches: int, total_failures: int, overall_success_rate: float, slowest_provider: string|null, most_errors_provider: string|null}}
     */
    public function getDashboard(): array
    {
        $providers = $this->getObservedProviderList();
        $providerMetrics = [];
        $totalDispatches = 0;
        $totalFailures = 0;
        $slowestProvider = null;
        $slowestAvgLatency = 0.0;
        $mostErrorsProvider = null;
        $mostErrors = 0;

        foreach ($providers as $provider) {
            $metrics = $this->getProviderMetrics($provider);
            $providerMetrics[$provider] = $metrics;
            $totalDispatches += $metrics['total'];
            $totalFailures += $metrics['failure'];

            if ($metrics['avg_latency_ms'] > $slowestAvgLatency) {
                $slowestAvgLatency = $metrics['avg_latency_ms'];
                $slowestProvider = $provider;
            }

            if ($metrics['failure'] > $mostErrors) {
                $mostErrors = $metrics['failure'];
                $mostErrorsProvider = $provider;
            }
        }

        return [
            'enabled' => $this->enabled,
            'providers' => $providerMetrics,
            'summary' => [
                'total_dispatches' => $totalDispatches,
                'total_failures' => $totalFailures,
                'overall_success_rate' => $totalDispatches > 0
                    ? round(($totalDispatches - $totalFailures) / $totalDispatches, 4)
                    : 1.0,
                'slowest_provider' => $slowestProvider,
                'most_errors_provider' => $mostErrorsProvider,
            ],
        ];
    }

    /**
     * Get detailed metrics for a single provider.
     *
     * @param  string  $provider  Provider name
     * @return array{total: int, success: int, failure: int, success_rate: float, avg_latency_ms: float, p50_latency_ms: float, p95_latency_ms: float, p99_latency_ms: float, slow_dispatches: int, error_budget_remaining: float, error_budget_breached: bool, recent_errors: list<array{type: string, message: string|null, timestamp: int}>}
     */
    public function getProviderMetrics(string $provider): array
    {
        $success = $this->getCounter("{$provider}_success");
        $failure = $this->getCounter("{$provider}_failure");
        $total = $success + $failure;

        $latencies = $this->getLatencySamples($provider);
        $errorLog = $this->getErrorLog($provider);

        $avgLatency = $this->calculateAverage($latencies);
        $sorted = $latencies;
        sort($sorted);

        $slowDispatches = count(array_filter($latencies, fn (float $l): bool => $l > $this->slowDispatchThreshold));

        $errorBudgetUsed = $total > 0 ? $failure / $total : 0.0;
        $errorBudgetRemaining = max(0.0, 1.0 - ($errorBudgetUsed / $this->errorBudgetThreshold));

        return [
            'total' => $total,
            'success' => $success,
            'failure' => $failure,
            'success_rate' => $total > 0 ? round($success / $total, 4) : 1.0,
            'avg_latency_ms' => round($avgLatency, 2),
            'p50_latency_ms' => $this->percentile($sorted, 50),
            'p95_latency_ms' => $this->percentile($sorted, 95),
            'p99_latency_ms' => $this->percentile($sorted, 99),
            'slow_dispatches' => $slowDispatches,
            'error_budget_remaining' => round($errorBudgetRemaining, 4),
            'error_budget_breached' => $errorBudgetRemaining <= 0.0,
            'recent_errors' => $errorLog,
        ];
    }

    /**
     * Get per-event metrics for a specific provider.
     *
     * Aggregates success/failure counts and average latency
     * for each event name dispatched to the given provider.
     *
     * @param  string  $provider  Provider name
     * @return array<string, array{success: int, failure: int, total: int, success_rate: float, avg_latency_ms: float}>
     */
    public function getEventMetrics(string $provider): array
    {
        $pattern = self::CACHE_PREFIX . "{$provider}_event_";
        $prefixLength = strlen($pattern);

        $successKeys = $this->findKeys("{$provider}_event_");
        $eventMetrics = [];

        foreach ($successKeys as $key) {
            $suffix = substr($key, $prefixLength);
            if (str_ends_with($suffix, '_success')) {
                $eventName = substr($suffix, 0, -8);
                if (! isset($eventMetrics[$eventName])) {
                    $eventMetrics[$eventName] = ['success' => 0, 'failure' => 0];
                }
                $eventMetrics[$eventName]['success'] = $this->getCounter($key);
            } elseif (str_ends_with($suffix, '_failure')) {
                $eventName = substr($suffix, 0, -8);
                if (! isset($eventMetrics[$eventName])) {
                    $eventMetrics[$eventName] = ['success' => 0, 'failure' => 0];
                }
                $eventMetrics[$eventName]['failure'] = $this->getCounter($key);
            }
        }

        $result = [];
        foreach ($eventMetrics as $eventName => $counts) {
            $total = $counts['success'] + $counts['failure'];
            $latencyKey = "{$provider}_{$eventName}";
            $latencies = $this->getLatencySamples($latencyKey);
            $avgLatency = $this->calculateAverage($latencies);

            $result[$eventName] = [
                'success' => $counts['success'],
                'failure' => $counts['failure'],
                'total' => $total,
                'success_rate' => $total > 0 ? round($counts['success'] / $total, 4) : 1.0,
                'avg_latency_ms' => round($avgLatency, 2),
            ];
        }

        return $result;
    }

    /**
     * Get filter pipeline metrics.
     *
     * Returns counts of events filtered by each pipeline filter.
     *
     * @return array<string, array{filtered: int, top_events: list<string>}>
     */
    public function getFilterMetrics(): array
    {
        $filterKeys = $this->findKeys('filter_');
        $filterMetrics = [];

        foreach ($filterKeys as $key) {
            $count = $this->getCounter($key);
            if ($count > 0) {
                $filterMetrics[$key] = ['filtered' => $count];
            }
        }

        return $filterMetrics;
    }

    /**
     * Get the dispatch volume timeline for a provider.
     *
     * Returns an array of time-binned dispatch counts for chart rendering.
     *
     * @param  string  $provider  Provider name
     * @param  int  $minutes  Number of minutes to look back (default: 60)
     * @return list<array{minute: string, success: int, failure: int}>
     */
    public function getDispatchTimeline(string $provider, int $minutes = 60): array
    {
        $timeline = [];
        $now = time();

        for ($i = $minutes - 1; $i >= 0; $i--) {
            $timestamp = $now - ($i * 60);
            $minuteKey = date('Y-m-d-H-i', $timestamp);
            $success = $this->getCounter("{$provider}_timeline_{$minuteKey}_success");
            $failure = $this->getCounter("{$provider}_timeline_{$minuteKey}_failure");

            $timeline[] = [
                'minute' => date('H:i', $timestamp),
                'success' => $success,
                'failure' => $failure,
            ];
        }

        return $timeline;
    }

    /**
     * Reset all observability metrics for a provider.
     *
     * Clears all counters, latency samples, and error logs.
     *
     * @param  string  $provider  Provider name
     */
    public function resetProvider(string $provider): void
    {
        $keys = $this->findKeys($provider);
        foreach ($keys as $key) {
            $this->cache->forget(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * Reset all observability metrics.
     */
    public function resetAll(): void
    {
        $allKeys = $this->findKeys('');
        foreach ($allKeys as $key) {
            $this->cache->forget(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * Check if a provider should be observed.
     *
     * If observedProviders is empty, all providers are observed.
     *
     * @param  string  $provider  Provider name
     */
    private function shouldObserve(string $provider): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->observedProviders === []) {
            return true;
        }

        return in_array($provider, $this->observedProviders, true);
    }

    /**
     * Increment a named counter.
     */
    private function incrementCounter(string $name): void
    {
        $this->registerKey($name);
        $key = self::CACHE_PREFIX . $name;
        $current = $this->getCounter($name);
        $this->cache->put($key, $current + 1, $this->ttl);
    }

    /**
     * Get a named counter value.
     */
    private function getCounter(string $name): int
    {
        $value = $this->cache->get(self::CACHE_PREFIX . $name);

        return is_int($value) ? $value : 0;
    }

    /**
     * Record a latency sample for a provider.
     *
     * Maintains a rolling window of latency samples using a
     * ring buffer stored in the cache.
     */
    private function recordLatency(string $provider, float $latencyMs): void
    {
        $this->registerKey("latency_{$provider}");
        $key = self::CACHE_PREFIX . "latency_{$provider}";
        /** @var list<float>|null $samples */
        $samples = $this->cache->get($key);

        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = $latencyMs;

        // Keep only the most recent samples
        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_SAMPLES);
        }

        $this->cache->put($key, $samples, $this->ttl);
    }

    /**
     * Get latency samples for a provider.
     *
     * @return list<float>
     */
    private function getLatencySamples(string $provider): array
    {
        $key = self::CACHE_PREFIX . "latency_{$provider}";
        /** @var list<float>|null $samples */
        $samples = $this->cache->get($key);

        return is_array($samples) ? $samples : [];
    }

    /**
     * Append an error log entry for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $errorType  Error classification
     * @param  string|null  $errorMessage  Human-readable error message
     */
    private function appendErrorLog(string $provider, string $errorType, ?string $errorMessage): void
    {
        $this->registerKey("errors_{$provider}");
        $key = self::CACHE_PREFIX . "errors_{$provider}";
        /** @var list<array{type: string, message: string|null, timestamp: int}>|null $errors */
        $errors = $this->cache->get($key);

        if (! is_array($errors)) {
            $errors = [];
        }

        $errors[] = [
            'type' => $errorType,
            'message' => $errorMessage,
            'timestamp' => time(),
        ];

        // Keep only the most recent errors
        if (count($errors) > self::MAX_ERROR_LOG) {
            $errors = array_slice($errors, -self::MAX_ERROR_LOG);
        }

        $this->cache->put($key, $errors, $this->ttl);
    }

    /**
     * Get error log entries for a provider.
     *
     * @return list<array{type: string, message: string|null, timestamp: int}>
     */
    private function getErrorLog(string $provider): array
    {
        $key = self::CACHE_PREFIX . "errors_{$provider}";
        /** @var list<array{type: string, message: string|null, timestamp: int}>|null $errors */
        $errors = $this->cache->get($key);

        return is_array($errors) ? $errors : [];
    }

    /**
     * Find all cache keys matching a prefix.
     *
     * @return list<string>
     */
    private function findKeys(string $prefix): array
    {
        // Since we don't have a reliable way to scan cache keys across all drivers,
        // we maintain a known-key index for observability
        $indexKey = self::CACHE_PREFIX . '_index';
        /** @var list<string>|null $index */
        $index = $this->cache->get($indexKey);

        return is_array($index) ? array_filter($index, fn (string $k): bool => $prefix === '' || str_starts_with($k, $prefix)) : [];
    }

    /**
     * Register a key in the observability index.
     */
    private function registerKey(string $name): void
    {
        $indexKey = self::CACHE_PREFIX . '_index';
        /** @var list<string>|null $index */
        $index = $this->cache->get($indexKey);

        if (! is_array($index)) {
            $index = [];
        }

        if (! in_array($name, $index, true)) {
            $index[] = $name;
            $this->cache->put($indexKey, $index, $this->ttl * 2);
        }
    }

    /**
     * Calculate the average of an array of floats.
     *
     * @param  list<float>  $values
     * @return float
     */
    private function calculateAverage(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Calculate the percentile from a sorted array of floats.
     *
     * @param  list<float>  $sorted  Must be sorted ascending
     * @param  float  $percentile  Percentile (0-100)
     * @return float
     */
    private function percentile(array $sorted, float $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) (ceil(($percentile / 100) * count($sorted))) - 1;
        $index = max(0, min($index, count($sorted) - 1));

        return round($sorted[$index], 2);
    }

    /**
     * Get the list of observed provider names.
     *
     * @return list<string>
     */
    private function getObservedProviderList(): array
    {
        if ($this->observedProviders !== []) {
            return $this->observedProviders;
        }

        return ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'webhook'];
    }
}
