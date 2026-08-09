<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Time-series event aggregation and trend analysis engine.
 *
 * Provides per-event and per-category aggregated statistics over
 * configurable time windows. Computes trends (direction, percentage
 * change) between consecutive windows for dashboard sparklines and
 * alerting.
 *
 * All aggregation is computed from the in-memory event stream (ring buffer).
 * For production-scale time-series, this service pairs with the EventStreamService
 * or an external time-series database via the DataWarehouseExportService.
 *
 * Aggregations available:
 *   - Event counts per name (hourly/daily window)
 *   - Category breakdown (ecommerce/saas/engagement)
 *   - Top events by volume
 *   - Unique identities seen per window
 *   - Trend direction (up/down/flat) with percentage change
 *   - Moving average over N windows
 *
 * @phpstan-type TimeBucket array{period: string, timestamp: string, events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>}
 * @phpstan-type TrendResult array{direction: 'up'|'down'|'flat', change_pct: float, current: int, previous: int}
 * @phpstan-type AggregationResult array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: TrendResult, moving_avg: float, period: string}
 *
 * @since 6.0.0
 */
final class EventTimeSeriesService
{
    private const CACHE_PREFIX = 'zb_ts_';

    private const DEFAULT_TTL = 300; // 5 minutes

    /** @var list<string> Supported aggregation periods */
    private const PERIODS = ['5m', '15m', '1h', '6h', '1d', '7d', '30d'];

    /** @var array<string, int> Period to seconds mapping */
    private const PERIOD_SECONDS = [
        '5m' => 300,
        '15m' => 900,
        '1h' => 3600,
        '6h' => 21600,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    private readonly int $cacheTtl;

    private readonly AnalyticsManager $manager;

    private readonly CacheRepository $cache;

    /**
     * @param  AnalyticsManager  $manager  Analytics manager for event queries
     * @param  CacheRepository  $cache  Cache store for aggregation persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $tsConfig = $config->get('zeroboiler.analytics.time_series', []);
        /** @var array{cache_ttl?: int} $tsConfig */
        $this->cacheTtl = (int) ($tsConfig['cache_ttl'] ?? self::DEFAULT_TTL);
    }

    /**
     * Aggregate events for a specific time period.
     *
     * Computes total event count, unique identities, top events by volume,
     * category breakdown, and trend direction relative to the previous period.
     *
     * @param  string  $period  Time period ('5m', '15m', '1h', '6h', '1d', '7d', '30d')
     * @return AggregationResult
     */
    public function aggregate(string $period = '1h'): array
    {
        $period = $this->validatePeriod($period);
        $cacheKey = self::CACHE_PREFIX . "agg_{$period}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['total_events'])) {
            return $cached;
        }

        $events = $this->getEventsInWindow($period);
        $previousEvents = $this->getEventsInPreviousWindow($period);

        $totalEvents = count($events);
        $uniqueIdentities = $this->countUniqueIdentities($events);
        $topEvents = $this->computeTopEvents($events);
        $categoryBreakdown = $this->computeCategoryBreakdown($events);
        $trend = $this->computeTrend(count($events), count($previousEvents));
        $movingAvg = $this->computeMovingAverage($period);

        $result = [
            'total_events' => $totalEvents,
            'unique_identities' => $uniqueIdentities,
            'top_events' => $topEvents,
            'category_breakdown' => $categoryBreakdown,
            'trend' => $trend,
            'moving_avg' => $movingAvg,
            'period' => $period,
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Aggregate a single event name over time.
     *
     * Returns event count, trend, and percentage of total events
     * for a specific event name.
     *
     * @param  string  $eventName  Exact event name
     * @param  string  $period  Time period
     * @return array{count: int, trend: TrendResult, pct_of_total: float, period: string, category: string|null}
     */
    public function aggregateEvent(string $eventName, string $period = '1h'): array
    {
        $period = $this->validatePeriod($period);
        $cacheKey = self::CACHE_PREFIX . "evt_{$eventName}_{$period}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['count'])) {
            return $cached;
        }

        $events = $this->getEventsInWindow($period);
        $previousEvents = $this->getEventsInPreviousWindow($period);

        $currentCount = count(array_filter($events, fn (array $e): bool => ($e['event'] ?? '') === $eventName));
        $previousCount = count(array_filter($previousEvents, fn (array $e): bool => ($e['event'] ?? '') === $eventName));
        $totalEvents = count($events);

        $result = [
            'count' => $currentCount,
            'trend' => $this->computeTrend($currentCount, $previousCount),
            'pct_of_total' => $totalEvents > 0 ? round(($currentCount / $totalEvents) * 100, 2) : 0.0,
            'period' => $period,
            'category' => EventCatalog::getCategory($eventName),
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Aggregate events by category.
     *
     * Returns event count per category with trend for each.
     *
     * @param  string  $period  Time period
     * @return array{categories: array<string, array{count: int, pct: float, trend: TrendResult}>, total: int, period: string}
     */
    public function aggregateByCategory(string $period = '1h'): array
    {
        $period = $this->validatePeriod($period);
        $cacheKey = self::CACHE_PREFIX . "cat_{$period}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['categories'])) {
            return $cached;
        }

        $events = $this->getEventsInWindow($period);
        $previousEvents = $this->getEventsInPreviousWindow($period);

        $categoryCounts = [];
        $previousCategoryCounts = [];

        foreach ($events as $entry) {
            $category = EventCatalog::getCategory($entry['event'] ?? '');
            if ($category !== null) {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }
        }

        foreach ($previousEvents as $entry) {
            $category = EventCatalog::getCategory($entry['event'] ?? '');
            if ($category !== null) {
                $previousCategoryCounts[$category] = ($previousCategoryCounts[$category] ?? 0) + 1;
            }
        }

        $total = count($events);
        $categories = [];

        foreach ($categoryCounts as $cat => $count) {
            $categories[$cat] = [
                'count' => $count,
                'pct' => $total > 0 ? round(($count / $total) * 100, 2) : 0.0,
                'trend' => $this->computeTrend($count, $previousCategoryCounts[$cat] ?? 0),
            ];
        }

        $result = [
            'categories' => $categories,
            'total' => $total,
            'period' => $period,
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get full dashboard time-series data across all supported periods.
     *
     * Returns aggregation data for each period in a single call,
     * optimized for admin dashboard rendering.
     *
     * @return array<string, AggregationResult>
     */
    public function dashboard(): array
    {
        $dashboard = [];

        foreach (self::PERIODS as $period) {
            $dashboard[$period] = $this->aggregate($period);
        }

        return $dashboard;
    }

    /**
     * Compare two time periods side-by-side.
     *
     * Returns current vs previous period metrics for A/B comparison.
     *
     * @param  string  $currentPeriod  Current period ('1h', '1d', etc.)
     * @param  string  $previousPeriod  Previous period (must be >= current)
     * @return array{current: AggregationResult, previous: AggregationResult, delta: array{events: int, identities: int, pct_change: float}}
     */
    public function compare(string $currentPeriod, string $previousPeriod): array
    {
        $current = $this->aggregate($currentPeriod);
        $previous = $this->aggregate($previousPeriod);

        $deltaEvents = $current['total_events'] - $previous['total_events'];
        $pctChange = $previous['total_events'] > 0
            ? round(($deltaEvents / $previous['total_events']) * 100, 2)
            : ($current['total_events'] > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => [
                'events' => $deltaEvents,
                'identities' => $current['unique_identities'] - $previous['unique_identities'],
                'pct_change' => $pctChange,
            ],
        ];
    }

    /**
     * Get the list of supported aggregation periods.
     *
     * @return list<string>
     */
    public function supportedPeriods(): array
    {
        return self::PERIODS;
    }

    /**
     * Get the seconds value for a period string.
     */
    public function periodToSeconds(string $period): int
    {
        return self::PERIOD_SECONDS[$period] ?? 3600;
    }

    /**
     * Get events within the current time window from the event stream.
     *
     * @param  string  $period  Time period
     * @return list<array<string, mixed>>
     */
    private function getEventsInWindow(string $period): array
    {
        try {
            $streamService = app(EventStreamService::class);
            $cutoff = now()->subSeconds(self::PERIOD_SECONDS[$period] ?? 3600)->toIso8601String();
            $recent = $streamService->getRecentEvents(1000);

            return array_filter(
                $recent,
                fn (array $e): bool => ($e['timestamp'] ?? '') >= $cutoff,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get events from the previous time window (before the current one).
     *
     * @param  string  $period  Time period
     * @return list<array<string, mixed>>
     */
    private function getEventsInPreviousWindow(string $period): array
    {
        try {
            $streamService = app(EventStreamService::class);
            $seconds = self::PERIOD_SECONDS[$period] ?? 3600;
            $startCutoff = now()->subSeconds($seconds * 2)->toIso8601String();
            $endCutoff = now()->subSeconds($seconds)->toIso8601String();
            $recent = $streamService->getRecentEvents(2000);

            return array_filter(
                $recent,
                fn (array $e): bool => ($e['timestamp'] ?? '') >= $startCutoff && ($e['timestamp'] ?? '') < $endCutoff,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Count unique identities (user_id or client_id) in a set of events.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function countUniqueIdentities(array $events): int
    {
        $identities = [];

        foreach ($events as $entry) {
            $userId = $entry['user_id'] ?? $entry['client_id'] ?? null;
            if ($userId !== null && is_string($userId) && $userId !== '') {
                $identities[$userId] = true;
            }
        }

        return count($identities);
    }

    /**
     * Compute top events by volume.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array{event: string, count: int}>
     */
    private function computeTopEvents(array $events): array
    {
        $counts = [];

        foreach ($events as $entry) {
            $name = $entry['event'] ?? '';
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);

        $top = [];
        foreach ($counts as $event => $count) {
            $top[] = ['event' => $event, 'count' => $count];
            if (count($top) >= 10) {
                break;
            }
        }

        return $top;
    }

    /**
     * Compute category breakdown of events.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, int>
     */
    private function computeCategoryBreakdown(array $events): array
    {
        $breakdown = [];

        foreach ($events as $entry) {
            $category = EventCatalog::getCategory($entry['event'] ?? '');
            if ($category !== null) {
                $breakdown[$category] = ($breakdown[$category] ?? 0) + 1;
            }
        }

        arsort($breakdown);

        return $breakdown;
    }

    /**
     * Compute trend direction and percentage change.
     *
     * @return TrendResult
     */
    private function computeTrend(int $current, int $previous): array
    {
        if ($previous === 0 && $current === 0) {
            return ['direction' => 'flat', 'change_pct' => 0.0, 'current' => 0, 'previous' => 0];
        }

        if ($previous === 0) {
            return ['direction' => 'up', 'change_pct' => 100.0, 'current' => $current, 'previous' => 0];
        }

        $changePct = round((($current - $previous) / $previous) * 100, 2);
        $direction = match (true) {
            $changePct > 5.0 => 'up',
            $changePct < -5.0 => 'down',
            default => 'flat',
        };

        return [
            'direction' => $direction,
            'change_pct' => $changePct,
            'current' => $current,
            'previous' => $previous,
        ];
    }

    /**
     * Compute a simple moving average across recent windows.
     *
     * @param  string  $period  Time period
     * @return float  Average events per window (last 3 windows)
     */
    private function computeMovingAverage(string $period): float
    {
        $windowCounts = [];
        $seconds = self::PERIOD_SECONDS[$period] ?? 3600;

        for ($i = 0; $i < 3; $i++) {
            $startCutoff = now()->subSeconds($seconds * ($i + 1))->toIso8601String();
            $endCutoff = now()->subSeconds($seconds * $i)->toIso8601String();

            try {
                $streamService = app(EventStreamService::class);
                $recent = $streamService->getRecentEvents(2000);

                $count = count(array_filter(
                    $recent,
                    fn (array $e): bool => ($e['timestamp'] ?? '') >= $startCutoff && ($e['timestamp'] ?? '') < $endCutoff,
                ));

                $windowCounts[] = $count;
            } catch (\Throwable) {
                $windowCounts[] = 0;
            }
        }

        $sum = array_sum($windowCounts);
        $count = count($windowCounts);

        return $count > 0 ? round($sum / $count, 1) : 0.0;
    }

    /**
     * Validate and normalize a period string.
     */
    private function validatePeriod(string $period): string
    {
        if (in_array($period, self::PERIODS, true)) {
            return $period;
        }

        return '1h'; // Default fallback
    }
}
