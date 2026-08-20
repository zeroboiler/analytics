<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Full version integrity audit — ensures all version entry points
 * across PHP, JS, TypeScript, Svelte, composer.json, and package.json
 * remain synchronized at the same version number.
 *
 * This test serves as a permanent guard against version drift.
 * When bumping versions, ALL entries in $expectedFiles must be updated.
 *
 * @since 266.0.0
 */
final class Phase33VersionIntegrityAuditTest extends TestCase
{
    private const EXPECTED_VERSION = '268.0.0';

    /**
     * @var array<string, array{path: string, pattern: string, description: string}>
     */
    private const EXPECTED_FILES = [
        'php-dto' => [
            'path' => __DIR__ . '/../src/DTO/AnalyticsEvent.php',
            'pattern' => "/public const VERSION = '268.0.0'/",
            'description' => 'AnalyticsEvent::VERSION constant',
        ],
        'composer' => [
            'path' => __DIR__ . '/../composer.json',
            'pattern' => '/"version": "268.0.0"/',
            'description' => 'composer.json version field',
        ],
        'package-json' => [
            'path' => __DIR__ . '/../package.json',
            'pattern' => '/"version": "268.0.0"/',
            'description' => 'package.json version field',
        ],
        'integrity-command' => [
            'path' => __DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php',
            'pattern' => "/private const EXPECTED_VERSION = '268.0.0'/",
            'description' => 'AnalyticsIntegrityCommand::EXPECTED_VERSION',
        ],
        'service-provider' => [
            'path' => __DIR__ . '/../src/AnalyticsServiceProvider.php',
            'pattern' => '/@version 268.0.0/',
            'description' => 'AnalyticsServiceProvider docblock @version',
        ],
        'js-client' => [
            'path' => __DIR__ . '/../resources/js/analytics.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'JS client library @version tag',
        ],
        'js-getVersion' => [
            'path' => __DIR__ . '/../resources/js/analytics.js',
            'pattern' => "/return '268.0.0'/",
            'description' => 'JS client getVersion() return value',
        ],
        'ts-types' => [
            'path' => __DIR__ . '/../resources/js/analytics.d.ts',
            'pattern' => '/@version 268.0.0/',
            'description' => 'TypeScript type definitions @version tag',
        ],
        'svelte-use-analytics' => [
            'path' => __DIR__ . '/../resources/js/useAnalytics.svelte.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'useAnalytics Svelte composable @version tag',
        ],
        'svelte-use-lifecycle' => [
            'path' => __DIR__ . '/../resources/js/useLifecycle.svelte.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'useLifecycle Svelte composable @version tag',
        ],
        'svelte-use-config' => [
            'path' => __DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'useAnalyticsConfig Svelte composable @version tag',
        ],
        'svelte-use-session-replay' => [
            'path' => __DIR__ . '/../resources/js/useSessionReplay.svelte.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'useSessionReplay Svelte composable @version tag',
        ],
        'svelte-use-performance' => [
            'path' => __DIR__ . '/../resources/js/usePerformanceTracker.svelte.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'usePerformanceTracker Svelte composable @version tag',
        ],
        'js-constants' => [
            'path' => __DIR__ . '/../resources/js/analytics.constants.js',
            'pattern' => '/@version 268.0.0/',
            'description' => 'JS constants @version tag',
        ],
        'readme-badge' => [
            'path' => __DIR__ . '/../README.md',
            'pattern' => '/version-266.0.0/',
            'description' => 'README version badge',
        ],
    ];

    /**
     * @test
     */
    public function all_version_entry_points_are_synchronized(): void
    {
        $failures = [];
        $passes = [];

        foreach (self::EXPECTED_FILES as $key => $entry) {
            $content = @file_get_contents($entry['path']);

            if ($content === false) {
                $failures[] = "[{$key}] File not found: {$entry['path']}";

                continue;
            }

            if (preg_match($entry['pattern'], $content)) {
                $passes[] = $entry['description'];
            } else {
                $failures[] = "[{$key}] Pattern mismatch in {$entry['description']} ({$entry['path']})";
            }
        }

        $this->assertEmpty(
            $failures,
            implode("\n", [
                'Version integrity check failed for ' . self::EXPECTED_VERSION . ':',
                ...$failures,
                '',
                'Passed checks: ' . count($passes) . '/' . count(self::EXPECTED_FILES),
                'Passing: ' . implode(', ', $passes),
            ]),
        );
    }

    /**
     * @test
     */
    public function analytics_event_version_constant_is_string(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $constant = $reflection->getConstant('VERSION');

        $this->assertNotFalse($constant, 'AnalyticsEvent::VERSION constant must exist');
        $this->assertIsString($constant, 'AnalyticsEvent::VERSION must be a string');
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $constant,
            'AnalyticsEvent::VERSION must follow semver (x.y.z)',
        );
    }

    /**
     * @test
     */
    public function integrity_command_version_matches_dto_version(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $cmdReflection = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
        $expectedVersion = $cmdReflection->getConstant('EXPECTED_VERSION');

        $this->assertNotFalse($dtoVersion, 'AnalyticsEvent::VERSION must exist');
        $this->assertNotFalse($expectedVersion, 'AnalyticsIntegrityCommand::EXPECTED_VERSION must exist');
        $this->assertSame(
            $expectedVersion,
            $dtoVersion,
            'AnalyticsIntegrityCommand::EXPECTED_VERSION must match AnalyticsEvent::VERSION',
        );
    }

    /**
     * @test
     */
    public function composer_json_version_matches_dto_version(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $composerPath = __DIR__ . '/../composer.json';
        $this->assertFileExists($composerPath);

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $this->assertIsArray($composer, 'composer.json must be valid JSON');
        $this->assertArrayHasKey('version', $composer, 'composer.json must have a version field');

        $this->assertSame(
            $dtoVersion,
            $composer['version'],
            'composer.json version must match AnalyticsEvent::VERSION',
        );
    }

    /**
     * @test
     */
    public function package_json_version_matches_dto_version(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $pkgPath = __DIR__ . '/../package.json';
        $this->assertFileExists($pkgPath);

        $pkg = json_decode((string) file_get_contents($pkgPath), true);
        $this->assertIsArray($pkg, 'package.json must be valid JSON');
        $this->assertArrayHasKey('version', $pkg, 'package.json must have a version field');

        $this->assertSame(
            $dtoVersion,
            $pkg['version'],
            'package.json version must match AnalyticsEvent::VERSION',
        );
    }

    /**
     * @test
     */
    public function js_client_getVersion_returns_dto_version(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $this->assertFileExists($jsPath);

        $js = (string) file_get_contents($jsPath);

        $this->assertStringContainsString(
            "return '{$dtoVersion}'",
            $js,
            'JS getVersion() must return the same version as AnalyticsEvent::VERSION',
        );

        $this->assertStringContainsString(
            "@version {$dtoVersion}",
            $js,
            'JS client @version tag must match AnalyticsEvent::VERSION',
        );
    }

    /**
     * @test
     */
    public function all_svelte_composables_have_matching_version_tag(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $svelteFiles = glob(__DIR__ . '/../resources/js/*.svelte.js');
        $this->assertNotEmpty($svelteFiles, 'At least one Svelte composable file must exist');

        foreach ($svelteFiles as $filePath) {
            $content = (string) file_get_contents($filePath);
            $basename = basename($filePath);

            $this->assertStringContainsString(
                "@version {$dtoVersion}",
                $content,
                "{$basename} @version tag must match AnalyticsEvent::VERSION ({$dtoVersion})",
            );
        }
    }

    /**
     * @test
     */
    public function typescript_definitions_have_matching_version_tag(): void
    {
        $dtoReflection = new \ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $dtoVersion = $dtoReflection->getConstant('VERSION');

        $tsPath = __DIR__ . '/../resources/js/analytics.d.ts';
        $this->assertFileExists($tsPath);

        $ts = (string) file_get_contents($tsPath);

        $this->assertStringContainsString(
            "@version {$dtoVersion}",
            $ts,
            'TypeScript definitions @version tag must match AnalyticsEvent::VERSION',
        );
    }

    /**
     * @test
     */
    public function total_version_entry_point_count(): void
    {
        $expectedCount = count(self::EXPECTED_FILES);

        $this->assertGreaterThanOrEqual(
            15,
            $expectedCount,
            'There should be at least 15 version entry points to check.',
        );

        $this->assertSame(
            $expectedCount,
            15,
            'EXPECTED_FILES count changed — update the assertion to ' . $expectedCount . ' if intentional.',
        );
    }
}
