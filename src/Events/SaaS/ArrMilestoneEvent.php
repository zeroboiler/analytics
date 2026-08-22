<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Annual Recurring Revenue (ARR) milestone event.
 *
 * Tracks when the company reaches a significant ARR threshold
 * (e.g., $1M, $5M, $10M ARR). Used for growth milestone tracking,
 * investor reporting, and team celebration automation.
 *
 * @since 174.0.0
 */
final readonly class ArrMilestoneEvent extends AnalyticsEvent
{
    /**
     * @param  float  $arr  The ARR amount at milestone (e.g. 1_000_000.0)
     * @param  string|null  $milestone  Milestone label (e.g. '1M_ARR', '10M_ARR')
     * @param  float|null  $previousArr  Previous ARR for growth calculation
     * @param  float|null  $arrGrowthRate  ARR growth rate percentage
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $arr,
        ?string $milestone = null,
        ?float $previousArr = null,
        ?float $arrGrowthRate = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'arr' => $arr,
            'milestone' => $milestone,
            'previous_arr' => $previousArr,
            'arr_growth_rate' => $arrGrowthRate,
        ]);

        parent::__construct(name: 'arr_milestone', params: $merged);
    }
}
