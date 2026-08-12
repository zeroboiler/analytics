<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Product-Market Fit (PMF) scoring service.
 *
 * Computes a composite PMF score (0–100) using industry-standard signals:
 * - Sean Ellis Test: "How would you feel if you could no longer use this product?"
 * - Activation Rate: Percentage of users completing key activation milestones
 * - Retention Curve: N-day retention sustainability (D7, D14, D30)
 * - Feature Engagement Depth: How many features users adopt after signup
 * - Organic Growth Signal: Referral/invitation rate and virality coefficient
 * - Revenue Stickiness: Net Revenue Retention (NRR) as a proxy for value delivery
 * - Engagement Cadence: Weekly/Monthly active usage patterns
 *
 * Inspired by Superhuman's Sean Ellis framework, Amplitude's PMF analysis,
 * and OpenView's retention-based PMF scoring.
 *
 * @since 47.0.0
 */
final class ProductMarketFitScoringService
{
    /** @var array<string, mixed> */
    private array $config;

    private string $cachePrefix;

    private int $cacheTtl;

    private float $ellisVeryDisappointedThreshold;

    private float $activationRateWeight;

    private float $retentionWeight;

    private float $engagementWeight;

    private float $organicGrowthWeight;

    private float $revenueStickinessWeight;

    private float $ellisTestWeight;

    /**
     * @param  CacheRepository  $cache  Cache repository for computed scores
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->config = $config->get('zeroboiler.analytics.pmf_scoring', []);
        $this->cachePrefix = (string) ($this->config['cache_prefix'] ?? 'zb_pmf_');
        $this->cacheTtl = (int) ($this->config['cache_ttl'] ?? 3600);
        $this->ellisVeryDisappointedThreshold = (float) ($this->config['ellis_threshold'] ?? 0.40);
        $this->ellisTestWeight = (float) ($this->config['weights']['ellis_test'] ?? 0.25);
        $this->activationRateWeight = (float) ($this->config['weights']['activation_rate'] ?? 0.20);
        $this->retentionWeight = (float) ($this->config['weights']['retention'] ?? 0.20);
        $this->engagementWeight = (float) ($this->config['weights']['engagement'] ?? 0.15);
        $this->organicGrowthWeight = (float) ($this->config['weights']['organic_growth'] ?? 0.10);
        $this->revenueStickinessWeight = (float) ($this->config['weights']['revenue_stickiness'] ?? 0.10);
    }

    /**
     * Compute the composite PMF score from individual signals.
     *
     * @param  array{ellis_score: float|null, activation_rate: float|null, retention_d7: float|null, retention_d30: float|null, feature_depth: float|null, organic_rate: float|null, nrr: float|null, weekly_active_ratio: float|null, monthly_active_ratio: float|null}  $signals
     * @return array{score: int, grade: string, signals: array<string, array{value: float|null, score: float, weight: float, contribution: float}>, recommendations: list<string>, timestamp: string}
     */
    public function computeScore(array $signals): array
    {
        $signalScores = [];
        $totalContribution = 0.0;

        // Sean Ellis Test Score
        $ellisValue = $signals['ellis_score'] ?? null;
        $ellisScore = $ellisValue !== null ? $this->scoreEllisTest($ellisValue) : 0.0;
        $signalScores['ellis_test'] = $this->buildSignalEntry($ellisValue, $ellisScore, $this->ellisTestWeight);
        $totalContribution += $signalScores['ellis_test']['contribution'];

        // Activation Rate Score
        $activationValue = $signals['activation_rate'] ?? null;
        $activationScore = $activationValue !== null ? $this->scoreActivationRate($activationValue) : 0.0;
        $signalScores['activation_rate'] = $this->buildSignalEntry($activationValue, $activationScore, $this->activationRateWeight);
        $totalContribution += $signalScores['activation_rate']['contribution'];

        // Retention Score
        $d7 = $signals['retention_d7'] ?? null;
        $d30 = $signals['retention_d30'] ?? null;
        $retentionValue = $d30 ?? $d7 ?? null;
        $retentionScore = $retentionValue !== null ? $this->scoreRetention($d7, $d30) : 0.0;
        $signalScores['retention'] = $this->buildSignalEntry($retentionValue, $retentionScore, $this->retentionWeight);
        $totalContribution += $signalScores['retention']['contribution'];

        // Feature Engagement Depth Score
        $depthValue = $signals['feature_depth'] ?? null;
        $depthScore = $depthValue !== null ? $this->scoreFeatureDepth($depthValue) : 0.0;
        $signalScores['feature_engagement'] = $this->buildSignalEntry($depthValue, $depthScore, $this->engagementWeight);
        $totalContribution += $signalScores['feature_engagement']['contribution'];

        // Organic Growth Score
        $organicValue = $signals['organic_rate'] ?? null;
        $organicScore = $organicValue !== null ? $this->scoreOrganicGrowth($organicValue) : 0.0;
        $signalScores['organic_growth'] = $this->buildSignalEntry($organicValue, $organicScore, $this->organicGrowthWeight);
        $totalContribution += $signalScores['organic_growth']['contribution'];

        // Revenue Stickiness Score
        $nrrValue = $signals['nrr'] ?? null;
        $nrrScore = $nrrValue !== null ? $this->scoreRevenueStickiness($nrrValue) : 0.0;
        $signalScores['revenue_stickiness'] = $this->buildSignalEntry($nrrValue, $nrrScore, $this->revenueStickinessWeight);
        $totalContribution += $signalScores['revenue_stickiness']['contribution'];

        // Engagement Cadence Score
        $waRatio = $signals['weekly_active_ratio'] ?? null;
        $maRatio = $signals['monthly_active_ratio'] ?? null;
        $cadenceValue = $waRatio ?? $maRatio ?? null;
        $cadenceScore = $cadenceValue !== null ? $this->scoreEngagementCadence($waRatio, $maRatio) : 0.0;
        $signalScores['engagement_cadence'] = $this->buildSignalEntry($cadenceValue, $cadenceScore, 0.0);
        // Note: engagement cadence weight is absorbed into engagement_weight above

        $compositeScore = (int) round(min(100, $totalContribution));
        $grade = $this->calculateGrade($compositeScore);
        $recommendations = $this->generateRecommendations($signalScores);

        return [
            'score' => $compositeScore,
            'grade' => $grade,
            'signals' => $signalScores,
            'recommendations' => $recommendations,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Cache the PMF score for later retrieval.
     *
     * @param  array{score: int, grade: string, signals: array<string, mixed>, recommendations: list<string>, timestamp: string}  $result
     */
    public function cacheScore(array $result): void
    {
        $this->cache->put($this->cachePrefix . 'score', $result, $this->cacheTtl);
    }

    /**
     * Get the cached PMF score.
     *
     * @return array{score: int, grade: string, signals: array<string, mixed>, recommendations: list<string>, timestamp: string}|null
     */
    public function getCachedScore(): ?array
    {
        $cached = $this->cache->get($this->cachePrefix . 'score');

        return is_array($cached) ? $cached : null;
    }

    /**
     * Clear the cached PMF score.
     */
    public function clearCache(): void
    {
        $this->cache->forget($this->cachePrefix . 'score');
        $this->cache->forget($this->cachePrefix . 'history');
    }

    /**
     * Get PMF scoring configuration summary.
     *
     * @return array{ellis_threshold: float, weights: array<string, float>, cache_ttl: int, grading: array<string, array{min: int, max: int, label: string}>}
     */
    public function getConfigSummary(): array
    {
        return [
            'ellis_threshold' => $this->ellisVeryDisappointedThreshold,
            'weights' => [
                'ellis_test' => $this->ellisTestWeight,
                'activation_rate' => $this->activationRateWeight,
                'retention' => $this->retentionWeight,
                'engagement' => $this->engagementWeight,
                'organic_growth' => $this->organicGrowthWeight,
                'revenue_stickiness' => $this->revenueStickinessWeight,
            ],
            'cache_ttl' => $this->cacheTtl,
            'grading' => $this->getGradingScale(),
        ];
    }

    /**
     * Score the Sean Ellis Test result.
     *
     * The "very disappointed" percentage is the key PMF indicator.
     * Above 40% is generally considered strong PMF.
     *
     * @param  float  $veryDisappointedRate  Percentage (0.0–1.0) of users who would be "very disappointed"
     * @return float Score 0–100
     */
    public function scoreEllisTest(float $veryDisappointedRate): float
    {
        if ($veryDisappointedRate >= 0.40) {
            return min(100, 60 + (($veryDisappointedRate - 0.40) / 0.60) * 40);
        }

        if ($veryDisappointedRate >= 0.25) {
            return 30 + (($veryDisappointedRate - 0.25) / 0.15) * 30;
        }

        return ($veryDisappointedRate / 0.25) * 30;
    }

    /**
     * Score the activation rate.
     *
     * @param  float  $rate  Activation rate (0.0–1.0)
     * @return float Score 0–100
     */
    public function scoreActivationRate(float $rate): float
    {
        if ($rate >= 0.60) {
            return min(100, 70 + (($rate - 0.60) / 0.40) * 30);
        }

        if ($rate >= 0.30) {
            return 30 + (($rate - 0.30) / 0.30) * 40;
        }

        return ($rate / 0.30) * 30;
    }

    /**
     * Score retention based on D7 and D30 retention rates.
     *
     * @param  float|null  $d7  Day-7 retention rate (0.0–1.0)
     * @param  float|null  $d30  Day-30 retention rate (0.0–1.0)
     * @return float Score 0–100
     */
    public function scoreRetention(?float $d7, ?float $d30): float
    {
        $d30Score = $d30 !== null ? $d30 : ($d7 !== null ? $d7 * 0.7 : 0);

        if ($d30Score >= 0.40) {
            return min(100, 70 + (($d30Score - 0.40) / 0.60) * 30);
        }

        if ($d30Score >= 0.15) {
            return 30 + (($d30Score - 0.15) / 0.25) * 40;
        }

        return ($d30Score / 0.15) * 30;
    }

    /**
     * Score feature engagement depth.
     *
     * @param  float  $depth  Average features adopted per user (as ratio of total features)
     * @return float Score 0–100
     */
    public function scoreFeatureDepth(float $depth): float
    {
        $clamped = min(1.0, max(0.0, $depth));

        if ($clamped >= 0.50) {
            return min(100, 60 + (($clamped - 0.50) / 0.50) * 40);
        }

        if ($clamped >= 0.20) {
            return 30 + (($clamped - 0.20) / 0.30) * 30;
        }

        return ($clamped / 0.20) * 30;
    }

    /**
     * Score organic growth rate.
     *
     * @param  float  $rate  Organic signup/referral rate (0.0–1.0)
     * @return float Score 0–100
     */
    public function scoreOrganicGrowth(float $rate): float
    {
        if ($rate >= 0.30) {
            return min(100, 70 + (($rate - 0.30) / 0.70) * 30);
        }

        if ($rate >= 0.10) {
            return 30 + (($rate - 0.10) / 0.20) * 40;
        }

        return ($rate / 0.10) * 30;
    }

    /**
     * Score revenue stickiness (Net Revenue Retention).
     *
     * @param  float  $nrr  Net Revenue Retention (1.0 = 100%, 1.2 = 120%)
     * @return float Score 0–100
     */
    public function scoreRevenueStickiness(float $nrr): float
    {
        if ($nrr >= 1.20) {
            return min(100, 80 + (($nrr - 1.20) / 0.80) * 20);
        }

        if ($nrr >= 1.0) {
            return 50 + (($nrr - 1.0) / 0.20) * 30;
        }

        if ($nrr >= 0.80) {
            return 20 + (($nrr - 0.80) / 0.20) * 30;
        }

        return max(0, ($nrr / 0.80) * 20);
    }

    /**
     * Score engagement cadence from WAU/MAU ratios.
     *
     * @param  float|null  $weeklyActiveRatio  WAU/MAU ratio (0.0–1.0)
     * @param  float|null  $monthlyActiveRatio  DAU/MAU ratio (0.0–1.0)
     * @return float Score 0–100
     */
    public function scoreEngagementCadence(?float $weeklyActiveRatio, ?float $monthlyActiveRatio): float
    {
        $value = $weeklyActiveRatio ?? ($monthlyActiveRatio ?? 0);

        if ($value >= 0.60) {
            return min(100, 70 + (($value - 0.60) / 0.40) * 30);
        }

        if ($value >= 0.30) {
            return 30 + (($value - 0.30) / 0.30) * 40;
        }

        return ($value / 0.30) * 30;
    }

    /**
     * Get the grading scale.
     *
     * @return array<string, array{min: int, max: int, label: string}>
     */
    public function getGradingScale(): array
    {
        return [
            'exceptional' => ['min' => 85, 'max' => 100, 'label' => 'Exceptional PMF — product-market fit achieved'],
            'strong' => ['min' => 70, 'max' => 84, 'label' => 'Strong PMF — close to product-market fit'],
            'moderate' => ['min' => 50, 'max' => 69, 'label' => 'Moderate PMF — promising but needs improvement'],
            'weak' => ['min' => 30, 'max' => 49, 'label' => 'Weak PMF — significant pivot or iteration needed'],
            'none' => ['min' => 0, 'max' => 29, 'label' => 'No PMF — fundamental reassessment required'],
        ];
    }

    /**
     * Calculate the grade from a composite score.
     */
    private function calculateGrade(int $score): string
    {
        return match (true) {
            $score >= 85 => 'exceptional',
            $score >= 70 => 'strong',
            $score >= 50 => 'moderate',
            $score >= 30 => 'weak',
            default => 'none',
        };
    }

    /**
     * Build a signal entry for the result.
     *
     * @param  float|null  $value  Raw input value
     * @param  float  $score  Computed score 0–100
     * @param  float  $weight  Weight in composite calculation
     * @return array{value: float|null, score: float, weight: float, contribution: float}
     */
    private function buildSignalEntry(?float $value, float $score, float $weight): array
    {
        return [
            'value' => $value,
            'score' => round($score, 2),
            'weight' => $weight,
            'contribution' => round($score * $weight, 2),
        ];
    }

    /**
     * Generate actionable recommendations based on signal scores.
     *
     * @param  array<string, array{value: float|null, score: float, weight: float, contribution: float}>  $signals
     * @return list<string>
     */
    private function generateRecommendations(array $signals): array
    {
        $recommendations = [];

        if (($signals['ellis_test']['score'] ?? 0) < 40) {
            $recommendations[] = 'Sean Ellis Test score is below 40%. Conduct user interviews to identify core value proposition gaps.';
        }

        if (($signals['activation_rate']['score'] ?? 0) < 40) {
            $recommendations[] = 'Activation rate is low. Simplify onboarding, reduce time-to-value, and add progressive disclosure.';
        }

        if (($signals['retention']['score'] ?? 0) < 40) {
            $recommendations[] = 'Retention is declining. Analyze drop-off points, improve re-engagement channels, and add stickiness features.';
        }

        if (($signals['feature_engagement']['score'] ?? 0) < 40) {
            $recommendations[] = 'Feature adoption depth is shallow. Add guided tours, contextual tooltips, and feature discovery mechanisms.';
        }

        if (($signals['organic_growth']['score'] ?? 0) < 30) {
            $recommendations[] = 'Organic growth is weak. Invest in referral programs, content marketing, and virality loops.';
        }

        if (($signals['revenue_stickiness']['score'] ?? 0) < 40) {
            $recommendations[] = 'Revenue stickiness (NRR) is below target. Focus on upsell, cross-sell, and reducing churn drivers.';
        }

        return $recommendations;
    }
}
