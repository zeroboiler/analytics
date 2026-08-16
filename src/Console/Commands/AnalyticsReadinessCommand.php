<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\SaaSReadinessAssessment;
use ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions;

/**
 * Displays a comprehensive SaaS analytics readiness assessment.
 *
 * Evaluates 7 dimensions of analytics instrumentation quality:
 * event coverage, provider coverage, funnel readiness, AARRR
 * coverage, identity tracking, e-commerce readiness, and
 * configuration quality.
 *
 * @since 101.0.0
 */
final class AnalyticsReadinessCommand extends Command
{
    protected $signature = 'zb:analytics:readiness
        {--json : Output as JSON}
        {--recommendations : Show only top recommendations}
        {--funnels : Show funnel coverage details}
        {--dimension=* : Assess specific dimensions only}';

    protected $description = 'Display SaaS analytics readiness assessment with actionable recommendations';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $showRecommendations = (bool) $this->option('recommendations');
        $showFunnels = (bool) $this->option('funnels');
        $specificDimensions = (array) $this->option('dimension');

        $report = $this->buildReport();

        if ($outputJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Header
        $this->newLine();
        $this->line('  <options=bold;bg=blue;fg=white> 📊 SaaS Analytics Readiness Assessment </>');
        $this->line('  <fg=cyan>ZeroBoiler Analytics v' . $report['version'] . '</>');
        $this->newLine();

        // Overall score
        $grade = $report['overall_grade'];
        $gradeColor = match ($grade) {
            'A' => 'green',
            'B' => 'blue',
            'C' => 'yellow',
            'D' => 'red',
            default => 'red',
        };
        $score = $report['overall_score'];

        $this->line("  Overall Score: <options=bold;fg={$gradeColor}>{$score}/100</> (Grade: <options=bold;fg={$gradeColor}>{$grade}</>)");
        $this->line("  Tracked Events: {$report['tracked_count']}/{$report['total_catalog_events']} ({$report['coverage_percent']}%)");
        $this->newLine();

        if ($showRecommendations) {
            $this->showRecommendations($report);

            return self::SUCCESS;
        }

        if ($showFunnels) {
            $this->showFunnelCoverage();

            return self::SUCCESS;
        }

        // Dimension scores
        $this->line('  <options=bold>Dimension Scores:</options>');
        $this->line('  ─────────────────────────────────────────────────────');

        $dimensions = $report['dimensions'];

        if ($specificDimensions !== []) {
            $dimensions = array_filter(
                $dimensions,
                fn (string $key): bool => in_array($key, $specificDimensions, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        foreach ($dimensions as $key => $dimension) {
            $statusIcon = match ($dimension['status']) {
                'excellent' => '<fg=green>✓</>',
                'good' => '<fg=green>✓</>',
                'fair' => '<fg=yellow>⚠</>',
                'poor' => '<fg=red>✗</>',
                'missing' => '<fg=red>✗</>',
            };

            $percent = $dimension['percent'];
            $percentColor = match (true) {
                $percent >= 90.0 => 'green',
                $percent >= 70.0 => 'blue',
                $percent >= 50.0 => 'yellow',
                default => 'red',
            };

            $bar = $this->renderBar($percent);
            $this->line("  {$statusIcon} <fg=white>{$dimension['name']}</>: <options=bold;fg={$percentColor}>{$percent}%</> {$bar}");

            // Show key findings
            foreach ($dimension['findings'] as $finding) {
                $this->line("      <fg=gray>→ {$finding}</>");
            }
        }

        $this->newLine();

        // Top recommendations
        $assessment = new SaaSReadinessAssessment;
        $recs = $assessment->topRecommendations(3);

        if ($recs !== []) {
            $this->line('  <options=bold>Top Recommendations:</options>');
            $this->line('  ─────────────────────────────────────────────────────');

            foreach ($recs as $i => $rec) {
                $impactColor = match ($rec['impact']) {
                    'high' => 'red',
                    'medium' => 'yellow',
                    default => 'gray',
                };
                $num = $i + 1;
                $this->line("  <fg=white>{$num}.</> [{$rec['impact']}] <fg=white>{$rec['action']}</>");
            }

            $this->newLine();
        }

        // Timestamp
        $this->line("  <fg=gray>Generated: {$report['generated_at']}</>");

        return self::SUCCESS;
    }

    /**
     * Show only recommendations.
     */
    private function showRecommendations(array $report): void
    {
        $assessment = new SaaSReadinessAssessment;
        $recs = $assessment->topRecommendations(10);

        $this->line("  Overall: <options=bold>{$report['overall_score']}/100</> (Grade: {$report['overall_grade']})");
        $this->newLine();

        if ($recs === []) {
            $this->line('  <fg=green>✓ No recommendations — your analytics are fully instrumented!</>');

            return;
        }

        foreach ($recs as $i => $rec) {
            $impactColor = match ($rec['impact']) {
                'high' => 'red',
                'medium' => 'yellow',
                default => 'gray',
            };
            $num = $i + 1;
            $this->line("  <fg=white>{$num}.</> <fg={$impactColor}>[{$rec['impact']}]</> <fg=white>{$rec['action']}</>");
            $this->line("      <fg=gray>Dimension: {$rec['description']}</>");
        }
    }

    /**
     * Show funnel coverage details.
     */
    private function showFunnelCoverage(): void
    {
        $coverage = SaaSFunnelDefinitions::coverageReport([]);

        $this->line('  <options=bold>Funnel Definitions Coverage:</options>');
        $this->line('  ─────────────────────────────────────────────────────');
        $this->line('  <fg=gray>Note: No tracked events provided. Showing required events for each funnel.</>');
        $this->newLine();

        foreach ($coverage as $key => $funnel) {
            $statusIcon = match ($funnel['status']) {
                'complete' => '<fg=green>✓</>',
                'partial' => '<fg=yellow>⚠</>',
                default => '<fg=red>○</>',
            };

            $this->line("  {$statusIcon} <fg=white>{$funnel['funnel']}</> ({$key})");
            $this->line("      Steps: {$funnel['total_steps']}, Required events: " . implode(', ', $funnel['missing_events']));
        }
    }

    /**
     * Build the assessment report.
     */
    private function buildReport(): array
    {
        $assessment = new SaaSReadinessAssessment;

        return $assessment->assess();
    }

    /**
     * Render a visual progress bar.
     */
    private function renderBar(float $percent): string
    {
        $width = 20;
        $filled = (int) round(($percent / 100.0) * $width);

        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);

        $color = match (true) {
            $percent >= 90.0 => 'green',
            $percent >= 70.0 => 'blue',
            $percent >= 50.0 => 'yellow',
            default => 'red',
        };

        return "<fg={$color}>{$bar}</>";
    }
}
