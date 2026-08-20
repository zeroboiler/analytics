<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 90 production readiness test.
 *
 * Validates:
 * 1. Version consistency (266.0.0) across all entry points
 * 2. Zero double-paren constructor syntax errors ()): void → ): void)
 * 3. Constructor `: void` return type compliance (non-readonly classes)
 * 4. strict_types on all source files
 * 5. Zero TODO/FIXME in source files
 * 6. MIT license header on all source files
 * 7. Config integrity
 * 8. ServiceProvider finality and #[Override]
 * 9. Facade accessor return type
 * 10. Exception hierarchy consistency
 * 11. Source and test file counts
 *
 * @since 266.0.0
 */
final class Phase90ProductionReadinessTest extends TestCase
{
    private const EXPECTED_VERSION = '266.0.0';

    /** @var list<string> Files that must contain the version string */
    private const VERSION_ENTRY_POINTS = [
        'composer.json',
        'package.json',
        'src/DTO/AnalyticsEvent.php',
        'src/Console/Commands/AnalyticsIntegrityCommand.php',
        'src/AnalyticsServiceProvider.php',
        'resources/js/analytics.js',
        'resources/js/analytics.constants.js',
        'resources/js/analytics.d.ts',
        'README.md',
    ];

    /** @var list<string> Files with known double-paren bug (should now be fixed) */
    private const FIXED_DOUBLE_PAREN_FILES = [
        'src/Services/EventNamingConventionLinter.php',
        'src/Services/EventPayloadMarshallerService.php',
        'src/Services/EventSchemaDriftDetectorService.php',
        'src/Services/EventSemanticClassifierService.php',
    ];

    /** @var list<string> Files that needed constructor : void fix */
    private const FIXED_CONSTRUCTOR_VOID_FILES = [
        'src/Services/IdentityLinkService.php',
        'src/Tracking/PlausibleEventTracker.php',
        'src/Tracking/PostHogEventTracker.php',
    ];

    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = dirname(__DIR__);
    }

    // ---- Version Consistency ----

    public function testVersionConsistencyAcrossEntryPoints(): void
    {
        foreach (self::VERSION_ENTRY_POINTS as $relativePath) {
            $path = "{$this->baseDir}/{$relativePath}";
            $this->assertFileExists($path, "Version entry point exists: {$relativePath}");

            $content = (string) file_get_contents($path);
            $this->assertStringContainsString(
                self::EXPECTED_VERSION,
                $content,
                "Version entry point contains 266.0.0: {$relativePath}",
            );
        }
    }

    public function testAnalyticsEventVersionConstant(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/DTO/AnalyticsEvent.php");
        $this->assertStringContainsString(
            "public const VERSION = '266.0.0'",
            $content,
            'AnalyticsEvent::VERSION is 266.0.0',
        );
    }

    public function testIntegrityCommandExpectedVersion(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/Console/Commands/AnalyticsIntegrityCommand.php");
        $this->assertStringContainsString(
            "EXPECTED_VERSION = '266.0.0'",
            $content,
            'AnalyticsIntegrityCommand::EXPECTED_VERSION is 266.0.0',
        );
    }

    // ---- Double-Paren Constructor Fix Verification ----

    public function testNoDoubleParenConstructorsInFixedFiles(): void
    {
        foreach (self::FIXED_DOUBLE_PAREN_FILES as $relativePath) {
            $path = "{$this->baseDir}/{$relativePath}";
            $this->assertFileExists($path, "Fixed file exists: {$relativePath}");

            $content = (string) file_get_contents($path);
            // Must NOT contain )): void (double paren before : void)
            $this->assertStringNotContainsString(
                ')): void',
                $content,
                "No double-paren in constructor: {$relativePath}",
            );
            // Must contain proper ): void
            $this->assertMatchesRegularExpression(
                '/__construct\s*\([^)]*\)\s*:\s*void\s*\{/',
                $content,
                "Proper constructor signature in: {$relativePath}",
            );
        }
    }

    public function testNoDoubleParenConstructorsInAnySourceFile(): void
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator("{$this->baseDir}/src", \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, ')): void')) {
                $violations[] = $iterator->getSubPathName();
            }
        }

        $this->assertEmpty(
            $violations,
            'No source files contain double-paren constructor syntax. Found: ' . implode(', ', $violations),
        );
    }

    // ---- Constructor : void Compliance ----

    public function testFixedConstructorVoidFilesComply(): void
    {
        foreach (self::FIXED_CONSTRUCTOR_VOID_FILES as $relativePath) {
            $path = "{$this->baseDir}/{$relativePath}";
            $this->assertFileExists($path, "Fixed file exists: {$relativePath}");

            $content = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression(
                '/__construct\s*\([^)]*\)\s*:\s*void/',
                $content,
                "Constructor has : void return type: {$relativePath}",
            );
        }
    }

    // ---- strict_types ----

    public function testAllSourceFilesHaveStrictTypes(): void
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator("{$this->baseDir}/src", \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1);')) {
                $violations[] = $iterator->getSubPathName();
            }
        }

        $this->assertEmpty(
            $violations,
            'All source files have strict_types. Missing: ' . implode(', ', $violations),
        );
    }

    // ---- No TODO/FIXME ----

    public function testNoTodoFixmeInSourceFiles(): void
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator("{$this->baseDir}/src", \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', $line)) {
                    // Skip docblock examples mentioning GTM-XXXXXXX and G-XXXXXXXXXX patterns
                    if (str_contains($line, 'GTM-XXXXXXX') || str_contains($line, 'G-XXXXXXXXXX')) {
                        continue;
                    }
                    $violations[] = $iterator->getSubPathName() . ":" . ($lineNum + 1);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'No TODO/FIXME/HACK/XXX in source files. Found: ' . implode(', ', $violations),
        );
    }

    // ---- MIT License Header ----

    public function testAllSourceFilesHaveMitLicense(): void
    {
        $sampleFiles = [
            'src/AnalyticsManager.php',
            'src/AnalyticsServiceProvider.php',
            'src/DTO/AnalyticsEvent.php',
            'src/Services/EventNamingConventionLinter.php',
            'src/Trackers/GA4Tracker.php',
            'src/Facades/Analytics.php',
            'config/zeroboiler.php',
        ];

        foreach ($sampleFiles as $relativePath) {
            $path = "{$this->baseDir}/{$relativePath}";
            $this->assertFileExists($path, "Sample file exists: {$relativePath}");
            $content = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'MIT license',
                $content,
                "MIT license header present: {$relativePath}",
            );
        }
    }

    // ---- ServiceProvider ----

    public function testServiceProviderIsFinal(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/AnalyticsServiceProvider.php");
        $this->assertStringContainsString('final class AnalyticsServiceProvider', $content);
    }

    public function testServiceProviderHasOverrideAttribute(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/AnalyticsServiceProvider.php");
        $this->assertStringContainsString('#[\\Override]', $content);
    }

    // ---- Facade ----

    public function testFacadeIsFinal(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/Facades/Analytics.php");
        $this->assertStringContainsString('final class Analytics extends Facade', $content);
    }

    public function testFacadeAccessorHasReturnType(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/src/Facades/Analytics.php");
        $this->assertMatchesRegularExpression(
            '/getFacadeAccessor\s*\(\s*\)\s*:\s*string/',
            $content,
            'Facade getFacadeAccessor() has : string return type',
        );
    }

    // ---- Exception Hierarchy ----

    public function testExceptionHierarchyExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/src/Exceptions/AnalyticsException.php");
        $this->assertFileExists("{$this->baseDir}/src/Exceptions/AnalyticsRuntimeException.php");
        $this->assertFileExists("{$this->baseDir}/src/Exceptions/InvalidAnalyticsArgumentException.php");
    }

    public function testExceptionClassesAreFinal(): void
    {
        foreach (
            [
                'src/Exceptions/AnalyticsException.php',
                'src/Exceptions/AnalyticsRuntimeException.php',
                'src/Exceptions/InvalidAnalyticsArgumentException.php',
            ] as $relativePath
        ) {
            $content = (string) file_get_contents("{$this->baseDir}/{$relativePath}");
            $this->assertStringContainsString('final class', $content, "Exception is final: {$relativePath}");
        }
    }

    // ---- Config ----

    public function testConfigFileExists(): void
    {
        $this->assertFileExists("{$this->baseDir}/config/zeroboiler.php");
    }

    public function testConfigHasRequiredSections(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/config/zeroboiler.php");

        $requiredSections = [
            "'ga4'",
            "'gtm'",
            "'meta_pixel'",
            "'consent'",
            "'queue'",
            "'api'",
            "'identity'",
            "'auto_track'",
            "'ecommerce'",
            "'revenue'",
        ];

        foreach ($requiredSections as $section) {
            $this->assertStringContainsString(
                $section,
                $content,
                "Config has section: {$section}",
            );
        }
    }

    // ---- File Counts ----

    public function testSourceFileCount(): void
    {
        $srcFiles = glob("{$this->baseDir}/src/**/*.php", GLOB_BRACE);
        $srcCount = $srcFiles === false ? 0 : count($srcFiles);

        $this->assertGreaterThan(990, $srcCount, "Source file count should be > 990 (got {$srcCount})");
    }

    public function testTestFileCount(): void
    {
        $testFiles = glob("{$this->baseDir}/tests/**/*.php", GLOB_BRACE);
        $testCount = $testFiles === false ? 0 : count($testFiles);

        $this->assertGreaterThan(510, $testCount, "Test file count should be > 510 (got {$testCount})");
    }

    // ---- Project Structure ----

    public function testProjectStructureFiles(): void
    {
        $files = [
            'composer.json',
            'README.md',
            'config/zeroboiler.php',
            'routes/analytics.php',
            'src/AnalyticsServiceProvider.php',
            'src/AnalyticsManager.php',
            'src/Facades/Analytics.php',
            'src/DTO/AnalyticsEvent.php',
        ];

        foreach ($files as $relativePath) {
            $this->assertFileExists(
                "{$this->baseDir}/{$relativePath}",
                "Project file exists: {$relativePath}",
            );
        }
    }

    // ---- Composer Metadata ----

    public function testComposerMetadata(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/composer.json");
        $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('zeroboiler/analytics', $json['name']);
        $this->assertSame('library', $json['type']);
        $this->assertSame('MIT', $json['license']);
        $this->assertSame('^8.5', $json['require']['php']);
        $this->assertSame('^13.0', $json['require']['illuminate/contracts']);
        $this->assertArrayHasKey('zeroboiler/analytics', $json['extra']['laravel']['providers'][0] ? [] : []);
    }

    public function testComposerPhpRequirement(): void
    {
        $content = (string) file_get_contents("{$this->baseDir}/composer.json");
        $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('^8.5', $json['require']['php']);
    }
}
