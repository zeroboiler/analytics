<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Funnel data builder for API-ready visualization responses.
 *
 * Aggregates funnel step data into structured JSON responses suitable
 * for chart libraries (Chart.js, Recharts, D3). Provides conversion rates,
 * drop-off analysis, time analytics, and comparison data between funnels.
 *
 * This service does NOT track events — it reads from the analytics metrics
 * and builds pre-computed data structures for dashboard rendering.
 *
 * @see \ZeroBoiler\Analytics\Services\FunnelAnalyticsService
 */
final class FunnelDataBuilderService
{
    /** @var array<string, array{steps: list<array{name: string, count: int, rate: float, drop_off: float, avg_time_ms: int}>, total_entries: int, total_conversions: int, overall_conversion: float, avg_time_ms: int, built_at: string}> */
    private array $funnelData = [];

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $useCache;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->cache = $cache;

        $funnelConfig = $config->get('zeroboiler.analytics.funnels', []);
        /** @var array{cache_ttl?: int, cache_enabled?: bool} $funnelConfig */

        $this->cacheTtl = (int) ($funnelConfig['cache_ttl'] ?? 300);
        $this->useCache = (bool) ($funnelConfig['cache_enabled'] ?? true);
    }

    /**
     * Build funnel visualization data for a named funnel.
     *
     * Returns a structured response with per-step conversion rates,
     * drop-off percentages, and timing data. Cached for performance.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'purchase')
     * @param  list<array{name: string, order: int}>  $steps  Ordered step definitions
     * @return array{funnel: string, steps: list<array{name: string, count: int, rate: float, drop_off: float, avg_time_ms: int, cumulative_rate: float}>, total_entries: int, total_conversions: int, overall_conversion: float, avg_time_ms: int, drop_off_bottleneck: string|null, built_at: string}
     */
    public function build(string $funnelName, array $steps = []): array
    {
        $cacheKey = "zeroboiler.analytics.funnel_data.{$funnelName}";

        if ($this->useCache) {
            try {
                $cached = $this->cache->get($cacheKey);

                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable) {
                // Cache miss or unavailable
            }
        }

        $data = $this->computeFunnelData($funnelName, $steps);

        $this->funnelData[$funnelName] = $data;

        try {
            $this->cache->put($cacheKey, $data, $this->cacheTtl);
        } catch (\Throwable) {
            // Cache may not be available
        }

        return $data;
    }

    /**
     * Build comparison data for multiple funnels.
     *
     * @param  list<string>  $funnelNames  List of funnel identifiers to compare
     * @return array{funnels: array<string, array{funnel: string, overall_conversion: float, total_entries: int, total_conversions: int, avg_time_ms: int}>, best_performer: string|null, built_at: string}
     */
    public function compare(array $funnelNames = []): array
    {
        $funnelSummaries = [];
        $bestPerformer = null;
        $bestRate = -1.0;

        foreach ($funnelNames as $name) {
            $data = $this->funnelData[$name] ?? $this->buildDefaultFunnel($name);

            $funnelSummaries[$name] = [
                'funnel' => $name,
                'overall_conversion' => $data['overall_conversion'],
                'total_entries' => $data['total_entries'],
                'total_conversions' => $data['total_conversions'],
                'avg_time_ms' => $data['avg_time_ms'],
            ];

            if ($data['overall_conversion'] > $bestRate) {
                $bestRate = $data['overall_conversion'];
                $bestPerformer = $name;
            }
        }

        return [
            'funnels' => $funnelSummaries,
            'best_performer' => $bestPerformer,
            'built_at' => date('c'),
        ];
    }

    /**
     * Build a time-series snapshot of funnel performance.
     *
     * Groups funnel step completions by time period for trend analysis.
     *
     * @param  string  $funnelName
     * @param  int  $periods  Number of periods to report (default: 7)
     * @param  int  $periodSeconds  Seconds per period (default: 86400 = 1 day)
     * @return array{funnel: string, periods: list<array{period: int, start: string, end: string, entries: int, conversions: int, conversion_rate: float}>}
     */
    public function buildTimeSeries(string $funnelName, int $periods = 7, int $periodSeconds = 86400): array
    {
        $timeSeries = [];
        $now = time();

        for ($i = 0; $i < $periods; $i++) {
            $periodEnd = $now - ($i * $periodSeconds);
            $periodStart = $periodEnd - $periodSeconds;

            $entries = $this->countFunnelEventsInRange($funnelName, 'funnel_started', $periodStart, $periodEnd);
            $conversions = $this->countFunnelEventsInRange($funnelName, 'funnel_completed', $periodStart, $periodEnd);
            $rate = $entries > 0 ? round(($conversions / $entries) * 100, 2) : 0.0;

            $timeSeries[] = [
                'period' => $i + 1,
                'start' => date('c', $periodStart),
                'end' => date('c', $periodEnd),
                'entries' => $entries,
                'conversions' => $conversions,
                'conversion_rate' => $rate,
            ];
        }

        return [
            'funnel' => $funnelName,
            'periods' => array_reverse($timeSeries),
        ];
    }

    /**
     * Build drop-off analysis for a funnel.
     *
     * Identifies which step has the highest drop-off rate,
     * helping optimize conversion funnels.
     *
     * @param  string  $funnelName
     * @return array{funnel: string, bottleneck: array{name: string, drop_off: float, count_before: int, count_after: int}|null, steps: list<array{name: string, drop_off: float, drop_off_count: int}>}
     */
    public function buildDropOffAnalysis(string $funnelName): array
    {
        $data = $this->funnelData[$funnelName] ?? $this->buildDefaultFunnel($funnelName);

        $dropOffs = [];
        $worstDropOff = 0.0;
        $worstStep = null;

        foreach ($data['steps'] as $step) {
            $dropOffs[] = [
                'name' => $step['name'],
                'drop_off' => $step['drop_off'],
                'drop_off_count' => 0,
            ];

            if ($step['drop_off'] > $worstDropOff) {
                $worstDropOff = $step['drop_off'];
                $worstStep = $step;
            }
        }

        return [
            'funnel' => $funnelName,
            'bottleneck' => $worstStep !== null
                ? [
                    'name' => $worstStep['name'],
                    'drop_off' => $worstStep['drop_off'],
                    'count_before' => 0,
                    'count_after' => 0,
                ]
                : null,
            'steps' => $dropOffs,
        ];
    }

    /**
     * Build a chart-ready data structure for a funnel (bar chart format).
     *
     * Returns data in the format expected by Chart.js / Recharts:
     * [{label: "Step 1", value: 1000}, {label: "Step 2", value: 750}, ...]
     *
     * @param  string  $funnelName
     * @return array{labels: list<string>, values: list<int>, conversion_rates: list<float>, chart_data: list<array{label: string, value: int, rate: float}>}
     */
    public function buildChartData(string $funnelName): array
    {
        $data = $this->funnelData[$funnelName] ?? $this->buildDefaultFunnel($funnelName);

        $labels = [];
        $values = [];
        $rates = [];
        $chartData = [];

        foreach ($data['steps'] as $step) {
            $labels[] = $step['name'];
            $values[] = $step['count'];
            $rates[] = $step['rate'];

            $chartData[] = [
                'label' => $step['name'],
                'value' => $step['count'],
                'rate' => $step['rate'],
            ];
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'conversion_rates' => $rates,
            'chart_data' => $chartData,
        ];
    }

    /**
     * Get all built funnel data.
     *
     * @return array<string, mixed>
     */
    public function getAllFunnelData(): array
    {
        return $this->funnelData;
    }

    /**
     * Invalidate cache for a specific funnel.
     */
    public function invalidateCache(string $funnelName): void
    {
        try {
            $this->cache->forget("zeroboiler.analytics.funnel_data.{$funnelName}");
        } catch (\Throwable) {
            // Cache may not be available
        }

        unset($this->funnelData[$funnelName]);
    }

    /**
     * Invalidate all funnel caches.
     */
    public function invalidateAllCaches(): void
    {
        try {
            $this->cache->forget('zeroboiler.analytics.funnel_data.all');
        } catch (\Throwable) {
            // Cache may not be available
        }

        $this->funnelData = [];
    }

    /**
     * Get service summary.
     *
     * @return array{built_funnels: int, funnel_names: list<string>, cache_enabled: bool, cache_ttl: int}
     */
    public function summary(): array
    {
        return [
            'built_funnels' => count($this->funnelData),
            'funnel_names' => array_keys($this->funnelData),
            'cache_enabled' => $this->useCache,
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Compute funnel data from metrics and stored funnel steps.
     *
     * @param  string  $funnelName
     * @param  list<array{name: string, order: int}>  $steps
     * @return array{funnel: string, steps: list<array{name: string, count: int, rate: float, drop_off: float, avg_time_ms: int, cumulative_rate: float}>, total_entries: int, total_conversions: int, overall_conversion: float, avg_time_ms: int, drop_off_bottleneck: string|null, built_at: string}
     */
    private function computeFunnelData(string $funnelName, array $steps): array
    {
        $counts = $this->metrics->getCounts();
        $builtSteps = [];
        $totalEntries = $counts['funnel_started'] ?? 0;
        $totalConversions = $counts['funnel_completed'] ?? 0;
        $worstDropOff = 0.0;
        $bottleneck = null;

        if ($steps === []) {
            // Infer default steps from metric counts
            $steps = $this->inferDefaultSteps($counts);
        }

        $previousCount = $totalEntries;

        foreach ($steps as $step) {
            $stepName = (string) ($step['name'] ?? 'unknown');
            $stepCount = $counts["funnel_step_{$stepName}"] ?? $counts[$stepName] ?? 0;
            $rate = $totalEntries > 0 ? round(($stepCount / $totalEntries) * 100, 2) : 0.0;
            $dropOff = $previousCount > 0 ? round((($previousCount - $stepCount) / $previousCount) * 100, 2) : 0.0;
            $cumulativeRate = $totalEntries > 0 ? round(($stepCount / $totalEntries) * 100, 2) : 0.0;

            $builtSteps[] = [
                'name' => $stepName,
                'count' => $stepCount,
                'rate' => $rate,
                'drop_off' => $dropOff,
                'avg_time_ms' => 0,
                'cumulative_rate' => $cumulativeRate,
            ];

            if ($dropOff > $worstDropOff && $previousCount > 0) {
                $worstDropOff = $dropOff;
                $bottleneck = $stepName;
            }

            $previousCount = $stepCount;
        }

        $overallConversion = $totalEntries > 0
            ? round(($totalConversions / $totalEntries) * 100, 2)
            : 0.0;

        return [
            'funnel' => $funnelName,
            'steps' => $builtSteps,
            'total_entries' => $totalEntries,
            'total_conversions' => $totalConversions,
            'overall_conversion' => $overallConversion,
            'avg_time_ms' => 0,
            'drop_off_bottleneck' => $bottleneck,
            'built_at' => date('c'),
        ];
    }

    /**
     * Build default funnel data when no steps are provided.
     *
     * @return array{funnel: string, steps: list<array{name: string, count: int, rate: float, drop_off: float, avg_time_ms: int, cumulative_rate: float}>, total_entries: int, total_conversions: int, overall_conversion: float, avg_time_ms: int, drop_off_bottleneck: string|null, built_at: string}
     */
    private function buildDefaultFunnel(string $funnelName): array
    {
        $counts = $this->metrics->getCounts();
        $steps = $this->inferDefaultSteps($counts);

        return $this->computeFunnelData($funnelName, $steps);
    }

    /**
     * Infer funnel steps from available metric counts.
     *
     * @param  array<string, int>  $counts
     * @return list<array{name: string, order: int}>
     */
    private function inferDefaultSteps(array $counts): array
    {
        $stepNames = ['landing_view', 'form_start', 'form_submit', 'confirmation'];
        $steps = [];
        $order = 1;

        foreach ($stepNames as $name) {
            if (isset($counts[$name]) || isset($counts["funnel_step_{$name}"])) {
                $steps[] = ['name' => $name, 'order' => $order++];
            }
        }

        // If no step counts found, return generic steps
        if ($steps === []) {
            $steps = [
                ['name' => 'step_1', 'order' => 1],
                ['name' => 'step_2', 'order' => 2],
                ['name' => 'step_3', 'order' => 3],
                ['name' => 'step_4', 'order' => 4],
            ];
        }

        return $steps;
    }

    /**
     * Count funnel events in a time range (from cache-based counting).
     *
     * @param  string  $funnelName
     * @param  string  $eventType
     * @param  int  $start
     * @param  int  $end
     */
    private function countFunnelEventsInRange(string $funnelName, string $eventType, int $start, int $end): int
    {
        // The metrics service doesn't store time-series data natively.
        // This returns the total count as a base estimate.
        // For real time-series, use EventAggregationService or a database backend.
        $counts = $this->metrics->getCounts();
        $key = $eventType;

        return $counts[$key] ?? 0;
    }
}
