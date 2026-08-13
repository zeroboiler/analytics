<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\UtmAttribution;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;

// ── FunnelAnalyticsService Tests ────────────────────────────────────

describe('v2.2 — FunnelAnalyticsService', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        expect($service)->toBeInstanceOf(FunnelAnalyticsService::class);
    });

    it('starts a funnel and tracks step', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        $service->startFunnel('signup', ['source' => 'landing_page']);
        $service->trackStep('signup', 'form_start', 1);
        $service->trackStep('signup', 'email_confirmed', 2);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(3); // funnel_started + 2 steps
        expect($layer[0]['event'])->toBe('funnel_started');
        expect($layer[0]['funnel_name'])->toBe('signup');
        expect($layer[1]['event'])->toBe('funnel_step');
        expect($layer[1]['funnel_step_name'])->toBe('form_start');
        expect($layer[2]['funnel_step_number'])->toBe(2);
    });

    it('completes a funnel', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        $service->startFunnel('purchase', ['plan' => 'pro']);
        $service->trackStep('purchase', 'add_to_cart', 1);
        $service->trackStep('purchase', 'checkout', 2);
        $service->trackStep('purchase', 'payment', 3);
        $service->complete('purchase', 3, ['value' => 99.99]);

        $layer = $manager->gtm()->getDataLayer();
        $completeEvent = array_filter($layer, fn (array $e) => $e['event'] === 'funnel_completed');
        expect($completeEvent)->not->toBeEmpty();
        $event = array_values($completeEvent)[0];
        expect($event['funnel_total_steps'])->toBe(3);
        expect($event['funnel_steps_completed'])->toBe(3);
        expect($event['funnel_skipped_steps'])->toBe(0);
    });

    it('tracks funnel abandonment', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        $service->startFunnel('onboarding', []);
        $service->trackStep('onboarding', 'profile_setup', 1);
        $service->abandon('onboarding', 'profile_setup', 5);

        $layer = $manager->gtm()->getDataLayer();
        $abandonEvent = array_filter($layer, fn (array $e) => $e['event'] === 'funnel_abandoned');
        expect($abandonEvent)->not->toBeEmpty();
        $event = array_values($abandonEvent)[0];
        expect($event['funnel_abandoned_at'])->toBe('profile_setup');
        expect($event['funnel_total_steps'])->toBe(5);
        expect($event['funnel_completion_rate'])->toBe(20.0);
    });

    it('tracks funnel retry', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        $service->retry('signup', 2, ['previous_step' => 'email_confirmed']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('funnel_retry');
        expect($layer[0]['funnel_attempt_number'])->toBe(2);
    });

    it('isActive returns correct state', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        expect($service->isActive('signup'))->toBeFalse();

        $service->startFunnel('signup');
        expect($service->isActive('signup'))->toBeTrue();

        $service->complete('signup', 3);
        expect($service->isActive('signup'))->toBeFalse();
    });

    it('getCurrentStep returns current step name', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        expect($service->getCurrentStep('checkout'))->toBeNull();

        $service->startFunnel('checkout');
        $service->trackStep('checkout', 'shipping_info', 1);

        expect($service->getCurrentStep('checkout'))->toBe('shipping_info');
    });

    it('getActiveFunnels returns list of active funnel names', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        $service->startFunnel('signup');
        $service->startFunnel('purchase');

        $active = $service->getActiveFunnels();
        expect($active)->toContain('signup');
        expect($active)->toContain('purchase');
        expect($active)->toHaveCount(2);
    });

    it('returns the underlying manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new FunnelAnalyticsService($manager, $queue, $config);

        expect($service->getManager())->toBe($manager);
    });
});

// ── RevenueAttributionService Tests ─────────────────────────────────

describe('v2.2 — RevenueAttributionService', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        expect($service)->toBeInstanceOf(RevenueAttributionService::class);
    });

    it('tracks revenue with attribution', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        $attribution = new UtmAttribution(
            source: 'google',
            medium: 'cpc',
            campaign: 'spring_2026',
        );

        $service->trackRevenue('rev-001', 99.99, ['plan' => 'pro'], $attribution, 'user-42');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('revenue_tracked');
        expect($layer[0]['revenue_amount'])->toBe(99.99);
        expect($layer[0]['currency'])->toBe('USD');
        expect($layer[0]['utm_source'])->toBe('google');
        expect($layer[0]['utm_medium'])->toBe('cpc');
    });

    it('tracks MRR change', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'EUR', false);

        $service->trackMrrChange('user-42', 49.99, 29.99, 'pro', 'upgrade');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('mrr_change');
        expect($layer[0]['mrr_amount'])->toBe(49.99);
        expect($layer[0]['mrr_previous'])->toBe(29.99);
        expect($layer[0]['mrr_delta'])->toBe(20.0);
        expect($layer[0]['change_type'])->toBe('upgrade');
        expect($layer[0]['currency'])->toBe('EUR');
    });

    it('tracks LTV estimate', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        $service->trackLtv('user-42', 599.99, 299.99, 180);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('ltv_update');
        expect($layer[0]['ltv'])->toBe(599.99);
        expect($layer[0]['total_revenue'])->toBe(299.99);
        expect($layer[0]['days_active'])->toBe(180);
        expect($layer[0]['avg_daily_revenue'])->toBe(1.67);
    });

    it('tracks cohort revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        $service->trackCohortRevenue('2026-01', 15000.00, 250);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('cohort_revenue');
        expect($layer[0]['cohort_revenue'])->toBe(15000.00);
        expect($layer[0]['cohort_customers'])->toBe(250);
        expect($layer[0]['cohort_arpu'])->toBe(60.0);
    });

    it('tracks revenue breakdown', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        $service->trackRevenueBreakdown('stripe', 'organic', 5000.00, 50);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('revenue_breakdown');
        expect($layer[0]['revenue_source'])->toBe('stripe');
        expect($layer[0]['revenue_channel'])->toBe('organic');
        expect($layer[0]['avg_transaction_value'])->toBe(100.0);
    });

    it('gets and sets default currency', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        expect($service->getDefaultCurrency())->toBe('USD');

        $service->setDefaultCurrency('EUR');
        expect($service->getDefaultCurrency())->toBe('EUR');
    });

    it('returns the underlying manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $service = new RevenueAttributionService($manager, $queue, 'USD', false);

        expect($service->getManager())->toBe($manager);
    });
});

// ── AnalyticsDataBus Tests ─────────────────────────────────────────

describe('v2.2 — AnalyticsDataBus', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        expect($bus)->toBeInstanceOf(AnalyticsDataBus::class);
    });

    it('routes events to all providers by default', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        $event = new AnalyticsEvent(name: 'page_view');
        $bus->route($event);

        // Should dispatch to GA4 (via standard path since no rules)
        $ga4Calls = $manager->ga4()->getCalls();
        expect($ga4Calls)->not->toBeEmpty();
    });

    it('routes events by name pattern', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        // Only route purchase events to GA4
        $bus->routeByPattern('purchase*', ['ga4']);

        $purchaseEvent = new AnalyticsEvent(name: 'purchase');
        $bus->route($purchaseEvent);

        // Non-matching event goes to standard dispatch (GTM via trackEvent)
        $otherEvent = new AnalyticsEvent(name: 'page_view');
        $bus->route($otherEvent);

        $layer = $manager->gtm()->getDataLayer();
        // page_view should appear in GTM (no rule matched, standard dispatch)
        $pageViews = array_filter($layer, fn (array $e) => ($e['event'] ?? '') === 'page_view');
        expect($pageViews)->not->toBeEmpty();
    });

    it('routes events by category', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        // Route ecommerce events only to GA4
        $bus->routeByCategory('ecommerce', ['ga4']);

        $purchaseEvent = new AnalyticsEvent(name: 'purchase');
        $bus->route($purchaseEvent);

        // purchase is an ecommerce event — should be routed to GA4 only
        $ga4Calls = $manager->ga4()->getCalls();
        expect($ga4Calls)->not->toBeEmpty();
    });

    it('routes events by param value', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        // Route events with method=github only to GTM
        $bus->routeByParam('method', 'github', ['gtm']);

        $githubEvent = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'github']);
        $bus->route($githubEvent);

        // Should have been routed specifically to GTM
        $layer = $manager->gtm()->getDataLayer();
        $signUpEvents = array_filter($layer, fn (array $e) => ($e['event'] ?? '') === 'sign_up' && ($e['method'] ?? '') === 'github');
        expect($signUpEvents)->not->toBeEmpty();
    });

    it('routeTo sends event to specific providers', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        $event = new AnalyticsEvent(name: 'test_event');
        $bus->routeTo($event, ['gtm']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('test_event');
    });

    it('routeExcept excludes specified providers', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        $layerBefore = count($manager->gtm()->getDataLayer());

        // Route to all except GTM — should go to meta, plausible, posthog (all disabled)
        // So effectively the event goes nowhere visible
        $event = new AnalyticsEvent(name: 'filtered_event');
        $bus->routeExcept($event, ['gtm']);

        $layerAfter = count($manager->gtm()->getDataLayer());
        expect($layerAfter)->toBe($layerBefore); // GTM should not have received it
    });

    it('clearRules removes all routing rules', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        $bus->routeByPattern('purchase*', ['ga4']);
        $bus->routeByCategory('saas', ['gtm']);
        expect($bus->getRules())->toHaveCount(2);

        $bus->clearRules();
        expect($bus->getRules())->toBeEmpty();
    });

    it('getDefaultProviders returns all provider names', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $bus = new AnalyticsDataBus($manager, $queue, false);

        $providers = $bus->getDefaultProviders();
        expect($providers)->toContain('ga4');
        expect($providers)->toContain('gtm');
        expect($providers)->toContain('meta');
        expect($providers)->toContain('plausible');
        expect($providers)->toContain('posthog');
    });
});

// ── UtmAttribution Tests ────────────────────────────────────────────

describe('v2.2 — UtmAttribution', function () {
    it('creates from request params', function () {
        $utm = UtmAttribution::fromRequest([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
            'utm_term' => 'analytics+tools',
            'utm_content' => 'banner_ad',
        ], true, 'https://referrer.com', '/pricing');

        expect($utm->source)->toBe('google');
        expect($utm->medium)->toBe('cpc');
        expect($utm->campaign)->toBe('spring_sale');
        expect($utm->term)->toBe('analytics+tools');
        expect($utm->content)->toBe('banner_ad');
        expect($utm->firstTouch)->toBeTrue();
        expect($utm->referrer)->toBe('https://referrer.com');
        expect($utm->landingPage)->toBe('/pricing');
        expect($utm->timestamp)->not->toBeNull();
    });

    it('handles empty params', function () {
        $utm = UtmAttribution::fromRequest([]);

        expect($utm->source)->toBeNull();
        expect($utm->medium)->toBeNull();
        expect($utm->hasAttribution())->toBeFalse();
    });

    it('hasAttribution returns true when any UTM is present', function () {
        $utm = new UtmAttribution(source: 'newsletter');

        expect($utm->hasAttribution())->toBeTrue();
    });

    it('toArray filters null and false values', function () {
        $utm = new UtmAttribution(source: 'google', medium: 'cpc', campaign: null);

        $array = $utm->toArray();
        expect($array)->toHaveKey('utm_source');
        expect($array)->toHaveKey('utm_medium');
        expect($array)->not->toHaveKey('utm_campaign');
        expect($array)->not->toHaveKey('utm_first_touch');
    });

    it('serializes and deserializes', function () {
        $original = new UtmAttribution(
            source: 'google',
            medium: 'cpc',
            campaign: 'spring_2026',
            firstTouch: true,
        );

        $serialized = $original->toString();
        $restored = UtmAttribution::fromString($serialized);

        expect($restored->source)->toBe('google');
        expect($restored->medium)->toBe('cpc');
        expect($restored->campaign)->toBe('spring_2026');
        expect($restored->firstTouch)->toBeTrue();
    });

    it('fromString handles invalid JSON gracefully', function () {
        $restored = UtmAttribution::fromString('invalid-json');

        expect($restored->source)->toBeNull();
        expect($restored->hasAttribution())->toBeFalse();
    });

    it('describe returns human-readable string', function () {
        $utm = new UtmAttribution(source: 'google', medium: 'cpc', campaign: 'spring_sale');
        expect($utm->describe())->toBe('google/cpc (spring_sale)');

        $empty = new UtmAttribution;
        expect($empty->describe())->toBe('direct / none');

        $sourceOnly = new UtmAttribution(source: 'newsletter');
        expect($sourceOnly->describe())->toBe('newsletter');
    });
});

// ── AnalyticsManager v2.2 Methods ──────────────────────────────────

describe('v2.2 — AnalyticsManager new methods', function () {
    it('eventExists checks catalog', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->eventExists('purchase'))->toBeTrue();
        expect($manager->eventExists('sign_up'))->toBeTrue();
        expect($manager->eventExists('page_view'))->toBeTrue();
        expect($manager->eventExists('nonexistent_event'))->toBeFalse();
    });

    it('eventCategory returns correct category', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->eventCategory('purchase'))->toBe('ecommerce');
        expect($manager->eventCategory('sign_up'))->toBe('saas');
        expect($manager->eventCategory('page_view'))->toBe('engagement');
        expect($manager->eventCategory('unknown_event'))->toBeNull();
    });

    it('totalEventCount returns correct count', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        // 8 ecommerce + 12 saas + 14 engagement = 34
        expect($manager->totalEventCount())->toBeGreaterThanOrEqual(30);
    });

    it('version returns 2.2.0', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->version())->toBe('76.0.0');
    });

    it('providerSummary returns enabled state for all providers', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => false],
                    'meta_pixel' => ['enabled' => true, 'id' => '12345', 'access_token' => 'token'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->providerSummary();

        expect($summary)->toHaveKey('ga4');
        expect($summary)->toHaveKey('gtm');
        expect($summary)->toHaveKey('meta');
        expect($summary)->toHaveKey('plausible');
        expect($summary)->toHaveKey('posthog');
        expect($summary['ga4']['enabled'])->toBeTrue();
        expect($summary['ga4']['id'])->toBe('G-TEST');
        expect($summary['gtm']['enabled'])->toBeFalse();
        expect($summary['meta']['enabled'])->toBeTrue();
    });
});
