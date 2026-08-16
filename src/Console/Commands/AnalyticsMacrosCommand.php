<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Macros\AnalyticsMacroRegistry;

/**
 * Lists, validates, and inspects registered analytics event macros.
 *
 * Provides a single command for operators to manage event macros:
 * - List all registered macros with their configuration
 * - Validate macro integrity (required keys, naming, etc.)
 * - Show details of a specific macro
 * - Display macros grouped by tag
 * - Execute a macro with test parameters
 *
 * @since 118.0.0
 */
final class AnalyticsMacrosCommand extends Command
{
    protected $signature = 'zb:analytics:macros
        {--list : List all registered macros}
        {--validate : Validate macro integrity}
        {--tags : Group macros by tag}
        {--name= : Show details for a specific macro}
        {--json : Output as JSON}
        {--execute= : Execute a macro by name (requires --params)}
        {--params={} : JSON parameters for macro execution}';

    protected $description = 'Manage and inspect analytics event macros';

    /**
     * Execute the macros command.
     */
    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $showList = (bool) $this->option('list');
        $validate = (bool) $this->option('validate');
        $showTags = (bool) $this->option('tags');
        $name = (string) $this->option('name');
        $executeName = (string) $this->option('execute');

        // Default to listing if no option specified
        if (! $showList && ! $validate && ! $showTags && $name === '' && $executeName === '') {
            $showList = true;
        }

        if ($executeName !== '') {
            return $this->executeMacro($executeName);
        }

        if ($name !== '') {
            return $this->showMacroDetails($name, $outputJson);
        }

        if ($validate) {
            return $this->validateMacros($outputJson);
        }

        if ($showTags) {
            return $this->showByTag($outputJson);
        }

        return $this->listMacros($outputJson);
    }

    /**
     * List all registered macros.
     *
     * @param  bool  $outputJson
     */
    private function listMacros(bool $outputJson): int
    {
        $macros = AnalyticsMacroRegistry::all();

        if ($macros === []) {
            if ($outputJson) {
                $this->line(json_encode(['macros' => [], 'count' => 0], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->info('No analytics macros registered.');
            $this->newLine();
            $this->line('Register macros in your AppServiceProvider or config:');
            $this->line('  config/zeroboiler.php → analytics.macros.definitions');

            return self::SUCCESS;
        }

        if ($outputJson) {
            $data = [];
            foreach ($macros as $macro) {
                $data[] = $macro->toArray();
            }

            $this->line(json_encode(['macros' => $data, 'count' => count($data)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📋 Analytics Macros (" . count($macros) . ' registered)');
        $this->newLine();

        $headers = ['Name', 'Event', 'Required Keys', 'Tags', 'Description'];
        $rows = [];

        foreach ($macros as $macro) {
            $rows[] = [
                $macro->name(),
                $macro->eventName(),
                implode(', ', $macro->requiredKeys()) ?: '—',
                implode(', ', $macro->tags()) ?: '—',
                $macro->description() ?? '—',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show details for a specific macro.
     *
     * @param  string  $name
     * @param  bool  $outputJson
     */
    private function showMacroDetails(string $name, bool $outputJson): int
    {
        $macro = AnalyticsMacroRegistry::get($name);

        if ($macro === null) {
            $this->error("Macro '{$name}' not found.");
            $this->line('Available: ' . implode(', ', AnalyticsMacroRegistry::names()));

            return self::FAILURE;
        }

        if ($outputJson) {
            $this->line(json_encode($macro->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🏷️  Macro: {$macro->name()}");
        $this->newLine();

        $this->line("  Event:     <comment>{$macro->eventName()}</comment>");
        $this->line('  Required:  ' . (implode(', ', $macro->requiredKeys()) ?: '<fg=gray>none</>'));
        $this->line('  Tags:      ' . (implode(', ', $macro->tags()) ?: '<fg=gray>none</>'));
        $this->line('  Desc:      ' . ($macro->description() ?? '<fg=gray>none</>'));
        $this->newLine();

        if ($macro->defaults() !== []) {
            $this->line('  <info>Defaults:</info>');
            foreach ($macro->defaults() as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                $this->line("    {$key}: <comment>{$display}</comment>");
            }
            $this->newLine();
        }

        // Show example usage
        $this->line('  <info>Usage:</info>');
        $this->line("    AnalyticsMacroRegistry::execute(\$manager, '{$name}', [");
        foreach ($macro->requiredKeys() as $key) {
            $this->line("      '{$key}' => '...',");
        }
        $this->line('    ]);');

        return self::SUCCESS;
    }

    /**
     * Validate all registered macros for integrity.
     *
     * @param  bool  $outputJson
     */
    private function validateMacros(bool $outputJson): int
    {
        $result = AnalyticsMacroRegistry::validate();

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $count = AnalyticsMacroRegistry::count();
        $this->info("🔍 Macro Validation ({$count} macros)");
        $this->newLine();

        if ($result['errors'] === []) {
            $this->info('  ✅ No validation errors found.');
        } else {
            foreach ($result['errors'] as $error) {
                $this->error("  ❌ {$error}");
            }
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('  ⚠️  Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("     {$warning}");
            }
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show macros grouped by tag.
     *
     * @param  bool  $outputJson
     */
    private function showByTag(bool $outputJson): int
    {
        $byTag = AnalyticsMacroRegistry::byTag();

        if ($byTag === []) {
            $this->info('No tagged macros found.');

            return self::SUCCESS;
        }

        if ($outputJson) {
            $this->line(json_encode(['by_tag' => $byTag], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🏷️  Macros by Tag');
        $this->newLine();

        foreach ($byTag as $tag => $macroNames) {
            $this->line("  <comment>{$tag}</comment> (" . count($macroNames) . '):');
            foreach ($macroNames as $macroName) {
                $this->line("    • {$macroName}");
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Execute a macro with provided parameters.
     *
     * @param  string  $macroName
     */
    private function executeMacro(string $macroName): int
    {
        $macro = AnalyticsMacroRegistry::get($macroName);

        if ($macro === null) {
            $this->error("Macro '{$macroName}' not found.");

            return self::FAILURE;
        }

        $paramsJson = (string) $this->option('params');

        try {
            /** @var array<string, mixed> $params */
            $params = $paramsJson !== '{}' ? json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException $e) {
            $this->error("Invalid JSON in --params: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("🚀 Executing macro: {$macroName}");
        $this->line("   Event: {$macro->eventName()}");
        $this->line('   Params: ' . json_encode($params, JSON_PRETTY_PRINT));

        try {
            $result = $macro->build($params);
            $this->newLine();
            $this->info('   ✅ Macro built successfully.');
            $this->line('   Final params: ' . json_encode($result['params'], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error("   ❌ {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
