<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventLineageTrackerService;

/**
 * Analytics Event Lineage Tracker Command.
 *
 * Provides visibility into the lifecycle tracking of analytics events.
 * Use for debugging pipeline issues, monitoring provider reliability,
 * and generating compliance reports.
 *
 * @since 49.0.0
 */
final class AnalyticsLineageCommand extends Command
{
    protected $signature = 'zb:analytics:lineage
        {--mode=status : Command mode (status, stats, failures, stages, providers, show, trace, purge, purge-before, export)}
        {--id= : Lineage ID to inspect (for show/trace mode)}
        {--event= : Filter by event name}
        {--source= : Filter by source (api, server, client, webhook, replay, batch, lifecycle)}
        {--status= : Filter by status (delivered, partial, failed, filtered)}
        {--limit=50 : Maximum entries to show}
        {--days=7 : Days to retain when purging}
        {--json : Output as JSON}
        {--purge : Shortcut for --mode=purge}';

    protected $description = 'Inspect and manage analytics event lineage tracking';

    private ?EventLineageTrackerService $service = null;

    private function service(): EventLineageTrackerService
    {
        if ($this->service === null) {
            $this->service = app(EventLineageTrackerService::class);
        }

        return $this->service;
    }

    #[\Override]
    public function handle(): int
    {
        $mode = $this->option('purge') ? 'purge' : ((string) $this->option('mode'));
        $json = (bool) $this->option('json');

        return match ($mode) {
            'status' => $this->modeStatus($json),
            'stats' => $this->modeStats($json),
            'failures' => $this->modeFailures($json),
            'stages' => $this->modeStages($json),
            'providers' => $this->modeProviders($json),
            'show' => $this->modeShow($json),
            'trace' => $this->modeTrace($json),
            'purge' => $this->modePurge($json),
            'purge-before' => $this->modePurgeBefore($json),
            'export' => $this->modeExport($json),
            default => $this->modeStatus($json),
        };
    }

    /**
     * Display lineage tracking status.
     */
    private function modeStatus(bool $json): int
    {
        $service = $this->service();
        $stats = $service->getStats();

        $output = [
            'enabled' => $service->isEnabled(),
            'auto_track' => $service->isAutoTrackEnabled(),
            'total_tracked' => $stats['total_tracked'],
            'in_progress' => $stats['in_progress'],
            'delivered' => $stats['delivered'],
            'partial' => $stats['partial'],
            'failed' => $stats['failed'],
            'filtered' => $stats['filtered'],
            'avg_duration_ms' => $stats['avg_duration_ms'],
            'by_source' => $stats['by_source'],
            'enrichment_stages_used' => $stats['enrichment_stages_used'],
        ];

        if ($json) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📊 Analytics Event Lineage Status');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Enabled', $output['enabled'] ? '✅ Yes' : '❌ No'],
                ['Auto-Track', $output['auto_track'] ? '✅ Yes' : '❌ No'],
                ['Total Tracked', (string) $output['total_tracked']],
                ['In Progress', (string) $output['in_progress']],
                ['Delivered', (string) $output['delivered']],
                ['Partial', (string) $output['partial']],
                ['Failed', (string) $output['failed']],
                ['Filtered', (string) $output['filtered']],
                ['Avg Duration', $output['avg_duration_ms'] !== null ? $output['avg_duration_ms'] . 'ms' : 'N/A'],
            ],
        );

        if (! empty($output['by_source'])) {
            $this->newLine();
            $this->info('By Source:');
            $rows = [];
            foreach ($output['by_source'] as $source => $count) {
                $rows[] = [$source, (string) $count];
            }
            $this->table(['Source', 'Count'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Display detailed statistics.
     */
    private function modeStats(bool $json): int
    {
        $stats = $this->service()->getStats();

        if ($json) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📈 Lineage Statistics');
        $this->newLine();
        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Display failure patterns.
     */
    private function modeFailures(bool $json): int
    {
        $patterns = $this->service()->getFailurePatterns(limit: (int) $this->option('limit'));

        if (empty($patterns)) {
            $this->info('✅ No failure patterns detected.');
            return self::SUCCESS;
        }

        if ($json) {
            $this->line(json_encode($patterns, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->warn('⚠️  Top Failure Patterns:');
        $rows = [];
        foreach ($patterns as $p) {
            $rows[] = [
                $p['pattern'],
                (string) $p['count'],
                $p['last_seen'] !== null ? date('Y-m-d H:i:s', (int) $p['last_seen']) : 'N/A',
            ];
        }
        $this->table(['Pattern', 'Count', 'Last Seen'], $rows);

        return self::SUCCESS;
    }

    /**
     * Display enrichment stage performance stats.
     */
    private function modeStages(bool $json): int
    {
        $stageStats = $this->service()->getStagePerformanceStats();

        if (empty($stageStats)) {
            $this->info('No enrichment stage data available.');
            return self::SUCCESS;
        }

        if ($json) {
            $this->line(json_encode($stageStats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🔧 Enrichment Stage Performance:');
        $rows = [];
        foreach ($stageStats as $stage => $data) {
            $rows[] = [
                $stage,
                (string) $data['count'],
                $data['avg_ms'] . 'ms',
                $data['min_ms'] !== null ? $data['min_ms'] . 'ms' : 'N/A',
                $data['max_ms'] !== null ? $data['max_ms'] . 'ms' : 'N/A',
            ];
        }
        $this->table(['Stage', 'Calls', 'Avg', 'Min', 'Max'], $rows);

        return self::SUCCESS;
    }

    /**
     * Display provider reliability stats.
     */
    private function modeProviders(bool $json): int
    {
        $providerStats = $this->service()->getProviderReliabilityStats();

        if (empty($providerStats)) {
            $this->info('No provider dispatch data available.');
            return self::SUCCESS;
        }

        if ($json) {
            $this->line(json_encode($providerStats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🌐 Provider Dispatch Reliability:');
        $rows = [];
        foreach ($providerStats as $provider => $data) {
            $rows[] = [
                $provider,
                (string) $data['total'],
                (string) $data['success'],
                (string) $data['failure'],
                $data['success_rate'] . '%',
                $data['avg_duration_ms'] !== null ? $data['avg_duration_ms'] . 'ms' : 'N/A',
            ];
        }
        $this->table(['Provider', 'Total', 'Success', 'Failed', 'Rate', 'Avg Time'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show a specific lineage entry by ID.
     */
    private function modeShow(bool $json): int
    {
        $id = $this->option('id');

        if ($id === null || $id === '') {
            $this->error('Lineage ID required. Use --id=<lineage_id>');
            return self::FAILURE;
        }

        $entry = $this->service()->getLineage((string) $id);

        if ($entry === null) {
            $this->error("Lineage entry '{$id}' not found.");
            return self::FAILURE;
        }

        if ($json) {
            $this->line(json_encode($entry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📋 Lineage: {$id}");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Event', $entry['event_name'] ?? 'N/A'],
                ['Source', $entry['source'] ?? 'N/A'],
                ['Client ID', $entry['client_id'] ?? 'N/A'],
                ['User ID', $entry['user_id'] ?? 'N/A'],
                ['Status', $entry['status'] ?? 'N/A'],
                ['Total Duration', ($entry['total_duration_ms'] ?? 'N/A') . 'ms'],
            ],
        );

        if (! empty($entry['enrichment_stages'])) {
            $this->newLine();
            $this->info('Enrichment Pipeline:');
            $rows = [];
            foreach ($entry['enrichment_stages'] as $stage) {
                $rows[] = [
                    $stage['stage'] ?? 'unknown',
                    ($stage['modified'] ?? false) ? '✅ Modified' : '⏭️ Pass-through',
                    $stage['duration_ms'] !== null ? $stage['duration_ms'] . 'ms' : 'N/A',
                ];
            }
            $this->table(['Stage', 'Result', 'Duration'], $rows);
        }

        if (! empty($entry['provider_dispatches'])) {
            $this->newLine();
            $this->info('Provider Dispatches:');
            $rows = [];
            foreach ($entry['provider_dispatches'] as $dispatch) {
                $success = ($dispatch['success'] ?? false) ? '✅ Success' : '❌ Failed';
                $rows[] = [
                    $dispatch['provider'] ?? 'unknown',
                    $success,
                    $dispatch['duration_ms'] !== null ? $dispatch['duration_ms'] . 'ms' : 'N/A',
                    $dispatch['error'] ?? '—',
                ];
            }
            $this->table(['Provider', 'Result', 'Duration', 'Error'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Trace the full lifecycle of a lineage entry (detailed view).
     */
    private function modeTrace(bool $json): int
    {
        return $this->modeShow($json);
    }

    /**
     * Purge all lineage entries.
     */
    private function modePurge(bool $json): int
    {
        if (! $this->confirm('This will permanently delete ALL lineage entries. Continue?')) {
            return self::SUCCESS;
        }

        $count = $this->service()->purge();

        if ($json) {
            $this->line(json_encode(['purged' => $count], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🗑️  Purged {$count} lineage entries.");

        return self::SUCCESS;
    }

    /**
     * Purge lineage entries older than N days.
     */
    private function modePurgeBefore(bool $json): int
    {
        $days = (int) $this->option('days');
        $beforeTimestamp = microtime(true) - ($days * 86400);

        $count = $this->service()->purgeBefore($beforeTimestamp);

        if ($json) {
            $this->line(json_encode(['purged' => $count, 'older_than_days' => $days], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🗑️  Purged {$count} lineage entries older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Export lineage data for compliance reporting.
     */
    private function modeExport(bool $json): int
    {
        $export = $this->service()->exportForCompliance();

        if ($json) {
            $this->line(json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("📄 Lineage Export: {$export['total']} entries at {$export['exported_at']}");
        }

        return self::SUCCESS;
    }
}
