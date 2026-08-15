<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventReportingService;

/**
 * Generate scheduled analytics reports for dashboard and notification delivery.
 *
 * Designed to run via Laravel Scheduler at configurable intervals (hourly, daily, weekly).
 * Generates reports for each configured period and outputs JSON or table format.
 * Optionally writes reports to disk for archival or integration with notification systems.
 *
 * @see \ZeroBoiler\Analytics\Services\EventReportingService
 *
 * @since 1.0.0
 */
final class AnalyticsScheduledReportCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:report:schedule
        {--period=daily : Report period (hourly, daily, weekly, monthly)}
        {--format=json : Output format (json, table)}
        {--output= : Optional file path to write report to disk}
        {--all : Generate reports for all periods}
    ';

    /** @var string */
    protected $description = 'Generate scheduled analytics reports for dashboard and notification delivery';

    /**
     * Execute the console command.
     */
    #[Override]
    #[Override]
    public function handle(EventReportingService $reporting, ConfigRepository $config): int
    {
        $scheduleConfig = $config->get('zeroboiler.analytics.scheduled_reports', []);
        /** @var array{enabled?: bool, periods?: list<string>, output_path?: string, auto_archive?: bool} $scheduleConfig */

        if (($scheduleConfig['enabled'] ?? false) === false && ! $this->option('all')) {
            $this->warn('Scheduled reports are disabled. Set ANALYTICS_SCHEDULED_REPORTS_ENABLED=true or use --all.');

            return self::SUCCESS;
        }

        $format = (string) $this->option('format');
        $outputPath = (string) $this->option('output') ?: ($scheduleConfig['output_path'] ?? '');
        $periods = $this->option('all')
            ? (['hourly', 'daily', 'weekly', 'monthly'])
            : [(string) $this->option('period')];

        $generated = 0;
        $errors = 0;

        foreach ($periods as $period) {
            $report = $reporting->report($period);

            if (($report['enabled'] ?? false) === false && ! $this->option('all')) {
                $this->line("Period '{$period}': reporting service is disabled, skipping.");
                continue;
            }

            try {
                $this->displayReport($report, $format);

                if ($outputPath !== '') {
                    $this->writeReport($report, $outputPath, $period, $format);
                }

                $generated++;
            } catch (\Throwable $e) {
                $this->error("Failed to generate {$period} report: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Generated {$generated} report(s), {$errors} error(s).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Display a report in the specified format.
     *
     * @param  array<string, mixed>  $report
     */
    private function displayReport(array $report, string $format): void
    {
        $period = $report['period'] ?? 'unknown';

        if ($format === 'table') {
            $this->newLine();
            $this->components->info("📊 Analytics Report — {$period}");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Generated At', $report['generated_at'] ?? 'N/A'],
                    ['Total Events', (string) ($report['total_events'] ?? 0)],
                    ['Dispatched', (string) ($report['total_dispatched'] ?? 0)],
                    ['Failed', (string) ($report['total_failed'] ?? 0)],
                    ['Success Rate', ($report['success_rate'] ?? 0) . '%'],
                    ['Catalog Events', (string) ($report['event_catalog_summary']['total'] ?? 0)],
                ],
            );

            $topEvents = $report['top_events'] ?? [];
            if (count($topEvents) > 0) {
                $this->newLine();
                $this->components->info('🔝 Top Events');
                $this->table(
                    ['Event', 'Count', 'Category'],
                    array_map(
                        fn (array $e): array => [$e['name'], (string) $e['count'], $e['category'] ?? ''],
                        array_slice($topEvents, 0, 10),
                    ),
                );
            }

            $byProvider = $report['by_provider'] ?? [];
            if (count($byProvider) > 0) {
                $this->newLine();
                $this->components->info('📡 By Provider');
                $this->table(
                    ['Provider', 'Events'],
                    array_map(fn (string $k, int $v): array => [$k, (string) $v], array_keys($byProvider), array_values($byProvider)),
                );
            }
        } else {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Write a report to disk.
     *
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report, string $basePath, string $period, string $format): void
    {
        $filename = $format === 'table'
            ? "{$period}_report.txt"
            : "{$period}_report.json";

        $path = rtrim($basePath, '/') . '/' . $filename;
        $content = $format === 'table'
            ? $this->formatReportAsText($report, $period)
            : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        file_put_contents($path, $content . "\n");

        $this->info("Report written to: {$path}");
    }

    /**
     * Format a report as plain text for file output.
     *
     * @param  array<string, mixed>  $report
     */
    private function formatReportAsText(array $report, string $period): string
    {
        $lines = [
            "=== Analytics Report — {$period} ===",
            "Generated: {$report['generated_at']}",
            "Total Events: {$report['total_events']}",
            "Dispatched: {$report['total_dispatched']}",
            "Failed: {$report['total_failed']}",
            "Success Rate: {$report['success_rate']}%",
            '',
        ];

        foreach ($report['top_events'] ?? [] as $event) {
            $lines[] = "  {$event['name']}: {$event['count']} ({$event['category']})";
        }

        return implode("\n", $lines);
    }
}
