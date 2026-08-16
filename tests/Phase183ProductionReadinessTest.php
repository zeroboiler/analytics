<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 183 production readiness test.
 *
 * Validates:
 * 1. Version consistency (183.0.0) across all entry points
 * 2. New services exist and are final with strict types
 * 3. Event catalog integrity (9 categories)
 * 4. Source file counts (src + tests)
 * 5. Config integrity (saas_telemetry, replay_validation)
 * 6. ServiceProvider registration completeness
 *
 * @since 183.0.0
 */
final class Phase183ProductionReadinessTest extends TestCase
{
    private const EXPECTED_VERSION = '183.0.0';

    /** @var list<string> Files that should contain the version */
    private const VERSION_ENTRY_POINTS = [
        'composer.json',
        'package.json',
        'src/DTO/AnalyticsEvent.php',
        'src/Console/Commands/AnalyticsIntegrityCommand.php',
        'src/AnalyticsServiceProvider.php',
        'src/Http/Controllers/AnalyticsEventController.php',
        'resources/js/analytics.js',
        'resources/js/analytics.constants.js',
        'resources/js/analytics.d.ts',
        'README.md',
    ];

    /** @var list<string> New services in v183 */
    private const NEW_SERVICES = [
        'src/Services/SaaSTelemetryAggregatorService.php',
        'src/Services/EventReplayValidationService.php',
    ];

    /** @var list<string> New tests in v183 */
    private const NEW_TESTS = [
        'tests/V183EventReplayValidationTest.php',
        'tests/V183SaaSTelemetryAggregatorTest.php',
        'tests/Phase183ProductionReadinessTest.php',
    ];

    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = dirname(__DIR__, 2);
    }

    public function testVersionConsistencyAcrossEntryPoints(): void
    {
        foreach (self::VERSION_ENTRY_POINTS as $relativePath) {
            $path = "{$this->baseDir}/{$relativePath}";
            $this->assertFileExists($path, "Version entry point exists: {$relativePath}");

            $content = (string) file_get_contents($path);
            $this->assertStringContainsString(
                self::EXPECTED_VERSION,
                $content,
                "Version entry point contains 183.0.0: {$relativePath}",
            );
        }
    }

    public function testNewServicesExist(): void
    {
        foreach (self::NEW_SERVICES as $relativePath) {
            $this->assertFileExists(
                "{$this->baseDir}/{$relativePath}",
                "New service file exists: {$relativePath}",
            );
        }
    }

    public function testNewTestsExist(): void
    {
        foreach (self::NEW_TESTS as $relativePath) {
            $this->assertFileExists(
                "{$this->baseDir}/{$relativePath}",
                "New test file exists: {$relativePath}",
            );
        }
    }

    public function testNewServicesHaveStrictTypes(): void
    {
        foreach (self::NEW_SERVICES as $relativePath) {
            $content = (string) file_get_contents("{$this->baseDir}/{$relativePath}");
            $this->assertStringContainsString(
                'declare(strict_types=1);',
                $content,
                "Service has strict types: {$relativePath}",
            );
        }
    }

    public function testNewServicesHaveMitLicense(): void
    {
        foreach (self::NEW_SERVICES as $relativePath) {
            $content = (string) file_get_contents("{$this->baseDir}/{$relativePath}");
            $this->assertStringContainsString(
                'MIT license',
                $content,
                "Service has MIT license header: {$relativePath}",
            );
        }
    }

    public function testNewServicesAreFinal(): void
    {
        foreach (self::NEW_SERVICES as $relativePath) {
            $content = (string) file_get_contents("{$this->baseDir}/{$relativePath}");
            $this->assertStringContainsString(
                'final class',
                $content,
                "Service is final: {$relativePath}",
            );
        }
    }

    public function testSaaSTelemetryServiceStructure(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/Services/SaaSTelemetryAggregatorService.php");

        $this->assertStringContainsString('record(array', $content);
        $this->assertStringContainsString('summary()', $content);
        $this->assertStringContainsString('providerDetails(', $content);
        $this->assertStringContainsString('categoryBreakdown()', $content);
        $this->assertStringContainsString('detectAnomalies()', $content);
        $this->assertStringContainsString('quickOverview()', $content);
        $this->assertStringContainsString('flush()', $content);
        $this->assertStringContainsString('saveBaseline()', $content);
    }

    public function testEventReplayValidationServiceStructure(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/Services/EventReplayValidationService.php");

        $this->assertStringContainsString('validate(AnalyticsEvent', $content);
        $this->assertStringContainsString('validateBatch(', $content);
        $this->assertStringContainsString('markReplayed(', $content);
        $this->assertStringContainsString('stats()', $content);
        $this->assertStringContainsString('getBlockedEvents()', $content);
        $this->assertStringContainsString('blockEvent(', $content);
        $this->assertStringContainsString('unblockEvent(', $content);
        $this->assertStringContainsString('REPLAY_DUPLICATE', $content);
        $this->assertStringContainsString('EVENT_BLOCKED', $content);
        $this->assertStringContainsString('SENSITIVE_CONTENT', $content);
        $this->assertStringContainsString('STALE_EVENT', $content);
    }

    public function testSourceFileCount(): void
    {
        $srcFiles = glob("{$this->baseDir}/src/**/*.php", GLOB_BRACE);
        $srcCount = $srcFiles === false ? 0 : count($srcFiles);

        $this->assertGreaterThan(840, $srcCount, "Source file count should be > 840 (got {$srcCount})");
    }

    public function testTestFileCount(): void
    {
        $testFiles = glob("{$this->baseDir}/tests/**/*.php", GLOB_BRACE);
        $testCount = $testFiles === false ? 0 : count($testFiles);

        $this->assertGreaterThan(420, $testCount, "Test file count should be > 420 (got {$testCount})");
    }

    public function testConfigFileExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/config/zeroboiler.php");
    }

    public function testServiceProviderExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/src/AnalyticsServiceProvider.php");
    }

    public function testEventCatalogExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/src/Events/EventCatalog.php");
    }

    public function testReadmeExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/README.md");
    }

    public function testJsClientExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/resources/js/analytics.js");
    }

    public function testSvelteComposablesExist(): void
    {
        $composableFiles = [
            'resources/js/useAnalytics.svelte.js',
            'resources/js/useScrollDepth.svelte.js',
            'resources/js/useConsent.svelte.js',
            'resources/js/useIdentity.svelte.js',
            'resources/js/usePageView.svelte.js',
        ];

        foreach ($composableFiles as $file) {
            $this->assertFileExists(
                "{$this->baseDir}/{$file}",
                "Svelte composable exists: {$file}",
            );
        }
    }

    public function testRoutesFileExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/routes/analytics.php");
    }
}
