<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Pre-computed analytics rollup engine for efficient dashboard queries.
 *
 * Maintains materialized time-series aggregations (hourly, daily, weekly)
 * in the cache layer so dashboard widgets and API endpoints can query
 * aggregate metrics without scanning raw event data.
 *
 * Rollups include:
 * - Event counts by name, category, and provider
 * - Unique user and client counts per period
 * - Top events ranking
 * - Category distribution percentages
 * - Period-over-period trend deltas
 *
 * Inspired by Materialized Views in data warehousing, ClickHouse
 * rollup tables, and Mixpanel/Amplitude pre-aggregated dashboards.
 *
 * Configuration (zeroboiler.analytics.rollup):
 *   - enabled: bool (default: true)
 *   - granularities: list<string> — ['hourly', 'daily', 'weekly']
 *   - cache_prefix: string (default: 'zb_rollup_')
 *   - hourly_ttl: int (default: 7200 = 2 hours)
 *   - daily_ttl: int (default: 604800 = 7 days)
 *   - weekly_ttl: int (default: 2592000 = 30 days)
 *   - max_top_events: int (default: 20)
 *   - max_unique_trackers: int (default: 10000)
 *
 * @since 52.0.0
 */
final class AnalyticsRollupService
{
    /** @var list<string> Supported granularity levels */
    private const GRANULARITIES = ['hourly', 'daily', 'weekly'];

    private bool $enabled;

    /** @var list<string> Active granularities */
    private array $granularities;

    private string $cachePrefix;

    private int $hourlyTtl;

    private int $dailyTtl;

    private int $weeklyTtl;

    private int $maxTopEvents;

    private int $maxUniqueTrackers;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $rollupConfig = $config->get('zeroboiler.analytics.rollup', []);
        /** @var array{enabled?: bool, granularities?: list<string>, cache_prefix?: string, hourly_ttl?: int, daily_ttl?: int, weekly_ttl?: int, max_top_events?: int, max_unique_trackers?: int} $rollupConfig */

        $this->enabled = (bool) ($rollupConfig['enabled'] ?? true);
        $this->granularities = (array) ($rollupConfig['granularities'] ?? ['hourly', 'daily', 'weekly']);
        $this->cachePrefix = (string) ($rollupConfig['cache_prefix'] ?? 'zb_rollup_');
        $this->hourlyTtl = (int) ($rollupConfig['hourly_ttl'] ?? 7200);
        $this->dailyTtl = (int) ($rollupConfig['daily_ttl'] ?? 604800);
        $this->weeklyTtl = (int) ($rollupConfig['weekly_ttl'] ?? 2592000);
        $this->maxTopEvents = (int) ($rollupConfig['max_top_events'] ?? 20);
        $this->maxUniqueTrackers = (int) ($rollupConfig['max_unique_trackers'] ?? 10000);
    }

    /**
     * Check if the rollup engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the active granularities.
     *
     * @return list<string>
     */
    public function getGranularities(): array
    {
        return $this->granularities;
    }

    /**
     * Record an event occurrence for rollup aggregation.
     *
     * Increments counters for the current time period at all active granularities.
     * Tracks event name, category, provider, unique users, and unique clients.
     *
     * @param  string  $eventName  Event name (e.g. 'purchase', 'page_view')
     * @param  string|null  $category  Event category (ecommerce, saas, engagement, security, uptime, infrastructure)
     * @param  string|null  $provider  Provider name (ga4, meta, posthog, etc.)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function record(
        string $eventName,
        ?string $category = null,
        ?string $provider = null,
        ?string $userId = null,
        ?string $clientId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        foreach ($this->granularities as $granularity) {
            $period = $this->getPeriodKey($granularity);
            $prefix = $this->cachePrefix . $granularity . ':' . $period;

            // Increment total event count
            $this->cache->increment($prefix . ':total', 1);

            // Increment per-event count
            $this->cache->increment($prefix . ':events:' . $eventName, 1);

            // Increment per-category count
            if ($category !== null && $category !== '') {
                $this->cache->increment($prefix . ':categories:' . $category, 1);
            }

            // Increment per-provider count
            if ($provider !== null && $provider !== '') {
                $this->cache->increment($prefix . ':providers:' . $provider, 1);
            }

            // Track unique users (bounded set using cache keys)
            if ($userId !== null && $userId !== '') {
                $this->trackUnique($prefix . ':users', $userId);
            }

            // Track unique clients (bounded set using cache keys)
            if ($clientId !== null && $clientId !== '') {
                $this->trackUnique($prefix . ':clients', $clientId);
            }
        }
    }

    /**
     * Get aggregated rollup data for a specific granularity and period.
     *
     * @param  string  $granularity  'hourly', 'daily', or 'weekly'
     * @param  string|null  $periodOverride  Optional period key override (e.g. '2026-08-13')
     * @return array{period: string, granularity: string, total: int, events: array<string, int>, categories: array<string, int>, providers: array<string, int>, unique_users: int, unique_clients: int, top_events: list<array{name: string, count: int}>, category_distribution: array<string, float>}
     */
    public function query(string $granularity, ?string $periodOverride = null): array
    {
        $period = $periodOverride ?? $this->getPeriodKey($granularity);
        $prefix = $this->cachePrefix . $granularity . ':' . $period;

        $events = $this->scanKeys($prefix . ':events:*');
        $categories = $this->scanKeys($prefix . ':categories:*');
        $providers = $this->scanKeys($prefix . ':providers:*');
        $uniqueUsers = $this->countUniqueKeys($prefix . ':users:*');
        $uniqueClients = $this->countUniqueKeys($prefix . ':clients:*');
        $total = (int) ($this->cache->get($prefix . ':total') ?? 0);

        // Sort events by count descending for top events
        arsort($events);
        $topEvents = [];
        $count = 0;
        foreach ($events as $name => $cnt) {
            $topEvents[] = ['name' => $name, 'count' => $cnt];
            $count++;
            if ($count >= $this->maxTopEvents) {
                break;
            }
        }

        // Category distribution percentages
        $categoryDistribution = [];
        foreach ($categories as $cat => $cnt) {
            $categoryDistribution[$cat] = $total > 0 ? round(($cnt / $total) * 100, 2) : 0.0;
        }

        return [
            'period' => $period,
            'granularity' => $granularity,
            'total' => $total,
            'events' => $events,
            'categories' => $categories,
            'providers' => $providers,
            'unique_users' => $uniqueUsers,
            'unique_clients' => $uniqueClients,
            'top_events' => $topEvents,
            'category_distribution' => $categoryDistribution,
        ];
    }

    /**
     * Get trend comparison between current and previous period.
     *
     * Computes delta and percentage change for total events, unique users,
     * and unique clients across two consecutive periods.
     *
     * @param  string  $granularity  'hourly', 'daily', or 'weekly'
     * @return array{current: array{period: string, total: int, unique_users: int, unique_clients: int}, previous: array{period: string, total: int, unique_users: int, unique_clients: int}, delta: array{total: int, unique_users: int, unique_clients: int}, pct_change: array{total: float, unique_users: float, unique_clients: float}}
     */
    public function trend(string $granularity): array
    {
        $currentPeriod = $this->getPeriodKey($granularity);
        $previousPeriod = $this->getPreviousPeriodKey($granularity);

        $current = $this->querySummary($granularity, $currentPeriod);
        $previous = $this->querySummary($granularity, $previousPeriod);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => [
                'total' => $current['total'] - $previous['total'],
                'unique_users' => $current['unique_users'] - $previous['unique_users'],
                'unique_clients' => $current['unique_clients'] - $previous['unique_clients'],
            ],
            'pct_change' => [
                'total' => $this->pctChange($previous['total'], $current['total']),
                'unique_users' => $this->pctChange($previous['unique_users'], $current['unique_users']),
                'unique_clients' => $this->pctChange($previous['unique_clients'], $current['unique_clients']),
            ],
        ];
    }

    /**
     * Get a lightweight summary for a specific period (no event-level detail).
     *
     * @param  string  $granularity
     * @param  string|null  $periodOverride
     * @return array{period: string, granularity: string, total: int, unique_users: int, unique_clients: int}
     */
    public function querySummary(string $granularity, ?string $periodOverride = null): array
    {
        $period = $periodOverride ?? $this->getPeriodKey($granularity);
        $prefix = $this->cachePrefix . $granularity . ':' . $period;

        return [
            'period' => $period,
            'granularity' => $granularity,
            'total' => (int) ($this->cache->get($prefix . ':total') ?? 0),
            'unique_users' => $this->countUniqueKeys($prefix . ':users:*'),
            'unique_clients' => $this->countUniqueKeys($prefix . ':clients:*'),
        ];
    }

    /**
     * Get a multi-period trend sparkline for an event.
     *
     * Returns an array of data points for the last N periods.
     *
     * @param  string  $eventName  Event name to track
     * @param  string  $granularity  'hourly', 'daily', or 'weekly'
     * @param  int  $periods  Number of periods to look back (default: 24)
     * @return list<array{period: string, count: int}>
     */
    public function sparkline(string $eventName, string $granularity = 'hourly', int $periods = 24): array
    {
        $data = [];
        $now = new \DateTimeImmutable;

        for ($i = $periods - 1; $i >= 0; $i--) {
            $period = $this->getPeriodKeyForDate($granularity, $now->modify("-{$i} {$granularity}"));
            $prefix = $this->cachePrefix . $granularity . ':' . $period;
            $count = (int) ($this->cache->get($prefix . ':events:' . $eventName) ?? 0);

            $data[] = ['period' => $period, 'count' => $count];
        }

        return $data;
    }

    /**
     * Get the overall rollup service summary.
     *
     * @return array{enabled: bool, granularities: list<string>, hourly_ttl: int, daily_ttl: int, weekly_ttl: int, max_top_events: int, max_unique_trackers: int, cache_prefix: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'granularities' => $this->granularities,
            'hourly_ttl' => $this->hourlyTtl,
            'daily_ttl' => $this->dailyTtl,
            'weekly_ttl' => $this->weeklyTtl,
            'max_top_events' => $this->maxTopEvents,
            'max_unique_trackers' => $this->maxUniqueTrackers,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Get statistics about the current rollup data volume.
     *
     * Scans cache keys for the current period at each granularity.
     *
     * @return array{granularities: array<string, array{period: string, total: int, event_types: int, categories: int, providers: int, unique_users: int, unique_clients: int}>}
     */
    public function stats(): array
    {
        $result = [];

        foreach ($this->granularities as $granularity) {
            $data = $this->query($granularity);

            $result[$granularity] = [
                'period' => $data['period'],
                'total' => $data['total'],
                'event_types' => count($data['events']),
                'categories' => count($data['categories']),
                'providers' => count($data['providers']),
                'unique_users' => $data['unique_users'],
                'unique_clients' => $data['unique_clients'],
            ];
        }

        return ['granularities' => $result];
    }

    /**
     * Clear all rollup data for a specific granularity (or all).
     *
     * @param  string|null  $granularity  Specific granularity or null for all
     * @return int Number of cache keys cleared (approximate)
     */
    public function clear(?string $granularity = null): int
    {
        $cleared = 0;
        $targets = $granularity !== null ? [$granularity] : $this->granularities;

        foreach ($targets as $g) {
            $pattern = $this->cachePrefix . $g . ':*';

            // Use cache store's flushPrefix if available (Redis/Memcached)
            if (method_exists($this->cache->getStore(), 'flushPrefix')) {
                try {
                    $this->cache->getStore()->flushPrefix($this->cachePrefix . $g . ':');
                    $cleared++;
                } catch (\Throwable) {
                    // Fall back to nothing — can't clear without prefix support
                }
            }
        }

        return $cleared;
    }

    /**
     * Get the TTL for a given granularity.
     */
    public function getTtlForGranularity(string $granularity): int
    {
        return match ($granularity) {
            'hourly' => $this->hourlyTtl,
            'daily' => $this->dailyTtl,
            'weekly' => $this->weeklyTtl,
            default => 3600,
        };
    }

    /**
     * Generate a period key for the current time at a given granularity.
     *
     * @param  string  $granularity
     * @return string Format depends on granularity:
     *   - hourly: '2026-08-13T14'
     *   - daily: '2026-08-13'
     *   - weekly: '2026-W33'
     */
    private function getPeriodKey(string $granularity): string
    {
        return $this->getPeriodKeyForDate($granularity, new \DateTimeImmutable);
    }

    /**
     * Generate a period key for a specific date at a given granularity.
     *
     * @param  string  $granularity
     * @param  \DateTimeImmutable  $date
     * @return string
     */
    private function getPeriodKeyForDate(string $granularity, \DateTimeImmutable $date): string
    {
        return match ($granularity) {
            'hourly' => $date->format('Y-m-d\TH'),
            'daily' => $date->format('Y-m-d'),
            'weekly' => 'W' . $date->format('Y-\WW'),
            default => $date->format('Y-m-d'),
        };
    }

    /**
     * Get the previous period key for trend comparison.
     *
     * @param  string  $granularity
     * @return string
     */
    private function getPreviousPeriodKey(string $granularity): string
    {
        $now = new \DateTimeImmutable;

        return match ($granularity) {
            'hourly' => $now->modify('-1 hour')->format('Y-m-d\TH'),
            'daily' => $now->modify('-1 day')->format('Y-m-d'),
            'weekly' => 'W' . $now->modify('-1 week')->format('Y-\WW'),
            default => $now->modify('-1 day')->format('Y-m-d'),
        };
    }

    /**
     * Track a unique identifier in a bounded set.
     *
     * Uses a bloom-filter-like approach with individual cache keys.
     * Each unique ID gets its own key within the period prefix.
     * Bounded by maxUniqueTrackers to prevent unbounded memory growth.
     *
     * @param  string  $prefix  Cache key prefix (e.g. 'zb_rollup_hourly:2026-08-13T14:users')
     * @param  string  $id  Unique identifier (user ID or client ID)
     */
    private function trackUnique(string $prefix, string $id): void
    {
        // Check existing count to enforce limit
        $countKey = $prefix . ':_count';
        $currentCount = (int) ($this->cache->get($countKey) ?? 0);

        if ($currentCount >= $this->maxUniqueTrackers) {
            return;
        }

        // Use a hash-based key to avoid cache key length issues
        $idHash = hash('xxh64', $id);
        $memberKey = $prefix . ':' . $idHash;

        // Atomic set-if-absent (ADD)
        $added = $this->cache->add($memberKey, 1, $this->getTtlForCurrentGranularity($prefix));

        if ($added) {
            $this->cache->increment($countKey, 1);
        }
    }

    /**
     * Count unique tracked identifiers for a prefix pattern.
     *
     * @param  string  $pattern  Cache key pattern (e.g. 'zb_rollup_hourly:2026-08-13T14:users:*')
     * @return int
     */
    private function countUniqueKeys(string $pattern): int
    {
        $countKey = str_replace(':*', ':_count', $pattern);

        return (int) ($this->cache->get($countKey) ?? 0);
    }

    /**
     * Scan cache keys matching a pattern and return their values.
     *
     * Returns an associative array of key suffix → value.
     *
     * @param  string  $pattern  Cache key pattern (e.g. 'zb_rollup_hourly:2026-08-13T14:events:*')
     * @return array<string, int>
     */
    private function scanKeys(string $pattern): array
    {
        $result = [];

        try {
            if (method_exists($this->cache->getStore(), 'get')) {
                // Try to use the cache store's search if available
                $store = $this->cache->getStore();

                if (method_exists($store, 'connection') && method_exists($store, 'connection')) {
                    // Redis: use SCAN
                    $connection = $store->connection();
                    if (method_exists($connection, 'scan')) {
                        $prefix = str_replace('*', '', $pattern);
                        $cursor = '0';
                        do {
                            // @phpstan-ignore-next-line — dynamic Redis call
                            $items = $connection->scan($cursor, ['match' => $prefix, 'count' => 100]);
                            if (is_array($items)) {
                                foreach ($items as $key) {
                                    if (is_string($key) && ! str_ends_with($key, ':_count')) {
                                        $suffix = str_replace($prefix, '', $key);
                                        $value = $this->cache->get($key);
                                        $result[$suffix] = is_int($value) ? $value : (int) $value;
                                    }
                                }
                            }
                        } while ($cursor !== '0');
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback: return empty — not all cache drivers support scanning
            Log::debug('AnalyticsRollup: Cache scan failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Calculate percentage change between two values.
     *
     * @param  int  $previous  Previous period value
     * @param  int  $current  Current period value
     * @return float Percentage change (positive = growth, negative = decline)
     */
    private function pctChange(int $previous, int $current): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * Infer TTL from a cache key prefix by extracting the granularity.
     *
     * @param  string  $prefix
     * @return int
     */
    private function getTtlForCurrentGranularity(string $prefix): int
    {
        $base = str_replace($this->cachePrefix, '', $prefix);

        if (str_starts_with($base, 'hourly')) {
            return $this->hourlyTtl;
        }

        if (str_starts_with($base, 'daily')) {
            return $this->dailyTtl;
        }

        if (str_starts_with($base, 'weekly')) {
            return $this->weeklyTtl;
        }

        return 3600;
    }
}
