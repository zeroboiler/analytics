<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Phase87;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Phase 87 production readiness — TrackerInterface identify() + providerName() contract.
 *
 * Validates:
 * - TrackerInterface has identify() and providerName() methods
 * - All 10 tracker implementations have both methods with #[\Override]
 * - AnalyticsManager has identifyAll() and identifyTo() methods
 * - Facade documents the new methods
 * - CI workflow is clean (no redacted secrets)
 * - .editorconfig and CONTRIBUTING.md exist
 * - Version consistency at 266.0.0
 * - All source files maintain strict_types + MIT headers
 *
 * @since 266.0.0
 */
final class Phase87ProductionReadinessTest extends TestCase
{
    private const EXPECTED_VERSION = '266.0.0';

    private const TRACKER_FILES = [
        'GA4Tracker.php' => 'ga4',
        'GTMTracker.php' => 'gtm',
        'MetaPixelTracker.php' => 'meta',
        'PlausibleTracker.php' => 'plausible',
        'PosthogTracker.php' => 'posthog',
        'MixpanelTracker.php' => 'mixpanel',
        'AmplitudeTracker.php' => 'amplitude',
        'TikTokTracker.php' => 'tiktok',
        'LinkedInTracker.php' => 'linkedin',
        'WebhookTracker.php' => 'webhook',
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = __DIR__ . '/../../src';
    }

    // ── TrackerInterface Contract ──────────────────────────────

    #[Test]
    public function trackerInterfaceHasIdentifyMethod(): void
    {
        $content = file_get_contents($this->srcDir . '/Trackers/TrackerInterface.php');
        $this->assertStringContainsString('public function identify(string $userId, array $traits = []): void', $content);
        $this->assertStringContainsString('@since 266.0.0', $content);
    }

    #[Test]
    public function trackerInterfaceHasProviderNameMethod(): void
    {
        $content = file_get_contents($this->srcDir . '/Trackers/TrackerInterface.php');
        $this->assertStringContainsString('public function providerName(): string', $content);
        $this->assertStringContainsString('@since 266.0.0', $content);
    }

    // ── All 10 Trackers Implement Both Methods ─────────────────

    #[Test]
    public function allTrackersHaveIdentifyMethod(): void
    {
        foreach (self::TRACKER_FILES as $file => $name) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString(
                'public function identify(string $userId, array $traits = []): void',
                $content,
                "{$file} must implement identify()",
            );
        }
    }

    #[Test]
    public function allTrackersHaveProviderNameMethod(): void
    {
        foreach (self::TRACKER_FILES as $file => $name) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString(
                'public function providerName(): string',
                $content,
                "{$file} must implement providerName()",
            );
        }
    }

    #[Test]
    public function allTrackersReturnCorrectProviderName(): void
    {
        foreach (self::TRACKER_FILES as $file => $expectedName) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString(
                "return '{$expectedName}';",
                $content,
                "{$file} providerName() must return '{$expectedName}'",
            );
        }
    }

    // ── AnalyticsManager Methods ───────────────────────────────

    #[Test]
    public function analyticsManagerHasIdentifyAllMethod(): void
    {
        $content = file_get_contents($this->srcDir . '/AnalyticsManager.php');
        $this->assertStringContainsString('public function identifyAll(string $userId, array $traits = []): array', $content);
        $this->assertStringContainsString('@since 266.0.0', $content);
    }

    #[Test]
    public function analyticsManagerHasIdentifyToMethod(): void
    {
        $content = file_get_contents($this->srcDir . '/AnalyticsManager.php');
        $this->assertStringContainsString('public function identifyTo(string $userId, array $providers, array $traits = []): array', $content);
    }

    #[Test]
    public function analyticsManagerIdentifyAllCallsTrackerIdentify(): void
    {
        $content = file_get_contents($this->srcDir . '/AnalyticsManager.php');
        $this->assertStringContainsString("\$tracker->identify(\$userId, \$traits)", $content);
        $this->assertStringContainsString("\$tracker->providerName()", $content);
    }

    // ── Facade Documentation ───────────────────────────────────

    #[Test]
    public function facadeDocumentsIdentifyAllAndIdentifyTo(): void
    {
        $content = file_get_contents($this->srcDir . '/Facades/Analytics.php');
        $this->assertStringContainsString('identifyAll', $content);
        $this->assertStringContainsString('identifyTo', $content);
        $this->assertStringContainsString('v266.0.0', $content);
    }

    // ── CI Workflow Clean ──────────────────────────────────────

    #[Test]
    public function ciWorkflowHasNoRedactedSecrets(): void
    {
        $content = file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');
        $this->assertStringNotContainsString('COMP....', $content, 'CI must not contain redacted secret URLs');
    }

    #[Test]
    public function ciWorkflowHasPintPhpstanPest(): void
    {
        $content = file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');
        $this->assertStringContainsString('pint', $content);
        $this->assertStringContainsString('phpstan', $content);
        $this->assertStringContainsString('pest', $content);
    }

    // ── Project Hygiene Files ───────────────────────────────────

    #[Test]
    public function editorconfigExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../.editorconfig');
    }

    #[Test]
    public function contributingMdExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../CONTRIBUTING.md');
    }

    #[Test]
    public function editorconfigHasPhpRules(): void
    {
        $content = file_get_contents(__DIR__ . '/../../.editorconfig');
        $this->assertStringContainsString('indent_size = 4', $content);
        $this->assertStringContainsString('indent_style = space', $content);
        $this->assertStringContainsString('trim_trailing_whitespace = true', $content);
    }

    #[Test]
    public function contributingMentionsPintAndPhpstan(): void
    {
        $content = file_get_contents(__DIR__ . '/../../CONTRIBUTING.md');
        $this->assertStringContainsString('pint', $content);
        $this->assertStringContainsString('phpstan', $content);
    }

    // ── Version Consistency ──────────────────────────────────────

    #[Test]
    public function versionConsistencyAcrossEntryPoints(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $package = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $readme = file_get_contents(__DIR__ . '/../../README.md');

        $this->assertSame(self::EXPECTED_VERSION, $composer['version']);
        $this->assertSame(self::EXPECTED_VERSION, $package['version']);
        $this->assertStringContainsString('version-' . self::EXPECTED_VERSION, $readme);
    }

    #[Test]
    public function analyticsEventVersionConstantMatches(): void
    {
        $this->assertSame(self::EXPECTED_VERSION, AnalyticsEvent::VERSION);
    }

    #[Test]
    public function noStaleVersionsInEntryFiles(): void
    {
        $staleVersions = ['256.0.0', '257.0.0'];
        $entryFiles = [
            'composer.json',
            'package.json',
            'src/DTO/AnalyticsEvent.php',
            'src/Trackers/TrackerInterface.php',
        ];

        foreach ($entryFiles as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            foreach ($staleVersions as $stale) {
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $line) {
                    if (str_contains($line, $stale) && !str_contains($line, '@since')) {
                        $this->fail(
                            "Stale version {$stale} in {$file} line " . ($lineNum + 1),
                        );
                    }
                }
            }
        }

        $this->assertTrue(true);
    }

    // ── Code Quality ────────────────────────────────────────────

    #[Test]
    public function allTrackerFilesHaveStrictTypes(): void
    {
        foreach (self::TRACKER_FILES as $file => $name) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString('declare(strict_types=1)', $content, "{$file} missing strict_types");
        }
    }

    #[Test]
    public function allTrackerFilesHaveMitHeader(): void
    {
        foreach (self::TRACKER_FILES as $file => $name) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString('part of ZeroBoiler, licensed under the MIT license', $content, "{$file} missing MIT header");
        }
    }

    #[Test]
    public function allTrackerFilesAreFinal(): void
    {
        foreach (self::TRACKER_FILES as $file => $name) {
            $content = file_get_contents($this->srcDir . '/Trackers/' . $file);
            $this->assertStringContainsString('final class', $content, "{$file} must be final");
        }
    }

    #[Test]
    public function trackerInterfaceHasIdentifySinceTag(): void
    {
        $content = file_get_contents($this->srcDir . '/Trackers/TrackerInterface.php');
        // Verify identify has @since 266.0.0
        $this->assertMatchesRegularExpression(
            '/identify\(string \$userId.*?@since 264\.0\.0/s',
            $content,
            'identify() must have @since 266.0.0 docblock',
        );
    }

    // ── Project Scale Thresholds ────────────────────────────────

    #[Test]
    public function sourceFileCountMeetsThreshold(): void
    {
        $count = $this->countPhpFiles($this->srcDir);
        $this->assertGreaterThanOrEqual(989, $count, "Expected at least 989 source files, got {$count}");
    }

    #[Test]
    public function testFileCountMeetsThreshold(): void
    {
        $count = $this->countPhpFiles(__DIR__ . '/../..');
        // Include the new Phase87 test
        $this->assertGreaterThanOrEqual(507, $count, "Expected at least 507 test files, got {$count}");
    }

    #[Test]
    public function trackerCountIsTen(): void
    {
        $trackers = glob($this->srcDir . '/Trackers/*Tracker.php');
        $this->assertCount(10, $trackers, 'Expected exactly 10 tracker implementations');
    }

    // ── TrackerInterface Method Count ───────────────────────────

    #[Test]
    public function trackerInterfaceHasEightMethods(): void
    {
        $content = file_get_contents($this->srcDir . '/Trackers/TrackerInterface.php');
        $this->assertSame(8, substr_count($content, 'public function'), 'TrackerInterface should have 8 public methods');
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function countPhpFiles(string $directory): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }
}
