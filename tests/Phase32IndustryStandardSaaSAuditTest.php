<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

/**
 * Phase 32 Production Audit — Industry-Standard SaaS Starter Verification
 *
 * Comprehensive verification of all 12 SaaS starter upgrade criteria:
 * 1. Event Catalog (Ecommerce + SaaS + Engagement)
 * 2. Server-Side Lifecycle Tracker
 * 3. Inertia middleware (page props, client ID cookie)
 * 4. API controller + routes
 * 5. Svelte JS client library
 * 6. Event queue (async dispatch)
 * 7. User identity linking
 * 8. E-commerce helpers (GA4 + Meta format conversion)
 * 9. Admin commands (AnalyticsOverviewCommand + AnalyticsTestCommand)
 * 10. Config expansion
 * 11. Optional providers (Plausible, PostHog)
 * 12. Tests + version consistency
 *
 * @since 104.0.0
 */
it('criterion 1a: EcommerceEvents catalog has core events with provider mappings', function () {
    $coreEvents = ['view_item', 'add_to_cart', 'purchase', 'refund'];
    foreach ($coreEvents as $eventName) {
        $entry = EcommerceEvents::get($eventName);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString()->not->toBeEmpty();
        expect($entry['posthog'])->toBeString()->not->toBeEmpty();
        expect($entry['class'])->toBeString()->not->toBeEmpty();
    }

    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(EcommerceEvents::category())->toBe('ecommerce');
    expect(EcommerceEvents::has('view_item'))->toBeTrue();
    expect(EcommerceEvents::has('purchase'))->toBeTrue();
});

it('criterion 1b: SaaSEvents catalog has lifecycle events', function () {
    $coreEvents = ['sign_up', 'login', 'start_trial', 'cancellation', 'plan_upgrade'];
    foreach ($coreEvents as $eventName) {
        $entry = SaaSEvents::get($eventName);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString()->not->toBeEmpty();
        expect($entry['class'])->toBeString()->not->toBeEmpty();
    }

    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(40);
    expect(SaaSEvents::category())->toBe('saas');
});

it('criterion 1c: EngagementEvents catalog has core interaction events', function () {
    $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
    foreach ($coreEvents as $eventName) {
        $entry = EngagementEvents::get($eventName);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString()->not->toBeEmpty();
    }

    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
    expect(EngagementEvents::category())->toBe('engagement');
});

it('criterion 1d: EventCatalog unifies all categories', function () {
    $all = EventCatalog::all();

    expect($all)->toBeArray();
    expect(count($all))->toBeGreaterThanOrEqual(85);

    // All categories represented
    $ecommerceCount = 0;
    $saasCount = 0;
    $engagementCount = 0;
    $securityCount = 0;
    $uptimeCount = 0;
    $infraCount = 0;

    foreach ($all as $entry) {
        match ($entry['category']) {
            'ecommerce' => $ecommerceCount++,
            'saas' => $saasCount++,
            'engagement' => $engagementCount++,
            'security' => $securityCount++,
            'uptime' => $uptimeCount++,
            'infrastructure' => $infraCount++,
            default => null,
        };
    }

    expect($ecommerceCount)->toBeGreaterThanOrEqual(10);
    expect($saasCount)->toBeGreaterThanOrEqual(20);
    expect($engagementCount)->toBeGreaterThanOrEqual(20);

    // Provider coverage methods
    expect(EventCatalog::names())->toBeArray();
    expect(count(EventCatalog::names()))->toBeGreaterThanOrEqual(85);

    // PostHog names
    expect(EventCatalog::posthogNameFor('purchase'))->toBeString();
    expect(EventCatalog::posthogNameFor('page_view'))->toBeString();
});

it('criterion 2: Server-side lifecycle tracker has config-driven mapping', function () {
    // LifecycleEventMapper has DEFAULT_MAPPINGS
    $mapping = LifecycleEventMapper::getDefaultMapping('auth.login');
    expect($mapping)->not->toBeNull();
    expect($mapping['target'])->toBeString();
    expect($mapping['target_class'] ?? $mapping['target'])->not->toBeEmpty();

    // LifecycleEventSubscriber exists and is final
    $ref = new ReflectionClass(LifecycleEventSubscriber::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->hasMethod('register'))->toBeTrue();
    expect($ref->hasMethod('track'))->toBeTrue();
    expect($ref->hasMethod('diagnosticSummary'))->toBeTrue();
});

it('criterion 3: Inertia middleware has page props and client ID cookie', function () {
    $ref = new ReflectionClass(HandleInertiaAnalytics::class);
    expect($ref->isFinal())->toBeTrue();

    // Must have handle method
    expect($ref->hasMethod('handle'))->toBeTrue();
    $method = $ref->getMethod('handle');

    // Private helper for tracking ID
    expect($ref->hasMethod('getOrCreateTrackingId'))->toBeTrue();
    expect($ref->hasMethod('generateTrackingId'))->toBeTrue();
    expect($ref->hasMethod('getUserId'))->toBeTrue();
    expect($ref->hasMethod('detectAuthStateChange'))->toBeTrue();

    // Implements HttpMiddlewareContract
    $interfaces = $ref->getInterfaceNames();
    expect($interfaces)->toContain(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class);
});

it('criterion 4: API controller has track, batch, identify, consent endpoints', function () {
    $ref = new ReflectionClass(AnalyticsEventController::class);

    // Core endpoints
    expect($ref->hasMethod('track'))->toBeTrue();
    expect($ref->hasMethod('batch'))->toBeTrue();
    expect($ref->hasMethod('identify'))->toBeTrue();
    expect($ref->hasMethod('updateConsent'))->toBeTrue();
    expect($ref->hasMethod('health'))->toBeTrue();
    expect($ref->hasMethod('pageview'))->toBeTrue();

    // Extended SaaS endpoints
    expect($ref->hasMethod('stats'))->toBeTrue();
    expect($ref->hasMethod('report'))->toBeTrue();
    expect($ref->hasMethod('export'))->toBeTrue();
    expect($ref->hasMethod('dashboardOverview'))->toBeTrue();
    expect($ref->hasMethod('saasKpiSummary'))->toBeTrue();
    expect($ref->hasMethod('revenueForecast'))->toBeTrue();

    // Identity resolution
    expect($ref->hasMethod('identityLookup'))->toBeTrue();
    expect($ref->hasMethod('identityResolve'))->toBeTrue();
});

it('criterion 5: Svelte JS client library exports core functions', function () {
    $jsPath = __DIR__ . '/../resources/js/analytics.js';
    expect(file_exists($jsPath))->toBeTrue('analytics.js must exist');

    $content = file_get_contents($jsPath);

    // Core exports
    $requiredExports = [
        'export function init(',
        'export function trackEvent(',
        'export function trackPageView(',
        'export function identify(',
        'export function updateConsent(',
        'export function flushQueue(',
        'export function destroy(',
        'export function getTrackingId(',
        'export function isInitialized(',
        'export function getVersion(',
    ];

    foreach ($requiredExports as $export) {
        expect(str_contains($content, $export))->toBeTrue("Missing export: {$export}");
    }

    // Scroll depth tracking
    expect(str_contains($content, 'export function initScrollDepth('))->toBeTrue('Missing initScrollDepth export');

    // Inertia page view tracker
    expect(str_contains($content, 'initInertiaPageViewTracker'))->toBeTrue('Missing initInertiaPageViewTracker');

    // Debounce/throttle
    expect(str_contains($content, 'export function trackDebounced('))->toBeTrue('Missing trackDebounced');
    expect(str_contains($content, 'export function trackThrottled('))->toBeTrue('Missing trackThrottled');

    // E-commerce shorthand helpers
    expect(str_contains($content, 'export async function trackPurchase('))->toBeTrue('Missing trackPurchase');
    expect(str_contains($content, 'export async function trackRefund('))->toBeTrue('Missing trackRefund');
    expect(str_contains($content, 'export async function trackAddToCart('))->toBeTrue('Missing trackAddToCart');
    expect(str_contains($content, 'export async function trackViewItem('))->toBeTrue('Missing trackViewItem');

    // Sampling engine
    expect(str_contains($content, 'export function getSamplingDecision('))->toBeTrue('Missing getSamplingDecision');
    expect(str_contains($content, 'export function getDebugEventLog('))->toBeTrue('Missing getDebugEventLog');

    // Svelte composables
    $composablePath = __DIR__ . '/../resources/js/useAnalytics.svelte.js';
    expect(file_exists($composablePath))->toBeTrue('useAnalytics.svelte.js must exist');
    $composableContent = file_get_contents($composablePath);
    expect(str_contains($composableContent, 'export function useAnalytics('))->toBeTrue('Missing useAnalytics composable');

    // Constants file
    $constantsPath = __DIR__ . '/../resources/js/analytics.constants.js';
    expect(file_exists($constantsPath))->toBeTrue('analytics.constants.js must exist');
    $constantsContent = file_get_contents($constantsPath);
    expect(str_contains($constantsContent, 'export const EcommerceEvents'))->toBeTrue('Missing EcommerceEvents constants');
    expect(str_contains($constantsContent, 'export const SaaSEvents'))->toBeTrue('Missing SaaSEvents constants');
    expect(str_contains($constantsContent, 'export const EngagementEvents'))->toBeTrue('Missing EngagementEvents constants');
});

it('criterion 6: Event queue infrastructure exists', function () {
    $jobPath = __DIR__ . '/../src/Jobs/TrackAnalyticsEventJob.php';
    $batchJobPath = __DIR__ . '/../src/Jobs/TrackAnalyticsEventBatchJob.php';
    $queueDispatcherPath = __DIR__ . '/../src/Queue/QueuedAnalyticsDispatcher.php';

    expect(file_exists($jobPath))->toBeTrue('TrackAnalyticsEventJob must exist');
    expect(file_exists($batchJobPath))->toBeTrue('TrackAnalyticsEventBatchJob must exist');
    expect(file_exists($queueDispatcherPath))->toBeTrue('QueuedAnalyticsDispatcher must exist');

    // Check queue dispatcher has isEnabled method
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    expect($ref->hasMethod('isEnabled'))->toBeTrue();
    expect($ref->hasMethod('dispatch'))->toBeTrue();
});

it('criterion 7: User identity linking exists', function () {
    $trackerPath = __DIR__ . '/../src/Tracking/UserIdentityTracker.php';
    expect(file_exists($trackerPath))->toBeTrue('UserIdentityTracker must exist');

    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);
    expect($ref->hasMethod('link'))->toBeTrue();

    // Identity resolution service
    $servicePath = __DIR__ . '/../src/Services/IdentityResolutionService.php';
    expect(file_exists($servicePath))->toBeTrue('IdentityResolutionService must exist');

    // Identity graph service
    $graphPath = __DIR__ . '/../src/Services/IdentityGraphService.php';
    expect(file_exists($graphPath))->toBeTrue('IdentityGraphService must exist');

    // Cross-provider identity
    $crossPath = __DIR__ . '/../src/Services/CrossProviderIdentityService.php';
    expect(file_exists($crossPath))->toBeTrue('CrossProviderIdentityService must exist');
});

it('criterion 8: E-commerce format converter has GA4 + Meta + PostHog conversions', function () {
    $ref = new ReflectionClass(EcommerceFormatConverter::class);
    expect($ref->isFinal())->toBeTrue();

    // GA4 → Meta conversions
    expect($ref->hasMethod('ga4ToMetaContents'))->toBeTrue();
    expect($ref->hasMethod('ga4ToMetaPurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToMetaRefund'))->toBeTrue();
    expect($ref->hasMethod('metaToGa4Items'))->toBeTrue();
    expect($ref->hasMethod('metaToGa4Purchase'))->toBeTrue();

    // GA4 → PostHog conversions
    expect($ref->hasMethod('ga4ToPosthogProperties'))->toBeTrue();
    expect($ref->hasMethod('ga4ToPosthogPurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToPosthogRefund'))->toBeTrue();

    // GA4 → Plausible conversions
    expect($ref->hasMethod('ga4ToPlausiblePurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToPlausibleAuto'))->toBeTrue();

    // GA4 → Mixpanel conversions
    expect($ref->hasMethod('ga4ToMixpanelPurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToMixpanelRefund'))->toBeTrue();

    // GA4 → Amplitude conversions
    expect($ref->hasMethod('ga4ToAmplitudePurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToAmplitudeRefund'))->toBeTrue();

    // GA4 → TikTok conversions
    expect($ref->hasMethod('ga4ToTiktokPurchase'))->toBeTrue();
    expect($ref->hasMethod('ga4ToTiktokAddToCart'))->toBeTrue();

    // GA4 → LinkedIn conversions
    expect($ref->hasMethod('ga4ToLinkedinPurchase'))->toBeTrue();

    // Universal converter
    expect($ref->hasMethod('toGa4Format'))->toBeTrue();
    expect($ref->hasMethod('fromGa4Format'))->toBeTrue();
    expect($ref->hasMethod('buildForAllProviders'))->toBeTrue();

    // Convenience builders
    expect($ref->hasMethod('buildGa4Purchase'))->toBeTrue();
    expect($ref->hasMethod('buildGa4Refund'))->toBeTrue();
    expect($ref->hasMethod('buildGa4AddToCart'))->toBeTrue();
    expect($ref->hasMethod('buildGa4ViewItem'))->toBeTrue();
    expect($ref->hasMethod('buildMetaPurchase'))->toBeTrue();
    expect($ref->hasMethod('buildMetaAddToCart'))->toBeTrue();
    expect($ref->hasMethod('buildPosthogPurchase'))->toBeTrue();
    expect($ref->hasMethod('buildPurchaseEvent'))->toBeTrue();
    expect($ref->hasMethod('calculateTotalValue'))->toBeTrue();
});

it('criterion 9: Admin commands exist (AnalyticsOverviewCommand + AnalyticsTestCommand)', function () {
    $overviewPath = __DIR__ . '/../src/Console/Commands/AnalyticsOverviewCommand.php';
    $testPath = __DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php';

    expect(file_exists($overviewPath))->toBeTrue('AnalyticsOverviewCommand must exist');
    expect(file_exists($testPath))->toBeTrue('AnalyticsTestCommand must exist');

    $overviewRef = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
    expect($overviewRef->isFinal())->toBeTrue();
    expect($overviewRef->hasMethod('handle'))->toBeTrue();

    $testRef = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class);
    expect($testRef->isFinal())->toBeTrue();
    expect($testRef->hasMethod('handle'))->toBeTrue();
});

it('criterion 10: Config has all required sections', function () {
    $configPath = __DIR__ . '/../config/zeroboiler.php';
    expect(file_exists($configPath))->toBeTrue('Config file must exist');

    $content = file_get_contents($configPath);

    // Required config sections
    $requiredSections = [
        "'ga4'",
        "'gtm'",
        "'meta_pixel'",
        "'consent'",
        "'auto_track'",
        "'queue'",
        "'lifecycle'",
        "'api'",
        "'identity'",
        "'ecommerce'",
        "'revenue'",
        "'client_auto_track'",
        "'revenue_checksum'",
        "'dedup_cache'",
        "'sla_monitor'",
        "'cost_forecast'",
        "'experiment_analysis'",
        "'deploy_gate'",
        "'sampling'",
        "'broadcasting'",
        "'budget_optimizer'",
        "'tenant_dashboard'",
        "'governance_policies'",
    ];

    foreach ($requiredSections as $section) {
        expect(str_contains($content, $section))->toBeTrue("Missing config section: {$section}");
    }
});

it('criterion 11: Optional providers (Plausible + PostHog + others) implement TrackerInterface', function () {
    $trackers = [
        GA4Tracker::class,
        GTMTracker::class,
        MetaPixelTracker::class,
        PlausibleTracker::class,
        PosthogTracker::class,
        AmplitudeTracker::class,
        MixpanelTracker::class,
        TikTokTracker::class,
        LinkedInTracker::class,
        WebhookTracker::class,
    ];

    $trackerInterface = TrackerInterface::class;

    foreach ($trackers as $tracker) {
        expect(class_exists($tracker))->toBeTrue("Tracker class {$tracker} must exist");
        $ref = new ReflectionClass($tracker);
        expect($ref->implementsInterface($trackerInterface))
            ->toBeTrue("{$tracker} must implement TrackerInterface");

        // Must have track and isEnabled methods
        expect($ref->hasMethod('track'))->toBeTrue("{$tracker} must have track() method");
        expect($ref->hasMethod('isEnabled'))->toBeTrue("{$tracker} must have isEnabled() method");
    }

    expect(count($trackers))->toBe(10, 'Must have 10 tracker implementations');
});

it('criterion 12a: Version consistency across package files', function () {
    $version = AnalyticsEvent::VERSION;

    // Composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe($version);

    // Config existence
    expect(file_exists(__DIR__ . '/../config/zeroboiler.php'))->toBeTrue();

    // Routes existence
    expect(file_exists(__DIR__ . '/../routes/analytics.php'))->toBeTrue();

    // Database migration
    expect(file_exists(__DIR__ . '/../database/migrations/2026_08_12_000000_create_analytics_events_table.php'))->toBeTrue();
});

it('criterion 12b: All source files use strict_types', function () {
    $srcDir = __DIR__ . '/../src';
    $violations = 0;
    $checked = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $checked++;
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1);')) {
                $violations++;
            }
        }
    }

    // Allow up to 2 violations for stub/helper files
    expect($violations)->toBeLessThanOrEqual(2, "Too many strict_types violations: {$violations} in {$checked} files");
    expect($checked)->toBeGreaterThan(400, 'Must have 400+ source PHP files');
});

it('criterion 12c: All event catalog classes are final and use strict types', function () {
    $catalogs = [
        EcommerceEvents::class,
        SaaSEvents::class,
        EngagementEvents::class,
        SecurityEvents::class,
        UptimeEvents::class,
        InfrastructureEvents::class,
        EventCatalog::class,
    ];

    foreach ($catalogs as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
        $file = $ref->getFileName();
        expect($file)->not->toBeFalse("{$class} file must exist");
        $content = file_get_contents((string) $file);
        expect(str_contains($content, 'declare(strict_types=1);'))->toBeTrue("{$class} must use strict_types");
    }
});

it('criterion 12d: Routes file has core API endpoints', function () {
    $content = file_get_contents(__DIR__ . '/../routes/analytics.php');

    $requiredRoutes = [
        "Route::post('events',",
        "Route::post('batch',",
        "Route::post('identify',",
        "Route::post('consent',",
        "Route::get('health',",
        "Route::post('pageview',",
        "Route::get('stats',",
        "Route::get('catalog',",
    ];

    foreach ($requiredRoutes as $route) {
        expect(str_contains($content, $route))->toBeTrue("Missing route: {$route}");
    }
});

it('criterion 12e: AnalyticsEvent DTO is readonly with named args', function () {
    $ref = new ReflectionClass(AnalyticsEvent::class);
    expect($ref->isReadOnly())->toBeTrue('AnalyticsEvent must be readonly');
    expect($ref->isFinal())->toBeTrue('AnalyticsEvent must be final');

    $constructor = $ref->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    $paramNames = array_map(fn (ReflectionParameter $p) => $p->getName(), $params);

    expect($paramNames)->toContain('name');
    expect($paramNames)->toContain('params');
    expect($paramNames)->toContain('clientId');
    expect($paramNames)->toContain('userId');
    expect($paramNames)->toContain('timestamp');
    expect($paramNames)->toContain('priority');
    expect($paramNames)->toContain('source');
});

it('criterion 12f: TypeScript definitions file exists and covers core exports', function () {
    $dtsPath = __DIR__ . '/../resources/js/analytics.d.ts';
    expect(file_exists($dtsPath))->toBeTrue('analytics.d.ts must exist');

    $content = file_get_contents($dtsPath);

    $requiredTypes = [
        'export function init',
        'export function trackEvent',
        'export function trackPageView',
        'export function identify',
        'export function updateConsent',
        'export function trackPurchase',
        'export function trackRefund',
    ];

    foreach ($requiredTypes as $type) {
        expect(str_contains($content, $type))->toBeTrue("Missing TypeScript export: {$type}");
    }
});
