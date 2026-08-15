<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventTransformationEngine;

/**
 * Event transformation test and preview command.
 *
 * Allows developers to preview how an event payload will be transformed
 * for each provider before dispatch. Useful for debugging field mappings,
 * verifying rename rules, and validating payload compliance.
 *
 * Subcommands:
 *   - preview: Show transformed payload for a specific event + provider
 *   - preview-all: Show transformed payload across all providers
 *   - validate: Validate all registered transformation mappings
 *   - list: List all registered mappings
 *   - export: Export all mappings as JSON
 *
 * @since 70.0.0
 */
final class AnalyticsTransformCommand extends Command
{
    protected $signature = 'zb:analytics:transform
        {action : Action to perform (preview|preview-all|validate|list|export)}
        {--event= : Event name to preview}
        {--provider= : Target provider (ga4, meta, posthog, etc.)}
        {--json : Output as JSON}
        {--params= : JSON string of event params to use for preview}';

    protected $description = 'Test and preview event payload transformations per provider';

    private const ALL_PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
        'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin',
    ];

    private ?EventTransformationEngine $engine = null;

    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'preview' => $this->preview(),
            'preview-all' => $this->previewAll(),
            'validate' => $this->validate(),
            'list' => $this->listMappings(),
            'export' => $this->export(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Preview transformation for a specific event + provider.
     */
    private function preview(): int
    {
        $eventName = $this->option('event');
        $provider = $this->option('provider');

        if ($eventName === null || $provider === null) {
            $this->error('Both --event and --provider are required for preview.');

            return self::FAILURE;
        }

        $event = $this->buildEvent($eventName);
        $engine = $this->getEngine();
        $result = $engine->transform($event, $provider);

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($result->dropped) {
            $this->warn("Event '{$eventName}' would be DROPPED for provider '{$provider}'.");

            foreach ($result->applied as $step) {
                $this->line("  → {$step['rule']}: {$step['action']}");
            }

            return self::SUCCESS;
        }

        $this->info("Transformed payload for '{$eventName}' → '{$provider}':");
        $this->newLine();
        $this->line("  Event Name: {$result->eventName}");

        if ($result->params !== []) {
            $this->line('  Params:');
            foreach ($result->params as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                $this->line("    {$key}: {$display}");
            }
        } else {
            $this->line('  Params: (empty)');
        }

        if ($result->applied !== []) {
            $this->newLine();
            $this->line('  Applied transformations:');
            foreach ($result->applied as $step) {
                $this->line("    [{$step['rule']}] {$step['field']}: {$step['action']}");
            }
        }

        if ($result->warnings !== []) {
            $this->newLine();
            $this->line('  Warnings:');
            foreach ($result->warnings as $warning) {
                $this->warn("    ⚠ {$warning}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Preview transformation across all providers.
     */
    private function previewAll(): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->error('--event is required for preview-all.');

            return self::FAILURE;
        }

        $event = $this->buildEvent($eventName);
        $engine = $this->getEngine();

        if ($this->option('json')) {
            $results = $engine->transformForAll($event, self::ALL_PROVIDERS);
            $output = [];
            foreach ($results as $provider => $result) {
                $output[$provider] = $result->toArray();
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Previewing '{$eventName}' across all providers:");
        $this->newLine();

        $results = $engine->transformForAll($event, self::ALL_PROVIDERS);

        foreach ($results as $provider => $result) {
            $status = $result->dropped ? '<fg=red>DROPPED</>' : '<fg=green>OK</>';
            $name = $result->dropped ? $result->eventName : ($result->eventName !== $eventName ? "{$eventName} → {$result->eventName}" : $eventName);
            $paramCount = count($result->params);

            $this->line("  {$provider}: {$status} {$name} ({$paramCount} params)");

            if ($result->applied !== []) {
                foreach ($result->applied as $step) {
                    $this->line("    [{$step['rule']}] {$step['field']}: {$step['action']}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Validate all registered transformation mappings.
     */
    private function validate(): int
    {
        $engine = $this->getEngine();
        $result = $engine->validateMappings();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Transformation Engine: {$engine->mappingCount()} mappings registered");

        if ($result['valid']) {
            $this->info('<fg=green>All mappings are valid.</>');
        } else {
            $this->error('<fg=red>Mapping validation failed.</>');
            $this->newLine();

            foreach ($result['errors'] as $error) {
                $this->error("  ✗ {$error}");
            }
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->warn("  ⚠ {$warning}");
            }
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * List all registered transformation mappings.
     */
    private function listMappings(): int
    {
        $engine = $this->getEngine();
        $mappings = $engine->allMappings();

        if ($mappings === []) {
            $this->info('No transformation mappings registered.');

            return self::SUCCESS;
        }

        $this->info("Registered transformation mappings ({$engine->mappingCount()}):");
        $this->newLine();

        $this->table(
            ['Event', 'Provider', 'Rules', 'Whitelist', 'Name Override'],
            array_map(
                fn ($mapping) => [
                    $mapping->eventName,
                    $mapping->provider,
                    (string) count($mapping->rules),
                    $mapping->allowOnly !== [] ? implode(', ', $mapping->allowOnly) : '—',
                    $mapping->eventNameOverride ?? '—',
                ],
                array_values($mappings),
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Export all mappings as JSON.
     */
    private function export(): int
    {
        $engine = $this->getEngine();
        $exported = $engine->exportMappings();

        $this->line(json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action '{$action}'. Valid actions: preview, preview-all, validate, list, export");

        return self::FAILURE;
    }

    /**
     * Build an AnalyticsEvent from options.
     */
    private function buildEvent(string $eventName): AnalyticsEvent
    {
        $params = [];

        $rawParams = $this->option('params');
        if (is_string($rawParams) && $rawParams !== '') {
            $decoded = json_decode($rawParams, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        return new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: 'test',
            timestamp: new \DateTimeImmutable(),
        );
    }

    /**
     * Get or resolve the transformation engine.
     */
    private function getEngine(): EventTransformationEngine
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        return app(EventTransformationEngine::class);
    }
}
