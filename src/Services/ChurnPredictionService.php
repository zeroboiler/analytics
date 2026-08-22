<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Churn prediction and risk scoring service for SaaS analytics.
 *
 * Scores users based on configurable risk signals (inactivity, usage decline,
 * support tickets, billing issues) and identifies at-risk accounts before
 * they churn. Uses a weighted scoring model with configurable thresholds.
 *
 * Configuration is read from `zeroboiler.analytics.churn_prediction`.
 *
 * @phpstan-type RiskSignal array{name: string, weight: float, value: float, max_value: float, score: float}
 * @phpstan-type ChurnRiskProfile array{user_id: string, overall_score: float, risk_level: 'low'|'medium'|'high'|'critical', signals: list<RiskSignal>, recommendation: string, probability_percent: float}
 *
 * @since 1.0.0
 */
final class ChurnPredictionService
{
    private int $cacheTtl;

    private string $cachePrefix;

    /** @var array<string, float> Signal weights */
    private array $signalWeights;

    private int $highRiskThreshold;

    private int $mediumRiskThreshold;

    private int $criticalRiskThreshold;

    private int $inactiveDaysThreshold;

    private const CACHE_PREFIX = 'zb_churn_';

    /**
     * Default signal weights for churn prediction.
     *
     * @return array<string, float>
     */
    private static function defaultWeights(): array
    {
        return [
            'days_inactive' => 25.0,
            'usage_decline_pct' => 20.0,
            'support_tickets_30d' => 15.0,
            'failed_payments_90d' => 20.0,
            'feature_adoption_low' => 10.0,
            'contract_expiring_30d' => 15.0,
            'billing_disputes' => 20.0,
            'login_frequency_decline' => 15.0,
            'engagement_score_low' => 10.0,
            'plan_downgrade_recent' => 25.0,
        ];
    }

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $churnConfig = $config->get('zeroboiler.analytics.churn_prediction', []);
        /** @var array{cache_ttl?: int, cache_prefix?: string, signal_weights?: array<string, float>, high_risk_threshold?: int, medium_risk_threshold?: int, critical_risk_threshold?: int, inactive_days_threshold?: int} $churnConfig */

        $this->cacheTtl = (int) ($churnConfig['cache_ttl'] ?? 600); // 10 minutes
        $this->cachePrefix = (string) ($churnConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->signalWeights = array_merge(
            self::defaultWeights(),
            $churnConfig['signal_weights'] ?? [],
        );
        $this->highRiskThreshold = (int) ($churnConfig['high_risk_threshold'] ?? 60);
        $this->mediumRiskThreshold = (int) ($churnConfig['medium_risk_threshold'] ?? 30);
        $this->criticalRiskThreshold = (int) ($churnConfig['critical_risk_threshold'] ?? 80);
        $this->inactiveDaysThreshold = (int) ($churnConfig['inactive_days_threshold'] ?? 14);
    }

    /**
     * Calculate churn risk score for a single user.
     *
     * Evaluates all configured signals and produces a composite score (0-100).
     *
     * @param  string  $userId  The user to score
     * @param  array{days_inactive?: int, usage_decline_pct?: float, support_tickets_30d?: int, failed_payments_90d?: int, feature_adoption_pct?: float, contract_expiring_30d?: bool, billing_disputes?: int, login_frequency_decline_pct?: float, engagement_score?: float, plan_downgrade_recent?: bool}  $signals  User behavior signals
     * @return ChurnRiskProfile
     */
    public function scoreUser(string $userId, array $signals = []): array
    {
        $evaluatedSignals = $this->evaluateSignals($signals);
        $overallScore = $this->calculateOverallScore($evaluatedSignals);

        $riskLevel = $this->determineRiskLevel($overallScore);
        $probabilityPercent = min(100.0, $overallScore * 1.1); // Calibrated estimate
        $recommendation = $this->generateRecommendation($riskLevel, $evaluatedSignals);

        return [
            'user_id' => $userId,
            'overall_score' => round($overallScore, 1),
            'risk_level' => $riskLevel,
            'signals' => $evaluatedSignals,
            'recommendation' => $recommendation,
            'probability_percent' => round($probabilityPercent, 1),
        ];
    }

    /**
     * Score multiple users and return ranked results.
     *
     * @param  list<array{user_id: string, days_inactive?: int, usage_decline_pct?: float, support_tickets_30d?: int, failed_payments_90d?: int, feature_adoption_pct?: float, contract_expiring_30d?: bool, billing_disputes?: int, login_frequency_decline_pct?: float, engagement_score?: float, plan_downgrade_recent?: bool}>  $users
     * @return array{ranked: list<ChurnRiskProfile>, summary: array{total: int, critical: int, high: int, medium: int, low: int, avg_score: float}, at_risk_count: int}
     */
    public function scoreBatch(array $users): array
    {
        $profiles = [];
        $critical = 0;
        $high = 0;
        $medium = 0;
        $low = 0;
        $totalScore = 0.0;

        foreach ($users as $userData) {
            $userId = (string) ($userData['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }

            $signals = array_filter(
                $userData,
                fn (string $key): bool => $key !== 'user_id',
                ARRAY_FILTER_USE_KEY,
            );

            $profile = $this->scoreUser($userId, $signals);
            $profiles[] = $profile;
            $totalScore += $profile['overall_score'];

            match ($profile['risk_level']) {
                'critical' => $critical++,
                'high' => $high++,
                'medium' => $medium++,
                'low' => $low++,
            };
        }

        // Sort by score descending (highest risk first)
        usort($profiles, fn (array $a, array $b): int => $b['overall_score'] <=> $a['overall_score']);

        $total = count($profiles);

        return [
            'ranked' => $profiles,
            'summary' => [
                'total' => $total,
                'critical' => $critical,
                'high' => $high,
                'medium' => $medium,
                'low' => $low,
                'avg_score' => $total > 0 ? round($totalScore / $total, 1) : 0,
            ],
            'at_risk_count' => $critical + $high,
        ];
    }

    /**
     * Get a summary of churn risk across a cohort.
     *
     * Returns aggregate statistics without per-user details.
     *
     * @param  list<array{user_id: string, days_inactive?: int}>  $users
     * @return array{total_users: int, risk_distribution: array{low: int, medium: int, high: int, critical: int}, avg_risk_score: float, estimated_monthly_churn_revenue: float, top_risk_factors: list<array{signal: string, avg_score: float}>}
     */
    public function cohortRiskSummary(array $users): array
    {
        $batch = $this->scoreBatch($users);
        $summary = $batch['summary'];

        // Identify top risk factors across the cohort
        $signalScores = [];
        $revenueAtRisk = 0.0;

        foreach ($batch['ranked'] as $profile) {
            if ($profile['risk_level'] === 'critical' || $profile['risk_level'] === 'high') {
                // Estimate: ~99 ARPU for revenue at risk
                $revenueAtRisk += 99.0;
            }

            foreach ($profile['signals'] as $signal) {
                $name = $signal['name'];
                if (! isset($signalScores[$name])) {
                    $signalScores[$name] = [];
                }
                $signalScores[$name][] = $signal['score'];
            }
        }

        // Average signal scores across cohort
        $topRiskFactors = [];
        foreach ($signalScores as $name => $scores) {
            $avg = array_sum($scores) / count($scores);
            $topRiskFactors[] = [
                'signal' => $name,
                'avg_score' => round($avg, 1),
            ];
        }

        usort($topRiskFactors, fn (array $a, array $b): int => $b['avg_score'] <=> $a['avg_score']);
        $topRiskFactors = array_slice($topRiskFactors, 0, 5);

        return [
            'total_users' => $summary['total'],
            'risk_distribution' => [
                'low' => $summary['low'],
                'medium' => $summary['medium'],
                'high' => $summary['high'],
                'critical' => $summary['critical'],
            ],
            'avg_risk_score' => $summary['avg_score'],
            'estimated_monthly_churn_revenue' => round($revenueAtRisk, 2),
            'top_risk_factors' => $topRiskFactors,
        ];
    }

    /**
     * Get configured signal weights.
     *
     * @return array<string, float>
     */
    public function getSignalWeights(): array
    {
        return $this->signalWeights;
    }

    /**
     * Get configured risk thresholds.
     *
     * @return array{medium: int, high: int, critical: int}
     */
    public function getThresholds(): array
    {
        return [
            'medium' => $this->mediumRiskThreshold,
            'high' => $this->highRiskThreshold,
            'critical' => $this->criticalRiskThreshold,
        ];
    }

    /**
     * Clear the churn prediction cache.
     */
    public function clearCache(): void
    {
        try {
            Cache::forget($this->cachePrefix . 'batch_');
            Cache::forget($this->cachePrefix . 'cohort_');
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Evaluate all signals and normalize to 0-100 scale.
     *
     * @param  array<string, mixed>  $signals
     * @return list<RiskSignal>
     */
    private function evaluateSignals(array $signals): array
    {
        $evaluated = [];

        // Days inactive (0 = active, > threshold = max risk)
        $daysInactive = (int) ($signals['days_inactive'] ?? 0);
        $evaluated[] = [
            'name' => 'days_inactive',
            'weight' => $this->signalWeights['days_inactive'] ?? 25.0,
            'value' => (float) $daysInactive,
            'max_value' => (float) $this->inactiveDaysThreshold * 3,
            'score' => $this->normalizeScore($daysInactive, 0, $this->inactiveDaysThreshold * 3),
        ];

        // Usage decline percentage (0 = stable, 100 = total decline)
        $usageDecline = (float) ($signals['usage_decline_pct'] ?? 0);
        $evaluated[] = [
            'name' => 'usage_decline_pct',
            'weight' => $this->signalWeights['usage_decline_pct'] ?? 20.0,
            'value' => $usageDecline,
            'max_value' => 100.0,
            'score' => $this->normalizeScore($usageDecline, 0, 100),
        ];

        // Support tickets in last 30 days
        $supportTickets = (int) ($signals['support_tickets_30d'] ?? 0);
        $evaluated[] = [
            'name' => 'support_tickets_30d',
            'weight' => $this->signalWeights['support_tickets_30d'] ?? 15.0,
            'value' => (float) $supportTickets,
            'max_value' => 5.0,
            'score' => $this->normalizeScore($supportTickets, 0, 5),
        ];

        // Failed payments in 90 days
        $failedPayments = (int) ($signals['failed_payments_90d'] ?? 0);
        $evaluated[] = [
            'name' => 'failed_payments_90d',
            'weight' => $this->signalWeights['failed_payments_90d'] ?? 20.0,
            'value' => (float) $failedPayments,
            'max_value' => 3.0,
            'score' => $this->normalizeScore($failedPayments, 0, 3),
        ];

        // Feature adoption (inverse: low adoption = high risk)
        $featureAdoption = (float) ($signals['feature_adoption_pct'] ?? 100);
        $evaluated[] = [
            'name' => 'feature_adoption_low',
            'weight' => $this->signalWeights['feature_adoption_low'] ?? 10.0,
            'value' => $featureAdoption,
            'max_value' => 100.0,
            'score' => 100.0 - $this->normalizeScore($featureAdoption, 0, 100),
        ];

        // Contract expiring
        $contractExpiring = (bool) ($signals['contract_expiring_30d'] ?? false);
        $evaluated[] = [
            'name' => 'contract_expiring_30d',
            'weight' => $this->signalWeights['contract_expiring_30d'] ?? 15.0,
            'value' => $contractExpiring ? 1.0 : 0.0,
            'max_value' => 1.0,
            'score' => $contractExpiring ? 100.0 : 0.0,
        ];

        // Billing disputes
        $billingDisputes = (int) ($signals['billing_disputes'] ?? 0);
        $evaluated[] = [
            'name' => 'billing_disputes',
            'weight' => $this->signalWeights['billing_disputes'] ?? 20.0,
            'value' => (float) $billingDisputes,
            'max_value' => 2.0,
            'score' => $this->normalizeScore($billingDisputes, 0, 2),
        ];

        // Login frequency decline
        $loginDecline = (float) ($signals['login_frequency_decline_pct'] ?? 0);
        $evaluated[] = [
            'name' => 'login_frequency_decline',
            'weight' => $this->signalWeights['login_frequency_decline'] ?? 15.0,
            'value' => $loginDecline,
            'max_value' => 100.0,
            'score' => $this->normalizeScore($loginDecline, 0, 100),
        ];

        // Engagement score (inverse)
        $engagementScore = (float) ($signals['engagement_score'] ?? 100);
        $evaluated[] = [
            'name' => 'engagement_score_low',
            'weight' => $this->signalWeights['engagement_score_low'] ?? 10.0,
            'value' => $engagementScore,
            'max_value' => 100.0,
            'score' => 100.0 - $this->normalizeScore($engagementScore, 0, 100),
        ];

        // Recent plan downgrade
        $planDowngrade = (bool) ($signals['plan_downgrade_recent'] ?? false);
        $evaluated[] = [
            'name' => 'plan_downgrade_recent',
            'weight' => $this->signalWeights['plan_downgrade_recent'] ?? 25.0,
            'value' => $planDowngrade ? 1.0 : 0.0,
            'max_value' => 1.0,
            'score' => $planDowngrade ? 100.0 : 0.0,
        ];

        return $evaluated;
    }

    /**
     * Calculate overall churn risk score from evaluated signals.
     *
     * Uses weighted average: score = Σ(signal_score × weight) / Σ(weight)
     *
     * @param  list<RiskSignal>  $signals
     * @return float Score 0-100
     */
    private function calculateOverallScore(array $signals): float
    {
        $totalWeighted = 0.0;
        $totalWeight = 0.0;

        foreach ($signals as $signal) {
            $totalWeighted += $signal['score'] * $signal['weight'];
            $totalWeight += $signal['weight'];
        }

        return $totalWeight > 0 ? min(100.0, $totalWeighted / $totalWeight) : 0.0;
    }

    /**
     * Determine risk level from score.
     *
     * @param  float  $score
     * @return 'low'|'medium'|'high'|'critical'
     */
    private function determineRiskLevel(float $score): string
    {
        return match (true) {
            $score >= $this->criticalRiskThreshold => 'critical',
            $score >= $this->highRiskThreshold => 'high',
            $score >= $this->mediumRiskThreshold => 'medium',
            default => 'low',
        };
    }

    /**
     * Generate actionable recommendation based on risk level and signals.
     *
     * @param  'low'|'medium'|'high'|'critical'  $riskLevel
     * @param  list<RiskSignal>  $signals
     * @return string
     */
    private function generateRecommendation(string $riskLevel, array $signals): string
    {
        // Find top contributing signal
        $topSignal = array_reduce(
            $signals,
            fn (?array $carry, array $signal): ?array => $carry === null || $signal['score'] > $carry['score'] ? $signal : $carry,
        );

        $topSignalName = $topSignal['name'] ?? 'unknown';

        return match ($riskLevel) {
            'critical' => "Critical churn risk. Immediate intervention required. Primary factor: {$topSignalName}. Consider proactive outreach and retention offer.",
            'high' => "High churn risk detected. Primary factor: {$topSignalName}. Schedule retention call within 48 hours.",
            'medium' => "Moderate churn risk. Monitor closely. Primary factor: {$topSignalName}. Consider engagement campaign.",
            'low' => "Low churn risk. Account appears healthy. Continue standard monitoring.",
        };
    }

    /**
     * Normalize a value to a 0-100 scale.
     *
     * @param  float  $value
     * @param  float  $min
     * @param  float  $max
     * @return float 0-100
     */
    private function normalizeScore(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        $normalized = (($value - $min) / ($max - $min)) * 100;

        return max(0.0, min(100.0, $normalized));
    }
}
