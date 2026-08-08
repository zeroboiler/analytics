<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Expansion revenue event — tracks revenue growth from existing customers.
 *
 * Fires when a customer generates additional revenue beyond their initial
 * subscription: add-on purchases, seat expansion, storage upgrades,
 * usage-based overages, or cross-sell conversions.
 *
 * Complements RevenueEvent (which tracks total revenue) by specifically
 * capturing expansion signals for net revenue retention analysis.
 */
final class ExpansionRevenueEvent extends AnalyticsEvent
{
    /**
     * @param  float  $amount  The expansion revenue amount
     * @param  string  $source  Expansion source (e.g. 'addon', 'seat_expansion', 'usage_overage', 'cross_sell')
     * @param  string|null  $currency  Currency code (default: USD)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        float $amount,
        string $source,
        ?string $currency = null,
        array $params = [],
    ): void {
        $merged = array_merge($params, [
            'amount' => $amount,
            'currency' => $currency ?? 'USD',
            'expansion_source' => $source,
        ]);

        parent::__construct(name: 'expansion_revenue', params: $merged);
    }
}
