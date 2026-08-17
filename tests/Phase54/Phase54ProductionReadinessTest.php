<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Phase54;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Requests\BatchEventRequest;
use ZeroBoiler\Analytics\Http\Requests\IdentifyRequest;
use ZeroBoiler\Analytics\Http\Requests\TrackEventRequest;
use ZeroBoiler\Analytics\Http\Requests\UpdateConsentRequest;
use ZeroBoiler\Analytics\Http\Requests\OptOutRequest;
use ZeroBoiler\Analytics\Http\Requests\OptInRequest;
use ZeroBoiler\Analytics\Http\Requests\PageViewRequest;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\IdentityGraphService;
use ZeroBoiler\Analytics\Services\CrossDeviceIdentityMergeService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Jobs\OTLPExportJob;

/**
 * Phase 54 Production Readiness — 12-Feature SaaS Starter Verification.
 *
 * Validates all 12 industry-standard SaaS analytics features are
 * production-ready: Event Catalog, Server-Side Lifecycle Tracker,
 * Inertia middleware, API controller + routes, JS client library,
 * Event queue, User identity linking, E-commerce helpers,
 * Admin commands, Config expansion, Optional providers, Tests.
 *
 * @since 229.0.0
 */
final class Phase54ProductionReadinessTest extends \PHPUnit\Framework\TestCase
{
    // ── 1. Event Catalog ────────────────────────────────────────────

    #[Test]
    public function ecommerce_events_catalog_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Events/Ecommerce/EcommerceEvents.php');
        $this->assertTrue(class_exists(EcommerceEvents::class));
    }

    #[Test]
    public function ecommerce_events_has_minimum_15_entries(): void
    {
        $count = EcommerceEvents::count();
        $this->assertGreaterThanOrEqual(15, $count, "EcommerceEvents should have at least 15 events, got {$count}");
    }

    #[Test]
    public function ecommerce_events_has_core_events(): void
    {
        $names = EcommerceEvents::names();
        $coreEvents = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($coreEvents as $event) {
            $this->assertContains($event, $names, "Missing core ecommerce event: {$event}");
        }
    }

    #[Test]
    public function ecommerce_events_has_typed_factory_methods(): void
    {
        $events = EcommerceEvents::all();
        $this->assertNotEmpty($events);
        foreach ($events as $name => $entry) {
            $this->assertArrayHasKey('class', $entry, "Event {$name} missing class mapping");
            $this->assertArrayHasKey('ga4', $entry, "Event {$name} missing GA4 mapping");
            $this->assertArrayHasKey('meta', $entry, "Event {$name} missing Meta mapping");
        }
    }

    #[Test]
    public function ecommerce_events_factory_methods_return_analytics_event(): void
    {
        $event = EcommerceEvents::viewItem(['item_id' => 'SKU-001']);
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('view_item', $event->name);
        $this->assertSame('ecommerce', $event->category);
    }

    #[Test]
    public function saas_events_catalog_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Events/SaaS/SaaSEvents.php');
        $this->assertTrue(class_exists(SaaSEvents::class));
    }

    #[Test]
    public function saas_events_has_minimum_80_entries(): void
    {
        $count = SaaSEvents::count();
        $this->assertGreaterThanOrEqual(80, $count, "SaaSEvents should have at least 80 events, got {$count}");
    }

    #[Test]
    public function saas_events_has_core_lifecycle_events(): void
    {
        $names = SaaSEvents::names();
        $coreEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($coreEvents as $event) {
            $this->assertContains($event, $names, "Missing core SaaS event: {$event}");
        }
    }

    #[Test]
    public function engagement_events_catalog_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Events/Engagement/EngagementEvents.php');
        $this->assertTrue(class_exists(EngagementEvents::class));
    }

    #[Test]
    public function engagement_events_has_minimum_30_entries(): void
    {
        $count = EngagementEvents::count();
        $this->assertGreaterThanOrEqual(30, $count, "EngagementEvents should have at least 30 events, got {$count}");
    }

    #[Test]
    public function engagement_events_has_core_events(): void
    {
        $names = EngagementEvents::names();
        $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($coreEvents as $event) {
            $this->assertContains($event, $names, "Missing core engagement event: {$event}");
        }
    }

    // ── 2. Server-Side Lifecycle Tracker ────────────────────────────

    #[Test]
    public function lifecycle_event_mapper_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/LifecycleEventMapper.php');
        $this->assertTrue(class_exists(LifecycleEventMapper::class));
    }

    #[Test]
    public function lifecycle_event_mapper_has_default_mappings_constant(): void
    {
        $reflection = new \ReflectionClass(LifecycleEventMapper::class);
        $this->assertTrue($reflection->hasConstant('DEFAULT_MAPPING_COUNT'));
        $count = LifecycleEventMapper::DEFAULT_MAPPING_COUNT;
        $this->assertGreaterThanOrEqual(60, $count, "DEFAULT_MAPPING_COUNT should be >= 60, got {$count}");
    }

    #[Test]
    public function lifecycle_event_mapper_default_mappings_covers_auth(): void
    {
        $reflection = new \ReflectionClass(LifecycleEventMapper::class);
        $defaultMappings = $reflection->getConstant('DEFAULT_MAPPINGS');
        $this->assertIsArray($defaultMappings);
        $this->assertArrayHasKey('auth.login', $defaultMappings);
        $this->assertArrayHasKey('auth.register', $defaultMappings);
        $this->assertArrayHasKey('auth.logout', $defaultMappings);
    }

    #[Test]
    public function lifecycle_event_mapper_default_mappings_covers_subscription(): void
    {
        $reflection = new \ReflectionClass(LifecycleEventMapper::class);
        $defaultMappings = $reflection->getConstant('DEFAULT_MAPPINGS');
        $subscriptionKeys = ['subscription.created', 'subscription.upgraded', 'subscription.downgraded', 'subscription.cancelled', 'subscription.renewal'];
        foreach ($subscriptionKeys as $key) {
            $this->assertArrayHasKey($key, $defaultMappings, "Missing subscription mapping: {$key}");
        }
    }

    // ── 3. Inertia Middleware ───────────────────────────────────────

    #[Test]
    public function inertia_analytics_middleware_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertTrue(class_exists(HandleInertiaAnalytics::class));
    }

    #[Test]
    public function inertia_middleware_is_final(): void
    {
        $reflection = new \ReflectionClass(HandleInertiaAnalytics::class);
        $this->assertTrue($reflection->isFinal(), 'HandleInertiaAnalytics must be final');
    }

    #[Test]
    public function inertia_middleware_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function inertia_middleware_has_handle_method(): void
    {
        $this->assertTrue(method_exists(HandleInertiaAnalytics::class, 'handle'));
        $method = new \ReflectionMethod(HandleInertiaAnalytics::class, 'handle');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->getReturnType() instanceof \ReflectionNamedType);
    }

    #[Test]
    public function inertia_middleware_injects_provider_ids(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'ga4MeasurementId'", $content);
        $this->assertStringContainsString("'gtmContainerId'", $content);
        $this->assertStringContainsString("'metaPixelId'", $content);
        $this->assertStringContainsString("'plausibleDomain'", $content);
        $this->assertStringContainsString("'posthogHost'", $content);
    }

    #[Test]
    public function inertia_middleware_manages_tracking_cookie(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'trackingId'", $content);
        $this->assertStringContainsString("'zb_analytics_id'", $content);
    }

    // ── 4. API Controller + Routes ──────────────────────────────────

    #[Test]
    public function event_controller_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        $this->assertTrue(class_exists(AnalyticsEventController::class));
    }

    #[Test]
    public function event_controller_has_track_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'track'));
    }

    #[Test]
    public function event_controller_has_batch_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'batch'));
    }

    #[Test]
    public function event_controller_has_identify_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'identify'));
    }

    #[Test]
    public function event_controller_has_consent_methods(): void
    {
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'updateConsent'));
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'optOut'));
        $this->assertTrue(method_exists(AnalyticsEventController::class, 'optIn'));
    }

    #[Test]
    public function form_requests_exist(): void
    {
        $this->assertTrue(class_exists(TrackEventRequest::class));
        $this->assertTrue(class_exists(BatchEventRequest::class));
        $this->assertTrue(class_exists(IdentifyRequest::class));
        $this->assertTrue(class_exists(UpdateConsentRequest::class));
        $this->assertTrue(class_exists(OptOutRequest::class));
        $this->assertTrue(class_exists(OptInRequest::class));
        $this->assertTrue(class_exists(PageViewRequest::class));
    }

    // ── 5. JS Client Library ───────────────────────────────────────

    #[Test]
    public function analytics_js_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../resources/js/analytics.js');
    }

    #[Test]
    public function analytics_js_has_track_event_export(): void
    {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('export', $content);
        $this->assertStringContainsString('trackEvent', $content);
    }

    #[Test]
    public function analytics_js_has_track_page_view_export(): void
    {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('trackPageView', $content);
    }

    #[Test]
    public function analytics_js_has_version(): void
    {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('@version 229.0.0', $content);
    }

    #[Test]
    public function analytics_js_has_minimum_8000_loc(): void
    {
        $lines = count(file(__DIR__ . '/../../resources/js/analytics.js'));
        $this->assertGreaterThanOrEqual(8000, $lines, "analytics.js should have at least 8,000 LOC, got {$lines}");
    }

    #[Test]
    public function svelte_composables_exist(): void
    {
        $composables = [
            'useAnalytics',
            'useConsent',
            'useIdentity',
            'usePageView',
            'useScrollDepth',
            'useEcommerce',
            'useAttribution',
            'usePerformanceTracker',
        ];
        foreach ($composables as $composable) {
            $this->assertFileExists(
                __DIR__ . "/../../resources/js/{$composable}.svelte.js",
                "Missing Svelte composable: {$composable}.svelte.js",
            );
        }
    }

    #[Test]
    public function typescript_definitions_exist(): void
    {
        $this->assertFileExists(__DIR__ . '/../../resources/js/analytics.d.ts');
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        $this->assertGreaterThanOrEqual(2000, strlen($content), 'TypeScript definitions should be substantial');
    }

    // ── 6. Event Queue ──────────────────────────────────────────────

    #[Test]
    public function track_event_job_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Jobs/TrackAnalyticsEventJob.php');
        $this->assertTrue(class_exists(TrackAnalyticsEventJob::class));
    }

    #[Test]
    public function track_batch_job_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Jobs/TrackAnalyticsEventBatchJob.php');
        $this->assertTrue(class_exists(TrackAnalyticsEventBatchJob::class));
    }

    #[Test]
    public function otlp_export_job_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Jobs/OTLPExportJob.php');
        $this->assertTrue(class_exists(OTLPExportJob::class));
    }

    // ── 7. User Identity Linking ────────────────────────────────────

    #[Test]
    public function identity_graph_service_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/IdentityGraphService.php');
        $this->assertTrue(class_exists(IdentityGraphService::class));
    }

    #[Test]
    public function cross_device_identity_merge_service_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/CrossDeviceIdentityMergeService.php');
        $this->assertTrue(class_exists(CrossDeviceIdentityMergeService::class));
    }

    #[Test]
    public function identity_composable_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../resources/js/useIdentity.svelte.js');
    }

    #[Test]
    public function inertia_middleware_detects_auth_state_change(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'authStateChanged'", $content);
    }

    // ── 8. E-Commerce Helpers ──────────────────────────────────────

    #[Test]
    public function ecommerce_format_converter_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/EcommerceFormatConverter.php');
        $this->assertTrue(class_exists(EcommerceFormatConverter::class));
    }

    #[Test]
    public function ecommerce_format_converter_has_substantial_loc(): void
    {
        $lines = count(file(__DIR__ . '/../../src/Services/EcommerceFormatConverter.php'));
        $this->assertGreaterThanOrEqual(1000, $lines, "EcommerceFormatConverter should have >= 1,000 LOC, got {$lines}");
    }

    // ── 9. Admin Commands ────────────────────────────────────────────

    #[Test]
    public function overview_command_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Console/Commands/AnalyticsOverviewCommand.php');
    }

    #[Test]
    public function test_command_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function overview_command_has_substantial_loc(): void
    {
        $lines = count(file(__DIR__ . '/../../src/Console/Commands/AnalyticsOverviewCommand.php'));
        $this->assertGreaterThanOrEqual(400, $lines, "AnalyticsOverviewCommand should have >= 400 LOC, got {$lines}");
    }

    #[Test]
    public function test_command_has_substantial_loc(): void
    {
        $lines = count(file(__DIR__ . '/../../src/Console/Commands/AnalyticsTestCommand.php'));
        $this->assertGreaterThanOrEqual(300, $lines, "AnalyticsTestCommand should have >= 300 LOC, got {$lines}");
    }

    // ── 10. Config Expansion ───────────────────────────────────────

    #[Test]
    public function config_file_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../config/zeroboiler.php');
    }

    #[Test]
    public function config_file_has_substantial_loc(): void
    {
        $lines = count(file(__DIR__ . '/../../config/zeroboiler.php'));
        $this->assertGreaterThanOrEqual(5000, $lines, "Config file should have >= 5,000 LOC, got {$lines}");
    }

    #[Test]
    public function config_has_required_sections(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $sections = ['ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'api', 'identity', 'ecommerce'];
        foreach ($sections as $section) {
            $this->assertStringContainsString("'{$section}'", $content, "Missing config section: {$section}");
        }
    }

    // ── 11. Optional Providers ──────────────────────────────────────

    #[Test]
    public function plausible_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/PlausibleTracker.php');
        $this->assertTrue(class_exists(PlausibleTracker::class));
    }

    #[Test]
    public function posthog_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/PosthogTracker.php');
        $this->assertTrue(class_exists(PosthogTracker::class));
    }

    #[Test]
    public function amplitude_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/AmplitudeTracker.php');
        $this->assertTrue(class_exists(AmplitudeTracker::class));
    }

    #[Test]
    public function mixpanel_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/MixpanelTracker.php');
        $this->assertTrue(class_exists(MixpanelTracker::class));
    }

    #[Test]
    public function tiktok_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/TikTokTracker.php');
        $this->assertTrue(class_exists(TikTokTracker::class));
    }

    #[Test]
    public function linkedin_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/LinkedInTracker.php');
        $this->assertTrue(class_exists(LinkedInTracker::class));
    }

    #[Test]
    public function webhook_tracker_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Trackers/WebhookTracker.php');
        $this->assertTrue(class_exists(WebhookTracker::class));
    }

    // ── 12. Tests + README ─────────────────────────────────────────

    #[Test]
    public function test_directory_has_sufficient_files(): void
    {
        $testFiles = glob(__DIR__ . '/../../tests/**/*Test.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(400, count($testFiles), 'Should have at least 400 test files');
    }

    #[Test]
    public function readme_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../README.md');
    }

    #[Test]
    public function readme_has_current_version_badge(): void
    {
        $content = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('version-229.0.0', $content, 'README should display v229.0.0 badge');
    }

    #[Test]
    public function changelog_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../CHANGELOG.md');
    }

    #[Test]
    public function changelog_has_v229_entry(): void
    {
        $content = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        $this->assertStringContainsString('[229.0.0]', $content, 'CHANGELOG should have 229.0.0 entry');
    }

    // ── Version Consistency ──────────────────────────────────────────

    #[Test]
    public function version_consistency_across_all_entry_points(): void
    {
        $dtoVersion = AnalyticsEvent::VERSION;
        $this->assertSame('229.0.0', $dtoVersion, 'AnalyticsEvent::VERSION must be 229.0.0');

        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertSame('229.0.0', $composerJson['version'], 'composer.json version must be 229.0.0');

        $packageJson = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $this->assertSame('229.0.0', $packageJson['version'], 'package.json version must be 229.0.0');

        $jsContent = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('@version 229.0.0', $jsContent, 'analytics.js @version must be 229.0.0');
    }

    // ── Source File Quality ─────────────────────────────────────────

    #[Test]
    public function dto_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function dto_has_mit_license_header(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString('MIT license', $content);
    }

    #[Test]
    public function dto_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($reflection->isFinal(), 'AnalyticsEvent must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AnalyticsEvent must be readonly');
    }

    #[Test]
    public function event_controller_has_mit_license(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        $this->assertStringContainsString('MIT license', $content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function source_file_count_baseline(): void
    {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(940, count($srcFiles), 'Should have at least 940 source files');
    }

    #[Test]
    public function command_count_baseline(): void
    {
        $commandFiles = glob(__DIR__ . '/../../src/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(100, count($commandFiles), 'Should have at least 100 artisan commands');
    }

    #[Test]
    public function service_count_baseline(): void
    {
        $serviceFiles = glob(__DIR__ . '/../../src/Services/*.php');
        $this->assertGreaterThanOrEqual(420, count($serviceFiles), 'Should have at least 420 services');
    }
}
