<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

describe('v2.0 — AnalyticsManager pageView convenience methods', function () {
    it('pageView() tracks page_view with title, location, and referrer', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->pageView('Pricing', 'https://example.com/pricing', 'https://google.com');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('page_view');
        expect($layer[0]['page_title'])->toBe('Pricing');
        expect($layer[0]['page_location'])->toBe('https://example.com/pricing');
        expect($layer[0]['page_referrer'])->toBe('https://google.com');
    });

    it('pageView() works with minimal params', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->pageView();

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('page_view');
    });

    it('serverSidePageView() tracks with client and user identity', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->serverSidePageView(
            'Dashboard',
            'https://example.com/dashboard',
            'https://example.com/home',
            'client-uuid-123',
            'user-42',
        );

        // Event should be dispatched (check via gtag calls recorded)
        $calls = $manager->ga4()->getCalls();
        expect($calls)->not->toBeEmpty();
    });

    it('serverSidePageView() works with null identity', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->serverSidePageView('Home', '', '', null, null);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('page_view');
    });

    it('logout() tracks logout with method', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->logout('sanctum');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
        expect($layer[0]['method'])->toBe('sanctum');
    });

    it('logout() works without method', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->logout();

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
    });

    it('trialEnd() tracks trial_end with outcome', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trialEnd('converted', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('trial_end');
        expect($layer[0]['outcome'])->toBe('converted');
        expect($layer[0]['plan_name'])->toBe('pro');
    });

    it('planDowngrade() tracks plan_downgrade event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->planDowngrade('pro', 'starter');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('plan_downgrade');
        expect($layer[0]['from_plan'])->toBe('pro');
        expect($layer[0]['to_plan'])->toBe('starter');
    });

    it('formatEcommerceForMeta() converts GA4 items to Meta format', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'item_category' => 'Tools', 'price' => 49.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'item_category' => 'Electronics', 'price' => 29.99, 'quantity' => 1],
        ];

        $meta = $manager->formatEcommerceForMeta($items);

        expect($meta['content_ids'])->toBe(['SKU-001', 'SKU-002']);
        expect($meta['num_items'])->toBe(3);
        expect($meta['contents'])->toHaveCount(2);
        expect($meta['contents'][0]['id'])->toBe('SKU-001');
        expect($meta['contents'][0]['item_price'])->toBe(49.99);
        expect($meta['contents'][0]['quantity'])->toBe(2);
        expect($meta['contents'][1]['name'])->toBe('Gadget');
    });

    it('formatEcommerceForMeta() handles empty items', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $meta = $manager->formatEcommerceForMeta([]);

        expect($meta['content_ids'])->toBe([]);
        expect($meta['contents'])->toBe([]);
        expect($meta['num_items'])->toBe(0);
    });

    it('formatEcommerceForMeta() uses defaults for missing fields', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $meta = $manager->formatEcommerceForMeta([['item_id' => 'X']]);

        expect($meta['contents'][0]['id'])->toBe('X');
        expect($meta['contents'][0]['quantity'])->toBe(1);
        expect($meta['contents'][0]['item_price'])->toBe(0.0);
        expect($meta['contents'][0]['name'])->toBe('');
        expect($meta['contents'][0]['category'])->toBe('');
    });
});
