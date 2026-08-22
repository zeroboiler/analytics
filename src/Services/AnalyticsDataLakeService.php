<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Models\AnalyticsEventModel;

/**
 * Analytics Data Lake Export Service.
 *
 * Aggregates analytics events into time-partitioned snapshots suitable for
 * warehouse ingestion. Supports JSON Lines (NDJSON), CSV, and aggregated
 * summary formats. Provides column projection, date range filtering,
 * category filtering, and configurable batch sizes.
 *
 * Designed for periodic export jobs (scheduled via Laravel scheduler or
 * queued commands) that feed downstream data warehouses like BigQuery,
 * Snowflake, Redshift, or ClickHouse.
 *
 * Inspired by Segment's Protocols export, RudderStack's Warehouse Actions,
 * and PostHog's Data Warehouse export.
 *
 * Configuration: `zeroboiler.analytics.data_lake`
 *
 * @see \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob
 * @see \ZeroBoiler\Analytics\Services\ExportService
 *
 * @since 188.0.0
 */
final class AnalyticsDataLakeService
{
    /** @var string Cache key prefix for data lake snapshots */
    private const CACHE_PREFIX = 'zb_datalake_';

    /** @var int Default TTL for cached snapshots (1 hour) */
    private const DEFAULT_TTL = 3600;

    /** @var int Maximum events per export batch */
    private const MAX_BATCH_SIZE = 10000;

    /** @var string JSON Lines format */
    public const FORMAT_NDJSON = 'ndjson';

    /** @var string CSV format */
    public const FORMAT_CSV = 'csv';

    /** @var string Aggregated summary format */
    public const FORMAT_SUMMARY = 'summary';

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    private int $maxBatchSize;

    /** @var list<string> Default columns to include in exports */
    private array $defaultColumns;

    /** @var list<string> Categories to include (empty = all) */
    private array $includedCategories;

    /** @var string Default export format */
    private string $defaultFormat;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $dlConfig = $config->get('zeroboiler.analytics.data_lake', []);
        /** @var array{enabled?: bool, ttl?: int, max_batch_size?: int, default_columns?: list<string>, categories?: list<string>, default_format?: string} $dlConfig */
        $this->enabled = (bool) ($dlConfig['enabled'] ?? true);
        $this->ttl = (int) ($dlConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->maxBatchSize = (int) ($dlConfig['max_batch_size'] ?? self::MAX_BATCH_SIZE);
        $this->defaultColumns = (array) ($dlConfig['default_columns'] ?? [
            'id', 'name', 'category', 'client_id', 'user_id',
            'params', 'session_id', 'page_url', 'referrer',
            'ip', 'user_agent', 'created_at',
        ]);
        $this->includedCategories = (array) ($dlConfig['categories'] ?? []);
        $this->defaultFormat = (string) ($dlConfig['default_format'] ?? self::FORMAT_NDJSON);
    }

    /**
     * Export events as JSON Lines (NDJSON).
     *
     * Each line is a valid JSON object representing one event.
     * Suitable for BigQuery, Snowflake, and generic JSON ingestion.
     *
     * @param  string|null  $startDate  Start date (Y-m-d), defaults to 30 days ago
     * @param  string|null  $endDate  End date (Y-m-d), defaults to today
     * @param  list<string>|null  $categories  Categories to include (null = all or config default)
     * @param  list<string>|null  $columns  Columns to project (null = all default columns)
     * @param  int  $limit  Max events to export
     * @return list<string>  Array of JSON Lines strings
     */
    public function exportNdjson(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $categories = null,
        ?array $columns = null,
        int $limit = self::MAX_BATCH_SIZE,
    ): array {
        $events = $this->queryEvents($startDate, $endDate, $categories, $limit);
        $columns = $columns ?? $this->defaultColumns;
        $lines = [];

        foreach ($events as $event) {
            $row = $this->projectColumns($event, $columns);
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $lines;
    }

    /**
     * Export events as CSV string.
     *
     * First line is the header row, followed by one row per event.
     * Suitable for Redshift COPY, S3 ingestion, and spreadsheet imports.
     *
     * @param  string|null  $startDate  Start date (Y-m-d)
     * @param  string|null  $endDate  End date (Y-m-d)
     * @param  list<string>|null  $categories  Categories to include
     * @param  list<string>|null  $columns  Columns to project
     * @param  int  $limit  Max events to export
     * @return string  CSV string with header row
     */
    public function exportCsv(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $categories = null,
        ?array $columns = null,
        int $limit = self::MAX_BATCH_SIZE,
    ): string {
        $events = $this->queryEvents($startDate, $endDate, $categories, $limit);
        $columns = $columns ?? $this->defaultColumns;
        $lines = [];

        // Header row
        $lines[] = $this->csvEncodeRow($columns);

        foreach ($events as $event) {
            $row = $this->projectColumns($event, $columns);
            $values = array_map(
                fn (string $col): string => $this->csvEncodeValue($row[$col] ?? ''),
                $columns,
            );
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate an aggregated summary of events for warehouse ingestion.
     *
     * Produces per-event-name, per-category, and per-day aggregation counts
     * with first/last seen timestamps and unique user/client counts.
     *
     * @param  string|null  $startDate  Start date (Y-m-d)
     * @param  string|null  $endDate  End date (Y-m-d)
     * @param  list<string>|null  $categories  Categories to include
     * @return array{by_event: array<string, array{name: string, count: int, unique_users: int, unique_clients: int, first_seen: string|null, last_seen: string|null}>, by_category: array<string, int>, by_day: array<string, int>, total: int, date_range: array{start: string, end: string}}
     */
    public function exportSummary(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $categories = null,
    ): array {
        $events = $this->queryEvents($startDate, $endDate, $categories, self::MAX_BATCH_SIZE);

        $byEvent = [];
        $byCategory = [];
        $byDay = [];

        foreach ($events as $event) {
            $name = $event->name;
            $category = $event->category ?? 'unknown';
            $day = $event->created_at?->format('Y-m-d') ?? 'unknown';

            // Per-event aggregation
            if (! isset($byEvent[$name])) {
                $byEvent[$name] = [
                    'name' => $name,
                    'count' => 0,
                    'unique_users' => 0,
                    'unique_clients' => 0,
                    'first_seen' => $event->created_at?->toIso8601String(),
                    'last_seen' => null,
                ];
            }
            $byEvent[$name]['count']++;
            $lastSeen = $event->created_at?->toIso8601String();
            if ($lastSeen !== null) {
                $byEvent[$name]['last_seen'] = $lastSeen;
            }

            // Per-category count
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

            // Per-day count
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        // Count unique users/clients per event (second pass with limited memory)
        $eventUsers = [];
        $eventClients = [];
        foreach ($events as $event) {
            $name = $event->name;
            if ($event->user_id !== null) {
                $eventUsers[$name][$event->user_id] = true;
            }
            if ($event->client_id !== null) {
                $eventClients[$name][$event->client_id] = true;
            }
        }

        foreach ($byEvent as $name => &$data) {
            $data['unique_users'] = count($eventUsers[$name] ?? []);
            $data['unique_clients'] = count($eventClients[$name] ?? []);
        }
        unset($data);

        return [
            'by_event' => $byEvent,
            'by_category' => $byCategory,
            'by_day' => $byDay,
            'total' => count($events),
            'date_range' => [
                'start' => $startDate ?? now()->subDays(30)->format('Y-m-d'),
                'end' => $endDate ?? now()->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Generate a cached snapshot key for deduplication and incremental exports.
     *
     * @param  string  $format  Export format
     * @param  string  $dateRange  Date range identifier
     * @return string  Cache key
     */
    public function snapshotKey(string $format, string $dateRange): string
    {
        return self::CACHE_PREFIX . md5("{$format}:{$dateRange}");
    }

    /**
     * Get a cached snapshot if it exists and hasn't expired.
     *
     * @param  string  $format  Export format
     * @param  string  $dateRange  Date range identifier
     * @return string|array|null  Cached snapshot data or null
     */
    public function getCachedSnapshot(string $format, string $dateRange): string|array|null
    {
        return $this->cache->get($this->snapshotKey($format, $dateRange));
    }

    /**
     * Store a snapshot in cache for future retrieval.
     *
     * @param  string  $format  Export format
     * @param  string  $dateRange  Date range identifier
     * @param  string|array  $data  Snapshot data to cache
     */
    public function cacheSnapshot(string $format, string $dateRange, string|array $data): void
    {
        $this->cache->put($this->snapshotKey($format, $dateRange), $data, $this->ttl);
    }

    /**
     * Clear all cached data lake snapshots.
     *
     * @return int Number of cache keys cleared
     */
    public function clearCache(): int
    {
        $cleared = 0;
        // Best-effort cache clear — depends on cache driver supporting tags or prefix scan
        try {
            $this->cache->flush();
            $cleared = 1;
        } catch (\Throwable $e) {
            Log::warning('AnalyticsDataLakeService: cache flush failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $cleared;
    }

    /**
     * Get the export statistics for the data lake.
     *
     * @return array{enabled: bool, ttl: int, max_batch_size: int, columns_count: int, included_categories: list<string>, default_format: string}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'ttl' => $this->ttl,
            'max_batch_size' => $this->maxBatchSize,
            'columns_count' => count($this->defaultColumns),
            'included_categories' => $this->includedCategories,
            'default_format' => $this->defaultFormat,
        ];
    }

    /**
     * Check if the data lake service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get supported export formats.
     *
     * @return list<string>
     */
    public static function supportedFormats(): array
    {
        return [self::FORMAT_NDJSON, self::FORMAT_CSV, self::FORMAT_SUMMARY];
    }

    /**
     * Query events from the database with date range and category filters.
     *
     * @param  string|null  $startDate
     * @param  string|null  $endDate
     * @param  list<string>|null  $categories
     * @param  int  $limit
     * @return list<AnalyticsEventModel>
     */
    private function queryEvents(
        ?string $startDate,
        ?string $endDate,
        ?array $categories,
        int $limit,
    ): array {
        $start = $startDate ?? now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?? now()->format('Y-m-d');

        try {
            $query = AnalyticsEventModel::query()
                ->where('created_at', '>=', "{$start} 00:00:00")
                ->where('created_at', '<=', "{$end} 23:59:59")
                ->orderBy('created_at', 'desc')
                ->limit(min($limit, $this->maxBatchSize));

            $effectiveCategories = $categories ?? $this->includedCategories;
            if (! empty($effectiveCategories)) {
                $query->whereIn('category', $effectiveCategories);
            }

            /** @var list<AnalyticsEventModel> */
            return $query->get()->all();
        } catch (\Throwable $e) {
            Log::error('AnalyticsDataLakeService: query failed', [
                'start' => $start,
                'end' => $end,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Project event model columns into a flat associative array.
     *
     * @param  AnalyticsEventModel  $event
     * @param  list<string>  $columns  Columns to include
     * @return array<string, mixed>
     */
    private function projectColumns(AnalyticsEventModel $event, array $columns): array
    {
        $map = [
            'id' => $event->getAttribute('id'),
            'name' => $event->name,
            'category' => $event->category,
            'client_id' => $event->client_id,
            'user_id' => $event->user_id,
            'params' => $event->params,
            'session_id' => $event->session_id,
            'page_url' => $event->page_url,
            'referrer' => $event->referrer,
            'ip' => $event->ip,
            'user_agent' => $event->user_agent,
            'created_at' => $event->created_at?->toIso8601String(),
        ];

        $result = [];
        foreach ($columns as $col) {
            $result[$col] = $map[$col] ?? null;
        }

        return $result;
    }

    /**
     * Encode a CSV row (header).
     *
     * @param  list<string>  $values
     * @return string
     */
    private function csvEncodeRow(array $values): string
    {
        return implode(',', array_map(
            fn (string $v): string => $this->csvEncodeValue($v),
            $values,
        ));
    }

    /**
     * Encode a single CSV value with proper escaping.
     *
     * @param  mixed  $value
     * @return string
     */
    private function csvEncodeValue(mixed $value): string
    {
        $str = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');

        // Escape double quotes and wrap if contains comma, quote, or newline
        if (str_contains($str, ',') || str_contains($str, '"') || str_contains($str, "\n")) {
            return '"' . str_replace('"', '""', $str) . '"';
        }

        return $str;
    }
}
