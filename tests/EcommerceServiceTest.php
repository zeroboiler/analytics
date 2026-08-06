<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;

beforeEach(function (): void {
    $config = new Repository([
        'zeroboiler' => [
            'analytics' => [
                'ga4' => ['enabled' => false],
                'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                'meta_pixel' => ['enabled' => false],
                'ecommerce' => [
                    'currency' => 'USD',
                    'brand' => 'Acme Corp',
                ],
            ],
        ],
    ]);

    $this->manager = new AnalyticsManager($config);
    $this->service = new EcommerceAnalyticsService($this->manager, $config);
});

describe('EcommerceAnalyticsService', function () {
    it('can be instantiated', function () {
        expect($this->service)->toBeInstanceOf(EcommerceAnalyticsService::class);
    });

    describe('formatGA4Item', function () {
        it('formats a complete item for GA4', function () {
            $formatted = $this->service->formatGA4Item([
                'item_id' => 'SKU-001',
                'item_name' => 'Premium Widget',
                'item_category' => 'Widgets',
                'item_variant' => 'XL',
                'item_brand' => 'CustomBrand',
                'price' => 49.99,
                'quantity' => 2,
            ]);

            expect($formatted['item_id'])->toBe('SKU-001')
                ->and($formatted['item_name'])->toBe('Premium Widget')
                ->and($formatted['item_category'])->toBe('Widgets')
                ->and($formatted['item_variant'])->toBe('XL')
                ->and($formatted['price'])->toBe(49.99)
                ->and($formatted['quantity'])->toBe(2);
        });

        it('uses config brand when item_brand is missing', function () {
            $formatted = $this->service->formatGA4Item([
                'item_id' => 'A',
                'item_name' => 'B',
                'price' => 10,
            ]);

            expect($formatted['item_brand'])->toBe('Acme Corp');
        });

        it('filters out empty values', function () {
            $formatted = $this->service->formatGA4Item([
                'item_id' => 'A',
                'item_name' => 'B',
                'price' => 0,
                'quantity' => 0,
            ]);

            expect($formatted)->not->toHaveKey('item_category')
                ->and($formatted)->not->toHaveKey('item_variant')
                ->and($formatted)->not->toHaveKey('price')
                ->and($formatted)->not->toHaveKey('quantity');
        });

        it('applies defaults for missing fields', function () {
            $formatted = $this->service->formatGA4Item([
                'item_id' => 'X',
                'item_name' => 'Y',
            ]);

            expect($formatted['item_id'])->toBe('X')
                ->and($formatted['item_name'])->toBe('Y')
                ->and($formatted['quantity'])->toBe(1);
        });
    });

    describe('formatMetaItem', function () {
        it('formats item for Meta Pixel format', function () {
            $formatted = $this->service->formatMetaItem([
                'item_id' => 'SKU-001',
                'item_name' => 'Widget',
                'item_category' => 'Gadgets',
                'price' => 29.99,
                'quantity' => 3,
            ]);

            expect($formatted['id'])->toBe('SKU-001')
                ->and($formatted['name'])->toBe('Widget')
                ->and($formatted['category'])->toBe('Gadgets')
                ->and($formatted['item_price'])->toBe(29.99)
                ->and($formatted['quantity'])->toBe(3);
        });

        it('has different field names than GA4 format', function () {
            $ga4 = $this->service->formatGA4Item(['item_id' => 'X', 'price' => 10]);
            $meta = $this->service->formatMetaItem(['item_id' => 'X', 'price' => 10]);

            expect($ga4)->toHaveKey('item_id');
            expect($meta)->toHaveKey('id');
            expect($meta)->not->toHaveKey('item_id');
        });
    });

    describe('viewItem', function () {
        it('dispatches view_item event via manager', function () {
            $this->service->viewItem([
                'item_id' => 'SKU-001',
                'item_name' => 'Test Product',
                'price' => 19.99,
            ]);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('view_item');
        });
    });

    describe('addToCart', function () {
        it('dispatches add_to_cart event', function () {
            $this->service->addToCart([
                'item_id' => 'SKU-001',
                'item_name' => 'Widget',
                'price' => 25.00,
                'quantity' => 2,
            ]);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('add_to_cart');
        });
    });

    describe('removeFromCart', function () {
        it('dispatches remove_from_cart event', function () {
            $this->service->removeFromCart([
                'item_id' => 'SKU-001',
                'item_name' => 'Widget',
                'price' => 25.00,
            ]);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('remove_from_cart');
        });
    });

    describe('viewCart', function () {
        it('dispatches view_cart event', function () {
            $this->service->viewCart([
                ['item_id' => 'A', 'item_name' => 'Alpha', 'price' => 10],
            ], 10.00);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('view_cart');
        });
    });

    describe('beginCheckout', function () {
        it('dispatches begin_checkout event', function () {
            $this->service->beginCheckout([], 50.00);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('begin_checkout');
        });

        it('passes coupon parameter', function () {
            $this->service->beginCheckout([], 50.00, ['coupon' => 'SAVE20']);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer[0]['eventParams']['coupon'])->toBe('SAVE20');
        });
    });

    describe('addPaymentInfo', function () {
        it('dispatches add_payment_info event', function () {
            $this->service->addPaymentInfo('credit_card');

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('add_payment_info');
            expect($layer[0]['eventParams']['payment_type'])->toBe('credit_card');
        });
    });

    describe('purchase', function () {
        it('dispatches purchase event', function () {
            $this->service->purchase('TXN-123', 99.99, []);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('purchase');
            expect($layer[0]['eventParams']['transaction_id'])->toBe('TXN-123');
            expect($layer[0]['eventParams']['value'])->toBe(99.99);
        });

        it('passes tax and shipping', function () {
            $this->service->purchase('TXN-456', 100.0, [], [
                'tax' => 10.0,
                'shipping' => 5.0,
            ]);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer[0]['eventParams']['tax'])->toBe(10.0);
            expect($layer[0]['eventParams']['shipping'])->toBe(5.0);
        });
    });

    describe('refund', function () {
        it('dispatches refund event', function () {
            $this->service->refund('TXN-789', 25.00);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('refund');
            expect($layer[0]['eventParams']['transaction_id'])->toBe('TXN-789');
        });
    });
});
