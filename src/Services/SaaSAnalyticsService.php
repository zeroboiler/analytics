<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;

/**
 * High-level SaaS analytics service.
 *
 * Provides convenience methods for common SaaS lifecycle tracking scenarios
 * including user acquisition, trial, subscription, and retention events.
 */
class SaaSAnalyticsService
{
    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Track a user sign-up.
     *
     * @param  string  $method  Signup method (e.g. 'email', 'google', 'github')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackSignUp(string $method = 'email', array $params = []): void
    {
        $this->manager->trackEvent(new SignUpEvent(method: $method));
    }

    /**
     * Track a user login.
     *
     * @param  string  $method  Auth guard (e.g. 'web', 'sanctum')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackLogin(string $method = 'web', array $params = []): void
    {
        $this->manager->trackEvent(new LoginEvent(method: $method));
    }

    /**
     * Track a trial start.
     *
     * @param  string  $plan  Plan name (e.g. 'pro', 'business')
     * @param  int  $trialDays  Number of trial days
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackTrialStart(string $plan, int $trialDays = 14, array $params = []): void
    {
        $this->manager->trackEvent(new TrialStartEvent(
            plan: $plan,
            trialDays: $trialDays,
        ));
    }

    /**
     * Track a subscription event.
     *
     * @param  string  $plan  Plan name
     * @param  float  $value  Subscription value
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackSubscription(
        string $plan,
        float $value,
        string $currency = 'USD',
        array $params = [],
    ): void {
        $this->manager->trackEvent(new SubscriptionEvent(
            plan: $plan,
            value: $value,
            currency: $currency,
        ));
    }

    /**
     * Track a plan upgrade.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackPlanUpgrade(string $fromPlan, string $toPlan, array $params = []): void
    {
        $this->manager->trackEvent(new PlanUpgradeEvent(
            fromPlan: $fromPlan,
            toPlan: $toPlan,
        ));
    }

    /**
     * Track a cancellation.
     *
     * @param  string  $plan  Cancelled plan name
     * @param  string  $reason  Cancellation reason
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackCancellation(string $plan, string $reason = '', array $params = []): void
    {
        $this->manager->trackEvent(new CancellationEvent(
            plan: $plan,
            reason: $reason,
        ));
    }

    /**
     * Track a feature usage event.
     *
     * @param  string  $feature  Feature identifier
     * @param  int  $usageCount  How many times the feature was used
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackFeatureUsed(string $feature, int $usageCount = 1, array $params = []): void
    {
        $this->manager->trackEvent(new FeatureUsedEvent(
            feature: $feature,
            usageCount: $usageCount,
        ));
    }

    /**
     * Track a custom SaaS event with a generic name.
     *
     * @param  array<string, mixed>  $params  Event parameters
     */
    public function trackCustomEvent(string $name, array $params = []): void
    {
        $this->manager->trackEvent(new AnalyticsEvent(name: $name, params: $params));
    }

    /**
     * Get the underlying analytics manager.
     */
    public function getManager(): AnalyticsManager
    {
        return $this->manager;
    }
}
