<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\FirstValueEvent;
use ZeroBoiler\Analytics\Events\SaaS\ProductAnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\RetentionRiskEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\UpcomingRenewalEvent;
use ZeroBoiler\Analytics\Services\AnalyticsFeatureFlagService;
use ZeroBoiler\Analytics\Services\AnalyticsJourneyOrchestrator;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

// ── v22.0.0 New Event Classes ───────────────────────────────────

describe('FirstValueEvent', function () {
    it('creates with correct name and critical priority', function () {
        $event = new FirstValueEvent(
            action: 'first_report_generated',
            clientId: 'client-123',
            userId: 'user-456',
            timeToValue: 3600,
        );

        expect($event->name)->toBe('first_value');
        expect($event->priority)->toBe('critical');
        expect($event->source)->toBe('server');
        expect($event->clientId)->toBe('client-123');
        expect($event->userId)->toBe('user-456');
    });

    it('includes action and time_to_value in params', function () {
        $event = new FirstValueEvent(
            action: 'first_api_call',
            timeToValue: 7200,
        );

        expect($event->params['action'])->toBe('first_api_call');
        expect($event->params['time_to_value'])->toBe(7200);
    });

    it('merges additional params', function () {
        $event = new FirstValueEvent(
            action: 'first_collaboration',
            params: ['team_size' => 5, 'workspace' => 'acme'],
        );

        expect($event->params['team_size'])->toBe(5);
        expect($event->params['workspace'])->toBe('acme');
        expect($event->params['action'])->toBe('first_collaboration');
    });

    it('is in the SaaS catalog', function () {
        expect(SaaSEvents::has('first_value'))->toBeTrue();
        expect(SaaSEvents::classFor('first_value'))->toBe(FirstValueEvent::class);
    });

    it('has correct provider mappings in catalog', function () {
        $entry = SaaSEvents::get('first_value');
        expect($entry['ga4'])->toBe('first_value');
        expect($entry['meta'])->toBe('CompleteRegistration');
        expect($entry['posthog'])->toBe('$set');
        expect($entry['plausible'])->toBe('activation');
    });
});

describe('UpcomingRenewalEvent', function () {
    it('creates with correct name and normal priority', function () {
        $event = new UpcomingRenewalEvent(
            planName: 'Pro',
            amount: 49.99,
            daysUntilRenewal: 7,
        );

        expect($event->name)->toBe('upcoming_renewal');
        expect($event->priority)->toBe('normal');
        expect($event->planName)->toBe('Pro');
        expect($event->amount)->toBe(49.99);
        expect($event->daysUntilRenewal)->toBe(7);
    });

    it('includes plan and renewal details in params', function () {
        $event = new UpcomingRenewalEvent(
            planName: 'Enterprise',
            amount: 199.00,
            daysUntilRenewal: 14,
            params: ['billing_cycle' => 'monthly'],
        );

        expect($event->params['plan_name'])->toBe('Enterprise');
        expect($event->params['amount'])->toBe(199.0);
        expect($event->params['days_until_renewal'])->toBe(14);
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['billing_cycle'])->toBe('monthly');
    });

    it('is in the SaaS catalog', function () {
        expect(SaaSEvents::has('upcoming_renewal'))->toBeTrue();
        expect(SaaSEvents::classFor('upcoming_renewal'))->toBe(UpcomingRenewalEvent::class);
    });
});

describe('RetentionRiskEvent', function () {
    it('creates with medium risk by default', function () {
        $event = new RetentionRiskEvent(
            riskLevel: 'medium',
            signals: ['login_decline_50pct', 'feature_usage_drop'],
            riskScore: 0.65,
        );

        expect($event->name)->toBe('retention_risk');
        expect($event->priority)->toBe('normal');
        expect($event->riskLevel)->toBe('medium');
        expect($event->riskScore)->toBe(0.65);
    });

    it('escalates priority for critical risk', function () {
        $event = new RetentionRiskEvent(
            riskLevel: 'critical',
            signals: ['no_login_30d', 'support_tickets_3plus'],
            riskScore: 0.95,
        );

        expect($event->priority)->toBe('critical');
    });

    it('includes signal metadata in params', function () {
        $event = new RetentionRiskEvent(
            riskLevel: 'high',
            signals: ['usage_decline'],
            riskScore: 0.8,
        );

        expect($event->params['risk_level'])->toBe('high');
        expect($event->params['risk_score'])->toBe(0.8);
        expect($event->params['signal_count'])->toBe(1);
        expect($event->params['signals'])->toBe(['usage_decline']);
    });

    it('is in the SaaS catalog', function () {
        expect(SaaSEvents::has('retention_risk'))->toBeTrue();
        expect(SaaSEvents::classFor('retention_risk'))->toBe(RetentionRiskEvent::class);
    });
});

describe('ProductAnalyticsEvent', function () {
    it('creates with structured category/action/object', function () {
        $event = new ProductAnalyticsEvent(
            category: 'report',
            action: 'create',
            objectName: 'monthly_summary',
        );

        expect($event->name)->toBe('product_analytics');
        expect($event->priority)->toBe('normal');
        expect($event->category)->toBe('report');
        expect($event->action)->toBe('create');
        expect($event->objectName)->toBe('monthly_summary');
    });

    it('builds full event name from components', function () {
        $event = new ProductAnalyticsEvent(
            category: 'api',
            action: 'call',
            objectName: 'webhook',
        );

        expect($event->params['event_full_name'])->toBe('api.call.webhook');
        expect($event->params['product_category'])->toBe('api');
        expect($event->params['product_action'])->toBe('call');
        expect($event->params['product_object'])->toBe('webhook');
    });

    it('is in the SaaS catalog', function () {
        expect(SaaSEvents::has('product_analytics'))->toBeTrue();
        expect(SaaSEvents::classFor('product_analytics'))->toBe(ProductAnalyticsEvent::class);
    });
});

// ── Event Catalog Expansion ──────────────────────────────────────

describe('SaaS Event Catalog v22.0.0', function () {
    it('has 4 new events added in v22', function () {
        expect(SaaSEvents::has('first_value'))->toBeTrue();
        expect(SaaSEvents::has('upcoming_renewal'))->toBeTrue();
        expect(SaaSEvents::has('retention_risk'))->toBeTrue();
        expect(SaaSEvents::has('product_analytics'))->toBeTrue();
    });

    it('all new events appear in unified catalog', function () {
        $catalog = EventCatalog::all();

        expect(isset($catalog['first_value']))->toBeTrue();
        expect(isset($catalog['upcoming_renewal']))->toBeTrue();
        expect(isset($catalog['retention_risk']))->toBeTrue();
        expect(isset($catalog['product_analytics']))->toBeTrue();
    });

    it('new events have correct category in unified catalog', function () {
        expect(EventCatalog::getCategory('first_value'))->toBe('saas');
        expect(EventCatalog::getCategory('upcoming_renewal'))->toBe('saas');
        expect(EventCatalog::getCategory('retention_risk'))->toBe('saas');
        expect(EventCatalog::getCategory('product_analytics'))->toBe('saas');
    });

    it('catalog validates without errors', function () {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });
});

// ── Feature Flag Analytics Service ──────────────────────────────

describe('AnalyticsFeatureFlagService', function () {
    it('registers and evaluates feature flags', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsFeatureFlagService($manager, $config, $cache);

        $service->registerFlag('new_dashboard', [
            'enabled' => true,
            'variants' => ['control', 'variant_a'],
            'default_variant' => 'control',
            'category' => 'ui',
        ]);

        expect($service->isEnabled('new_dashboard'))->toBeTrue();
        expect($service->getFlags())->toHaveKey('new_dashboard');
    });

    it('returns default variant for disabled flags', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsFeatureFlagService($manager, $config, $cache);

        $service->registerFlag('disabled_feature', [
            'enabled' => false,
            'variants' => ['control', 'variant'],
        ]);

        $variant = $service->evaluate('disabled_feature', 'user-1');

        expect($variant)->toBe('control');
    });

    it('returns control for unregistered flags', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsFeatureFlagService($manager, $config, $cache);

        $variant = $service->evaluate('nonexistent_flag', 'user-1');

        expect($variant)->toBe('control');
    });

    it('provides adoption stats', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsFeatureFlagService($manager, $config, $cache);

        $stats = $service->getAdoptionStats('any_flag');

        expect($stats['total'])->toBe(0);
        expect($stats['variants'])->toBeEmpty();
    });
});

// ── Journey Orchestration Service ────────────────────────────────

describe('AnalyticsJourneyOrchestrator', function () {
    it('advances users through journey stages', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['visitor', 'signed_up', 'activated', 'retained'],
                        'cache_prefix' => 'zb_journey_test_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        $result = $service->advanceTo('user-1', 'signed_up');

        expect($result['advanced'])->toBeTrue();
        expect($result['to'])->toBe('signed_up');
        expect($result['stage_index'])->toBe(1);
    });

    it('does not regress to earlier stages', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['visitor', 'signed_up', 'activated', 'retained'],
                        'cache_prefix' => 'zb_journey_noreg_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        $service->advanceTo('user-2', 'activated');
        $result = $service->advanceTo('user-2', 'visitor');

        expect($result['advanced'])->toBeFalse();
    });

    it('rejects invalid stage names', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['visitor', 'signed_up', 'activated'],
                        'cache_prefix' => 'zb_journey_inv_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        $result = $service->advanceTo('user-3', 'nonexistent');

        expect($result['advanced'])->toBeFalse();
        expect($result['stage_index'])->toBe(-1);
    });

    it('tracks sequential stage transitions', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['visitor', 'signed_up', 'activated', 'retained', 'champion'],
                        'cache_prefix' => 'zb_journey_seq_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        $r1 = $service->advanceTo('user-4', 'signed_up');
        expect($r1['advanced'])->toBeTrue();

        $r2 = $service->advanceTo('user-4', 'activated');
        expect($r2['advanced'])->toBeTrue();
        expect($r2['from'])->toBe('signed_up');

        $r3 = $service->advanceTo('user-4', 'retained');
        expect($r3['advanced'])->toBeTrue();
        expect($r3['to'])->toBe('retained');

        $state = $service->getState('user-4');
        expect($state['current_stage'])->toBe('retained');
        expect($state['transition_count'])->toBe(3);
    });

    it('provides correct stage list', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['a', 'b', 'c'],
                        'cache_prefix' => 'zb_journey_list_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        expect($service->getStages())->toBe(['a', 'b', 'c']);
    });

    it('resets user state correctly', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'journey' => [
                        'stages' => ['visitor', 'signed_up', 'activated'],
                        'cache_prefix' => 'zb_journey_reset_',
                        'cache_ttl' => 3600,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $cache = app('cache');
        $service = new AnalyticsJourneyOrchestrator($manager, $config, $cache);

        $service->advanceTo('user-5', 'activated');
        $service->resetState('user-5');

        $state = $service->getState('user-5');
        expect($state['current_stage'])->toBeNull();
        expect($state['stage_index'])->toBe(-1);
    });
});

// ── Version Sweep ─────────────────────────────────────────────────

describe('Version Sweep v22.0.0', function () {
    it('AnalyticsEvent VERSION is 22.0.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('22.0.0');
    });

    it('event catalog has all categories', function () {
        $categories = EventCatalog::byCategory();

        expect($categories)->toHaveKey('ecommerce');
        expect($categories)->toHaveKey('saas');
        expect($categories)->toHaveKey('engagement');
        expect($categories)->toHaveKey('security');
        expect($categories)->toHaveKey('uptime');
    });

    it('total catalog count is positive and consistent', function () {
        $all = EventCatalog::all();
        $count = EventCatalog::count();
        $names = EventCatalog::names();

        expect($count)->toBeGreaterThan(0);
        expect(count($all))->toBe($count);
        expect(count($names))->toBe($count);
    });

    it('all catalog events have required keys', function () {
        $required = EventCatalog::requiredKeys();

        foreach (EventCatalog::all() as $name => $entry) {
            foreach ($required as $key) {
                expect(array_key_exists($key, $entry))
                    ->toBeTrue("Event '{$name}' missing key '{$key}'");
            }
        }
    });
});
