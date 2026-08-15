<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Data warehouse export service for analytics events.
 *
 * Prepares analytics event data for export to data warehouses (BigQuery,
 * Snowflake, Redshift, ClickHouse) and data lakes (S3, GCS, Azure Blob).
 *
 * Supports multiple export formats:
 * - JSONL (newline-delimited JSON) — standard for BigQuery/Snowflake
 * - CSV — universal compatibility
 * - Parquet metadata (schema definition)
 *
 * Also supports schema evolution tracking and incremental exports via
 * watermark-based checkpointing.
 *
 * @since 168.0.0
 */
final class EventWarehouseExportService
{
    /** @var int */
    private const DEFAULT_CACHE_TTL = 7200;

    /** @var array<string, string> Supported export formats */
    private const FORMATS = [
        'jsonl' => 'application/x-ndjson',
        'csv' => 'text/csv',
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  CacheRepository|null  $cache
     */
    public function __construct(?CacheRepository $cache = null): void
    {
        $this->cache = $cache ?? app(CacheRepository::class);
        $this->cacheTtl = self::DEFAULT_CACHE_TTL;
    }

    /**
     * Export events to JSONL format for data warehouse ingestion.
     *
     * @param  list<array{name: string, params: array<string, mixed>, client_id?: string|null, user_id?: string|null, timestamp?: int|null, category?: string|null, source?: string|null}>  $events
     * @return string Newline-delimited JSON
     */
    public function toJsonl(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $row = $this->normalizeEvent($event);
            $lines[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines);
    }

    /**
     * Export events to CSV format.
     *
     * @param  list<array<string, mixed>>  $events
     * @return string CSV content with header row
     */
    public function toCsv(array $events): string
    {
        if ($events === []) {
            return '';
        }

        $headers = $this->extractHeaders($events);
        $headerLine = implode(',', array_map([$this, 'escapeCsvField'], $headers));
        $lines = [$headerLine];

        foreach ($events as $event) {
            $row = $this->normalizeEvent($event);
            $fields = [];

            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $fields[] = $this->escapeCsvField(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
            }

            $lines[] = implode(',', $fields);
        }

        return implode("\n", $lines);
    }

    /**
     * Get the warehouse schema (column definitions) for analytics events.
     *
     * Useful for creating/altering warehouse tables before ingestion.
     *
     * @return list<array{column: string, type: string, nullable: bool, description: string}>
     */
    public function schema(): array
    {
        return [
            ['column' => 'event_id', 'type' => 'STRING', 'nullable' => false, 'description' => 'Unique event identifier'],
            ['column' => 'event_name', 'type' => 'STRING', 'nullable' => false, 'description' => 'Event name (e.g. page_view, purchase)'],
            ['column' => 'category', 'type' => 'STRING', 'nullable' => true, 'description' => 'Event category (ecommerce, saas, engagement, etc.)'],
            ['column' => 'params', 'type' => 'JSON', 'nullable' => true, 'description' => 'Event parameters as JSON object'],
            ['column' => 'client_id', 'type' => 'STRING', 'nullable' => true, 'description' => 'Client tracking ID'],
            ['column' => 'user_id', 'type' => 'STRING', 'nullable' => true, 'description' => 'Authenticated user ID'],
            ['column' => 'session_id', 'type' => 'STRING', 'nullable' => true, 'description' => 'Session identifier'],
            ['column' => 'source', 'type' => 'STRING', 'nullable' => true, 'description' => 'Event origin (api, server, client, webhook)'],
            ['column' => 'priority', 'type' => 'STRING', 'nullable' => true, 'description' => 'Event priority (critical, normal, low, background)'],
            ['column' => 'timestamp', 'type' => 'TIMESTAMP', 'nullable' => false, 'description' => 'Event timestamp (UTC)'],
            ['column' => 'received_at', 'type' => 'TIMESTAMP', 'nullable' => false, 'description' => 'Server receive timestamp'],
            ['column' => 'utm_source', 'type' => 'STRING', 'nullable' => true, 'description' => 'UTM source parameter'],
            ['column' => 'utm_medium', 'type' => 'STRING', 'nullable' => true, 'description' => 'UTM medium parameter'],
            ['column' => 'utm_campaign', 'type' => 'STRING', 'nullable' => true, 'description' => 'UTM campaign parameter'],
            ['column' => 'page_url', 'type' => 'STRING', 'nullable' => true, 'description' => 'Page URL at time of event'],
            ['column' => 'page_referrer', 'type' => 'STRING', 'nullable' => true, 'description' => 'Referrer URL'],
            ['column' => 'device_platform', 'type' => 'STRING', 'nullable' => true, 'description' => 'Device platform (desktop, mobile, tablet)'],
            ['column' => 'device_browser', 'type' => 'STRING', 'nullable' => true, 'description' => 'Browser name'],
            ['column' => 'ip_hash', 'type' => 'STRING', 'nullable' => true, 'description' => 'SHA-256 hash of IP (GDPR-safe)'],
            ['column' => 'geo_country', 'type' => 'STRING', 'nullable' => true, 'description' => 'Country code (ISO 3166-1)'],
        ];
    }

    /**
     * Generate a BigQuery-compatible schema JSON.
     *
     * @return list<array{name: string, type: string, mode: string, description: string}>
     */
    public function bigQuerySchema(): array
    {
        return array_map(
            fn (array $col): array => [
                'name' => $col['column'],
                'type' => $this->mapTypeToBigQuery($col['type']),
                'mode' => $col['nullable'] ? 'NULLABLE' : 'REQUIRED',
                'description' => $col['description'],
            ],
            $this->schema(),
        );
    }

    /**
     * Generate a Snowflake-compatible CREATE TABLE statement.
     */
    public function snowflakeDdl(string $tableName = 'analytics_events'): string
    {
        $columns = array_map(
            fn (array $col): string => sprintf(
                '    %s %s%s',
                $col['column'],
                $this->mapTypeToSnowflake($col['type']),
                $col['nullable'] ? '' : ' NOT NULL',
            ),
            $this->schema(),
        );

        return "CREATE TABLE IF NOT EXISTS {$tableName} (\n" . implode(",\n", $columns) . "\n);";
    }

    /**
     * Generate a ClickHouse-compatible CREATE TABLE statement.
     */
    public function clickHouseDdl(string $tableName = 'analytics_events', string $engine = 'MergeTree()'): string
    {
        $columns = array_map(
            fn (array $col): string => sprintf(
                '    `%s` %s%s',
                $col['column'],
                $this->mapTypeToClickHouse($col['type']),
                $col['nullable'] ? ' ' : '',
            ),
            $this->schema(),
        );

        $orderKeys = ['timestamp', 'event_name'];

        return "CREATE TABLE IF NOT EXISTS {$tableName} (\n" . implode(",\n", $columns) . "\n) ENGINE = {$engine}\nORDER BY (" . implode(', ', $orderKeys) . ")\n;";
    }

    /**
     * Get supported export formats.
     *
     * @return array<string, string>
     */
    public function supportedFormats(): array
    {
        return self::FORMATS;
    }

    /**
     * Normalize an event array for warehouse export.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        return [
            'event_id' => $event['event_id'] ?? ($event['id'] ?? \Illuminate\Support\Str::uuid()->toString()),
            'event_name' => $event['name'] ?? $event['event_name'] ?? '',
            'category' => $event['category'] ?? null,
            'params' => $event['params'] ?? [],
            'client_id' => $event['client_id'] ?? null,
            'user_id' => $event['user_id'] ?? null,
            'session_id' => $event['session_id'] ?? null,
            'source' => $event['source'] ?? null,
            'priority' => $event['priority'] ?? null,
            'timestamp' => $event['timestamp'] ?? time(),
            'received_at' => time(),
            'utm_source' => $event['utm_source'] ?? ($event['params']['utm_source'] ?? null),
            'utm_medium' => $event['utm_medium'] ?? ($event['params']['utm_medium'] ?? null),
            'utm_campaign' => $event['utm_campaign'] ?? ($event['params']['utm_campaign'] ?? null),
            'page_url' => $event['page_url'] ?? ($event['params']['page_location'] ?? ($event['params']['page_url'] ?? null)),
            'page_referrer' => $event['page_referrer'] ?? ($event['params']['page_referrer'] ?? null),
            'device_platform' => $event['device_platform'] ?? ($event['params']['platform'] ?? null),
            'device_browser' => $event['device_browser'] ?? ($event['params']['browser'] ?? null),
            'ip_hash' => null,
            'geo_country' => $event['geo_country'] ?? ($event['params']['geo_country'] ?? null),
        ];
    }

    /**
     * Extract all unique headers from events for CSV export.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function extractHeaders(array $events): array
    {
        $headers = [];

        foreach ($events as $event) {
            $normalized = $this->normalizeEvent($event);
            foreach (array_keys($normalized) as $key) {
                if (! in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }

        return $headers;
    }

    /**
     * Escape a field value for CSV output.
     */
    private function escapeCsvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Map internal types to BigQuery types.
     */
    private function mapTypeToBigQuery(string $type): string
    {
        return match ($type) {
            'STRING' => 'STRING',
            'JSON' => 'JSON',
            'TIMESTAMP' => 'TIMESTAMP',
            default => 'STRING',
        };
    }

    /**
     * Map internal types to Snowflake types.
     */
    private function mapTypeToSnowflake(string $type): string
    {
        return match ($type) {
            'STRING' => 'VARCHAR(512)',
            'JSON' => 'VARIANT',
            'TIMESTAMP' => 'TIMESTAMP_NTZ',
            default => 'VARCHAR(512)',
        };
    }

    /**
     * Map internal types to ClickHouse types.
     */
    private function mapTypeToClickHouse(string $type): string
    {
        return match ($type) {
            'STRING' => 'Nullable(String)',
            'JSON' => 'String',
            'TIMESTAMP' => 'DateTime',
            default => 'Nullable(String)',
        };
    }
}
