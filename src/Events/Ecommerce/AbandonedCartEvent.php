<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Cart abandonment event — fired when a user adds items to cart but leaves without purchasing.
 *
 * Tracks the cart contents and value at the time of abandonment.
 * Use with FunnelVelocityService to measure time-to-abandon and identify
 * checkout flow bottlenecks.
 *
 * @see \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents
 *
 * @since 1.0.0
 */
final readonly class AbandonedCartEvent extends AnalyticsEvent
{
    /**
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int}>  $items  Cart items at time of abandonment
     * @param  float  $cartValue  Total cart value
     * @param  string|null  $abandonmentReason  Why the user abandoned (timeout, navigation, close_tab, etc.)
     * @param  int|null  $timeOnCart  Seconds spent on cart page before abandoning
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        array $items = [],
        float $cartValue = 0.0,
        ?string $abandonmentReason = null,
        ?int $timeOnCart = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'abandoned_cart',
            params: array_filter(array_merge([
                'items' => $items,
                'cart_value' => $cartValue,
                'cart_item_count' => count($items),
                'abandonment_reason' => $abandonmentReason,
                'time_on_cart' => $timeOnCart,
            ], $params)),
        );
    }
}
