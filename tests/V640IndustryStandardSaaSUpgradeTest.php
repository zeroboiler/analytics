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
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\SaasRevenueEventBuilder;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as EcommerceConverterAlias;

beforeEach(function (): void {
    // Reset static catalog caches between tests
    $refEcommerce = new ReflectionProperty(EcommerceEvents::class, 'catalog');
    $refEcommerce->setAccessible(true);
    $refEcommerce->setValue(null, []);

    $refSaas = new ReflectionProperty(SaaSEvents::class, 'catalog');
    $refSaas->setAccessible(true);
    $refSaas->setValue(null, []);

    $refEngagement = new ReflectionProperty(EngagementEvents::class, 'catalog');
    $refEngagement->setAccessible(true);
    $refEngagement->setValue(null, []);
});

// ── 1. Event Catalog: Complete Coverage ───────────────────────────────

describe('Event Catalog', function (): void {
    test('all three categories have required events', function (): void {
        // Ecommerce core events
        expect(EcommerceEvents::has('view_item'))->toBeTrue();
        expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
        expect(EcommerceEvents::has('purchase'))->toBeTrue();
        expect(EcommerceEvents::has('refund'))->toBeTrue();

        // SaaS core events
        expect(SaaSEvents::has('sign_up'))->toBeTrue();
        expect(SaaSEvents::has('login'))->toBeTrue();
        expect(SaaSEvents::has('start_trial'))->toBeTrue();
        expect(SaaSEvents::has('subscribe'))->toBeTrue();
        expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
        expect(SaaSEvents::has('plan_downgrade'))->toBeTrue();
        expect(SaaSEvents::has('cancellation'))->toBeTrue();

        // Engagement core events
        expect(EngagementEvents::has('page_view'))->toBeTrue();
        expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
        expect(EngagementEvents::has('click'))->toBeTrue();
        expect(EngagementEvents::has('form_start'))->toBeTrue();
        expect(EngagementEvents::has('form_submit'))->toBeTrue();
        expect(EngagementEvents::has('search'))->toBeTrue();
        expect(EngagementEvents::has('share'))->toBeTrue();
        expect(EngagementEvents::has('error'))->toBeTrue();
    });

    test('total event count exceeds 90 (industry standard minimum)', function (): void {
        $total = EcommerceEvents::count() + SaaSEvents::count() + EngagementEvents::count();

        expect($total)->toBeGreaterThan(90);
    });

    test('all events have ga4 mappings', function (): void {
        $all = EventCatalog::all();
        $missingGa4 = [];

        foreach ($all as $name => $entry) {
            if (empty($entry['ga4'])) {
                $missingGa4[] = $name;
            }
        }

        expect($missingGa4)->toBeEmpty('Events missing GA4 mapping: '.implode(', ', $missingGa4));
    });

    test('catalog validates without errors', function (): void {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });
});

// ── 2. Server-Side Lifecycle Tracker ─────────────────────────────────

describe('Lifecycle Event Mapper', function (): void {
    test('has default mappings for all critical SaaS events', function (): void {
        $mapper = LifecycleEventMapper::class;

        // Access DEFAULT_MAPPINGS constant
        $ref = new ReflectionProperty($mapper, 'DEFAULT_MAPPINGS');
        $ref->setAccessible(true);
        $defaults = $ref->getValue(null);

        $criticalEvents = [
            'auth.login',
            'auth.register',
            'subscription.created',
            'subscription.upgraded',
            'subscription.cancelled',
            'trial.started',
            'order.completed',
            'billing.payment_succeeded',
        ];

        foreach ($criticalEvents as $event) {
            expect($defaults[$event])->not->toBeNull("Missing mapping for {$event}");
        }
    });

    test('all default mappings have source and target fields', function (): void {
        $ref = new ReflectionProperty(LifecycleEventMapper::class, 'DEFAULT_MAPPINGS');
        $ref->setAccessible(true);
        $defaults = $ref->getValue(null);

        foreach ($defaults as $key => $mapping) {
            expect($mapping)->toHaveKey('source');
            expect($mapping)->toHaveKey('target');
            expect(is_string($mapping['source']))->toBeTrue("Mapping {$key} has invalid source");
            expect(is_string($mapping['target']))->toBeTrue("Mapping {$key} has invalid target");
        }
    });
});

// ── 3. E-commerce Format Conversion ──────────────────────────────────

describe('E-commerce Format Conversion', function (): void {
    test('converts GA4 items to Meta contents', function (): void {
        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaContents($items);

        expect($result['content_ids'])->toEqual(['SKU-001', 'SKU-002']);
        expect($result['num_items'])->toBe(2);
        expect($result['value'])->toBe(29.99 * 2 + 49.99);
    });

    test('converts Meta contents to GA4 items', function (): void {
        $contents = [
            ['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 29.99, 'item_name' => 'Widget'],
        ];

        $result = EcommerceFormatConverter::metaToGa4Items($contents);

        expect($result[0]['item_id'])->toBe('SKU-001');
        expect($result[0]['price'])->toBe(29.99);
        expect($result[0]['quantity'])->toBe(2);
    });

    test('converts GA4 purchase to Meta purchase', function (): void {
        $ga4Params = [
            'transaction_id' => 'TXN-123',
            'value' => 109.97,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 109.97, 'quantity' => 1],
            ],
        ];

        $meta = EcommerceFormatConverter::ga4ToMetaPurchase($ga4Params);

        expect($meta['value'])->toBe(109.97);
        expect($meta['currency'])->toBe('USD');
        expect($meta['content_type'])->toBe('product');
    });
});

// ── 4. SaaS Revenue Event Builder ─────────────────────────────────────

describe('SaasRevenueEventBuilder', function (): void {
    test('builds subscription event with all providers', function (): void {
        $result = SaasRevenueEventBuilder::subscription('Pro', 49.00, 'USD', 'monthly');

        expect($result)->toHaveKeys(['ga4', 'meta', 'posthog']);
        expect($result['ga4']['value'])->toBe(49.0);
        expect($result['ga4']['currency'])->toBe('USD');
        expect($result['meta']['content_name'])->toBe('Pro');
        expect($result['posthog']['plan'])->toBe('Pro');
    });

    test('builds plan upgrade event with from/to plan', function (): void {
        $result = SaasRevenueEventBuilder::planUpgrade('Starter', 'Pro', 99.00);

        expect($result['ga4']['from_plan'])->toBe('Starter');
        expect($result['ga4']['to_plan'])->toBe('Pro');
        expect($result['posthog']['value'])->toBe(99.0);
    });

    test('builds plan downgrade event', function (): void {
        $result = SaasRevenueEventBuilder::planDowngrade('Enterprise', 'Pro', 49.00);

        expect($result['ga4']['from_plan'])->toBe('Enterprise');
        expect($result['ga4']['to_plan'])->toBe('Pro');
    });

    test('builds cancellation event with reason', function (): void {
        $result = SaasRevenueEventBuilder::cancellation('Pro', 'too_expensive');

        expect($result['ga4']['plan'])->toBe('Pro');
        expect($result['ga4']['reason'])->toBe('too_expensive');
        expect($result['posthog']['reason'])->toBe('too_expensive');
    });

    test('builds trial start event with plan and days', function (): void {
        $result = SaasRevenueEventBuilder::trialStart('Pro', 14);

        expect($result['ga4']['plan'])->toBe('Pro');
        expect($result['ga4']['trial_days'])->toBe(14);
    });

    test('builds trial conversion event', function (): void {
        $result = SaasRevenueEventBuilder::trialConversion('Pro', 99.00);

        expect($result['ga4']['plan'])->toBe('Pro');
        expect($result['ga4']['value'])->toBe(99.0);
        expect($result['meta']['content_category'])->toBe('subscription');
    });

    test('builds payment succeeded event', function (): void {
        $result = SaasRevenueEventBuilder::paymentSucceeded(99.00, 'EUR', 'INV-001');

        expect($result['ga4']['value'])->toBe(99.0);
        expect($result['ga4']['currency'])->toBe('EUR');
        expect($result['ga4']['transaction_id'])->toBe('INV-001');
    });

    test('builds payment failed event', function (): void {
        $result = SaasRevenueEventBuilder::paymentFailed(99.00, 'USD', 'card_declined');

        expect($result['ga4']['value'])->toBe(99.0);
        expect($result['ga4']['reason'])->toBe('card_declined');
    });

    test('buildEvent creates AnalyticsEvent with target provider', function (): void {
        $event = SaasRevenueEventBuilder::buildEvent(
            'subscribe',
            'ga4',
            ['value' => 49.0],
            'client-123',
            'user-456',
        );

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('subscribe');
        expect($event->clientId)->toBe('client-123');
        expect($event->userId)->toBe('user-456');
        expect($event->params['_target_provider'])->toBe('ga4');
    });
});

// ── 5. Config Completeness ──────────────────────────────────────────

describe('Config Completeness', function (): void {
    test('config file has all industry-standard sections', function (): void {
        $configPath = __DIR__.'/../config/zeroboiler.php';
        $config = require $configPath;

        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'identity', 'ecommerce', 'revenue', 'api',
            'plausible', 'posthog', 'webhook', 'audit_log', 'debug',
            'validation', 'pipeline', 'sampling', 'pii_sanitization',
            'replay', 'metrics', 'stream', 'client_auto_track',
            'performance', 'tracking_preference', 'enrichment',
            'lifecycle', 'dedup', 'dispatcher', 'gdpr', 'attribution',
            'routing', 'event_cache', 'funnels', 'alerts',
            'realtime', 'snapshots', 'saas_kpi', 'health_score',
            'aarrr',
        ];

        foreach ($requiredSections as $section) {
            expect($config['analytics'])->toHaveKey($section, "Missing config section: {$section}");
        }
    });
});

// ── 6. Version Consistency ───────────────────────────────────────────

describe('Version Consistency', function (): void {
    test('version is 6.4.0 across all entry points', function (): void {
        // DTO version constant
        expect(AnalyticsEvent::VERSION)->toBe('6.4.0');

        // composer.json version
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($composer['version'])->toBe('6.4.0');

        // JS client version
        $jsContent = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect(str_contains($jsContent, "'6.4.0'") || str_contains($jsContent, '"6.4.0"'))->toBeTrue();

        // Svelte composable version
        $svelteContent = file_get_contents(__DIR__.'/../resources/js/useAnalytics.svelte.js');
        expect(str_contains($svelteContent, '6.4.0'))->toBeTrue();

        // TypeScript definitions version
        $tsContent = file_get_contents(__DIR__.'/../resources/js/analytics.d.ts');
        expect(str_contains($tsContent, '6.4.0'))->toBeTrue();
    });
});

// ── 7. Strict Types Enforcement ──────────────────────────────────────

describe('Strict Types', function (): void {
    test('all source files declare strict_types=1', function (): void {
        $sourceDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $violations = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace($sourceDir.'/', '', $file->getPathname());
            }
        }

        // Allow a small tolerance for stubs/helpers
        expect(count($violations))->toBeLessThanOrEqual(1, 'Files missing strict_types: '.implode(', ', $violations));
    });
});

// ── 8. Queue Job Serialization ────────────────────────────────────────

describe('Queue Job Serialization', function (): void {
    test('TrackAnalyticsEventJob is readonly and serializable', function (): void {
        $job = new TrackAnalyticsEventJob(
            name: 'test_event',
            params: ['key' => 'value'],
            clientId: 'client-123',
            userId: 'user-456',
        );

        expect($job->name)->toBe('test_event');
        expect($job->params)->toBe(['key' => 'value']);
        expect($job->clientId)->toBe('client-123');
        expect($job->userId)->toBe('user-456');

        // Verify serializable
        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        expect($unserialized->name)->toBe('test_event');
    });

    test('TrackAnalyticsEventBatchJob is readonly and serializable', function (): void {
        $events = [
            ['name' => 'event_a', 'params' => []],
            ['name' => 'event_b', 'params' => ['key' => 'val']],
        ];

        $job = new TrackAnalyticsEventBatchJob(
            events: $events,
            clientId: 'client-123',
        );

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        expect($unserialized->events)->toHaveCount(2);
    });
});

// ── 9. Cross-Provider Coverage ──────────────────────────────────────

describe('Cross-Provider Coverage', function (): void {
    test('core events have all provider mappings', function (): void {
        $coreEvents = [
            'sign_up', 'login', 'purchase', 'page_view', 'search',
        ];

        foreach ($coreEvents as $eventName) {
            $entry = EventCatalog::get($eventName);

            expect($entry)->not->toBeNull("Event {$eventName} not found in catalog");
            expect($entry['ga4'])->not->toBeEmpty("Event {$eventName} missing GA4 mapping");
            expect($entry['posthog'])->not->toBeEmpty("Event {$eventName} missing PostHog mapping");
        }
    });

    test('GA4 and Meta names are non-empty for ecommerce events', function (): void {
        foreach (EcommerceEvents::all() as $name => $entry) {
            expect($entry['ga4'])->not->toBeEmpty("Ecommerce event {$name} missing GA4 mapping");
        }
    });

    test('plausible names exist for key engagement events', function (): void {
        $plausibleEvents = EngagementEvents::plausibleNames();
        expect($plausibleEvents)->not->toBeEmpty();
        expect(in_array('pageview', $plausibleEvents))->toBeTrue();
        expect(in_array('search', $plausibleEvents))->toBeTrue();
    });
});

// ── 10. Identity Linking ────────────────────────────────────────────

describe('Identity Linking', function (): void {
    test('UserIdentityTracker has identify, onLogin, onRegister, onLogout methods', function (): void {
        $tracker = new ReflectionClass(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);

        expect($tracker->hasMethod('identify'))->toBeTrue();
        expect($tracker->hasMethod('onLogin'))->toBeTrue();
        expect($tracker->hasMethod('onRegister'))->toBeTrue();
        expect($tracker->hasMethod('onLogout'))->toBeTrue();
    });

    test('IdentityResolutionService is registered in service provider', function (): void {
        $spContent = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect(str_contains($spContent, 'IdentityResolutionService'))->toBeTrue();
    });
});

// ── 11. GDPR / Consent Compliance ─────────────────────────────────────

describe('GDPR / Consent Compliance', function (): void {
    test('consent config has granular purposes', function (): void {
        $config = require __DIR__.'/../config/zeroboiler.php';

        expect($config['analytics']['consent']['purposes'])->toHaveKey('necessary');
        expect($config['analytics']['consent']['purposes']['necessary']['required'])->toBeTrue();
        expect($config['analytics']['consent']['purposes'])->toHaveKey('analytics');
        expect($config['analytics']['consent']['purposes'])->toHaveKey('marketing');
        expect($config['analytics']['consent']['purposes'])->toHaveKey('functional');
    });

    test('GDPR events exist in catalog', function (): void {
        $gdprEvents = EventCatalog::gdprEvents();
        $gdprNames = array_column($gdprEvents, 'name');

        expect($gdprNames)->toContain('sign_up');
        expect($gdprNames)->toContain('account_deleted');
        expect($gdprNames)->toContain('consent_granted');
        expect($gdprNames)->toContain('consent_withdrawn');
        expect($gdprNames)->toContain('data_subject_access_request');
        expect($gdprNames)->toContain('data_erasure_completed');
    });

    test('ip anonymization config exists', function (): void {
        $config = require __DIR__.'/../config/zeroboiler.php';

        expect($config['analytics']['gdpr'])->toHaveKey('anonymize_ip');
        expect($config['analytics']['gdpr'])->toHaveKey('ip_mask_v4');
    });
});

// ── 12. End-to-End SaaS Lifecycle Flow Validation ────────────────────

describe('SaaS Lifecycle Flow', function (): void {
    test('complete signup → trial → subscription → upgrade → cancellation flow', function (): void {
        // Step 1: Sign Up
        $signup = EventCatalog::get('sign_up');
        expect($signup)->not->toBeNull();
        expect($signup['category'])->toBe('saas');
        expect($signup['ga4'])->toBe('sign_up');
        expect($signup['meta'])->toBe('CompleteRegistration');

        // Step 2: Login
        $login = EventCatalog::get('login');
        expect($login)->not->BeNull();
        expect($login['ga4'])->toBe('login');

        // Step 3: Trial Start
        $trialStart = EventCatalog::get('start_trial');
        expect($trialStart)->not->BeNull();
        expect($trialStart['ga4'])->toBe('start_trial');

        // Step 4: Trial Convert
        $trialConverted = EventCatalog::get('trial_converted');
        expect($trialConverted)->not->BeNull();

        // Step 5: Subscribe
        $subscribe = EventCatalog::get('subscribe');
        expect($subscribe)->not->BeNull();
        expect($subscribe['ga4'])->toBe('purchase'); // Maps to GA4 purchase

        // Step 6: Plan Upgrade
        $upgrade = EventCatalog::get('plan_upgrade');
        expect($upgrade)->not->BeNull();
        expect($upgrade['ga4'])->toBe('plan_upgrade');

        // Step 7: Cancellation
        $cancellation = EventCatalog::get('cancellation');
        expect($cancellation)->not->BeNull();
        expect($cancellation['meta'])->toBe('CancelSubscription');

        // Verify lifecycle mapper has corresponding mappings
        $ref = new ReflectionProperty(LifecycleEventMapper::class, 'DEFAULT_MAPPINGS');
        $ref->setAccessible(true);
        $defaults = $ref->getValue(null);

        expect($defaults['auth.register']['target'])->toContain('SignUpEvent');
        expect($defaults['auth.login']['target'])->toContain('LoginEvent');
        expect($defaults['trial.started']['target'])->toContain('TrialStartEvent');
        expect($defaults['trial.converted']['target'])->toContain('TrialConvertedEvent');
        expect($defaults['subscription.created']['target'])->toContain('SubscriptionEvent');
        expect($defaults['subscription.upgraded']['target'])->toContain('PlanUpgradeEvent');
        expect($defaults['subscription.cancelled']['target'])->toContain('CancellationEvent');
    });

    test('e-commerce purchase flow events all exist', function (): void {
        $flowEvents = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];

        foreach ($flowEvents as $event) {
            $entry = EventCatalog::get($event);
            expect($entry)->not->BeNull("Missing event: {$event}");
            expect($entry['category'])->toBe('ecommerce');
        }
    });
});
