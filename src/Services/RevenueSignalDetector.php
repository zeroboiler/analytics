<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS revenue signal detector that identifies early churn and expansion
 * signals from user event pattern analysis.
 *
 * Analyzes event sequences, feature adoption patterns, usage frequency,
 * and engagement metrics to compute:
 * - Churn risk score (0-100) per user
 * - Expansion opportunity score (0-100) per user
 * - Signal categorization (strong_positive, moderate_positive, neutral, moderate_negative, strong_negative)
 * - Recommended actions (retention playbook, upsell trigger, etc.)
 *
 * Signals are detected from configurable event patterns and weighted by
 * their statistical correlation with churn/expansion outcomes.
 *
 * @since 82.0.0
 */
final class RevenueSignalDetector
{
    private const CACHE_PREFIX = 'zb_revenue_signal_';
    private const DEFAULT_TTL = 3600; // 1 hour
    private const DEFAULT_CHURN_WINDOW_DAYS = 30;
    private const DEFAULT_EXPANSION_WINDOW_DAYS = 14;

    /**
     * Churn signal definitions with weights.
     *
     * Each signal defines: event pattern, weight, and decay.
     *
     * @var array<string, array{weight: float, decay_days: int, description: string}>
     */
    private const CHURN_SIGNALS = [
        'login_frequency_decline' => [
            'weight' => 0.25,
            'decay_days' => 14,
            'description' => 'Login frequency declining over 2+ weeks',
        ],
        'feature_usage_drop' => [
            'weight' => 0.20,
            'decay_days' => 7,
            'description' => 'Core feature usage dropped below threshold',
        ],
        'support_ticket_spike' => [
            'weight' => 0.15,
            'decay_days' => 5,
            'description' => 'Support ticket volume increased significantly',
        ],
        'error_rate_increase' => [
            'weight' => 0.15,
            'decay_days' => 3,
            'description' => 'Error events increased for the user',
        ],
        'trial_nearing_end_no_conversion' => [
            'weight' => 0.30,
            'decay_days' => 3,
            'description' => 'Trial ending soon with low activation',
        ],
        'downgrade_event' => [
            'weight' => 0.35,
            'decay_days' => 7,
            'description' => 'User triggered a plan downgrade',
        ],
        'payment_failed' => [
            'weight' => 0.25,
            'decay_days' => 5,
            'description' => 'Recent payment failure',
        ],
        'session_duration_decline' => [
            'weight' => 0.10,
            'decay_days' => 14,
            'description' => 'Average session duration declining',
        ],
    ];

    /**
     * Expansion signal definitions with weights.
     *
     * @var array<string, array{weight: float, decay_days: int, description: string}>
     */
    private const EXPANSION_SIGNALS = [
        'feature_limit_reached' => [
            'weight' => 0.30,
            'decay_days' => 7,
            'description' => 'User hit feature usage limit',
        ],
        'team_member_invites' => [
            'weight' => 0.25,
            'decay_days' => 14,
            'description' => 'User invited team members recently',
        ],
        'api_usage_growth' => [
            'weight' => 0.20,
            'decay_days' => 7,
            'description' => 'API usage growing week-over-week',
        ],
        'integration_connecting' => [
            'weight' => 0.20,
            'decay_days' => 10,
            'description' => 'User connecting new integrations',
        ],
        'high_engagement_streak' => [
            'weight' => 0.15,
            'decay_days' => 7,
            'description' => 'Consecutive daily active streak > 5 days',
        ],
        'export_volume_increase' => [
            'weight' => 0.15,
            'decay_days' => 5,
            'description' => 'Data export volume increasing',
        ],
    ];

    /** @var array<string, float> User churn scores (in-memory cache) */
    private array $churnScoreCache = [];

    /** @var array<string, float> User expansion scores (in-memory cache) */
    private array $expansionScoreCache = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {}

    /**
     * Compute the churn risk score for a user.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $eventCounts  Recent event counts keyed by event name
     * @param  array<string, mixed>  $context  Additional user context (plan, trial_end_date, days_active, etc.)
     * @return array{user_id: string, churn_score: float, churn_risk: string, signals: list<array{name: string, weight: float, detected: bool, value: float}>, recommendation: string|null, computed_at: string}
     */
    public function churnScore(string $userId, array $eventCounts = [], array $context = []): array
    {
        $cacheKey = self::CACHE_PREFIX . 'churn:' . $userId;

        if (isset($this->churnScoreCache[$userId])) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $totalScore = 0.0;
        $signals = [];

        foreach (self::CHURN_SIGNALS as $signalName => $signalConfig) {
            $detected = $this->detectChurnSignal($signalName, $eventCounts, $context);
            $signalScore = $detected ? $signalConfig['weight'] : 0.0;

            // Apply time decay if detection timestamp is available
            $detectedAt = $context[$signalName . '_detected_at'] ?? null;
            if ($detectedAt !== null && is_string($detectedAt)) {
                $daysAgo = $this->daysBetween($detectedAt);
                if ($daysAgo > $signalConfig['decay_days']) {
                    $decayFactor = max(0, 1.0 - ($daysAgo - $signalConfig['decay_days']) / $signalConfig['decay_days']);
                    $signalScore *= $decayFactor;
                }
            }

            $totalScore += $signalScore;
            $signals[] = [
                'name' => $signalName,
                'weight' => $signalConfig['weight'],
                'detected' => $detected,
                'value' => round($signalScore, 4),
            ];
        }

        // Cap at 100
        $churnScore = min(100.0, round($totalScore * 100, 1));
        $churnRisk = $this->classifyRisk($churnScore);
        $recommendation = $this->churnRecommendation($churnScore, $signals);

        $result = [
            'user_id' => $userId,
            'churn_score' => $churnScore,
            'churn_risk' => $churnRisk,
            'signals' => $signals,
            'recommendation' => $recommendation,
            'computed_at' => now()->toIso8601String(),
        ];

        $ttl = (int) ($this->config->get('zeroboiler.analytics.revenue_signals.cache_ttl', self::DEFAULT_TTL));
        $this->cache->put($cacheKey, $result, $ttl);
        $this->churnScoreCache[$userId] = $churnScore;

        return $result;
    }

    /**
     * Compute the expansion opportunity score for a user.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $eventCounts  Recent event counts keyed by event name
     * @param  array<string, mixed>  $context  Additional user context (plan, mrr, team_size, etc.)
     * @return array{user_id: string, expansion_score: float, expansion_potential: string, signals: list<array{name: string, weight: float, detected: bool, value: float}>, recommendation: string|null, computed_at: string}
     */
    public function expansionScore(string $userId, array $eventCounts = [], array $context = []): array
    {
        $cacheKey = self::CACHE_PREFIX . 'expansion:' . $userId;

        if (isset($this->expansionScoreCache[$userId])) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $totalScore = 0.0;
        $signals = [];

        foreach (self::EXPANSION_SIGNALS as $signalName => $signalConfig) {
            $detected = $this->detectExpansionSignal($signalName, $eventCounts, $context);
            $signalScore = $detected ? $signalConfig['weight'] : 0.0;

            // Apply time decay
            $detectedAt = $context[$signalName . '_detected_at'] ?? null;
            if ($detectedAt !== null && is_string($detectedAt)) {
                $daysAgo = $this->daysBetween($detectedAt);
                if ($daysAgo > $signalConfig['decay_days']) {
                    $decayFactor = max(0, 1.0 - ($daysAgo - $signalConfig['decay_days']) / $signalConfig['decay_days']);
                    $signalScore *= $decayFactor;
                }
            }

            $totalScore += $signalScore;
            $signals[] = [
                'name' => $signalName,
                'weight' => $signalConfig['weight'],
                'detected' => $detected,
                'value' => round($signalScore, 4),
            ];
        }

        // Cap at 100
        $expansionScore = min(100.0, round($totalScore * 100, 1));
        $expansionPotential = $this->classifyPotential($expansionScore);
        $recommendation = $this->expansionRecommendation($expansionScore, $signals);

        $result = [
            'user_id' => $userId,
            'expansion_score' => $expansionScore,
            'expansion_potential' => $expansionPotential,
            'signals' => $signals,
            'recommendation' => $recommendation,
            'computed_at' => now()->toIso8601String(),
        ];

        $ttl = (int) ($this->config->get('zeroboiler.analytics.revenue_signals.cache_ttl', self::DEFAULT_TTL));
        $this->cache->put($cacheKey, $result, $ttl);
        $this->expansionScoreCache[$userId] = $expansionScore;

        return $result;
    }

    /**
     * Compute a combined revenue signal report for a user.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $eventCounts  Recent event counts
     * @param  array<string, mixed>  $context  User context
     * @return array{user_id: string, churn: array<string, mixed>, expansion: array<string, mixed>, net_signal: string, net_score: float, priority: string}
     */
    public function fullSignalReport(string $userId, array $eventCounts = [], array $context = []): array
    {
        $churn = $this->churnScore($userId, $eventCounts, $context);
        $expansion = $this->expansionScore($userId, $eventCounts, $context);

        // Net score: expansion - churn (range: -100 to +100)
        $netScore = round($expansion['expansion_score'] - $churn['churn_score'], 1);

        if ($netScore > 30) {
            $netSignal = 'strong_expansion';
            $priority = 'high_upsell';
        } elseif ($netScore > 10) {
            $netSignal = 'moderate_expansion';
            $priority = 'monitor_upsell';
        } elseif ($netScore > -10) {
            $netSignal = 'neutral';
            $priority = 'standard';
        } elseif ($netScore > -30) {
            $netSignal = 'moderate_churn_risk';
            $priority = 'retention_watch';
        } else {
            $netSignal = 'strong_churn_risk';
            $priority = 'urgent_retention';
        }

        return [
            'user_id' => $userId,
            'churn' => $churn,
            'expansion' => $expansion,
            'net_signal' => $netSignal,
            'net_score' => $netScore,
            'priority' => $priority,
        ];
    }

    /**
     * Get batch churn scores for multiple users.
     *
     * @param  list<string>  $userIds  User identifiers
     * @param  array<string, array<string, mixed>>  $eventCountsMap  Map of userId → event counts
     * @param  array<string, array<string, mixed>>  $contextMap  Map of userId → context
     * @return array<string, array<string, mixed>>
     */
    public function batchChurnScores(array $userIds, array $eventCountsMap = [], array $contextMap = []): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $results[$userId] = $this->churnScore(
                $userId,
                $eventCountsMap[$userId] ?? [],
                $contextMap[$userId] ?? [],
            );
        }

        return $results;
    }

    /**
     * Get the top at-risk users by churn score.
     *
     * @param  list<string>  $userIds  User identifiers to analyze
     * @param  int  $limit  Maximum results
     * @return list<array{user_id: string, churn_score: float, churn_risk: string}>
     */
    public function topAtRiskUsers(array $userIds, int $limit = 10): array
    {
        $scores = [];

        foreach ($userIds as $userId) {
            $result = $this->churnScore($userId);
            $scores[] = [
                'user_id' => $userId,
                'churn_score' => $result['churn_score'],
                'churn_risk' => $result['churn_risk'],
            ];
        }

        usort($scores, function (array $a, array $b): int {
            return $b['churn_score'] <=> $a['churn_score'];
        });

        return array_slice($scores, 0, $limit);
    }

    /**
     * Get the top expansion opportunity users.
     *
     * @param  list<string>  $userIds  User identifiers to analyze
     * @param  int  $limit  Maximum results
     * @return list<array{user_id: string, expansion_score: float, expansion_potential: string}>
     */
    public function topExpansionUsers(array $userIds, int $limit = 10): array
    {
        $scores = [];

        foreach ($userIds as $userId) {
            $result = $this->expansionScore($userId);
            $scores[] = [
                'user_id' => $userId,
                'expansion_score' => $result['expansion_score'],
                'expansion_potential' => $result['expansion_potential'],
            ];
        }

        usort($scores, function (array $a, array $b): int {
            return $b['expansion_score'] <=> $a['expansion_score'];
        });

        return array_slice($scores, 0, $limit);
    }

    /**
     * Clear cached signal data for a user.
     */
    public function clearUserCache(string $userId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'churn:' . $userId);
        $this->cache->forget(self::CACHE_PREFIX . 'expansion:' . $userId);
        unset($this->churnScoreCache[$userId], $this->expansionScoreCache[$userId]);
    }

    /**
     * Detect if a specific churn signal is present.
     */
    private function detectChurnSignal(string $signalName, array $eventCounts, array $context): bool
    {
        return match ($signalName) {
            'login_frequency_decline' => ($context['login_trend'] ?? 'stable') === 'declining',
            'feature_usage_drop' => ($context['core_feature_usage_trend'] ?? 'stable') === 'declining',
            'support_ticket_spike' => ($eventCounts['support_ticket_created'] ?? 0) >= 3,
            'error_rate_increase' => ($context['error_rate_trend'] ?? 'stable') === 'increasing',
            'trial_nearing_end_no_conversion' => $this->isTrialEndingSoon($context),
            'downgrade_event' => ($eventCounts['plan_downgrade'] ?? 0) > 0,
            'payment_failed' => ($eventCounts['payment_failed'] ?? 0) > 0,
            'session_duration_decline' => ($context['avg_session_duration_trend'] ?? 'stable') === 'declining',
            default => false,
        };
    }

    /**
     * Detect if a specific expansion signal is present.
     */
    private function detectExpansionSignal(string $signalName, array $eventCounts, array $context): bool
    {
        return match ($signalName) {
            'feature_limit_reached' => ($eventCounts['feature_limit_reached'] ?? 0) > 0,
            'team_member_invites' => ($eventCounts['invite_sent'] ?? 0) >= 2,
            'api_usage_growth' => ($context['api_usage_trend'] ?? 'stable') === 'growing',
            'integration_connecting' => ($eventCounts['integration_connected'] ?? 0) > 0,
            'high_engagement_streak' => ($context['active_streak_days'] ?? 0) >= 5,
            'export_volume_increase' => ($context['export_volume_trend'] ?? 'stable') === 'increasing',
            default => false,
        };
    }

    /**
     * Check if the user's trial is ending soon with low activation.
     */
    private function isTrialEndingSoon(array $context): bool
    {
        $trialEnd = $context['trial_end_date'] ?? null;
        $activationScore = $context['activation_score'] ?? 100;

        if ($trialEnd === null || ! is_string($trialEnd)) {
            return false;
        }

        $daysUntilEnd = $this->daysBetween($trialEnd);

        return $daysUntilEnd <= 3 && $daysUntilEnd >= 0 && (float) $activationScore < 50;
    }

    /**
     * Classify a churn score into a risk category.
     */
    private function classifyRisk(float $score): string
    {
        if ($score >= 70) {
            return 'critical';
        }

        if ($score >= 50) {
            return 'high';
        }

        if ($score >= 30) {
            return 'moderate';
        }

        if ($score >= 10) {
            return 'low';
        }

        return 'minimal';
    }

    /**
     * Classify an expansion score into a potential category.
     */
    private function classifyPotential(float $score): string
    {
        if ($score >= 70) {
            return 'hot';
        }

        if ($score >= 50) {
            return 'warm';
        }

        if ($score >= 30) {
            return 'moderate';
        }

        if ($score >= 10) {
            return 'cool';
        }

        return 'cold';
    }

    /**
     * Generate a churn retention recommendation.
     *
     * @param  float  $score  Churn score
     * @param  list<array{name: string, weight: float, detected: bool, value: float}>  $signals  Detected signals
     */
    private function churnRecommendation(float $score, array $signals): ?string
    {
        if ($score < 10) {
            return null;
        }

        $detected = array_filter($signals, static fn (array $s): bool => $s['detected']);
        $topSignal = array_reduce(
            $detected,
            static fn (?array $carry, array $s): ?array => ($carry === null || $s['value'] > $carry['value']) ? $s : $carry,
            null,
        );

        if ($topSignal === null) {
            return null;
        }

        return match ($topSignal['name']) {
            'trial_nearing_end_no_conversion' => 'trigger_trial_extension_offer',
            'payment_failed' => 'send_payment_retry_with_incentive',
            'downgrade_event' => 'offer_downgrade_conservation_plan',
            'feature_usage_drop' => 'schedule_product_tour_or_tips',
            'login_frequency_decline' => 'send_re_engagement_email_campaign',
            'support_ticket_spike' => 'escalate_to_customer_success',
            'error_rate_increase' => 'trigger_proactive_support_outreach',
            'session_duration_decline' => 'suggest_relevant_features_or_content',
            default => 'monitor_and_follow_up',
        };
    }

    /**
     * Generate an expansion recommendation.
     *
     * @param  float  $score  Expansion score
     * @param  list<array{name: string, weight: float, detected: bool, value: float}>  $signals  Detected signals
     */
    private function expansionRecommendation(float $score, array $signals): ?string
    {
        if ($score < 10) {
            return null;
        }

        $detected = array_filter($signals, static fn (array $s): bool => $s['detected']);
        $topSignal = array_reduce(
            $detected,
            static fn (?array $carry, array $s): ?array => ($carry === null || $s['value'] > $carry['value']) ? $s : $carry,
            null,
        );

        if ($topSignal === null) {
            return null;
        }

        return match ($topSignal['name']) {
            'feature_limit_reached' => 'trigger_plan_upgrade_prompt',
            'team_member_invites' => 'suggest_team_plan_with_collaboration_features',
            'api_usage_growth' => 'offer_higher_api_limits_on_upgrade',
            'integration_connecting' => 'show_integrations_catalog_for_automation',
            'high_engagement_streak' => 'request_testimonial_or_case_study',
            'export_volume_increase' => 'offer_advanced_reporting_on_higher_plan',
            default => 'nurture_lead',
        };
    }

    /**
     * Calculate days between a date string and now.
     */
    private function daysBetween(string $dateString): float
    {
        try {
            $date = new \DateTimeImmutable($dateString);
            $diff = $date->diff(new \DateTimeImmutable());

            return (float) ($diff->invert ? -$diff->days : $diff->days);
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
