<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\SchemaDiffReporter;

/**
 * Artisan command to export all analytics event schemas as JSON or TypeScript.
 *
 * Useful for generating documentation, client-side type definitions,
 * and schema validation in downstream systems.
 *
 * @version 2.94.0
 */
final class AnalyticsSchemaExportCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:schema:export
        {--format=json : Output format (json, ts, summary)}
        {--output=- : Output path (- for stdout)}
        {--category=* : Filter by category (ecommerce, saas, engagement)}
        {--with-coverage : Include schema coverage report}
        {--with-providers : Include provider mappings}';

    /** @var string */
    protected $description = 'Export analytics event schemas as JSON, TypeScript, or summary';

    /**
     * Execute the console command.
     */
    public function handle(EventPropertySchema $propertySchema): int
    {
        $format = $this->option('format');
        $output = $this->option('output');
        $categories = $this->option('category');
        $withCoverage = $this->option('with-coverage');
        $withProviders = $this->option('with-providers');

        $propertySchema->registerBuiltInSchemas();
        $schemaRegistry = new EventSchemaRegistry;

        // Build schema data
        $allEvents = EventCatalog::all();

        // Filter by category if specified
        if (! empty($categories)) {
            $allEvents = array_filter(
                $allEvents,
                fn (array $entry): bool => in_array($entry['category'], $categories, true),
            );
        }

        $exported = $this->buildExportData($allEvents, $propertySchema, $schemaRegistry, $withProviders);

        // Add coverage report if requested
        if ($withCoverage) {
            $diffReporter = new SchemaDiffReporter;
            $exported['_coverage'] = $diffReporter->report($propertySchema, $schemaRegistry);
            $exported['_coverage_by_category'] = $diffReporter->reportByCategory($propertySchema, $schemaRegistry);
        }

        // Generate output
        $content = match ($format) {
            'ts' => $this->generateTypeScript($allEvents, $propertySchema),
            'summary' => $this->generateSummary($allEvents, $propertySchema, $schemaRegistry),
            default => json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        };

        // Write output
        if ($output === '-') {
            $this->line($content);

            return self::SUCCESS;
        }

        $bytes = file_put_contents($output, $content . "\n");

        if ($bytes === false) {
            $this->error("Failed to write to: {$output}");

            return self::FAILURE;
        }

        $this->info("Exported {$format} schema to: {$output} ({$bytes} bytes)");

        return self::SUCCESS;
    }

    /**
     * Build the export data structure.
     *
     * @param  array<string, mixed>  $events
     * @return array<string, mixed>
     */
    private function buildExportData(
        array $events,
        EventPropertySchema $propertySchema,
        EventSchemaRegistry $schemaRegistry,
        bool $withProviders,
    ): array {
        $data = [];

        foreach ($events as $name => $entry) {
            $item = [
                'name' => $entry['name'],
                'category' => $entry['category'],
                'class' => $entry['class'],
                'ga4' => $entry['ga4'],
            ];

            if ($withProviders) {
                $item['meta'] = $entry['meta'] ?? null;
                $item['posthog'] = $entry['posthog'] ?? null;
                $item['plausible'] = $entry['plausible'] ?? null;
            }

            // Add property schema
            $propSchema = $propertySchema->getSchema($name);
            if (! empty($propSchema)) {
                $item['properties'] = $propSchema;
            }

            // Add registry schema
            $regSchema = $schemaRegistry->get($name);
            if ($regSchema !== null) {
                $item['registry'] = [
                    'description' => $regSchema->description,
                    'required' => array_keys($regSchema->requiredParams),
                    'optional' => array_keys($regSchema->optionalParams),
                ];
            }

            $data[$name] = $item;
        }

        return $data;
    }

    /**
     * Generate TypeScript type definitions.
     *
     * @param  array<string, mixed>  $events
     */
    private function generateTypeScript(array $events, EventPropertySchema $propertySchema): string
    {
        $lines = [];
        $lines[] = '/**';
        $lines[] = ' * ZeroBoiler Analytics — Event Schema TypeScript Definitions';
        $lines[] = ' * Auto-generated by zb:analytics:schema:export --format=ts';
        $lines[] = ' * @version 2.94.0';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = '// ─── Global Properties ─────────────────────────────────';
        $lines[] = 'export interface AnalyticsGlobalProperties {';
        $lines[] = '  user_id?: string;';
        $lines[] = '  client_id?: string;';
        $lines[] = '  session_id?: string;';
        $lines[] = '  timestamp?: string;';
        $lines[] = '  source?: string;';
        $lines[] = '}';
        $lines[] = '';

        foreach ($events as $name => $entry) {
            $category = $entry['category'];
            $propSchema = $propertySchema->getSchema($name);

            $lines[] = "// ─── {$name} ({$category}) ─────────────────────────";
            $lines[] = '/**';

            if (! empty($propSchema)) {
                foreach ($propSchema as $prop => $rules) {
                    $desc = $rules['description'] ?? '';
                    $required = $rules['required'] ?? false;
                    $prefix = $required ? ' * @property [required] ' : ' * @property [optional] ';
                    $lines[] = "{$prefix}{$prop} - {$desc}";
                }
            }

            $lines[] = ' * @ga4 ' . ($entry['ga4'] ?? $name);
            $lines[] = ' */';

            $typeName = $this->toTypeName($name);

            if (! empty($propSchema)) {
                $lines[] = "export interface {$typeName}Params extends AnalyticsGlobalProperties {";

                foreach ($propSchema as $prop => $rules) {
                    $tsType = $this->phpTypeToTs($rules['type'] ?? 'string');
                    $required = $rules['required'] ?? false;
                    $optional = $required ? '' : '?';
                    $lines[] = "  {$prop}{$optional}: {$tsType};";
                }

                $lines[] = '}';
            } else {
                $lines[] = "export interface {$typeName}Params extends AnalyticsGlobalProperties {";
                $lines[] = '  [key: string]: unknown;';
                $lines[] = '}';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a human-readable summary.
     *
     * @param  array<string, mixed>  $events
     */
    private function generateSummary(
        array $events,
        EventPropertySchema $propertySchema,
        EventSchemaRegistry $schemaRegistry,
    ): string {
        $diffReporter = new SchemaDiffReporter;
        $lines = [];
        $lines[] = $diffReporter->summary($propertySchema, $schemaRegistry);
        $lines[] = '';
        $lines[] = str_repeat('─', 50);
        $lines[] = 'Event Details:';
        $lines[] = str_repeat('─', 50);

        foreach ($events as $name => $entry) {
            $hasProp = $propertySchema->hasSchema($name) ? '✓' : '✗';
            $hasReg = $schemaRegistry->has($name) ? '✓' : '✗';
            $ga4 = $entry['ga4'] ?? $name;
            $meta = $entry['meta'] ?? '—';

            $lines[] = '';
            $lines[] = "[{$entry['category']}] {$name}";
            $lines[] = "  GA4: {$ga4} | Meta: {$meta}";
            $lines[] = "  Property schema: {$hasProp} | Registry schema: {$hasReg}";

            $propSchema = $propertySchema->getSchema($name);
            if (! empty($propSchema)) {
                $requiredParams = array_filter($propSchema, fn (array $r): bool => ($r['required'] ?? false));
                $optionalParams = array_filter($propSchema, fn (array $r): bool => ! ($r['required'] ?? false));

                if (! empty($requiredParams)) {
                    $names = array_map(fn (string $p): string => $p, array_keys($requiredParams));
                    $lines[] = '  Required: ' . implode(', ', $names);
                }

                if (! empty($optionalParams)) {
                    $names = array_map(fn (string $p): string => $p, array_keys($optionalParams));
                    $lines[] = '  Optional: ' . implode(', ', $names);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Convert event name to TypeScript type name.
     */
    private function toTypeName(string $eventName): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $eventName)));
    }

    /**
     * Convert PHP type to TypeScript type.
     */
    private function phpTypeToTs(string $phpType): string
    {
        return match ($phpType) {
            'string' => 'string',
            'int', 'integer' => 'number',
            'float', 'number', 'numeric' => 'number',
            'bool', 'boolean' => 'boolean',
            'array', 'object' => 'Record<string, unknown>',
            default => 'unknown',
        };
    }
}
