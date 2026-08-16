<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventDispatchOrchestrator;

/**
 * Analytics dispatch orchestrator command — inspect and manage the
 * event dispatch orchestration engine.
 *
 * Provides operational visibility into dispatch decisions, provider outcomes,
 * and orchestration health. Supports JSON output for CI/CD integration.
 *
 * Actions:
 * - health: Show composite orchestration health summary
 * - decisions: Show recent dispatch decisions
 * - outcomes: Show per-provider outcome statistics
 * - stats: Show aggregated decision statistics
 * - clear: Clear all orchestrator data
 *
 * @see \ZeroBoiler\Analytics\Services\EventDispatchOrchestrator
 * @see \ZeroBoiler\Analytics\Services\EventDispatchLatencyTracker
 * @see \ZeroBoiler\Analytics\Services\EventReplayAuditLedger
 *
 * @since 207.0.0
 */
final class AnalyticsOrchestratorCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:orchestrator
                            {action : Command action (health|decisions|outcomes|stats|clear)}
                            {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Inspect and manage the event dispatch orchestration engine';

    /**
     * Execute the console command.
     */
    public function handle(EventDispatchOrchestrator $orchestrator): int
    {
        $action = $this->argument('action');
        $asJson = $this->option('json');

        return match ($action) {
            'health' => $this->showHealth($orchestrator, $asJson),
            'decisions' => $this->showDecisions($orchestrator, $asJson),
            'outcomes' => $this->showOutcomes($orchestrator, $asJson),
            'stats' => $this->showStats($orchestrator, $asJson),
            'clear' => $this->clearData($orchestrator),
            default => $this->unknownAction($action),
        };
    }

    /**
     * Show orchestration health summary.
     *
     * @param  EventDispatchOrchestrator  $orchestrator
     * @param  bool  $asJson
     * @return int
     */
    private function showHealth(EventDispatchOrchestrator $orchestrator, bool $asJson): int
    {
        $health = $orchestrator->healthSummary();

        if ($asJson) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Event Dispatch Orchestration Health');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Enabled', $health['enabled'] ? '✓ Yes' : '✗ No'],
                ['Total Decisions', number_format($health['total_decisions'])],
                ['Dispatch Rate', $health['dispatch_rate'] . '%'],
                ['Defer Rate', $health['defer_rate'] . '%'],
                ['Drop Rate', $health['drop_rate'] . '%'],
            ],
        );

        if (! empty($health['provider_summary'])) {
            $rows = [];
            foreach ($health['provider_summary'] as $provider => $summary) {
                $successRate = $summary['success_rate'] ?? 'N/A';
                $rateDisplay = is_numeric($successRate) ? $successRate . '%' : $successRate;
                $status = is_numeric($successRate) && $successRate >= 95 ? '✓' : (is_numeric($successRate) && $successRate >= 80 ? '⚠' : '✗');

                $rows[] = [
                    $provider,
                    number_format($summary['total']),
                    $rateDisplay,
                    $status,
                ];
            }

            $this->table(
                ['Provider', 'Events', 'Success Rate', 'Status'],
                $rows,
            );
        }

        return self::SUCCESS;
    }

    /**
     * Show recent dispatch decisions.
     *
     * @param  EventDispatchOrchestrator  $orchestrator
     * @param  bool  $asJson
     * @return int
     */
    private function showDecisions(EventDispatchOrchestrator $orchestrator, bool $asJson): int
    {
        $stats = $orchestrator->stats();

        if ($asJson) {
            $this->line(json_encode($stats['recent_decisions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $decisions = $stats['recent_decisions'];

        if (empty($decisions)) {
            $this->components->info('No dispatch decisions recorded yet.');

            return self::SUCCESS;
        }

        $this->components->info('Recent Dispatch Decisions');
        $this->newLine();

        $rows = [];
        foreach (array_slice($decisions, -15) as $d) {
            $rows[] = [
                $d['event'] ?? '-',
                $d['provider'] ?? '-',
                $d['action'] ?? '-',
                $d['priority'] ?? '-',
                $d['reasoning'] ?? '-',
            ];
        }

        $this->table(
            ['Event', 'Provider', 'Action', 'Priority', 'Reasoning'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Show per-provider outcome statistics.
     *
     * @param  EventDispatchOrchestrator  $orchestrator
     * @param  bool  $asJson
     * @return int
     */
    private function showOutcomes(EventDispatchOrchestrator $orchestrator, bool $asJson): int
    {
        $outcomes = $orchestrator->outcomeStats();

        if ($asJson) {
            $this->line(json_encode($outcomes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Per-Provider Outcome Statistics');
        $this->newLine();

        $rows = [];
        foreach ($outcomes as $provider => $os) {
            if ($os['total'] === 0) {
                continue;
            }

            $avgLatency = $os['avg_latency_ms'] !== null
                ? $os['avg_latency_ms'] . 'ms'
                : '-';

            $rows[] = [
                $provider,
                number_format($os['total']),
                number_format($os['success']),
                number_format($os['failed']),
                $avgLatency,
                count($os['recent_errors']) > 0 ? implode(', ', array_slice($os['recent_errors'], 0, 2)) : '-',
            ];
        }

        if (empty($rows)) {
            $this->components->info('No outcome data recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Provider', 'Total', 'Success', 'Failed', 'Avg Latency', 'Recent Errors'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Show aggregated decision statistics.
     *
     * @param  EventDispatchOrchestrator  $orchestrator
     * @param  bool  $asJson
     * @return int
     */
    private function showStats(EventDispatchOrchestrator $orchestrator, bool $asJson): int
    {
        $stats = $orchestrator->stats();

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Dispatch Decision Statistics');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Decisions', number_format($stats['total_decisions'])],
            ],
        );

        if (! empty($stats['by_action'])) {
            $actionRows = [];
            foreach ($stats['by_action'] as $action => $count) {
                $actionRows[] = [$action, number_format($count)];
            }

            $this->table(['Action', 'Count'], $actionRows);
        }

        if (! empty($stats['by_provider'])) {
            $providerRows = [];
            foreach ($stats['by_provider'] as $provider => $count) {
                $providerRows[] = [$provider, number_format($count)];
            }

            $this->table(['Provider', 'Decisions'], $providerRows);
        }

        return self::SUCCESS;
    }

    /**
     * Clear all orchestrator data.
     *
     * @param  EventDispatchOrchestrator  $orchestrator
     * @return int
     */
    private function clearData(EventDispatchOrchestrator $orchestrator): int
    {
        $orchestrator->clear();
        $this->components->info('Orchestrator data cleared successfully.');

        return self::SUCCESS;
    }

    /**
     * Handle unknown action.
     *
     * @param  string  $action
     * @return int
     */
    private function unknownAction(string $action): int
    {
        $this->components->error("Unknown action: {$action}");
        $this->line('Available actions: health, decisions, outcomes, stats, clear');

        return self::FAILURE;
    }
}
