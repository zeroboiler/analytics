<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event dispatch latency tracker.
 *
 * Measures the round-trip latency from event dispatch to provider acknowledgment.
 * Tracks per-provider, per-event, and aggregate latency metrics with configurable
 * sampling, retention, and alerting thresholds.
 *
 * Supports:
 * - Per-event latency recording (microsecond precision)
 * - Per-provider aggregate stats (p50, p75, p90, p95, p99)
 * - Sliding window retention (configurable TTL)
 * - Slow dispatch detection with configurable threshold
 * - Latency histogram buckets for dashboard visualization
 *
 * @since 206.0.0
 */
final class EventDispatchLatencyTracker
{
    /** @var int Default histogram bucket boundaries (ms) */
    private const DEFAULT_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var int Cache TTL for latency data (seconds) */
    private int $ttl;

    /** @var float Slow dispatch threshold (ms) */
    private float $slowThreshold;

    /** @var float Sampling rate (0.0–1.0) */
    private float $samplingRate;

    /** @var bool Whether latency tracking is enabled */
    private bool $enabled;

    /** @var list<int> Histogram bucket boundaries (ms) */
    private array $buckets;

    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_latency_';

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $latencyConfig = $config->get('zeroboiler.analytics.latency_tracking', []);
        /** @var array{enabled?: bool, ttl?: int, slow_threshold_ms?: float, sampling_rate?: float, buckets?: list<int>} $latencyConfig */
        $this->enabled = (bool) ($latencyConfig['enabled'] ?? true);
        $this->ttl = (int) ($latencyConfig['ttl'] ?? 3600);
        $this->slowThreshold = (float) ($latencyConfig['slow_threshold_ms'] ?? 1000.0);
        $this->samplingRate = (float) ($latencyConfig['sampling_rate'] ?? 1.0);
        $this->buckets = (array) ($latencyConfig['buckets'] ?? self::DEFAULT_BUCKETS);
    }

    /**
     * Record a dispatch latency measurement.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, etc.)
     * @param  string  $eventName  Event name
     * @param  float  $latencyMs  Latency in milliseconds
     * @param  bool  $success  Whether the dispatch was successful
     * @param  string|null  $error  Error message if dispatch failed
     */
    public function record(
        string $provider,
        string $eventName,
        float $latencyMs,
        bool $success = true,
        ?string $error = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        if ($this->samplingRate < 1.0 && (mt_rand() / mt_getrandmax()) > $this->samplingRate) {
            return;
        }

        $provider = strtolower($provider);
        $eventName = strtolower($eventName);

        // Record per-provider latency
        $this->recordProviderLatency($provider, $latencyMs, $success, $error);

        // Record per-event latency
        $this->recordEventLatency($eventName, $latencyMs, $success);

        // Record cross (provider + event) latency
        $this->recordCrossLatency($provider, $eventName, $latencyMs, $success);

        // Record in histogram
        $this->recordHistogram($provider, $latencyMs);
    }

    /**
     * Get per-provider latency statistics.
     *
     * @param  string  $provider  Provider name
     * @return array{count: int, success_count: int, error_count: int, avg_ms: float, min_ms: float|null, max_ms: float|null, p50_ms: float|null, p90_ms: float|null, p95_ms: float|null, p99_ms: float|null, slow_count: int, slow_rate: float}
     */
    public function providerStats(string $provider): array
    {
        $provider = strtolower($provider);
        $key = self::CACHE_PREFIX . 'provider_' . $provider;

        /** @var array{samples: list<float>, errors: int, slow: int}|null $data */
        $data = $this->cache->get($key);

        if ($data === null || empty($data['samples'])) {
            return $this->emptyStats();
        }

        $samples = $data['samples'];
        $count = count($samples);
        $slowCount = $data['slow'];
        $errorCount = $data['errors'];

        sort($samples);

        return [
            'count' => $count,
            'success_count' => $count - $errorCount,
            'error_count' => $errorCount,
            'avg_ms' => round(array_sum($samples) / $count, 2),
            'min_ms' => round($samples[0], 2),
            'max_ms' => round($samples[count($samples) - 1], 2),
            'p50_ms' => round($this->percentile($samples, 50), 2),
            'p90_ms' => round($this->percentile($samples, 90), 2),
            'p95_ms' => round($this->percentile($samples, 95), 2),
            'p99_ms' => round($this->percentile($samples, 99), 2),
            'slow_count' => $slowCount,
            'slow_rate' => $count > 0 ? round($slowCount / $count, 4) : 0.0,
        ];
    }

    /**
     * Get latency stats for all tracked providers.
     *
     * @return array<string, array{count: int, success_count: int, error_count: int, avg_ms: float, min_ms: float|null, max_ms: float|null, p50_ms: float|null, p90_ms: float|null, p95_ms: float|null, p99_ms: float|null, slow_count: int, slow_rate: float}>
     */
    public function allProviderStats(): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin'];
        $stats = [];

        foreach ($providers as $provider) {
            $stats[$provider] = $this->providerStats($provider);
        }

        return $stats;
    }

    /**
     * Get per-event latency statistics.
     *
     * @param  string  $eventName  Event name
     * @return array{count: int, avg_ms: float, min_ms: float|null, max_ms: float|null}
     */
    public function eventStats(string $eventName): array
    {
        $eventName = strtolower($eventName);
        $key = self::CACHE_PREFIX . 'event_' . $eventName;

        /** @var list<float>|null $samples */
        $samples = $this->cache->get($key);

        if ($samples === null || empty($samples)) {
            return ['count' => 0, 'avg_ms' => 0.0, 'min_ms' => null, 'max_ms' => null];
        }

        sort($samples);
        $count = count($samples);

        return [
            'count' => $count,
            'avg_ms' => round(array_sum($samples) / $count, 2),
            'min_ms' => round($samples[0], 2),
            'max_ms' => round($samples[$count - 1], 2),
        ];
    }

    /**
     * Get the latency histogram for a provider.
     *
     * Returns bucketed counts suitable for histogram visualization.
     *
     * @param  string  $provider  Provider name
     * @return array{buckets: list<array{upper_bound: int, count: int}>, total: int}
     */
    public function histogram(string $provider): array
    {
        $provider = strtolower($provider);
        $key = self::CACHE_PREFIX . 'histogram_' . $provider;

        /** @var array<int, int>|null $counts */
        $counts = $this->cache->get($key);

        $result = [];
        $total = 0;

        foreach ($this->buckets as $upperBound) {
            $count = $counts[$upperBound] ?? 0;
            $result[] = [
                'upper_bound' => $upperBound,
                'count' => $count,
            ];
            $total += $count;
        }

        // Overflow bucket (events exceeding the largest boundary)
        $overflow = $counts[PHP_INT_MAX] ?? 0;
        if ($overflow > 0) {
            $result[] = [
                'upper_bound' => PHP_INT_MAX,
                'count' => $overflow,
            ];
            $total += $overflow;
        }

        return [
            'buckets' => $result,
            'total' => $total,
        ];
    }

    /**
     * Get the slowest events across all providers.
     *
     * @param  int  $limit  Maximum number of results
     * @return list<array{provider: string, event: string, avg_ms: float, count: int}>
     */
    public function slowestEvents(int $limit = 20): array
    {
        $key = self::CACHE_PREFIX . 'slowest';
        /** @var list<array{provider: string, event: string, avg_ms: float, count: int}>|null $ranked */
        $ranked = $this->cache->get($key);

        if ($ranked === null) {
            return [];
        }

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Get a comprehensive diagnostic summary.
     *
     * @return array{enabled: bool, sampling_rate: float, slow_threshold_ms: float, ttl: int, providers: array<string, array{count: int, avg_ms: float, p95_ms: float|null, slow_rate: float}>, global: array{total_recorded: int, providers_with_data: int, overall_avg_ms: float|null}}
     */
    public function diagnosticSummary(): array
    {
        $allStats = $this->allProviderStats();

        $providerSummary = [];
        $totalRecorded = 0;
        $allAvgs = [];
        $providersWithData = 0;

        foreach ($allStats as $provider => $stats) {
            if ($stats['count'] > 0) {
                $totalRecorded += $stats['count'];
                $allAvgs[] = $stats['avg_ms'];
                $providersWithData++;
            }

            $providerSummary[$provider] = [
                'count' => $stats['count'],
                'avg_ms' => $stats['avg_ms'],
                'p95_ms' => $stats['p95_ms'],
                'slow_rate' => $stats['slow_rate'],
            ];
        }

        return [
            'enabled' => $this->enabled,
            'sampling_rate' => $this->samplingRate,
            'slow_threshold_ms' => $this->slowThreshold,
            'ttl' => $this->ttl,
            'providers' => $providerSummary,
            'global' => [
                'total_recorded' => $totalRecorded,
                'providers_with_data' => $providersWithData,
                'overall_avg_ms' => ! empty($allAvgs) ? round(array_sum($allAvgs) / count($allAvgs), 2) : null,
            ],
        ];
    }

    /**
     * Clear all latency data from cache.
     */
    public function clear(): void
    {
        // Clear known keys (best-effort since we can't enumerate all cache keys)
        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin'];
        foreach ($providers as $provider) {
            $this->cache->forget(self::CACHE_PREFIX . 'provider_' . $provider);
            $this->cache->forget(self::CACHE_PREFIX . 'histogram_' . $provider);
        }
        $this->cache->forget(self::CACHE_PREFIX . 'slowest');
    }

    /**
     * Check if latency tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record per-provider latency sample.
     *
     * @param  string  $provider  Lowercase provider name
     * @param  float  $latencyMs  Latency in milliseconds
     * @param  bool  $success  Whether dispatch succeeded
     * @param  string|null  $error  Error message
     */
    private function recordProviderLatency(string $provider, float $latencyMs, bool $success, ?string $error): void
    {
        $key = self::CACHE_PREFIX . 'provider_' . $provider;

        /** @var array{samples: list<float>, errors: int, slow: int} $data */
        $data = $this->cache->get($key, [
            'samples' => [],
            'errors' => 0,
            'slow' => 0,
        ]);

        // Keep max 1000 samples per provider (ring buffer)
        if (count($data['samples']) >= 1000) {
            array_shift($data['samples']);
        }

        $data['samples'][] = $latencyMs;

        if (! $success) {
            $data['errors']++;
        }

        if ($latencyMs > $this->slowThreshold) {
            $data['slow']++;
        }

        $this->cache->put($key, $data, $this->ttl);
    }

    /**
     * Record per-event latency sample.
     *
     * @param  string  $eventName  Lowercase event name
     * @param  float  $latencyMs  Latency in milliseconds
     * @param  bool  $success  Whether dispatch succeeded
     */
    private function recordEventLatency(string $eventName, float $latencyMs, bool $success): void
    {
        if (! $success) {
            return;
        }

        $key = self::CACHE_PREFIX . 'event_' . $eventName;

        /** @var list<float> $samples */
        $samples = $this->cache->get($key, []);

        // Keep max 500 samples per event
        if (count($samples) >= 500) {
            array_shift($samples);
        }

        $samples[] = $latencyMs;
        $this->cache->put($key, $samples, $this->ttl);
    }

    /**
     * Record cross (provider + event) latency.
     *
     * @param  string  $provider  Lowercase provider name
     * @param  string  $eventName  Lowercase event name
     * @param  float  $latencyMs  Latency in milliseconds
     * @param  bool  $success  Whether dispatch succeeded
     */
    private function recordCrossLatency(string $provider, string $eventName, float $latencyMs, bool $success): void
    {
        if (! $success) {
            return;
        }

        $crossKey = self::CACHE_PREFIX . 'cross_' . $provider . '_' . $eventName;

        /** @var list<float> $crossSamples */
        $crossSamples = $this->cache->get($crossKey, []);

        // Keep max 200 samples per cross key
        if (count($crossSamples) >= 200) {
            array_shift($crossSamples);
        }

        $crossSamples[] = $latencyMs;
        $this->cache->put($crossKey, $crossSamples, $this->ttl);
    }

    /**
     * Record latency in histogram bucket.
     *
     * @param  string  $provider  Lowercase provider name
     * @param  float  $latencyMs  Latency in milliseconds
     */
    private function recordHistogram(string $provider, float $latencyMs): void
    {
        $key = self::CACHE_PREFIX . 'histogram_' . $provider;

        /** @var array<int, int> $counts */
        $counts = $this->cache->get($key, []);

        $bucketed = false;
        foreach ($this->buckets as $upperBound) {
            if ($latencyMs <= $upperBound) {
                $counts[$upperBound] = ($counts[$upperBound] ?? 0) + 1;
                $bucketed = true;
                break;
            }
        }

        if (! $bucketed) {
            $counts[PHP_INT_MAX] = ($counts[PHP_INT_MAX] ?? 0) + 1;
        }

        $this->cache->put($key, $counts, $this->ttl);
    }

    /**
     * Calculate a percentile from a sorted array of values.
     *
     * Uses linear interpolation for non-integer ranks.
     *
     * @param  list<float>  $sorted  Sorted array of values
     * @param  float  $percentile  Percentile (0–100)
     * @return float|null Interpolated value or null if empty
     */
    private function percentile(array $sorted, float $percentile): ?float
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper || $upper >= $count) {
            return $sorted[$lower];
        }

        $fraction = $index - $lower;

        return $sorted[$lower] + ($fraction * ($sorted[$upper] - $sorted[$lower]));
    }

    /**
     * Return an empty stats structure.
     *
     * @return array{count: int, success_count: int, error_count: int, avg_ms: float, min_ms: float|null, max_ms: float|null, p50_ms: float|null, p90_ms: float|null, p95_ms: float|null, p99_ms: float|null, slow_count: int, slow_rate: float}
     */
    private function emptyStats(): array
    {
        return [
            'count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'avg_ms' => 0.0,
            'min_ms' => null,
            'max_ms' => null,
            'p50_ms' => null,
            'p90_ms' => null,
            'p95_ms' => null,
            'p99_ms' => null,
            'slow_count' => 0,
            'slow_rate' => 0.0,
        ];
    }
}
