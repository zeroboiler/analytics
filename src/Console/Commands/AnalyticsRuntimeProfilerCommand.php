<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\AnalyticsEventBuffer;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

/**
 * Analytics Runtime Pipeline Profiler Command.
 *
 * Sends a synthetic test event through the full analytics dispatch pipeline
 * and measures latency at each stage:
 *
 *   1. Event DTO construction
 *   2. Middleware stack processing (PII sanitization, sampling, consent, enrichment)
 *   3. Event dispatcher routing (per-provider dispatch)
 *   4. Queue dispatch (if enabled)
 *   5. Server-side tracker auto-tracking
 *
 * Reports per-stage timing, total pipeline latency, and identifies
 * the slowest stage. Useful for:
 * - Production performance baselining
 * - Identifying pipeline bottlenecks
 * - Validating queue vs sync dispatch overhead
 * - CI performance regression detection
 *
 * Options:
 *   --iterations=N   Run N iterations and report averages (default: 1)
 *   --json           Output as JSON for programmatic consumption
 *   --warmup=N       Warm-up iterations (not measured, default: 0)
 *
 * @see \ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 154.0.0
 */
final class AnalyticsRuntimeProfilerCommand extends Command
{
    protected $signature = 'zb:analytics:profile
        {--iterations=1 : Number of profiling iterations}
        {--json : Output as JSON}
        {--warmup=0 : Warm-up iterations (not measured)}';

    protected $description = 'Profile the analytics dispatch pipeline — measure per-stage latency';

    private AnalyticsManager $manager;

    private ConfigRepository $config;

    /** @var list<array{iteration: int, stages: array<string, float>, total_ms: float}> */
    private array $runs = [];

    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        parent::__construct();
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Execute the pipeline profiling.
     */
    #[Override]
    public function handle(): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));
        $outputJson = (bool) $this->option('json');

        if (! $outputJson) {
            $this->info('⏱️  ZeroBoiler Analytics — Runtime Pipeline Profiler');
            $this->line('   Version: ' . AnalyticsEvent::VERSION);
            $this->newLine();
        }

        // Warm-up iterations
        if ($warmup > 0) {
            if (! $outputJson) {
                $this->line("  Warming up ({$warmup} iteration" . ($warmup > 1 ? 's' : '') . ')...');
            }

            for ($i = 0; $i < $warmup; $i++) {
                $this->runSingleIteration(-1);
            }
        }

        // Measured iterations
        for ($i = 0; $i < $iterations; $i++) {
            $this->runSingleIteration($i + 1);
        }

        // Report
        if ($outputJson) {
            $this->line(json_encode($this->buildReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->outputReport();

        return self::SUCCESS;
    }

    /**
     * Run a single profiling iteration.
     *
     * @param int $iteration 1-based iteration number, or -1 for warm-up
     */
    private function runSingleIteration(int $iteration): void
    {
        $stages = [];

        // Stage 1: Event DTO Construction
        $start = hrtime(true);
        $event = new AnalyticsEvent(
            name: 'zb_profile_test',
            params: [
                'profile_iteration' => $iteration,
                'profile_timestamp' => time(),
                'source' => 'runtime_profiler',
                'test_param_1' => 'value_1',
                'test_param_2' => 42,
                'test_param_3' => true,
            ],
            category: 'engagement',
        );
        $stages['dto_construction'] = $this->elapsedMs($start);

        // Stage 2: Manager dispatch (sync path through middleware + providers)
        $start = hrtime(true);
        $this->manager->trackEvent($event);
        $stages['manager_dispatch'] = $this->elapsedMs($start);

        // Stage 3: Direct track() call (measures facade overhead)
        $start = hrtime(true);
        $this->manager->track('zb_profile_direct', ['source' => 'profiler_direct']);
        $stages['direct_track'] = $this->elapsedMs($start);

        // Stage 4: Identify + track combined
        $start = hrtime(true);
        $this->manager->identify('profiler_user_' . $iteration);
        $this->manager->track('zb_profile_identified', ['source' => 'profiler_after_identify']);
        $stages['identify_and_track'] = $this->elapsedMs($start);

        // Stage 5: Page view (common high-frequency event)
        $start = hrtime(true);
        $this->manager->trackPageView('/profiler/test', 'Profiler Test Page');
        $stages['page_view'] = $this->elapsedMs($start);

        // Stage 6: Purchase (complex multi-param event)
        $start = hrtime(true);
        $this->manager->purchase(
            'profiler_txn_' . $iteration,
            99.99,
            [
                [
                    'item_id' => 'SKU-PROFILE-001',
                    'item_name' => 'Profiler Widget',
                    'price' => 49.99,
                    'quantity' => 2,
                    'item_category' => 'test',
                ],
            ],
        );
        $stages['purchase_event'] = $this->elapsedMs($start);

        // Total
        $totalMs = array_sum($stages);

        if ($iteration > 0) {
            $this->runs[] = [
                'iteration' => $iteration,
                'stages' => $stages,
                'total_ms' => $totalMs,
            ];
        }
    }

    /**
     * Calculate elapsed milliseconds from a high-resolution nanosecond timestamp.
     */
    private function elapsedMs(int $hrTime): float
    {
        return round((hrtime(true) - $hrTime) / 1_000_000, 3);
    }

    /**
     * Build the profiling report data.
     *
     * @return array{iterations: int, runs: list<array{iteration: int, stages: array<string, float>, total_ms: float}>, averages: array<string, float>, min: array<string, float>, max: array<string, float>, slowest_stage: string, fastest_stage: string, config: array<string, mixed>}
     */
    private function buildReport(): array
    {
        $stageNames = [
            'dto_construction',
            'manager_dispatch',
            'direct_track',
            'identify_and_track',
            'page_view',
            'purchase_event',
        ];

        $averages = [];
        $min = [];
        $max = [];

        foreach ($stageNames as $stage) {
            $values = array_column(array_map(fn (array $r): array => $r['stages'], $this->runs), $stage);
            $values = array_filter($values, fn (mixed $v): bool => $v !== null);

            if ($values !== []) {
                $averages[$stage] = round(array_sum($values) / count($values), 3);
                $min[$stage] = round(min($values), 3);
                $max[$stage] = round(max($values), 3);
            }
        }

        $slowestStage = array_keys($averages, max($averages))[0] ?? 'unknown';
        $fastestStage = array_keys($averages, min($averages))[0] ?? 'unknown';

        return [
            'iterations' => count($this->runs),
            'runs' => $this->runs,
            'averages' => $averages,
            'min' => $min,
            'max' => $max,
            'slowest_stage' => $slowestStage,
            'fastest_stage' => $fastestStage,
            'config' => [
                'queue_enabled' => (bool) $this->config->get('zeroboiler.analytics.queue.enabled', false),
                'sampling_enabled' => (bool) $this->config->get('zeroboiler.analytics.sampling.enabled', false),
                'sanitization_enabled' => (bool) $this->config->get('zeroboiler.analytics.sanitization.enabled', false),
                'dedup_enabled' => (bool) $this->config->get('zeroboiler.analytics.dedup_cache.enabled', false),
                'enabled_providers' => $this->getEnabledProviders(),
            ],
        ];
    }

    /**
     * Get the list of currently enabled providers.
     *
     * @return list<string>
     */
    private function getEnabledProviders(): array
    {
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
        $enabled = [];

        foreach ($providers as $p) {
            if ((bool) $this->config->get("zeroboiler.analytics.{$p}.enabled", false)) {
                $enabled[] = $p;
            }
        }

        return $enabled;
    }

    /**
     * Output the profiling report to the console.
     */
    private function outputReport(): void
    {
        if ($this->runs === []) {
            $this->warn('No profiling data collected.');

            return;
        }

        $report = $this->buildReport();

        // Per-run results
        if (count($this->runs) === 1) {
            $run = $this->runs[0];
            $this->section('Pipeline Latency (Single Run)');
            $this->outputStages($run['stages']);
            $this->newLine();
            $this->line('  <fg=cyan>Total Pipeline:</> <fg=white>' . $run['total_ms'] . ' ms</>');
        } else {
            // Summary across iterations
            $this->section('Pipeline Latency Averages (' . $report['iterations'] . ' iterations)');

            foreach ($report['averages'] as $stage => $avg) {
                $minVal = $report['min'][$stage] ?? 0;
                $maxVal = $report['max'][$stage] ?? 0;
                $bar = str_repeat('█', (int) min($avg * 2, 40));
                $label = $this->formatStageLabel($stage);
                $this->line("  {$label}  <fg=cyan>{$avg:>8.3f}</> ms  <fg=gray>(min: {$minVal}, max: {$maxVal})</>  {$bar}");
            }

            $totalAvg = array_sum($report['averages']);
            $this->newLine();
            $this->line("  <fg=cyan>Total Average:</> <fg=white>{$totalAvg} ms</>");
            $this->newLine();

            // Slowest / Fastest
            $this->line("  <fg=red>Slowest Stage:</> <fg=white>{$this->formatStageLabel($report['slowest_stage'])} ({$report['averages'][$report['slowest_stage']]} ms)</>");
            $this->line("  <fg=green>Fastest Stage:</> <fg=white>{$this->formatStageLabel($report['fastestStage'])} ({$report['averages'][$report['fastestStage']]} ms)</>");
        }

        // Config context
        $this->newLine();
        $this->section('Configuration Context');
        $cfg = $report['config'];
        $this->line('  Queue:        ' . ($cfg['queue_enabled'] ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));
        $this->line('  Sampling:     ' . ($cfg['sampling_enabled'] ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));
        $this->line('  Sanitization: ' . ($cfg['sanitization_enabled'] ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));
        $this->line('  Dedup:        ' . ($cfg['dedup_enabled'] ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));
        $providers = $cfg['enabled_providers'];
        $providerStr = $providers !== [] ? implode(', ', $providers) : '<fg=yellow>none</>';
        $this->line("  Providers:    {$providerStr}");
    }

    /**
     * Output a single run's stages.
     *
     * @param array<string, float> $stages
     */
    private function outputStages(array $stages): void
    {
        $total = array_sum($stages);

        foreach ($stages as $stage => $ms) {
            $pct = $total > 0 ? round(($ms / $total) * 100, 1) : 0;
            $bar = str_repeat('█', (int) min($ms * 2, 40));
            $label = $this->formatStageLabel($stage);
            $this->line("  {$label}  <fg=cyan>{$ms:>8.3f}</> ms  <fg=gray>({$pct}%)</>  {$bar}");
        }
    }

    /**
     * Format a stage name for display.
     */
    private function formatStageLabel(string $stage): string
    {
        return match ($stage) {
            'dto_construction' => 'DTO Construction   ',
            'manager_dispatch' => 'Manager Dispatch    ',
            'direct_track' => 'Direct Track()      ',
            'identify_and_track' => 'Identify + Track    ',
            'page_view' => 'Page View          ',
            'purchase_event' => 'Purchase Event      ',
            default => str_pad($stage, 20),
        };
    }

    /**
     * Print a section header.
     */
    private function section(string $title): void
    {
        $this->line("<fg=blue;options=bold>{$title}</>");
    }
}
