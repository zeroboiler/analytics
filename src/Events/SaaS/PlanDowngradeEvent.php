<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a plan downgrade.
 *
 * GA4: plan_downgrade (custom)
 * Meta: (custom event)
 *
 * @since 1.0.0
 */
final readonly class PlanDowngradeEvent extends AnalyticsEvent
{
    /**
     * @param  string  $fromPlan  Current plan name
     * @param  string  $toPlan  New (lower) plan name
     */
    public function __construct(string $fromPlan, string $toPlan): void
    {
        parent::__construct('plan_downgrade', [
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
        ]);
    }
}
