<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Schema\EventSchemaBuilder;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended;

/**
 * CLI command for inspecting and managing the analytics event schema registry.
 *
 * Provides commands for:
 * - Listing all registered schemas
 * - Showing a specific schema with full details
 * - Validating a schema's catalog coverage
 * - Exporting schemas as JSON
 * - Getting summary statistics
 *
 * @since 117.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended
 */
final class AnalyticsSchemaCommand extends Command
{
    /** @var string Command signature */
    protected $signature = 'zb:analytics:schema
        {action? : Action to perform (list, show, validate, export, summary)}
        {--name= : Schema name for show/validate actions}
        {--json : Output as JSON}
        {--category= : Filter by category (list action)}
    ';

    /** @var string Command description */
    protected $description = 'Inspect and manage the analytics event schema registry';

    /**
     * Execute the console command.
     */
    public function handle(EventSchemaRegistryExtended $registry): int
    {
        $action = $this->argument('action') ?? 'summary';

        return match ($action) {
            'list' => $this->listSchemas($registry),
            'show' => $this->showSchema($registry),
            'validate' => $this->validateSchema($registry),
            'export' => $this->exportSchemas($registry),
            'summary' => $this->showSummary($registry),
            default => $this->invalidAction($action),
        };
    }

    /**
     * List all registered schemas.
     *
     * @return int  Exit code
     */
    private function listSchemas(EventSchemaRegistryExtended $registry): int
    {
        $category = $this->option('category');
        $schemas = $registry->all();

        if ($category !== null) {
            $schemas = array_filter(
                $schemas,
                static fn ($s) => $s->category === $category,
            );
        }

        if (empty($schemas)) {
            $this->info('No schemas registered.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $output = [];
            foreach ($schemas as $schema) {
                $output[] = [
                    'name' => $schema->name,
                    'category' => $schema->category,
                    'properties' => count($schema->properties),
                    'required' => count($schema->requiredProperties()),
                    'providers' => $schema->providerCoverageCount(),
                ];
            }

            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Category', 'Props', 'Required', 'Providers', 'Tags'],
            array_map(static fn ($s) => [
                $s->name,
                $s->category,
                count($s->properties),
                count($s->requiredProperties()),
                $s->providerCoverageCount() . '/8',
                implode(', ', array_slice($s->tags, 0, 3)),
            ], $schemas),
        );

        $this->info(sprintf('Total: %d schemas', count($schemas)));

        return self::SUCCESS;
    }

    /**
     * Show a specific schema with full details.
     *
     * @return int  Exit code
     */
    private function showSchema(EventSchemaRegistryExtended $registry): int
    {
        $name = $this->option('name');

        if ($name === null || $name === '') {
            $this->error('Please specify a schema name with --name=');

            return self::FAILURE;
        }

        $schema = $registry->get($name);

        if ($schema === null) {
            $this->error("Schema '{$name}' not found in registry.");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($schema->toJson());

            return self::SUCCESS;
        }

        // Header
        $this->line(sprintf('<info>%s</info> [%s]', $schema->name, $schema->category));
        $this->line($schema->description);
        $this->newLine();

        // Provider mappings
        $providers = $schema->providerMappings();
        $providerLines = [];
        foreach ($providers as $provider => $mapping) {
            $providerLines[] = sprintf('  %-12s %s', $provider . ':', $mapping ?? '<fg=red>—</>');
        }
        $this->line('<comment>Provider Mappings:</comment>');
        $this->line(implode("\n", $providerLines));
        $this->newLine();

        // Properties
        $this->line('<comment>Properties:</comment>');
        foreach ($schema->properties as $propDef) {
            $required = $propDef->isRequired ? '<fg=red>required</>' : 'optional';
            $default = $propDef->hasDefault ? " = {$propDef->defaultValue}" : '';
            $this->line(sprintf(
                '  %-20s <fg=cyan>%-8s</> %s%s',
                $propDef->name,
                $propDef->type,
                $required,
                $default,
            ));
        }

        // Tags
        if (! empty($schema->tags)) {
            $this->newLine();
            $this->line('<comment>Tags:</comment> ' . implode(', ', $schema->tags));
        }

        // Validation rules
        $this->newLine();
        $this->line('<comment>Validation Rules:</comment>');
        foreach ($registry->validationRules($name) as $prop => $rule) {
            $this->line(sprintf('  %-20s %s', $prop . ':', $rule));
        }

        return self::SUCCESS;
    }

    /**
     * Validate a specific schema or all schemas.
     *
     * @return int  Exit code
     */
    private function validateSchema(EventSchemaRegistryExtended $registry): int
    {
        $name = $this->option('name');

        if ($name !== null && $name !== '') {
            $coverage = $registry->catalogCoverage();

            $schema = $registry->get($name);
            if ($schema === null) {
                $this->error("Schema '{$name}' not found.");

                return self::FAILURE;
            }

            $inCatalog = \ZeroBoiler\Analytics\Events\EventCatalog::has($name);

            $this->line(sprintf('<info>%s</info> [%s]', $name, $schema->category));
            $this->line(sprintf('  Catalog: %s', $inCatalog ? '<fg=green>✓ Registered</>' : '<fg=yellow>⚠ Not in EventCatalog</>'));
            $this->line(sprintf('  Properties: %d (%d required, %d optional)', count($schema->properties), count($schema->requiredProperties()), count($schema->optionalProperties())));
            $this->line(sprintf('  Provider Coverage: %d/8', $schema->providerCoverageCount()));

            return self::SUCCESS;
        }

        // Validate all schemas
        $coverage = $registry->catalogCoverage();
        $summary = $registry->summary();

        if ($this->option('json')) {
            $this->line(json_encode([
                'coverage' => $coverage,
                'summary' => $summary,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Schema Registry Validation');
        $this->line(sprintf('  Total schemas: %d', $summary['total']));
        $this->line(sprintf('  In EventCatalog: %d', count($coverage['in_catalog'])));
        $this->line(sprintf('  Missing from catalog: %d', count($coverage['missing_from_catalog'])));

        if (! empty($coverage['missing_from_catalog'])) {
            $this->newLine();
            $this->warn('Schemas missing from EventCatalog:');
            foreach ($coverage['missing_from_catalog'] as $missing) {
                $this->line("  - {$missing}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Export all schemas as JSON.
     *
     * @return int  Exit code
     */
    private function exportSchemas(EventSchemaRegistryExtended $registry): int
    {
        $export = $registry->export();

        if ($this->option('json')) {
            $this->line(json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line(json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info(sprintf('Exported %d schemas.', count($export)));

        return self::SUCCESS;
    }

    /**
     * Show registry summary statistics.
     *
     * @return int  Exit code
     */
    private function showSummary(EventSchemaRegistryExtended $registry): int
    {
        $summary = $registry->summary();
        $coverage = $registry->catalogCoverage();

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'coverage' => $coverage,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('📊 Analytics Schema Registry Summary');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Schemas', (string) $summary['total']],
                ['Total Properties', (string) $summary['total_properties']],
                ['Required Properties', (string) $summary['required_properties']],
                ['In EventCatalog', (string) count($coverage['in_catalog'])],
                ['Missing from Catalog', (string) count($coverage['missing_from_catalog'])],
            ],
        );

        // Category breakdown
        $this->newLine();
        $this->line('<comment>Categories:</comment>');
        foreach ($summary['categories'] as $cat => $count) {
            $this->line("  {$cat}: {$count}");
        }

        // Provider coverage
        $this->newLine();
        $this->line('<comment>Provider Coverage:</comment>');
        foreach ($summary['provider_coverage'] as $provider => $count) {
            $bar = str_repeat('█', (int) ($count / max($summary['total'], 1) * 20));
            $pct = $summary['total'] > 0 ? round(($count / $summary['total']) * 100) : 0;
            $this->line(sprintf('  %-12s %s %3d%% (%d)', $provider, $bar, $pct, $count));
        }

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     *
     * @param  string  $action  Invalid action name
     * @return int  Exit code
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action '{$action}'.");
        $this->line('Available actions: list, show, validate, export, summary');

        return self::FAILURE;
    }
}
