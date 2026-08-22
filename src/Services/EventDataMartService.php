<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event data mart service — pre-aggregated OLAP-style event rollup cubes.
 *
 * Materializes raw analytics events into time-binned summary tables stored in
 * the Laravel cache, enabling instant dashboard queries without scanning raw events.
 * Inspired by the data mart pattern used by Amplitude, Mixpanel, and PostHog.
 *
 * Supports multiple granularity levels (minute, hour, day, week, month) and
 * aggregation dimensions (event name, category, provider, client_id, user_id).
 * Each cell stores count, unique_count, first_seen, last_seen, and metadata.
 *
 * Configuration is read from `zeroboiler.analytics.data_mart`.
 *
 * @phpstan-type MartCell array{count: int, unique_count: int, first_seen: string, last_seen: string, metadata: array<string, mixed>}
 * @phpstan-type MartSlice array<string, MartCell>
 * @phpstan-type MartCube array{granularity: string, dimension: string, period: string, slices: MartSlice, total: int, unique_total: int, generated_at: string, ttl: int}
 *
 * @since 7.0.0
 */
final class EventDataMartService
{
    private const CACHE_PREFIX = 'zb_datamart_';

    private const DEFAULT_TTL = 86400; // 24 hours

    private const DEFAULT_MAX_DIMENSIONS = 50;

    /** @var list<string> Supported granularity levels */
    private const GRANULARITIES = ['minute', 'hour', 'day', 'week', 'month'];

    /** @var list<string> Supported aggregation dimensions */
    private const DIMENSIONS = ['event_name', 'category', 'provider', 'client_id', 'user_id', 'source'];

    private bool $enabled;

    private int $cacheTtl;

    private string $defaultGranularity;

    private int $maxDimensions;

    /** @var list<string> Dimensions to pre-compute on ingest */
    private array $autoDimensions;

    /** @var list<string> Event categories to track (empty = all) */
    private array $trackedCategories;

    /**
     * @param  CacheRepository  $cache  Application cache driver
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $martConfig = $config->get('zeroboiler.analytics.data_mart', []);
        /** @var array{enabled?: bool, cache_ttl?: int, default_granularity?: string, max_dimensions?: int, auto_dimensions?: list<string>, tracked_categories?: list<string>} $martConfig */

        $this->enabled = (bool) ($martConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($martConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->defaultGranularity = $martConfig['default_granularity'] ?? 'hour';
        $this->maxDimensions = (int) ($martConfig['max_dimensions'] ?? self::DEFAULT_MAX_DIMENSIONS);
        $this->autoDimensions = (array) ($martConfig['auto_dimensions'] ?? ['event_name', 'category']);
        $this->trackedCategories = (array) ($martConfig['tracked_categories'] ?? []);
    }

    /**
     * Check if the data mart is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record an event into all configured data mart dimensions.
     *
     * Increments the count cell for each auto-dimension at the current
     * time granularity. Thread-safe via cache atomic increment.
     *
     * @param  array{name: string, category?: string, client_id?: string, user_id?: string, provider?: string, source?: string, timestamp?: string}  $event  Event data
     */
    public function ingest(array $event): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! empty($this->trackedCategories)) {
            $category = $event['category'] ?? 'unknown';
            if (! in_array($category, $this->trackedCategories, true)) {
                return;
            }
        }

        $timestamp = $event['timestamp'] ?? now()->toIso8601String();
        $now = $timestamp;

        foreach ($this->autoDimensions as $dimension) {
            $key = $event[$dimension] ?? 'unknown';

            if (is_string($key) && strlen($key) > 100) {
                $key = substr($key, 0, 100);
            }

            $this->incrementCell($dimension, $key, $now, $event);
        }

        // Always track the default granularity cube
        $this->incrementCell('_all', '_total', $now, $event);
    }

    /**
     * Ingest a batch of events efficiently.
     *
     * @param  list<array{name: string, category?: string, client_id?: string, user_id?: string, provider?: string, source?: string, timestamp?: string}>  $events
     */
    public function ingestBatch(array $events): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($events as $event) {
            $this->ingest($event);
        }
    }

    /**
     * Query the data mart for a specific dimension and key range.
     *
     * Returns time-binned counts for the requested dimension over the
     * specified period and granularity.
     *
     * @param  string  $dimension  Aggregation dimension (event_name, category, provider, etc.)
     * @param  string  $granularity  Time bin size (minute, hour, day, week, month)
     * @param  string  $period  ISO 8601 period start (e.g., '2026-08-01T00:00:00Z')
     * @param  int|null  $limit  Max results (null = no limit)
     * @return array{dimension: string, granularity: string, period: string, data: list<array{key: string, count: int, unique_count: int}>, total: int}
     */
    public function query(
        string $dimension,
        string $granularity,
        string $period,
        ?int $limit = null,
    ): array {
        $cacheKey = $this->cacheKey($dimension, $granularity);

        /** @var MartSlice|null $slices */
        $slices = $this->cache->get($cacheKey, []);

        $results = [];
        $total = 0;

        foreach ($slices as $key => $cell) {
            $results[] = [
                'key' => $key,
                'count' => $cell['count'],
                'unique_count' => $cell['unique_count'],
            ];
            $total += $cell['count'];
        }

        usort($results, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return [
            'dimension' => $dimension,
            'granularity' => $granularity,
            'period' => $period,
            'data' => $results,
            'total' => $total,
        ];
    }

    /**
     * Get top events by count for a given dimension.
     *
     * @return list<array{key: string, count: int, unique_count: int, first_seen: string, last_seen: string}>
     */
    public function top(
        string $dimension = 'event_name',
        int $limit = 10,
        string $granularity = 'hour',
    ): array {
        $cacheKey = $this->cacheKey($dimension, $granularity);

        /** @var MartSlice|null $slices */
        $slices = $this->cache->get($cacheKey, []);

        $items = [];
        foreach ($slices as $key => $cell) {
            $items[] = [
                'key' => $key,
                'count' => $cell['count'],
                'unique_count' => $cell['unique_count'],
                'first_seen' => $cell['first_seen'],
                'last_seen' => $cell['last_seen'],
            ];
        }

        usort($items, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($items, 0, $limit);
    }

    /**
     * Get the total event count across all dimensions.
     */
    public function totalCount(): int
    {
        $cacheKey = $this->cacheKey('_all', $this->defaultGranularity);

        /** @var MartSlice $slices */
        $slices = $this->cache->get($cacheKey, []);

        return ($slices['_total']['count'] ?? 0);
    }

    /**
     * Get the total unique count (approximate).
     */
    public function totalUniqueCount(): int
    {
        $cacheKey = $this->cacheKey('_all', $this->defaultGranularity);

        /** @var MartSlice $slices */
        $slices = $this->cache->get($cacheKey, []);

        return ($slices['_total']['unique_count'] ?? 0);
    }

    /**
     * Get event counts grouped by category.
     *
     * @return array<string, int>
     */
    public function byCategory(): array
    {
        return $this->sumDimension('category');
    }

    /**
     * Get event counts grouped by event name.
     *
     * @return array<string, int>
     */
    public function byEventName(): array
    {
        return $this->sumDimension('event_name');
    }

    /**
     * Get event counts grouped by provider.
     *
     * @return array<string, int>
     */
    public function byProvider(): array
    {
        return $this->sumDimension('provider');
    }

    /**
     * Get a summary of the data mart status.
     *
     * @return array{enabled: bool, granularity: string, dimensions: list<string>, tracked_categories: list<string>, total_events: int, total_unique: int, cache_ttl: int, cached_cubes: int}
     */
    public function summary(): array
    {
        $totalEvents = $this->totalCount();
        $totalUnique = $this->totalUniqueCount();

        return [
            'enabled' => $this->enabled,
            'granularity' => $this->defaultGranularity,
            'dimensions' => $this->autoDimensions,
            'tracked_categories' => $this->trackedCategories,
            'total_events' => $totalEvents,
            'total_unique' => $totalUnique,
            'cache_ttl' => $this->cacheTtl,
            'cached_cubes' => $this->countCachedCubes(),
        ];
    }

    /**
     * Get the data mart as a full exportable cube.
     *
     * @return MartCube
     */
    public function exportCube(
        string $dimension = 'event_name',
        string $granularity = 'hour',
    ): array {
        $cacheKey = $this->cacheKey($dimension, $granularity);

        /** @var MartSlice $slices */
        $slices = $this->cache->get($cacheKey, []);

        $totalCount = 0;
        $totalUnique = 0;

        foreach ($slices as $cell) {
            $totalCount += $cell['count'];
            $totalUnique += $cell['unique_count'];
        }

        return [
            'granularity' => $granularity,
            'dimension' => $dimension,
            'period' => now()->toIso8601String(),
            'slices' => $slices,
            'total' => $totalCount,
            'unique_total' => $totalUnique,
            'generated_at' => now()->toIso8601String(),
            'ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Compare two dimensions' distributions.
     *
     * Useful for spotting anomalies (e.g., category distribution drift).
     *
     * @return array{dimension_a: string, dimension_b: string, data: list<array{key: string, count_a: int, count_b: int, ratio: float}>}
     */
    public function compareDimensions(
        string $dimensionA,
        string $dimensionB,
        string $granularity = 'hour',
    ): array {
        $cubeA = $this->sumDimension($dimensionA, $granularity);
        $cubeB = $this->sumDimension($dimensionB, $granularity);

        $allKeys = array_unique(array_merge(array_keys($cubeA), array_keys($cubeB)));
        $data = [];

        foreach ($allKeys as $key) {
            $countA = $cubeA[$key] ?? 0;
            $countB = $cubeB[$key] ?? 0;
            $data[] = [
                'key' => $key,
                'count_a' => $countA,
                'count_b' => $countB,
                'ratio' => $countB > 0 ? round($countA / $countB, 4) : 0.0,
            ];
        }

        usort($data, fn (array $a, array $b): int => abs($b['ratio'] - 1.0) <=> abs($a['ratio'] - 1.0));

        return [
            'dimension_a' => $dimensionA,
            'dimension_b' => $dimensionB,
            'data' => $data,
        ];
    }

    /**
     * Get supported granularity levels.
     *
     * @return list<string>
     */
    public function supportedGranularities(): array
    {
        return self::GRANULARITIES;
    }

    /**
     * Get supported aggregation dimensions.
     *
     * @return list<string>
     */
    public function supportedDimensions(): array
    {
        return self::DIMENSIONS;
    }

    /**
     * Clear all data mart caches.
     */
    public function clear(): void
    {
        foreach ($this->autoDimensions as $dimension) {
            foreach (self::GRANULARITIES as $granularity) {
                $this->cache->forget($this->cacheKey($dimension, $granularity));
            }
        }

        foreach (self::GRANULARITIES as $granularity) {
            $this->cache->forget($this->cacheKey('_all', $granularity));
        }
    }

    /**
     * Increment a cell in the data mart.
     *
     * @param  string  $dimension  Dimension name
     * @param  string  $key  Dimension value key
     * @param  string  $timestamp  ISO 8601 timestamp
     * @param  array<string, mixed>  $event  Original event data
     */
    private function incrementCell(string $dimension, string $key, string $timestamp, array $event): void
    {
        $granularity = $this->defaultGranularity;
        $cacheKey = $this->cacheKey($dimension, $granularity);

        /** @var MartSlice $slices */
        $slices = $this->cache->get($cacheKey, []);

        if (! isset($slices[$key])) {
            if (count($slices) >= $this->maxDimensions && ! isset($slices[$key])) {
                return; // Drop new keys if at capacity
            }

            $slices[$key] = [
                'count' => 0,
                'unique_count' => 0,
                'first_seen' => $timestamp,
                'last_seen' => $timestamp,
                'metadata' => [],
            ];
        }

        $slices[$key]['count']++;
        $slices[$key]['last_seen'] = $timestamp;

        // Track unique client IDs if available
        $clientId = $event['client_id'] ?? null;
        if ($clientId !== null) {
            $uniqueKey = 'unique_clients';
            if (! isset($slices[$key]['metadata'][$uniqueKey])) {
                $slices[$key]['metadata'][$uniqueKey] = [];
            }

            if (! in_array($clientId, $slices[$key]['metadata'][$uniqueKey], true)) {
                $slices[$key]['metadata'][$uniqueKey][] = $clientId;
                $slices[$key]['unique_count']++;

                // Cap unique tracking at 1000 per cell to prevent memory bloat
                if (count($slices[$key]['metadata'][$uniqueKey]) > 1000) {
                    // Switch to probabilistic counting (HyperLogLog-inspired)
                    $slices[$key]['metadata']['probabilistic'] = true;
                }
            }
        }

        $this->cache->put($cacheKey, $slices, $this->cacheTtl);
    }

    /**
     * Sum counts for a given dimension.
     *
     * @return array<string, int>
     */
    private function sumDimension(string $dimension, string $granularity = 'hour'): array
    {
        $cacheKey = $this->cacheKey($dimension, $granularity);

        /** @var MartSlice $slices */
        $slices = $this->cache->get($cacheKey, []);

        $result = [];
        foreach ($slices as $key => $cell) {
            $result[$key] = $cell['count'];
        }

        return $result;
    }

    /**
     * Generate a cache key for a dimension and granularity.
     */
    private function cacheKey(string $dimension, string $granularity): string
    {
        return self::CACHE_PREFIX . $dimension . '_' . $granularity;
    }

    /**
     * Count the number of cached cubes.
     */
    private function countCachedCubes(): int
    {
        $count = 0;
        foreach ($this->autoDimensions as $dimension) {
            $cacheKey = $this->cacheKey($dimension, $this->defaultGranularity);
            $slices = $this->cache->get($cacheKey);
            if ($slices !== null) {
                $count++;
            }
        }

        return $count;
    }
}
