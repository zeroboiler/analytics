<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\ProjectionDefinition;
use ZeroBoiler\Analytics\Services\EventMaterializer;
use ZeroBoiler\Analytics\Services\MetricProjectionEngine;
use ZeroBoiler\Analytics\Services\ProjectionRegistry;

/**
 * CLI command for managing and inspecting metric projections.
 *
 * Provides commands to:
 * - List all registered projections
 * - Evaluate a specific projection
 * - Validate projection definitions
 * - Refresh materialized views
 * - Show registry summary
 *
 * Usage:
 *   php artisan zb:analytics:projections --list
 *   php artisan zb:analytics:projections --evaluate=dau
 *   php artisan zb:analytics:projections --evaluate=dau --window=24h
 *   php artisan zb:analytics:projections --validate
 *   php artisan zb:analytics:projections --refresh-all
 *   php artisan zb:analytics:projections --dashboard
 *   php artisan zb:analytics:projections --export --json
 *
 * @since 128.0.0
 */
final class AnalyticsProjectionsCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:projections
        {--list : List all registered projections}
        {--evaluate= : Evaluate a specific projection by name}
        {--window= : Override the time window (e.g. 24h, 7d, 30d)}
        {--validate : Validate all projection definitions}
        {--refresh-all : Refresh all materialized projections}
        {--dashboard : Show dashboard-ready metrics grouped by category}
        {--export : Export all materialized metrics as flat array}
        {--category= : Filter by category}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Manage and inspect analytics metric projections';

    private ?ProjectionRegistry $registry = null;

    private ?MetricProjectionEngine $engine = null;

    private ?EventMaterializer $materializer = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->resolveDependencies();

        $action = $this->resolveAction();

        return match ($action) {
            'list' => $this->listProjections(),
            'evaluate' => $this->evaluateProjection(),
            'validate' => $this->validateProjections(),
            'refresh' => $this->refreshAll(),
            'dashboard' => $this->showDashboard(),
            'export' => $this->exportMetrics(),
            default => $this->noActionTaken(),
        };
    }

    /**
     * Resolve service dependencies.
     */
    private function resolveDependencies(): void
    {
        $this->registry = $this->app->make(ProjectionRegistry::class);
        $this->engine = $this->app->make(MetricProjectionEngine::class);
        $this->materializer = $this->app->make(EventMaterializer::class);
    }

    /**
     * Determine which action to perform based on options.
     *
     * @return string
     */
    private function resolveAction(): string
    {
        if ($this->option('evaluate') !== null) {
            return 'evaluate';
        }

        if ($this->option('validate')) {
            return 'validate';
        }

        if ($this->option('refresh-all')) {
            return 'refresh';
        }

        if ($this->option('dashboard')) {
            return 'dashboard';
        }

        if ($this->option('export')) {
            return 'export';
        }

        // Default to list
        return 'list';
    }

    /**
     * List all registered projections in a table.
     */
    private function listProjections(): int
    {
        $projections = $this->registry->all();

        if (empty($projections)) {
            $this->warn('No projections registered.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($projections as $definition) {
            $rows[] = [
                $definition->name,
                $definition->label,
                $definition->type,
                $definition->event,
                $definition->category ?? '-',
                $definition->window ?? '-',
                implode(',', $definition->tags),
            ];
        }

        $asJson = $this->option('json');

        if ($asJson) {
            $output = array_map(static fn (ProjectionDefinition $d): array => $d->toArray(), $projections);

            $this->line(json_encode(array_values($output), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Name', 'Label', 'Type', 'Event', 'Category', 'Window', 'Tags'],
                $rows,
            );

            $summary = $this->registry->summary();
            $this->newLine();
            $this->info("Total: {$summary['count']} projections across " . count($summary['categories']) . ' categories');
        }

        return self::SUCCESS;
    }

    /**
     * Evaluate a specific projection.
     */
    private function evaluateProjection(): int
    {
        /** @var string $name */
        $name = $this->option('evaluate');

        /** @var string|null $windowOverride */
        $windowOverride = $this->option('window');

        if (! $this->registry->has($name)) {
            $this->error("Projection '{$name}' not found.");

            $this->info('Available projections:');
            foreach ($this->registry->names() as $availableName) {
                $this->line("  - {$availableName}");
            }

            return self::FAILURE;
        }

        $result = $this->engine->evaluate($name, $windowOverride);

        if ($result === null) {
            $this->error("Failed to evaluate projection '{$name}'.");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $definition = $this->registry->get($name);
            $label = $definition !== null ? $definition->label : $name;
            $windowDisplay = $result->window !== null ? $result->window : 'default';

            $this->line('');
            $this->info("📊 {$label}");
            $this->line("   Type:     {$result->type}");
            $this->line("   Value:    {$result->value}");
            $this->line("   Events:   {$result->eventCount}");
            $this->line("   Window:   {$windowDisplay}");
            $this->line("   Cached:   " . ($result->cached ? '✅ yes' : '❌ no'));
            $this->line("   Stale:    " . ($result->isStale() ? '⚠️ yes' : '✅ no'));
            $this->line("   Computed: {$result->computedAt?->format('Y-m-d H:i:s')}");

            if (! empty($result->metadata)) {
                $this->line("   Metadata: " . json_encode($result->metadata, JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Validate all projection definitions.
     */
    private function validateProjections(): int
    {
        $validation = $this->registry->validate();

        if ($this->option('json')) {
            $this->line(json_encode($validation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("✅ Valid: {$validation['valid']} projections");
            $this->info("❌ Invalid: {$validation['invalid']} projections");

            if (! empty($validation['errors'])) {
                $this->newLine();
                $this->warn('Validation errors:');

                foreach ($validation['errors'] as $name => $errors) {
                    $this->line("  {$name}:");
                    foreach ($errors as $error) {
                        $this->line("    - {$error}");
                    }
                }
            }

            // Also check registry errors
            $registryErrors = $this->registry->errors();

            if (! empty($registryErrors)) {
                $this->newLine();
                $this->warn('Registration errors:');

                foreach ($registryErrors as $name => $errors) {
                    $this->line("  {$name}:");
                    foreach ($errors as $error) {
                        $this->line("    - {$error}");
                    }
                }
            }
        }

        return $validation['invalid'] === 0 && empty($this->registry->errors())
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Refresh all materialized projections.
     */
    private function refreshAll(): int
    {
        $this->info('Refreshing all materialized projections...');

        $results = $this->materializer->refreshAll();

        if ($this->option('json')) {
            $output = [];
            foreach ($results as $name => $result) {
                $output[$name] = $result?->toArray();
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $name => $result) {
                $status = $result !== null ? "✅ {$result->value}" : '❌ null';
                $this->line("  {$name}: {$status}");
            }
        }

        $this->newLine();
        $this->info("Refreshed " . count($results) . ' projections.');

        return self::SUCCESS;
    }

    /**
     * Show dashboard-ready metrics grouped by category.
     */
    private function showDashboard(): int
    {
        /** @var string|null $category */
        $category = $this->option('category');

        /** @var string|null $windowOverride */
        $windowOverride = $this->option('window');

        $dashboard = $this->materializer->dashboard($category, $windowOverride);

        if ($this->option('json')) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($dashboard['categories'] as $cat => $count) {
                $this->newLine();
                $this->info("📊 {$cat} ({$count} metrics)");

                foreach ($dashboard['metrics'] as $name => $metric) {
                    if (($metric['category'] ?? '') === $cat) {
                        $stale = ($metric['stale'] ?? false) ? ' ⚠️' : '';
                        $cached = ($metric['cached'] ?? false) ? ' 📦' : '';
                        $metricWindow = $metric['window'] ?? '-';
                        $this->line(
                            "  {$metric['label']}: {$metric['value']} ({$metric['type']}, {$metricWindow}){$stale}{$cached}",
                        );
                    }
                }
            }

            $this->newLine();
            $this->info("Total: {$dashboard['total']} metrics");
        }

        return self::SUCCESS;
    }

    /**
     * Export all materialized metrics.
     */
    private function exportMetrics(): int
    {
        $export = $this->materializer->export();

        $this->line(json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * No action was explicitly requested — show help.
     */
    private function noActionTaken(): int
    {
        $this->info('Use one of the following options:');
        $this->line('  --list          List all registered projections');
        $this->line('  --evaluate=NAME Evaluate a specific projection');
        $this->line('  --validate      Validate all projection definitions');
        $this->line('  --refresh-all   Refresh all materialized projections');
        $this->line('  --dashboard     Show dashboard-ready metrics');
        $this->line('  --export        Export all materialized metrics');

        return self::SUCCESS;
    }
}
