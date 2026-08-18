<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\AnalyticsManager;
/**
 * Quick static helpers for common SaaS analytics events.
 *
 * Provides one-liner methods for the most frequent SaaS event patterns
 * (sign up, trial, subscription, plan change, etc.) to reduce boilerplate
 * in controllers and services.
 *
 * All methods dispatch via the AnalyticsManager singleton and accept
 * optional user/client IDs for identity enrichment.
 *
 * @since 195.0.0
 */
final class SaaSEventHelpers
{
    /**
     * Track a user sign-up event.
     *
     * @param  string  $method  Registration method (email, google, github, etc.)
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function signUp(string $method = 'email', array $extra = []): void
    {
        self::manager()->track('sign_up', array_merge([
            'method' => $method,
            'timestamp' => now()->toIso8601String(),
        ], $extra));
    }

    /**
     * Track a user login event.
     *
     * @param  string  $method  Auth method (email, oauth, sso, etc.)
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function login(string $method = 'email', array $extra = []): void
    {
        self::manager()->track('login', array_merge([
            'method' => $method,
        ], $extra));
    }

    /**
     * Track a trial start event.
     *
     * @param  string  $plan  Trial plan name
     * @param  int  $durationDays  Trial duration in days
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function trialStart(string $plan = 'free', int $durationDays = 14, array $extra = []): void
    {
        self::manager()->track('trial_start', array_merge([
            'plan' => $plan,
            'duration_days' => $durationDays,
        ], $extra));
    }

    /**
     * Track a subscription event (new, renewal, or upgrade).
     *
     * @param  string  $plan  Plan name
     * @param  float  $mrr  Monthly recurring revenue
     * @param  string  $billingCycle  monthly, yearly, etc.
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function subscription(string $plan, float $mrr, string $billingCycle = 'monthly', array $extra = []): void
    {
        self::manager()->track('subscription', array_merge([
            'plan' => $plan,
            'mrr' => $mrr,
            'billing_cycle' => $billingCycle,
        ], $extra));
    }

    /**
     * Track a plan upgrade event.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float  $mrrDelta  Revenue difference
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function planUpgrade(string $fromPlan, string $toPlan, float $mrrDelta = 0.0, array $extra = []): void
    {
        self::manager()->track('plan_upgrade', array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'mrr_delta' => $mrrDelta,
        ], $extra));
    }

    /**
     * Track a plan downgrade event.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float  $mrrDelta  Revenue difference (negative)
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function planDowngrade(string $fromPlan, string $toPlan, float $mrrDelta = 0.0, array $extra = []): void
    {
        self::manager()->track('plan_downgrade', array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'mrr_delta' => $mrrDelta,
        ], $extra));
    }

    /**
     * Track a cancellation event.
     *
     * @param  string  $plan  Cancelled plan name
     * @param  string|null  $reason  Cancellation reason
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function cancellation(string $plan, ?string $reason = null, array $extra = []): void
    {
        self::manager()->track('cancellation', array_merge([
            'plan' => $plan,
            'reason' => $reason,
        ], $extra));
    }

    /**
     * Track a feature used event.
     *
     * @param  string  $feature  Feature name/identifier
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function featureUsed(string $feature, array $extra = []): void
    {
        self::manager()->track('feature_used', array_merge([
            'feature' => $feature,
        ], $extra));
    }

    /**
     * Track a team/invite event.
     *
     * @param  string  $action  invited, accepted, declined
     * @param  string|null  $role  Assigned role
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function teamEvent(string $action, ?string $role = null, array $extra = []): void
    {
        self::manager()->track('team_' . $action, array_merge([
            'role' => $role,
        ], $extra));
    }

    /**
     * Track an onboarding step event.
     *
     * @param  string  $step  Step identifier
     * @param  int  $stepNumber  Sequential step number
     * @param  int  $totalSteps  Total onboarding steps
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function onboardingStep(string $step, int $stepNumber, int $totalSteps, array $extra = []): void
    {
        self::manager()->track('onboarding_step', array_merge([
            'step' => $step,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'progress_pct' => $totalSteps > 0 ? round(($stepNumber / $totalSteps) * 100, 1) : 0.0,
        ], $extra));
    }

    /**
     * Track a first value / aha moment event.
     *
     * @param  string  $milestone  Milestone name
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function firstValue(string $milestone = 'default', array $extra = []): void
    {
        self::manager()->track('first_value', array_merge([
            'milestone' => $milestone,
        ], $extra));
    }

    /**
     * Track a revenue event with currency.
     *
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $type  new, expansion, contraction, churn
     * @param  array<string, mixed>  $extra  Additional params
     */
    public static function revenue(float $amount, string $currency = 'USD', string $type = 'new', array $extra = []): void
    {
        self::manager()->track('revenue', array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'revenue_type' => $type,
        ], $extra));
    }

    /**
     * Track a custom SaaS event with explicit name.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     */
    public static function custom(string $name, array $params = []): void
    {
        self::manager()->track($name, $params);
    }

    /**
     * Resolve the AnalyticsManager from the container.
     */
    private static function manager(): AnalyticsManager
    {
        return app(AnalyticsManager::class);
    }
}
