<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Enhanced event export service for SaaS analytics dashboards.
 *
 * Provides structured export of the event catalog, provider mappings,
 * and event schema metadata. Designed for:
 * - Admin dashboard data tables
 * - Documentation generators
 * - Event catalog API responses
 * - Developer tooling (auto-complete, type hints)
 *
 * Unlike ExportService (which exports live event data from the stream),
 * this service exports the static event catalog metadata and mappings.
 *
 * @see \ZeroBoiler\Analytics\Services\ExportService
 */
final class EventExporterService
{
    /**
     * Export the full event catalog as a structured array.
     *
     * Returns all events grouped by category with provider mappings,
     * parameter schemas, and metadata suitable for API responses
     * or admin dashboard data tables.
     *
     * @return array{version: string, total: int, categories: array<string, array{count: int, events: array<string, mixed>}>, providers: array<string, list<string>>}
     */
    public function exportCatalog(): array
    {
        $byCategory = EventCatalog::byCategory();
        $result = [
            'version' => '2.63.0',
            'total' => EventCatalog::count(),
            'categories' => [],
        ];

        foreach ($byCategory as $categoryName => $events) {
            $categoryEvents = [];

            foreach ($events as $eventName => $entry) {
                $categoryEvents[$eventName] = [
                    'ga4' => $entry['ga4'] ?? $eventName,
                    'meta' => $entry['meta'] ?? null,
                    'class' => $entry['class'] ?? null,
                ];
            }

            $result['categories'][$categoryName] = [
                'count' => count($categoryEvents),
                'events' => $categoryEvents,
            ];
        }

        return $result;
    }

    /**
     * Export provider mapping table as a flat key-value array.
     *
     * Maps each catalog event name to its GA4, Meta, PostHog, and
     * Plausible equivalents. Useful for documentation and debugging.
     *
     * @return array<string, array{ga4: string, meta: string|null, posthog: string, plausible: string|null}>
     */
    public function exportProviderMappings(): array
    {
        $catalog = EventCatalog::all();
        $posthogMap = \ZeroBoiler\Analytics\Support\EventTransformer::saasToPosthogEventMap();
        $plausibleMap = \ZeroBoiler\Analytics\Support\EventTransformer::toPlausibleEventMap();
        $mappings = [];

        foreach ($catalog as $name => $entry) {
            $plausibleName = $plausibleMap[$name] ?? $name;

            $mappings[$name] = [
                'ga4' => $entry['ga4'] ?? $name,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $posthogMap[$name] ?? $name,
                'plausible' => $plausibleName !== null ? $plausibleName : null,
            ];
        }

        return $mappings;
    }

    /**
     * Export event catalog as JSON string.
     *
     * @param  bool  $pretty  Pretty-print output
     * @return string JSON string
     */
    public function exportCatalogJson(bool $pretty = false): string
    {
        $data = $this->exportCatalog();
        $flags = $pretty
            ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            : JSON_UNESCAPED_SLASHES;

        return json_encode($data, $flags) ?: '{}';
    }

    /**
     * Export provider mappings as JSON string.
     *
     * @param  bool  $pretty  Pretty-print output
     * @return string JSON string
     */
    public function exportMappingsJson(bool $pretty = false): string
    {
        $data = $this->exportProviderMappings();
        $flags = $pretty
            ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            : JSON_UNESCAPED_SLASHES;

        return json_encode($data, $flags) ?: '{}';
    }

    /**
     * Export event catalog as CSV string.
     *
     * Columns: event_name, category, ga4_name, meta_name, posthog_name, class
     *
     * @return string CSV content with header row
     */
    public function exportCatalogCsv(): string
    {
        $mappings = $this->exportProviderMappings();
        $catalog = EventCatalog::all();
        $headers = ['event_name', 'category', 'ga4_name', 'meta_name', 'posthog_name', 'plausible_name', 'class'];
        $lines = [implode(',', $headers)];

        foreach ($catalog as $name => $entry) {
            $mapping = $mappings[$name] ?? [];
            $fields = [
                $this->csvEscape($name),
                $this->csvEscape($entry['category'] ?? 'unknown'),
                $this->csvEscape($mapping['ga4'] ?? $name),
                $this->csvEscape($mapping['meta'] ?? ''),
                $this->csvEscape($mapping['posthog'] ?? $name),
                $this->csvEscape($mapping['plausible'] ?? ''),
                $this->csvEscape($entry['class'] ?? ''),
            ];
            $lines[] = implode(',', $fields);
        }

        return implode("\n", $lines);
    }

    /**
     * Get a summary of the event catalog for quick reference.
     *
     * @return array{total: int, ecommerce: int, saas: int, engagement: int, ga4_mappings: int, meta_mappings: int, posthog_mappings: int, plausible_mappings: int}
     */
    public function summary(): array
    {
        return [
            'total' => EventCatalog::count(),
            'ecommerce' => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count(),
            'saas' => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count(),
            'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count(),
            'ga4_mappings' => count(EventCatalog::allGa4Names()),
            'meta_mappings' => count(EventCatalog::allMetaNames()),
            'posthog_mappings' => count(EventCatalog::allPosthogNames()),
            'plausible_mappings' => count(EventCatalog::allPlausibleNames()),
        ];
    }

    /**
     * Export events for a specific category only.
     *
     * @param  'ecommerce'|'saas'|'engagement'  $category
     * @return array<string, array{ga4: string, meta: string|null, class: string|null}>
     */
    public function exportCategory(string $category): array
    {
        $events = EventCatalog::category($category);
        $result = [];

        foreach ($events as $name => $entry) {
            $result[$name] = [
                'ga4' => $entry['ga4'] ?? $name,
                'meta' => $entry['meta'] ?? null,
                'class' => $entry['class'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Escape a value for CSV output.
     */
    private function csvEscape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
