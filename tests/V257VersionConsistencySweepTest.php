<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * V257 — Version Consistency Sweep & Production Readiness Test.
 *
 * Validates that all 22 version entry points report 257.0.0 and
 * verifies the overall project quality metrics.
 *
 * @since 257.0.0
 */
final class V257VersionConsistencySweepTest extends TestCase
{
    private const EXPECTED_VERSION = '264.0.0';

    // ── Version Entry Points ───────────────────────────────────

    #[Test]
    public function analyticsEventVersionConstantMatches(): void
    {
        $this->assertSame(
            self::EXPECTED_VERSION,
            AnalyticsEvent::VERSION,
            'AnalyticsEvent::VERSION must match expected version',
        );
    }

    #[Test]
    public function composerJsonVersionMatches(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame(self::EXPECTED_VERSION, $composer['version']);
    }

    #[Test]
    public function packageJsonVersionMatches(): void
    {
        $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame(self::EXPECTED_VERSION, $pkg['version']);
    }

    #[Test]
    public function analyticsJsGetVersionReturnsCorrectVersion(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString(
            "return '" . self::EXPECTED_VERSION . "';",
            $content,
            'analytics.js getVersion() must return expected version',
        );
    }

    #[Test]
    public function analyticsJsHeaderVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString(
            '@version ' . self::EXPECTED_VERSION,
            $content,
            'analytics.js @version header must match',
        );
    }

    #[Test]
    public function analyticsDtVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        $this->assertStringContainsString(
            '@version ' . self::EXPECTED_VERSION,
            $content,
            'analytics.d.ts @version must match',
        );
    }

    #[Test]
    public function analyticsConstantsJsVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
        $this->assertStringContainsString(
            '@version ' . self::EXPECTED_VERSION,
            $content,
            'analytics.constants.js @version must match',
        );
    }

    #[Test]
    public function serviceProviderVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString(
            '@version ' . self::EXPECTED_VERSION,
            $content,
            'AnalyticsServiceProvider @version must match',
        );
    }

    #[Test]
    public function integrityCommandVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
        $this->assertStringContainsString(
            "EXPECTED_VERSION = '" . self::EXPECTED_VERSION . "'",
            $content,
            'AnalyticsIntegrityCommand EXPECTED_VERSION must match',
        );
    }

    #[Test]
    public function allSvelteComposablesVersionMatch(): void
    {
        $dir = __DIR__ . '/../resources/js/';
        $files = glob($dir . 'use*.svelte.js');
        $this->assertGreaterThanOrEqual(14, count($files), 'At least 14 Svelte composables expected');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);
            $this->assertStringContainsString(
                '@version ' . self::EXPECTED_VERSION,
                $content,
                "{$basename} must have @version " . self::EXPECTED_VERSION,
            );
        }
    }

    #[Test]
    public function readmeBadgeVersionMatches(): void
    {
        $content = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString(
            'badge/version-' . self::EXPECTED_VERSION,
            $content,
            'README version badge must match',
        );
    }

    // ── No Stale Versions ──────────────────────────────────────

    #[Test]
    public function noStaleVersionsInVersionEntryFiles(): void
    {
        $staleVersions = ['254.0.0', '255.0.0', '256.0.0'];
        $entryFiles = [
            'composer.json',
            'package.json',
            'src/DTO/AnalyticsEvent.php',
            'src/Console/Commands/AnalyticsIntegrityCommand.php',
            'src/AnalyticsServiceProvider.php',
            'resources/js/analytics.js',
            'resources/js/analytics.d.ts',
            'resources/js/analytics.constants.js',
        ];

        foreach ($entryFiles as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            foreach ($staleVersions as $stale) {
                // Check for version declarations (not @since annotations)
                $versionPattern = '/' . preg_quote($stale, '/') . '/';
                // Exclude @since lines
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $line) {
                    if (str_contains($line, $stale) && !str_contains($line, '@since')) {
                        $this->fail(
                            "Stale version {$stale} found in {$file} line " . ($lineNum + 1) . ": {$line}",
                        );
                    }
                }
            }
        }

        $this->assertTrue(true, 'No stale versions in entry files');
    }

    // ── Project Scale Thresholds ────────────────────────────────

    #[Test]
    public function sourceFileCountMeetsThreshold(): void
    {
        $count = $this->countPhpFiles(__DIR__ . '/../src');
        $this->assertGreaterThanOrEqual(980, $count, "Expected at least 980 source files, got {$count}");
    }

    #[Test]
    public function testFileCountMeetsThreshold(): void
    {
        $count = $this->countPhpFiles(__DIR__ . '/../tests');
        $this->assertGreaterThanOrEqual(500, $count, "Expected at least 500 test files, got {$count}");
    }

    #[Test]
    public function commandCountMeetsThreshold(): void
    {
        $count = glob(__DIR__ . '/../src/Console/Commands/*Command.php');
        $this->assertGreaterThanOrEqual(115, count($count), 'Expected at least 115 artisan commands');
    }

    #[Test]
    public function serviceCountMeetsThreshold(): void
    {
        $count = glob(__DIR__ . '/../src/Services/*Service.php');
        $this->assertGreaterThanOrEqual(330, count($count), 'Expected at least 330 services');
    }

    #[Test]
    public function svelteComposableCountMeetsThreshold(): void
    {
        $count = glob(__DIR__ . '/../resources/js/use*.svelte.js');
        $this->assertGreaterThanOrEqual(14, count($count), 'Expected at least 14 Svelte composables');
    }

    // ── 12-Feature SaaS Starter Verification ────────────────────

    #[Test]
    public function eventCatalogsExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(__DIR__ . '/../src/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(__DIR__ . '/../src/Events/Engagement/EngagementEvents.php');
    }

    #[Test]
    public function serverSideLifecycleTrackerExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Tracking/ServerSideTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/LifecycleEventMapper.php');
    }

    #[Test]
    public function inertiaMiddlewareExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Http/Middleware/InertiaAnalyticsMiddleware.php');
    }

    #[Test]
    public function apiControllerAndRoutesExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
        $this->assertFileExists(__DIR__ . '/../routes/analytics.php');
    }

    #[Test]
    public function jsClientLibraryExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.js');
        $this->assertFileExists(__DIR__ . '/../resources/js/analytics.d.ts');
    }

    #[Test]
    public function eventQueueJobsExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Jobs/TrackAnalyticsEventJob.php');
        $this->assertFileExists(__DIR__ . '/../src/Jobs/TrackAnalyticsEventBatchJob.php');
    }

    #[Test]
    public function identityServicesExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/IdentityGraphService.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/UserIdentityTracker.php');
    }

    #[Test]
    public function ecommerceHelpersExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/EcommerceFormatConverter.php');
        $this->assertFileExists(__DIR__ . '/../src/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function adminCommandsExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function configHasRequiredSections(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $requiredSections = ['queue', 'api', 'identity', 'auto_track', 'ecommerce', 'consent'];
        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey(
                $section,
                $config['analytics'],
                "Config must have '{$section}' section",
            );
        }
    }

    #[Test]
    public function optionalProvidersExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Trackers/PlausibleTracker.php');
        $this->assertFileExists(__DIR__ . '/../src/Trackers/PosthogTracker.php');
    }

    // ── Code Quality ────────────────────────────────────────────

    #[Test]
    public function allSourceFilesHaveStrictTypes(): void
    {
        $violations = $this->findFilesMissingStrictTypes(__DIR__ . '/../src');
        $this->assertEmpty(
            $violations,
            'All source files must have declare(strict_types=1). Violations: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function allSourceFilesHaveMitHeader(): void
    {
        $violations = $this->findFilesMissingMitHeader(__DIR__ . '/../src');
        $this->assertEmpty(
            $violations,
            'All source files must have MIT license header. Violations: ' . implode(', ', $violations),
        );
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

    /**
     * @return list<string>
     */
    private function findFilesMissingStrictTypes(string $directory): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if (!str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function findFilesMissingMitHeader(string $directory): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if (!str_contains($content, 'part of ZeroBoiler, licensed under the MIT license')) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        return $violations;
    }
}
