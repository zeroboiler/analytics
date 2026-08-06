<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a completed purchase transaction.
 *
 * GA4: purchase
 * Meta: Purchase
 */
final readonly class PurchaseEvent extends AnalyticsEvent
{
    /**
     * @param  string  $transactionId  Unique transaction ID
     * @param  float  $value  Revenue / transaction value
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int, item_category?: string}>  $items  Purchased items
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $coupon  Coupon code applied
     * @param  string|null  $affiliation  Store affiliation
     * @param  float|null  $tax  Tax amount
     * @param  float|null  $shipping  Shipping cost
     */
    public function __construct(
        string $transactionId,
        float $value,
        array $items = [],
        string $currency = 'USD',
        ?string $coupon = null,
        ?string $affiliation = null,
        ?float $tax = null,
        ?float $shipping = null,
    ) {
        parent::__construct('purchase', array_filter([
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => $currency,
            'items' => $items,
            'coupon' => $coupon,
            'affiliation' => $affiliation,
            'tax' => $tax,
            'shipping' => $shipping,
        ]));
    }
}
