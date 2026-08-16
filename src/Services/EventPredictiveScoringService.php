<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Predictive scoring service for user conversion, churn, and expansion.
 *
 * Uses event-based signals to compute probability scores for key SaaS
 * lifecycle outcomes. Designed as a lightweight, config-driven predictor
 * that operates without ML models — using heuristics based on industry
 * benchmarks from OpenView, Bessemer, and ProfitWell data.
 *
 * Scores:
 * - conversion_probability: Likelihood of free→paid conversion (0.0-1.0)
 * - churn_risk: Likelihood of churning within 30 days (0.0-1.0)
 * - expansion_likelihood: Likelihood of upsell/expansion (0.0-1.0)
 * - health_score: Composite user health (0-100)
 *
 * Inspired by ProfitWell Retention Engine, ChartMogel Churn Radar,
 * and Baremetrics Cancellation Insights.
 *
 * @since 8.1.0
 */
final class EventPredictiveScoringService
{
    /** Conversion signal weights — events that increase conversion probability. */
    private const CONVERSION_SIGNALS = [
        'start_trial' => ['weight' => 0.15, 'description' => 'Trial started'],
        'feature_used' => ['weight' => 0.08, 'description' => 'Feature adopted'],
        'login' => ['weight' => 0.03, 'description' => 'Active usage'],
        'page_view' => ['weight' => 0.02, 'description' => 'Page engagement'],
        'search' => ['weight' => 0.05, 'description' => 'Active discovery'],
        'export' => ['weight' => 0.10, 'description' => 'Data investment'],
        'invite_sent' => ['weight' => 0.12, 'description' => 'Team growth'],
        'team_created' => ['weight' => 0.10, 'description' => 'Team investment'],
        'onboarding_step' => ['weight' => 0.07, 'description' => 'Onboarding progress'],
        'form_submit' => ['weight' => 0.05, 'description' => 'Form engagement'],
        'workspace_created' => ['weight' => 0.08, 'description' => 'Workspace setup'],
        'integration_connected' => ['weight' => 0.12, 'description' => 'Integration adoption'],
    ];

    /** Churn signal weights — events that increase churn risk. */
    private const CHURN_SIGNALS = [
        'error' => ['weight' => 0.10, 'description' => 'Error encountered'],
        'cancellation' => ['weight' => 0.50, 'description' => 'Cancellation initiated'],
        'plan_downgrade' => ['weight' => 0.25, 'description' => 'Plan downgraded'],
        'support_contact' => ['weight' => 0.08, 'description' => 'Support contacted'],
        'feature_limit_reached' => ['weight' => 0.15, 'description' => 'Feature limit hit'],
    ];

    /** Expansion signal weights — events that indicate upsell/expansion potential. */
    private const EXPANSION_SIGNALS = [
        'plan_upgrade' => ['weight' => 0.30, 'description' => 'Plan upgraded'],
        'team_created' => ['weight' => 0.20, 'description' => 'Team created'],
        'team_member_joined' => ['weight' => 0.10, 'description' => 'Team member added'],
        'invite_sent' => ['weight' => 0.15, 'description' => 'Invitation sent'],
        'feature_used' => ['weight' => 0.05, 'description' => 'Feature adoption'],
        'workspace_created' => ['weight' => 0.10, 'description' => 'Workspace created'],
        'usage_quota_reached' => ['weight' => 0.15, 'description' => 'Quota limit reached'],
        'export' => ['weight' => 0.08, 'description' => 'Data export activity'],
    ];

    /** Health-positive signals. */
    private const HEALTH_POSITIVE_SIGNALS = [
        'login', 'feature_used', 'page_view', 'search', 'export', 'form_submit',
        'invite_sent', 'team_created', 'workspace_created', 'integration_connected',
        'onboarding_step', 'trial_converted', 'subscribe', 'plan_upgrade',
    ];

    /** Health-negative signals. */
    private const HEALTH_NEGATIVE_SIGNALS = [
        'error', 'cancellation', 'plan_downgrade', 'feature_limit_reached',
        'payment_failed', 'checkout_abandon',
    ];

    private CacheRepository $cache;

    /** @var array{enabled: bool, cache_ttl: int, lookback_days: int, custom_weights: array<string, array<string, float>>, decay_factor: float} */
    private array $config;

    /**
     * @param  CacheRepository  $cache
     * @param  array{enabled?: bool, cache_ttl?: int, lookback_days?: int, custom_weights?: array<string, array<string, float>>, decay_factor?: float}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []): void
    {
        $this->cache = $cache;
        $this->config = [
            'enabled' => $config['enabled'] ?? true,
            'cache_ttl' => $config['cache_ttl'] ?? 600,
            'lookback_days' => $config['lookback_days'] ?? 30,
            'custom_weights' => $config['custom_weights'] ?? [],
            'decay_factor' => $config['decay_factor'] ?? 0.95,
        ];
    }

    /**
     * Compute all predictive scores for a user.
     *
     * @param  string  $identity  User ID or client ID
     * @param  array<int, AnalyticsEvent>  $events  User's recent event history
     * @return array{identity: string, conversion_probability: float, churn_risk: float, expansion_likelihood: float, health_score: int, signals: array<string, mixed>, scored_at: string}
     */
    public function score(string $identity, array $events): array
    {
        if (! $this->config['enabled'] || empty($events)) {
            return $this->emptyScore($identity);
        }

        $signalProfile = $this->buildSignalProfile($events);
        $conversionProb = $this->computeConversionProbability($signalProfile, $events);
        $churnRisk = $this->computeChurnRisk($signalProfile, $events);
        $expansionLikelihood = $this->computeExpansionLikelihood($signalProfile, $events);
        $healthScore = $this->computeHealthScore($signalProfile);

        return [
            'identity' => $identity,
            'conversion_probability' => round($conversionProb, 4),
            'churn_risk' => round($churnRisk, 4),
            'expansion_likelihood' => round($expansionLikelihood, 4),
            'health_score' => $healthScore,
            'health_grade' => $this->healthGrade($healthScore),
            'signals' => $signalProfile,
            'total_events' => count($events),
            'unique_events' => count(array_unique(array_map(fn (AnalyticsEvent $e): string => $e->name, $events))),
            'scored_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Batch score multiple users.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @return array<string, array{conversion_probability: float, churn_risk: float, expansion_likelihood: float, health_score: int, health_grade: string}>
     */
    public function scoreBatch(array $userEvents): array
    {
        $results = [];

        foreach ($userEvents as $identity => $events) {
            $scores = $this->score($identity, $events);
            $results[$identity] = [
                'conversion_probability' => $scores['conversion_probability'],
                'churn_risk' => $scores['churn_risk'],
                'expansion_likelihood' => $scores['expansion_likelihood'],
                'health_score' => $scores['health_score'],
                'health_grade' => $scores['health_grade'],
            ];
        }

        return $results;
    }

    /**
     * Get aggregate scoring summary for a group of users.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @return array{total_users: int, avg_conversion_probability: float, avg_churn_risk: float, avg_expansion_likelihood: float, avg_health_score: float, at_risk_count: int, power_user_count: int, grade_distribution: array<string, int>}
     */
    public function summary(array $userEvents): array
    {
        $scores = $this->scoreBatch($userEvents);
        $totalUsers = count($scores);

        if ($totalUsers === 0) {
            return $this->emptySummary();
        }

        $sumConversion = 0.0;
        $sumChurn = 0.0;
        $sumExpansion = 0.0;
        $sumHealth = 0;
        $atRiskCount = 0;
        $powerUserCount = 0;
        $gradeDistribution = ['A+' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

        foreach ($scores as $score) {
            $sumConversion += $score['conversion_probability'];
            $sumChurn += $score['churn_risk'];
            $sumExpansion += $score['expansion_likelihood'];
            $sumHealth += $score['health_score'];

            if ($score['churn_risk'] >= 0.5) {
                $atRiskCount++;
            }

            if ($score['health_score'] >= 80 && $score['expansion_likelihood'] >= 0.3) {
                $powerUserCount++;
            }

            $grade = $score['health_grade'];
            if (isset($gradeDistribution[$grade])) {
                $gradeDistribution[$grade]++;
            }
        }

        return [
            'total_users' => $totalUsers,
            'avg_conversion_probability' => round($sumConversion / $totalUsers, 4),
            'avg_churn_risk' => round($sumChurn / $totalUsers, 4),
            'avg_expansion_likelihood' => round($sumExpansion / $totalUsers, 4),
            'avg_health_score' => (int) round($sumHealth / $totalUsers),
            'at_risk_count' => $atRiskCount,
            'at_risk_percentage' => round(($atRiskCount / $totalUsers) * 100, 2),
            'power_user_count' => $powerUserCount,
            'power_user_percentage' => round(($powerUserCount / $totalUsers) * 100, 2),
            'grade_distribution' => $gradeDistribution,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get top churn-risk users sorted by risk score.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @param  int  $limit  Maximum results
     * @return array{users: array<string, array{churn_risk: float, health_score: int, top_signals: list<string>}>}
     */
    public function topChurnRisks(array $userEvents, int $limit = 10): array
    {
        $scores = $this->scoreBatch($userEvents);

        // Filter users with churn risk > 0
        $churnRisks = array_filter($scores, fn (array $s): bool => $s['churn_risk'] > 0);

        // Sort by churn risk descending
        uasort($churnRisks, fn (array $a, array $b): int => $b['churn_risk'] <=> $a['churn_risk']);

        return [
            'users' => array_slice($churnRisks, 0, $limit, true),
            'total_at_risk' => count($churnRisks),
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get top expansion candidates sorted by expansion likelihood.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @param  int  $limit
     * @return array{users: array<string, array{expansion_likelihood: float, health_score: int}>}
     */
    public function topExpansionCandidates(array $userEvents, int $limit = 10): array
    {
        $scores = $this->scoreBatch($userEvents);

        $candidates = array_filter($scores, fn (array $s): bool => $s['expansion_likelihood'] > 0);

        uasort($candidates, fn (array $a, array $b): int => $b['expansion_likelihood'] <=> $a['expansion_likelihood']);

        return [
            'users' => array_slice($candidates, 0, $limit, true),
            'total_candidates' => count($candidates),
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build a signal profile from events (name → count with time-decay).
     *
     * @param  array<int, AnalyticsEvent>  $events
     * @return array<string, float>
     */
    private function buildSignalProfile(array $events): array
    {
        $profile = [];
        $now = time();
        $decay = $this->config['decay_factor'];

        // Group by event name with recency decay
        foreach ($events as $event) {
            $name = $event->name;
            $timestamp = $event->timestamp ?? $now;
            $ageHours = max(0, ($now - $timestamp) / 3600);
            $decayFactor = $decay ** $ageHours;

            $profile[$name] = ($profile[$name] ?? 0.0) + $decayFactor;
        }

        return $profile;
    }

    /**
     * Compute conversion probability from signal profile.
     *
     * Uses weighted signal aggregation with diminishing returns (log curve).
     *
     * @param  array<string, float>  $signalProfile
     * @param  array<int, AnalyticsEvent>  $events
     * @return float  0.0-1.0
     */
    private function computeConversionProbability(array $signalProfile, array $events): float
    {
        $signals = $this->mergeCustomWeights('conversion', self::CONVERSION_SIGNALS);
        $rawScore = 0.0;

        foreach ($signals as $eventName => $config) {
            $weight = $config['weight'];
            $count = $signalProfile[$eventName] ?? 0.0;

            // Diminishing returns: log(1 + count) * weight
            $rawScore += log(1 + $count) * $weight;
        }

        // Bonus for diversity of conversion signals
        $matchedSignals = count(array_intersect(array_keys(self::CONVERSION_SIGNALS), array_keys($signalProfile)));
        $diversityBonus = min(0.1, $matchedSignals * 0.02);

        $totalPossible = array_sum(array_column($signals, 'weight')) * 2; // *2 for log scale normalization
        $probability = $totalPossible > 0 ? min(1.0, ($rawScore + $diversityBonus) / $totalPossible) : 0.0;

        return max(0.0, min(1.0, $probability));
    }

    /**
     * Compute churn risk from signal profile.
     *
     * @param  array<string, float>  $signalProfile
     * @param  array<int, AnalyticsEvent>  $events
     * @return float  0.0-1.0
     */
    private function computeChurnRisk(array $signalProfile, array $events): float
    {
        $signals = $this->mergeCustomWeights('churn', self::CHURN_SIGNALS);
        $rawScore = 0.0;

        foreach ($signals as $eventName => $config) {
            $weight = $config['weight'];
            $count = $signalProfile[$eventName] ?? 0.0;
            $rawScore += log(1 + $count) * $weight;
        }

        // Churn is also influenced by declining engagement
        $eventNames = array_map(fn (AnalyticsEvent $e): string => $e->name, $events);
        $halfIndex = (int) (count($eventNames) / 2);
        $recentHalf = array_slice($eventNames, $halfIndex);
        $olderHalf = array_slice($eventNames, 0, $halfIndex);

        if (! empty($olderHalf)) {
            $engagementRatio = count($recentHalf) / count($olderHalf);
            if ($engagementRatio < 0.5) {
                $rawScore += 0.15; // Significant decline
            } elseif ($engagementRatio < 0.8) {
                $rawScore += 0.05; // Moderate decline
            }
        }

        // Low recency bonus for churn
        $timestamps = array_filter(array_map(fn (AnalyticsEvent $e): ?int => $e->timestamp ?? null, $events));
        if (! empty($timestamps)) {
            $lastEvent = max($timestamps);
            $daysSinceLast = (time() - $lastEvent) / 86400;
            if ($daysSinceLast > 14) {
                $rawScore += 0.10;
            } elseif ($daysSinceLast > 7) {
                $rawScore += 0.05;
            }
        }

        $totalPossible = array_sum(array_column($signals, 'weight')) * 2;
        $risk = $totalPossible > 0 ? min(1.0, $rawScore / $totalPossible) : 0.0;

        return max(0.0, min(1.0, $risk));
    }

    /**
     * Compute expansion likelihood from signal profile.
     *
     * @param  array<string, float>  $signalProfile
     * @param  array<int, AnalyticsEvent>  $events
     * @return float  0.0-1.0
     */
    private function computeExpansionLikelihood(array $signalProfile, array $events): float
    {
        $signals = $this->mergeCustomWeights('expansion', self::EXPANSION_SIGNALS);
        $rawScore = 0.0;

        foreach ($signals as $eventName => $config) {
            $weight = $config['weight'];
            $count = $signalProfile[$eventName] ?? 0.0;
            $rawScore += log(1 + $count) * $weight;
        }

        // High engagement bonus
        if (count($events) >= 50) {
            $rawScore += 0.1;
        }

        $totalPossible = array_sum(array_column($signals, 'weight')) * 2;
        $likelihood = $totalPossible > 0 ? min(1.0, $rawScore / $totalPossible) : 0.0;

        return max(0.0, min(1.0, $likelihood));
    }

    /**
     * Compute composite health score (0-100).
     *
     * Combines conversion probability, inverse churn risk, expansion likelihood,
     * and engagement level into a single score.
     *
     * @param  array<string, float>  $signalProfile
     * @return int  0-100
     */
    private function computeHealthScore(array $signalProfile): int
    {
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($signalProfile as $event => $count) {
            if (in_array($event, self::HEALTH_POSITIVE_SIGNALS, true)) {
                $positiveCount += $count;
            }
            if (in_array($event, self::HEALTH_NEGATIVE_SIGNALS, true)) {
                $negativeCount += $count;
            }
        }

        $totalSignals = $positiveCount + $negativeCount;
        if ($totalSignals === 0) {
            return 50; // Neutral
        }

        $positivity = $positiveCount / $totalSignals;
        $signalDiversity = count($signalProfile);

        // Base score from positivity ratio (0-60)
        $baseScore = $positivity * 60;

        // Diversity bonus (0-20): more diverse signals = healthier
        $diversityBonus = min(20, $signalDiversity * 2);

        // Volume bonus (0-20): more positive signals = healthier
        $volumeBonus = min(20, $positiveCount * 0.5);

        $score = (int) round(min(100, max(0, $baseScore + $diversityBonus + $volumeBonus)));

        return $score;
    }

    /**
     * Convert numeric health score to letter grade.
     *
     * @return 'A+'|'A'|'B'|'C'|'D'|'F'
     */
    private function healthGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'F',
        };
    }

    /**
     * Merge custom weights with built-in signal weights.
     *
     * @param  string  $type  'conversion'|'churn'|'expansion'
     * @param  array<string, array{weight: float, description: string}>  $builtIn
     * @return array<string, array{weight: float, description: string}>
     */
    private function mergeCustomWeights(string $type, array $builtIn): array
    {
        $custom = $this->config['custom_weights'][$type] ?? [];

        if (empty($custom)) {
            return $builtIn;
        }

        $merged = $builtIn;

        foreach ($custom as $event => $weight) {
            if (isset($merged[$event])) {
                $merged[$event]['weight'] = $weight;
            } else {
                $merged[$event] = ['weight' => $weight, 'description' => 'Custom signal'];
            }
        }

        return $merged;
    }

    /**
     * Return an empty score structure.
     *
     * @param  string  $identity
     * @return array<string, mixed>
     */
    private function emptyScore(string $identity): array
    {
        return [
            'identity' => $identity,
            'conversion_probability' => 0.0,
            'churn_risk' => 0.0,
            'expansion_likelihood' => 0.0,
            'health_score' => 50,
            'health_grade' => 'C',
            'signals' => [],
            'total_events' => 0,
            'unique_events' => 0,
            'scored_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Return an empty summary structure.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'total_users' => 0,
            'avg_conversion_probability' => 0.0,
            'avg_churn_risk' => 0.0,
            'avg_expansion_likelihood' => 0.0,
            'avg_health_score' => 0,
            'at_risk_count' => 0,
            'at_risk_percentage' => 0.0,
            'power_user_count' => 0,
            'power_user_percentage' => 0.0,
            'grade_distribution' => ['A+' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
            'analyzed_at' => now()->toIso8601String(),
        ];
    }
}
