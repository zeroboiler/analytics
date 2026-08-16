<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Bus\AnalyticsEventBus;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;

// ─── Source File Strict Types Verification ─────────────────────────────────

test('all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getRealPath());
        if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
            $violations[] = $file->getFilename();
        }
    }
    expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
});

// ─── No TODO/FIXME Markers ───────────────────────────────────────────────────

test('no TODO or FIXME markers in source code', function (): void {
    $srcDir = __DIR__.'/../src';
    $content = '';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $c = file_get_contents($file->getRealPath());
            if ($c !== false) {
                $content .= $c."\n";
            }
        }
    }
    expect($content)->not->toContain('TODO');
    expect($content)->not->toContain('FIXME');
});

// ─── Exception Hierarchy ─────────────────────────────────────────────────────

test('exception hierarchy is correct', function (): void {
    expect((new ReflectionClass(AnalyticsException::class))->isAbstract())->toBeTrue();
    expect((new ReflectionClass(InvalidAnalyticsArgumentException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
    expect((new ReflectionClass(AnalyticsRuntimeException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
});

test('leaf exceptions are final', function (): void {
    $finalLeafs = [
        InvalidAnalyticsArgumentException::class,
        AnalyticsRuntimeException::class,
    ];
    foreach ($finalLeafs as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Tracker Interface Compliance ───────────────────────────────────────────

test('all trackers implement TrackerInterface', function (): void {
    $trackers = [
        GA4Tracker::class,
        GTMTracker::class,
        MetaPixelTracker::class,
        PlausibleTracker::class,
        PosthogTracker::class,
        MixpanelTracker::class,
        AmplitudeTracker::class,
        LinkedInTracker::class,
        TikTokTracker::class,
    ];

    foreach ($trackers as $class) {
        expect((new ReflectionClass($class))->implementsInterface(TrackerInterface::class))
            ->toBeTrue("{$class} should implement TrackerInterface");
    }
});

// ─── Event Store Interface ─────────────────────────────────────────────────

test('AnalyticsEventStoreInterface is an interface', function (): void {
    expect((new ReflectionClass(AnalyticsEventStoreInterface::class))->isInterface())->toBeTrue();
    expect((new ReflectionClass(AnalyticsEventStoreInterface::class))->hasMethod('store'))->toBeTrue();
});

// ─── Core Service Classes ─────────────────────────────────────────────────

test('core services are final', function (): void {
    $finalClasses = [
        AnalyticsManager::class,
        EventPipeline::class,
        EventSchemaRegistry::class,
        AnalyticsEventBus::class,
        AnalyticsServiceProvider::class,
    ];

    foreach ($finalClasses as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Provider Services Exist ───────────────────────────────────────────────

test('provider service classes exist', function (): void {
    $providers = [
        GoogleAnalyticsService::class,
        GoogleTagManagerService::class,
        MetaPixelService::class,
    ];
    foreach ($providers as $class) {
        expect(class_exists($class))->toBeTrue("{$class} should exist");
    }
});

// ─── Facade ────────────────────────────────────────────────────────────────

test('facade has @see reference', function (): void {
    $ref = new ReflectionClass(Analytics::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@see');
});

// ─── Composer.json Integrity ───────────────────────────────────────────────

test('composer.json has correct structure', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['type'])->toBe('library');
    expect($composer['license'])->toBe('MIT');
    expect($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/');
});

// ─── Version Consistency ──────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$composer['version']}-blue");
});

// ─── DTO Presence ──────────────────────────────────────────────────────────

test('core DTOs are readonly', function (): void {
    $dtos = [
        AnalyticsEvent::class,
    ];
    foreach ($dtos as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeTrue("{$class} should be readonly");
    }
});

// ─── Event Schema ──────────────────────────────────────────────────────────

test('EventSchema is a proper class with required methods', function (): void {
    $ref = new ReflectionClass(EventSchema::class);
    expect($ref->hasMethod('getName'))->toBeTrue();
    expect($ref->hasMethod('getCategory'))->toBeTrue();
});
