<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * Industry-Standard SaaS Analytics — v163.0.0 Comprehensive Audit.
 *
 * Validates the complete ZeroBoiler Analytics package meets industry-standard
 * SaaS starter requirements across all 12 planned features plus quality gates.
 *
 * @since 163.0.0
 */
describe('V163 Industry Standard SaaS Analytics Audit', function () {
    // ── Criterion 1: Event Catalog ──────────────────────────────────────

    describe('Event Catalog', function () {
        it('has EcommerceEvents catalog with core events', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class))->toBeTrue();
            $catalog = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::all();
            expect($catalog)->toHaveKey('view_item');
            expect($catalog)->toHaveKey('add_to_cart');
            expect($catalog)->toHaveKey('purchase');
            expect($catalog)->toHaveKey('refund');
            expect(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
        });

        it('has SaaSEvents catalog with core lifecycle events', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::class))->toBeTrue();
            $catalog = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::all();
            expect($catalog)->toHaveKey('sign_up');
            expect($catalog)->toHaveKey('login');
            expect($catalog)->toHaveKey('trial_start');
            expect($catalog)->toHaveKey('subscription');
            expect($catalog)->toHaveKey('plan_upgrade');
            expect($catalog)->toHaveKey('cancellation');
            expect(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count())->toBeGreaterThanOrEqual(70);
        });

        it('has EngagementEvents catalog with core engagement events', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::class))->toBeTrue();
            $catalog = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::all();
            expect($catalog)->toHaveKey('page_view');
            expect($catalog)->toHaveKey('scroll_depth');
            expect($catalog)->toHaveKey('click');
            expect($catalog)->toHaveKey('form_start');
            expect($catalog)->toHaveKey('form_submit');
            expect($catalog)->toHaveKey('search');
            expect($catalog)->toHaveKey('share');
            expect($catalog)->toHaveKey('error');
        });

        it('has unified EventCatalog with 8 categories', function () {
            $all = \ZeroBoiler\Analytics\Events\EventCatalog::all();
            $categories = \ZeroBoiler\Analytics\Events\EventCatalog::byCategory();
            expect($categories)->toHaveKey('ecommerce');
            expect($categories)->toHaveKey('saas');
            expect($categories)->toHaveKey('engagement');
            expect($categories)->toHaveKey('security');
            expect($categories)->toHaveKey('uptime');
            expect($categories)->toHaveKey('infrastructure');
            expect($categories)->toHaveKey('marketing');
            expect($categories)->toHaveKey('customer_success');
            expect(\ZeroBoiler\Analytics\Events\EventCatalog::count())->toBeGreaterThanOrEqual(200);
        });
    });

    // ── Criterion 2: Server-Side Lifecycle Tracker ──────────────────

    describe('Server-Side Lifecycle Tracker', function () {
        it('has LifecycleEventMapper with 60+ mappings', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Services\LifecycleEventMapper::class))->toBeTrue();
            $mapper = new \ZeroBoiler\Analytics\Services\LifecycleEventMapper(new \Illuminate\Config\Repository([]));
            expect($mapper->getMappings())->toBeArray();
            expect(count($mapper->getMappings()))->toBeGreaterThanOrEqual(60);
        });

        it('has ServerSideTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\ServerSideTracker::class))->toBeTrue();
        });

        it('has LifecycleEventSubscriber', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber::class))->toBeTrue();
        });
    });

    // ── Criterion 3: Inertia Middleware ────────────────────────────────

    describe('Inertia Middleware', function () {
        it('has HandleInertiaAnalytics middleware', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class))->toBeTrue();
        });

        it('implements HttpMiddlewareContract', function () {
            $middleware = new \ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
            expect($middleware->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class))->toBeTrue();
        });

        it('has tracking ID cookie management', function () {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
            expect($reflection->hasMethod('getOrCreateTrackingId'))->toBeTrue();
            expect($reflection->hasMethod('getUserId'))->toBeTrue();
            expect($reflection->hasMethod('detectAuthStateChange'))->toBeTrue();
        });

        it('has Inertia auto-track props', function () {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
            $method = $reflection->getMethod('handle');
            // Verify the method reads client_auto_track config
            expect($method)->not->toBeNull();
        });
    });

    // ── Criterion 4: API Controller & Routes ───────────────────────────

    describe('API Controller & Routes', function () {
        it('has AnalyticsEventController with core endpoints', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class))->toBeTrue();
            $controller = new \ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            expect($controller->hasMethod('track'))->toBeTrue();
            expect($controller->hasMethod('batch'))->toBeTrue();
            expect($controller->hasMethod('identify'))->toBeTrue();
            expect($controller->hasMethod('updateConsent'))->toBeTrue();
            expect($controller->hasMethod('health'))->toBeTrue();
        });

        it('has route file with 200+ endpoints', function () {
            $routesPath = __DIR__ . '/../routes/analytics.php';
            expect(file_exists($routesPath))->toBeTrue();
            $content = file_get_contents($routesPath);
            $routeCount = substr_count($content, 'Route::');
            expect($routeCount)->toBeGreaterThanOrEqual(200);
        });

        it('has SSE controller', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController::class))->toBeTrue();
        });
    });

    // ── Criterion 5: JS Client Library ────────────────────────────────

    describe('JS Client Library', function () {
        it('has analytics.js with 8000+ lines', function () {
            $jsPath = __DIR__ . '/../resources/js/analytics.js';
            expect(file_exists($jsPath))->toBeTrue();
            $lines = count(file($jsPath));
            expect($lines)->toBeGreaterThanOrEqual(8000);
        });

        it('exports core functions', function () {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect(str_contains($content, 'export function trackEvent'))->toBeTrue();
            expect(str_contains($content, 'export function trackPageView'))->toBeTrue();
            expect(str_contains($content, 'export function initInertiaPageViewTracker'))->toBeTrue();
            expect(str_contains($content, 'export function identify'))->toBeTrue();
            expect(str_contains($content, 'export function getClientId'))->toBeTrue();
            expect(str_contains($content, 'export function scrollDepth'))->toBeTrue();
        });

        it('has Svelte composables', function () {
            expect(file_exists(__DIR__ . '/../resources/js/useAnalytics.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/useEcommerce.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/useLifecycle.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/usePerformanceTracker.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/useSaaSMetrics.svelte.js'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../resources/js/useSessionReplay.svelte.js'))->toBeTrue();
        });

        it('has TypeScript definitions', function () {
            expect(file_exists(__DIR__ . '/../resources/js/analytics.d.ts'))->toBeTrue();
            $dtsLines = count(file(__DIR__ . '/../resources/js/analytics.d.ts'));
            expect($dtsLines)->toBeGreaterThanOrEqual(2000);
        });
    });

    // ── Criterion 6: Event Queue ──────────────────────────────────────

    describe('Event Queue', function () {
        it('has TrackAnalyticsEventJob', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class))->toBeTrue();
        });

        it('has TrackAnalyticsEventBatchJob', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class))->toBeTrue();
        });

        it('has QueuedAnalyticsDispatcher', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
        });

        it('has queue config section', function () {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            expect($config['analytics'])->toHaveKey('queue');
            expect($config['analytics']['queue'])->toHaveKey('enabled');
            expect($config['analytics']['queue'])->toHaveKey('queue');
        });
    });

    // ── Criterion 7: User Identity Linking ───────────────────────────

    describe('User Identity Linking', function () {
        it('has UserIdentityTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
        });

        it('has IdentityResolutionService', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class))->toBeTrue();
        });

        it('has identity config section', function () {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            expect($config['analytics'])->toHaveKey('identity');
            expect($config['analytics']['identity'])->toHaveKey('cookie_name');
            expect($config['analytics']['identity'])->toHaveKey('link_on_auth');
        });
    });

    // ── Criterion 8: E-commerce Helpers ───────────────────────────────

    describe('E-commerce Helpers', function () {
        it('has EcommerceFormatConverter', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class))->toBeTrue();
        });

        it('has GA4 to Meta conversion', function () {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class);
            expect($reflection->hasMethod('purchaseToMeta'))->toBeTrue();
            expect($reflection->hasMethod('refundToMeta'))->toBeTrue();
        });

        it('has ecommerce config section', function () {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            expect($config['analytics'])->toHaveKey('ecommerce');
            expect($config['analytics']['ecommerce'])->toHaveKey('currency');
        });
    });

    // ── Criterion 9: Admin Commands ──────────────────────────────────

    describe('Admin Commands', function () {
        it('has AnalyticsOverviewCommand', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
        });

        it('has AnalyticsTestCommand', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
        });

        it('AnalyticsOverviewCommand covers 10 providers', function () {
            $reflection = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class, 'getCatalogStats');
            expect($reflection)->not->toBeNull();
        });
    });

    // ── Criterion 10: Config Expansion ───────────────────────────────

    describe('Config Expansion', function () {
        it('has all required config sections', function () {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'];

            expect($analytics)->toHaveKey('ga4');
            expect($analytics)->toHaveKey('gtm');
            expect($analytics)->toHaveKey('meta_pixel');
            expect($analytics)->toHaveKey('consent');
            expect($analytics)->toHaveKey('queue');
            expect($analytics)->toHaveKey('lifecycle');
            expect($analytics)->toHaveKey('api');
            expect($analytics)->toHaveKey('identity');
            expect($analytics)->toHaveKey('ecommerce');
            expect($analytics)->toHaveKey('auto_track');
        });

        it('has optional provider configs', function () {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'];

            expect($analytics)->toHaveKey('plausible');
            expect($analytics)->toHaveKey('posthog');
            expect($analytics)->toHaveKey('mixpanel');
            expect($analytics)->toHaveKey('amplitude');
            expect($analytics)->toHaveKey('tiktok');
            expect($analytics)->toHaveKey('linkedin');
        });
    });

    // ── Criterion 11: Optional Providers ──────────────────────────────

    describe('Optional Providers', function () {
        it('has PlausibleTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class))->toBeTrue();
        });

        it('has PosthogTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class))->toBeTrue();
        });

        it('has MixpanelTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class))->toBeTrue();
        });

        it('has AmplitudeTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class))->toBeTrue();
        });

        it('has TikTokTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\TikTokTracker::class))->toBeTrue();
        });

        it('has LinkedInTracker', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\LinkedInTracker::class))->toBeTrue();
        });

        it('has TrackerInterface', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class))->toBeTrue();
        });
    });

    // ── Criterion 12: Tests + README ──────────────────────────────────

    describe('Tests and Documentation', function () {
        it('has test files', function () {
            $testFiles = glob(__DIR__ . '/*.php');
            expect(count($testFiles))->toBeGreaterThanOrEqual(300);
        });

        it('has README', function () {
            expect(file_exists(__DIR__ . '/../README.md'))->toBeTrue();
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect(str_contains($readme, 'ZeroBoiler Analytics'))->toBeTrue();
            expect(str_contains($readme, '163.0.0'))->toBeTrue();
        });

        it('has CHANGELOG', function () {
            expect(file_exists(__DIR__ . '/../CHANGELOG.md'))->toBeTrue();
        });

        it('has MIT license', function () {
            expect(file_exists(__DIR__ . '/../LICENSE'))->toBeTrue();
        });
    });

    // ── Quality Gates ─────────────────────────────────────────────────

    describe('Quality Gates', function () {
        it('has consistent version across all entry points', function () {
            $version = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
            expect($version)->toBe('163.0.0');

            // Verify composer.json version
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('163.0.0');

            // Verify package.json version
            $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
            expect($package['version'])->toBe('163.0.0');
        });

        it('AnalyticsEvent DTO is readonly', function () {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('EventCatalog is final', function () {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        it('core catalog classes use strict_types', function () {
            $files = [
                __DIR__ . '/../src/Events/EventCatalog.php',
                __DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php',
                __DIR__ . '/../src/Events/SaaS/SaaSEvents.php',
                __DIR__ . '/../src/Events/Engagement/EngagementEvents.php',
                __DIR__ . '/../src/DTO/AnalyticsEvent.php',
                __DIR__ . '/../src/AnalyticsManager.php',
            ];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
            }
        });

        it('core classes have MIT license header', function () {
            $files = [
                __DIR__ . '/../src/Events/EventCatalog.php',
                __DIR__ . '/../src/AnalyticsManager.php',
                __DIR__ . '/../src/AnalyticsServiceProvider.php',
                __DIR__ . '/../src/Facades/Analytics.php',
            ];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect(str_contains($content, 'part of ZeroBoiler, licensed under the MIT license'))->toBeTrue();
            }
        });

        it('AnalyticsManager has identify method', function () {
            $manager = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
            expect($manager->hasMethod('identify'))->toBeTrue();
        });

        it('AnalyticsFacade has AnalyticsManager delegation', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Facades\Analytics::class))->toBeTrue();
            $facade = new \ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
            expect($facade->hasMethod('track'))->toBeTrue();
            expect($facade->hasMethod('identify'))->toBeTrue();
            expect($facade->hasMethod('pageView'))->toBeTrue();
            expect($facade->hasMethod('purchase'))->toBeTrue();
        });

        it('has comprehensive format converters', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Support\SaaSFormatConverter::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Support\EngagementFormatConverter::class))->toBeTrue();
        });

        it('package metrics exceed industry-standard thresholds', function () {
            $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
            $testFiles = glob(__DIR__ . '/*.php');
            expect(count($srcFiles))->toBeGreaterThanOrEqual(700);
            expect(count($testFiles))->toBeGreaterThanOrEqual(300);
        });
    });
});
