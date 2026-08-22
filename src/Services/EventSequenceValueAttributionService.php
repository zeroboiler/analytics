<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\DTO\SequenceValueAttribution;
/**
 * Event Sequence Value Attribution Matrix Service.
 *
 * Computes business-value scores for detected user journey sequences
 * by correlating event sequences with revenue, LTV, conversion, and
 * retention outcomes. Produces a ranked "value matrix" that identifies
 * the highest-impact user paths.
 *
 * Composite scoring weights (configurable):
 * - **LTV correlation** (30%) — Average lifetime value of users completing this path
 * - **Conversion lift** (25%) — Conversion rate delta vs. baseline
 * - **Retention impact** (20%) — D7/D30 retention for this cohort
 * - **Revenue per occurrence** (15%) — Total revenue / sequence count
 * - **Time-to-value velocity** (10%) — Speed from sequence start to first value
 *
 * Value grades (S > A > B > C > D):
 * - S: Top 5% — highest-value paths, double down on acquisition
 * - A: Top 20% — strong performers, optimize funnel steps
 * - B: Top 50% — solid paths, monitor and iterate
 * - C: Bottom 50% — underperformers, investigate drop-offs
 * - D: Bottom 10% — dead ends, consider removal or redesign
 *
 * @since 212.0.0
 */
final class EventSequenceValueAttributionService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_seq_value_';

    /** @var string Matrix cache key */
    private const CACHE_MATRIX = 'zb_seq_value_matrix';

    /**
     * Default weight allocations for composite scoring.
     */
    private const WEIGHT_LTV = 0.30;
    private const WEIGHT_CONVERSION = 0.25;
    private const WEIGHT_RETENTION = 0.20;
    private const WEIGHT_REVENUE = 0.15;
    private const WEIGHT_VELOCITY = 0.10;

    /**
     * Revenue value multipliers per event name.
     *
     * Events directly associated with revenue get higher multipliers.
     *
     * @var array<string, float>
     */
    private const EVENT_REVENUE_MULTIPLIERS = [
        'purchase' => 10.0,
        'subscription_created' => 15.0,
        'subscription_renewal' => 12.0,
        'plan_upgrade' => 18.0,
        'trial_converted' => 14.0,
        'payment_succeeded' => 8.0,
        'add_to_cart' => 3.0,
        'begin_checkout' => 5.0,
        'add_payment_info' => 6.0,
        'view_item' => 1.0,
        'view_cart' => 2.0,
        'sign_up' => 4.0,
        'start_trial' => 6.0,
        'login' => 0.5,
        'page_view' => 0.1,
        'scroll_depth' => 0.05,
        'click' => 0.2,
        'form_start' => 1.5,
        'form_submit' => 2.0,
        'search' => 1.0,
        'feature_used' => 2.5,
        'feature_adopted' => 3.0,
        'share' => 1.5,
        'invite_sent' => 3.0,
        'team_created' => 5.0,
        'team_member_joined' => 4.0,
        'integration_connected' => 6.0,
        'cancellation' => -5.0,
        'plan_downgrade' => -3.0,
        'subscription_cancelled' => -8.0,
        'refund' => -6.0,
        'trial_expired' => -2.0,
    ];

    private CacheRepository $cache;
    private ConfigRepository $config;
    private int $cacheTtl;
    private float $weightLtv;
    private float $weightConversion;
    private float $weightRetention;
    private float $weightRevenue;
    private float $weightVelocity;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository
     * @param  ConfigRepository|null  $config  Optional config repository
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 600)
     * @param  array{ltv?: float, conversion?: float, retention?: float, revenue?: float, velocity?: float}|null  $weights  Custom weight overrides
     */
    public function __construct(
        ?CacheRepository $cache = null,
        ?ConfigRepository $config = null,
        int $cacheTtl = 600,
        ?array $weights = null,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->cacheTtl = $cacheTtl;

        // Allow config-driven weight overrides
        $this->weightLtv = $weights['ltv'] ?? self::WEIGHT_LTV;
        $this->weightConversion = $weights['conversion'] ?? self::WEIGHT_CONVERSION;
        $this->weightRetention = $weights['retention'] ?? self::WEIGHT_RETENTION;
        $this->weightRevenue = $weights['revenue'] ?? self::WEIGHT_REVENUE;
        $this->weightVelocity = $weights['velocity'] ?? self::WEIGHT_VELOCITY;
    }

    /**
     * Compute value attribution for a single detected event sequence pattern.
     *
     * @param  EventSequencePattern  $pattern  Detected sequence pattern
     * @param  array{baseline_conversion?: float, baseline_ltv?: float, baseline_d7?: float, baseline_d30?: float, avg_acquisition_cost?: float}|null  $baselines  Optional baseline metrics for lift computation
     * @return SequenceValueAttribution
     */
    public function attribute(EventSequencePattern $pattern, ?array $baselines = null): SequenceValueAttribution
    {
        $baselines ??= [
            'baseline_conversion' => 0.05,
            'baseline_ltv' => 50.0,
            'baseline_d7' => 0.30,
            'baseline_d30' => 0.15,
            'avg_acquisition_cost' => 25.0,
        ];

        // 1. Compute sequence revenue score (sum of event multipliers)
        $sequenceRevenueScore = $this->computeSequenceRevenueScore($pattern->sequence);

        // 2. Estimate LTV correlation (higher if sequence contains revenue events)
        $ltvScore = $this->computeLtvScore($pattern->sequence, $pattern->conversionRate);

        // 3. Conversion lift vs. baseline
        $conversionLift = $pattern->conversionRate - ($baselines['baseline_conversion'] ?? 0.05);

        // 4. Retention estimation (sequences with engagement events score higher)
        $retentionScore = $this->computeRetentionScore($pattern->sequence);

        // 5. Time-to-value velocity (normalized, lower is better)
        $velocityScore = $this->computeVelocityScore($pattern->averageDurationSeconds);

        // 6. Composite score
        $composite = $this->computeCompositeScore(
            ltvScore: $ltvScore,
            conversionScore: $this->normalizeLift($conversionLift),
            retentionScore: $retentionScore,
            revenueScore: $this->normalizeRevenue($sequenceRevenueScore),
            velocityScore: $velocityScore,
        );

        // 7. Grade
        $grade = $this->gradeFromScore($composite);

        // 8. Revenue per occurrence
        $revenuePerOccurrence = $pattern->occurrences > 0
            ? $sequenceRevenueScore * $pattern->conversionRate
            : 0.0;

        // 9. Estimated ROI
        $acquisitionCost = $baselines['avg_acquisition_cost'] ?? 25.0;
        $roi = $acquisitionCost > 0
            ? ($revenuePerOccurrence * ($baselines['baseline_ltv'] ?? 50.0)) / $acquisitionCost
            : 0.0;

        return new SequenceValueAttribution(
            sequenceId: $pattern->id,
            sequence: $pattern->sequence,
            occurrences: $pattern->occurrences,
            uniqueUsers: $pattern->uniqueUsers,
            avgLtv: $ltvScore * ($baselines['baseline_ltv'] ?? 50.0),
            totalRevenue: $revenuePerOccurrence * $pattern->occurrences,
            conversionRate: $pattern->conversionRate,
            conversionLift: $conversionLift,
            d7Retention: $retentionScore * ($baselines['baseline_d7'] ?? 0.30),
            d30Retention: $retentionScore * ($baselines['baseline_d30'] ?? 0.15),
            timeToValueSeconds: $pattern->averageDurationSeconds,
            sequenceRoi: $roi,
            valueGrade: $grade,
            compositeScore: $composite,
            metadata: [
                'sequence_length' => count($pattern->sequence),
                'sequence_revenue_score' => round($sequenceRevenueScore, 2),
                'median_duration' => round($pattern->medianDurationSeconds, 1),
                'sample_size' => $pattern->occurrences,
            ],
        );
    }

    /**
     * Compute value attribution for multiple patterns and return ranked results.
     *
     * @param  list<EventSequencePattern>  $patterns  List of detected patterns
     * @param  array{baseline_conversion?: float, baseline_ltv?: float, baseline_d7?: float, baseline_d30?: float, avg_acquisition_cost?: float}|null  $baselines  Optional baseline metrics
     * @return array{attributions: list<array{sequence_id: string, sequence: list<string>, composite_score: float, value_grade: string, occurrences: int, avg_ltv: float, conversion_lift: float, sequence_roi: float}>, summary: array{total_sequences: int, top_path: string|null, avg_score: float, grade_distribution: array{S: int, A: int, B: int, C: int, D: int}, highest_ltv_path: string|null, fastest_path: string|null}}
     */
    public function attributeMatrix(array $patterns, ?array $baselines = null): array
    {
        $cacheKey = self::CACHE_MATRIX;

        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $attributions = [];
        $gradeDistribution = ['S' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $totalScore = 0.0;
        $highestLtv = ['path' => null, 'ltv' => 0.0];
        $fastestPath = ['path' => null, 'duration' => PHP_FLOAT_MAX];

        foreach ($patterns as $pattern) {
            $attribution = $this->attribute($pattern, $baselines);
            $attributions[] = $attribution;

            $gradeDistribution[$attribution->valueGrade] ??= 0;
            $gradeDistribution[$attribution->valueGrade]++;
            $totalScore += $attribution->compositeScore;

            if ($attribution->avgLtv > $highestLtv['ltv']) {
                $highestLtv = ['path' => implode(' → ', $attribution->sequence), 'ltv' => $attribution->avgLtv];
            }

            if ($attribution->timeToValueSeconds < $fastestPath['duration'] && $attribution->timeToValueSeconds > 0) {
                $fastestPath = ['path' => implode(' → ', $attribution->sequence), 'duration' => $attribution->timeToValueSeconds];
            }
        }

        usort($attributions, fn (SequenceValueAttribution $a, SequenceValueAttribution $b): int => $b->compositeScore <=> $a->compositeScore);

        $flatAttributions = array_map(
            fn (SequenceValueAttribution $a): array => [
                'sequence_id' => $a->sequenceId,
                'sequence' => $a->sequence,
                'composite_score' => round($a->compositeScore, 4),
                'value_grade' => $a->valueGrade,
                'occurrences' => $a->occurrences,
                'avg_ltv' => round($a->avgLtv, 2),
                'conversion_lift' => round($a->conversionLift, 4),
                'sequence_roi' => round($a->sequenceRoi, 2),
            ],
            $attributions,
        );

        $result = [
            'attributions' => $flatAttributions,
            'summary' => [
                'total_sequences' => count($attributions),
                'top_path' => ! empty($attributions) ? implode(' → ', $attributions[0]->sequence) : null,
                'avg_score' => count($attributions) > 0 ? round($totalScore / count($attributions), 4) : 0.0,
                'grade_distribution' => $gradeDistribution,
                'highest_ltv_path' => $highestLtv['path'],
                'fastest_path' => $fastestPath['duration'] < PHP_FLOAT_MAX ? $fastestPath['path'] : null,
            ],
        ];

        if ($this->cache !== null) {
            $this->cache->put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Get the top N highest-value sequences.
     *
     * @param  list<EventSequencePattern>  $patterns  List of detected patterns
     * @param  int  $n  Number of top sequences to return
     * @return list<SequenceValueAttribution>
     */
    public function topValueSequences(array $patterns, int $n = 5): array
    {
        $matrix = $this->attributeMatrix($patterns);

        // Rebuild full attributions from cached or fresh matrix
        $attributions = [];
        foreach ($patterns as $pattern) {
            $attributions[] = $this->attribute($pattern);
        }

        usort($attributions, fn (SequenceValueAttribution $a, SequenceValueAttribution $b): int => $b->compositeScore <=> $a->compositeScore);

        return array_slice($attributions, 0, $n);
    }

    /**
     * Get sequences with negative value (cancellation/refund dominated).
     *
     * @param  list<EventSequencePattern>  $patterns  List of detected patterns
     * @return list<array{sequence: list<string>, composite_score: float, value_grade: string, warning: string}>
     */
    public function negativeValueSequences(array $patterns): array
    {
        $results = [];

        foreach ($patterns as $pattern) {
            $attribution = $this->attribute($pattern);
            $revenueScore = $this->computeSequenceRevenueScore($pattern->sequence);

            if ($revenueScore < 0) {
                $results[] = [
                    'sequence' => $pattern->sequence,
                    'composite_score' => round($attribution->compositeScore, 4),
                    'value_grade' => $attribution->valueGrade,
                    'warning' => 'Sequence contains churn/negative revenue events — investigate funnel leaks',
                ];
            }
        }

        return $results;
    }

    /**
     * Compare value attribution between two sequences.
     *
     * @return array{sequence_a: array{score: float, grade: string, ltv: float, roi: float}, sequence_b: array{score: float, grade: string, ltv: float, roi: float}, delta: float, recommendation: string}
     */
    public function compare(EventSequencePattern $patternA, EventSequencePattern $patternB): array
    {
        $attrA = $this->attribute($patternA);
        $attrB = $this->attribute($patternB);

        $delta = round($attrA->compositeScore - $attrB->compositeScore, 4);
        $seqA = implode(' → ', $patternA->sequence);
        $seqB = implode(' → ', $patternB->sequence);

        if (abs($delta) < 0.05) {
            $recommendation = 'Comparable value — both sequences deliver similar business impact.';
        } elseif ($delta > 0) {
            $recommendation = "Path [{$seqA}] outperforms [{$seqB}] by +" . ($delta * 100) . '%. Prioritize acquisition for this funnel.';
        } else {
            $recommendation = "Path [{$seqB}] outperforms [{$seqA}] by +" . (abs($delta) * 100) . '%. Shift acquisition budget to the higher-value funnel.';
        }

        return [
            'sequence_a' => [
                'score' => round($attrA->compositeScore, 4),
                'grade' => $attrA->valueGrade,
                'ltv' => round($attrA->avgLtv, 2),
                'roi' => round($attrA->sequenceRoi, 2),
            ],
            'sequence_b' => [
                'score' => round($attrB->compositeScore, 4),
                'grade' => $attrB->valueGrade,
                'ltv' => round($attrB->avgLtv, 2),
                'roi' => round($attrB->sequenceRoi, 2),
            ],
            'delta' => $delta,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Get revenue multiplier for a specific event.
     */
    public function getEventRevenueMultiplier(string $eventName): float
    {
        return self::EVENT_REVENUE_MULTIPLIERS[$eventName] ?? 0.0;
    }

    /**
     * Get all event revenue multipliers.
     *
     * @return array<string, float>
     */
    public function getAllRevenueMultipliers(): array
    {
        return self::EVENT_REVENUE_MULTIPLIERS;
    }

    /**
     * Get the weight configuration.
     *
     * @return array{ltv: float, conversion: float, retention: float, revenue: float, velocity: float}
     */
    public function getWeights(): array
    {
        return [
            'ltv' => $this->weightLtv,
            'conversion' => $this->weightConversion,
            'retention' => $this->weightRetention,
            'revenue' => $this->weightRevenue,
            'velocity' => $this->weightVelocity,
        ];
    }

    /**
     * Clear the cached value matrix.
     */
    public function clearCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->forget(self::CACHE_MATRIX);
        }
    }

    /**
     * Compute the sum of revenue multipliers for a sequence.
     *
     * @param  list<string>  $sequence
     */
    private function computeSequenceRevenueScore(array $sequence): float
    {
        $total = 0.0;

        foreach ($sequence as $event) {
            $total += self::EVENT_REVENUE_MULTIPLIERS[$event] ?? 0.0;
        }

        return $total;
    }

    /**
     * Compute LTV correlation score (0.0–1.0).
     *
     * Sequences containing revenue events and high conversion get higher scores.
     *
     * @param  list<string>  $sequence
     */
    private function computeLtvScore(array $sequence, float $conversionRate): float
    {
        $revenueScore = $this->computeSequenceRevenueScore($sequence);

        if ($revenueScore <= 0) {
            return 0.1 * $conversionRate;
        }

        $normalizedRevenue = min(1.0, $revenueScore / 50.0);

        return $normalizedRevenue * (0.3 + 0.7 * $conversionRate);
    }

    /**
     * Compute retention score (0.0–1.0).
     *
     * Sequences with engagement and adoption events indicate higher retention.
     *
     * @param  list<string>  $sequence
     */
    private function computeRetentionScore(array $sequence): float
    {
        $retentionEvents = ['feature_used', 'feature_adopted', 'login', 'search', 'share', 'team_member_joined', 'integration_connected', 'page_view', 'form_submit'];
        $churnEvents = ['cancellation', 'subscription_cancelled', 'plan_downgrade', 'trial_expired', 'refund'];

        $retentionCount = 0;
        $churnCount = 0;

        foreach ($sequence as $event) {
            if (in_array($event, $retentionEvents, true)) {
                $retentionCount++;
            }
            if (in_array($event, $churnEvents, true)) {
                $churnCount++;
            }
        }

        $total = count($sequence);
        if ($total === 0) {
            return 0.5;
        }

        $retentionRatio = $retentionCount / $total;
        $churnPenalty = $churnCount > 0 ? min(0.5, $churnCount * 0.15) : 0.0;

        return max(0.0, min(1.0, $retentionRatio - $churnPenalty + 0.2));
    }

    /**
     * Compute velocity score (0.0–1.0).
     *
     * Shorter durations score higher (faster time-to-value).
     */
    private function computeVelocityScore(float $durationSeconds): float
    {
        if ($durationSeconds <= 0) {
            return 0.5;
        }

        // Logarithmic normalization: < 60s → 1.0, > 86400s → 0.0
        return max(0.0, min(1.0, 1.0 - (log10(max($durationSeconds, 1)) / log10(86400))));
    }

    /**
     * Normalize conversion lift to 0.0–1.0 range.
     *
     * Lift of +0.30 → 1.0, -0.10 → 0.0.
     */
    private function normalizeLift(float $lift): float
    {
        return max(0.0, min(1.0, ($lift + 0.10) / 0.40));
    }

    /**
     * Normalize revenue score to 0.0–1.0 range.
     *
     * Revenue score of 50+ → 1.0, negative → 0.0.
     */
    private function normalizeRevenue(float $revenueScore): float
    {
        if ($revenueScore <= 0) {
            return 0.0;
        }

        return min(1.0, $revenueScore / 50.0);
    }

    /**
     * Compute the weighted composite score.
     */
    private function computeCompositeScore(
        float $ltvScore,
        float $conversionScore,
        float $retentionScore,
        float $revenueScore,
        float $velocityScore,
    ): float {
        return (
            $ltvScore * $this->weightLtv
            + $conversionScore * $this->weightConversion
            + $retentionScore * $this->weightRetention
            + $revenueScore * $this->weightRevenue
            + $velocityScore * $this->weightVelocity
        );
    }

    /**
     * Map composite score to value grade.
     */
    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'S',
            $score >= 0.65 => 'A',
            $score >= 0.45 => 'B',
            $score >= 0.25 => 'C',
            default => 'D',
        };
    }
}
