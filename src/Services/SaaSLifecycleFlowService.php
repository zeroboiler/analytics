<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS lifecycle funnel tracking service.
 *
 * Provides a high-level API for tracking the standard SaaS customer funnel:
 *   anonymous → signed_up → trialing → subscribed → activated → expanding → retained → champion
 *
 * Each method dispatches the appropriate analytics event AND returns
 * the resulting funnel stage for server-side tracking (e.g., storing
 * the user's funnel stage in the database or cache).
 *
 * Inspired by industry-standard SaaS funnel tracking from Segment,
 * Mixpanel, and Amplitude best practices.
 *
 * Usage:
 *   $flow = app(SaaSLifecycleFlowService::class);
 *   $flow->trackSignUp($userId, method: 'email', referrer: 'google');
 *   $flow->trackTrialStart($userId, plan: 'pro', days: 14);
 *   $stage = $flow->getStage($userId); // 'trialing'
 *
 * @since 194.0.0
 */
final class SaaSLifecycleFlowService
{
    /**
     * Ordered SaaS lifecycle funnel stages.
     *
     * @var list<string>
     */
    public const STAGES = [
        'anonymous',
        'signed_up',
        'trialing',
        'subscribed',
        'activated',
        'expanding',
        'retained',
        'champion',
    ];

    /**
     * Stage index lookup for quick comparison.
     *
     * @var array<string, int>
     */
    private const STAGE_INDEX = [
        'anonymous' => 0,
        'signed_up' => 1,
        'trialing' => 2,
        'subscribed' => 3,
        'activated' => 4,
        'expanding' => 5,
        'retained' => 6,
        'champion' => 7,
    ];

    /**
     * SaaS event name to funnel stage mapping.
     *
     * @var array<string, string|null>
     */
    private const EVENT_STAGE_MAP = [
        'sign_up' => 'signed_up',
        'start_trial' => 'trialing',
        'subscribe' => 'subscribed',
        'plan_upgrade' => 'expanding',
        'feature_used' => null, // Doesn't change stage alone
        'subscription_renewal' => 'retained',
    ];

    /** @var AnalyticsManager|null */
    private ?AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager|null  $manager  Analytics manager (null for track-only mode)
     */
    public function __construct(?AnalyticsManager $manager = null): void
    {
        $this->manager = $manager;
    }

    /**
     * Get all funnel stages.
     *
     * @return list<string>
     */
    public static function stages(): array
    {
        return self::STAGES;
    }

    /**
     * Get the numeric index of a funnel stage.
     *
     * @return int 0-based index, or 0 for unknown stages
     */
    public static function stageIndex(string $stage): int
    {
        return self::STAGE_INDEX[$stage] ?? 0;
    }

    /**
     * Resolve the target funnel stage for a given event name.
     *
     * @return string|null Target stage, or null if the event doesn't change the stage
     */
    public static function resolveStageForEvent(string $eventName): ?string
    {
        return self::EVENT_STAGE_MAP[$eventName] ?? null;
    }

    /**
     * Check if advancing from one stage to another is a forward progression.
     *
     * @return bool True if $toStage is strictly after $fromStage
     */
    public static function isForwardProgression(string $fromStage, string $toStage): bool
    {
        $fromIdx = self::STAGE_INDEX[$fromStage] ?? 0;
        $toIdx = self::STAGE_INDEX[$toStage] ?? 0;

        return $toIdx > $fromIdx;
    }

    /**
     * Calculate funnel progress as a percentage (0.0 to 1.0).
     *
     * @return float Progress value
     */
    public static function progressForStage(string $stage): float
    {
        $idx = self::STAGE_INDEX[$stage] ?? 0;
        $maxIdx = count(self::STAGES) - 1;

        return $maxIdx > 0 ? round($idx / $maxIdx, 2) : 0.0;
    }

    /**
     * Get the next expected stage after a given stage.
     *
     * @return string|null Next stage name, or null if at 'champion'
     */
    public static function nextStageAfter(string $stage): ?string
    {
        $idx = self::STAGE_INDEX[$stage] ?? 0;

        return self::STAGES[$idx + 1] ?? null;
    }

    /**
     * Track a sign-up event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (method, referrer, etc.)
     * @return string New funnel stage ('signed_up')
     */
    public function trackSignUp(?string $userId, array $params = []): string
    {
        $this->dispatch('sign_up', $userId, $params, 'saas');

        return 'signed_up';
    }

    /**
     * Track a trial start event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (plan, days, trial_id)
     * @return string New funnel stage ('trialing')
     */
    public function trackTrialStart(?string $userId, array $params = []): string
    {
        $this->dispatch('start_trial', $userId, $params, 'saas');

        return 'trialing';
    }

    /**
     * Track a subscription event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (plan, mrr, billing_cycle)
     * @return string New funnel stage ('subscribed')
     */
    public function trackSubscription(?string $userId, array $params = []): string
    {
        $this->dispatch('subscribe', $userId, $params, 'saas');

        return 'subscribed';
    }

    /**
     * Track a plan upgrade event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (previous_plan, new_plan, additional_mrr)
     * @return string New funnel stage ('expanding')
     */
    public function trackPlanUpgrade(?string $userId, array $params = []): string
    {
        $this->dispatch('plan_upgrade', $userId, $params, 'saas');

        return 'expanding';
    }

    /**
     * Track an activation (first value) event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (milestone, time_to_activate)
     * @return string New funnel stage ('activated')
     */
    public function trackActivation(?string $userId, array $params = []): string
    {
        $this->dispatch('feature_used', $userId, array_merge($params, [
            'feature_name' => $params['milestone'] ?? 'activation',
        ]), 'saas');

        return 'activated';
    }

    /**
     * Track a renewal event and return the new funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (renewal_count, tenure_months)
     * @return string New funnel stage ('retained')
     */
    public function trackRenewal(?string $userId, array $params = []): string
    {
        $this->dispatch('subscription_renewal', $userId, $params, 'saas');

        return 'retained';
    }

    /**
     * Track a cancellation event. Does NOT change the funnel stage.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters (reason, feedback, plan)
     */
    public function trackCancellation(?string $userId, array $params = []): void
    {
        $this->dispatch('cancellation', $userId, $params, 'saas');
    }

    /**
     * Get a funnel summary for a user based on their current stage.
     *
     * @param  string  $currentStage  Current funnel stage
     * @return array{stage: string, progress: float, next_stage: string|null, stages: list<string>}
     */
    public static function funnelSummary(string $currentStage): array
    {
        return [
            'stage' => $currentStage,
            'progress' => self::progressForStage($currentStage),
            'next_stage' => self::nextStageAfter($currentStage),
            'stages' => self::STAGES,
        ];
    }

    /**
     * Get a complete funnel breakdown with event names for each stage.
     *
     * Returns each stage with its index, progress, and the SaaS catalog
     * event names that can trigger a transition to that stage.
     *
     * @return array<int, array{stage: string, index: int, progress: float, trigger_events: list<string>}>
     */
    public static function funnelBreakdown(): array
    {
        $breakdown = [];

        foreach (self::STAGES as $idx => $stage) {
            $triggerEvents = [];
            foreach (self::EVENT_STAGE_MAP as $event => $targetStage) {
                if ($targetStage === $stage) {
                    $triggerEvents[] = $event;
                }
            }

            $breakdown[] = [
                'stage' => $stage,
                'index' => $idx,
                'progress' => self::progressForStage($stage),
                'trigger_events' => $triggerEvents,
            ];
        }

        return $breakdown;
    }

    /**
     * Dispatch an analytics event through the manager.
     *
     * @param  string  $name  Event name
     * @param  string|null  $userId  User ID
     * @param  array<string, mixed>  $params  Event params
     * @param  string  $category  Event category
     */
    private function dispatch(string $name, ?string $userId, array $params, string $category): void
    {
        $this->manager?->trackEvent(new AnalyticsEvent(
            name: $name,
            params: $params,
            userId: $userId,
            category: $category,
        ));
    }
}
