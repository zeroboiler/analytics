<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Data warehouse export service for analytics event ETL.
 *
 * Exports tracked events to CSV or NDJSON format for ingestion by
 * data warehouses (Snowflake, BigQuery, Redshift, Databricks).
 * Supports configurable field selection, filtering by date/event/category,
 * and output to file or stream.
 *
 * @see \ZeroBoiler\Analytics\Services\ExportService
 *
 * @since 1.0.0
 */
final class DataWarehouseExportService
{
    /** @var list<AnalyticsEvent> */
    private array $events = [];

    private string $format;

    /** @var list<string> */
    private array $includeFields;

    private ?string $filterCategory;

    private ?string $filterEvent;

    private ?\DateTimeInterface $filterFrom;

    private ?\DateTimeInterface $filterTo;

    private string $outputPath;

    private bool $includeHeaders;

    private string $nullValue;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $dwConfig = $config->get('zeroboiler.analytics.data_warehouse', []);
        /** @var array{format?: string, include_fields?: list<string>, output_path?: string, include_headers?: bool, null_value?: string} $dwConfig */

        $this->format = (string) ($dwConfig['format'] ?? 'ndjson');
        $this->includeFields = (array) ($dwConfig['include_fields'] ?? []);
        $this->outputPath = (string) ($dwConfig['output_path'] ?? storage_path('app/analytics/exports'));
        $this->includeHeaders = (bool) ($dwConfig['include_headers'] ?? true);
        $this->nullValue = (string) ($dwConfig['null_value'] ?? '');
        $this->filterCategory = null;
        $this->filterEvent = null;
        $this->filterFrom = null;
        $this->filterTo = null;
    }

    /**
     * Add a single event to the export buffer.
     */
    public function addEvent(AnalyticsEvent $event): self
    {
        if ($this->passesFilter($event)) {
            $this->events[] = $event;
        }

        return $this;
    }

    /**
     * Add multiple events to the export buffer.
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function addEvents(array $events): self
    {
        foreach ($events as $event) {
            $this->addEvent($event);
        }

        return $this;
    }

    /**
     * Filter by event category (ecommerce, saas, engagement).
     */
    public function filterByCategory(?string $category): self
    {
        $this->filterCategory = $category;

        return $this;
    }

    /**
     * Filter by exact event name.
     */
    public function filterByEvent(?string $eventName): self
    {
        $this->filterEvent = $eventName;

        return $this;
    }

    /**
     * Filter by date range (from).
     */
    public function filterFrom(?\DateTimeInterface $from): self
    {
        $this->filterFrom = $from;

        return $this;
    }

    /**
     * Filter by date range (to).
     */
    public function filterTo(?\DateTimeInterface $to): self
    {
        $this->filterTo = $to;

        return $this;
    }

    /**
     * Check if an event passes all active filters.
     */
    private function passesFilter(AnalyticsEvent $event): bool
    {
        if ($this->filterCategory !== null) {
            $entry = \ZeroBoiler\Analytics\Events\EventCatalog::get($event->name);
            if ($entry !== null && ($entry['category'] ?? null) !== $this->filterCategory) {
                return false;
            }
        }

        if ($this->filterEvent !== null && $event->name !== $this->filterEvent) {
            return false;
        }

        if ($this->filterFrom !== null) {
            $ts = $event->timestamp;
            if ($ts !== null && $ts < $this->filterFrom) {
                return false;
            }
        }

        if ($this->filterTo !== null) {
            $ts = $event->timestamp;
            if ($ts !== null && $ts > $this->filterTo) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the number of events in the export buffer.
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Clear the export buffer.
     */
    public function clear(): self
    {
        $this->events = [];

        return $this;
    }

    /**
     * Export events to a string.
     *
     * @return string CSV or NDJSON formatted string
     */
    public function exportToString(): string
    {
        return match ($this->format) {
            'csv' => $this->exportCsv(),
            default => $this->exportNdjson(),
        };
    }

    /**
     * Export events to a file.
     *
     * @return array{path: string, format: string, events: int, bytes: int}
     */
    public function exportToFile(?string $filename = null): array
    {
        $filename = $filename ?? $this->generateFilename();
        $fullPath = rtrim($this->outputPath, '/') . '/' . $filename;
        $content = $this->exportToString();

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $written = @file_put_contents($fullPath, $content);

        if ($written === false) {
            try {
                Log::error('DataWarehouseExportService: failed to write export file', [
                    'path' => $fullPath,
                ]);
            } catch (\Throwable) {
                // Log facade unavailable
            }

            $written = 0;
        }

        return [
            'path' => $fullPath,
            'format' => $this->format,
            'events' => count($this->events),
            'bytes' => $written,
        ];
    }

    /**
     * Export as NDJSON (newline-delimited JSON).
     *
     * Each line is a self-contained JSON object representing one event.
     * Compatible with BigQuery, Snowflake COPY INTO, and Redshift COPY.
     *
     * @return string
     */
    private function exportNdjson(): string
    {
        $lines = [];

        foreach ($this->events as $event) {
            $row = $this->eventToRow($event);
            $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines) . (count($lines) > 0 ? "\n" : '');
    }

    /**
     * Export as CSV with optional header row.
     *
     * @return string
     */
    private function exportCsv(): string
    {
        if (count($this->events) === 0) {
            return '';
        }

        // Collect all unique keys across all events for consistent columns
        $allKeys = $this->collectAllKeys();
        $lines = [];

        if ($this->includeHeaders) {
            $lines[] = $this->csvRow($allKeys);
        }

        foreach ($this->events as $event) {
            $row = $this->eventToRow($event);
            $values = [];
            foreach ($allKeys as $key) {
                $values[] = $row[$key] ?? $this->nullValue;
            }
            $lines[] = $this->csvRow($values);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Convert an event to a flat associative array for export.
     *
     * @return array<string, mixed>
     */
    private function eventToRow(AnalyticsEvent $event): array
    {
        $catalogEntry = \ZeroBoiler\Analytics\Events\EventCatalog::get($event->name);

        $row = [
            'event_name' => $event->name,
            'category' => $catalogEntry['category'] ?? null,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'ga4_event' => $catalogEntry['ga4'] ?? null,
            'meta_event' => $catalogEntry['meta'] ?? null,
            'posthog_event' => $catalogEntry['posthog'] ?? null,
            'plausible_event' => $catalogEntry['plausible'] ?? null,
        ];

        // Merge event params
        foreach ($event->params as $key => $value) {
            $row['param_' . $key] = $value;
        }

        // Apply field selection if configured
        if (! empty($this->includeFields)) {
            $filtered = [];
            foreach ($this->includeFields as $field) {
                if (array_key_exists($field, $row)) {
                    $filtered[$field] = $row[$field];
                }
            }

            return $filtered;
        }

        return $row;
    }

    /**
     * Collect all unique keys across all buffered events.
     *
     * @return list<string>
     */
    private function collectAllKeys(): array
    {
        $keys = [];

        foreach ($this->events as $event) {
            $row = array_keys($this->eventToRow($event));
            foreach ($row as $key) {
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Format a row as a CSV line.
     *
     * @param  list<string|null>  $values
     */
    private function csvRow(array $values): string
    {
        $escaped = array_map(
            fn (?string $v): string => $this->csvEscape($v ?? $this->nullValue),
            $values,
        );

        return implode(',', $escaped);
    }

    /**
     * Escape a value for CSV output.
     */
    private function csvEscape(string $value): string
    {
        if (
            str_contains($value, ',') ||
            str_contains($value, '"') ||
            str_contains($value, "\n") ||
            str_contains($value, "\r")
        ) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Generate a filename with timestamp.
     */
    private function generateFilename(): string
    {
        $now = new \DateTimeImmutable('now');

        return sprintf(
            'analytics_export_%s.%s',
            $now->format('Y-m-d_His'),
            $this->format === 'csv' ? 'csv' : 'ndjson',
        );
    }

    /**
     * Get available export formats.
     *
     * @return list<string>
     */
    public static function supportedFormats(): array
    {
        return ['ndjson', 'csv'];
    }

    /**
     * Get the export summary without performing export.
     *
     * @return array{total: int, filtered: int, category: string|null, event: string|null, format: string, fields: int}
     */
    public function summary(): array
    {
        return [
            'total' => count($this->events),
            'filtered' => count($this->events), // After filtering
            'category' => $this->filterCategory,
            'event' => $this->filterEvent,
            'format' => $this->format,
            'fields' => count($this->includeFields) ?: null,
        ];
    }
}
