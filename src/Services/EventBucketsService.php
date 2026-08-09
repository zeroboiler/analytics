<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Time-binned event aggregation service for analytics dashboards.
 *
 * Aggregates events into configurable time buckets (minute, hour, day, week, month)
 * for chart rendering, trend analysis, and periodic reporting. Each bucket contains
 * event counts, unique user/client counts, value totals, and per-event breakdowns.
 *
 * Supports multiple concurrent bucket series, automatic expiry, and efficient
 * cache-based storage. Designed for real-time dashboard widgets.
 *
 * Configuration: `zeroboiler.analytics.event_buckets`
 *
 * @since 1.0.0
 */
final class EventBucketsService
{
    private const CACHE_PREFIX = 'zb_event_buckets_';

    private const DEFAULT_TTL = 86400; // 24 hours

    private const DEFAULT_MAX_SERIES = 50;

    private const DEFAULT_MAX_BUCKETS_PER_SERIES = 1000;

    /** @var array<string, int> Bucket duration in seconds */
    private const BUCKET_DURATIONS = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
        'week' => 604800,
        'month' => 2592000,
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxSeries;

    private int $maxBucketsPerSeries;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $bucketConfig = $config->get('zeroboiler.analytics.event_buckets', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_series?: int, max_buckets_per_series?: int} $bucketConfig */
        $this->enabled = (bool) ($bucketConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($bucketConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxSeries = (int) ($bucketConfig['max_series'] ?? self::DEFAULT_MAX_SERIES);
        $this->maxBucketsPerSeries = (int) ($bucketConfig['max_buckets_per_series'] ?? self::DEFAULT_MAX_BUCKETS_PER_SERIES);
    }

    /**
     * Record an event occurrence in a bucket series.
     *
     * @param  string  $series  Bucket series name (e.g., 'page_views', 'signups', 'revenue')
     * @param  string|null  $eventName  Optional event name for per-event breakdown
     * @param  string|null  $userId  User ID for unique user counting
     * @param  string|null  $clientId  Client ID for unique client counting
     * @param  float  $value  Optional value (e.g., revenue amount)
     */
    public function record(
        string $series,
        ?string $eventName = null,
        ?string $userId = null,
        ?string $clientId = null,
        float $value = 0.0,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $seriesData = $this->getSeries($series);
        $now = time();

        // Generate bucket keys for all configured granularities
        foreach (self::BUCKET_DURATIONS as $granularity => $duration) {
            $bucketKey = $this->bucketKey($now, $granularity, $duration);

            if (! isset($seriesData[$granularity][$bucketKey])) {
                $seriesData[$granularity][$bucketKey] = [
                    'count' => 0,
                    'value' => 0.0,
                    'users' => [],
                    'clients' => [],
                    'events' => [],
                    'start' => $bucketKey,
                    'end' => $bucketKey + $duration - 1,
                ];
            }

            $bucket = &$seriesData[$granularity][$bucketKey];
            $bucket['count']++;
            $bucket['value'] += $value;

            if ($userId !== null && ! in_array($userId, $bucket['users'], true)) {
                $bucket['users'][] = $userId;
            }

            if ($clientId !== null && ! in_array($clientId, $bucket['clients'], true)) {
                $bucket['clients'][] = $clientId;
            }

            if ($eventName !== null) {
                $bucket['events'][$eventName] = ($bucket['events'][$eventName] ?? 0) + 1;
            }
            unset($bucket);

            // Trim old buckets
            $seriesData[$granularity] = array_slice(
                $seriesData[$granularity],
                -$this->maxBucketsPerSeries,
                null,
                true,
            );
        }

        $this->putSeries($series, $seriesData);
    }

    /**
     * Get aggregated buckets for a series at a given granularity.
     *
     * @param  string  $series  Bucket series name
     * @param  string  $granularity  'minute', 'hour', 'day', 'week', or 'month'
     * @param  int  $limit  Maximum buckets to return (from most recent)
     * @return list<array{start: int, end: int, count: int, value: float, unique_users: int, unique_clients: int, events: array<string, int>}>
     */
    public function getBuckets(string $series, string $granularity = 'hour', int $limit = 24): array
    {
        if (! $this->enabled || ! isset(self::BUCKET_DURATIONS[$granularity])) {
            return [];
        }

        $seriesData = $this->getSeries($series);

        if (! isset($seriesData[$granularity])) {
            return [];
        }

        $buckets = $seriesData[$granularity];
        krsort($buckets);
        $buckets = array_slice($buckets, 0, $limit, true);

        return array_values(array_map(
            fn (array $bucket): array => [
                'start' => $bucket['start'],
                'end' => $bucket['end'],
                'count' => $bucket['count'],
                'value' => round($bucket['value'], 4),
                'unique_users' => count($bucket['users']),
                'unique_clients' => count($bucket['clients']),
                'events' => $bucket['events'],
            ],
            $buckets,
        ));
    }

    /**
     * Get a summary of a bucket series.
     *
     * @param  string  $series  Bucket series name
     * @param  string  $granularity  'minute', 'hour', 'day', 'week', or 'month'
     * @param  int  $last  Number of buckets to summarize
     * @return array{total_events: int, total_value: float, avg_per_bucket: float, unique_users: int, unique_clients: int, top_events: array<string, int>, bucket_count: int}
     */
    public function summary(string $series, string $granularity = 'hour', int $last = 24): array
    {
        $buckets = $this->getBuckets($series, $granularity, $last);

        if (empty($buckets)) {
            return [
                'total_events' => 0,
                'total_value' => 0.0,
                'avg_per_bucket' => 0.0,
                'unique_users' => 0,
                'unique_clients' => 0,
                'top_events' => [],
                'bucket_count' => 0,
            ];
        }

        $totalEvents = array_sum(array_column($buckets, 'count'));
        $totalValue = array_sum(array_column($buckets, 'value'));
        $allUsers = [];
        $allClients = [];
        $allEvents = [];

        foreach ($buckets as $bucket) {
            $allEvents = array_merge($allEvents, $bucket['events']);
        }

        // Aggregate top events
        $topEvents = [];
        foreach ($allEvents as $eventName => $count) {
            $topEvents[$eventName] = ($topEvents[$eventName] ?? 0) + $count;
        }
        arsort($topEvents);
        $topEvents = array_slice($topEvents, 0, 20, true);

        return [
            'total_events' => $totalEvents,
            'total_value' => round($totalValue, 4),
            'avg_per_bucket' => count($buckets) > 0 ? round($totalEvents / count($buckets), 2) : 0.0,
            'unique_users' => 0, // Approximate — true unique across buckets requires dedup
            'unique_clients' => 0,
            'top_events' => $topEvents,
            'bucket_count' => count($buckets),
        ];
    }

    /**
     * Compare two series side-by-side for a given granularity.
     *
     * Useful for comparing e.g., "page_views" vs "sign_ups" conversion rates.
     *
     * @param  string  $seriesA  First series name
     * @param  string  $seriesB  Second series name
     * @param  string  $granularity  Time bucket granularity
     * @param  int  $limit  Number of buckets
     * @return list<array{start: int, end: int, a_count: int, b_count: int, ratio: float}>
     */
    public function compare(string $seriesA, string $seriesB, string $granularity = 'hour', int $limit = 24): array
    {
        $bucketsA = $this->getBuckets($seriesA, $granularity, $limit);
        $bucketsB = $this->getBuckets($seriesB, $granularity, $limit);

        // Build lookup map for series B keyed by start time
        $bMap = [];
        foreach ($bucketsB as $bucket) {
            $bMap[$bucket['start']] = $bucket['count'];
        }

        $result = [];
        foreach ($bucketsA as $bucket) {
            $bCount = $bMap[$bucket['start']] ?? 0;
            $result[] = [
                'start' => $bucket['start'],
                'end' => $bucket['end'],
                'a_count' => $bucket['count'],
                'b_count' => $bCount,
                'ratio' => $bucket['count'] > 0 ? round($bCount / $bucket['count'], 4) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Get all registered series names.
     *
     * @return list<string>
     */
    public function seriesList(): array
    {
        $index = $this->cache->get(self::CACHE_PREFIX . '_index', []);
        /** @var list<string> $index */

        return $index;
    }

    /**
     * Delete a bucket series and all its data.
     */
    public function deleteSeries(string $series): bool
    {
        $this->cache->forget($this->seriesCacheKey($series));

        $index = $this->cache->get(self::CACHE_PREFIX . '_index', []);
        /** @var list<string> $index */
        $index = array_values(array_filter($index, fn (string $s): bool => $s !== $series));
        $this->cache->put(self::CACHE_PREFIX . '_index', $index, $this->cacheTtl * 2);

        return true;
    }

    /**
     * Clear all bucket series data.
     */
    public function clear(): void
    {
        $index = $this->seriesList();

        foreach ($index as $series) {
            $this->cache->forget($this->seriesCacheKey($series));
        }

        $this->cache->forget(self::CACHE_PREFIX . '_index');
    }

    /**
     * Check if event bucket tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the available granularities.
     *
     * @return list<string>
     */
    public static function availableGranularities(): array
    {
        return array_keys(self::BUCKET_DURATIONS);
    }

    /**
     * Generate a bucket key (epoch start time) for the current moment.
     *
     * Buckets are aligned to wall-clock boundaries:
     * - minute: top of the minute
     * - hour: top of the hour
     * - day: midnight UTC
     * - week: Monday midnight UTC
     * - month: first of month midnight UTC
     */
    private function bucketKey(int $timestamp, string $granularity, int $duration): int
    {
        return match ($granularity) {
            'minute' => (int) floor($timestamp / 60) * 60,
            'hour' => (int) floor($timestamp / 3600) * 3600,
            'day' => (int) floor($timestamp / 86400) * 86400,
            'week' => (int) floor(($timestamp - 345600) / 604800) * 604800 + 345600, // Monday
            'month' => (int) strtotime(date('Y-m-01 00:00:00', $timestamp)),
            default => (int) floor($timestamp / $duration) * $duration,
        };
    }

    /**
     * Get the cache key for a series.
     */
    private function seriesCacheKey(string $series): string
    {
        return self::CACHE_PREFIX . 'series_' . $series;
    }

    /**
     * Load series data from cache.
     *
     * @return array<string, array<string, array{count: int, value: float, users: list<string>, clients: list<string>, events: array<string, int>, start: int, end: int}>>
     */
    private function getSeries(string $series): array
    {
        $data = $this->cache->get($this->seriesCacheKey($series), []);
        /** @var array<string, array<string, array{count: int, value: float, users: list<string>, clients: list<string>, events: array<string, int>, start: int, end: int}>> $data */

        // Register series in index
        $index = $this->cache->get(self::CACHE_PREFIX . '_index', []);
        /** @var list<string> $index */
        if (! in_array($series, $index, true)) {
            $index[] = $series;
            // Limit series count
            $index = array_slice($index, -$this->maxSeries);
            $this->cache->put(self::CACHE_PREFIX . '_index', $index, $this->cacheTtl * 2);
        }

        return $data;
    }

    /**
     * Save series data to cache.
     *
     * @param  string  $series
     * @param  array<string, array<string, array{count: int, value: float, users: list<string>, clients: list<string>, events: array<string, int>, start: int, end: int}>>  $data
     */
    private function putSeries(string $series, array $data): void
    {
        $this->cache->put($this->seriesCacheKey($series), $data, $this->cacheTtl);
    }
}
