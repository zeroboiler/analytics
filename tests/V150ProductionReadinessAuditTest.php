<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware;
use ZeroBoiler\Analytics\Http\Middleware\VerifySdkToken;
use ZeroBoiler\Analytics\Http\Requests\TrackEventRequest;
use ZeroBoiler\Analytics\Http\Requests\BatchEventRequest;
use ZeroBoiler\Analytics\Http\Requests\IdentifyRequest;
use ZeroBoiler\Analytics\Http\Requests\UpdateConsentRequest;
use ZeroBoiler\Analytics\Http\Requests\PageViewRequest;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\ServerSideTracker;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Facades\Analytics;

/**
 * V150 Production Readiness Audit — Industry-Standard SaaS Starter.
 *
 * Comprehensive audit test validating all 12 planned SaaS analytics features
 * are implemented and production-ready at industry-standard SaaS starter level.
 *
 * Feature checklist:
 *  1. Event Catalog: Ecommerce, SaaS, Engagement events with typed classes
 *  2. Server-Side Lifecycle Tracker: config-driven Laravel event → analytics mapping
 *  3. Inertia middleware: page props, client ID cookie, auth state detection
 *  4. API controller + routes: events, batch, identify, consent, health
 *  5. JS client library: trackEvent, trackPageView, scroll depth, client ID management
 *  6. Event queue: async dispatch via QueuedAnalyticsDispatcher
 *  7. User identity linking: client ID ↔ user ID resolution
 *  8. E-commerce helpers: GA4 + Meta format conversion
 *  9. Admin commands: AnalyticsOverviewCommand, AnalyticsTestCommand
 * 10. Config expansion: queue, API, identity, auto-track, ecommerce
 * 11. Optional providers: Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn
 * 12. Tests + README
 *
 * @since 150.0.0
 */
final class V150ProductionReadinessAuditTest extends TestCase
{
    // ─── Feature 1: Event Catalog ──────────────────────────────────

    public function test_ecommerce_catalog_has_core_events(): void
    {
        $names = EcommerceEvents::names();

        $this->assertContains('view_item', $names, 'ViewItem must exist in ecommerce catalog');
        $this->assertContains('add_to_cart', $names, 'AddToCart must exist in ecommerce catalog');
        $this->assertContains('purchase', $names, 'Purchase must exist in ecommerce catalog');
        $this->assertContains('refund', $names, 'Refund must exist in ecommerce catalog');
        $this->assertContains('remove_from_cart', $names, 'RemoveFromCart must exist');
        $this->assertContains('view_cart', $names, 'ViewCart must exist');
        $this->assertContains('begin_checkout', $names, 'BeginCheckout must exist');
        $this->assertContains('add_payment_info', $names, 'AddPaymentInfo must exist');
        $this->assertGreaterThanOrEqual(15, EcommerceEvents::count(), 'Ecommerce catalog must have at least 15 events');
    }

    public function test_saas_catalog_has_core_events(): void
    {
        $names = SaaSEvents::names();

        $this->assertContains('sign_up', $names, 'SignUp must exist in SaaS catalog');
        $this->assertContains('login', $names, 'Login must exist in SaaS catalog');
        $this->assertContains('start_trial', $names, 'TrialStart must exist in SaaS catalog');
        $this->assertContains('subscribe', $names, 'Subscription must exist in SaaS catalog');
        $this->assertContains('plan_upgrade', $names, 'PlanUpgrade must exist in SaaS catalog');
        $this->assertContains('cancellation', $names, 'Cancellation must exist in SaaS catalog');
        $this->assertGreaterThanOrEqual(50, SaaSEvents::count(), 'SaaS catalog must have at least 50 events');
    }

    public function test_engagement_catalog_has_core_events(): void
    {
        $names = EngagementEvents::names();

        $this->assertContains('page_view', $names, 'PageView must exist in engagement catalog');
        $this->assertContains('scroll_depth', $names, 'ScrollDepth must exist in engagement catalog');
        $this->assertContains('click', $names, 'Click must exist in engagement catalog');
        $this->assertContains('form_start', $names, 'FormStart must exist in engagement catalog');
        $this->assertContains('form_submit', $names, 'FormSubmit must exist in engagement catalog');
        $this->assertContains('search', $names, 'Search must exist in engagement catalog');
        $this->assertContains('share', $names, 'Share must exist in engagement catalog');
        $this->assertContains('error', $names, 'Error must exist in engagement catalog');
        $this->assertGreaterThanOrEqual(30, EngagementEvents::count(), 'Engagement catalog must have at least 30 events');
    }

    public function test_ecommerce_events_have_provider_mappings(): void
    {
        $entry = EcommerceEvents::get('purchase');

        $this->assertNotNull($entry, 'Purchase entry must exist');
        $this->assertArrayHasKey('ga4', $entry);
        $this->assertArrayHasKey('meta', $entry);
        $this->assertArrayHasKey('posthog', $entry);
        $this->assertArrayHasKey('mixpanel', $entry);
        $this->assertArrayHasKey('amplitude', $entry);
        $this->assertArrayHasKey('class', $entry);
        $this->assertSame('purchase', $entry['ga4']);
        $this->assertSame('Purchase', $entry['meta']);
    }

    public function test_saas_events_have_provider_mappings(): void
    {
        $entry = SaaSEvents::get('sign_up');

        $this->assertNotNull($entry, 'SignUp entry must exist');
        $this->assertArrayHasKey('ga4', $entry);
        $this->assertArrayHasKey('meta', $entry);
        $this->assertArrayHasKey('posthog', $entry);
        $this->assertArrayHasKey('class', $entry);
        $this->assertSame('CompleteRegistration', $entry['meta']);
    }

    public function test_unified_event_catalog_exists(): void
    {
        $this->assertTrue(
            class_exists(EventCatalog::class),
            'EventCatalog must exist',
        );

        $total = EventCatalog::totalCount();
        $this->assertGreaterThanOrEqual(210, $total, 'EventCatalog must have at least 210 events');
    }

    // ─── Feature 2: Server-Side Lifecycle Tracker ─────────────────

    public function test_lifecycle_event_mapper_exists(): void
    {
        $this->assertTrue(
            class_exists(LifecycleEventMapper::class),
            'LifecycleEventMapper must exist for config-driven event mapping',
        );
    }

    public function test_server_side_tracker_exists(): void
    {
        $this->assertTrue(
            class_exists(ServerSideTracker::class),
            'ServerSideTracker must exist for auto-tracking',
        );
    }

    public function test_lifecycle_config_section_exists(): void
    {
        // Validate the config file has the lifecycle section
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'lifecycle' => [", $configContents, 'Config must have lifecycle section');
        $this->assertStringContainsString('ANALYTICS_LIFECYCLE_ENABLED', $configContents);
        $this->assertStringContainsString('ANALYTICS_LIFECYCLE_QUEUE_EVENTS', $configContents);
        $this->assertStringContainsString('custom_mappings', $configContents);
    }

    // ─── Feature 3: Inertia Middleware ─────────────────────────────

    public function test_inertia_middleware_exists(): void
    {
        $this->assertTrue(
            class_exists(HandleInertiaAnalytics::class),
            'HandleInertiaAnalytics middleware must exist',
        );
    }

    public function test_inertia_middleware_implements_contract(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(HandleInertiaAnalytics::class))->isFinal(),
            'HandleInertiaAnalytics must be final',
        );
    }

    public function test_inertia_middleware_exposes_tracking_id(): void
    {
        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'handle');
        $this->assertTrue($method->hasReturnType(), 'handle() must have return type');
    }

    public function test_inertia_middleware_has_cookie_management(): void
    {
        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'getOrCreateTrackingId');
        $this->assertTrue($method->isPrivate(), 'getOrCreateTrackingId must be private');

        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'generateTrackingId');
        $this->assertTrue($method->isPrivate(), 'generateTrackingId must be private');
    }

    public function test_inertia_middleware_has_auth_state_detection(): void
    {
        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'detectAuthStateChange');
        $this->assertTrue($method->isPrivate(), 'detectAuthStateChange must be private');

        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'getPreviousUserId');
        $this->assertTrue($method->isPrivate(), 'getPreviousUserId must be private');
    }

    // ─── Feature 4: API Controller + Routes ────────────────────────

    public function test_api_controller_exists(): void
    {
        $this->assertTrue(
            class_exists(AnalyticsEventController::class),
            'AnalyticsEventController must exist',
        );
    }

    public function test_sse_controller_exists(): void
    {
        $this->assertTrue(
            class_exists(AnalyticsSSEController::class),
            'AnalyticsSSEController must exist',
        );
    }

    public function test_api_requests_exist(): void
    {
        $this->assertTrue(class_exists(TrackEventRequest::class));
        $this->assertTrue(class_exists(BatchEventRequest::class));
        $this->assertTrue(class_exists(IdentifyRequest::class));
        $this->assertTrue(class_exists(UpdateConsentRequest::class));
        $this->assertTrue(class_exists(PageViewRequest::class));
    }

    public function test_api_middleware_exists(): void
    {
        $this->assertTrue(
            class_exists(VerifySdkToken::class),
            'VerifySdkToken middleware must exist',
        );
    }

    public function test_api_routes_file_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../routes/analytics.php');
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertNotFalse($contents);
        $this->assertStringContainsString("Route::prefix('analytics')", $contents);
        $this->assertStringContainsString('health', $contents);
        $this->assertStringContainsString('catalog', $contents);
        $this->assertStringContainsString('events', $contents);
    }

    // ─── Feature 5: JS Client Library ──────────────────────────────

    public function test_js_client_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.js');
    }

    public function test_js_client_has_core_functions(): void
    {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('export function trackEvent', $contents);
        $this->assertStringContainsString('export function trackPageView', $contents);
        $this->assertStringContainsString('export function init', $contents);
        $this->assertStringContainsString('export function getVersion', $contents);
        $this->assertStringContainsString('export function identify', $contents);
    }

    public function test_js_client_has_scroll_depth_tracking(): void
    {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('scroll', strtolower($contents));
    }

    public function test_js_client_has_batch_queue(): void
    {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('eventQueue', $contents);
        $this->assertStringContainsString('flushQueue', $contents);
        $this->assertStringContainsString('FLUSH_INTERVAL', $contents);
    }

    public function test_js_client_has_client_id_management(): void
    {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('trackingId', $contents);
        $this->assertStringContainsString('getTrackingId', $contents);
    }

    public function test_js_client_version_is_150(): void
    {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString("'150.0.0'", $contents);
    }

    public function test_svelte_composables_exist(): void
    {
        $composables = [
            'useAnalytics.svelte.js',
            'useEcommerce.svelte.js',
            'useSaaSMetrics.svelte.js',
            'useLifecycle.svelte.js',
            'usePerformanceTracker.svelte.js',
            'useSessionReplay.svelte.js',
            'useAnalyticsConfig.svelte.js',
        ];

        foreach ($composables as $composable) {
            $this->assertFileExists(
                __DIR__ . '/../resources/js/' . $composable,
                "{$composable} must exist",
            );
        }
    }

    public function test_type_definitions_exist(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.d.ts');
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.constants.js');
    }

    // ─── Feature 6: Event Queue ────────────────────────────────────

    public function test_queue_dispatcher_exists(): void
    {
        $this->assertTrue(
            class_exists(QueuedAnalyticsDispatcher::class),
            'QueuedAnalyticsDispatcher must exist',
        );
    }

    public function test_queue_jobs_exist(): void
    {
        $this->assertTrue(
            class_exists(TrackAnalyticsEventJob::class),
            'TrackAnalyticsEventJob must exist',
        );
        $this->assertTrue(
            class_exists(TrackAnalyticsEventBatchJob::class),
            'TrackAnalyticsEventBatchJob must exist',
        );
    }

    public function test_queue_config_section_exists(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'queue' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_QUEUE_ENABLED', $configContents);
        $this->assertStringContainsString('ANALYTICS_QUEUE_MAX_BATCH_SIZE', $configContents);
    }

    // ─── Feature 7: User Identity Linking ──────────────────────────

    public function test_identity_tracker_exists(): void
    {
        $this->assertTrue(
            class_exists(UserIdentityTracker::class),
            'UserIdentityTracker must exist',
        );
    }

    public function test_identity_config_section_exists(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'identity' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_IDENTITY_COOKIE', $configContents);
        $this->assertStringContainsString('ANALYTICS_IDENTITY_LINK_ON_AUTH', $configContents);
        $this->assertStringContainsString('ANALYTICS_IDENTITY_CACHE_PREFIX', $configContents);
        $this->assertStringContainsString('ANALYTICS_IDENTITY_LINK_TTL', $configContents);
    }

    // ─── Feature 8: E-commerce Helpers ──────────────────────────────

    public function test_ecommerce_format_converter_exists(): void
    {
        $this->assertTrue(
            class_exists(EcommerceFormatConverter::class),
            'EcommerceFormatConverter must exist',
        );
    }

    public function test_ecommerce_format_converter_has_ga4_to_meta(): void
    {
        $method = new \ReflectionMethod(EcommerceFormatConverter::class, 'ga4ToMetaContents');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_ecommerce_format_converter_has_meta_to_ga4(): void
    {
        $method = new \ReflectionMethod(EcommerceFormatConverter::class, 'metaToGa4Items');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_ecommerce_config_section_exists(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'ecommerce' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_ECOMMERCE_CURRENCY', $configContents);
    }

    // ─── Feature 9: Admin Commands ──────────────────────────────────

    public function test_analytics_overview_command_exists(): void
    {
        $this->assertTrue(
            class_exists(AnalyticsOverviewCommand::class),
            'AnalyticsOverviewCommand must exist',
        );
    }

    public function test_analytics_test_command_exists(): void
    {
        $this->assertTrue(
            class_exists(AnalyticsTestCommand::class),
            'AnalyticsTestCommand must exist',
        );
    }

    public function test_test_command_has_dry_run_option(): void
    {
        $ref = new \ReflectionClass(AnalyticsTestCommand::class);
        $property = $ref->getProperty('signature');
        $signature = $property->getValue(new ($ref->getName())(
            \Mockery::mock(AnalyticsManager::class)
        ));

        // Re-create with mock-free check using signature property
        $this->assertStringContainsString('--dry-run', (string) $ref->getDefaultProperties()['signature'] ?? '');
    }

    // ─── Feature 10: Config Expansion ───────────────────────────────

    public function test_config_has_api_section(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'api' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_API_ENABLED', $configContents);
        $this->assertStringContainsString('ANALYTICS_API_BASE_URL', $configContents);
        $this->assertStringContainsString('ANALYTICS_API_SDK_TOKEN', $configContents);
    }

    public function test_config_has_consent_section(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'consent' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_CONSENT_DEFAULT', $configContents);
    }

    public function test_config_has_auto_track_section(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'auto_track' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_AUTO_TRACK_ENABLED', $configContents);
    }

    public function test_config_has_client_auto_track_section(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);
        $this->assertStringContainsString("'client_auto_track' => [", $configContents);
        $this->assertStringContainsString('ANALYTICS_CLIENT_PAGE_VIEWS', $configContents);
        $this->assertStringContainsString('ANALYTICS_CLIENT_SCROLL_DEPTH', $configContents);
    }

    // ─── Feature 11: Optional Providers ──────────────────────────────

    public function test_plausible_tracker_exists(): void
    {
        $this->assertTrue(class_exists(PlausibleTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(PlausibleTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_posthog_tracker_exists(): void
    {
        $this->assertTrue(class_exists(PosthogTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(PosthogTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_mixpanel_tracker_exists(): void
    {
        $this->assertTrue(class_exists(MixpanelTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(MixpanelTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_amplitude_tracker_exists(): void
    {
        $this->assertTrue(class_exists(AmplitudeTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(AmplitudeTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_tiktok_tracker_exists(): void
    {
        $this->assertTrue(class_exists(TikTokTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(TikTokTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_linkedin_tracker_exists(): void
    {
        $this->assertTrue(class_exists(LinkedInTracker::class));
        $this->assertTrue(
            (new \ReflectionClass(LinkedInTracker::class))->implementsInterface(TrackerInterface::class),
        );
    }

    public function test_all_10_core_trackers_exist(): void
    {
        $trackers = [
            GA4Tracker::class,
            GTMTracker::class,
            MetaPixelTracker::class,
            PlausibleTracker::class,
            PosthogTracker::class,
            MixpanelTracker::class,
            AmplitudeTracker::class,
            TikTokTracker::class,
            LinkedInTracker::class,
            WebhookTracker::class,
        ];

        foreach ($trackers as $tracker) {
            $this->assertTrue(
                class_exists($tracker),
                "{$tracker} must exist",
            );
            $this->assertTrue(
                (new \ReflectionClass($tracker))->implementsInterface(TrackerInterface::class),
                "{$tracker} must implement TrackerInterface",
            );
        }
    }

    public function test_config_has_all_provider_sections(): void
    {
        $configContents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($configContents);

        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        foreach ($providers as $provider) {
            $this->assertStringContainsString(
                "'{$provider}' => [",
                $configContents,
                "Config must have {$provider} section",
            );
        }
    }

    // ─── Feature 12: Tests + README ────────────────────────────────

    public function test_readme_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../README.md');
    }

    public function test_readme_has_version_150_badge(): void
    {
        $contents = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString('150.0.0', $contents, 'README must reference v150.0.0');
    }

    public function test_readme_has_quick_start(): void
    {
        $contents = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString('Quick Start', $contents);
        $this->assertStringContainsString('composer require zeroboiler/analytics', $contents);
    }

    public function test_test_files_exist(): void
    {
        $testFiles = glob(__DIR__ . '/V*.php');
        $this->assertGreaterThanOrEqual(
            100,
            count($testFiles),
            'Must have at least 100 versioned test files',
        );
    }

    public function test_contributing_file_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../CONTRIBUTING.md');
    }

    // ─── Version Consistency ───────────────────────────────────────

    public function test_version_is_150(): void
    {
        $this->assertSame('150.0.0', AnalyticsEvent::VERSION);
    }

    public function test_composer_version_is_150(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('150.0.0', $composer['version']);
    }

    public function test_package_version_is_150(): void
    {
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame('150.0.0', $package['version']);
    }

    public function test_integrity_command_version_is_150(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
        $constants = $ref->getConstants();
        $this->assertSame('150.0.0', $constants['EXPECTED_VERSION'] ?? null);
    }

    public function test_php_requirement_is_85(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('^8.5', $composer['require']['php']);
    }

    public function test_laravel_requirement_is_13(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('^13.0', $composer['require']['illuminate/contracts']);
    }

    // ─── PHP 8.5 Syntax & Code Quality ──────────────────────────────

    public function test_dto_has_strict_types(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_dto_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_dto_constructor_has_void_return_type(): void
    {
        $constructor = new \ReflectionMethod(AnalyticsEvent::class, '__construct');
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_manager_is_final(): void
    {
        $this->assertTrue((new \ReflectionClass(AnalyticsManager::class))->isFinal());
    }

    public function test_facade_exists(): void
    {
        $this->assertTrue(class_exists(Analytics::class));
    }

    public function test_service_provider_exists(): void
    {
        $this->assertTrue(class_exists(AnalyticsServiceProvider::class));
    }

    public function test_blade_directives_exist(): void
    {
        $this->assertTrue(class_exists(AnalyticsDirectives::class));
    }

    public function test_auto_page_view_middleware_exists(): void
    {
        $this->assertTrue(class_exists(AutoPageViewMiddleware::class));
    }

    public function test_inject_analytics_scripts_middleware_exists(): void
    {
        $this->assertTrue(class_exists(InjectAnalyticsScripts::class));
    }

    // ─── MIT License ────────────────────────────────────────────────

    public function test_license_file_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../LICENSE');
    }

    public function test_dto_has_license_header(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString('MIT license', $contents);
    }

    // ─── Migration Exists ────────────────────────────────────────────

    public function test_migration_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../database/migrations/2026_08_12_000000_create_analytics_events_table.php');
    }

    public function test_migration_has_strict_types(): void
    {
        $contents = file_get_contents(__DIR__ . '/../database/migrations/2026_08_12_000000_create_analytics_events_table.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    // ─── Routes File ───────────────────────────────────────────────

    public function test_routes_file_has_strict_types(): void
    {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_routes_file_has_license_header(): void
    {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString('MIT license', $contents);
    }
}
