<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventIngestionService;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\AnalyticsCommandScheduler;

/**
 * Analytics ingestion and cost overview command.
 *
 * Displays real-time ingestion metrics, cost allocation breakdowns,
 * scheduled task status, and budget utilization.
 *
 * Usage:
 *   php artisan zb:analytics:ingestion
 *   php artisan zb:analytics:ingestion --costs
 *   php artisan zb:analytics:ingestion --scheduler
 *   php artisan zb:analytics:ingestion --json
 *
 * @since 36.0.0
 */
final class AnalyticsIngestionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:ingestion
        {--costs : Show cost allocation breakdown}
        {--scheduler : Show scheduled task status}
        {--execute-due : Execute all due scheduled tasks}
        {--reset : Reset all ingestion and cost stats}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analytics ingestion metrics, cost allocation, and scheduler status';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        EventIngestionService $ingestion,
        EventCostTracker $costTracker,
        AnalyticsCommandScheduler $scheduler,
    ): int
    {
        $showCosts = $this->option('costs');
        $showScheduler = $this->option('scheduler');
        $executeDue = $this->option('execute-due');
        $reset = $this->option('reset');
        $asJson = $this->option('json');

        if ($reset) {
            $ingestion->resetStats();
            $costTracker->reset();
            $this->components->info('Ingestion and cost stats reset.');

            return self::SUCCESS;
        }

        $output = [];

        // ── Ingestion Metrics ─────────────────────────────────────
        $ingestionMetrics = $ingestion->getMetrics();
        $aggregatedStats = $ingestion->getAggregatedStats();

        $output['ingestion'] = [
            'enabled' => $ingestion->isEnabled(),
            'request' => $ingestionMetrics,
            'aggregated' => $aggregatedStats,
        ];

        // ── Cost Allocation ───────────────────────────────────────
        if ($showCosts || (! $showScheduler && ! $executeDue)) {
            $dailyBreakdown = $costTracker->getDailyCostBreakdown();
            $monthlyBreakdown = $costTracker->getMonthlyCostBreakdown();
            $remaining = $costTracker->getRemainingBudget();
            $budgetExceeded = $costTracker->isBudgetExceeded();

            $output['cost_allocation'] = [
                'enabled' => $costTracker->isEnabled(),
                'daily' => $dailyBreakdown,
                'monthly' => $monthlyBreakdown,
                'budget_remaining' => $remaining,
                'budget_exceeded' => $budgetExceeded,
                'request_metrics' => $costTracker->getRequestMetrics(),
            ];
        }

        // ── Scheduler Status ────────────────────────────────────────
        if ($showScheduler || (! $showCosts && ! $executeDue)) {
            $summary = $scheduler->getSummary();
            $dueTasks = $scheduler->getDueTasks();
            $executionLog = $scheduler->getExecutionLog();

            $output['scheduler'] = [
                'enabled' => $scheduler->isEnabled(),
                'summary' => $summary,
                'due_tasks' => $dueTasks,
                'execution_log' => $executionLog,
            ];
        }

        // ── Execute Due Tasks ──────────────────────────────────────
        if ($executeDue) {
            $result = $scheduler->executeDueTasks();
            $output['execution'] = $result;

            if (! $asJson) {
                $this->components->info("Executed: " . implode(', ', $result['executed']) ?: 'none');
                if ($result['failed'] !== []) {
                    $this->components->error("Failed: " . implode(', ', $result['failed']));
                }
            }
        }

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // ── Human-Readable Output ──────────────────────────────────
        $this->components->title('ZeroBoiler Analytics — Ingestion & Cost Overview');

        // Ingestion table
        $this->newLine();
        $this->components->section('Ingestion Pipeline');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Status', $ingestion->isEnabled() ? '✅ Enabled' : '❌ Disabled'],
                ['Request Events', (string) $ingestionMetrics['ingested']],
                ['Request Rejected', (string) $ingestionMetrics['rejected']],
                ['Request Avg Latency', $ingestionMetrics['avg_latency_ms'] . 'ms'],
                ['Aggregated Ingested', (string) $aggregatedStats['total_ingested']],
                ['Aggregated Rejected', (string) $aggregatedStats['total_rejected']],
                ['Aggregated Avg Latency', $aggregatedStats['avg_latency_ms'] . 'ms'],
            ],
        );

        // Sources breakdown
        if ($ingestionMetrics['sources'] !== []) {
            $this->newLine();
            $this->components->section('Event Sources');
            $sourceRows = [];
            foreach ($ingestionMetrics['sources'] as $source => $count) {
                $sourceRows[] = [$source, (string) $count];
            }
            $this->table(['Source', 'Count'], $sourceRows);
        }

        // Cost table
        if (isset($output['cost_allocation'])) {
            $this->newLine();
            $this->components->section('Cost Allocation');

            $costAlloc = $output['cost_allocation'];
            $costRows = [
                ['Status', $costAlloc['enabled'] ? '✅ Enabled' : '❌ Disabled'],
                ['Daily Total', (string) $costAlloc['daily']['total']],
                ['Monthly Total', (string) $costAlloc['monthly']['total']],
                ['Budget Remaining', $costAlloc['budget_remaining'] > 0 ? (string) $costAlloc['budget_remaining'] : '∞'],
                ['Budget Exceeded', $costAlloc['budget_exceeded'] ? '⚠️  YES' : 'No'],
                ['Request Avg Cost/Event', (string) $costAlloc['request_metrics']['avg_cost_per_event']],
            ];
            $this->table(['Metric', 'Value'], $costRows);

            if ($costAlloc['daily']['providers'] !== []) {
                $this->newLine();
                $providerRows = [];
                foreach ($costAlloc['daily']['providers'] as $provider => $cost) {
                    $providerRows[] = [$provider, (string) $cost];
                }
                $this->table(['Provider', 'Daily Cost'], $providerRows);
            }
        }

        // Scheduler table
        if (isset($output['scheduler'])) {
            $this->newLine();
            $this->components->section('Command Scheduler');

            $schedSummary = $output['scheduler']['summary'];
            $schedRows = [
                ['Status', $schedSummary['enabled'] ? '✅ Enabled' : '❌ Disabled'],
                ['Total Tasks', (string) $schedSummary['total_tasks']],
                ['Enabled Tasks', (string) $schedSummary['enabled_tasks']],
                ['Due Now', (string) $schedSummary['due_tasks']],
                ['Last Executed', $schedSummary['last_executed'] ?? 'Never'],
            ];
            $this->table(['Metric', 'Value'], $schedRows);

            if ($output['scheduler']['due_tasks'] !== []) {
                $this->newLine();
                $this->components->info('Due tasks: ' . implode(', ', $output['scheduler']['due_tasks']));
            }

            // Task list
            $tasks = $scheduler->getTasks();
            if ($tasks !== []) {
                $this->newLine();
                $taskRows = [];
                foreach ($tasks as $name => $task) {
                    $log = $output['scheduler']['execution_log'][$name] ?? null;
                    $taskRows[] = [
                        $name,
                        $task['frequency'],
                        $task['enabled'] ? '✅' : '❌',
                        $log['last_run'] ?? 'Never',
                        $log['last_status'] ?? '—',
                        $log['run_count'] ?? 0,
                    ];
                }
                $this->table(
                    ['Task', 'Frequency', 'Enabled', 'Last Run', 'Status', 'Runs'],
                    $taskRows,
                );
            }
        }

        return self::SUCCESS;
    }
}
