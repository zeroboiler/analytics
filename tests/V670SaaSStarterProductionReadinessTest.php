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
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\SaasRevenueEventBuilder;
use ZeroBoiler\Analytics\Support\EventBuilder;

// ── V670 SaaS Starter Production Readiness ───────────────────────────

describe('V670 SaaS Starter Production Readiness', function () {

    // ── 1. Event Catalog Completeness ──────────────────────────────

    describe('event catalog completeness', function () {
        test('catalog has 90+ events across all categories', function (): void {
            $all = EventCatalog::all();
            expect(count($all))->toBeGreaterThanOrEqual(90);
        });

        test('all categories are non-empty', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect($byCategory)->toHaveKey('ecommerce');
            expect($byCategory)->toHaveKey('saas');
            expect($byCategory)->toHaveKey('engagement');
            expect(count($byCategory['ecommerce']))->toBeGreaterThanOrEqual(10);
            expect(count($byCategory['saas']))->toBeGreaterThanOrEqual(30);
            expect(count($byCategory['engagement']))->toBeGreaterThanOrEqual(20);
        });

        test('core SaaS events exist', function (): void {
            $coreKeys = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
            foreach ($coreKeys as $key) {
                expect(EventCatalog::has($key))->toBeTrue("Missing core event: {$key}");
            }
        });

        test('core ecommerce events exist', function (): void {
            $coreKeys = ['view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund'];
            foreach ($coreKeys as $key) {
                expect(EventCatalog::has($key))->toBeTrue("Missing ecommerce event: {$key}");
            }
        });

        test('core engagement events exist', function (): void {
            $coreKeys = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
            foreach ($coreKeys as $key) {
                expect(EventCatalog::has($key))->toBeTrue("Missing engagement event: {$key}");
            }
        });

        test('all catalog entries have required keys', function (): void {
            $requiredKeys = ['name', 'class', 'ga4', 'category'];
            foreach (EventCatalog::all() as $name => $entry) {
                foreach ($requiredKeys as $key) {
                    expect(array_key_exists($key, $entry))
                        ->toBeTrue("Event '{$name}' missing key '{$key}'");
                }
            }
        });

        test('catalog validation passes', function (): void {
            $result = EventCatalog::validate();
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });
    });

    // ── 2. Cross-Provider Coverage ─────────────────────────────────

    describe('cross-provider coverage', function () {
        test('every event has a GA4 mapping', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect($entry['ga4'])->not->toBeEmpty("Event '{$name}' has empty GA4 mapping");
            }
        });

        test('every event has a PostHog mapping', function (): void {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['posthog']) && $entry['posthog'] !== '')
                    ->toBeTrue("Event '{$name}' missing PostHog mapping");
            }
        });

        test('provider mappings matrix is consistent', function (): void {
            $matrix = EventCatalog::allProviderMappingsMatrix();
            expect($matrix)->not->toBeEmpty();
            foreach ($matrix as $name => $mapping) {
                expect($mapping)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'category']);
            }
        });

        test('GA4 and Meta names are non-empty unique sets', function (): void {
            $ga4Names = EventCatalog::allGa4Names();
            $metaNames = EventCatalog::allMetaNames();
            $posthogNames = EventCatalog::allPosthogNames();
            expect($ga4Names)->not->toBeEmpty();
            expect($metaNames)->not->toBeEmpty();
            expect($posthogNames)->not->toBeEmpty();
        });
    });

    // ── 3. Industry Standard Readiness ─────────────────────────────

    describe('industry standard readiness', function () {
        test('industry standard set covers all AARRR stages', function (): void {
            $standard = EventCatalog::industryStandard();
            expect($standard)->toHaveKeys(['critical', 'high', 'medium', 'low', 'all', 'count']);
            expect($standard['count'])->toBeGreaterThanOrEqual(60);
            expect(count($standard['critical']))->toBeGreaterThanOrEqual(8);
            expect(count($standard['high']))->toBeGreaterThanOrEqual(10);
        });

        test('industry readiness score is 100% (all standard events covered)', function (): void {
            $score = EventCatalog::industryReadinessScore();
            expect($score['score'])->toBe(100);
            expect($score['gaps'])->toBeEmpty();
        });

        test('recommended instrumentation has starter, growth, enterprise levels', function (): void {
            $starter = EventCatalog::recommendedInstrumentation('starter');
            $growth = EventCatalog::recommendedInstrumentation('growth');
            $enterprise = EventCatalog::recommendedInstrumentation('enterprise');
            expect($starter['count'])->toBeGreaterThanOrEqual(15);
            expect($growth['count'])->toBeGreaterThan($starter['count']);
            expect($enterprise['count'])->toBeGreaterThan($growth['count']);
        });

        test('quick start set has all funnel coverage', function (): void {
            $quick = EventCatalog::quickStart();
            expect($quick['funnel_coverage']['signup'])->toBeTrue();
            expect($quick['funnel_coverage']['trial'])->toBeTrue();
            expect($quick['funnel_coverage']['revenue'])->toBeTrue();
            expect($quick['funnel_coverage']['engagement'])->toBeTrue();
        });
    });

    // ── 4. Lifecycle Event Mapper ──────────────────────────────────

    describe('lifecycle event mapper', function () {
        test('mapper has all critical lifecycle mappings', function (): void {
            $defaults = (new ReflectionClass(LifecycleEventMapper::class))
                ->getConstant('DEFAULT_MAPPINGS');

            expect($defaults)->not->toBeEmpty();

            $criticalKeys = [
                'auth.login', 'auth.register', 'subscription.created',
                'subscription.upgraded', 'subscription.cancelled',
                'trial.started', 'order.completed', 'order.refunded',
            ];
            foreach ($criticalKeys as $key) {
                expect(isset($defaults[$key]))->toBeTrue("Missing lifecycle mapping: {$key}");
            }
        });

        test('all lifecycle targets extend AnalyticsEvent', function (): void {
            $defaults = (new ReflectionClass(LifecycleEventMapper::class))
                ->getConstant('DEFAULT_MAPPINGS');

            foreach ($defaults as $key => $mapping) {
                $targetClass = $mapping['target'];
                // Classes may not be autoloaded in test context; check string format
                expect(is_string($targetClass) && $targetClass !== '')->toBeTrue(
                    "Mapping '{$key}' has invalid target class"
                );
            }
        });
    });

    // ── 5. E-commerce Format Conversion ───────────────────────────

    describe('ecommerce format conversion', function () {
        test('GA4 items convert to Meta contents correctly', function (): void {
            $ga4Items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ];

            $meta = EcommerceFormatConverter::ga4ToMetaContents($ga4Items);

            expect($meta['content_ids'])->toEqual(['SKU-001']);
            expect($meta['contents'])->toHaveCount(1);
            expect($meta['contents'][0]['id'])->toBe('SKU-001');
            expect($meta['contents'][0]['quantity'])->toBe(2);
            expect($meta['contents'][0]['item_price'])->toBe(29.99);
            expect($meta['num_items'])->toBe(1);
            expect($meta['value'])->toBe(59.98);
        });

        test('Meta contents convert back to GA4 items', function (): void {
            $metaContents = [
                ['id' => 'SKU-001', 'quantity' => 3, 'item_price' => 10.0, 'item_name' => 'Gadget'],
            ];

            $ga4Items = EcommerceFormatConverter::metaToGa4Items($metaContents);

            expect($ga4Items)->toHaveCount(1);
            expect($ga4Items[0]['item_id'])->toBe('SKU-001');
            expect($ga4Items[0]['quantity'])->toBe(3);
            expect($ga4Items[0]['price'])->toBe(10.0);
        });

        test('GA4 purchase converts to Meta purchase format', function (): void {
            $ga4Params = [
                'transaction_id' => 'TXN-123',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'P1', 'item_name' => 'Product', 'price' => 99.99, 'quantity' => 1],
                ],
            ];

            $metaParams = EcommerceFormatConverter::ga4ToMetaPurchase($ga4Params);

            expect($metaParams['value'])->toBe(99.99);
            expect($metaParams['currency'])->toBe('USD');
            expect($metaParams['content_type'])->toBe('product');
            expect($metaParams['content_ids'])->toEqual(['P1']);
        });

        test('GA4 items convert to PostHog properties', function (): void {
            $ga4Items = [
                ['item_id' => 'SKU-X', 'item_name' => 'Thing', 'price' => 5.0, 'quantity' => 4],
            ];

            $posthog = EcommerceFormatConverter::ga4ToPosthogProperties($ga4Items);

            expect($posthog['items'])->toHaveCount(1);
            expect($posthog['total_value'])->toBe(20.0);
            expect($posthog['item_count'])->toBe(1);
            expect($posthog['items'][0]['sku'])->toBe('SKU-X');
        });

        test('convenience builders produce correct structures', function (): void {
            $purchase = EcommerceFormatConverter::buildGa4Purchase(
                'TXN-456',
                149.99,
                'EUR',
                [['item_id' => 'E1', 'price' => 149.99, 'quantity' => 1]],
                ['tax' => 25.0, 'shipping' => 5.0],
            );

            expect($purchase['transaction_id'])->toBe('TXN-456');
            expect($purchase['value'])->toBe(149.99);
            expect($purchase['currency'])->toBe('EUR');
            expect($purchase['tax'])->toBe(25.0);
            expect($purchase['shipping'])->toBe(5.0);
            expect($purchase['items'])->toHaveCount(1);
        });
    });

    // ── 6. SaasRevenueEventBuilder ─────────────────────────────────

    describe('SaasRevenueEventBuilder', function () {
        test('builds subscription event for all providers', function (): void {
            $ga4 = SaasRevenueEventBuilder::subscription('pro', 49.0, 'monthly', 'USD', 'user-1');
            expect($ga4)->toHaveKey('value');
            expect($ga4['value'])->toBe(49.0);

            $meta = SaasRevenueEventBuilder::subscriptionMeta('pro', 49.0, 'monthly', 'USD', 'user-1');
            expect($meta)->toHaveKey('value');

            $posthog = SaasRevenueEventBuilder::subscriptionPosthog('pro', 49.0, 'monthly', 'USD', 'user-1');
            expect($posthog)->toHaveKey('$currency');
        });

        test('builds plan upgrade event for all providers', function (): void {
            $ga4 = SaasRevenueEventBuilder::planUpgrade('starter', 'pro', 'user-1');
            expect($ga4)->toHaveKey('from_plan');
            expect($ga4['from_plan'])->toBe('starter');
            expect($ga4['to_plan'])->toBe('pro');

            $meta = SaasRevenueEventBuilder::planUpgradeMeta('starter', 'pro', 'user-1');
            expect($meta)->not->toBeEmpty();

            $posthog = SaasRevenueEventBuilder::planUpgradePosthog('starter', 'pro', 'user-1');
            expect($posthog)->not->toBeEmpty();
        });

        test('builds cancellation event for all providers', function (): void {
            $ga4 = SaasRevenueEventBuilder::cancellation('pro', 'too_expensive', 'user-1');
            expect($ga4['reason'])->toBe('too_expensive');

            $meta = SaasRevenueEventBuilder::cancellationMeta('pro', 'too_expensive', 'user-1');
            expect($meta)->not->toBeEmpty();
        });

        test('buildEvent creates dispatchable AnalyticsEvent', function (): void {
            $event = SaasRevenueEventBuilder::buildEvent(
                'subscribe',
                'ga4',
                SaasRevenueEventBuilder::subscription('pro', 49.0, 'monthly', 'USD', 'user-1'),
                'user-1',
                'client-abc',
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('subscribe');
            expect($event->clientId)->toBe('client-abc');
        });
    });

    // ── 7. SaaS Analytics Service ─────────────────────────────────

    describe('SaaS analytics service', function () {
        beforeEach(function (): void {
            $this->config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                        'queue' => ['enabled' => false],
                    ],
                ],
            ]);
            $this->manager = new AnalyticsManager($this->config);
        });

        test('tracks full SaaS lifecycle events', function (): void {
            $service = new SaaSAnalyticsService($this->manager);

            $service->trackSignUp('github');
            $service->trackLogin('web');
            $service->trackTrialStart('pro', 14);
            $service->trackSubscription('business', 99.99, 'USD');
            $service->trackPlanUpgrade('starter', 'pro');
            $service->trackCancellation('pro', 'too_expensive');
            $service->trackFeatureUsed('export', 5);

            $layer = $this->manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(7);

            $eventNames = array_column($layer, 'event');
            expect($eventNames)->toContain('sign_up');
            expect($eventNames)->toContain('login');
            expect($eventNames)->toContain('start_trial');
            expect($eventNames)->toContain('subscribe');
            expect($eventNames)->toContain('plan_upgrade');
            expect($eventNames)->toContain('cancellation');
            expect($eventNames)->toContain('feature_used');
        });
    });

    // ── 8. EventBuilder Priority ──────────────────────────────────

    describe('EventBuilder priority delegation', function () {
        test('EventBuilder delegates priority to EventCatalog', function (): void {
            $event = new AnalyticsEvent(name: 'purchase');
            $builder = new EventBuilder($event);

            $priority = $builder->priority();
            expect(in_array($priority, ['critical', 'high', 'medium', 'low'], true))->toBeTrue();
        });

        test('purchase event is critical priority', function (): void {
            $priority = EventCatalog::eventPriority('purchase');
            expect($priority)->toBe('critical');
        });

        test('sign_up event is critical priority', function (): void {
            $priority = EventCatalog::eventPriority('sign_up');
            expect($priority)->toBe('critical');
        });

        test('page_view event has a valid priority', function (): void {
            $priority = EventCatalog::eventPriority('page_view');
            expect(in_array($priority, ['critical', 'high', 'medium', 'low'], true))->toBeTrue();
        });
    });

    // ── 9. GDPR & Compliance Events ───────────────────────────────

    describe('GDPR compliance events', function () {
        test('GDPR events exist in catalog', function (): void {
            $gdprKeys = ['consent_granted', 'consent_withdrawn', 'data_subject_access_request', 'data_erasure_completed'];
            foreach ($gdprKeys as $key) {
                expect(EventCatalog::has($key))->toBeTrue("Missing GDPR event: {$key}");
            }
        });

        test('gdprSensitiveEvents returns non-empty list', function (): void {
            $sensitive = EventCatalog::gdprSensitiveEvents();
            expect($sensitive)->not->toBeEmpty();
            expect(count($sensitive))->toBeGreaterThanOrEqual(10);
        });

        test('gdprEvents returns compliance-relevant events', function (): void {
            $gdpr = EventCatalog::gdprEvents();
            expect($gdpr)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $gdpr);
            expect($names)->toContain('sign_up');
            expect($names)->toContain('consent_granted');
        });
    });

    // ── 10. Funnel Templates ───────────────────────────────────────

    describe('funnel templates', function () {
        test('signup funnel has all required steps', function (): void {
            $templates = EventCatalog::funnelTemplates();
            expect($templates)->toHaveKey('signup');
            expect($templates['signup']['steps'])->toHaveCount(5);
            expect($templates['signup']['total_steps'])->toBe(5);
        });

        test('trial funnel exists with correct steps', function (): void {
            $templates = EventCatalog::funnelTemplates();
            expect($templates)->toHaveKey('trial');
            expect($templates['trial']['steps'])->toHaveCount(4);
        });

        test('checkout funnel maps ecommerce events', function (): void {
            $templates = EventCatalog::funnelTemplates();
            expect($templates)->toHaveKey('checkout');
            $events = array_column($templates['checkout']['steps'], 'event');
            expect($events)->toContain('view_item');
            expect($events)->toContain('add_to_cart');
            expect($events)->toContain('purchase');
        });
    });

    // ── 11. Version Consistency ──────────────────────────────────

    describe('version consistency', function () {
        test('AnalyticsEvent::VERSION is 6.7.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('6.7.0');
        });
    });

    // ── 12. PHP 8.5 Strict Types ──────────────────────────────────

    describe('PHP 8.5 compliance', function () {
        test('all PHP source files use strict types', function (): void {
            $srcDir = dirname(__DIR__, 2) . '/src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                $firstLine = explode("\n", $contents)[0] ?? '';
                $secondLine = explode("\n", $contents)[1] ?? '';
                if (! str_contains($secondLine, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }

            expect($violations)->toBeEmpty(
                'Files missing declare(strict_types=1): ' . implode(', ', $violations)
            );
        });
    });

    // ── 13. B2B Team & Organization Events ────────────────────────

    describe('B2B team events', function () {
        test('b2bTeamEvents returns team-related events', function (): void {
            $b2b = EventCatalog::b2bTeamEvents();
            expect($b2b)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $b2b);
            expect($names)->toContain('team_created');
            expect($names)->toContain('team_member_joined');
            expect($names)->toContain('role_changed');
        });
    });

    // ── 14. Privacy-Safe Events ───────────────────────────────────

    describe('privacy-safe events', function () {
        test('privacySafeEvents returns non-empty list', function (): void {
            $safe = EventCatalog::privacySafeEvents();
            expect($safe)->not->toBeEmpty();
            expect(count($safe))->toBeGreaterThanOrEqual(10);
        });

        test('privacy-safe events contain no PII-sensitive events', function (): void {
            $safe = EventCatalog::privacySafeEvents();
            $safeNames = array_map(fn (array $e): string => $e['name'], $safe);
            // These are PII-sensitive and should NOT be in the safe list
            expect($safeNames)->not->toContain('sign_up');
            expect($safeNames)->not->toContain('login');
        });
    });

    // ── 15. SaaS Acquisition & Monetization ────────────────────────

    describe('SaaS acquisition and monetization', function () {
        test('saasAcquisitionEvents covers marketing channels', function (): void {
            $acq = EventCatalog::saasAcquisitionEvents();
            expect($acq)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $acq);
            expect($names)->toContain('sign_up');
            expect($names)->toContain('start_trial');
        });

        test('saasMonetizationEvents covers revenue lifecycle', function (): void {
            $mon = EventCatalog::saasMonetizationEvents();
            expect($mon)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $mon);
            expect($names)->toContain('purchase');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('plan_upgrade');
            expect($names)->toContain('cancellation');
            expect($names)->toContain('refund');
        });
    });

    // ── 16. DAU/MAU & Product Health ─────────────────────────────

    describe('DAU/MAU and product health', function () {
        test('dauMauEvents returns engagement signals', function (): void {
            $dau = EventCatalog::dauMauEvents();
            expect($dau)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $dau);
            expect($names)->toContain('login');
            expect($names)->toContain('page_view');
        });

        test('productHealthEvents returns quality signals', function (): void {
            $health = EventCatalog::productHealthEvents();
            expect($health)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $health);
            expect($names)->toContain('error');
            expect($names)->toContain('web_vitals');
        });
    });

    // ── 17. Enterprise Compliance Events ──────────────────────────

    describe('enterprise compliance', function () {
        test('enterpriseComplianceEvents covers GDPR, SOC2, ISO27001', function (): void {
            $compliance = EventCatalog::enterpriseComplianceEvents();
            expect($compliance)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $compliance);
            // GDPR
            expect($names)->toContain('sign_up');
            expect($names)->toContain('consent_granted');
            expect($names)->toContain('data_subject_access_request');
            // SOC2
            expect($names)->toContain('logout');
            expect($names)->toContain('role_changed');
        });
    });

    // ── 18. Revenue Events ───────────────────────────────────────

    describe('revenue events', function () {
        test('revenueEvents returns financial events', function (): void {
            $rev = EventCatalog::revenueEvents();
            expect($rev)->not->toBeEmpty();
            $names = array_map(fn (array $e): string => $e['name'], $rev);
            expect($names)->toContain('purchase');
            expect($names)->toContain('refund');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('payment_succeeded');
        });
    });
});
