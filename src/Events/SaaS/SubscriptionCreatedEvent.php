<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a new subscription creation.
 *
 * Distinct from SubscriptionEvent (which is a general subscription event).
 * SubscriptionCreatedEvent fires specifically when a new subscription is
 * created, providing initial billing cycle and term details.
 *
 * GA4: subscription_created
 * Meta: Subscribe
 * PostHog: subscription_created
 *
 * @since 1.0.0
 */
final readonly class SubscriptionCreatedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $plan  Plan name (e.g. 'pro', 'enterprise')
     * @param  float|null  $value  Subscription value
     * @param  string|null  $currency  Currency code (e.g. 'USD')
     * @param  string|null  $billingCycle  Billing cycle (e.g. 'monthly', 'yearly')
     * @param  string|null  $source  Acquisition source (e.g. 'trial_conversion', 'direct')
     */
    public function __construct(
        string $plan,
        ?float $value = null,
        ?string $currency = null,
        ?string $billingCycle = null,
        ?string $source = null,
    ){
        parent::__construct('subscription_created', array_filter([
            'plan' => $plan,
            'value' => $value,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
            'source' => $source,
        ]));
    }
}
