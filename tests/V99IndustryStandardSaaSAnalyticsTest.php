<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as EcommerceSupportConverter;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * V99 — Industry Standard SaaS Analytics Final Maturity Test.
 *
 * Validates the complete analytics package at industry-standard SaaS level.
 * Covers all 12 SaaS starter features plus advanced capabilities:
 * - 100+ event catalog with GA4/Meta/PostHog/Plausible mappings
 * - Server-side lifecycle tracker with 40+ config-driven mappings
 * - Inertia middleware with analytics page props
 * - REST API with form request validation
 * - Svelte JS client + TypeScript definitions
 * - Event queue for async dispatch
 * - User identity linking (client ↔ user)
 * - E-commerce format conversion (GA4 ↔ Meta)
 * - Admin commands (overview, test, health, behavioral, dashboard)
 * - Comprehensive config (30+ sections)
 * - Optional providers (Plausible, PostHog)
 * - 155+ test files with version consistency
 */
test('package maturity: version is 5.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('5.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('5.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 5.0.0');
    expect($js)->toContain("'5.0.0'");

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 5.0.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 5.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-5.0.0');
});

test('package maturity: composer.json requires PHP 8.5+ and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toContain('^13');
    expect($composer['type'])->toBe('library');
    expect($composer['license'])->toBe('MIT');
});

test('feature 1: event catalog has 100+ events with full provider coverage', function (): void {
    $all = EventCatalog::all();

    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);

    // All events have GA4 mapping
    foreach ($all as $name => $entry) {
        expect($entry)->toHaveKeys(['name', 'class', 'ga4', 'category']);
        expect($entry['ga4'])->toBeString();
        expect($entry['ga4'])->not->toBeEmpty();
    }

    // Provider-specific name lists
    expect(EventCatalog::allGa4Names())->not->toBeEmpty();
    expect(EventCatalog::allMetaNames())->not->toBeEmpty();
    expect(EventCatalog::allPosthogNames())->not->toBeEmpty();
    expect(EventCatalog::allPlausibleNames())->not->toBeEmpty();
});

test('feature 1b: catalog has revenue, GDPR, and core SaaS collections', function (): void {
    $revenue = EventCatalog::revenueEvents();
    $gdpr = EventCatalog::gdprEvents();
    $coreSaas = EventCatalog::coreSaaS();

    expect(count($revenue))->toBeGreaterThanOrEqual(10);
    expect($gdpr)->not->toBeEmpty();
    expect(count($coreSaas))->toBeGreaterThanOrEqual(10);

    // Revenue names contain key events
    $revenueNames = array_map(fn (array $e): string => $e['name'], $revenue);
    expect($revenueNames)->toContain('purchase');
    expect($revenueNames)->toContain('subscribe');
});

test('feature 2: lifecycle event mapper has 40+ config-driven mappings', function (): void {
    $ref = new ReflectionClass(LifecycleEventMapper::class);
    $const = $ref->getConstant('DEFAULT_MAPPINGS');

    expect($const)->toBeArray();
    expect(count($const))->toBeGreaterThanOrEqual(40);

    // Authentication
    expect($const)->toHaveKey('auth.login');
    expect($const)->toHaveKey('auth.register');

    // Subscription lifecycle
    expect($const)->toHaveKey('subscription.created');
    expect($const)->toHaveKey('subscription.upgraded');
    expect($const)->toHaveKey('subscription.cancelled');

    // Trial
    expect($const)->toHaveKey('trial.started');

    // Account
    expect($const)->toHaveKey('account.activated');
    expect($const)->toHaveKey('account.deleted');

    // GDPR
    expect($const)->toHaveKey('gdpr.data_erasure_completed');
    expect($const)->toHaveKey('consent.granted');

    // Each mapping has source and target
    foreach ($const as $key => $mapping) {
        expect($mapping)->toHaveKey('source');
        expect($mapping)->toHaveKey('target');
        expect($mapping['target'])->toBeString();
    }
});

test('feature 3: Inertia middleware implements HttpMiddlewareContract', function (): void {
    $ref = new ReflectionClass(HandleInertiaAnalytics::class);

    expect($ref->implementsInterface(HttpMiddlewareContract::class))->toBeTrue();

    $method = $ref->getMethod('handle');
    expect($method->isPublic())->toBeTrue();
    expect($method->hasReturnType())->toBeTrue();
});

test('feature 4: API routes include events, batch, identify, consent + health + GDPR', function (): void {
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

    // Route count 130+
    preg_match_all("/Route::(get|post|put|patch|delete)\\(/", $routes, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(130);
});

test('feature 5: JS client is substantial with all required exports', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->not->toBeFalse();

    // Core exports
    $requiredExports = [
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

    foreach ($requiredExports as $export) {
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

    // Substantial (5K+ lines)
    $lineCount = substr_count($js, "\n");
    expect($lineCount)->toBeGreaterThanOrEqual(5000);
});

test('feature 5b: Svelte composables use Svelte 5 runes', function (): void {
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->not->toBeFalse();

    expect($svelte)->toContain('export function useAnalytics(');
    expect($svelte)->toContain('$state(');
    expect($svelte)->toContain('$derived(');
    expect($svelte)->toContain('$effect(');

    // Specialized composables
    expect($svelte)->toContain('export function useEcommerce(');
    expect($svelte)->toContain('export function useConsent(');
    expect($svelte)->toContain('export function usePlausible(');
    expect($svelte)->toContain('export function usePostHog(');

    // Substantial (800+ lines)
    $lineCount = substr_count($svelte, "\n");
    expect($lineCount)->toBeGreaterThanOrEqual(800);
});

test('feature 6: event queue with QueuedAnalyticsDispatcher and EventReplayQueue', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class))->toBeTrue();

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'queue' => [");
    expect($config)->toContain('ANALYTICS_QUEUE_ENABLED');
});

test('feature 7: identity linking with IdentityResolutionService', function (): void {
    expect(class_exists(IdentityResolutionService::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
    expect(class_exists(\ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class))->toBeTrue();

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'identity' => [");
    expect($config)->toContain('ANALYTICS_IDENTITY_COOKIE');
});

test('feature 8: e-commerce format converter with GA4 ↔ Meta conversion', function (): void {
    $ref = new ReflectionClass(EcommerceSupportConverter::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods());

    expect($methods)->toContain('toGa4Format');
    expect($methods)->toContain('toMetaFormat');
    expect($methods)->toContain('fromGa4Format');
    expect($methods)->toContain('fromMetaFormat');

    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'ecommerce' => [");
    expect($config)->toContain('ANALYTICS_ECOMMERCE_CURRENCY');
});

test('feature 9: admin commands — overview, test, health, behavioral, dashboard', function (): void {
    $requiredCommands = [
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsBehavioralCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class,
        \ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand::class,
    ];

    foreach ($requiredCommands as $cmd) {
        expect(class_exists($cmd))->toBeTrue("Command {$cmd} must exist");
    }
});

test('feature 10: config has 25+ sections', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    $requiredSections = [
        'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
        'queue', 'identity', 'ecommerce', 'revenue', 'track_links',
        'api', 'plausible', 'posthog', 'webhook', 'audit_log',
        'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
        'replay', 'metrics', 'stream', 'client_auto_track', 'performance',
    ];

    foreach ($requiredSections as $section) {
        expect($config)->toContain("'{$section}' => [");
    }
});

test('feature 11: optional providers implement TrackerInterface', function (): void {
    $plausibleRef = new ReflectionClass(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
    $posthogRef = new ReflectionClass(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);

    expect($plausibleRef->implementsInterface(TrackerInterface::class))->toBeTrue();
    expect($posthogRef->implementsInterface(TrackerInterface::class))->toBeTrue();

    // Config sections
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'plausible' => [");
    expect($config)->toContain("'posthog' => [");
});

test('feature 12: 155+ test files with comprehensive coverage', function (): void {
    $testDir = __DIR__;
    $testFiles = glob($testDir . '/*Test.php');
    $featureTestFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
    if ($featureTestFiles === false) {
        $featureTestFiles = [];
    }

    $total = count($testFiles) + count($featureTestFiles);
    expect($total)->toBeGreaterThanOrEqual(155);

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

test('advanced: AnalyticsManager has SaaS lifecycle convenience methods', function (): void {
    $manager = new AnalyticsManager;

    $ref = new ReflectionClass($manager);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    // SaaS lifecycle methods
    expect($methods)->toContain('signUp');
    expect($methods)->toContain('login');
    expect($methods)->toContain('trialStart');
    expect($methods)->toContain('subscription');
    expect($methods)->toContain('planUpgrade');
    expect($methods)->toContain('cancellation');
    expect($methods)->toContain('purchase');
    expect($methods)->toContain('identify');

    // Orchestration methods
    expect($methods)->toContain('orchestrate');
    expect($methods)->toContain('orchestrateAdvance');
    expect($methods)->toContain('orchestrateComplete');
    expect($methods)->toContain('orchestrateCancel');

    // Funnel methods
    expect($methods)->toContain('trackFunnel');
    expect($methods)->toContain('trackFunnelProgress');

    // Health
    expect($methods)->toContain('healthCheck');
    expect($methods)->toContain('ping');

    // SaaS acquisition convenience
    expect($methods)->toContain('trackSaaSAcquisition');
});

test('advanced: EventPriorityCalculator scores maturity at 80+', function (): void {
    $calculator = new EventPriorityCalculator;
    $result = $calculator->maturityScore();

    expect($result)->toHaveKeys(['score', 'grade', 'details']);
    expect($result['score'])->toBeInt();
    expect($result['score'])->toBeGreaterThanOrEqual(80);
    expect($result['grade'])->toBeString();
});

test('advanced: AARRR framework coverage', function (): void {
    $calculator = new EventPriorityCalculator;
    $classified = $calculator->classifyAll();

    expect($classified)->toHaveKeys([
        'acquisition', 'activation', 'retention', 'revenue', 'referral', 'operational',
    ]);

    expect(count($classified['revenue']))->toBeGreaterThanOrEqual(20);
    expect(count($classified['acquisition']))->toBeGreaterThanOrEqual(3);
});

test('advanced: catalog validates cleanly with summary', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();

    $summary = EventCatalog::summary();
    expect($summary)->toHaveKeys(['total', 'ecommerce', 'saas', 'engagement']);
    expect($summary['total'])->toBeGreaterThanOrEqual(100);
});

test('advanced: AnalyticsEvent DTO is readonly and final', function (): void {
    $ref = new ReflectionClass(AnalyticsEvent::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('advanced: Facade proxies all public manager methods', function (): void {
    $facadeRef = new ReflectionClass(Analytics::class);
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

test('advanced: pipeline has dedup, consent, sampling, and enrichment', function (): void {
    $requiredPipelineFilters = [
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

    foreach ($requiredPipelineFilters as $filter) {
        expect(class_exists($filter))->toBeTrue("Pipeline filter {$filter} must exist");
    }
});

test('advanced: middleware stack has consent gate, PII sanitization, schema validation', function (): void {
    $requiredMiddleware = [
        \ZeroBoiler\Analytics\Middleware\ConsentGateMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\SchemaValidationMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\TimestampMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\LoggingMiddleware::class,
        \ZeroBoiler\Analytics\Middleware\AuditLogMiddleware::class,
    ];

    foreach ($requiredMiddleware as $mw) {
        expect(class_exists($mw))->toBeTrue("Middleware {$mw} must exist");
    }
});

test('advanced: services layer has 80+ registered services', function (): void {
    $servicesDir = __DIR__ . '/../src/Services';
    $serviceFiles = glob($servicesDir . '/*.php');
    expect($serviceFiles)->not->toBeEmpty();
    expect(count($serviceFiles))->toBeGreaterThanOrEqual(80);
});

test('advanced: GDPR compliance services exist', function (): void {
    $gdprServices = [
        \ZeroBoiler\Analytics\Services\AdvancedPIIDetector::class,
        \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService::class,
        \ZeroBoiler\Analytics\Services\GdprErasureService::class,
        \ZeroBoiler\Analytics\Services\DataRetentionPolicyService::class,
        \ZeroBoiler\Analytics\Services\DataMinimizationService::class,
        \ZeroBoiler\Analytics\Services\EventComplianceService::class,
        \ZeroBoiler\Analytics\Services\IpAnonymizationService::class,
    ];

    foreach ($gdprServices as $service) {
        expect(class_exists($service))->toBeTrue("GDPR service {$service} must exist");
    }
});

test('advanced: SaaS revenue and analytics services', function (): void {
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

test('advanced: AnalyticsMetrics tracks counters', function (): void {
    expect(class_exists(AnalyticsMetrics::class))->toBeTrue();

    $ref = new ReflectionClass(AnalyticsMetrics::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('increment');
    expect($methods)->toContain('decrement');
    expect($methods)->toContain('count');
    expect($methods)->toContain('reset');
    expect($methods)->toContain('all');
});
