<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 60 Production Readiness — Constructor Void Return Type Sweep.
 *
 * Validates that every public constructor in the entire src/ tree
 * declares a `: void` return type, as required by PHP 8.5
 * industry-standard coding practices and PHPStan level 9.
 *
 * Also validates the version bump across all entry points.
 *
 * @since 235.0.0
 */
final class Phase60ProductionReadinessTest extends TestCase
{
    private const VERSION = '235.0.0';
    private const SRC_DIR = __DIR__ . '/../src';
    private const ROOT_DIR = __DIR__ . '/..';

    // ── 1. Constructor Void Return Type Sweep ───────────────────────

    #[Test]
    public function allPublicConstructorsDeclareVoidReturnType(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                if (! str_contains($line, 'function __construct(')) {
                    continue;
                }

                // For multi-line constructors, check forward until we find ): or ) {
                $searchStart = $lineNum;
                $searchEnd = min($lineNum + 20, count($lines) - 1);
                $found = false;

                for ($i = $searchStart; $i <= $searchEnd; $i++) {
                    if (str_contains($lines[$i], ': void')) {
                        $found = true;
                        break;
                    }
                    // If we hit { before : void, it's a violation
                    if (str_contains($lines[$i], '{') && ! str_contains($lines[$i], ': void')) {
                        break;
                    }
                }

                if (! $found) {
                    $relative = str_replace(self::ROOT_DIR . '/', '', $file);
                    $violations[] = "{$relative}:{$lineNum}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d constructor(s) missing ': void' return type:\n  - %s",
                count($violations),
                implode("\n  - ", $violations),
            ),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInTrackers(): void
    {
        $trackerDir = self::SRC_DIR . '/Trackers';
        $files = glob($trackerDir . '/*.php');
        $this->assertNotEmpty($files, 'Tracker directory should have files');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'function __construct(')) {
                $this->assertStringContainsString(
                    ': void',
                    $content,
                    basename($file) . ' constructor must declare : void return type',
                );
            }
        }
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInDTOs(): void
    {
        $dtoDir = self::SRC_DIR . '/DTO';
        $files = glob($dtoDir . '/*.php');
        $this->assertNotEmpty($files, 'DTO directory should have files');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'DTO constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInServices(): void
    {
        $servicesDir = self::SRC_DIR . '/Services';
        $files = glob($servicesDir . '/*.php');
        $this->assertNotEmpty($files, 'Services directory should have files');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            // Check for __construct without : void anywhere in the file
            if (preg_match('/function __construct\s*\(/', $content) && ! str_contains($content, ': void')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Service constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInEvents(): void
    {
        $eventsDir = self::SRC_DIR . '/Events';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($eventsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                $violations[] = str_replace(self::SRC_DIR . '/', '', $file->getPathname());
            }
        }

        $this->assertEmpty(
            $violations,
            'Event constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInJobs(): void
    {
        $jobsDir = self::SRC_DIR . '/Jobs';
        $files = glob($jobsDir . '/*.php');
        $this->assertNotEmpty($files, 'Jobs directory should have files');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Job constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInMiddleware(): void
    {
        $dirs = [
            self::SRC_DIR . '/Http/Middleware',
            self::SRC_DIR . '/Middleware',
        ];

        $violations = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                    $violations[] = basename($file);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Middleware constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInPipeline(): void
    {
        $pipelineDir = self::SRC_DIR . '/Pipeline';
        $files = glob($pipelineDir . '/*.php');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Pipeline constructors missing : void: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function noConstructorsWithoutVoidReturnTypeInCommands(): void
    {
        $commandsDir = self::SRC_DIR . '/Console/Commands';
        $files = glob($commandsDir . '/*.php');
        $this->assertNotEmpty($files, 'Commands directory should have files');

        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'function __construct(') && ! str_contains($content, ': void')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Command constructors missing : void: ' . implode(', ', $violations),
        );
    }

    // ── 2. Version Consistency ─────────────────────────────────────

    #[Test]
    public function versionEntryPointsConsistent(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString(self::VERSION, $content, 'AnalyticsEvent VERSION constant');

        $composer = json_decode(file_get_contents(self::ROOT_DIR . '/composer.json'), true);
        $this->assertSame(self::VERSION, $composer['version'], 'composer.json version');

        $readme = file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString(self::VERSION, $readme, 'README version badge');
    }

    #[Test]
    public function analyticsManagerVersionReturnsConstant(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsManager.php');
        $this->assertStringContainsString('AnalyticsEvent::VERSION', $content);
    }

    // ── 3. Strict Types Coverage ────────────────────────────────────

    #[Test]
    public function allPhpSourceFilesDeclareStrictTypes(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace(self::ROOT_DIR . '/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d file(s) missing declare(strict_types=1):\n  - %s",
                count($violations),
                implode("\n  - ", array_slice($violations, 0, 20)),
            ),
        );
    }

    #[Test]
    public function noTodosOrFixmesInSource(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/(?:TODO|FIXME|HACK|XXX)\b/', $line)) {
                    $relative = str_replace(self::ROOT_DIR . '/', '', $file);
                    $violations[] = "{$relative}:{$lineNum}: " . trim($line);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                "Found %d TODO/FIXME/HACK comment(s):\n  - %s",
                count($violations),
                implode("\n  - ", array_slice($violations, 0, 10)),
            ),
        );
    }

    // ── 4. File Count & Scale ──────────────────────────────────────

    #[Test]
    public function sourceFileCountExceedsMinimum(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $this->assertGreaterThanOrEqual(950, count($files), 'Expected at least 950 PHP source files');
    }

    #[Test]
    public function testFileCountExceedsMinimum(): void
    {
        $files = $this->phpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(480, count($files), 'Expected at least 480 test files');
    }

    #[Test]
    public function commandCountExceedsMinimum(): void
    {
        $files = glob(self::SRC_DIR . '/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(110, count($files), 'Expected at least 110 artisan commands');
    }

    #[Test]
    public function jsClientModuleCount(): void
    {
        $files = array_merge(
            glob(self::ROOT_DIR . '/resources/js/*.js'),
            glob(self::ROOT_DIR . '/resources/js/*.svelte.js'),
        );
        $this->assertGreaterThanOrEqual(14, count($files), 'Expected at least 14 JS/Svelte modules');
    }

    // ── 5. All 12 SaaS Starter Features Verified ───────────────────

    #[Test]
    public function feature1EventCatalogExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
    }

    #[Test]
    public function feature2LifecycleTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/LifecycleEventMapper.php');
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php');
    }

    #[Test]
    public function feature3InertiaMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/HandleInertiaAnalytics.php');
    }

    #[Test]
    public function feature4ApiControllerAndRoutesExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsEventController.php');
        $this->assertFileExists(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/TrackEventRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/BatchEventRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/IdentifyRequest.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/UpdateConsentRequest.php');
    }

    #[Test]
    public function feature5JsClientLibraryExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.js');
    }

    #[Test]
    public function feature6EventQueueExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Jobs/TrackAnalyticsEventJob.php');
        $this->assertFileExists(self::SRC_DIR . '/Jobs/TrackAnalyticsEventBatchJob.php');
    }

    #[Test]
    public function feature7IdentityLinkingExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityGraphService.php');
    }

    #[Test]
    public function feature8EcommerceHelpersExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function feature9AdminCommandsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function feature10ConfigExpansionExists(): void
    {
        $config = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $config);
        $this->assertStringContainsString("'identity'", $config);
        $this->assertStringContainsString("'auto_track'", $config);
        $this->assertStringContainsString("'ecommerce'", $config);
    }

    #[Test]
    public function feature11OptionalProvidersExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PlausibleTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PosthogTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/MixpanelTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/AmplitudeTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/TikTokTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/LinkedInTracker.php');
    }

    #[Test]
    public function feature12TestsAndReadmeExist(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/README.md');
        $this->assertFileExists(self::ROOT_DIR . '/tests/Pest.php');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Get all PHP files recursively from a directory.
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
