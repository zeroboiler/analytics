<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Subscription lifecycle tracking service.
 *
 * Provides a clean API for tracking subscription events through the
 * complete SaaS subscription lifecycle:
 *
 * - Trial started / ended / converted
 * - Subscription created / renewed / paused / resumed
 * - Plan upgrade / downgrade
 * - Subscription cancelled
 * - Payment succeeded / failed
 * - Billing retry
 *
 * All methods produce typed AnalyticsEvent objects that flow through
 * the standard dispatch pipeline (queue, consent, sampling, etc.).
 *
 * Configuration: `zeroboiler.analytics.revenue`, `zeroboiler.analytics.ecommerce`
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class SubscriptionLifecycleService
{
    /**
     * Create a new subscription lifecycle service.
     *
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {}

    /**
     * Track a trial started event.
     *
     * @param  string  $userId  User starting the trial
     * @param  string  $plan  Plan name (e.g. 'pro', 'enterprise')
     * @param  int  $trialDays  Trial duration in days
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function trialStarted(
        string $userId,
        string $plan,
        int $trialDays = 14,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'trial_started',
            params: array_merge([
                'user_id' => $userId,
                'plan' => $plan,
                'trial_days' => $trialDays,
                'currency' => $this->currency(),
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a trial converted (to paid) event.
     *
     * @param  string  $userId  User who converted
     * @param  string  $plan  Plan they converted to
     * @param  float|null  $amount  Subscription amount
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function trialConverted(
        string $userId,
        string $plan,
        ?float $amount = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'trial_converted',
            params: array_merge([
                'user_id' => $userId,
                'plan' => $plan,
                'amount' => $amount,
                'currency' => $this->currency(),
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a trial expired (not converted) event.
     *
     * @param  string  $userId  User whose trial expired
     * @param  string  $plan  Original trial plan
     * @param  int  $trialDays  Trial duration
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function trialExpired(
        string $userId,
        string $plan,
        int $trialDays = 14,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'trial_expired',
            params: array_merge([
                'user_id' => $userId,
                'plan' => $plan,
                'trial_days' => $trialDays,
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a subscription created event.
     *
     * @param  string  $userId  Subscribing user
     * @param  string  $subscriptionId  Subscription identifier
     * @param  string  $plan  Plan name
     * @param  float  $amount  Subscription amount
     * @param  string|null  $billingCycle  Billing cycle (monthly, yearly)
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function subscriptionCreated(
        string $userId,
        string $subscriptionId,
        string $plan,
        float $amount,
        ?string $billingCycle = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'subscription_created',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'plan' => $plan,
                'amount' => $amount,
                'currency' => $this->currency(),
                'billing_cycle' => $billingCycle ?? $this->defaultBillingCycle(),
                'revenue_type' => 'new',
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a subscription renewed event.
     *
     * @param  string  $userId  User ID
     * @param  string  $subscriptionId  Subscription ID
     * @param  string  $plan  Current plan
     * @param  float  $amount  Renewal amount
     * @param  int  $renewalCount  Number of previous renewals
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function subscriptionRenewed(
        string $userId,
        string $subscriptionId,
        string $plan,
        float $amount,
        int $renewalCount = 1,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'subscription_renewed',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'plan' => $plan,
                'amount' => $amount,
                'currency' => $this->currency(),
                'renewal_count' => $renewalCount,
                'revenue_type' => 'renewal',
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a plan upgrade event.
     *
     * @param  string  $userId  User upgrading
     * @param  string  $fromPlan  Previous plan
     * @param  string  $toPlan  New plan
     * @param  float|null  $previousAmount  Previous plan amount
     * @param  float|null  $newAmount  New plan amount
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function planUpgraded(
        string $userId,
        string $fromPlan,
        string $toPlan,
        ?float $previousAmount = null,
        ?float $newAmount = null,
        array $extra = [],
    ): AnalyticsEvent {
        $expansionAmount = ($newAmount !== null && $previousAmount !== null)
            ? round($newAmount - $previousAmount, 2)
            : null;

        return new AnalyticsEvent(
            name: 'plan_upgraded',
            params: array_merge([
                'user_id' => $userId,
                'from_plan' => $fromPlan,
                'to_plan' => $toPlan,
                'previous_amount' => $previousAmount,
                'new_amount' => $newAmount,
                'currency' => $this->currency(),
                'expansion_amount' => $expansionAmount,
                'revenue_type' => 'expansion',
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a plan downgrade event.
     *
     * @param  string  $userId  User downgrading
     * @param  string  $fromPlan  Previous plan
     * @param  string  $toPlan  New plan
     * @param  float|null  $previousAmount  Previous plan amount
     * @param  float|null  $newAmount  New plan amount
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function planDowngraded(
        string $userId,
        string $fromPlan,
        string $toPlan,
        ?float $previousAmount = null,
        ?float $newAmount = null,
        array $extra = [],
    ): AnalyticsEvent {
        $contractionAmount = ($previousAmount !== null && $newAmount !== null)
            ? round($previousAmount - $newAmount, 2)
            : null;

        return new AnalyticsEvent(
            name: 'plan_downgraded',
            params: array_merge([
                'user_id' => $userId,
                'from_plan' => $fromPlan,
                'to_plan' => $toPlan,
                'previous_amount' => $previousAmount,
                'new_amount' => $newAmount,
                'currency' => $this->currency(),
                'contraction_amount' => $contractionAmount,
                'revenue_type' => 'contraction',
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a subscription cancelled event.
     *
     * @param  string  $userId  User cancelling
     * @param  string  $subscriptionId  Subscription ID
     * @param  string  $plan  Current plan at cancellation
     * @param  float|null  $lostMrr  Monthly revenue lost
     * @param  string|null  $reason  Cancellation reason
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function subscriptionCancelled(
        string $userId,
        string $subscriptionId,
        string $plan,
        ?float $lostMrr = null,
        ?string $reason = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'subscription_cancelled',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'plan' => $plan,
                'lost_mrr' => $lostMrr,
                'currency' => $this->currency(),
                'cancellation_reason' => $reason,
                'revenue_type' => 'churn',
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a subscription paused event.
     *
     * @param  string  $userId  User pausing
     * @param  string  $subscriptionId  Subscription ID
     * @param  string  $plan  Current plan
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function subscriptionPaused(
        string $userId,
        string $subscriptionId,
        string $plan,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'subscription_paused',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'plan' => $plan,
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a subscription resumed event.
     *
     * @param  string  $userId  User resuming
     * @param  string  $subscriptionId  Subscription ID
     * @param  string  $plan  Current plan
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function subscriptionResumed(
        string $userId,
        string $subscriptionId,
        string $plan,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'subscription_resumed',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'plan' => $plan,
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a payment succeeded event.
     *
     * @param  string  $userId  Paying user
     * @param  string  $subscriptionId  Subscription ID
     * @param  float  $amount  Payment amount
     * @param  string|null  $paymentMethod  Payment method (stripe, paypal, etc.)
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function paymentSucceeded(
        string $userId,
        string $subscriptionId,
        float $amount,
        ?string $paymentMethod = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'payment_succeeded',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'amount' => $amount,
                'currency' => $this->currency(),
                'payment_method' => $paymentMethod,
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a payment failed event.
     *
     * @param  string  $userId  User with failed payment
     * @param  string  $subscriptionId  Subscription ID
     * @param  float  $attemptedAmount  Amount that was attempted
     * @param  int  $attemptNumber  Payment attempt number (for retries)
     * @param  string|null  $failureReason  Reason for failure
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function paymentFailed(
        string $userId,
        string $subscriptionId,
        float $attemptedAmount,
        int $attemptNumber = 1,
        ?string $failureReason = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'payment_failed',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'attempted_amount' => $attemptedAmount,
                'currency' => $this->currency(),
                'attempt_number' => $attemptNumber,
                'failure_reason' => $failureReason,
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Track a billing retry event.
     *
     * @param  string  $userId  User with retry
     * @param  string  $subscriptionId  Subscription ID
     * @param  int  $retryCount  Total retry attempts
     * @param  float|null  $outstandingAmount  Outstanding amount
     * @param  array<string, mixed>  $extra  Additional parameters
     * @return AnalyticsEvent
     */
    public function billingRetry(
        string $userId,
        string $subscriptionId,
        int $retryCount,
        ?float $outstandingAmount = null,
        array $extra = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'billing_retry',
            params: array_merge([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'retry_count' => $retryCount,
                'outstanding_amount' => $outstandingAmount,
                'currency' => $this->currency(),
            ], $extra),
            userId: $userId,
        );
    }

    /**
     * Get the configured currency code.
     *
     * @return string
     */
    private function currency(): string
    {
        return (string) ($this->config->get('zeroboiler.analytics.revenue.currency', 'USD')
            ?? $this->config->get('zeroboiler.analytics.ecommerce.currency', 'USD'));
    }

    /**
     * Get the default billing cycle.
     *
     * @return string
     */
    private function defaultBillingCycle(): string
    {
        return (string) $this->config->get('zeroboiler.analytics.revenue.billing_cycle_default', 'monthly');
    }
}
