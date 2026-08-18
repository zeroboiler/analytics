<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\DispatchDecisionReplayService;
use ZeroBoiler\Analytics\Services\EventDeliveryWatermarkService;

/**
 * Analytics watermark management command.
 *
 * Provides CLI access to the event delivery watermark system for
 * monitoring provider delivery progress, detecting gaps, and
 * analyzing cross-provider consistency.
 *
 * Modes:
 *   (default)  — Dashboard summary
 *   status      — Per-provider watermark status
 *   gaps        — Detect and show delivery gaps
 *   consistency — Cross-provider consistency report
 *   log         — Recent dispatch log
 *   replay      — Dispatch decision replay analysis
 *   reset       — Reset all watermarks and counters
 *
 * @since 245.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventDeliveryWatermarkService
 * @see \ZeroBoiler\Analytics\Services\DispatchDecisionReplayService
 */
final class AnalyticsWatermarkCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:watermark
        {mode? : Operation mode (status|gaps|consistency|log|replay|reset)}
        {--provider= : Filter by provider}
        {--limit=50 : Max entries for log/gaps}
        {--json : Output as JSON}
        {--fresh : Clear cache before reading}';

    /** @var string */
    protected $description = 'Monitor event delivery watermarks, detect gaps, and analyze cross-provider consistency';

    public function __construct(
        private readonly EventDeliveryWatermarkService $watermarkService,
        private readonly DispatchDecisionReplayService $replayService,
    ): void {
        parent::__construct();
    }

    /**
 * Execute the console command.
 */
    public function handle(): int
    {
        $mode = $this->argument('mode') ?? 'dashboard';
        $json = $this->option('json');

        return match ($mode) {
            'dashboard', null => $this->showDashboard($json),
            'status' => $this->showStatus($json),
            'gaps' => $this->showGaps($json),
            'consistency' => $this->showConsistency($json),
            'log' => $this->showLog($json),
            'replay' => $this->showReplay($json),
            'reset' => $this->resetWatermarks(),
            default => $this->invalidMode($mode),
        };
    }

    /**
 * Show dashboard summary.
     *
     * @return int
     */
    private function showDashboard(bool $json): int
    {
        $dashboard = $this->watermarkService->dashboard();

        if ($json) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Event Delivery Watermark Dashboard');
        $this->newLine();

        $this->line("  Global HWM:          <fg=cyan>{$dashboard['global_hwm']}</>");
        $this->line("  Providers Tracked:    <fg=cyan>{$dashboard['dispatch_stats']['providers_tracked']}</>");
        $this->line("  Confirmation Rate:    <fg=green>{$dashboard['dispatch_stats']['confirmation_rate']}%</>");
        $this->line("  Total Dispatched:     {$dashboard['dispatch_stats']['total_dispatched']}");
        $this->line("  Total Gaps:           " . ($dashboard['gaps']['total'] > 0 ? "<fg=red>{$dashboard['gaps']['total']}</>" : '<fg=green>0</>'));
        $this->line("  Consistency Score:    " . $this->formatScore($dashboard['consistency']['consistency_score'], $dashboard['consistency']['status']));
        $this->newLine();

        // Provider status table
        $headers = ['Provider', 'Watermark', 'Lag', 'Status', 'Gaps', 'Last Event'];
        $rows = [];

        foreach ($dashboard['providers'] as $p) {
            $rows[] = [
                $p['provider'],
                (string) $p['confirmed_watermark'],
                (string) $p['lag'],
                $this->statusLabel($p['status']),
                (string) $p['gap_count'],
                $p['last_event'] ?? '-',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
 * Show per-provider status.
     *
     * @return int
     */
    private function showStatus(bool $json): int
    {
        $provider = $this->option('provider');

        if ($provider !== null) {
            $watermark = $this->watermarkService->providerWatermark($provider);
            $globalHwm = $this->watermarkService->globalHighWaterMark();
            $lag = max(0, $globalHwm - $watermark);
            $gaps = $this->watermarkService->gapsForProvider($provider);
            $checkpoint = $this->watermarkService->resumeCheckpoint($provider);

            $data = [
                'provider' => $provider,
                'confirmed_watermark' => $watermark,
                'global_hwm' => $globalHwm,
                'lag' => $lag,
                'resume_checkpoint' => $checkpoint,
                'open_gaps' => count($gaps),
                'gaps' => $gaps,
            ];

            if ($json) {
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info("Watermark Status: {$provider}");
            $this->line("  Confirmed Watermark:   <fg=cyan>{$watermark}</>");
            $this->line("  Global HWM:            <fg=cyan>{$globalHwm}</>");
            $this->line("  Lag:                   <fg=yellow>{$lag}</> events behind");
            $this->line("  Resume Checkpoint:     <fg=cyan>{$checkpoint}</>");
            $this->line("  Open Gaps:             " . (count($gaps) > 0 ? "<fg=red>" . count($gaps) . '</>' : '<fg=green>0</>'));

            if ($gaps !== []) {
                $this->newLine();
                $this->components->warn('Open Delivery Gaps:');
                $this->table(
                    ['Seq', 'Event', 'Detected At'],
                    array_map(static fn (array $g): array => [
                        (string) $g['seq'],
                        $g['event'],
                        date('Y-m-d H:i:s', (int) $g['detected_at']),
                    ], $gaps),
                );
            }

            return self::SUCCESS;
        }

        $statuses = $this->watermarkService->providerStatuses();

        if ($json) {
            $this->line(json_encode($statuses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Provider', 'Confirmed', 'Lag', 'Status', 'Gaps', 'Last Event'],
            array_map(static fn (array $s): array => [
                $s['provider'],
                (string) $s['confirmed_watermark'],
                (string) $s['lag'],
                $s['status'],
                (string) $s['gap_count'],
                $s['last_event'] ?? '-',
            ], $statuses),
        );

        return self::SUCCESS;
    }

    /**
 * Show delivery gap detection results.
     *
     * @return int
     */
    private function showGaps(bool $json): int
    {
        $provider = $this->option('provider');
        $limit = (int) $this->option('limit');

        if ($provider !== null) {
            $gaps = $this->watermarkService->gapsForProvider($provider);
            $replayable = $this->watermarkService->replayableGaps($provider);

            $data = [
                'provider' => $provider,
                'open_gaps' => count($gaps),
                'replayable' => $replayable,
                'gaps' => $gaps,
            ];

            if ($json) {
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->components->info("Delivery Gaps: {$provider}");
            $this->line("  Open Gaps:     " . (count($gaps) > 0 ? "<fg=red>" . count($gaps) . '</>' : '<fg=green>0</>'));
            $this->line("  Replayable:    " . count($replayable) . ' events from checkpoint');
            $this->line("  Checkpoint:    <fg=cyan>{$this->watermarkService->resumeCheckpoint($provider)}</>");

            if ($gaps !== []) {
                $this->newLine();
                $this->table(
                    ['Seq', 'Event', 'Detected At'],
                    array_map(static fn (array $g): array => [
                        (string) $g['seq'],
                        $g['event'],
                        date('Y-m-d H:i:s', (int) $g['detected_at']),
                    ], array_slice($gaps, 0, $limit)),
                );
            }

            return self::SUCCESS;
        }

        $gapReport = $this->watermarkService->detectGaps();

        if ($json) {
            $this->line(json_encode($gapReport, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Delivery Gap Detection');
        $this->line("  Total Gaps: <fg=yellow>{$gapReport['total_gaps']}</>");
        $this->newLine();

        if ($gapReport['by_provider'] !== []) {
            $this->table(
                ['Provider', 'Open Gaps'],
                array_map(static fn (string $p, int $c): array => [$p, (string) $c], array_keys($gapReport['by_provider']), array_values($gapReport['by_provider'])),
            );
        } else {
            $this->components->info('No delivery gaps detected.');
        }

        return self::SUCCESS;
    }

    /**
 * Show cross-provider consistency report.
     *
     * @return int
     */
    private function showConsistency(bool $json): int
    {
        $report = $this->watermarkService->consistencyReport();

        if ($json) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Cross-Provider Consistency Report');
        $this->newLine();
        $this->line("  Consistency Score:   " . $this->formatScore($report['consistency_score'], $report['status']));
        $this->line("  Status:              <fg=yellow>{$report['status']}</>");
        $this->line("  Max Lag:             <fg=red>{$report['max_lag']}</> events");
        $this->line("  Min Lag:             <fg=green>{$report['min_lag']}</> events");
        $this->line("  Avg Lag:             <fg=yellow>{$report['avg_lag']}</> events");
        $this->line("  Lag Std Dev:         {$report['lag_std_dev']}");
        $this->line("  Providers:           {$report['provider_count']}");
        $this->line("  Most Behind:         " . ($report['most_behind'] ?? 'N/A'));
        $this->line("  Most Current:        " . ($report['most_current'] ?? 'N/A'));

        return self::SUCCESS;
    }

    /**
 * Show recent dispatch log.
     *
     * @return int
     */
    private function showLog(bool $json): int
    {
        $provider = $this->option('provider');
        $limit = (int) $this->option('limit');

        $log = $provider !== null
            ? $this->watermarkService->dispatchLogForProvider($provider, $limit)
            : $this->watermarkService->dispatchLog($limit);

        if ($json) {
            $this->line(json_encode($log, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Seq', 'Event', 'Provider', 'Status', 'Time'],
            array_map(static fn (array $e): array => [
                (string) $e['seq'],
                $e['event'],
                $e['provider'],
                $e['status'],
                date('H:i:s', (int) $e['timestamp']),
            ], $log),
        );

        return self::SUCCESS;
    }

    /**
 * Show dispatch decision replay analysis.
     *
     * @return int
     */
    private function showReplay(bool $json): int
    {
        $analysis = $this->replayService->analyze();
        $reasoning = $this->replayService->reasoningBreakdown();

        if ($json) {
            $this->line(json_encode([
                'analysis' => $analysis,
                'reasoning_breakdown' => $reasoning,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Dispatch Decision Replay Analysis');
        $this->newLine();
        $this->line("  Total Decisions:     <fg=cyan>{$analysis['total']}</>");
        $this->line("  Dispatch Rate:       <fg=green>{$analysis['dispatch_rate']}%</>");
        $this->line("  Drop Rate:           " . ($analysis['drop_rate'] > 5.0 ? "<fg=red>{$analysis['drop_rate']}%</>" : "<fg=green>{$analysis['drop_rate']}%"));
        $this->line("  Defer Rate:          <fg=yellow>{$analysis['defer_rate']}%</>");
        $this->line("  Circuit Opens:       " . ($analysis['circuit_open_count'] > 0 ? "<fg=red>{$analysis['circuit_open_count']}</>" : '0'));
        $this->line("  Consent Denied:      {$analysis['consent_denied_count']}");
        $this->line("  Budget Exceeded:     {$analysis['budget_exceeded_count']}");
        $this->newLine();

        // Action breakdown
        $this->components->info('Action Breakdown:');
        foreach ($analysis['by_action'] as $action => $count) {
            $pct = $analysis['total'] > 0 ? round(($count / $analysis['total']) * 100.0, 1) : 0.0;
            $this->line("  {$action}: <fg=cyan>{$count}</> ({$pct}%)");
        }

        // Top reasoning
        if ($reasoning !== []) {
            $this->newLine();
            $this->components->info('Top Decision Reasoning:');
            $this->table(
                ['Reasoning', 'Action', 'Count', 'Example Event'],
                array_map(static fn (array $r): array => [
                    $r['reasoning'],
                    $r['action'],
                    (string) $r['count'],
                    $r['example_event'] ?? '-',
                ], array_slice($reasoning, 0, 10)),
            );
        }

        return self::SUCCESS;
    }

    /**
 * Reset all watermarks.
     *
     * @return int
     */
    private function resetWatermarks(): int
    {
        if (! $this->components->confirm('Reset all watermarks, gaps, and counters? This cannot be undone.')) {
            return self::SUCCESS;
        }

        $this->watermarkService->reset();
        $this->components->info('All watermarks, gaps, and counters have been reset.');

        return self::SUCCESS;
    }
    /**
 * Handle invalid mode.
     *
     * @return int
     */
    private function invalidMode(string $mode): int
    {
        $this->components->error("Invalid mode: {$mode}. Use: dashboard, status, gaps, consistency, log, replay, or reset.");

        return self::FAILURE;
    }

    /**
 * Format a consistency score with color.
     */
    private function formatScore(float $score, string $status): string
    {
        $color = match ($status) {
            'consistent' => 'green',
            'moderate' => 'yellow',
            'inconsistent' => 'red',
            'critical' => 'red',
            default => 'white',
        };

        return "<fg={$color}>{$score}%</> ({$status})";
    }

    /**
 * Format a provider status with color.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'current' => '<fg=green>current</>',
            'lagging' => '<fg=yellow>lagging</>',
            'behind' => '<fg=red>behind</>',
            'critical' => '<fg=red;options=bold>CRITICAL</>',
            default => $status,
        };
    }
}
