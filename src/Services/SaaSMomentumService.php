<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS Momentum Analytics — measures growth rate of change (GRoC) for key metrics.
 *
 * While most analytics tools track absolute values (MRR, churn rate, DAU),
 * this service tracks the **rate of change** — the second derivative that
 * reveals whether growth is accelerating or decelerating.
 *
 * **Key metrics tracked:**
 * - MRR velocity (MoM $ change, 3-month trend)
 * - Retention acceleration (is retention improving or declining?)
 * - Engagement momentum (DAU trend direction)
 * - Revenue momentum (net new MRR acceleration)
 * - Conversion velocity (signup-to-trial conversion trend)
 *
 * **Momentum scoring:**
 * Each metric gets a momentum score from -100 (rapidly declining) to +100
 * (rapidly accelerating). The composite score gives an instant read on
 * whether the business is gaining or losing momentum.
 *
 * **Use cases:**
 * - Board reporting: "Our growth momentum score is +72"
 * - Early warning: Detect deceleration before absolute metrics drop
 * - Team motivation: Celebrate acceleration, not just absolute growth
 * - Investor updates: Show trajectory, not just position
 *
 * Configuration: `zeroboiler.analytics.momentum`
 *
 * Inspired by OpenView Partners' SaaS Growth Metrics methodology.
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueForecastService
 * @see \ZeroBoiler\Analytics\Services\GrowthMetricsService
 *
 * @since 175.0.0
 */
final class SaaSMomentumService
{
    private const CACHE_PREFIX = 'zb_momentum_';

    private const DEFAULT_CACHE_TTL = 1800; // 30 minutes

    /** @var int Minimum data points required for momentum calculation */
    private const MIN_DATA_POINTS = 2;

    /**
     * Metric definitions with their momentum thresholds and weights.
     *
     * @var array<string, array{label: string, weight: float, positive_is_good: bool, thresholds: array{strong_decline: float, decline: float, flat: float, growth: float, strong_growth: float}, unit: string}>
     */
    private const METRIC_DEFINITIONS = [
        'mrr' => [
            'label' => 'MRR Velocity',
            'weight' => 0.25,
            'positive_is_good' => true,
            'thresholds' => [
                'strong_decline' => -15.0,
                'decline' => -5.0,
                'flat' => -2.0,
                'growth' => 5.0,
                'strong_growth' => 15.0,
            ],
            'unit' => '% MoM',
        ],
        'retention' => [
            'label' => 'Retention Acceleration',
            'weight' => 0.20,
            'positive_is_good' => true,
            'thresholds' => [
                'strong_decline' => -5.0,
                'decline' => -1.0,
                'flat' => -0.5,
                'growth' => 1.0,
                'strong_growth' => 5.0,
            ],
            'unit' => '% point MoM',
        ],
        'engagement' => [
            'label' => 'Engagement Momentum',
            'weight' => 0.15,
            'positive_is_good' => true,
            'thresholds' => [
                'strong_decline' => -20.0,
                'decline' => -5.0,
                'flat' => -2.0,
                'growth' => 5.0,
                'strong_growth' => 20.0,
            ],
            'unit' => '% MoM',
        ],
        'net_new_mrr' => [
            'label' => 'Net New MRR Acceleration',
            'weight' => 0.20,
            'positive_is_good' => true,
            'thresholds' => [
                'strong_decline' => -25.0,
                'decline' => -10.0,
                'flat' => -3.0,
                'growth' => 10.0,
                'strong_growth' => 25.0,
            ],
            'unit' => '% MoM',
        ],
        'conversion' => [
            'label' => 'Conversion Velocity',
            'weight' => 0.10,
            'positive_is_good' => true,
            'thresholds' => [
                'strong_decline' => -20.0,
                'decline' => -5.0,
                'flat' => -2.0,
                'growth' => 5.0,
                'strong_growth' => 20.0,
            ],
            'unit' => '% point MoM',
        ],
        'churn' => [
            'label' => 'Churn Velocity',
            'weight' => 0.10,
            'positive_is_good' => false, // Lower churn rate change is better
            'thresholds' => [
                'strong_decline' => 5.0,
                'decline' => 2.0,
                'flat' => 0.5,
                'growth' => -0.5,
                'strong_growth' => -2.0,
            ],
            'unit' => '% point MoM',
        ],
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    /** @var int Number of periods to average for trend smoothing */
    private int $smoothingWindow;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $momentumConfig = $config->get('zeroboiler.analytics.momentum', []);
        /** @var array{enabled?: bool, cache_ttl?: int, smoothing_window?: int} $momentumConfig */

        $this->enabled = (bool) ($momentumConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($momentumConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->smoothingWindow = (int) ($momentumConfig['smoothing_window'] ?? 3);
    }

    /**
     * Calculate momentum score for a single metric.
     *
     * Accepts a time series of values and computes the rate of change
     * as a momentum score from -100 to +100.
     *
     * @param  string  $metric  Metric key (mrr, retention, engagement, etc.)
     * @param  list<float> $values  Time series values (oldest first, most recent last)
     * @return array{score: int, direction: string, rate_of_change: float, trend: string, label: string, confidence: string}
     */
    public function calculateMetricMomentum(string $metric, array $values): array
    {
        if (! $this->enabled) {
            return $this->disabledMetric($metric);
        }

        $definition = self::METRIC_DEFINITIONS[$metric] ?? null;
        if ($definition === null) {
            return [
                'score' => 0,
                'direction' => 'unknown',
                'rate_of_change' => 0.0,
                'trend' => 'unknown',
                'label' => $metric,
                'confidence' => 'unknown_metric',
            ];
        }

        if (count($values) < self::MIN_DATA_POINTS) {
            return [
                'score' => 0,
                'direction' => 'insufficient_data',
                'rate_of_change' => 0.0,
                'trend' => 'flat',
                'label' => $definition['label'],
                'confidence' => 'insufficient_data',
            ];
        }

        $changes = [];
        for ($i = 1; $i < count($values); $i++) {
            $prev = $values[$i - 1];
            $current = $values[$i];

            if ($prev > 0.0001) {
                $changes[] = (($current - $prev) / $prev) * 100;
            } elseif ($prev < -0.0001) {
                $changes[] = (($current - $prev) / abs($prev)) * 100;
            }
        }

        if ($changes === []) {
            return [
                'score' => 0,
                'direction' => 'flat',
                'rate_of_change' => 0.0,
                'trend' => 'flat',
                'label' => $definition['label'],
                'confidence' => 'no_variance',
            ];
        }

        // Smooth with moving average
        $smoothedChanges = $this->smoothChanges($changes);
        $latestChange = $smoothedChanges[array_key_last($smoothedChanges)] ?? 0.0;

        $score = $this->rateToScore($latestChange, $definition['thresholds'], $definition['positive_is_good']);

        $direction = $this->scoreToDirection($score);
        $trend = $this->scoreToTrend($score);
        $confidence = $this->calculateConfidence($changes);

        return [
            'score' => $score,
            'direction' => $direction,
            'rate_of_change' => round($latestChange, 2),
            'trend' => $trend,
            'label' => $definition['label'],
            'confidence' => $confidence,
        ];
    }

    /**
     * Calculate the composite momentum score across all tracked metrics.
     *
     * @param  array<string, list<float>>  $metricValues  Map of metric key → time series values
     * @return array{composite_score: int, grade: string, direction: string, metrics: array<string, array{score: int, direction: string, rate_of_change: float, trend: string, label: string}>, strongest_metric: string|null, weakest_metric: string|null, insight: string, computed_at: string}
     */
    public function compositeScore(array $metricValues): array
    {
        if (! $this->enabled) {
            return $this->disabledComposite();
        }

        $cacheKey = self::CACHE_PREFIX . 'composite_' . md5(json_encode($metricValues, JSON_THROW_ON_ERROR));

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $metricScores = [];
        $strongestScore = -101;
        $weakestScore = 101;
        $strongestMetric = null;
        $weakestMetric = null;

        foreach (self::METRIC_DEFINITIONS as $key => $definition) {
            $values = $metricValues[$key] ?? [];
            $momentum = $this->calculateMetricMomentum($key, $values);

            $metricScores[$key] = [
                'score' => $momentum['score'],
                'direction' => $momentum['direction'],
                'rate_of_change' => $momentum['rate_of_change'],
                'trend' => $momentum['trend'],
                'label' => $momentum['label'],
            ];

            $weightedSum += $momentum['score'] * $definition['weight'];
            $totalWeight += $definition['weight'];

            if ($momentum['score'] > $strongestScore) {
                $strongestScore = $momentum['score'];
                $strongestMetric = $key;
            }

            if ($momentum['score'] < $weakestScore) {
                $weakestScore = $momentum['score'];
                $weakestMetric = $key;
            }
        }

        $compositeScore = (int) round($totalWeight > 0 ? $weightedSum / $totalWeight : 0);
        $grade = $this->scoreToGrade($compositeScore);
        $direction = $this->scoreToDirection($compositeScore);
        $insight = $this->generateInsight($compositeScore, $strongestMetric, $weakestMetric, $metricScores);

        $result = [
            'composite_score' => $compositeScore,
            'grade' => $grade,
            'direction' => $direction,
            'metrics' => $metricScores,
            'strongest_metric' => $strongestMetric,
            'weakest_metric' => $weakestMetric,
            'insight' => $insight,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Generate a quick momentum summary (no caching).
     *
     * Lightweight version for sidebar widgets or header badges.
     *
     * @param  array<string, list<float>>  $metricValues
     * @return array{composite_score: int, direction: string, insight: string}
     */
    public function quickSummary(array $metricValues): array
    {
        $full = $this->compositeScore($metricValues);

        return [
            'composite_score' => $full['composite_score'],
            'direction' => $full['direction'],
            'insight' => $full['insight'],
        ];
    }

    /**
     * Get available metric definitions.
     *
     * @return array<string, array{label: string, weight: float, positive_is_good: bool, unit: string}>
     */
    public function availableMetrics(): array
    {
        $result = [];
        foreach (self::METRIC_DEFINITIONS as $key => $def) {
            $result[$key] = [
                'label' => $def['label'],
                'weight' => $def['weight'],
                'positive_is_good' => $def['positive_is_good'],
                'unit' => $def['unit'],
            ];
        }

        return $result;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Smooth a series of changes with a simple moving average.
     *
     * @param  list<float>  $changes
     * @return list<float>
     */
    private function smoothChanges(array $changes): array
    {
        if (count($changes) <= $this->smoothingWindow) {
            return $changes;
        }

        $smoothed = [];
        for ($i = 0; $i < count($changes); $i++) {
            $start = max(0, $i - $this->smoothingWindow + 1);
            $window = array_slice($changes, $start, $this->smoothingWindow);
            $smoothed[] = array_sum($window) / count($window);
        }

        return $smoothed;
    }

    /**
     * Convert a rate of change percentage to a -100..+100 momentum score.
     *
     * @param  float  $rate  Rate of change percentage
     * @param  array{strong_decline: float, decline: float, flat: float, growth: float, strong_growth: float}  $thresholds
     * @param  bool  $positiveIsGood
     */
    private function rateToScore(float $rate, array $thresholds, bool $positiveIsGood): int
    {
        if ($rate >= $thresholds['strong_growth']) {
            return 100;
        }
        if ($rate >= $thresholds['growth']) {
            $range = $thresholds['strong_growth'] - $thresholds['growth'];
            $pct = $range > 0 ? ($rate - $thresholds['growth']) / $range : 1.0;

            return (int) round(50 + $pct * 50);
        }
        if ($rate >= $thresholds['flat']) {
            $range = $thresholds['growth'] - $thresholds['flat'];
            $pct = $range > 0 ? ($rate - $thresholds['flat']) / $range : 0.5;

            return (int) round($pct * 50);
        }
        if ($rate >= $thresholds['decline']) {
            $range = $thresholds['flat'] - $thresholds['decline'];
            $pct = $range > 0 ? ($rate - $thresholds['decline']) / $range : 0.5;

            return (int) round(-50 + $pct * 50);
        }
        if ($rate >= $thresholds['strong_decline']) {
            $range = $thresholds['decline'] - $thresholds['strong_decline'];
            $pct = $range > 0 ? ($rate - $thresholds['strong_decline']) / $range : 1.0;

            return (int) round(-100 + $pct * 50);
        }

        return -100;
    }

    /**
     * Convert a momentum score to a human-readable direction.
     */
    private function scoreToDirection(int $score): string
    {
        return match (true) {
            $score >= 70 => 'strong_acceleration',
            $score >= 30 => 'acceleration',
            $score >= -10 => 'stable',
            $score >= -50 => 'deceleration',
            default => 'strong_deceleration',
        };
    }

    /**
     * Convert a momentum score to a trend label.
     */
    private function scoreToTrend(int $score): string
    {
        return match (true) {
            $score >= 70 => '🚀 accelerating',
            $score >= 30 => '📈 growing',
            $score >= -10 => '➡️ stable',
            $score >= -50 => '📉 declining',
            default => '⚠️ rapidly declining',
        };
    }

    /**
     * Convert a momentum score to a letter grade.
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 80 => 'A+',
            $score >= 60 => 'A',
            $score >= 40 => 'B+',
            $score >= 20 => 'B',
            $score >= 0 => 'C',
            $score >= -20 => 'D',
            $score >= -50 => 'F',
            default => 'F-',
        };
    }

    /**
     * Calculate confidence level based on data stability.
     *
     * @param  list<float>  $changes
     */
    private function calculateConfidence(array $changes): string
    {
        $count = count($changes);
        if ($count < 2) {
            return 'low';
        }

        if ($count >= 6) {
            return 'high';
        }

        return 'medium';
    }

    /**
     * Generate a human-readable insight for the composite score.
     *
     * @param  int  $compositeScore
     * @param  string|null  $strongestMetric
     * @param  string|null  $weakestMetric
     * @param  array<string, array{score: int, direction: string, label: string}>  $metricScores
     */
    private function generateInsight(int $compositeScore, ?string $strongestMetric, ?string $weakestMetric, array $metricScores): string
    {
        if ($compositeScore >= 60) {
            $strongestLabel = $strongestMetric !== null ? ($metricScores[$strongestMetric]['label'] ?? $strongestMetric) : 'growth';
            return "Strong positive momentum driven by {$strongestLabel}. The business is accelerating across most metrics.";
        }

        if ($compositeScore >= 20) {
            return "Moderate positive momentum. Growth is steady but there's room to accelerate key metrics.";
        }

        if ($compositeScore >= -10) {
            return "Momentum is roughly flat. The business is maintaining its position but not gaining traction.";
        }

        if ($compositeScore >= -50) {
            $weakestLabel = $weakestMetric !== null ? ($metricScores[$weakestMetric]['label'] ?? $weakestMetric) : 'key metrics';
            return "Negative momentum detected. {$weakestLabel} is decelerating — investigate root causes.";
        }

        return "Severe momentum decline across most metrics. Immediate action required to reverse the trajectory.";
    }

    /**
     * Build a disabled response for single metric.
     *
     * @param  string  $metric
     * @return array{score: int, direction: string, rate_of_change: float, trend: string, label: string, confidence: string}
     */
    private function disabledMetric(string $metric): array
    {
        $definition = self::METRIC_DEFINITIONS[$metric] ?? null;

        return [
            'score' => 0,
            'direction' => 'disabled',
            'rate_of_change' => 0.0,
            'trend' => 'disabled',
            'label' => $definition['label'] ?? $metric,
            'confidence' => 'disabled',
        ];
    }

    /**
     * Build a disabled response for composite score.
     *
     * @return array{composite_score: int, grade: string, direction: string, metrics: array<empty, empty>, strongest_metric: string|null, weakest_metric: string|null, insight: string, computed_at: string}
     */
    private function disabledComposite(): array
    {
        return [
            'composite_score' => 0,
            'grade' => 'N/A',
            'direction' => 'disabled',
            'metrics' => [],
            'strongest_metric' => null,
            'weakest_metric' => null,
            'insight' => 'SaaS momentum tracking is disabled. Enable via zeroboiler.analytics.momentum.enabled.',
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
