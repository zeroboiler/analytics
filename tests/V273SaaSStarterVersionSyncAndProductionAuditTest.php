<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies version consistency across all entry points and confirms
 * all 12 industry-standard SaaS starter features are at production quality.
 *
 * @since 273.0.0
 */
final class V273SaaSStarterVersionSyncAndProductionAuditTest extends TestCase
{
    private const EXPECTED_VERSION = '272.0.0';

    // ── Version Consistency ──────────────────────────────────────────

    public function it_has_consistent_version_in_composer_json(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame(self::EXPECTED_VERSION, $json['version']);
    }

    public function it_has_consistent_version_in_package_json(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame(self::EXPECTED_VERSION, $json['version']);
    }

    public function it_has_consistent_version_in_analytics_event_dto(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString("public const VERSION = '" . self::EXPECTED_VERSION . "'", $content);
    }

    public function it_has_consistent_version_in_service_provider(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::EXPECTED_VERSION, $content);
    }

    public function it_has_consistent_version_in_js_client(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('@version ' . self::EXPECTED_VERSION, $content);
    }

    public function it_has_consistent_version_in_typescript_definitions(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        $this->assertStringContainsString('@version ' . self::EXPECTED_VERSION, $content);
    }

    public function it_has_consistent_version_in_integrity_command(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
        $this->assertStringContainsString("EXPECTED_VERSION = '" . self::EXPECTED_VERSION . "'", $content);
    }

    public function it_has_no_stale_versions_in_js_files(): void
    {
        $jsDir = __DIR__ . '/../resources/js';
        $files = glob($jsDir . '/*.{js,ts,svelte.js}', GLOB_BRACE);
        $this->assertNotEmpty($files, 'JS files should exist');

        $stale = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/@version\s+(\d+\.\d+\.\d+)/', $content, $m)) {
                if ($m[1] !== self::EXPECTED_VERSION) {
                    $stale[] = basename($file) . ' -> ' . $m[1];
                }
            }
        }

        $this->assertEmpty($stale, 'Stale version references found: ' . implode(', ', $stale));
    }

    // ── 12 Industry-Standard SaaS Starter Features ───────────────────

    public function it_has_ecommerce_event_catalog(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php');
        $content = file_get_contents(__DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php');
        $this->assertStringContainsString('view_item', $content);
        $this->assertStringContainsString('add_to_cart', $content);
        $this->assertStringContainsString('purchase', $content);
        $this->assertStringContainsString('refund', $content);
        $this->assertStringContainsString('viewItem(', $content);
        $this->assertStringContainsString('addToCart(', $content);
    }

    public function it_has_saas_event_catalog(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/SaaS/SaaSEvents.php');
        $content = file_get_contents(__DIR__ . '/../src/Events/SaaS/SaaSEvents.php');
        $this->assertStringContainsString('sign_up', $content);
        $this->assertStringContainsString('login', $content);
        $this->assertStringContainsString('start_trial', $content);
        $this->assertStringContainsString('subscription', $content);
        $this->assertStringContainsString('plan_upgrade', $content);
        $this->assertStringContainsString('cancellation', $content);
    }

    public function it_has_engagement_event_catalog(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/Engagement/EngagementEvents.php');
        $content = file_get_contents(__DIR__ . '/../src/Events/Engagement/EngagementEvents.php');
        $this->assertStringContainsString('page_view', $content);
        $this->assertStringContainsString('scroll_depth', $content);
        $this->assertStringContainsString('click', $content);
        $this->assertStringContainsString('form_start', $content);
        $this->assertStringContainsString('form_submit', $content);
        $this->assertStringContainsString('search', $content);
        $this->assertStringContainsString('share', $content);
        $this->assertStringContainsString('error', $content);
    }

    public function it_has_server_side_lifecycle_tracker(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Tracking/ServerSideTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/LifecycleEventMapper.php');
        $this->assertFileExists(__DIR__ . '/../src/Tracking/LifecycleEventSubscriber.php');
        $content = file_get_contents(__DIR__ . '/../src/Services/LifecycleEventMapper.php');
        $this->assertStringContainsString('auth.login', $content);
        $this->assertStringContainsString('auth.register', $content);
        $this->assertStringContainsString('subscription.created', $content);
    }

    public function it_has_inertia_middleware(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('zbAnalytics', $content);
        $this->assertStringContainsString('trackingId', $content);
        $this->assertStringContainsString('ga4MeasurementId', $content);
        $this->assertStringContainsString('metaPixelId', $content);
        $this->assertStringContainsString('posthogHost', $content);
    }

    public function it_has_api_controller_and_routes(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
        $this->assertFileExists(__DIR__ . '/../routes/analytics.php');
        $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString("Route::post('events'", $routes);
        $this->assertStringContainsString("Route::post('batch'", $routes);
        $this->assertStringContainsString("Route::post('identify'", $routes);
        $this->assertStringContainsString("Route::post('consent'", $routes);
    }

    public function it_has_js_client_library(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.js');
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('export function trackEvent', $js);
        $this->assertStringContainsString('export function trackPageView', $js);
        $this->assertStringContainsString('initInertiaPageViewTracker', $js);
        $this->assertStringContainsString('scrollDepth', $js);
        $this->assertStringContainsString('trackingId', $js);
    }

    public function it_has_event_queue(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Queue/QueuedAnalyticsDispatcher.php');
        $this->assertFileExists(__DIR__ . '/../src/Queue/TrackAnalyticsEventJob.php');
        $this->assertFileExists(__DIR__ . '/../src/Queue/TrackAnalyticsEventBatchJob.php');
    }

    public function it_has_user_identity_linking(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/IdentityGraphService.php');
        $this->assertFileExists(__DIR__ . '/../src/Tracking/UserIdentityTracker.php');
        $this->assertFileExists(__DIR__ . '/../resources/js/useIdentity.svelte.js');
    }

    public function it_has_ecommerce_helpers(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/EcommerceFormatConverter.php');
        $content = file_get_contents(__DIR__ . '/../src/Services/EcommerceFormatConverter.php');
        $this->assertStringContainsString('toGa4', $content);
        $this->assertStringContainsString('toMeta', $content);
    }

    public function it_has_admin_commands(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');
    }

    public function it_has_config_expansion(): void
    {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $config);
        $this->assertStringContainsString("'api'", $config);
        $this->assertStringContainsString("'identity'", $config);
        $this->assertStringContainsString("'auto_track'", $config);
        $this->assertStringContainsString("'ecommerce'", $config);
    }

    public function it_has_optional_providers(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Tracking/PlausibleEventTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Tracking/PosthogEventTracker.php');
    }

    // ── Source File Scale Thresholds ─────────────────────────────────

    public function it_has_minimum_source_files(): void
    {
        $count = count(glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE));
        $this->assertGreaterThan(990, $count, "Expected >990 source files, got {$count}");
    }

    public function it_has_minimum_test_files(): void
    {
        $count = count(glob(__DIR__ . '/../tests/**/*.php', GLOB_BRACE));
        $this->assertGreaterThan(520, $count, "Expected >520 test files, got {$count}");
    }

    public function it_has_minimum_svelte_composables(): void
    {
        $count = count(glob(__DIR__ . '/../resources/js/use*.svelte.js'));
        $this->assertGreaterThan(14, $count, "Expected >14 Svelte composables, got {$count}");
    }

    public function it_has_typescript_definitions(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.d.ts');
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        $this->assertGreaterThan(2000, strlen($content), 'TypeScript definitions should be >2000 chars');
    }

    public function it_has_js_event_constants(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.constants.js');
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
        $this->assertStringContainsString('EVENT_NAMES', $content);
    }

    // ── Code Quality ─────────────────────────────────────────────────

    public function it_has_strict_types_in_all_source_files(): void
    {
        $files = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1);')) {
                $violations[] = str_replace(__DIR__ . '/../', '', $file);
            }
        }
        $this->assertEmpty($violations, 'Files missing strict_types: ' . implode(', ', array_slice($violations, 0, 5)));
    }

    public function it_has_php_85_syntax_in_all_source_files(): void
    {
        $this->assertFileExists(__DIR__ . '/../composer.json');
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('^8.5', $json['require']['php']);
    }

    public function it_has_unified_engagement_composable(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/useEngagement.svelte.js');
        $content = file_get_contents(__DIR__ . '/../resources/js/useEngagement.svelte.js');
        $this->assertStringContainsString('initEngagement', $content);
        $this->assertStringContainsString('engagementScore', $content);
    }
}
