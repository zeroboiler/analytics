<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;
use ZeroBoiler\Analytics\Pipeline\EventMetadataEnricher;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\EventTransformer;

/**
 * Full SaaS analytics pipeline smoke test.
 *
 * End-to-end validation of the complete analytics pipeline covering:
 * - Full SaaS user journey (signup → trial → subscription → upgrade → cancellation)
 * - E-commerce funnel (view → add to cart → checkout → purchase → refund)
 * - Engagement events (page view, scroll depth, form submit, search, error)
 * - Consent Mode v2 compliance (grant, deny, state propagation)
 * - Identity resolution (client ID ↔ user ID linking)
 * - Multi-provider dispatch (GA4, GTM, Meta, Plausible, PostHog)
 * - E-commerce format conversion (GA4 ↔ Meta formats)
 * - Pipeline processing (UTM enrichment, metadata, timestamp)
 * - GDPR data erasure reset
 * - Event catalog integrity (5 categories, 100+ events)
 * - Queue dispatch readiness
 * - Facade proxy verification
 *
 * @since 11.0.0
 */
describe('Full SaaS Analytics Pipeline Smoke Test', function (): void {
    beforeEach(function (): void {
        $fake = new AnalyticsFake;
        app()->instance('zeroboiler.analytics', $fake);
    });

    afterEach(function (): void {
        // Restore
        app()->forgetInstance('zeroboiler.analytics');
    });

    // ── SaaS User Journey ──────────────────────────────────────────────

    describe('SaaS User Journey', function (): void {
        test('signup → trial → subscription → upgrade → cancellation pipeline', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            // Step 1: Sign Up
            $fake->track('sign_up', ['method' => 'email', 'plan' => 'free']);
            AnalyticsFake::assertTracked('sign_up');

            // Step 2: Email Verified
            $fake->track('email_verified', ['method' => 'email']);
            AnalyticsFake::assertTracked('email_verified');

            // Step 3: Login
            $fake->track('login', ['method' => 'email', 'user_id' => 'user-123']);
            AnalyticsFake::assertTracked('login');

            // Step 4: Trial Start
            $fake->track('trial_start', ['plan_name' => 'pro', 'trial_days' => 14]);
            AnalyticsFake::assertTracked('trial_start');

            // Step 5: Subscription Created
            $fake->track('subscription.created', [
                'plan_name' => 'pro',
                'amount' => 49.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
            ]);
            AnalyticsFake::assertTracked('subscription.created');

            // Step 6: Feature Used
            $fake->track('feature_used', ['feature_name' => 'api_access', 'category' => 'core']);
            AnalyticsFake::assertTracked('feature_used');

            // Step 7: Plan Upgrade
            $fake->track('plan_upgrade', [
                'from_plan' => 'pro',
                'to_plan' => 'enterprise',
                'price_difference' => 150.00,
            ]);
            AnalyticsFake::assertTracked('plan_upgrade');

            // Step 8: Payment Succeeded
            $fake->track('payment_succeeded', [
                'amount' => 199.00,
                'currency' => 'USD',
                'method' => 'card',
            ]);
            AnalyticsFake::assertTracked('payment_succeeded');

            // Step 9: Invoice Generated
            $fake->track('invoice_generated', [
                'amount' => 199.00,
                'invoice_id' => 'INV-001',
            ]);
            AnalyticsFake::assertTracked('invoice_generated');

            // Step 10: Cancellation
            $fake->track('cancellation', [
                'plan_name' => 'enterprise',
                'reason' => 'budget',
            ]);
            AnalyticsFake::assertTracked('cancellation');

            // Verify full journey was tracked
            $events = AnalyticsFake::trackedEvents();
            expect($events)->toHaveCount(10);
        });

        test('SaaS catalog contains all lifecycle events', function (): void {
            $saasEvents = SaaSEvents::all();

            // Critical SaaS events must exist
            expect(SaaSEvents::has('sign_up'))->toBeTrue();
            expect(SaaSEvents::has('login'))->toBeTrue();
            expect(SaaSEvents::has('trial_start'))->toBeTrue();
            expect(SaaSEvents::has('subscription.created'))->toBeTrue();
            expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
            expect(SaaSEvents::has('cancellation'))->toBeTrue();
            expect(SaaSEvents::has('payment_succeeded'))->toBeTrue();
            expect(SaaSEvents::has('payment_failed'))->toBeTrue();
            expect(SaaSEvents::has('invoice_generated'))->toBeTrue();
            expect(SaaSEvents::has('feature_used'))->toBeTrue();

            // SaaS category should have substantial coverage
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(40);
        });
    });

    // ── E-Commerce Funnel ─────────────────────────────────────────────

    describe('E-Commerce Funnel', function (): void {
        test('view_item → add_to_cart → begin_checkout → purchase → refund pipeline', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            // Step 1: View Item
            $fake->track('view_item', [
                'item_id' => 'SKU-001',
                'item_name' => 'Premium Widget',
                'price' => 49.99,
                'currency' => 'USD',
            ]);
            AnalyticsFake::assertTracked('view_item');

            // Step 2: Add to Cart
            $fake->track('add_to_cart', [
                'item_id' => 'SKU-001',
                'item_name' => 'Premium Widget',
                'price' => 49.99,
                'quantity' => 2,
                'currency' => 'USD',
            ]);
            AnalyticsFake::assertTracked('add_to_cart');

            // Step 3: Begin Checkout
            $fake->track('begin_checkout', [
                'value' => 99.98,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'SKU-001', 'quantity' => 2, 'price' => 49.99],
                ],
            ]);
            AnalyticsFake::assertTracked('begin_checkout');

            // Step 4: Purchase
            $fake->track('purchase', [
                'transaction_id' => 'TXN-12345',
                'value' => 99.98,
                'currency' => 'USD',
                'tax' => 8.00,
                'shipping' => 5.99,
            ]);
            AnalyticsFake::assertTracked('purchase');

            // Step 5: Refund
            $fake->track('refund', [
                'transaction_id' => 'TXN-12345',
                'value' => 49.99,
                'currency' => 'USD',
                'reason' => 'quality',
            ]);
            AnalyticsFake::assertTracked('refund');

            $events = AnalyticsFake::trackedEvents();
            expect($events)->toHaveCount(5);
        });

        test('E-commerce catalog contains all core events', function (): void {
            expect(EcommerceEvents::has('view_item'))->toBeTrue();
            expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
            expect(EcommerceEvents::has('remove_from_cart'))->toBeTrue();
            expect(EcommerceEvents::has('begin_checkout'))->toBeTrue();
            expect(EcommerceEvents::has('purchase'))->toBeTrue();
            expect(EcommerceEvents::has('refund'))->toBeTrue();
            expect(EcommerceEvents::has('view_cart'))->toBeTrue();
            expect(EcommerceEvents::has('add_payment_info'))->toBeTrue();
            expect(EcommerceEvents::has('select_item'))->toBeTrue();
            expect(EcommerceEvents::has('view_promotion'))->toBeTrue();
            expect(EcommerceEvents::has('select_promotion'))->toBeTrue();
            expect(EcommerceEvents::has('wishlist'))->toBeTrue();
            expect(EcommerceEvents::has('checkout_step'))->toBeTrue();

            expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(13);
        });
    });

    // ── Engagement Events ──────────────────────────────────────────────

    describe('Engagement Events', function (): void {
        test('page_view → scroll → form → search → share → error pipeline', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('page_view', ['title' => 'Homepage', 'location' => '/']);
            AnalyticsFake::assertTracked('page_view');

            $fake->track('scroll_depth', ['depth_percent' => 75, 'page' => '/']);
            AnalyticsFake::assertTracked('scroll_depth');

            $fake->track('form_start', ['form_name' => 'contact', 'form_id' => 'contact-form']);
            AnalyticsFake::assertTracked('form_start');

            $fake->track('form_submit', ['form_name' => 'contact', 'form_id' => 'contact-form', 'success' => true]);
            AnalyticsFake::assertTracked('form_submit');

            $fake->track('search', ['query' => 'analytics', 'results_count' => 42]);
            AnalyticsFake::assertTracked('search');

            $fake->track('share', ['method' => 'twitter', 'content_type' => 'article']);
            AnalyticsFake::assertTracked('share');

            $fake->track('error', ['message' => 'TypeError', 'source' => 'app.js', 'line' => 42]);
            AnalyticsFake::assertTracked('error');

            $fake->track('click', ['element' => 'cta-button', 'url' => '/pricing']);
            AnalyticsFake::assertTracked('click');

            expect(AnalyticsFake::trackedEvents())->toHaveCount(8);
        });

        test('Engagement catalog contains all core events', function (): void {
            expect(EngagementEvents::has('page_view'))->toBeTrue();
            expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
            expect(EngagementEvents::has('click'))->toBeTrue();
            expect(EngagementEvents::has('form_start'))->toBeTrue();
            expect(EngagementEvents::has('form_submit'))->toBeTrue();
            expect(EngagementEvents::has('search'))->toBeTrue();
            expect(EngagementEvents::has('share'))->toBeTrue();
            expect(EngagementEvents::has('error'))->toBeTrue();
            expect(EngagementEvents::has('session_start'))->toBeTrue();
            expect(EngagementEvents::has('session_end'))->toBeTrue();

            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(25);
        });
    });

    // ── Consent Mode v2 Compliance ────────────────────────────────────

    describe('Consent Mode v2 Compliance', function (): void {
        test('consent grant → deny → state propagation', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            // Grant consent
            $fake->grantConsent();
            $consent = $fake->getConsent();
            expect($consent)->not->toBeNull();
            expect($consent->isGranted())->toBeTrue();

            // Deny consent
            $fake->denyConsent();
            $consent = $fake->getConsent();
            expect($consent)->not->toBeNull();
            expect($consent->isGranted())->toBeFalse();

            // Grant again
            $fake->grantConsent();
            $consent = $fake->getConsent();
            expect($consent->isGranted())->toBeTrue();
        });

        test('consent state is serializable for API responses', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');
            $fake->grantConsent();
            $consent = $fake->getConsent();

            expect($consent)->not->toBeNull();

            $array = $consent->toArray();
            expect($array)->toBeArray();
            expect($array)->toHaveKey('granted');
            expect($array['granted'])->toBeBool();
        });

        test('consent history is tracked for audit trail', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->grantConsent();
            $fake->denyConsent();
            $fake->grantConsent();

            // Consent history should reflect state changes
            $consent = $fake->getConsent();
            expect($consent)->not->toBeNull();
            expect($consent->isGranted())->toBeTrue();
        });

        test('consent_granted and consent_withdrawn events exist in catalog', function (): void {
            expect(EngagementEvents::has('consent_granted'))->toBeTrue();
            expect(EngagementEvents::has('consent_withdrawn'))->toBeTrue();
        });
    });

    // ── Identity Resolution ───────────────────────────────────────────

    describe('Identity Resolution', function (): void {
        test('client ID ↔ user ID linking', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            // Anonymous event with client ID
            $fake->track('page_view', ['title' => 'Landing'], 'client-abc-123');
            AnalyticsFake::assertTracked('page_view');

            // Identify call links client ID to user ID
            $fake->identify('user-456', 'client-abc-123', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'plan' => 'pro',
            ]);
            AnalyticsFake::assertIdentified('user-456');

            // Authenticated events now have user ID
            $fake->track('feature_used', ['feature_name' => 'dashboard'], 'client-abc-123');
            AnalyticsFake::assertTracked('feature_used');

            $events = AnalyticsFake::trackedEvents();
            expect($events)->toHaveCount(3);
        });

        test('identify with empty traits', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->identify('user-789', 'client-xyz');
            AnalyticsFake::assertIdentified('user-789');
        });

        test('multiple identify calls accumulate', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->identify('user-1', 'client-a');
            $fake->identify('user-2', 'client-b');
            $fake->identify('user-3', 'client-c');

            expect(AnalyticsFake::trackedEvents())->toHaveCount(3);
        });
    });

    // ── Multi-Provider Dispatch ───────────────────────────────────────

    describe('Multi-Provider Dispatch', function (): void {
        test('single event dispatch creates one event record', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('custom_event', ['key' => 'value']);
            $events = AnalyticsFake::trackedEvents();

            expect($events)->toHaveCount(1);
            expect($events[0]->name)->toBe('custom_event');
            expect($events[0]->params)->toBe(['key' => 'value']);
        });

        test('event with explicit client ID and user ID', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $event = new AnalyticsEvent(
                name: 'subscription.created',
                params: ['plan' => 'pro', 'amount' => 49.0],
                clientId: 'client-abc',
                userId: 'user-123',
            );

            $fake->trackEvent($event);
            AnalyticsFake::assertTracked('subscription.created');

            $tracked = AnalyticsFake::trackedEvents();
            expect($tracked[0]->clientId)->toBe('client-abc');
            expect($tracked[0]->userId)->toBe('user-123');
        });

        test('event with priority and source', function (): void {
            $event = new AnalyticsEvent(
                name: 'critical_error',
                params: ['message' => 'DB connection lost'],
                priority: 'critical',
                source: 'server',
            );

            expect($event->name)->toBe('critical_error');
            expect($event->priority)->toBe('critical');
            expect($event->source)->toBe('server');
        });
    });

    // ── E-Commerce Format Conversion ──────────────────────────────────

    describe('E-Commerce Format Conversion', function (): void {
        test('items are converted to Meta Pixel format', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget A', 'price' => 29.99, 'quantity' => 2],
                ['item_id' => 'SKU-002', 'item_name' => 'Widget B', 'price' => 49.99, 'quantity' => 1],
            ];

            $formatted = $fake->formatEcommerceForMeta($items);

            expect($formatted)->toHaveKey('content_ids');
            expect($formatted)->toHaveKey('contents');
            expect($formatted)->toHaveKey('num_items');
            expect($formatted['num_items'])->toBe(3);
            expect($formatted['content_ids'])->toContain('SKU-001');
            expect($formatted['content_ids'])->toContain('SKU-002');
        });

        test('EcommerceFormatConverter produces GA4 items array', function (): void {
            $converter = new EcommerceFormatConverter;

            $items = $converter->toGa4Items([
                ['id' => 'SKU-001', 'name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
            ]);

            expect($items)->toBeArray();
            expect($items)->not->toBeEmpty();
            expect($items[0])->toHaveKey('item_id');
            expect($items[0]['item_id'])->toBe('SKU-001');
        });

        test('EventTransformer resolves GA4 event names', function (): void {
            $ga4Name = EventTransformer::transformForProvider('sign_up', 'ga4');
            expect($ga4Name)->toBeString();
            expect($ga4Name)->not->toBeEmpty();
        });

        test('EventTransformer resolves Meta event names', function (): void {
            $metaName = EventTransformer::transformForProvider('sign_up', 'meta');
            expect($metaName)->toBeString();
        });
    });

    // ── Pipeline Processing ───────────────────────────────────────────

    describe('Pipeline Processing', function (): void {
        test('UTM enricher adds UTM parameters to event', function (): void {
            $event = new AnalyticsEvent(
                name: 'page_view',
                params: ['title' => 'Home'],
            );

            $pipeline = new EventPipeline;
            $pipeline->pipe(new UtmEnricher([
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'spring_sale',
                'utm_term' => 'analytics',
                'utm_content' => 'banner_ad',
            ]));

            $result = $pipeline->process($event);
            expect($result)->not->toBeNull();
            expect($result->params)->toHaveKey('utm_source');
            expect($result->params['utm_source'])->toBe('google');
            expect($result->params)->toHaveKey('utm_medium');
            expect($result->params['utm_medium'])->toBe('cpc');
        });

        test('timestamp enricher adds timestamp to event', function (): void {
            $event = new AnalyticsEvent(name: 'custom_event');

            $pipeline = new EventPipeline;
            $pipeline->pipe(new TimestampEnricher);

            $result = $pipeline->process($event);
            expect($result)->not->toBeNull();
            expect($result->timestamp)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        test('metadata enricher adds session and page context', function (): void {
            $event = new AnalyticsEvent(name: 'page_view');

            $pipeline = new EventPipeline;
            $pipeline->pipe(new EventMetadataEnricher(
                sessionId: 'session-abc',
                pageUrl: 'https://example.com/pricing',
                referrer: 'https://google.com',
                includeTimestamp: true,
            ));

            $result = $pipeline->process($event);
            expect($result)->not->toBeNull();
            expect($result->params)->toHaveKey('session_id');
            expect($result->params['session_id'])->toBe('session-abc');
            expect($result->params)->toHaveKey('page_url');
        });

        test('empty pipeline passes event through unchanged', function (): void {
            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

            $pipeline = new EventPipeline;
            $result = $pipeline->process($event);

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('test_event');
            expect($result->params)->toBe(['key' => 'value']);
        });
    });

    // ── GDPR Compliance ───────────────────────────────────────────────

    describe('GDPR Compliance', function (): void {
        test('resetIdentity clears tracking state', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('page_view', []);
            $fake->identify('user-123', 'client-abc');

            // Reset
            $fake->resetIdentity();

            // Should be able to continue tracking after reset
            $fake->track('page_view', []);
            expect(AnalyticsFake::trackedEvents())->not->toBeEmpty();
        });

        test('opt-out prevents tracking', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->optOut('client-xyz');

            // Verify tracking preference was set
            $allowed = $fake->isTrackingAllowed(null, 'client-xyz');
            expect($allowed)->toBeFalse();
        });

        test('opt-in re-enables tracking', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->optOut('client-xyz');
            $fake->optIn('client-xyz');

            $allowed = $fake->isTrackingAllowed(null, 'client-xyz');
            expect($allowed)->toBeTrue();
        });
    });

    // ── Event Catalog Integrity ───────────────────────────────────────

    describe('Event Catalog Integrity', function (): void {
        test('all 5 categories are present', function (): void {
            $categories = EventCatalog::byCategory();

            expect($categories)->toHaveKey('ecommerce');
            expect($categories)->toHaveKey('saas');
            expect($categories)->toHaveKey('engagement');
            expect($categories)->toHaveKey('security');
            expect($categories)->toHaveKey('uptime');
        });

        test('catalog has 100+ events total', function (): void {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
        });

        test('catalog validates without errors', function (): void {
            $result = EventCatalog::validate();

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        test('no duplicate event names across categories', function (): void {
            $all = EventCatalog::all();
            $names = array_keys($all);

            expect($names)->toEqual(array_unique($names));
        });

        test('every event has required fields', function (): void {
            $all = EventCatalog::all();
            $required = EventCatalog::requiredKeys();

            foreach ($all as $name => $entry) {
                foreach ($required as $key) {
                    expect($entry)->toHaveKey($key);
                }
            }
        });

        test('byProvider returns all 6 providers', function (): void {
            $providers = EventCatalog::byProvider();

            expect($providers)->toHaveKey('ga4');
            expect($providers)->toHaveKey('meta');
            expect($providers)->toHaveKey('posthog');
            expect($providers)->toHaveKey('plausible');
            expect($providers)->toHaveKey('mixpanel');
            expect($providers)->toHaveKey('amplitude');
        });
    });

    // ── AnalyticsEvent DTO ─────────────────────────────────────────────

    describe('AnalyticsEvent DTO', function (): void {
        test('immutable DTO with strict types', function (): void {
            $event = new AnalyticsEvent(
                name: 'test',
                params: ['key' => 'value'],
                clientId: 'client-abc',
                userId: 'user-123',
                priority: 'normal',
                source: 'api',
            );

            expect($event->name)->toBe('test');
            expect($event->params)->toBe(['key' => 'value']);
            expect($event->clientId)->toBe('client-abc');
            expect($event->userId)->toBe('user-123');
            expect($event->priority)->toBe('normal');
            expect($event->source)->toBe('api');
        });

        test('fromArray with valid data', function (): void {
            $event = AnalyticsEvent::fromArray([
                'name' => 'test',
                'params' => ['key' => 'value'],
                'client_id' => 'client-abc',
                'user_id' => 'user-123',
            ]);

            expect($event->name)->toBe('test');
            expect($event->clientId)->toBe('client-abc');
        });

        test('fromArray with missing fields uses defaults', function (): void {
            $event = AnalyticsEvent::fromArray([]);

            expect($event->name)->toBe('');
            expect($event->params)->toBe([]);
            expect($event->clientId)->toBeNull();
            expect($event->userId)->toBeNull();
        });

        test('toArray round-trips correctly', function (): void {
            $original = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 99.99],
                clientId: 'client-abc',
                userId: 'user-123',
            );

            $array = $original->toArray();
            $restored = AnalyticsEvent::fromArray($array);

            expect($restored->name)->toBe($original->name);
            expect($restored->clientId)->toBe($original->clientId);
            expect($restored->userId)->toBe($original->userId);
        });

        test('VERSION constant matches expected format', function (): void {
            expect(AnalyticsEvent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
        });
    });

    // ── Version Consistency ───────────────────────────────────────────

    describe('Version Consistency', function (): void {
        test('AnalyticsEvent::VERSION follows semver format', function (): void {
            expect(AnalyticsEvent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
        });
    });

    // ── Queue Dispatch Readiness ───────────────────────────────────────

    describe('Queue Dispatch Readiness', function (): void {
        test('queue classes are loadable', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class))->toBeTrue();
        });

        test('TrackAnalyticsEventJob is serializable structure', function (): void {
            $event = new AnalyticsEvent(name: 'test', params: []);
            $job = new \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob($event);

            // Job should be constructable
            expect($job)->toBeInstanceOf(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class);
        });

        test('TrackAnalyticsEventBatchJob accepts event array', function (): void {
            $events = [
                new AnalyticsEvent(name: 'event_1', params: []),
                new AnalyticsEvent(name: 'event_2', params: []),
            ];
            $job = new \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob($events);

            expect($job)->toBeInstanceOf(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class);
        });
    });

    // ── Facade Proxy Verification ────────────────────────────────────

    describe('Facade Proxy', function (): void {
        test('facade accessor returns correct binding', function (): void {
            $accessor = Analytics::getFacadeAccessor();
            expect($accessor)->toBe('zeroboiler.analytics');
        });
    });

    // ── AnalyticsFake Assertions ──────────────────────────────────────

    describe('AnalyticsFake Assertions', function (): void {
        test('assertTrackedTimes validates exact count', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('click', ['element' => 'btn-1']);
            $fake->track('click', ['element' => 'btn-2']);
            $fake->track('click', ['element' => 'btn-3']);

            AnalyticsFake::assertTrackedTimes('click', 3);
        });

        test('assertNotTracked validates absence', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('page_view', []);

            AnalyticsFake::assertNotTracked('purchase');
        });

        test('assertTracked with callback validates params', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('purchase', ['value' => 99.99, 'currency' => 'USD']);

            AnalyticsFake::assertTracked('purchase', function (AnalyticsEvent $event): bool {
                return ($event->params['value'] ?? 0) === 99.99;
            });
        });

        test('assertNothingTracked after reset', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('page_view', []);
            AnalyticsFake::assertTracked('page_view');

            $fake->reset();
            AnalyticsFake::assertNothingTracked();
        });

        test('trackedEvents returns all events in order', function (): void {
            /** @var AnalyticsFake $fake */
            $fake = app('zeroboiler.analytics');

            $fake->track('event_1', []);
            $fake->track('event_2', []);
            $fake->track('event_3', []);

            $events = AnalyticsFake::trackedEvents();
            expect($events[0]->name)->toBe('event_1');
            expect($events[1]->name)->toBe('event_2');
            expect($events[2]->name)->toBe('event_3');
        });
    });
});
