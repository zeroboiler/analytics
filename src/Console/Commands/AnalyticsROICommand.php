<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsROIService;

/**
 * Analytics ROI Calculator Command — measure the return on investment of your analytics stack.
 *
 * Usage:
 *   php artisan zb:analytics:roi              # Full ROI report
 *   php artisan zb:analytics:roi --score    # Quick ROI score + grade
 *   php artisan zb:analytics:roi --providers # Provider-level ROI breakdown
 *   php artisan zb:analytics:roi --categories # Category-level ROI breakdown
 *   php artisan zb:analytics:roi --efficiency # Cost efficiency metrics
 *   php artisan zb:analytics:roi --recommendations # Actionable recommendations only
 *   php artisan zb:analytics:roi --json     # JSON output for any action
 *   php artisan zb:analytics:roi --invalidate # Clear cache and recompute
 *
 * @since 218.0.0
 */
final class AnalyticsROICommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:roi
        {--score : Quick ROI score and grade only}
        {--providers : Provider-level ROI breakdown}
        {--categories : Category-level ROI breakdown}
        {--efficiency : Cost efficiency metrics}
        {--recommendations : Actionable recommendations only}
        {--json : Output as JSON}
        {--invalidate : Clear cache and recompute}';

    /** @var string */
    protected $description = 'Calculate analytics stack ROI — cost efficiency, provider utilization, and insight yield';

    private SaaSAnalyticsROIService $service;

    public function __construct(SaaSAnalyticsROIService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        if ($this->option('invalidate')) {
            $this->service->invalidateCache();
            $this->components->info('ROI cache invalidated. Recomputing...');
        }

        $asJson = $this->option('json');

        if ($this->option('score')) {
            return $this->showScore($asJson);
        }

        if ($this->option('providers')) {
            return $this->showProviders($asJson);
        }

        if ($this->option('categories')) {
            return $this->showCategories($asJson);
        }

        if ($this->option('efficiency')) {
            return $this->showEfficiency($asJson);
        }

        if ($this->option('recommendations')) {
            return $this->showRecommendations($asJson);
        }

        return $this->showFullReport($asJson);
    }

    /**
     * Show full ROI report.
     */
    private function showFullReport(bool $asJson): int
    {
        $report = $this->service->calculate();

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info("📊 Analytics ROI Report — {$report['period']}");

        // Header
        $this->newLine();
        $this->line("  Overall ROI:  <fg=green;options=bold>{$report['overall_roi_percent']}%</>");
        $this->line("  Grade:        <fg=green;options=bold>{$report['grade']}</>");
        $this->line('  Total Events: ' . ($report['total_events']));
        $this->line("  Total Cost:   \${$report['total_cost']}");
        $this->line("  Total Value:  \${$report['total_value']}");
        $this->line("  Insight Yield: {$report['insight_yield_per_1k']} per 1K events");

        // Provider ROI table
        $this->newLine();
        $this->components->info('Provider ROI Breakdown');
        $headers = ['Provider', 'Events', 'Dispatch Cost', 'Attributed Rev.', 'ROI %', 'Efficiency'];
        $rows = array_map(
            fn (array $p): array => [
                $p['provider'],
                number_format($p['events_tracked']),
                '$' . number_format($p['dispatch_cost'], 4),
                '$' . number_format($p['attributed_revenue'], 2),
                number_format($p['roi_percent'], 1) . '%',
                number_format($p['efficiency_score'], 2),
            ],
            $report['provider_rois'],
        );
        $this->table($headers, $rows);

        // Category ROI table
        $this->newLine();
        $this->components->info('Category ROI Breakdown');
        $headers = ['Category', 'Events', 'Insight Yield', 'Impact Score', 'Coverage %'];
        $rows = array_map(
            fn (array $c): array => [
                $c['category'],
                number_format($c['event_count']),
                number_format($c['insight_yield'], 2),
                number_format($c['impact_score'], 2),
                number_format($c['coverage_percent'], 1) . '%',
            ],
            $report['category_rois'],
        );
        $this->table($headers, $rows);

        // Recommendations
        $this->newLine();
        $this->components->info('Recommendations');
        foreach ($report['recommendations'] as $i => $rec) {
            $this->line("  <fg=yellow>" . ($i + 1) . ".</> {$rec}");
        }

        return self::SUCCESS;
    }

    /**
     * Show quick score only.
     */
    private function showScore(bool $asJson): int
    {
        $roi = $this->service->roiPercent();
        $grade = $this->service->grade();

        if ($asJson) {
            $this->line(json_encode([
                'roi_percent' => $roi,
                'grade' => $grade,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line("ROI: {$roi}% — Grade: {$grade}");

        return self::SUCCESS;
    }

    /**
     * Show provider ROI breakdown.
     */
    private function showProviders(bool $asJson): int
    {
        $providers = $this->service->providerRois();

        if ($asJson) {
            $this->line(json_encode($providers, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $headers = ['Provider', 'Events', 'Dispatch Cost', 'Attributed Rev.', 'ROI %', 'Efficiency'];
        $rows = array_map(
            fn (array $p): array => [
                $p['provider'],
                number_format($p['events_tracked']),
                '$' . number_format($p['dispatch_cost'], 4),
                '$' . number_format($p['attributed_revenue'], 2),
                number_format($p['roi_percent'], 1) . '%',
                number_format($p['efficiency_score'], 2),
            ],
            $providers,
        );
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show category ROI breakdown.
     */
    private function showCategories(bool $asJson): int
    {
        $categories = $this->service->categoryRois();

        if ($asJson) {
            $this->line(json_encode($categories, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $headers = ['Category', 'Events', 'Insight Yield', 'Impact Score', 'Coverage %'];
        $rows = array_map(
            fn (array $c): array => [
                $c['category'],
                number_format($c['event_count']),
                number_format($c['insight_yield'], 2),
                number_format($c['impact_score'], 2),
                number_format($c['coverage_percent'], 1) . '%',
            ],
            $categories,
        );
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show cost efficiency metrics.
     */
    private function showEfficiency(bool $asJson): int
    {
        $efficiency = $this->service->costEfficiency();

        if ($asJson) {
            $this->line(json_encode($efficiency, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line("Cost per Event:    \${$efficiency['cost_per_event']}");
        $this->line("Cost per Insight:  \${$efficiency['cost_per_insight']}");
        $this->line("Infrastructure:    {$efficiency['infra_share']}% of total cost");
        $this->line("Dispatch:          {$efficiency['dispatch_share']}% of total cost");
        $this->line("Labor:             {$efficiency['labor_share']}% of total cost");

        return self::SUCCESS;
    }

    /**
     * Show recommendations only.
     */
    private function showRecommendations(bool $asJson): int
    {
        $recs = $this->service->recommendations();

        if ($asJson) {
            $this->line(json_encode($recs, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($recs as $i => $rec) {
            $this->line("  " . ($i + 1) . ". {$rec}");
        }

        return self::SUCCESS;
    }
}
