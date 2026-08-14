<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;

/**
 * Phase 38 — SaaS Starter Maturity Audit.
 *
 * Comprehensive verification that all 12 SaaS starter upgrade criteria
 * remain fully valid at v110.0.0. Validates class structure, method
 * signatures, provider coverage, version integrity, and production
 * readiness without requiring a Laravel application bootstrap.
 *
 * @since 110.0.0
 */
describe('V110SaaSStarterMaturityAudit', function () {
    // ── Version Integrity ───────────────────────────────────────────────

    describe('Version Integrity', function () {
        it('version is 110.0.0 across all package files', function () {
            $version = '110.0.0';

            // AnalyticsEvent::VERSION
            expect(AnalyticsEvent::VERSION)->toBe($version);

            // composer.json
            $composer = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['version'])->toBe($version);

            // package.json
            $pkg = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($pkg['version'])->toBe($version);

            // README.md version badge
            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect(str_contains($readme, "version-{$version}"))->toBeTrue(
                "README.md version badge should show {$version}"
            );

            // JS client files
            $jsFiles = [
                'analytics.js',
                'analytics.d.ts',
                'analytics.constants.js',
                'useAnalytics.svelte.js',
                'useLifecycle.svelte.js',
                'useSessionReplay.svelte.js',
                'usePerformanceTracker.svelte.js',
                'useAnalyticsConfig.svelte.js',
            ];
            $jsDir = dirname(__DIR__, 2) . '/resources/js';
            foreach ($jsFiles as $file) {
                $content = file_get_contents("{$jsDir}/{$file}");
                expect(str_contains($content, "@version {$version}"))
                    ->toBeTrue("{$file} should have @version {$version}");
            }
        });

        it('AnalyticsEvent DTO is readonly', function () {
            $ref = new \ReflectionClass(AnalyticsEvent::class);
            expect($ref->isReadOnly())->toBeTrue('AnalyticsEvent should be a readonly class');
            expect($ref->isFinal())->toBeTrue('AnalyticsEvent should be final');
        });

        it('AnalyticsEvent has VERSION constant', function () {
            $ref = new \ReflectionClass(AnalyticsEvent::class);
            expect($ref->hasConstant('VERSION'))->toBeTrue();
            expect($ref->getConstant('VERSION'))->toBe('110.0.0');
        });
    });

    // ── Criterion 1: Event Catalog ────────────────────────────────────

    describe('Criterion 1: Event Catalog', function () {
        it('EventCatalog is final with strict types', function () {
            $ref = new \ReflectionClass(EventCatalog::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('EcommerceEvents has core e-commerce events', function () {
            $expected = ['ViewItem', 'AddToCart', 'Purchase', 'Refund'];
            foreach ($expected as $event) {
                expect(defined(EcommerceEvents::class . '::' . strtoupper($event)))
                    ->toBeTrue("EcommerceEvents::{$event} should exist");
            }
        });

        it('SaaSEvents has core SaaS events', function () {
            $expected = ['SignUp', 'Login', 'TrialStart', 'Subscription', 'PlanUpgrade', 'Cancellation'];
            foreach ($expected as $event) {
                expect(defined(SaaSEvents::class . '::' . strtoupper($event)))
                    ->toBeTrue("SaaSEvents::{$event} should exist");
            }
        });

        it('EngagementEvents has core engagement events', function () {
            $expected = ['PageView', 'ScrollDepth', 'Click', 'FormStart', 'FormSubmit', 'Search', 'Share', 'Error'];
            foreach ($expected as $event) {
                expect(defined(EngagementEvents::class . '::' . strtoupper($event)))
                    ->toBeTrue("EngagementEvents::{$event} should exist");
            }
        });

        it('EventCatalog has 6+ categories', function () {
            $categories = EventCatalog::byCategory();
            expect(count($categories))->toBeGreaterThanOrEqual(6);
            expect(isset($categories['ecommerce']))->toBeTrue();
            expect(isset($categories['saas']))->toBeTrue();
            expect(isset($categories['engagement']))->toBeTrue();
            expect(isset($categories['security']))->toBeTrue();
            expect(isset($categories['uptime']))->toBeTrue();
            expect(isset($categories['infrastructure']))->toBeTrue();
        });

        it('EventCatalog has 50+ total events', function () {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(50);
        });

        it('EventCatalog provides provider name lookups for all 8 providers', function () {
            $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
            foreach ($providers as $provider) {
                $method = 'all' . ucfirst($provider) . 'Names';
                expect(method_exists(EventCatalog::class, $method))
                    ->toBeTrue("EventCatalog::{$method}() should exist");
            }
        });

        it('EventCatalog has resolve() and resolveAndGet() methods', function () {
            expect(method_exists(EventCatalog::class, 'resolve'))->toBeTrue();
            expect(method_exists(EventCatalog::class, 'resolveAndGet'))->toBeTrue();

            // Verify resolve works for camelCase → snake_case
            expect(EventCatalog::resolve('ViewItem'))->toBe('view_item');
            expect(EventCatalog::resolve('AddToCart'))->toBe('add_to_cart');
            expect(EventCatalog::resolve('SignUp'))->toBe('sign_up');
            expect(EventCatalog::resolve('PageView'))->toBe('page_view');
        });
    });

    // ── Criterion 2: Server-Side Lifecycle Tracker ──────────────────────

    describe('Criterion 2: Server-Side Lifecycle Tracker', function () {
        it('LifecycleEventMapper exists with strict types', function () {
            expect(class_exists(LifecycleEventMapper::class))->toBeTrue();
            $ref = new \ReflectionClass(LifecycleEventMapper::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('LifecycleEventMapper has 60+ default mappings', function () {
            expect(LifecycleEventMapper::DEFAULT_MAPPING_COUNT)->toBeGreaterThanOrEqual(60);
        });

        it('LifecycleEventMapper covers auth, subscription, trial, feature, ecommerce, engagement', function () {
            $ref = new \ReflectionClass(LifecycleEventMapper::class);
            $mappings = $ref->getConstant('DEFAULT_MAPPINGS');
            expect($mappings)->toBeArray();
            expect($mappings)->toHaveKey('auth.login');
            expect($mappings)->toHaveKey('auth.register');
            expect($mappings)->toHaveKey('subscription.created');
            expect($mappings)->toHaveKey('trial.started');
            expect($mappings)->toHaveKey('feature.used');
            expect($mappings)->toHaveKey('order.completed');
            expect($mappings)->toHaveKey('form.submitted');
            expect($mappings)->toHaveKey('search.performed');
            expect($mappings)->toHaveKey('error.occurred');
        });

        it('LifecycleEventSubscriber exists and is final', function () {
            expect(class_exists(LifecycleEventSubscriber::class))->toBeTrue();
            $ref = new \ReflectionClass(LifecycleEventSubscriber::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // ── Criterion 3: Inertia Middleware ──────────────────────────────────

    describe('Criterion 3: Inertia Middleware', function () {
        it('HandleInertiaAnalytics exists with strict types', function () {
            expect(class_exists(HandleInertiaAnalytics::class))->toBeTrue();
            $ref = new \ReflectionClass(HandleInertiaAnalytics::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('HandleInertiaAnalytics has handle method', function () {
            $ref = new \ReflectionClass(HandleInertiaAnalytics::class);
            expect($ref->hasMethod('handle'))->toBeTrue();
            $method = $ref->getMethod('handle');
            expect($method->isPublic())->toBeTrue();
        });

        it('HandleInertiaAnalytics has client ID cookie management', function () {
            $ref = new \ReflectionClass(HandleInertiaAnalytics::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'client_id'))->toBeTrue();
            expect(str_contains($content, 'cookie'))->toBeTrue();
        });
    });

    // ── Criterion 4: API Controller + Routes ────────────────────────────

    describe('Criterion 4: API Controller + Routes', function () {
        it('AnalyticsEventController exists with strict types', function () {
            expect(class_exists(AnalyticsEventController::class))->toBeTrue();
            $ref = new \ReflectionClass(AnalyticsEventController::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });

        it('AnalyticsEventController has track, batch, identify, consent, health endpoints', function () {
            $ref = new \ReflectionClass(AnalyticsEventController::class);
            $required = ['track', 'batch', 'identify', 'consent', 'health'];
            foreach ($required as $method) {
                expect($ref->hasMethod($method))
                    ->toBeTrue("AnalyticsEventController should have {$method}()");
            }
        });

        it('Routes file exists with analytics endpoints', function () {
            $routesPath = dirname(__DIR__, 2) . '/routes/analytics.php';
            expect(file_exists($routesPath))->toBeTrue();
            $content = file_get_contents($routesPath);
            expect(str_contains($content, 'api/analytics/events'))->toBeTrue();
            expect(str_contains($content, 'api/analytics/batch'))->toBeTrue();
            expect(str_contains($content, 'api/analytics/identify'))->toBeTrue();
            expect(str_contains($content, 'api/analytics/consent'))->toBeTrue();
        });
    });

    // ── Criterion 5: JS Client Library ──────────────────────────────────

    describe('Criterion 5: JS Client Library', function () {
        it('analytics.js exists and has 5000+ lines', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            expect(file_exists($jsPath))->toBeTrue();
            $lines = count(file($jsPath));
            expect($lines)->toBeGreaterThanOrEqual(5000);
        });

        it('analytics.js exports core functions', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $exports = ['init', 'trackEvent', 'trackPageView', 'initInertiaPageViewTracker', 'identify', 'getClientId', 'setClientId'];
            foreach ($exports as $export) {
                expect(str_contains($content, "export function {$export}"))
                    ->toBeTrue("analytics.js should export {$export}()");
            }
        });

        it('analytics.d.ts TypeScript definitions exist', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            expect(file_exists($dtsPath))->toBeTrue();
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'trackEvent'))->toBeTrue();
            expect(str_contains($content, 'trackPageView'))->toBeTrue();
        });

        it('JS client has scroll depth tracking', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            expect(str_contains(file_get_contents($jsPath), 'initScrollDepthTracker'))->toBeTrue();
        });

        it('JS client has SaaS shorthand functions', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $shorthands = ['trackSignUp', 'trackTrialStart', 'trackSubscription', 'trackPlanUpgrade', 'trackCancellation'];
            foreach ($shorthands as $fn) {
                expect(str_contains($content, "export async function {$fn}"))
                    ->toBeTrue("analytics.js should export {$fn}()");
            }
        });
    });

    // ── Criterion 6: Event Queue ───────────────────────────────────────

    describe('Criterion 6: Event Queue', function () {
        it('QueuedAnalyticsDispatcher exists with strict types', function () {
            expect(class_exists(QueuedAnalyticsDispatcher::class))->toBeTrue();
            $ref = new \ReflectionClass(QueuedAnalyticsDispatcher::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('QueuedAnalyticsDispatcher has dispatch and dispatchBatch methods', function () {
            $ref = new \ReflectionClass(QueuedAnalyticsDispatcher::class);
            expect($ref->hasMethod('dispatch'))->toBeTrue();
            expect($ref->hasMethod('dispatchBatch'))->toBeTrue();
        });
    });

    // ── Criterion 7: User Identity Linking ──────────────────────────────

    describe('Criterion 7: User Identity Linking', function () {
        it('UserIdentityTracker exists with strict types', function () {
            expect(class_exists(UserIdentityTracker::class))->toBeTrue();
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('UserIdentityTracker has client ID ↔ user ID linking', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'linkClientIdToUser'))->toBeTrue();
            expect(str_contains($content, 'resolveIdentity'))->toBeTrue();
        });

        it('AnalyticsManager has identify method', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
            expect($ref->hasMethod('identify'))->toBeTrue();
            $method = $ref->getMethod('identify');
            $params = $method->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(2);
        });
    });

    // ── Criterion 8: E-commerce Helpers ────────────────────────────────

    describe('Criterion 8: E-commerce Helpers', function () {
        it('EcommerceFormatConverter exists and is final', function () {
            expect(class_exists(EcommerceFormatConverter::class))->toBeTrue();
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('EcommerceFormatConverter has bidirectional GA4 ↔ Meta conversion', function () {
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->hasMethod('ga4ToMetaContents'))->toBeTrue();
            expect($ref->hasMethod('metaToGa4Items'))->toBeTrue();
            expect($ref->hasMethod('ga4ToMetaPurchase'))->toBeTrue();
            expect($ref->hasMethod('metaToGa4Purchase'))->toBeTrue();
        });

        it('EcommerceFormatConverter has GA4 ↔ PostHog conversion', function () {
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->hasMethod('ga4ToPosthogPurchase'))->toBeTrue();
            expect($ref->hasMethod('ga4ToPosthogRefund'))->toBeTrue();
        });

        it('EcommerceFormatConverter has convenience builders', function () {
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->hasMethod('buildGa4Purchase'))->toBeTrue();
            expect($ref->hasMethod('buildGa4Refund'))->toBeTrue();
            expect($ref->hasMethod('buildGa4AddToCart'))->toBeTrue();
            expect($ref->hasMethod('buildGa4ViewItem'))->toBeTrue();
            expect($ref->hasMethod('buildMetaPurchase'))->toBeTrue();
            expect($ref->hasMethod('buildMetaAddToCart'))->toBeTrue();
            expect($ref->hasMethod('buildPurchaseEvent'))->toBeTrue();
        });
    });

    // ── Criterion 9: Admin Commands ───────────────────────────────────

    describe('Criterion 9: Admin Commands', function () {
        it('AnalyticsOverviewCommand exists and is final', function () {
            expect(class_exists(AnalyticsOverviewCommand::class))->toBeTrue();
            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('AnalyticsTestCommand exists and is final', function () {
            expect(class_exists(AnalyticsTestCommand::class))->toBeTrue();
            $ref = new \ReflectionClass(AnalyticsTestCommand::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('AnalyticsTestCommand tests all 10 providers', function () {
            $ref = new \ReflectionClass(AnalyticsTestCommand::class);
            $content = file_get_contents($ref->getFileName());
            $providers = ['GA4', 'GTM', 'Meta Pixel', 'Plausible', 'PostHog', 'Mixpanel', 'Amplitude', 'Webhook', 'TikTok', 'LinkedIn'];
            foreach ($providers as $p) {
                expect(str_contains($content, $p))->toBeTrue("AnalyticsTestCommand should test {$p}");
            }
        });

        it('AnalyticsTestCommand has lifecycle test option', function () {
            $ref = new \ReflectionClass(AnalyticsTestCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'testLifecycleMappings'))->toBeTrue();
        });
    });

    // ── Criterion 10: Config Expansion ────────────────────────────────

    describe('Criterion 10: Config Expansion', function () {
        it('Config file has required sections', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            expect(file_exists($configPath))->toBeTrue();
            $content = file_get_contents($configPath);

            $sections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'lifecycle',
                'api', 'identity', 'ecommerce', 'auto_track', 'plausible',
                'posthog', 'mixpanel', 'amplitude',
            ];
            foreach ($sections as $section) {
                expect(str_contains($content, "'{$section}'"))
                    ->toBeTrue("Config should have '{$section}' section");
            }
        });

        it('Config has queue settings', function () {
            $content = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
            expect(str_contains($content, 'max_batch_size'))->toBeTrue();
        });

        it('Config has API settings', function () {
            $content = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
            expect(str_contains($content, 'sdk_token'))->toBeTrue();
            expect(str_contains($content, 'rate_limit'))->toBeTrue();
        });

        it('Config has identity settings', function () {
            $content = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
            expect(str_contains($content, 'auto_link'))->toBeTrue();
        });
    });

    // ── Criterion 11: Optional Providers ──────────────────────────────

    describe('Criterion 11: All 10 Provider Trackers', function () {
        it('all 10 tracker classes exist with strict types', function () {
            $trackers = [
                GA4Tracker::class,
                GTMTracker::class,
                MetaPixelTracker::class,
                PlausibleTracker::class,
                PosthogTracker::class,
                MixpanelTracker::class,
                AmplitudeTracker::class,
                WebhookTracker::class,
                TikTokTracker::class,
                LinkedInTracker::class,
            ];

            foreach ($trackers as $tracker) {
                expect(class_exists($tracker))->toBeTrue("{$tracker} should exist");
                $ref = new \ReflectionClass($tracker);
                $content = file_get_contents($ref->getFileName());
                expect(str_contains($content, 'declare(strict_types=1)'))
                    ->toBeTrue("{$tracker} should have strict types");
            }
        });

        it('all trackers implement TrackerInterface', function () {
            $interface = \ZeroBoiler\Analytics\Trackers\TrackerInterface::class;
            expect(interface_exists($interface))->toBeTrue();

            $trackers = [
                GA4Tracker::class, GTMTracker::class, MetaPixelTracker::class,
                PlausibleTracker::class, PosthogTracker::class, MixpanelTracker::class,
                AmplitudeTracker::class, WebhookTracker::class, TikTokTracker::class,
                LinkedInTracker::class,
            ];

            foreach ($trackers as $tracker) {
                $ref = new \ReflectionClass($tracker);
                expect($ref->implementsInterface($interface))
                    ->toBeTrue("{$tracker} should implement TrackerInterface");
            }
        });

        it('AnalyticsManager has 10 provider accessors', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
            $accessors = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
            foreach ($accessors as $accessor) {
                expect($ref->hasMethod($accessor))
                    ->toBeTrue("AnalyticsManager should have {$accessor}()");
            }
        });
    });

    // ── Criterion 12: Tests + README ──────────────────────────────────

    describe('Criterion 12: Tests + README', function () {
        it('300+ test files exist', function () {
            $testFiles = glob(dirname(__DIR__) . '/*Test.php');
            expect(count($testFiles))->toBeGreaterThanOrEqual(300);
        });

        it('README.md exists with 8000+ lines', function () {
            $readmePath = dirname(__DIR__, 2) . '/README.md';
            expect(file_exists($readmePath))->toBeTrue();
            $lines = count(file($readmePath));
            expect($lines)->toBeGreaterThanOrEqual(8000);
        });

        it('README documents all major sections', function () {
            $readmePath = dirname(__DIR__, 2) . '/README.md';
            $content = file_get_contents($readmePath);

            $sections = [
                'Quick Start', 'Features', 'Configuration', 'Event Catalog Reference',
                'Inertia.js Integration', 'JS Client API Reference', 'Admin Commands',
                'Testing', 'Troubleshooting',
            ];
            foreach ($sections as $section) {
                expect(str_contains($content, $section))
                    ->toBeTrue("README should document '{$section}' section");
            }
        });

        it('CHANGELOG.md documents v110.0.0', function () {
            $changelogPath = dirname(__DIR__, 2) . '/CHANGELOG.md';
            expect(file_exists($changelogPath))->toBeTrue();
            $content = file_get_contents($changelogPath);
            expect(str_contains($content, '[110.0.0]'))->toBeTrue();
            expect(str_contains($content, 'Phase 38'))->toBeTrue();
        });

        it('Phase 35 audit test version is 110.0.0', function () {
            $testPath = dirname(__DIR__) . '/Phase35SaaSStarterAuditTest.php';
            expect(file_exists($testPath))->toBeTrue();
            $content = file_get_contents($testPath);
            expect(str_contains($content, "'110.0.0'"))->toBeTrue();
        });

        it('Phase 36 audit test version is 110.0.0', function () {
            $testPath = dirname(__DIR__) . '/Phase36PrivacyInventoryAuditTest.php';
            expect(file_exists($testPath))->toBeTrue();
            $content = file_get_contents($testPath);
            expect(str_contains($content, "'110.0.0'"))->toBeTrue();
        });
    });

    // ── Production Readiness ────────────────────────────────────────────

    describe('Production Readiness', function () {
        it('production codebase has 55000+ LOC', function () {
            $srcDir = dirname(__DIR__, 2) . '/src';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            );
            $totalLines = 0;
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $totalLines += count(file($file->getPathname()));
                }
            }
            expect($totalLines)->toBeGreaterThanOrEqual(55000);
        });

        it('AnalyticsFacade exists', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Facades\Analytics::class))->toBeTrue();
        });

        it('AnalyticsServiceProvider exists with version 110.0.0', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
            expect(str_contains($content, '@version 110.0.0'))->toBeTrue();
        });

        it('routes file exists with analytics routes', function () {
            $routesPath = dirname(__DIR__, 2) . '/routes/analytics.php';
            expect(file_exists($routesPath))->toBeTrue();
            $content = file_get_contents($routesPath);
            expect(str_contains($content, 'analytics/events'))->toBeTrue();
            expect(str_contains($content, 'analytics/batch'))->toBeTrue();
        });
    });
});
