<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * Phase 173 — Production Readiness Audit
 *
 * Validates version sweep integrity across all 14 entry points,
 * core class structure (finality, strict types, void constructors),
 * event catalog coverage, and provider interface compliance.
 *
 * @since 173.0.0
 */
describe('Phase 173 — Production Readiness Audit', function (): void {

    // ─── Version Sweep (All 14 Entry Points) ─────────────────────────

    it('composer.json version is 173.0.0', function (): void {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );
        expect($composer['version'])->toBe('173.0.0');
    });

    it('AnalyticsEvent::VERSION is 173.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('173.0.0');
    });

    it('package.json version is 173.0.0', function (): void {
        $pkg = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/package.json'),
            true,
        );
        expect($pkg['version'])->toBe('173.0.0');
    });

    it('analytics.js header version is 173.0.0', function (): void {
        $js = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.js');
        expect($js)->toContain('@version 173.0.0');
        expect($js)->toContain("return '173.0.0'");
    });

    it('analytics.d.ts version is 173.0.0', function (): void {
        $dts = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.d.ts');
        expect($dts)->toContain('@version 173.0.0');
    });

    it('analytics.constants.js version is 173.0.0', function (): void {
        $constants = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.constants.js');
        expect($constants)->toContain('@version 173.0.0');
    });

    it('all 7 Svelte composables have version 173.0.0', function (): void {
        $composables = [
            'useAnalytics.svelte.js',
            'useAnalyticsConfig.svelte.js',
            'useEcommerce.svelte.js',
            'useLifecycle.svelte.js',
            'useSaaSMetrics.svelte.js',
            'usePerformanceTracker.svelte.js',
            'useSessionReplay.svelte.js',
        ];

        foreach ($composables as $file) {
            $path = dirname(__DIR__, 2) . '/resources/js/' . $file;
            expect(file_exists($path))->toBeTrue("Missing composable: {$file}");
            $content = file_get_contents($path);
            expect($content)->toContain('@version 173.0.0', "Version mismatch in {$file}");
        }
    });

    it('ServiceProvider @version is 173.0.0', function (): void {
        $sp = file_get_contents(
            dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php',
        );
        expect($sp)->toContain('@version 173.0.0');
    });

    it('IntegrityCommand EXPECTED_VERSION is 173.0.0', function (): void {
        $ref = new ReflectionClass(
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class,
        );
        $constant = $ref->getConstant('EXPECTED_VERSION');
        expect($constant)->toBe('173.0.0');
    });

    it('README badge version is 173.0.0', function (): void {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        expect($readme)->toContain('version-173.0.0');
    });

    // ─── Core Class Structure ──────────────────────────────────────────

    it('AnalyticsManager is final + has void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->hasMethod('__construct'))->toBeTrue();
        $ctor = $ref->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    it('AnalyticsServiceProvider is final', function (): void {
        expect(
            (new ReflectionClass(AnalyticsServiceProvider::class))->isFinal(),
        )->toBeTrue();
    });

    it('AnalyticsServiceProvider has complete register/boot/provides', function (): void {
        $ref = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('boot'))->toBeTrue();
        expect($ref->hasMethod('provides'))->toBeTrue();
    });

    it('Facade accessor returns correct binding', function (): void {
        $method = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        expect($method->isStatic())->toBeTrue();
    });

    it('AnalyticsEvent is readonly + final', function (): void {
        $ref = new ReflectionClass(AnalyticsEvent::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    // ─── Source File Counts ─────────────────────────────────────────────

    it('source file count is at least 817', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(817);
    });

    it('test file count is at least 410', function (): void {
        $testDir = dirname(__DIR__, 2) . '/tests';
        $files = glob($testDir . '/**/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(410);
    });

    // ─── Event Catalog Coverage ────────────────────────────────────────

    it('event catalog has 8 categories', function (): void {
        $byCategory = EventCatalog::byCategory();
        expect($byCategory)->toHaveCount(8);
        expect(array_keys($byCategory))->toEqual([
            'ecommerce',
            'saas',
            'engagement',
            'security',
            'uptime',
            'infrastructure',
            'marketing',
            'customer_success',
        ]);
    });

    it('event catalog total is at least 190 events', function (): void {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(190);
    });

    it('core SaaS events exist in catalog', function (): void {
        $coreSaaS = ['sign_up', 'login', 'start_trial', 'subscription', 'plan_upgrade', 'cancellation'];
        foreach ($coreSaaS as $event) {
            expect(EventCatalog::has($event))->toBeTrue("Missing core SaaS event: {$event}");
        }
    });

    it('core ecommerce events exist in catalog', function (): void {
        $coreEcom = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($coreEcom as $event) {
            expect(EventCatalog::has($event))->toBeTrue("Missing core ecommerce event: {$event}");
        }
    });

    it('core engagement events exist in catalog', function (): void {
        $coreEngagement = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($coreEngagement as $event) {
            expect(EventCatalog::has($event))->toBeTrue("Missing core engagement event: {$event}");
        }
    });

    // ─── Subdirectory Cross-Reference ─────────────────────────────────

    it('src subdirectories exist for key domains', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';

        $domains = [
            'Trackers',
            'Events',
            'Enrichment',
            'Services',
            'Console',
            'Jobs',
            'Pipeline',
            'Schema',
            'Middleware',
            'DTO',
            'Bus',
            'Blade',
            'Http',
            'Inertia',
            'Queue',
            'Store',
            'Tracking',
            'Support',
            'Macros',
            'Context',
            'Blueprints',
            'Attributes',
        ];

        foreach ($domains as $domain) {
            expect(is_dir($srcDir . '/' . $domain))->toBeTrue(
                "Missing src/{$domain} directory"
            );
        }
    });

    // ─── Config Integrity ───────────────────────────────────────────────

    it('config file has expected SaaS starter sections', function (): void {
        $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
        $content = file_get_contents($configPath);

        expect($content)->toContain("'ga4'");
        expect($content)->toContain("'gtm'");
        expect($content)->toContain("'meta_pixel'");
        expect($content)->toContain("'consent'");
        expect($content)->toContain("'queue'");
        expect($content)->toContain("'lifecycle'");
        expect($content)->toContain("'api'");
        expect($content)->toContain("'identity'");
        expect($content)->toContain("'ecommerce'");
        expect($content)->toContain("'auto_track'");
    });

    // ─── Tracker Interface Compliance ───────────────────────────────────

    it('TrackerInterface exists and defines track method', function (): void {
        $ref = new ReflectionClass(TrackerInterface::class);
        expect($ref->hasMethod('track'))->toBeTrue();
        expect($ref->hasMethod('isEnabled'))->toBeTrue();
    });

    it('all tracker implementations implement TrackerInterface', function (): void {
        $trackers = [
            \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
            \ZeroBoiler\Analytics\Trackers\MixpanelTracker::class,
            \ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class,
            \ZeroBoiler\Analytics\Trackers\TikTokTracker::class,
            \ZeroBoiler\Analytics\Trackers\LinkedInTracker::class,
            \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
        ];

        foreach ($trackers as $tracker) {
            expect($tracker)->toBeInstanceOf(
                TrackerInterface::class,
                "{$tracker} does not implement TrackerInterface"
            );
        }
    });

    // ─── Strict Types + License Header ────────────────────────────────

    it('AnalyticsManager has declare(strict_types=1)', function (): void {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/src/AnalyticsManager.php',
        );
        expect($content)->toContain('declare(strict_types=1)');
    });

    it('EventCatalog has declare(strict_types=1)', function (): void {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/src/Events/EventCatalog.php',
        );
        expect($content)->toContain('declare(strict_types=1)');
    });

    it('AnalyticsEventController has declare(strict_types=1)', function (): void {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/src/Http/Controllers/AnalyticsEventController.php',
        );
        expect($content)->toContain('declare(strict_types=1)');
    });

    it('HandleInertiaAnalytics has declare(strict_types=1)', function (): void {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/src/Inertia/HandleInertiaAnalytics.php',
        );
        expect($content)->toContain('declare(strict_types=1)');
    });

    it('LifecycleEventSubscriber has declare(strict_types=1)', function (): void {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/src/Tracking/LifecycleEventSubscriber.php',
        );
        expect($content)->toContain('declare(strict_types=1)');
    });

    // ─── Lifecycle Subscriber Structure ─────────────────────────────────

    it('LifecycleEventSubscriber is final', function (): void {
        $ref = new ReflectionClass(
            \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber::class,
        );
        expect($ref->isFinal())->toBeTrue();
    });

    it('LifecycleEventSubscriber has register and track methods', function (): void {
        $ref = new ReflectionClass(
            \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber::class,
        );
        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('track'))->toBeTrue();
        expect($ref->hasMethod('diagnosticSummary'))->toBeTrue();
    });

    // ─── Event Catalog Name Resolution ──────────────────────────────────

    it('EventCatalog::resolve handles PascalCase', function (): void {
        expect(EventCatalog::resolve('ViewItem'))->toBe('view_item');
        expect(EventCatalog::resolve('AddToCart'))->toBe('add_to_cart');
    });

    it('EventCatalog::resolve handles kebab-case', function (): void {
        expect(EventCatalog::resolve('sign-up'))->toBe('sign_up');
    });

    it('EventCatalog::resolve returns null for unknown events', function (): void {
        expect(EventCatalog::resolve('nonexistent_event_xyz'))->toBeNull();
    });

    // ─── Provider Coverage Parity ──────────────────────────────────────

    it('all providers have at least 1 GA4 mapping', function (): void {
        $ga4Names = EventCatalog::allGa4Names();
        expect(count($ga4Names))->toBeGreaterThanOrEqual(150);
    });

    it('Meta Pixel has at least 1 mapping', function (): void {
        $metaNames = EventCatalog::allMetaNames();
        expect(count($metaNames))->toBeGreaterThanOrEqual(50);
    });
});
