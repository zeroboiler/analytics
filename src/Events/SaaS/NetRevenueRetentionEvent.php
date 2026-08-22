<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Net Revenue Retention (NRR) computed event.
 *
 * Emitted when the system computes net revenue retention for a cohort period.
 * NRR = (Starting MRR + Expansion - Contraction - Churn) / Starting MRR.
 * Values above 100% indicate net-positive revenue growth from existing customers.
 *
 * @since 174.0.0
 */
final class NetRevenueRetentionEvent extends AnalyticsEvent
{
    /**
     * @param  float  $nrr  Net revenue retention percentage (e.g. 115.0 for 115%)
     * @param  string  $period  Period type (e.g. 'monthly', 'quarterly', 'annual')
     * @param  float|null  $startingMrr  Starting MRR for the period
     * @param  float|null  $expansionMrr  Expansion MRR during the period
     * @param  float|null  $contractionMrr  Contraction MRR during the period
     * @param  float|null  $churnedMrr  Churned MRR during the period
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $nrr,
        string $period,
        ?float $startingMrr = null,
        ?float $expansionMrr = null,
        ?float $contractionMrr = null,
        ?float $churnedMrr = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'nrr_percentage' => $nrr,
            'period' => $period,
            'starting_mrr' => $startingMrr,
            'expansion_mrr' => $expansionMrr,
            'contraction_mrr' => $contractionMrr,
            'churned_mrr' => $churnedMrr,
        ]);

        parent::__construct(name: 'net_revenue_retention', params: $merged);
    }
}
