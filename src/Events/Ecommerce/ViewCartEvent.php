<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user views their shopping cart.
 *
 * GA4: view_cart
 * Meta: (no standard equivalent)
 */
final readonly class ViewCartEvent extends AnalyticsEvent
{
    /**
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int}>  $items  Cart items
     * @param  float  $value  Total cart value
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  int|null  $itemCount  Total number of items
     */
    public function __construct(
        array $items,
        float $value,
        string $currency = 'USD',
        ?int $itemCount = null,
    ) {
        parent::__construct('view_cart', array_filter([
            'currency' => $currency,
            'value' => $value,
            'items' => $items,
            'item_count' => $itemCount,
        ]));
    }
}
