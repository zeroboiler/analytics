<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\SaaSStarterJourneyService;
use ZeroBoiler\Analytics\Services\SaaSStarterValidationService;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * SaaS Starter Journey integration test.
 *
 * Validates the complete SaaS starter analytics pipeline covering:
 * - SaaSStarterJourneyService catalog-validated tracking
 * - All 20 starter events dispatched correctly
 * - Event catalog presence (100% coverage)
 * - E-commerce format conversion (GA4 → Meta)
 * - Facade convenience methods (signUp, login, trialStart, etc.)
 * - Engagement shorthand methods
 * - Journey readiness report
 * - SaaS event helpers (static utility class)
 * - SaaS starter validation service tier scoring
 * - Cross-category catalog integrity
 *
 * @since 249.0.0
 */
describe('SaaS Starter Journey Integration', function () {

    // ── Catalog Coverage ─────────────────────────────────────────

    it('has 100% starter event catalog coverage', function () {
        $presence = SaaSStarterEvents::catalogPresence();
        $missing = SaaSStarterEvents::missingFromCatalog();

        // All 20 starter events must be in the catalog
        expect($missing)->toHaveCount(0);
        expect(SaaSStarterEvents::coveragePercent())->toBe(100.0);

        // Verify each category has events
        $byCategory = SaaSStarterEvents::byCategory();
        expect($byCategory['saas'])->toHaveCount(8); // sign_up, login, start_trial, trial_converted, subscribe, plan_upgrade, cancellation, feature_used
        expect($byCategory['ecommerce'])->toHaveCount(4); // view_item, add_to_cart, purchase, refund
        expect($byCategory['engagement'])->toHaveCount(8); // page_view, scroll_depth, click, form_start, form_submit, search, share, error

        // Total must be 20
        expect(SaaSStarterEvents::count())->toBe(20);
    });

    it('has correct priority order for all 20 events', function () {
        $order = SaaSStarterEvents::priorityOrder();

        expect($order)->toHaveCount(20);
        expect($order[0])->toBe('sign_up');
        expect($order[1])->toBe('login');
        // Last should be error
        expect($order[19])->toBe('error');

        // All unique
        expect(array_unique($order))->toHaveCount(20);
    });

    // ── Catalog Class Validation ─────────────────────────────────

    it('all starter events have a valid class mapping in their respective catalogs', function () {
        $saasCatalog = SaaSEvents::all();
        $ecomCatalog = EcommerceEvents::all();
        $engCatalog = EngagementEvents::all();

        // SaaS events
        foreach (['sign_up', 'login', 'start_trial', 'trial_converted', 'subscribe', 'plan_upgrade', 'cancellation'] as $event) {
            expect(isset($saasCatalog[$event]))->toBeTrue("SaaS catalog missing: {$event}");
            expect($saasCatalog[$event]['class'])->toBeString();
        }

        // E-commerce events
        foreach (['view_item', 'add_to_cart', 'purchase', 'refund'] as $event) {
            expect(isset($ecomCatalog[$event]))->toBeTrue("Ecommerce catalog missing: {$event}");
            expect($ecomCatalog[$event]['class'])->toBeString();
        }

        // Engagement events
        foreach (['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'] as $event) {
            expect(isset($engCatalog[$event]))->toBeTrue("Engagement catalog missing: {$event}");
            expect($engCatalog[$event]['class'])->toBeString();
        }
    });

    // ── E-commerce Format Conversion ─────────────────────────────

    it('converts GA4 items to Meta Pixel contents correctly', function () {
        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 29.99, 'quantity' => 1],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaContents($items);

        expect($result['content_ids'])->toEqual(['SKU-001', 'SKU-002']);
        expect($result['num_items'])->toBe(3);
        expect($result['value'])->toBe(129.97); // (49.99 * 2) + 29.99
        expect(count($result['contents']))->toBe(2);
        expect($result['contents'][0]['id'])->toBe('SKU-001');
        expect($result['contents'][0]['quantity'])->toBe(2);
    });

    // ── SaaSStarterJourneyService ─────────────────────────────────

    it('journey service has correct journey stages', function () {
        expect(SaaSStarterJourneyService::STAGES)->toEqual([
            'acquisition', 'activation', 'revenue', 'retention',
        ]);
    });

    it('journey readiness report shows 100% catalog coverage', function () {
        $manager = app(AnalyticsManager::class);
        $service = new SaaSStarterJourneyService($manager);
        $report = $service->readinessReport();

        expect($report['overall'])->toBe(100.0);
        expect($report['total'])->toBe(20);
        expect($report['present'])->toBe(20);

        // Each stage must be present
        expect(isset($report['stages']['acquisition']))->toBeTrue();
        expect(isset($report['stages']['activation']))->toBeTrue();
        expect(isset($report['stages']['revenue']))->toBeTrue();
        expect(isset($report['stages']['retention']))->toBeTrue();

        // All stages 100%
        foreach ($report['stages'] as $stage => $data) {
            expect($data['missing'])->toHaveCount(0);
            expect($data['score'])->toBe(100.0);
        }
    });

    it('journey service tracks a validated sign_up event', function () {
        Analytics::fake();

        $manager = app(AnalyticsManager::class);
        $service = new SaaSStarterJourneyService($manager);
        $result = $service->signUp(['method' => 'google']);

        expect($result['success'])->toBeTrue();
        expect($result['errors'])->toHaveCount(0);
        expect($result['event'])->not->toBeNull();
        expect($result['event']->name)->toBe('sign_up');
        expect($result['event']->category)->toBe('saas');
        expect($result['event']->params['method'])->toBe('google');

        Analytics::assertTracked('sign_up');
    });

    it('journey service tracks validated engagement events', function () {
        Analytics::fake();

        $manager = app(AnalyticsManager::class);
        $service = new SaaSStarterJourneyService($manager);

        // Page view
        $pv = $service->pageView(['title' => 'Pricing']);
        expect($pv['success'])->toBeTrue();
        expect($pv['event']->name)->toBe('page_view');

        // Click
        $click = $service->click('cta_buy');
        expect($click['success'])->toBeTrue();
        expect($click['event']->params['target'])->toBe('cta_buy');

        // Search
        $search = $service->search('analytics tool');
        expect($search['success'])->toBeTrue();
        expect($search['event']->params['query'])->toBe('analytics tool');

        // Form
        $fs = $service->formStart(['form_id' => 'signup']);
        expect($fs['success'])->toBeTrue();

        $fsub = $service->formSubmit(['form_id' => 'signup', 'success' => true]);
        expect($fsub['success'])->toBeTrue();

        // Scroll depth
        $sd = $service->scrollDepth(75);
        expect($sd['success'])->toBeTrue();
        expect($sd['event']->params['percent'])->toBe(75);

        // Share
        $sh = $service->share('twitter');
        expect($sh['success'])->toBeTrue();

        // Error
        $err = $service->error('Test error');
        expect($err['success'])->toBeTrue();
        expect($err['event']->params['message'])->toBe('Test error');

        Analytics::assertTrackedTimes('page_view', 1);
        Analytics::assertTrackedTimes('click', 1);
        Analytics::assertTrackedTimes('search', 1);
        Analytics::assertTrackedTimes('form_start', 1);
        Analytics::assertTrackedTimes('form_submit', 1);
        Analytics::assertTrackedTimes('scroll_depth', 1);
        Analytics::assertTrackedTimes('share', 1);
        Analytics::assertTrackedTimes('error', 1);
    });

    it('journey service tracks validated revenue events', function () {
        Analytics::fake();

        $manager = app(AnalyticsManager::class);
        $service = new SaaSStarterJourneyService($manager);

        // Subscription
        $sub = $service->subscription(['plan' => 'pro', 'amount' => 49.0]);
        expect($sub['success'])->toBeTrue();
        expect($sub['event']->name)->toBe('subscribe');
        expect($sub['event']->category)->toBe('saas');

        // Plan upgrade
        $upg = $service->planUpgrade('starter', 'pro', ['mrr_delta' => 30.0]);
        expect($upg['success'])->toBeTrue();
        expect($upg['event']->params['from_plan'])->toBe('starter');
        expect($upg['event']->params['to_plan'])->toBe('pro');

        // Purchase
        $pur = $service->purchase(['transaction_id' => 'TX-001', 'value' => 99.99]);
        expect($pur['success'])->toBeTrue();
        expect($pur['event']->name)->toBe('purchase');
        expect($pur['event']->category)->toBe('ecommerce');

        // Refund
        $ref = $service->refund(['transaction_id' => 'TX-001', 'value' => 99.99]);
        expect($ref['success'])->toBeTrue();
        expect($ref['event']->name)->toBe('refund');

        // Trial start
        $trial = $service->trialStart(['plan' => 'pro', 'trial_days' => 30]);
        expect($trial['success'])->toBeTrue();
        expect($trial['event']->params['plan'])->toBe('pro');

        // Cancellation
        $can = $service->cancellation(['plan' => 'pro', 'reason' => 'too_expensive']);
        expect($can['success'])->toBeTrue();
        expect($can['event']->params['reason'])->toBe('too_expensive');

        // Feature used
        $feat = $service->featureUsed('dashboard');
        expect($feat['success'])->toBeTrue();

        Analytics::assertTracked('subscribe');
        Analytics::assertTracked('plan_upgrade');
        Analytics::assertTracked('purchase');
        Analytics::assertTracked('refund');
        Analytics::assertTracked('start_trial');
        Analytics::assertTracked('cancellation');
        Analytics::assertTracked('feature_used');
    });

    it('journey service rejects unknown events', function () {
        $manager = app(AnalyticsManager::class);
        $service = new SaaSStarterJourneyService($manager);

        // Access private method via reflection to test validation directly
        $reflection = new \ReflectionMethod($service, 'trackValidated');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($service, 'nonexistent_event_xyz', [], 'saas');

        expect($result['success'])->toBeFalse();
        expect($result['event'])->toBeNull();
        expect($result['errors'])->not->toBeEmpty();
    });

    // ── SaaS Starter Validation Service ───────────────────────────

    it('validation service has correct tier constants', function () {
        expect(SaaSStarterValidationService::TIER_STARTER)->toBe('starter');
        expect(SaaSStarterValidationService::TIER_GROWTH)->toBe('growth');
        expect(SaaSStarterValidationService::TIER_ADVANCED)->toBe('advanced');
        expect(SaaSStarterValidationService::TIER_ENTERPRISE)->toBe('enterprise');
    });

    it('starter tier events are all in catalog', function () {
        $reflection = new \ReflectionClass(SaaSStarterValidationService::class);
        $prop = $reflection->getProperty('STARTER_EVENTS');
        $prop->setAccessible(true);
        $starterEvents = $prop->getValue(new SaaSStarterValidationService);

        foreach ($starterEvents as $event) {
            expect(EventCatalog::has($event))->toBeTrue("Starter tier event '{$event}' missing from catalog");
        }
    });

    // ── SaaS Event Helpers ───────────────────────────────────────

    it('SaaS event helpers class exists and has required methods', function () {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Support\SaaSEventHelpers::class);

        $expectedMethods = ['signUp', 'login', 'trialStart', 'subscription', 'planUpgrade', 'planDowngrade', 'cancellation', 'featureUsed', 'onboardingStep', 'firstValue', 'revenue', 'custom'];

        foreach ($expectedMethods as $method) {
            expect($reflection->hasMethod($method))->toBeTrue("SaaSEventHelpers missing: {$method}");
            expect($reflection->getMethod($method)->isPublic())->toBeTrue();
            expect($reflection->getMethod($method)->isStatic())->toBeTrue();
        }
    });

    // ── Event Catalog Cross-Category Integrity ────────────────────

    it('all three catalogs have provider mappings', function () {
        // SaaS catalog
        $saas = SaaSEvents::all();
        foreach ($saas as $name => $entry) {
            expect(isset($entry['ga4']))->toBeTrue("SaaS event '{$name}' missing ga4 mapping");
            expect(isset($entry['posthog']))->toBeTrue("SaaS event '{$name}' missing posthog mapping");
            expect(isset($entry['mixpanel']))->toBeTrue("SaaS event '{$name}' missing mixpanel mapping");
            expect(isset($entry['amplitude']))->toBeTrue("SaaS event '{$name}' missing amplitude mapping");
        }

        // Ecommerce catalog
        $ecom = EcommerceEvents::all();
        foreach ($ecom as $name => $entry) {
            expect(isset($entry['ga4']))->toBeTrue("Ecommerce event '{$name}' missing ga4 mapping");
            expect(isset($entry['posthog']))->toBeTrue("Ecommerce event '{$name}' missing posthog mapping");
        }

        // Engagement catalog
        $eng = EngagementEvents::all();
        foreach ($eng as $name => $entry) {
            expect(isset($entry['ga4']))->toBeTrue("Engagement event '{$name}' missing ga4 mapping");
            expect(isset($entry['posthog']))->toBeTrue("Engagement event '{$name}' missing posthog mapping");
        }
    });

    // ── SaaSStarterEvents Client Summary ──────────────────────────

    it('client summary returns correct structure', function () {
        $summary = SaaSStarterEvents::clientSummary();

        expect($summary['total'])->toBe(20);
        expect($summary['coverage'])->toBe(100.0);
        expect(isset($summary['categories']['saas']))->toBeTrue();
        expect(isset($summary['categories']['ecommerce']))->toBeTrue();
        expect(isset($summary['categories']['engagement']))->toBeTrue();
        expect(count($summary['events']))->toBe(20);

        // Each event must have required fields
        foreach ($summary['events'] as $event) {
            expect(isset($event['name']))->toBeTrue();
            expect(isset($event['label']))->toBeTrue();
            expect(isset($event['category']))->toBeTrue();
            expect(isset($event['hint']))->toBeTrue();
            expect(in_array($event['category'], ['saas', 'ecommerce', 'engagement'], true))->toBeTrue();
        }
    });

    // ── Typed Factory Methods (Catalog Shorthands) ────────────────

    it('ecommerce catalog has typed factory methods for all starter events', function () {
        $reflection = new \ReflectionClass(EcommerceEvents::class);

        $starterMethods = ['viewItem', 'addToCart', 'purchase', 'refund'];

        foreach ($starterMethods as $method) {
            expect($reflection->hasMethod($method))->toBeTrue("EcommerceEvents missing: {$method}");
            $m = $reflection->getMethod($method);
            expect($m->isPublic())->toBeTrue();
            expect($m->isStatic())->toBeTrue();

            // Verify return type
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull();
            expect((string) $returnType)->toBe(AnalyticsEvent::class);
        }
    });

    it('typed factory methods produce valid events', function () {
        $item = ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1];

        $viewItem = EcommerceEvents::viewItem($item);
        expect($viewItem)->toBeInstanceOf(AnalyticsEvent::class);
        expect($viewItem->name)->toBe('view_item');
        expect($viewItem->category)->toBe('ecommerce');
        expect($viewItem->params['item_id'])->toBe('SKU-001');

        $addToCart = EcommerceEvents::addToCart(['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1]);
        expect($addToCart->name)->toBe('add_to_cart');
        expect($addToCart->category)->toBe('ecommerce');

        $purchase = EcommerceEvents::purchase(['transaction_id' => 'TX-001', 'value' => 99.99]);
        expect($purchase->name)->toBe('purchase');
        expect($purchase->params['transaction_id'])->toBe('TX-001');

        $refund = EcommerceEvents::refund(['transaction_id' => 'TX-001', 'value' => 99.99]);
        expect($refund->name)->toBe('refund');
        expect($refund->category)->toBe('ecommerce');
    });

    // ── Event Catalog Comprehensive Integrity ─────────────────────

    it('EventCatalog has all 9 categories', function () {
        $categories = EventCatalog::categories();

        $expectedCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success', 'webhook'];

        foreach ($expectedCategories as $cat) {
            expect(in_array($cat, $categories, true))->toBeTrue("Missing category: {$cat}");
        }
    });

    it('EventCatalog returns correct category for starter events', function () {
        expect(EventCatalog::category('sign_up'))->toBe('saas');
        expect(EventCatalog::category('purchase'))->toBe('ecommerce');
        expect(EventCatalog::category('page_view'))->toBe('engagement');
        expect(EventCatalog::category('error'))->toBe('engagement');
        expect(EventCatalog::category('scroll_depth'))->toBe('engagement');
    });
});
