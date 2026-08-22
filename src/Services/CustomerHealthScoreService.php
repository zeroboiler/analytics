<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Customer Health Score Service — composite SaaS customer health index.
 *
 * Computes a single 0-100 health score for a customer by aggregating
 * multiple SaaS health signals into a weighted composite index. Signals
 * include:
 *
 * - **Engagement** (30%): Recent login frequency, feature usage, session depth
 * - **Revenue** (25%): MRR stability, payment history, plan tier health
 * - **Retention** (20%): Account age, churn risk signals, login recency
 * - **Support** (15%): Open tickets, NPS score, feedback sentiment
 * - **Growth** (10%): Feature adoption rate, team member additions, upgrade signals
 *
 * The score is cache-backed with configurable TTL and supports batch computation
 * for dashboard rendering. Inspired by Gainsight's customer health scoring,
 * Totango's success scores, and HubSpot's customer health index.
 *
 * @since 240.0.0
 */
final class CustomerHealthScoreService
{
    /** @var non-empty-string Cache key prefix */
    private const CACHE_PREFIX = 'zb_health_score_';

    /** @var positive-int Default cache TTL in seconds (5 minutes) */
    private const DEFAULT_TTL = 300;

    /** @var array<string, float> Default signal weights (must sum to 1.0) */
    private const DEFAULT_WEIGHTS = [
        'engagement' => 0.30,
        'revenue' => 0.25,
        'retention' => 0.20,
        'support' => 0.15,
        'growth' => 0.10,
    ];

    /** @var array<string, string> Health score tier labels */
    private const TIERS = [
        'critical' => 'Critical',
        'at_risk' => 'At Risk',
        'needs_attention' => 'Needs Attention',
        'healthy' => 'Healthy',
        'thriving' => 'Thriving',
    ];

    /** @var array<string, int> Tier score boundaries */
    private const TIER_BOUNDARIES = [
        'critical' => 0,
        'at_risk' => 25,
        'needs_attention' => 50,
        'healthy' => 70,
        'thriving' => 85,
    ];

    private CacheRepository $cache;

    /** @var int */
    private int $ttl;

    /** @var array<string, float> */
    private array $weights;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository (auto-resolved)
     * @param  positive-int|null  $ttl  Cache TTL in seconds
     * @param  array<string, float>|null  $weights  Custom signal weights
     */
    public function __construct(
        ?CacheRepository $cache = null,
        ?int $ttl = null,
        ?array $weights = null,
    ){
        $this->cache = $cache ?? Cache::getStore();
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
        $this->weights = $weights ?? self::DEFAULT_WEIGHTS;
    }

    /**
     * Compute the composite health score for a customer.
     *
     * Aggregates all signal dimensions into a single 0-100 score.
     * Results are cached per customer.
     *
     * @param  string  $customerId  The customer/user identifier
     * @param  array<string, mixed>  $signals  Raw signal data from various sources
     * @return array{score: int, tier: string, tier_label: string, signals: array<string, array{score: float, weight: float, contribution: float}>, computed_at: int}
     */
    public function compute(string $customerId, array $signals = []): array
    {
        $cacheKey = self::CACHE_PREFIX . $customerId;

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['score']) && $signals === []) {
            return $cached;
        }

        $signalScores = $this->computeSignalScores($signals);
        $compositeScore = $this->computeComposite($signalScores);
        $tier = $this->getTier($compositeScore);

        $result = [
            'score' => $compositeScore,
            'tier' => $tier,
            'tier_label' => self::TIERS[$tier],
            'signals' => $signalScores,
            'computed_at' => time(),
        ];

        $this->cache->put($cacheKey, $result, $this->ttl);

        return $result;
    }

    /**
     * Compute health scores for multiple customers in batch.
     *
     * @param  array<string, array<string, mixed>>  $customerSignals  Map of customerId => signals
     * @return array<string, array{score: int, tier: string, tier_label: string}>
     */
    public function computeBatch(array $customerSignals): array
    {
        $results = [];

        foreach ($customerSignals as $customerId => $signals) {
            $health = $this->compute($customerId, $signals);
            $results[$customerId] = [
                'score' => $health['score'],
                'tier' => $health['tier'],
                'tier_label' => $health['tier_label'],
            ];
        }

        return $results;
    }

    /**
     * Get the aggregated health score distribution across a customer set.
     *
     * Useful for dashboard widgets and executive summaries.
     *
     * @param  list<string>  $customerIds  List of customer IDs to analyze
     * @return array{total: int, average: float, by_tier: array<string, int>, distribution: array<string, float>}
     */
    public function distribution(array $customerIds): array
    {
        $scores = [];
        $byTier = [];

        foreach (self::TIERS as $tier => $label) {
            $byTier[$tier] = 0;
        }

        foreach ($customerIds as $customerId) {
            $health = $this->compute($customerId);
            $scores[] = $health['score'];
            $byTier[$health['tier']]++;
        }

        $total = count($scores);
        $average = $total > 0 ? (float) array_sum($scores) / $total : 0.0;

        $distribution = [];
        foreach ($byTier as $tier => $count) {
            $distribution[$tier] = $total > 0 ? round($count / $total * 100, 1) : 0.0;
        }

        return [
            'total' => $total,
            'average' => round($average, 1),
            'by_tier' => $byTier,
            'distribution' => $distribution,
        ];
    }

    /**
     * Compute individual signal scores from raw data.
     *
     * Each signal is scored 0-100 based on available data.
     *
     * @param  array<string, mixed>  $signals  Raw signal data
     * @return array<string, array{score: float, weight: float, contribution: float}>
     */
    private function computeSignalScores(array $signals): array
    {
        $result = [];

        $result['engagement'] = $this->scoreEngagement($signals);
        $result['engagement']['weight'] = $this->weights['engagement'];
        $result['engagement']['contribution'] = round($result['engagement']['score'] * $this->weights['engagement'], 2);

        $result['revenue'] = $this->scoreRevenue($signals);
        $result['revenue']['weight'] = $this->weights['revenue'];
        $result['revenue']['contribution'] = round($result['revenue']['score'] * $this->weights['revenue'], 2);

        $result['retention'] = $this->scoreRetention($signals);
        $result['retention']['weight'] = $this->weights['retention'];
        $result['retention']['contribution'] = round($result['retention']['score'] * $this->weights['retention'], 2);

        $result['support'] = $this->scoreSupport($signals);
        $result['support']['weight'] = $this->weights['support'];
        $result['support']['contribution'] = round($result['support']['score'] * $this->weights['support'], 2);

        $result['growth'] = $this->scoreGrowth($signals);
        $result['growth']['weight'] = $this->weights['growth'];
        $result['growth']['contribution'] = round($result['growth']['score'] * $this->weights['growth'], 2);

        return $result;
    }

    /**
     * Compute the composite score from weighted signal scores.
     *
     * @param  array<string, array{contribution: float}>  $signalScores
     * @return int Rounded composite score 0-100
     */
    private function computeComposite(array $signalScores): int
    {
        $total = 0.0;
        foreach ($signalScores as $signal) {
            $total += $signal['contribution'];
        }

        return (int) min(100, max(0, round($total)));
    }

    /**
     * Determine the health tier for a given score.
     *
     * @param  int  $score  Composite health score
     * @return string Tier key (critical, at_risk, needs_attention, healthy, thriving)
     */
    private function getTier(int $score): string
    {
        if ($score >= self::TIER_BOUNDARIES['thriving']) {
            return 'thriving';
        }
        if ($score >= self::TIER_BOUNDARIES['healthy']) {
            return 'healthy';
        }
        if ($score >= self::TIER_BOUNDARIES['needs_attention']) {
            return 'needs_attention';
        }
        if ($score >= self::TIER_BOUNDARIES['at_risk']) {
            return 'at_risk';
        }

        return 'critical';
    }

    /**
     * Score the engagement signal (0-100).
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: float, label: string}
     */
    private function scoreEngagement(array $signals): array
    {
        $score = 50.0; // Default: neutral

        // Login frequency (last 30 days)
        $loginCount = (int) ($signals['login_count_30d'] ?? 0);
        if ($loginCount >= 20) {
            $score = min(100, $score + 25);
        } elseif ($loginCount >= 10) {
            $score = min(100, $score + 15);
        } elseif ($loginCount >= 3) {
            $score = min(100, $score + 5);
        } elseif ($loginCount === 0) {
            $score = max(0, $score - 20);
        }

        // Feature usage
        $featuresUsed = (int) ($signals['features_used_count'] ?? 0);
        if ($featuresUsed >= 5) {
            $score = min(100, $score + 15);
        } elseif ($featuresUsed >= 2) {
            $score = min(100, $score + 8);
        }

        // Session depth (average pages per session)
        $avgPages = (float) ($signals['avg_pages_per_session'] ?? 0);
        if ($avgPages >= 10) {
            $score = min(100, $score + 10);
        } elseif ($avgPages >= 5) {
            $score = min(100, $score + 5);
        }

        // Last login recency (days ago)
        $daysSinceLogin = (int) ($signals['days_since_last_login'] ?? 999);
        if ($daysSinceLogin <= 1) {
            $score = min(100, $score + 10);
        } elseif ($daysSinceLogin <= 3) {
            $score = min(100, $score + 5);
        } elseif ($daysSinceLogin >= 14) {
            $score = max(0, $score - 15);
        } elseif ($daysSinceLogin >= 7) {
            $score = max(0, $score - 5);
        }

        return [
            'score' => max(0, min(100, round($score, 1))),
            'label' => 'Engagement',
        ];
    }

    /**
     * Score the revenue signal (0-100).
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: float, label: string}
     */
    private function scoreRevenue(array $signals): array
    {
        $score = 50.0;

        // Payment history (successful vs failed)
        $successfulPayments = (int) ($signals['successful_payments_count'] ?? 0);
        $failedPayments = (int) ($signals['failed_payments_count'] ?? 0);

        if ($successfulPayments >= 6) {
            $score = min(100, $score + 25);
        } elseif ($successfulPayments >= 3) {
            $score = min(100, $score + 15);
        } elseif ($successfulPayments >= 1) {
            $score = min(100, $score + 8);
        }

        if ($failedPayments > 0) {
            $score = max(0, $score - min(30, $failedPayments * 10));
        }

        // MRR amount (relative to industry average proxy)
        $mrr = (float) ($signals['mrr'] ?? 0);
        if ($mrr >= 100) {
            $score = min(100, $score + 15);
        } elseif ($mrr >= 50) {
            $score = min(100, $score + 10);
        } elseif ($mrr >= 20) {
            $score = min(100, $score + 5);
        }

        // Plan tier (higher tier = better health)
        $planTier = (string) ($signals['plan_tier'] ?? '');
        if (in_array($planTier, ['enterprise', 'business'], true)) {
            $score = min(100, $score + 10);
        } elseif ($planTier === 'pro') {
            $score = min(100, $score + 5);
        }

        return [
            'score' => max(0, min(100, round($score, 1))),
            'label' => 'Revenue',
        ];
    }

    /**
     * Score the retention signal (0-100).
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: float, label: string}
     */
    private function scoreRetention(array $signals): array
    {
        $score = 50.0;

        // Account age (days)
        $accountAge = (int) ($signals['account_age_days'] ?? 0);
        if ($accountAge >= 365) {
            $score = min(100, $score + 20);
        } elseif ($accountAge >= 180) {
            $score = min(100, $score + 15);
        } elseif ($accountAge >= 90) {
            $score = min(100, $score + 10);
        } elseif ($accountAge >= 30) {
            $score = min(100, $score + 5);
        }

        // Churn risk signals
        $churnRisk = (float) ($signals['churn_risk_score'] ?? 0);
        if ($churnRisk >= 0.8) {
            $score = max(0, $score - 30);
        } elseif ($churnRisk >= 0.5) {
            $score = max(0, $score - 15);
        }

        // Login streak (consecutive days active)
        $loginStreak = (int) ($signals['login_streak_days'] ?? 0);
        if ($loginStreak >= 5) {
            $score = min(100, $score + 15);
        } elseif ($loginStreak >= 2) {
            $score = min(100, $score + 8);
        }

        // Trial status (past trial = more committed)
        $trialConverted = (bool) ($signals['trial_converted'] ?? false);
        if ($trialConverted) {
            $score = min(100, $score + 5);
        }

        return [
            'score' => max(0, min(100, round($score, 1))),
            'label' => 'Retention',
        ];
    }

    /**
     * Score the support signal (0-100).
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: float, label: string}
     */
    private function scoreSupport(array $signals): array
    {
        $score = 70.0; // Default: moderately healthy

        $openTickets = (int) ($signals['open_tickets_count'] ?? 0);
        if ($openTickets === 0) {
            $score = min(100, $score + 10);
        } elseif ($openTickets >= 3) {
            $score = max(0, $score - 15);
        } elseif ($openTickets >= 1) {
            $score = max(0, $score - 5);
        }

        // NPS score (0-10 scale → 0-100)
        $nps = (int) ($signals['nps_score'] ?? 0);
        if ($nps >= 9) {
            $score = min(100, $score + 20);
        } elseif ($nps >= 7) {
            $score = min(100, $score + 10);
        } elseif ($nps >= 5) {
            $score = min(100, $score + 0);
        } elseif ($nps >= 1) {
            $score = max(0, $score - 10);
        }

        // Feedback sentiment
        $feedbackSentiment = (float) ($signals['feedback_sentiment'] ?? 0.5);
        if ($feedbackSentiment >= 0.8) {
            $score = min(100, $score + 10);
        } elseif ($feedbackSentiment < 0.3) {
            $score = max(0, $score - 15);
        }

        return [
            'score' => max(0, min(100, round($score, 1))),
            'label' => 'Support',
        ];
    }

    /**
     * Score the growth signal (0-100).
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: float, label: string}
     */
    private function scoreGrowth(array $signals): array
    {
        $score = 50.0;

        // Feature adoption rate
        $adoptionRate = (float) ($signals['feature_adoption_rate'] ?? 0);
        if ($adoptionRate >= 0.8) {
            $score = min(100, $score + 25);
        } elseif ($adoptionRate >= 0.5) {
            $score = min(100, $score + 15);
        } elseif ($adoptionRate >= 0.2) {
            $score = min(100, $score + 5);
        }

        // Team member additions
        $teamMembers = (int) ($signals['team_member_count'] ?? 1);
        if ($teamMembers >= 10) {
            $score = min(100, $score + 15);
        } elseif ($teamMembers >= 5) {
            $score = min(100, $score + 10);
        } elseif ($teamMembers >= 2) {
            $score = min(100, $score + 5);
        }

        // Upgrade signals
        $upgradedRecently = (bool) ($signals['upgraded_recently'] ?? false);
        if ($upgradedRecently) {
            $score = min(100, $score + 10);
        }

        // Integration usage
        $integrationsUsed = (int) ($signals['integrations_count'] ?? 0);
        if ($integrationsUsed >= 3) {
            $score = min(100, $score + 10);
        } elseif ($integrationsUsed >= 1) {
            $score = min(100, $score + 5);
        }

        return [
            'score' => max(0, min(100, round($score, 1))),
            'label' => 'Growth',
        ];
    }

    /**
     * Invalidate the cached health score for a customer.
     */
    public function invalidate(string $customerId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $customerId);
    }

    /**
     * Get the tier definitions for display purposes.
     *
     * @return array<string, array{label: string, min_score: int}>
     */
    public static function tierDefinitions(): array
    {
        $definitions = [];
        foreach (self::TIERS as $key => $label) {
            $definitions[$key] = [
                'label' => $label,
                'min_score' => self::TIER_BOUNDARIES[$key],
            ];
        }

        return $definitions;
    }

    /**
     * Get the default signal weights.
     *
     * @return array<string, float>
     */
    public static function defaultWeights(): array
    {
        return self::DEFAULT_WEIGHTS;
    }
}
