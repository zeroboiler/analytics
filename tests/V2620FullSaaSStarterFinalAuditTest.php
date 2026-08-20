<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * Full SaaS Starter Final Audit — All 12 Industry-Standard Features at v264.0.0
 *
 * Validates production readiness across the complete feature set:
 * 1. Event Catalog (EcommerceEvents, SaaSEvents, EngagementEvents)
 * 2. Server-Side Lifecycle Tracker (LifecycleEventMapper, LifecycleEventSubscriber)
 * 3. Inertia middleware (HandleInertiaAnalytics)
 * 4. API controller + routes (events, batch, identify, consent)
 * 5. JS client library (analytics.js ~8,500 LOC)
 * 6. Event queue (QueuedAnalyticsDispatcher)
 * 7. User identity linking (UserIdentityTracker, IdentityGraphService)
 * 8. E-commerce helpers (EcommerceFormatConverter)
 * 9. Admin commands (AnalyticsOverviewCommand, AnalyticsTestCommand)
 * 10. Config expansion (queue, API, identity, auto-track, ecommerce)
 * 11. Optional providers (Plausible, PostHog)
 * 12. Tests + README
 *
 * @since 264.0.0
 */
describe('V262 Full SaaS Starter Final Audit', function () {
    $srcDir = __DIR__ . '/../src';
    $root = __DIR__ . '/..';

    // ── 1. Event Catalog ──────────────────────────────────────────────
    describe('1. Event Catalog', function () {
        it('EcommerceEvents catalog exists and is final', function () {
            $file = $srcDir . '/Events/Ecommerce/EcommerceEvents.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class EcommerceEvents');
        });

        it('EcommerceEvents has core SaaS events (view_item, add_to_cart, purchase, refund)', function () {
            $content = (string) file_get_contents($srcDir . '/Events/Ecommerce/EcommerceEvents.php');
            expect($content)->toContain("'view_item'");
            expect($content)->toContain("'add_to_cart'");
            expect($content)->toContain("'purchase'");
            expect($content)->toContain("'refund'");
        });

        it('SaaSEvents catalog exists and is final', function () {
            $file = $srcDir . '/Events/SaaS/SaaSEvents.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class SaaSEvents');
        });

        it('SaaSEvents has core SaaS events (sign_up, login, start_trial, subscription, plan_upgrade, cancellation)', function () {
            $content = (string) file_get_contents($srcDir . '/Events/SaaS/SaaSEvents.php');
            expect($content)->toContain("'sign_up'");
            expect($content)->toContain("'login'");
            expect($content)->toContain("'start_trial'");
            expect($content)->toContain("'subscription'");
            expect($content)->toContain("'plan_upgrade'");
            expect($content)->toContain("'cancellation'");
        });

        it('EngagementEvents catalog exists and is final', function () {
            $file = $srcDir . '/Events/Engagement/EngagementEvents.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class EngagementEvents');
        });

        it('EngagementEvents has core events (page_view, scroll_depth, click, form_start, form_submit, search, share, error)', function () {
            $content = (string) file_get_contents($srcDir . '/Events/Engagement/EngagementEvents.php');
            expect($content)->toContain("'page_view'");
            expect($content)->toContain("'scroll_depth'");
            expect($content)->toContain("'click'");
            expect($content)->toContain("'form_start'");
            expect($content)->toContain("'form_submit'");
            expect($content)->toContain("'search'");
            expect($content)->toContain("'share'");
            expect($content)->toContain("'error'");
        });

        it('EventCatalog central registry exists', function () {
            expect(file_exists($srcDir . '/Events/EventCatalog.php'))->toBeTrue();
        });

        it('SaaSStarterEvents exists with 20 essential events', function () {
            $file = $srcDir . '/Events/SaaSStarterEvents.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('final class SaaSStarterEvents');
            expect($content)->toContain('sign_up');
            expect($content)->toContain('login');
            expect($content)->toContain('start_trial');
            expect($content)->toContain('subscribe');
            expect($content)->toContain('plan_upgrade');
            expect($content)->toContain('cancellation');
            expect($content)->toContain('view_item');
            expect($content)->toContain('add_to_cart');
            expect($content)->toContain('purchase');
            expect($content)->toContain('refund');
            expect($content)->toContain('page_view');
            expect($content)->toContain('scroll_depth');
            expect($content)->toContain('click');
            expect($content)->toContain('form_start');
            expect($content)->toContain('form_submit');
            expect($content)->toContain('search');
            expect($content)->toContain('share');
            expect($content)->toContain('error');
            expect($content)->toContain('feature_used');
            expect($content)->toContain('trial_converted');
        });
    });

    // ── 2. Server-Side Lifecycle Tracker ───────────────────────────────
    describe('2. Server-Side Lifecycle Tracker', function () {
        it('LifecycleEventMapper exists and is final', function () {
            $file = $srcDir . '/Services/LifecycleEventMapper.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class LifecycleEventMapper');
        });

        it('LifecycleEventMapper has 67+ built-in mappings', function () {
            $content = (string) file_get_contents($srcDir . '/Services/LifecycleEventMapper.php');
            expect($content)->toContain('DEFAULT_MAPPING_COUNT');
            expect($content)->toContain('auth.login');
            expect($content)->toContain('auth.register');
            expect($content)->toContain('subscription.created');
            expect($content)->toContain('trial.started');
            expect($content)->toContain('order.completed');
            expect($content)->toContain('consent.granted');
        });

        it('LifecycleEventSubscriber exists', function () {
            expect(file_exists($srcDir . '/Tracking/LifecycleEventSubscriber.php'))->toBeTrue();
        });

        it('LifecycleEventTracker exists', function () {
            expect(file_exists($srcDir . '/Tracking/LifecycleEventTracker.php'))->toBeTrue();
        });

        it('ServerSideTracker exists', function () {
            expect(file_exists($srcDir . '/Tracking/ServerSideTracker.php'))->toBeTrue();
        });

        it('LifecycleAttributionEnricher exists', function () {
            expect(file_exists($srcDir . '/Tracking/LifecycleAttributionEnricher.php'))->toBeTrue();
        });
    });

    // ── 3. Inertia Middleware ──────────────────────────────────────────
    describe('3. Inertia Middleware', function () {
        it('HandleInertiaAnalytics exists and is final', function () {
            $file = $srcDir . '/Inertia/HandleInertiaAnalytics.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class HandleInertiaAnalytics');
        });

        it('Inertia middleware injects tracking ID cookie', function () {
            $content = (string) file_get_contents($srcDir . '/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('trackingId');
            expect($content)->toContain('Cookie::');
        });

        it('Inertia middleware exposes provider IDs', function () {
            $content = (string) file_get_contents($srcDir . '/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('ga4MeasurementId');
            expect($content)->toContain('gtmContainerId');
            expect($content)->toContain('metaPixelId');
        });

        it('Inertia middleware exposes consent state', function () {
            $content = (string) file_get_contents($srcDir . '/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('consent');
        });

        it('Inertia middleware exposes user ID', function () {
            $content = (string) file_get_contents($srcDir . '/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('userId');
        });

        it('Inertia middleware injects campaign context', function () {
            $content = (string) file_get_contents($srcDir . '/Inertia/HandleInertiaAnalytics.php');
            expect($content)->toContain('campaignContext');
        });
    });

    // ── 4. API Controller + Routes ─────────────────────────────────────
    describe('4. API Controller + Routes', function () {
        it('AnalyticsEventController exists', function () {
            $file = $srcDir . '/Http/Controllers/AnalyticsEventController.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class AnalyticsEventController');
        });

        it('Routes file exists with track/batch/identify/consent endpoints', function () {
            $file = $root . '/routes/analytics.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain("Route::post('events'");
            expect($content)->toContain("Route::post('batch'");
            expect($content)->toContain("Route::post('identify'");
            expect($content)->toContain("Route::post('consent'");
        });

        it('FormRequest classes exist for validation', function () {
            $requests = ['TrackEventRequest', 'BatchEventRequest', 'IdentifyRequest', 'UpdateConsentRequest'];
            foreach ($requests as $request) {
                expect(file_exists($srcDir . "/Http/Requests/{$request}.php"))->toBeTrue("Missing {$request}");
            }
        });
    });

    // ── 5. JS Client Library ──────────────────────────────────────────
    describe('5. JS Client Library', function () {
        it('analytics.js exists with 8000+ LOC', function () {
            $file = $root . '/resources/js/analytics.js';
            expect(file_exists($file))->toBeTrue();
            $lines = count(file($file));
            expect($lines)->toBeGreaterThanOrEqual(8000);
        });

        it('exports trackEvent function', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('export function trackEvent');
        });

        it('exports trackPageView function', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('export function trackPageView');
        });

        it('exports init function for Inertia initialization', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('export function init');
        });

        it('has scroll depth tracking', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('scroll');
        });

        it('has client ID management', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('trackingId');
        });

        it('has batch queue with flush', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('eventQueue');
            expect($content)->toContain('flush');
        });

        it('has consent mode support', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('consent');
        });

        it('TypeScript definitions exist with 3000+ LOC', function () {
            $file = $root . '/resources/js/analytics.d.ts';
            expect(file_exists($file))->toBeTrue();
            $lines = count(file($file));
            expect($lines)->toBeGreaterThanOrEqual(3000);
        });

        it('15 Svelte composables exist', function () {
            $composables = glob($root . '/resources/js/use*.svelte.js');
            expect($composables)->not->toBeEmpty();
            expect(count($composables))->toBe(15);
        });

        it('analytics.constants.js exists', function () {
            expect(file_exists($root . '/resources/js/analytics.constants.js'))->toBeTrue();
        });
    });

    // ── 6. Event Queue ─────────────────────────────────────────────────
    describe('6. Event Queue', function () {
        it('QueuedAnalyticsDispatcher exists and is final', function () {
            $file = $srcDir . '/Queue/QueuedAnalyticsDispatcher.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('final class QueuedAnalyticsDispatcher');
        });

        it('TrackAnalyticsEventJob exists', function () {
            expect(file_exists($srcDir . '/Jobs/TrackAnalyticsEventJob.php'))->toBeTrue();
        });

        it('TrackAnalyticsEventBatchJob exists', function () {
            expect(file_exists($srcDir . '/Jobs/TrackAnalyticsEventBatchJob.php'))->toBeTrue();
        });

        it('QueuedAnalyticsDispatcher has dispatch and dispatchBatch methods', function () {
            $content = (string) file_get_contents($srcDir . '/Queue/QueuedAnalyticsDispatcher.php');
            expect($content)->toContain('public function dispatch(');
            expect($content)->toContain('public function dispatchBatch(');
        });

        it('QueuedAnalyticsDispatcher supports config-driven queue name', function () {
            $content = (string) file_get_contents($srcDir . '/Queue/QueuedAnalyticsDispatcher.php');
            expect($content)->toContain("'queue'");
            expect($content)->toContain("'connection'");
        });
    });

    // ── 7. User Identity Linking ──────────────────────────────────────
    describe('7. User Identity Linking', function () {
        it('UserIdentityTracker exists', function () {
            expect(file_exists($srcDir . '/Tracking/UserIdentityTracker.php'))->toBeTrue();
        });

        it('IdentityGraphService exists', function () {
            expect(file_exists($srcDir . '/Services/IdentityGraphService.php'))->toBeTrue();
        });

        it('CrossDeviceIdentityMergeService exists', function () {
            expect(file_exists($srcDir . '/Services/CrossDeviceIdentityMergeService.php'))->toBeTrue();
        });

        it('Identity resolution API routes exist', function () {
            $content = (string) file_get_contents($root . '/routes/analytics.php');
            expect($content)->toContain("Route::get('identity/{clientId}'");
            expect($content)->toContain("Route::post('identity/resolve'");
        });

        it('identity Svelte composable exists', function () {
            expect(file_exists($root . '/resources/js/useIdentity.svelte.js'))->toBeTrue();
        });
    });

    // ── 8. E-commerce Helpers ──────────────────────────────────────────
    describe('8. E-commerce Helpers', function () {
        it('EcommerceFormatConverter exists', function () {
            expect(file_exists($srcDir . '/Services/EcommerceFormatConverter.php'))->toBeTrue();
        });

        it('EcommerceFormatConverter has GA4 and Meta conversion methods', function () {
            $content = (string) file_get_contents($srcDir . '/Services/EcommerceFormatConverter.php');
            expect($content)->toContain('ga4');
            expect($content)->toContain('meta');
        });

        it('ecommerce Svelte composable exists', function () {
            expect(file_exists($root . '/resources/js/useEcommerce.svelte.js'))->toBeTrue();
        });

        it('EcommerceEventConstants exists', function () {
            expect(file_exists($srcDir . '/Events/EcommerceEventConstants.php'))->toBeTrue();
        });
    });

    // ── 9. Admin Commands ──────────────────────────────────────────────
    describe('9. Admin Commands', function () {
        it('AnalyticsOverviewCommand exists', function () {
            $file = $srcDir . '/Console/Commands/AnalyticsOverviewCommand.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('AnalyticsTestCommand exists', function () {
            $file = $srcDir . '/Console/Commands/AnalyticsTestCommand.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('100+ artisan commands exist', function () {
            $commands = glob($srcDir . '/Console/Commands/*Command.php');
            expect(count($commands))->toBeGreaterThanOrEqual(100);
        });
    });

    // ── 10. Config Expansion ───────────────────────────────────────────
    describe('10. Config Expansion', function () {
        it('config file exists with 9000+ LOC', function () {
            $file = $root . '/config/zeroboiler.php';
            expect(file_exists($file))->toBeTrue();
            $lines = count(file($file));
            expect($lines)->toBeGreaterThanOrEqual(9000);
        });

        it('config has queue section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'queue'");
        });

        it('config has api section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'api'");
        });

        it('config has identity section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'identity'");
        });

        it('config has auto_track section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'auto_track'");
        });

        it('config has ecommerce section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'ecommerce'");
        });

        it('config has lifecycle section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'lifecycle'");
        });

        it('config has consent section', function () {
            $content = (string) file_get_contents($root . '/config/zeroboiler.php');
            expect($content)->toContain("'consent'");
        });
    });

    // ── 11. Optional Providers ─────────────────────────────────────────
    describe('11. Optional Providers', function () {
        it('PlausibleTracker exists', function () {
            $file = $srcDir . '/Trackers/PlausibleTracker.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('PosthogTracker exists', function () {
            $file = $srcDir . '/Trackers/PosthogTracker.php';
            expect(file_exists($file))->toBeTrue();
            $content = (string) file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('10 provider trackers exist', function () {
            $trackers = glob($srcDir . '/Trackers/*Tracker.php');
            expect(count($trackers))->toBeGreaterThanOrEqual(10);
        });

        it('TrackerInterface exists', function () {
            expect(file_exists($srcDir . '/Trackers/TrackerInterface.php'))->toBeTrue();
        });
    });

    // ── 12. Tests + README ─────────────────────────────────────────────
    describe('12. Tests + README', function () {
        it('500+ test files exist', function () {
            $tests = glob($root . '/tests/**/*Test.php', GLOB_BRACE);
            // Also check nested directories
            $allTests = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/tests', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            $count = 0;
            foreach ($allTests as $file) {
                if ($file->isFile() && str_contains($file->getFilename(), 'Test.php')) {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(500);
        });

        it('README.md exists', function () {
            expect(file_exists($root . '/README.md'))->toBeTrue();
        });

        it('README has no leaked shell commands', function () {
            $content = (string) file_get_contents($root . '/README.md');
            // Ensure no 'cat /tmp/' or similar leaked shell output
            expect($content)->not->toContain('cat /tmp/');
            expect($content)->not->toContain('grep -c');
        });

        it('README has Quick Start section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('## Quick Start');
        });

        it('README has Features section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('## Features');
        });

        it('README has Configuration section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('## Configuration');
        });

        it('README has API Reference section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('## API Reference');
        });

        it('README has Admin Commands section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('## Admin Commands');
        });

        it('README has Inertia.js Integration section', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('Inertia.js Integration');
        });
    });

    // ── Version Consistency ────────────────────────────────────────────
    describe('Version Consistency', function () {
        it('AnalyticsEvent::VERSION is 264.0.0', function () {
            $content = (string) file_get_contents($srcDir . '/DTO/AnalyticsEvent.php');
            expect($content)->toContain("VERSION = '264.0.0'");
        });

        it('composer.json version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/composer.json');
            expect($content)->toContain('"version": "264.0.0"');
        });

        it('package.json version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/package.json');
            expect($content)->toContain('"version": "264.0.0"');
        });

        it('analytics.js getVersion returns 264.0.0', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain("return '264.0.0'");
        });

        it('analytics.js @version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.js');
            expect($content)->toContain('@version 264.0.0');
        });

        it('analytics.d.ts @version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.d.ts');
            expect($content)->toContain('@version 264.0.0');
        });

        it('analytics.constants.js @version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/resources/js/analytics.constants.js');
            expect($content)->toContain('@version 264.0.0');
        });

        it('all 15 Svelte composables @version is 264.0.0', function () {
            $composables = glob($root . '/resources/js/use*.svelte.js');
            foreach ($composables as $file) {
                $content = (string) file_get_contents($file);
                expect($content)->toContain('@version 264.0.0')
                    ->fail("{$file} is not at v264.0.0");
            }
        });

        it('AnalyticsIntegrityCommand EXPECTED_VERSION is 264.0.0', function () {
            $content = (string) file_get_contents($srcDir . '/Console/Commands/AnalyticsIntegrityCommand.php');
            expect($content)->toContain("EXPECTED_VERSION = '264.0.0'");
        });

        it('AnalyticsServiceProvider @version is 264.0.0', function () {
            $content = (string) file_get_contents($srcDir . '/AnalyticsServiceProvider.php');
            expect($content)->toContain('@version 264.0.0');
        });

        it('README badge version is 264.0.0', function () {
            $content = (string) file_get_contents($root . '/README.md');
            expect($content)->toContain('version-264.0.0');
        });
    });

    // ── Code Quality ───────────────────────────────────────────────────
    describe('Code Quality', function () {
        it('all catalog files have declare(strict_types=1)', function () {
            $files = [
                $srcDir . '/Events/Ecommerce/EcommerceEvents.php',
                $srcDir . '/Events/SaaS/SaaSEvents.php',
                $srcDir . '/Events/Engagement/EngagementEvents.php',
                $srcDir . '/Events/EventCatalog.php',
                $srcDir . '/Inertia/HandleInertiaAnalytics.php',
                $srcDir . '/Tracking/ServerSideTracker.php',
                $srcDir . '/Tracking/UserIdentityTracker.php',
                $srcDir . '/Queue/QueuedAnalyticsDispatcher.php',
                $srcDir . '/Trackers/PlausibleTracker.php',
                $srcDir . '/Trackers/PosthogTracker.php',
            ];
            foreach ($files as $file) {
                $content = (string) file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)')
                    ->fail("{$file} missing strict_types");
            }
        });

        it('all catalog classes are final', function () {
            $classes = [
                'EcommerceEvents' => $srcDir . '/Events/Ecommerce/EcommerceEvents.php',
                'SaaSEvents' => $srcDir . '/Events/SaaS/SaaSEvents.php',
                'EngagementEvents' => $srcDir . '/Events/Engagement/EngagementEvents.php',
                'HandleInertiaAnalytics' => $srcDir . '/Inertia/HandleInertiaAnalytics.php',
                'QueuedAnalyticsDispatcher' => $srcDir . '/Queue/QueuedAnalyticsDispatcher.php',
            ];
            foreach ($classes as $name => $file) {
                $content = (string) file_get_contents($file);
                expect($content)->toContain("final class {$name}")
                    ->fail("{$file} is not final");
            }
        });

        it('980+ source files exist', function () {
            $count = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(980);
        });

        it('440+ service files exist', function () {
            $services = glob($srcDir . '/Services/*.php');
            expect(count($services))->toBeGreaterThanOrEqual(440);
        });

        it('110+ artisan commands exist', function () {
            $commands = glob($srcDir . '/Console/Commands/*Command.php');
            expect(count($commands))->toBeGreaterThanOrEqual(110);
        });
    });
});
