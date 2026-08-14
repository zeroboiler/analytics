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
     * Track a user logout event.
     *
     * @param  string|null  $method  Logout method (manual, session_expired, forced)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function logout(?string $method = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'method' => $method,
        ]));

        $this->manager->track('logout', $params);
    }

    /**
     * Track a trial conversion event (trial → paid).
     *
     * @param  string|null  $plan  Converted plan name
     * @param  float|null  $value  Subscription value
     * @param  string|null  $currency  Currency code
     * @param  int|null  $trialDays  Number of trial days before conversion
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function trialConverted(
        ?string $plan = null,
        ?float $value = null,
        ?string $currency = null,
        ?int $trialDays = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'value' => $value,
            'currency' => $currency,
            'trial_days' => $trialDays,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('trial_converted', $params);
    }

    /**
     * Track a trial expired event (trial ended without conversion).
     *
     * @param  string|null  $plan  Expired trial plan name
     * @param  int|null  $trialDays  Number of trial days
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function trialExpired(?string $plan = null, ?int $trialDays = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'trial_days' => $trialDays,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('trial_expired', $params);
    }

    /**
     * Track a subscription paused event.
     *
     * @param  string|null  $plan  Paused plan name
     * @param  string|null  $reason  Pause reason (financial, seasonal, etc.)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function subscriptionPaused(?string $plan = null, ?string $reason = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('subscription_paused', $params);
    }

    /**
     * Track a subscription resumed event.
     *
     * @param  string|null  $plan  Resumed plan name
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function subscriptionResumed(?string $plan = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('subscription_resumed', $params);
    }

    /**
     * Track an invoice generated event.
     *
     * @param  string|null  $invoiceId  Invoice identifier
     * @param  float|null  $amount  Invoice amount
     * @param  string|null  $currency  Currency code
     * @param  string|null  $billingCycle  Billing cycle (monthly, yearly)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function invoiceGenerated(
        ?string $invoiceId = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $billingCycle = null,
        array $extra = [],
    ): void {
        $params = array_merge($extra, array_filter([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('invoice_generated', $params);
    }

    /**
     * Track a profile updated event.
     *
     * @param  list<string>|null  $fields  List of updated fields (e.g. ['name', 'email'])
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function profileUpdated(?array $fields = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'updated_fields' => $fields,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('profile_updated', $params);
    }

    /**
     * Track a password changed event.
     *
     * @param  string|null  $method  Change method (manual, reset, admin)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function passwordChanged(?string $method = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('password_changed', $params);
    }

    /**
     * Track a role changed event.
     *
     * @param  string|null  $fromRole  Previous role
     * @param  string|null  $toRole  New role
     * @param  string|null  $changedBy  Who changed the role (self, admin)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function roleChanged(?string $fromRole = null, ?string $toRole = null, ?string $changedBy = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'from_role' => $fromRole,
            'to_role' => $toRole,
            'changed_by' => $changedBy,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('role_changed', $params);
    }

    /**
     * Track an integration connected event (e.g. Slack, Stripe, GitHub).
     *
     * @param  string|null  $provider  Integration provider name
     * @param  string|null  $type  Integration type (oauth, api_key, webhook)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function integrationConnected(?string $provider = null, ?string $type = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'provider' => $provider,
            'type' => $type,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('integration_connected', $params);
    }

    /**
     * Track an integration failed event.
     *
     * @param  string|null  $provider  Integration provider name
     * @param  string|null  $reason  Failure reason (auth_failed, timeout, invalid_config)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function integrationFailed(?string $provider = null, ?string $reason = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'provider' => $provider,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('integration_failed', $params);
    }

    /**
     * Track a data erasure completed event (GDPR right to erasure).
     *
     * @param  string|null  $requestId  GDPR request identifier
     * @param  string|null  $scope  Erasure scope (full, partial, specific_data)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function dataErasureCompleted(?string $requestId = null, ?string $scope = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'request_id' => $requestId,
            'scope' => $scope,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('data_erasure_completed', $params);
    }

    /**
     * Track an email verified event.
     *
     * @param  string|null  $method  Verification method (link, otp, admin)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function emailVerified(?string $method = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('email_verified', $params);
    }

    /**
     * Track a team member joined event.
     *
     * @param  string|null  $teamId  Team identifier
     * @param  string|null  $role  Member role
     * @param  string|null  $invitedBy  Who invited the member
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function teamMemberJoined(?string $teamId = null, ?string $role = null, ?string $invitedBy = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'team_id' => $teamId,
            'role' => $role,
            'invited_by' => $invitedBy,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('team_member_joined', $params);
    }

    /**
     * Track a team member removed event.
     *
     * @param  string|null  $teamId  Team identifier
     * @param  string|null  $reason  Removal reason (left, removed, deactivated)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function teamMemberRemoved(?string $teamId = null, ?string $reason = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'team_id' => $teamId,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('team_member_removed', $params);
    }

    /**
     * Track a subscription renewal event.
     *
     * @param  string|null  $plan  Renewed plan name
     * @param  float|null  $value  Renewal value
     * @param  int|null  $cycleCount  Renewal cycle number (1 = first renewal)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function subscriptionRenewal(?string $plan = null, ?float $value = null, ?int $cycleCount = null, array $extra = []): void
    {
        $params = array_merge($extra, array_filter([
            'plan' => $plan,
            'value' => $value,
            'cycle_count' => $cycleCount,
        ], fn (mixed $v): bool => $v !== null));

        $this->manager->track('subscription_renewal', $params);
    }

    /**
     * Get the underlying AnalyticsManager instance.
     */
    public function manager(): AnalyticsManager
    {
        return $this->manager;
    }
}
