<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsInsightEngineService;
use ZeroBoiler\Analytics\Services\EventDataMartService;

/**
 * Analytics insights command — generate automated analytics insights.
 *
 * Produces a comprehensive insight report combining data mart statistics,
 * catalog coverage analysis, provider mapping gaps, and health signals.
 * Useful for daily cron jobs and monitoring.
 *
 * @since 7.0.0
 */
final class AnalyticsInsightsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:insights
                            {--format=table : Output format (table, json, summary)}
                            {--severity= : Filter by severity (critical, warning, info)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate automated analytics insight report from data mart and catalog';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        AnalyticsInsightEngineService $insightEngine,
        EventDataMartService $dataMart,
    ): int
    {
        $this->info('🔍 ZeroBoiler Analytics — Insight Report');
        $this->newLine();

        // Data Mart Status
        $this->sectionTitle('Data Mart Status');

        $summary = $dataMart->summary();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Enabled', $summary['enabled'] ? '✅ Yes' : '❌ No'],
                ['Default Granularity', $summary['granularity']],
                ['Auto Dimensions', implode(', ', $summary['dimensions'])],
                ['Total Events', (string) $summary['total_events']],
                ['Total Unique', (string) $summary['total_unique']],
                ['Cached Cubes', (string) $summary['cached_cubes']],
                ['Cache TTL', $summary['cache_ttl'] . 's'],
            ],
        );

        // Category Distribution
        $this->sectionTitle('Category Distribution');

        $byCategory = $dataMart->byCategory();
        if (! empty($byCategory)) {
            $rows = [];
            foreach ($byCategory as $category => $count) {
                $rows[] = [$category, (string) $count];
            }

            $this->table(['Category', 'Events'], $rows);
        } else {
            $this->warn('No data mart data available yet. Enable auto-tracking or use API ingestion.');
        }

        // Top Events
        $this->sectionTitle('Top Events');

        $topEvents = $dataMart->top('event_name', 10);

        if (! empty($topEvents)) {
            $rows = [];
            foreach ($topEvents as $i => $event) {
                $rows[] = [
                    (string) ($i + 1),
                    $event['key'],
                    (string) $event['count'],
                    (string) $event['unique_count'],
                ];
            }

            $this->table(['#', 'Event', 'Count', 'Unique'], $rows);
        } else {
            $this->warn('No top events data available.');
        }

        // Insight Report
        $this->sectionTitle('Insight Report');

        if (! $insightEngine->isEnabled()) {
            $this->warn('Insight engine is disabled. Enable via config: analytics.insight_engine.enabled');

            return self::SUCCESS;
        }

        $report = $insightEngine->generateReport();
        $format = $this->option('format');
        $severity = $this->option('severity');

        // Filter by severity if requested
        $insights = $report['insights'];
        if ($severity !== null) {
            $insights = array_values(array_filter(
                $insights,
                fn (array $insight): bool => $insight['severity'] === $severity,
            ));
        }

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($format === 'summary') {
            $health = $insightEngine->quickHealth();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Status', $health['status'] === 'healthy' ? '✅ Healthy' : ($health['status'] === 'warning' ? '⚠️ Warning' : '🚨 Critical')],
                    ['Health Score', $health['score'] . '/100'],
                    ['Issues', (string) $health['issues']],
                ],
            );

            $this->newLine();
            $this->line($report['summary']);

            return self::SUCCESS;
        }

        // Table format (default)
        $this->table(
            ['Severity', 'Type', 'Title', 'Metric'],
            array_map(
                fn (array $insight): array => [
                    $this->severityEmoji($insight['severity']),
                    $insight['type'],
                    wordwrap($insight['title'], 50),
                    $insight['metric'] . ': ' . ($insight['value'] ?? 'n/a'),
                ],
                $insights,
            ),
        );

        $this->newLine();

        // Summary counts
        $this->line(sprintf(
            'Total: %d insights (%d critical, %d warnings, %d info)',
            $report['total'],
            $report['critical'],
            $report['warnings'],
            $report['info'],
        ));

        $this->line($report['summary']);

        return self::SUCCESS;
    }

    /**
     * Print a section title.
     */
    private function sectionTitle(string $title): void
    {
        $this->newLine();
        $this->line("── {$title} " . str_repeat('─', max(1, 60 - strlen($title))));
    }

    /**
     * Get severity emoji.
     */
    private function severityEmoji(string $severity): string
    {
        return match ($severity) {
            'critical' => '🚨',
            'warning' => '⚠️',
            default => 'ℹ️',
        };
    }
}
