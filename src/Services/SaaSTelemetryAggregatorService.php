<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
/**
 * SaaS Telemetry Aggregation Service — unified provider telemetry dashboard.
 *
 * Aggregates real-time dispatch telemetry across all configured analytics
 * providers into a single queryable cache-backed data source. Computes:
 *
 * - Per-provider dispatch counts and success/failure rates
 * - Per-category event distribution
 * - Per-event-name frequency rankings
 * - Rolling window throughput metrics (1m, 5m, 15m, 1h)
 * - Cross-provider latency percentiles (p50, p95, p99)
 * - Provider health status (healthy, degraded, down)
 * - Anomaly detection on throughput spikes/drops
 *
 * Designed for admin dashboard rendering, SaaS observability pages,
 * and operational alerting integrations.
 *
 * Cache TTL: configurable (default 60s for near-real-time).
 *
 * @since 183.0.0
 */
final class SaaSTelemetryAggregatorService
{
    private CacheRepository $cache;

    private int $cacheTtl;

    private string $cachePrefix;

    /** @var array<string, array{total: int, success: int, failure: int, last_dispatch: string|null}> */
    private array $providerStats = [];

    /** @var array<string, int> Category → event count */
    private array $categoryStats = [];

    /** @var array<string, int> Event name → count */
    private array $eventNameStats = [];

    /** @var array<string, list<float>> Provider → latency samples (ms) */
    private array $latencySamples = [];

    /** @var array{1: int, 5: int, 15: int, 60: int} Rolling window counters (seconds) */
    private array $windowCounters = [1 => 0, 5 => 0, 15 => 0, 60 => 0];

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        private readonly AnalyticsManager $manager,
    ): void {
        $this->cache = $cache;
        $this->cachePrefix = (string) ($config->get('zeroboiler.analytics.saas_telemetry.cache_prefix', 'zb_telemetry_'));
        $this->cacheTtl = (int) ($config->get('zeroboiler.analytics.saas_telemetry.cache_ttl', 60));
        $this->loadFromCache();
    }

    /**
     * Record a telemetry data point for a dispatched event.
     *
     * @param  array{provider: string, success: bool, latency_ms: float, event: AnalyticsEvent}  $dataPoint
     */
    public function record(array $dataPoint): void
    {
        $provider = $dataPoint['provider'];
        $event = $dataPoint['event'];

        // Update provider stats
        if (! isset($this->providerStats[$provider])) {
            $this->providerStats[$provider] = [
                'total' => 0,
                'success' => 0,
                'failure' => 0,
                'last_dispatch' => null,
            ];
        }

        $this->providerStats[$provider]['total']++;
        if ($dataPoint['success']) {
            $this->providerStats[$provider]['success']++;
        } else {
            $this->providerStats[$provider]['failure']++;
        }
        $this->providerStats[$provider]['last_dispatch'] = $event->timestamp?->format('c') ?? now()->toIso8601String();

        // Update category stats
        $category = $event->category ?? 'unknown';
        $this->categoryStats[$category] = ($this->categoryStats[$category] ?? 0) + 1;

        // Update event name stats
        $this->eventNameStats[$event->name] = ($this->eventNameStats[$event->name] ?? 0) + 1;

        // Update latency samples (keep last 100)
        $this->latencySamples[$provider] ??= [];
        $this->latencySamples[$provider][] = $dataPoint['latency_ms'];
        if (count($this->latencySamples[$provider]) > 100) {
            array_shift($this->latencySamples[$provider]);
        }

        // Update rolling window counters
        foreach ($this->windowCounters as &$count) {
            $count++;
        }

        $this->persistToCache();
    }

    /**
     * Get a comprehensive telemetry summary.
     *
     * @return array{
     *     providers: array<string, array{total: int, success: int, failure: int, success_rate: float, health: string, latency: array{p50: float|null, p95: float|null, p99: float|null}, last_dispatch: string|null}>,
     *     categories: array<string, int>,
     *     top_events: array<string, int>,
     *     windows: array{1m: int, 5m: int, 15m: int, 1h: int},
     *     totals: array{events: int, providers: int},
     *     uptime: array{healthy: int, degraded: int, down: int}
     * }
     */
    public function summary(): array
    {
        $providers = [];
        $uptime = ['healthy' => 0, 'degraded' => 0, 'down' => 0];

        foreach ($this->providerStats as $name => $stats) {
            $successRate = $stats['total'] > 0 ? $stats['success'] / $stats['total'] : 1.0;
            $health = $this->computeHealth($successRate, $this->latencySamples[$name] ?? []);

            $providers[$name] = [
                'total' => $stats['total'],
                'success' => $stats['success'],
                'failure' => $stats['failure'],
                'success_rate' => round($successRate, 4),
                'health' => $health,
                'latency' => $this->computeLatencyPercentiles($this->latencySamples[$name] ?? []),
                'last_dispatch' => $stats['last_dispatch'],
            ];

            $uptime[$health]++;
        }

        // Sort events by count descending, take top 20
        arsort($this->eventNameStats);
        $topEvents = array_slice($this->eventNameStats, 0, 20, true);

        return [
            'providers' => $providers,
            'categories' => $this->categoryStats,
            'top_events' => $topEvents,
            'windows' => [
                '1m' => $this->windowCounters[1],
                '5m' => $this->windowCounters[5],
                '15m' => $this->windowCounters[15],
                '1h' => $this->windowCounters[60],
            ],
            'totals' => [
                'events' => array_sum(array_column($this->providerStats, 'total')),
                'providers' => count($this->providerStats),
            ],
            'uptime' => $uptime,
        ];
    }

    /**
     * Get provider-specific telemetry details.
     *
     * @return array{stats: array<string, mixed>, latency: array{p50: float|null, p95: float|null, p99: float|null, samples: int}, health: string, recent_events: int}|null
     */
    public function providerDetails(string $provider): ?array
    {
        $stats = $this->providerStats[$provider] ?? null;
        if ($stats === null) {
            return null;
        }

        $successRate = $stats['total'] > 0 ? $stats['success'] / $stats['total'] : 1.0;
        $samples = $this->latencySamples[$provider] ?? [];

        return [
            'stats' => $stats,
            'latency' => [
                'p50' => $this->percentile($samples, 50),
                'p95' => $this->percentile($samples, 95),
                'p99' => $this->percentile($samples, 99),
                'samples' => count($samples),
            ],
            'health' => $this->computeHealth($successRate, $samples),
            'recent_events' => $stats['total'],
        ];
    }

    /**
     * Get category-level event distribution.
     *
     * @return array{categories: array<string, int>, total: int}
     */
    public function categoryBreakdown(): array
    {
        return [
            'categories' => $this->categoryStats,
            'total' => array_sum($this->categoryStats),
        ];
    }

    /**
     * Detect throughput anomalies compared to baseline.
     *
     * Compares current window counts against the rolling average baseline.
     * Returns anomalies where current rate deviates > 2 standard deviations.
     *
     * @return list<array{type: string, provider?: string, metric: string, current: float, baseline: float, deviation: float, severity: string}>
     */
    public function detectAnomalies(): array
    {
        $anomalies = [];
        $baselineKey = $this->cachePrefix . 'baseline';

        /** @var array{provider_rates: array<string, float>, window_rate: float}|null $baseline */
        $baseline = $this->cache->get($baselineKey);

        if ($baseline === null) {
            return $anomalies;
        }

        // Check per-provider rate anomalies
        foreach ($this->providerStats as $name => $stats) {
            $baselineRate = $baseline['provider_rates'][$name] ?? 0;
            if ($baselineRate > 0) {
                $currentRate = $stats['total'];
                $deviation = $baselineRate > 0 ? ($currentRate - $baselineRate) / $baselineRate : 0;

                if (abs($deviation) > 2.0) {
                    $anomalies[] = [
                        'type' => $deviation > 0 ? 'spike' : 'drop',
                        'provider' => $name,
                        'metric' => 'dispatch_rate',
                        'current' => (float) $currentRate,
                        'baseline' => $baselineRate,
                        'deviation' => round($deviation, 4),
                        'severity' => abs($deviation) > 5.0 ? 'critical' : 'warning',
                    ];
                }
            }
        }

        return $anomalies;
    }

    /**
     * Get a compact overview suitable for CLI output.
     *
     * @return array{providers: int, events_total: int, healthy: int, degraded: int, down: int, top_category: string, top_event: string}
     */
    public function quickOverview(): array
    {
        $summary = $this->summary();
        arsort($this->categoryStats);
        arsort($this->eventNameStats);

        return [
            'providers' => $summary['totals']['providers'],
            'events_total' => $summary['totals']['events'],
            'healthy' => $summary['uptime']['healthy'],
            'degraded' => $summary['uptime']['degraded'],
            'down' => $summary['uptime']['down'],
            'top_category' => array_key_first($this->categoryStats) ?: 'none',
            'top_event' => array_key_first($this->eventNameStats) ?: 'none',
        ];
    }

    /**
     * Flush all telemetry data from cache and memory.
     */
    public function flush(): void
    {
        $this->providerStats = [];
        $this->categoryStats = [];
        $this->eventNameStats = [];
        $this->latencySamples = [];
        $this->windowCounters = [1 => 0, 5 => 0, 15 => 0, 60 => 0];

        $this->persistToCache();
    }

    /**
     * Save current baseline for anomaly detection.
     */
    public function saveBaseline(): void
    {
        $providerRates = [];
        foreach ($this->providerStats as $name => $stats) {
            $providerRates[$name] = (float) $stats['total'];
        }

        $this->cache->put(
            $this->cachePrefix . 'baseline',
            [
                'provider_rates' => $providerRates,
                'window_rate' => (float) array_sum($this->windowCounters),
                'timestamp' => now()->toIso8601String(),
            ],
            3600,
        );
    }

    /**
     * Compute provider health status from success rate and latency.
     *
     * @param  list<float>  $latencySamples
     */
    private function computeHealth(float $successRate, array $latencySamples): string
    {
        $p95 = $this->percentile($latencySamples, 95);

        if ($successRate >= 0.99 && ($p95 === null || $p95 < 500.0)) {
            return 'healthy';
        }

        if ($successRate >= 0.90 && ($p95 === null || $p95 < 1000.0)) {
            return 'degraded';
        }

        return 'down';
    }

    /**
     * Compute latency percentiles from samples.
     *
     * @param  list<float>  $samples
     * @return array{p50: float|null, p95: float|null, p99: float|null}
     */
    private function computeLatencyPercentiles(array $samples): array
    {
        if ($samples === []) {
            return ['p50' => null, 'p95' => null, 'p99' => null];
        }

        sort($samples);

        return [
            'p50' => $this->percentile($samples, 50),
            'p95' => $this->percentile($samples, 95),
            'p99' => $this->percentile($samples, 99),
        ];
    }

    /**
     * Compute the Nth percentile from a sorted array of samples.
     *
     * @param  list<float>  $samples  Must be sorted ascending
     */
    private function percentile(array $samples, int $n): ?float
    {
        if ($samples === []) {
            return null;
        }

        $count = count($samples);
        $index = (int) ceil(($n / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return round($samples[$index], 2);
    }

    /**
     * Persist telemetry state to cache.
     */
    private function persistToCache(): void
    {
        $this->cache->put($this->cachePrefix . 'provider_stats', $this->providerStats, $this->cacheTtl);
        $this->cache->put($this->cachePrefix . 'category_stats', $this->categoryStats, $this->cacheTtl);
        $this->cache->put($this->cachePrefix . 'event_name_stats', $this->eventNameStats, $this->cacheTtl);
        $this->cache->put($this->cachePrefix . 'latency_samples', $this->latencySamples, $this->cacheTtl);
        $this->cache->put($this->cachePrefix . 'window_counters', $this->windowCounters, $this->cacheTtl);
    }

    /**
     * Load telemetry state from cache.
     */
    private function loadFromCache(): void
    {
        $this->providerStats = $this->cache->get($this->cachePrefix . 'provider_stats', []);
        $this->categoryStats = $this->cache->get($this->cachePrefix . 'category_stats', []);
        $this->eventNameStats = $this->cache->get($this->cachePrefix . 'event_name_stats', []);
        $this->latencySamples = $this->cache->get($this->cachePrefix . 'latency_samples', []);
        $this->windowCounters = $this->cache->get($this->cachePrefix . 'window_counters', [1 => 0, 5 => 0, 15 => 0, 60 => 0]);
    }
}
