<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Product-Market Fit (PMF) scoring engine based on analytics signals.
 *
 * Implements the Sean Ellis test methodology combined with behavioral cohort
 * analysis to compute a PMF score (0-100). The score aggregates signals from
 * user activation patterns, retention curves, feature adoption depth, and
 * organic growth indicators.
 *
 * Configuration is read from `zeroboiler.analytics.pmf`.
 *
 * @since 61.0.0
 */
final class ProductMarketFitScoringService
{
    /** @var array{enabled: bool, cache_ttl: int, weights: array<string, float>, thresholds: array{very_early: float, early: float, strong: float, excellent: float}} */
    private array $config;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  array<string, mixed>|ConfigRepository  $config  Config array or repository
     */
    public function __construct(CacheRepository $cache, array|ConfigRepository $config = []){
        $this->cache = $cache;

        // Support both direct array config and ConfigRepository (from service container)
        if ($config instanceof ConfigRepository) {
            $configArray = $config->get('zeroboiler.analytics.pmf', []);
            /** @var array<string, mixed> $configArray */
            $config = $configArray;
        }

        $this->config = [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'cache_ttl' => (int) ($config['cache_ttl'] ?? 3600), // 1 hour
            'weights' => [
                'activation_rate' => (float) ($config['weights']['activation_rate'] ?? 0.25),
                'retention_week2' => (float) ($config['weights']['retention_week2'] ?? 0.25),
                'feature_depth' => (float) ($config['weights']['feature_depth'] ?? 0.20),
                'organic_growth' => (float) ($config['weights']['organic_growth'] ?? 0.15),
                'nps_proxy' => (float) ($config['weights']['nps_proxy'] ?? 0.15),
            ],
            'thresholds' => [
                'very_early' => (float) ($config['thresholds']['very_early'] ?? 25.0),
                'early' => (float) ($config['thresholds']['early'] ?? 40.0),
                'strong' => (float) ($config['thresholds']['strong'] ?? 60.0),
                'excellent' => (float) ($config['thresholds']['excellent'] ?? 75.0),
            ],
        ];
    }

    /**
     * Compute the PMF score from aggregated analytics signals.
     *
     * @param  array{activation_rate?: float, retention_week2?: float, feature_depth_score?: float, organic_growth_rate?: float, nps_proxy?: float, weekly_signups?: int, weekly_active?: int, dau_mau_ratio?: float}  $signals
     * @return array{score: float, grade: string, grade_label: string, breakdown: array<string, float>, signals_received: list<string>, recommendations: list<string>}
     */
    public function compute(array $signals): array
    {
        $weights = $this->config['weights'];

        // Extract signals with defaults
        $activationRate = (float) ($signals['activation_rate'] ?? 0.0);
        $retentionWeek2 = (float) ($signals['retention_week2'] ?? 0.0);
        $featureDepth = (float) ($signals['feature_depth_score'] ?? 0.0);
        $organicGrowth = (float) ($signals['organic_growth_rate'] ?? 0.0);
        $npsProxy = (float) ($signals['nps_proxy'] ?? 0.0);

        // Normalize signals to 0-100 scale
        $normalizedActivation = $this->normalizeSignal($activationRate, 100);
        $normalizedRetention = $this->normalizeSignal($retentionWeek2, 80);
        $normalizedFeatureDepth = $this->normalizeSignal($featureDepth, 100);
        $normalizedOrganic = $this->normalizeSignal($organicGrowth, 50);
        $normalizedNps = $this->normalizeSignal($npsProxy, 100);

        // Calculate weighted score
        $score = (
            $normalizedActivation * $weights['activation_rate'] +
            $normalizedRetention * $weights['retention_week2'] +
            $normalizedFeatureDepth * $weights['feature_depth'] +
            $normalizedOrganic * $weights['organic_growth'] +
            $normalizedNps * $weights['nps_proxy']
        ) * 100;

        $score = round(min(max($score, 0.0), 100.0), 1);
        $grade = $this->calculateGrade($score);

        $breakdown = [
            'activation_rate' => round($normalizedActivation * $weights['activation_rate'] * 100, 1),
            'retention_week2' => round($normalizedRetention * $weights['retention_week2'] * 100, 1),
            'feature_depth' => round($normalizedFeatureDepth * $weights['feature_depth'] * 100, 1),
            'organic_growth' => round($normalizedOrganic * $weights['organic_growth'] * 100, 1),
            'nps_proxy' => round($normalizedNps * $weights['nps_proxy'] * 100, 1),
        ];

        // Track which signals were actually provided
        $received = [];
        if (isset($signals['activation_rate'])) {
            $received[] = 'activation_rate';
        }
        if (isset($signals['retention_week2'])) {
            $received[] = 'retention_week2';
        }
        if (isset($signals['feature_depth_score'])) {
            $received[] = 'feature_depth_score';
        }
        if (isset($signals['organic_growth_rate'])) {
            $received[] = 'organic_growth_rate';
        }
        if (isset($signals['nps_proxy'])) {
            $received[] = 'nps_proxy';
        }

        $recommendations = $this->generateRecommendations($breakdown, $received);

        return [
            'score' => $score,
            'grade' => $grade['grade'],
            'grade_label' => $grade['label'],
            'breakdown' => $breakdown,
            'signals_received' => $received,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Get the PMF score with cached result.
     *
     * @param  array{activation_rate?: float, retention_week2?: float, feature_depth_score?: float, organic_growth_rate?: float, nps_proxy?: float}  $signals
     * @param  string|null  $cacheKey  Optional cache key for multi-tenant scenarios
     * @return array{score: float, grade: string, grade_label: string, breakdown: array<string, float>, signals_received: list<string>, recommendations: list<string>}
     */
    public function computeCached(array $signals, ?string $cacheKey = null): array
    {
        $key = $cacheKey ?? 'zb_pmf_score';

        return $this->cache->remember($key, $this->config['cache_ttl'], function () use ($signals): array {
            return $this->compute($signals);
        });
    }

    /**
     * Get the current PMF grade thresholds.
     *
     * @return array{very_early: float, early: float, strong: float, excellent: float}
     */
    public function getThresholds(): array
    {
        return $this->config['thresholds'];
    }

    /**
     * Get the current signal weights.
     *
     * @return array<string, float>
     */
    public function getWeights(): array
    {
        return $this->config['weights'];
    }

    /**
     * Check if PMF scoring is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Invalidate cached PMF score.
     */
    public function invalidateCache(?string $cacheKey = null): void
    {
        $key = $cacheKey ?? 'zb_pmf_score';
        $this->cache->forget($key);
    }

    /**
     * Get a PMF summary for the SaaS maturity dashboard.
     *
     * @param  array{activation_rate?: float, retention_week2?: float, feature_depth_score?: float, organic_growth_rate?: float, nps_proxy?: float, weekly_signups?: int, weekly_active?: int, dau_mau_ratio?: float}  $signals
     * @return array{pmf_score: float, pmf_grade: string, pmf_grade_label: string, readiness: array{signals_count: int, max_signals: int, coverage: float}, top_signal: string|null, weakest_signal: string|null}
     */
    public function summary(array $signals): array
    {
        $result = $this->compute($signals);

        $maxSignals = 5;
        $signalCount = count($result['signals_received']);
        $coverage = $maxSignals > 0 ? round(($signalCount / $maxSignals) * 100, 1) : 0.0;

        // Find strongest and weakest signals
        $topSignal = null;
        $weakestSignal = null;
        $topValue = -1.0;
        $weakestValue = 101.0;

        foreach ($result['breakdown'] as $name => $value) {
            if ($value > $topValue) {
                $topValue = $value;
                $topSignal = $name;
            }
            if ($value < $weakestValue) {
                $weakestValue = $value;
                $weakestSignal = $name;
            }
        }

        return [
            'pmf_score' => $result['score'],
            'pmf_grade' => $result['grade'],
            'pmf_grade_label' => $result['grade_label'],
            'readiness' => [
                'signals_count' => $signalCount,
                'max_signals' => $maxSignals,
                'coverage' => $coverage,
            ],
            'top_signal' => $topSignal,
            'weakest_signal' => $weakestSignal,
        ];
    }

    /**
     * Normalize a signal value to a 0-1 scale.
     *
     * @param  float  $value  The signal value (percentage or ratio)
     * @param  float  $maxExpected  The maximum expected value (cap for normalization)
     * @return float  Normalized value between 0.0 and 1.0
     */
    private function normalizeSignal(float $value, float $maxExpected): float
    {
        if ($maxExpected <= 0.0) {
            return 0.0;
        }

        return min(max($value / $maxExpected, 0.0), 1.0);
    }

    /**
     * Calculate the PMF grade from the score.
     *
     * @param  float  $score  PMF score 0-100
     * @return array{grade: string, label: string}
     */
    private function calculateGrade(float $score): array
    {
        $thresholds = $this->config['thresholds'];

        if ($score >= $thresholds['excellent']) {
            return ['grade' => 'A+', 'label' => 'Excellent PMF — strong product-market fit'];
        }

        if ($score >= $thresholds['strong']) {
            return ['grade' => 'A', 'label' => 'Strong PMF — approaching product-market fit'];
        }

        if ($score >= $thresholds['early']) {
            return ['grade' => 'B', 'label' => 'Early PMF — promising signals, needs improvement'];
        }

        if ($score >= $thresholds['very_early']) {
            return ['grade' => 'C', 'label' => 'Very Early PMF — still searching for fit'];
        }

        return ['grade' => 'D', 'label' => 'Pre-PMF — significant signal gaps'];
    }

    /**
     * Generate actionable recommendations based on the score breakdown.
     *
     * @param  array<string, float>  $breakdown  Signal contribution breakdown
     * @param  list<string>  $received  Which signals were provided
     * @return list<string>
     */
    private function generateRecommendations(array $breakdown, array $received): array
    {
        $recommendations = [];

        // Check for missing signals
        $allSignals = ['activation_rate', 'retention_week2', 'feature_depth_score', 'organic_growth_rate', 'nps_proxy'];
        $missing = array_values(array_diff($allSignals, $received));

        foreach ($missing as $signal) {
            $recommendations[] = match ($signal) {
                'activation_rate' => 'Instrument signup → first-feature conversion to measure activation rate',
                'retention_week2' => 'Track week-2 retention with login/session events to measure stickiness',
                'feature_depth_score' => 'Monitor feature adoption breadth to measure product depth engagement',
                'organic_growth_rate' => 'Track share, invite, and referral events to measure viral coefficient',
                'nps_proxy' => 'Implement feedback or goal_conversion tracking to measure user satisfaction',
                default => "Provide the '{$signal}' signal for a more accurate PMF score",
            };
        }

        // Check for weak signals (below 15% contribution)
        foreach ($breakdown as $signal => $contribution) {
            if ($contribution < 15.0 && in_array($signal, $received, true)) {
                $recommendations[] = match ($signal) {
                    'activation_rate' => 'Activation rate is low — optimize onboarding flow and reduce time-to-value',
                    'retention_week2' => 'Week-2 retention is weak — add re-engagement triggers and email sequences',
                    'feature_depth' => 'Feature adoption is shallow — guide users to discover more features',
                    'organic_growth' => 'Organic growth is low — implement referral incentives and share triggers',
                    'nps_proxy' => 'User satisfaction proxy is low — collect feedback and address pain points',
                    default => "Improve the '{$signal}' signal for a higher PMF score",
                };
            }
        }

        return $recommendations;
    }
}
