<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Contraction revenue event — tracks revenue loss from existing customers.
 *
 * Fires when a customer generates less revenue than before: plan downgrades,
 * seat removal, feature removal, or discount negotiations. Complements
 * ExpansionRevenueEvent for net revenue retention analysis.
 *
 * @since 174.0.0
 */
final readonly class ContractionRevenueEvent extends AnalyticsEvent
{
    /**
     * @param  float  $amount  The contraction revenue amount (negative value)
     * @param  string  $source  Contraction source (e.g. 'plan_downgrade', 'seat_removal', 'feature_removal', 'discount')
     * @param  string|null  $currency  Currency code (default: USD)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $amount,
        string $source,
        ?string $currency = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'amount' => $amount,
            'currency' => $currency ?? 'USD',
            'contraction_source' => $source,
        ]);

        parent::__construct(name: 'contraction_revenue', params: $merged);
    }
}
