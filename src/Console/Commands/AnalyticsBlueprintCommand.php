<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintBuilderService;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry;

/**
 * CLI interface for the Event Blueprint Builder.
 *
 * Provides actions for listing blueprints, inspecting schemas,
 * building events (dry-run), generating provider payloads,
 * and validating the blueprint registry.
 *
 * @since 247.0.0
 */
final class AnalyticsBlueprintCommand extends Command
{
    protected $signature = 'zb:analytics:blueprint
        {action? : Action to perform (list|schema|build|payloads|validate|config)}
        {--name= : Blueprint name for schema/build/payloads}
        {--json : Output as JSON}
        {--params= : JSON-encoded params for build/payloads}
        {--category= : Filter blueprints by category}
        {--computed= : JSON-encoded computed params (e.g. {"value":"price*quantity"})}';

    protected $description = 'Build, inspect, and validate analytics event blueprints';

    private EventBlueprintBuilderService $builder;

    private EventBlueprintRegistry $registry;

    public function __construct(EventBlueprintBuilderService $builder, EventBlueprintRegistry $registry): void
    {
        parent::__construct();
        $this->builder = $builder;
        $this->registry = $registry;
    }

    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action');
        $asJson = (bool) $this->option('json');

        if (! is_string($action) || $action === '') {
            $this->renderDefault($asJson);

            return self::SUCCESS;
        }

        return match ($action) {
            'list' => $this->actionList($asJson),
            'schema' => $this->actionSchema($asJson),
            'build' => $this->actionBuild($asJson),
            'payloads' => $this->actionPayloads($asJson),
            'validate' => $this->actionValidate($asJson),
            'config' => $this->actionConfig($asJson),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Default: show registry summary.
     */
    private function renderDefault(bool $asJson): void
    {
        $diagnostics = $this->registry->diagnostics();

        if ($asJson) {
            $this->line(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->info('🎨 Event Blueprint Registry');
        $this->line('   Total: <info>' . $diagnostics['total'] . '</info> blueprints');
        $this->line('   Built-in: <info>' . $diagnostics['built_in'] . '</info>');
        $this->line('   Config: <info>' . $diagnostics['config'] . '</info>');
        $this->line('   Runtime: <info>' . $diagnostics['runtime'] . '</info>');
        $this->line('   Deprecated: <comment>' . $diagnostics['deprecated'] . '</comment>');
        $this->newLine();

        if ($diagnostics['by_category'] !== []) {
            $this->info('By Category:');
            foreach ($diagnostics['by_category'] as $cat => $count) {
                $this->line("   {$cat}: <info>{$count}</info>");
            }
        }

        $this->newLine();
        $this->comment('Actions: list, schema, build, payloads, validate, config');
    }

    /**
     * List all blueprints.
     */
    private function actionList(bool $asJson): int
    {
        $schemas = $this->builder->allSchemas();
        $categoryFilter = $this->option('category');

        if (is_string($categoryFilter) && $categoryFilter !== '') {
            $schemas = array_values(array_filter(
                $schemas,
                fn (array $s): bool => $s['category'] === $categoryFilter,
            ));
        }

        if ($asJson) {
            $this->line(json_encode($schemas, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($schemas === []) {
            $this->warn('No blueprints found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Blueprint', 'Label', 'Category', 'Base Event', 'Params', 'Required'],
            array_map(fn (array $s): array => [
                $s['name'],
                $s['label'],
                $s['category'],
                $s['base_event'],
                (string) $s['param_count'],
                (string) $s['required_count'],
            ], $schemas),
        );

        return self::SUCCESS;
    }

    /**
     * Show blueprint schema.
     */
    private function actionSchema(bool $asJson): int
    {
        $name = $this->option('name');

        if (! is_string($name) || $name === '') {
            $this->error('--name=<blueprint> is required for schema action.');

            return self::FAILURE;
        }

        $schema = $this->builder->schema($name);

        if ($schema === null) {
            $this->error("Blueprint '{$name}' not found.");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📋 {$schema['label']} ({$schema['name']})");
        $this->line('   Category: <info>' . $schema['category'] . '</info>');
        $this->line('   Base Event: <info>' . ($schema['base_event'] !== '' ? $schema['base_event'] : '(self)') . '</info>');
        $this->newLine();

        if ($schema['required'] !== []) {
            $this->line('   <fg=red>Required:</> ' . implode(', ', $schema['required']));
        }

        if ($schema['optional'] !== []) {
            $this->line('   <fg=cyan>Optional:</> ' . implode(', ', $schema['optional']));
        }

        if ($schema['types'] !== []) {
            $this->newLine();
            $this->info('   Parameter Types:');
            foreach ($schema['types'] as $param => $type) {
                $required = in_array($param, $schema['required'], true) ? '*' : ' ';
                $default = array_key_exists($param, $schema['defaults'])
                    ? ' = ' . json_encode($schema['defaults'][$param])
                    : '';
                $this->line("   {$required} <info>{$param}</info>: {$type}{$default}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build an event from a blueprint (dry-run).
     */
    private function actionBuild(bool $asJson): int
    {
        $name = $this->option('name');

        if (! is_string($name) || $name === '') {
            $this->error('--name=<blueprint> is required for build action.');

            return self::FAILURE;
        }

        $params = $this->parseParamsOption();
        $computed = $this->parseComputedOption();

        $builder = $this->builder->from($name)->with($params);

        if ($computed !== []) {
            $builder->compute($computed);
        }

        $report = $builder->buildReport();

        if ($asJson) {
            $output = [
                'event_name' => $report['event']->name,
                'params' => $report['event']->params,
                'category' => $report['event']->category,
                'priority' => $report['event']->priority,
                'client_id' => $report['event']->clientId,
                'user_id' => $report['event']->userId,
                'source' => $report['event']->source,
                'errors' => $report['errors'],
                'warnings' => $report['warnings'],
                'coerced' => $report['coerced'],
            ];
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📦 Built: <info>{$report['event']->name}</info>");
        $this->line('   Category: ' . ($report['event']->category ?? '(auto)'));
        $this->line('   Priority: ' . ($report['event']->priority ?? '(default)'));
        $this->newLine();

        $this->info('   Parameters:');
        foreach ($report['event']->params as $key => $value) {
            $display = is_array($value) ? json_encode($value) : (string) $value;
            $this->line("   <info>{$key}</info>: {$display}");
        }

        if ($report['coerced'] !== []) {
            $this->newLine();
            $this->line('   <fg=cyan>Coercions:</>');
            foreach ($report['coerced'] as $log) {
                $this->line("   ↳ {$log}");
            }
        }

        if ($report['errors'] !== []) {
            $this->newLine();
            foreach ($report['errors'] as $error) {
                $this->error("   ✗ {$error}");
            }
        }

        if ($report['warnings'] !== []) {
            $this->newLine();
            foreach ($report['warnings'] as $warning) {
                $this->warn("   ⚠ {$warning}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Generate provider payloads for a blueprint.
     */
    private function actionPayloads(bool $asJson): int
    {
        $name = $this->option('name');

        if (! is_string($name) || $name === '') {
            $this->error('--name=<blueprint> is required for payloads action.');

            return self::FAILURE;
        }

        $params = $this->parseParamsOption();
        $payloads = $this->builder->from($name)->with($params)->toProviderPayloads();

        if ($asJson) {
            $this->line(json_encode($payloads, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🌐 Provider Payloads for <info>{$name}</info>");
        $this->newLine();

        $this->info('   GA4:');
        foreach ($payloads['ga4'] as $key => $value) {
            $display = is_array($value) ? json_encode($value) : (string) $value;
            $this->line("   <info>{$key}</info>: {$display}");
        }

        $this->newLine();

        if ($payloads['meta'] !== null) {
            $this->info('   Meta Pixel:');
            foreach ($payloads['meta'] as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                $this->line("   <info>{$key}</info>: {$display}");
            }
        } else {
            $this->comment('   Meta Pixel: (not supported for this event)');
        }

        $this->newLine();

        $this->info('   PostHog:');
        foreach ($payloads['posthog'] as $key => $value) {
            $display = is_array($value) ? json_encode($value) : (string) $value;
            $this->line("   <info>{$key}</info>: {$display}");
        }

        return self::SUCCESS;
    }

    /**
     * Validate the blueprint registry.
     */
    private function actionValidate(bool $asJson): int
    {
        $result = $this->registry->validateRegistry();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($result['valid']) {
            $this->info('✅ Blueprint registry is valid.');
        } else {
            $this->error('❌ Blueprint registry has errors:');
            foreach ($result['errors'] as $error) {
                $this->line("   ✗ {$error}");
            }
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("   ⚠ {$warning}");
            }
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show builder configuration.
     */
    private function actionConfig(bool $asJson): int
    {
        $config = $this->builder->getConfig();
        $config['enabled'] = $this->builder->isEnabled();

        if ($asJson) {
            $this->line(json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('⚙️ Blueprint Builder Configuration');
        $this->line('   Enabled: <info>' . ($config['enabled'] ? 'YES' : 'NO') . '</info>');
        $this->line('   Auto-coerce: <info>' . ($config['auto_coerce'] ? 'YES' : 'NO') . '</info>');
        $this->line('   PII fields: <info>' . count($config['pii_fields']) . '</info> (' . implode(', ', array_slice($config['pii_fields'], 0, 5)) . ')');
        $this->line('   Registry total: <info>' . $config['registry_total'] . '</info>');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Unknown action: '{$action}'. Use: list, schema, build, payloads, validate, config");

        return self::FAILURE;
    }

    /**
     * Parse the --params option from JSON string.
     *
     * @return array<string, mixed>
     */
    private function parseParamsOption(): array
    {
        $raw = $this->option('params');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Parse the --computed option from JSON string.
     *
     * @return array<string, string>
     */
    private function parseComputedOption(): array
    {
        $raw = $this->option('computed');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
