<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDependencyGraphService;
use ZeroBoiler\Analytics\Services\MultiCurrencyRevenueNormalizer;

/**
 * Admin CLI for event dependency graph and multi-currency management.
 *
 * Provides diagnostics, validation, and management commands for the
 * dependency graph service and multi-currency revenue normalizer.
 *
 * Modes:
 *   (default)  — Summary of both services
 *   --graph    — Dependency graph statistics and health
 *   --validate — Validate a specific event against the dependency graph
 *   --path     — Validate an event sequence path
 *   --topo     — Topological sort of all graph nodes
 *   --cycles   — Detect cycles in the dependency graph
 *   --currency — Multi-currency normalizer statistics and rates
 *   --convert  — Convert a value between currencies
 *   --json     — Output as JSON
 *
 * @since 40.0.0
 */
final class AnalyticsDependencyGraphCommand extends Command
{
    protected $signature = 'zb:analytics:dependencies
        {--graph : Show dependency graph statistics}
        {--validate= : Validate a specific event name against the graph}
        {--path= : Validate event sequence path (comma-separated, e.g. sign_up,start_trial,subscribe)}
        {--topo : Show topological sort of graph nodes}
        {--cycles : Detect cycles in the graph}
        {--currency : Show multi-currency normalizer status}
        {--convert= : Convert value (format: amount:from:to, e.g. 100:EUR:USD)}
        {--json : Output as JSON}';

    protected $description = 'Manage event dependency graph and multi-currency normalization';

    private bool $jsonOutput = false;

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        EventDependencyGraphService $graphService,
        MultiCurrencyRevenueNormalizer $currencyService,
    ): int
    {
        $this->jsonOutput = $this->option('json');

        // Default: show summary
        if (! $this->option('graph') &&
            ! $this->option('validate') &&
            ! $this->option('path') &&
            ! $this->option('topo') &&
            ! $this->option('cycles') &&
            ! $this->option('currency') &&
            ! $this->option('convert')) {
            return $this->showSummary($graphService, $currencyService);
        }

        if ($this->option('graph')) {
            return $this->showGraph($graphService);
        }

        if ($this->option('validate')) {
            return $this->validateEvent($graphService);
        }

        if ($this->option('path')) {
            return $this->validatePath($graphService);
        }

        if ($this->option('topo')) {
            return $this->showTopologicalSort($graphService);
        }

        if ($this->option('cycles')) {
            return $this->showCycles($graphService);
        }

        if ($this->option('currency')) {
            return $this->showCurrency($currencyService);
        }

        if ($this->option('convert')) {
            return $this->convertValue($currencyService);
        }

        return self::SUCCESS;
    }

    /**
     * Show summary of both services.
     */
    private function showSummary(
        EventDependencyGraphService $graphService,
        MultiCurrencyRevenueNormalizer $currencyService,
    ): int {
        $graphSummary = $graphService->summary();
        $currencySummary = $currencyService->summary();

        $output = [
            'dependency_graph' => $graphSummary,
            'multi_currency' => $currencySummary,
        ];

        if ($this->jsonOutput) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Dependency Graph
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│  Event Dependency Graph                      │');
        $this->info('├─────────────────────────────────────────────┤');

        $stats = $graphSummary['statistics'];
        $this->line(sprintf('  Enabled:           %s', $graphSummary['enabled'] ? '<info>Yes</info>' : '<comment>No</comment>'));
        $this->line(sprintf('  Nodes:             %d', $stats['nodes']));
        $this->line(sprintf('  Edges:             %d', $stats['edges']));
        $this->line(sprintf('    Required:        %d', $stats['required_edges']));
        $this->line(sprintf('    Expected:        %d', $stats['expected_edges']));
        $this->line(sprintf('    Exclusive:       %d', $stats['exclusive_edges']));
        $this->line(sprintf('  Cycles:            %d', $stats['cycles']));
        $this->line(sprintf('  Has custom nodes:  %s', $stats['has_custom'] ? 'Yes' : 'No'));

        if (count($graphSummary['critical_paths']) > 0) {
            $this->newLine();
            $this->info('  Critical Paths:');
            foreach (array_slice($graphSummary['critical_paths'], 0, 5) as $i => $path) {
                $this->line(sprintf('    %d. %s', $i + 1, $path));
            }
        }

        // Multi-Currency
        $this->newLine();
        $this->info('┌─────────────────────────────────────────────┐');
        $this->info('│  Multi-Currency Revenue Normalizer           │');
        $this->info('├─────────────────────────────────────────────┤');

        $curStats = $currencySummary['statistics'];
        $this->line(sprintf('  Enabled:            %s', $curStats['enabled'] ? '<info>Yes</info>' : '<comment>No</comment>'));
        $this->line(sprintf('  Base Currency:      %s', $curStats['base_currency']));
        $this->line(sprintf('  Available Currencies: %d', $curStats['available_currencies']));
        $this->line(sprintf('  Rates Source:       %s', $curStats['rates_source']));
        $this->line(sprintf('  Stale Rates:        %d', $curStats['stale_count']));

        return self::SUCCESS;
    }

    /**
     * Show dependency graph details.
     */
    private function showGraph(EventDependencyGraphService $graphService): int
    {
        $graph = $graphService->getGraph();
        $stats = $graphService->statistics();

        $output = [
            'statistics' => $stats,
            'graph' => $graph,
        ];

        if ($this->jsonOutput) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Event Dependency Graph');
        $this->line(sprintf('  Nodes: %d  |  Edges: %d  |  Required: %d  |  Expected: %d  |  Exclusive: %d',
            $stats['nodes'], $stats['edges'], $stats['required_edges'],
            $stats['expected_edges'], $stats['exclusive_edges'],
        ));

        $this->newLine();
        $this->info('Nodes:');

        foreach ($graph as $name => $data) {
            $pre = count($data['prerequisites']) > 0
                ? ' ← [' . implode(', ', $data['prerequisites']) . ']'
                : '';
            $suc = count($data['successors']) > 0
                ? ' → [' . implode(', ', $data['successors']) . ']'
                : ' → (terminal)';

            $this->line(sprintf('  • %s%s%s', $name, $pre, $suc));
        }

        return self::SUCCESS;
    }

    /**
     * Validate a single event against the graph.
     */
    private function validateEvent(EventDependencyGraphService $graphService): int
    {
        $eventName = (string) $this->option('validate');
        $event = new AnalyticsEvent(name: $eventName, params: []);
        $result = $graphService->validate($event);

        if ($this->jsonOutput) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['valid']) {
            $this->info(sprintf('✓ Event "%s" passed dependency validation', $eventName));

            if (count($result['missing_expected']) > 0) {
                $this->comment(sprintf('  Note: expected (soft) prerequisites missing: %s',
                    implode(', ', $result['missing_expected'])));
            }

            return self::SUCCESS;
        }

        $this->error(sprintf('✗ Event "%s" failed dependency validation', $eventName));

        if (count($result['missing_prerequisites']) > 0) {
            $this->line(sprintf('  Missing required prerequisites: %s',
                implode(', ', $result['missing_prerequisites'])));
        }

        if (count($result['exclusive_violations']) > 0) {
            $this->line(sprintf('  Exclusive violations (should not co-occur): %s',
                implode(', ', $result['exclusive_violations'])));
        }

        return self::FAILURE;
    }

    /**
     * Validate an event sequence path.
     */
    private function validatePath(EventDependencyGraphService $graphService): int
    {
        $pathStr = (string) $this->option('path');
        $events = explode(',', $pathStr);
        $events = array_map('trim', $events);
        $events = array_filter($events, fn (string $e): bool => $e !== '');

        $result = $graphService->validatePath(array_values($events));

        if ($this->jsonOutput) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf('Path: %s', implode(' → ', $events)));

        if ($result['valid']) {
            $this->info('✓ Path is valid — all edges exist in the dependency graph');

            $prob = $graphService->funnelCompletionProbability(array_values($events));
            $this->comment(sprintf('  Funnel completion probability: %.1f%%', $prob['probability'] * 100));

            return self::SUCCESS;
        }

        $this->error('✗ Path has violations:');

        foreach ($result['violations'] as $violation) {
            $this->line(sprintf('  • %s → %s: %s', $violation['from'], $violation['to'], $violation['reason']));
        }

        return self::FAILURE;
    }

    /**
     * Show topological sort.
     */
    private function showTopologicalSort(EventDependencyGraphService $graphService): int
    {
        $sorted = $graphService->topologicalSort();

        if ($this->jsonOutput) {
            $this->line(json_encode(['topological_order' => $sorted], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Topological Sort (execution order):');
        $this->line('');

        foreach ($sorted as $i => $event) {
            $this->line(sprintf('  %3d. %s', $i + 1, $event));
        }

        return self::SUCCESS;
    }

    /**
     * Detect and display cycles.
     */
    private function showCycles(EventDependencyGraphService $graphService): int
    {
        $cycles = $graphService->detectCycles();

        if ($this->jsonOutput) {
            $this->line(json_encode(['cycles' => $cycles, 'count' => count($cycles)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return count($cycles) === 0 ? self::SUCCESS : self::FAILURE;
        }

        if (count($cycles) === 0) {
            $this->info('✓ No cycles detected in the dependency graph');

            return self::SUCCESS;
        }

        $this->error(sprintf('✗ %d cycle(s) detected:', count($cycles)));

        foreach ($cycles as $i => $cycle) {
            $this->line(sprintf('  %d. %s', $i + 1, implode(' → ', $cycle)));
        }

        return self::FAILURE;
    }

    /**
     * Show multi-currency normalizer status.
     */
    private function showCurrency(MultiCurrencyRevenueNormalizer $currencyService): int
    {
        $stats = $currencyService->statistics();
        $rates = $currencyService->getAllRates();

        $output = [
            'statistics' => $stats,
            'rates' => $rates,
        ];

        if ($this->jsonOutput) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Multi-Currency Revenue Normalizer');
        $this->line(sprintf('  Enabled:              %s', $stats['enabled'] ? '<info>Yes</info>' : '<comment>No</comment>'));
        $this->line(sprintf('  Base Currency:        %s', $stats['base_currency']));
        $this->line(sprintf('  Available Currencies: %d', $stats['available_currencies']));
        $this->line(sprintf('  Rates Source:         %s', $stats['rates_source']));

        if (count($stats['stale_rates']) > 0) {
            $this->line(sprintf('  Stale Rates:          %s', implode(', ', $stats['stale_rates'])));
        }

        $this->newLine();
        $this->info('Exchange Rates (→ %s):', $stats['base_currency']);

        foreach ($rates as $currency => $rate) {
            $stale = in_array($currency, $stats['stale_rates'], true) ? ' ⚠️ stale' : '';
            $this->line(sprintf('  %-6s = %.4f%s', $currency, $rate, $stale));
        }

        return self::SUCCESS;
    }

    /**
     * Convert a value between currencies.
     */
    private function convertValue(MultiCurrencyRevenueNormalizer $currencyService): int
    {
        $convertSpec = (string) $this->option('convert');
        $parts = explode(':', $convertSpec);

        if (count($parts) !== 3) {
            $this->error('Invalid convert format. Use: amount:from:to (e.g., 100:EUR:USD)');

            return self::FAILURE;
        }

        $amount = (float) $parts[0];
        $from = strtoupper(trim($parts[1]));
        $to = strtoupper(trim($parts[2]));

        $result = $currencyService->convertValue($amount, $from, $to);

        if ($result === null) {
            $this->error(sprintf('Cannot convert %s → %s: rate not available', $from, $to));

            return self::FAILURE;
        }

        $output = [
            'amount' => $amount,
            'from_currency' => $from,
            'to_currency' => $to,
            'converted' => $result,
            'rate' => $currencyService->getRate($from),
        ];

        if ($this->jsonOutput) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rate = $currencyService->getRate($from);
        $toRate = $currencyService->getRate($to);
        $effectiveRate = ($rate !== null && $toRate !== null && $toRate !== 0.0)
            ? $toRate / $rate
            : null;

        $this->info(sprintf('%.2f %s = %.2f %s', $amount, $from, $result, $to));

        if ($effectiveRate !== null) {
            $this->comment(sprintf('  Rate: 1 %s = %.6f %s', $from, $effectiveRate, $to));
        }

        return self::SUCCESS;
    }
}
