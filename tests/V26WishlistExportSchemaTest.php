<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

// ── EventSchemaRegistry Expansion Tests ──────────────────────────────

describe('EventSchemaRegistry v2.6', function () {
    it('has add_to_wishlist schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('add_to_wishlist'))->toBeTrue();

        $schema = $registry->get('add_to_wishlist');
        expect($schema)->not->toBeNull();
        expect($schema->category)->toBe('ecommerce');
        expect($schema->description)->toBe('Tracks adding an item to the wishlist');
    });

    it('validates add_to_wishlist with required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('add_to_wishlist', [
            'item_id' => 'SKU-001',
            'item_name' => 'Widget',
            'price' => 49.99,
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('rejects add_to_wishlist without required item_id', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('add_to_wishlist', [
            'item_name' => 'Widget',
        ]);

        expect($result['valid'])->toBeFalse();
    });

    it('has set_user_properties schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('set_user_properties'))->toBeTrue();
        $schema = $registry->get('set_user_properties');
        expect($schema->category)->toBe('core');
    });

    it('has alias schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('alias'))->toBeTrue();
        $schema = $registry->get('alias');
        expect($schema->category)->toBe('core');

        // Requires previous_id and new_id
        $result = $registry->validate('alias', [
            'previous_id' => 'anon-uuid',
            'new_id' => 'user-42',
        ]);
        expect($result['valid'])->toBeTrue();
    });

    it('rejects alias without required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('alias', [
            'previous_id' => 'anon-uuid',
        ]);
        expect($result['valid'])->toBeFalse();
    });

    it('has outbound_click schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('outbound_click'))->toBeTrue();
        $schema = $registry->get('outbound_click');
        expect($schema->category)->toBe('engagement');
    });

    it('has internal_click schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('internal_click'))->toBeTrue();
        $schema = $registry->get('internal_click');
        expect($schema->category)->toBe('engagement');
    });

    it('schema count is higher than v2.5', function () {
        $registry = new EventSchemaRegistry;

        // v2.5 had ~38 schemas, v2.6 adds ~6 more
        expect($registry->count())->toBeGreaterThanOrEqual(40);
    });

    it('getEventsByCategory returns correct category counts', function () {
        $registry = new EventSchemaRegistry;

        $ecommerce = $registry->getEventsByCategory('ecommerce');
        $engagement = $registry->getEventsByCategory('engagement');
        $core = $registry->getEventsByCategory('core');

        expect($ecommerce)->not->toBeEmpty();
        expect($engagement)->not->toBeEmpty();
        expect($core)->not->toBeEmpty();

        // Wishlist is in ecommerce
        expect(in_array('add_to_wishlist', $ecommerce))->toBeTrue();

        // outbound_click is in engagement
        expect(in_array('outbound_click', $engagement))->toBeTrue();

        // alias is in core
        expect(in_array('alias', $core))->toBeTrue();
    });
});

// ── AnalyticsManager::wishlist() Tests ───────────────────────────────

describe('AnalyticsManager wishlist', function () {
    it('tracks wishlist event via GTM', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->wishlist([
            'item_id' => 'SKU-001',
            'item_name' => 'Premium Widget',
            'item_category' => 'Gadgets',
            'price' => 199.99,
            'currency' => 'USD',
        ]);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('add_to_wishlist');
        expect($layer[0]['item_id'])->toBe('SKU-001');
        expect($layer[0]['price'])->toBe(199.99);
    });

    it('tracks wishlist with additional params', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->wishlist(['item_id' => 'SKU-003'], ['source' => 'search', 'list_position' => 3]);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('add_to_wishlist');
        expect($layer[0]['item_id'])->toBe('SKU-003');
        expect($layer[0]['source'])->toBe('search');
    });

    it('tracks wishlist with minimal item data', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->wishlist(['item_id' => 'SKU-MIN']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('add_to_wishlist');
        expect($layer[0]['item_id'])->toBe('SKU-MIN');
        expect($layer[0]['currency'])->toBe('USD');
    });
});

// ── EventCatalog with Wishlist ───────────────────────────────────────

describe('EventCatalog wishlist integration', function () {
    it('includes add_to_wishlist in catalog', function () {
        expect(EventCatalog::has('add_to_wishlist'))->toBeTrue();
    });

    it('add_to_wishlist has correct category', function () {
        $entry = EventCatalog::get('add_to_wishlist');
        expect($entry)->not->toBeNull();
        expect($entry['category'])->toBe('ecommerce');
        expect($entry['ga4'])->toBe('add_to_wishlist');
        expect($entry['meta'])->toBe('AddToWishlist');
    });

    it('total event count reflects wishlist addition', function () {
        $total = EventCatalog::count();
        // Ecommerce: 9, SaaS: 11, Engagement: 13 = 33
        expect($total)->toBeGreaterThanOrEqual(33);
    });

    it('EcommerceEvents catalog includes wishlist', function () {
        expect(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::has('add_to_wishlist'))->toBeTrue();
        expect(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count())->toBe(9);
    });

    it('search finds wishlist events', function () {
        $results = EventCatalog::search('wishlist');
        expect($results)->not->toBeEmpty();
        expect($results[0]['name'])->toBe('add_to_wishlist');
    });
});

// ── Version Consistency ─────────────────────────────────────────────

describe('Version v2.6.0 consistency', function () {
    it('AnalyticsManager reports v2.6.0', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->version())->toBe('5.0.0');
    });

    it('event catalog summary includes all categories', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->eventCatalogSummary();

        expect($summary['ecommerce'])->toBe(9);
        expect($summary['total'])->toBeGreaterThanOrEqual(33);
    });
});

// ── AnalyticsExportCommand Unit Tests ────────────────────────────────

describe('AnalyticsExportCommand', function () {
    it('can be instantiated with dependencies', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $registry = new EventSchemaRegistry;

        $command = new \ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand($manager, $registry);

        expect($command)->toBeInstanceOf(\ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand::class);
        expect($command->getDescription())->toContain('Export');
    });
});
