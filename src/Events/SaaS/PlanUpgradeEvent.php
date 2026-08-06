<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a plan upgrade.
 *
 * GA4: plan_upgrade (custom)
 * Meta: (custom event)
 */
final readonly class PlanUpgradeEvent extends AnalyticsEvent
{
    /**
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float|null  $priceDifference  Additional cost
     */
    public function __construct(string $fromPlan, string $toPlan, ?float $priceDifference = null)
    {
        parent::__construct('plan_upgrade', array_filter([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'price_difference' => $priceDifference,
        ]));
    }
}
