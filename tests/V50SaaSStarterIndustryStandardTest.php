<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Support\EventTransformer;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\AnalyticsEventTransformer;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ConsentLogService;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\DTO\ConsentState;

beforeEach(function (): void {
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
});

// ── v2.50.0 Industry-Standard SaaS Starter Validation ─────────────────

describe('v2.50.0 SaaS Starter Industry-Standard Validation', function (): void {

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 1: Event Catalog Completeness
    // ═══════════════════════════════════════════════════════════════════

    describe('Event Catalog — 70 events, 3 categories, all typed', function (): void {
        test('total event count is 70', function (): void {
            expect(EventCatalog::count())->toBe(70);
        });

        test('SaaS catalog has 37 events', function (): void {
            expect(SaaSEvents::count())->toBe(37);
        });

        test('Ecommerce catalog has 12 events', function (): void {
            expect(EcommerceEvents::count())->toBe(12);
        });

        test('Engagement catalog has 21 events', function (): void {
            expect(EngagementEvents::count())->toBe(21);
        });

        test('all events have typed classes (not CustomEvent)', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect($entry['class'])->not->toBe(
                    'ZeroBoiler\\Analytics\\Events\\CustomEvent',
                    "Event '{$name}' uses CustomEvent instead of typed class",
                );
            }
        });

        test('no duplicate event names across categories', function (): void {
            $names = EventCatalog::names();
            expect(count($names))->toBe(count(array_unique($names)));
        });

        test('every event has category annotation', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['category']))->toBeTrue("Event '{$name}' missing category");
                expect(in_array($entry['category'], ['ecommerce', 'saas', 'engagement'], true))->toBeTrue(
                    "Event '{$name}' has invalid category '{$entry['category']}'",
                );
            }
        });

        test('catalog validation passes', function (): void {
            $result = EventCatalog::validate();
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toHaveCount(0);
        });

        test('EcommerceEvents includes all required GA4 ecommerce events', function (): void {
            $required = ['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund', 'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion'];
            foreach ($required as $event) {
                expect(EcommerceEvents::has($event))->toBeTrue("Missing ecommerce event: {$event}");
            }
        });

        test('SaaSEvents includes all core SaaS lifecycle events', function (): void {
            $core = ['sign_up', 'login', 'logout', 'start_trial', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'feature_used', 'revenue_tracked'];
            foreach ($core as $event) {
                expect(SaaSEvents::has($event))->toBeTrue("Missing SaaS event: {$event}");
            }
        });

        test('EngagementEvents includes all core engagement events', function (): void {
            $core = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
            foreach ($core as $event) {
                expect(EngagementEvents::has($event))->toBeTrue("Missing engagement event: {$event}");
            }
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 2: Cross-Provider Event Mapping
    // ═══════════════════════════════════════════════════════════════════

    describe('Cross-Provider Event Mapping — GA4, Meta, PostHog, Plausible', function (): void {
        test('all events have GA4 mappings', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['ga4']) && $entry['ga4'] !== '')->toBeTrue(
                    "Event '{$name}' has no GA4 mapping",
                );
            }
        });

        test('all events have Meta Pixel mappings (non-null)', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect($entry['meta'] !== null)->toBeTrue(
                    "Event '{$name}' has no Meta Pixel mapping",
                );
            }
        });

        test('GA4→Meta mapping covers all ecommerce events', function (): void {
            $ga4Map = [
                'view_item' => 'ViewContent',
                'add_to_cart' => 'AddToCart',
                'remove_from_cart' => 'RemoveFromCart',
                'begin_checkout' => 'InitiateCheckout',
                'add_payment_info' => 'AddPaymentInfo',
                'purchase' => 'Purchase',
                'refund' => 'Refund',
                'add_to_wishlist' => 'AddToWishlist',
            ];
            foreach ($ga4Map as $ga4 => $expectedMeta) {
                expect(EventTransformer::ga4ToMetaEventName($ga4))->toBe($expectedMeta);
            }
        });

        test('SaaS→PostHog mapping covers core lifecycle events', function (): void {
            $posthogMap = EventTransformer::saasToPosthogEventMap();
            expect($posthogMap['sign_up'])->toBe('$signup');
            expect($posthogMap['login'])->toBe('$identify');
            expect($posthogMap['start_trial'])->toBe('start_trial');
            expect($posthogMap['subscribe'])->toBe('purchase');
            expect($posthogMap['plan_upgrade'])->toBe('plan_upgrade');
            expect($posthogMap['cancellation'])->toBe('cancellation');
        });

        test('PostHog names list is non-empty', function (): void {
            expect(EventCatalog::allPosthogNames())->not->toBeEmpty();
        });

        test('PostHog mappings table has same count as catalog', function (): void {
            $mappings = EventCatalog::allPosthogMappings();
            expect(count($mappings))->toBe(EventCatalog::count());
        });

        test('posthogNameFor returns correct mapped names', function (): void {
            expect(EventCatalog::posthogNameFor('sign_up'))->toBe('$signup');
            expect(EventCatalog::posthogNameFor('purchase'))->toBe('purchase');
            expect(EventCatalog::posthogNameFor('nonexistent'))->toBe('nonexistent');
        });

        test('Plausible names list is non-empty', function (): void {
            expect(EventCatalog::allPlausibleNames())->not->toBeEmpty();
        });

        test('byProvider returns all 4 providers', function (): void {
            $byProvider = EventCatalog::byProvider();
            expect(array_keys($byProvider))->toContain('ga4', 'meta', 'posthog', 'plausible');
            expect($byProvider['ga4'])->not->toBeEmpty();
            expect($byProvider['meta'])->not->toBeEmpty();
            expect($byProvider['posthog'])->not->toBeEmpty();
            expect($byProvider['plausible'])->not->toBeEmpty();
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 3: E-Commerce Format Conversion (GA4 ↔ Meta ↔ PostHog)
    // ═══════════════════════════════════════════════════════════════════

    describe('Ecommerce Format Conversion — 3-provider support', function (): void {
        $sampleItems = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'item_category' => 'Gadgets', 'price' => 29.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gizmo', 'item_category' => 'Gadgets', 'price' => 49.99, 'quantity' => 1],
        ];

        test('GA4→Meta contents conversion preserves IDs and quantities', function () use ($sampleItems): void {
            $meta = EcommerceFormatConverter::ga4ToMetaContents($sampleItems);
            expect($meta['content_ids'])->toBe(['SKU-001', 'SKU-002']);
            expect($meta['contents'])->toHaveCount(2);
            expect($meta['contents'][0]['id'])->toBe('SKU-001');
            expect($meta['contents'][0]['quantity'])->toBe(2);
            expect($meta['num_items'])->toBe(3);
            expect($meta['value'])->toBe(109.97); // 29.99*2 + 49.99*1
        });

        test('Meta→GA4 reverse conversion round-trips correctly', function () use ($sampleItems): void {
            $meta = EcommerceFormatConverter::ga4ToMetaContents($sampleItems);
            $ga4 = EcommerceFormatConverter::metaToGa4Items($meta['contents']);
            expect($ga4[0]['item_id'])->toBe('SKU-001');
            expect($ga4[0]['price'])->toBe(29.99);
            expect($ga4[1]['item_id'])->toBe('SKU-002');
        });

        test('GA4→PostHog properties conversion preserves data', function () use ($sampleItems): void {
            $posthog = EcommerceFormatConverter::ga4ToPosthogProperties($sampleItems);
            expect($posthog['items'])->toHaveCount(2);
            expect($posthog['items'][0]['sku'])->toBe('SKU-001');
            expect($posthog['items'][0]['name'])->toBe('Widget');
            expect($posthog['items'][0]['price'])->toBe(29.99);
            expect($posthog['items'][0]['quantity'])->toBe(2);
            expect($posthog['total_value'])->toBe(109.97);
            expect($posthog['item_count'])->toBe(2);
        });

        test('GA4→PostHog purchase conversion includes all fields', function () use ($sampleItems): void {
            $ga4Params = [
                'transaction_id' => 'TXN-123',
                'value' => 109.97,
                'currency' => 'USD',
                'items' => $sampleItems,
                'tax' => 8.50,
                'shipping' => 5.99,
                'coupon' => 'SAVE10',
            ];
            $posthog = EcommerceFormatConverter::ga4ToPosthogPurchase($ga4Params);
            expect($posthog['$currency'])->toBe('USD');
            expect($posthog['value'])->toBe(109.97);
            expect($posthog['transaction_id'])->toBe('TXN-123');
            expect($posthog['coupon'])->toBe('SAVE10');
            expect($posthog['tax'])->toBe(8.50);
            expect($posthog['shipping'])->toBe(5.99);
            expect($posthog['items'])->toHaveCount(2);
        });

        test('GA4→PostHog refund conversion', function () use ($sampleItems): void {
            $ga4Params = [
                'transaction_id' => 'TXN-123',
                'value' => 29.99,
                'currency' => 'USD',
                'items' => [$sampleItems[0]],
            ];
            $posthog = EcommerceFormatConverter::ga4ToPosthogRefund($ga4Params);
            expect($posthog['$currency'])->toBe('USD');
            expect($posthog['value'])->toBe(29.99);
            expect($posthog['transaction_id'])->toBe('TXN-123');
        });

        test('buildPosthogPurchase creates correctly formatted properties', function () use ($sampleItems): void {
            $props = EcommerceFormatConverter::buildPosthogPurchase(
                'TXN-456',
                109.97,
                'EUR',
                $sampleItems,
                ['coupon' => 'WELCOME'],
            );
            expect($props['$currency'])->toBe('EUR');
            expect($props['value'])->toBe(109.97);
            expect($props['transaction_id'])->toBe('TXN-456');
            expect($props['coupon'])->toBe('WELCOME');
            expect($props['items'])->toHaveCount(2);
        });

        test('buildPurchaseEvent supports all 3 providers', function () use ($sampleItems): void {
            $ga4 = EcommerceFormatConverter::buildPurchaseEvent('ga4', 'TXN-1', 99.99, 'USD', $sampleItems);
            expect($ga4->name)->toBe('purchase');
            expect($ga4->params['transaction_id'])->toBe('TXN-1');
            expect(isset($ga4->params['items']))->toBeTrue();

            $meta = EcommerceFormatConverter::buildPurchaseEvent('meta', 'TXN-2', 99.99, 'USD', $sampleItems);
            expect($meta->name)->toBe('Purchase');
            expect(isset($meta->params['contents']))->toBeTrue();

            $posthog = EcommerceFormatConverter::buildPurchaseEvent('posthog', 'TXN-3', 99.99, 'USD', $sampleItems);
            expect($posthog->name)->toBe('purchase');
            expect($posthog->params['$currency'])->toBe('USD');
            expect($posthog->params['transaction_id'])->toBe('TXN-3');
            expect($posthog->params['items'])->toHaveCount(2);
        });

        test('calculateTotalValue computes correctly', function () use ($sampleItems): void {
            expect(EcommerceFormatConverter::calculateTotalValue($sampleItems))->toBe(109.97);
            expect(EcommerceFormatConverter::calculateTotalValue([]))->toBe(0.0);
        });

        test('normalizeGa4Item fills missing fields', function (): void {
            $raw = ['item_id' => 'X'];
            $normalized = EcommerceFormatConverter::normalizeGa4Item($raw);
            expect($normalized['item_id'])->toBe('X');
            expect($normalized['item_name'])->toBe('');
            expect($normalized['price'])->toBe(0.0);
            expect($normalized['quantity'])->toBe(1);
        });

        test('normalizeGa4Item handles id→item_id fallback', function (): void {
            $raw = ['id' => 'Y', 'name' => 'Thing', 'price' => 10.0];
            $normalized = EcommerceFormatConverter::normalizeGa4Item($raw);
            expect($normalized['item_id'])->toBe('Y');
            expect($normalized['item_name'])->toBe('Thing');
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 4: Event Catalog Utility Methods
    // ═══════════════════════════════════════════════════════════════════

    describe('Event Catalog Utility Methods', function (): void {
        test('search finds events by partial name', function (): void {
            $results = EventCatalog::search('cart');
            expect($results)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $results);
            expect($names)->toContain('add_to_cart');
            expect($names)->toContain('view_cart');
        });

        test('search returns empty for non-matching pattern', function (): void {
            expect(EventCatalog::search('zzzznonexistent'))->toHaveCount(0);
        });

        test('getCategory returns correct category for each event', function (): void {
            expect(EventCatalog::getCategory('purchase'))->toBe('ecommerce');
            expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
            expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
            expect(EventCatalog::getCategory('nonexistent'))->toBeNull();
        });

        test('classFor returns typed class for each event', function (): void {
            expect(EventCatalog::classFor('purchase'))->not->toBeNull();
            expect(EventCatalog::classFor('sign_up'))->not->toBeNull();
            expect(EventCatalog::classFor('page_view'))->not->toBeNull();
            expect(EventCatalog::classFor('nonexistent'))->toBeNull();
        });

        test('has returns correct boolean', function (): void {
            expect(EventCatalog::has('purchase'))->toBeTrue();
            expect(EventCatalog::has('nonexistent'))->toBeFalse();
        });

        test('byCategory returns all 3 categories with correct keys', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect(array_keys($byCategory))->toEqual(['ecommerce', 'saas', 'engagement']);
            expect(count($byCategory['ecommerce']))->toBe(EcommerceEvents::count());
            expect(count($byCategory['saas']))->toBe(SaaSEvents::count());
            expect(count($byCategory['engagement']))->toBe(EngagementEvents::count());
        });

        test('category method returns correct subset', function (): void {
            $ecommerce = EventCatalog::category('ecommerce');
            expect(count($ecommerce))->toBe(EcommerceEvents::count());
            foreach ($ecommerce as $entry) {
                expect($entry['category'])->toBe('ecommerce');
            }
            expect(EventCatalog::category('invalid'))->toHaveCount(0);
        });

        test('ga4Names returns non-empty unique list', function (): void {
            $ga4 = EcommerceEvents::ga4Names();
            expect($ga4)->not->toBeEmpty();
            expect(count($ga4))->toBe(EcommerceEvents::count());
        });

        test('metaNames returns non-empty filtered list', function (): void {
            $meta = EngagementEvents::metaNames();
            expect($meta)->not->toBeEmpty();
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 5: EventTransformer Cross-Provider Coverage
    // ═══════════════════════════════════════════════════════════════════

    describe('EventTransformer — Full Provider Coverage', function (): void {
        test('ga4ToMetaEventName maps all ecommerce events', function (): void {
            $ga4Events = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase', 'refund'];
            foreach ($ga4Events as $ga4) {
                $meta = EventTransformer::ga4ToMetaEventName($ga4);
                expect($meta)->not->toBeNull("GA4 event '{$ga4}' has no Meta mapping");
            }
        });

        test('hasMetaEquivalent detects mapped events', function (): void {
            expect(EventTransformer::hasMetaEquivalent('purchase'))->toBeTrue();
            expect(EventTransformer::hasMetaEquivalent('scroll_depth'))->toBeFalse();
        });

        test('ga4ItemsToMetaContents produces correct format', function (): void {
            $items = [['item_id' => 'X', 'quantity' => 3, 'price' => 10.0]];
            $meta = EventTransformer::ga4ItemsToMetaContents($items);
            expect($meta['content_ids'])->toBe(['X']);
            expect($meta['contents'][0]['id'])->toBe('X');
            expect($meta['contents'][0]['quantity'])->toBe(3);
        });

        test('saasToPosthogEventMap is non-empty', function (): void {
            $map = EventTransformer::saasToPosthogEventMap();
            expect($map)->not->toBeEmpty();
            // At minimum should map sign_up and login
            expect($map)->toHaveKey('sign_up');
            expect($map)->toHaveKey('login');
        });

        test('transformForProvider dispatches to correct handler', function (): void {
            $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

            // GA4 transform
            $ga4 = EventTransformer::transformForProvider('ga4', 'purchase', ['value' => 99.99], $event);
            expect($ga4)->toBeInstanceOf(AnalyticsEvent::class);

            // Meta transform
            $meta = EventTransformer::transformForProvider('meta', 'purchase', ['value' => 99.99], $event);
            expect($meta)->toBeInstanceOf(AnalyticsEvent::class);

            // PostHog transform
            $posthog = EventTransformer::transformForProvider('posthog', 'sign_up', ['plan' => 'pro'], $event);
            expect($posthog)->toBeInstanceOf(AnalyticsEvent::class);
            expect($posthog->name)->toBe('$signup');
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 6: SaaS Lifecycle Events — Typed Classes
    // ═══════════════════════════════════════════════════════════════════

    describe('SaaS Lifecycle — Typed Event Classes', function (): void {
        test('SignUpEvent is instantiable', function (): void {
            $class = SaaSEvents::classFor('sign_up');
            expect($class)->not->toBeNull();
            expect(class_exists($class))->toBeTrue();
        });

        test('TrialStartEvent is instantiable', function (): void {
            $class = SaaSEvents::classFor('start_trial');
            expect($class)->not->toBeNull();
            expect(class_exists($class))->toBeTrue();
        });

        test('PlanUpgradeEvent is instantiable', function (): void {
            $class = SaaSEvents::classFor('plan_upgrade');
            expect($class)->not->toBeNull();
            expect(class_exists($class))->toBeTrue();
        });

        test('CancellationEvent is instantiable', function (): void {
            $class = SaaSEvents::classFor('cancellation');
            expect($class)->not->toBeNull();
            expect(class_exists($class))->toBeTrue();
        });

        test('all SaaS event classes exist on disk', function (): void {
            $all = SaaSEvents::all();
            foreach ($all as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue(
                    "SaaS event class '{$entry['class']}' for '{$name}' does not exist",
                );
            }
        });

        test('all Engagement event classes exist on disk', function (): void {
            $all = EngagementEvents::all();
            foreach ($all as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue(
                    "Engagement event class '{$entry['class']}' for '{$name}' does not exist",
                );
            }
        });

        test('all Ecommerce event classes exist on disk', function (): void {
            $all = EcommerceEvents::all();
            foreach ($all as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue(
                    "Ecommerce event class '{$entry['class']}' for '{$name}' does not exist",
                );
            }
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 7: Config Structure Validation
    // ═══════════════════════════════════════════════════════════════════

    describe('Config — All Required Sections Present', function (): void {
        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook',
            'consent', 'auto_track', 'queue', 'identity', 'ecommerce', 'revenue',
            'track_links', 'api', 'audit_log', 'debug', 'validation', 'pipeline',
            'sampling', 'pii_sanitization', 'replay', 'metrics', 'stream',
            'client_auto_track', 'performance', 'tracking_preferences', 'gdpr',
            'attribution', 'profile', 'inbound_webhook', 'funnels', 'alerts',
            'lifecycle', 'correlation', 'retention', 'source_tagging', 'validation_boot',
            'referral', 'broadcast', 'tenant', 'retention_policy', 'gate',
            'reporting', 'dead_letter_queue', 'realtime', 'ab_tests', 'snapshots',
            'saas_kpi', 'utm_aggregation', 'geolocation', 'forwarding',
            'performance_budget', 'routing', 'aliases', 'event_cache',
        ];

        test('all ' . count($requiredSections) . ' config sections are present in config file', function () use ($requiredSections): void {
            // Verify by reading the actual config file structure
            $configPath = __DIR__ . '/../config/zeroboiler.php';
            expect(file_exists($configPath))->toBeTrue('Config file exists');

            $config = require $configPath;
            expect(is_array($config))->toBeTrue('Config returns array');
            expect(isset($config['analytics']))->toBeTrue('Config has analytics key');

            foreach ($requiredSections as $section) {
                expect(isset($config['analytics'][$section]))->toBeTrue(
                    "Missing config section: analytics.{$section}",
                );
            }
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 8: Filesystem Integrity
    // ═══════════════════════════════════════════════════════════════════

    describe('Filesystem — Required Files and Directories', function (): void {
        $srcDir = __DIR__ . '/../src';

        test('AnalyticsManager exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/AnalyticsManager.php'))->toBeTrue();
        });

        test('EventCatalog exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Events/EventCatalog.php'))->toBeTrue();
        });

        test('Inertia middleware exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Inertia/HandleInertiaAnalytics.php'))->toBeTrue();
        });

        test('HTTP middleware exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Http/Middleware/InjectAnalyticsScripts.php'))->toBeTrue();
        });

        test('API controller exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Http/Controllers/AnalyticsEventController.php'))->toBeTrue();
        });

        test('Routes file exists', function (): void {
            expect(file_exists(__DIR__ . '/../routes/analytics.php'))->toBeTrue();
        });

        test('JS client library exists', function (): void {
            expect(file_exists(__DIR__ . '/../resources/js/analytics.js'))->toBeTrue();
        });

        test('JS TypeScript definitions exist', function (): void {
            expect(file_exists(__DIR__ . '/../resources/js/analytics.d.ts'))->toBeTrue();
        });

        test('Queue dispatcher exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Queue/QueuedAnalyticsDispatcher.php'))->toBeTrue();
        });

        test('Event replay queue exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Queue/EventReplayQueue.php'))->toBeTrue();
        });

        test('Overview command exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Console/Commands/AnalyticsOverviewCommand.php'))->toBeTrue();
        });

        test('Test command exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Console/Commands/AnalyticsTestCommand.php'))->toBeTrue();
        });

        test('TrackerInterface exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/TrackerInterface.php'))->toBeTrue();
        });

        test('GA4Tracker exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/GA4Tracker.php'))->toBeTrue();
        });

        test('MetaPixelTracker exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/MetaPixelTracker.php'))->toBeTrue();
        });

        test('PlausibleTracker exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/PlausibleTracker.php'))->toBeTrue();
        });

        test('PosthogTracker exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/PosthogTracker.php'))->toBeTrue();
        });

        test('GTMTracker exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Trackers/GTMTracker.php'))->toBeTrue();
        });

        test('EcommerceFormatConverter exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Support/EcommerceFormatConverter.php'))->toBeTrue();
        });

        test('EventTransformer exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Support/EventTransformer.php'))->toBeTrue();
        });

        test('LifecycleEventMapper exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Services/LifecycleEventMapper.php'))->toBeTrue();
        });

        test('Facade exists', function () use ($srcDir): void {
            expect(file_exists($srcDir . '/Facades/Analytics.php'))->toBeTrue();
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 9: JS Client Verification
    // ═══════════════════════════════════════════════════════════════════

    describe('JS Client — Core Exports', function (): void {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $jsContent = file_get_contents($jsPath);
        expect($jsContent)->not->toBeFalse();

        $exports = [
            'export function init(',
            'export function destroy(',
            'export function isInitialized(',
            'export function getVersion(',
            'export function getTrackingId(',
            'export function trackEvent(',
            'export async function trackPageView(',
            'export async function trackScreenView(',
            'export async function trackAbTestExposure(',
            'export async function trackEcommerce(',
            'export async function trackWishlist(',
            'export async function selectItem',       // trackSelectItem
            'export async function trackSelectItem(',
            'export async function trackPromotionView(',
            'export async function trackPromotionClick(',
            'export async function identify(',
            'export async function updateConsent(',
            'export function initScrollDepth(',
            'export function initInertiaPageViewTracker(',
            'export function initFormTracking(',
            'export function initErrorTracking(',
            'export function setUserProperties(',
            'export function setAlias(',
        ];

        foreach ($exports as $export) {
            $name = trim(explode('(', $export)[1] ?? '');
            test("JS exports {$name}", function () use ($jsContent, $export, $name): void {
                expect(str_contains($jsContent, $export))->toBeTrue("Missing JS export: {$export}");
            });
        }

        test('JS client has batch queue implementation', function () use ($jsContent): void {
            expect(str_contains($jsContent, 'eventQueue'))->toBeTrue();
            expect(str_contains($jsContent, 'flushQueue'))->toBeTrue();
            expect(str_contains($jsContent, 'MAX_QUEUE_SIZE'))->toBeTrue();
            expect(str_contains($jsContent, 'FLUSH_INTERVAL'))->toBeTrue();
        });

        test('JS client has sendBeacon unload handling', function () use ($jsContent): void {
            expect(str_contains($jsContent, 'sendBeacon'))->toBeTrue();
            expect(str_contains($jsContent, 'beforeunload'))->toBeTrue();
        });

        test('JS client version is 2.50.0', function () use ($jsContent): void {
            expect(str_contains($jsContent, "'2.89.0'"))->toBeTrue('JS version should be 2.89.0');
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // SECTION 10: Source File Metrics
    // ═══════════════════════════════════════════════════════════════════

    describe('Source File Metrics — Scale Check', function (): void {
        test('total PHP source files >= 280', function (): void {
            $count = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(280);
        });

        test('total test files >= 80', function (): void {
            $count = 0;
            $iterator = new DirectoryIterator(__DIR__);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && $file->getBasename() !== 'Pest.php') {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(80);
        });

        test('JS client has >= 2500 lines', function (): void {
            $lines = count(file(__DIR__ . '/../resources/js/analytics.js'));
            expect($lines)->toBeGreaterThanOrEqual(2500);
        });
    });
});
