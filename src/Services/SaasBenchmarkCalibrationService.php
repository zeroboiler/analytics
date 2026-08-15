<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS benchmark calibration service.
 *
 * Compares a SaaS product's key metrics against industry-standard benchmarks
 * sourced from OpenView Partners, KeyBanc Capital, Meritech Capital, and
 * Points North Data. Provides gap analysis and percentile ranking.
 *
 * Benchmarks cover:
 * - Net Revenue Retention (NRR)
 * - Gross Revenue Retention (GRR)
 * - Logo Retention
 * - CAC Payback Period
 * - LTV:CAC Ratio
 * - Burn Multiple
 * - Rule of 40
 * - Gross Margin
 * - Quick Ratio
 * - Net Dollar Retention
 *
 * @since 174.0.0
 */
final class SaasBenchmarkCalibrationService
{
    /**
     * Industry benchmarks by ARR tier (in USD).
     *
     * Keys: '<=1M', '1-5M', '5-20M', '20-100M', '>100M'
     * Values: benchmark medians from OpenView/Meritech/KeyBanc combined datasets.
     *
     * @var array<string, array<string, array{p25: float, p50: float, p75: float, p90: float, source: string}>>
     */
    private const BENCHMARKS = [
        '<=1M' => [
            'nrr' => ['p25' => 90.0, 'p50' => 105.0, 'p75' => 115.0, 'p90' => 130.0, 'source' => 'OpenView 2024'],
            'grr' => ['p25' => 80.0, 'p50' => 85.0, 'p75' => 90.0, 'p90' => 95.0, 'source' => 'Meritech 2024'],
            'cac_payback_months' => ['p25' => 14.0, 'p50' => 18.0, 'p75' => 24.0, 'p90' => 30.0, 'source' => 'KeyBanc 2024'],
            'ltv_cac_ratio' => ['p25' => 2.0, 'p50' => 3.0, 'p75' => 5.0, 'p90' => 8.0, 'source' => 'OpenView 2024'],
            'burn_multiple' => ['p25' => 0.5, 'p50' => 1.0, 'p75' => 1.5, 'p90' => 2.5, 'source' => 'Sacks Framework'],
            'rule_of_40' => ['p25' => -10.0, 'p50' => 5.0, 'p75' => 20.0, 'p90' => 35.0, 'source' => 'Meritech 2024'],
            'gross_margin' => ['p25' => 60.0, 'p50' => 70.0, 'p75' => 75.0, 'p90' => 80.0, 'source' => 'KeyBanc 2024'],
            'quick_ratio' => ['p25' => 1.0, 'p50' => 2.0, 'p75' => 3.0, 'p90' => 4.5, 'source' => 'OpenView 2024'],
            'logo_retention' => ['p25' => 70.0, 'p50' => 80.0, 'p75' => 85.0, 'p90' => 92.0, 'source' => 'KeyBanc 2024'],
        ],
        '1-5M' => [
            'nrr' => ['p25' => 100.0, 'p50' => 110.0, 'p75' => 120.0, 'p90' => 135.0, 'source' => 'OpenView 2024'],
            'grr' => ['p25' => 82.0, 'p50' => 87.0, 'p75' => 91.0, 'p90' => 95.0, 'source' => 'Meritech 2024'],
            'cac_payback_months' => ['p25' => 12.0, 'p50' => 16.0, 'p75' => 22.0, 'p90' => 28.0, 'source' => 'KeyBanc 2024'],
            'ltv_cac_ratio' => ['p25' => 3.0, 'p50' => 4.0, 'p75' => 6.0, 'p90' => 10.0, 'source' => 'OpenView 2024'],
            'burn_multiple' => ['p25' => 0.5, 'p50' => 1.0, 'p75' => 1.5, 'p90' => 2.0, 'source' => 'Sacks Framework'],
            'rule_of_40' => ['p25' => 0.0, 'p50' => 15.0, 'p75' => 30.0, 'p90' => 45.0, 'source' => 'Meritech 2024'],
            'gross_margin' => ['p25' => 65.0, 'p50' => 72.0, 'p75' => 78.0, 'p90' => 82.0, 'source' => 'KeyBanc 2024'],
            'quick_ratio' => ['p25' => 1.5, 'p50' => 2.5, 'p75' => 3.5, 'p90' => 5.0, 'source' => 'OpenView 2024'],
            'logo_retention' => ['p25' => 75.0, 'p50' => 83.0, 'p75' => 88.0, 'p90' => 93.0, 'source' => 'KeyBanc 2024'],
        ],
        '5-20M' => [
            'nrr' => ['p25' => 105.0, 'p50' => 115.0, 'p75' => 125.0, 'p90' => 140.0, 'source' => 'OpenView 2024'],
            'grr' => ['p25' => 85.0, 'p50' => 90.0, 'p75' => 93.0, 'p90' => 96.0, 'source' => 'Meritech 2024'],
            'cac_payback_months' => ['p25' => 10.0, 'p50' => 14.0, 'p75' => 20.0, 'p90' => 24.0, 'source' => 'KeyBanc 2024'],
            'ltv_cac_ratio' => ['p25' => 3.0, 'p50' => 5.0, 'p75' => 7.0, 'p90' => 12.0, 'source' => 'OpenView 2024'],
            'burn_multiple' => ['p25' => 0.4, 'p50' => 0.8, 'p75' => 1.2, 'p90' => 2.0, 'source' => 'Sacks Framework'],
            'rule_of_40' => ['p25' => 10.0, 'p50' => 25.0, 'p75' => 40.0, 'p90' => 55.0, 'source' => 'Meritech 2024'],
            'gross_margin' => ['p25' => 68.0, 'p50' => 75.0, 'p75' => 80.0, 'p90' => 85.0, 'source' => 'KeyBanc 2024'],
            'quick_ratio' => ['p25' => 2.0, 'p50' => 3.0, 'p75' => 4.0, 'p90' => 6.0, 'source' => 'OpenView 2024'],
            'logo_retention' => ['p25' => 78.0, 'p50' => 85.0, 'p75' => 90.0, 'p90' => 94.0, 'source' => 'KeyBanc 2024'],
        ],
        '20-100M' => [
            'nrr' => ['p25' => 108.0, 'p50' => 118.0, 'p75' => 130.0, 'p90' => 145.0, 'source' => 'OpenView 2024'],
            'grr' => ['p25' => 88.0, 'p50' => 92.0, 'p75' => 95.0, 'p90' => 97.0, 'source' => 'Meritech 2024'],
            'cac_payback_months' => ['p25' => 8.0, 'p50' => 12.0, 'p75' => 18.0, 'p90' => 22.0, 'source' => 'KeyBanc 2024'],
            'ltv_cac_ratio' => ['p25' => 4.0, 'p50' => 6.0, 'p75' => 8.0, 'p90' => 14.0, 'source' => 'OpenView 2024'],
            'burn_multiple' => ['p25' => 0.3, 'p50' => 0.7, 'p75' => 1.0, 'p90' => 1.5, 'source' => 'Sacks Framework'],
            'rule_of_40' => ['p25' => 15.0, 'p50' => 30.0, 'p75' => 45.0, 'p90' => 60.0, 'source' => 'Meritech 2024'],
            'gross_margin' => ['p25' => 70.0, 'p50' => 78.0, 'p75' => 82.0, 'p90' => 88.0, 'source' => 'KeyBanc 2024'],
            'quick_ratio' => ['p25' => 2.5, 'p50' => 3.5, 'p75' => 5.0, 'p90' => 7.0, 'source' => 'OpenView 2024'],
            'logo_retention' => ['p25' => 82.0, 'p50' => 88.0, 'p75' => 92.0, 'p90' => 96.0, 'source' => 'KeyBanc 2024'],
        ],
        '>100M' => [
            'nrr' => ['p25' => 110.0, 'p50' => 120.0, 'p75' => 135.0, 'p90' => 150.0, 'source' => 'OpenView 2024'],
            'grr' => ['p25' => 90.0, 'p50' => 93.0, 'p75' => 96.0, 'p90' => 98.0, 'source' => 'Meritech 2024'],
            'cac_payback_months' => ['p25' => 6.0, 'p50' => 10.0, 'p75' => 15.0, 'p90' => 20.0, 'source' => 'KeyBanc 2024'],
            'ltv_cac_ratio' => ['p25' => 5.0, 'p50' => 7.0, 'p75' => 10.0, 'p90' => 15.0, 'source' => 'OpenView 2024'],
            'burn_multiple' => ['p25' => 0.2, 'p50' => 0.5, 'p75' => 0.8, 'p90' => 1.2, 'source' => 'Sacks Framework'],
            'rule_of_40' => ['p25' => 20.0, 'p50' => 35.0, 'p75' => 50.0, 'p90' => 65.0, 'source' => 'Meritech 2024'],
            'gross_margin' => ['p25' => 72.0, 'p50' => 80.0, 'p75' => 85.0, 'p90' => 90.0, 'source' => 'KeyBanc 2024'],
            'quick_ratio' => ['p25' => 3.0, 'p50' => 4.0, 'p75' => 6.0, 'p90' => 8.0, 'source' => 'OpenView 2024'],
            'logo_retention' => ['p25' => 85.0, 'p50' => 90.0, 'p75' => 94.0, 'p90' => 97.0, 'source' => 'KeyBanc 2024'],
        ],
    ];

    /** @var CacheRepository */
    private readonly CacheRepository $cache;

    /** @var ConfigRepository */
    private readonly ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Get benchmarks for a specific ARR tier.
     *
     * @param  string  $arrTier  One of: '<=1M', '1-5M', '5-20M', '20-100M', '>100M'
     * @return array<string, array{p25: float, p50: float, p75: float, p90: float, source: string}>
     */
    public function benchmarks(string $arrTier = '1-5M'): array
    {
        return self::BENCHMARKS[$arrTier] ?? self::BENCHMARKS['1-5M'];
    }

    /**
     * Compare actual metrics against industry benchmarks.
     *
     * @param  array<string, float>  $actuals  Key-value map of actual metric values
     * @param  string  $arrTier  ARR tier for benchmark selection
     * @return array<string, array{actual: float, benchmark_p50: float, gap: float, percentile: string, grade: string, recommendation: string}>
     */
    public function calibrate(array $actuals, string $arrTier = '1-5M'): array
    {
        $benchmarks = $this->benchmarks($arrTier);
        $results = [];

        foreach ($actuals as $metric => $value) {
            $benchmark = $benchmarks[$metric] ?? null;

            if ($benchmark === null) {
                continue;
            }

            $gap = $value - $benchmark['p50'];
            $percentile = $this->computePercentile($value, $benchmark);
            $grade = $this->assignGrade($metric, $value, $benchmark);
            $recommendation = $this->generateRecommendation($metric, $value, $benchmark);

            $results[$metric] = [
                'actual' => round($value, 2),
                'benchmark_p50' => $benchmark['p50'],
                'gap' => round($gap, 2),
                'percentile' => $percentile,
                'grade' => $grade,
                'recommendation' => $recommendation,
            ];
        }

        return $results;
    }

    /**
     * Get an overall benchmark score (0-100) based on calibrated metrics.
     *
     * @param  array<string, float>  $actuals  Key-value map of actual metric values
     * @param  string  $arrTier  ARR tier for benchmark selection
     * @return array{score: int, grade: string, metrics_count: int, strong_metrics: list<string>, weak_metrics: list<string>}
     */
    public function overallScore(array $actuals, string $arrTier = '1-5M'): array
    {
        $calibration = $this->calibrate($actuals, $arrTier);

        if ($calibration === []) {
            return [
                'score' => 0,
                'grade' => 'N/A',
                'metrics_count' => 0,
                'strong_metrics' => [],
                'weak_metrics' => [],
            ];
        }

        $totalScore = 0;
        $count = 0;
        $strong = [];
        $weak = [];

        foreach ($calibration as $metric => $data) {
            $count++;
            $gradeScore = match ($data['grade']) {
                'A+', 'A' => 100,
                'A-', 'B+' => 90,
                'B', 'B-' => 80,
                'C+', 'C' => 70,
                'C-' => 60,
                'D' => 50,
                'F' => 25,
                default => 0,
            };

            $totalScore += $gradeScore;

            if ($gradeScore >= 80) {
                $strong[] = $metric;
            } elseif ($gradeScore < 60) {
                $weak[] = $metric;
            }
        }

        $avgScore = (int) round($totalScore / $count);
        $overallGrade = $this->scoreToGrade($avgScore);

        return [
            'score' => $avgScore,
            'grade' => $overallGrade,
            'metrics_count' => $count,
            'strong_metrics' => $strong,
            'weak_metrics' => $weak,
        ];
    }

    /**
     * Get all available ARR tiers.
     *
     * @return list<string>
     */
    public static function arrTiers(): array
    {
        return array_keys(self::BENCHMARKS);
    }

    /**
     * Get all available metric names.
     *
     * @return list<string>
     */
    public static function metricNames(): array
    {
        return array_keys(self::BENCHMARKS['1-5M']);
    }

    /**
     * Resolve the ARR tier from a numeric ARR value.
     *
     * @param  float  $arr  Annual recurring revenue in USD
     * @return string  ARR tier key
     */
    public static function resolveTier(float $arr): string
    {
        return match (true) {
            $arr <= 1_000_000 => '<=1M',
            $arr <= 5_000_000 => '1-5M',
            $arr <= 20_000_000 => '5-20M',
            $arr <= 100_000_000 => '20-100M',
            default => '>100M',
        };
    }

    /**
     * Get gap analysis for a single metric.
     *
     * @param  string  $metric  Metric name
     * @param  float  $actual  Actual value
     * @param  string  $arrTier  ARR tier
     * @return array{actual: float, p25: float, p50: float, p75: float, p90: float, gap_from_median: float, percentile: string, direction: string, actionable: string}|null
     */
    public function gapAnalysis(string $metric, float $actual, string $arrTier = '1-5M'): ?array
    {
        $benchmark = $this->benchmarks($arrTier)[$metric] ?? null;

        if ($benchmark === null) {
            return null;
        }

        // For metrics where lower is better (cac_payback, burn_multiple)
        $lowerIsBetter = in_array($metric, ['cac_payback_months', 'burn_multiple'], true);
        $gap = $lowerIsBetter
            ? $benchmark['p50'] - $actual
            : $actual - $benchmark['p50'];

        return [
            'actual' => round($actual, 2),
            'p25' => $benchmark['p25'],
            'p50' => $benchmark['p50'],
            'p75' => $benchmark['p75'],
            'p90' => $benchmark['p90'],
            'gap_from_median' => round($gap, 2),
            'percentile' => $this->computePercentile($actual, $benchmark),
            'direction' => $gap > 0 ? ($lowerIsBetter ? 'below' : 'above') : ($lowerIsBetter ? 'above' : 'below'),
            'actionable' => $this->generateRecommendation($metric, $actual, $benchmark),
        ];
    }

    /**
     * Get a cached calibration report.
     *
     * @param  array<string, float>  $actuals  Actual metrics
     * @param  string  $arrTier  ARR tier
     * @return array{calibration: array<string, mixed>, overall: array<string, mixed>, tier: string, computed_at: string}
     */
    public function cachedReport(array $actuals, string $arrTier = '1-5M'): array
    {
        $cacheKey = 'zb_benchmark_calibration_' . md5(serialize([$actuals, $arrTier]));
        $ttl = $this->config->get('zeroboiler.analytics.saas_kpi_calc.cache_ttl', 300);

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $report = [
            'calibration' => $this->calibrate($actuals, $arrTier),
            'overall' => $this->overallScore($actuals, $arrTier),
            'tier' => $arrTier,
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $report, $ttl);

        return $report;
    }

    /**
     * Compute approximate percentile for a value within a benchmark distribution.
     *
     * @param  float  $value  The actual value
     * @param  array{p25: float, p50: float, p75: float, p90: float}  $benchmark  Benchmark percentiles
     * @return string  Percentile label (e.g. 'P25-P50', 'P75-P90')
     */
    private function computePercentile(float $value, array $benchmark): string
    {
        return match (true) {
            $value < $benchmark['p25'] => '<P25',
            $value < $benchmark['p50'] => 'P25-P50',
            $value < $benchmark['p75'] => 'P50-P75',
            $value < $benchmark['p90'] => 'P75-P90',
            default => '>=P90',
        };
    }

    /**
     * Assign a letter grade for a metric based on actual vs benchmark.
     *
     * @param  string  $metric  Metric name
     * @param  float  $value  Actual value
     * @param  array{p25: float, p50: float, p75: float, p90: float}  $benchmark  Benchmark percentiles
     * @return string  Letter grade (A+ through F)
     */
    private function assignGrade(string $metric, float $value, array $benchmark): string
    {
        $lowerIsBetter = in_array($metric, ['cac_payback_months', 'burn_multiple'], true);

        if ($lowerIsBetter) {
            return match (true) {
                $value <= $benchmark['p25'] => 'A+',
                $value <= $benchmark['p50'] => 'A',
                $value <= $benchmark['p75'] => 'B',
                $value <= $benchmark['p90'] => 'C',
                default => 'D',
            };
        }

        return match (true) {
            $value >= $benchmark['p90'] => 'A+',
            $value >= $benchmark['p75'] => 'A',
            $value >= $benchmark['p50'] => 'B',
            $value >= $benchmark['p25'] => 'C',
            default => 'D',
        };
    }

    /**
     * Generate an actionable recommendation for a metric.
     *
     * @param  string  $metric  Metric name
     * @param  float  $value  Actual value
     * @param  array{p25: float, p50: float, p75: float, p90: float}  $benchmark  Benchmark percentiles
     * @return string  Actionable recommendation
     */
    private function generateRecommendation(string $metric, float $value, array $benchmark): string
    {
        $lowerIsBetter = in_array($metric, ['cac_payback_months', 'burn_multiple'], true);
        $belowMedian = $lowerIsBetter ? ($value > $benchmark['p50']) : ($value < $benchmark['p50']);

        if ($belowMedian) {
            return match ($metric) {
                'nrr' => 'Focus on expansion revenue through upselling, cross-selling, and usage-based pricing to improve NRR above 110%.',
                'grr' => 'Invest in customer success and onboarding to reduce churn. Target GRR above 90%.',
                'cac_payback_months' => 'Optimize sales efficiency: shorten sales cycles, improve lead quality, and increase ARPU to reduce payback below 12 months.',
                'ltv_cac_ratio' => 'Increase customer lifetime value through retention and expansion, or reduce CAC through more efficient acquisition channels.',
                'burn_multiple' => 'Improve growth efficiency by increasing net new ARR while controlling burn rate. Target burn multiple below 1.0x.',
                'rule_of_40' => 'Balance growth rate and profitability. If growth < 40%, focus on margin improvement; if margin < 0%, accelerate growth.',
                'gross_margin' => 'Optimize cost structure: reduce COGS, improve infrastructure efficiency, and consider pricing adjustments.',
                'quick_ratio' => 'Accelerate new MRR growth while minimizing churn and contraction. Target quick ratio above 4.0.',
                'logo_retention' => 'Implement early warning systems for at-risk accounts and invest in customer success programs.',
                default => 'Review this metric against industry benchmarks and identify improvement levers.',
            };
        }

        return 'Metric is at or above industry median. Continue current strategy and focus on other improvement areas.';
    }

    /**
     * Convert a numeric score to a letter grade.
     *
     * @param  int  $score  Score (0-100)
     * @return string  Letter grade
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 85 => 'A',
            $score >= 75 => 'B+',
            $score >= 65 => 'B',
            $score >= 55 => 'B-',
            $score >= 45 => 'C',
            $score >= 35 => 'C-',
            $score >= 25 => 'D',
            default => 'F',
        };
    }
}
