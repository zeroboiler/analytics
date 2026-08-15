<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 39 — Industry-Standard SaaS Analytics Starter — Full Feature Audit.
 *
 * Validates that all 12 planned SaaS analytics features are present, well-structured,
 * and meet industry standards. This is a source-level audit (no runtime).
 *
 * Checklist:
 *  1. Event Catalog: EcommerceEvents, SaaSEvents, EngagementEvents with required events
 *  2. Server-Side Lifecycle Tracker: config-driven mapping
 *  3. Inertia middleware: page props, client ID cookie
 *  4. API controller + routes: events, batch, identify, consent
 *  5. Svelte JS client library: trackEvent, trackPageView, scroll depth, client ID
 *  6. Event queue: async dispatch
 *  7. User identity linking: client ID ↔ user ID
 *  8. E-commerce helpers: GA4 + Meta format conversion
 *  9. Admin commands: AnalyticsOverviewCommand, AnalyticsTestCommand
 * 10. Config expansion: queue, API, identity, auto-track, ecommerce
 * 11. Optional providers: Plausible, PostHog trackers
 * 12. Tests + README: non-empty test directory and README
 *
 * @since 148.0.0
 */
final class Phase39SaaSIndustryStandardAuditTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';

    // ── 1. Event Catalog ──────────────────────────────────────────────────

    #[Test]
    public function ecommerce_catalog_exists_and_contains_required_events(): void
    {
        $file = self::PKG_ROOT . '/src/Events/Ecommerce/EcommerceEvents.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertNotEmpty($content);

        $required = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($required as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing ecommerce event: {$event}");
        }

        // Typed event classes exist
        $classes = [
            'src/Events/Ecommerce/ViewItemEvent.php',
            'src/Events/Ecommerce/AddToCartEvent.php',
            'src/Events/Ecommerce/PurchaseEvent.php',
            'src/Events/Ecommerce/RefundEvent.php',
        ];
        foreach ($classes as $cls) {
            $this->assertFileExists(self::PKG_ROOT . '/' . $cls, "Missing: {$cls}");
        }
    }

    #[Test]
    public function saas_catalog_exists_and_contains_required_events(): void
    {
        $file = self::PKG_ROOT . '/src/Events/SaaS/SaaSEvents.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $required = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($required as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing SaaS event: {$event}");
        }

        // Typed event classes exist
        $classes = [
            'src/Events/SaaS/SignUpEvent.php',
            'src/Events/SaaS/LoginEvent.php',
            'src/Events/SaaS/TrialStartEvent.php',
            'src/Events/SaaS/SubscriptionEvent.php',
            'src/Events/SaaS/PlanUpgradeEvent.php',
            'src/Events/SaaS/CancellationEvent.php',
        ];
        foreach ($classes as $cls) {
            $this->assertFileExists(self::PKG_ROOT . '/' . $cls, "Missing: {$cls}");
        }
    }

    #[Test]
    public function engagement_catalog_exists_and_contains_required_events(): void
    {
        $file = self::PKG_ROOT . '/src/Events/Engagement/EngagementEvents.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($required as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing engagement event: {$event}");
        }
    }

    #[Test]
    public function unified_event_catalog_exists(): void
    {
        $file = self::PKG_ROOT . '/src/Events/EventCatalog.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertStringContainsString('EcommerceEvents', $content);
        $this->assertStringContainsString('SaaSEvents', $content);
        $this->assertStringContainsString('EngagementEvents', $content);
        $this->assertStringContainsString('SecurityEvents', $content);
        $this->assertStringContainsString('InfrastructureEvents', $content);
        $this->assertStringContainsString('MarketingEvents', $content);
    }

    #[Test]
    public function event_catalog_classes_use_strict_types(): void
    {
        $files = [
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
            'src/Events/EventCatalog.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertStringContainsString('declare(strict_types=1)', $content, "Missing strict_types in {$file}");
        }
    }

    #[Test]
    public function event_catalog_classes_are_final(): void
    {
        $files = [
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
            'src/Events/EventCatalog.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertMatchesRegularExpression('/final\s+class\s+/', $content, "Class not final in {$file}");
        }
    }

    #[Test]
    public function event_catalog_has_docblocks(): void
    {
        $files = [
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertStringContainsString('/**', $content, "Missing docblock in {$file}");
            $this->assertStringContainsString('@since', $content, "Missing @since in {$file}");
        }
    }

    // ── 2. Server-Side Lifecycle Tracker ──────────────────────────────────

    #[Test]
    public function lifecycle_tracker_class_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Tracking/ServerSideTracker.php');
    }

    #[Test]
    public function lifecycle_subscriber_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Tracking/LifecycleEventSubscriber.php');
    }

    #[Test]
    public function lifecycle_mapper_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/LifecycleEventMapper.php');
    }

    #[Test]
    public function config_has_lifecycle_mapping_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'lifecycle'", $content);
        $this->assertStringContainsString("'custom_mappings'", $content);
        $this->assertStringContainsString("'queue_events'", $content);
    }

    #[Test]
    public function config_has_auto_track_event_map(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'auto_track'", $content);
        $this->assertStringContainsString("'event_map'", $content);
        $this->assertStringContainsString("'auth.login'", $content);
        $this->assertStringContainsString("'auth.register'", $content);
    }

    #[Test]
    public function lifecycle_tracker_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Tracking/ServerSideTracker.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ── 3. Inertia Middleware ──────────────────────────────────────────────

    #[Test]
    public function inertia_middleware_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
    }

    #[Test]
    public function inertia_middleware_injects_tracking_id(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('trackingId', $content);
        $this->assertStringContainsString('getOrCreateTrackingId', $content);
    }

    #[Test]
    public function inertia_middleware_injects_consent_state(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('consent', $content);
    }

    #[Test]
    public function inertia_middleware_injects_provider_ids(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('ga4MeasurementId', $content);
        $this->assertStringContainsString('gtmContainerId', $content);
        $this->assertStringContainsString('metaPixelId', $content);
    }

    #[Test]
    public function inertia_middleware_injects_auto_track_config(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('autoTrack', $content);
        $this->assertStringContainsString('pageViews', $content);
        $this->assertStringContainsString('scrollDepth', $content);
        $this->assertStringContainsString('errorTracking', $content);
    }

    #[Test]
    public function inertia_middleware_injects_ecommerce_config(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'ecommerce'", $content);
        $this->assertStringContainsString("'currency'", $content);
    }

    #[Test]
    public function inertia_middleware_injects_user_id(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'userId'", $content);
    }

    #[Test]
    public function inertia_middleware_has_cookie_management(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('Cookie::queue', $content);
        $this->assertStringContainsString('generateTrackingId', $content);
    }

    #[Test]
    public function inertia_middleware_is_final_with_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertMatchesRegularExpression('/final\s+class\s+HandleInertiaAnalytics/', $content);
    }

    // ── 4. API Controller + Routes ────────────────────────────────────────

    #[Test]
    public function api_event_controller_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Http/Controllers/AnalyticsEventController.php');
    }

    #[Test]
    public function api_routes_include_events_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('events'", $content);
    }

    #[Test]
    public function api_routes_include_batch_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('batch'", $content);
    }

    #[Test]
    public function api_routes_include_identify_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('identify'", $content);
    }

    #[Test]
    public function api_routes_include_consent_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('consent'", $content);
    }

    #[Test]
    public function api_routes_include_health_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::get('health'", $content);
    }

    #[Test]
    public function api_routes_include_pageview_endpoint(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('pageview'", $content);
    }

    #[Test]
    public function api_routes_file_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function api_config_section_exists(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'api'", $content);
        $this->assertStringContainsString("'rate_limit'", $content);
        $this->assertStringContainsString("'sdk_token'", $content);
        $this->assertStringContainsString("'base_url'", $content);
    }

    // ── 5. JS Client Library ─────────────────────────────────────────────

    #[Test]
    public function js_client_library_exists(): void
    {
        $file = self::PKG_ROOT . '/resources/js/analytics.js';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertGreaterThan(5000, strlen($content), 'JS client should be substantial');
    }

    #[Test]
    public function js_client_exports_track_event(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function trackEvent', $content);
    }

    #[Test]
    public function js_client_exports_track_page_view(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function trackPageView', $content);
    }

    #[Test]
    public function js_client_exports_init(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function init(', $content);
    }

    #[Test]
    public function js_client_has_scroll_depth_tracker(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initScrollDepthTracker', $content);
    }

    #[Test]
    public function js_client_has_client_id_management(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('getClientId', $content);
    }

    #[Test]
    public function js_client_has_batch_queue(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('eventQueue', $content);
        $this->assertStringContainsString('FLUSH_INTERVAL', $content);
    }

    #[Test]
    public function js_client_has_consent_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('consent', $content);
    }

    #[Test]
    public function js_client_has_sampling_engine(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('shouldSampleEvent', $content);
    }

    #[Test]
    public function js_client_has_ga4_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initGA4', $content);
    }

    #[Test]
    public function js_client_has_gtm_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initGTM', $content);
    }

    #[Test]
    public function js_client_has_meta_pixel_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initMetaPixel', $content);
    }

    #[Test]
    public function js_client_has_posthog_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initPostHog', $content);
    }

    #[Test]
    public function js_client_has_plausible_support(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('initPlausible', $content);
    }

    #[Test]
    public function js_client_version_matches_composer(): void
    {
        $js = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $composer = file_get_contents(self::PKG_ROOT . '/composer.json');
        $composerVersion = json_decode($composer, true)['version'] ?? '0.0.0';

        $this->assertStringContainsString($composerVersion, $js, 'JS version should match composer version');
    }

    #[Test]
    public function js_client_constants_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/resources/js/analytics.constants.js');
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.constants.js');
        $this->assertStringContainsString('EcommerceEvents', $content);
        $this->assertStringContainsString('SaaSEvents', $content);
        $this->assertStringContainsString('EngagementEvents', $content);
    }

    #[Test]
    public function svelte_composables_exist(): void
    {
        $composables = [
            'useAnalytics.svelte.js',
            'useAnalyticsConfig.svelte.js',
            'useEcommerce.svelte.js',
            'useSaaSMetrics.svelte.js',
            'useLifecycle.svelte.js',
            'usePerformanceTracker.svelte.js',
            'useSessionReplay.svelte.js',
        ];
        foreach ($composables as $file) {
            $this->assertFileExists(
                self::PKG_ROOT . '/resources/js/' . $file,
                "Missing Svelte composable: {$file}"
            );
        }
    }

    #[Test]
    public function typescript_definitions_exist(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/resources/js/analytics.d.ts');
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.d.ts');
        $this->assertStringContainsString('export function trackEvent', $content);
        $this->assertStringContainsString('export function init', $content);
        $this->assertStringContainsString('export function trackPageView', $content);
    }

    #[Test]
    public function js_client_exports_init_full_stack(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function initFullStack', $content);
    }

    #[Test]
    public function js_client_has_offline_recovery(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString('enableOfflineRecovery', $content);
    }

    // ── 6. Event Queue ────────────────────────────────────────────────────

    #[Test]
    public function queued_dispatcher_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Queue/QueuedAnalyticsDispatcher.php');
    }

    #[Test]
    public function queued_dispatcher_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Queue/QueuedAnalyticsDispatcher.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function config_has_queue_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $content);
        $this->assertStringContainsString("'enabled'", $content);
        $this->assertStringContainsString("'queue'", $content);
        $this->assertStringContainsString("'connection'", $content);
        $this->assertStringContainsString("'max_batch_size'", $content);
    }

    #[Test]
    public function event_bus_dispatcher_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Bus/AnalyticsEventDispatcher.php');
    }

    #[Test]
    public function service_provider_registers_queued_dispatcher(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('QueuedAnalyticsDispatcher', $content);
    }

    // ── 7. User Identity Linking ───────────────────────────────────────────

    #[Test]
    public function identity_tracker_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Tracking/UserIdentityTracker.php');
    }

    #[Test]
    public function identity_resolution_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/IdentityResolutionService.php');
    }

    #[Test]
    public function identity_graph_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/IdentityGraphService.php');
    }

    #[Test]
    public function config_has_identity_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'identity'", $content);
        $this->assertStringContainsString("'cookie_name'", $content);
        $this->assertStringContainsString("'cookie_ttl'", $content);
        $this->assertStringContainsString("'link_on_auth'", $content);
    }

    #[Test]
    public function config_identity_has_cache_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'cache_prefix'", $content);
        $this->assertStringContainsString("'link_ttl'", $content);
    }

    #[Test]
    public function api_routes_include_identity_endpoints(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::get('identity/", $content);
        $this->assertStringContainsString("Route::post('identity/resolve'", $content);
    }

    // ── 8. E-commerce Helpers ─────────────────────────────────────────────

    #[Test]
    public function ecommerce_format_converter_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function ecommerce_analytics_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/EcommerceAnalyticsService.php');
    }

    #[Test]
    public function ecommerce_format_converter_has_ga4_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Support/EcommerceFormatConverter.php');
        $this->assertStringContainsString('toGa4', $content);
    }

    #[Test]
    public function ecommerce_format_converter_has_meta_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Support/EcommerceFormatConverter.php');
        $this->assertStringContainsString('toMeta', $content);
    }

    #[Test]
    public function ecommerce_service_registered_in_provider(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('EcommerceAnalyticsService', $content);
    }

    #[Test]
    public function config_has_ecommerce_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'ecommerce'", $content);
        $this->assertStringContainsString("'currency'", $content);
        $this->assertStringContainsString("'brand'", $content);
        $this->assertStringContainsString("'tax_behavior'", $content);
    }

    // ── 9. Admin Commands ─────────────────────────────────────────────────

    #[Test]
    public function analytics_overview_command_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Console/Commands/AnalyticsOverviewCommand.php');
    }

    #[Test]
    public function analytics_test_command_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function commands_have_strict_types(): void
    {
        $files = [
            'src/Console/Commands/AnalyticsOverviewCommand.php',
            'src/Console/Commands/AnalyticsTestCommand.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertStringContainsString('declare(strict_types=1)', $content, "Missing strict_types in {$file}");
        }
    }

    #[Test]
    public function commands_registered_in_provider(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsOverviewCommand', $content);
        $this->assertStringContainsString('AnalyticsTestCommand', $content);
    }

    // ── 10. Config Expansion ──────────────────────────────────────────────

    #[Test]
    public function config_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/config/zeroboiler.php');
    }

    #[Test]
    public function config_has_queue_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $content);
    }

    #[Test]
    public function config_has_api_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'api'", $content);
    }

    #[Test]
    public function config_has_identity_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'identity'", $content);
    }

    #[Test]
    public function config_has_auto_track_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'auto_track'", $content);
    }

    #[Test]
    public function config_has_ecommerce_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'ecommerce'", $content);
    }

    #[Test]
    public function config_has_consent_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'consent'", $content);
        $this->assertStringContainsString("'purposes'", $content);
    }

    #[Test]
    public function config_has_client_auto_track_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'client_auto_track'", $content);
        $this->assertStringContainsString("'page_views'", $content);
        $this->assertStringContainsString("'scroll_depth'", $content);
    }

    #[Test]
    public function config_has_revenue_checksum_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'revenue_checksum'", $content);
    }

    #[Test]
    public function config_has_dedup_cache_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'dedup_cache'", $content);
    }

    #[Test]
    public function config_has_sampling_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        // Search in config file for sampling section
        $this->assertStringContainsString("'sampling'", $content);
    }

    #[Test]
    public function config_has_retention_cohort_settings(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'retention_cohort'", $content);
    }

    #[Test]
    public function config_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ── 11. Optional Providers ────────────────────────────────────────────

    #[Test]
    public function plausible_tracker_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Trackers/PlausibleTracker.php');
    }

    #[Test]
    public function posthog_tracker_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Trackers/PosthogTracker.php');
    }

    #[Test]
    public function plausible_tracker_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Trackers/PlausibleTracker.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function posthog_tracker_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Trackers/PosthogTracker.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function plausible_registered_in_manager(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsManager.php');
        $this->assertStringContainsString('PlausibleTracker', $content);
    }

    #[Test]
    public function posthog_registered_in_manager(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsManager.php');
        $this->assertStringContainsString('PosthogTracker', $content);
    }

    #[Test]
    public function plausible_registered_in_service_provider(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('PlausibleTracker', $content);
    }

    #[Test]
    public function posthog_registered_in_service_provider(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('PosthogTracker', $content);
    }

    // ── 12. Tests + README ───────────────────────────────────────────────

    #[Test]
    public function test_directory_has_sufficient_tests(): void
    {
        $testsDir = self::PKG_ROOT . '/tests';
        $this->assertDirectoryExists($testsDir);

        $files = glob($testsDir . '/*Test.php');
        $this->assertGreaterThan(50, count($files), 'Should have at least 50 test files');
    }

    #[Test]
    public function test_directory_has_specific_feature_tests(): void
    {
        $required = [
            'tests/EcommerceEventsTest.php',
            'tests/SaaSEventsTest.php',
            'tests/EngagementEventsTest.php',
            'tests/EventCatalogTest.php',
            'tests/ServerSideTrackerTest.php',
            'tests/ConsentModeTest.php',
            'tests/GA4TrackerTest.php',
            'tests/MetaPixelTrackerTest.php',
            'tests/GTMTrackerTest.php',
            'tests/OptionalTrackersTest.php',
        ];
        foreach ($required as $file) {
            $this->assertFileExists(self::PKG_ROOT . '/' . $file, "Missing test: {$file}");
        }
    }

    #[Test]
    public function readme_exists_and_is_substantial(): void
    {
        $file = self::PKG_ROOT . '/README.md';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertGreaterThan(10000, strlen($content), 'README should be comprehensive');
    }

    #[Test]
    public function readme_contains_quick_start(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('Quick Start', $content);
    }

    #[Test]
    public function readme_contains_js_client_docs(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('JS Client API', $content);
    }

    #[Test]
    public function readme_contains_inertia_docs(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('Inertia', $content);
    }

    #[Test]
    public function readme_contains_event_catalog_docs(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('Event Catalog', $content);
    }

    #[Test]
    public function readme_contains_admin_commands_docs(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('Admin Commands', $content);
    }

    #[Test]
    public function readme_contains_config_docs(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('Configuration', $content);
    }

    // ── Cross-Cutting Quality Checks ─────────────────────────────────────

    #[Test]
    public function composer_json_matches_readme_version(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $readme = file_get_contents(self::PKG_ROOT . '/README.md');
        $version = $composer['version'] ?? '';

        $this->assertNotEmpty($version, 'Composer version should not be empty');
        $this->assertStringContainsString($version, $readme, 'README should reference current version');
    }

    #[Test]
    public function package_json_matches_composer_version(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $pkg = json_decode(file_get_contents(self::PKG_ROOT . '/package.json'), true);

        $this->assertSame(
            $composer['version'],
            $pkg['version'],
            'package.json version should match composer.json version'
        );
    }

    #[Test]
    public function analytics_event_version_constant_matches_composer(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $content = file_get_contents(self::PKG_ROOT . '/src/DTO/AnalyticsEvent.php');

        $this->assertStringContainsString(
            "'{$composer['version']}'",
            $content,
            'AnalyticsEvent::VERSION should match composer version'
        );
    }

    #[Test]
    public function facade_exists_with_method_annotations(): void
    {
        $file = self::PKG_ROOT . '/src/Facades/Analytics.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertStringContainsString('@method', $content);
        $this->assertStringContainsString('track(', $content);
        $this->assertStringContainsString('identify(', $content);
    }

    #[Test]
    public function blade_directives_exist(): void
    {
        $file = self::PKG_ROOT . '/src/Blade/Directives/AnalyticsDirectives.php';
        $this->assertFileExists($file);
    }

    #[Test]
    public function middleware_stack_exists(): void
    {
        $file = self::PKG_ROOT . '/src/Middleware/AnalyticsMiddlewareStack.php';
        $this->assertFileExists($file);
    }

    #[Test]
    public function inject_analytics_scripts_middleware_exists(): void
    {
        $file = self::PKG_ROOT . '/src/Http/Middleware/InjectAnalyticsScripts.php';
        $this->assertFileExists($file);
    }

    #[Test]
    public function service_provider_registers_facade_and_alias(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('zeroboiler.analytics', $content);
        $this->assertStringContainsString('AnalyticsManager', $content);
    }

    #[Test]
    public function database_migration_exists(): void
    {
        $migrations = glob(self::PKG_ROOT . '/database/migrations/*.php');
        $this->assertNotEmpty($migrations, 'Should have at least one migration');
    }

    #[Test]
    public function phpstan_config_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/phpstan.neon.dist');
    }

    #[Test]
    public function ci_workflow_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/.github/workflows/ci.yml');
    }

    #[Test]
    public function changelog_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/CHANGELOG.md');
    }

    #[Test]
    public function license_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/LICENSE');
    }

    #[Test]
    public function gitignore_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/.gitignore');
    }

    // ── Additional Provider Coverage ─────────────────────────────────────

    #[Test]
    public function all_ten_trackers_exist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker',
            'PlausibleTracker', 'PosthogTracker', 'MixpanelTracker',
            'AmplitudeTracker', 'TikTokTracker', 'LinkedInTracker', 'WebhookTracker',
        ];
        foreach ($trackers as $tracker) {
            $this->assertFileExists(
                self::PKG_ROOT . "/src/Trackers/{$tracker}.php",
                "Missing tracker: {$tracker}"
            );
        }
    }

    #[Test]
    public function tracker_interface_exists(): void
    {
        $file = self::PKG_ROOT . '/src/Trackers/TrackerInterface.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function all_tracker_classes_use_strict_types(): void
    {
        $trackers = glob(self::PKG_ROOT . '/src/Trackers/*Tracker.php');
        foreach ($trackers as $tracker) {
            $content = file_get_contents($tracker);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                basename($tracker) . ' missing strict_types'
            );
        }
    }

    // ── SaaS Maturity Score ───────────────────────────────────────────────

    #[Test]
    public function maturity_score_is_industry_standard(): void
    {
        // Count: providers (10), event categories (8+), commands (50+), services (200+), tests (50+)
        $trackerCount = count(glob(self::PKG_ROOT . '/src/Trackers/*Tracker.php'));
        $testCount = count(glob(self::PKG_ROOT . '/tests/*Test.php'));
        $commandCount = count(glob(self::PKG_ROOT . '/src/Console/Commands/*Command.php'));
        $serviceCount = count(glob(self::PKG_ROOT . '/src/Services/*Service.php'));

        $this->assertGreaterThanOrEqual(10, $trackerCount, 'Should have 10+ trackers');
        $this->assertGreaterThanOrEqual(50, $testCount, 'Should have 50+ test files');
        $this->assertGreaterThanOrEqual(50, $commandCount, 'Should have 50+ commands');
        $this->assertGreaterThanOrEqual(200, $serviceCount, 'Should have 200+ services');
    }

    #[Test]
    public function js_client_loc_is_production_grade(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $lineCount = substr_count($content, "\n");
        $this->assertGreaterThan(5000, $lineCount, 'JS client should have 5000+ lines');
    }

    #[Test]
    public function config_loc_is_comprehensive(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $lineCount = substr_count($content, "\n");
        $this->assertGreaterThan(500, $lineCount, 'Config should have 500+ lines');
    }

    #[Test]
    public function routes_file_is_comprehensive(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $routeCount = substr_count($content, 'Route::');
        $this->assertGreaterThan(200, $routeCount, 'Should have 200+ API routes');
    }

    // ── Identity Resolution API Endpoints ──────────────────────────────────

    #[Test]
    public function api_has_user_properties_endpoints(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::get('user-properties/", $content);
        $this->assertStringContainsString("Route::post('user-properties/", $content);
    }

    #[Test]
    public function api_has_subscription_lifecycle_endpoints(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('subscription/", $content);
    }

    // ── SaaS-Specific Services ────────────────────────────────────────────

    #[Test]
    public function saas_analytics_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/SaaSAnalyticsService.php');
    }

    #[Test]
    public function revenue_analytics_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/RevenueAnalyticsService.php');
    }

    #[Test]
    public function revenue_waterfall_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/RevenueWaterfallService.php');
    }

    #[Test]
    public function churn_prediction_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/ChurnPredictionService.php');
    }

    #[Test]
    public function aarrr_framework_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/AARRRFrameworkService.php');
    }

    #[Test]
    public function cohort_analytics_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/CohortAnalyticsService.php');
    }

    #[Test]
    public function retention_calculator_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/RetentionCalculator.php');
    }

    #[Test]
    public function session_analytics_service_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/SessionAnalyticsService.php');
    }
}
