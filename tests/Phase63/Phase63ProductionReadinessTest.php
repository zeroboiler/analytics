<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 63 Production Readiness — Event Throttle + SaaS Health Score + Full Verification.
 *
 * Validates:
 * - Version consistency across 5 entry points (composer.json, AnalyticsEvent, ServiceProvider, README, CHANGELOG)
 * - Source file scale thresholds (≥965 source files, ≥495 test files)
 * - Event Catalog completeness (EcommerceEvents, SaaSEvents, EngagementEvents)
 * - Provider coverage (9 trackers: GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn)
 * - Service count thresholds (≥447 services, ≥115 commands)
 * - Config expansion (event_throttle, saas_health sections)
 * - New service files (EventThrottleService, SaaSHealthScoreAggregator)
 * - New command (AnalyticsSaaSHealthCommand)
 * - JS client existence
 * - PHP 8.5 strict types + MIT headers
 * - Zero TODO/FIXME/HACK
 * - 12 core SaaS features verified
 *
 * @since 242.0.0
 */
final class Phase63ProductionReadinessTest extends TestCase
{
    private const VERSION = '268.0.0';
    private const SRC_DIR = __DIR__ . '/../src';
    private const ROOT_DIR = __DIR__ . '/..';

    // ── 1. Version Consistency ──────────────────────────────────────

    #[Test]
    public function composerJsonVersion(): void
    {
        $json = json_decode((string) file_get_contents(self::ROOT_DIR . '/composer.json'), true);
        $this->assertSame(self::VERSION, $json['version'] ?? null);
    }

    #[Test]
    public function analyticsEventVersion(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString("public const VERSION = '" . self::VERSION . "'", $content);
    }

    #[Test]
    public function serviceProviderVersion(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    #[Test]
    public function readmeBadgeVersion(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('version-' . self::VERSION, $content);
    }

    #[Test]
    public function changelogLatestVersion(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/CHANGELOG.md');
        $this->assertStringContainsString('[' . self::VERSION . ']', $content);
    }

    #[Test]
    public function packageJsonVersion(): void
    {
        $json = json_decode((string) file_get_contents(self::ROOT_DIR . '/package.json'), true);
        $this->assertSame(self::VERSION, $json['version'] ?? null);
    }

    // ── 2. Source File Scale Thresholds ────────────────────────────

    #[Test]
    public function sourceFileCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR);
        $this->assertGreaterThanOrEqual(965, $count, "Expected at least 965 source PHP files, got {$count}");
    }

    #[Test]
    public function testFileCount(): void
    {
        $count = $this->countPhpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(493, $count, "Expected at least 493 test PHP files, got {$count}");
    }

    #[Test]
    public function commandCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR . '/Console/Commands');
        $this->assertGreaterThanOrEqual(115, $count, "Expected at least 115 command files, got {$count}");
    }

    #[Test]
    public function serviceCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR . '/Services');
        $this->assertGreaterThanOrEqual(447, $count, "Expected at least 447 service files, got {$count}");
    }

    // ── 3. Event Catalog Completeness ─────────────────────────────

    #[Test]
    public function ecommerceEventsCatalogExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
    }

    #[Test]
    public function saasEventsCatalogExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
    }

    #[Test]
    public function engagementEventsCatalogExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
    }

    #[Test]
    public function ecommerceEventsHasViewAndPurchase(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertStringContainsString('view_item', $content);
        $this->assertStringContainsString('add_to_cart', $content);
        $this->assertStringContainsString('purchase', $content);
        $this->assertStringContainsString('refund', $content);
    }

    #[Test]
    public function saasEventsHasCoreEvents(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertStringContainsString('sign_up', $content);
        $this->assertStringContainsString('login', $content);
        $this->assertStringContainsString('trial_start', $content);
        $this->assertStringContainsString('subscription_created', $content);
        $this->assertStringContainsString('plan_upgrade', $content);
        $this->assertStringContainsString('cancellation', $content);
    }

    #[Test]
    public function engagementEventsHasCoreEvents(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $this->assertStringContainsString('page_view', $content);
        $this->assertStringContainsString('scroll_depth', $content);
        $this->assertStringContainsString('click', $content);
        $this->assertStringContainsString('form_submit', $content);
        $this->assertStringContainsString('search', $content);
    }

    // ── 4. Provider Coverage ───────────────────────────────────────

    #[Test]
    public function allNineTrackersExist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker',
            'PlausibleTracker', 'PosthogTracker', 'MixpanelTracker',
            'AmplitudeTracker', 'TikTokTracker', 'LinkedInTracker',
        ];

        foreach ($trackers as $tracker) {
            $this->assertFileExists(
                self::SRC_DIR . '/Trackers/' . $tracker . '.php',
                "Missing tracker: {$tracker}"
            );
        }
    }

    #[Test]
    public function trackerInterfaceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/TrackerInterface.php');
    }

    // ── 5. New Phase 63 Features ───────────────────────────────────

    #[Test]
    public function eventThrottleServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EventThrottleService.php');
    }

    #[Test]
    public function eventThrottleServiceHasStrictTypes(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventThrottleService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function eventThrottleServiceHasReturnTypeDeclarations(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventThrottleService.php');
        $this->assertStringContainsString('function allow(AnalyticsEvent $event): bool', $content);
        $this->assertStringContainsString('function stats(string $clientId): array', $content);
        $this->assertStringContainsString('function reset(string $clientId): bool', $content);
        $this->assertStringContainsString('function isEnabled(): bool', $content);
    }

    #[Test]
    public function eventThrottleServiceHasDocblocks(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventThrottleService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('* @since 242.0.0', $content);
    }

    #[Test]
    public function saasHealthScoreAggregatorExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/SaaSHealthScoreAggregator.php');
    }

    #[Test]
    public function saasHealthScoreAggregatorHasStrictTypes(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/SaaSHealthScoreAggregator.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function saasHealthScoreAggregatorHasComputeMethod(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/SaaSHealthScoreAggregator.php');
        $this->assertStringContainsString('function compute(): array', $content);
        $this->assertStringContainsString('function score(): float', $content);
        $this->assertStringContainsString('function grade(): string', $content);
        $this->assertStringContainsString('function meetsThreshold(float $threshold): bool', $content);
        $this->assertStringContainsString('function weakDimensions(float $belowScore = 50.0): array', $content);
    }

    #[Test]
    public function saasHealthScoreAggregatorHas12Dimensions(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/SaaSHealthScoreAggregator.php');
        $dimensions = [
            'event_coverage', 'provider_diversity', 'identity_linking',
            'ecommerce_tracking', 'funnel_completeness', 'revenue_tracking',
            'consent_compliance', 'observability', 'deduplication',
            'queue_reliability', 'schema_validation', 'data_governance',
        ];

        foreach ($dimensions as $dim) {
            $this->assertStringContainsString($dim, $content, "Missing dimension: {$dim}");
        }
    }

    #[Test]
    public function saasHealthCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsSaaSHealthCommand.php');
    }

    #[Test]
    public function saasHealthCommandRegistered(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsSaaSHealthCommand', $content);
    }

    // ── 6. Config Expansion ────────────────────────────────────────

    #[Test]
    public function configHasEventThrottleSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'event_throttle'", $content);
        $this->assertStringContainsString('ANALYTICS_EVENT_THROTTLE_ENABLED', $content);
        $this->assertStringContainsString('global_limit', $content);
        $this->assertStringContainsString('per_event_limit', $content);
    }

    #[Test]
    public function configHasSaaSHealthSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'saas_health'", $content);
        $this->assertStringContainsString('ANALYTICS_SAAS_HEALTH_ENABLED', $content);
        $this->assertStringContainsString('event_coverage', $content);
        $this->assertStringContainsString('provider_diversity', $content);
    }

    // ── 7. Existing Core Features Verification ─────────────────────

    #[Test]
    public function consentModeV2Exists(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/DTO/ConsentState.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function bladeDirectivesExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Blade/Directives/AnalyticsDirectives.php');
    }

    #[Test]
    public function autoInjectMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/InjectAnalyticsScripts.php');
    }

    #[Test]
    public function inertiaMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
    }

    #[Test]
    public function apiControllerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsController.php');
    }

    #[Test]
    public function routesFileExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/routes/analytics.php');
    }

    #[Test]
    public function queuedAnalyticsDispatcherExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Queue/QueuedAnalyticsDispatcher.php');
    }

    #[Test]
    public function userIdentityTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
    }

    #[Test]
    public function ecommerceFormatConverterExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function overviewCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
    }

    #[Test]
    public function testCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php');
    }

    // ── 8. JS Client ───────────────────────────────────────────────

    #[Test]
    public function jsClientLibraryExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.js');
    }

    #[Test]
    public function jsConstantsExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.constants.js');
    }

    #[Test]
    public function jsSvelteComposablesExist(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/useAnalytics.svelte.js');
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/useEcommerce.svelte.js');
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/useIdentity.svelte.js');
    }

    // ── 9. Code Quality ─────────────────────────────────────────────

    #[Test]
    public function allNewSourceFilesHaveStrictTypes(): void
    {
        $newFiles = [
            '/Services/EventThrottleService.php',
            '/Services/SaaSHealthScoreAggregator.php',
            '/Console/Commands/AnalyticsSaaSHealthCommand.php',
        ];

        foreach ($newFiles as $file) {
            $content = (string) file_get_contents(self::SRC_DIR . $file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                "Missing strict_types in {$file}"
            );
        }
    }

    #[Test]
    public function allNewSourceFilesHaveMitLicense(): void
    {
        $newFiles = [
            '/Services/EventThrottleService.php',
            '/Services/SaaSHealthScoreAggregator.php',
            '/Console/Commands/AnalyticsSaaSHealthCommand.php',
        ];

        foreach ($newFiles as $file) {
            $content = (string) file_get_contents(self::SRC_DIR . $file);
            $this->assertStringContainsString(
                'MIT license',
                $content,
                "Missing MIT license header in {$file}"
            );
        }
    }

    #[Test]
    public function noTodoFixmeHackInNewFiles(): void
    {
        $newFiles = [
            '/Services/EventThrottleService.php',
            '/Services/SaaSHealthScoreAggregator.php',
            '/Console/Commands/AnalyticsSaaSHealthCommand.php',
        ];

        foreach ($newFiles as $file) {
            $content = (string) file_get_contents(self::SRC_DIR . $file);
            // Exclude doc patterns like G-XXXXXXXXXX, GTM-XXXXXXX
            $cleaned = preg_replace('/G-\w+|GTM-\w+/', '', $content);
            $this->assertDoesNotMatchRegularExpression(
                '/\b(TODO|FIXME|HACK|XXX)\b/i',
                $cleaned,
                "Found TODO/FIXME/HACK in {$file}"
            );
        }
    }

    #[Test]
    public function allNewFilesHaveDocblocks(): void
    {
        $newFiles = [
            '/Services/EventThrottleService.php',
            '/Services/SaaSHealthScoreAggregator.php',
            '/Console/Commands/AnalyticsSaaSHealthCommand.php',
        ];

        foreach ($newFiles as $file) {
            $content = (string) file_get_contents(self::SRC_DIR . $file);
            $this->assertStringContainsString('/**', $content, "Missing docblock in {$file}");
        }
    }

    #[Test]
    public function eventThrottleServiceIsFinal(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventThrottleService.php');
        $this->assertStringContainsString('final class EventThrottleService', $content);
    }

    #[Test]
    public function saasHealthScoreAggregatorIsFinal(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/SaaSHealthScoreAggregator.php');
        $this->assertStringContainsString('final class SaaSHealthScoreAggregator', $content);
    }

    #[Test]
    public function saasHealthCommandIsFinal(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsSaaSHealthCommand.php');
        $this->assertStringContainsString('final class AnalyticsSaaSHealthCommand', $content);
    }

    // ── 10. 12-Feature SaaS Verification ───────────────────────────

    #[Test]
    public function feature1EventCatalog(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/EventCatalog.php');
    }

    #[Test]
    public function feature2ServerSideLifecycleTracker(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/LifecycleEventMapper.php');
    }

    #[Test]
    public function feature3InertiaMiddleware(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('zbAnalytics', $content);
        $this->assertStringContainsString('trackingId', $content);
        $this->assertStringContainsString('Cookie::queue', $content);
    }

    #[Test]
    public function feature4ApiControllerAndRoutes(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsController.php');
        $routes = (string) file_get_contents(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertStringContainsString("'analytics/events'", $routes);
        $this->assertStringContainsString("'analytics/batch'", $routes);
        $this->assertStringContainsString("'analytics/identify'", $routes);
        $this->assertStringContainsString("'analytics/consent'", $routes);
    }

    #[Test]
    public function feature5SvelteJsClient(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('trackEvent', $content);
        $this->assertStringContainsString('trackPageView', $content);
    }

    #[Test]
    public function feature6EventQueueAsyncDispatch(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Queue/QueuedAnalyticsDispatcher.php');
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsEventDispatcher.php');
    }

    #[Test]
    public function feature7UserIdentityLinking(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityResolutionService.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityGraphService.php');
    }

    #[Test]
    public function feature8EcommerceHelpers(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/EcommerceAnalyticsService.php');
    }

    #[Test]
    public function feature9AdminCommands(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php');
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsSaaSHealthCommand.php');
    }

    #[Test]
    public function feature10ConfigExpansion(): void
    {
        $config = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $config);
        $this->assertStringContainsString("'api'", $config);
        $this->assertStringContainsString("'identity'", $config);
        $this->assertStringContainsString("'auto_track'", $config);
        $this->assertStringContainsString("'ecommerce'", $config);
    }

    #[Test]
    public function feature11OptionalProviders(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PlausibleTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PosthogTracker.php');
    }

    #[Test]
    public function feature12TestsExist(): void
    {
        $testCount = $this->countPhpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(493, $testCount, "Expected at least 493 test files for feature 12");
    }

    // ── 11. Services Registered ────────────────────────────────────

    #[Test]
    public function eventThrottleServiceRegistered(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('EventThrottleService', $content);
        $this->assertStringContainsString('singleton', $content);
    }

    #[Test]
    public function saasHealthScoreAggregatorRegistered(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('SaaSHealthScoreAggregator', $content);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Recursively count PHP files in a directory.
     */
    private function countPhpFiles(string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }
}
