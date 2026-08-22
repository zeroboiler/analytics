<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\V260;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 89 — Constructor `: void` Return Type Sweep.
 *
 * Validates that all non-readonly constructors in the src/ tree
 * declare an explicit `: void` return type, as required by PHP 8.5
 * strict mode and CONTRIBUTING.md project standards.
 *
 * @since 260.0.0
 */
#[Group('quality')]
final class V260Phase89ConstructorVoidSweepTest extends TestCase
{
    /**
     * Test that the 4 fixed constructors now declare `: void`.
     */
    public function testFixedConstructorsDeclareVoid(): void
    {
        $files = [
            'src/Http/Middleware/InertiaAnalyticsMiddleware.php',
            'src/Queue/AnalyticsQueueService.php',
            'src/Queue/DispatchAnalyticsJob.php',
            'src/Queue/BatchDispatchAnalyticsJob.php',
        ];

        foreach ($files as $file) {
            $path = base_path($file);
            $this->assertFileExists($path, "{$file} should exist");

            $contents = file_get_contents($path);
            $this->assertNotEmpty($contents, "{$file} should not be empty");
        }

        // InertiaAnalyticsMiddleware
        $inertia = file_get_contents(base_path('src/Http/Middleware/InertiaAnalyticsMiddleware.php'));
        $this->assertStringContainsString(
            'public function __construct(
        private AnalyticsManager $manager,
        ConfigRepository $config,
    ) {',
            $inertia,
            'InertiaAnalyticsMiddleware constructor must declare : void',
        );

        // AnalyticsQueueService
        $queue = file_get_contents(base_path('src/Queue/AnalyticsQueueService.php'));
        $this->assertStringContainsString(
            "private AnalyticsManager \$manager,\n        ConfigRepository \$config,\n    ): void {",
            $queue,
            'AnalyticsQueueService constructor must declare : void',
        );

        // DispatchAnalyticsJob
        $dispatch = file_get_contents(base_path('src/Queue/DispatchAnalyticsJob.php'));
        $this->assertStringContainsString(
            "private ?string \$userId = null,\n    ): void {",
            $dispatch,
            'DispatchAnalyticsJob constructor must declare : void',
        );

        // BatchDispatchAnalyticsJob
        $batch = file_get_contents(base_path('src/Queue/BatchDispatchAnalyticsJob.php'));
        $this->assertStringContainsString(
            "private ?string \$userId = null,\n    ): void {",
            $batch,
            'BatchDispatchAnalyticsJob constructor must declare : void',
        );
    }

    /**
     * Test that no non-readonly constructors in src/ are missing `: void`.
     *
     * Scans all PHP files in src/ for public constructors and verifies
     * that every non-promoted-property constructor declares `: void`.
     */
    public function testNoMissingConstructorVoidInSrc(): void
    {
        $missing = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('src'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $lines = explode("\n", $contents);

            foreach ($lines as $i => $line) {
                if (strpos($line, 'public function __construct(') === false) {
                    continue;
                }

                // Find closing parenthesis of parameter list
                $j = $i;
                $depth = substr_count($line, '(') - substr_count($line, ')');
                while ($depth > 0 && $j < count($lines) - 1) {
                    $j++;
                    $depth += substr_count($lines[$j], '(') - substr_count($lines[$j], ')');
                }

                $closingLine = $lines[$j];

                // Skip if has readonly promoted properties (PHP allows no return type)
                $block = implode("\n", array_slice($lines, $i, $j - $i + 1));
                if (strpos($block, 'readonly') !== false) {
                    continue;
                }

                if (strpos($closingLine, ': void') === false) {
                    $relativePath = str_replace(base_path() . '/', '', $file->getPathname());
                    $missing[] = "{$relativePath}:" . ($j + 1);
                }
            }
        }

        $this->assertEmpty(
            $missing,
            'Found constructors without : void return type: ' . implode(', ', $missing),
        );
    }

    /**
     * Test version consistency across all entry points.
     */
    public function testVersionConsistency(): void
    {
        $version = '260.0.0';

        // composer.json
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertSame($version, $composer['version'] ?? null, 'composer.json version');

        // package.json
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);
        $this->assertSame($version, $pkg['version'] ?? null, 'package.json version');

        // AnalyticsEvent::VERSION
        $eventFile = file_get_contents(base_path('src/DTO/AnalyticsEvent.php'));
        $this->assertStringContainsString("VERSION = '{$version}'", $eventFile, 'AnalyticsEvent::VERSION');

        // Integrity command
        $integrity = file_get_contents(base_path('src/Console/Commands/AnalyticsIntegrityCommand.php'));
        $this->assertStringContainsString("EXPECTED_VERSION = '{$version}'", $integrity, 'IntegrityCommand EXPECTED_VERSION');
    }

    /**
     * Test that all files in src/ have strict_types=1.
     */
    public function testAllSrcFilesHaveStrictTypes(): void
    {
        $missing = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('src'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (strpos($contents, "declare(strict_types=1)") === false) {
                $relativePath = str_replace(base_path() . '/', '', $file->getPathname());
                $missing[] = $relativePath;
            }
        }

        $this->assertEmpty(
            $missing,
            'Found files without declare(strict_types=1): ' . implode(', ', $missing),
        );
    }

    /**
     * Test project scale thresholds.
     */
    public function testProjectScaleThresholds(): void
    {
        $srcCount = 0;
        $testCount = 0;
        $commandCount = 0;
        $serviceCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('src'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $srcCount++;
                if (str_contains($file->getPathname(), 'Console/Commands')) {
                    $commandCount++;
                }
                if (str_contains($file->getPathname(), 'Services')) {
                    $serviceCount++;
                }
            }
        }

        $testIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($testIterator as $file) {
            if ($file->getExtension() === 'php') {
                $testCount++;
            }
        }

        $this->assertGreaterThan(980, $srcCount, "Source files should exceed 980, got {$srcCount}");
        $this->assertGreaterThan(500, $testCount, "Test files should exceed 500, got {$testCount}");
        $this->assertGreaterThan(115, $commandCount, "Commands should exceed 115, got {$commandCount}");
        $this->assertGreaterThan(450, $serviceCount, "Services should exceed 450, got {$serviceCount}");
    }

    /**
     * Test all 12 SaaS starter feature classes exist.
     */
    public function testAll12SaaSStarterFeaturesExist(): void
    {
        $features = [
            // 1. Event Catalog
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
            // 2. Server-Side Lifecycle Tracker
            'src/Tracking/LifecycleEventTracker.php',
            'src/Tracking/ServerSideTracker.php',
            'src/Services/LifecycleEventMapper.php',
            // 3. Inertia middleware
            'src/Http/Middleware/InertiaAnalyticsMiddleware.php',
            // 4. API controller + routes
            'src/Http/Controllers/AnalyticsEventController.php',
            'routes/analytics.php',
            // 5. JS client library
            'resources/js/analytics.js',
            'resources/js/analytics.d.ts',
            // 6. Event queue
            'src/Queue/AnalyticsQueueService.php',
            'src/Queue/QueuedAnalyticsDispatcher.php',
            'src/Queue/DispatchAnalyticsJob.php',
            'src/Queue/BatchDispatchAnalyticsJob.php',
            // 7. User identity linking
            'src/Tracking/UserIdentityTracker.php',
            'src/Services/IdentityGraphService.php',
            'src/Services/IdentityResolutionService.php',
            // 8. E-commerce helpers
            'src/Support/EcommerceFormatConverter.php',
            // 9. Admin commands
            'src/Console/Commands/AnalyticsOverviewCommand.php',
            'src/Console/Commands/AnalyticsTestCommand.php',
            // 10. Config expansion
            'config/zeroboiler.php',
            // 11. Optional providers
            'src/Tracking/PlausibleTracker.php',
            'src/Tracking/PostHogTracker.php',
            // 12. Tests + README
            'README.md',
        ];

        foreach ($features as $feature) {
            $this->assertFileExists(
                base_path($feature),
                "SaaS starter feature file must exist: {$feature}",
            );
        }
    }

    /**
     * Test JS client version consistency.
     */
    public function testJsClientVersionConsistency(): void
    {
        $version = '260.0.0';

        $jsFiles = glob(base_path('resources/js/*.js'));
        $this->assertNotEmpty($jsFiles, 'JS modules should exist');

        foreach ($jsFiles as $file) {
            $contents = file_get_contents($file);
            if (str_contains($contents, '@version')) {
                $this->assertStringContainsString(
                    "@version {$version}",
                    $contents,
                    basename($file) . ' should declare @version ' . $version,
                );
            }
        }

        // TypeScript definitions
        $dts = file_get_contents(base_path('resources/js/analytics.d.ts'));
        $this->assertStringContainsString("@version {$version}", $dts, 'analytics.d.ts version');
    }
}
