<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventSignalIntelligenceService;

/**
 * Analytics Signal Intelligence command.
 *
 * Displays the event signal intelligence report including provider
 * health signals, anomaly detection, dispatch balance, and
 * signal-to-noise ratio scoring.
 *
 * @since 7.7.0
 */
final class AnalyticsSignalIntelligenceCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:signal
        {--json : Output as JSON}
        {--anomalies-only : Show only detected anomalies}
        {--providers-only : Show only provider signals}
    ';

    /** @var string */
    protected $description = 'Display event signal intelligence report — provider health, anomaly detection, and signal quality';

    /**
     * Execute the command.
     */
    #[Override]
    public function handle(EventSignalIntelligenceService $service): int
    {
        $report = $service->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('anomalies-only')) {
            return $this->displayAnomalies($report['anomalies']);
        }

        if ($this->option('providers-only')) {
            return $this->displayProviders($report['providers']);
        }

        return $this->displayFullReport($report);
    }

    /**
     * Display the full signal intelligence report.
     *
     * @param  array{signal_score: float, grade: string, providers: array<string, mixed>, categories: array<string, mixed>, anomalies: list<array{type: string, provider: string|null, message: string, severity: string, detected_at: string}>, staleness_summary: array{stale: list<string>, healthy: list<string>}, signal_to_noise: float, dispatch_balance: float, recommendations: list<string>, computed_at: string}  $report
     */
    private function displayFullReport(array $report): int
    {
        $this->components->info('📊 Event Signal Intelligence Report');
        $this->newLine();

        // Signal Score
        $scoreColor = match (true) {
            $report['signal_score'] >= 80 => 'green',
            $report['signal_score'] >= 50 => 'yellow',
            default => 'red',
        };
        $this->components->twoColumnDetail(
            'Signal Score',
            "<fg={$scoreColor}>{$report['signal_score']}</>/100 — {$report['grade']}",
        );

        // Signal-to-Noise
        $this->components->twoColumnDetail(
            'Signal-to-Noise',
            $this->formatPercentage($report['signal_to_noise']),
        );

        // Dispatch Balance
        $balanceColor = match (true) {
            $report['dispatch_balance'] >= 70 => 'green',
            $report['dispatch_balance'] >= 40 => 'yellow',
            default => 'red',
        };
        $this->components->twoColumnDetail(
            'Dispatch Balance',
            "<fg={$balanceColor}>{$report['dispatch_balance']}%</>",
        );

        // Computed At
        $this->components->twoColumnDetail(
            'Computed At',
            $report['computed_at'],
        );

        $this->newLine();

        // Provider Signals
        $this->displayProviders($report['providers']);
        $this->newLine();

        // Staleness Summary
        $this->components->info('🕐 Staleness Summary');
        if (empty($report['staleness_summary']['stale'])) {
            $this->components->twoColumnDetail('Status', '<fg=green>All providers healthy</>');
        } else {
            foreach ($report['staleness_summary']['stale'] as $stale) {
                $this->components->twoColumnDetail("⚠️  Stale", $stale);
            }
        }

        $this->newLine();

        // Anomalies
        $this->displayAnomalies($report['anomalies']);
        $this->newLine();

        // Recommendations
        if (! empty($report['recommendations'])) {
            $this->components->info('💡 Recommendations');
            foreach ($report['recommendations'] as $rec) {
                $this->line("  • {$rec}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display provider signals table.
     *
     * @param  array<string, array{name: string, status: string, events_dispatched: int, events_failed: int, failure_rate: float, last_dispatch_at: string|null, staleness_seconds: int|null, anomaly_score: float, health_decay: float}>  $providers
     */
    private function displayProviders(array $providers): int
    {
        $this->components->info('📡 Provider Signals');

        $rows = [];
        foreach ($providers as $signal) {
            $statusIcon = match ($signal['status']) {
                'healthy' => '🟢',
                'degraded' => '🟡',
                'stale' => '🟠',
                'down' => '🔴',
                default => '⚪',
            };

            $rows[] = [
                $signal['name'],
                "{$statusIcon} {$signal['status']}",
                (string) $signal['events_dispatched'],
                $signal['failure_rate'] > 0 ? "{$signal['failure_rate']}%" : '—',
                $signal['last_dispatch_at'] ?? 'never',
                (string) $signal['anomaly_score'],
            ];
        }

        $this->table(
            ['Provider', 'Status', 'Events', 'Fail Rate', 'Last Dispatch', 'Anomaly'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Display anomalies.
     *
     * @param  list<array{type: string, provider: string|null, message: string, severity: string, detected_at: string}>  $anomalies
     */
    private function displayAnomalies(array $anomalies): int
    {
        $this->components->info('⚠️  Detected Anomalies');

        if (empty($anomalies)) {
            $this->components->twoColumnDetail('Status', '<fg=green>No anomalies detected</>');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($anomalies as $anomaly) {
            $severityIcon = match ($anomaly['severity']) {
                'critical' => '🔴',
                'warning' => '🟡',
                default => '⚪',
            };

            $rows[] = [
                $anomaly['type'],
                $anomaly['provider'] ?? '—',
                "{$severityIcon} {$anomaly['severity']}",
                $anomaly['message'],
                $anomaly['detected_at'],
            ];
        }

        $this->table(
            ['Type', 'Provider', 'Severity', 'Message', 'Detected'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Format a ratio as a percentage string.
     */
    private function formatPercentage(float $ratio): string
    {
        $percentage = round($ratio * 100, 1);
        $color = match (true) {
            $percentage >= 70 => 'green',
            $percentage >= 40 => 'yellow',
            default => 'red',
        };

        return "<fg={$color}>{$percentage}%</>";
    }
}
