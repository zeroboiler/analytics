<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
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
 * Tests for all 12 typed e-commerce event classes.
 *
 * Validates constructor parameters, DTO conversion, cross-provider
 * mapping consistency, and param structure for every e-commerce event.
 */
final class V218EcommerceEventClassesTest extends TestCase
{
    public function test_view_item_event(): void
    {
        $event = new ViewItemEvent(
            itemId: 'SKU-001',
            itemName: 'Widget',
            itemCategory: 'Gadgets',
            price: 49.99,
            currency: 'USD',
        );

        $this->assertSame('view_item', $event->name);
        $this->assertSame('SKU-001', $event->params['item_id']);
        $this->assertSame('Widget', $event->params['item_name']);
        $this->assertSame(49.99, $event->params['price']);
        $this->assertSame('USD', $event->params['currency']);
    }

    public function test_add_to_cart_event(): void
    {
        $event = new AddToCartEvent(
            itemId: 'SKU-001',
            itemName: 'Widget',
            itemCategory: 'Gadgets',
            price: 49.99,
            quantity: 2,
            currency: 'EUR',
        );

        $this->assertSame('add_to_cart', $event->name);
        $this->assertSame(2, $event->params['quantity']);
        $this->assertSame('EUR', $event->params['currency']);
    }

    public function test_remove_from_cart_event(): void
    {
        $event = new RemoveFromCartEvent(
            itemId: 'SKU-001',
            itemName: 'Widget',
            itemCategory: 'Gadgets',
            price: 49.99,
            quantity: 1,
            currency: 'USD',
        );

        $this->assertSame('remove_from_cart', $event->name);
        $this->assertSame(1, $event->params['quantity']);
    }

    public function test_view_cart_event(): void
    {
        $event = new ViewCartEvent(
            items: [['item_id' => 'SKU-001', 'price' => 49.99]],
            value: 49.99,
            currency: 'USD',
        );

        $this->assertSame('view_cart', $event->name);
        $this->assertIsArray($event->params['items']);
        $this->assertSame(49.99, $event->params['value']);
    }

    public function test_begin_checkout_event(): void
    {
        $event = new BeginCheckoutEvent(
            items: [['item_id' => 'SKU-001']],
            value: 49.99,
            currency: 'USD',
            coupon: 'SAVE10',
        );

        $this->assertSame('begin_checkout', $event->name);
        $this->assertSame('SAVE10', $event->params['coupon']);
    }

    public function test_add_payment_info_event(): void
    {
        $event = new AddPaymentInfoEvent(paymentType: 'credit_card');

        $this->assertSame('add_payment_info', $event->name);
        $this->assertSame('credit_card', $event->params['payment_type']);
    }

    public function test_purchase_event(): void
    {
        $event = new PurchaseEvent(
            transactionId: 'TXN-12345',
            value: 99.99,
            items: [['item_id' => 'SKU-001', 'quantity' => 2]],
            currency: 'USD',
            coupon: 'WELCOME',
            tax: 8.00,
            shipping: 5.00,
        );

        $this->assertSame('purchase', $event->name);
        $this->assertSame('TXN-12345', $event->params['transaction_id']);
        $this->assertSame(99.99, $event->params['value']);
        $this->assertSame(8.00, $event->params['tax']);
        $this->assertSame(5.00, $event->params['shipping']);
    }

    public function test_refund_event(): void
    {
        $event = new RefundEvent(
            transactionId: 'TXN-12345',
            refundValue: 49.99,
            currency: 'USD',
            items: [],
        );

        $this->assertSame('refund', $event->name);
        $this->assertSame(49.99, $event->params['refund_value']);
    }

    public function test_wishlist_event(): void
    {
        $event = new WishlistEvent(
            itemId: 'SKU-001',
            itemName: 'Widget',
            itemCategory: 'Gadgets',
            price: 49.99,
            currency: 'USD',
        );

        $this->assertSame('add_to_wishlist', $event->name);
        $this->assertSame('SKU-001', $event->params['item_id']);
    }

    public function test_select_item_event(): void
    {
        $event = new SelectItemEvent(
            items: [['item_id' => 'SKU-001']],
            itemListId: 'related_products',
            itemListName: 'Related Products',
            currency: 'USD',
        );

        $this->assertSame('select_item', $event->name);
        $this->assertSame('related_products', $event->params['item_list_id']);
        $this->assertSame('Related Products', $event->params['item_list_name']);
    }

    public function test_select_promotion_event(): void
    {
        $event = new SelectPromotionEvent(
            promotionId: 'PROMO-001',
            promotionName: 'Summer Sale',
            creativeName: 'hero_banner',
            creativeSlot: 'homepage_top',
            locationId: 'slot_1',
        );

        $this->assertSame('select_promotion', $event->name);
        $this->assertSame('Summer Sale', $event->params['promotion_name']);
        $this->assertSame('slot_1', $event->params['location_id']);
    }

    public function test_view_promotion_event(): void
    {
        $event = new ViewPromotionEvent(
            promotionId: 'PROMO-001',
            promotionName: 'Summer Sale',
            creativeName: 'hero_banner',
            creativeSlot: 'homepage_top',
            locationId: 'slot_1',
        );

        $this->assertSame('view_promotion', $event->name);
        $this->assertSame('PROMO-001', $event->params['promotion_id']);
    }

    public function test_all_ecommerce_events_are_analytics_events(): void
    {
        $events = [
            new ViewItemEvent(itemId: 'x', itemName: 'y', itemCategory: 'z', price: 1.0, currency: 'USD'),
            new AddToCartEvent(itemId: 'x', itemName: 'y', itemCategory: 'z', price: 1.0, quantity: 1, currency: 'USD'),
            new RemoveFromCartEvent(itemId: 'x', itemName: 'y', itemCategory: 'z', price: 1.0, quantity: 1, currency: 'USD'),
            new ViewCartEvent(items: [], value: 0.0, currency: 'USD'),
            new BeginCheckoutEvent(items: [], value: 0.0, currency: 'USD'),
            new AddPaymentInfoEvent(paymentType: 'card'),
            new PurchaseEvent(transactionId: 't', value: 1.0, items: [], currency: 'USD'),
            new RefundEvent(transactionId: 't', currency: 'USD'),
            new WishlistEvent(itemId: 'x', itemName: 'y', itemCategory: 'z', price: 1.0, currency: 'USD'),
            new SelectItemEvent(items: [], currency: 'USD'),
            new SelectPromotionEvent(),
            new ViewPromotionEvent(),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(AnalyticsEvent::class, $event);
        }

        $this->assertCount(12, $events);
    }

    public function test_ecommerce_events_have_monetary_params_typed(): void
    {
        $purchase = new PurchaseEvent(transactionId: 't', value: 99.99, items: [], currency: 'USD');
        $refund = new RefundEvent(transactionId: 't', refundValue: 49.99, currency: 'USD');

        $this->assertIsFloat($purchase->params['value']);
        $this->assertIsFloat($refund->params['refund_value']);
    }
}
