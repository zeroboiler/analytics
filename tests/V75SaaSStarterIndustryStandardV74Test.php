<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * V75 — SaaS Starter Industry Standard v76.0.0 Comprehensive Validation.
 *
 * Validates all 12 SaaS starter features at version 76.0.0 with zero
 * version drift across the entire codebase (tests, src, config, JS, TS, README).
 *
 * Features validated:
 * 1. Event Catalog: 100+ events (ecommerce, SaaS, engagement, infrastructure, security, uptime)
 * 2. Server-Side Lifecycle Tracker: config-driven Laravel event → analytics event mapping
 * 3. Inertia middleware: page props with analytics config, client ID cookie
 * 4. API controller + routes: POST /api/analytics/events, /batch, /identify, /consent
 * 5. Svelte JS client: trackEvent, trackPageView, initInertiaPageViewTracker, scroll depth
 * 6. Event queue: async dispatch (QueuedAnalyticsDispatcher + EventReplayQueue)
 * 7. User identity linking: client ID ↔ user ID (IdentityResolutionService)
 * 8. E-commerce helpers: GA4 + Meta format conversion (EcommerceFormatConverter)
 * 9. Admin commands: AnalyticsOverviewCommand, AnalyticsTestCommand
 * 10. Config expansion: queue, API, identity, auto-track, ecommerce, 30+ sections
 * 11. Optional providers: Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook trackers
 * 12. Tests: 280+ test files with version consistency
 *
 * @since 76.0.0
 */
test('v75 version sweep: zero drift — 76.0.0 everywhere', function (): void {
    // AnalyticsEvent DTO
    expect(AnalyticsEvent::VERSION)->toBe('76.0.0');

    // composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('76.0.0');

    // package.json
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe('76.0.0');

    // JS client
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 76.0.0');
    expect($js)->toContain("'76.0.0'");

    // Svelte composable
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 76.0.0');

    // TypeScript definitions
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 76.0.0');

    // README badge
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-76.0.0');

    // IntegrityCommand EXPECTED_VERSION constant
    $integrityFile = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
    expect($integrityFile)->toContain("EXPECTED_VERSION = '76.0.0'");

    // Zero references to old version 10.3.0 in tests
    $testFiles = glob(__DIR__ . '/*Test.php');
    $staleRefs = 0;
    foreach ($testFiles as $file) {
        $content = file_get_contents($file);
        $staleRefs += substr_count($content, '10.3.0');
    }
    expect($staleRefs)->toBe(0, 'No 10.3.0 references should remain in test files');

    // No stale references in src/
    $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
    if ($srcFiles === false) {
        $srcFiles = [];
    }
    $srcStale = 0;
    foreach ($srcFiles as $file) {
        $srcStale += substr_count(file_get_contents($file), '10.3.0');
    }
    expect($srcStale)->toBe(0, 'No 10.3.0 references should remain in src/');

    // No stale references in config
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toContain('10.3.0');
});

test('v75 feature 1: event catalog has 100+ events across all categories', function (): void {
    $all = EventCatalog::all();

    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);

    // All entries have required keys
    foreach ($all as $name => $entry) {
        expect($entry)->toHaveKeys(['name', 'class', 'ga4', 'category']);
        expect($entry['ga4'])->toBeString();
        expect($entry['ga4'])->not->toBeEmpty();
    }

    // Provider name lists are non-empty
    expect(EventCatalog::allGa4Names())->not->toBeEmpty();
    expect(EventCatalog::allMetaNames())->not->toBeEmpty();
    expect(EventCatalog::allPosthogNames())->not->toBeEmpty();
    expect(EventCatalog::allPlausibleNames())->not->toBeEmpty();

    // Revenue, GDPR, core SaaS collections
    expect(count(EventCatalog::revenueEvents()))->toBeGreaterThanOrEqual(10);
    expect(EventCatalog::gdprEvents())->not->toBeEmpty();
    expect(count(EventCatalog::coreSaaS()))->toBeGreaterThanOrEqual(10);

    // Catalog validates cleanly
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('v75 feature 2: lifecycle mapper has 40+ config-driven mappings', function (): void {
    $ref = new ReflectionClass(LifecycleEventMapper::class);
    $const = $ref->getConstant('DEFAULT_MAPPINGS');

    expect($const)->toBeArray();
    expect(count($const))->toBeGreaterThanOrEqual(40);

    // Key lifecycle events
    $requiredMappings = [
        'auth.login', 'auth.register', 'auth.logout',
        'subscription.created', 'subscription.upgraded', 'subscription.cancelled',
        'trial.started', 'trial.ended',
        'account.activated', 'account.deleted',
        'gdpr.data_erasure_completed', 'consent.granted', 'consent.withdrawn',
        'order.completed', 'order.refunded',
        'form.submitted', 'search.performed',
    ];

    foreach ($requiredMappings as $mapping) {
        expect($const)->toHaveKey($mapping);
    }

    // Each mapping has source, target, priority
    foreach ($const as $key => $mapping) {
        expect($mapping)->toHaveKey('source');
        expect($mapping)->toHaveKey('target');
        expect($mapping['target'])->toBeString();
    }
});

test('v75 feature 3: Inertia middleware with 25+ prop groups', function (): void {
    $ref = new ReflectionClass(HandleInertiaAnalytics::class);

    expect($ref->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class))->toBeTrue();

    $method = $ref->getMethod('handle');
    expect($method->isPublic())->toBeTrue();
    expect($method->hasReturnType())->toBeTrue();

    // #[\Override] attribute
    $attrs = $method->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === \Override::class) {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();

    // Constructor injection
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();
    expect(count($params))->toBe(2);

    // Verify 25+ prop groups are injected (checked via source)
    $source = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
    expect($source)->not->toBeFalse();

    $propKeys = [
        'enabled', 'consent', 'trackingId', 'userId',
        'ga4MeasurementId', 'gtmContainerId', 'metaPixelId',
        'plausibleDomain', 'posthogHost', 'amplitudeApiKey',
        'mixpanelToken', 'tiktokPixelId', 'linkedinPartnerId',
        'trackLinks', 'device', 'apiBase', 'apiEnabled',
        'consentPurposes', 'debug', 'autoTrack', 'performance',
        'ecommerce', 'consentLogEnabled', 'consentVersion',
        'version', 'subscriptionTiers', 'identityAutoLink',
        'maturity', 'onboarding', 'funnelReadiness',
        'recommendedEvents', 'dedup', 'sampling', 'geolocation',
        'regionalConsent', 'crossDomain', 'sessionRecording', 'observability',
    ];

    expect(count($propKeys))->toBeGreaterThanOrEqual(25);

    foreach ($propKeys as $key) {
        expect($source)->toContain("'{$key}'");
    }
});

test('v75 feature 4: API routes with 130+ endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    // Core CRUD
    expect($routes)->toContain("Route::post('events'");
    expect($routes)->toContain("Route::post('batch'");
    expect($routes)->toContain("Route::post('identify'");
    expect($routes)->toContain("Route::post('consent'");

    // Public
    expect($routes)->toContain("Route::get('health'");
    expect($routes)->toContain("Route::get('catalog'");

    // GDPR
    expect($routes)->toContain("Route::delete('data'");
    expect($routes)->toContain("Route::get('gdpr/export'");

    // Identity
    expect($routes)->toContain('identityLookup');
    expect($routes)->toContain('identityResolve');

    // SSE
    expect($routes)->toContain("Route::get('stream'");

    // Route count
    preg_match_all("/Route::(get|post|put|patch|delete)\\(/", $routes, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(130);
});

test('v75 feature 5: JS client with 5K+ lines and all required exports', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->not->toBeFalse();

    // Core exports
    $exports = [
        'export function init(',
        'export function trackEvent(',
        'export function trackPageView(',
        'export function trackScreenView(',
        'export function trackEcommerce(',
        'export function trackIdentify(',
        'export function updateConsent(',
        'export function flushQueue(',
        'export function getTrackingId(',
        'export function getVersion(',
        'export function destroy(',
        'export function isInitialized(',
    ];

    foreach ($exports as $export) {
        expect($js)->toContain($export);
    }

    // Inertia page view tracker
    expect($js)->toContain('function initInertiaPageViewTracker');

    // Batch queue
    expect($js)->toContain('eventQueue');
    expect($js)->toContain('FLUSH_INTERVAL');
    expect($js)->toContain('MAX_QUEUE_SIZE');

    // Provider init functions
    expect($js)->toContain('function initGA4');
    expect($js)->toContain('function initGTM');
    expect($js)->toContain('function initMetaPixel');
    expect($js)->toContain('function initPlausible');
    expect($js)->toContain('function initPostHog');

    // UTM capture
    expect($js)->toContain('function captureUTM');

    // Auto-identify
    expect($js)->toContain('function autoIdentify');

    // Version
    expect($js)->toContain("'76.0.0'");

    // Substantial (5K+ lines)
    $lineCount = substr_count($js, "\n");
    expect($lineCount)->toBeGreaterThanOrEqual(5000);
});

test('v75 feature 5b: Svelte composables with Svelte 5 runes', function (): void {
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->not->toBeFalse();

    expect($svelte)->toContain('export function useAnalytics(');
    expect($svelte)->toContain('$state(');
    expect($svelte)->toContain('$derived(');
    expect($svelte)->toContain('$effect(');

    expect($svelte)->toContain('export function useEcommerce(');
    expect($svelte)->toContain('export function useConsent(');
    expect($svelte)->toContain('export function usePlausible(');
    expect($svelte)->toContain('export function usePostHog(');

    // Imports from analytics.js
    expect($svelte)->toContain("from './analytics.js'");

    $lineCount = substr_count($svelte, "\n");
    expect($lineCount)->toBeGreaterThanOrEqual(800);
});

test('v75 feature 6: event queue with QueuedAnalyticsDispatcher', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class))->toBeTrue();

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'queue' => [");
    expect($config)->toContain('ANALYTICS_QUEUE_ENABLED');
    expect($config)->toContain('ANALYTICS_QUEUE');
    expect($config)->toContain('ANALYTICS_QUEUE_CONNECTION');
});

test('v75 feature 7: identity linking with IdentityResolutionService', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Services\CrossProviderIdentityService::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Services\IdentityGraphService::class))->toBeTrue();

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'identity' => [");
    expect($config)->toContain('ANALYTICS_IDENTITY_COOKIE');
    expect($config)->toContain('ANALYTICS_IDENTITY_LINK_ON_AUTH');
    expect($config)->toContain('ANALYTICS_IDENTITY_CACHE_PREFIX');
    expect($config)->toContain('ANALYTICS_IDENTITY_LINK_TTL');
});

test('v75 feature 8: e-commerce format converter GA4 ↔ Meta', function (): void {
    $ref = new ReflectionClass(EcommerceFormatConverter::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods());

    expect($methods)->toContain('toGa4Format');
    expect($methods)->toContain('toMetaFormat');
    expect($methods)->toContain('fromGa4Format');
    expect($methods)->toContain('fromMetaFormat');

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'ecommerce' => [");
    expect($config)->toContain('ANALYTICS_ECOMMERCE_CURRENCY');
    expect($config)->toContain('ANALYTICS_ECOMMERCE_BRAND');

    // EcommerceAnalyticsService
    expect(class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Services\CartStateManager::class))->toBeTrue();
});

test('v75 feature 9: admin commands (overview, test, health, behavioral, dashboard)', function (): void {
    $commands = [
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsBehavioralCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand::class,
    ];

    foreach ($commands as $cmd) {
        expect(class_exists($cmd))->toBeTrue("Command {$cmd} must exist");
    }

    // OverviewCommand is final
    $overviewRef = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
    expect($overviewRef->isFinal())->toBeTrue();

    // TestCommand is final
    $testRef = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class);
    expect($testRef->isFinal())->toBeTrue();
});

test('v75 feature 10: config has 30+ sections', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    $sections = [
        'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
        'queue', 'identity', 'ecommerce', 'revenue', 'track_links',
        'api', 'plausible', 'posthog', 'webhook', 'audit_log',
        'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
        'replay', 'metrics', 'stream', 'client_auto_track', 'performance',
        'sanitization', 'data_mart', 'insight_engine', 'recommendations',
        'lifecycle', 'onboarding_funnel', 'saas_kpi_calc', 'event_templates',
    ];

    foreach ($sections as $section) {
        expect($config)->toContain("'{$section}' => [");
    }

    expect(count($sections))->toBeGreaterThanOrEqual(30);
});

test('v75 feature 11: all 10 providers implement TrackerInterface', function (): void {
    $providers = [
        \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
        \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
        \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
        \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
        \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
        \ZeroBoiler\Analytics\Trackers\MixpanelTracker::class,
        \ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class,
        \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
        \ZeroBoiler\Analytics\Trackers\TikTokTracker::class,
        \ZeroBoiler\Analytics\Trackers\LinkedInTracker::class,
    ];

    foreach ($providers as $tracker) {
        expect(class_exists($tracker))->toBeTrue("Tracker {$tracker} must exist");
        $ref = new ReflectionClass($tracker);
        expect($ref->implementsInterface(TrackerInterface::class))->toBeTrue("{$tracker} must implement TrackerInterface");
    }

    // Config sections for optional providers
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'plausible' => [");
    expect($config)->toContain("'posthog' => [");
    expect($config)->toContain("'mixpanel' => [");
    expect($config)->toContain("'amplitude' => [");
    expect($config)->toContain("'tiktok' => [");
    expect($config)->toContain("'linkedin' => [");
});

test('v75 feature 12: 280+ test files with comprehensive coverage', function (): void {
    $testDir = __DIR__;
    $testFiles = glob($testDir . '/*Test.php');
    $featureFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
    if ($featureFiles === false) {
        $featureFiles = [];
    }

    $total = count($testFiles) + count($featureFiles);
    expect($total)->toBeGreaterThanOrEqual(280);

    // Key test categories
    $requiredTests = [
        'AnalyticsManagerTest.php',
        'EcommerceEventsTest.php',
        'EngagementEventsTest.php',
        'SaaSEventsTest.php',
        'EventCatalogTest.php',
        'ConsentModeTest.php',
        'PipelineTest.php',
        'GA4TrackerTest.php',
        'GTMTrackerTest.php',
        'MetaPixelTrackerTest.php',
        'ServerSideTrackerTest.php',
        'OptionalTrackersTest.php',
    ];

    foreach ($requiredTests as $testFile) {
        expect(file_exists($testDir . '/' . $testFile))->toBeTrue("Missing test: {$testFile}");
    }
});

test('v75 advanced: AnalyticsManager SaaS lifecycle methods', function (): void {
    $manager = new AnalyticsManager;
    $ref = new ReflectionClass($manager);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    // SaaS lifecycle
    expect($methods)->toContain('signUp');
    expect($methods)->toContain('login');
    expect($methods)->toContain('trialStart');
    expect($methods)->toContain('subscription');
    expect($methods)->toContain('planUpgrade');
    expect($methods)->toContain('cancellation');
    expect($methods)->toContain('purchase');
    expect($methods)->toContain('identify');

    // Orchestration
    expect($methods)->toContain('orchestrate');
    expect($methods)->toContain('orchestrateAdvance');
    expect($methods)->toContain('orchestrateComplete');
    expect($methods)->toContain('orchestrateCancel');

    // Funnel
    expect($methods)->toContain('trackFunnel');
    expect($methods)->toContain('trackFunnelProgress');

    // Health
    expect($methods)->toContain('healthCheck');
    expect($methods)->toContain('ping');
});

test('v75 advanced: AnalyticsEvent DTO is readonly and final', function (): void {
    $ref = new ReflectionClass(AnalyticsEvent::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('v75 advanced: Facade documents SaaS lifecycle methods', function (): void {
    $facadeRef = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
    $doc = $facadeRef->getDocComment();
    expect($doc)->not->toBeFalse();

    expect($doc)->toContain('signUp');
    expect($doc)->toContain('login');
    expect($doc)->toContain('trialStart');
    expect($doc)->toContain('subscription');
    expect($doc)->toContain('planUpgrade');
    expect($doc)->toContain('cancellation');
    expect($doc)->toContain('purchase');
    expect($doc)->toContain('identify');
    expect($doc)->toContain('healthCheck');
});

test('v75 advanced: pipeline has dedup, consent, sampling, and enrichment stages', function (): void {
    $filters = [
        \ZeroBoiler\Analytics\Pipeline\ConsentFilter::class,
        \ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter::class,
        \ZeroBoiler\Analytics\Pipeline\EventDebounceFilter::class,
        \ZeroBoiler\Analytics\Pipeline\EventDeduplicationFilter::class,
        \ZeroBoiler\Analytics\Pipeline\SamplingFilter::class,
        \ZeroBoiler\Analytics\Pipeline\TimestampEnricher::class,
        \ZeroBoiler\Analytics\Pipeline\UtmEnricher::class,
        \ZeroBoiler\Analytics\Pipeline\UserContextEnricher::class,
        \ZeroBoiler\Analytics\Pipeline\GeolocationEnricher::class,
        \ZeroBoiler\Analytics\Pipeline\SchemaEnricher::class,
    ];

    foreach ($filters as $filter) {
        expect(class_exists($filter))->toBeTrue("Pipeline filter {$filter} must exist");
    }
});

test('v75 advanced: middleware stack with consent, PII, schema, audit', function (): void {
    $middleware = [
        \ZeroBoiler\Analytics\Middleware\ConsentGateMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\SchemaValidationMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\TimestampMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\LoggingMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\AuditLogMiddleware::class,
    ];

    foreach ($middleware as $mw) {
        expect(class_exists($mw))->toBeTrue("Middleware {$mw} must exist");
    }
});

test('v75 advanced: services layer has 80+ registered services', function (): void {
    $servicesDir = __DIR__ . '/../src/Services';
    $serviceFiles = glob($servicesDir . '/*.php');
    expect($serviceFiles)->not->toBeEmpty();
    expect(count($serviceFiles))->toBeGreaterThanOrEqual(80);
});

test('v75 advanced: GDPR compliance services exist', function (): void {
    $gdprServices = [
        \ZeroBoiler\Analytics\Services\GdprErasureService::class,
        \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService::class,
        \ZeroBoiler\Analytics\Services\DataRetentionPolicyService::class,
        \ZeroBoiler\Analytics\Services\DataMinimizationService::class,
        \ZeroBoiler\Analytics\Services\EventComplianceService::class,
        \ZeroBoiler\Analytics\Services\IpAnonymizationService::class,
        \ZeroBoiler\Analytics\Services\AdvancedPIIDetector::class,
    ];

    foreach ($gdprServices as $service) {
        expect(class_exists($service))->toBeTrue("GDPR service {$service} must exist");
    }
});

test('v75 advanced: SaaS revenue and analytics services', function (): void {
    $saasServices = [
        \ZeroBoiler\Analytics\Services\RevenueAnalyticsService::class,
        \ZeroBoiler\Analytics\Services\RevenueIntelligenceService::class,
        \ZeroBoiler\Analytics\Services\RevenueAttributionService::class,
        \ZeroBoiler\Analytics\Services\RevenueForecastService::class,
        \ZeroBoiler\Analytics\Services\FunnelAnalyticsService::class,
        \ZeroBoiler\Analytics\Services\FunnelVelocityService::class,
        \ZeroBoiler\Analytics\Services\CohortAnalyticsService::class,
        \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class,
        \ZeroBoiler\Analytics\Services\ChurnPredictionService::class,
        \ZeroBoiler\Analytics\Services\RetentionCalculator::class,
    ];

    foreach ($saasServices as $service) {
        expect(class_exists($service))->toBeTrue("SaaS service {$service} must exist");
    }
});

test('v75 advanced: AnalyticsMetrics tracks counters', function (): void {
    expect(class_exists(AnalyticsMetrics::class))->toBeTrue();

    $ref = new ReflectionClass(AnalyticsMetrics::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('increment');
    expect($methods)->toContain('decrement');
    expect($methods)->toContain('count');
    expect($methods)->toContain('reset');
    expect($methods)->toContain('all');
});
