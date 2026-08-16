<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS Health Score service for measuring overall product health.
 *
 * Computes a composite health score (0–100) from multiple SaaS KPIs:
 * - Engagement health (event frequency, DAU/MAU ratio)
 * - Revenue health (MRR growth, churn rate, ARPU trend)
 * - Conversion health (trial-to-paid, signup-to-trial)
 * - Retention health (cohort retention, session recurrence)
 *
 * Individual sub-scores are weighted and combined into an overall score.
 * Health trends are tracked over time for dashboard visualization.
 *
 * Configuration: `zeroboiler.analytics.health_score`
 *
 * @since 1.0.0
 */
final class SaaSHealthScoreService
{
    private const CACHE_PREFIX = 'zb_saas_health_';

    private const DEFAULT_TTL = 86400; // 24 hours

    private const DEFAULT_HISTORY_LIMIT = 90;

    /** @var array<string, float> Default weight configuration */
    private const DEFAULT_WEIGHTS = [
        'engagement' => 0.25,
        'revenue' => 0.30,
        'conversion' => 0.25,
        'retention' => 0.20,
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, float> */
    private array $weights;

    private SaasKpiTracker $kpiTracker;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  SaasKpiTracker  $kpiTracker
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config, SaasKpiTracker $kpiTracker): void
    {
        $this->cache = $cache;
        $this->kpiTracker = $kpiTracker;

        $healthConfig = $config->get('zeroboiler.analytics.health_score', []);
        /** @var array{enabled?: bool, cache_ttl?: int, weights?: array<string, float>} $healthConfig */
        $this->enabled = (bool) ($healthConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($healthConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->weights = $healthConfig['weights'] ?? self::DEFAULT_WEIGHTS;
    }

    /**
     * Calculate the overall SaaS health score.
     *
     * @param  array<string, mixed>  $overrides  Optional metric overrides (for testing or simulation)
     * @return array{score: int, grade: string, sub_scores: array<string, array{score: int, weight: float, factors: array<string, mixed>}>, calculated_at: string, period: string}
     */
    public function calculate(array $overrides = []): array
    {
        if (! $this->enabled) {
            return $this->disabledResponse();
        }

        $subScores = [
            'engagement' => $this->engagementScore($overrides),
            'revenue' => $this->revenueScore($overrides),
            'conversion' => $this->conversionScore($overrides),
            'retention' => $this->retentionScore($overrides),
        ];

        $totalScore = 0.0;
        foreach ($subScores as $dimension => $data) {
            $weight = $this->weights[$dimension] ?? 0.0;
            $totalScore += $data['score'] * $weight;
        }

        $score = (int) round(min(100, max(0, $totalScore)));
        $grade = $this->grade($score);

        // Persist history
        $this->recordHistory($score);

        return [
            'score' => $score,
            'grade' => $grade,
            'sub_scores' => $subScores,
            'calculated_at' => date('c'),
            'period' => 'daily',
        ];
    }

    /**
     * Get the current health score without recalculating (from cache).
     *
     * @return array{score: int, grade: string, sub_scores: array<string, array{score: int, weight: float, factors: array<string, mixed>}>, calculated_at: string}|null
     */
    public function current(): ?array
    {
        return $this->cache->get(self::CACHE_PREFIX . 'current', null);
    }

    /**
     * Get health score history for trend visualization.
     *
     * @param  int  $limit  Number of historical data points
     * @return list<array{score: int, grade: string, calculated_at: string}>
     */
    public function history(int $limit = 30): array
    {
        $history = $this->cache->get(self::CACHE_PREFIX . 'history', []);
        /** @var list<array{score: int, grade: string, calculated_at: string}> $history */

        return array_slice($history, -$limit);
    }

    /**
     * Calculate engagement sub-score (0–100).
     *
     * Factors:
     * - Active vs total users ratio (30%)
     * - Average events per user (25%)
     * - Session frequency (25%)
     * - Feature adoption breadth (20%)
     *
     * @param  array<string, mixed>  $overrides
     * @return array{score: int, weight: float, factors: array<string, mixed>}
     */
    public function engagementScore(array $overrides = []): array
    {
        $factors = [];

        // Active user ratio: active subscribers vs a target (simulated from KPI data)
        $activeCount = $overrides['active_users'] ?? $this->kpiTracker->getActiveSubscriberCount();
        $totalTarget = max(1, $overrides['total_users_target'] ?? $activeCount * 2);
        $activeRatio = $activeCount / $totalTarget;
        $factors['active_ratio'] = round($activeRatio, 4);

        // MRR per subscriber (proxy for engagement depth)
        $arpu = $overrides['arpu'] ?? $this->kpiTracker->getArpu();
        $arpuTarget = max(1, $overrides['arpu_target'] ?? 50.0);
        $arpuScore = min(1.0, $arpu / $arpuTarget);
        $factors['arpu_ratio'] = round($arpuScore, 4);

        // Feature usage breadth (from overrides or assumed high)
        $featureCount = $overrides['features_used'] ?? 5;
        $featureTarget = max(1, $overrides['features_target'] ?? 10);
        $featureBreadth = min(1.0, $featureCount / $featureTarget);
        $factors['feature_breadth'] = round($featureBreadth, 4);

        // Session frequency (from overrides)
        $sessionsPerDay = $overrides['sessions_per_day'] ?? 100;
        $sessionsTarget = max(1, $overrides['sessions_target'] ?? 500);
        $sessionFrequency = min(1.0, $sessionsPerDay / $sessionsTarget);
        $factors['session_frequency'] = round($sessionFrequency, 4);

        // Weighted calculation
        $score = (int) round((
            $activeRatio * 30 +
            $arpuScore * 25 +
            $featureBreadth * 20 +
            $sessionFrequency * 25
        ));

        return [
            'score' => min(100, max(0, $score)),
            'weight' => $this->weights['engagement'] ?? 0.25,
            'factors' => $factors,
        ];
    }

    /**
     * Calculate revenue sub-score (0–100).
     *
     * Factors:
     * - MRR growth trend (30%)
     * - Churn rate (inverse, 30%)
     * - ARPU vs target (20%)
     * - CLV health (20%)
     *
     * @param  array<string, mixed>  $overrides
     * @return array{score: int, weight: float, factors: array<string, mixed>}
     */
    public function revenueScore(array $overrides = []): array
    {
        $factors = [];

        // MRR growth: compare latest to previous in history
        $mrrHistory = $this->kpiTracker->getMrrHistory(2);
        $mrrGrowth = 0.0;
        if (count($mrrHistory) >= 2) {
            $current = $mrrHistory[count($mrrHistory) - 1]['mrr'];
            $previous = $mrrHistory[count($mrrHistory) - 2]['mrr'];
            if ($previous > 0) {
                $mrrGrowth = ($current - $previous) / $previous;
            }
        }
        $factors['mrr_growth'] = round($mrrGrowth, 4);

        // Churn rate (lower is better)
        $churnRate = $overrides['churn_rate'] ?? $this->kpiTracker->getChurnRate();
        $churnScore = max(0, 1.0 - min(1.0, $churnRate * 10)); // 10% churn = 0 score
        $factors['churn_rate'] = round($churnRate, 4);
        $factors['churn_score'] = round($churnScore, 4);

        // ARPU
        $arpu = $overrides['arpu'] ?? $this->kpiTracker->getArpu();
        $arpuTarget = max(1, $overrides['arpu_target'] ?? 50.0);
        $arpuScore = min(1.0, $arpu / $arpuTarget);
        $factors['arpu_ratio'] = round($arpuScore, 4);

        // CLV health
        $clv = $overrides['clv'] ?? $this->kpiTracker->getClv();
        $clvTarget = max(1, $overrides['clv_target'] ?? 500.0);
        $clvScore = min(1.0, $clv / $clvTarget);
        $factors['clv_ratio'] = round($clvScore, 4);

        // Weighted calculation
        $score = (int) round((
            max(0, min(1, ($mrrGrowth + 1) / 2)) * 30 + // Normalize growth -100%..+100% to 0..1
            $churnScore * 30 +
            $arpuScore * 20 +
            $clvScore * 20
        ));

        return [
            'score' => min(100, max(0, $score)),
            'weight' => $this->weights['revenue'] ?? 0.30,
            'factors' => $factors,
        ];
    }

    /**
     * Calculate conversion sub-score (0–100).
     *
     * Factors:
     * - Trial-to-paid rate (40%)
     * - Signup-to-trial ratio (30%)
     * - MRR per subscriber (30%)
     *
     * @param  array<string, mixed>  $overrides
     * @return array{score: int, weight: float, factors: array<string, mixed>}
     */
    public function conversionScore(array $overrides = []): array
    {
        $factors = [];

        // Trial conversion rate
        $trialRate = $overrides['trial_conversion'] ?? $this->kpiTracker->getTrialConversionRate();
        $trialTarget = $overrides['trial_target'] ?? 0.40; // 40% is excellent
        $trialScore = min(1.0, $trialRate / $trialTarget);
        $factors['trial_conversion'] = round($trialRate, 4);

        // Signup-to-trial ratio (from overrides)
        $signupCount = $overrides['signups'] ?? 100;
        $trialCount = $overrides['trials'] ?? 60;
        $signupToTrial = $signupCount > 0 ? $trialCount / $signupCount : 0;
        $signupToTrialTarget = $overrides['signup_trial_target'] ?? 0.70;
        $signupTrialScore = min(1.0, $signupToTrial / $signupToTrialTarget);
        $factors['signup_to_trial'] = round($signupToTrial, 4);

        // Revenue per subscriber
        $arpu = $overrides['arpu'] ?? $this->kpiTracker->getArpu();
        $arpuTarget = max(1, $overrides['arpu_target'] ?? 50.0);
        $arpuScore = min(1.0, $arpu / $arpuTarget);
        $factors['arpu_ratio'] = round($arpuScore, 4);

        // Weighted calculation
        $score = (int) round((
            $trialScore * 40 +
            $signupTrialScore * 30 +
            $arpuScore * 30
        ));

        return [
            'score' => min(100, max(0, $score)),
            'weight' => $this->weights['conversion'] ?? 0.25,
            'factors' => $factors,
        ];
    }

    /**
     * Calculate retention sub-score (0–100).
     *
     * Factors:
     * - Churn rate inverse (35%)
     * - Active subscriber longevity (25%)
     * - Subscription renewal rate (25%)
     * - Plan stability (15%)
     *
     * @param  array<string, mixed>  $overrides
     * @return array{score: int, weight: float, factors: array<string, mixed>}
     */
    public function retentionScore(array $overrides = []): array
    {
        $factors = [];

        // Churn inverse (lower churn = higher score)
        $churnRate = $overrides['churn_rate'] ?? $this->kpiTracker->getChurnRate();
        $churnScore = max(0, 1.0 - min(1.0, $churnRate * 10));
        $factors['churn_inverse'] = round($churnScore, 4);
        $factors['churn_rate'] = round($churnRate, 4);

        // Subscriber longevity (average age of active subscriptions)
        $activeSubscribers = $overrides['active_subscribers'] ?? $this->kpiTracker->getActiveSubscriberCount();
        $avgAgeDays = $overrides['avg_subscription_age'] ?? 90;
        $longevityTarget = 365; // 1 year is great
        $longevityScore = min(1.0, $avgAgeDays / $longevityTarget);
        $factors['avg_subscription_age'] = $avgAgeDays;
        $factors['longevity_ratio'] = round($longevityScore, 4);

        // Renewal rate (from overrides)
        $renewalRate = $overrides['renewal_rate'] ?? 0.85;
        $renewalTarget = $overrides['renewal_target'] ?? 0.95;
        $renewalScore = min(1.0, $renewalRate / $renewalTarget);
        $factors['renewal_rate'] = round($renewalRate, 4);

        // Plan stability (% of users on their original plan)
        $planStability = $overrides['plan_stability'] ?? 0.80;
        $stabilityScore = min(1.0, $planStability);
        $factors['plan_stability'] = round($planStability, 4);

        // Weighted calculation
        $score = (int) round((
            $churnScore * 35 +
            $longevityScore * 25 +
            $renewalScore * 25 +
            $stabilityScore * 15
        ));

        return [
            'score' => min(100, max(0, $score)),
            'weight' => $this->weights['retention'] ?? 0.20,
            'factors' => $factors,
        ];
    }

    /**
     * Get the grade letter for a health score.
     *
     * A (90-100): Excellent, B (75-89): Good, C (60-74): Fair,
     * D (40-59): Needs Attention, F (0-39): Critical
     */
    public function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * Check if health score tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all health score data.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'current');
        $this->cache->forget(self::CACHE_PREFIX . 'history');
    }

    /**
     * Record health score in history.
     */
    private function recordHistory(int $score): void
    {
        $history = $this->cache->get(self::CACHE_PREFIX . 'history', []);
        /** @var list<array{score: int, grade: string, calculated_at: string}> $history */

        $history[] = [
            'score' => $score,
            'grade' => $this->grade($score),
            'calculated_at' => date('c'),
        ];

        // Keep limited history
        $history = array_slice($history, -self::DEFAULT_HISTORY_LIMIT);

        $this->cache->put(self::CACHE_PREFIX . 'history', $history, $this->cacheTtl * 30);
        $this->cache->put(self::CACHE_PREFIX . 'current', [
            'score' => $score,
            'grade' => $this->grade($score),
            'calculated_at' => date('c'),
        ], $this->cacheTtl);
    }

    /**
     * Return a disabled response.
     *
     * @return array{score: int, grade: string, sub_scores: array<empty, empty>, calculated_at: string, period: string}
     */
    private function disabledResponse(): array
    {
        return [
            'score' => 0,
            'grade' => 'N/A',
            'sub_scores' => [],
            'calculated_at' => date('c'),
            'period' => 'daily',
        ];
    }
}
