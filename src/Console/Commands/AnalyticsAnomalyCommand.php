<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService;
use ZeroBoiler\Analytics\Services\AnalyticsExportFormatterService;

/**
 * Analytics anomaly detection monitoring command.
 *
 * Provides subcommands for checking, configuring, and managing the
 * real-time anomaly detection system. Can be run on a cron schedule
 * for periodic anomaly checks.
 *
 * @since 54.0.0
 */
final class AnalyticsAnomalyCommand extends Command
{
    protected $signature = 'zb:analytics:anomaly
        {action? : Subcommand (check|status|metrics|clear|formats)}
        {--format=table : Output format (table|json)}';

    protected $description = 'Monitor analytics for anomalies and manage detection settings';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(AnalyticsAnomalyDetectionService $detection): int
    {
        $action = $this->argument('action') ?? 'check';

        return match ($action) {
            'check' => $this->checkAnomalies($detection),
            'status' => $this->showStatus($detection),
            'metrics' => $this->showMetrics($detection),
            'clear' => $this->clearData($detection),
            'formats' => $this->showExportFormats(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Run anomaly detection check and display results.
     */
    private function checkAnomalies(AnalyticsAnomalyDetectionService $detection): int
    {
        $this->info('🔍 Running anomaly detection check...');

        $anomalies = $detection->detectAnomalies();

        if ($anomalies === []) {
            $this->info('✅ No anomalies detected. All metrics within normal ranges.');
            return self::SUCCESS;
        }

        $this->warn(count($anomalies) . ' anomaly(ies) detected:');

        $rows = [];
        foreach ($anomalies as $anomaly) {
            $rows[] = [
                $anomaly['type'],
                strtoupper($anomaly['severity']),
                round($anomaly['deviation'], 1) . 'σ',
                $anomaly['message'],
            ];
        }

        $this->table(['Type', 'Severity', 'Deviation', 'Message'], $rows);

        return self::FAILURE;
    }

    /**
     * Display anomaly detection status summary.
     */
    private function showStatus(AnalyticsAnomalyDetectionService $detection): int
    {
        $status = $detection->status();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('📊 Anomaly Detection Status');
        $this->newLine();

        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $status['enabled'] ? '✅ Yes' : '❌ No'],
                ['Current Window Events', $status['current_window']['count']],
                ['Current Event Types', $status['current_window']['event_types']],
                ['Current Providers', $status['current_window']['provider_count']],
                ['Current Unique Clients', $status['current_window']['unique_clients']],
                ['Baseline Avg Events', round($status['baseline']['avg_count'], 1)],
                ['Baseline Std Deviation', round($status['baseline']['std_count'], 1)],
                ['Baseline Providers', $status['baseline']['provider_count']],
                ['Baseline Sample Size', $status['baseline']['sample_size']],
                ['Windows Tracked', $status['windows_tracked']],
                ['Recent Alerts', count($status['recent_alerts'])],
            ],
        );

        if ($status['recent_alerts'] !== []) {
            $this->warn('Recent Alerts:');
            $alertRows = array_map(
                fn (array $a): array => [
                    $a['fired_at'] ?? 'unknown',
                    $a['type'],
                    strtoupper($a['severity'] ?? 'unknown'),
                    substr($a['message'] ?? '', 0, 60),
                ],
                array_slice($status['recent_alerts'], 0, 10),
            );
            $this->table(['Time', 'Type', 'Severity', 'Message'], $alertRows);
        }

        return self::SUCCESS;
    }

    /**
     * Display anomaly detection metrics.
     */
    private function showMetrics(AnalyticsAnomalyDetectionService $detection): int
    {
        $metrics = $detection->metrics();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('📈 Anomaly Detection Metrics');
        $this->newLine();

        $this->table(
            ['Metric', 'Value', 'Interpretation'],
            [
                ['Rate Deviation', $metrics['rate_deviation'] ?? 'N/A', $this->interpretDeviation($metrics['rate_deviation'])],
                ['Provider Balance', ($metrics['provider_balance'] ?? 'N/A') . '%', $this->interpretProvider($metrics['provider_balance'])],
                ['Composition Drift', ($metrics['composition_drift'] ?? 'N/A') . '%', $this->interpretDrift($metrics['composition_drift'])],
                ['Client Spike', $metrics['client_spike'] ?? 'N/A', $this->interpretDeviation($metrics['client_spike'])],
                ['Alerts (24h)', $metrics['anomaly_count_24h'], $metrics['anomaly_count_24h'] > 10 ? '⚠️ High alert frequency' : '✅ Normal'],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Clear all anomaly detection data.
     */
    private function clearData(AnalyticsAnomalyDetectionService $detection): int
    {
        if (! $this->confirm('Clear all anomaly detection data and recent alerts?')) {
            return self::SUCCESS;
        }

        $detection->clear();
        $this->info('✅ Anomaly detection data cleared.');

        return self::SUCCESS;
    }

    /**
     * Show supported export formats.
     */
    private function showExportFormats(): int
    {
        $formats = AnalyticsExportFormatterService::supportedFormats();

        $rows = array_map(
            fn (array $f): array => [$f['format'], $f['label'], $f['description']],
            $formats,
        );

        $this->table(['Format', 'Label', 'Description'], $rows);

        return self::SUCCESS;
    }

    /**
     * Handle invalid subcommand.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: check, status, metrics, clear, formats');

        return self::FAILURE;
    }

    /**
     * Interpret a deviation value.
     */
    private function interpretDeviation(?float $value): string
    {
        if ($value === null) {
            return 'N/A (insufficient data)';
        }

        if ($value < 1.0) {
            return '✅ Normal';
        }
        if ($value < 2.0) {
            return '⚡ Elevated';
        }
        if ($value < 3.0) {
            return '⚠️ High';
        }

        return '🔴 Critical';
    }

    /**
     * Interpret provider balance score.
     */
    private function interpretProvider(?float $value): string
    {
        if ($value === null) {
            return 'N/A (insufficient data)';
        }

        if ($value === 0.0) {
            return '✅ All healthy';
        }
        if ($value < 25.0) {
            return '⚡ Partial degradation';
        }
        if ($value < 50.0) {
            return '⚠️ Significant issues';
        }

        return '🔴 Critical provider failure';
    }

    /**
     * Interpret composition drift score.
     */
    private function interpretDrift(?float $value): string
    {
        if ($value === null) {
            return 'N/A (insufficient data)';
        }

        if ($value === 0.0) {
            return '✅ No drift';
        }
        if ($value < 0.1) {
            return '⚡ Minor drift';
        }
        if ($value < 0.3) {
            return '⚠️ Notable drift';
        }

        return '🔴 Significant composition change';
    }
}
