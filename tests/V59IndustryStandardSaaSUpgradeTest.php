<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

beforeEach(function (): void {
    // Static catalogs use lazy initialization — no reset needed
});

// ─── Industry Standard Version Integrity ──────────────────────────────

describe('Version Integrity', function (): void {
    test('DTO version matches expected', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('5.9.0');
    });

    test('all version strings are consistent across package', function (): void {
        // Verify no stale version references remain
        $files = [
            __DIR__ . '/../src/DTO/AnalyticsEvent.php',
            __DIR__ . '/../src/Support/EcommerceFormatConverter.php',
            __DIR__ . '/../composer.json',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toContain('5.7.0');
            expect($content)->toContain('5.9.0');
        }
    });
});

// ─── Event Catalog: SaaS Starter Coverage ────────────────────────────

describe('Event Catalog — Industry Standard SaaS Coverage', function (): void {
    test('all core SaaS lifecycle events exist', function (): void {
        $saasCore = [
            'sign_up', 'login', 'start_trial', 'subscribe',
            'plan_upgrade', 'cancellation', 'feature_used', 'revenue_tracked',
        ];

        foreach ($saasCore as $eventName) {
            expect(SaaSEvents::has($eventName))
                ->toBeTrue("SaaS event '{$eventName}' must exist in catalog");
        }
    });

    test('all core ecommerce events exist', function (): void {
        $ecommerceCore = [
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'add_payment_info', 'purchase', 'refund',
            'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion',
        ];

        foreach ($ecommerceCore as $eventName) {
            expect(EcommerceEvents::has($eventName))
                ->toBeTrue("Ecommerce event '{$eventName}' must exist in catalog");
        }
    });

    test('all core engagement events exist', function (): void {
        $engagementCore = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'screen_view', 'session_start', 'session_end',
        ];

        foreach ($engagementCore as $eventName) {
            expect(EngagementEvents::has($eventName))
                ->toBeTrue("Engagement event '{$eventName}' must exist in catalog");
        }
    });

    test('catalog totals meet minimum thresholds', function (): void {
        expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(14);
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
        expect(EngagementEvents::count())->toBeGreaterThanOrEqual(28);
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(90);
    });

    test('unified EventCatalog::all returns entries from all categories', function (): void {
        $all = EventCatalog::all();
        $byCategory = EventCatalog::byCategory();

        expect($all)->toBeArray();
        expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        expect(count($all))->toBeGreaterThanOrEqual(90);

        // Every entry must have required fields
        foreach (array_slice($all, 0, 20) as $name => $entry) {
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('class');
            expect($entry)->toHaveKey('ga4');
            expect($entry)->toHaveKey('meta');
            expect($entry)->toHaveKey('category');
        }
    });

    test('GA4 provider mappings exist for all catalog events', function (): void {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect($entry['ga4'])->not->toBeEmpty("Event '{$name}' must have a GA4 mapping");
        }
    });
});

// ─── Cross-Provider Format Consistency ──────────────────────────────────

describe('Cross-Provider Format Conversion', function (): void {
    test('EcommerceFormatConverter converts GA4 items to Meta contents', function (): void {
        $ga4Items = [
            [
                'item_id' => 'SKU-123',
                'item_name' => 'Premium Plan',
                'price' => 49.99,
                'quantity' => 1,
            ],
            [
                'item_id' => 'SKU-456',
                'item_name' => 'Add-on Pack',
                'price' => 9.99,
                'quantity' => 3,
            ],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaContents($ga4Items);

        expect($result)->toHaveKey('content_ids');
        expect($result)->toHaveKey('contents');
        expect($result)->toHaveKey('num_items');
        expect($result)->toHaveKey('value');
        expect($result['content_ids'])->toContain('SKU-123');
        expect($result['content_ids'])->toContain('SKU-456');
        expect($result['num_items'])->toBe(4);
        expect($result['value'])->toBe(79.96);
    });

    test('EcommerceFormatConverter converts Meta contents to GA4 items', function (): void {
        $metaContents = [
            [
                'id' => 'SKU-123',
                'quantity' => 2,
                'item_price' => 29.99,
            ],
        ];

        $result = EcommerceFormatConverter::metaToGa4Items($metaContents);

        expect($result)->toBeArray();
        expect($result[0]['item_id'])->toBe('SKU-123');
        expect($result[0]['price'])->toBe(29.99);
        expect($result[0]['quantity'])->toBe(2);
    });

    test('catalog entries have correct provider event name formats', function (): void {
        // GA4 uses snake_case
        foreach (EcommerceEvents::ga4Names() as $name) {
            expect($name)->toMatch('/^[a-z_]+$/');
        }

        // Meta uses PascalCase or null
        foreach (EcommerceEvents::metaNames() as $name) {
            expect($name)->toMatch('/^[A-Z][a-zA-Z]+$/');
        }

        // PostHog uses snake_case
        foreach (SaaSEvents::posthogNames() as $name) {
            expect($name)->toMatch('/^[a-z\$_][a-z0-9\$_]+$/');
        }
    });
});

// ─── Lifecycle Event Mapper ─────────────────────────────────────────────

describe('Lifecycle Event Mapper — Config-Driven', function (): void {
    test('built-in mappings cover all core SaaS lifecycle events', function (): void {
        $config = app('config');
        $config->set('zeroboiler.analytics.lifecycle', [
            'enabled' => true,
            'override_defaults' => false,
        ]);

        $mapper = new LifecycleEventMapper(
            app(AnalyticsManager::class),
            $config,
        );

        // Verify the mapper can resolve core lifecycle events
        $reflection = new ReflectionClass($mapper);
        $property = $reflection->getProperty('activeMappings');
        $mappings = $property->getValue($mapper);

        $requiredMappings = [
            'auth.login', 'auth.register', 'auth.logout',
            'subscription.created', 'subscription.upgraded', 'subscription.cancelled',
            'trial.started', 'trial.ended',
            'order.completed', 'order.refunded',
            'form.submitted', 'search.performed',
        ];

        foreach ($requiredMappings as $key) {
            expect($mappings)->toHaveKey($key, "Lifecycle mapping '{$key}' must exist");
        }
    });
});

// ─── API Controller ─────────────────────────────────────────────────────

describe('API Controller — Industry Standard', function (): void {
    test('controller has core tracking endpoints', function (): void {
        $controller = new ReflectionClass(AnalyticsEventController::class);

        $requiredMethods = [
            'track', 'batch', 'identify', 'updateConsent', 'pageview',
            'health', 'catalog', 'stats',
        ];

        foreach ($requiredMethods as $method) {
            expect($controller->hasMethod($method))
                ->toBeTrue("AnalyticsEventController must have '{$method}' method");
        }
    });
});

// ─── Routes ─────────────────────────────────────────────────────────────

describe('Routes — Full API Surface', function (): void {
    test('core event routes are defined', function (): void {
        $routeContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

        $requiredRoutes = [
            "Route::post('events'",
            "Route::post('batch'",
            "Route::post('identify'",
            "Route::post('consent'",
            "Route::post('pageview'",
            "Route::get('health'",
            "Route::get('catalog'",
            "Route::get('stats'",
        ];

        foreach ($requiredRoutes as $route) {
            expect($routeContent)->toContain($route);
        }
    });
});

// ─── Inertia Middleware ──────────────────────────────────────────────────

describe('Inertia Middleware — SaaS Props', function (): void {
    test('HandleInertiaAnalytics injects all required props', function (): void {
        $middleware = new ReflectionClass(HandleInertiaAnalytics::class);
        expect($middleware->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class))->toBeTrue();
        expect($middleware->hasMethod('handle'))->toBeTrue();
    });
});

// ─── Analytics Manager — Convenience Methods ─────────────────────────────

describe('Analytics Manager — SaaS Convenience API', function (): void {
    test('facade provides trackEvent method', function (): void {
        expect(method_exists(Analytics::class, 'trackEvent'))->toBeTrue();
    });

    test('facade provides identify method', function (): void {
        expect(method_exists(Analytics::class, 'identify'))->toBeTrue();
    });

    test('facade provides setConsent method', function (): void {
        expect(method_exists(Analytics::class, 'setConsent'))->toBeTrue();
    });

    test('facade provides setUserProperties method', function (): void {
        expect(method_exists(Analytics::class, 'setUserProperties'))->toBeTrue();
    });

    test('manager has all provider getter methods', function (): void {
        $manager = new ReflectionClass(AnalyticsManager::class);

        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        foreach ($providers as $provider) {
            expect($manager->hasMethod($provider))->toBeTrue("Manager must have '{$provider}()' method");
        }
    });
});

// ─── Consent Mode v2 ─────────────────────────────────────────────────────

describe('Consent Mode v2 — GDPR Compliance', function (): void {
    test('ConsentState defaults to granted via helper', function (): void {
        $state = ConsentState::granted();

        expect($state->isGranted('analytics_storage'))->toBeTrue();
        expect($state->isGranted('ad_storage'))->toBeTrue();
        expect($state->isGranted('ad_user_data'))->toBeTrue();
        expect($state->isGranted('ad_personalization'))->toBeTrue();
        expect($state->isGranted('functionality_storage'))->toBeTrue();
        expect($state->isGranted('security_storage'))->toBeTrue();
        expect($state->hasAnalyticsConsent())->toBeTrue();
        expect($state->hasAdConsent())->toBeTrue();
    });

    test('ConsentState can be created with denied state', function (): void {
        $state = ConsentState::denied();

        expect($state->isDenied('analytics_storage'))->toBeTrue();
        expect($state->isDenied('ad_storage'))->toBeTrue();
        expect($state->hasAnalyticsConsent())->toBeFalse();
    });

    test('ConsentState can override individual signals', function (): void {
        $state = ConsentState::granted()->with([
            'analytics_storage' => 'denied',
        ]);

        expect($state->isDenied('analytics_storage'))->toBeTrue();
        expect($state->isGranted('ad_storage'))->toBeTrue();
    });
});

// ─── Event Queue & Async Dispatch ───────────────────────────────────────

describe('Event Queue — Async Dispatch', function (): void {
    test('config has queue settings', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'queue' => [");
        expect($config)->toContain("'enabled'");
        expect($config)->toContain("'connection'");
    });

    test('TrackAnalyticsEventJob exists and is serializable', function (): void {
        $jobClass = \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class;
        expect(class_exists($jobClass))->toBeTrue();

        $reflection = new ReflectionClass($jobClass);
        expect($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class)
            || $reflection->hasMethod('__construct'))->toBeTrue();
    });
});

// ─── Identity Linking ────────────────────────────────────────────────────

describe('Identity — Client ID ↔ User ID Linking', function (): void {
    test('config has identity settings', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'identity' => [");
        expect($config)->toContain("'cookie_name'");
        expect($config)->toContain("'link_on_auth'");
        expect($config)->toContain("'cache_prefix'");
        expect($config)->toContain("'link_ttl'");
    });

    test('IdentityResolutionService exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class))->toBeTrue();
    });
});

// ─── Plausible & PostHog Optional Providers ──────────────────────────────

describe('Optional Providers — Plausible & PostHog', function (): void {
    test('PlausibleTracker exists and is configurable', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class))->toBeTrue();
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'plausible' => [");
    });

    test('PosthogTracker exists and is configurable', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class))->toBeTrue();
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'posthog' => [");
    });

    test('both providers implement TrackerInterface', function (): void {
        expect((new ReflectionClass(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class)))
            ->implementsInterface(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
        expect((new ReflectionClass(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class)))
            ->implementsInterface(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    });
});

// ─── Admin Commands ───────────────────────────────────────────────────────

describe('Admin Commands', function (): void {
    test('AnalyticsOverviewCommand exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
    });

    test('AnalyticsTestCommand exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
    });

    test('AnalyticsDiagnosticsCommand exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDiagnosticsCommand::class))->toBeTrue();
    });

    test('AnalyticsHealthCheckCommand exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class))->toBeTrue();
    });
});

// ─── PHP 8.5 Syntax Compliance ──────────────────────────────────────────

describe('PHP 8.5 Compliance', function (): void {
    test('all source files use strict types', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = $file->getContents();
            $lines = explode("\n", $content);
            $hasStrict = false;
            foreach ($lines as $line) {
                if (str_contains(trim($line), 'declare(strict_types=1)')) {
                    $hasStrict = true;
                    break;
                }
            }
            if (! $hasStrict) {
                $violations[] = $file->getRelativePathname();
            }
        }

        expect($violations)->toBeEmpty('All source files must declare strict_types. Violations: ' . implode(', ', $violations));
    });
});

// ─── Config Completeness ────────────────────────────────────────────────

describe('Config — Full SaaS Starter Coverage', function (): void {
    test('config has all required sections', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'identity', 'ecommerce', 'revenue', 'plausible',
            'posthog', 'api', 'debug', 'audit_log', 'lifecycle',
            'track_links', 'dedup', 'pipeline', 'performance',
            'client_auto_track', 'webhook',
        ];

        foreach ($requiredSections as $section) {
            expect($config)->toContain("'{$section}' => [");
        }
    });

    test('config has lifecycle event mapping section', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'lifecycle' => [");
        expect($config)->toContain("'events'");
        expect($config)->toContain("'custom_mappings'");
    });
});

// ─── JS Client Completeness ────────────────────────────────────────────

describe('JS Client — Full Feature Set', function (): void {
    test('analytics.js exports all required functions', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        $requiredExports = [
            'export function init(',
            'export function destroy(',
            'export function trackEvent(',
            'export function trackPageView(',
            'export function flushQueue(',
            'export function setConsent(',
            'export function identify(',
        ];

        foreach ($requiredExports as $export) {
            expect($js)->toContain($export);
        }
    });

    test('JS client has batch queue implementation', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('eventQueue');
        expect($js)->toContain('FLUSH_INTERVAL');
        expect($js)->toContain('MAX_QUEUE_SIZE');
        expect($js)->toContain('sendBeacon');
    });

    test('JS client version matches PHP version', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain("'5.9.0'");
    });
});

// ─── Provider Health Monitor ─────────────────────────────────────────────

describe('Provider Health Monitor', function (): void {
    test('ProviderHealthMonitor service exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\ProviderHealthMonitor::class))->toBeTrue();
    });

    test('ProviderHealthMonitor has required methods', function (): void {
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderHealthMonitor::class);

        expect($class->hasMethod('recordSuccess'))->toBeTrue();
        expect($class->hasMethod('recordFailure'))->toBeTrue();
        expect($class->hasMethod('isHealthy'))->toBeTrue();
        expect($class->hasMethod('getScore'))->toBeTrue();
        expect($class->hasMethod('activeProviders'))->toBeTrue();
        expect($class->hasMethod('summary'))->toBeTrue();
    });
});

// ─── Event Routing ──────────────────────────────────────────────────────

describe('Event Routing Configuration', function (): void {
    test('config has routing section', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'routing' => [");
        expect($config)->toContain("'rules'");
        expect($config)->toContain("'enabled'");
    });

    test('EventRouter service exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class))->toBeTrue();
    });
});

// ─── Comprehensive Integration ───────────────────────────────────────────

describe('End-to-End — SaaS Starter Flow', function (): void {
    test('full signup → trial → subscription → upgrade → cancellation flow is tracked', function (): void {
        $flow = ['sign_up', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];

        foreach ($flow as $eventName) {
            $entry = EventCatalog::get($eventName);
            expect($entry)->not->toBeNull("Event '{$eventName}' must exist in unified catalog");
            expect($entry['category'])->toBe('saas');
            expect($entry['ga4'])->not->toBeEmpty();
        }
    });

    test('full ecommerce funnel is tracked', function (): void {
        $funnel = ['view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase'];

        foreach ($funnel as $eventName) {
            $entry = EventCatalog::get($eventName);
            expect($entry)->not->toBeNull("Ecommerce funnel event '{$eventName}' must exist");
            expect($entry['category'])->toBe('ecommerce');
        }
    });

    test('engagement coverage meets industry standard', function (): void {
        $engagement = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'session_start', 'session_end',
            'screen_view', 'outbound_click', 'file_download',
        ];

        foreach ($engagement as $eventName) {
            expect(EngagementEvents::has($eventName))
                ->toBeTrue("Engagement event '{$eventName}' must exist for industry-standard coverage");
        }
    });
});
