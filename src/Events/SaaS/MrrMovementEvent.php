<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * MRR (Monthly Recurring Revenue) movement event.
 *
 * Tracks changes in monthly recurring revenue categorized by type:
 * new (new customers), expansion (upsells), contraction (downsells),
 * reactivation (churned customer returned), and churn (customer left).
 * Used by RevenueWaterfallService to build revenue waterfall charts.
 *
 * @since 78.0.0
 */
final readonly class MrrMovementEvent extends AnalyticsEvent
{
    /**
     * @param  string  $movementType  One of: new, expansion, contraction, reactivation, churn
     * @param  float  $amount  MRR amount for this movement (e.g. 49.00)
     * @param  string|null  $customerId  Customer/user identifier
     * @param  string|null  $planId  Plan identifier that triggered the movement
     * @param  string|null  $previousPlanId  Previous plan (for expansion/contraction)
     * @param  string|null  $currency  Currency code (ISO 4217)
     * @param  string|null  $billingCycle  Billing cycle (monthly, yearly)
     * @param  string|null  $reason  Reason for the movement
     * @param  string|null  $effectiveDate  When the movement takes effect
     */
    public function __construct(
        string $movementType,
        float $amount,
        ?string $customerId = null,
        ?string $planId = null,
        ?string $previousPlanId = null,
        ?string $currency = null,
        ?string $billingCycle = null,
        ?string $reason = null,
        ?string $effectiveDate = null,
    ){
        parent::__construct(
            'mrr_movement',
            array_filter([
                'movement_type' => $movementType,
                'amount' => $amount,
                'currency' => $currency,
                'customer_id' => $customerId,
                'plan_id' => $planId,
                'previous_plan_id' => $previousPlanId,
                'billing_cycle' => $billingCycle,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
            ], fn ($v) => $v !== null),
        );
    }
}
