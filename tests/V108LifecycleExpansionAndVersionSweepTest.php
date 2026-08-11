<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Services\EventTransformer;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V10.8 — Lifecycle Expansion & Version Sweep Test
 *
 * Validates the v10.8.0 release:
 * - Version consistency across all entry points (10.8.0)
 * - LifecycleEventMapper new mappings (SLA breach, feature adopted, expansion revenue)
 * - All 5 event catalog categories with provider coverage
 * - Ecommerce format conversion (GA4 ↔ Meta)
 * - EventTransformer provider mapping completeness
 *
 * @covers \ZeroBoiler\Analytics\DTO\AnalyticsEvent
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents
 * @covers \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 * @covers \ZeroBoiler\Analytics\Services\EventTransformer
 */
describe('V10.8 — Lifecycle Expansion & Version Sweep', function (): void {
    // ─── Version Consistency ─────────────────────────────────────────

    describe('Version consistency (10.8.0 sweep)', function (): void {
        it('AnalyticsEvent VERSION is 10.8.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('10.8.0');
        });

        it('VERSION follows semantic versioning format', function (): void {
            expect(AnalyticsEvent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
        });
    });

    // ─── Lifecycle Event Mapper Expansion ──────────────────────────

    describe('Lifecycle event mapper new mappings (v10.8.0)', function (): void {
        it('SaaS catalog includes sla_breach event', function (): void {
            expect(SaaSEvents::has('sla_breach'))->toBeTrue();
        });

        it('SaaS catalog includes feature_adopted event', function (): void {
            expect(SaaSEvents::has('feature_adopted'))->toBeTrue();
        });

        it('SaaS catalog includes expansion_revenue event', function (): void {
            expect(SaaSEvents::has('expansion_revenue'))->toBeTrue();
        });

        it('sla_breach has provider mappings', function (): void {
            $entry = SaaSEvents::get('sla_breach');

            expect($entry)->not->toBeNull();
            expect($entry['ga4'])->toBeString();
            expect($entry['ga4'])->not->toBeEmpty();
            expect($entry['meta'])->not->toBeNull();
            expect($entry['posthog'])->toBeString();
            expect($entry['mixpanel'])->toBeString();
            expect($entry['amplitude'])->toBeString();
        });

        it('feature_adopted has provider mappings', function (): void {
            $entry = SaaSEvents::get('feature_adopted');

            expect($entry)->not->toBeNull();
            expect($entry['ga4'])->toBeString();
            expect($entry['posthog'])->toBeString();
            expect($entry['mixpanel'])->toBeString();
        });

        it('expansion_revenue has provider mappings', function (): void {
            $entry = SaaSEvents::get('expansion_revenue');

            expect($entry)->not->toBeNull();
            expect($entry['ga4'])->toBeString();
            expect($entry['posthog'])->toBeString();
            expect($entry['mixpanel'])->toBeString();
        });

        it('SaaS catalog total count exceeds 55 events', function (): void {
            expect(SaaSEvents::count())->toBeGreaterThan(55);
        });

        it('all new events have valid class references', function (): void {
            $newEvents = ['sla_breach', 'feature_adopted', 'expansion_revenue'];

            foreach ($newEvents as $eventName) {
                $entry = SaaSEvents::get($eventName);
                $className = $entry['class'] ?? null;

                expect($className)->not->toBeNull();
                expect($className)->toBeString();
                expect($className)->toMatch('/Event$/');
            }
        });
    });

    // ─── Event Catalog Completeness ─────────────────────────────────

    describe('Event catalog completeness', function (): void {
        it('has all 5 built-in categories registered', function (): void {
            $byCategory = EventCatalog::byCategory();

            expect(array_keys($byCategory))->toBe([
                'ecommerce', 'saas', 'engagement', 'security', 'uptime',
            ]);
        });

        it('EcommerceEvents has all core e-commerce events', function (): void {
            $names = EcommerceEvents::names();

            expect($names)->toContain('view_item');
            expect($names)->toContain('add_to_cart');
            expect($names)->toContain('purchase');
            expect($names)->toContain('refund');
            expect($names)->toContain('begin_checkout');
        });

        it('SaaSEvents has all core SaaS events', function (): void {
            $names = SaaSEvents::names();

            expect($names)->toContain('sign_up');
            expect($names)->toContain('login');
            expect($names)->toContain('start_trial');
            expect($names)->toContain('plan_upgrade');
            expect($names)->toContain('cancellation');
            expect($names)->toContain('sla_breach');
            expect($names)->toContain('feature_adopted');
            expect($names)->toContain('expansion_revenue');
        });

        it('EngagementEvents has all core engagement events', function (): void {
            $names = EngagementEvents::names();

            expect($names)->toContain('page_view');
            expect($names)->toContain('scroll_depth');
            expect($names)->toContain('click');
            expect($names)->toContain('form_start');
            expect($names)->toContain('form_submit');
            expect($names)->toContain('search');
            expect($names)->toContain('share');
            expect($names)->toContain('error');
        });

        it('total catalog count exceeds 120 events', function (): void {
            expect(EventCatalog::count())->toBeGreaterThan(120);
        });

        it('all catalog entries have 7 provider fields', function (): void {
            $all = EventCatalog::all();
            $requiredFields = ['name', 'class', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];

            foreach ($all as $eventName => $entry) {
                foreach ($requiredFields as $field) {
                    expect(isset($entry[$field]))->toBeTrue("Event '{$eventName}' missing '{$field}' field");
                }
            }
        });
    });

    // ─── Provider Coverage ──────────────────────────────────────────

    describe('Provider coverage', function (): void {
        it('EventTransformer supports all 7 providers', function (): void {
            $providers = EventTransformer::supportedProviders();

            expect($providers)->toContain('ga4');
            expect($providers)->toContain('gtm');
            expect($providers)->toContain('meta');
            expect($providers)->toContain('posthog');
            expect($providers)->toContain('plausible');
            expect($providers)->toContain('mixpanel');
            expect($providers)->toContain('amplitude');
        });

        it('can transform SaaS events to GA4 format', function (): void {
            $result = EventTransformer::transformForProvider('sign_up', 'ga4');

            expect($result)->toBe('sign_up');
        });

        it('can transform SaaS events to Meta format', function (): void {
            $result = EventTransformer::transformForProvider('sign_up', 'meta');

            expect($result)->toBe('CompleteRegistration');
        });

        it('can transform SaaS events to Mixpanel format', function (): void {
            $result = EventTransformer::transformForProvider('sign_up', 'mixpanel');

            expect($result)->toBe('Sign Up');
        });

        it('can transform SaaS events to Amplitude format', function (): void {
            $result = EventTransformer::transformForProvider('sign_up', 'amplitude');

            expect($result)->toBe('Sign Up');
        });

        it('returns original name for unknown provider', function (): void {
            $result = EventTransformer::transformForProvider('sign_up', 'unknown_provider');

            expect($result)->toBe('sign_up');
        });
    });

    // ─── E-commerce Format Conversion ───────────────────────────────

    describe('E-commerce format conversion', function (): void {
        it('converts purchase event to GA4 format', function (): void {
            $items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Product A', 'price' => 29.99, 'quantity' => 2],
            ];
            $ga4 = EcommerceFormatConverter::toGa4Purchase($items, 59.98, 'USD', 'TX-123');

            expect($ga4)->toBeArray();
            expect($ga4['transaction_id'])->toBe('TX-123');
            expect($ga4['value'])->toBe(59.98);
            expect($ga4['currency'])->toBe('USD');
            expect($ga4['items'])->toHaveCount(1);
        });

        it('converts purchase event to Meta format', function (): void {
            $items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Product A', 'price' => 29.99, 'quantity' => 2],
            ];
            $meta = EcommerceFormatConverter::toMetaPurchase($items, 59.98, 'USD', 'TX-123');

            expect($meta)->toBeArray();
            expect($meta['content_ids'])->toContain('SKU-001');
            expect($meta['value'])->toBe(59.98);
            expect($meta['currency'])->toBe('USD');
        });

        it('converts add_to_cart to Meta format', function (): void {
            $meta = EcommerceFormatConverter::toMetaAddToCart('SKU-001', 'Product A', 29.99, 1, 'USD');

            expect($meta)->toBeArray();
            expect($meta['content_ids'])->toBe(['SKU-001']);
            expect($meta['content_name'])->toBe('Product A');
            expect($meta['value'])->toBe(29.99);
        });
    });

    // ─── Cross-Category Integration ────────────────────────────────

    describe('Cross-category integration', function (): void {
        it('EventCatalog summary includes all categories', function (): void {
            $summary = EventCatalog::summary();

            expect($summary)->toHaveKey('ecommerce');
            expect($summary)->toHaveKey('saas');
            expect($summary)->toHaveKey('engagement');
            expect($summary)->toHaveKey('security');
            expect($summary)->toHaveKey('uptime');
            expect($summary)->toHaveKey('total');
        });

        it('catalog classFor returns correct class for each category', function (): void {
            expect(EcommerceEvents::classFor('purchase'))->toBeString();
            expect(SaaSEvents::classFor('sign_up'))->toBeString();
            expect(EngagementEvents::classFor('page_view'))->toBeString();
        });

        it('catalog count() returns consistent totals', function (): void {
            $total = EventCatalog::count();
            $sum = EcommerceEvents::count()
                + SaaSEvents::count()
                + EngagementEvents::count()
                + SecurityEvents::count()
                + UptimeEvents::count();

            expect($total)->toBe($sum);
        });

        it('EventCatalog byProvider returns all 7 providers', function (): void {
            $byProvider = EventCatalog::byProvider();

            expect(array_keys($byProvider))->toContain('ga4');
            expect(array_keys($byProvider))->toContain('meta');
            expect(array_keys($byProvider))->toContain('posthog');
            expect(array_keys($byProvider))->toContain('plausible');
            expect(array_keys($byProvider))->toContain('mixpanel');
            expect(array_keys($byProvider))->toContain('amplitude');
        });
    });
});
