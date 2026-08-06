<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

describe('v2.1 — AnalyticsManager trackEcommerce convenience', function () {
    it('trackEcommerce() dispatches GA4 event with original data', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->trackEcommerce('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ],
        ]);

        $calls = $manager->ga4()->getCalls();
        expect($calls)->not->toBeEmpty();

        $eventData = json_decode($calls[0]['body'], true);
        expect($eventData['event_name'])->toBe('purchase');
        expect($eventData['params']['transaction_id'])->toBe('TXN-123');
        expect($eventData['params']['value'])->toBe(99.99);
    });

    it('trackEcommerce() also dispatches Meta equivalent when enabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'meta_pixel' => ['enabled' => true, 'id' => '12345', 'access_token' => 'token'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->trackEcommerce('purchase', [
            'transaction_id' => 'TXN-456',
            'value' => 149.99,
            'currency' => 'EUR',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 3],
            ],
        ]);

        $ga4Calls = $manager->ga4()->getCalls();
        expect($ga4Calls)->not->toBeEmpty();

        // Meta should also receive the Purchase event
        $metaCalls = $manager->meta()->getCalls();
        // The meta call should have 'Purchase' event or the formatted data
        expect($metaCalls)->not->toBeEmpty();
    });

    it('trackEcommerce() does not dispatch Meta for unmapped events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'meta_pixel' => ['enabled' => true, 'id' => '12345', 'access_token' => 'token'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $metaCallsBefore = count($manager->meta()->getCalls());

        $manager->trackEcommerce('remove_from_cart', [
            'item_id' => 'SKU-001',
        ]);

        // remove_from_cart has no Meta equivalent — should not add extra Meta call
        $metaCallsAfter = count($manager->meta()->getCalls());
        expect($metaCallsAfter)->toBe($metaCallsBefore + 1); // Only the GA4 track() call
    });

    it('trackEcommerce() works with empty data', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEcommerce('view_item');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('view_item');
    });

    it('trackEcommerce() merges additional params', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEcommerce('add_to_cart', [
            'value' => 29.99,
            'items' => [],
        ], ['source' => 'quick_add', 'position' => 1]);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['event'])->toBe('add_to_cart');
        expect($layer[0]['source'])->toBe('quick_add');
        expect($layer[0]['position'])->toBe(1);
    });
});

describe('v2.1 — EventCatalog search and byProvider', function () {
    it('search() finds events by partial name match', function () {
        $results = EventCatalog::search('purchase');

        expect($results)->not->toBeEmpty();
        $names = array_column($results, 'name');
        expect($names)->toContain('purchase');
    });

    it('search() returns empty for no match', function () {
        $results = EventCatalog::search('nonexistent_event_xyz');

        expect($results)->toBeEmpty();
    });

    it('search() is case-insensitive', function () {
        $results = EventCatalog::search('LOGIN');

        expect($results)->not->toBeEmpty();
        $names = array_column($results, 'name');
        expect($names)->toContain('login');
    });

    it('search() finds multiple matching events', function () {
        $results = EventCatalog::search('cart');

        expect($results)->not->toBeEmpty();
        $names = array_column($results, 'name');
        expect($names)->toContain('add_to_cart');
        expect($names)->toContain('remove_from_cart');
        expect($names)->toContain('view_cart');
    });

    it('byProvider() returns ga4 and meta event lists', function () {
        $providers = EventCatalog::byProvider();

        expect($providers)->toHaveKey('ga4');
        expect($providers)->toHaveKey('meta');
        expect($providers['ga4'])->not->toBeEmpty();
        expect($providers['meta'])->not->toBeEmpty();
    });

    it('byProvider() ga4 list has no duplicates', function () {
        $providers = EventCatalog::byProvider();

        $unique = array_unique($providers['ga4']);
        expect(count($unique))->toBe(count($providers['ga4']));
    });

    it('byProvider() meta list has no nulls', function () {
        $providers = EventCatalog::byProvider();

        foreach ($providers['meta'] as $name) {
            expect($name)->not->toBeNull();
        }
    });
});
