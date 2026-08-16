<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService;
use ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer;
use ZeroBoiler\Analytics\Services\EventCorrelationEngineService;

/**
 * Analytics self-healing artisan command.
 *
 * Provides diagnostic and recovery operations for the analytics pipeline.
 * Can be used manually for troubleshooting or scheduled for automatic recovery.
 *
 * Modes:
 * - heal: Execute a specific healing action
 * - heal-all: Execute all eligible healing actions
 * - auto: Run automatic healing based on health status
 * - history: Show healing history
 * - summary: Show self-healing service summary
 * - correlate: Analyze event correlations
 * - root-cause: Analyze root cause of an anomaly
 *
 * @since 48.0.0
 */
final class AnalyticsSelfHealCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:self-heal
        {--mode=summary : Operation mode (heal, heal-all, auto, history, summary, correlate, root-cause)}
        {--action= : Specific healing action (for --mode=heal)}
        {--event= : Event name (for --mode=root-cause or --mode=correlate)}
        {--anomaly-type=spike : Anomaly type for root cause analysis}
        {--limit=20 : Result limit}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Analytics self-healing: automatic pipeline recovery, correlation analysis, and anomaly root cause diagnosis';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        AnalyticsSelfHealingService $selfHealingService,
        ?EventCorrelationEngineService $correlationEngine = null,
        ?AnomalyRootCauseAnalyzer $rootCauseAnalyzer = null,
    ): int {
        $mode = (string) $this->option('mode');
        $json = (bool) $this->option('json');

        return match ($mode) {
            'heal' => $this->handleHeal($selfHealingService, $json),
            'heal-all' => $this->handleHealAll($selfHealingService, $json),
            'auto' => $this->handleAutoHeal($selfHealingService, $json),
            'history' => $this->handleHistory($selfHealingService, $json),
            'summary' => $this->handleSummary($selfHealingService, $json),
            'correlate' => $this->handleCorrelate($correlationEngine, $json),
            'root-cause' => $this->handleRootCause($rootCauseAnalyzer, $json),
            default => $this->invalidMode($mode),
        };
    }

    /**
     * Handle the heal mode.
     */
    private function handleHeal(AnalyticsSelfHealingService $service, bool $json): int
    {
        $action = (string) $this->option('action');

        if ($action === '') {
            $this->error('Action required for --mode=heal. Use --action=<name>');
            $this->line('Available actions: warm_cache, reset_provider_health, flush_dlq, reset_pipeline, cleanup_stale_data, check_queue_health, reset_fraud_metrics, reset_quality_firewall, clear_correlations');

            return 1;
        }

        $result = $service->heal($action);

        if ($json) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $result['status'] === 'success' ? 0 : 1;
        }

        $statusColor = match ($result['status']) {
            'success' => 'info',
            'skipped' => 'comment',
            'failed' => 'error',
            default => 'line',
        };

        $this->{$statusColor}("[{$result['status']}] {$result['action']}: {$result['message']} ({$result['duration_ms']}ms)");

        return $result['status'] === 'success' ? 0 : 1;
    }

    /**
     * Handle the heal-all mode.
     */
    private function handleHealAll(AnalyticsSelfHealingService $service, bool $json): int
    {
        $result = $service->healAll();

        if ($json) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $result['failed'] === 0 ? 0 : 1;
        }

        $this->info("Self-Healing: {$result['succeeded']}/{$result['total']} succeeded, {$result['failed']} failed, {$result['skipped']} skipped");

        foreach ($result['results'] as $r) {
            $statusColor = match ($r['status']) {
                'success' => 'info',
                'skipped' => 'comment',
                'failed' => 'error',
                default => 'line',
            };
            $this->{$statusColor}("  [{$r['status']}] {$r['action']}: {$r['message']} ({$r['duration_ms']}ms)");
        }

        return $result['failed'] === 0 ? 0 : 1;
    }

    /**
     * Handle the auto mode.
     */
    private function handleAutoHeal(AnalyticsSelfHealingService $service, bool $json): int
    {
        $result = $service->autoHeal();

        if ($json) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($result['auto_heal_enabled']) {
            $this->info('Auto-healing enabled. Actions triggered: ' . implode(', ', $result['actions_triggered']));

            foreach ($result['results'] as $r) {
                $this->line("  [{$r['status']}] {$r['action']}: {$r['message']}");
            }
        } else {
            $this->comment('Auto-healing is disabled. Set ANALYTICS_SELF_HEALING_AUTO_HEAL_ENABLED=true to enable.');
        }

        return 0;
    }

    /**
     * Handle the history mode.
     */
    private function handleHistory(AnalyticsSelfHealingService $service, bool $json): int
    {
        $limit = (int) $this->option('limit');
        $history = $service->getHistory($limit);

        if ($json) {
            $this->line(json_encode($history, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($history === []) {
            $this->comment('No healing history found.');

            return 0;
        }

        $this->info("Healing History (last {$limit}):");
        $this->line('');

        foreach ($history as $entry) {
            $time = date('Y-m-d H:i:s', $entry['timestamp']);
            $statusColor = match ($entry['status']) {
                'success' => 'info',
                'skipped' => 'comment',
                'failed' => 'error',
                default => 'line',
            };
            $this->{$statusColor}("  [{$entry['status']}] {$time} — {$entry['action']}: {$entry['message']} ({$entry['duration_ms']}ms)");
        }

        return 0;
    }

    /**
     * Handle the summary mode.
     */
    private function handleSummary(AnalyticsSelfHealingService $service, bool $json): int
    {
        $summary = $service->getSummary();

        if ($json) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Self-Healing Service Summary');
        $this->line('');
        $this->line('  Auto-heal enabled: ' . ($summary['auto_heal_enabled'] ? '<info>Yes</info>' : '<comment>No</comment>'));
        $this->line('  Cooldown: ' . $summary['cooldown_seconds'] . 's');
        $this->line('  Total healings: ' . $summary['total_healings']);
        $this->line('  Auto-heal actions: ' . (count($summary['auto_heal_actions']) > 0 ? implode(', ', $summary['auto_heal_actions']) : '(none)'));
        $this->line('  Available actions: ' . implode(', ', $summary['available_actions']));

        if ($summary['last_healing'] !== null) {
            $last = $summary['last_healing'];
            $time = date('Y-m-d H:i:s', $last['timestamp']);
            $this->line("  Last healing: {$time} — {$last['action']} [{$last['status']}]");
        } else {
            $this->line('  Last healing: (none)');
        }

        return 0;
    }

    /**
     * Handle the correlate mode.
     */
    private function handleCorrelate(?EventCorrelationEngineService $engine, bool $json): int
    {
        if ($engine === null) {
            $this->error('EventCorrelationEngineService not available.');

            return 1;
        }

        $event = (string) $this->option('event');
        $limit = (int) $this->option('limit');

        if ($event !== '') {
            $correlations = $engine->getCorrelatedEvents($event);
        } else {
            $topCorrelations = $engine->getTopCorrelations($limit);

            if ($json) {
                $this->line(json_encode($topCorrelations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->info("Top {$limit} Event Correlations:");

                foreach ($topCorrelations as $corr) {
                    $this->line("  {$corr['event_a']} ↔ {$corr['event_b']}: score={$corr['score']}, co-occurrences={$corr['cooccurrences']}");
                }
            }

            return 0;
        }

        if ($json) {
            $this->line(json_encode($correlations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $summary = $engine->getSummary();
            $this->info("Correlations for '{$event}':");
            $this->line("  Total pairs tracked: {$summary['total_pairs_tracked']}");
            $this->line("  Events with correlations: {$summary['events_with_correlations']}");

            if ($correlations !== []) {
                $this->line('');
                foreach ($correlations as $corr) {
                    $this->line("  {$corr['event']}: score={$corr['score']}, direction={$corr['direction']}, co-occurrences={$corr['cooccurrences']}");
                }
            } else {
                $this->comment('  No significant correlations found.');
            }
        }

        return 0;
    }

    /**
     * Handle the root-cause mode.
     */
    private function handleRootCause(?AnomalyRootCauseAnalyzer $analyzer, bool $json): int
    {
        if ($analyzer === null) {
            $this->error('AnomalyRootCauseAnalyzer not available.');

            return 1;
        }

        $event = (string) $this->option('event');
        $anomalyType = (string) $this->option('anomaly-type');

        if ($event === '') {
            $this->error('Event name required for --mode=root-cause. Use --event=<name>');

            return 1;
        }

        $analysis = $analyzer->analyze($event, $anomalyType);

        if ($json) {
            $this->line(json_encode($analysis, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->info("Root Cause Analysis for '{$event}' ({$anomalyType})");
            $this->line("  Analysis ID: {$analysis['analysis_id']}");
            $this->line('');

            if ($analysis['root_causes'] === []) {
                $this->comment('  No root causes identified.');
            } else {
                foreach ($analysis['root_causes'] as $i => $cause) {
                    $this->line("  #" . ($i + 1) . " — {$cause['event']} [{$cause['category']}]");
                    $this->line("      Confidence: {$cause['confidence']}");
                    $this->line("      {$cause['explanation']}");
                    $this->line("      💡 {$cause['suggestion']}");
                    $this->line('');
                }
            }
        }

        return 0;
    }

    /**
     * Handle invalid mode.
     */
    private function invalidMode(string $mode): int
    {
        $this->error("Invalid mode: {$mode}");
        $this->line('Available modes: heal, heal-all, auto, history, summary, correlate, root-cause');

        return 1;
    }
}
