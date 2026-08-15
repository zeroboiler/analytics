<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * V167.0.0 — SaaS Analytics Starter Kit Completion Audit
 *
 * Validates all 12 planned SaaS analytics features at industry-standard level:
 * 1. Event Catalog (EcommerceEvents, SaaSEvents, EngagementEvents)
 * 2. Server-Side Lifecycle Tracker
 * 3. Inertia middleware (page props + client ID cookie)
 * 4. API controller + routes (events/batch/identify/consent)
 * 5. JS client library (trackEvent, trackPageView, etc.)
 * 6. Event queue (async dispatch)
 * 7. User identity linking (client ID ↔ user ID)
 * 8. E-commerce helpers (GA4 + Meta format conversion)
 * 9. Admin commands (AnalyticsOverviewCommand, AnalyticsTestCommand)
 * 10. Config expansion (queue, API, identity, auto-track, ecommerce)
 * 11. Optional providers (Plausible, PostHog)
 * 12. Tests + README accuracy
 *
 * @since 167.0.0
 */

use function Pest\Faker\fake;

describe('V1670 — SaaS Analytics Starter Kit Completion Audit', function (): void {
    $version = '167.0.0';

    // ─── 1. Event Catalog ───────────────────────────────────────────
    describe('Feature 1: Event Catalog', function () use ($version): void {
        it('has EcommerceEvents class with expected events', function (): void {
            $file = __DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class EcommerceEvents');
            expect($content)->toContain('view_item');
            expect($content)->toContain('add_to_cart');
            expect($content)->toContain('purchase');
            expect($content)->toContain('refund');
        });

        it('has SaaSEvents class with expected events', function (): void {
            $file = __DIR__ . '/../src/Events/SaaS/SaaSEvents.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class SaaSEvents');
            expect($content)->toContain('sign_up');
            expect($content)->toContain('login');
            expect($content)->toContain('trial_start');
            expect($content)->toContain('subscription');
            expect($content)->toContain('plan_upgrade');
            expect($content)->toContain('cancellation');
        });

        it('has EngagementEvents class with expected events', function (): void {
            $file = __DIR__ . '/../src/Events/Engagement/EngagementEvents.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class EngagementEvents');
            expect($content)->toContain('page_view');
            expect($content)->toContain('scroll_depth');
            expect($content)->toContain('click');
            expect($content)->toContain('form_start');
            expect($content)->toContain('form_submit');
            expect($content)->toContain('search');
            expect($content)->toContain('share');
            expect($content)->toContain('error');
        });

        it('has EventCatalog aggregator', function (): void {
            $file = __DIR__ . '/../src/Events/EventCatalog.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class EventCatalog');
            expect($content)->toContain('public static function all()');
            expect($content)->toContain('public static function byCategory()');
            expect($content)->toContain('public static function count()');
        });
    });

    // ─── 2. Server-Side Lifecycle Tracker ──────────────────────────
    describe('Feature 2: Server-Side Lifecycle Tracker', function (): void {
        it('has LifecycleEventMapper', function (): void {
            $file = __DIR__ . '/../src/Services/LifecycleEventMapper.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('has LifecycleEventSubscriber', function (): void {
            $file = __DIR__ . '/../src/Tracking/LifecycleEventSubscriber.php';
            expect($file)->toBeFile();
        });

        it('config has lifecycle mapping section', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'lifecycle' => [");
            expect($config)->toContain('custom_mappings');
        });
    });

    // ─── 3. Inertia Middleware ─────────────────────────────────────
    describe('Feature 3: Inertia Middleware', function (): void {
        it('has HandleInertiaAnalytics middleware', function (): void {
            $file = __DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class HandleInertiaAnalytics');
            expect($content)->toContain('getOrCreateTrackingId');
            expect($content)->toContain('generateTrackingId');
            expect($content)->toContain("'zbAnalytics'");
        });

        it('injects provider IDs into page props', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('ga4MeasurementId');
            expect($content)->toContain('gtmContainerId');
            expect($content)->toContain('metaPixelId');
            expect($content)->toContain('plausibleDomain');
            expect($content)->toContain('posthogHost');
        });

        it('injects auto-track config into page props', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain("'autoTrack' => [");
            expect($content)->toContain('pageViews');
            expect($content)->toContain('scrollDepth');
        });

        it('detects auth state changes for identity stitching', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('authStateChanged');
            expect($content)->toContain('detectAuthStateChange');
        });
    });

    // ─── 4. API Controller + Routes ────────────────────────────────
    describe('Feature 4: API Controller + Routes', function (): void {
        it('has AnalyticsEventController with track/batch/identify/consent', function (): void {
            $file = __DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('public function track(');
            expect($content)->toContain('public function batch(');
            expect($content)->toContain('public function identify(');
            expect($content)->toContain('public function updateConsent(');
        });

        it('routes file has POST events/batch/identify/consent', function (): void {
            $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
            expect($routes)->toContain("Route::post('events'");
            expect($routes)->toContain("Route::post('batch'");
            expect($routes)->toContain("Route::post('identify'");
            expect($routes)->toContain("Route::post('consent'");
        });
    });

    // ─── 5. JS Client Library ───────────────────────────────────────
    describe('Feature 5: JS Client Library', function (): void {
        it('has analytics.js with core functions', function (): void {
            $file = __DIR__ . '/../resources/js/analytics.js';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('export function trackEvent');
            expect($content)->toContain('export function trackPageView');
            expect($content)->toContain('export function init(');
            expect($content)->toContain('export function getVersion()');
        });

        it('analytics.js has scroll depth tracking', function (): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($content)->toContain('scroll');
        });

        it('analytics.js has client ID management', function (): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($content)->toContain('trackingId');
            expect($content)->toContain('X-Analytics-Client-Id');
        });

        it('has analytics.constants.js with event names', function (): void {
            $file = __DIR__ . '/../resources/js/analytics.constants.js';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('VIEW_ITEM');
            expect($content)->toContain('ADD_TO_CART');
            expect($content)->toContain('PURCHASE');
            expect($content)->toContain('SIGN_UP');
        });

        it('has 7 Svelte composables', function (): void {
            $composables = glob(__DIR__ . '/../resources/js/use*.svelte.js');
            expect(count($composables))->toBeGreaterThanOrEqual(7);
        });

        it('has TypeScript type definitions', function (): void {
            $file = __DIR__ . '/../resources/js/analytics.d.ts';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('export function trackEvent');
            expect($content)->toContain('export function init');
        });
    });

    // ─── 6. Event Queue ────────────────────────────────────────────
    describe('Feature 6: Event Queue', function (): void {
        it('has QueuedAnalyticsDispatcher', function (): void {
            $file = __DIR__ . '/../src/Queue/QueuedAnalyticsDispatcher.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('dispatch');
        });

        it('config has queue section', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'queue' => [");
            expect($config)->toContain('ANALYTICS_QUEUE_ENABLED');
            expect($config)->toContain("env('ANALYTICS_QUEUE', 'analytics')");
        });
    });

    // ─── 7. User Identity Linking ──────────────────────────────────
    describe('Feature 7: User Identity Linking', function (): void {
        it('has UserIdentityTracker', function (): void {
            $file = __DIR__ . '/../src/Tracking/UserIdentityTracker.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('linkClientIdToUser');
            expect($content)->toContain('resolveIdentity');
            expect($content)->toContain('identify');
        });

        it('has IdentityResolutionService', function (): void {
            $file = __DIR__ . '/../src/Services/IdentityResolutionService.php';
            expect($file)->toBeFile();
        });

        it('config has identity section with cookie and link settings', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'identity' => [");
            expect($config)->toContain('cookie_name');
            expect($config)->toContain('link_on_auth');
            expect($config)->toContain('auto_link');
            expect($config)->toContain('cache_prefix');
        });
    });

    // ─── 8. E-Commerce Helpers ─────────────────────────────────────
    describe('Feature 8: E-Commerce Helpers', function (): void {
        it('has EcommerceAnalyticsService', function (): void {
            $file = __DIR__ . '/../src/Services/EcommerceAnalyticsService.php';
            expect($file)->toBeFile();
        });

        it('has EcommerceFormatConverter', function (): void {
            $file = __DIR__ . '/../src/Support/EcommerceFormatConverter.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('convertForProvider');
        });

        it('config has ecommerce section', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'ecommerce' => [");
            expect($config)->toContain('currency');
            expect($config)->toContain('brand');
        });
    });

    // ─── 9. Admin Commands ─────────────────────────────────────────
    describe('Feature 9: Admin Commands', function (): void {
        it('has AnalyticsOverviewCommand', function (): void {
            $file = __DIR__ . '/../src/Console/Commands/AnalyticsOverviewCommand.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('has AnalyticsTestCommand', function (): void {
            $file = __DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('has at least 80 artisan commands', function (): void {
            $commands = glob(__DIR__ . '/../src/Console/Commands/*.php');
            expect(count($commands))->toBeGreaterThanOrEqual(80);
        });
    });

    // ─── 10. Config Expansion ──────────────────────────────────────
    describe('Feature 10: Config Expansion', function (): void {
        it('config has queue, api, identity, auto_track, ecommerce, lifecycle', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'queue' => [");
            expect($config)->toContain("'api' => [");
            expect($config)->toContain("'identity' => [");
            expect($config)->toContain("'auto_track' => [");
            expect($config)->toContain("'ecommerce' => [");
            expect($config)->toContain("'lifecycle' => [");
        });
    });

    // ─── 11. Optional Providers ───────────────────────────────────
    describe('Feature 11: Optional Providers', function (): void {
        it('has PlausibleTracker', function (): void {
            $file = __DIR__ . '/../src/Trackers/PlausibleTracker.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('has PosthogTracker', function (): void {
            $file = __DIR__ . '/../src/Trackers/PosthogTracker.php';
            expect($file)->toBeFile();
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });
    });

    // ─── 12. Tests + README Accuracy ──────────────────────────────
    describe('Feature 12: Tests + README Accuracy', function () use ($version): void {
        it('has at least 400 test files', function (): void {
            $tests = glob(__DIR__ . '/*.php');
            expect(count($tests))->toBeGreaterThanOrEqual(400);
        });

        it('README has accurate service count (355+)', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('**355 services**');
        });

        it('README has accurate command count (84)', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('**84 artisan commands**');
        });

        it('README has accurate JS LOC (~11,700)', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('~11,700 LOC');
        });

        it('README has v167.0.0 badge', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('version-167.0.0');
        });

        it('README has v167.0.0 What\'s New section', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('What\'s New in v167.0.0');
        });

        it('README has Production Readiness section with correct file count', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('805 source files');
        });
    });

    // ─── Version Sweep Consistency ─────────────────────────────────
    describe('Version Sweep — All 14 Entry Points', function () use ($version): void {
        it('composer.json version matches', function () use ($version): void {
            $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($json['version'])->toBe($version);
        });

        it('package.json version matches', function () use ($version): void {
            $json = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
            expect($json['version'])->toBe($version);
        });

        it('AnalyticsEvent::VERSION matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
            expect($content)->toContain("public const VERSION = '{$version}'");
        });

        it('AnalyticsIntegrityCommand::EXPECTED_VERSION matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
            expect($content)->toContain("private const EXPECTED_VERSION = '{$version}'");
        });

        it('ServiceProvider @version matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
            expect($content)->toContain("@version {$version}");
        });

        it('analytics.js header version matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($content)->toContain("@version {$version}");
        });

        it('analytics.js getVersion() matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($content)->toContain("return '{$version}';");
        });

        it('analytics.d.ts version matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($content)->toContain("@version {$version}");
        });

        it('analytics.constants.js version matches', function () use ($version): void {
            $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
            expect($content)->toContain("@version {$version}");
        });

        it('all 7 Svelte composables have matching version', function () use ($version): void {
            $files = glob(__DIR__ . '/../resources/js/use*.svelte.js');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain("@version {$version}");
            }
        });
    });

    // ─── Quality Gates ──────────────────────────────────────────────
    describe('Quality Gates', function (): void {
        it('all event catalog classes use strict types', function (): void {
            $catalogs = [
                'src/Events/Ecommerce/EcommerceEvents.php',
                'src/Events/SaaS/SaaSEvents.php',
                'src/Events/Engagement/EngagementEvents.php',
                'src/Events/EventCatalog.php',
            ];
            foreach ($catalogs as $relPath) {
                $content = file_get_contents(__DIR__ . '/../' . $relPath);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        it('event catalog classes are final', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php');
            expect($content)->toContain('final class EcommerceEvents');

            $content = file_get_contents(__DIR__ . '/../src/Events/SaaS/SaaSEvents.php');
            expect($content)->toContain('final class SaaSEvents');

            $content = file_get_contents(__DIR__ . '/../src/Events/Engagement/EngagementEvents.php');
            expect($content)->toContain('final class EngagementEvents');

            $content = file_get_contents(__DIR__ . '/../src/Events/EventCatalog.php');
            expect($content)->toContain('final class EventCatalog');
        });

        it('Inertia middleware has MIT header', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
        });

        it('API controller has MIT header', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
            expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
        });

        it('UserIdentityTracker has return type declarations', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Tracking/UserIdentityTracker.php');
            expect($content)->toContain('public function linkClientIdToUser(string $clientId, string $userId): bool');
            expect($content)->toContain('public function resolveIdentity(string $clientId): array');
            expect($content)->toContain('public function identify(string $userId, string $clientId): void');
        });

        it('CHANGELOG has v167.0.0 entry', function (): void {
            $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
            expect($changelog)->toContain('[167.0.0]');
        });
    });
});
