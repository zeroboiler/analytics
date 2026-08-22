<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics pipeline performance profiler.
 *
 * Measures, records, and reports event dispatch latency across all providers,
 * event categories, and individual event names. Maintains cache-backed
 * performance telemetry for operational monitoring and alerting.
 *
 * Provides:
 * - Per-provider dispatch latency tracking (min, max, avg, p95, p99)
 * - Per-category dispatch volume and latency aggregation
 * - Slow event detection with configurable thresholds
 * - Performance degradation detection (latency trend analysis)
 * - Cache-backed telemetry with configurable TTL
 *
 * @since 137.0.0
 */
final class AnalyticsPipelineProfilerService
{
    private const CACHE_PREFIX = 'zb_profiler_';

    private const CACHE_TTL = 3600;

    private const MAX_SAMPLES = 1000;

    private const LATENCY_BUCKETS = [
        'fast' => 50,       // < 50ms
        'normal' => 200,    // < 200ms
        'slow' => 500,      // < 500ms
        'very_slow' => 1000, // < 1000ms
        'timeout' => PHP_INT_MAX, // >= 1000ms
    ];

    private const LATENCY_BUCKET_LABELS = [
        'fast' => '<50ms',
        'normal' => '<200ms',
        'slow' => '<500ms',
        'very_slow' => '<1s',
        'timeout' => '>=1s',
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private float $slowThresholdMs;

    private float $criticalThresholdMs;

    private int $cacheTtl;

    private int $maxSamples;

    /** @var array<string, list<int>> In-memory latency samples (per provider) */
    private array $providerSamples = [];

    /** @var array<string, list<int>> In-memory latency samples (per category) */
    private array $categorySamples = [];

    /** @var int Total dispatched events in this request cycle */
    private int $requestDispatchCount = 0;

    /** @var float Total dispatch latency in this request cycle */
    private float $requestTotalLatency = 0.0;

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  CacheRepository  $cache  Cache repository for telemetry persistence
     * @param  array{slow_threshold_ms?: float, critical_threshold_ms?: float, cache_ttl?: int, max_samples?: int}  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        array $config = [],
    ){
        $this->manager = $manager;
        $this->cache = $cache;
        $this->slowThresholdMs = (float) ($config['slow_threshold_ms'] ?? 500.0);
        $this->criticalThresholdMs = (float) ($config['critical_threshold_ms'] ?? 1000.0);
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? self::CACHE_TTL);
        $this->maxSamples = (int) ($config['max_samples'] ?? self::MAX_SAMPLES);
    }

    /**
     * Record a dispatch measurement for a provider.
     *
     * Call this after each provider dispatch to track latency.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, plausible, mixpanel, amplitude, tiktok, linkedin)
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     * @param  string  $eventName  The event name that was dispatched
     * @param  bool  $success  Whether the dispatch succeeded
     */
    public function record(string $provider, float $latencyMs, string $eventName, bool $success = true): void
    {
        $category = EventCatalog::getCategory($eventName) ?? 'custom';

        // In-memory samples
        if (! isset($this->providerSamples[$provider])) {
            $this->providerSamples[$provider] = [];
        }
        $this->providerSamples[$provider][] = (int) ($latencyMs * 1000);

        // Trim to max samples
        if (count($this->providerSamples[$provider]) > $this->maxSamples) {
            $this->providerSamples[$provider] = array_slice(
                $this->providerSamples[$provider],
                -$this->maxSamples,
            );
        }

        if (! isset($this->categorySamples[$category])) {
            $this->categorySamples[$category] = [];
        }
        $this->categorySamples[$category][] = (int) ($latencyMs * 1000);

        if (count($this->categorySamples[$category]) > $this->maxSamples) {
            $this->categorySamples[$category] = array_slice(
                $this->categorySamples[$category],
                -$this->maxSamples,
            );
        }

        // Request cycle totals
        $this->requestDispatchCount++;
        $this->requestTotalLatency += $latencyMs;

        // Persist to cache
        $this->persistProviderSample($provider, (int) ($latencyMs * 1000), $success);
        $this->persistCategorySample($category, (int) ($latencyMs * 1000));
        $this->persistEventSample($eventName, (int) ($latencyMs * 1000), $success);

        // Slow event detection
        if ($latencyMs >= $this->slowThresholdMs) {
            $this->recordSlowEvent($provider, $eventName, $latencyMs);
        }

        // Critical threshold
        if ($latencyMs >= $this->criticalThresholdMs) {
            Log::warning('AnalyticsPipelineProfiler: critical dispatch latency', [
                'provider' => $provider,
                'event' => $eventName,
                'latency_ms' => $latencyMs,
            ]);
        }
    }

    /**
     * Get the provider latency profile (min, max, avg, p95, p99, count, bucket distribution).
     *
     * @param  string|null  $provider  Specific provider or null for all
     * @return array{count: int, min: float, max: float, avg: float, p50: float, p95: float, p99: float, buckets: array<string, int>, slow_count: int, critical_count: int}
     */
    public function providerProfile(?string $provider = null): array
    {
        if ($provider !== null) {
            $samples = $this->loadSamples(self::CACHE_PREFIX . "provider:{$provider}");
            $local = $this->providerSamples[$provider] ?? [];

            return $this->computeProfile(array_merge($samples, $local));
        }

        $profiles = [];
        foreach ($this->getKnownProviders() as $p) {
            $profiles[$p] = $this->providerProfile($p);
        }

        return $profiles;
    }

    /**
     * Get the category latency profile.
     *
     * @param  string|null  $category  Specific category or null for all
     * @return array<string, array{count: int, min: float, max: float, avg: float, p95: float, buckets: array<string, int>}>
     */
    public function categoryProfile(?string $category = null): array
    {
        $categories = array_keys(EventCatalog::byCategory());

        if ($category !== null) {
            $samples = $this->loadSamples(self::CACHE_PREFIX . "category:{$category}");
            $local = $this->categorySamples[$category] ?? [];

            return $this->computeProfile(array_merge($samples, $local));
        }

        $profiles = [];
        foreach ($categories as $cat) {
            $profiles[$cat] = $this->categoryProfile($cat);
        }

        return $profiles;
    }

    /**
     * Get a list of slow events exceeding the threshold.
     *
     * @param  int  $limit  Maximum number of slow events to return
     * @return list<array{provider: string, event: string, latency_ms: float, timestamp: int}>
     */
    public function slowEvents(int $limit = 50): array
    {
        /** @var list<array{provider: string, event: string, latency_ms: float, timestamp: int}> $slowEvents */
        $slowEvents = $this->cache->get(self::CACHE_PREFIX . 'slow_events', []);

        return array_slice($slowEvents, 0, $limit);
    }

    /**
     * Get request-cycle dispatch summary (current request only).
     *
     * @return array{dispatch_count: int, total_latency_ms: float, avg_latency_ms: float}
     */
    public function requestSummary(): array
    {
        return [
            'dispatch_count' => $this->requestDispatchCount,
            'total_latency_ms' => round($this->requestTotalLatency, 2),
            'avg_latency_ms' => $this->requestDispatchCount > 0
                ? round($this->requestTotalLatency / $this->requestDispatchCount, 2)
                : 0.0,
        ];
    }

    /**
     * Get the full pipeline health dashboard.
     *
     * Aggregates provider profiles, category profiles, slow events,
     * and request-cycle metrics into a single dashboard view.
     *
     * @return array{version: string, providers: array<string, array>, categories: array<string, array>, slow_events: list<array>, slow_threshold_ms: float, critical_threshold_ms: float, request: array, degraded_providers: list<string>}
     */
    public function dashboard(): array
    {
        $providerProfiles = $this->providerProfile();
        $categoryProfiles = $this->categoryProfile();

        $degradedProviders = [];
        foreach ($providerProfiles as $provider => $profile) {
            if (($profile['p95'] ?? 0) >= $this->slowThresholdMs) {
                $degradedProviders[] = $provider;
            }
        }

        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'providers' => $providerProfiles,
            'categories' => $categoryProfiles,
            'slow_events' => $this->slowEvents(10),
            'slow_threshold_ms' => $this->slowThresholdMs,
            'critical_threshold_ms' => $this->criticalThresholdMs,
            'request' => $this->requestSummary(),
            'degraded_providers' => $degradedProviders,
        ];
    }

    /**
     * Clear all profiler data from cache and memory.
     */
    public function flush(): void
    {
        $this->providerSamples = [];
        $this->categorySamples = [];
        $this->requestDispatchCount = 0;
        $this->requestTotalLatency = 0.0;

        foreach ($this->getKnownProviders() as $provider) {
            $this->cache->forget(self::CACHE_PREFIX . "provider:{$provider}");
        }

        foreach (array_keys(EventCatalog::byCategory()) as $category) {
            $this->cache->forget(self::CACHE_PREFIX . "category:{$category}");
        }

        $this->cache->forget(self::CACHE_PREFIX . 'slow_events');
    }

    /**
     * Check if profiling is enabled.
     *
     * Reads from the analytics pipeline_profiler config section.
     */
    public function isEnabled(): bool
    {
        $config = app('config');

        return (bool) $config->get('zeroboiler.analytics.pipeline_profiler.enabled', false);
    }

    /**
     * Get the configured slow threshold.
     */
    public function getSlowThreshold(): float
    {
        return $this->slowThresholdMs;
    }

    /**
     * Get the configured critical threshold.
     */
    public function getCriticalThreshold(): float
    {
        return $this->criticalThresholdMs;
    }

    /**
     * Persist a provider sample to cache.
     */
    private function persistProviderSample(string $provider, int $latencyMicros, bool $success): void
    {
        $key = self::CACHE_PREFIX . "provider:{$provider}";
        $this->appendSample($key, $latencyMicros);

        // Track failure count
        if (! $success) {
            $failKey = self::CACHE_PREFIX . "provider:{$provider}:failures";
            /** @var int $failCount */
            $failCount = $this->cache->get($failKey, 0);
            $this->cache->put($failKey, $failCount + 1, $this->cacheTtl);
        }
    }

    /**
     * Persist a category sample to cache.
     */
    private function persistCategorySample(string $category, int $latencyMicros): void
    {
        $key = self::CACHE_PREFIX . "category:{$category}";
        $this->appendSample($key, $latencyMicros);
    }

    /**
     * Persist an event-level sample to cache.
     */
    private function persistEventSample(string $eventName, int $latencyMicros, bool $success): void
    {
        $key = self::CACHE_PREFIX . "event:{$eventName}";
        $this->appendSample($key, $latencyMicros);
    }

    /**
     * Append a latency sample to a cache-backed ring buffer.
     */
    private function appendSample(string $key, int $value): void
    {
        /** @var list<int> $samples */
        $samples = $this->cache->get($key, []);
        $samples[] = $value;

        if (count($samples) > $this->maxSamples) {
            $samples = array_slice($samples, -$this->maxSamples);
        }

        $this->cache->put($key, $samples, $this->cacheTtl);
    }

    /**
     * Load samples from cache.
     *
     * @return list<int>
     */
    private function loadSamples(string $key): array
    {
        /** @var list<int> $samples */
        $samples = $this->cache->get($key, []);

        return is_array($samples) ? $samples : [];
    }

    /**
     * Record a slow event in the cache-backed ring buffer.
     */
    private function recordSlowEvent(string $provider, string $eventName, float $latencyMs): void
    {
        $key = self::CACHE_PREFIX . 'slow_events';
        /** @var list<array{provider: string, event: string, latency_ms: float, timestamp: int}> $slowEvents */
        $slowEvents = $this->cache->get($key, []);
        $slowEvents[] = [
            'provider' => $provider,
            'event' => $eventName,
            'latency_ms' => round($latencyMs, 1),
            'timestamp' => time(),
        ];

        // Keep last 200 slow events
        if (count($slowEvents) > 200) {
            $slowEvents = array_slice($slowEvents, -200);
        }

        $this->cache->put($key, $slowEvents, $this->cacheTtl * 2);
    }

    /**
     * Compute statistical profile from latency samples.
     *
     * @param  list<int>  $samples  Latency samples in microseconds
     * @return array{count: int, min: float, max: float, avg: float, p50: float, p95: float, p99: float, buckets: array<string, int>, slow_count: int, critical_count: int}
     */
    private function computeProfile(array $samples): array
    {
        if ($samples === []) {
            return [
                'count' => 0,
                'min' => 0.0,
                'max' => 0.0,
                'avg' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
                'buckets' => [],
                'slow_count' => 0,
                'critical_count' => 0,
            ];
        }

        sort($samples);

        $count = count($samples);
        $minMs = $samples[0] / 1000.0;
        $maxMs = $samples[$count - 1] / 1000.0;
        $avgMs = array_sum($samples) / $count / 1000.0;

        $p50Ms = $samples[(int) floor($count * 0.50)] / 1000.0;
        $p95Ms = $samples[(int) floor($count * 0.95)] / 1000.0;
        $p99Ms = $samples[(int) floor($count * 0.99)] / 1000.0;

        // Bucket distribution (convert to ms for comparison)
        $buckets = array_fill_keys(array_keys(self::LATENCY_BUCKET_LABELS), 0);
        $slowCount = 0;
        $criticalCount = 0;

        foreach ($samples as $sampleMicros) {
            $ms = $sampleMicros / 1000.0;

            foreach (self::LATENCY_BUCKETS as $bucket => $threshold) {
                if ($ms < $threshold) {
                    $buckets[$bucket]++;
                    break;
                }
            }

            if ($ms >= $this->slowThresholdMs) {
                $slowCount++;
            }

            if ($ms >= $this->criticalThresholdMs) {
                $criticalCount++;
            }
        }

        return [
            'count' => $count,
            'min' => round($minMs, 2),
            'max' => round($maxMs, 2),
            'avg' => round($avgMs, 2),
            'p50' => round($p50Ms, 2),
            'p95' => round($p95Ms, 2),
            'p99' => round($p99Ms, 2),
            'buckets' => $buckets,
            'slow_count' => $slowCount,
            'critical_count' => $criticalCount,
        ];
    }

    /**
     * Get known provider names.
     *
     * @return list<string>
     */
    private function getKnownProviders(): array
    {
        return ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
    }
}
