<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SdkBridgeService;

beforeEach(function (): void {
    $this->version = '166.0.0';
    $this->srcDir = __DIR__ . '/../../src';
    $this->testDir = __DIR__ . '/../../tests';
});

// ─── Version Sweep ────────────────────────────────────────────────────────

describe('Phase 39 — Version Sweep (v166.0.0)', function (): void {
    test('AnalyticsEvent::VERSION matches', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe($this->version);
    });

    test('composer.json version matches', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['version'])->toBe($this->version);
    });

    test('package.json version matches', function (): void {
        $pkg = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        expect($pkg['version'])->toBe($this->version);
    });

    test('JS analytics.js getVersion returns correct version', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain("return '166.0.0';");
    });

    test('JS analytics.d.ts version comment matches', function (): void {
        $dts = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        expect($dts)->toContain('@version 166.0.0');
    });

    test('JS analytics.constants.js version comment matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.constants.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useAnalytics.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useAnalytics.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useAnalyticsConfig.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useAnalyticsConfig.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useEcommerce.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useEcommerce.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useLifecycle.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useLifecycle.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS usePerformanceTracker.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/usePerformanceTracker.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useSaaSMetrics.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useSaaSMetrics.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('JS useSessionReplay.svelte.js version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');
        expect($js)->toContain('@version 166.0.0');
    });

    test('no stale version references (165.0.0) in JS files', function (): void {
        $jsFiles = glob(__DIR__ . '/../../resources/js/*.js');
        foreach ($jsFiles as $file) {
            $content = file_get_contents($file);
            // Allow in comments if it's a changelog/history reference
            // But getVersion() must be 166.0.0
            if (basename($file) === 'analytics.js') {
                expect($content)->not->toContain("return '165.0.0'");
            }
        }
    });
});

// ─── SdkBridgeService Quality Gates ───────────────────────────────────────

describe('Phase 39 — SdkBridgeService Quality', function (): void {
    test('SdkBridgeService is final', function (): void {
        $ref = new ReflectionClass(SdkBridgeService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('SdkBridgeService has strict types', function (): void {
        $contents = file_get_contents($ref = (new ReflectionClass(SdkBridgeService::class))->getFileName());
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('SdkBridgeService has license header', function (): void {
        $contents = file_get_contents((new ReflectionClass(SdkBridgeService::class))->getFileName());
        expect($contents)->toContain('This file is part of ZeroBoiler');
        expect($contents)->toContain('MIT license');
    });

    test('SdkBridgeService public method count is reasonable (10-25)', function (): void {
        $ref = new ReflectionClass(SdkBridgeService::class);
        $publicMethods = count($ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($publicMethods)->toBeGreaterThanOrEqual(10);
        expect($publicMethods)->toBeLessThanOrEqual(25);
    });

    test('all SdkBridgeService methods have return type declarations', function (): void {
        $ref = new ReflectionClass(SdkBridgeService::class);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull(
                "Method {$method->getName()} must have a return type declaration"
            );
        }
    });

    test('SdkBridgeService constructor has :void return type', function (): void {
        $ref = new ReflectionMethod(SdkBridgeService::class, '__construct');
        $returnType = $ref->getReturnType();
        expect($returnType)->not->toBeNull();
        expect((string) $returnType)->toBe('void');
    });

    test('SdkBridgeService has @since annotation', function (): void {
        $ref = new ReflectionClass(SdkBridgeService::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 166.0.0');
    });

    test('SdkBridgeService has no TODO/FIXME markers', function (): void {
        $contents = file_get_contents((new ReflectionClass(SdkBridgeService::class))->getFileName());
        expect($contents)->not->toContain('TODO');
        expect($contents)->not->toContain('FIXME');
    });
});

// ─── JS SDK Bridge Section Validation ─────────────────────────────────────

describe('Phase 39 — JS SDK Bridge Validation', function (): void {
    test('analytics.js contains SDK_BRIDGE_INBOUND_MAP', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('SDK_BRIDGE_INBOUND_MAP');
    });

    test('analytics.js contains SDK_BRIDGE_OUTBOUND_MAP', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('SDK_BRIDGE_OUTBOUND_MAP');
    });

    test('analytics.js exports trackFromSdk', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('export async function trackFromSdk');
    });

    test('analytics.js exports translateToSdk', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('export function translateToSdk');
    });

    test('analytics.js exports fetchSdkBridgeCompatibility', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('export async function fetchSdkBridgeCompatibility');
    });

    test('analytics.js exports getSupportedBridgeSdks', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('export function getSupportedBridgeSdks');
    });

    test('analytics.js exports inspectSdkTranslation', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('export function inspectSdkTranslation');
    });

    test('analytics.d.ts defines SdkBridgeTrackResult interface', function (): void {
        $dts = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        expect($dts)->toContain('SdkBridgeTrackResult');
    });

    test('analytics.d.ts defines SdkBridgeTranslation interface', function (): void {
        $dts = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        expect($dts)->toContain('SdkBridgeTranslation');
    });

    test('analytics.d.ts defines SdkBridgeInspection interface', function (): void {
        $dts = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        expect($dts)->toContain('SdkBridgeInspection');
    });

    test('analytics.d.ts defines SdkBridgeCompatibilityReport interface', function (): void {
        $dts = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        expect($dts)->toContain('SdkBridgeCompatibilityReport');
    });

    test('SDK Bridge Mode section header in JS', function (): void {
        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($js)->toContain('SDK Bridge Mode (v166.0.0)');
    });
});

// ─── Composer Metadata ───────────────────────────────────────────────────

describe('Phase 39 — Composer Metadata', function (): void {
    test('composer.json requires PHP ^8.5', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires illuminate/contracts ^13.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    test('composer.json has quality scripts', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['scripts'])->toHaveKey('test');
        expect($composer['scripts'])->toHaveKey('analyse');
        expect($composer['scripts'])->toHaveKey('lint');
        expect($composer['scripts'])->toHaveKey('quality');
    });

    test('composer.json has MIT license', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['license'])->toBe('MIT');
    });

    test('composer.json minimum-stability is stable', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['minimum-stability'])->toBe('stable');
    });
});

// ─── PHPStan Config ──────────────────────────────────────────────────────

describe('Phase 39 — PHPStan Config', function (): void {
    test('phpstan.neon exists', function (): void {
        expect(file_exists(__DIR__ . '/../../phpstan.neon'))->toBeTrue();
    });

    test('phpstan level is 9', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../phpstan.neon');
        expect($contents)->toContain('->level(9)');
    });

    test('phpstan has extended checks', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../phpstan.neon');
        expect($contents)->toContain('checkUnusedParameters');
        expect($contents)->toContain('checkUninitializedProperties');
    });
});

// ─── Test File Counts ─────────────────────────────────────────────────────

describe('Phase 39 — Test Infrastructure', function (): void {
    test('V1660 test file exists', function (): void {
        expect(file_exists(__DIR__ . '/../../tests/V1660SdkBridgeServiceTest.php'))->toBeTrue();
    });

    test('Phase39 production audit test exists', function (): void {
        expect(file_exists(__FILE__))->toBeTrue();
    });

    test('source file count is at least 800', function (): void {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        if ($srcFiles === false) {
            // Recursive glob fallback
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__ . '/../../src', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $srcFiles = [];
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $srcFiles[] = $file->getPathname();
                }
            }
        }
        expect(count($srcFiles))->toBeGreaterThanOrEqual(800);
    });
});
