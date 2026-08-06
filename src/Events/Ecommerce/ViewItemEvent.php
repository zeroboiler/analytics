<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user views a product/item detail page.
 *
 * GA4: view_item
 * Meta: ViewContent
 */
final readonly class ViewItemEvent extends AnalyticsEvent
{
    /**
     * @param  string  $itemId  Product/item ID
     * @param  string  $itemName  Product/item name
     * @param  float|null  $price  Item price
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $itemCategory  Category of the item
     * @param  string|null  $itemVariant  Variant of the item
     * @param  string|null  $itemBrand  Brand of the item
     */
    public function __construct(
        string $itemId,
        string $itemName,
        ?float $price = null,
        string $currency = 'USD',
        ?string $itemCategory = null,
        ?string $itemVariant = null,
        ?string $itemBrand = null,
    ) {
        parent::__construct('view_item', array_filter([
            'currency' => $currency,
            'value' => $price,
            'items' => [array_filter([
                'item_id' => $itemId,
                'item_name' => $itemName,
                'price' => $price,
                'item_category' => $itemCategory,
                'item_variant' => $itemVariant,
                'item_brand' => $itemBrand,
            ])],
        ]));
    }
}
