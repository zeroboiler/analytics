<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\FunnelLeakDetectionService;

/**
 * Artisan command for analyzing funnel leaks and conversion drop-offs.
 *
 * Provides actionable insights into conversion funnel performance
 * by detecting significant drop-off points and generating prioritized
 * recommendations for improvement.
 *
 * Usage:
 *   php artisan zb:analytics:funnel-leaks
 *   php artisan zb:analytics:funnel-leaks --funnel=purchase_funnel
 *   php artisan zb:analytics:funnel-leaks --all
 *   php artisan zb:analytics:funnel-leaks --json
 *
 * @since 148.0.0
 */
final class AnalyticsFunnelLeakCommand extends Command
{
    protected $signature = 'zb:analytics:funnel-leaks
        {--funnel= : Analyze a specific funnel by name}
        {--all : Analyze all registered funnels}
        {--json : Output as JSON}
        {--recommendations : Show only recommendations}
        {--list : List all registered funnel definitions}';

    protected $description = 'Analyze conversion funnel leaks and drop-offs';

    private FunnelLeakDetectionService $service;

    public function __construct(FunnelLeakDetectionService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        if (! $this->service->isEnabled()) {
            $this->warn('Funnel leak detection is disabled. Set ANALYTICS_FUNNEL_LEAK_DETECTION_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        // List mode
        if ($this->option('list')) {
            return $this->listFunnels();
        }

        // All funnels mode
        if ($this->option('all')) {
            return $this->analyzeAllFunnels();
        }

        // Specific funnel mode
        $funnelName = $this->option('funnel');

        if ($funnelName !== null) {
            return $this->analyzeSingleFunnel((string) $funnelName);
        }

        // Default: analyze all
        return $this->analyzeAllFunnels();
    }

    /**
     * List all registered funnel definitions.
     */
    private function listFunnels(): int
    {
        $funnels = $this->service->getFunnels();

        if ($funnels === []) {
            $this->info('No funnel definitions registered.');

            return self::SUCCESS;
        }

        $this->info('Registered Funnel Definitions:');
        $this->newLine();

        $rows = [];
        foreach ($funnels as $name => $def) {
            $steps = implode(' → ', $def['steps']);
            $rows[] = [$name, count($def['steps']), $steps];
        }

        $this->table(['Funnel', 'Steps', 'Flow'], $rows);

        return self::SUCCESS;
    }

    /**
     * Analyze all funnels.
     */
    private function analyzeAllFunnels(): int
    {
        $results = $this->service->analyzeAll();
        $outputJson = (bool) $this->option('json');

        if ($outputJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Funnel Leak Detection — All Funnels');
        $this->newLine();

        $rows = [];
        $totalLeaks = 0;

        foreach ($results as $name => $result) {
            $leakLabel = $result['biggest_leak'] !== null
                ? "⚠️  {$result['biggest_leak']}"
                : '✅ No leaks';
            $conversionLabel = $result['overall_conversion'] >= 50
                ? "✅ {$result['overall_conversion']}%"
                : ($result['overall_conversion'] >= 20
                    ? "⚠️  {$result['overall_conversion']}%"
                    : "🔴 {$result['overall_conversion']}%");

            $rows[] = [
                $name,
                $conversionLabel,
                $result['leak_count'],
                $leakLabel,
            ];
            $totalLeaks += $result['leak_count'];
        }

        $this->table(['Funnel', 'Overall Conversion', 'Leaks', 'Biggest Leak'], $rows);
        $this->newLine();

        if ($totalLeaks === 0) {
            $this->info('✅ No funnel leaks detected across all funnels.');
        } else {
            $this->warn("⚠️  {$totalLeaks} leak(s) detected across all funnels. Run with --funnel=<name> for details.");
        }

        return self::SUCCESS;
    }

    /**
     * Analyze a single funnel.
     */
    private function analyzeSingleFunnel(string $funnelName): int
    {
        $analysis = $this->service->analyze($funnelName);
        $outputJson = (bool) $this->option('json');

        if ($outputJson) {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $onlyRecommendations = (bool) $this->option('recommendations');

        if ($onlyRecommendations) {
            return $this->showRecommendations($analysis);
        }

        // Header
        $this->info("Funnel: {$analysis['funnel']}");
        $this->line("Overall Conversion: {$analysis['overall_conversion']}%");
        $this->newLine();

        // Steps table
        if ($analysis['steps'] !== []) {
            $rows = [];
            foreach ($analysis['steps'] as $step) {
                $severityLabel = match ($step['severity']) {
                    'critical' => '🔴 CRITICAL',
                    'warning' => '⚠️  WARNING',
                    null => '✅ OK',
                    default => '✅ OK',
                };

                $conversionLabel = "{$step['conversion_rate']}%";

                $rows[] = [
                    $step['name'],
                    $step['users'],
                    $conversionLabel,
                    $step['is_leak'] ? round($step['drop_off'] * 100, 1) . '%' : '—',
                    $severityLabel,
                ];
            }

            $this->table(['Step', 'Users', 'Conversion', 'Drop-off', 'Status'], $rows);
            $this->newLine();
        }

        // Biggest leak
        if ($analysis['biggest_leak'] !== null) {
            $leak = $analysis['biggest_leak'];
            $dropPercent = round($leak['drop_off'] * 100, 1);
            $this->warn("Biggest Leak: {$leak['name']} ({$dropPercent}% drop-off, {$leak['severity']})");
        } else {
            $this->info('✅ No significant leaks detected in this funnel.');
        }

        // Recommendations
        if ($analysis['recommendations'] !== []) {
            $this->newLine();
            $this->showRecommendations($analysis);
        }

        return self::SUCCESS;
    }

    /**
     * Show only recommendations from an analysis.
     *
     * @param  array{recommendations: list<array{priority: string, step: string, message: string, action: string}>}  $analysis
     */
    private function showRecommendations(array $analysis): int
    {
        $recommendations = $analysis['recommendations'];

        if ($recommendations === []) {
            $this->info('No recommendations — funnel is performing well.');

            return self::SUCCESS;
        }

        $this->info('Recommendations:');
        $this->newLine();

        $rows = [];
        foreach ($recommendations as $rec) {
            $priority = $rec['priority'] === 'critical' ? '🔴 Critical' : '⚠️  Warning';
            $rows[] = [
                $priority,
                $rec['step'],
                $rec['message'],
                $rec['action'],
            ];
        }

        $this->table(['Priority', 'Step', 'Issue', 'Action'], $rows);

        return self::SUCCESS;
    }
}
