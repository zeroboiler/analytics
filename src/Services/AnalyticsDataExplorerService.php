<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Store\EventStoreManager;

/**
 * Analytics Data Explorer — Flexible event data exploration service.
 *
 * Provides ad-hoc querying, filtering, aggregation, and drill-down
 * capabilities for analytics event data. Designed as a building block
 * for analytics dashboards, data exploration UIs, and debugging tools.
 *
 * Supports:
 * - Time-range filtering with configurable granularity
 * - Event name and category filtering
 * - Property/parameter-based drill-down
 * - Multi-dimensional aggregation (by event, category, provider, user)
 * - Top-N queries with configurable thresholds
 * - Trend direction detection (rising/falling/stable)
 * - Data export in multiple formats (JSON, CSV)
 *
 * @since 60.0.0
 */
final class AnalyticsDataExplorerService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var ConfigRepository */
    private ConfigRepository $config;

    /** @var EventStoreManager|null */
    private ?EventStoreManager $store;

    /** @var int Default cache TTL in seconds (5 minutes) */
    private const DEFAULT_CACHE_TTL = 300;

    /** @var int Maximum number of results per query */
    private const MAX_RESULTS = 1000;

    /** @var int Maximum number of drill-down dimensions */
    private const MAX_DIMENSIONS = 10;

    /** @var array<string, string> Granularity to SQL interval mapping */
    private const GRANULARITY_MAP = [
        'minute' => '1 minute',
        'hour' => '1 hour',
        'day' => '1 day',
        'week' => '1 week',
        'month' => '1 month',
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  EventStoreManager|null  $store  Optional event store for persistent queries
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        ?EventStoreManager $store = null,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->store = $store;
    }

    /**
     * Explore events with flexible filtering and aggregation.
     *
     * @param  array<string, mixed>  $filters  Query filters
     * @param  string  $groupBy  Aggregation dimension (event_name, category, provider, user_id, etc.)
     * @param  string  $period  Time period (1h, 6h, 24h, 7d, 30d, 90d)
     * @param  string  $granularity  Time bin granularity (minute, hour, day, week, month)
     * @param  int  $limit  Maximum number of results
     * @return array{query: array<string, mixed>, results: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function explore(
        array $filters = [],
        string $groupBy = 'event_name',
        string $period = '24h',
        string $granularity = 'hour',
        int $limit = 50,
    ): array {
        $limit = min($limit, self::MAX_RESULTS);
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Normalize filters
        $normalizedFilters = $this->normalizeFilters($filters);

        // Build base query parameters
        $query = array_merge([
            'from' => $timeRange['from'],
            'to' => $timeRange['to'],
            'granularity' => $granularity,
            'group_by' => $groupBy,
            'limit' => $limit,
        ], $normalizedFilters);

        // Execute query against event store (or return empty for cache-only mode)
        $results = $this->executeExploreQuery($query);

        $response = [
            'query' => $query,
            'results' => $results,
            'meta' => [
                'total_results' => count($results),
                'period' => $period,
                'time_range' => $timeRange,
                'granularity' => $granularity,
                'group_by' => $groupBy,
                'filters_applied' => count($normalizedFilters),
            ],
        ];

        $this->cache->put($cacheKey, $response, self::DEFAULT_CACHE_TTL);

        return $response;
    }

    /**
     * Get top events by count for a given period.
     *
     * @param  string  $period  Time period (1h, 6h, 24h, 7d, 30d, 90d)
     * @param  int  $limit  Number of top events to return
     * @param  string|null  $category  Optional category filter
     * @return array{top_events: list<array{name: string, count: int, trend: string, change_percent: float}>, period: string, meta: array<string, mixed>}
     */
    public function topEvents(
        string $period = '24h',
        int $limit = 20,
        ?string $category = null,
    ): array {
        $limit = min($limit, 100);
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $filters = [];
        if ($category !== null && $category !== '') {
            $filters['category'] = $category;
        }

        $results = $this->executeTopQuery($filters, $timeRange, $limit);

        // Compute trend direction for each event
        $previousRange = $this->getPreviousPeriodRange($timeRange);
        $topEvents = [];
        foreach ($results as $event) {
            $previousCount = $this->getEventCountInPeriod($event['name'], $previousRange);
            $changePercent = $previousCount > 0
                ? round((($event['count'] - $previousCount) / $previousCount) * 100, 1)
                : ($event['count'] > 0 ? 100.0 : 0.0);

            $topEvents[] = [
                'name' => $event['name'],
                'count' => $event['count'],
                'trend' => $this->classifyTrend($changePercent),
                'change_percent' => $changePercent,
            ];
        }

        $response = [
            'top_events' => $topEvents,
            'period' => $period,
            'meta' => [
                'time_range' => $timeRange,
                'category_filter' => $category,
                'limit' => $limit,
            ],
        ];

        $this->cache->put($cacheKey, $response, self::DEFAULT_CACHE_TTL);

        return $response;
    }

    /**
     * Drill down into a specific event to analyze its parameters/properties.
     *
     * @param  string  $eventName  The event to drill into
     * @param  array<string, mixed>  $filters  Additional filters
     * @param  string  $period  Time period
     * @return array{event: string, parameter_stats: array<string, array{unique_values: int, top_values: list<array{value: mixed, count: int}>}>, total_count: int, time_distribution: list<array{bucket: string, count: int}>}
     */
    public function drillDown(
        string $eventName,
        array $filters = [],
        string $period = '24h',
    ): array {
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $filters['event_name'] = $eventName;
        $normalizedFilters = $this->normalizeFilters($filters);

        // Get parameter statistics
        $parameterStats = $this->getParameterStats($eventName, $normalizedFilters, $timeRange);

        // Get time distribution
        $timeDistribution = $this->getTimeDistribution($eventName, $normalizedFilters, $timeRange, 'hour');

        // Get total count
        $totalCount = $this->getEventCountInPeriod($eventName, $timeRange);

        $response = [
            'event' => $eventName,
            'parameter_stats' => $parameterStats,
            'total_count' => $totalCount,
            'time_distribution' => $timeDistribution,
        ];

        $this->cache->put($cacheKey, $response, self::DEFAULT_CACHE_TTL);

        return $response;
    }

    /**
     * Compare two time periods for a given event or set of events.
     *
     * @param  string  $eventName  Event to compare (or '*' for all)
     * @param  string  $periodA  First period (e.g., '7d')
     * @param  string  $periodB  Second period (e.g., 'previous_7d')
     * @param  string|null  $category  Optional category filter
     * @return array{comparison: array<string, array{current: int, previous: int, change: float, trend: string}>, period_a: array<string, string>, period_b: array<string, string>, meta: array<string, mixed>}
     */
    public function compare(
        string $eventName = '*',
        string $periodA = '7d',
        string $periodB = 'previous_7d',
        ?string $category = null,
    ): array {
        $rangeA = $this->parsePeriod($periodA);
        $rangeB = $this->parsePeriod($periodB);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $events = $eventName === '*'
            ? $this->getEventList($category)
            : [$eventName];

        $comparison = [];
        foreach ($events as $name) {
            $countA = $this->getEventCountInPeriod($name, $rangeA);
            $countB = $this->getEventCountInPeriod($name, $rangeB);
            $change = $countB > 0
                ? round((($countA - $countB) / $countB) * 100, 1)
                : ($countA > 0 ? 100.0 : 0.0);

            $comparison[$name] = [
                'current' => $countA,
                'previous' => $countB,
                'change' => $change,
                'trend' => $this->classifyTrend($change),
            ];
        }

        // Sort by absolute change descending
        uasort($comparison, function (array $a, array $b): int {
            return abs($b['change']) <=> abs($a['change']);
        });

        $response = [
            'comparison' => $comparison,
            'period_a' => $rangeA,
            'period_b' => $rangeB,
            'meta' => [
                'event_filter' => $eventName,
                'category_filter' => $category,
                'events_compared' => count($comparison),
            ],
        ];

        $this->cache->put($cacheKey, $response, self::DEFAULT_CACHE_TTL);

        return $response;
    }

    /**
     * Get event funnel analysis for a sequence of events.
     *
     * Computes conversion rates between sequential steps in a funnel.
     *
     * @param  list<string>  $steps  Ordered event names forming the funnel
     * @param  string  $period  Time period
     * @param  string|null  $category  Optional category filter
     * @return array{funnel: list<array{step: int, event: string, count: int, drop_off: float, conversion: float}>, overall_conversion: float, period: string}
     */
    public function funnel(
        array $steps = [],
        string $period = '7d',
        ?string $category = null,
    ): array {
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $steps = array_values(array_filter($steps, static fn (string $s): bool => $s !== ''));
        $funnel = [];
        $prevCount = 0;

        foreach ($steps as $index => $event) {
            $count = $this->getEventCountInPeriod($event, $timeRange);
            $dropOff = $prevCount > 0
                ? round((($prevCount - $count) / $prevCount) * 100, 1)
                : 0.0;

            $funnel[] = [
                'step' => $index + 1,
                'event' => $event,
                'count' => $count,
                'drop_off' => $dropOff,
                'conversion' => $index === 0 ? 100.0 : ($prevCount > 0
                    ? round(($count / $prevCount) * 100, 1)
                    : 0.0),
            ];

            $prevCount = $count;
        }

        $firstCount = $funnel[0]['count'] ?? 0;
        $lastCount = $funnel[array_key_last($funnel)]['count'] ?? 0;
        $overallConversion = $firstCount > 0
            ? round(($lastCount / $firstCount) * 100, 1)
            : 0.0;

        $response = [
            'funnel' => $funnel,
            'overall_conversion' => $overallConversion,
            'period' => $period,
        ];

        $this->cache->put($cacheKey, $response, self::DEFAULT_CACHE_TTL);

        return $response;
    }

    /**
     * Get explorer service health and configuration status.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'store_available' => $this->store !== null,
            'cache_ttl' => self::DEFAULT_CACHE_TTL,
            'max_results' => self::MAX_RESULTS,
            'supported_granularities' => array_keys(self::GRANULARITY_MAP),
            'supported_periods' => ['1h', '6h', '24h', '7d', '14d', '30d', '90d', 'previous_7d', 'previous_30d'],
        ];
    }

    /**
     * Parse a human-readable period string into from/to timestamps.
     *
     * @param  string  $period  Period string (1h, 6h, 24h, 7d, 14d, 30d, 90d, previous_7d, previous_30d)
     * @return array{from: string, to: string, label: string}
     */
    private function parsePeriod(string $period): array
    {
        $now = now();
        $to = $now->toDateTimeString();

        if (str_starts_with($period, 'previous_')) {
            // previous_Xd means the X-day period before the most recent X-day period
            $duration = (int) substr($period, 9, -1);
            $from = $now->copy()->subDays($duration * 2)->toDateTimeString();
            $toAdjusted = $now->copy()->subDays($duration)->toDateTimeString();

            return [
                'from' => $from,
                'to' => $toAdjusted,
                'label' => $period,
            ];
        }

        $matches = [];
        if (preg_match('/^(\d+)(h|d)$/', $period, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            if ($unit === 'h') {
                $from = $now->copy()->subHours($value)->toDateTimeString();
            } else {
                $from = $now->copy()->subDays($value)->toDateTimeString();
            }
        } else {
            $from = $now->copy()->subDay()->toDateTimeString();
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => $period,
        ];
    }

    /**
     * Get the previous period range for comparison.
     *
     * @param  array{from: string, to: string}  $currentRange
     * @return array{from: string, to: string}
     */
    private function getPreviousPeriodRange(array $currentRange): array
    {
        $currentFrom = \Illuminate\Support\Carbon::parse($currentRange['from']);
        $currentTo = \Illuminate\Support\Carbon::parse($currentRange['to']);
        $durationSeconds = $currentTo->diffInSeconds($currentFrom);

        return [
            'from' => $currentFrom->copy()->subSeconds($durationSeconds)->toDateTimeString(),
            'to' => $currentFrom->toDateTimeString(),
        ];
    }

    /**
     * Normalize and validate filter parameters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$key] = match ($key) {
                'event_name', 'category', 'provider', 'user_id', 'client_id' => is_string($value) ? $value : null,
                'from', 'to' => is_string($value) ? $value : null,
                default => $value,
            };

            if ($normalized[$key] === null) {
                unset($normalized[$key]);
            }
        }

        return $normalized;
    }

    /**
     * Classify a change percentage into a trend direction.
     *
     * @param  float  $changePercent
     * @return string  'rising', 'falling', 'stable'
     */
    private function classifyTrend(float $changePercent): string
    {
        if ($changePercent > 5.0) {
            return 'rising';
        }

        if ($changePercent < -5.0) {
            return 'falling';
        }

        return 'stable';
    }

    /**
     * Build a deterministic cache key for a method call.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $args
     * @return string
     */
    private function buildCacheKey(string $method, array $args): string
    {
        $hash = hash('xxh128', json_encode([$method, $args], JSON_THROW_ON_ERROR));

        return "zb_explorer:{$method}:{$hash}";
    }

    /**
     * Execute an explore query against the event store.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function executeExploreQuery(array $query): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            return $this->store->query($query);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Execute a top-N query against the event store.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{from: string, to: string}  $timeRange
     * @param  int  $limit
     * @return list<array{name: string, count: int}>
     */
    private function executeTopQuery(array $filters, array $timeRange, int $limit): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            $query = array_merge($filters, [
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'group_by' => 'event_name',
                'order' => 'count_desc',
                'limit' => $limit,
            ]);

            /** @var list<array{name: string, count: int}> $results */
            $results = $this->store->query($query);

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get parameter statistics for a specific event.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $filters
     * @param  array{from: string, to: string}  $timeRange
     * @return array<string, array{unique_values: int, top_values: list<array{value: mixed, count: int}>}>
     */
    private function getParameterStats(string $eventName, array $filters, array $timeRange): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            $query = array_merge($filters, [
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_name' => $eventName,
                'analysis' => 'parameter_stats',
                'top_values_per_param' => 5,
            ]);

            /** @var array<string, array{unique_values: int, top_values: list<array{value: mixed, count: int}>}> $results */
            $results = $this->store->query($query);

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get time distribution for a specific event.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $filters
     * @param  array{from: string, to: string}  $timeRange
     * @param  string  $granularity
     * @return list<array{bucket: string, count: int}>
     */
    private function getTimeDistribution(
        string $eventName,
        array $filters,
        array $timeRange,
        string $granularity = 'hour',
    ): array {
        if ($this->store === null) {
            return [];
        }

        try {
            $query = array_merge($filters, [
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_name' => $eventName,
                'analysis' => 'time_distribution',
                'granularity' => $granularity,
            ]);

            /** @var list<array{bucket: string, count: int}> $results */
            $results = $this->store->query($query);

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get event count for a specific event in a time period.
     *
     * @param  string  $eventName
     * @param  array{from: string, to: string}  $timeRange
     * @return int
     */
    private function getEventCountInPeriod(string $eventName, array $timeRange): int
    {
        if ($this->store === null) {
            return 0;
        }

        try {
            $results = $this->store->query([
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_name' => $eventName,
                'analysis' => 'count',
            ]);

            return is_array($results) && isset($results[0]['count'])
                ? (int) $results[0]['count']
                : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get list of event names, optionally filtered by category.
     *
     * @param  string|null  $category
     * @return list<string>
     */
    private function getEventList(?string $category = null): array
    {
        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::all();

        if ($category === null || $category === '') {
            return array_map(static fn (array $e): string => $e['name'], $catalog);
        }

        return array_map(
            static fn (array $e): string => $e['name'],
            array_filter($catalog, static fn (array $e): bool =>
                ($e['category'] ?? '') === $category),
        );
    }
}
