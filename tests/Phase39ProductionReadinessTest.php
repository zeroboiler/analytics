<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;

describe('Phase 39 — Production Readiness Hardening', function (): void {

    // ─── Service Class Finality ──────────────────────────────────────────

    it('AnalyticsManager is final + has void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->hasMethod('__construct'))->toBeTrue();
        $ctor = $ref->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    it('AnalyticsServiceProvider is final', function (): void {
        expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsServiceProvider has complete register/boot/provides', function (): void {
        $ref = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('boot'))->toBeTrue();
        expect($ref->hasMethod('provides'))->toBeTrue();
    });

    // ─── Facade Correctness ──────────────────────────────────────────────

    it('Facade accessor returns correct binding', function (): void {
        $method = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        expect($method->isStatic())->toBeTrue();
    });

    // ─── Version Consistency ────────────────────────────────────────────

    it('composer.json version is 178.0.0', function (): void {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );
        expect($composer['version'])->toBe('178.0.0');
    });

    it('README version badge matches composer.json', function (): void {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        expect($readme)->toContain("version-{$composer['version']}");
    });

    // ─── File Counts ─────────────────────────────────────────────────────

    it('source file count is at least 833', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(833);
    });

    // ─── Config Integrity ────────────────────────────────────────────────

    it('config file has expected sections', function (): void {
        $configPath = dirname(__DIR__, 2) . '/config/zeroboiler-analytics.php';
        $content = file_get_contents($configPath);

        expect($content)->toContain('ga4');
        expect($content)->toContain('gtm');
        expect($content)->toContain('tracking');
    });

    // ─── Subdirectory Cross-Reference ───────────────────────────────────

    it('src subdirectories exist for key domains', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';

        $domains = [
            'Trackers',
            'Events',
            'Enrichment',
            'Services',
            'Commands',
            'Jobs',
        ];

        foreach ($domains as $domain) {
            expect(is_dir($srcDir . '/' . $domain))->toBeTrue(
                "Missing src/{$domain} directory"
            );
        }
    });
});
