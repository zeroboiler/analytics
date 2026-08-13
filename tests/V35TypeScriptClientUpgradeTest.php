<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    // Reset static catalogs for clean state
    $ref = new ReflectionClass(EcommerceEvents::class);
    $prop = $ref->getProperty('catalog');
    
    $prop->setValue(null);

    $ref = new ReflectionClass(SaaSEvents::class);
    $prop = $ref->getProperty('catalog');
    
    $prop->setValue(null);

    $ref = new ReflectionClass(EngagementEvents::class);
    $prop = $ref->getProperty('catalog');
    
    $prop->setValue(null);
});

// ─── TypeScript Type Definitions ──────────────────────────────────────────

it('TypeScript type definitions file exists', function (): void {
    expect(file_exists(__DIR__ . '/../../resources/js/analytics.d.ts'))->toBeTrue();
});

it('TypeScript definitions contain ZbAnalyticsConfig interface', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface ZbAnalyticsConfig');
    expect($content)->toContain('enabled: boolean');
    expect($content)->toContain('consent: ConsentSignals');
    expect($content)->toContain('trackingId: string');
    expect($content)->toContain('ga4MeasurementId?: string');
    expect($content)->toContain('gtmContainerId?: string');
    expect($content)->toContain('metaPixelId?: string');
    expect($content)->toContain('plausibleDomain?: string');
    expect($content)->toContain('posthogHost?: string');
});

it('TypeScript definitions contain ConsentSignals interface', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface ConsentSignals');
    expect($content)->toContain('analytics_storage');
    expect($content)->toContain('ad_storage');
    expect($content)->toContain("'granted' | 'denied'");
});

it('TypeScript definitions contain AutoTrackConfig interface', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface AutoTrackConfig');
    expect($content)->toContain('pageViews: boolean');
    expect($content)->toContain('scrollDepth: boolean');
    expect($content)->toContain('formTracking: boolean');
    expect($content)->toContain('errorTracking: boolean');
    expect($content)->toContain('linkTracking: boolean');
    expect($content)->toContain('sessionTracking: boolean');
    expect($content)->toContain('idleTimeout: number');
    expect($content)->toContain('errorIgnorePatterns: string[]');
});

it('TypeScript definitions contain PerformanceConfig interface', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface PerformanceConfig');
    expect($content)->toContain('trackLCP: boolean');
    expect($content)->toContain('trackFID: boolean');
    expect($content)->toContain('trackCLS: boolean');
    expect($content)->toContain('trackINP: boolean');
    expect($content)->toContain('trackTTFB: boolean');
    expect($content)->toContain('trackFCP: boolean');
    expect($content)->toContain('sendToServer: boolean');
});

it('TypeScript definitions contain all core function signatures', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');

    // Core functions
    expect($content)->toContain('export function init(');
    expect($content)->toContain('export function destroy(): void');
    expect($content)->toContain('export function trackEvent(');
    expect($content)->toContain('export function trackPageView(');
    expect($content)->toContain('export function flushQueue(');
    expect($content)->toContain('export function getVersion(): string');
    expect($content)->toContain('export function getTrackingId()');

    // E-commerce
    expect($content)->toContain('export function trackEcommerce(');
    expect($content)->toContain('export function trackWishlist(');
    expect($content)->toContain('export function trackSelectItem(');
    expect($content)->toContain('export function trackPromotionView(');

    // Identity
    expect($content)->toContain('export function identify(');
    expect($content)->toContain('export function alias(');
    expect($content)->toContain('export function identifyWithTraits(');

    // GDPR
    expect($content)->toContain('export function updateConsent(');
    expect($content)->toContain('export function optOutTracking(');
    expect($content)->toContain('export function optInTracking(');

    // Auto-trackers
    expect($content)->toContain('export function initScrollDepth(');
    expect($content)->toContain('export function initInertiaPageViewTracker(');
    expect($content)->toContain('export function initFormTracking(');
    expect($content)->toContain('export function initErrorTracking(');
    expect($content)->toContain('export function initWebVitals(');
    expect($content)->toContain('export function initSessionTracking(');
    expect($content)->toContain('export function initLinkTracking(');
    expect($content)->toContain('export function initAll(');
    expect($content)->toContain('export function destroyAll()');

    // Advanced
    expect($content)->toContain('export function pushToDataLayer(');
    expect($content)->toContain('export function fetchEventCatalog(');
    expect($content)->toContain('export function setUserProperties(');
    expect($content)->toContain('export function trackServerPageView(');
});

it('TypeScript definitions contain Ga4Item interface', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface Ga4Item');
    expect($content)->toContain('item_id: string');
    expect($content)->toContain('price: number');
    expect($content)->toContain('quantity: number');
});

it('TypeScript definitions contain EventCatalog response type', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('export interface EventCatalog');
    expect($content)->toContain('total: number');
    expect($content)->toContain('categories:');
    expect($content)->toContain('names: string[]');
});

it('TypeScript definitions contain version 2.35.0', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('@version 76.0.0');
});

it('TypeScript definitions extend Inertia PageProps with zbAnalytics', function (): void {
    $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
    expect($content)->toContain('declare module');
    expect($content)->toContain('@inertiajs/core');
    expect($content)->toContain('zbAnalytics?');
});

// ─── JS Client sendBeacon Unload Flush ────────────────────────────────────

it('JS client contains sendBeacon flush on unload function', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    expect($js)->toContain('function flushPendingOnUnload()');
    expect($js)->toContain('navigator.sendBeacon');
    expect($js)->toContain('application/json');
});

it('JS client registers unload handler in init()', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    expect($js)->toContain("window.addEventListener('beforeunload', flushPendingOnUnload)");
});

it('JS client cleans up unload handler in destroy()', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    expect($js)->toContain("window.removeEventListener('beforeunload', flushPendingOnUnload)");
});

it('sendBeacon flush drains queue and creates Blob', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    // Verify the function drains the queue
    expect($js)->toContain('const events = [...eventQueue]');
    expect($js)->toContain('eventQueue.length = 0');
    // Verify Blob creation for sendBeacon
    expect($js)->toContain("new Blob([payload], { type: 'application/json' })");
});

// ─── Version Consistency ──────────────────────────────────────────────────

it('JS client getVersion() returns 2.35.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    expect($js)->toContain("return '76.0.0'");
});

it('JS client JSDoc version is 2.35.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
    expect($js)->toContain('@version 76.0.0');
});

it('Composer version is 2.35.0', function (): void {
    $composer = json_decode(
        file_get_contents(__DIR__ . '/../../composer.json'),
        true,
    );
    expect($composer['version'])->toBe('76.0.0');
});

it('No stale 2.34.0 references remain in source files', function (): void {
    $sourceDir = __DIR__ . '/../../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (str_contains($content, "'2.34.0'") || str_contains($content, '"2.34.0"')) {
            $relative = str_replace(__DIR__ . '/../../', '', $file);
            $this->fail("Stale version '2.34.0' found in src/{$relative}");
        }
    }

    expect(true)->toBeTrue();
});

it('No stale 2.34.0 references remain in test files', function (): void {
    $testDir = __DIR__ . '/..';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (str_contains($content, '2.34.0')) {
            $relative = str_replace(__DIR__ . '/../', '', $file);
            $this->fail("Stale version '2.34.0' found in tests/{$relative}");
        }
    }

    expect(true)->toBeTrue();
});

// ─── Event Catalog Integrity ──────────────────────────────────────────────

it('total event count is 52', function (): void {
    expect(EventCatalog::count())->toBe(52);
});

it('ecommerce catalog has 12 events', function (): void {
    expect(EcommerceEvents::count())->toBe(12);
});

it('SaaS catalog has 19 events', function (): void {
    expect(SaaSEvents::count())->toBe(19);
});

it('engagement catalog has 21 events', function (): void {
    expect(EngagementEvents::count())->toBe(21);
});

it('event catalog validation passes with no errors', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

// ─── File Count Verification ────────────────────────────────────────────

it('PHP source file count is at least 250', function (): void {
    $sourceDir = __DIR__ . '/../../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $count++;
        }
    }
    expect($count)->toBeGreaterThanOrEqual(250);
});

it('test file count is at least 80', function (): void {
    $testDir = __DIR__ . '/..';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getFilename(), 'Test')) {
            $count++;
        }
    }
    expect($count)->toBeGreaterThanOrEqual(80);
});

it('JS client file exists and is non-trivial', function (): void {
    $jsPath = __DIR__ . '/../../resources/js/analytics.js';
    expect(file_exists($jsPath))->toBeTrue();
    $size = filesize($jsPath);
    expect($size)->toBeGreaterThan(50000); // 50KB+
});

it('TypeScript definitions file is non-trivial', function (): void {
    $tsPath = __DIR__ . '/../../resources/js/analytics.d.ts';
    expect(file_exists($tsPath))->toBeTrue();
    $size = filesize($tsPath);
    expect($size)->toBeGreaterThan(5000); // 5KB+
});

// ─── Overview Command Feature List ──────────────────────────────────────

it('AnalyticsOverviewCommand lists TypeScript types as a feature', function (): void {
    $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsOverviewCommand.php');
    expect($content)->toContain('TypeScript type definitions');
});

it('AnalyticsOverviewCommand lists sendBeacon unload flush as a feature', function (): void {
    $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsOverviewCommand.php');
    expect($content)->toContain('sendBeacon');
});
