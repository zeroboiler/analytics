<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventReplayAuditService;
use ZeroBoiler\Analytics\Services\AnalyticsDataRetentionService;

/**
 * Analytics Replay Audit Command — inspect event replay audit logs.
 *
 * Provides admin CLI for reviewing replay operations, viewing statistics,
 * searching audit entries, and managing data retention.
 *
 * Usage:
 *   php artisan zb:analytics:replay-audit          Show audit summary
 *   php artisan zb:analytics:replay-audit --stats  Show detailed statistics
 *   php artisan zb:analytics:replay-audit --search event_name=purchase
 *   php artisan zb:analytics:replay-audit --recent  Show last 10 entries
 *   php artisan zb:analytics:replay-audit --purge-status  Show retention status
 *   php artisan zb:analytics:replay-audit --purge-expired  Execute expired event purge
 *   php artisan zb:analytics:replay-audit --purge-expired --dry-run  Preview purge
 *   php artisan zb:analytics:replay-audit --purge-logs  Show recent purge logs
 *   php artisan zb:analytics:replay-audit --json  Output as JSON
 *
 * @since 39.0.0
 */
final class AnalyticsReplayAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:replay-audit
        {--stats : Show detailed audit statistics}
        {--search= : Search filter (key=value format, e.g. source=command)}
        {--recent : Show last 10 audit entries}
        {--limit=10 : Number of entries to show}
        {--purge-status : Show data retention status}
        {--purge-expired : Purge expired archived events}
        {--purge-logs : Show recent purge log entries}
        {--dry-run : Preview purge without deleting (use with --purge-expired)}
        {--category= : Restrict purge to a specific category}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Inspect event replay audit logs and manage data retention';

    private ?EventReplayAuditService $auditService = null;

    private ?AnalyticsDataRetentionService $retentionService = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->auditService = $this->getAuditService();
        $this->retentionService = $this->getRetentionService();

        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('search')) {
            return $this->showSearch();
        }

        if ($this->option('recent')) {
            return $this->showRecent();
        }

        if ($this->option('purge-status')) {
            return $this->showPurgeStatus();
        }

        if ($this->option('purge-expired')) {
            return $this->purgeExpired();
        }

        if ($this->option('purge-logs')) {
            return $this->showPurgeLogs();
        }

        return $this->showSummary();
    }

    /**
     * Show audit summary.
     */
    private function showSummary(): int
    {
        $summary = $this->auditService->summary();
        $stats = $this->auditService->statistics();

        if ($this->option('json')) {
            $this->line(json_encode([
                'audit' => $summary,
                'statistics' => $stats,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('ZeroBoiler Analytics — Replay Audit Summary');
        $this->newLine();
        $this->table(
            ['Property', 'Value'],
            [
                ['Enabled', $summary['enabled'] ? 'Yes' : 'No'],
                ['Auto-record', $summary['auto_record'] ? 'Yes' : 'No'],
                ['Total Entries', (string) $summary['total_entries']],
                ['Max Entries', (string) $summary['max_entries']],
                ['Utilization', $summary['utilization'] . '%'],
                ['Retention TTL', $summary['retention_ttl'] . 's (' . round($summary['retention_ttl'] / 86400) . ' days)'],
                ['Cache Prefix', $summary['cache_prefix']],
            ],
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Replays', (string) $stats['total_replays']],
                ['Single Replays', (string) $stats['single_replays']],
                ['Bulk Replays', (string) $stats['bulk_replays']],
                ['Successful', (string) $stats['successful']],
                ['Failed', (string) $stats['failed']],
                ['Success Rate', $stats['success_rate'] !== null ? $stats['success_rate'] . '%' : 'N/A'],
                ['Avg Duration', $stats['avg_duration_ms'] !== null ? $stats['avg_duration_ms'] . 'ms' : 'N/A'],
                ['Recent Failures', (string) $stats['recent_failures']],
            ],
        );

        $this->newLine();
        $this->info('Replay by Source:');

        if (empty($stats['by_source'])) {
            $this->warn('  No replay activity recorded.');
        } else {
            $this->table(
                ['Source', 'Count'],
                array_map(
                    fn (string $source, int $count): array => [$source, (string) $count],
                    array_keys($stats['by_source']),
                    array_values($stats['by_source']),
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Show detailed statistics.
     */
    private function showStats(): int
    {
        $stats = $this->auditService->statistics(period: null);
        $dailyStats = $this->auditService->statistics(period: 'day');
        $weeklyStats = $this->auditService->statistics(period: 'week');

        $output = [
            'all_time' => $stats,
            'last_day' => $dailyStats,
            'last_week' => $weeklyStats,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('ZeroBoiler Analytics — Replay Statistics');
        $this->newLine();

        foreach ($output as $period => $periodStats) {
            $this->comment(ucwords(str_replace('_', ' ', $period)));
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Replays', (string) $periodStats['total_replays']],
                    ['Single', (string) $periodStats['single_replays']],
                    ['Bulk', (string) $periodStats['bulk_replays']],
                    ['Success Rate', $periodStats['success_rate'] . '%'],
                    ['Avg Duration', $periodStats['avg_duration_ms'] . 'ms'],
                ],
            );
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Search audit entries.
     */
    private function showSearch(): int
    {
        $filterString = (string) $this->option('search');
        $filters = $this->parseFilterString($filterString);
        $limit = (int) $this->option('limit');

        $results = $this->auditService->search($filters, limit: $limit);

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Replay Audit Search — {$results['total']} result(s)");
        $this->newLine();

        if (empty($results['entries'])) {
            $this->warn('No matching audit entries found.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $entry): array => [
            $entry['audit_id'] ?? '-',
            $entry['type'] ?? '-',
            $entry['event_name'] ?? ($entry['total_events'] ?? '-'),
            $entry['source'] ?? '-',
            $entry['success'] ? '✓' : '✗',
            $entry['recorded_at'] ?? '-',
        ], $results['entries']);

        $this->table(
            ['Audit ID', 'Type', 'Event', 'Source', 'Success', 'Recorded At'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Show recent audit entries.
     */
    private function showRecent(): int
    {
        $limit = (int) $this->option('limit');
        $results = $this->auditService->search([], limit: $limit);

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Recent Replay Audit Entries (last {$limit})");
        $this->newLine();

        if (empty($results['entries'])) {
            $this->warn('No audit entries recorded yet.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $entry): array => [
            $entry['audit_id'] ?? '-',
            $entry['type'] ?? '-',
            $entry['event_name'] ?? ($entry['total_events'] . ' events'),
            ($entry['providers_succeeded'] ?? $entry['replayed'] ?? '-') . '/' . ($entry['providers_failed'] ?? $entry['failed'] ?? '-'),
            $entry['duration_ms'] !== null ? $entry['duration_ms'] . 'ms' : '-',
            $entry['recorded_at'] ?? '-',
        ], $results['entries']);

        $this->table(
            ['Audit ID', 'Type', 'Event', 'OK/Fail', 'Duration', 'Recorded At'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Show data retention status.
     */
    private function showPurgeStatus(): int
    {
        $stats = $this->retentionService->statistics();
        $summary = $this->retentionService->summary();
        $purgeLogs = $this->retentionService->getPurgeLogs();

        $output = [
            'statistics' => $stats,
            'summary' => $summary,
            'recent_purge_logs' => $purgeLogs,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('ZeroBoiler Analytics — Data Retention Status');
        $this->newLine();

        $this->table(
            ['Property', 'Value'],
            [
                ['Enabled', $stats['enabled'] ? 'Yes' : 'No'],
                ['GDPR Erase', $stats['gdpr_erase_enabled'] ? 'Enabled' : 'Disabled'],
                ['Default Retention', $stats['default_days'] . ' days'],
                ['Total Archived', (string) $stats['total_archived']],
                ['Purge Batch Size', (string) $stats['purge_batch_size']],
                ['Configured Categories', (string) $stats['category_count']],
            ],
        );

        $categories = $this->retentionService->configuredCategories();

        if (! empty($categories)) {
            $this->newLine();
            $this->info('Category Retention Periods:');
            $this->table(
                ['Category', 'Retention (days)'],
                array_map(
                    fn (string $cat, int $days): array => [$cat, (string) $days],
                    array_keys($categories),
                    array_values($categories),
                ),
            );
        }

        if (! empty($purgeLogs)) {
            $this->newLine();
            $this->info('Recent Purge Operations:');
            $this->table(
                ['Timestamp', 'Purged', 'Expired Found', 'Category'],
                array_map(fn (array $log): array => [
                    $log['timestamp'],
                    (string) $log['purged'],
                    (string) $log['expired_found'],
                    $log['category'] ?? 'all',
                ], $purgeLogs),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Purge expired archived events.
     */
    private function purgeExpired(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $category = $this->option('category') !== null ? (string) $this->option('category') : null;

        if ($dryRun) {
            $this->warn('DRY RUN — no events will be deleted.');
        }

        $this->info($dryRun ? 'Scanning for expired events...' : 'Purging expired events...');

        $result = $this->retentionService->purgeExpired($dryRun, $category);

        $output = [
            'scanned' => $result['scanned'],
            'expired' => $result['purged'],
            'dry_run' => $result['dry_run'],
            'category' => $result['category'],
            'timestamp' => $result['timestamp'],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();

        if ($result['purged'] === 0) {
            $this->info('No expired events found.');

            return self::SUCCESS;
        }

        $action = $dryRun ? 'Would purge' : 'Purged';
        $this->info("{$action} {$result['purged']} expired event(s) out of {$result['scanned']} scanned.");

        if ($category !== null) {
            $this->line("Category filter: {$category}");
        }

        return self::SUCCESS;
    }

    /**
     * Show recent purge logs.
     */
    private function showPurgeLogs(): int
    {
        $logs = $this->retentionService->getPurgeLogs();

        if ($this->option('json')) {
            $this->line(json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Recent Purge Logs');
        $this->newLine();

        if (empty($logs)) {
            $this->warn('No purge operations recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Timestamp', 'Purged', 'Expired Found', 'Category'],
            array_map(fn (array $log): array => [
                $log['timestamp'],
                (string) $log['purged'],
                (string) $log['expired_found'],
                $log['category'] ?? 'all',
            ], $logs),
        );

        return self::SUCCESS;
    }

    /**
     * Parse a filter string "key=value" into an associative array.
     *
     * Supports comma-separated filters: "source=command,type=bulk"
     *
     * @return array<string, string>
     */
    private function parseFilterString(string $filterString): array
    {
        $filters = [];

        foreach (explode(',', $filterString) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Get the audit service from the container.
     */
    private function getAuditService(): EventReplayAuditService
    {
        return $this->laravel->make(EventReplayAuditService::class);
    }

    /**
     * Get the retention service from the container.
     */
    private function getRetentionService(): AnalyticsDataRetentionService
    {
        return $this->laravel->make(AnalyticsDataRetentionService::class);
    }
}
