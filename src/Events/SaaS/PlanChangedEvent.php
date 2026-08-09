<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a plan change event (general-purpose).
 *
 * Unlike PlanUpgradeEvent and PlanDowngradeEvent which are directional,
 * PlanChangedEvent captures any plan transition with full context.
 * Useful for tracking A/B test plan migrations, admin-initiated changes,
 * or grandfathered plan transitions that don't fit strict upgrade/downgrade.
 *
 * GA4: plan_changed
 * Meta: CustomEvent
 * PostHog: plan_changed
 *
 * @since 1.0.0
 */
final readonly class PlanChangedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $fromPlan  Previous plan name (e.g. 'starter')
     * @param  string  $toPlan  New plan name (e.g. 'pro')
     * @param  string|null  $direction  Direction of change: 'upgrade', 'downgrade', 'lateral', 'admin'
     * @param  string|null  $reason  Reason for change (e.g. 'auto_upgrade', 'user_initiated', 'support', 'trial_conversion')
     * @param  float|null  $priceDifference  Monthly price difference (positive = increase, negative = decrease)
     * @param  string|null  $currency  Currency code
     */
    public function __construct(
        string $fromPlan,
        string $toPlan,
        ?string $direction = null,
        ?string $reason = null,
        ?float $priceDifference = null,
        ?string $currency = null,
    ): void {
        parent::__construct('plan_changed', array_filter([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'direction' => $direction,
            'reason' => $reason,
            'price_difference' => $priceDifference,
            'currency' => $currency,
        ]));
    }
}
