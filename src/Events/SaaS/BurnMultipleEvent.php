<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Burn Multiple event — tracks the ratio of net burn to net new ARR.
 *
 * Burn Multiple = Net Burn / Net New ARR.
 * A burn multiple < 1.0 is excellent (efficient growth), 1-2 is good,
 * and > 3.0 suggests unsustainable burn rate relative to growth.
 * Inspired by David Sacks' burn multiple framework.
 *
 * @since 174.0.0
 */
final readonly class BurnMultipleEvent extends AnalyticsEvent
{
    /**
     * @param  float  $burnMultiple  The burn multiple ratio (e.g. 1.5)
     * @param  float  $netBurn  Net cash burn for the period (negative = burning)
     * @param  float  $netNewArr  Net new ARR added during the period
     * @param  string|null  $period  Period type (e.g. 'monthly', 'quarterly', 'annual')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $burnMultiple,
        float $netBurn,
        float $netNewArr,
        ?string $period = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'burn_multiple' => $burnMultiple,
            'net_burn' => $netBurn,
            'net_new_arr' => $netNewArr,
            'period' => $period ?? 'monthly',
        ]);

        parent::__construct(name: 'burn_multiple', params: $merged);
    }
}
