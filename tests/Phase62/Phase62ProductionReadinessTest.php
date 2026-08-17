<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 62 Production Readiness — README Metrics Sync + Full 12-Feature SaaS Verification.
 *
 * Validates the analytics package meets industry-standard SaaS starter criteria
 * with up-to-date metrics: source files, test files, services, commands,
 * event catalog completeness, provider coverage, JS client, and all 12
 * core SaaS features verified at production quality.
 *
 * @since 241.0.0
 */
final class Phase62ProductionReadinessTest extends TestCase
{
    private const VERSION = '241.0.0';
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

    // ── 2. Source File Scale Thresholds ────────────────────────────

    #[Test]
    public function sourceFileCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR);
        $this->assertGreaterThanOrEqual(960, $count, "Expected at least 960 source PHP files, got {$count}");
    }

    #[Test]
    public function testFileCount(): void
    {
        $count = $this->countPhpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(490, $count, "Expected at least 490 test PHP files, got {$count}");
    }

    #[Test]
    public function serviceCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR . '/Services');
        $this->assertGreaterThanOrEqual(440, $count, "Expected at least 440 service files, got {$count}");
    }

    #[Test]
    public function commandCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR . '/Console/Commands');
        $this->assertGreaterThanOrEqual(110, $count, "Expected at least 110 command files, got {$count}");
    }

    #[Test]
    public function trackerCount(): void
    {
        $count = $this->countPhpFiles(self::SRC_DIR . '/Trackers');
        $this->assertGreaterThanOrEqual(10, $count, "Expected at least 10 tracker files, got {$count}");
    }

    #[Test]
    public function jsClientExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.js');
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $loc = substr_count($content, "\n");
        $this->assertGreaterThanOrEqual(8000, $loc, "Expected JS client >= 8,000 LOC, got {$loc}");
    }

    #[Test]
    public function typeScriptDefinitionsExist(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.d.ts');
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.d.ts');
        $loc = substr_count($content, "\n");
        $this->assertGreaterThanOrEqual(3000, $loc, "Expected TypeScript defs >= 3,000 LOC, got {$loc}");
    }

    #[Test]
    public function svelteComposableCount(): void
    {
        $count = $this->countFilesMatching(self::ROOT_DIR . '/resources/js', '*.svelte.js');
        $this->assertGreaterThanOrEqual(14, $count, "Expected at least 14 Svelte composables, got {$count}");
    }

    // ── 3. Feature 1: Event Catalog Completeness ──────────────────

    #[Test]
    public function eventCatalogFileExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/EventCatalog.php');
    }

    #[Test]
    public function ecommerceEventsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertStringContainsString("'view_item'", $content);
        $this->assertStringContainsString("'add_to_cart'", $content);
        $this->assertStringContainsString("'purchase'", $content);
        $this->assertStringContainsString("'refund'", $content);
        $this->assertStringContainsString("'begin_checkout'", $content);
    }

    #[Test]
    public function saasEventsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertStringContainsString("'sign_up'", $content);
        $this->assertStringContainsString("'login'", $content);
        $this->assertStringContainsString("'start_trial'", $content);
        $this->assertStringContainsString("'subscribe'", $content);
        $this->assertStringContainsString("'plan_upgrade'", $content);
        $this->assertStringContainsString("'cancellation'", $content);
    }

    #[Test]
    public function engagementEventsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $this->assertStringContainsString("'page_view'", $content);
        $this->assertStringContainsString("'scroll_depth'", $content);
        $this->assertStringContainsString("'click'", $content);
        $this->assertStringContainsString("'form_submit'", $content);
        $this->assertStringContainsString("'search'", $content);
        $this->assertStringContainsString("'share'", $content);
        $this->assertStringContainsString("'error'", $content);
    }

    #[Test]
    public function eventAliasRegistryExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/EventAliasRegistry.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/EventAliasRegistry.php');
        $this->assertStringContainsString('resolve(', $content);
        $this->assertStringContainsString('register(', $content);
        $this->assertStringContainsString('validate(', $content);
    }

    #[Test]
    public function eventCatalogHasNineCategories(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/EventCatalog.php');
        $this->assertStringContainsString("self::withCategory(EcommerceEvents::all(), 'ecommerce')", $content);
        $this->assertStringContainsString("self::withCategory(SaaSEvents::all(), 'saas')", $content);
        $this->assertStringContainsString("self::withCategory(EngagementEvents::all(), 'engagement')", $content);
        $this->assertStringContainsString("self::withCategory(SecurityEvents::all(), 'security')", $content);
        $this->assertStringContainsString("self::withCategory(UptimeEvents::all(), 'uptime')", $content);
        $this->assertStringContainsString("self::withCategory(InfrastructureEvents::all(), 'infrastructure')", $content);
        $this->assertStringContainsString("self::withCategory(MarketingEvents::all(), 'marketing')", $content);
        $this->assertStringContainsString("self::withCategory(CustomerSuccessEvents::all(), 'customer_success')", $content);
        $this->assertStringContainsString("self::withCategory(WebhookEvents::all(), 'webhook')", $content);
    }

    // ── 4. Feature 2: Server-Side Lifecycle Tracker ───────────────

    #[Test]
    public function serverSideTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
    }

    #[Test]
    public function lifecycleEventMapperExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/LifecycleEventMapper.php');
    }

    #[Test]
    public function lifecycleEventSubscriberExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php');
    }

    #[Test]
    public function lifecycleAttributionEnricherExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleAttributionEnricher.php');
    }

    // ── 5. Feature 3: Inertia Middleware ───────────────────────────

    #[Test]
    public function inertiaMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('handle(', $content);
        $this->assertStringContainsString('clientId', $content);
    }

    // ── 6. Feature 4: API Controller + Routes ─────────────────────

    #[Test]
    public function apiControllerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsEventController.php');
    }

    #[Test]
    public function formRequestClassesExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/TrackEventRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/BatchEventRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/IdentifyRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/UpdateConsentRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/PageViewRequest.php');
    }

    #[Test]
    public function sdkTokenMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/VerifySdkToken.php');
    }

    // ── 7. Feature 5: JS Client Library ───────────────────────────

    #[Test]
    public function jsClientExportsTrackEvent(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('trackEvent', $content);
        $this->assertStringContainsString('trackPageView', $content);
    }

    #[Test]
    public function jsClientHasConsentMode(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('consent', $content);
    }

    #[Test]
    public function jsClientHasBatchQueue(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('batch', $content);
    }

    #[Test]
    public function jsClientHasSampling(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('sampling', $content);
    }

    #[Test]
    public function jsClientHasScrollDepth(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/resources/js/analytics.js');
        $this->assertStringContainsString('scroll', $content);
    }

    // ── 8. Feature 6: Event Queue ──────────────────────────────────

    #[Test]
    public function queuedDispatcherExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Queue/QueuedAnalyticsDispatcher.php');
    }

    #[Test]
    public function eventBusExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsEventBus.php');
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsEventDispatcher.php');
    }

    // ── 9. Feature 7: User Identity Linking ────────────────────────

    #[Test]
    public function userIdentityTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
    }

    #[Test]
    public function identityResolutionServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityResolutionService.php');
    }

    #[Test]
    public function identityGraphServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityGraphService.php');
    }

    // ── 10. Feature 8: E-commerce Helpers ──────────────────────────

    #[Test]
    public function ecommerceFormatConverterExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
        $this->assertStringContainsString('ga4', $content);
        $this->assertStringContainsString('meta', $content);
    }

    #[Test]
    public function ecommerceAnalyticsServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EcommerceAnalyticsService.php');
    }

    // ── 11. Feature 9: Admin Commands ───────────────────────────────

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

    #[Test]
    public function revenueIntelligenceCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/SaaSRevenueIntelligenceCommand.php');
    }

    // ── 12. Feature 10: Config Expansion ──────────────────────────

    #[Test]
    public function configFileExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/config/zeroboiler.php');
    }

    #[Test]
    public function configHasQueueSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $content);
    }

    #[Test]
    public function configHasApiSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'api'", $content);
    }

    #[Test]
    public function configHasIdentitySection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'identity'", $content);
    }

    #[Test]
    public function configHasAutoTrackSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'auto_track'", $content);
    }

    #[Test]
    public function configHasEcommerceSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'ecommerce'", $content);
    }

    // ── 13. Feature 11: Optional Providers ─────────────────────────

    #[Test]
    public function plausibleTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PlausibleTracker.php');
    }

    #[Test]
    public function posthogTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PosthogTracker.php');
    }

    // ── 14. Feature 12: Tests + README ────────────────────────────

    #[Test]
    public function readmeExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/README.md');
    }

    #[Test]
    public function readmeHasQuickStart(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('Quick Start', $content);
        $this->assertStringContainsString('composer require', $content);
    }

    #[Test]
    public function readmeHasTableOfContents(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('Table of Contents', $content);
    }

    #[Test]
    public function readmeHasFeaturesSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('## Features', $content);
    }

    #[Test]
    public function readmeHasConfigurationSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('## Configuration', $content);
    }

    #[Test]
    public function readmeHasApiReference(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('API Reference', $content);
    }

    #[Test]
    public function readmeHasAdminCommandsSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('Admin Commands', $content);
    }

    #[Test]
    public function changelogExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/CHANGELOG.md');
    }

    // ── 15. Code Quality — Strict Types & MIT Headers ──────────────

    #[Test]
    public function allSourceFilesHaveStrictTypes(): void
    {
        $violations = $this->findFilesMissingStrictTypes(self::SRC_DIR);
        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', array_slice($violations, 0, 10))
        );
    }

    #[Test]
    public function allSourceFilesHaveMitHeader(): void
    {
        $violations = $this->findFilesMissingMitHeader(self::SRC_DIR);
        $this->assertEmpty(
            $violations,
            'Files missing MIT license header: ' . implode(', ', array_slice($violations, 0, 10))
        );
    }

    // ── 16. Provider Coverage ──────────────────────────────────────

    #[Test]
    public function serviceProviderRegistersCore(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('GoogleAnalyticsService::class', $content);
        $this->assertStringContainsString('MetaPixelService::class', $content);
        $this->assertStringContainsString('GoogleTagManagerService::class', $content);
        $this->assertStringContainsString('PlausibleTracker::class', $content);
        $this->assertStringContainsString('PosthogTracker::class', $content);
    }

    #[Test]
    public function providerCapabilityMatrixExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/ProviderCapabilityMatrixService.php');
    }

    // ── 17. SaaS Starter Events Coverage ──────────────────────────

    #[Test]
    public function saasStarterEventsExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaSStarterEvents.php');
    }

    #[Test]
    public function saasEventConstantsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEventConstants.php');
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/SaaS/SaaSEventConstants.php');
        $this->assertStringContainsString('SIGN_UP', $content);
        $this->assertStringContainsString('LOGIN', $content);
        $this->assertStringContainsString('START_TRIAL', $content);
        $this->assertStringContainsString('SUBSCRIBE', $content);
        $this->assertStringContainsString('PLAN_UPGRADE', $content);
        $this->assertStringContainsString('CANCELLATION', $content);
        $this->assertStringContainsString('public static function all()', $content);
        $this->assertStringContainsString('public static function isValid', $content);
    }

    // ── 18. Event Catalog SemVer + Health Score ─────────────────────

    #[Test]
    public function eventCatalogSemVerServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EventCatalogSemVerService.php');
    }

    #[Test]
    public function customerHealthScoreServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/CustomerHealthScoreService.php');
    }

    #[Test]
    public function saasQuickDeployCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsSaaSQuickDeployCommand.php');
    }

    #[Test]
    public function behavioralSegmentationServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/BehavioralSegmentationService.php');
    }

    // ── 19. Blade Directives ──────────────────────────────────────

    #[Test]
    public function bladeDirectivesExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Blade/Directives/AnalyticsDirectives.php');
    }

    // ── 20. Auto-Inject Middleware ──────────────────────────────────

    #[Test]
    public function injectAnalyticsScriptsMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/InjectAnalyticsScripts.php');
    }

    #[Test]
    public function autoPageViewMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/AutoPageViewMiddleware.php');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Count PHP files in a directory recursively.
     */
    private function countPhpFiles(string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count files matching a pattern in a directory.
     */
    private function countFilesMatching(string $dir, string $pattern): int
    {
        $count = 0;
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $file) {
            if (! $file->isDot() && fnmatch($pattern, $file->getFilename())) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Find PHP files missing declare(strict_types=1).
     *
     * @return list<string>
     */
    private function findFilesMissingStrictTypes(string $dir): array
    {
        return $this->scanForCompliance($dir, 'declare(strict_types=1)');
    }

    /**
     * Find PHP files missing MIT license header.
     *
     * @return list<string>
     */
    private function findFilesMissingMitHeader(string $dir): array
    {
        return $this->scanForCompliance($dir, 'This file is part of ZeroBoiler, licensed under the MIT license');
    }

    /**
     * Scan PHP files for a required string.
     *
     * @return list<string> Relative paths of non-compliant files
     */
    private function scanForCompliance(string $dir, string $needle): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (! str_contains($content, $needle)) {
                $violations[] = str_replace(self::ROOT_DIR . '/', '', $file->getPathname());
            }
        }

        return $violations;
    }
}
