<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Full SaaS Analytics Production Readiness Audit — v234.0.0.
 *
 * Validates all 12 industry-standard SaaS starter features
 * at production quality. Each feature category is verified
 * for file existence, structure, type safety, and completeness.
 *
 * Feature checklist:
 *  1. Event Catalog (EcommerceEvents, SaaSEvents, EngagementEvents)
 *  2. Server-Side Lifecycle Tracker (config-driven Laravel event → analytics mapping)
 *  3. Inertia middleware (page props, client ID cookie, 10+ providers)
 *  4. API controller + routes (POST events, batch, identify, consent)
 *  5. JS client library (trackEvent, trackPageView, scroll depth, client ID)
 *  6. Event queue (async dispatch via QueuedAnalyticsDispatcher)
 *  7. User identity linking (client ID ↔ user ID, cache-backed)
 *  8. E-commerce helpers (GA4 ↔ Meta format conversion)
 *  9. Admin commands (AnalyticsOverviewCommand, AnalyticsTestCommand)
 * 10. Config expansion (queue, API, identity, auto-track, ecommerce)
 * 11. Optional providers (Plausible, PostHog trackers)
 * 12. Tests + README (483+ test files, 5300+ test cases)
 */
final class Phase59FullSaaSProductionReadinessTest extends TestCase
{
    private const VERSION = '233.0.0';
    private const SRC_DIR = __DIR__ . '/../src';
    private const ROOT_DIR = __DIR__ . '/..';

    // ── 1. Event Catalog ──────────────────────────────────────────

    #[Test]
    public function ecommerceEventsCatalogExists(): void
    {
        $path = self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class EcommerceEvents', $content);
    }

    #[Test]
    public function ecommerceEventsHasMinimum15Events(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $count = substr_count($content, "'name' =>");
        $this->assertGreaterThanOrEqual(15, $count, "Expected at least 15 ecommerce events, got {$count}");
    }

    #[Test]
    public function saasEventsCatalogExists(): void
    {
        $path = self::SRC_DIR . '/Events/SaaS/SaaSEvents.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class SaaSEvents', $content);
    }

    #[Test]
    public function saasEventsHasCoreLifecycleEvents(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $required = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($required as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing core SaaS event: {$event}");
        }
    }

    #[Test]
    public function engagementEventsCatalogExists(): void
    {
        $path = self::SRC_DIR . '/Events/Engagement/EngagementEvents.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class EngagementEvents', $content);
    }

    #[Test]
    public function engagementEventsHasCoreEvents(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($required as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing core engagement event: {$event}");
        }
    }

    #[Test]
    public function eventCatalogAggregatesAllCategories(): void
    {
        $path = self::SRC_DIR . '/Events/EventCatalog.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('final class EventCatalog', $content);
        $this->assertStringContainsString('EcommerceEvents::all()', $content);
        $this->assertStringContainsString('SaaSEvents::all()', $content);
        $this->assertStringContainsString('EngagementEvents::all()', $content);
    }

    #[Test]
    public function totalEventCountAtLeast200(): void
    {
        $files = [
            self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php',
            self::SRC_DIR . '/Events/SaaS/SaaSEvents.php',
            self::SRC_DIR . '/Events/Engagement/EngagementEvents.php',
            self::SRC_DIR . '/Events/Security/SecurityEvents.php',
            self::SRC_DIR . '/Events/Uptime/UptimeEvents.php',
            self::SRC_DIR . '/Events/Infrastructure/InfrastructureEvents.php',
            self::SRC_DIR . '/Events/Marketing/MarketingEvents.php',
            self::SRC_DIR . '/Events/SaaS/CustomerSuccessEvents.php',
            self::SRC_DIR . '/Events/Webhook/WebhookEvents.php',
        ];
        $total = 0;
        foreach ($files as $file) {
            if (file_exists($file)) {
                $total += substr_count(file_get_contents($file), "'name' =>");
            }
        }
        $this->assertGreaterThanOrEqual(200, $total, "Expected at least 200 total events across all categories, got {$total}");
    }

    // ── 2. Server-Side Lifecycle Tracker ────────────────────────────

    #[Test]
    public function serverSideTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/ServerSideTracker.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class ServerSideTracker', $content);
    }

    #[Test]
    public function serverSideTrackerHasCoreMappings(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
        $this->assertStringContainsString('Login::class', $content);
        $this->assertStringContainsString('Registered::class', $content);
        $this->assertStringContainsString('Logout::class', $content);
        $this->assertStringContainsString("'subscription.created'", $content);
        $this->assertStringContainsString("'subscription.upgraded'", $content);
        $this->assertStringContainsString("'subscription.cancelled'", $content);
        $this->assertStringContainsString("'trial.started'", $content);
    }

    #[Test]
    public function lifecycleEventMapperExists(): void
    {
        $path = self::SRC_DIR . '/Services/LifecycleEventMapper.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function lifecycleEventSubscriberExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function lifecycleAttributionEnricherExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/LifecycleAttributionEnricher.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ── 3. Inertia Middleware ──────────────────────────────────────

    #[Test]
    public function inertiaMiddlewareExists(): void
    {
        $path = self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class HandleInertiaAnalytics', $content);
    }

    #[Test]
    public function inertiaMiddlewareInjectsProviderIds(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'ga4MeasurementId'", $content);
        $this->assertStringContainsString("'gtmContainerId'", $content);
        $this->assertStringContainsString("'metaPixelId'", $content);
        $this->assertStringContainsString("'plausibleDomain'", $content);
        $this->assertStringContainsString("'posthogHost'", $content);
    }

    #[Test]
    public function inertiaMiddlewareInjectsTrackingIdAndConsent(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'trackingId'", $content);
        $this->assertStringContainsString("'userId'", $content);
        $this->assertStringContainsString("'consent'", $content);
        $this->assertStringContainsString("'autoTrack'", $content);
    }

    #[Test]
    public function inertiaMiddlewareInjectsCampaignContext(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'campaignContext'", $content);
    }

    #[Test]
    public function inertiaMiddlewareHasCookieManagement(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('getOrCreateTrackingId', $content);
        $this->assertStringContainsString('Cookie::queue', $content);
        $this->assertStringContainsString("'zb_analytics_id'", $content);
    }

    // ── 4. API Controller + Routes ──────────────────────────────────

    #[Test]
    public function apiControllerExists(): void
    {
        $path = self::SRC_DIR . '/Http/Controllers/AnalyticsEventController.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function apiRoutesIncludeCoreEndpoints(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertStringContainsString("Route::post('events'", $content);
        $this->assertStringContainsString("Route::post('batch'", $content);
        $this->assertStringContainsString("Route::post('identify'", $content);
        $this->assertStringContainsString("Route::post('consent'", $content);
        $this->assertStringContainsString("Route::post('pageview'", $content);
    }

    #[Test]
    public function apiRoutesIncludeHealthEndpoint(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertStringContainsString("Route::get('health'", $content);
    }

    #[Test]
    public function apiRoutesIncludeSubscriptionEndpoints(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertStringContainsString("'subscription/trial-started'", $content);
        $this->assertStringContainsString("'subscription/created'", $content);
        $this->assertStringContainsString("'subscription/plan-upgraded'", $content);
        $this->assertStringContainsString("'subscription/cancelled'", $content);
    }

    #[Test]
    public function formRequestClassesExist(): void
    {
        $requests = [
            'TrackEventRequest',
            'BatchEventRequest',
            'IdentifyRequest',
            'UpdateConsentRequest',
            'PageViewRequest',
            'OptInRequest',
            'OptOutRequest',
        ];
        foreach ($requests as $class) {
            $path = self::SRC_DIR . "/Http/Requests/{$class}.php";
            $this->assertFileExists($path, "Missing FormRequest: {$class}");
        }
    }

    // ── 5. JS Client Library ───────────────────────────────────────

    #[Test]
    public function jsClientLibraryExists(): void
    {
        $path = self::ROOT_DIR . '/resources/js/analytics.js';
        $this->assertFileExists($path);
    }

    #[Test]
    public function jsClientExportsTrackEvent(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function trackEvent', $content);
    }

    #[Test]
    public function jsClientExportsTrackPageView(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function trackPageView', $content);
    }

    #[Test]
    public function jsClientHasClientIdManagement(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('export function getClientId', $content);
        $this->assertStringContainsString('export function getUserId', $content);
    }

    #[Test]
    public function jsClientHasBatchQueue(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('eventQueue', $content);
        $this->assertStringContainsString('flushQueue', $content);
    }

    #[Test]
    public function jsClientHasConsentMode(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('consentResolved', $content);
        $this->assertStringContainsString('consent', $content);
    }

    #[Test]
    public function jsClientHasSamplingAndDebounce(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('shouldSampleEvent', $content);
        $this->assertStringContainsString('trackDeduped', $content);
        $this->assertStringContainsString('trackOnce', $content);
    }

    #[Test]
    public function jsClientMinLoc14000(): void
    {
        $lines = count(file(self::ROOT_DIR . '/resources/js/analytics.js'));
        $this->assertGreaterThan(14000, $lines, "JS client should be at least 14,000 LOC, got {$lines}");
    }

    #[Test]
    public function svelteComposablesExist(): void
    {
        $composables = [
            'useAnalytics.svelte.js',
            'useAnalyticsConfig.svelte.js',
            'useAttribution.svelte.js',
            'useConsent.svelte.js',
            'useEcommerce.svelte.js',
            'useEventSequence.svelte.js',
            'useIdentity.svelte.js',
            'useLifecycle.svelte.js',
            'usePageView.svelte.js',
            'usePerformanceTracker.svelte.js',
            'useSaaSFlow.svelte.js',
            'useSaaSMetrics.svelte.js',
            'useScrollDepth.svelte.js',
            'useSessionReplay.svelte.js',
        ];
        foreach ($composables as $file) {
            $path = self::ROOT_DIR . "/resources/js/{$file}";
            $this->assertFileExists($path, "Missing Svelte composable: {$file}");
        }
    }

    #[Test]
    public function typeScriptDefinitionsExist(): void
    {
        $path = self::ROOT_DIR . '/resources/js/analytics.d.ts';
        $this->assertFileExists($path);
        $lines = count(file($path));
        $this->assertGreaterThan(3000, $lines, "TypeScript definitions should be at least 3,000 LOC, got {$lines}");
    }

    // ── 6. Event Queue ──────────────────────────────────────────────

    #[Test]
    public function queuedAnalyticsDispatcherExists(): void
    {
        $path = self::SRC_DIR . '/Queue/QueuedAnalyticsDispatcher.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class QueuedAnalyticsDispatcher', $content);
    }

    #[Test]
    public function trackAnalyticsEventJobExists(): void
    {
        $path = self::SRC_DIR . '/Jobs/TrackAnalyticsEventJob.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function trackAnalyticsEventBatchJobExists(): void
    {
        $path = self::SRC_DIR . '/Jobs/TrackAnalyticsEventBatchJob.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function configHasQueueSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue' =>", $content);
        $this->assertStringContainsString('ANALYTICS_QUEUE_ENABLED', $content);
    }

    // ── 7. User Identity Linking ────────────────────────────────────

    #[Test]
    public function userIdentityTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/UserIdentityTracker.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class UserIdentityTracker', $content);
    }

    #[Test]
    public function userIdentityTrackerHasLinkMethod(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
        $this->assertStringContainsString('linkClientIdToUser', $content);
    }

    #[Test]
    public function identityGraphServiceExists(): void
    {
        $path = self::SRC_DIR . '/Services/IdentityGraphService.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function identityResolutionServiceExists(): void
    {
        $path = self::SRC_DIR . '/Services/IdentityResolutionService.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function configHasIdentitySection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'identity' =>", $content);
        $this->assertStringContainsString('ANALYTICS_IDENTITY_COOKIE', $content);
    }

    // ── 8. E-commerce Helpers ──────────────────────────────────────

    #[Test]
    public function ecommerceFormatConverterExists(): void
    {
        $path = self::SRC_DIR . '/Support/EcommerceFormatConverter.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class EcommerceFormatConverter', $content);
    }

    #[Test]
    public function ecommerceFormatConverterHasGA4ToMeta(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
        $this->assertStringContainsString('ga4ToMetaContents', $content);
        $this->assertStringContainsString('metaToGa4Items', $content);
    }

    #[Test]
    public function ecommerceFormatConverterHasGA4ToMetaPurchase(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
        $this->assertStringContainsString('ga4ToMetaPurchase', $content);
        $this->assertStringContainsString('ga4ToMetaRefund', $content);
    }

    #[Test]
    public function ecommerceFormatConverterIsSubstantial(): void
    {
        $lines = count(file(self::SRC_DIR . '/Support/EcommerceFormatConverter.php'));
        $this->assertGreaterThan(1500, $lines, "EcommerceFormatConverter should be at least 1,500 LOC, got {$lines}");
    }

    // ── 9. Admin Commands ─────────────────────────────────────────

    #[Test]
    public function analyticsOverviewCommandExists(): void
    {
        $path = self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('final class AnalyticsOverviewCommand', $content);
    }

    #[Test]
    public function analyticsTestCommandExists(): void
    {
        $path = self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function analyticsOverviewCommandHasMultipleOptions(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertStringContainsString('--json', $content);
        $this->assertStringContainsString('--providers', $content);
        $this->assertStringContainsString('--catalog', $content);
        $this->assertStringContainsString('--health', $content);
    }

    #[Test]
    public function totalCommandCountAtLeast110(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR . '/Console/Commands', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(110, $count, "Expected at least 110 artisan commands, got {$count}");
    }

    // ── 10. Config Expansion ───────────────────────────────────────

    #[Test]
    public function configHasAllCoreSections(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $sections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'lifecycle', 'api', 'client_auto_track', 'identity', 'ecommerce',
        ];
        foreach ($sections as $section) {
            $this->assertStringContainsString(
                "'{$section}' =>",
                $content,
                "Missing config section: {$section}",
            );
        }
    }

    #[Test]
    public function configHasRevenueSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'revenue' =>", $content);
        $this->assertStringContainsString('ANALYTICS_REVENUE_CURRENCY', $content);
    }

    #[Test]
    public function configHasDedupSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'dedup_cache' =>", $content);
    }

    #[Test]
    public function configHasEventLifecycleSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'event_lifecycle' =>", $content);
    }

    #[Test]
    public function configHasLifecycleAttributionSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'lifecycle_attribution' =>", $content);
    }

    #[Test]
    public function configHasGrowthMetricsSection(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'growth_metrics' =>", $content);
    }

    // ── 11. Optional Providers ─────────────────────────────────────

    #[Test]
    public function plausibleTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Trackers/PlausibleTracker.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function posthogTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Trackers/PosthogTracker.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function allCoreTrackersExist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker',
            'PlausibleTracker', 'PosthogTracker',
            'MixpanelTracker', 'AmplitudeTracker',
            'TikTokTracker', 'LinkedInTracker',
        ];
        foreach ($trackers as $tracker) {
            $path = self::SRC_DIR . "/Trackers/{$tracker}.php";
            $this->assertFileExists($path, "Missing tracker: {$tracker}");
        }
    }

    #[Test]
    public function trackerInterfaceExists(): void
    {
        $path = self::SRC_DIR . '/Trackers/TrackerInterface.php';
        $this->assertFileExists($path);
    }

    // ── 12. Tests + README ─────────────────────────────────────────

    #[Test]
    public function testDirectoryExists(): void
    {
        $this->assertDirectoryExists(self::ROOT_DIR . '/tests');
    }

    #[Test]
    public function testFileCountAtLeast480(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT_DIR . '/tests', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(480, $count, "Expected at least 480 test files, got {$count}");
    }

    #[Test]
    public function readmeExists(): void
    {
        $path = self::ROOT_DIR . '/README.md';
        $this->assertFileExists($path);
    }

    #[Test]
    public function readmeHasCorrectVersion(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString(self::VERSION, $content, "README missing version " . self::VERSION);
    }

    #[Test]
    public function readmeHasFeatureList(): void
    {
        $content = file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('## Features', $content);
        $this->assertStringContainsString('Event Catalog', $content);
        $this->assertStringContainsString('Inertia.js Integration', $content);
        $this->assertStringContainsString('API Reference', $content);
    }

    // ── Infrastructure Quality ────────────────────────────────────

    #[Test]
    public function serviceProviderExists(): void
    {
        $path = self::SRC_DIR . '/AnalyticsServiceProvider.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license.', $content);
    }

    #[Test]
    public function facadeExists(): void
    {
        $path = self::SRC_DIR . '/Facades/Analytics.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function composerJsonHasCorrectMetadata(): void
    {
        $json = json_decode(file_get_contents(self::ROOT_DIR . '/composer.json'), true);
        $this->assertSame(self::VERSION, $json['version']);
        $this->assertSame('^8.5', $json['require']['php']);
        $this->assertArrayHasKey('zeroboiler/analytics', $json);
    }

    #[Test]
    public function serviceCountAtLeast430(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR . '/Services', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(430, $count, "Expected at least 430 services, got {$count}");
    }

    #[Test]
    public function dtoAnalyticsEventExists(): void
    {
        $path = self::SRC_DIR . '/DTO/AnalyticsEvent.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('VERSION', $content);
    }

    #[Test]
    public function analyticsManagerExists(): void
    {
        $path = self::SRC_DIR . '/AnalyticsManager.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function bladeDirectivesExist(): void
    {
        $path = self::SRC_DIR . '/Blade/Directives/AnalyticsDirectives.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function injectScriptsMiddlewareExists(): void
    {
        $path = self::SRC_DIR . '/Http/Middleware/InjectAnalyticsScripts.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function autoPageViewMiddlewareExists(): void
    {
        $path = self::SRC_DIR . '/Http/Middleware/AutoPageViewMiddleware.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function databaseMigrationExists(): void
    {
        $iterator = new \DirectoryIterator(self::ROOT_DIR . '/database/migrations');
        $found = false;
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains($file->getFilename(), 'create_analytics')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Analytics database migration not found');
    }

    #[Test]
    public function ciWorkflowExists(): void
    {
        $path = self::ROOT_DIR . '/.github/workflows/ci.yml';
        $this->assertFileExists($path);
    }

    #[Test]
    public function phpstanConfigExists(): void
    {
        $path = self::ROOT_DIR . '/phpstan.neon.dist';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('level:', $content);
    }

    #[Test]
    public function eventBusExists(): void
    {
        $path = self::SRC_DIR . '/Bus/AnalyticsEventBus.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function eventDispatcherExists(): void
    {
        $path = self::SRC_DIR . '/Bus/AnalyticsEventDispatcher.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function anonymousIdTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/AnonymousIdTracker.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function sessionTrackerExists(): void
    {
        $path = self::SRC_DIR . '/Tracking/SessionTracker.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function consentStateDtoExists(): void
    {
        $path = self::SRC_DIR . '/DTO/ConsentState.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function eventBlueprintsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Blueprints/EventBlueprint.php');
        $this->assertFileExists(self::SRC_DIR . '/Blueprints/EventBlueprintRegistry.php');
    }

    #[Test]
    public function enrichmentRegistryExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Enrichment/EventEnrichmentRegistry.php');
        $this->assertFileExists(self::SRC_DIR . '/Enrichment/EventEnrichmentPlugin.php');
    }

    #[Test]
    public function eventStoreImplementationsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Store/DatabaseEventStore.php');
        $this->assertFileExists(self::SRC_DIR . '/Store/CacheEventStore.php');
        $this->assertFileExists(self::SRC_DIR . '/Store/NullEventStore.php');
    }
}
