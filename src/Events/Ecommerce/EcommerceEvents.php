<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

/**
 * Static catalog of all e-commerce analytics events.
 *
 * Provides a central registry for event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null}
 */
final class EcommerceEvents
{
    /** @var array<string, EventEntry> */
    private static array $catalog = [];

    /**
     * Build the event catalog (lazy initialization).
     *
     * @return array<string, EventEntry>
     */
    private static function catalog(): array
    {
        if (self::$catalog !== []) {
            return self::$catalog;
        }

        self::$catalog = [
            'view_item' => [
                'name' => 'view_item',
                'class' => ViewItemEvent::class,
                'ga4' => 'view_item',
                'meta' => 'ViewContent',
            ],
            'add_to_cart' => [
                'name' => 'add_to_cart',
                'class' => AddToCartEvent::class,
                'ga4' => 'add_to_cart',
                'meta' => 'AddToCart',
            ],
            'remove_from_cart' => [
                'name' => 'remove_from_cart',
                'class' => RemoveFromCartEvent::class,
                'ga4' => 'remove_from_cart',
                'meta' => 'RemoveFromCart',
            ],
            'view_cart' => [
                'name' => 'view_cart',
                'class' => ViewCartEvent::class,
                'ga4' => 'view_cart',
                'meta' => 'ViewCart',
            ],
            'begin_checkout' => [
                'name' => 'begin_checkout',
                'class' => BeginCheckoutEvent::class,
                'ga4' => 'begin_checkout',
                'meta' => 'InitiateCheckout',
            ],
            'add_payment_info' => [
                'name' => 'add_payment_info',
                'class' => AddPaymentInfoEvent::class,
                'ga4' => 'add_payment_info',
                'meta' => 'AddPaymentInfo',
            ],
            'purchase' => [
                'name' => 'purchase',
                'class' => PurchaseEvent::class,
                'ga4' => 'purchase',
                'meta' => 'Purchase',
            ],
            'refund' => [
                'name' => 'refund',
                'class' => RefundEvent::class,
                'ga4' => 'refund',
                'meta' => 'Refund',
            ],
            'add_to_wishlist' => [
                'name' => 'add_to_wishlist',
                'class' => WishlistEvent::class,
                'ga4' => 'add_to_wishlist',
                'meta' => 'AddToWishlist',
            ],
            'select_item' => [
                'name' => 'select_item',
                'class' => SelectItemEvent::class,
                'ga4' => 'select_item',
                'meta' => 'ViewItem',
            ],
            'select_promotion' => [
                'name' => 'select_promotion',
                'class' => SelectPromotionEvent::class,
                'ga4' => 'select_promotion',
                'meta' => 'ViewContent',
            ],
            'view_promotion' => [
                'name' => 'view_promotion',
                'class' => ViewPromotionEvent::class,
                'ga4' => 'view_promotion',
                'meta' => 'ViewContent',
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all e-commerce event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all e-commerce event entries.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return self::catalog();
    }

    /**
     * Get a specific event entry by name.
     *
     * @return EventEntry|null
     */
    public static function get(string $name): ?array
    {
        return self::catalog()[$name] ?? null;
    }

    /**
     * Check if an event name exists in the catalog.
     */
    public static function has(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    /**
     * Get the total number of e-commerce events.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }

    /**
     * Get all GA4 event names in this category.
     *
     * @return list<string>
     */
    public static function ga4Names(): array
    {
        return array_map(
            fn (array $entry): string => $entry['ga4'],
            self::catalog(),
        );
    }

    /**
     * Get all Meta Pixel event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function metaNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['meta'],
                self::catalog(),
            ),
            fn (?string $meta): bool => $meta !== null,
        ));
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return self::catalog()[$name]['class'] ?? null;
    }

    /**
     * Get the category name for this catalog.
     */
    public static function category(): string
    {
        return 'ecommerce';
    }
}
