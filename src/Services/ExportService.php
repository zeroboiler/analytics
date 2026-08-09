<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Export service for analytics event data.
 *
 * Generates CSV and JSON exports from the event stream buffer or
 * from provided event arrays. Supports date range filtering,
 * event name filtering, and category-based exports.
 *
 * Designed for admin dashboards, compliance reporting, and
 * data warehouse integration pipelines.
 *
 * @see \ZeroBoiler\Analytics\Services\EventStreamService
 *
 * @since 1.0.0
 */
final class ExportService
{
    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private EventStreamService $stream;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  EventStreamService  $stream
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        EventStreamService $stream,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->stream = $stream;
    }

    /**
     * Export events from the stream buffer as a CSV string.
     *
     * @param  string|null  $filter  Event name filter (null = all)
     * @param  string|null  $category  Category filter (null = all)
     * @param  int  $limit  Maximum events to export
     * @return string CSV content
     */
    public function toCsv(?string $filter = null, ?string $category = null, int $limit = 1000): string
    {
        $events = $this->resolveEvents($filter, $category, $limit);

        $headers = ['id', 'event', 'category', 'provider', 'client_id', 'user_id', 'timestamp', 'dispatched'];
        $lines = [implode(',', $headers)];

        foreach ($events as $entry) {
            $cat = $this->manager->eventCategory($entry['event']) ?? 'custom';
            $fields = [
                $entry['id'],
                $this->csvEscape($entry['event']),
                $this->csvEscape($cat),
                $this->csvEscape($entry['provider'] ?? ''),
                $this->csvEscape($entry['client_id'] ?? ''),
                $this->csvEscape($entry['user_id'] ?? ''),
                $this->csvEscape($entry['timestamp']),
                $entry['dispatched'] ? 'yes' : 'no',
            ];
            $lines[] = implode(',', $fields);
        }

        return implode("\n", $lines);
    }

    /**
     * Export events from the stream buffer as a JSON string.
     *
     * @param  string|null  $filter  Event name filter (null = all)
     * @param  string|null  $category  Category filter (null = all)
     * @param  int  $limit  Maximum events to export
     * @param  bool  $pretty  Pretty-print JSON
     * @return string JSON content
     */
    public function toJson(?string $filter = null, ?string $category = null, int $limit = 1000, bool $pretty = false): string
    {
        $events = $this->resolveEvents($filter, $category, $limit);

        $export = [];

        foreach ($events as $entry) {
            $export[] = [
                'id' => $entry['id'],
                'event' => $entry['event'],
                'category' => $this->manager->eventCategory($entry['event']) ?? 'custom',
                'params' => $entry['params'],
                'provider' => $entry['provider'] ?? null,
                'client_id' => $entry['client_id'] ?? null,
                'user_id' => $entry['user_id'] ?? null,
                'timestamp' => $entry['timestamp'],
                'dispatched' => $entry['dispatched'],
            ];
        }

        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;

        return json_encode($export, $flags) ?: '[]';
    }

    /**
     * Generate a metrics summary export as JSON.
     *
     * Includes dispatch counts, failure rates, provider stats,
     * event catalog summary, and configuration overview.
     *
     * @return string JSON content
     */
    public function metricsExport(): string
    {
        $data = [
            'generated_at' => date('c'),
            'version' => $this->manager->version(),
            'metrics' => [
                'total_dispatched' => $this->metrics->totalDispatched(),
                'total_failed' => $this->metrics->totalFailed(),
                'per_provider' => $this->metrics->summary(),
            ],
            'providers' => $this->manager->providerSummary(),
            'catalog' => $this->manager->eventCatalogSummary(),
            'stream' => $this->stream->stats(),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Generate a compliance-ready export with PII redaction.
     *
     * All user-identifiable fields are hashed or removed.
     *
     * @param  int  $limit  Maximum events
     * @return string JSON content
     */
    public function complianceExport(int $limit = 1000): string
    {
        $events = $this->stream->since(0, $limit);
        $export = [];

        foreach ($events as $entry) {
            $sanitizedParams = $this->redactPii($entry['params']);

            $export[] = [
                'id' => $entry['id'],
                'event' => $entry['event'],
                'category' => $this->manager->eventCategory($entry['event']) ?? 'custom',
                'params' => $sanitizedParams,
                'client_id_hash' => $entry['client_id'] !== null
                    ? hash('sha256', $entry['client_id'])
                    : null,
                'user_id_hash' => $entry['user_id'] !== null
                    ? hash('sha256', $entry['user_id'])
                    : null,
                'timestamp' => $entry['timestamp'],
            ];
        }

        return json_encode($export, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Resolve events based on filter and category constraints.
     *
     * @return list<array{id: int, event: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, provider: string|null, timestamp: string, dispatched: bool}>
     */
    private function resolveEvents(?string $filter, ?string $category, int $limit): array
    {
        if ($category !== null) {
            return $this->stream->filterByCategory($category, $limit);
        }

        if ($filter !== null) {
            return $this->stream->filter($filter, $limit);
        }

        return $this->stream->since(0, $limit);
    }

    /**
     * Escape a value for CSV output.
     */
    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Redact PII from event parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function redactPii(array $params): array
    {
        $piiKeys = [
            'email', 'phone', 'name', 'first_name', 'last_name',
            'address', 'ip', 'user_agent', 'password', 'token',
            'credit_card', 'ssn', 'date_of_birth',
        ];

        $redacted = [];

        foreach ($params as $key => $value) {
            if (in_array(strtolower($key), $piiKeys, true) && is_string($value)) {
                $redacted[$key] = hash('sha256', $value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
