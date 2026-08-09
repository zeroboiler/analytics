<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user selects an item from a list.
 *
 * Typically fired before view_item or add_to_cart.
 * Part of the GA4 e-commerce product funnel.
 *
 * GA4: select_item
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class SelectItemEvent extends AnalyticsEvent
{
    /**
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int, item_category?: string, item_variant?: string, item_brand?: string}>  $items  Selected items
     * @param  string|null  $itemListId  Item list identifier (e.g. 'related_products')
     * @param  string|null  $itemListName  Item list name (e.g. 'Related Products')
     * @param  string  $currency  Currency code (ISO 4217)
     */
    public function __construct(
        array $items = [],
        ?string $itemListId = null,
        ?string $itemListName = null,
        string $currency = 'USD',
    ): void {
        parent::__construct('select_item', array_filter([
            'item_list_id' => $itemListId,
            'item_list_name' => $itemListName,
            'currency' => $currency,
            'items' => $items,
        ]));
    }
}
