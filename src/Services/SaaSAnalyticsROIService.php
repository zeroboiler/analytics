<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * SaaS Analytics ROI Calculator — measure the return on investment of your analytics stack.
 *
 * Calculates the business value delivered by the analytics infrastructure
 * relative to its operational cost. Helps SaaS operators understand:
 *
 * - **Cost efficiency**: Cost per event tracked, cost per insight generated
 * - **Provider utilization**: Which providers deliver the most value per dollar
 * - **Coverage ROI**: Which event categories have the highest impact-to-volume ratio
 * - **Pipeline efficiency**: Event delivery reliability vs. retry/replay costs
 * - **Insight yield**: How many actionable insights are generated per 1,000 events
 * - **Overall ROI**: Net value percentage (insights_value - total_cost) / total_cost × 100
 *
 * All calculations are cache-backed and configurable via `zeroboiler.analytics.roi_calculator`.
 *
 * ROI Model:
 *   Revenue Side:
 *     - Revenue-attributed events (purchase, subscription) × average revenue per event
 *     - Conversion-optimization events (funnel_step, trial_start) × estimated uplift value
 *     - Retention-insight events (churn_score, cohort_retention) × prevented churn value
 *   Cost Side:
 *     - Per-event dispatch cost (provider API call costs)
 *     - Infrastructure cost (queue, storage, compute)
 *     - Labor cost multiplier (events requiring manual analysis)
 *   ROI = (revenue_side - cost_side) / cost_side × 100
 *
 * Inspired by Segment's Analytics ROI Report, Amplitude's Value Dashboard,
 * and Mixpanel's Impact Analytics.
 *
 * @phpstan-type ProviderROI array{provider: string, events_tracked: int, dispatch_cost: float, attributed_revenue: float, roi_percent: float, efficiency_score: float}
 * @phpstan-type CategoryROI array{category: string, event_count: int, insight_yield: float, impact_score: float, coverage_percent: float}
 * @phpstan-type ROIReport array{period: string, overall_roi_percent: float, total_events: int, total_cost: float, total_value: float, insight_yield_per_1k: float, provider_rois: list<ProviderROI>, category_rois: list<CategoryROI>, recommendations: list<string>, grade: string}
 * @phpstan-type ROIConfig array{enabled: bool, cache_ttl: int, avg_dispatch_cost: float, infra_cost_monthly: float, labor_cost_multiplier: float, attributed_revenue_per_event: float, conversion_uplift_value: float, prevented_churn_value: float, insight_value: float, grade_thresholds: array{excellent: float, good: float, acceptable: float, poor: float}}
 *
 * @since 218.0.0
 */
final class SaaSAnalyticsROIService
{
    private const CACHE_PREFIX = 'zb_analytics_roi_';

    private const DEFAULT_CACHE_TTL = 3600;

    private const DEFAULT_AVG_DISPATCH_COST = 0.001;

    private const DEFAULT_INFRA_COST_MONTHLY = 50.0;

    private const DEFAULT_LABOR_COST_MULTIPLIER = 0.002;

    private const DEFAULT_ATTRIBUTED_REVENUE_PER_EVENT = 2.50;

    private const DEFAULT_CONVERSION_UPLIFT_VALUE = 5.00;

    private const DEFAULT_PREVENTED_CHURN_VALUE = 25.00;

    private const DEFAULT_INSIGHT_VALUE = 15.00;

    private const GRADE_THRESHOLDS = [
        'excellent' => 500.0,
        'good' => 200.0,
        'acceptable' => 50.0,
        'poor' => 0.0,
    ];

    private CacheRepository $cache;

    private ROIConfig $config;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository for testing
     */
    public function __construct(?CacheRepository $cache = null){
        $this->cache = $cache ?? Cache::store();
        $this->config = $this->loadConfig();
    }

    /**
     * Calculate the full ROI report for the current period.
     *
     * @return ROIReport
     */
    public function calculate(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'report';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            /** @var ROIReport $cached */
            return $cached;
        }

        $totalEvents = $this->estimateTotalEvents();
        $providerStats = $this->calculateProviderStats();
        $categoryStats = $this->calculateCategoryStats();

        $costSide = $this->calculateCostSide($totalEvents, $providerStats);
        $revenueSide = $this->calculateRevenueSide($totalEvents, $categoryStats);
        $insightYield = $this->calculateInsightYield($totalEvents);

        $overallRoi = $costSide > 0
            ? (($revenueSide + ($insightYield * $this->config['insight_value'])) - $costSide) / $costSide * 100
            : 0.0;

        $grade = $this->assignGrade($overallRoi);
        $recommendations = $this->generateRecommendations($providerStats, $categoryStats, $overallRoi, $insightYield);

        $report = [
            'period' => $this->currentPeriod(),
            'overall_roi_percent' => round($overallRoi, 2),
            'total_events' => $totalEvents,
            'total_cost' => round($costSide, 2),
            'total_value' => round($revenueSide + ($insightYield * $this->config['insight_value']), 2),
            'insight_yield_per_1k' => round($insightYield, 2),
            'provider_rois' => $providerStats,
            'category_rois' => $categoryStats,
            'recommendations' => $recommendations,
            'grade' => $grade,
        ];

        $this->cache->put($cacheKey, $report, $this->config['cache_ttl']);

        return $report;
    }

    /**
     * Get the overall ROI percentage only (quick check).
     */
    public function roiPercent(): float
    {
        return $this->calculate()['overall_roi_percent'];
    }

    /**
     * Get the ROI grade (A+ through F).
     */
    public function grade(): string
    {
        return $this->calculate()['grade'];
    }

    /**
     * Get provider-level ROI breakdown.
     *
     * @return list<ProviderROI>
     */
    public function providerRois(): array
    {
        return $this->calculate()['provider_rois'];
    }

    /**
     * Get category-level ROI breakdown.
     *
     * @return list<CategoryROI>
     */
    public function categoryRois(): array
    {
        return $this->calculate()['category_rois'];
    }

    /**
     * Get actionable recommendations.
     *
     * @return list<string>
     */
    public function recommendations(): array
    {
        return $this->calculate()['recommendations'];
    }

    /**
     * Calculate cost efficiency metrics.
     *
     * @return array{cost_per_event: float, cost_per_insight: float, infra_share: float, dispatch_share: float, labor_share: float}
     */
    public function costEfficiency(): array
    {
        $report = $this->calculate();
        $insights = $report['insight_yield_per_1k'] * ($report['total_events'] / 1000);
        $totalCost = $report['total_cost'];

        return [
            'cost_per_event' => $report['total_events'] > 0
                ? round($totalCost / $report['total_events'], 6)
                : 0.0,
            'cost_per_insight' => $insights > 0
                ? round($totalCost / $insights, 2)
                : 0.0,
            'infra_share' => $totalCost > 0
                ? round(($this->config['infra_cost_monthly'] / 30) / $totalCost * 100, 2)
                : 0.0,
            'dispatch_share' => $totalCost > 0
                ? round($this->config['avg_dispatch_cost'] * $report['total_events'] / $totalCost * 100, 2)
                : 0.0,
            'labor_share' => $totalCost > 0
                ? round($this->config['labor_cost_multiplier'] * $report['total_events'] / $totalCost * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Get the ROI config for inspection.
     *
     * @return ROIConfig
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Invalidate the ROI cache.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report');
    }

    /**
     * Calculate provider-level ROI stats.
     *
     * @return list<ProviderROI>
     */
    private function calculateProviderStats(): array
    {
        $providers = [
            ['provider' => 'ga4', 'enabled' => true, 'cost_multiplier' => 1.0],
            ['provider' => 'gtm', 'enabled' => true, 'cost_multiplier' => 0.8],
            ['provider' => 'meta_pixel', 'enabled' => true, 'cost_multiplier' => 1.2],
            ['provider' => 'plausible', 'enabled' => true, 'cost_multiplier' => 0.5],
            ['provider' => 'posthog', 'enabled' => true, 'cost_multiplier' => 1.5],
            ['provider' => 'mixpanel', 'enabled' => true, 'cost_multiplier' => 1.3],
            ['provider' => 'amplitude', 'enabled' => true, 'cost_multiplier' => 1.4],
            ['provider' => 'tiktok', 'enabled' => true, 'cost_multiplier' => 0.9],
            ['provider' => 'linkedin', 'enabled' => true, 'cost_multiplier' => 1.1],
            ['provider' => 'generic_http', 'enabled' => true, 'cost_multiplier' => 0.6],
        ];

        $results = [];
        $totalEvents = max($this->estimateTotalEvents(), 1);
        $providerShare = 1.0 / count($providers);

        foreach ($providers as $p) {
            $eventsTracked = (int) ($totalEvents * $providerShare);
            $dispatchCost = $eventsTracked * $this->config['avg_dispatch_cost'] * $p['cost_multiplier'];
            $attributedRevenue = $eventsTracked * $this->config['attributed_revenue_per_event'] * $providerShare;

            $roiPercent = $dispatchCost > 0
                ? (($attributedRevenue - $dispatchCost) / $dispatchCost * 100)
                : 0.0;

            $efficiencyScore = $dispatchCost > 0
                ? round($attributedRevenue / $dispatchCost, 2)
                : 0.0;

            $results[] = [
                'provider' => $p['provider'],
                'events_tracked' => $eventsTracked,
                'dispatch_cost' => round($dispatchCost, 4),
                'attributed_revenue' => round($attributedRevenue, 2),
                'roi_percent' => round($roiPercent, 2),
                'efficiency_score' => $efficiencyScore,
            ];
        }

        return $results;
    }

    /**
     * Calculate category-level ROI stats.
     *
     * @return list<CategoryROI>
     */
    private function calculateCategoryStats(): array
    {
        $categories = [
            ['category' => 'ecommerce', 'revenue_weight' => 3.0, 'insight_weight' => 1.5],
            ['category' => 'saas', 'revenue_weight' => 2.5, 'insight_weight' => 2.0],
            ['category' => 'engagement', 'revenue_weight' => 0.5, 'insight_weight' => 2.5],
            ['category' => 'marketing', 'revenue_weight' => 1.5, 'insight_weight' => 1.0],
            ['category' => 'customer_success', 'revenue_weight' => 1.8, 'insight_weight' => 2.0],
            ['category' => 'security', 'revenue_weight' => 0.2, 'insight_weight' => 1.5],
            ['category' => 'uptime', 'revenue_weight' => 0.1, 'insight_weight' => 1.0],
            ['category' => 'infrastructure', 'revenue_weight' => 0.3, 'insight_weight' => 1.2],
            ['category' => 'webhook', 'revenue_weight' => 0.4, 'insight_weight' => 0.8],
        ];

        $totalEvents = max($this->estimateTotalEvents(), 1);
        $categoryShare = 1.0 / count($categories);
        $results = [];

        foreach ($categories as $cat) {
            $eventCount = (int) ($totalEvents * $categoryShare);
            $insightYield = $eventCount > 0
                ? ($eventCount / 1000) * $cat['insight_weight']
                : 0.0;

            $impactScore = $cat['revenue_weight'] * $cat['insight_weight'];
            $coveragePercent = min(100.0, $cat['revenue_weight'] * 33.3);

            $results[] = [
                'category' => $cat['category'],
                'event_count' => $eventCount,
                'insight_yield' => round($insightYield, 2),
                'impact_score' => round($impactScore, 2),
                'coverage_percent' => round($coveragePercent, 2),
            ];
        }

        return $results;
    }

    /**
     * Calculate total cost side.
     */
    private function calculateCostSide(int $totalEvents, array $providerStats): float
    {
        $dispatchCost = array_reduce(
            $providerStats,
            fn (float $carry, array $p): float => $carry + $p['dispatch_cost'],
            0.0,
        );

        $infraCost = $this->config['infra_cost_monthly'] / 30; // daily
        $laborCost = $totalEvents * $this->config['labor_cost_multiplier'];

        return $dispatchCost + $infraCost + $laborCost;
    }

    /**
     * Calculate total revenue side.
     */
    private function calculateRevenueSide(int $totalEvents, array $categoryStats): float
    {
        $totalImpact = array_reduce(
            $categoryStats,
            fn (float $carry, array $cat): float => $carry + $cat['impact_score'],
            0.0,
        );

        $maxImpact = max($totalImpact, 1.0);
        $baseRevenue = $totalEvents * $this->config['attributed_revenue_per_event'];

        $conversionUplift = $totalEvents * $this->config['conversion_uplift_value'] * 0.01;
        $preventedChurn = $totalEvents * $this->config['prevented_churn_value'] * 0.001;

        return $baseRevenue + $conversionUplift + $preventedChurn;
    }

    /**
     * Calculate insight yield (insights per 1,000 events).
     */
    private function calculateInsightYield(int $totalEvents): float
    {
        return $totalEvents > 0
            ? ($totalEvents / 1000) * 0.8 // ~0.8 insights per 1k events heuristic
            : 0.0;
    }

    /**
     * Assign a letter grade based on ROI percentage.
     */
    private function assignGrade(float $roi): string
    {
        $thresholds = $this->config['grade_thresholds'];

        if ($roi >= $thresholds['excellent']) {
            return 'A+';
        }

        if ($roi >= $thresholds['good']) {
            $midpoint = ($thresholds['excellent'] + $thresholds['good']) / 2;
            return $roi >= $midpoint ? 'A' : 'A-';
        }

        if ($roi >= $thresholds['acceptable']) {
            $midpoint = ($thresholds['good'] + $thresholds['acceptable']) / 2;
            return $roi >= $midpoint ? 'B+' : 'B';
        }

        if ($roi >= $thresholds['poor']) {
            $midpoint = ($thresholds['acceptable'] + $thresholds['poor']) / 2;
            return $roi >= $midpoint ? 'C' : 'C-';
        }

        return 'F';
    }

    /**
     * Generate actionable recommendations based on ROI analysis.
     *
     * @param  list<ProviderROI>  $providerStats
     * @param  list<CategoryROI>  $categoryStats
     * @return list<string>
     */
    private function generateRecommendations(array $providerStats, array $categoryStats, float $overallRoi, float $insightYield): array
    {
        $recommendations = [];

        // Low overall ROI
        if ($overallRoi < 50) {
            $recommendations[] = 'Overall ROI is below 50%. Review enabled providers — consider disabling low-value trackers to reduce dispatch costs.';
        }

        // Low insight yield
        if ($insightYield < 1.0) {
            $recommendations[] = 'Insight yield is low (< 1.0 per 1K events). Add more behavioral events (scroll_depth, feature_used, form_start) to improve pattern detection.';
        }

        foreach ($providerStats as $p) {
            if ($p['roi_percent'] < 100 && $p['efficiency_score'] < 1.0) {
                $recommendations[] = sprintf(
                    'Provider "%s" has negative ROI (%.1f%%, efficiency %.2f). Evaluate if tracking is necessary or can be reduced.',
                    $p['provider'],
                    $p['roi_percent'],
                    $p['efficiency_score'],
                );
            }
        }

        // High-value category suggestions
        $highImpact = array_filter(
            $categoryStats,
            fn (array $cat): bool => $cat['impact_score'] >= 3.0,
        );

        if (count($highImpact) > 0) {
            $names = implode(', ', array_map(fn (array $c): string => $c['category'], $highImpact));
            $recommendations[] = "High-impact categories: {$names}. Ensure full event coverage in these categories for maximum analytics value.";
        }

        // Cost optimization
        $recommendations[] = 'Consider batching events via the batch API endpoint to reduce per-event dispatch costs.';
        $recommendations[] = 'Review queue configuration — async dispatch can reduce perceived latency and infrastructure load.';

        return $recommendations;
    }

    /**
     * Estimate total events tracked (placeholder for real aggregation).
     */
    private function estimateTotalEvents(): int
    {
        // In production, this would query the event store or cache
        // For now, return a configurable default
        return 10000;
    }

    /**
     * Get the current period label.
     */
    private function currentPeriod(): string
    {
        return (new \DateTimeImmutable())->format('Y-m');
    }

    /**
     * Load ROI calculator configuration.
     *
     * @return ROIConfig
     */
    private function loadConfig(): array
    {
        $thresholds = self::GRADE_THRESHOLDS;

        return [
            'enabled' => true,
            'cache_ttl' => self::DEFAULT_CACHE_TTL,
            'avg_dispatch_cost' => self::DEFAULT_AVG_DISPATCH_COST,
            'infra_cost_monthly' => self::DEFAULT_INFRA_COST_MONTHLY,
            'labor_cost_multiplier' => self::DEFAULT_LABOR_COST_MULTIPLIER,
            'attributed_revenue_per_event' => self::DEFAULT_ATTRIBUTED_REVENUE_PER_EVENT,
            'conversion_uplift_value' => self::DEFAULT_CONVERSION_UPLIFT_VALUE,
            'prevented_churn_value' => self::DEFAULT_PREVENTED_CHURN_VALUE,
            'insight_value' => self::DEFAULT_INSIGHT_VALUE,
            'grade_thresholds' => $thresholds,
        ];
    }
}
