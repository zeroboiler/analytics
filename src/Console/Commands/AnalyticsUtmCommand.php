<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\UtmParameterManager;

/**
 * Admin command for UTM Parameter Manager diagnostics and operations.
 *
 * Provides subcommands for inspecting UTM configuration, validating URLs,
 * cleaning URLs, decorating URLs with UTM params, and computing completeness scores.
 *
 * @since 55.0.0
 */
final class AnalyticsUtmCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zb:analytics:utm
        {action : Subcommand (config|validate|clean|decorate|extract|score|aliases|internal)}
        {--url= : URL to process (for validate, clean, decorate, extract, score)}
        {--source= : utm_source value (for decorate)}
        {--medium= : utm_medium value (for decorate)}
        {--campaign= : utm_campaign value (for decorate)}
        {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'UTM Parameter Manager — validate, clean, decorate, and extract campaign parameters';

    /**
     * Execute the console command.
     */
    public function handle(UtmParameterManager $utm): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'config' => $this->actionConfig($utm),
            'validate' => $this->actionValidate($utm),
            'clean' => $this->actionClean($utm),
            'decorate' => $this->actionDecorate($utm),
            'extract' => $this->actionExtract($utm),
            'score' => $this->actionScore($utm),
            'aliases' => $this->actionAliases($utm),
            'internal' => $this->actionInternal($utm),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show UTM manager configuration.
     */
    private function actionConfig(UtmParameterManager $utm): int
    {
        $summary = $utm->configSummary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════╗');
        $this->info('║  UTM Parameter Manager — Config      ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->newLine();
        $this->line("  Enabled:               <fg={$this->boolColor($summary['enabled'])}>{$this->boolStr($summary['enabled'])}</>");
        $this->line("  Max Value Length:      {$summary['max_value_length']}");
        $this->line("  Max Key Length:        {$summary['max_key_length']}");
        $this->line("  Lowercase Src/Med:     <fg={$this->boolColor($summary['lowercase_source_medium'])}>{$this->boolStr($summary['lowercase_source_medium'])}</>");
        $this->line("  Trim Values:           <fg={$this->boolColor($summary['trim_values'])}>{$this->boolStr($summary['trim_values'])}</>");
        $this->line("  Strip HTML:           <fg={$this->boolColor($summary['strip_html'])}>{$this->boolStr($summary['strip_html'])}</>");
        $this->line("  Standard Params:       {$summary['standard_params_count']}");
        $this->line("  Internal Params:       {$summary['internal_params_count']}");
        $this->newLine();
        $this->line('  Required for Completeness:');
        foreach ($summary['required_for_completeness'] as $param) {
            $this->line("    • {$param}");
        }

        if (! empty($summary['aliases'])) {
            $this->newLine();
            $this->line('  Aliases:');
            foreach ($summary['aliases'] as $alias => $target) {
                $this->line("    {$alias} → {$target}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Validate UTM parameters in a URL.
     */
    private function actionValidate(UtmParameterManager $utm): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('URL is required. Use --url=...');

            return self::FAILURE;
        }

        $params = $utm->extractFromUrl($url);
        $result = $utm->validate($params);

        if ($this->option('json')) {
            $this->line(json_encode([
                'url' => $url,
                'extracted' => $params,
                'validation' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("URL: {$url}");
        $this->newLine();

        if (empty($params)) {
            $this->warn('No UTM parameters found in URL.');
        } else {
            $this->info('Extracted UTM Parameters:');
            foreach ($params as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }

        $this->newLine();

        if ($result['valid']) {
            $this->info('✓ Validation passed');
        } else {
            $this->error('✗ Validation failed');
            foreach ($result['errors'] as $error) {
                $this->line("  • {$error}");
            }
        }

        if (! empty($result['warnings'])) {
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("  ⚠ {$warning}");
            }
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Clean internal tracking params from a URL.
     */
    private function actionClean(UtmParameterManager $utm): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('URL is required. Use --url=...');

            return self::FAILURE;
        }

        $cleaned = $utm->cleanUrl($url);

        if ($this->option('json')) {
            $this->line(json_encode([
                'original' => $url,
                'cleaned' => $cleaned,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Original:');
        $this->line("  {$url}");
        $this->newLine();
        $this->info('Cleaned:');
        $this->line("  {$cleaned}");

        return self::SUCCESS;
    }

    /**
     * Decorate a URL with UTM parameters.
     */
    private function actionDecorate(UtmParameterManager $utm): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('URL is required. Use --url=...');

            return self::FAILURE;
        }

        $utmParams = array_filter([
            'utm_source' => $this->option('source'),
            'utm_medium' => $this->option('medium'),
            'utm_campaign' => $this->option('campaign'),
        ]);

        if (empty($utmParams)) {
            $this->error('At least one UTM param is required. Use --source=, --medium=, --campaign=');

            return self::FAILURE;
        }

        $decorated = $utm->decorateUrl($url, $utmParams);

        if ($this->option('json')) {
            $this->line(json_encode([
                'original' => $url,
                'utm_params' => $utmParams,
                'decorated' => $decorated,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Decorated URL:');
        $this->line("  {$decorated}");

        return self::SUCCESS;
    }

    /**
     * Extract UTM parameters from a URL.
     */
    private function actionExtract(UtmParameterManager $utm): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('URL is required. Use --url=...');

            return self::FAILURE;
        }

        $raw = $utm->extractFromUrl($url);
        $sanitized = $utm->extractAndSanitizeUrl($url);

        if ($this->option('json')) {
            $this->line(json_encode([
                'url' => $url,
                'raw' => $raw,
                'sanitized' => $sanitized,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("URL: {$url}");
        $this->newLine();

        if (empty($raw)) {
            $this->warn('No UTM parameters found.');
        } else {
            $this->info('Extracted (Raw):');
            foreach ($raw as $key => $value) {
                $this->line("  {$key}: {$value}");
            }

            $this->newLine();
            $this->info('Extracted (Sanitized):');
            foreach ($sanitized as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Calculate UTM completeness score.
     */
    private function actionScore(UtmParameterManager $utm): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('URL is required. Use --url=...');

            return self::FAILURE;
        }

        $params = $utm->extractFromUrl($url);
        $score = $utm->completenessScore($params);

        if ($this->option('json')) {
            $this->line(json_encode([
                'url' => $url,
                'extracted_params' => $params,
                'completeness' => $score,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("URL: {$url}");
        $this->newLine();
        $this->line("  Completeness Score:  <fg={$this->scoreColor($score['score'])}>{$score['score']}%</>");
        $this->line("  Present:             {$score['present']}/{$score['total']}");

        if (! empty($score['missing'])) {
            $this->warn('  Missing:');
            foreach ($score['missing'] as $param) {
                $this->line("    • {$param}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show configured aliases.
     */
    private function actionAliases(UtmParameterManager $utm): int
    {
        $configured = $utm->getAliases();
        $defaults = UtmParameterManager::defaultAliases();

        if ($this->option('json')) {
            $this->line(json_encode([
                'configured' => $configured,
                'defaults_available' => $defaults,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Configured Aliases:');
        if (empty($configured)) {
            $this->warn('  No aliases configured.');
        } else {
            foreach ($configured as $alias => $target) {
                $this->line("  {$alias} → {$target}");
            }
        }

        $this->newLine();
        $this->info('Default Aliases Available:');
        foreach ($defaults as $alias => $target) {
            $active = isset($configured[$alias]);
            $this->line("  " . ($active ? '✓' : '·') . " {$alias} → {$target}");
        }

        return self::SUCCESS;
    }

    /**
     * Show internal tracking params.
     */
    private function actionInternal(UtmParameterManager $utm): int
    {
        $params = $utm->internalParams();

        if ($this->option('json')) {
            $this->line(json_encode([
                'count' => count($params),
                'params' => $params,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Internal Tracking Parameters (stripped on clean):');
        $this->newLine();
        foreach ($params as $param) {
            $this->line("  • {$param}");
        }
        $this->newLine();
        $this->line("  Total: " . count($params));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $valid = ['config', 'validate', 'clean', 'decorate', 'extract', 'score', 'aliases', 'internal'];
        $this->error("Invalid action: '{$action}'");
        $this->line('Valid actions: ' . implode(', ', $valid));

        return self::FAILURE;
    }

    /**
     * Format boolean for display.
     */
    private function boolStr(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Color for boolean display.
     */
    private function boolColor(bool $value): string
    {
        return $value ? 'green' : 'red';
    }

    /**
     * Color for score display.
     */
    private function scoreColor(int $score): string
    {
        if ($score >= 80) {
            return 'green';
        }

        if ($score >= 50) {
            return 'yellow';
        }

        return 'red';
    }
}
