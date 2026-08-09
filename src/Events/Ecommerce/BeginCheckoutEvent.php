<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user initiates the checkout process.
 *
 * GA4: begin_checkout
 * Meta: InitiateCheckout
 *
 * @since 1.0.0
 */
final readonly class BeginCheckoutEvent extends AnalyticsEvent
{
    /**
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int}>  $items  Cart items
     * @param  float  $value  Total checkout value
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  int|null  $itemCount  Total number of items in cart
     * @param  string|null  $coupon  Coupon code applied
     */
    public function __construct(
        array $items,
        float $value,
        string $currency = 'USD',
        ?int $itemCount = null,
        ?string $coupon = null,
    ): void {
        parent::__construct('begin_checkout', array_filter([
            'currency' => $currency,
            'value' => $value,
            'items' => $items,
            'item_count' => $itemCount,
            'coupon' => $coupon,
        ]));
    }
}
