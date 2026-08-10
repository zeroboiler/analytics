<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * SaaS QuickStart — one-call setup for standard SaaS event tracking.
 *
 * Provides a single entry point for bootstrapping the most common
 * SaaS analytics events. Automatically configures tracking for
 * authentication, subscription lifecycle, trial, and engagement events
 * with sensible defaults.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class SaaSQuickStartService
{
    /**
     * @param  AnalyticsManager  $manager  The analytics manager instance
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
    ): void {}

    /**
     * Track a user sign-up event.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $params  Additional parameters (method, referral, etc.)
     */
    public function trackSignUp(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('sign_up', array_merge([
            'user_id' => $userId,
        ], $params));
    }

    /**
     * Track a user login event.
     *
     * @param  string  $userId  User identifier
     * @param  string|null  $method  Authentication method (email, oauth, sso, etc.)
     */
    public function trackLogin(string $userId, ?string $method = null): void
    {
        $params = ['user_id' => $userId];
        if ($method !== null) {
            $params['method'] = $method;
        }

        $this->manager->trackEvent('login', $params);
    }

    /**
     * Track trial start event.
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Trial plan name
     * @param  int|null  $trialDays  Trial duration in days
     */
    public function trackTrialStart(string $userId, string $plan = 'free', ?int $trialDays = null): void
    {
        $params = [
            'user_id' => $userId,
            'plan' => $plan,
        ];
        if ($trialDays !== null) {
            $params['trial_days'] = $trialDays;
        }

        $this->manager->trackEvent('start_trial', $params);
    }

    /**
     * Track trial conversion (trial → paid).
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Converted-to plan name
     * @param  float|null  $revenue  Monthly revenue amount
     */
    public function trackTrialConversion(string $userId, string $plan, ?float $revenue = null): void
    {
        $params = [
            'user_id' => $userId,
            'plan' => $plan,
        ];
        if ($revenue !== null) {
            $params['revenue'] = $revenue;
        }

        $this->manager->trackEvent('trial_converted', $params);
    }

    /**
     * Track subscription created event.
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Plan name
     * @param  float|null  $revenue  Monthly revenue
     * @param  string|null  $billingCycle  monthly, yearly, etc.
     */
    public function trackSubscription(string $userId, string $plan, ?float $revenue = null, ?string $billingCycle = null): void
    {
        $params = [
            'user_id' => $userId,
            'plan' => $plan,
        ];
        if ($revenue !== null) {
            $params['revenue'] = $revenue;
        }
        if ($billingCycle !== null) {
            $params['billing_cycle'] = $billingCycle;
        }

        $this->manager->trackEvent('subscribe', $params);
    }

    /**
     * Track plan upgrade event.
     *
     * @param  string  $userId  User identifier
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float|null  $revenueChange  Revenue difference
     */
    public function trackPlanUpgrade(string $userId, string $fromPlan, string $toPlan, ?float $revenueChange = null): void
    {
        $params = [
            'user_id' => $userId,
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
        ];
        if ($revenueChange !== null) {
            $params['revenue_change'] = $revenueChange;
        }

        $this->manager->trackEvent('plan_upgrade', $params);
    }

    /**
     * Track cancellation event.
     *
     * @param  string  $userId  User identifier
     * @param  string|null  $reason  Cancellation reason
     * @param  string|null  $plan  Plan at time of cancellation
     */
    public function trackCancellation(string $userId, ?string $reason = null, ?string $plan = null): void
    {
        $params = ['user_id' => $userId];
        if ($reason !== null) {
            $params['reason'] = $reason;
        }
        if ($plan !== null) {
            $params['plan'] = $plan;
        }

        $this->manager->trackEvent('cancellation', $params);
    }

    /**
     * Track a purchase event (e-commerce shortcut).
     *
     * @param  string  $transactionId  Unique transaction identifier
     * @param  float  $value  Transaction value
     * @param  list<array{item_id: string, item_name?: string, price?: float, quantity?: int}>  $items  Purchased items
     */
    public function trackPurchase(string $transactionId, float $value, array $items = []): void
    {
        $this->manager->purchase($transactionId, $value, $items);
    }

    /**
     * Track feature usage event.
     *
     * @param  string  $userId  User identifier
     * @param  string  $featureName  Feature identifier
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function trackFeatureUsed(string $userId, string $featureName, array $metadata = []): void
    {
        $this->manager->trackEvent('feature_used', array_merge([
            'user_id' => $userId,
            'feature_name' => $featureName,
        ], $metadata));
    }

    /**
     * Track an error event.
     *
     * @param  string  $message  Error message
     * @param  string|null  $severity  Error severity (error, warning, critical)
     * @param  array<string, mixed>  $context  Error context
     */
    public function trackError(string $message, ?string $severity = null, array $context = []): void
    {
        $params = ['message' => $message];
        if ($severity !== null) {
            $params['severity'] = $severity;
        }

        $this->manager->trackEvent('error', array_merge($params, $context));
    }

    /**
     * Track all standard SaaS onboarding events in sequence.
     *
     * Convenience method for tracking the complete new user journey:
     * sign_up → login → start_trial (if applicable).
     *
     * @param  string  $userId  User identifier
     * @param  array{method?: string, referral?: string, plan?: string, trial_days?: int}  $options  Onboarding options
     */
    public function trackOnboardingSequence(string $userId, array $options = []): void
    {
        $this->trackSignUp($userId, array_filter([
            'method' => $options['method'] ?? null,
            'referral' => $options['referral'] ?? null,
        ], fn ($v): bool => $v !== null));

        $this->trackLogin($userId, $options['method'] ?? null);

        if (isset($options['plan']) || isset($options['trial_days'])) {
            $this->trackTrialStart(
                $userId,
                $options['plan'] ?? 'free',
                $options['trial_days'] ?? null,
            );
        }
    }
}
