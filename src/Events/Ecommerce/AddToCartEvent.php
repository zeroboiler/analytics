<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user adds an item to their shopping cart.
 *
 * GA4: add_to_cart
 * Meta: AddToCart
 */
final readonly class AddToCartEvent extends AnalyticsEvent
{
    /**
     * @param  string  $itemId  Product/item ID
     * @param  string  $itemName  Product/item name
     * @param  float|null  $price  Item price
     * @param  int  $quantity  Number of items added
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $itemCategory  Category of the item
     * @param  string|null  $itemVariant  Variant of the item
     */
    public function __construct(
        string $itemId,
        string $itemName,
        ?float $price = null,
        int $quantity = 1,
        string $currency = 'USD',
        ?string $itemCategory = null,
        ?string $itemVariant = null,
    ) {
        parent::__construct('add_to_cart', array_filter([
            'currency' => $currency,
            'value' => ($price ?? 0) * $quantity,
            'items' => [[
                'item_id' => $itemId,
                'item_name' => $itemName,
                'price' => $price,
                'quantity' => $quantity,
                'item_category' => $itemCategory,
                'item_variant' => $itemVariant,
            ]],
        ]));
    }
}
