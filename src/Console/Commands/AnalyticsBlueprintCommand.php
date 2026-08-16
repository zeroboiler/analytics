<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry;

/**
 * Manage and inspect analytics event blueprints.
 *
 * Provides commands for listing, inspecting, validating, and testing
 * event blueprints in the registry.
 *
 * @since 66.0.0
 */
final class AnalyticsBlueprintCommand extends Command
{
    protected $signature = 'zb:analytics:blueprints
        {--list : List all registered blueprints}
        {--inspect= : Inspect a specific blueprint by name}
        {--validate : Validate the entire blueprint registry}
        {--categories : Group output by category}
        {--json : Output as JSON}
        {--build= : Test building an event from a blueprint (blueprint_name)}
        {--params= : JSON params for --build (e.g. \'{"user_id":"usr_1"}\')}';

    protected $description = 'Manage and inspect analytics event blueprints';

    private EventBlueprintRegistry $registry;

    public function __construct(EventBlueprintRegistry $registry): void
    {
        parent::__construct();
        $this->registry = $registry;
    }

    #[Override]
    public function handle(): int
    {
        // Validate registry
        if ($this->option('validate')) {
            return $this->validateRegistry();
        }

        // Build test
        $buildName = $this->option('build');

        if ($buildName !== null) {
            return $this->testBuild((string) $buildName);
        }

        // Inspect single blueprint
        $inspectName = $this->option('inspect');

        if ($inspectName !== null) {
            return $this->inspectBlueprint((string) $inspectName);
        }

        // Default: list all blueprints
        return $this->listBlueprints();
    }

    /**
     * List all registered blueprints.
     */
    private function listBlueprints(): int
    {
        $byCategory = $this->registry->byCategory();
        $outputJson = (bool) $this->option('json');

        $rows = [];

        foreach ($byCategory as $category => $blueprints) {
            foreach ($blueprints as $blueprint) {
                $rows[] = [
                    $blueprint->name,
                    $blueprint->label,
                    $category,
                    $blueprint->baseEvent,
                    $blueprint->version,
                    $blueprint->isDeprecated() ? '⚠️ DEPRECATED' : '✅',
                    $blueprint->owner() ?? '—',
                ];
            }
        }

        if ($outputJson) {
            $data = [];

            foreach ($byCategory as $category => $blueprints) {
                foreach ($blueprints as $blueprint) {
                    $data[] = $blueprint->toArray();
                }
            }

            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $diagnostics = $this->registry->diagnostics();

        $this->info("📋 Event Blueprints ({$diagnostics['total']} total)");
        $this->line("   Built-in: {$diagnostics['built_in']} | Config: {$diagnostics['config']} | Runtime: {$diagnostics['runtime']} | Deprecated: {$diagnostics['deprecated']}");
        $this->newLine();

        $this->table(
            ['Name', 'Label', 'Category', 'Base Event', 'Version', 'Status', 'Owner'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Inspect a specific blueprint.
     */
    private function inspectBlueprint(string $name): int
    {
        $blueprint = $this->registry->find($name);

        if ($blueprint === null) {
            $this->error("Blueprint '{$name}' not found.");

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($blueprint->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🎯 Blueprint: {$blueprint->name}");
        $this->line("   Label: <info>{$blueprint->label}</info>");
        $this->line("   Description: {$blueprint->description}");
        $this->line("   Base Event: <info>{$blueprint->baseEvent}</info>");
        $this->line("   Category: <info>{$blueprint->category}</info>");
        $this->line("   Version: <info>{$blueprint->version}</info>");
        $this->line("   Priority: " . ($blueprint->priority ?? 'inherit'));
        $this->line("   Owner: " . ($blueprint->owner() ?? '—'));

        if ($blueprint->isDeprecated()) {
            $this->line("   ⚠️  Deprecated: " . ($blueprint->deprecationNotice() ?? 'yes'));
        }

        if ($blueprint->requiredParams !== []) {
            $this->newLine();
            $this->line('   Required Params:');
            foreach ($blueprint->requiredParams as $param) {
                $type = $blueprint->paramTypes[$param] ?? 'mixed';
                $this->line("     • <comment>{$param}</comment> ({$type})");
            }
        }

        if ($blueprint->defaultParams !== []) {
            $this->newLine();
            $this->line('   Default Params:');
            foreach ($blueprint->defaultParams as $key => $value) {
                $display = is_scalar($value) ? (string) $value : json_encode($value);
                $this->line("     • <comment>{$key}</comment> = {$display}");
            }
        }

        if ($blueprint->paramTypes !== []) {
            $this->newLine();
            $this->line('   Param Types:');
            foreach ($blueprint->paramTypes as $key => $type) {
                $this->line("     • <comment>{$key}</comment>: {$type}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Validate the blueprint registry.
     */
    private function validateRegistry(): int
    {
        $result = $this->registry->validateRegistry();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['valid']) {
            $this->info('✅ Blueprint registry is valid.');
        } else {
            $this->error('❌ Blueprint registry validation failed:');
            foreach ($result['errors'] as $error) {
                $this->line("   • {$error}");
            }
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("   ⚠️  {$warning}");
            }
        }

        $diagnostics = $this->registry->diagnostics();
        $this->newLine();
        $this->line("   Total: {$diagnostics['total']} | Deprecated: {$diagnostics['deprecated']}");

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Test building an event from a blueprint.
     */
    private function testBuild(string $blueprintName): int
    {
        $paramsJson = $this->option('params');

        $params = [];

        if ($paramsJson !== null) {
            $decoded = json_decode((string) $paramsJson, true);

            if (! is_array($decoded)) {
                $this->error('Invalid JSON for --params.');

                return self::FAILURE;
            }

            $params = $decoded;
        }

        $result = $this->registry->buildUnsafe($blueprintName, $params);

        if ($result['errors'] !== []) {
            $this->error('Build validation errors:');
            foreach ($result['errors'] as $error) {
                $this->line("   • {$error}");
            }
        }

        if ($result['warnings'] !== []) {
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("   ⚠️  {$warning}");
            }
        }

        $event = $result['event'];

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'event' => $event->toArray(),
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📦 Built Event from Blueprint '{$blueprintName}'");
        $this->line("   Name: <info>{$event->name}</info>");
        $this->line("   Params: " . json_encode($event->params, JSON_PRETTY_PRINT));
        $this->line("   Priority: " . ($event->priority ?? 'inherit'));
        $this->line("   Client ID: " . ($event->clientId ?? 'null'));
        $this->line("   User ID: " . ($event->userId ?? 'null'));

        return self::SUCCESS;
    }
}
