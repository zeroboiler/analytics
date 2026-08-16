<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

/**
 * E-commerce analytics event name constants for IDE autocompletion and type safety.
 *
 * Use these constants instead of raw strings to prevent typos and enable
 * IDE "find usages" / refactoring support when tracking e-commerce events.
 *
 * @since 100.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents
 */
final class EcommerceEventConstants
{
    /** @var string Product/page viewed */
    public const VIEW_ITEM = 'view_item';
    /** @var string Item added to cart */
    public const ADD_TO_CART = 'add_to_cart';
    /** @var string Item removed from cart */
    public const REMOVE_FROM_CART = 'remove_from_cart';
    /** @var string Cart viewed */
    public const VIEW_CART = 'view_cart';
    /** @var string Checkout started */
    public const BEGIN_CHECKOUT = 'begin_checkout';
    /** @var string Payment info added */
    public const ADD_PAYMENT_INFO = 'add_payment_info';
    /** @var string Purchase completed */
    public const PURCHASE = 'purchase';
    /** @var string Refund issued */
    public const REFUND = 'refund';
    /** @var string Item added to wishlist */
    public const ADD_TO_WISHLIST = 'add_to_wishlist';
    /** @var string Item selected */
    public const SELECT_ITEM = 'select_item';
    /** @var string Promotion selected */
    public const SELECT_PROMOTION = 'select_promotion';
    /** @var string Promotion viewed */
    public const VIEW_PROMOTION = 'view_promotion';
    /** @var string Checkout step completed */
    public const CHECKOUT_STEP = 'checkout_step';
    /** @var string Cart abandoned */
    public const ABANDONED_CART = 'abandoned_cart';
    /** @var string Checkout abandoned */
    public const CHECKOUT_ABANDON = 'checkout_abandon';

    /**
     * Get all e-commerce event name constants as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }

    /**
     * Get all e-commerce event name constants as a list.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(self::all());
    }

    /**
     * Check if a given event name is a valid e-commerce event constant.
     */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * Get the total number of e-commerce event constants.
     */
    public static function count(): int
    {
        return count(self::all());
    }
}
