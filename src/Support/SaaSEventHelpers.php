<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Server-side convenience helpers for SaaS lifecycle events.
 *
 * Provides one-line methods for tracking the most common SaaS events
 * with properly structured parameters, auto-attaching user identity
 * and contextual metadata. Complements the AnalyticsManager convenience
 * methods with SaaS-specific parameter builders.
 *
 * All methods delegate to AnalyticsManager::trackEvent() and respect
 * consent state, debug mode, DataBus routing, and interceptors.
 *
 * @since 97.0.0
 */
final class SaaSEventHelpers
{
    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
    ): void {}

    /**
     * Track a user sign-up event with method and contextual params.
     *
     * @param  string|null  $method  Signup method (email, google, github, etc.)
     * @param  array<string, mixed>  $extra  Additional parameters (referral, campaign, etc.)
     */
    public function signUp(?string $method = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'method' => $method,
        ]));

        $this->manager->track('sign_up', $params);
    }

    /**
     * Track a user login event with method and auto-identify.
     *
     * Fires a login event and automatically calls identify() to link
     * the user ID to the client tracking ID.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID (optional)
     * @param  string|null  $method  Login method (email, oauth, sso, etc.)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function login(string $userId, ?string $clientId = null, ?string $method = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'method' => $method,
            'user_id' => $userId,
        ]));

        $this->manager->track('login', $params);

        // Auto-identify on login
        $this->manager->identify($userId, $clientId, array_filter([
            'method' => $method,
        ]));
    }

    /**
     * Track a trial start event with plan and duration info.
     *
     * @param  string|null  $plan  Trial plan name (e.g. 'pro', 'business')
     * @param  int|null  $durationDays  Trial duration in days (e.g. 14)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function trialStart(?string $plan = null, ?int $durationDays = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'duration_days' => $durationDays,
        ]));

        $this->manager->track('start_trial', $params);
    }

    /**
     * Track a subscription creation event with full billing context.
     *
     * @param  string|null  $plan  Subscription plan name
     * @param  float|null  $value  Subscription value
     * @param  string|null  $currency  Currency code (e.g. 'USD')
     * @param  string|null  $billingCycle  Billing cycle (monthly, yearly)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function subscription(
        ?string $plan = null,
        ?float $value = null,
        ?string $currency = null,
        ?string $billingCycle = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'value' => $value,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('subscribe', $params);
    }

    /**
     * Track a plan upgrade event with plan transition details.
     *
     * @param  string|null  $fromPlan  Previous plan name
     * @param  string|null  $toPlan  New plan name
     * @param  float|null  $valueDifference  Price difference (can be negative)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function planUpgrade(
        ?string $fromPlan = null,
        ?string $toPlan = null,
        ?float $valueDifference = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value_difference' => $valueDifference,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('plan_upgrade', $params);
    }

    /**
     * Track a plan downgrade event with plan transition details.
     *
     * @param  string|null  $fromPlan  Previous plan name
     * @param  string|null  $toPlan  New plan name
     * @param  float|null  $valueDifference  Price difference (typically negative)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function planDowngrade(
        ?string $fromPlan = null,
        ?string $toPlan = null,
        ?float $valueDifference = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value_difference' => $valueDifference,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('plan_downgrade', $params);
    }

    /**
     * Track a subscription cancellation event with reason and context.
     *
     * @param  string|null  $reason  Cancellation reason (price, competitor, unused, etc.)
     * @param  string|null  $plan  Cancelled plan name
     * @param  float|null  $lostRevenue  Monthly revenue lost from this cancellation
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function cancellation(
        ?string $reason = null,
        ?string $plan = null,
        ?float $lostRevenue = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'reason' => $reason,
            'plan' => $plan,
            'lost_revenue' => $lostRevenue,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('cancellation', $params);
    }

    /**
     * Track a feature usage event with feature name and context.
     *
     * @param  string  $feature  Feature name (e.g. 'export', 'api_access')
     * @param  array<string, mixed>  $extra  Additional parameters (category, count, etc.)
     */
    public function featureUsed(string $feature, array $extra = []): void
    {
        $params = array_merge($extra, [
            'feature' => $feature,
        ]);

        $this->manager->track('feature_used', $params);
    }

    /**
     * Track a team creation event.
     *
     * @param  string|null  $teamName  Team name
     * @param  int|null  $memberCount  Initial member count
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function teamCreated(?string $teamName = null, ?int $memberCount = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'team_name' => $teamName,
            'member_count' => $memberCount,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('team_created', $params);
    }

    /**
     * Track an invite sent event.
     *
     * @param  string|null  $role  Invited role (admin, member, viewer)
     * @param  string|null  $channel  Invitation channel (email, link)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function inviteSent(?string $role = null, ?string $channel = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'role' => $role,
            'channel' => $channel,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('invite_sent', $params);
    }

    /**
     * Track a payment failed event with context.
     *
     * @param  string|null  $reason  Failure reason (card_declined, insufficient_funds, etc.)
     * @param  float|null  $amount  Attempted payment amount
     * @param  string|null  $currency  Currency code
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function paymentFailed(?string $reason = null, ?float $amount = null, ?string $currency = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'reason' => $reason,
            'amount' => $amount,
            'currency' => $currency,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('payment_failed', $params);
    }

    /**
     * Get the underlying AnalyticsManager instance.
     */
    public function manager(): AnalyticsManager
    {
        return $this->manager;
    }
}
