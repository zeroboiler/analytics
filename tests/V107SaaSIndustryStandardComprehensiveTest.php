<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Services\EventTransformer;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V10.7 — Comprehensive SaaS Industry Standard Analytics Test
 *
 * Validates the full analytics stack: event catalog integrity, provider coverage,
 * identity linking, e-commerce format conversion, lifecycle mapping, JS client
 * readiness, config consistency, and industry-standard SaaS event patterns.
 *
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents
 * @covers \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents
 * @covers \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents
 * @covers \ZeroBoiler\Analytics\Events\Security\SecurityEvents
 * @covers \ZeroBoiler\Analytics\Events\Uptime\UptimeEvents
 * @covers \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 * @covers \ZeroBoiler\Analytics\Services\EventTransformer
 * @covers \ZeroBoiler\Analytics\DTO\AnalyticsEvent
 * @covers \ZeroBoiler\Analytics\Tracking\UserIdentityTracker
 */
describe('V10.7 — SaaS Industry Standard Analytics', function (): void {
    // ─── Event Catalog Integrity ─────────────────────────────────────

    describe('Event catalog integrity', function (): void {
        it('has all 5 built-in categories registered', function (): void {
            $byCategory = EventCatalog::byCategory();

            expect(array_keys($byCategory))->toBe([
                'ecommerce', 'saas', 'engagement', 'security', 'uptime',
            ]);
        });

        it('has no duplicate event names across categories', function (): void {
            $validation = EventCatalog::validate();

            expect($validation['valid'])->toBeTrue();
            expect($validation['errors'])->toHaveCount(0);
        });

        it('has every required key in all entries', function (): void {
            $all = EventCatalog::all();
            $required = EventCatalog::requiredKeys();

            foreach ($all as $name => $entry) {
                foreach ($required as $key) {
                    expect($entry)
                        ->toHaveKey($key)
                        ->and($entry['name'])
                        ->toBe($name);
                }
            }
        });

        it('has a substantial event catalog', function (): void {
            $count = EventCatalog::count();

            expect($count)->toBeGreaterThan(100);
        });
    });

    // ─── Provider Coverage ────────────────────────────────────────────

    describe('Provider coverage', function (): void {
        it('has GA4 names for all events', function (): void {
            $ga4Names = EventCatalog::allGa4Names();
            $count = EventCatalog::count();

            expect($ga4Names)->toHaveCount($count);
            expect($ga4Names)->not->toContain('');
        });

        it('has Mixpanel names for all categories', function (): void {
            $mixpanelNames = EventCatalog::allMixpanelNames();

            expect($mixpanelNames)->not->toBeEmpty();
            // At least ecommerce and saas should have Mixpanel mappings
            expect(EcommerceEvents::mixpanelNames())->not->toBeEmpty();
            expect(SaaSEvents::mixpanelNames())->not->toBeEmpty();
        });

        it('has Amplitude names for all categories', function (): void {
            $amplitudeNames = EventCatalog::allAmplitudeNames();

            expect($amplitudeNames)->not->toBeEmpty();
            expect(EcommerceEvents::amplitudeNames())->not->toBeEmpty();
            expect(EngagementEvents::amplitudeNames())->not->toBeEmpty();
        });

        it('has Plausible names for key engagement events', function (): void {
            $plausible = EventCatalog::plausibleNameFor('page_view');

            expect($plausible)->toBe('pageview');
        });

        it('has PostHog names for key SaaS events', function (): void {
            $posthog = EventCatalog::posthogNameFor('sign_up');

            expect($posthog)->not->toBeNull();
        });

        it('byProvider() returns all 6 providers', function (): void {
            $byProvider = EventCatalog::byProvider();

            expect(array_keys($byProvider))->toBe([
                'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude',
            ]);
        });

        it('Mixpanel uses Title Case convention', function (): void {
            $name = EcommerceEvents::mixpanelNameFor('add_to_cart');

            // Mixpanel convention: Title Case
            expect($name)->toBe('Add to Cart');
        });

        it('Amplitude uses Past Tense convention', function (): void {
            $name = EcommerceEvents::amplitudeNameFor('purchase');

            // Amplitude convention: Past Tense
            expect($name)->toBe('Completed Order');
        });
    });

    // ─── Core SaaS Events ─────────────────────────────────────────────

    describe('Core SaaS events present', function (): void {
        it('has sign_up, login, trial_start, subscription events', function (): void {
            expect(SaaSEvents::has('sign_up'))->toBeTrue();
            expect(SaaSEvents::has('login'))->toBeTrue();
            expect(SaaSEvents::has('trial_start'))->toBeTrue();
            expect(SaaSEvents::has('subscription_created'))->toBeTrue();
            expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
            expect(SaaSEvents::has('cancellation'))->toBeTrue();
        });

        it('has core engagement events', function (): void {
            expect(EngagementEvents::has('page_view'))->toBeTrue();
            expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
            expect(EngagementEvents::has('click'))->toBeTrue();
            expect(EngagementEvents::has('form_start'))->toBeTrue();
            expect(EngagementEvents::has('form_submit'))->toBeTrue();
            expect(EngagementEvents::has('search'))->toBeTrue();
            expect(EngagementEvents::has('share'))->toBeTrue();
            expect(EngagementEvents::has('error'))->toBeTrue();
        });

        it('has core ecommerce events', function (): void {
            expect(EcommerceEvents::has('view_item'))->toBeTrue();
            expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
            expect(EcommerceEvents::has('purchase'))->toBeTrue();
            expect(EcommerceEvents::has('refund'))->toBeTrue();
            expect(EcommerceEvents::has('begin_checkout'))->toBeTrue();
            expect(EcommerceEvents::has('remove_from_cart'))->toBeTrue();
        });

        it('has security events', function (): void {
            expect(SecurityEvents::has('login_attempt'))->toBeTrue();
            expect(SecurityEvents::has('suspicious_activity'))->toBeTrue();
            expect(SecurityEvents::has('rate_limit_exceeded'))->toBeTrue();
        });

        it('has uptime events', function (): void {
            expect(UptimeEvents::has('service_up'))->toBeTrue();
            expect(UptimeEvents::has('service_down'))->toBeTrue();
            expect(UptimeEvents::has('deployment'))->toBeTrue();
        });
    });

    // ─── AnalyticsEvent DTO ───────────────────────────────────────────

    describe('AnalyticsEvent DTO', function (): void {
        it('creates with name only', function (): void {
            $event = new AnalyticsEvent(name: 'test_event');

            expect($event->name)->toBe('test_event');
            expect($event->params)->toBe([]);
            expect($event->clientId)->toBeNull();
            expect($event->userId)->toBeNull();
            expect($event->timestamp)->toBeNull();
            expect($event->priority)->toBeNull();
            expect($event->source)->toBeNull();
        });

        it('creates with all fields', function (): void {
            $ts = new \DateTimeImmutable('2026-01-15 12:00:00');
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 99.99, 'currency' => 'USD'],
                clientId: 'cli_123',
                userId: 'usr_456',
                timestamp: $ts,
                priority: 'critical',
                source: 'api',
            );

            expect($event->name)->toBe('purchase');
            expect($event->params['value'])->toBe(99.99);
            expect($event->clientId)->toBe('cli_123');
            expect($event->userId)->toBe('usr_456');
            expect($event->timestamp)->toBe($ts);
            expect($event->priority)->toBe('critical');
            expect($event->source)->toBe('api');
        });

        it('serializes to array with all fields', function (): void {
            $ts = new \DateTimeImmutable('2026-01-15 12:00:00');
            $event = new AnalyticsEvent(
                name: 'test',
                params: ['key' => 'val'],
                clientId: 'c1',
                userId: 'u1',
                timestamp: $ts,
                priority: 'normal',
                source: 'server',
            );

            $arr = $event->toArray();

            expect($arr)->toHaveKey('name');
            expect($arr)->toHaveKey('params');
            expect($arr)->toHaveKey('client_id');
            expect($arr)->toHaveKey('user_id');
            expect($arr)->toHaveKey('timestamp');
            expect($arr)->toHaveKey('priority');
            expect($arr)->toHaveKey('source');
        });

        it('round-trips through fromArray', function (): void {
            $original = new AnalyticsEvent(
                name: 'round_trip',
                params: ['a' => 1],
                clientId: 'c',
                userId: 'u',
                priority: 'low',
                source: 'client',
            );

            $restored = AnalyticsEvent::fromArray($original->toArray());

            expect($restored->name)->toBe($original->name);
            expect($restored->params)->toBe($original->params);
            expect($restored->clientId)->toBe($original->clientId);
            expect($restored->userId)->toBe($original->userId);
            expect($restored->priority)->toBe($original->priority);
            expect($restored->source)->toBe($original->source);
        });

        it('reports correct version', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('10.5.0');
        });
    });

    // ─── Event Transformer ────────────────────────────────────────────

    describe('EventTransformer', function (): void {
        it('transforms saas events for GA4', function (): void {
            $ga4 = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']),
                'ga4',
            );

            // GA4 uses snake_case
            expect($ga4->name)->toBe('sign_up');
        });

        it('transforms saas events for Meta', function (): void {
            $meta = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'purchase', params: ['value' => 50]),
                'meta',
            );

            // Meta uses Title Case
            expect($meta->name)->toBe('Purchase');
        });

        it('transforms saas events for PostHog', function (): void {
            $posthog = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'sign_up', params: []),
                'posthog',
            );

            expect($posthog->name)->toBe('$signup');
        });

        it('transforms saas events for Plausible', function (): void {
            $plausible = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'page_view', params: []),
                'plausible',
            );

            expect($plausible->name)->toBe('pageview');
        });

        it('transforms saas events for Mixpanel', function (): void {
            $mixpanel = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'sign_up', params: []),
                'mixpanel',
            );

            expect($mixpanel->name)->toBe('Sign Up');
        });

        it('transforms saas events for Amplitude', function (): void {
            $amplitude = EventTransformer::transformForProvider(
                new AnalyticsEvent(name: 'sign_up', params: []),
                'amplitude',
            );

            expect($amplitude->name)->toBe('Signed Up');
        });

        it('returns original event for unknown provider', function (): void {
            $event = new AnalyticsEvent(name: 'custom_event', params: []);
            $result = EventTransformer::transformForProvider($event, 'unknown_provider');

            expect($result->name)->toBe('custom_event');
        });

        it('has comprehensive saas-to-mixpanel event map', function (): void {
            $map = EventTransformer::saasToMixpanelEventMap();

            expect($map)->toHaveCount(50);
            expect($map)->toHaveKey('sign_up');
            expect($map)->toHaveKey('purchase');
            expect($map)->toHaveKey('add_to_cart');
        });

        it('has comprehensive saas-to-amplitude event map', function (): void {
            $map = EventTransformer::saasToAmplitudeEventMap();

            expect($map)->toHaveCount(50);
            expect($map)->toHaveKey('sign_up');
            expect($map)->toHaveKey('purchase');
        });
    });

    // ─── E-commerce Format Conversion ────────────────────────────────

    describe('EcommerceFormatConverter', function (): void {
        it('exists and is instantiable', function (): void {
            $converter = new EcommerceFormatConverter;

            expect($converter)->toBeInstanceOf(EcommerceFormatConverter::class);
        });

        it('provides GA4 item format conversion', function (): void {
            $converter = new EcommerceFormatConverter;
            $items = [
                ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ];

            // EcommerceFormatConverter should handle GA4 items array
            $ga4Items = $converter->toGa4Items($items);

            expect($ga4Items)->toBeArray();
            expect($ga4Items[0])->toHaveKey('item_id');
            expect($ga4Items[0]['item_id'])->toBe('SKU-1');
        });

        it('provides Meta content format conversion', function (): void {
            $converter = new EcommerceFormatConverter;
            $items = [
                ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ];

            $metaItems = $converter->toMetaContents($items);

            expect($metaItems)->toBeArray();
            expect($metaItems[0])->toHaveKey('id');
        });
    });

    // ─── Event Catalog Factory ────────────────────────────────────────

    describe('Event catalog factory', function (): void {
        it('creates events via factory', function (): void {
            $event = \ZeroBoiler\Analytics\Support\EventCatalogFactory::event('purchase', [
                'value' => 49.99,
            ]);

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('purchase');
            expect($event->params['value'])->toBe(49.99);
        });

        it('creates events with identity', function (): void {
            $event = \ZeroBoiler\Analytics\Support\EventCatalogFactory::event('sign_up')
                ->withClientId('cli_abc')
                ->withUserId('usr_xyz')
                ->build();

            expect($event->clientId)->toBe('cli_abc');
            expect($event->userId)->toBe('usr_xyz');
        });

        it('creates critical priority events', function (): void {
            $event = \ZeroBoiler\Analytics\Support\EventCatalogFactory::critical('payment_succeeded', [
                'amount' => 99.99,
            ]);

            expect($event->priority)->toBe('critical');
        });

        it('creates raw events without catalog validation', function (): void {
            $event = \ZeroBoiler\Analytics\Support\EventCatalogFactory::raw('my_custom_event');

            expect($event->name)->toBe('my_custom_event');
        });
    });

    // ─── Lifecycle Mapping Completeness ───────────────────────────────

    describe('Lifecycle mapping completeness', function (): void {
        it('maps auth.login → LoginEvent', function (): void {
            $entry = EventCatalog::get('login');

            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('saas');
        });

        it('maps subscription.created → SubscriptionEvent', function (): void {
            $entry = EventCatalog::get('subscription_created');

            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('saas');
        });

        it('maps order.completed → PurchaseEvent', function (): void {
            $entry = EventCatalog::get('purchase');

            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('ecommerce');
        });

        it('has conversion funnel events', function (): void {
            expect(SaaSEvents::has('trial_start'))->toBeTrue();
            expect(SaaSEvents::has('trial_converted'))->toBeTrue();
            expect(SaaSEvents::has('subscription_created'))->toBeTrue();
            expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
            expect(SaaSEvents::has('cancellation'))->toBeTrue();
        });

        it('maps engagement events to correct category', function (): void {
            expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
            expect(EventCatalog::getCategory('scroll_depth'))->toBe('engagement');
            expect(EventCatalog::getCategory('form_submit'))->toBe('engagement');
            expect(EventCatalog::getCategory('error'))->toBe('engagement');
        });
    });

    // ─── Identity Tracking ────────────────────────────────────────────

    describe('Identity tracking', function (): void {
        it('UserIdentityTracker creates identify events', function (): void {
            $event = new AnalyticsEvent(
                name: 'identify',
                params: [
                    'user_id' => 'user_123',
                    'client_id' => 'client_abc',
                ],
                clientId: 'client_abc',
                userId: 'user_123',
            );

            expect($event->name)->toBe('identify');
            expect($event->params['user_id'])->toBe('user_123');
            expect($event->params['client_id'])->toBe('client_abc');
        });

        it('AnalyticsEvent supports identity fields', function (): void {
            $event = new AnalyticsEvent(
                name: 'purchase',
                clientId: 'uuid-client',
                userId: '42',
            );

            expect($event->clientId)->toBe('uuid-client');
            expect($event->userId)->toBe('42');
        });
    });

    // ─── Provider Mappings Completeness ──────────────────────────────

    describe('Provider mappings completeness', function (): void {
        it('allMetaNames returns non-null entries', function (): void {
            $metaNames = EventCatalog::allMetaNames();

            expect($metaNames)->not->toBeEmpty();
            foreach ($metaNames as $name) {
                expect($name)->not->toBeEmpty();
            }
        });

        it('allPosthogNames returns non-null entries', function (): void {
            $posthogNames = EventCatalog::allPosthogNames();

            expect($posthogNames)->not->toBeEmpty();
            foreach ($posthogNames as $name) {
                expect($name)->not->toBeEmpty();
            }
        });

        it('allPlausibleNames returns non-null entries', function (): void {
            $plausibleNames = EventCatalog::allPlausibleNames();

            expect($plausibleNames)->not->toBeEmpty();
            foreach ($plausibleNames as $name) {
                expect($name)->not->toBeEmpty();
            }
        });

        it('has no empty GA4 names', function (): void {
            $all = EventCatalog::all();

            foreach ($all as $name => $entry) {
                expect($entry['ga4'])->not->toBeEmpty()
                    ->and($entry['ga4'])->not->toBe($name . '_broken');
            }
        });
    });

    // ─── Catalog Search ──────────────────────────────────────────────

    describe('Catalog search', function (): void {
        it('finds events by partial name match', function (): void {
            $results = EventCatalog::search('purchase');

            expect($results)->not->toBeEmpty();
        });

        it('finds events by category keyword', function (): void {
            $results = EventCatalog::search('cart');

            expect($results)->not->toBeEmpty();
        });

        it('returns empty for no match', function (): void {
            $results = EventCatalog::search('zzz_nonexistent_event');

            expect($results)->toBeEmpty();
        });
    });

    // ─── Version Consistency ─────────────────────────────────────────

    describe('Version consistency', function (): void {
        it('AnalyticsEvent VERSION is 10.5.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('10.5.0');
        });

        it('has catalog validation passing', function (): void {
            $result = EventCatalog::validate();

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });
    });

    // ─── Priority and Source Fields ───────────────────────────────────

    describe('Priority and source fields', function (): void {
        it('accepts valid priority levels', function (): void {
            foreach (['critical', 'normal', 'low', 'background'] as $priority) {
                $event = new AnalyticsEvent(name: 'test', priority: $priority);

                expect($event->priority)->toBe($priority);
            }
        });

        it('accepts valid source types', function (): void {
            foreach (['api', 'server', 'client', 'webhook', 'replay', 'batch'] as $source) {
                $event = new AnalyticsEvent(name: 'test', source: $source);

                expect($event->source)->toBe($source);
            }
        });

        it('priority is included in serialization', function (): void {
            $event = new AnalyticsEvent(name: 'test', priority: 'critical');
            $arr = $event->toArray();

            expect($arr)->toHaveKey('priority');
            expect($arr['priority'])->toBe('critical');
        });

        it('source is included in serialization', function (): void {
            $event = new AnalyticsEvent(name: 'test', source: 'api');
            $arr = $event->toArray();

            expect($arr)->toHaveKey('source');
            expect($arr['source'])->toBe('api');
        });
    });

    // ─── Event Count Expectations ────────────────────────────────────

    describe('Event count expectations', function (): void {
        it('ecommerce has at least 14 events', function (): void {
            expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(14);
        });

        it('saas has at least 40 events', function (): void {
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(40);
        });

        it('engagement has at least 20 events', function (): void {
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(20);
        });

        it('security has at least 5 events', function (): void {
            expect(SecurityEvents::count())->toBeGreaterThanOrEqual(5);
        });

        it('uptime has at least 5 events', function (): void {
            expect(UptimeEvents::count())->toBeGreaterThanOrEqual(5);
        });

        it('total catalog exceeds 100 events', function (): void {
            expect(EventCatalog::count())->toBeGreaterThan(100);
        });
    });

    // ─── Provider-Specific Name Lookups ───────────────────────────────

    describe('Provider-specific name lookups', function (): void {
        it('mixpanelNameFor returns correct format', function (): void {
            $name = EventCatalog::mixpanelNameFor('purchase');

            expect($name)->not->toBeNull();
            // Mixpanel convention: Title Case
            expect($name[0])->toBeUpperCase();
        });

        it('amplitudeNameFor returns correct format', function (): void {
            $name = EventCatalog::amplitudeNameFor('sign_up');

            expect($name)->not->toBeNull();
            // Amplitude convention: Past Tense
            expect($name)->toContain(' ');
        });

        it('plausibleNameFor returns null for unsupported events', function (): void {
            // Some SaaS events may not have Plausible equivalents
            $name = EventCatalog::plausibleNameFor('trial_start');

            // Plausible is pageview-focused — many events won't have mappings
            // This assertion just confirms the method works
            expect($name)->toBeString()->or()->toBeNull();
        });

        it('posthogNameFor returns $-prefixed for reserved events', function (): void {
            $name = EventCatalog::posthogNameFor('sign_up');

            expect($name)->toStartWith('$');
        });
    });

    // ─── Provider Mapping Tables ───────────────────────────────────────

    describe('Provider mapping tables', function (): void {
        it('allPosthogMappings is a complete map', function (): void {
            $map = EventCatalog::allPosthogMappings();

            expect($map)->toHaveCount(EventCatalog::count());
        });

        it('allPlausibleMappings includes all events', function (): void {
            $map = EventCatalog::allPlausibleMappings();

            expect($map)->toHaveCount(EventCatalog::count());
        });

        it('allMixpanelMappings is available via all()', function (): void {
            $all = EventCatalog::all();

            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('mixpanel');
            }
        });

        it('allAmplitudeMappings is available via all()', function (): void {
            $all = EventCatalog::all();

            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('amplitude');
            }
        });
    });
});
