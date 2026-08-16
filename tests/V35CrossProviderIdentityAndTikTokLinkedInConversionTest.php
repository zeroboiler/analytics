<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\CrossProviderIdentityService;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

describe('v35.0.0 — CrossProviderIdentityService', function () {
    it('can be instantiated with config', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                    ],
                    'cross_provider_identity' => [
                        'enabled' => true,
                        'provider_sync' => [
                            'ga4' => true,
                            'meta' => true,
                            'posthog' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new CrossProviderIdentityService($manager, $config);

        expect($service->isEnabled())->toBeTrue();
        expect($service->isProviderEnabled('ga4'))->toBeTrue();
        expect($service->isProviderEnabled('meta'))->toBeTrue();
        expect($service->isProviderEnabled('posthog'))->toBeFalse();
    });

    it('returns a summary with all providers', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                    ],
                    'cross_provider_identity' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new CrossProviderIdentityService($manager, $config);
        $summary = $service->summary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary)->toHaveKey('providers');
        expect($summary['providers'])->toHaveKeys([
            'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude',
            'tiktok', 'linkedin', 'plausible',
        ]);
    });

    it('can be disabled via config', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                    ],
                    'cross_provider_identity' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new CrossProviderIdentityService($manager, $config);

        expect($service->isEnabled())->toBeFalse();
    });
});

describe('v35.0.0 — EcommerceFormatConverter TikTok/LinkedIn', function () {
    it('converts GA4 items to TikTok format', function () {
        $items = [
            [
                'item_id' => 'SKU-001',
                'item_name' => 'Premium Widget',
                'item_category' => 'Widgets',
                'price' => 29.99,
                'quantity' => 2,
            ],
        ];

        $result = EcommerceFormatConverter::ga4ToTiktokProperties($items);

        expect($result['contents'])->toHaveCount(1);
        expect($result['contents'][0]['content_id'])->toBe('SKU-001');
        expect($result['contents'][0]['content_name'])->toBe('Premium Widget');
        expect($result['contents'][0]['quantity'])->toBe(2);
        expect($result['contents'][0]['price'])->toBe(29.99);
        expect($result['total_value'])->toBe(59.98);
        expect($result['num_items'])->toBe(1);
    });

    it('converts GA4 purchase to TikTok format', function () {
        $ga4Params = [
            'transaction_id' => 'TX-123',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'item_category' => 'Widgets', 'price' => 99.99, 'quantity' => 1],
            ],
        ];

        $result = EcommerceFormatConverter::ga4ToTiktokPurchase($ga4Params);

        expect($result['value'])->toBe(99.99);
        expect($result['currency'])->toBe('USD');
        expect($result['transaction_id'])->toBe('TX-123');
        expect($result['contents'])->toHaveCount(1);
    });

    it('converts GA4 purchase to LinkedIn format', function () {
        $ga4Params = [
            'transaction_id' => 'TX-456',
            'value' => 149.99,
            'currency' => 'EUR',
            'items' => [
                ['item_id' => 'A', 'item_name' => 'Product', 'price' => 149.99, 'quantity' => 1],
                ['item_id' => 'B', 'item_name' => 'Addon', 'price' => 0.0, 'quantity' => 1],
            ],
        ];

        $result = EcommerceFormatConverter::ga4ToLinkedinPurchase($ga4Params);

        expect($result['value'])->toBe(149.99);
        expect($result['currency'])->toBe('EUR');
        expect($result['transaction_id'])->toBe('TX-456');
        expect($result['items_count'])->toBe(2);
    });

    it('builds a TikTok purchase event', function () {
        $event = EcommerceFormatConverter::buildTiktokPurchase(
            'TX-789',
            49.99,
            'GBP',
            [
                ['item_id' => 'X', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
            ],
        );

        expect($event->name)->toBe('CompletePayment');
        expect($event->params['value'])->toBe(49.99);
        expect($event->params['contents'])->toHaveCount(1);
    });

    it('builds a LinkedIn purchase event', function () {
        $event = EcommerceFormatConverter::buildLinkedinPurchase(
            'TX-LINK',
            199.99,
            'USD',
        );

        expect($event->name)->toBe('purchase');
        expect($event->params['value'])->toBe(199.99);
    });

    it('buildForAllProviders includes tiktok and linkedin', function () {
        $ga4Params = [
            'transaction_id' => 'TX-MULTI',
            'value' => 100.00,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'P1', 'item_name' => 'Product', 'price' => 100.00, 'quantity' => 1],
            ],
        ];

        $result = EcommerceFormatConverter::buildForAllProviders('purchase', $ga4Params);

        expect($result)->toHaveKeys([
            'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude',
            'plausible', 'tiktok', 'linkedin',
        ]);
        expect($result['tiktok']['value'])->toBe(100.00);
        expect($result['linkedin']['value'])->toBe(100.00);
        expect($result['plausible'])->not->toBeNull();
    });

    it('buildForAllProviders handles add_to_cart for all providers', function () {
        $ga4Params = [
            'value' => 25.00,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'C1', 'item_name' => 'Cart Item', 'price' => 25.00, 'quantity' => 1],
            ],
        ];

        $result = EcommerceFormatConverter::buildForAllProviders('add_to_cart', $ga4Params);

        expect($result['tiktok']['value'])->toBe(25.00);
        expect($result['linkedin']['value'])->toBe(25.00);
    });

    it('buildForAllProviders handles refund for all providers', function () {
        $ga4Params = [
            'transaction_id' => 'TX-REF',
            'value' => 50.00,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'R1', 'item_name' => 'Refunded', 'price' => 50.00, 'quantity' => 1],
            ],
        ];

        $result = EcommerceFormatConverter::buildForAllProviders('refund', $ga4Params);

        expect($result['tiktok']['transaction_id'])->toBe('TX-REF');
        expect($result['linkedin']['value'])->toBe(50.00);
    });
});

describe('v35.0.0 — Event Catalog TikTok/LinkedIn Methods', function () {
    it('EcommerceEvents has tiktokNames() and linkedinNames() methods', function () {
        expect(method_exists(EcommerceEvents::class, 'tiktokNames'))->toBeTrue();
        expect(method_exists(EcommerceEvents::class, 'linkedinNames'))->toBeTrue();

        $tiktok = EcommerceEvents::tiktokNames();
        $linkedin = EcommerceEvents::linkedinNames();

        expect($tiktok)->toBeArray();
        expect($linkedin)->toBeArray();
        expect($tiktok)->not->toBeEmpty();
        // purchase should map to CompletePayment on TikTok
        expect($tiktok)->toContain('CompletePayment');
    });

    it('SaaSEvents has tiktokNames() and linkedinNames() methods', function () {
        expect(method_exists(SaaSEvents::class, 'tiktokNames'))->toBeTrue();
        expect(method_exists(SaaSEvents::class, 'linkedinNames'))->toBeTrue();
    });

    it('EngagementEvents has tiktokNames() and linkedinNames() methods', function () {
        expect(method_exists(EngagementEvents::class, 'tiktokNames'))->toBeTrue();
        expect(method_exists(EngagementEvents::class, 'linkedinNames'))->toBeTrue();
    });

    it('EcommerceEvents purchase entry has tiktok and linkedin keys', function () {
        $entry = EcommerceEvents::get('purchase');

        expect($entry)->not->toBeNull();
        expect($entry)->toHaveKey('tiktok');
        expect($entry)->toHaveKey('linkedin');
        expect($entry['tiktok'])->toBe('CompletePayment');
        expect($entry['linkedin'])->toBe('purchase');
    });
});
