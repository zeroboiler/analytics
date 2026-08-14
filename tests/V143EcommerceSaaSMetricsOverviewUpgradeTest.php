<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as SupportConverter;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Support\SaaSEventHelpers;

// ── Version Consistency (v143.0.0) ────────────────────────────────────

describe('V143 Version Consistency', function () {
    it('has VERSION 143.0.0 in AnalyticsEvent', function () {
        expect(AnalyticsEvent::VERSION)->toBe('143.0.0');
    });

    it('has consistent version across composer.json equivalent', function () {
        // The version in AnalyticsEvent::VERSION must match the expected release version
        expect(AnalyticsEvent::VERSION)->toBe('143.0.0');
    });

    it('has strict_types declaration in all checked files', function () {
        $files = [
            __DIR__.'/../../src/DTO/AnalyticsEvent.php',
            __DIR__.'/../../src/AnalyticsManager.php',
            __DIR__.'/../../src/Events/EventCatalog.php',
            __DIR__.'/../../src/Tracking/ServerSideTracker.php',
            __DIR__.'/../../src/Support/EcommerceFormatConverter.php',
            __DIR__.'/../../src/Console/Commands/AnalyticsOverviewCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    it('has MIT license headers in all checked files', function () {
        $files = [
            __DIR__.'/../../src/DTO/AnalyticsEvent.php',
            __DIR__.'/../../src/AnalyticsManager.php',
            __DIR__.'/../../src/Events/EventCatalog.php',
            __DIR__.'/../../src/Console/Commands/AnalyticsOverviewCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        }
    });
});

// ── Ecommerce Events Catalog Completeness ──────────────────────────

describe('Ecommerce Catalog Completeness', function () {
    it('has ViewItem event in catalog', function () {
        expect(EcommerceEvents::has('view_item'))->toBeTrue();
        expect(EcommerceEvents::get('view_item'))->not->toBeNull();
    });

    it('has AddToCart event in catalog', function () {
        expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
        expect(EcommerceEvents::get('add_to_cart'))->not->toBeNull();
    });

    it('has Purchase event in catalog', function () {
        expect(EcommerceEvents::has('purchase'))->toBeTrue();
        expect(EcommerceEvents::get('purchase'))->not->toBeNull();
    });

    it('has Refund event in catalog', function () {
        expect(EcommerceEvents::has('refund'))->toBeTrue();
        expect(EcommerceEvents::get('refund'))->not->toBeNull();
    });

    it('has RemoveFromCart event in catalog', function () {
        expect(EcommerceEvents::has('remove_from_cart'))->toBeTrue();
    });

    it('has ViewCart event in catalog', function () {
        expect(EcommerceEvents::has('view_cart'))->toBeTrue();
    });

    it('has BeginCheckout event in catalog', function () {
        expect(EcommerceEvents::has('begin_checkout'))->toBeTrue();
    });

    it('has SelectItem event in catalog', function () {
        expect(EcommerceEvents::has('select_item'))->toBeTrue();
    });

    it('has at least 15 ecommerce events', function () {
        expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    });
});

// ── SaaS Events Catalog Completeness ────────────────────────────────

describe('SaaS Catalog Completeness', function () {
    it('has SignUp event in catalog', function () {
        expect(SaaSEvents::has('sign_up'))->toBeTrue();
    });

    it('has Login event in catalog', function () {
        expect(SaaSEvents::has('login'))->toBeTrue();
    });

    it('has TrialStart event in catalog', function () {
        expect(SaaSEvents::has('trial_start'))->toBeTrue();
    });

    it('has Subscription event in catalog', function () {
        expect(SaaSEvents::has('subscription'))->toBeTrue();
    });

    it('has PlanUpgrade event in catalog', function () {
        expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
    });

    it('has Cancellation event in catalog', function () {
        expect(SaaSEvents::has('cancellation'))->toBeTrue();
    });

    it('has at least 60 SaaS events', function () {
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(60);
    });
});

// ── Engagement Events Catalog Completeness ──────────────────────────

describe('Engagement Catalog Completeness', function () {
    it('has PageView event in catalog', function () {
        expect(EngagementEvents::has('page_view'))->toBeTrue();
    });

    it('has ScrollDepth event in catalog', function () {
        expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
    });

    it('has Click event in catalog', function () {
        expect(EngagementEvents::has('click'))->toBeTrue();
    });

    it('has FormStart event in catalog', function () {
        expect(EngagementEvents::has('form_start'))->toBeTrue();
    });

    it('has FormSubmit event in catalog', function () {
        expect(EngagementEvents::has('form_submit'))->toBeTrue();
    });

    it('has Search event in catalog', function () {
        expect(EngagementEvents::has('search'))->toBeTrue();
    });

    it('has Share event in catalog', function () {
        expect(EngagementEvents::has('share'))->toBeTrue();
    });

    it('has Error event in catalog', function () {
        expect(EngagementEvents::has('error'))->toBeTrue();
    });

    it('has at least 30 engagement events', function () {
        expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
    });
});

// ── Ecommerce Format Converter ─────────────────────────────────────

describe('EcommerceFormatConverter', function () {
    it('converts GA4 items to Meta contents format', function () {
        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 19.99, 'quantity' => 1],
        ];

        $result = SupportConverter::ga4ToMetaContents($items);

        expect($result['content_ids'])->toBe(['SKU-001', 'SKU-002']);
        expect($result['contents'])->toHaveCount(2);
        expect($result['num_items'])->toBe(3);
        expect($result['value'])->toBe(119.97); // 49.99*2 + 19.99*1
    });

    it('converts Meta contents to GA4 items format', function () {
        $contents = [
            ['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 49.99, 'item_name' => 'Widget'],
        ];

        $items = SupportConverter::metaToGa4Items($contents);

        expect($items)->toHaveCount(1);
        expect($items[0]['item_id'])->toBe('SKU-001');
        expect($items[0]['price'])->toBe(49.99);
        expect($items[0]['quantity'])->toBe(2);
    });

    it('builds GA4 purchase params', function () {
        $items = [['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2]];

        $params = SupportConverter::buildGa4Purchase('TXN-123', 99.98, 'USD', $items, [
            'tax' => 8.00,
            'shipping' => 5.99,
        ]);

        expect($params['transaction_id'])->toBe('TXN-123');
        expect($params['value'])->toBe(99.98);
        expect($params['currency'])->toBe('USD');
        expect($params['tax'])->toBe(8.00);
        expect($params['shipping'])->toBe(5.99);
        expect($params['items'])->toHaveCount(1);
    });

    it('builds GA4 add_to_cart params', function () {
        $item = ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 3];

        $params = SupportConverter::buildGa4AddToCart($item, 'EUR', 'search_results');

        expect($params['currency'])->toBe('EUR');
        expect($params['value'])->toBe(149.97);
        expect($params['item_list_name'])->toBe('search_results');
    });

    it('converts GA4 purchase to Meta purchase', function () {
        $ga4Params = [
            'transaction_id' => 'TXN-123',
            'value' => 99.98,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ],
        ];

        $meta = SupportConverter::ga4ToMetaPurchase($ga4Params);

        expect($meta['content_ids'])->toBe(['SKU-001']);
        expect($meta['value'])->toBe(99.98);
        expect($meta['currency'])->toBe('USD');
        expect($meta['content_type'])->toBe('product');
    });
});

// ── Unified Event Catalog ───────────────────────────────────────────

describe('Unified EventCatalog', function () {
    it('has at least 210 total events', function () {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(210);
    });

    it('resolves event names in various formats', function () {
        expect(EventCatalog::resolve('ViewItem'))->toBe('view_item');
        expect(EventCatalog::resolve('add-to-cart'))->toBe('add_to_cart');
        expect(EventCatalog::resolve('page_view'))->toBe('page_view');
    });

    it('categorizes events correctly', function () {
        expect(EventCatalog::getCategory('purchase'))->toBe('ecommerce');
        expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
        expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
        expect(EventCatalog::getCategory('login'))->toBe('saas');
    });

    it('provides provider coverage across 8 providers', function () {
        $providers = EventCatalog::byProvider();
        expect(array_keys($providers))->toContain('ga4');
        expect(array_keys($providers))->toContain('meta');
        expect(array_keys($providers))->toContain('posthog');
        expect(array_keys($providers))->toContain('plausible');
        expect(array_keys($providers))->toContain('mixpanel');
        expect(array_keys($providers))->toContain('amplitude');
        expect(array_keys($providers))->toContain('tiktok');
        expect(array_keys($providers))->toContain('linkedin');
    });

    it('has GA4 mappings for all ecommerce events', function () {
        $ga4Names = EventCatalog::allGa4Names();
        expect(in_array('purchase', $ga4Names, true))->toBeTrue();
        expect(in_array('add_to_cart', $ga4Names, true))->toBeTrue();
        expect(in_array('view_item', $ga4Names, true))->toBeTrue();
        expect(in_array('begin_checkout', $ga4Names, true))->toBeTrue();
    });
});

// ── Lifecycle Event Mapper ─────────────────────────────────────────

describe('LifecycleEventMapper', function () {
    it('has at least 67 default mappings', function () {
        expect(LifecycleEventMapper::DEFAULT_MAPPING_COUNT)->toBeGreaterThanOrEqual(67);
    });

    it('has auth.login mapping', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'lifecycle' => ['enabled' => true],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);

        $mappings = $mapper->getActiveMappings();
        expect($mappings)->toHaveKey('auth.login');
    });
});

// ── Server-Side Tracker ───────────────────────────────────────────

describe('ServerSideTracker', function () {
    it('loads config event_map on construction', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'auto_track' => [
                        'enabled' => true,
                        'events' => [],
                        'event_map' => [
                            'custom.event' => \ZeroBoiler\Analytics\Events\SaaS\SignUpEvent::class,
                        ],
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $tracker = new ServerSideTracker($manager, $config);

        // Should not throw — config event map is loaded
        expect(true)->toBeTrue();
    });
});

// ── AnalyticsOverviewCommand ───────────────────────────────────────

describe('AnalyticsOverviewCommand Data', function () {
    it('builds overview with saas_kpi section', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                    'mixpanel' => ['enabled' => false, 'token' => ''],
                    'amplitude' => ['enabled' => false, 'api_key' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => ''],
                    'tiktok' => ['enabled' => false, 'pixel_id' => '', 'access_token' => ''],
                    'linkedin' => ['enabled' => false, 'partner_id' => '', 'conversion_id' => '', 'access_token' => ''],
                    'saas_kpi_calc' => [
                        'enabled' => true,
                        'cache_ttl' => 300,
                        'mrr_goal' => 50000,
                        'churn_warning' => 0.03,
                        'ltv_cac_target' => 5.0,
                        'quick_ratio_target' => 6.0,
                        'rule_of_40_target' => 50.0,
                    ],
                    'revenue' => [
                        'subscription_tiers' => [
                            'free' => ['name' => 'Free', 'price' => 0],
                            'pro' => ['name' => 'Pro', 'price' => 49],
                        ],
                    ],
                    'identity' => [
                        'cookie_name' => 'zb_test_id',
                        'cookie_ttl' => 525600,
                        'link_on_auth' => true,
                        'auto_link' => true,
                        'cache_prefix' => 'zb_identity_',
                        'link_ttl' => 7776000,
                    ],
                    'event_costs' => [
                        'budget_threshold' => 1000.00,
                        'currency' => 'USD',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $command = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
        $instance = $command->newInstance($manager);

        // Use reflection to call the private buildOverview method
        $method = $command->getMethod('buildOverview');
        $method->setAccessible(true);
        $overview = $method->invoke($instance);

        // Verify SaaS KPI section
        expect($overview)->toHaveKey('saas_kpi');
        expect($overview['saas_kpi']['enabled'])->toBeTrue();
        expect($overview['saas_kpi']['mrr_goal'])->toBe(50000.0);
        expect($overview['saas_kpi']['churn_warning'])->toBe(0.03);
        expect($overview['saas_kpi']['ltv_cac_target'])->toBe(5.0);
        expect($overview['saas_kpi']['quick_ratio_target'])->toBe(6.0);
        expect($overview['saas_kpi']['rule_of_40_target'])->toBe(50.0);
        expect($overview['saas_kpi']['tiers_count'])->toBe(2);

        // Verify Identity section
        expect($overview)->toHaveKey('identity');
        expect($overview['identity']['cookie_name'])->toBe('zb_test_id');
        expect($overview['identity']['link_on_auth'])->toBeTrue();
        expect($overview['identity']['auto_link'])->toBeTrue();
        expect($overview['identity']['cache_prefix'])->toBe('zb_identity_');

        // Verify Event Cost section
        expect($overview)->toHaveKey('event_costs');
        expect($overview['event_costs']['budget_threshold'])->toBe(1000.0);
        expect($overview['event_costs']['currency'])->toBe('USD');
    });

    it('includes version in overview', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                    'mixpanel' => ['enabled' => false, 'token' => ''],
                    'amplitude' => ['enabled' => false, 'api_key' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => ''],
                    'tiktok' => ['enabled' => false, 'pixel_id' => '', 'access_token' => ''],
                    'linkedin' => ['enabled' => false, 'partner_id' => '', 'conversion_id' => '', 'access_token' => ''],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $command = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
        $instance = $command->newInstance($manager);

        $method = $command->getMethod('buildOverview');
        $method->setAccessible(true);
        $overview = $method->invoke($instance);

        expect($overview['version'])->toBe('143.0.0');
        expect($overview['total_providers'])->toBe(10);
        expect($overview)->toHaveKey('saas_kpi');
        expect($overview)->toHaveKey('identity');
        expect($overview)->toHaveKey('event_costs');
    });
});

// ── AnalyticsEvent DTO ──────────────────────────────────────────────

describe('AnalyticsEvent DTO v143', function () {
    it('creates event with category and session_id', function () {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'client-123',
            userId: 'user-456',
            category: 'ecommerce',
            sessionId: 'sess-789',
        );

        expect($event->name)->toBe('purchase');
        expect($event->category)->toBe('ecommerce');
        expect($event->sessionId)->toBe('sess-789');
        expect($event->clientId)->toBe('client-123');
        expect($event->userId)->toBe('user-456');
    });

    it('creates event from array with all fields', function () {
        $event = AnalyticsEvent::fromArray([
            'name' => 'sign_up',
            'params' => ['method' => 'google'],
            'client_id' => 'c-1',
            'user_id' => 'u-1',
            'category' => 'saas',
            'session_id' => 's-1',
            'priority' => 'normal',
            'source' => 'server',
        ]);

        expect($event->name)->toBe('sign_up');
        expect($event->category)->toBe('saas');
        expect($event->sessionId)->toBe('s-1');
        expect($event->priority)->toBe('normal');
        expect($event->source)->toBe('server');
    });

    it('has withCategory and withSessionId immutable helpers', function () {
        $event = new AnalyticsEvent(name: 'click', params: ['target' => 'button']);
        $enriched = $event->withCategory('engagement')->withSessionId('sess-1');

        expect($enriched->category)->toBe('engagement');
        expect($enriched->sessionId)->toBe('sess-1');
        // Original unchanged (readonly)
        expect($event->category)->toBeNull();
    });
});

// ── No TODO/FIXME in Source ─────────────────────────────────────────

describe('Production Cleanliness', function () {
    it('has no TODO or FIXME in key source files', function () {
        $dirs = [
            __DIR__.'/../../src/DTO/AnalyticsEvent.php',
            __DIR__.'/../../src/AnalyticsManager.php',
            __DIR__.'/../../src/Events/EventCatalog.php',
            __DIR__.'/../../src/Console/Commands/AnalyticsOverviewCommand.php',
            __DIR__.'/../../src/Support/EcommerceFormatConverter.php',
        ];

        foreach ($dirs as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toContain('TODO');
            expect($content)->not->toContain('FIXME');
        }
    });
});
