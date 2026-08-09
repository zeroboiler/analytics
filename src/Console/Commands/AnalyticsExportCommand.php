<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Export the event catalog as JSON or CSV for documentation and integration.
 *
 * Outputs the full event catalog with names, categories, classes, and
 * cross-provider mappings. Useful for generating client-side type definitions,
 * API documentation, or data dictionary exports.
 *
 * @since 1.0.0
 */
final class AnalyticsExportCommand extends Command
{
    protected $signature = 'zb:analytics:export
        {--format=json : Export format (json, csv, markdown)}
        {--output=- : Output file path (default: stdout)}
        {--category=* : Filter by category (ecommerce, saas, engagement)}';

    protected $description = 'Export the event catalog as JSON, CSV, or Markdown';

    private AnalyticsManager $manager;

    private EventSchemaRegistry $registry;

    public function __construct(AnalyticsManager $manager, EventSchemaRegistry $registry): void
    {
        parent::__construct();
        $this->manager = $manager;
        $this->registry = $registry;
    }

    #[\Override]
    public function handle(): int
    {
        $format = (string) $this->option('format');
        $output = (string) $this->option('output');
        $categories = (array) $this->option('category');

        $catalog = $this->buildCatalog($categories);

        if (empty($catalog)) {
            $this->warn('No events found matching the given criteria.');

            return self::SUCCESS;
        }

        $content = match ($format) {
            'csv' => $this->toCsv($catalog),
            'markdown' => $this->toMarkdown($catalog),
            default => $this->toJson($catalog),
        };

        if ($output === '-') {
            $this->line($content);
        } else {
            $written = file_put_contents($output, $content.PHP_EOL);

            if ($written === false) {
                $this->error("Failed to write to: {$output}");

                return self::FAILURE;
            }

            $this->info("Exported {$this->countEvents($catalog)} events to: {$output}");
        }

        return self::SUCCESS;
    }

    /**
     * Build the catalog data, optionally filtered by category.
     *
     * @param  list<string>  $categories
     * @return array<int, array{name: string, category: string, class: string, ga4: string, meta: string|null, description: string|null}>
     */
    private function buildCatalog(array $categories): array
    {
        $all = EventCatalog::all();

        if (! empty($categories)) {
            $normalized = array_map(fn (string $c): string => strtolower($c), $categories);
            $all = array_filter(
                $all,
                fn (array $entry): bool => in_array(strtolower($entry['category']), $normalized, true),
            );
        }

        $result = [];

        foreach ($all as $name => $entry) {
            $schema = $this->registry->get($name);
            $result[] = [
                'name' => $entry['name'],
                'category' => $entry['category'],
                'class' => $entry['class'],
                'ga4' => $entry['ga4'],
                'meta' => $entry['meta'] ?? null,
                'description' => $schema?->description ?? null,
            ];
        }

        return $result;
    }

    /**
     * Convert catalog to JSON string.
     *
     * @param  array<int, array<string, mixed>>  $catalog
     */
    private function toJson(array $catalog): string
    {
        return json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert catalog to CSV string.
     *
     * @param  array<int, array<string, mixed>>  $catalog
     */
    private function toCsv(array $catalog): string
    {
        $headers = ['name', 'category', 'class', 'ga4', 'meta', 'description'];
        $lines = [implode(',', $headers)];

        foreach ($catalog as $event) {
            $row = array_map(
                fn (string $field): string => $this->csvEscape((string) ($event[$field] ?? '')),
                $headers,
            );
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines);
    }

    /**
     * Escape a string for CSV output.
     */
    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Convert catalog to Markdown table.
     *
     * @param  array<int, array<string, mixed>>  $catalog
     */
    private function toMarkdown(array $catalog): string
    {
        $lines = [];

        $lines[] = '| Event | Category | GA4 | Meta | Description |';
        $lines[] = '|-------|----------|-----|------|-------------|';

        foreach ($catalog as $event) {
            $meta = $event['meta'] ?? '—';
            $desc = $event['description'] ?? '—';
            $lines[] = '| `'.$event['name'].'` | '.$event['category'].' | '.$event['ga4'].' | '.$meta.' | '.$desc.' |';
        }

        $lines[] = '';
        $lines[] = '> Generated by ZeroBoiler Analytics v'.$this->manager->version();

        return implode("\n", $lines);
    }

    /**
     * Count total events in the catalog array.
     *
     * @param  array<int, array<string, mixed>>  $catalog
     */
    private function countEvents(array $catalog): int
    {
        return count($catalog);
    }
}
