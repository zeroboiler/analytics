<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsDataQualityFirewall;
use ZeroBoiler\Analytics\Services\EventFlowAnalysisService;
use ZeroBoiler\Analytics\Services\ProviderEventCompatibilityMatrix;

/**
 * Analytics flow analysis and data quality command.
 *
 * Provides visibility into:
 * - Event flow analysis (path tracking, funnel drop-off, conversion paths)
 * - Data quality firewall metrics (quarantine/drop rates)
 * - Provider event compatibility matrix (coverage, gaps)
 * - Quality evaluation of sample events
 *
 * Modes: flow, quality, matrix, evaluate, summary
 *
 * @since 46.0.0
 */
final class AnalyticsFlowCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:flow
        {mode=summary : Analysis mode (flow|quality|matrix|evaluate|summary)}
        {--event= : Event name for evaluate mode}
        {--funnel= : Comma-separated funnel steps for drop-off analysis}
        {--steps=3 : Number of steps for top paths}
        {--limit=25 : Maximum results}
        {--provider=ga4 : Provider for matrix gap analysis}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Analyze event flows, data quality, and provider coverage';

    private readonly EventFlowAnalysisService $flowService;

    private readonly AnalyticsDataQualityFirewall $qualityFirewall;

    private readonly ProviderEventCompatibilityMatrix $compatibilityMatrix;

    /**
     * Create a new AnalyticsFlowCommand.
     */
    public function __construct(ConfigRepository $config): void
    {
        parent::__construct();

        $cache = app('cache');
        $this->flowService = new EventFlowAnalysisService($cache, $config);
        $this->qualityFirewall = new AnalyticsDataQualityFirewall($cache, $config);
        $this->compatibilityMatrix = new ProviderEventCompatibilityMatrix($cache, $config);
    }

    /**
     * Execute the console command.
     */
    #[Override]
    #[Override]
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $asJson = $this->option('json');

        return match ($mode) {
            'flow' => $this->handleFlow($asJson),
            'quality' => $this->handleQuality($asJson),
            'matrix' => $this->handleMatrix($asJson),
            'evaluate' => $this->handleEvaluate($asJson),
            'summary' => $this->handleSummary($asJson),
            default => $this->invalidMode($mode),
        };
    }

    /**
     * Handle flow analysis mode.
     */
    private function handleFlow(bool $asJson): int
    {
        $metrics = $this->flowService->getMetrics();

        if (! $metrics['enabled']) {
            $this->warn('Event flow analysis is disabled. Enable it in config: zeroboiler.analytics.event_flow.enabled');

            return self::SUCCESS;
        }

        // Top paths
        $steps = (int) $this->option('steps');
        $limit = (int) $this->option('limit');
        $topPaths = $this->flowService->topPaths($steps, $limit);

        // Funnel drop-off if specified
        $funnelStr = $this->option('funnel');
        $funnelResult = null;
        if ($funnelStr !== null) {
            $funnelSteps = explode(',', $funnelStr);
            $funnelSteps = array_map('trim', $funnelSteps);
            $funnelResult = $this->flowService->funnelDropOff($funnelSteps);
        }

        $output = [
            'mode' => 'flow',
            'metrics' => $metrics,
            'top_paths' => $topPaths,
        ];

        if ($funnelResult !== null) {
            $output['funnel_drop_off'] = $funnelResult;
        }

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Event Flow Analysis ===');
        $this->table(
            ['Metric', 'Value'],
            array_map(
                fn (string $k, mixed $v): array => [$k, is_array($v) ? json_encode($v) : (string) $v],
                array_keys($metrics),
                $metrics,
            ),
        );

        if (! empty($topPaths)) {
            $this->newLine();
            $this->info("Top {$steps}-step paths:");
            $this->table(
                ['Path', 'Count', '%'],
                array_map(
                    fn (array $p): array => [$p['path'], $p['count'], $p['percentage'] . '%'],
                    $topPaths,
                ),
            );
        }

        if ($funnelResult !== null) {
            $this->newLine();
            $this->info('Funnel Drop-Off:');
            $this->table(
                ['Step', 'Count', 'Drop-Off', 'Drop Rate', 'Conversion Rate'],
                array_map(
                    fn (array $s): array => [$s['step'], $s['count'], $s['drop_off'], $s['drop_off_rate'] . '%', $s['conversion_rate'] . '%'],
                    $funnelResult['steps'],
                ),
            );
            $this->line("Total conversion: {$funnelResult['total_conversion']}%");
        }

        return self::SUCCESS;
    }

    /**
     * Handle data quality mode.
     */
    private function handleQuality(bool $asJson): int
    {
        $metrics = $this->qualityFirewall->getMetrics();
        $summary = $this->qualityFirewall->summary();

        $output = [
            'mode' => 'quality',
            'metrics' => $metrics,
            'config' => $summary,
        ];

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Data Quality Firewall ===');
        $status = $metrics['enabled'] ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>';
        $this->line("Status: {$status}");

        $this->table(
            ['Metric', 'Value'],
            [
                ['Events evaluated', $metrics['evaluated']],
                ['Events passed', $metrics['passed']],
                ['Events quarantined', $metrics['quarantined']],
                ['Events dropped', $metrics['dropped']],
                ['Quarantine threshold', $metrics['quarantine_threshold']],
                ['Drop threshold', $metrics['drop_threshold']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Handle provider matrix mode.
     */
    private function handleMatrix(bool $asJson): int
    {
        $provider = $this->option('provider');
        $limit = (int) $this->option('limit');

        $summary = $this->compatibilityMatrix->summary();
        $coverage = $this->compatibilityMatrix->getProviderCoverage();
        $readiness = $this->compatibilityMatrix->getReadinessScores();
        $gaps = $this->compatibilityMatrix->getGapRecommendations((string) $provider, $limit);

        $output = [
            'mode' => 'matrix',
            'summary' => $summary,
            'coverage' => $coverage,
            'readiness' => $readiness,
            "gaps_for_{$provider}" => $gaps,
        ];

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Provider Event Compatibility Matrix ===');

        $this->newLine();
        $this->info('Provider Coverage:');
        $this->table(
            ['Provider', 'Mapped', 'Total', 'Coverage %', 'Unmapped'],
            array_map(
                fn (string $p, array $d): array => [
                    $p,
                    $d['mapped_count'],
                    $d['total_events'],
                    $d['coverage_pct'] . '%',
                    count($d['unmapped']),
                ],
                array_keys($coverage),
                $coverage,
            ),
        );

        $this->newLine();
        $this->info('Readiness Scores:');
        $this->table(
            ['Provider', 'Score', 'Coverage', 'Specificity', 'Category'],
            array_map(
                fn (string $p, array $d): array => [
                    $p,
                    $d['score'] . '/100',
                    $d['coverage_weight'],
                    $d['specificity_weight'],
                    $d['category_weight'],
                ],
                array_keys($readiness['scores']),
                $readiness['scores'],
            ),
        );

        $this->line("Recommendation: {$readiness['recommendation']}");

        if (! empty($gaps)) {
            $this->newLine();
            $this->info("Gap Recommendations for {$provider}:");
            $this->table(
                ['Event', 'Category', 'Priority', 'Missing Providers'],
                array_map(
                    fn (array $g): array => [
                        $g['event'],
                        $g['category'],
                        $g['priority'],
                        implode(', ', $g['missing_providers']),
                    ],
                    $gaps,
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Handle evaluate mode — evaluate a single event's quality.
     */
    private function handleEvaluate(bool $asJson): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->error('Please specify an event name with --event=');

            return self::FAILURE;
        }

        $event = new AnalyticsEvent((string) $eventName, ['test_param' => 'value']);
        $result = $this->qualityFirewall->evaluate($event);

        $output = [
            'mode' => 'evaluate',
            'event' => $eventName,
            'result' => $result,
        ];

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("=== Quality Evaluation: {$eventName} ===");
        $this->line("Score: {$result['score']}");
        $dispositionColors = [
            'pass' => 'green',
            'quarantine' => 'yellow',
            'drop' => 'red',
        ];
        $color = $dispositionColors[$result['disposition']] ?? 'white';
        $this->line("Disposition: <fg={$color}>{$result['disposition']}</>");

        if (! empty($result['violations'])) {
            $this->table(
                ['Rule', 'Severity', 'Message'],
                array_map(
                    fn (array $v): array => [$v['rule'], $v['severity'], $v['message']],
                    $result['violations'],
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Handle summary mode — overview of all three services.
     */
    private function handleSummary(bool $asJson): int
    {
        $output = [
            'version' => '46.0.0',
            'flow' => $this->flowService->summary(),
            'quality' => $this->qualityFirewall->summary(),
            'matrix' => $this->compatibilityMatrix->summary(),
            'catalog' => [
                'total_events' => EventCatalog::count(),
                'categories' => array_keys(EventCatalog::byCategory()),
            ],
        ];

        if ($asJson) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Analytics Flow & Quality Summary (v46.0.0) ===');
        $this->newLine();

        $this->info('Event Flow Analysis:');
        $flow = $output['flow'];
        $this->line('  Enabled: ' . ($flow['enabled'] ? 'Yes' : 'No'));
        $this->line("  Max path length: {$flow['max_path_length']}");
        $this->line("  Path TTL: {$flow['path_ttl']}s");

        $this->newLine();
        $this->info('Data Quality Firewall:');
        $quality = $output['quality'];
        $this->line('  Enabled: ' . ($quality['enabled'] ? 'Yes' : 'No'));
        $this->line("  Quarantine threshold: {$quality['quarantine_threshold']}");
        $this->line("  Drop threshold: {$quality['drop_threshold']}");

        $this->newLine();
        $this->info('Provider Compatibility Matrix:');
        $matrix = $output['matrix'];
        $this->line('  Enabled: ' . ($matrix['enabled'] ? 'Yes' : 'No'));
        $this->line("  Providers: " . implode(', ', $matrix['providers']));
        $this->line("  Catalog size: {$matrix['catalog_size']} events");

        $this->newLine();
        $this->info('Catalog:');
        $this->line("  Total events: {$output['catalog']['total_events']}");
        $this->line('  Categories: ' . implode(', ', $output['catalog']['categories']));

        return self::SUCCESS;
    }

    /**
     * Handle invalid mode.
     */
    private function invalidMode(string $mode): int
    {
        $this->error("Invalid mode: {$mode}");
        $this->line('Available modes: flow, quality, matrix, evaluate, summary');

        return self::FAILURE;
    }
}
