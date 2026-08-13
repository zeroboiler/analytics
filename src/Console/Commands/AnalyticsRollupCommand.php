<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsRollupService;

/**
 * Analytics rollup management command.
 *
 * Provides CLI access to the pre-computed rollup engine for:
 * - Computing current period rollups from raw event data
 * - Querying aggregated metrics for any period
 * - Comparing trends between periods
 * - Viewing service configuration and data statistics
 * - Clearing stale rollup data
 *
 * @since 52.0.0
 */
final class AnalyticsRollupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:rollup
        {mode=summary : Operation mode (summary, stats, query, trend, sparkline, clear)}
        {--granularity=daily : Granularity for query/trend/sparkline (hourly, daily, weekly)}
        {--period= : Specific period key to query (e.g. 2026-08-13)}
        {--event= : Event name for sparkline mode}
        {--periods=24 : Number of periods for sparkline (default: 24)}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage pre-computed analytics rollups (v52.0.0)';

    /**
     * Execute the console command.
     */
    public function handle(AnalyticsRollupService $rollup): int
    {
        $mode = (string) $this->argument('mode');

        return match ($mode) {
            'summary' => $this->showSummary($rollup),
            'stats' => $this->showStats($rollup),
            'query' => $this->showQuery($rollup),
            'trend' => $this->showTrend($rollup),
            'sparkline' => $this->showSparkline($rollup),
            'clear' => $this->clearRollups($rollup),
            default => $this->unknownMode($mode),
        };
    }

    /**
     * Display the rollup service configuration summary.
     */
    private function showSummary(AnalyticsRollupService $rollup): int
    {
        $summary = $rollup->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║         Analytics Rollup Engine — Summary          ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line('  Status: ' . ($summary['enabled'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>'));
        $this->line('  Granularities: ' . implode(', ', $summary['granularities']));
        $this->line('  Cache Prefix: ' . $summary['cache_prefix']);
        $this->newLine();
        $this->line('  TTL:');
        $this->line('    Hourly: ' . $summary['hourly_ttl'] . 's (' . $this->formatSeconds($summary['hourly_ttl']) . ')');
        $this->line('    Daily:  ' . $summary['daily_ttl'] . 's (' . $this->formatSeconds($summary['daily_ttl']) . ')');
        $this->line('    Weekly: ' . $summary['weekly_ttl'] . 's (' . $this->formatSeconds($summary['weekly_ttl']) . ')');
        $this->newLine();
        $this->line('  Max Top Events: ' . $summary['max_top_events']);
        $this->line('  Max Unique Trackers: ' . $summary['max_unique_trackers']);

        return self::SUCCESS;
    }

    /**
     * Display current rollup statistics for all granularities.
     */
    private function showStats(AnalyticsRollupService $rollup): int
    {
        $stats = $rollup->stats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║         Analytics Rollup Engine — Stats             ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        foreach ($stats['granularities'] as $g => $data) {
            $this->line("  [<comment>{$g}</comment>] Period: {$data['period']}");
            $this->line("    Total Events:     {$data['total']}");
            $this->line("    Event Types:      {$data['event_types']}");
            $this->line("    Categories:       {$data['categories']}");
            $this->line("    Providers:        {$data['providers']}");
            $this->line("    Unique Users:     {$data['unique_users']}");
            $this->line("    Unique Clients:   {$data['unique_clients']}");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Query rollup data for a specific granularity and period.
     */
    private function showQuery(AnalyticsRollupService $rollup): int
    {
        $granularity = (string) $this->option('granularity');
        $period = $this->option('period');

        $data = $rollup->query($granularity, $period !== null ? (string) $period : null);

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║  Rollup Query — {$granularity}                         ║");
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line("  Period:       {$data['period']}");
        $this->line("  Total Events: {$data['total']}");
        $this->line("  Unique Users: {$data['unique_users']}");
        $this->line("  Unique Clients: {$data['unique_clients']}");
        $this->newLine();

        if (! empty($data['top_events'])) {
            $this->line('  Top Events:');
            foreach ($data['top_events'] as $i => $evt) {
                $rank = $i + 1;
                $this->line("    {$rank}. {$evt['name']}: {$evt['count']}");
            }
            $this->newLine();
        }

        if (! empty($data['categories'])) {
            $this->line('  Categories:');
            foreach ($data['categories'] as $cat => $count) {
                $pct = $data['category_distribution'][$cat] ?? 0;
                $this->line("    {$cat}: {$count} ({$pct}%)");
            }
        }

        if (! empty($data['providers'])) {
            $this->newLine();
            $this->line('  Providers:');
            foreach ($data['providers'] as $prov => $count) {
                $this->line("    {$prov}: {$count}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display trend comparison between current and previous period.
     */
    private function showTrend(AnalyticsRollupService $rollup): int
    {
        $granularity = (string) $this->option('granularity');
        $trend = $rollup->trend($granularity);

        if ($this->option('json')) {
            $this->line(json_encode($trend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║  Rollup Trend — {$granularity}                             ║");
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        $cur = $trend['current'];
        $prev = $trend['previous'];
        $pct = $trend['pct_change'];

        $this->line("  Current Period:  {$cur['period']}");
        $this->line("  Previous Period: {$prev['period']}");
        $this->newLine();

        $this->line('  Metric           │ Current │ Previous │  Delta  │ Change');
        $this->line('  ─────────────────┼─────────┼──────────┼─────────┼────────');
        $this->line(sprintf(
            '  Total Events     │ %7d │ %8d │ %+7d │ %s',
            $cur['total'],
            $prev['total'],
            $trend['delta']['total'],
            $this->formatChange($pct['total']),
        ));
        $this->line(sprintf(
            '  Unique Users     │ %7d │ %8d │ %+7d │ %s',
            $cur['unique_users'],
            $prev['unique_users'],
            $trend['delta']['unique_users'],
            $this->formatChange($pct['unique_users']),
        ));
        $this->line(sprintf(
            '  Unique Clients   │ %7d │ %8d │ %+7d │ %s',
            $cur['unique_clients'],
            $prev['unique_clients'],
            $trend['delta']['unique_clients'],
            $this->formatChange($pct['unique_clients']),
        ));

        return self::SUCCESS;
    }

    /**
     * Display sparkline data for an event.
     */
    private function showSparkline(AnalyticsRollupService $rollup): int
    {
        $eventName = (string) $this->option('event');
        $granularity = (string) $this->option('granularity');
        $periods = (int) $this->option('periods');

        if ($eventName === '') {
            $this->error('--event is required for sparkline mode.');

            return self::FAILURE;
        }

        $data = $rollup->sparkline($eventName, $granularity, $periods);

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║  Sparkline — {$eventName}                       ║");
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        foreach ($data as $point) {
            $bar = str_repeat('█', min((int) $point['count'], 50));
            $countStr = str_pad((string) $point['count'], 5);
            $this->line("  {$point['period']} │ {$countStr} │ {$bar}");
        }

        return self::SUCCESS;
    }

    /**
     * Clear rollup data.
     */
    private function clearRollups(AnalyticsRollupService $rollup): int
    {
        $cleared = $rollup->clear();

        if ($this->option('json')) {
            $this->line(json_encode(['cleared' => $cleared], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($cleared > 0) {
            $this->info("Cleared rollup data for {$cleared} granularity prefix(es).");
        } else {
            $this->warn('No rollup data cleared (cache driver may not support prefix-based flushing).');
        }

        return self::SUCCESS;
    }

    /**
     * Handle unknown mode.
     */
    private function unknownMode(string $mode): int
    {
        $this->error("Unknown mode: {$mode}");
        $this->line('Available modes: summary, stats, query, trend, sparkline, clear');

        return self::FAILURE;
    }

    /**
     * Format seconds into a human-readable duration.
     */
    private function formatSeconds(int $seconds): string
    {
        if ($seconds >= 86400) {
            $days = (int) round($seconds / 86400);

            return "{$days}d";
        }

        if ($seconds >= 3600) {
            $hours = (int) round($seconds / 3600);

            return "{$hours}h";
        }

        return "{$seconds}s";
    }

    /**
     * Format a percentage change with color indicator.
     */
    private function formatChange(float $pct): string
    {
        $sign = $pct >= 0 ? '+' : '';
        $color = $pct > 0 ? 'info' : ($pct < 0 ? 'error' : 'comment');

        return "<{$color}>{$sign}{$pct}%</{$color}>";
    }
}
