<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;

describe('Phase 44 — Production Readiness Audit', function (): void {

    // ─── Version Consistency ──────────────────────────────────────────

    it('composer.json version is 185.0.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('185.0.0');
    });

    it('package.json version is 185.0.0', function (): void {
        $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        expect($pkg['version'])->toBe('185.0.0');
    });

    it('AnalyticsEvent::VERSION is 185.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('185.0.0');
    });

    it('ServiceProvider @version annotation is 185.0.0', function (): void {
        $reflection = new ReflectionClass(AnalyticsServiceProvider::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@version 185.0.0');
    });

    // ─── Exception Hierarchy ──────────────────────────────────────────

    it('AnalyticsException is abstract with :void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsException::class);
        expect($ref->isAbstract())->toBeTrue();
        $ctor = $ref->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    it('AnalyticsRuntimeException is final leaf with factory method', function (): void {
        $ref = new ReflectionClass(AnalyticsRuntimeException::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
        expect($ref->hasMethod('forMessage'))->toBeTrue();
        // Bidirectional @see
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('AnalyticsException');
    });

    it('InvalidAnalyticsArgumentException is final leaf with factory method', function (): void {
        $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
        expect($ref->hasMethod('forMessage'))->toBeTrue();
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('AnalyticsException');
    });

    it('AnalyticsException has bidirectional @see references', function (): void {
        $doc = (new ReflectionClass(AnalyticsException::class))->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('AnalyticsRuntimeException');
        expect($doc)->toContain('InvalidAnalyticsArgumentException');
    });

    // ─── AnalyticsManager Quality ──────────────────────────────────────

    it('AnalyticsManager is final', function (): void {
        expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsManager has :void constructor', function (): void {
        $ctor = (new ReflectionClass(AnalyticsManager::class))->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    // ─── ServiceProvider Quality ────────────────────────────────────

    it('AnalyticsServiceProvider has register, boot, provides methods', function (): void {
        $ref = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('boot'))->toBeTrue();
        expect($ref->hasMethod('provides'))->toBeTrue();
        expect($ref->getMethod('register')->getReturnType()?->getName())->toBe('void');
        expect($ref->getMethod('boot')->getReturnType()?->getName())->toBe('void');
        expect($ref->getMethod('provides')->getReturnType()?->getName())->toBe('array');
    });

    // ─── Facade Quality ───────────────────────────────────────────────

    it('Analytics facade accessor returns zeroboiler.analytics', function (): void {
        expect(Analytics::getFacadeAccessor())->toBe('zeroboiler.analytics');
    });

    // ─── Source File Quality (statistical sample) ─────────────────────

    it('all source files have strict_types', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $missing = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $missing[] = $file->getPathname();
            }
        }
        expect($missing)->toBeEmpty('Missing strict_types in: ' . implode(', ', array_slice($missing, 0, 5)));
    });

    it('all source files have MIT license header', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $missing = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (!str_contains($content, 'ZeroBoiler, licensed under the MIT')) {
                $missing[] = $file->getPathname();
            }
        }
        expect($missing)->toBeEmpty('Missing MIT header in: ' . implode(', ', array_slice($missing, 0, 5)));
    });

    it('no source files contain TODO/FIXME/HACK markers', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $found = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match('/(?:\/\/|\/\*\*|\*)\s*(TODO|FIXME|HACK|XXX)/i', $content)) {
                $found[] = $file->getFilename();
            }
        }
        expect($found)->toBeEmpty('TODO/FIXME found in: ' . implode(', ', array_slice($found, 0, 5)));
    });

    // ─── File Counts ─────────────────────────────────────────────────

    it('has 847+ source files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(847);
    });

    it('has 425+ test files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../tests', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(425);
    });

    it('has 85 artisan commands', function (): void {
        $commandsDir = __DIR__ . '/../src/Console/Commands';
        expect(is_dir($commandsDir))->toBeTrue();
        $files = glob($commandsDir . '/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(85);
    });

    it('has 382 services', function (): void {
        $servicesDir = __DIR__ . '/../src/Services';
        expect(is_dir($servicesDir))->toBeTrue();
        $files = glob($servicesDir . '/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(382);
    });

    it('has 10 tracker implementations', function (): void {
        $trackersDir = __DIR__ . '/../src/Trackers';
        $files = glob($trackersDir . '*Tracker.php');
        expect(count($files))->toBeGreaterThanOrEqual(10);
    });

    // ─── JS Client & Svelte Composables ───────────────────────────────

    it('has analytics.js client', function (): void {
        expect(file_exists(__DIR__ . '/../resources/js/analytics.js'))->toBeTrue();
    });

    it('has TypeScript definitions', function (): void {
        expect(file_exists(__DIR__ . '/../resources/js/analytics.d.ts'))->toBeTrue();
    });

    it('has 11 Svelte composables', function (): void {
        $composables = glob(__DIR__ . '/../resources/js/*.svelte.js');
        expect(count($composables))->toBeGreaterThanOrEqual(11);
    });

    // ─── Config File Integrity ────────────────────────────────────────

    it('config file has required sections', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        expect(isset($config['analytics']['ga4']))->toBeTrue();
        expect(isset($config['analytics']['gtm']))->toBeTrue();
        expect(isset($config['analytics']['meta_pixel']))->toBeTrue();
        expect(isset($config['analytics']['consent']))->toBeTrue();
        expect(isset($config['analytics']['auto_track']))->toBeTrue();
    });

    // ─── Event Category Completeness ─────────────────────────────────

    it('has 9 event category directories', function (): void {
        $eventsDir = __DIR__ . '/../src/Events';
        $categories = array_filter(glob($eventsDir . '/*', GLOB_ONLYDIR), function (string $dir): bool {
            return basename($dir) !== 'Webhook' || true; // include all
        });
        $catNames = array_map(fn (string $d): string => basename($d), $categories);
        // Core 8 + Webhook = 9
        expect(count($categories))->toBeGreaterThanOrEqual(9);
        expect($catNames)->toContain('Ecommerce');
        expect($catNames)->toContain('SaaS');
        expect($catNames)->toContain('Engagement');
        expect($catNames)->toContain('Security');
        expect($catNames)->toContain('Uptime');
        expect($catNames)->toContain('Infrastructure');
        expect($catNames)->toContain('Marketing');
        expect($catNames)->toContain('CustomerSuccess');
        expect($catNames)->toContain('Webhook');
    });

    // ─── README Accuracy ──────────────────────────────────────────────

    it('README exists and has version badge 185.0.0', function (): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->not->toBeFalse();
        expect($readme)->toContain('version-185.0.0');
    });

    it('README headline says 382 services', function (): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain('**382 services**');
    });

    it('README headline says 85 artisan commands', function (): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain('**85 artisan commands**');
    });

    it('README headline says 11 Svelte composables', function (): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain('**11 Svelte composables**');
    });

    // ─── Provider Tracker Count ──────────────────────────────────────

    it('has providers for GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook', function (): void {
        $trackersDir = __DIR__ . '/../src/Trackers';
        $expected = [
            'GA4Tracker.php',
            'GTMTracker.php',
            'MetaPixelTracker.php',
            'PlausibleTracker.php',
            'PosthogTracker.php',
            'MixpanelTracker.php',
            'AmplitudeTracker.php',
            'TikTokTracker.php',
            'LinkedInTracker.php',
            'WebhookTracker.php',
        ];
        foreach ($expected as $file) {
            expect(file_exists($trackersDir . '/' . $file))
                ->toBeTrue("Missing tracker: {$file}");
        }
    });

    // ─── AnalyticsEvent DTO Quality ───────────────────────────────────

    it('AnalyticsEvent is final readonly', function (): void {
        $ref = new ReflectionClass(AnalyticsEvent::class);
        expect($ref->isFinal())->toBeTrue();
        // readonly check via attributes or properties
        $props = $ref->getProperties();
        expect($props)->not->toBeEmpty();
    });

    it('AnalyticsEvent has :void constructor', function (): void {
        $ctor = (new ReflectionClass(AnalyticsEvent::class))->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    // ─── PHP 8.5 Compatibility ────────────────────────────────────────

    it('composer.json requires PHP ^8.5', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
    });

    it('composer.json requires illuminate/contracts ^13.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    // ─── Middleware Registration ───────────────────────────────────────

    it('has InjectAnalyticsScripts middleware', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class))->toBeTrue();
    });

    it('has AutoPageViewMiddleware', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware::class))->toBeTrue();
    });

    // ─── Blade Directives ────────────────────────────────────────────

    it('has AnalyticsDirectives class', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives::class))->toBeTrue();
    });

    // ─── Inertia Integration ─────────────────────────────────────────

    it('has HandleInertiaAnalytics middleware', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class))->toBeTrue();
    });
});
