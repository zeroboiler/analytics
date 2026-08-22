<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SLOService;

/**
 * Service Level Objective (SLO) dashboard and management command.
 *
 * Displays SLO compliance status, error budget consumption, burn rates,
 * budget projections, and compliance history for all configured objectives.
 *
 * Actions:
 *   (none)    Show SLO dashboard overview
 *   status    Show detailed status for a specific objective
 *   project   Project remaining error budget based on current burn rate
 *   history   Show compliance history for an objective
 *   reset     Reset counters for an objective (testing/diagnostics)
 *   check     Check burn rate thresholds (exit code indicates severity)
 *
 * @since 157.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\SLOService
 * @see \ZeroBoiler\Analytics\Services\ProviderSLAMonitor
 */
final class AnalyticsSLOCommand extends Command
{
    protected $signature = 'zb:analytics:slo
        {action : Action to perform (status|project|history|reset|check)}
        {--objective= : Specific SLO objective name}
        {--provider= : Specific provider name for provider-level SLOs}
        {--json : Output as JSON}
        {--windows=24 : Number of history windows to show}';

    protected $description = 'Service Level Objective dashboard, error budget tracking, and burn rate analysis';

    private SLOService $sloService;

    public function __construct(SLOService $sloService): void
    {
        parent::__construct();
        $this->sloService = $sloService;
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $json = (bool) $this->option('json');

        if (! $this->sloService->isEnabled()) {
            $this->warn('SLO tracking is disabled in configuration.');

            return self::SUCCESS;
        }

        return match ($action) {
            'status' => $this->showStatus($json),
            'project' => $this->showProjection($json),
            'history' => $this->showHistory($json),
            'reset' => $this->resetObjective(),
            'check' => $this->checkBurnRates(),
            default => $this->showDashboard($json),
        };
    }

    /**
     * Show comprehensive SLO dashboard.
     */
    private function showDashboard(bool $json): int
    {
        $dashboard = $this->sloService->dashboard();

        if ($json) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — SLO Dashboard    ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        $summary = $dashboard['summary'];

        $this->line("  Window: {$dashboard['window_seconds']}s");
        $this->line(sprintf(
            '  Objectives: %d total — %d healthy, %d warning, %d critical',
            $summary['total'],
            $summary['healthy'],
            $summary['warning'],
            $summary['critical'],
        ));
        $this->newLine();

        $this->table(
            ['Objective', 'Target', 'Errors/Total', 'Budget', 'Remaining', 'Burn Rate', 'Status'],
            $this->formatDashboardRows($dashboard['objectives']),
        );

        return self::SUCCESS;
    }

    /**
     * Show detailed status for a specific objective.
     */
    private function showStatus(bool $json): int
    {
        $objective = $this->option('objective');
        $provider = $this->option('provider');

        if ($objective === null) {
            $this->error('--objective is required for status action.');

            return self::FAILURE;
        }

        $budget = $this->sloService->getErrorBudget((string) $objective, $provider !== null ? (string) $provider : null);
        $compliance = $this->sloService->getCompliance((string) $objective, $provider !== null ? (string) $provider : null);

        $result = [
            'objective' => $objective,
            'provider' => $provider,
            'compliance' => $compliance,
        ] + $budget;

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf('SLO Objective: %s%s', $objective, $provider !== null ? " ({$provider})" : ''));
        $this->table(
            ['Metric', 'Value'],
            [
                ['Target', $budget['target'] . '%'],
                ['Total Events', (string) $budget['total']],
                ['Errors', (string) $budget['errors']],
                ['Error Budget', (string) $budget['error_budget']],
                ['Remaining', (string) $budget['remaining'] . ' (' . $budget['remaining_pct'] . '%)'],
                ['Burn Rate', (string) $budget['burn_rate'] . 'x'],
                ['Compliance', $compliance . '%'],
                ['Status', $budget['status']],
            ],
        );

        return $budget['status'] === 'critical' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Show budget projection.
     */
    private function showProjection(bool $json): int
    {
        $objective = $this->option('objective');

        if ($objective === null) {
            $this->error('--objective is required for project action.');

            return self::FAILURE;
        }

        $provider = $this->option('provider');
        $projection = $this->sloService->project((string) $objective, $provider !== null ? (string) $provider : null);

        if ($json) {
            $this->line(json_encode($projection, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf('Budget Projection: %s', (string) $objective));
        $this->table(
            ['Metric', 'Value'],
            [
                ['Remaining Budget', (string) $projection['remaining_budget']],
                ['Windows Left', (string) $projection['estimated_windows_left']],
                ['Time Left', $projection['estimated_time_left']],
                ['Burn Rate', $projection['burn_rate'] . 'x'],
                ['Status', $projection['status']],
            ],
        );

        return $projection['status'] === 'critical' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Show compliance history.
     */
    private function showHistory(bool $json): int
    {
        $objective = $this->option('objective');

        if ($objective === null) {
            $this->error('--objective is required for history action.');

            return self::FAILURE;
        }

        $provider = $this->option('provider');
        $windows = (int) $this->option('windows');
        $history = $this->sloService->getComplianceHistory(
            (string) $objective,
            $provider !== null ? (string) $provider : null,
            $windows,
        );

        $rolling = $this->sloService->rollingCompliance(
            (string) $objective,
            $provider !== null ? (string) $provider : null,
        );

        if ($json) {
            $this->line(json_encode([
                'objective' => $objective,
                'provider' => $provider,
                'rolling_avg' => $rolling,
                'history' => $history,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf('Compliance History: %s (rolling: %.4f%%)', (string) $objective, $rolling));
        $this->newLine();

        $rows = [];
        foreach ($history as $window => $compliance) {
            $status = $compliance >= 99.9 ? '✅' : ($compliance >= 99.0 ? '⚠️' : '❌');
            $rows[] = [$window, $status, $compliance . '%'];
        }

        if (empty($rows)) {
            $this->line('  No compliance history recorded yet.');
        } else {
            $this->table(['Window', 'Status', 'Compliance'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Reset counters for an objective.
     */
    private function resetObjective(): int
    {
        $objective = $this->option('objective');

        if ($objective === null) {
            $this->error('--objective is required for reset action.');

            return self::FAILURE;
        }

        $provider = $this->option('provider');
        $this->sloService->reset((string) $objective, $provider !== null ? (string) $provider : null);
        $this->info("Reset SLO counters for: {$objective}" . ($provider !== null ? " ({$provider})" : ''));

        return self::SUCCESS;
    }

    /**
     * Check burn rate thresholds across all objectives.
     *
     * Exit code: 0 = healthy, 1 = warnings, 2 = critical thresholds exceeded.
     */
    private function checkBurnRates(): int
    {
        $objectives = $this->sloService->getAllObjectiveNames();
        $hasCritical = false;
        $hasWarning = false;

        $rows = [];
        foreach ($objectives as $name) {
            $check = $this->sloService->checkBurnRateThreshold($name);

            $status = $check['exceeds_critical'] ? '❌ CRITICAL' : ($check['exceeds_alert'] ? '⚠️ WARNING' : '✅ OK');

            if ($check['exceeds_critical']) {
                $hasCritical = true;
            } elseif ($check['exceeds_alert']) {
                $hasWarning = true;
            }

            $rows[] = [$name, $check['burn_rate'], $check['threshold_alert'], $check['threshold_critical'], $status];
        }

        $this->table(
            ['Objective', 'Burn Rate', 'Alert Thr', 'Critical Thr', 'Status'],
            $rows,
        );

        if ($hasCritical) {
            return self::FAILURE;
        }

        if ($hasWarning) {
            return 1;
        }

        return self::SUCCESS;
    }

    /**
     * Format dashboard rows for table display.
     *
     * @param  array<string, array{target: float, total: int, errors: int, error_budget: int, remaining: int, remaining_pct: float, burn_rate: float, status: string}>  $objectives
     * @return list<array<string, string>>
     */
    private function formatDashboardRows(array $objectives): array
    {
        $rows = [];

        foreach ($objectives as $name => $budget) {
            $statusIcon = match ($budget['status']) {
                'healthy' => '✅',
                'warning' => '⚠️',
                'critical' => '❌',
                default => '❓',
            };

            $rows[] = [
                $name,
                $budget['target'] . '%',
                sprintf('%d/%d', $budget['errors'], $budget['total']),
                (string) $budget['error_budget'],
                sprintf('%d (%s%%)', $budget['remaining'], $budget['remaining_pct']),
                $budget['burn_rate'] . 'x',
                $statusIcon . ' ' . $budget['status'],
            ];
        }

        return $rows;
    }
}
