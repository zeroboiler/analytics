<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\Bus\AnalyticsEventBus;
use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Models\AnalyticsEventModel;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Store\CacheEventStore;
use ZeroBoiler\Analytics\Store\DatabaseEventStore;
use ZeroBoiler\Analytics\Store\EventStoreManager;
use ZeroBoiler\Analytics\Store\NullEventStore;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;

// ═══════════════════════════════════════════════════════════════════════════════
// Phase 30 — Production Readiness Audit
// ═══════════════════════════════════════════════════════════════════════════════

// ─── Finality Verification ────────────────────────────────────────────────────

test('AnalyticsManager is final', function (): void {
    expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
});

test('AnalyticsMetrics is final', function (): void {
    expect((new ReflectionClass(AnalyticsMetrics::class))->isFinal())->toBeTrue();
});

test('AnalyticsServiceProvider is final', function (): void {
    expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
});

test('AnalyticsEvent is final', function (): void {
    expect((new ReflectionClass(AnalyticsEvent::class))->isFinal())->toBeTrue();
});

test('ConsentState is final readonly', function (): void {
    $ref = new ReflectionClass(ConsentState::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('EventPipeline is final', function (): void {
    expect((new ReflectionClass(EventPipeline::class))->isFinal())->toBeTrue();
});

test('Analytics facade is final', function (): void {
    expect((new ReflectionClass(Analytics::class))->isFinal())->toBeTrue();
});

// ─── Tracker Interface Compliance ──────────────────────────────────────────────

test('all trackers implement TrackerInterface', function (): void {
    $trackers = [
        GA4Tracker::class, GTMTracker::class, MetaPixelTracker::class,
        PlausibleTracker::class, PosthogTracker::class, WebhookTracker::class,
        MixpanelTracker::class, AmplitudeTracker::class, LinkedInTracker::class,
        TikTokTracker::class,
    ];

    foreach ($trackers as $class) {
        expect((new ReflectionClass($class))->implementsInterface(TrackerInterface::class))
            ->toBeTrue("{$class} should implement TrackerInterface");
    }
});

test('TrackerInterface has all required methods', function (): void {
    $ref = new ReflectionClass(TrackerInterface::class);
    $required = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];
    foreach ($required as $method) {
        expect($ref->hasMethod($method))->toBeTrue("TrackerInterface missing: {$method}");
    }
});

// ─── Exception Hierarchy ───────────────────────────────────────────────────────

test('AnalyticsException is abstract', function (): void {
    expect((new ReflectionClass(AnalyticsException::class))->isAbstract())->toBeTrue();
});

test('all exceptions extend AnalyticsException', function (): void {
    $exceptions = [InvalidAnalyticsArgumentException::class, AnalyticsRuntimeException::class];
    foreach ($exceptions as $class) {
        expect((new ReflectionClass($class))->isSubclassOf(AnalyticsException::class))
            ->toBeTrue("{$class} should extend AnalyticsException");
    }
});

test('all leaf exceptions are final', function (): void {
    $exceptions = [InvalidAnalyticsArgumentException::class, AnalyticsRuntimeException::class];
    foreach ($exceptions as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Event Store Implementations ──────────────────────────────────────────────

test('all event stores implement AnalyticsEventStoreInterface', function (): void {
    $stores = [DatabaseEventStore::class, CacheEventStore::class, NullEventStore::class];
    foreach ($stores as $class) {
        expect((new ReflectionClass($class))->implementsInterface(AnalyticsEventStoreInterface::class))
            ->toBeTrue("{$class} should implement AnalyticsEventStoreInterface");
    }
});

// ─── AnalyticsFake ─────────────────────────────────────────────────────────────

test('AnalyticsFake class exists', function (): void {
    expect(class_exists(AnalyticsFake::class))->toBeTrue();
});

// ─── Constructor :void Enforcement ─────────────────────────────────────────────

test('AnalyticsException constructor has :void return type', function (): void {
    $ctor = (new ReflectionClass(AnalyticsException::class))->getConstructor();
    $returnType = $ctor->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('void');
});

// ─── Strict Types ─────────────────────────────────────────────────────────────

test('all core source files declare strict_types=1', function (): void {
    $coreFiles = [
        __DIR__.'/../src/AnalyticsManager.php',
        __DIR__.'/../src/AnalyticsMetrics.php',
        __DIR__.'/../src/Exceptions/AnalyticsException.php',
        __DIR__.'/../src/Trackers/TrackerInterface.php',
        __DIR__.'/../src/DTO/AnalyticsEvent.php',
        __DIR__.'/../src/DTO/ConsentState.php',
        __DIR__.'/../src/AnalyticsServiceProvider.php',
        __DIR__.'/../src/Facades/Analytics.php',
    ];

    foreach ($coreFiles as $file) {
        expect(file_exists($file))->toBeTrue("Missing: {$file}");
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
    }
});

// ─── Composer.json Integrity ───────────────────────────────────────────────────

test('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

test('composer.json has correct PSR-4 autoloading', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('ZeroBoiler\\Analytics\\');
});

// ─── Tooling Presence ────────────────────────────────────────────────────────

test('phpstan.neon config exists', function (): void {
    expect(file_exists(__DIR__.'/../phpstan.neon') || file_exists(__DIR__.'/../phpstan.neon.dist'))->toBeTrue();
});

test('rector.php config exists', function (): void {
    expect(file_exists(__DIR__.'/../rector.php'))->toBeTrue();
});

test('pint.json config exists', function (): void {
    expect(file_exists(__DIR__.'/../pint.json'))->toBeTrue();
});

// ─── Bus Classes ───────────────────────────────────────────────────────────────

test('bus classes exist', function (): void {
    expect(class_exists(AnalyticsDataBus::class))->toBeTrue();
    expect(class_exists(AnalyticsEventBus::class))->toBeTrue();
    expect(class_exists(AnalyticsEventDispatcher::class))->toBeTrue();
});
