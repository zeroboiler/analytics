<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Cohort Waterfall Analysis Service — revenue flow decomposition by cohort.
 *
 * Visualizes how revenue flows through each cohort stage:
 * Signed Up → Trial → Converted → Active → Renewing → Churned.
 *
 * For each cohort period (week/month), computes:
 * - Entry count (new signups/trial starts)
 * - Stage conversion rates (trial→paid, paid→renewed)
 * - Revenue at each stage (MRR contribution)
 * - Drop-off amounts and rates between stages
 * - Net revenue retention per cohort
 *
 * Produces a waterfall-style data structure suitable for chart rendering
 * (bar + waterfall chart combos in dashboard frontends).
 *
 * Inspired by cohort analysis patterns used by ChartMogul, ProfitWell,
 * and Baremetrics revenue waterfall reports.
 *
 * Configuration: `zeroboiler.analytics.cohort_waterfall`
 *
 * @since 7.5.0
 */
final class CohortWaterfallService
{
    private const CACHE_PREFIX = 'zb_cohort_wf_';

    private const DEFAULT_CACHE_TTL = 600; // 10 minutes

    /** @var list<string> */
    private const DEFAULT_STAGES = [
        'signed_up',
        'trial_started',
        'trial_converted',
        'active',
        'renewing',
        'expansion',
        'contraction',
        'churned',
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private string $defaultGranularity;

    private string $currency;

    private int $projectionMonths;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $wfConfig = $config->get('zeroboiler.analytics.cohort_waterfall', []);
        /** @var array{enabled?: bool, cache_ttl?: int, granularity?: string, currency?: string, projection_months?: int} $wfConfig */
        $this->enabled = (bool) ($wfConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($wfConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->defaultGranularity = (string) ($wfConfig['granularity'] ?? 'monthly');
        $this->currency = (string) ($wfConfig['currency'] ?? 'USD');
        $this->projectionMonths = (int) ($wfConfig['projection_months'] ?? 6);
    }

    /**
     * Generate a full cohort waterfall report.
     *
     * @param  array{cohorts?: array<string, array{entered?: int, trial_starts?: int, conversions?: int, active?: int, renewals?: int, expansions?: float, contractions?: float, churned?: int, churned_mrr?: float, mrr?: float}>, period?: string}  $data  Cohort data keyed by period
     * @return array{generated_at: string, currency: string, granularity: string, stages: list<string>, cohorts: array<string, array<string, mixed>>, summary: array<string, mixed>, insights: list<string>, stage_averages: array<string, mixed>}
     */
    public function report(array $data = []): array
    {
        if (! $this->enabled) {
            return $this->disabledReport();
        }

        $cohorts = $data['cohorts'] ?? [];
        $granularity = $data['period'] ?? $this->defaultGranularity;
        $stages = self::DEFAULT_STAGES;

        $report = [
            'generated_at' => date('c'),
            'currency' => $this->currency,
            'granularity' => $granularity,
            'stages' => $stages,
            'cohorts' => [],
            'summary' => [],
            'insights' => [],
            'stage_averages' => [],
        ];

        $stageAggregates = array_fill_keys($stages, [
            'total_count' => 0,
            'total_revenue' => 0.0,
            'count' => 0,
        ]);

        foreach ($cohorts as $period => $cohort) {
            $waterfall = $this->buildCohortWaterfall($period, $cohort, $stages);
            $report['cohorts'][$period] = $waterfall;

            // Aggregate stage-level stats
            foreach ($stages as $stage) {
                if (isset($waterfall['stages'][$stage])) {
                    $stageAggregates[$stage]['total_count'] += $waterfall['stages'][$stage]['count'] ?? 0;
                    $stageAggregates[$stage]['total_revenue'] += $waterfall['stages'][$stage]['revenue'] ?? 0.0;
                    $stageAggregates[$stage]['count']++;
                }
            }
        }

        // Compute stage averages
        foreach ($stages as $stage) {
            $agg = $stageAggregates[$stage];
            $report['stage_averages'][$stage] = [
                'avg_count' => $agg['count'] > 0
                    ? round($agg['total_count'] / $agg['count'], 2)
                    : 0,
                'avg_revenue' => $agg['count'] > 0
                    ? round($agg['total_revenue'] / $agg['count'], 2)
                    : 0.0,
                'total_count' => $agg['total_count'],
                'total_revenue' => round($agg['total_revenue'], 2),
            ];
        }

        // Summary
        $totalEntries = array_sum(array_map(
            fn (array $c): int => (int) ($c['entered'] ?? 0),
            $cohorts,
        ));

        $totalConversions = array_sum(array_map(
            fn (array $c): int => (int) ($c['conversions'] ?? 0),
            $cohorts,
        ));

        $totalChurned = array_sum(array_map(
            fn (array $c): int => (int) ($c['churned'] ?? 0),
            $cohorts,
        ));

        $totalMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['mrr'] ?? 0),
            $cohorts,
        ));

        $totalExpansionMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['expansions'] ?? 0),
            $cohorts,
        ));

        $totalContractionMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['contractions'] ?? 0),
            $cohorts,
        ));

        $totalChurnedMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['churned_mrr'] ?? 0),
            $cohorts,
        ));

        $report['summary'] = [
            'total_cohorts' => count($cohorts),
            'total_entries' => $totalEntries,
            'total_conversions' => $totalConversions,
            'total_churned' => $totalChurned,
            'overall_conversion_rate' => $totalEntries > 0
                ? round(($totalConversions / $totalEntries) * 100, 2)
                : 0.0,
            'overall_churn_rate' => $totalConversions > 0
                ? round(($totalChurned / $totalConversions) * 100, 2)
                : 0.0,
            'total_mrr' => round($totalMRR, 2),
            'net_mrr_movement' => round($totalExpansionMRR - $totalContractionMRR - $totalChurnedMRR, 2),
            'expansion_mrr' => round($totalExpansionMRR, 2),
            'contraction_mrr' => round($totalContractionMRR, 2),
            'churned_mrr' => round($totalChurnedMRR, 2),
            'nrr' => $totalMRR > 0
                ? round((($totalMRR + $totalExpansionMRR - $totalContractionMRR - $totalChurnedMRR) / $totalMRR) * 100, 2)
                : 0.0,
        ];

        // Generate insights
        $report['insights'] = $this->generateInsights($report['summary'], $report['stage_averages']);

        return $report;
    }

    /**
     * Build a waterfall data structure for a single cohort period.
     *
     * Each stage shows: count, revenue, drop-off from previous stage,
     * and conversion rate from entry to current stage.
     *
     * @param  string  $period  Cohort period identifier (e.g., '2026-08')
     * @param  array{entered?: int, trial_starts?: int, conversions?: int, active?: int, renewals?: int, expansions?: float, contractions?: float, churned?: int, churned_mrr?: float, mrr?: float}  $cohort  Raw cohort data
     * @param  list<string>  $stages  Ordered list of waterfall stages
     * @return array{period: string, stages: array<string, array{count: int, revenue: float, drop_off_count: int, drop_off_rate: float, cumulative_rate: float}>}
     */
    private function buildCohortWaterfall(string $period, array $cohort, array $stages): array
    {
        $waterfall = [
            'period' => $period,
            'stages' => [],
        ];

        $entered = (int) ($cohort['entered'] ?? 0);

        $previousCount = $entered;
        $stageMap = [
            'signed_up' => ['count' => (int) ($cohort['entered'] ?? 0), 'revenue' => 0.0],
            'trial_started' => ['count' => (int) ($cohort['trial_starts'] ?? 0), 'revenue' => 0.0],
            'trial_converted' => ['count' => (int) ($cohort['conversions'] ?? 0), 'revenue' => 0.0],
            'active' => ['count' => (int) ($cohort['active'] ?? 0), 'revenue' => (float) ($cohort['mrr'] ?? 0)],
            'renewing' => ['count' => (int) ($cohort['renewals'] ?? 0), 'revenue' => (float) ($cohort['mrr'] ?? 0)],
            'expansion' => ['count' => 0, 'revenue' => (float) ($cohort['expansions'] ?? 0)],
            'contraction' => ['count' => 0, 'revenue' => (float) ($cohort['contractions'] ?? 0)],
            'churned' => ['count' => (int) ($cohort['churned'] ?? 0), 'revenue' => (float) ($cohort['churned_mrr'] ?? 0)],
        ];

        foreach ($stages as $stage) {
            $data = $stageMap[$stage] ?? ['count' => 0, 'revenue' => 0.0];
            $count = $data['count'];
            $revenue = $data['revenue'];

            $dropOff = max(0, $previousCount - $count);
            $dropOffRate = $previousCount > 0
                ? round(($dropOff / $previousCount) * 100, 2)
                : 0.0;

            $cumulativeRate = $entered > 0
                ? round(($count / $entered) * 100, 2)
                : 0.0;

            $waterfall['stages'][$stage] = [
                'count' => $count,
                'revenue' => round($revenue, 2),
                'drop_off_count' => $dropOff,
                'drop_off_rate' => $dropOffRate,
                'cumulative_rate' => $cumulativeRate,
            ];

            $previousCount = $count;
        }

        return $waterfall;
    }

    /**
     * Generate actionable insights from the waterfall summary.
     *
     * @param  array{total_cohorts: int, total_entries: int, total_conversions: int, total_churned: int, overall_conversion_rate: float, overall_churn_rate: float, total_mrr: float, net_mrr_movement: float, expansion_mrr: float, contraction_mrr: float, churned_mrr: float, nrr: float}  $summary
     * @param  array<string, array{avg_count: float, avg_revenue: float}>  $stageAverages
     * @return list<string>
     */
    private function generateInsights(array $summary, array $stageAverages): array
    {
        $insights = [];

        // Conversion rate insight
        if ($summary['overall_conversion_rate'] < 25) {
            $insights[] = sprintf(
                'Low trial-to-paid conversion (%.1f%%). Industry benchmark is 25-60%%.',
                $summary['overall_conversion_rate'],
            );
        } elseif ($summary['overall_conversion_rate'] > 60) {
            $insights[] = sprintf(
                'Excellent trial-to-paid conversion (%.1f%%). Top-decile SaaS performance.',
                $summary['overall_conversion_rate'],
            );
        }

        // Churn rate insight
        if ($summary['overall_churn_rate'] > 5) {
            $insights[] = sprintf(
                'High churn rate (%.1f%%). Target: < 3%% monthly for healthy SaaS.',
                $summary['overall_churn_rate'],
            );
        } elseif ($summary['overall_churn_rate'] < 2) {
            $insights[] = sprintf(
                'Very low churn rate (%.1f%%). Indicates strong product-market fit.',
                $summary['overall_churn_rate'],
            );
        }

        // NRR insight
        if ($summary['nrr'] < 100) {
            $insights[] = sprintf(
                'Net Revenue Retention below 100%% (%.1f%%). Expansion revenue is insufficient to offset churn.',
                $summary['nrr'],
            );
        } elseif ($summary['nrr'] >= 120) {
            $insights[] = sprintf(
                'Exceptional NRR (%.1f%%). Top-quartile SaaS companies average 120%%+.',
                $summary['nrr'],
            );
        }

        // Net MRR movement
        if ($summary['net_mrr_movement'] < 0) {
            $insights[] = sprintf(
                'Negative net MRR movement (%.2f). Revenue is declining — investigate churn drivers.',
                $summary['net_mrr_movement'],
            );
        } elseif ($summary['net_mrr_movement'] > 0 && $summary['total_mrr'] > 0) {
            $growthPct = round(($summary['net_mrr_movement'] / $summary['total_mrr']) * 100, 1);
            $insights[] = sprintf(
                'Positive MRR growth of %.1f%%. Expansion exceeds churn + contraction.',
                $growthPct,
            );
        }

        // Expansion vs contraction ratio
        if ($summary['contraction_mrr'] > 0) {
            $ratio = round($summary['expansion_mrr'] / $summary['contraction_mrr'], 2);
            if ($ratio < 1.0) {
                $insights[] = sprintf(
                    'Expansion/contraction ratio is %.2f (below 1.0). Contractions outpace upsells.',
                    $ratio,
                );
            }
        }

        return $insights;
    }

    /**
     * Get a quick cohort waterfall summary (lightweight, no per-cohort detail).
     *
     * @param  array{cohorts?: array<string, array{entered?: int, conversions?: int, churned?: int, mrr?: float, expansions?: float, contractions?: float, churned_mrr?: float}>}  $data
     * @return array{total_entries: int, total_conversions: int, total_churned: int, conversion_rate: float, churn_rate: float, nrr: float, net_mrr_movement: float, generated_at: string}
     */
    public function quickSummary(array $data = []): array
    {
        $cohorts = $data['cohorts'] ?? [];

        $totalEntries = array_sum(array_map(
            fn (array $c): int => (int) ($c['entered'] ?? 0),
            $cohorts,
        ));
        $totalConversions = array_sum(array_map(
            fn (array $c): int => (int) ($c['conversions'] ?? 0),
            $cohorts,
        ));
        $totalChurned = array_sum(array_map(
            fn (array $c): int => (int) ($c['churned'] ?? 0),
            $cohorts,
        ));
        $totalMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['mrr'] ?? 0),
            $cohorts,
        ));
        $totalExpansion = array_sum(array_map(
            fn (array $c): float => (float) ($c['expansions'] ?? 0),
            $cohorts,
        ));
        $totalContraction = array_sum(array_map(
            fn (array $c): float => (float) ($c['contractions'] ?? 0),
            $cohorts,
        ));
        $totalChurnedMRR = array_sum(array_map(
            fn (array $c): float => (float) ($c['churned_mrr'] ?? 0),
            $cohorts,
        ));

        $nrr = $totalMRR > 0
            ? round((($totalMRR + $totalExpansion - $totalContraction - $totalChurnedMRR) / $totalMRR) * 100, 2)
            : 0.0;

        return [
            'total_entries' => $totalEntries,
            'total_conversions' => $totalConversions,
            'total_churned' => $totalChurned,
            'conversion_rate' => $totalEntries > 0
                ? round(($totalConversions / $totalEntries) * 100, 2)
                : 0.0,
            'churn_rate' => $totalConversions > 0
                ? round(($totalChurned / $totalConversions) * 100, 2)
                : 0.0,
            'nrr' => $nrr,
            'net_mrr_movement' => round($totalExpansion - $totalContraction - $totalChurnedMRR, 2),
            'generated_at' => date('c'),
        ];
    }

    /**
     * Compare two cohort periods' waterfall data side-by-side.
     *
     * @param  array{entered?: int, trial_starts?: int, conversions?: int, active?: int, renewals?: int, expansions?: float, contractions?: float, churned?: int, churned_mrr?: float, mrr?: float}  $cohortA
     * @param  array{entered?: int, trial_starts?: int, conversions?: int, active?: int, renewals?: int, expansions?: float, contractions?: float, churned?: int, churned_mrr?: float, mrr?: float}  $cohortB
     * @return array{period_a: string|null, period_b: string|null, comparison: array<string, array{a: int|float, b: int|float, delta: int|float, delta_pct: float}>}
     */
    public function compare(array $cohortA, array $cohortB): array
    {
        $stages = self::DEFAULT_STAGES;
        $stageMapA = $this->extractStageValues($cohortA);
        $stageMapB = $this->extractStageValues($cohortB);

        $comparison = [];
        foreach ($stages as $stage) {
            $a = $stageMapA[$stage] ?? 0;
            $b = $stageMapB[$stage] ?? 0;
            $delta = $b - $a;
            $deltaPct = $a != 0 ? round(($delta / abs($a)) * 100, 2) : ($b > 0 ? 100.0 : 0.0);

            $comparison[$stage] = [
                'a' => is_float($a) ? round($a, 2) : $a,
                'b' => is_float($b) ? round($b, 2) : $b,
                'delta' => is_float($delta) ? round($delta, 2) : $delta,
                'delta_pct' => $deltaPct,
            ];
        }

        return [
            'period_a' => $cohortA['period'] ?? null,
            'period_b' => $cohortB['period'] ?? null,
            'comparison' => $comparison,
        ];
    }

    /**
     * Extract stage-level count/revenue values from a cohort data array.
     *
     * @param  array{entered?: int, trial_starts?: int, conversions?: int, active?: int, renewals?: int, expansions?: float, contractions?: float, churned?: int, churned_mrr?: float, mrr?: float}  $cohort
     * @return array<string, int|float>
     */
    private function extractStageValues(array $cohort): array
    {
        return [
            'signed_up' => (int) ($cohort['entered'] ?? 0),
            'trial_started' => (int) ($cohort['trial_starts'] ?? 0),
            'trial_converted' => (int) ($cohort['conversions'] ?? 0),
            'active' => (int) ($cohort['active'] ?? 0),
            'renewing' => (int) ($cohort['renewals'] ?? 0),
            'expansion' => (float) ($cohort['expansions'] ?? 0),
            'contraction' => (float) ($cohort['contractions'] ?? 0),
            'churned' => (int) ($cohort['churned'] ?? 0),
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the default waterfall stages.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        return self::DEFAULT_STAGES;
    }

    /**
     * Generate a disabled report stub.
     *
     * @return array{generated_at: string, enabled: false, stages: list<string>, cohorts: array<empty>, summary: array<empty, empty>, insights: list<string>, stage_averages: array<empty, empty>}
     */
    private function disabledReport(): array
    {
        return [
            'generated_at' => date('c'),
            'enabled' => false,
            'stages' => self::DEFAULT_STAGES,
            'cohorts' => [],
            'summary' => [],
            'insights' => ['Cohort waterfall analysis is disabled.'],
            'stage_averages' => [],
        ];
    }
}
