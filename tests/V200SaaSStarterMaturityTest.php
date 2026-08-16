<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * V200.0.0 — Comprehensive SaaS Starter Maturity Audit.
 *
 * Validates all 12 industry-standard SaaS analytics features required
 * for a production-ready SaaS analytics starter kit:
 *
 *  1. Event Catalog (EcommerceEvents, SaaSEvents, EngagementEvents)
 *  2. Server-Side Lifecycle Tracker (config-driven mapping)
 *  3. Inertia middleware (page props + client ID cookie)
 *  4. API controller + routes (/events, /batch, /identify, /consent)
 *  5. Svelte JS client (trackEvent, trackPageView, scroll depth, client ID)
 *  6. Event queue (async dispatch)
 *  7. User identity linking (client ID ↔ user ID)
 *  8. E-commerce helpers (GA4 + Meta format conversion)
 *  9. Admin commands (Overview, Test)
 * 10. Config expansion (queue, API, identity, auto-track, ecommerce)
 * 11. Optional providers (Plausible, PostHog)
 * 12. Tests + README
 *
 * @since 200.0.0
 */
final class V200SaaSStarterMaturityTest extends \PHPUnit\Framework\TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // 1. EVENT CATALOG
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function ecommerceEventsCatalogExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class);
        $this->assertTrue($class->isFinal(), 'EcommerceEvents must be final');
        $this->assertGreaterThanOrEqual(14, count(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names()));
    }

    #[Test]
    public function ecommerceCatalogContainsCoreEvents(): void
    {
        $names = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names();
        $required = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($required as $event) {
            $this->assertContains($event, $names, "Missing core ecommerce event: {$event}");
        }
    }

    #[Test]
    public function saasEventsCatalogExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/SaaS/SaaSEvents.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::class);
        $this->assertTrue($class->isFinal(), 'SaaSEvents must be final');
        $this->assertGreaterThanOrEqual(20, count(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names()));
    }

    #[Test]
    public function saasCatalogContainsCoreEvents(): void
    {
        $names = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names();
        $required = ['sign_up', 'login', 'start_trial', 'cancellation', 'plan_upgrade'];
        foreach ($required as $event) {
            $this->assertContains($event, $names, "Missing core SaaS event: {$event}");
        }
    }

    #[Test]
    public function engagementEventsCatalogExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/Engagement/EngagementEvents.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::class);
        $this->assertTrue($class->isFinal(), 'EngagementEvents must be final');
        $this->assertGreaterThanOrEqual(15, count(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names()));
    }

    #[Test]
    public function engagementCatalogContainsCoreEvents(): void
    {
        $names = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names();
        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($required as $event) {
            $this->assertContains($event, $names, "Missing core engagement event: {$event}");
        }
    }

    #[Test]
    public function eventCatalogAggregatesAllCategories(): void
    {
        $all = \ZeroBoiler\Analytics\Events\EventCatalog::all();
        $this->assertArrayHasKey('view_item', $all);
        $this->assertArrayHasKey('sign_up', $all);
        $this->assertArrayHasKey('page_view', $all);
        $this->assertGreaterThanOrEqual(150, count($all), 'EventCatalog should aggregate 150+ events');
    }

    #[Test]
    public function eventCatalogProvidesProviderMappings(): void
    {
        $entry = \ZeroBoiler\Analytics\Events\EventCatalog::get('purchase');
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('ga4', $entry);
        $this->assertArrayHasKey('meta', $entry);
        $this->assertArrayHasKey('category', $entry);
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. SERVER-SIDE LIFECYCLE TRACKER
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function lifecycleTrackerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Tracking/ServerSideTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Tracking/LifecycleEventSubscriber.php');
    }

    #[Test]
    public function lifecycleEventMapperExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/LifecycleEventMapper.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Services\LifecycleEventMapper::class);
        $this->assertTrue($class->isFinal());
    }

    #[Test]
    public function lifecycleConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('lifecycle', $config['analytics']);
        $this->assertArrayHasKey('enabled', $config['analytics']['lifecycle']);
        $this->assertArrayHasKey('queue_events', $config['analytics']['lifecycle']);
        $this->assertArrayHasKey('custom_mappings', $config['analytics']['lifecycle']);
    }

    #[Test]
    public function autoTrackConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('auto_track', $config['analytics']);
        $events = $config['analytics']['auto_track']['events'];
        $this->assertArrayHasKey('auth.login', $events);
        $this->assertArrayHasKey('subscription.created', $events);
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. INERTIA MIDDLEWARE
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function inertiaMiddlewareExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
        $this->assertTrue($class->isFinal(), 'HandleInertiaAnalytics must be final');

        $ctor = $class->getMethod('__construct');
        $this->assertStringContainsString('void', (string) $ctor->getReturnType());
    }

    #[Test]
    public function inertiaMiddlewareImplementsContract(): void
    {
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
        $interfaces = $class->getInterfaceNames();
        $this->assertContains(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class, $interfaces);
    }

    #[Test]
    public function inertiaMiddlewareHasHandleMethod(): void
    {
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
        $this->assertTrue($class->hasMethod('handle'));
        $method = $class->getMethod('handle');
        $this->assertNotNull($method->getReturnType());
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. API CONTROLLER + ROUTES
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function apiControllerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    }

    #[Test]
    public function apiRoutesExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../routes/analytics.php');
        $content = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString('events', $content);
        $this->assertStringContainsString('batch', $content);
    }

    #[Test]
    public function apiFormRequestsExist(): void
    {
        $requests = [
            'TrackEventRequest',
            'BatchEventRequest',
            'IdentifyRequest',
            'UpdateConsentRequest',
        ];
        foreach ($requests as $request) {
            $this->assertFileExists(
                __DIR__ . '/../src/Http/Requests/' . $request . '.php',
                "Missing form request: {$request}"
            );
        }
    }

    #[Test]
    public function apiConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('api', $config['analytics']);
        $api = $config['analytics']['api'];
        $this->assertArrayHasKey('enabled', $api);
        $this->assertArrayHasKey('base_url', $api);
        $this->assertArrayHasKey('rate_limit', $api);
        $this->assertArrayHasKey('sdk_token', $api);
        $this->assertArrayHasKey('batch_max_size', $api);
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. SVELTE JS CLIENT
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function jsClientLibraryExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.js');
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('trackEvent', $content);
        $this->assertStringContainsString('trackPageView', $content);
    }

    #[Test]
    public function jsClientHasInitFunction(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('function init', $content);
    }

    #[Test]
    public function svelteComposablesExist(): void
    {
        $composables = [
            'useAnalytics.svelte.js',
            'useConsent.svelte.js',
            'useEcommerce.svelte.js',
            'useIdentity.svelte.js',
            'usePageView.svelte.js',
            'useScrollDepth.svelte.js',
            'useLifecycle.svelte.js',
            'useSaaSFlow.svelte.js',
        ];
        foreach ($composables as $composable) {
            $this->assertFileExists(
                __DIR__ . '/../resources/js/' . $composable,
                "Missing Svelte composable: {$composable}"
            );
        }
    }

    #[Test]
    public function typeScriptDefinitionsExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.d.ts');
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. EVENT QUEUE
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function queueJobsExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Jobs/TrackAnalyticsEventJob.php');
        $this->assertFileExists(__DIR__ . '/../src/Jobs/TrackAnalyticsEventBatchJob.php');
    }

    #[Test]
    public function queuedDispatcherExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Queue/QueuedAnalyticsDispatcher.php');
    }

    #[Test]
    public function queueConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('queue', $config['analytics']);
        $queue = $config['analytics']['queue'];
        $this->assertArrayHasKey('enabled', $queue);
        $this->assertArrayHasKey('queue', $queue);
        $this->assertArrayHasKey('connection', $queue);
        $this->assertArrayHasKey('max_batch_size', $queue);
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. USER IDENTITY LINKING
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function identityTrackerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Tracking/UserIdentityTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/IdentityResolutionService.php');
    }

    #[Test]
    public function identityConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('identity', $config['analytics']);
        $identity = $config['analytics']['identity'];
        $this->assertArrayHasKey('cookie_name', $identity);
        $this->assertArrayHasKey('cookie_ttl', $identity);
        $this->assertArrayHasKey('cookie_secure', $identity);
        $this->assertArrayHasKey('cookie_samesite', $identity);
        $this->assertArrayHasKey('link_on_auth', $identity);
        $this->assertArrayHasKey('auto_link', $identity);
        $this->assertArrayHasKey('cache_prefix', $identity);
    }

    // ──────────────────────────────────────────────────────────────────
    // 8. E-COMMERCE HELPERS
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function ecommerceFormatConverterExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function ecommerceConfigSectionExists(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('ecommerce', $config['analytics']);
        $ecom = $config['analytics']['ecommerce'];
        $this->assertArrayHasKey('currency', $ecom);
        $this->assertArrayHasKey('brand', $ecom);
    }

    #[Test]
    public function ecommerceEventsHaveFactoryMethods(): void
    {
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class);
        $factoryMethods = ['viewItem', 'addToCart', 'purchase', 'refund'];
        foreach ($factoryMethods as $method) {
            $this->assertTrue(
                $class->hasMethod($method),
                "EcommerceEvents missing factory method: {$method}"
            );
            $m = $class->getMethod($method);
            $this->assertNotNull($m->getReturnType(), "{$method} must have return type");
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 9. ADMIN COMMANDS
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function overviewCommandExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsOverviewCommand.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
        $this->assertTrue($class->isFinal());
        $this->assertNotNull($class->getMethod('handle')->getReturnType());
    }

    #[Test]
    public function testCommandExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class);
        $this->assertTrue($class->isFinal());
        $this->assertNotNull($class->getMethod('handle')->getReturnType());
    }

    // ──────────────────────────────────────────────────────────────────
    // 10. CONFIG EXPANSION
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function coreConfigSectionsExist(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $analytics = $config['analytics'];
        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'lifecycle', 'api', 'identity', 'ecommerce',
            'client_auto_track', 'revenue', 'saas_kpi_calc',
        ];
        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey($section, $analytics, "Missing config section: {$section}");
        }
    }

    #[Test]
    public function configHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ──────────────────────────────────────────────────────────────────
    // 11. OPTIONAL PROVIDERS
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function plausibleTrackerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Trackers/PlausibleTracker.php');
    }

    #[Test]
    public function posthogTrackerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Trackers/PosthogTracker.php');
    }

    #[Test]
    public function allTenTrackersExist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker', 'PlausibleTracker',
            'PosthogTracker', 'MixpanelTracker', 'AmplitudeTracker', 'TikTokTracker',
            'LinkedInTracker', 'WebhookTracker',
        ];
        foreach ($trackers as $tracker) {
            $this->assertFileExists(
                __DIR__ . '/../src/Trackers/' . $tracker . '.php',
                "Missing tracker: {$tracker}"
            );
        }
    }

    #[Test]
    public function trackerInterfaceExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Trackers/TrackerInterface.php');
        $content = file_get_contents(__DIR__ . '/../src/Trackers/TrackerInterface.php');
        $this->assertStringContainsString('track', $content);
        $this->assertStringContainsString('isEnabled', $content);
    }

    // ──────────────────────────────────────────────────────────────────
    // 12. TESTS + README
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function readmeExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../README.md');
        $content = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString('200.0.0', $content);
        $this->assertStringContainsString('Quick Start', $content);
        $this->assertStringContainsString('Features', $content);
    }

    #[Test]
    public function minimumTestCount(): void
    {
        $testFiles = glob(__DIR__ . '/../tests/**/*Test.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(440, count($testFiles), 'Should have 440+ test files');
    }

    #[Test]
    public function changelogExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../CHANGELOG.md');
    }

    #[Test]
    public function contributingGuideExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../CONTRIBUTING.md');
    }

    #[Test]
    public function licenseExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../LICENSE');
    }

    // ──────────────────────────────────────────────────────────────────
    // INFRASTRUCTURE QUALITY CHECKS
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function sourceFileCount(): void
    {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(870, count($srcFiles), 'Should have 870+ source files');
    }

    #[Test]
    public function serviceCount(): void
    {
        $services = glob(__DIR__ . '/../src/Services/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(390, count($services), 'Should have 390+ services');
    }

    #[Test]
    public function commandCount(): void
    {
        $commands = glob(__DIR__ . '/../src/Console/Commands/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(85, count($commands), 'Should have 85+ artisan commands');
    }

    #[Test]
    public function facadeExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Facades/Analytics.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
        $this->assertTrue($class->isFinal());
    }

    #[Test]
    public function analyticsManagerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/AnalyticsManager.php');
        $class = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $this->assertTrue($class->isFinal());
    }

    #[Test]
    public function serviceProviderExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    }

    #[Test]
    public function composerJsonIntegrity(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('200.0.0', $json['version']);
        $this->assertSame('^8.5', $json['require']['php']);
        $this->assertSame('MIT', $json['license']);
        $this->assertArrayHasKey('laravel', $json['extra']);
        $this->assertContains(
            'ZeroBoiler\\Analytics\\AnalyticsServiceProvider',
            $json['extra']['laravel']['providers'],
        );
    }

    #[Test]
    public function packageJsonIntegrity(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame('200.0.0', $json['version']);
        $this->assertSame('MIT', $json['license']);
    }

    #[Test]
    public function dtoVersionConsistency(): void
    {
        $this->assertSame(
            '200.0.0',
            \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
        );
    }

    #[Test]
    public function consentModeSupport(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/DTO/ConsentState.php');
        $this->assertFileExists(__DIR__ . '/../src/Middleware/ConsentGateMiddleware.php');
    }

    #[Test]
    public function bladeDirectivesExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Blade/Directives/AnalyticsDirectives.php');
    }

    #[Test]
    public function ciWorkflowExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../.github/workflows/ci.yml');
    }

    #[Test]
    public function phpstanConfigExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../phpstan.neon.dist');
    }

    #[Test]
    public function eventBlueprintSystemExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Blueprints/EventBlueprint.php');
        $this->assertFileExists(__DIR__ . '/../src/Blueprints/EventBlueprintRegistry.php');
    }

    #[Test]
    public function busSystemExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Bus/AnalyticsEventBus.php');
        $this->assertFileExists(__DIR__ . '/../src/Bus/AnalyticsEventDispatcher.php');
    }

    #[Test]
    public function pipelineSystemExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Pipeline/EventPipeline.php');
        $this->assertFileExists(__DIR__ . '/../src/Pipeline/EventDeduplicationFilter.php');
        $this->assertFileExists(__DIR__ . '/../src/Pipeline/ConsentFilter.php');
    }

    #[Test]
    public function middlewareStackExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Middleware/AnalyticsMiddlewareStack.php');
        $this->assertFileExists(__DIR__ . '/../src/Middleware/PiiSanitizationMiddleware.php');
    }

    #[Test]
    public function cdpSystemExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/CDP/CdpProfileService.php');
        $this->assertFileExists(__DIR__ . '/../src/CDP/CdpSegmentService.php');
        $this->assertFileExists(__DIR__ . '/../src/CDP/CdpTraitComputer.php');
    }
}
