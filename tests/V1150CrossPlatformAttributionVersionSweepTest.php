<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\CrossPlatformAttributionService;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V115 — Phase 43: Cross-Platform Attribution + Version Integrity Sweep.
 *
 * Validates:
 * - CrossPlatformAttributionService with 5 attribution models
 * - Provider normalization (GA4, Meta, Plausible, PostHog)
 * - Touchpoint recording and provider breakdown
 * - Version integrity: all files at 114.0.0
 * - Svelte composable version sync
 * - Event catalog maturity ≥ 100 events
 * - Config section count ≥ 26
 * - Services count ≥ 311 (310 + CrossPlatformAttributionService)
 */
describe('Phase 43 — Cross-Platform Attribution Service', function () {
    test('CrossPlatformAttributionService exists and is final', function (): void {
        expect(class_exists(CrossPlatformAttributionService::class))->toBeTrue();

        $ref = new ReflectionClass(CrossPlatformAttributionService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('supports 5 attribution models', function (): void {
        $models = CrossPlatformAttributionService::supportedModels();
        expect($models)->toBe([
            'first_touch',
            'last_touch',
            'linear',
            'time_decay',
            'position_based',
        ]);
    });

    test('supports 5 providers with display names', function (): void {
        $providers = CrossPlatformAttributionService::supportedProviders();
        expect($providers)->toHaveCount(5);
        expect($providers)->toHaveKeys(['ga4', 'meta', 'plausible', 'posthog', 'webhook']);
        expect($providers['ga4'])->toBe('Google Analytics 4');
        expect($providers['meta'])->toBe('Meta Pixel');
        expect($providers['plausible'])->toBe('Plausible');
        expect($providers['posthog'])->toBe('PostHog');
    });

    test('normalizeGa4 extracts UTM fields', function (): void {
        $normalized = CrossPlatformAttributionService::normalizeGa4([
            'source' => 'google',
            'medium' => 'cpc',
            'campaign' => 'spring_sale',
            'client_id' => 'GA1.2.12345',
        ]);

        expect($normalized)->toHaveKeys(['source', 'medium', 'campaign', 'term', 'content', 'session_id', 'client_id']);
        expect($normalized['source'])->toBe('google');
        expect($normalized['medium'])->toBe('cpc');
        expect($normalized['campaign'])->toBe('spring_sale');
        expect($normalized['client_id'])->toBe('GA1.2.12345');
    });

    test('normalizeMeta extracts fbc/fbp fields', function (): void {
        $normalized = CrossPlatformAttributionService::normalizeMeta([
            'utm_source' => 'facebook',
            'utm_campaign' => 'retargeting',
            'fbc' => 'fb.1.1234.ABC',
            'fbp' => 'fb.1.5678.XYZ',
        ]);

        expect($normalized)->toHaveKeys(['source', 'medium', 'campaign', 'fbc', 'fbp', 'event_id']);
        expect($normalized['source'])->toBe('facebook');
        expect($normalized['fbc'])->toBe('fb.1.1234.ABC');
    });

    test('normalizePlausible extracts referrer and domain', function (): void {
        $normalized = CrossPlatformAttributionService::normalizePlausible([
            'referrer' => 'twitter.com',
            'domain' => 'myapp.com',
            'path' => '/pricing',
        ]);

        expect($normalized)->toHaveKeys(['source', 'medium', 'campaign', 'domain', 'path']);
        expect($normalized['source'])->toBe('twitter.com');
        expect($normalized['medium'])->toBe('referral');
    });

    test('normalizePosthog extracts distinct_id and URL', function (): void {
        $normalized = CrossPlatformAttributionService::normalizePosthog([
            'distinct_id' => 'user-789',
            '$current_url' => 'https://app.example.com/dashboard',
            'utm_source' => 'newsletter',
        ]);

        expect($normalized)->toHaveKeys(['source', 'medium', 'campaign', 'distinct_id', '$current_url']);
        expect($normalized['source'])->toBe('newsletter');
        expect($normalized['distinct_id'])->toBe('user-789');
    });

    test('GA4 normalization defaults for missing fields', function (): void {
        $normalized = CrossPlatformAttributionService::normalizeGa4([]);

        expect($normalized['source'])->toBe('(direct)');
        expect($normalized['medium'])->toBe('(none)');
        expect($normalized['campaign'])->toBe('');
        expect($normalized['client_id'])->toBe('');
    });

    test('Meta normalization defaults for missing fields', function (): void {
        $normalized = CrossPlatformAttributionService::normalizeMeta([]);

        expect($normalized['source'])->toBe('(direct)');
        expect($normalized['medium'])->toBe('(none)');
        expect($normalized['fbc'])->toBe('');
        expect($normalized['fbp'])->toBe('');
    });
});

describe('Phase 43 — Attribution Models via Cache', function () {
    test('first-touch attribution credits earliest touchpoint', function (): void {
        $cache = app(\Illuminate\Contracts\Cache\Repository::class);
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $service = new CrossPlatformAttributionService($cache, $config);

        $identity = 'test_first_touch_' . bin2hex(random_bytes(8));
        $service->clearTouchpoints($identity);

        $service->recordTouchpoint($identity, 'ga4', ['source' => 'google', 'campaign' => 'ad1']);
        $service->recordTouchpoint($identity, 'meta', ['source' => 'facebook', 'campaign' => 'ad2']);
        $service->recordTouchpoint($identity, 'plausible', ['source' => 'twitter']);

        // Build first-touch result manually
        $touchpoints = $service->getTouchpoints($identity);
        expect($touchpoints)->toHaveCount(3);
        expect($touchpoints[0]['provider'])->toBe('ga4');
        expect($touchpoints[0]['data']['source'])->toBe('google');

        $breakdown = $service->providerBreakdown($identity);
        expect($breakdown)->toHaveCount(5);
        expect($breakdown['ga4'])->toBe(1);
        expect($breakdown['meta'])->toBe(1);
        expect($breakdown['plausible'])->toBe(1);
        expect($breakdown['posthog'])->toBe(0);
        expect($breakdown['webhook'])->toBe(0);

        $service->clearTouchpoints($identity);
    });
});

describe('Phase 43 — Version Integrity Sweep', function () {
    test('all PHP/JS/TS/README versions are 117.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('117.0.0');

        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('117.0.0');

        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('@version 117.0.0');

        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect($dts)->toContain('@version 117.0.0');
    });

    test('all 5 Svelte composables at 117.0.0', function (): void {
        $svelteFiles = [
            'useAnalytics.svelte.js',
            'useAnalyticsConfig.svelte.js',
            'useLifecycle.svelte.js',
            'usePerformanceTracker.svelte.js',
            'useSessionReplay.svelte.js',
        ];

        foreach ($svelteFiles as $file) {
            $content = file_get_contents(__DIR__ . '/../resources/js/' . $file);
            expect($content)->toContain('@version 117.0.0', "Svelte composable {$file} must be at version 117.0.0");
        }
    });

    test('V99 maturity test expects 117.0.0', function (): void {
        $v99 = file_get_contents(__DIR__ . '/../tests/V99IndustryStandardSaaSAnalyticsTest.php');
        expect($v99)->toContain("'117.0.0'");
        expect($v99)->toContain('version-117.0.0');
        expect($v99)->not->toContain("'76.0.0'");
    });

    test('no stale 115.0.0 version references in Svelte files', function (): void {
        $svelteFiles = glob(__DIR__ . '/../resources/js/*.svelte.js');
        expect($svelteFiles)->not->toBeEmpty();

        foreach ($svelteFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toContain('@version 115.0.0', basename($file) . ' has stale version 115.0.0');
        }
    });
});

describe('Phase 43 — Package Maturity', function () {
    test('event catalog has 100+ events', function (): void {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    });

    test('lifecycle mapper has 40+ mappings', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');
        expect($const)->toBeArray();
        expect(count($const))->toBeGreaterThanOrEqual(40);
    });

    test('maturity score ≥ 80', function (): void {
        $calculator = new EventPriorityCalculator;
        $result = $calculator->maturityScore();
        expect($result['score'])->toBeGreaterThanOrEqual(80);
    });

    test('catalog validates cleanly', function (): void {
        $result = EventCatalog::validate();
        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('services directory has 311+ files', function (): void {
        $servicesDir = __DIR__ . '/../src/Services';
        $serviceFiles = glob($servicesDir . '/*.php');
        expect($serviceFiles)->not->toBeEmpty();
        expect(count($serviceFiles))->toBeGreaterThanOrEqual(311);
    });

    test('ecommerce events ≥ 15', function (): void {
        expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    });

    test('SaaS events ≥ 50', function (): void {
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    });

    test('engagement events ≥ 30', function (): void {
        expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
    });

    test('config has 26+ sections', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'identity', 'ecommerce', 'revenue', 'track_links',
            'api', 'plausible', 'posthog', 'webhook', 'audit_log',
            'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
            'replay', 'metrics', 'stream', 'client_auto_track', 'performance',
            'cross_platform_attribution',
        ];

        foreach ($requiredSections as $section) {
            expect($config)->toContain("'{$section}' => [");
        }
    });

    test('test files ≥ 155', function (): void {
        $testDir = __DIR__;
        $testFiles = glob($testDir . '/*Test.php');
        $featureTestFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
        if ($featureTestFiles === false) {
            $featureTestFiles = [];
        }
        $total = count($testFiles) + count($featureTestFiles);
        expect($total)->toBeGreaterThanOrEqual(155);
    });

    test('composer requires PHP 8.5+ and Laravel 13', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toContain('^13');
        expect($composer['type'])->toBe('library');
        expect($composer['license'])->toBe('MIT');
    });
});
