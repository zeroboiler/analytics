<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\Ecommerce\AddPaymentInfoEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\BeginCheckoutEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RemoveFromCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\SelectItemEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\SelectPromotionEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewPromotionEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\WishlistEvent;

/**
 * High-level ecommerce analytics service.
 *
 * Provides convenience methods for common ecommerce tracking scenarios.
 * Formats items correctly for both GA4 and Meta Pixel, then dispatches
 * to all enabled providers.
 *
 * @since 1.0.0
 */
final class EcommerceAnalyticsService
{
    private AnalyticsManager $manager;

    private string $defaultCurrency;

    private string $brand;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config){
        $this->manager = $manager;

        $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
        /** @var array{currency?: string, brand?: string} $ecommerce */
        $this->defaultCurrency = $ecommerce['currency'] ?? 'USD';
        $this->brand = $ecommerce['brand'] ?? '';
    }

    /**
     * Track a product view.
     *
     * @param  array<string, mixed>  $item
     */
    public function viewItem(array $item): void
    {
        $this->manager->trackEvent(new ViewItemEvent(
            itemId: $item['item_id'],
            itemName: $item['item_name'] ?? '',
            itemCategory: $item['item_category'] ?? '',
            price: $item['price'] ?? null,
            currency: $item['currency'] ?? $this->defaultCurrency,
        ));
    }

    /**
     * Track add-to-cart action.
     *
     * @param  array<string, mixed>  $item
     */
    public function addToCart(array $item): void
    {
        $this->manager->trackEvent(new AddToCartEvent(
            itemId: $item['item_id'],
            itemName: $item['item_name'] ?? '',
            itemCategory: $item['item_category'] ?? '',
            price: $item['price'] ?? null,
            quantity: $item['quantity'] ?? 1,
            currency: $item['currency'] ?? $this->defaultCurrency,
        ));
    }

    /**
     * Track remove-from-cart action.
     *
     * @param  array<string, mixed>  $item
     */
    public function removeFromCart(array $item): void
    {
        $this->manager->trackEvent(new RemoveFromCartEvent(
            itemId: $item['item_id'],
            itemName: $item['item_name'] ?? '',
            itemCategory: $item['item_category'] ?? '',
            price: $item['price'] ?? null,
            quantity: $item['quantity'] ?? 1,
            currency: $item['currency'] ?? $this->defaultCurrency,
        ));
    }

    /**
     * Track viewing the cart.
     *
     * @param  array<string, mixed>  $items
     */
    public function viewCart(array $items, float $value): void
    {
        $this->manager->trackEvent(new ViewCartEvent(
            items: $this->formatItems($items),
            value: $value,
            currency: $this->defaultCurrency,
        ));
    }

    /**
     * Track begin checkout.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, mixed>  $params
     */
    public function beginCheckout(array $items, float $value, array $params = []): void
    {
        $this->manager->trackEvent(new BeginCheckoutEvent(
            items: $this->formatItems($items),
            value: $value,
            currency: $this->defaultCurrency,
            coupon: $params['coupon'] ?? null,
        ));
    }

    /**
     * Track add payment info.
     */
    public function addPaymentInfo(string $paymentType): void
    {
        $this->manager->trackEvent(new AddPaymentInfoEvent(
            paymentType: $paymentType,
        ));
    }

    /**
     * Track a purchase.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, mixed>  $params
     */
    public function purchase(string $transactionId, float $value, array $items, array $params = []): void
    {
        $this->manager->trackEvent(new PurchaseEvent(
            transactionId: $transactionId,
            value: $value,
            items: $this->formatItems($items),
            currency: $params['currency'] ?? $this->defaultCurrency,
            coupon: $params['coupon'] ?? null,
            affiliation: $params['affiliation'] ?? null,
            tax: $params['tax'] ?? null,
            shipping: $params['shipping'] ?? null,
        ));
    }

    /**
     * Track a refund.
     *
     * @param  array<string, mixed>|null  $items
     */
    public function refund(string $transactionId, ?float $refundValue = null, ?array $items = null): void
    {
        $this->manager->trackEvent(new RefundEvent(
            transactionId: $transactionId,
            refundValue: $refundValue,
            currency: $this->defaultCurrency,
            items: $items !== null ? $this->formatItems($items) : [],
        ));
    }

    /**
     * Track an add-to-wishlist action.
     *
     * @param  array<string, mixed>  $item
     */
    public function addToWishlist(array $item): void
    {
        $this->manager->trackEvent(new WishlistEvent(
            itemId: $item['item_id'],
            itemName: $item['item_name'] ?? '',
            itemCategory: $item['item_category'] ?? '',
            price: $item['price'] ?? null,
            currency: $item['currency'] ?? $this->defaultCurrency,
        ));
    }

    /**
     * Track an item selection from a list.
     *
     * Part of the GA4 e-commerce product funnel.
     * Typically fired before view_item or add_to_cart.
     *
     * @param  array<int, array<string, mixed>>  $items  Selected items
     * @param  string|null  $itemListId  Item list identifier (e.g. 'related_products')
     * @param  string|null  $itemListName  Item list name (e.g. 'Related Products')
     */
    public function selectItem(array $items = [], ?string $itemListId = null, ?string $itemListName = null): void
    {
        $this->manager->trackEvent(new SelectItemEvent(
            items: $this->formatItems($items),
            itemListId: $itemListId,
            itemListName: $itemListName,
            currency: $this->defaultCurrency,
        ));
    }

    /**
     * Track a promotion click/selection.
     *
     * Part of the GA4 e-commerce promotion funnel.
     *
     * @param  string|null  $promotionId  Promotion ID
     * @param  string|null  $promotionName  Promotion name
     * @param  string|null  $creativeName  Creative name
     * @param  string|null  $creativeSlot  Creative slot position
     * @param  string|null  $locationId  Location ID
     */
    public function selectPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, ?string $locationId = null): void
    {
        $this->manager->trackEvent(new SelectPromotionEvent(
            promotionId: $promotionId,
            promotionName: $promotionName,
            creativeName: $creativeName,
            creativeSlot: $creativeSlot,
            locationId: $locationId,
        ));
    }

    /**
     * Track a promotion view impression.
     *
     * Part of the GA4 e-commerce promotion funnel.
     *
     * @param  string|null  $promotionId  Promotion ID
     * @param  string|null  $promotionName  Promotion name
     * @param  string|null  $creativeName  Creative name
     * @param  string|null  $creativeSlot  Creative slot position
     * @param  string|null  $locationId  Location ID
     */
    public function viewPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, ?string $locationId = null): void
    {
        $this->manager->trackEvent(new ViewPromotionEvent(
            promotionId: $promotionId,
            promotionName: $promotionName,
            creativeName: $creativeName,
            creativeSlot: $creativeSlot,
            locationId: $locationId,
        ));
    }

    /**
     * Format items for GA4 (standardized format).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function formatGA4Item(array $item): array
    {
        $formatted = [
            'item_id' => (string) ($item['item_id'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? ''),
            'item_category' => (string) ($item['item_category'] ?? ''),
            'item_variant' => (string) ($item['item_variant'] ?? ''),
            'item_brand' => (string) ($item['item_brand'] ?? $this->brand),
            'price' => (float) ($item['price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
        ];

        return array_filter($formatted, fn (mixed $v): bool => $v != '' && $v != 0);
    }

    /**
     * Format items for Meta Pixel (contents array format).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function formatMetaItem(array $item): array
    {
        return [
            'id' => (string) ($item['item_id'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'item_price' => (float) ($item['price'] ?? 0),
            'name' => (string) ($item['item_name'] ?? ''),
            'category' => (string) ($item['item_category'] ?? ''),
        ];
    }

    /**
     * Format an array of items using the GA4 format.
     *
     * @param  array<string, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function formatItems(array $items): array
    {
        return array_map(
            fn (array $item): array => $this->formatGA4Item($item),
            $items,
        );
    }
}
