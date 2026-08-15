<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService;

/**
 * Analytics Health Monitor command.
 *
 * Displays the composite health score for the analytics stack with
 * per-dimension breakdowns. Optionally outputs JSON for dashboard
 * consumption or CI pipeline integration.
 *
 * @since 8.8.0
 */
final class AnalyticsHealthMonitorCommand extends Command
{
    protected $signature = 'zb:analytics:health-monitor
        {--json : Output as JSON}
        {--record : Record a data point for time-series tracking}
        {--history : Show health score history}
        {--points=24 : Number of history data points}';

    protected $description = 'Analytics health monitor dashboard — composite score, per-dimension breakdowns, alerts';

    private AnalyticsHealthMonitorService $monitor;

    public function __construct(AnalyticsHealthMonitorService $monitor): void
    {
        parent::__construct();
        $this->monitor = $monitor;
    }

    /**
     * Execute the health monitor command.
     */
    #[Override]
    public function handle(): int
    {
        // Record data point if requested
        if ($this->option('record')) {
            $this->monitor->recordDataPoint();
            $this->info('✅ Health data point recorded.');
        }

        // Show history
        if ($this->option('history')) {
            return $this->showHistory();
        }

        $dashboard = $this->monitor->getDashboardData();

        if ($this->option('json')) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->displayDashboard($dashboard);
    }

    /**
     * Display the health dashboard in the console.
     *
     * @param  array{composite_score: int, grade: string, status: string, dimensions: array<string, array{name: string, score: int, weight: float, status: string}>, alerts: array<int, array{severity: string, dimension: string, message: string}>}  $dashboard
     */
    private function displayDashboard(array $dashboard): int
    {
        $score = $dashboard['composite_score'];
        $grade = $dashboard['grade'];
        $status = $dashboard['status'];

        // Header
        $this->info('🏥 ZeroBoiler Analytics — Health Monitor');
        $this->newLine();

        // Score
        $color = match (true) {
            $score >= 90 => 'green',
            $score >= 80 => 'green',
            $score >= 70 => 'yellow',
            $score >= 60 => 'yellow',
            default => 'red',
        };

        $this->line("  <fg={$color}>Composite Score: {$score}/100 (Grade: {$grade})</>");
        $this->line("  <fg={$color}>Status: " . strtoupper($status) . '</>');
        $this->newLine();

        // Dimensions table
        $rows = [];
        foreach ($dashboard['dimensions'] as $key => $dim) {
            $dimColor = match ($dim['status']) {
                'healthy' => 'green',
                'warning', 'degraded' => 'yellow',
                default => 'red',
            };
            $statusIcon = match ($dim['status']) {
                'healthy' => '✅',
                'warning' => '⚠️',
                'degraded' => '⚠️',
                default => '❌',
            };

            $rows[] = [
                $dim['name'],
                $dim['score'],
                round($dim['weight'] * 100) . '%',
                "<fg={$dimColor}>{$statusIcon} {$dim['status']}</>",
            ];
        }

        $this->table(
            ['Dimension', 'Score', 'Weight', 'Status'],
            $rows,
        );

        // Alerts
        $alerts = $dashboard['alerts'];
        if (count($alerts) > 0) {
            $this->newLine();
            $this->warn('Alerts (' . count($alerts) . '):');

            foreach ($alerts as $alert) {
                $icon = $alert['severity'] === 'critical' ? '🔴' : '🟡';
                $this->line("  {$icon} [{$alert['dimension']}] {$alert['message']}");
            }
        }

        $this->newLine();
        $this->info("Computed at: {$dashboard['metadata']['computed_at']}");

        return $score >= 60 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show health score history.
     */
    private function showHistory(): int
    {
        $points = (int) $this->option('points');
        $history = $this->monitor->getHistory($points);

        if ($this->option('json')) {
            $this->line(json_encode($history, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (count($history) === 0) {
            $this->warn('No health history recorded yet.');
            $this->line('Use --record to start tracking health scores over time.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($history as $point) {
            $rows[] = [
                $point['timestamp'],
                $point['score'],
                $point['grade'],
            ];
        }

        $this->table(
            ['Timestamp', 'Score', 'Grade'],
            $rows,
        );

        return self::SUCCESS;
    }
}
