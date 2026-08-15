<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventInspectorService;
use ZeroBoiler\Analytics\Services\WebVitalsAggregatorService;

/**
 * Analytics debug and inspection command.
 *
 * Provides subcommands for:
 *   - inspector:show    Show recent event traces from the Event Inspector
 *   - inspector:clear  Clear inspector data
 *   - inspector:enable Enable inspector at runtime
 *   - inspector:disable Disable inspector at runtime
 *   - rum:summary      Show RUM dashboard summary (Core Web Vitals)
 *   - rum:metric       Show percentile stats for a specific metric
 *   - rum:assessment   Show Core Web Vitals pass/fail assessment
 *   - rum:clear        Clear RUM data
 *
 * @since 68.0.0
 */
final class AnalyticsDebugCommand extends Command
{
    protected $signature = 'zb:analytics:debug
        {action : Subcommand (inspector:show|inspector:clear|inspector:enable|inspector:disable|rum:summary|rum:metric|rum:assessment|rum:clear)}
        {--metric= : Metric name for rum:metric (LCP, FID, CLS, INP, TTFB, FCP)}
        {--page= : Page path for rum:metric or rum:assessment}
        {--limit=10 : Max traces to show for inspector:show}
        {--json : Output as JSON}';

    protected $description = 'Debug and inspect analytics events, RUM metrics, and event lifecycle traces';

    private EventInspectorService $inspector;

    private WebVitalsAggregatorService $rum;

    /**
     * @param  EventInspectorService  $inspector
     * @param  WebVitalsAggregatorService  $rum
     */
    public function __construct(EventInspectorService $inspector, WebVitalsAggregatorService $rum): void
    {
        parent::__construct();
        $this->inspector = $inspector;
        $this->rum = $rum;
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspector:show' => $this->inspectorShow(),
            'inspector:clear' => $this->inspectorClear(),
            'inspector:enable' => $this->inspectorEnable(),
            'inspector:disable' => $this->inspectorDisable(),
            'rum:summary' => $this->rumSummary(),
            'rum:metric' => $this->rumMetric(),
            'rum:assessment' => $this->rumAssessment(),
            'rum:clear' => $this->rumClear(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show recent event inspector traces.
     */
    private function inspectorShow(): int
    {
        $json = (bool) $this->option('json');
        $limit = (int) $this->option('limit');

        if (! $this->inspector->isEnabled()) {
            $this->warn('Event Inspector is disabled. Enable with: zb:analytics:debug inspector:enable');
            $this->warn('Or set config: zeroboiler.analytics.inspector.enabled = true');

            return self::FAILURE;
        }

        $traces = $this->inspector->recentTraces(min($limit, 50));

        if ($json) {
            $this->line(json_encode($traces, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (empty($traces)) {
            $this->info('No event traces recorded yet.');

            return self::SUCCESS;
        }

        $this->info('🔍 ZeroBoiler Event Inspector — Recent Traces');
        $this->newLine();

        foreach ($traces as $traceEntry) {
            $trace = $traceEntry['trace'];
            $status = $trace['has_errors'] ? '❌' : '✅';
            $duration = $trace['total_duration_ms'] !== null
                ? $trace['total_duration_ms'] . 'ms'
                : '—';

            $this->line("{$status} {$traceEntry['event_name']} ({$traceEntry['event_id']}) — {$duration} — {$trace['stage_count']} stages");

            foreach ($trace['stages'] as $stage) {
                $stageDuration = $stage['duration_ms'] !== null
                    ? " [{$stage['duration_ms']}ms]"
                    : '';
                $this->line("   ├─ {$stage['stage']}{$stageDuration}");

                if (! empty($stage['context'])) {
                    foreach ($stage['context'] as $k => $v) {
                        $displayValue = is_array($v) ? json_encode($v) : (string) $v;
                        $this->line("   │  └─ {$k}: {$displayValue}");
                    }
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Clear inspector data.
     */
    private function inspectorClear(): int
    {
        $this->inspector->clear();
        $this->info('Event Inspector data cleared.');

        return self::SUCCESS;
    }

    /**
     * Enable inspector at runtime.
     */
    private function inspectorEnable(): int
    {
        $this->inspector->enable();
        $this->info('Event Inspector enabled.');

        return self::SUCCESS;
    }

    /**
     * Disable inspector at runtime.
     */
    private function inspectorDisable(): int
    {
        $this->inspector->disable();
        $this->info('Event Inspector disabled.');

        return self::SUCCESS;
    }

    /**
     * Show RUM dashboard summary.
     */
    private function rumSummary(): int
    {
        $json = (bool) $this->option('json');
        $page = $this->option('page');

        if (! $this->rum->isEnabled()) {
            $this->warn('RUM collection is not enabled. Enable via config: zeroboiler.analytics.rum.enabled');

            return self::FAILURE;
        }

        $summary = $this->rum->dashboardSummary($page);

        if ($json) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📊 ZeroBoiler RUM Dashboard — Core Web Vitals');
        $this->info("   Window: {$summary['window']}");
        $this->info("   Overall Score: {$summary['overall_score']}% good");
        $this->newLine();

        foreach ($summary['metrics'] as $metricName => $stats) {
            $p75 = $stats['p75'] ?? '—';
            $good = $stats['good_pct'] ?? 0;
            $poor = $stats['poor_pct'] ?? 0;
            $count = $stats['count'];

            $icon = $good >= 75 ? '✅' : ($good >= 50 ? '⚠️' : '❌');

            $this->line("{$icon} {$metricName}: p75={$p75}, good={$good}%, poor={$poor}% (n={$count})");
        }

        if (! empty($summary['worst_metrics'])) {
            $this->newLine();
            $this->warn('Worst-performing metrics: ' . implode(', ', $summary['worst_metrics']));
        }

        return self::SUCCESS;
    }

    /**
     * Show percentile stats for a specific RUM metric.
     */
    private function rumMetric(): int
    {
        $json = (bool) $this->option('json');
        $metric = (string) $this->option('metric');
        $page = $this->option('page');

        if ($metric === '') {
            $this->error('Specify a metric with --metric= (LCP, FID, CLS, INP, TTFB, FCP)');

            return self::FAILURE;
        }

        $normalizedMetric = strtoupper($metric);

        if (! in_array($normalizedMetric, WebVitalsAggregatorService::ALL_METRICS, true)) {
            $this->error("Invalid metric: {$metric}. Valid: " . implode(', ', WebVitalsAggregatorService::ALL_METRICS));

            return self::FAILURE;
        }

        $stats = $this->rum->percentileStats($normalizedMetric, $page);

        if ($json) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📈 {$normalizedMetric} Percentile Stats (page: " . ($page ?? '__global') . ')');
        $this->info("   Samples: {$stats['count']}");

        if ($stats['count'] === 0) {
            $this->warn('No data available for this metric.');

            return self::SUCCESS;
        }

        $this->line("   Min:  {$stats['min']}");
        $this->line("   p25:  {$stats['p25']}");
        $this->line("   p50:  {$stats['p50']}");
        $this->line("   p75:  {$stats['p75']}");
        $this->line("   p90:  {$stats['p90']}");
        $this->line("   p95:  {$stats['p95']}");
        $this->line("   p99:  {$stats['p99']}");
        $this->line("   Max:  {$stats['max']}");
        $this->line("   Mean: {$stats['mean']}");
        $this->newLine();
        $this->line("   Good: {$stats['good_pct']}% | Needs Improvement: {$stats['needs_improvement_pct']}% | Poor: {$stats['poor_pct']}%");

        return self::SUCCESS;
    }

    /**
     * Show Core Web Vitals pass/fail assessment.
     */
    private function rumAssessment(): int
    {
        $json = (bool) $this->option('json');
        $page = $this->option('page');

        if (! $this->rum->isEnabled()) {
            $this->warn('RUM collection is not enabled.');

            return self::FAILURE;
        }

        $assessment = $this->rum->coreWebVitalsAssessment($page);

        if ($json) {
            $this->line(json_encode($assessment, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🎯 Core Web Vitals Assessment');
        $this->newLine();

        $metrics = [
            'LCP' => $assessment['lcp'],
            'CLS' => $assessment['cls'],
            'INP' => $assessment['inp'],
        ];

        foreach ($metrics as $name => $data) {
            $icon = $data['pass'] ? '✅' : '❌';
            $p75 = $data['p75'] ?? 'no data';
            $this->line("{$icon} {$name}: p75={$p75}");
        }

        $this->newLine();

        if ($assessment['overall_pass']) {
            $this->info('✅ Overall: PASS — All Core Web Vitals thresholds met at p75');
        } else {
            $this->warn('❌ Overall: FAIL — One or more Core Web Vitals exceed thresholds at p75');
        }

        return self::SUCCESS;
    }

    /**
     * Clear RUM data.
     */
    private function rumClear(): int
    {
        $this->rum->clear();
        $this->info('RUM data cleared.');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     *
     * @param  string  $action
     * @return int
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions:');
        $this->line('  inspector:show     Show recent event traces');
        $this->line('  inspector:clear    Clear inspector data');
        $this->line('  inspector:enable   Enable inspector at runtime');
        $this->line('  inspector:disable  Disable inspector at runtime');
        $this->line('  rum:summary        Show RUM dashboard summary');
        $this->line('  rum:metric         Show metric percentile stats (--metric=LCP)');
        $this->line('  rum:assessment     Show Core Web Vitals assessment');
        $this->line('  rum:clear          Clear RUM data');

        return self::FAILURE;
    }
}
