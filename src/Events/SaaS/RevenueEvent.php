<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a revenue event for SaaS billing/metrics.
 *
 * GA4: revenue_tracked
 * Meta: Purchase (mapped)
 *
 * Use this to track MRR, ARR, one-time revenue, or any monetary event.
 *
 * @since 1.0.0
 */
final readonly class RevenueEvent extends AnalyticsEvent
{
    /**
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string  $revenueType  Type of revenue (e.g. 'mrr', 'arr', 'one_time', 'addon')
     * @param  string|null  $planName  Plan name that generated the revenue
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        float $amount,
        string $currency = 'USD',
        string $revenueType = 'one_time',
        ?string $planName = null,
        array $extra = [],
    ){
        $baseParams = array_filter([
            'value' => $amount,
            'currency' => $currency,
            'revenue_type' => $revenueType,
            'plan_name' => $planName,
        ]);

        parent::__construct('revenue_tracked', array_merge($baseParams, $extra));
    }
}
