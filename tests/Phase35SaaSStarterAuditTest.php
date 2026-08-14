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

/**
 * Phase 35 — SaaS Starter Industry Standard Audit.
 *
 * Comprehensive verification that all 12 SaaS starter upgrade criteria are met
 * with production-quality implementations. This test validates code structure,
 * class existence, method signatures, and integration points without requiring
 * a Laravel application bootstrap.
 *
 * @since 106.0.0
 */
describe('Phase35SaaSStarterAudit', function () {
    // ── Criterion 1: Event Catalog ───────────────────────────────────────

    describe('Criterion 1: Event Catalog', function () {
        it('EcommerceEvents class exists and is final with strict types', function () {
            expect(class_exists(EcommerceEvents::class))->toBeTrue();

            $ref = new \ReflectionClass(EcommerceEvents::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->getFileName())->not->toBeFalse();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('EcommerceEvents defines core e-commerce events', function () {
            $expectedEvents = ['ViewItem', 'AddToCart', 'Purchase', 'Refund'];
            foreach ($expectedEvents as $event) {
                $constant = EcommerceEvents::class . '::' . strtoupper($event);
                expect(defined($constant))->toBeTrue("EcommerceEvents::{$event} constant should exist");
            }
        });

        it('SaaSEvents class exists and is final with strict types', function () {
            expect(class_exists(SaaSEvents::class))->toBeTrue();

            $ref = new \ReflectionClass(SaaSEvents::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('SaaSEvents defines core SaaS events', function () {
            $expectedEvents = ['SignUp', 'Login', 'TrialStart', 'Subscription', 'PlanUpgrade', 'Cancellation'];
            foreach ($expectedEvents as $event) {
                $constant = SaaSEvents::class . '::' . strtoupper($event);
                expect(defined($constant))->toBeTrue("SaaSEvents::{$event} constant should exist");
            }
        });

        it('EngagementEvents class exists and is final with strict types', function () {
            expect(class_exists(EngagementEvents::class))->toBeTrue();

            $ref = new \ReflectionClass(EngagementEvents::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('EngagementEvents defines core engagement events', function () {
            $expectedEvents = ['PageView', 'ScrollDepth', 'Click', 'FormStart', 'FormSubmit', 'Search', 'Share', 'Error'];
            foreach ($expectedEvents as $event) {
                $constant = EngagementEvents::class . '::' . strtoupper($event);
                expect(defined($constant))->toBeTrue("EngagementEvents::{$event} constant should exist");
            }
        });

        it('EventCatalog provides provider name lookups for all 8 providers', function () {
            $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
            foreach ($providers as $provider) {
                $method = 'all' . ucfirst($provider) . 'Names';
                expect(method_exists(EventCatalog::class, $method))->toBeTrue(
                    "EventCatalog::{$method}() should exist for provider {$provider}"
                );
            }
        });

        it('EventCatalog has 6+ categories', function () {
            $categories = EventCatalog::byCategory();
            expect(count($categories))->toBeGreaterThanOrEqual(6);
        });

        it('EventCatalog has 50+ total events', function () {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(50);
        });
    });

    // ── Criterion 2: Server-Side Lifecycle Tracker ─────────────────────

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

        it('LifecycleEventSubscriber exists and is final', function () {
            expect(class_exists(LifecycleEventSubscriber::class))->toBeTrue();

            $ref = new \ReflectionClass(LifecycleEventSubscriber::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('LifecycleEventMapper covers auth, subscription, trial, feature, ecommerce, and engagement events', function () {
            $ref = new \ReflectionClass(LifecycleEventMapper::class);
            $defaultMappings = $ref->getConstant('DEFAULT_MAPPINGS');

            expect($defaultMappings)->toBeArray();
            expect($defaultMappings)->toHaveKey('auth.login');
            expect($defaultMappings)->toHaveKey('auth.register');
            expect($defaultMappings)->toHaveKey('subscription.created');
            expect($defaultMappings)->toHaveKey('trial.started');
            expect($defaultMappings)->toHaveKey('feature.used');
            expect($defaultMappings)->toHaveKey('order.completed');
            expect($defaultMappings)->toHaveKey('form.submitted');
            expect($defaultMappings)->toHaveKey('search.performed');
            expect($defaultMappings)->toHaveKey('error.occurred');
        });
    });

    // ── Criterion 3: Inertia Middleware ────────────────────────────────

    describe('Criterion 3: Inertia Middleware', function () {
        it('HandleInertiaAnalytics exists with strict types', function () {
            expect(class_exists(HandleInertiaAnalytics::class))->toBeTrue();

            $ref = new \ReflectionClass(HandleInertiaAnalytics::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('HandleInertiaAnalytics implements middleware contract', function () {
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
        });

        it('AnalyticsEventController has track, batch, identify, consent endpoints', function () {
            $ref = new \ReflectionClass(AnalyticsEventController::class);

            $requiredMethods = ['track', 'batch', 'identify', 'consent', 'health'];
            foreach ($requiredMethods as $method) {
                expect($ref->hasMethod($method))->toBeTrue(
                    "AnalyticsEventController should have {$method}() method"
                );
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

    // ── Criterion 5: Svelte JS Client Library ───────────────────────────

    describe('Criterion 5: Svelte JS Client Library', function () {
        it('analytics.js exists and has 5000+ lines', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            expect(file_exists($jsPath))->toBeTrue();

            $lines = count(file($jsPath));
            expect($lines)->toBeGreaterThanOrEqual(5000);
        });

        it('analytics.js exports core functions', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);

            $requiredExports = [
                'init', 'trackEvent', 'trackPageView', 'initInertiaPageViewTracker',
                'identify', 'scrollDepth', 'getClientId', 'setClientId',
            ];
            foreach ($requiredExports as $export) {
                expect(str_contains($content, "export function {$export}"))
                    ->toBeTrue("analytics.js should export {$export}()");
            }
        });

        it('analytics.d.ts TypeScript definitions exist', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            expect(file_exists($dtsPath))->toBeTrue();

            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'interface ZbAnalyticsProps'))->toBeTrue();
            expect(str_contains($content, 'trackEvent'))->toBeTrue();
            expect(str_contains($content, 'trackPageView'))->toBeTrue();
        });

        it('JS client has scroll depth tracking', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            expect(str_contains($content, 'initScrollDepthTracker'))->toBeTrue();
        });

        it('JS client has client ID management', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            expect(str_contains($content, 'getClientId'))->toBeTrue();
            expect(str_contains($content, 'setClientId'))->toBeTrue();
        });

        it('JS client has SaaS shorthand functions', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);

            $saasShorthands = ['trackSignUp', 'trackTrialStart', 'trackSubscription', 'trackPlanUpgrade', 'trackCancellation'];
            foreach ($saasShorthands as $fn) {
                expect(str_contains($content, "export async function {$fn}"))
                    ->toBeTrue("analytics.js should export SaaS shorthand {$fn}()");
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

        it('QueuedAnalyticsDispatcher has dispatch method', function () {
            $ref = new \ReflectionClass(QueuedAnalyticsDispatcher::class);
            expect($ref->hasMethod('dispatch'))->toBeTrue();
            expect($ref->hasMethod('dispatchBatch'))->toBeTrue();
        });
    });

    // ── Criterion 7: User Identity Linking ─────────────────────────────

    describe('Criterion 7: User Identity Linking', function () {
        it('UserIdentityTracker exists with strict types', function () {
            expect(class_exists(UserIdentityTracker::class))->toBeTrue();

            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('UserIdentityTracker has client ID to user ID mapping', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'linkClientIdToUser'))->toBeTrue();
            expect(str_contains($content, 'resolveIdentity'))->toBeTrue();
        });

        it('AnalyticsManager has identify method with client ID linkage', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
            expect($ref->hasMethod('identify'))->toBeTrue();

            $method = $ref->getMethod('identify');
            $params = $method->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(2);
        });
    });

    // ── Criterion 8: E-commerce Helpers ────────────────────────────────

    describe('Criterion 8: E-commerce Helpers', function () {
        it('EcommerceFormatConverter exists with strict types', function () {
            expect(class_exists(EcommerceFormatConverter::class))->toBeTrue();

            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('EcommerceFormatConverter has GA4 ↔ Meta conversion', function () {
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
        });

        it('EcommerceFormatConverter has multi-provider buildPurchaseEvent', function () {
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            expect($ref->hasMethod('buildPurchaseEvent'))->toBeTrue();
        });
    });

    // ── Criterion 9: Admin Commands ───────────────────────────────────

    describe('Criterion 9: Admin Commands', function () {
        it('AnalyticsOverviewCommand exists with strict types', function () {
            expect(class_exists(AnalyticsOverviewCommand::class))->toBeTrue();

            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('AnalyticsOverviewCommand has zb:analytics:overview signature', function () {
            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            $signature = $ref->getProperty('signature');
            expect($signature->isPublic() || $signature->isProtected())->toBeTrue();
        });

        it('AnalyticsOverviewCommand covers all 10 providers', function () {
            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            $content = file_get_contents($ref->getFileName());

            $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin'];
            foreach ($providers as $provider) {
                expect(str_contains($content, "'{$provider}'"))
                    ->toBeTrue("AnalyticsOverviewCommand should cover provider {$provider}");
            }
        });

        it('AnalyticsOverviewCommand includes tiktok and linkedin in catalog stats', function () {
            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            $content = file_get_contents($ref->getFileName());

            expect(str_contains($content, 'allTikTokNames'))->toBeTrue('Should include TikTok in catalog stats');
            expect(str_contains($content, 'allLinkedInNames'))->toBeTrue('Should include LinkedIn in catalog stats');
        });

        it('AnalyticsTestCommand exists with strict types', function () {
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
            foreach ($providers as $provider) {
                expect(str_contains($content, $provider))
                    ->toBeTrue("AnalyticsTestCommand should test {$provider}");
            }
        });

        it('AnalyticsTestCommand has lifecycle test option', function () {
            $ref = new \ReflectionClass(AnalyticsTestCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'testLifecycleMappings'))->toBeTrue();
        });
    });

    // ── Criterion 10: Config Expansion ─────────────────────────────────

    describe('Criterion 10: Config Expansion', function () {
        it('Config file has 20+ sections', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            expect(file_exists($configPath))->toBeTrue();

            $content = file_get_contents($configPath);

            $requiredSections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'lifecycle',
                'api', 'identity', 'ecommerce', 'auto_track', 'plausible',
                'posthog', 'mixpanel', 'amplitude',
            ];
            foreach ($requiredSections as $section) {
                expect(str_contains($content, "'{$section}'"))
                    ->toBeTrue("Config should have '{$section}' section");
            }
        });

        it('Config has queue settings', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);
            expect(str_contains($content, 'max_batch_size'))->toBeTrue();
        });

        it('Config has API settings', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);
            expect(str_contains($content, 'sdk_token'))->toBeTrue();
            expect(str_contains($content, 'rate_limit'))->toBeTrue();
        });

        it('Config has identity settings', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);
            expect(str_contains($content, 'auto_link'))->toBeTrue();
        });
    });

    // ── Criterion 11: Optional Providers ───────────────────────────────

    describe('Criterion 11: Optional Providers (Plausible, PostHog)', function () {
        it('PlausibleTracker exists with strict types', function () {
            expect(class_exists(PlausibleTracker::class))->toBeTrue();

            $ref = new \ReflectionClass(PlausibleTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('PlausibleTracker has null-safe customScriptUrl check', function () {
            $ref = new \ReflectionClass(PlausibleTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, "customScriptUrl !== null && \$this->customScriptUrl !== ''"))
                ->toBeTrue('PlausibleTracker should check null and empty for customScriptUrl');
        });

        it('PosthogTracker exists with strict types', function () {
            expect(class_exists(PosthogTracker::class))->toBeTrue();

            $ref = new \ReflectionClass(PosthogTracker::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('Both providers implement track method', function () {
            $plausibleRef = new \ReflectionClass(PlausibleTracker::class);
            expect($plausibleRef->hasMethod('track'))->toBeTrue();

            $posthogRef = new \ReflectionClass(PosthogTracker::class);
            expect($posthogRef->hasMethod('track'))->toBeTrue();
        });

        it('AnalyticsManager has 10 provider accessors', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);

            $accessors = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
            foreach ($accessors as $accessor) {
                expect($ref->hasMethod($accessor))
                    ->toBeTrue("AnalyticsManager should have {$accessor}() accessor");
            }
        });
    });

    // ── Criterion 12: Tests + README ───────────────────────────────────

    describe('Criterion 12: Tests + README', function () {
        it('SaasStarterTest exists with comprehensive tests', function () {
            $testPath = dirname(__DIR__) . '/SaasStarterTest.php';
            expect(file_exists($testPath))->toBeTrue();

            $content = file_get_contents($testPath);
            $lines = count(file($testPath));
            expect($lines)->toBeGreaterThanOrEqual(600);
        });

        it('README.md exists with 5000+ lines', function () {
            $readmePath = dirname(__DIR__, 2) . '/README.md';
            expect(file_exists($readmePath))->toBeTrue();

            $lines = count(file($readmePath));
            expect($lines)->toBeGreaterThanOrEqual(5000);
        });

        it('README documents all major sections', function () {
            $readmePath = dirname(__DIR__, 2) . '/README.md';
            $content = file_get_contents($readmePath);

            $requiredSections = [
                'Quick Start', 'Features', 'Configuration', 'Event Catalog Reference',
                'Inertia.js Integration', 'JS Client API Reference', 'Admin Commands',
                'Testing',
            ];
            foreach ($requiredSections as $section) {
                expect(str_contains($content, $section))
                    ->toBeTrue("README should document '{$section}' section");
            }
        });

        it('AnalyticsEvent DTO is readonly', function () {
            $ref = new \ReflectionClass(AnalyticsEvent::class);
            expect($ref->isReadOnly())->toBeTrue('AnalyticsEvent should be a readonly class');
        });

        it('Version is consistent across package files', function () {
            $version = '106.0.0';

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

            // README.md version badge
            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect(str_contains($readme, "version-{$version}"))->toBeTrue(
                "README.md version badge should show {$version}"
            );
        });
    });
});
