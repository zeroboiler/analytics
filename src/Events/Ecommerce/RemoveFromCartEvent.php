<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user removes an item from their shopping cart.
 *
 * GA4: remove_from_cart
 * Meta: (no standard equivalent — sent as custom event)
 */
final readonly class RemoveFromCartEvent extends AnalyticsEvent
{
    /**
     * @param  string  $itemId  Product/item ID
     * @param  string  $itemName  Product/item name
     * @param  float  $price  Item price
     * @param  int  $quantity  Number of items removed
     * @param  string  $currency  Currency code (ISO 4217)
     */
    public function __construct(
        string $itemId,
        string $itemName,
        float $price,
        int $quantity = 1,
        string $currency = 'USD',
    ) {
        parent::__construct('remove_from_cart', [
            'currency' => $currency,
            'value' => $price * $quantity,
            'items' => [[
                'item_id' => $itemId,
                'item_name' => $itemName,
                'price' => $price,
                'quantity' => $quantity,
            ]],
        ]);
    }
}
