<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\AddPaymentInfoEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\BeginCheckoutEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RemoveFromCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent;

describe('E-commerce Events', function () {
    describe('ViewItemEvent', function () {
        it('creates with all parameters', function () {
            $event = new ViewItemEvent(
                itemId: 'SKU-001',
                itemName: 'Premium Widget',
                price: 49.99,
                currency: 'EUR',
                itemCategory: 'Widgets',
                itemVariant: 'XL',
                itemBrand: 'Acme',
            );

            expect($event->name)->toBe('view_item');
            expect($event->params['currency'])->toBe('EUR');
            expect($event->params['value'])->toBe(49.99);
            expect($event->params['items'][0]['item_id'])->toBe('SKU-001');
            expect($event->params['items'][0]['item_name'])->toBe('Premium Widget');
            expect($event->params['items'][0]['price'])->toBe(49.99);
            expect($event->params['items'][0]['item_category'])->toBe('Widgets');
            expect($event->params['items'][0]['item_variant'])->toBe('XL');
            expect($event->params['items'][0]['item_brand'])->toBe('Acme');
        });

        it('filters out null optional parameters', function () {
            $event = new ViewItemEvent(
                itemId: 'SKU-002',
                itemName: 'Basic Item',
                price: 10.00,
            );

            expect($event->params['items'][0])->not->toHaveKey('item_category');
            expect($event->params['items'][0])->not->toHaveKey('item_variant');
            expect($event->params['items'][0])->not->toHaveKey('item_brand');
        });

        it('defaults to USD currency', function () {
            $event = new ViewItemEvent(itemId: 'A', itemName: 'B', price: 1.0);

            expect($event->params['currency'])->toBe('USD');
        });

        it('works with null price', function () {
            $event = new ViewItemEvent(itemId: 'A', itemName: 'B');

            expect($event->params)->not->toHaveKey('value');
            expect($event->params['items'][0])->not->toHaveKey('price');
        });

        it('wraps single item in items array', function () {
            $event = new ViewItemEvent(itemId: 'X', itemName: 'Y', price: 5.0);

            expect($event->params['items'])->toBeArray()
                ->and($event->params['items'])->toHaveCount(1);
        });
    });

    describe('AddToCartEvent', function () {
        it('creates with all parameters', function () {
            $event = new AddToCartEvent(
                itemId: 'SKU-001',
                itemName: 'Widget',
                price: 29.99,
                quantity: 3,
                currency: 'GBP',
                itemCategory: 'Gadgets',
                itemVariant: 'Red',
            );

            expect($event->name)->toBe('add_to_cart');
            expect($event->params['currency'])->toBe('GBP');
            expect($event->params['value'])->toBe(89.97); // 29.99 * 3
            expect($event->params['items'][0]['item_id'])->toBe('SKU-001');
            expect($event->params['items'][0]['quantity'])->toBe(3);
        });

        it('defaults quantity to 1', function () {
            $event = new AddToCartEvent(itemId: 'A', itemName: 'B', price: 10.0);

            expect($event->params['value'])->toBe(10.0); // 10 * 1
            expect($event->params['items'][0]['quantity'])->toBe(1);
        });

        it('calculates correct value for multiple quantities', function () {
            $event = new AddToCartEvent(itemId: 'A', itemName: 'B', price: 7.5, quantity: 4);

            expect($event->params['value'])->toBe(30.0);
        });

        it('omits value when price is null', function () {
            $event = new AddToCartEvent(itemId: 'A', itemName: 'B');

            // null price → value=0, filtered by top-level array_filter (falsy)
            // Nested items array retains null price (array_filter only applies top-level)
            expect($event->params)->not->toHaveKey('value');
        });
    });

    describe('RemoveFromCartEvent', function () {
        it('creates with required parameters', function () {
            $event = new RemoveFromCartEvent(
                itemId: 'SKU-001',
                itemName: 'Widget',
                currency: 'USD',
                price: 29.99,
                quantity: 2,
            );

            expect($event->name)->toBe('remove_from_cart');
            expect($event->params['value'])->toBe(59.98);
            expect($event->params['items'][0]['item_id'])->toBe('SKU-001');
            expect($event->params['items'][0]['quantity'])->toBe(2);
        });

        it('defaults to USD and quantity 1', function () {
            $event = new RemoveFromCartEvent(itemId: 'A', itemName: 'B', price: 5.0);

            expect($event->params['currency'])->toBe('USD');
            expect($event->params['items'][0]['quantity'])->toBe(1);
        });

        it('includes item category when provided', function () {
            $event = new RemoveFromCartEvent(
                itemId: 'X',
                itemName: 'Y',
                price: 10.0,
                itemCategory: 'Gadgets',
            );

            expect($event->params['items'][0]['item_category'])->toBe('Gadgets');
        });

        it('filters out null item category', function () {
            $event = new RemoveFromCartEvent(
                itemId: 'X',
                itemName: 'Y',
                price: 10.0,
            );

            expect($event->params['items'][0])->not->toHaveKey('item_category');
        });
    });

    describe('BeginCheckoutEvent', function () {
        it('creates with all parameters', function () {
            $items = [
                ['item_id' => 'A', 'item_name' => 'Alpha', 'price' => 10],
                ['item_id' => 'B', 'item_name' => 'Beta', 'price' => 20],
            ];

            $event = new BeginCheckoutEvent(
                items: $items,
                value: 30.0,
                currency: 'EUR',
                itemCount: 2,
                coupon: 'SAVE10',
            );

            expect($event->name)->toBe('begin_checkout');
            expect($event->params['value'])->toBe(30.0);
            expect($event->params['currency'])->toBe('EUR');
            expect($event->params['item_count'])->toBe(2);
            expect($event->params['coupon'])->toBe('SAVE10');
        });

        it('filters out null optional params', function () {
            $event = new BeginCheckoutEvent(items: [], value: 0);

            expect($event->params)->not->toHaveKey('item_count');
            expect($event->params)->not->toHaveKey('coupon');
        });
    });

    describe('AddPaymentInfoEvent', function () {
        it('creates with all parameters', function () {
            $event = new AddPaymentInfoEvent(
                paymentType: 'credit_card',
                currency: 'USD',
                value: 99.99,
                coupon: 'FIRST10',
            );

            expect($event->name)->toBe('add_payment_info');
            expect($event->params['payment_type'])->toBe('credit_card');
            expect($event->params['currency'])->toBe('USD');
            expect($event->params['value'])->toBe(99.99);
            expect($event->params['coupon'])->toBe('FIRST10');
        });

        it('filters out null optional params', function () {
            $event = new AddPaymentInfoEvent(paymentType: 'paypal');

            expect($event->params)->not->toHaveKey('value');
            expect($event->params)->not->toHaveKey('coupon');
        });

        it('defaults to USD currency', function () {
            $event = new AddPaymentInfoEvent(paymentType: 'bank_transfer');

            expect($event->params['currency'])->toBe('USD');
        });
    });

    describe('PurchaseEvent', function () {
        it('creates with full transaction data', function () {
            $items = [
                ['item_id' => 'A', 'item_name' => 'Alpha', 'price' => 10, 'quantity' => 2],
            ];

            $event = new PurchaseEvent(
                transactionId: 'TXN-12345',
                value: 24.99,
                items: $items,
                currency: 'USD',
                coupon: 'WELCOME20',
                affiliation: 'online_store',
                tax: 2.50,
                shipping: 5.99,
            );

            expect($event->name)->toBe('purchase');
            expect($event->params['transaction_id'])->toBe('TXN-12345');
            expect($event->params['value'])->toBe(24.99);
            expect($event->params['currency'])->toBe('USD');
            expect($event->params['coupon'])->toBe('WELCOME20');
            expect($event->params['affiliation'])->toBe('online_store');
            expect($event->params['tax'])->toBe(2.50);
            expect($event->params['shipping'])->toBe(5.99);
            expect($event->params['items'])->toHaveCount(1);
        });

        it('works with minimal parameters', function () {
            $event = new PurchaseEvent(
                transactionId: 'TXN-MIN',
                value: 9.99,
            );

            expect($event->params['transaction_id'])->toBe('TXN-MIN');
            expect($event->params['value'])->toBe(9.99);
            expect($event->params['currency'])->toBe('USD');
            expect($event->params)->not->toHaveKey('coupon');
            expect($event->params)->not->toHaveKey('tax');
            expect($event->params)->not->toHaveKey('shipping');
        });

        it('filters out null values from params', function () {
            $event = new PurchaseEvent(
                transactionId: 'TXN-999',
                value: 0,
                items: [],
                coupon: null,
                affiliation: null,
                tax: null,
                shipping: null,
            );

            expect($event->params)->not->toHaveKey('coupon');
            expect($event->params)->not->toHaveKey('affiliation');
        });
    });

    describe('RefundEvent', function () {
        it('creates with partial refund', function () {
            $event = new RefundEvent(
                transactionId: 'TXN-123',
                refundValue: 15.00,
                currency: 'USD',
                reason: 'damaged',
            );

            expect($event->name)->toBe('refund');
            expect($event->params['transaction_id'])->toBe('TXN-123');
            expect($event->params['value'])->toBe(15.00);
            expect($event->params['reason'])->toBe('damaged');
        });

        it('creates full refund without value', function () {
            $event = new RefundEvent(transactionId: 'TXN-456');

            expect($event->params['transaction_id'])->toBe('TXN-456');
            expect($event->params)->not->toHaveKey('value');
        });

        it('filters out null reason', function () {
            $event = new RefundEvent(
                transactionId: 'TXN-789',
                refundValue: 50.0,
            );

            expect($event->params)->not->toHaveKey('reason');
        });
    });

    describe('ViewCartEvent', function () {
        it('creates with all parameters', function () {
            $items = [
                ['item_id' => 'A', 'price' => 10],
                ['item_id' => 'B', 'price' => 20],
            ];

            $event = new ViewCartEvent(
                items: $items,
                value: 30.0,
                currency: 'EUR',
                itemCount: 2,
            );

            expect($event->name)->toBe('view_cart');
            expect($event->params['value'])->toBe(30.0);
            expect($event->params['currency'])->toBe('EUR');
            expect($event->params['item_count'])->toBe(2);
        });

        it('filters out null item_count', function () {
            $event = new ViewCartEvent(items: [], value: 0);

            expect($event->params)->not->toHaveKey('item_count');
        });
    });

    describe('Event class hierarchy', function () {
        it('all ecommerce events extend AnalyticsEvent', function () {
            $events = [
                new ViewItemEvent('A', 'B', 1.0),
                new AddToCartEvent('A', 'B', 1.0),
                new RemoveFromCartEvent('A', 'B', price: 1.0),
                new BeginCheckoutEvent([], 0),
                new AddPaymentInfoEvent('credit_card'),
                new PurchaseEvent('TXN', 1.0),
                new RefundEvent('TXN'),
                new ViewCartEvent([], 0),
            ];

            foreach ($events as $event) {
                expect($event)->toBeInstanceOf(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
            }
        });

        it('all ecommerce events are readonly and final', function () {
            $classes = [
                ViewItemEvent::class,
                AddToCartEvent::class,
                RemoveFromCartEvent::class,
                BeginCheckoutEvent::class,
                AddPaymentInfoEvent::class,
                PurchaseEvent::class,
                RefundEvent::class,
                ViewCartEvent::class,
            ];

            foreach ($classes as $class) {
                $reflection = new ReflectionClass($class);
                expect($reflection->isReadOnly())->toBeTrue()
                    ->and($reflection->isFinal())->toBeTrue();
            }
        });
    });
});
