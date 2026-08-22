<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * E-commerce wishlist event — fires when a user adds an item to their wishlist.
 *
 * Useful for tracking product interest and retargeting campaigns.
 * Maps to GA4 'add_to_wishlist' and Meta 'AddToWishlist'.
 *
 * @since 1.0.0
 */
final class WishlistEvent extends AnalyticsEvent
{
    public function __construct(
        string $itemId,
        string $itemName = '',
        string $itemCategory = '',
        ?float $price = null,
        string $currency = 'USD',
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'add_to_wishlist',
            params: array_filter([
                'item_id' => $itemId,
                'item_name' => $itemName,
                'item_category' => $itemCategory,
                'price' => $price,
                'currency' => $currency,
            ]),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
