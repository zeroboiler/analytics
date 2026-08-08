<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS metrics benchmarking engine with industry-standard thresholds.
 *
 * Provides percentile-based benchmarks for 20+ key SaaS metrics,
 * enabling teams to compare their metrics against industry averages
 * and identify areas for improvement.
 *
 * Benchmarks are sourced from industry research (OpenView, KeyBanc,
 * ProfitWell, Tomasz Tunguz, David Sacks) and updated periodically.
 * Config-driven overrides allow custom benchmarks per product tier.
 *
 * Configuration: `zeroboiler.analytics.benchmarks`
 *
 * @see \ZeroBoiler\Analytics\Services\SaaSHealthScoreService
 */
final class SaaSMetricsBenchmarkService
{
    private const CACHE_PREFIX = 'zb_benchmarks_';

    private const DEFAULT_CACHE_TTL = 43200; // 12 hours

    private bool $enabled;

    private readonly int $cacheTtl;

    private readonly string $industry;

    private readonly int $companyStage;

    private readonly CacheRepository $cache;

    /**
     * Industry benchmark data keyed by metric name.
     *
     * Each benchmark contains p25 (poor), p50 (average), p75 (good),
     * p90 (excellent) percentiles from industry data.
     *
     * @var array<string, array{label: string, unit: string, p25: float, p50: float, p75: float, p90: float, direction: 'higher_better'|'lower_better', category: string}>
     */
    private const BENCHMARKS = [
        // ── Revenue Metrics ────────────────────────────────────────
        'mrr_growth_rate' => [
            'label' => 'MRR Growth Rate',
            'unit' => '%',
            'p25' => 5.0, 'p50' => 10.0, 'p75' => 15.0, 'p90' => 25.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],
        'net_revenue_retention' => [
            'label' => 'Net Revenue Retention',
            'unit' => '%',
            'p25' => 90.0, 'p50' => 105.0, 'p75' => 115.0, 'p90' => 130.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],
        'arpu' => [
            'label' => 'ARPU',
            'unit' => '$',
            'p25' => 25.0, 'p50' => 60.0, 'p75' => 150.0, 'p90' => 500.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],
        'ltv' => [
            'label' => 'Lifetime Value (LTV)',
            'unit' => '$',
            'p25' => 200.0, 'p50' => 800.0, 'p75' => 2000.0, 'p90' => 5000.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],
        'ltv_cac_ratio' => [
            'label' => 'LTV:CAC Ratio',
            'unit' => 'x',
            'p25' => 1.0, 'p50' => 3.0, 'p75' => 5.0, 'p90' => 8.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],
        'cac_payback_months' => [
            'label' => 'CAC Payback Period',
            'unit' => 'months',
            'p25' => 24.0, 'p50' => 15.0, 'p75' => 9.0, 'p90' => 5.0,
            'direction' => 'lower_better',
            'category' => 'revenue',
        ],
        'gross_margin' => [
            'label' => 'Gross Margin',
            'unit' => '%',
            'p25' => 55.0, 'p50' => 65.0, 'p75' => 75.0, 'p90' => 85.0,
            'direction' => 'higher_better',
            'category' => 'revenue',
        ],

        // ── Conversion Metrics ──────────────────────────────────────
        'trial_conversion_rate' => [
            'label' => 'Trial Conversion Rate',
            'unit' => '%',
            'p25' => 10.0, 'p50' => 25.0, 'p75' => 40.0, 'p90' => 60.0,
            'direction' => 'higher_better',
            'category' => 'conversion',
        ],
        'signup_to_activation_rate' => [
            'label' => 'Signup → Activation Rate',
            'unit' => '%',
            'p25' => 20.0, 'p50' => 40.0, 'p75' => 60.0, 'p90' => 80.0,
            'direction' => 'higher_better',
            'category' => 'conversion',
        ],
        'demo_to_trial_rate' => [
            'label' => 'Demo → Trial Rate',
            'unit' => '%',
            'p25' => 15.0, 'p50' => 30.0, 'p75' => 50.0, 'p90' => 70.0,
            'direction' => 'higher_better',
            'category' => 'conversion',
        ],
        'pricing_page_conversion' => [
            'label' => 'Pricing Page Conversion',
            'unit' => '%',
            'p25' => 2.0, 'p50' => 5.0, 'p75' => 10.0, 'p90' => 20.0,
            'direction' => 'higher_better',
            'category' => 'conversion',
        ],

        // ── Retention Metrics ──────────────────────────────────────
        'monthly_churn_rate' => [
            'label' => 'Monthly Churn Rate',
            'unit' => '%',
            'p25' => 8.0, 'p50' => 4.0, 'p75' => 2.0, 'p90' => 0.5,
            'direction' => 'lower_better',
            'category' => 'retention',
        ],
        'annual_churn_rate' => [
            'label' => 'Annual Churn Rate',
            'unit' => '%',
            'p25' => 65.0, 'p50' => 40.0, 'p75' => 20.0, 'p90' => 5.0,
            'direction' => 'lower_better',
            'category' => 'retention',
        ],
        'logo_churn_rate' => [
            'label' => 'Logo (Customer) Churn Rate',
            'unit' => '%',
            'p25' => 6.0, 'p50' => 3.0, 'p75' => 1.5, 'p90' => 0.5,
            'direction' => 'lower_better',
            'category' => 'retention',
        ],
        'd1_retention' => [
            'label' => 'Day-1 Retention',
            'unit' => '%',
            'p25' => 20.0, 'p50' => 40.0, 'p75' => 60.0, 'p90' => 80.0,
            'direction' => 'higher_better',
            'category' => 'retention',
        ],
        'd7_retention' => [
            'label' => 'Day-7 Retention',
            'unit' => '%',
            'p25' => 10.0, 'p50' => 25.0, 'p75' => 45.0, 'p90' => 65.0,
            'direction' => 'higher_better',
            'category' => 'retention',
        ],
        'd30_retention' => [
            'label' => 'Day-30 Retention',
            'unit' => '%',
            'p25' => 5.0, 'p50' => 15.0, 'p75' => 30.0, 'p90' => 50.0,
            'direction' => 'higher_better',
            'category' => 'retention',
        ],

        // ── Engagement Metrics ─────────────────────────────────────
        'dau_mau_ratio' => [
            'label' => 'DAU/MAU Ratio (Stickiness)',
            'unit' => '%',
            'p25' => 10.0, 'p50' => 20.0, 'p75' => 40.0, 'p90' => 60.0,
            'direction' => 'higher_better',
            'category' => 'engagement',
        ],
        'feature_adoption_rate' => [
            'label' => 'Feature Adoption Rate',
            'unit' => '%',
            'p25' => 15.0, 'p50' => 35.0, 'p75' => 55.0, 'p90' => 75.0,
            'direction' => 'higher_better',
            'category' => 'engagement',
        ],
        'avg_features_per_user' => [
            'label' => 'Avg Features Used per User',
            'unit' => 'count',
            'p25' => 2.0, 'p50' => 5.0, 'p75' => 10.0, 'p90' => 20.0,
            'direction' => 'higher_better',
            'category' => 'engagement',
        ],
        'time_to_value' => [
            'label' => 'Time to Value',
            'unit' => 'minutes',
            'p25' => 60.0, 'p50' => 30.0, 'p75' => 10.0, 'p90' => 3.0,
            'direction' => 'lower_better',
            'category' => 'engagement',
        ],
        'weekly_active_users_ratio' => [
            'label' => 'WAU / Total Users',
            'unit' => '%',
            'p25' => 10.0, 'p50' => 25.0, 'p75' => 50.0, 'p90' => 70.0,
            'direction' => 'higher_better',
            'category' => 'engagement',
        ],

        // ── Funnel Metrics ─────────────────────────────────────────
        'signup_funnel_completion' => [
            'label' => 'Signup Funnel Completion',
            'unit' => '%',
            'p25' => 15.0, 'p50' => 35.0, 'p75' => 55.0, 'p90' => 75.0,
            'direction' => 'higher_better',
            'category' => 'funnel',
        ],
        'onboarding_completion_rate' => [
            'label' => 'Onboarding Completion Rate',
            'unit' => '%',
            'p25' => 20.0, 'p50' => 45.0, 'p75' => 70.0, 'p90' => 90.0,
            'direction' => 'higher_better',
            'category' => 'funnel',
        ],
        'upgrade_rate' => [
            'label' => 'Plan Upgrade Rate',
            'unit' => '%',
            'p25' => 2.0, 'p50' => 5.0, 'p75' => 12.0, 'p90' => 25.0,
            'direction' => 'higher_better',
            'category' => 'funnel',
        ],
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $benchConfig = $config->get('zeroboiler.analytics.benchmarks', []);
        /** @var array{enabled?: bool, cache_ttl?: int, industry?: string, company_stage?: int, overrides?: array<string, array{p25?: float, p50?: float, p75?: float, p90?: float}>} $benchConfig */
        $this->enabled = (bool) ($benchConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($benchConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->industry = (string) ($benchConfig['industry'] ?? 'saas');
        $this->companyStage = (int) ($benchConfig['company_stage'] ?? 0);
    }

    /**
     * Compare a single metric value against its benchmark.
     *
     * Returns the metric's grade (poor/average/good/excellent),
     * percentile rank, gap to next tier, and the benchmark thresholds.
     *
     * @param  string  $metric  Metric name (e.g. 'monthly_churn_rate', 'trial_conversion_rate')
     * @param  float  $value  Current metric value
     * @return array{metric: string, label: string, unit: string, value: float, grade: string, percentile: float, gap: float, direction: string, benchmarks: array{p25: float, p50: float, p75: float, p90: float}, category: string}
     *
     * @throws \InvalidArgumentException If the metric is not a known benchmark
     */
    public function compare(string $metric, float $value): array
    {
        $benchmark = $this->getBenchmark($metric);

        if ($benchmark === null) {
            throw new \InvalidArgumentException("Unknown benchmark metric: {$metric}");
        }

        $percentile = $this->calculatePercentile($value, $benchmark);
        $grade = $this->gradeFromPercentile($percentile);
        $gap = $this->calculateGap($value, $benchmark);

        return [
            'metric' => $metric,
            'label' => $benchmark['label'],
            'unit' => $benchmark['unit'],
            'value' => $value,
            'grade' => $grade,
            'percentile' => round($percentile, 1),
            'gap' => round($gap, 2),
            'direction' => $benchmark['direction'],
            'benchmarks' => [
                'p25' => $benchmark['p25'],
                'p50' => $benchmark['p50'],
                'p75' => $benchmark['p75'],
                'p90' => $benchmark['p90'],
            ],
            'category' => $benchmark['category'],
        ];
    }

    /**
     * Compare multiple metrics against their benchmarks.
     *
     * @param  array<string, float>  $metrics  Key-value map of metric name → value
     * @return array{results: array<string, array{metric: string, label: string, unit: string, value: float, grade: string, percentile: float, gap: float, direction: string, benchmarks: array{p25: float, p50: float, p75: float, p90: float}, category: string}>, summary: array{total: int, excellent: int, good: int, average: int, poor: int, overall_score: float, overall_grade: string, strongest: string|null, weakest: string|null}}
     */
    public function compareBatch(array $metrics): array
    {
        $results = [];
        $grades = ['excellent' => 0, 'good' => 0, 'average' => 0, 'poor' => 0];
        $bestPercentile = -1.0;
        $worstPercentile = 101.0;
        $strongest = null;
        $weakest = null;

        foreach ($metrics as $metric => $value) {
            try {
                $comparison = $this->compare((string) $metric, (float) $value);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $results[$metric] = $comparison;
            $grades[$comparison['grade']]++;

            if ($comparison['percentile'] > $bestPercentile) {
                $bestPercentile = $comparison['percentile'];
                $strongest = $metric;
            }

            if ($comparison['percentile'] < $worstPercentile) {
                $worstPercentile = $comparison['percentile'];
                $weakest = $metric;
            }
        }

        $total = count($results);
        $overallScore = $total > 0
            ? round((
                ($grades['excellent'] * 100) +
                ($grades['good'] * 75) +
                ($grades['average'] * 50) +
                ($grades['poor'] * 25)
            ) / $total, 1)
            : 0.0;

        return [
            'results' => $results,
            'summary' => [
                'total' => $total,
                'excellent' => $grades['excellent'],
                'good' => $grades['good'],
                'average' => $grades['average'],
                'poor' => $grades['poor'],
                'overall_score' => $overallScore,
                'overall_grade' => $this->gradeFromScore($overallScore),
                'strongest' => $strongest,
                'weakest' => $weakest,
            ],
        ];
    }

    /**
     * Get benchmark thresholds for a specific metric.
     *
     * @return array{label: string, unit: string, p25: float, p50: float, p75: float, p90: float, direction: string, category: string}|null
     */
    public function getBenchmark(string $metric): ?array
    {
        $all = $this->allBenchmarks();

        return $all[$metric] ?? null;
    }

    /**
     * Get all available benchmark definitions.
     *
     * @return array<string, array{label: string, unit: string, p25: float, p50: float, p75: float, p90: float, direction: string, category: string}>
     */
    public function allBenchmarks(): array
    {
        return self::BENCHMARKS;
    }

    /**
     * Get all benchmark metric names grouped by category.
     *
     * @return array<string, list<string>>
     */
    public function byCategory(): array
    {
        $groups = [];

        foreach (self::BENCHMARKS as $name => $benchmark) {
            $category = $benchmark['category'];
            $groups[$category][] = $name;
        }

        return $groups;
    }

    /**
     * Get benchmark definitions for a specific category.
     *
     * @return array<string, array{label: string, unit: string, p25: float, p50: float, p75: float, p90: float, direction: string, category: string}>
     */
    public function category(string $category): array
    {
        return array_filter(
            self::BENCHMARKS,
            fn (array $b): bool => $b['category'] === $category,
        );
    }

    /**
     * Get a health report card comparing actual metrics to benchmarks.
     *
     * Produces an actionable report with grades, recommendations,
     * and priority-ranked improvements.
     *
     * @param  array<string, float>  $metrics  Key-value map of metric name → value
     * @return array{score: float, grade: string, metrics: array<string, array{metric: string, label: string, value: float, grade: string, percentile: float, gap: float, recommendation: string}>, priorities: list<string>, summary: string}
     */
    public function reportCard(array $metrics): array
    {
        $comparison = $this->compareBatch($metrics);
        $scoredMetrics = [];

        foreach ($comparison['results'] as $name => $result) {
            $recommendation = $this->generateRecommendation($name, $result);
            $scoredMetrics[$name] = [
                'metric' => $result['metric'],
                'label' => $result['label'],
                'value' => $result['value'],
                'unit' => $result['unit'],
                'grade' => $result['grade'],
                'percentile' => $result['percentile'],
                'gap' => $result['gap'],
                'recommendation' => $recommendation,
            ];
        }

        // Sort by percentile ascending (worst first = highest priority)
        uasort($scoredMetrics, fn (array $a, array $b): int => $a['percentile'] <=> $b['percentile']);

        $priorities = array_keys(array_filter(
            $scoredMetrics,
            fn (array $m): bool => $m['percentile'] < 50.0,
        ));

        $excellentCount = $comparison['summary']['excellent'];
        $poorCount = $comparison['summary']['poor'];
        $total = $comparison['summary']['total'];

        $summary = $total === 0
            ? 'No metrics provided for benchmarking.'
            : sprintf(
                'Overall score: %s (%.0f/100). %d of %d metrics at excellent level, %d need immediate attention.',
                $comparison['summary']['overall_grade'],
                $comparison['summary']['overall_score'],
                $excellentCount,
                $total,
                $poorCount,
            );

        return [
            'score' => $comparison['summary']['overall_score'],
            'grade' => $comparison['summary']['overall_grade'],
            'metrics' => $scoredMetrics,
            'priorities' => array_values($priorities),
            'summary' => $summary,
        ];
    }

    /**
     * Get quick-start benchmark recommendations for a new SaaS product.
     *
     * Returns the 8 most impactful metrics every SaaS should track
     * and benchmark against, with target values for "good" (p75) tier.
     *
     * @return list<array{metric: string, label: string, target: float, unit: string, category: string}>
     */
    public function quickStartMetrics(): array
    {
        $essential = [
            'trial_conversion_rate',
            'monthly_churn_rate',
            'mrr_growth_rate',
            'dau_mau_ratio',
            'signup_to_activation_rate',
            'ltv_cac_ratio',
            'net_revenue_retention',
            'time_to_value',
        ];

        $result = [];

        foreach ($essential as $metric) {
            $benchmark = self::BENCHMARKS[$metric] ?? null;

            if ($benchmark !== null) {
                $result[] = [
                    'metric' => $metric,
                    'label' => $benchmark['label'],
                    'target' => $benchmark['p75'],
                    'unit' => $benchmark['unit'],
                    'category' => $benchmark['category'],
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate the percentile rank of a value against benchmarks.
     *
     * @param  float  $value  The actual metric value
     * @param  array{p25: float, p50: float, p75: float, p90: float, direction: string}  $benchmark
     */
    private function calculatePercentile(float $value, array $benchmark): float
    {
        $isHigherBetter = $benchmark['direction'] === 'higher_better';

        $percentiles = [
            ['threshold' => $benchmark['p25'], 'rank' => 25.0],
            ['threshold' => $benchmark['p50'], 'rank' => 50.0],
            ['threshold' => $benchmark['p75'], 'rank' => 75.0],
            ['threshold' => $benchmark['p90'], 'rank' => 90.0],
        ];

        if ($isHigherBetter) {
            // Higher is better: value above p90 = >90th percentile
            if ($value >= $benchmark['p90']) {
                return 95.0;
            }

            if ($value <= $benchmark['p25']) {
                return 12.5;
            }

            for ($i = count($percentiles) - 1; $i >= 1; $i--) {
                if ($value >= $percentiles[$i - 1]['threshold']) {
                    $lower = $percentiles[$i - 1];
                    $upper = $percentiles[$i];

                    if ($upper['threshold'] === $lower['threshold']) {
                        return $upper['rank'];
                    }

                    $interpolated = $lower['rank'] + (
                        ($value - $lower['threshold']) / ($upper['threshold'] - $lower['threshold'])
                    ) * ($upper['rank'] - $lower['rank']);

                    return round($interpolated, 1);
                }
            }
        } else {
            // Lower is better (churn, payback): value below p90 = >90th percentile
            if ($value <= $benchmark['p90']) {
                return 95.0;
            }

            if ($value >= $benchmark['p25']) {
                return 12.5;
            }

            for ($i = count($percentiles) - 1; $i >= 1; $i--) {
                if ($value <= $percentiles[$i - 1]['threshold']) {
                    $lower = $percentiles[$i - 1];
                    $upper = $percentiles[$i];

                    if ($upper['threshold'] === $lower['threshold']) {
                        return $upper['rank'];
                    }

                    $interpolated = $lower['rank'] + (
                        ($value - $lower['threshold']) / ($upper['threshold'] - $lower['threshold'])
                    ) * ($upper['rank'] - $lower['rank']);

                    return round($interpolated, 1);
                }
            }
        }

        return 50.0;
    }

    /**
     * Determine a grade from a percentile rank.
     */
    private function gradeFromPercentile(float $percentile): string
    {
        return match (true) {
            $percentile >= 75.0 => 'excellent',
            $percentile >= 50.0 => 'good',
            $percentile >= 25.0 => 'average',
            default => 'poor',
        };
    }

    /**
     * Determine a grade from a 0-100 score.
     */
    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 80.0 => 'excellent',
            $score >= 60.0 => 'good',
            $score >= 40.0 => 'average',
            default => 'poor',
        };
    }

    /**
     * Calculate the gap to the next better percentile tier.
     *
     * @param  float  $value  The actual metric value
     * @param  array{p25: float, p50: float, p75: float, p90: float, direction: string}  $benchmark
     */
    private function calculateGap(float $value, array $benchmark): float
    {
        $isHigherBetter = $benchmark['direction'] === 'higher_better';

        if ($isHigherBetter) {
            if ($value < $benchmark['p25']) {
                return $benchmark['p25'] - $value;
            }
            if ($value < $benchmark['p50']) {
                return $benchmark['p50'] - $value;
            }
            if ($value < $benchmark['p75']) {
                return $benchmark['p75'] - $value;
            }
            if ($value < $benchmark['p90']) {
                return $benchmark['p90'] - $value;
            }

            return 0.0; // Already above p90
        }

        // Lower is better
        if ($value > $benchmark['p25']) {
            return $value - $benchmark['p25'];
        }
        if ($value > $benchmark['p50']) {
            return $value - $benchmark['p50'];
        }
        if ($value > $benchmark['p75']) {
            return $value - $benchmark['p75'];
        }
        if ($value > $benchmark['p90']) {
            return $value - $benchmark['p90'];
        }

        return 0.0; // Already below p90
    }

    /**
     * Generate an actionable recommendation for a metric.
     *
     * @param  string  $metric
     * @param  array{value: float, grade: string, percentile: float, direction: string, benchmarks: array{p25: float, p50: float, p75: float, p90: float}, label: string}  $result
     */
    private function generateRecommendation(string $metric, array $result): string
    {
        $recommendations = [
            'trial_conversion_rate' => [
                'poor' => 'Focus on reducing time-to-value and adding guided onboarding. Target: %s%% (p75).',
                'average' => 'Improve trial experience with activation emails and in-app guidance. Target: %s%% (p75).',
                'good' => 'Solid trial conversion. Optimize with personalized onboarding flows.',
                'excellent' => 'Excellent trial conversion. Maintain and iterate on onboarding quality.',
            ],
            'monthly_churn_rate' => [
                'poor' => 'Critical: investigate cancellation reasons. Add retention plays, success team outreach. Target: <%.1f%% (p75).',
                'average' => 'Monitor cancellation drivers. Implement exit surveys and re-engagement campaigns.',
                'good' => 'Healthy churn rate. Focus on proactive retention and expansion revenue.',
                'excellent' => 'Best-in-class retention. Focus on expansion revenue from existing customers.',
            ],
            'mrr_growth_rate' => [
                'poor' => 'Prioritize acquisition channels and sales efficiency. Target: >%s%% MoM growth (p75).',
                'average' => 'Optimize conversion funnel and expand to new acquisition channels.',
                'good' => 'Strong growth. Balance acquisition with retention and expansion.',
                'excellent' => 'Exceptional growth. Maintain unit economics while scaling.',
            ],
            'net_revenue_retention' => [
                'poor' => 'NRR below 100% means revenue is shrinking. Focus on upsells and reducing downgrades.',
                'average' => 'NRR above 100% is healthy. Invest in expansion revenue plays.',
                'good' => 'Strong NRR. Optimize upsell timing and pricing strategies.',
                'excellent' => 'Best-in-class NRR. Maintain expansion momentum.',
            ],
            'dau_mau_ratio' => [
                'poor' => 'Low stickiness. Improve core product value and engagement loops. Target: >%s%% (p75).',
                'average' => 'Add habit-forming features, notifications, and engagement triggers.',
                'good' => 'Good engagement. Focus on deepening feature usage.',
                'excellent' => 'Excellent stickiness. Maintain core experience quality.',
            ],
            'ltv_cac_ratio' => [
                'poor' => 'LTV:CAC below 3x indicates unprofitable acquisition. Reduce CAC or increase LTV. Target: >%sx (p75).',
                'average' => 'Focus on improving retention (increases LTV) and conversion efficiency (reduces CAC).',
                'good' => 'Healthy unit economics. Optimize for growth within profitable bounds.',
                'excellent' => 'Excellent unit economics. Scale acquisition aggressively.',
            ],
            'time_to_value' => [
                'poor' => 'Users take too long to experience value. Simplify onboarding and add templates. Target: <%s min (p75).',
                'average' => 'Streamline first-use experience with progressive disclosure.',
                'good' => 'Fast time-to-value. Focus on deepening initial engagement.',
                'excellent' => 'Exceptional activation speed. Maintain simplicity.',
            ],
        ];

        $metricRecommendations = $recommendations[$metric] ?? null;

        if ($metricRecommendations === null) {
            $direction = $result['direction'] === 'higher_better' ? 'higher' : 'lower';

            return match ($result['grade']) {
                'poor' => "Below industry average. Investigate root causes and set improvement targets (direction: {$direction}).",
                'average' => 'At industry average. Identify quick wins to reach the next tier.',
                'good' => 'Above average. Focus on optimization and consistency.',
                'excellent' => 'Best-in-class. Maintain current performance.',
            };
        }

        $template = $metricRecommendations[$result['grade']] ?? $metricRecommendations['average'];
        $targetValue = $result['direction'] === 'higher_better'
            ? $result['benchmarks']['p75']
            : $result['benchmarks']['p75'];

        return sprintf($template, (string) $targetValue);
    }

    /**
     * Get the total number of available benchmarks.
     */
    public function benchmarkCount(): int
    {
        return count(self::BENCHMARKS);
    }

    /**
     * Get available metric names.
     *
     * @return list<string>
     */
    public function availableMetrics(): array
    {
        return array_keys(self::BENCHMARKS);
    }

    /**
     * Get available categories.
     *
     * @return list<string>
     */
    public function availableCategories(): array
    {
        return array_values(array_unique(array_column(self::BENCHMARKS, 'category')));
    }

    /**
     * Check if benchmarks are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a summary of the benchmarking service.
     *
     * @return array{enabled: bool, total_metrics: int, categories: list<string>, industry: string, version: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'total_metrics' => $this->benchmarkCount(),
            'categories' => $this->availableCategories(),
            'industry' => $this->industry,
            'version' => '2.94.0',
        ];
    }

    /**
     * Get the industry setting.
     */
    public function getIndustry(): string
    {
        return $this->industry;
    }

    /**
     * Get the company stage setting.
     */
    public function getCompanyStage(): int
    {
        return $this->companyStage;
    }
}
