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
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 1.0.0
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
                'posthog' => '$view_item',
                'plausible' => null,
                'mixpanel' => 'View Item',
                'amplitude' => 'View Item',
                'tiktok' => 'ViewContent',
                'linkedin' => 'view_product',
            ],
            'add_to_cart' => [
                'name' => 'add_to_cart',
                'class' => AddToCartEvent::class,
                'ga4' => 'add_to_cart',
                'meta' => 'AddToCart',
                'posthog' => 'add_to_cart',
                'plausible' => 'add_to_cart',
                'mixpanel' => 'Add to Cart',
                'amplitude' => 'Added to Cart',
                'tiktok' => 'AddToCart',
                'linkedin' => 'add_to_cart',
            ],
            'remove_from_cart' => [
                'name' => 'remove_from_cart',
                'class' => RemoveFromCartEvent::class,
                'ga4' => 'remove_from_cart',
                'meta' => 'RemoveFromCart',
                'posthog' => 'remove_from_cart',
                'plausible' => null,
                'mixpanel' => 'Remove from Cart',
                'amplitude' => 'Removed from Cart',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'view_cart' => [
                'name' => 'view_cart',
                'class' => ViewCartEvent::class,
                'ga4' => 'view_cart',
                'meta' => 'ViewCart',
                'posthog' => 'view_cart',
                'plausible' => null,
                'mixpanel' => 'View Cart',
                'amplitude' => 'Viewed Cart',
                'tiktok' => 'ViewContent',
                'linkedin' => null,
            ],
            'begin_checkout' => [
                'name' => 'begin_checkout',
                'class' => BeginCheckoutEvent::class,
                'ga4' => 'begin_checkout',
                'meta' => 'InitiateCheckout',
                'posthog' => 'begin_checkout',
                'plausible' => 'begin_checkout',
                'mixpanel' => 'Checkout Started',
                'amplitude' => 'Started Checkout',
                'tiktok' => 'InitiateCheckout',
                'linkedin' => 'purchase',
            ],
            'add_payment_info' => [
                'name' => 'add_payment_info',
                'class' => AddPaymentInfoEvent::class,
                'ga4' => 'add_payment_info',
                'meta' => 'AddPaymentInfo',
                'posthog' => 'add_payment_info',
                'plausible' => null,
                'mixpanel' => 'Add Payment Info',
                'amplitude' => 'Added Payment Info',
                'tiktok' => 'AddPaymentInfo',
                'linkedin' => null,
            ],
            'purchase' => [
                'name' => 'purchase',
                'class' => PurchaseEvent::class,
                'ga4' => 'purchase',
                'meta' => 'Purchase',
                'posthog' => 'purchase',
                'plausible' => 'purchase',
                'mixpanel' => 'Purchase',
                'amplitude' => 'Completed Order',
                'tiktok' => 'CompletePayment',
                'linkedin' => 'purchase',
            ],
            'refund' => [
                'name' => 'refund',
                'class' => RefundEvent::class,
                'ga4' => 'refund',
                'meta' => 'Refund',
                'posthog' => 'refund',
                'plausible' => 'refund',
                'mixpanel' => 'Refund',
                'amplitude' => 'Refunded Order',
                'tiktok' => 'ClickButton',
                'linkedin' => null,
            ],
            'add_to_wishlist' => [
                'name' => 'add_to_wishlist',
                'class' => WishlistEvent::class,
                'ga4' => 'add_to_wishlist',
                'meta' => 'AddToWishlist',
                'posthog' => 'add_to_wishlist',
                'plausible' => null,
                'mixpanel' => 'Add to Wishlist',
                'amplitude' => 'Added to Wishlist',
                'tiktok' => 'AddToWishlist',
                'linkedin' => null,
            ],
            'select_item' => [
                'name' => 'select_item',
                'class' => SelectItemEvent::class,
                'ga4' => 'select_item',
                'meta' => 'ViewItem',
                'posthog' => 'select_item',
                'plausible' => null,
                'mixpanel' => 'Select Item',
                'amplitude' => 'Selected Item',
                'tiktok' => 'ViewContent',
                'linkedin' => null,
            ],
            'select_promotion' => [
                'name' => 'select_promotion',
                'class' => SelectPromotionEvent::class,
                'ga4' => 'select_promotion',
                'meta' => 'ViewContent',
                'posthog' => 'select_promotion',
                'plausible' => null,
                'mixpanel' => 'Select Promotion',
                'amplitude' => 'Selected Promotion',
                'tiktok' => 'ClickButton',
                'linkedin' => null,
            ],
            'view_promotion' => [
                'name' => 'view_promotion',
                'class' => ViewPromotionEvent::class,
                'ga4' => 'view_promotion',
                'meta' => 'ViewContent',
                'posthog' => 'view_promotion',
                'plausible' => null,
                'mixpanel' => 'View Promotion',
                'amplitude' => 'Viewed Promotion',
                'tiktok' => 'ViewContent',
                'linkedin' => null,
            ],
            'checkout_step' => [
                'name' => 'checkout_step',
                'class' => CheckoutStepEvent::class,
                'ga4' => 'checkout_step',
                'meta' => 'CheckoutStep',
                'posthog' => 'checkout_step',
                'plausible' => null,
                'mixpanel' => 'Checkout Step',
                'amplitude' => 'Checkout Step',
                'tiktok' => 'InitiateCheckout',
                'linkedin' => null,
            ],
            // Cart & checkout abandonment (v2.82.0)
            'abandoned_cart' => [
                'name' => 'abandoned_cart',
                'class' => AbandonedCartEvent::class,
                'ga4' => 'remove_from_cart',
                'meta' => 'InitiateCheckout',
                'posthog' => 'abandoned_cart',
                'plausible' => null,
                'mixpanel' => 'Abandoned Cart',
                'amplitude' => 'Abandoned Cart',
                'tiktok' => 'ClickButton',
                'linkedin' => null,
            ],
            'checkout_abandon' => [
                'name' => 'checkout_abandon',
                'class' => CheckoutAbandonEvent::class,
                'ga4' => 'begin_checkout',
                'meta' => 'InitiateCheckout',
                'posthog' => 'checkout_abandon',
                'plausible' => null,
                'mixpanel' => 'Checkout Abandon',
                'amplitude' => 'Checkout Abandoned',
                'tiktok' => 'InitiateCheckout',
                'linkedin' => null,
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

    /**
     * Get all PostHog event names in this category.
     *
     * @return list<string>
     */
    public static function posthogNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['posthog'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Plausible event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function plausibleNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['plausible'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Mixpanel event names in this category.
     *
     * @return list<string>
     */
    public static function mixpanelNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['mixpanel'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Amplitude event names in this category.
     *
     * @return list<string>
     */
    public static function amplitudeNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['amplitude'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all TikTok event names in this category (non-null only).
     *
     * @return list<string>
     *
     * @since 35.0.0
     */
    public static function tiktokNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['tiktok'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all LinkedIn event names in this category (non-null only).
     *
     * @return list<string>
     *
     * @since 35.0.0
     */
    public static function linkedinNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['linkedin'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }
}
