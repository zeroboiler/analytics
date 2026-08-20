<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Phase88;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 88 production readiness test.
 *
 * Validates:
 * - AnalyticsEvent::VERSION is a real constant (not trapped in docblock)
 * - LifecycleEventTracker constructor has `: void` return type
 * - LifecycleEventTracker createListener has no dead if/else on queueEvents
 * - Version consistency at 264.0.0
 * - All source files maintain strict_types + MIT headers
 *
 * @since 264.0.0
 */
#[Group('production-readiness')]
class Phase88ProductionReadinessTest extends TestCase
{
    private readonly string $srcDir;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->srcDir = dirname(__DIR__, 2) . '/src';
    }

    /**
     * AnalyticsEvent::VERSION must be declared as a real PHP constant,
     * not trapped inside a docblock comment.
     */
    public function testAnalyticsEventVersionIsRealConstant(): void
    {
        $this->assertTrue(
            defined('ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION'),
            'AnalyticsEvent::VERSION must be a declared constant (not trapped in docblock)',
        );

        $version = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
        $this->assertSame('264.0.0', $version, 'AnalyticsEvent::VERSION must be 264.0.0');
    }

    /**
     * The VERSION constant line must NOT be inside a docblock.
     * It should be a standalone line with `public const VERSION =`.
     */
    public function testAnalyticsEventVersionIsNotInDocblock(): void
    {
        $content = file_get_contents($this->srcDir . '/DTO/AnalyticsEvent.php');
        $this->assertNotFalse($content);

        // The const must appear as actual code (not inside a `*` comment line)
        $this->assertMatchesRegularExpression(
            '/^\s+public const VERSION = /m',
            $content,
            'AnalyticsEvent::VERSION must be declared as actual PHP code, not inside a docblock',
        );
    }

    /**
     * LifecycleEventTracker constructor must have `: void` return type.
     */
    public function testLifecycleEventTrackerConstructorHasVoidReturn(): void
    {
        $content = file_get_contents($this->srcDir . '/Tracking/LifecycleEventTracker.php');
        $this->assertNotFalse($content);

        $this->assertMatchesRegularExpression(
            '/public function __construct\(/',
            $content,
            'LifecycleEventTracker must have a constructor',
        );

        $this->assertMatchesRegularExpression(
            '/\)\s*:\s*void\s*\{/s',
            $content,
            'LifecycleEventTracker constructor must have `: void` return type',
        );
    }

    /**
     * LifecycleEventTracker createListener must not have a dead if/else
     * on $this->queueEvents (both branches did the same thing).
     */
    public function testLifecycleEventTrackerNoDeadQueueBranch(): void
    {
        $content = file_get_contents($this->srcDir . '/Tracking/LifecycleEventTracker.php');
        $this->assertNotFalse($content);

        // There should be exactly one dispatch call in createListener, not two
        $matches = [];
        preg_match_all('/\$this->queueService->dispatch\(/', $content, $matches);
        $this->assertCount(
            1,
            $matches[0],
            'LifecycleEventTracker should have exactly one dispatch call (dead if/else removed)',
        );
    }

    /**
     * Version consistency: all entry points must report 264.0.0.
     */
    public function testVersionConsistency(): void
    {
        $expected = '264.0.0';

        // composer.json
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertSame($expected, $composer['version'] ?? null, 'composer.json version');

        // package.json
        $pkg = json_decode(file_get_contents(dirname(__DIR__, 2) . '/package.json'), true);
        $this->assertSame($expected, $pkg['version'] ?? null, 'package.json version');

        // AnalyticsEvent::VERSION
        $this->assertSame($expected, \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION);

        // analytics.js getVersion()
        $jsContent = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.js');
        $this->assertStringContainsString("return '{$expected}';", $jsContent, 'analytics.js getVersion() return');
    }

    /**
     * All PHP source files must have declare(strict_types=1).
     */
    public function testStrictTypesCoverage(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertEmpty(
            $violations,
            'All PHP source files must have declare(strict_types=1). Violations: ' . implode(', ', $violations),
        );
    }

    /**
     * All PHP source files must have the MIT license header.
     */
    public function testMitHeaderCoverage(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'This file is part of ZeroBoiler, licensed under the MIT license')) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertEmpty(
            $violations,
            'All PHP source files must have the MIT license header. Violations: ' . implode(', ', array_slice($violations, 0, 5)),
        );
    }
}
