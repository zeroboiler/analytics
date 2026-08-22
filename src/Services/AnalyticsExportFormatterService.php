<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Models\AnalyticsEventModel;
use Illuminate\Support\Collection;

/**
 * Analytics data export formatter service.
 *
 * Transforms analytics events into industry-standard export formats
 * for data warehouse loading, CSV export, and JSON serialization.
 * Supports GA4 BigQuery schema, Segment JSON, Snowplow canonical format,
 * and raw flat-table CSV with configurable column selection.
 *
 * @since 54.0.0
 */
final class AnalyticsExportFormatterService
{
    /**
     * Export a collection of events to CSV format.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @param  list<string>  $columns  Columns to include (null = all default columns)
     * @return string  CSV content with header row
     */
    public function toCsv(Collection $events, ?array $columns = null): string
    {
        $columns = $columns ?? $this->defaultColumns();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $columns);

        foreach ($events as $event) {
            $row = [];
            foreach ($columns as $col) {
                $row[] = $this->extractColumn($event, $col);
            }
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Export a collection of events to Segment JSON format.
     *
     * Transforms each event into the Segment specification format with
     * type, event name, properties, context, and identity fields.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @return list<array<string, mixed>>
     */
    public function toSegmentFormat(Collection $events): array
    {
        return $events->map(fn (AnalyticsEventModel $event): array => [
            'type' => 'track',
            'event' => $event->event_name,
            'timestamp' => $event->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'userId' => $event->user_id,
            'anonymousId' => $event->client_id,
            'properties' => $this->decodeParams($event->params),
            'context' => [
                'source' => 'zeroboiler_analytics',
                'version' => AnalyticsEvent::VERSION,
                'category' => $this->resolveCategory($event->event_name),
                'provider' => $event->provider ?? 'server',
            ],
            'integrations' => $this->buildIntegrations($event),
        ])->values()->all();
    }

    /**
     * Export a collection of events to GA4 BigQuery schema format.
     *
     * Matches the GA4 BigQuery export schema for direct loading
     * into BigQuery without transformation.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @return list<array<string, mixed>>
     */
    public function toBigQueryFormat(Collection $events): array
    {
        return $events->map(fn (AnalyticsEventModel $event): array => array_filter([
            'event_date' => $event->created_at?->format('Y%m\d') ?? now()->format('Ymd'),
            'event_timestamp' => ($event->created_at?->timestamp ?? now()->timestamp) * 1_000_000,
            'event_name' => $event->event_name,
            'event_params' => $this->toBigQueryParams($event->params),
            'event_previous_timestamp' => null,
            'event_bundle_sequence_id' => null,
            'event_server_timestamp_offset' => null,
            'user_id' => $event->user_id,
            'user_pseudo_id' => $event->client_id,
            'user_first_touch_timestamp' => null,
            'user_properties' => new \stdClass(),
            'device' => new \stdClass(),
            'geo' => new \stdClass(),
            'traffic_source' => new \stdClass(),
            'stream_id' => null,
            'platform' => 'SERVER',
            'items' => $this->extractItems($event->params),
        ]))->values()->all();
    }

    /**
     * Export a collection of events to Snowplow canonical format.
     *
     * Transforms events into the Snowplow self-describing JSON schema
     * for loading into Snowplow or compatible data warehouses.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @return list<array<string, mixed>>
     */
    public function toSnowplowFormat(Collection $events): array
    {
        return $events->map(fn (AnalyticsEventModel $event): array => [
            'schema' => 'iglu:com.zeroboiler/analytics_event/jsonschema/1-0-0',
            'data' => [
                'event_name' => $event->event_name,
                'event_id' => $event->id,
                'client_id' => $event->client_id,
                'user_id' => $event->user_id,
                'timestamp' => $event->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'params' => $this->decodeParams($event->params),
                'category' => $this->resolveCategory($event->event_name),
                'provider' => $event->provider ?? 'server',
                'context' => [
                    'version' => AnalyticsEvent::VERSION,
                    'source' => 'zeroboiler_analytics',
                ],
            ],
        ])->values()->all();
    }

    /**
     * Generate a summary export with aggregation metadata.
     *
     * Produces an export manifest with total events, time range,
     * category distribution, and per-provider counts alongside the
     * formatted data.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @param  string  $format  One of: csv, segment, bigquery, snowplow
     * @return array{meta: array<string, mixed>, data: mixed}
     */
    public function exportWithMetadata(Collection $events, string $format = 'csv'): array
    {
        return [
            'meta' => [
                'exported_at' => now()->toIso8601String(),
                'total_events' => $events->count(),
                'format' => $format,
                'version' => AnalyticsEvent::VERSION,
                'time_range' => $this->computeTimeRange($events),
                'category_distribution' => $this->computeCategoryDistribution($events),
                'provider_distribution' => $events->groupBy('provider')->map->count()->toArray(),
            ],
            'data' => match ($format) {
                'csv' => $this->toCsv($events),
                'segment' => $this->toSegmentFormat($events),
                'bigquery' => $this->toBigQueryFormat($events),
                'snowplow' => $this->toSnowplowFormat($events),
                default => $this->toCsv($events),
            },
        ];
    }

    /**
     * Get the list of supported export formats.
     *
     * @return list<array{format: string, label: string, description: string}>
     */
    public static function supportedFormats(): array
    {
        return [
            ['format' => 'csv', 'label' => 'CSV', 'description' => 'Comma-separated values with configurable columns'],
            ['format' => 'segment', 'label' => 'Segment JSON', 'description' => 'Segment specification format (type, event, properties, context)'],
            ['format' => 'bigquery', 'label' => 'GA4 BigQuery', 'description' => 'GA4 BigQuery export schema for direct warehouse loading'],
            ['format' => 'snowplow', 'label' => 'Snowplow', 'description' => 'Snowplow self-describing JSON schema format'],
        ];
    }

    /**
     * Get the default CSV column list.
     *
     * @return list<string>
     */
    private function defaultColumns(): array
    {
        return [
            'id', 'event_name', 'client_id', 'user_id', 'params',
            'provider', 'category', 'created_at', 'session_id',
        ];
    }

    /**
     * Extract a column value from an event model.
     *
     * @param  AnalyticsEventModel  $event
     * @param  string  $column
     */
    private function extractColumn(AnalyticsEventModel $event, string $column): string
    {
        return match ($column) {
            'id' => (string) $event->id,
            'event_name' => $event->event_name,
            'client_id' => (string) $event->client_id,
            'user_id' => (string) ($event->user_id ?? ''),
            'params' => json_encode($this->decodeParams($event->params), JSON_UNESCAPED_SLASHES),
            'provider' => $event->provider ?? 'server',
            'category' => $this->resolveCategory($event->event_name),
            'created_at' => $event->created_at?->toIso8601String() ?? '',
            'session_id' => (string) ($event->session_id ?? ''),
            default => '',
        };
    }

    /**
     * Decode JSON params string to array.
     *
     * @param  string|null  $params
     * @return array<string, mixed>
     */
    private function decodeParams(?string $params): array
    {
        if ($params === null || $params === '') {
            return [];
        }

        $decoded = json_decode($params, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Convert event params to GA4 BigQuery parameter format.
     *
     * BigQuery expects an array of {name, value} objects.
     *
     * @param  string|null  $params
     * @return list<array{name: string, value: mixed}>
     */
    private function toBigQueryParams(?string $params): array
    {
        $decoded = $this->decodeParams($params);
        $result = [];

        foreach ($decoded as $key => $value) {
            $result[] = [
                'name' => $key,
                'value' => [
                    'string_value' => is_string($value) ? $value : null,
                    'int_value' => is_int($value) ? $value : null,
                    'float_value' => is_float($value) ? $value : null,
                    'double_value' => is_float($value) ? $value : null,
                ],
            ];
        }

        return $result;
    }

    /**
     * Extract e-commerce items from event params for BigQuery format.
     *
     * @param  string|null  $params
     * @return list<array<string, mixed>>
     */
    private function extractItems(?string $params): array
    {
        $decoded = $this->decodeParams($params);
        $items = $decoded['items'] ?? [];

        return is_array($items) ? $items : [];
    }

    /**
     * Resolve the event category from the catalog.
     */
    private function resolveCategory(string $eventName): string
    {
        $entry = EventCatalog::get($eventName);

        return $entry['category'] ?? 'unknown';
    }

    /**
     * Build Segment integrations object from event model.
     *
     * @param  AnalyticsEventModel  $event
     * @return array<string, bool>
     */
    private function buildIntegrations(AnalyticsEventModel $event): array
    {
        $provider = $event->provider ?? 'server';
        $integrations = ['All' => true];

        if ($provider !== 'server' && $provider !== 'all') {
            $integrations[$provider] = true;
        }

        return $integrations;
    }

    /**
     * Compute the time range of exported events.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @return array{from: string|null, to: string|null}
     */
    private function computeTimeRange(Collection $events): array
    {
        if ($events->isEmpty()) {
            return ['from' => null, 'to' => null];
        }

        return [
            'from' => $events->min('created_at')?->toIso8601String(),
            'to' => $events->max('created_at')?->toIso8601String(),
        ];
    }

    /**
     * Compute event category distribution for the export metadata.
     *
     * @param  Collection<int, AnalyticsEventModel>  $events
     * @return array<string, int>
     */
    private function computeCategoryDistribution(Collection $events): array
    {
        return $events
            ->map(fn (AnalyticsEventModel $e): string => $this->resolveCategory($e->event_name))
            ->groupBy(fn (string $cat): string => $cat)
            ->map->count()
            ->toArray();
    }
}
