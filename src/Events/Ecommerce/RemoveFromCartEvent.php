<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

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
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  float|null  $price  Item price
     * @param  int  $quantity  Number of items removed
     * @param  string|null  $itemCategory  Category of the item
     */
    public function __construct(
        string $itemId,
        string $itemName,
        string $currency = 'USD',
        ?float $price = null,
        int $quantity = 1,
        ?string $itemCategory = null,
    ) {
        parent::__construct('remove_from_cart', array_filter([
            'currency' => $currency,
            'value' => ($price ?? 0) * $quantity,
            'items' => [array_filter([
                'item_id' => $itemId,
                'item_name' => $itemName,
                'price' => $price,
                'quantity' => $quantity,
                'item_category' => $itemCategory,
            ])],
        ]));
    }
}
