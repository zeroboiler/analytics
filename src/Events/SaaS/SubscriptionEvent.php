<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a subscription creation or renewal.
 *
 * GA4: purchase (mapped to subscription)
 * Meta: Subscribe (custom)
 */
final readonly class SubscriptionEvent extends AnalyticsEvent
{
    /**
     * @param  string  $planName  Subscription plan name
     * @param  float  $value  Revenue / subscription value
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $billingCycle  'monthly', 'yearly', 'lifetime'
     * @param  string|null  $transactionId  Payment transaction ID
     * @param  bool|null  $isRenewal  Whether this is a renewal
     */
    public function __construct(
        string $planName,
        float $value,
        string $currency = 'USD',
        ?string $billingCycle = null,
        ?string $transactionId = null,
        ?bool $isRenewal = null,
    ) {
        parent::__construct('subscribe', array_filter([
            'plan_name' => $planName,
            'value' => $value,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
            'transaction_id' => $transactionId,
            'is_renewal' => $isRenewal,
        ], fn (mixed $v): bool => $v !== null));
    }
}
