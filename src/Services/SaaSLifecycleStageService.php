<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * SaaS User Lifecycle Stage Detection Service.
 *
 * Automatically determines each user's current lifecycle stage based on
 * their behavioral signals and event history. Assigns users to one of
 * six lifecycle stages: **Prospect**, **Trial**, **Active**, **Engaged**,
 * **At Risk**, and **Churned**.
 *
 * Stage assignment rules are config-driven and evaluate a combination of:
 * - **Time-based signals** (days since signup, last activity)
 * - **Event-based signals** (trial events, subscription events, feature usage)
 * - **Engagement signals** (DAU streak, events per week, feature adoption count)
 * - **Revenue signals** (plan tier, MRR contribution, expansion events)
 *
 * Each stage transition is tracked with timestamps for lifecycle analytics.
 * The service supports:
 * - **Stage distribution** (count/percentage per stage)
 * - **Stage transitions** (flow analysis: which stages do users move to/from)
 * - **Risk scoring** (quantitative at-risk score for each user)
 * - **Cohort stage breakdown** (per-signup-cohort stage distribution)
 * - **Actionable recommendations** (stage-specific re-engagement suggestions)
 *
 * Configuration: `zeroboiler.analytics.lifecycle_stages`
 *
 * @phpstan-type LifecycleStageDefinition array{key: string, name: string, description: string, order: int, color: string, max_days_inactive: int|null, min_events_per_week: int|null, required_events: list<string>, excluded_events: list<string>, plan_requirements: list<string>|null, engagement_thresholds: array{min_dau_streak: int|null, min_features_used: int|null, min_events_per_week: int|null}|null}
 * @phpstan-type UserStageRecord array{user_id: string, stage: string, previous_stage: string|null, score: float, entered_at: string|null, signals: array<string, mixed>, reasons: list<string>}
 * @phpstan-type StageDistribution array{stages: array<string, array{count: int, percentage: float, avg_risk_score: float, avg_days_in_stage: float}>, total_users: int, computed_at: string}
 * @phpstan-type StageTransition array{from: string, to: string, count: int, rate: float}
 *
 * @since 185.0.0
 */
final class SaaSLifecycleStageService
{
    private const CACHE_PREFIX = 'zb_lifecycle_stage_';

    private const DEFAULT_TTL = 3600; // 1 hour

    /** @var array<string, LifecycleStageDefinition> */
    private const DEFAULT_STAGES = [
        'prospect' => [
            'key' => 'prospect',
            'name' => 'Prospect',
            'description' => 'Signed up but has not started a trial or used the product',
            'order' => 0,
            'color' => '#94A3B8',
            'max_days_inactive' => 30,
            'min_events_per_week' => null,
            'required_events' => ['sign_up'],
            'excluded_events' => ['start_trial', 'subscription.created'],
            'plan_requirements' => null,
            'engagement_thresholds' => null,
        ],
        'trial' => [
            'key' => 'trial',
            'name' => 'Trial',
            'description' => 'Active trial user — has started but not yet converted to paid',
            'order' => 1,
            'color' => '#F59E0B',
            'max_days_inactive' => 14,
            'min_events_per_week' => 2,
            'required_events' => ['start_trial'],
            'excluded_events' => ['subscription.created'],
            'plan_requirements' => null,
            'engagement_thresholds' => [
                'min_dau_streak' => null,
                'min_features_used' => 1,
                'min_events_per_week' => 2,
            ],
        ],
        'active' => [
            'key' => 'active',
            'name' => 'Active',
            'description' => 'Paid subscriber with regular usage',
            'order' => 2,
            'color' => '#10B981',
            'max_days_inactive' => 7,
            'min_events_per_week' => 5,
            'required_events' => ['subscription.created'],
            'excluded_events' => [],
            'plan_requirements' => null,
            'engagement_thresholds' => [
                'min_dau_streak' => 2,
                'min_features_used' => 2,
                'min_events_per_week' => 5,
            ],
        ],
        'engaged' => [
            'key' => 'engaged',
            'name' => 'Engaged',
            'description' => 'Power user — high frequency, broad feature adoption, deep engagement',
            'order' => 3,
            'color' => '#3B82F6',
            'max_days_inactive' => 3,
            'min_events_per_week' => 15,
            'required_events' => ['subscription.created'],
            'excluded_events' => [],
            'plan_requirements' => null,
            'engagement_thresholds' => [
                'min_dau_streak' => 5,
                'min_features_used' => 5,
                'min_events_per_week' => 15,
            ],
        ],
        'at_risk' => [
            'key' => 'at_risk',
            'name' => 'At Risk',
            'description' => 'Subscribed but showing declining engagement signals',
            'order' => 4,
            'color' => '#EF4444',
            'max_days_inactive' => null,
            'min_events_per_week' => null,
            'required_events' => ['subscription.created'],
            'excluded_events' => ['subscription.cancelled'],
            'plan_requirements' => null,
            'engagement_thresholds' => [
                'min_dau_streak' => null,
                'min_features_used' => null,
                'min_events_per_week' => 1,
            ],
        ],
        'churned' => [
            'key' => 'churned',
            'name' => 'Churned',
            'description' => 'Subscription cancelled or long-term inactive',
            'order' => 5,
            'color' => '#6B7280',
            'max_days_inactive' => null,
            'min_events_per_week' => null,
            'required_events' => ['subscription.cancelled'],
            'excluded_events' => [],
            'plan_requirements' => null,
            'engagement_thresholds' => null,
        ],
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, LifecycleStageDefinition> */
    private array $stageDefinitions;

    /** @var int Number of days of inactivity to consider "at risk" */
    private int $atRiskInactiveDays;

    /** @var int Number of days of inactivity to consider "churned" (if not explicitly cancelled) */
    private int $churnedInactiveDays;

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $stageConfig = $config->get('zeroboiler.analytics.lifecycle_stages', []);
        /** @var array{enabled?: bool, cache_ttl?: int, stages?: array<string, LifecycleStageDefinition>, at_risk_inactive_days?: int, churned_inactive_days?: int} $stageConfig */
        $this->enabled = (bool) ($stageConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($stageConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->stageDefinitions = (array) ($stageConfig['stages'] ?? self::DEFAULT_STAGES);
        $this->atRiskInactiveDays = (int) ($stageConfig['at_risk_inactive_days'] ?? 14);
        $this->churnedInactiveDays = (int) ($stageConfig['churned_inactive_days'] ?? 45);
    }

    /**
     * Check if the lifecycle stage service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Determine the lifecycle stage for a user based on their signals.
     *
     * Evaluates signals in priority order: explicit events → engagement → inactivity.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $signals  User behavioral signals:
     *   - events: list<string> Event names the user has triggered
     *   - last_activity_at: string|null ISO date of last activity
     *   - signed_up_at: string|null ISO date of signup
     *   - days_since_signup: int|null
     *   - days_since_last_activity: int|null
     *   - events_per_week: float|null Average events in the last 7 days
     *   - dau_streak: int|null Consecutive days active
     *   - features_used: int|null Number of distinct features used
     *   - plan: string|null Current subscription plan
     *   - mrr: float|null Monthly recurring revenue contribution
     * @return UserStageRecord
     */
    public function determineStage(string $userId, array $signals): array
    {
        $events = array_map('strval', $signals['events'] ?? []);
        $daysSinceActivity = (int) ($signals['days_since_last_activity'] ?? 0);
        $eventsPerWeek = (float) ($signals['events_per_week'] ?? 0.0);
        $dauStreak = (int) ($signals['dau_streak'] ?? 0);
        $featuresUsed = (int) ($signals['features_used'] ?? 0);

        $reasons = [];
        $stage = 'prospect';
        $score = 0.0;

        // 1. Check for explicit churn signal
        if (in_array('subscription.cancelled', $events, true)) {
            $stage = 'churned';
            $score = 0.0;
            $reasons[] = 'Subscription cancelled detected';

            return $this->buildStageRecord($userId, $stage, $score, $signals, $reasons);
        }

        // 2. Check for subscription (rules out prospect + trial)
        $hasSubscription = in_array('subscription.created', $events, true)
            || in_array('subscription.renewal', $events, true);

        // 3. Check for trial (without subscription)
        $hasTrial = in_array('start_trial', $events, true) && ! $hasSubscription;

        // 4. Determine subscription-based stage
        if ($hasSubscription) {
            $thresholds = $this->getEngagementThresholds('engaged');
            $activeThresholds = $this->getEngagementThresholds('active');

            if ($this->meetsThresholds($thresholds, $eventsPerWeek, $dauStreak, $featuresUsed)) {
                $stage = 'engaged';
                $score = min(1.0, ($eventsPerWeek / 20.0) + ($dauStreak / 10.0) + ($featuresUsed / 10.0));
                $reasons[] = 'Meets engaged thresholds (high frequency + broad adoption)';
            } elseif ($daysSinceActivity >= $this->atRiskInactiveDays) {
                $stage = 'at_risk';
                $score = max(0.0, 1.0 - ($daysSinceActivity / $this->churnedInactiveDays));
                $reasons[] = "Inactive for {$daysSinceActivity} days (≥ {$this->atRiskInactiveDays} day threshold)";
            } elseif ($this->meetsThresholds($activeThresholds, $eventsPerWeek, $dauStreak, $featuresUsed)) {
                $stage = 'active';
                $score = min(1.0, ($eventsPerWeek / 15.0) + ($dauStreak / 7.0) + ($featuresUsed / 5.0));
                $reasons[] = 'Active subscriber with regular usage';
            } else {
                $stage = 'active';
                $score = min(1.0, ($eventsPerWeek / 10.0) + ($dauStreak / 5.0));
                $reasons[] = 'Subscribed but below optimal engagement thresholds';
            }
        } elseif ($hasTrial) {
            $stage = 'trial';
            $trialThresholds = $this->getEngagementThresholds('trial');

            if ($trialThresholds !== null && ! $this->meetsThresholds($trialThresholds, $eventsPerWeek, $dauStreak, $featuresUsed)) {
                $score = 0.3;
                $reasons[] = 'Trial user with low engagement activity';
            } else {
                $score = min(1.0, ($eventsPerWeek / 10.0) + ($featuresUsed / 5.0));
                $reasons[] = 'Active trial user';
            }

            // Trial user inactive for too long → treat as at_risk prospect
            if ($daysSinceActivity >= $this->atRiskInactiveDays) {
                $stage = 'prospect';
                $score = 0.2;
                $reasons[] = "Trial user inactive for {$daysSinceActivity} days — likely abandoned";
            }
        } else {
            // Prospect: signed up but no trial/subscription
            $stage = 'prospect';
            $score = min(0.5, ($eventsPerWeek / 5.0));

            if ($eventsPerWeek > 0) {
                $reasons[] = 'Engaged prospect — showing interest signals';
            } else {
                $reasons[] = 'Passive prospect — no significant engagement';
            }

            // Long-inactive prospect
            if ($daysSinceActivity >= $this->churnedInactiveDays) {
                $score = 0.0;
                $reasons[] = "Inactive for {$daysSinceActivity} days — effectively churned prospect";
            }
        }

        return $this->buildStageRecord($userId, $stage, $score, $signals, $reasons);
    }

    /**
     * Compute stage distribution across a batch of users.
     *
     * @param  array<string, array<string, mixed>>  $userSignals  Map of userId => signals
     * @return StageDistribution
     */
    public function computeDistribution(array $userSignals): array
    {
        $counts = array_fill_keys(array_keys($this->stageDefinitions), 0);
        $riskScores = array_fill_keys(array_keys($this->stageDefinitions), []);
        $daysInStage = array_fill_keys(array_keys($this->stageDefinitions), []);
        $totalUsers = count($userSignals);

        foreach ($userSignals as $userId => $signals) {
            $record = $this->determineStage($userId, $signals);
            $stage = $record['stage'];

            if (isset($counts[$stage])) {
                $counts[$stage]++;
            } else {
                $counts[$stage] = 1;
            }

            $riskScores[$stage][] = $record['score'];

            if (isset($signals['days_since_signup'])) {
                $daysInStage[$stage][] = (int) $signals['days_since_signup'];
            }
        }

        $stages = [];

        foreach ($counts as $stageKey => $count) {
            $stageDef = $this->stageDefinitions[$stageKey] ?? [];
            $stageScores = $riskScores[$stageKey] ?? [];
            $stageDays = $daysInStage[$stageKey] ?? [];

            $stages[$stageKey] = [
                'name' => $stageDef['name'] ?? $stageKey,
                'color' => $stageDef['color'] ?? '#000000',
                'description' => $stageDef['description'] ?? '',
                'count' => $count,
                'percentage' => $totalUsers > 0 ? round(($count / $totalUsers) * 100, 1) : 0.0,
                'avg_risk_score' => count($stageScores) > 0
                    ? round(array_sum($stageScores) / count($stageScores), 3)
                    : 0.0,
                'avg_days_in_stage' => count($stageDays) > 0
                    ? round(array_sum($stageDays) / count($stageDays), 1)
                    : 0.0,
            ];
        }

        return [
            'stages' => $stages,
            'total_users' => $totalUsers,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Analyze stage transitions over time.
     *
     * Given two distribution snapshots (e.g., current vs previous period),
     * computes the net flow between stages.
     *
     * @param  StageDistribution  $current
     * @param  StageDistribution  $previous
     * @return array{transitions: list<StageTransition>, net_changes: array<string, int>, health_indicators: array{healthy_flow: bool, concern_stages: list<string>}}
     */
    public function analyzeTransitions(array $current, array $previous): array
    {
        $transitions = [];
        $netChanges = [];
        $concernStages = [];

        $currentStages = $current['stages'] ?? [];
        $previousStages = $previous['stages'] ?? [];

        foreach ($currentStages as $stageKey => $currentData) {
            $previousCount = $previousStages[$stageKey]['count'] ?? 0;
            $currentCount = $currentData['count'];
            $diff = $currentCount - $previousCount;

            $netChanges[$stageKey] = $diff;

            if ($diff !== 0) {
                // Estimate transition rate
                $rate = $previousCount > 0 ? (float) abs($diff) / $previousCount : 0.0;
                $fromStage = $diff > 0 ? 'other' : $stageKey;
                $toStage = $diff > 0 ? $stageKey : 'other';

                $transitions[] = [
                    'from' => $fromStage,
                    'to' => $toStage,
                    'count' => abs($diff),
                    'rate' => round($rate, 4),
                ];
            }

            // Flag concerning trends
            if ($stageKey === 'at_risk' && $diff > 0) {
                $concernStages[] = 'at_risk (growing)';
            }
            if ($stageKey === 'churned' && $diff > 0) {
                $concernStages[] = 'churned (growing)';
            }
            if ($stageKey === 'engaged' && $diff < 0) {
                $concernStages[] = 'engaged (declining)';
            }
        }

        $healthyFlow = empty($concernStages);

        return [
            'transitions' => $transitions,
            'net_changes' => $netChanges,
            'health_indicators' => [
                'healthy_flow' => $healthyFlow,
                'concern_stages' => $concernStages,
            ],
        ];
    }

    /**
     * Compute per-cohort stage breakdown.
     *
     * Groups users by signup cohort and computes stage distribution for each.
     *
     * @param  array<string, array<string, mixed>>  $userSignals  Map of userId => signals
     * @return array{cohorts: array<string, StageDistribution>, overall: StageDistribution}
     */
    public function cohortBreakdown(array $userSignals): array
    {
        $byCohort = [];

        foreach ($userSignals as $userId => $signals) {
            $cohort = (string) ($signals['cohort'] ?? ($signals['signed_up_at'] ?? 'unknown'));

            if (! isset($byCohort[$cohort])) {
                $byCohort[$cohort] = [];
            }

            $byCohort[$cohort][$userId] = $signals;
        }

        $cohorts = [];

        foreach ($byCohort as $cohortId => $cohortSignals) {
            $cohorts[$cohortId] = $this->computeDistribution($cohortSignals);
        }

        return [
            'cohorts' => $cohorts,
            'overall' => $this->computeDistribution($userSignals),
        ];
    }

    /**
     * Get actionable recommendations for users in a specific stage.
     *
     * @param  string  $stage  Stage key
     * @return list<string>
     */
    public function stageRecommendations(string $stage): array
    {
        $recommendations = match ($stage) {
            'prospect' => [
                'Trigger trial start with in-app prompts after signup',
                'Send onboarding email sequence highlighting top features',
                'Offer time-limited trial to create urgency',
                'Display social proof (testimonials, user counts)',
            ],
            'trial' => [
                'Guide users through activation milestones',
                'Monitor trial engagement and intervene at day 3-5',
                'Show value through personalized feature recommendations',
                'Offer extended trial for users showing engagement',
            ],
            'active' => [
                'Encourage deeper feature adoption with in-app tooltips',
                'Introduce collaboration features to increase stickiness',
                'Prompt for team invitations to build multi-user dependency',
                'Show usage analytics to reinforce value',
            ],
            'engaged' => [
                'Identify and nurture as potential advocates/champions',
                'Offer early access to new features (VIP treatment)',
                'Request testimonials and case studies',
                'Introduce referral program participation',
            ],
            'at_risk' => [
                'Send re-engagement email with personalized usage summary',
                'Offer temporary discount or credit to prevent churn',
                'Schedule proactive customer success outreach',
                'Identify and resolve specific pain points',
            ],
            'churned' => [
                'Launch win-back campaign with special offers',
                'Survey for churn reasons to improve product',
                'Monitor for return signals (website visits, support tickets)',
                'Maintain light touch email nurture sequence',
            ],
            default => ['No specific recommendations for unknown stage'],
        };

        return $recommendations;
    }

    /**
     * Quick summary of stage distribution.
     *
     * @param  StageDistribution  $distribution
     * @return array{healthy_ratio: float, at_risk_count: int, churned_count: int, engaged_count: int, top_stage: string, recommendation: string}
     */
    public function quickSummary(array $distribution): array
    {
        $stages = $distribution['stages'] ?? [];
        $total = $distribution['total_users'] ?? 0;

        $healthyCount = ($stages['active']['count'] ?? 0) + ($stages['engaged']['count'] ?? 0);
        $healthyRatio = $total > 0 ? (float) $healthyCount / $total : 0.0;
        $atRiskCount = $stages['at_risk']['count'] ?? 0;
        $churnedCount = $stages['churned']['count'] ?? 0;
        $engagedCount = $stages['engaged']['count'] ?? 0;

        $topStage = 'prospect';
        $topCount = 0;

        foreach ($stages as $stageKey => $data) {
            if (($data['count'] ?? 0) > $topCount) {
                $topCount = $data['count'];
                $topStage = $stageKey;
            }
        }

        $recommendation = 'Distribution looks healthy';
        if ($atRiskCount > $total * 0.2) {
            $recommendation = 'High proportion of at-risk users — proactive outreach recommended';
        } elseif ($churnedCount > $total * 0.3) {
            $recommendation = 'High churn rate — review onboarding and value delivery';
        } elseif ($healthyRatio > 0.6) {
            $recommendation = 'Strong user base — focus on engagement depth and expansion';
        }

        return [
            'healthy_ratio' => round($healthyRatio, 3),
            'at_risk_count' => $atRiskCount,
            'churned_count' => $churnedCount,
            'engaged_count' => $engagedCount,
            'top_stage' => $topStage,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Get all stage definitions.
     *
     * @return array<string, LifecycleStageDefinition>
     */
    public function getStages(): array
    {
        return $this->stageDefinitions;
    }

    /**
     * Get ordered stage keys.
     *
     * @return list<string>
     */
    public function getStageKeys(): array
    {
        $stages = $this->stageDefinitions;
        uasort($stages, fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return array_keys($stages);
    }

    /**
     * Invalidate cached stage computations.
     */
    public function invalidateCache(): void
    {
        // Cache invalidation handled by content-based keys
    }

    /**
     * Build a stage record.
     *
     * @return UserStageRecord
     */
    private function buildStageRecord(string $userId, string $stage, float $score, array $signals, array $reasons): array
    {
        return [
            'user_id' => $userId,
            'stage' => $stage,
            'previous_stage' => null,
            'score' => round($score, 4),
            'entered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'signals' => $signals,
            'reasons' => $reasons,
        ];
    }

    /**
     * Get engagement thresholds for a stage.
     *
     * @return array{min_dau_streak: int|null, min_features_used: int|null, min_events_per_week: int|null}|null
     */
    private function getEngagementThresholds(string $stageKey): ?array
    {
        return $this->stageDefinitions[$stageKey]['engagement_thresholds'] ?? null;
    }

    /**
     * Check if engagement signals meet the given thresholds.
     */
    private function meetsThresholds(
        ?array $thresholds,
        float $eventsPerWeek,
        int $dauStreak,
        int $featuresUsed,
    ): bool {
        if ($thresholds === null) {
            return false;
        }

        $minEvents = $thresholds['min_events_per_week'] ?? 0;
        $minStreak = $thresholds['min_dau_streak'] ?? 0;
        $minFeatures = $thresholds['min_features_used'] ?? 0;

        return $eventsPerWeek >= $minEvents
            && $dauStreak >= $minStreak
            && $featuresUsed >= $minFeatures;
    }
}
