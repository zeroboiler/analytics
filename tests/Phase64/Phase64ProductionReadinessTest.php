<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 64 Production Readiness — Event Catalog Diff, Quality Gate, Batch Dispatch.
 *
 * Validates the analytics package meets industry-standard SaaS starter criteria
 * with up-to-date metrics: source files, test files, services, commands,
 * event catalog completeness, provider coverage, batch tracking interface,
 * catalog diff service, quality gate command, and all 12 core SaaS features.
 *
 * @since 243.0.0
 */
final class Phase64ProductionReadinessTest extends TestCase
{
    private const VERSION = '243.0.0';
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
        $this->assertGreaterThanOrEqual(975, $count, "Expected at least 975 source PHP files, got {$count}");
    }

    #[Test]
    public function testFileCount(): void
    {
        $count = $this->countPhpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(494, $count, "Expected at least 494 test PHP files, got {$count}");
    }

    #[Test]
    public function commandCount(): void
    {
        $count = $this->countFiles(self::SRC_DIR . '/Console/Commands');
        $this->assertGreaterThanOrEqual(116, $count, "Expected at least 116 command files, got {$count}");
    }

    #[Test]
    public function serviceCount(): void
    {
        $count = $this->countFiles(self::SRC_DIR . '/Services');
        $this->assertGreaterThanOrEqual(448, $count, "Expected at least 448 service files, got {$count}");
    }

    #[Test]
    public function trackerCount(): void
    {
        $count = $this->countFiles(self::SRC_DIR . '/Trackers');
        $this->assertGreaterThanOrEqual(12, $count, "Expected at least 12 tracker files, got {$count}");
    }

    // ── 3. New Service: Event Catalog Diff ─────────────────────────

    #[Test]
    public function eventCatalogDiffServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
    }

    #[Test]
    public function eventCatalogDiffServiceHasStrictTypes(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function eventCatalogDiffServiceHasFinalClass(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('final class EventCatalogDiffService', $content);
    }

    #[Test]
    public function eventCatalogDiffServiceHasDocblock(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 243.0.0', $content);
    }

    #[Test]
    public function eventCatalogDiffServiceKeyMethods(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('public function takeSnapshot', $content);
        $this->assertStringContainsString('public function diff(', $content);
        $this->assertStringContainsString('public function diffAgainst(', $content);
        $this->assertStringContainsString('public function hasSnapshot', $content);
        $this->assertStringContainsString('public function getSnapshot', $content);
        $this->assertStringContainsString('public function clearSnapshot', $content);
        $this->assertStringContainsString('public function hasChanged', $content);
        $this->assertStringContainsString('public function categoryCounts', $content);
        $this->assertStringContainsString('public function currentCatalog', $content);
    }

    #[Test]
    public function eventCatalogDiffServiceReturnTypeDeclarations(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('public function takeSnapshot(): array', $content);
        $this->assertStringContainsString('public function hasSnapshot(): bool', $content);
        $this->assertStringContainsString('public function clearSnapshot(): bool', $content);
        $this->assertStringContainsString('public function hasChanged(): bool', $content);
    }

    #[Test]
    public function eventCatalogDiffServiceRenameDetection(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Services/EventCatalogDiffService.php');
        $this->assertStringContainsString('detectRenames', $content);
        $this->assertStringContainsString('levenshteinDistance', $content);
    }

    // ── 4. New Command: Quality Gate ─────────────────────────────────

    #[Test]
    public function qualityGateCommandExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php');
    }

    #[Test]
    public function qualityGateCommandHasStrictTypes(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function qualityGateCommandSignature(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php');
        $this->assertStringContainsString("zb:analytics:quality-gate", $content);
        $this->assertStringContainsString('--json', $content);
        $this->assertStringContainsString('--fail-level', $content);
        $this->assertStringContainsString('--snapshot', $content);
        $this->assertStringContainsString('--check', $content);
        $this->assertStringContainsString('--min-coverage', $content);
    }

    #[Test]
    public function qualityGateCommandCheckTypes(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php');
        $this->assertStringContainsString('checkSchemaCoverage', $content);
        $this->assertStringContainsString('checkCatalogDiff', $content);
        $this->assertStringContainsString('checkCompliance', $content);
        $this->assertStringContainsString('checkProviderCoverage', $content);
        $this->assertStringContainsString('checkDeduplication', $content);
    }

    #[Test]
    public function qualityGateCommandDocblock(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php');
        $this->assertStringContainsString('@since 243.0.0', $content);
    }

    // ── 5. Batch Tracking Interface ─────────────────────────────────

    #[Test]
    public function trackerInterfaceHasBatchMethod(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/TrackerInterface.php');
        $this->assertStringContainsString('public function trackBatch(array $events): int', $content);
    }

    #[Test]
    public function trackerHelpersHasDefaultBatch(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/TrackerHelpers.php');
        $this->assertStringContainsString('defaultTrackBatch', $content);
    }

    #[Test]
    public function ga4TrackerHasBatch(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/GA4Tracker.php');
        $this->assertStringContainsString('public function trackBatch(array $events): int', $content);
    }

    #[Test]
    public function metaPixelTrackerHasBatch(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/MetaPixelTracker.php');
        $this->assertStringContainsString('public function trackBatch(array $events): int', $content);
    }

    #[Test]
    public function posthogTrackerHasBatch(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/PosthogTracker.php');
        $this->assertStringContainsString('public function trackBatch(array $events): int', $content);
    }

    #[Test]
    public function allTrackersImplementBatch(): void
    {
        $trackers = [
            'GA4Tracker.php',
            'GTMTracker.php',
            'MetaPixelTracker.php',
            'PlausibleTracker.php',
            'PosthogTracker.php',
            'MixpanelTracker.php',
            'AmplitudeTracker.php',
            'TikTokTracker.php',
            'LinkedInTracker.php',
            'WebhookTracker.php',
        ];

        foreach ($trackers as $tracker) {
            $content = (string) file_get_contents(self::SRC_DIR . '/Trackers/' . $tracker);
            $this->assertStringContainsString(
                'trackBatch',
                $content,
                "Tracker {$tracker} missing trackBatch()",
            );
        }
    }

    #[Test]
    public function serviceProviderRegistersQualityGateCommand(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsQualityGateCommand', $content);
    }

    #[Test]
    public function serviceProviderRegistersCatalogDiffService(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('EventCatalogDiffService', $content);
    }

    // ── 6. Config Expansion ─────────────────────────────────────────

    #[Test]
    public function configHasCatalogDiffSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'catalog_diff'", $content);
    }

    #[Test]
    public function configHasQualityGateSection(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'quality_gate'", $content);
    }

    // ── 7. Event Catalog Completeness ───────────────────────────────

    #[Test]
    public function eventCatalogCategories(): void
    {
        $content = (string) file_get_contents(self::SRC_DIR . '/Events/EventCatalog.php');
        $categories = ['EcommerceEvents', 'SaaSEvents', 'EngagementEvents', 'SecurityEvents', 'UptimeEvents', 'InfrastructureEvents', 'MarketingEvents', 'CustomerSuccessEvents', 'WebhookEvents'];

        foreach ($categories as $cat) {
            $this->assertStringContainsString($cat, $content, "Missing category {$cat}");
        }
    }

    #[Test]
    public function eventCatalogDirectoryExists(): void
    {
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Ecommerce');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/SaaS');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Engagement');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Security');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Uptime');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Infrastructure');
        $this->assertDirectoryExists(self::SRC_DIR . '/Events/Marketing');
    }

    // ── 8. All 12 Core SaaS Features ───────────────────────────────

    #[Test]
    public function feature1EventCatalog(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
    }

    #[Test]
    public function feature2ServerSideLifecycle(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/LifecycleEventMapper.php');
    }

    #[Test]
    public function feature3InertiaMiddleware(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
    }

    #[Test]
    public function feature4ApiController(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsEventController.php');
    }

    #[Test]
    public function feature5JsClient(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.js');
    }

    #[Test]
    public function feature6EventQueue(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Queue/QueuedAnalyticsDispatcher.php');
        $this->assertFileExists(self::SRC_DIR . '/Jobs/TrackAnalyticsEventJob.php');
    }

    #[Test]
    public function feature7IdentityLinking(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
    }

    #[Test]
    public function feature8EcommerceHelpers(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function feature9AdminCommands(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function feature10ConfigExpansion(): void
    {
        $content = (string) file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $content);
        $this->assertStringContainsString("'identity'", $content);
        $this->assertStringContainsString("'auto_track'", $content);
        $this->assertStringContainsString("'ecommerce'", $content);
    }

    #[Test]
    public function feature11OptionalProviders(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PlausibleTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PosthogTracker.php');
    }

    #[Test]
    public function feature12TestsAndReadme(): void
    {
        $this->assertDirectoryExists(self::ROOT_DIR . '/tests');
        $this->assertFileExists(self::ROOT_DIR . '/README.md');
        $readme = (string) file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('Quick Start', $readme);
    }

    // ── 9. Code Quality ─────────────────────────────────────────────

    #[Test]
    public function allSourceFilesHaveStrictTypes(): void
    {
        $violations = $this->findFilesMissingStrictTypes(self::SRC_DIR);
        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', array_slice($violations, 0, 10)),
        );
    }

    #[Test]
    public function newFilesHaveMitHeader(): void
    {
        $files = [
            self::SRC_DIR . '/Services/EventCatalogDiffService.php',
            self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php',
        ];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'This file is part of ZeroBoiler, licensed under the MIT license',
                $content,
                basename($file) . ' missing MIT header',
            );
        }
    }

    #[Test]
    public function noTODOInNewFiles(): void
    {
        $files = [
            self::SRC_DIR . '/Services/EventCatalogDiffService.php',
            self::SRC_DIR . '/Console/Commands/AnalyticsQualityGateCommand.php',
        ];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/\b(TODO|FIXME|HACK|XXX)\b/i',
                $content,
                basename($file) . ' contains TODO/FIXME',
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function countPhpFiles(string $dir): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }

    private function countFiles(string $dir): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function findFilesMissingStrictTypes(string $dir): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getPathname();
            }
        }

        return $violations;
    }
}
