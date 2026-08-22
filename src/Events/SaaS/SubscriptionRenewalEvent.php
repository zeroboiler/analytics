<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Subscription renewal analytics event.
 *
 * Tracked when a recurring subscription is automatically or manually renewed.
 * Carries the plan name, billing amount, and billing cycle for revenue analytics.
 *
 * @phpstan-import-type EventParams from AnalyticsEvent
 *
 * @since 1.0.0
 */
final readonly class SubscriptionRenewalEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $planName  Current plan name (e.g. 'pro', 'enterprise')
     * @param  float|null  $amount  Renewal amount charged
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  string|null  $billingCycle  'monthly', 'yearly', 'custom'
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function __construct(
        ?string $planName = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $billingCycle = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'subscription_renewal',
            params: array_filter(array_merge([
                'plan_name' => $planName,
                'amount' => $amount,
                'currency' => $currency ?? 'USD',
                'billing_cycle' => $billingCycle,
            ], $params)),
        );
    }
}
