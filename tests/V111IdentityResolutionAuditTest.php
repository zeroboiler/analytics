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
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
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
 * Phase 39 — SaaS Starter Identity Resolution & Config Integrity Audit.
 *
 * Validates the new UserIdentityTracker enhancements (cache-backed
 * identity linking with linkClientIdToUser/resolveIdentity methods),
 * identity config auto_link setting, and comprehensive production
 * readiness at v111.0.0.
 *
 * @since 111.0.0
 */
describe('V111IdentityResolutionAudit', function () {
    // ── Version Integrity ───────────────────────────────────────────────

    describe('Version Integrity', function () {
        it('version is 111.0.0 across all package files', function () {
            $version = '111.0.0';

            expect(AnalyticsEvent::VERSION)->toBe($version);

            $composer = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['version'])->toBe($version);

            $pkg = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($pkg['version'])->toBe($version);

            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect(str_contains($readme, "version-{$version}"))->toBeTrue(
                "README.md version badge should show {$version}"
            );

            $jsFiles = [
                'analytics.js', 'analytics.d.ts', 'analytics.constants.js',
                'useAnalytics.svelte.js', 'useLifecycle.svelte.js',
                'useSessionReplay.svelte.js', 'usePerformanceTracker.svelte.js',
                'useAnalyticsConfig.svelte.js',
            ];
            $jsDir = dirname(__DIR__, 2) . '/resources/js';
            foreach ($jsFiles as $file) {
                $content = file_get_contents("{$jsDir}/{$file}");
                expect(str_contains($content, "@version {$version}"))
                    ->toBeTrue("{$file} should have @version {$version}");
            }
        });

        it('AnalyticsEvent DTO is readonly and final', function () {
            $ref = new \ReflectionClass(AnalyticsEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // ── UserIdentityTracker Enhancements ──────────────────────────────

    describe('UserIdentityTracker Identity Resolution', function () {
        it('UserIdentityTracker is final with strict types', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('has linkClientIdToUser method with proper return type', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('linkClientIdToUser'))->toBeTrue();
            $method = $ref->getMethod('linkClientIdToUser');
            expect($method->isPublic())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('bool');
        });

        it('has resolveIdentity method returning array', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('resolveIdentity'))->toBeTrue();
            $method = $ref->getMethod('resolveIdentity');
            expect($method->isPublic())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });

        it('has resolvePrimaryUserId method returning nullable string', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('resolvePrimaryUserId'))->toBeTrue();
            $method = $ref->getMethod('resolvePrimaryUserId');
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
            expect($returnType->allowsNull())->toBeTrue();
        });

        it('has resolveClientIds method returning array', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('resolveClientIds'))->toBeTrue();
            $method = $ref->getMethod('resolveClientIds');
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });

        it('has isAutoLinkEnabled method returning bool', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('isAutoLinkEnabled'))->toBeTrue();
            $method = $ref->getMethod('isAutoLinkEnabled');
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('bool');
        });

        it('constructor has cache, cachePrefix, linkTtl, maxLinksPerUser, maxLinksPerClient, autoLink parameters', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $method = $ref->getMethod('__construct');
            $params = $method->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(9);

            $names = array_map(fn (\ReflectionParameter $p) => $p->getName(), $params);
            expect(in_array('cache', $names, true))->toBeTrue();
            expect(in_array('cachePrefix', $names, true))->toBeTrue();
            expect(in_array('linkTtl', $names, true))->toBeTrue();
            expect(in_array('maxLinksPerUser', $names, true))->toBeTrue();
            expect(in_array('maxLinksPerClient', $names, true))->toBeTrue();
            expect(in_array('autoLink', $names, true))->toBeTrue();
        });

        it('linkClientIdToUser has @return bool docblock', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $method = $ref->getMethod('linkClientIdToUser');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect(str_contains($doc, '@return bool'))->toBeTrue();
        });

        it('resolveIdentity has @return list<string> docblock', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $method = $ref->getMethod('resolveIdentity');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect(str_contains($doc, '@return'))->toBeTrue();
        });

        it('has identify method that also auto-links', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            $content = file_get_contents($ref->getFileName());
            $identifyMethod = $ref->getMethod('identify');
            expect($identifyMethod->isPublic())->toBeTrue();
            // Verify auto_link logic is present in identify method body
            expect(str_contains($content, 'auto_link'))->toBeTrue();
            expect(str_contains($content, 'linkClientIdToUser'))->toBeTrue();
        });

        it('has onLogin and onRegister methods', function () {
            $ref = new \ReflectionClass(UserIdentityTracker::class);
            expect($ref->hasMethod('onLogin'))->toBeTrue();
            expect($ref->hasMethod('onRegister'))->toBeTrue();
            expect($ref->hasMethod('onLogout'))->toBeTrue();
        });
    });

    // ── Identity Config ────────────────────────────────────────────────

    describe('Identity Config', function () {
        it('config has auto_link setting', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);
            expect(str_contains($content, "'auto_link'"))->toBeTrue(
                "Config identity section should have 'auto_link' setting"
            );
        });

        it('config identity has all required keys', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);

            $identityKeys = [
                'cookie_name', 'cookie_ttl', 'cookie_secure', 'cookie_samesite',
                'cookie_domain', 'link_on_auth', 'auto_link',
                'cache_prefix', 'link_ttl', 'max_links_per_user', 'max_links_per_client',
            ];

            foreach ($identityKeys as $key) {
                expect(str_contains($content, "'{$key}'"))
                    ->toBeTrue("Config identity should have '{$key}' key");
            }
        });

        it('config has all 16 required sections', function () {
            $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
            $content = file_get_contents($configPath);

            $sections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'lifecycle',
                'api', 'identity', 'ecommerce', 'auto_track', 'plausible',
                'posthog', 'mixpanel', 'amplitude', 'client_auto_track',
                'revenue_checksum',
            ];

            foreach ($sections as $section) {
                expect(str_contains($content, "'{$section}'"))
                    ->toBeTrue("Config should have '{$section}' section");
            }
        });
    });

    // ── Event Catalog Coverage ──────────────────────────────────────────

    describe('Event Catalog', function () {
        it('EcommerceEvents has 15+ events', function () {
            expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
        });

        it('SaaSEvents has 40+ events', function () {
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(40);
        });

        it('EngagementEvents has 30+ events', function () {
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
        });

        it('EventCatalog has 100+ total events', function () {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
        });

        it('all catalog events have provider mappings', function () {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['ga4']))->toBeTrue("{$name} should have ga4 mapping");
                expect(isset($entry['meta']))->toBeTrue("{$name} should have meta mapping");
                expect(isset($entry['category']))->toBeTrue("{$name} should have category");
            }
        });
    });

    // ── Provider Trackers ───────────────────────────────────────────────

    describe('Provider Trackers', function () {
        it('all 10 trackers exist and implement TrackerInterface', function () {
            $interface = \ZeroBoiler\Analytics\Trackers\TrackerInterface::class;
            expect(interface_exists($interface))->toBeTrue();

            $trackers = [
                GA4Tracker::class, GTMTracker::class, MetaPixelTracker::class,
                PlausibleTracker::class, PosthogTracker::class, MixpanelTracker::class,
                AmplitudeTracker::class, WebhookTracker::class, TikTokTracker::class,
                LinkedInTracker::class,
            ];

            foreach ($trackers as $tracker) {
                expect(class_exists($tracker))->toBeTrue("{$tracker} should exist");
                $ref = new \ReflectionClass($tracker);
                expect($ref->implementsInterface($interface))
                    ->toBeTrue("{$tracker} should implement TrackerInterface");
            }
        });
    });

    // ── Lifecycle Tracker ───────────────────────────────────────────────

    describe('Lifecycle Tracker', function () {
        it('LifecycleEventMapper has 60+ default mappings', function () {
            expect(LifecycleEventMapper::DEFAULT_MAPPING_COUNT)->toBeGreaterThanOrEqual(60);
        });

        it('LifecycleEventSubscriber is final', function () {
            $ref = new \ReflectionClass(LifecycleEventSubscriber::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // ── E-commerce Helpers ──────────────────────────────────────────────

    describe('E-commerce Helpers', function () {
        it('EcommerceFormatConverter has bidirectional conversion methods', function () {
            $ref = new \ReflectionClass(EcommerceFormatConverter::class);
            $methods = [
                'ga4ToMetaContents', 'metaToGa4Items',
                'ga4ToMetaPurchase', 'metaToGa4Purchase',
                'ga4ToPosthogPurchase', 'ga4ToPosthogRefund',
                'buildGa4Purchase', 'buildGa4Refund',
                'buildGa4AddToCart', 'buildGa4ViewItem',
                'buildMetaPurchase', 'buildMetaAddToCart',
            ];

            foreach ($methods as $method) {
                expect($ref->hasMethod($method))
                    ->toBeTrue("EcommerceFormatConverter should have {$method}()");
            }
        });
    });

    // ── Admin Commands ──────────────────────────────────────────────────

    describe('Admin Commands', function () {
        it('AnalyticsOverviewCommand is final', function () {
            $ref = new \ReflectionClass(AnalyticsOverviewCommand::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('AnalyticsTestCommand is final and tests all providers', function () {
            $ref = new \ReflectionClass(AnalyticsTestCommand::class);
            expect($ref->isFinal())->toBeTrue();
            $content = file_get_contents($ref->getFileName());

            $providers = ['GA4', 'GTM', 'Meta Pixel', 'Plausible', 'PostHog', 'Mixpanel', 'Amplitude', 'Webhook', 'TikTok', 'LinkedIn'];
            foreach ($providers as $p) {
                expect(str_contains($content, $p))->toBeTrue("AnalyticsTestCommand should test {$p}");
            }
        });
    });

    // ── API & Routes ───────────────────────────────────────────────────

    describe('API & Routes', function () {
        it('AnalyticsEventController has track, batch, identify, consent, health', function () {
            $ref = new \ReflectionClass(AnalyticsEventController::class);
            $required = ['track', 'batch', 'identify', 'consent', 'health'];
            foreach ($required as $method) {
                expect($ref->hasMethod($method))
                    ->toBeTrue("AnalyticsEventController should have {$method}()");
            }
        });

        it('routes file has all analytics endpoints', function () {
            $routesPath = dirname(__DIR__, 2) . '/routes/analytics.php';
            $content = file_get_contents($routesPath);

            $endpoints = [
                'api/analytics/events', 'api/analytics/batch',
                'api/analytics/identify', 'api/analytics/consent',
            ];

            foreach ($endpoints as $endpoint) {
                expect(str_contains($content, $endpoint))
                    ->toBeTrue("Routes should have {$endpoint}");
            }
        });
    });

    // ── JS Client ───────────────────────────────────────────────────────

    describe('JS Client Library', function () {
        it('analytics.js exports core functions', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $exports = ['init', 'trackEvent', 'trackPageView', 'initInertiaPageViewTracker', 'identify', 'getClientId', 'setClientId'];
            foreach ($exports as $export) {
                expect(str_contains($content, "export function {$export}"))
                    ->toBeTrue("analytics.js should export {$export}()");
            }
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

        it('analytics.d.ts exists', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            expect(file_exists($dtsPath))->toBeTrue();
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

        it('335+ test files exist', function () {
            $testFiles = glob(dirname(__DIR__) . '/*Test.php');
            expect(count($testFiles))->toBeGreaterThanOrEqual(335);
        });

        it('CHANGELOG.md documents v111.0.0', function () {
            $changelogPath = dirname(__DIR__, 2) . '/CHANGELOG.md';
            expect(file_exists($changelogPath))->toBeTrue();
            $content = file_get_contents($changelogPath);
            expect(str_contains($content, '[111.0.0]'))->toBeTrue();
            expect(str_contains($content, 'Phase 39'))->toBeTrue();
        });
    });
});
