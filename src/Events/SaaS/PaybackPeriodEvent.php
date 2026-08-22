<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Customer payback period event — tracks CAC payback duration.
 *
 * Emitted when the system computes the CAC payback period for a customer
 * or cohort. Payback period = CAC / (ARPU × Gross Margin).
 * Industry benchmark: < 12 months is healthy for B2B SaaS.
 *
 * @since 174.0.0
 */
final readonly class PaybackPeriodEvent extends AnalyticsEvent
{
    /**
     * @param  float  $paybackMonths  Payback period in months (e.g. 8.5)
     * @param  float|null  $cac  Customer acquisition cost
     * @param  float|null  $arpu  Average revenue per user (monthly)
     * @param  float|null  $grossMargin  Gross margin percentage (e.g. 75.0)
     * @param  string|null  $cohort  Cohort identifier
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $paybackMonths,
        ?float $cac = null,
        ?float $arpu = null,
        ?float $grossMargin = null,
        ?string $cohort = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'payback_months' => $paybackMonths,
            'cac' => $cac,
            'arpu' => $arpu,
            'gross_margin' => $grossMargin,
            'cohort' => $cohort,
        ]);

        parent::__construct(name: 'payback_period', params: $merged);
    }
}
