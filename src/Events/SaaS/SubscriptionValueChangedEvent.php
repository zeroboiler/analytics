<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a subscription's monetary value changes.
 *
 * Tracks MRR/ARR impact for revenue analytics, cohort-based revenue
 * reporting, and plan-level financial dashboards. Captures the delta
 * (positive for upgrades, negative for downgrades) and the reason.
 *
 * @phpstan-import-type EventParams from AnalyticsEvent
 */
final readonly class SubscriptionValueChangedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $plan  The plan name after the change (e.g. 'Pro', 'Enterprise')
     * @param  float  $previousValue  The previous subscription value (e.g. 49.0)
     * @param  float  $newValue  The new subscription value (e.g. 99.0)
     * @param  string|null  $currency  ISO 4217 currency code (default: USD)
     * @param  string|null  $billingCycle  Billing period (monthly, yearly)
     * @param  string|null  $reason  Why the value changed (upgrade, downgrade, add_on, removal, discount, promotional)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function __construct(
        string $plan,
        float $previousValue,
        float $newValue,
        ?string $currency = null,
        ?string $billingCycle = null,
        ?string $reason = null,
        ?string $userId = null,
        ?string $clientId = null,
    ): void {
        $delta = round($newValue - $previousValue, 2);

        parent::__construct(
            name: 'subscription_value_changed',
            params: array_filter([
                'plan' => $plan,
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'delta' => $delta,
                'currency' => $currency ?? 'USD',
                'billing_cycle' => $billingCycle,
                'reason' => $reason,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
