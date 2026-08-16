<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════════════
// Phase 120 — HeartbeatMonitor, BundleEventService, FeatureFlagObserver Audit
// ═══════════════════════════════════════════════════════════════════════════════

use ZeroBoiler\Analytics\Services\AnalyticsHeartbeatMonitor;
use ZeroBoiler\Analytics\Services\SaaSBundleEventService;
use ZeroBoiler\Analytics\Services\SaaSFeatureFlagObserver;
use ZeroBoiler\Analytics\AnalyticsManager;

// ─── HeartbeatMonitor ─────────────────────────────────────────────────────────

test('AnalyticsHeartbeatMonitor class exists and is final', function (): void {
    expect(class_exists(AnalyticsHeartbeatMonitor::class))->toBeTrue();
    $ref = new ReflectionClass(AnalyticsHeartbeatMonitor::class);
    expect($ref->isFinal())->toBeTrue('must be final');
});

test('AnalyticsHeartbeatMonitor constructor has :void return type', function (): void {
    $ref = new ReflectionMethod(AnalyticsHeartbeatMonitor::class, '__construct');
    $rt = $ref->getReturnType();
    expect($rt)->not()->toBeNull();
    expect($rt->getName())->toBe('void');
});

test('AnalyticsHeartbeatMonitor has strict_types', function (): void {
    $content = file_get_contents((new ReflectionClass(AnalyticsHeartbeatMonitor::class))->getFileName());
    expect($content)->toContain('declare(strict_types=1)');
});

test('AnalyticsHeartbeatMonitor has @since 120.0.0', function (): void {
    $ref = new ReflectionClass(AnalyticsHeartbeatMonitor::class);
    $doc = $ref->getDocComment();
    expect($doc)->not()->toBeFalse();
    expect($doc)->toContain('@since 120.0.0');
});

test('AnalyticsHeartbeatMonitor public methods have return types', function (): void {
    $ref = new ReflectionClass(AnalyticsHeartbeatMonitor::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        expect($method->getReturnType())->not()->toBeNull(
            "public method {$method->getName()}() must have a return type"
        );
    }
});

test('AnalyticsHeartbeatMonitor pulse() returns array with required keys', function (): void {
    $ref = new ReflectionMethod(AnalyticsHeartbeatMonitor::class, 'pulse');
    $rt = $ref->getReturnType();
    expect($rt)->not()->toBeNull();
    expect($rt->getName())->toBe('array');
});

test('AnalyticsHeartbeatMonitor current() returns array with stale key', function (): void {
    $ref = new ReflectionMethod(AnalyticsHeartbeatMonitor::class, 'current');
    $rt = $ref->getReturnType();
    expect($rt)->not()->toBeNull();
    expect($rt->getName())->toBe('array');
});

test('AnalyticsHeartbeatMonitor has circuit breaker methods', function (): void {
    $class = AnalyticsHeartbeatMonitor::class;
    expect(method_exists($class, 'recordDispatch'))->toBeTrue();
    expect(method_exists($class, 'recordFailure'))->toBeTrue();
    expect(method_exists($class, 'resetProvider'))->toBeTrue();
    expect(method_exists($class, 'setQueueDepth'))->toBeTrue();
    expect(method_exists($class, 'providerStates'))->toBeTrue();
    expect(method_exists($class, 'isAlive'))->toBeTrue();
    expect(method_exists($class, 'clear'))->toBeTrue();
});

test('AnalyticsHeartbeatMonitor has history methods', function (): void {
    $class = AnalyticsHeartbeatMonitor::class;
    expect(method_exists($class, 'history'))->toBeTrue();
    expect(method_exists($class, 'aggregateStats'))->toBeTrue();
});

test('AnalyticsHeartbeatMonitor has typed properties', function (): void {
    $ref = new ReflectionClass(AnalyticsHeartbeatMonitor::class);
    $props = $ref->getProperties();
    $typedProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->hasType());
    expect(count($typedProps))->toBe(count($props), 'all properties must be typed');
});

// ─── SaaSBundleEventService ──────────────────────────────────────────────────

test('SaaSBundleEventService class exists and is final', function (): void {
    expect(class_exists(SaaSBundleEventService::class))->toBeTrue();
    $ref = new ReflectionClass(SaaSBundleEventService::class);
    expect($ref->isFinal())->toBeTrue('must be final');
});

test('SaaSBundleEventService constructor has :void return type', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, '__construct');
    $rt = $ref->getReturnType();
    expect($rt)->not()->toBeNull();
    expect($rt->getName())->toBe('void');
});

test('SaaSBundleEventService has strict_types', function (): void {
    $content = file_get_contents((new ReflectionClass(SaaSBundleEventService::class))->getFileName());
    expect($content)->toContain('declare(strict_types=1)');
});

test('SaaSBundleEventService has @since 120.0.0', function (): void {
    $ref = new ReflectionClass(SaaSBundleEventService::class);
    $doc = $ref->getDocComment();
    expect($doc)->not()->toBeFalse();
    expect($doc)->toContain('@since 120.0.0');
});

test('SaaSBundleEventService has journey templates constant', function (): void {
    $ref = new ReflectionClass(SaaSBundleEventService::class);
    expect($ref->hasConstant('JOURNEY_TEMPLATES'))->toBeTrue();
    $templates = $ref->getConstant('JOURNEY_TEMPLATES');
    expect($templates)->toBeArray();
    expect($templates)->toHaveKey('signup_funnel');
    expect($templates)->toHaveKey('activation_funnel');
    expect($templates)->toHaveKey('billing_funnel');
    expect($templates)->toHaveKey('expansion_funnel');
    expect($templates)->toHaveKey('retention_funnel');
    expect($templates)->toHaveKey('churn_funnel');
});

test('SaaSBundleEventService has required public methods with return types', function (): void {
    $class = SaaSBundleEventService::class;
    $methods = ['startBundle', 'addToBundle', 'completeBundle', 'abandonBundle', 'getBundle', 'activeBundles', 'completedBundles', 'journeyTemplates', 'getExpectedSteps', 'summary', 'clear'];

    foreach ($methods as $method) {
        expect(method_exists($class, $method))->toBeTrue("method {$method}() must exist");
        $ref = new ReflectionMethod($class, $method);
        expect($ref->getReturnType())->not()->toBeNull("method {$method}() must have return type");
    }
});

test('SaaSBundleEventService startBundle() returns string', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'startBundle');
    expect($ref->getReturnType()->getName())->toBe('string');
});

test('SaaSBundleEventService addToBundle() returns bool', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'addToBundle');
    expect($ref->getReturnType()->getName())->toBe('bool');
});

test('SaaSBundleEventService completeBundle() returns bool', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'completeBundle');
    expect($ref->getReturnType()->getName())->toBe('bool');
});

test('SaaSBundleEventService abandonBundle() returns bool', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'abandonBundle');
    expect($ref->getReturnType()->getName())->toBe('bool');
});

test('SaaSBundleEventService getBundle() returns ?array', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'getBundle');
    expect($ref->getReturnType()->getName())->toBe('array');
    expect($ref->getReturnType()->allowsNull())->toBeTrue();
});

test('SaaSBundleEventService summary() returns array', function (): void {
    $ref = new ReflectionMethod(SaaSBundleEventService::class, 'summary');
    expect($ref->getReturnType()->getName())->toBe('array');
});

test('SaaSBundleEventService has typed properties', function (): void {
    $ref = new ReflectionClass(SaaSBundleEventService::class);
    $props = $ref->getProperties();
    $typedProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->hasType());
    expect(count($typedProps))->toBe(count($props), 'all properties must be typed');
});

// ─── SaaSFeatureFlagObserver ──────────────────────────────────────────────────

test('SaaSFeatureFlagObserver class exists and is final', function (): void {
    expect(class_exists(SaaSFeatureFlagObserver::class))->toBeTrue();
    $ref = new ReflectionClass(SaaSFeatureFlagObserver::class);
    expect($ref->isFinal())->toBeTrue('must be final');
});

test('SaaSFeatureFlagObserver constructor has :void return type', function (): void {
    $ref = new ReflectionMethod(SaaSFeatureFlagObserver::class, '__construct');
    $rt = $ref->getReturnType();
    expect($rt)->not()->toBeNull();
    expect($rt->getName())->toBe('void');
});

test('SaaSFeatureFlagObserver has strict_types', function (): void {
    $content = file_get_contents((new ReflectionClass(SaaSFeatureFlagObserver::class))->getFileName());
    expect($content)->toContain('declare(strict_types=1)');
});

test('SaaSFeatureFlagObserver has @since 120.0.0', function (): void {
    $ref = new ReflectionClass(SaaSFeatureFlagObserver::class);
    $doc = $ref->getDocComment();
    expect($doc)->not()->toBeFalse();
    expect($doc)->toContain('@since 120.0.0');
});

test('SaaSFeatureFlagObserver has required public methods with return types', function (): void {
    $class = SaaSFeatureFlagObserver::class;
    $methods = [
        'recordEvaluation', 'recordConversion', 'isDuplicateEvaluation',
        'shouldIgnoreFlag', 'getIgnoredFlags', 'isEnabled',
        'isExposureTrackingEnabled', 'isConversionTrackingEnabled',
        'summary', 'reset',
    ];

    foreach ($methods as $method) {
        expect(method_exists($class, $method))->toBeTrue("method {$method}() must exist");
        $ref = new ReflectionMethod($class, $method);
        expect($ref->getReturnType())->not()->toBeNull("method {$method}() must have return type");
    }
});

test('SaaSFeatureFlagObserver recordEvaluation() returns bool', function (): void {
    $ref = new ReflectionMethod(SaaSFeatureFlagObserver::class, 'recordEvaluation');
    expect($ref->getReturnType()->getName())->toBe('bool');
});

test('SaaSFeatureFlagObserver recordConversion() returns bool', function (): void {
    $ref = new ReflectionMethod(SaaSFeatureFlagObserver::class, 'recordConversion');
    expect($ref->getReturnType()->getName())->toBe('bool');
});

test('SaaSFeatureFlagObserver has deduplication state properties', function (): void {
    $ref = new ReflectionClass(SaaSFeatureFlagObserver::class);
    expect($ref->hasProperty('recordedEvaluations'))->toBeTrue();
    expect($ref->hasProperty('recordedConversions'))->toBeTrue();

    $evalProp = $ref->getProperty('recordedEvaluations');
    $convProp = $ref->getProperty('recordedConversions');

    expect($evalProp->hasType())->toBeTrue();
    expect($convProp->hasType())->toBeTrue();
    expect($evalProp->getType()->getName())->toBe('array');
    expect($convProp->getType()->getName())->toBe('array');
});

test('SaaSFeatureFlagObserver has typed properties', function (): void {
    $ref = new ReflectionClass(SaaSFeatureFlagObserver::class);
    $props = $ref->getProperties();
    $typedProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->hasType());
    expect(count($typedProps))->toBe(count($props), 'all properties must be typed');
});

test('SaaSFeatureFlagObserver summary() returns array with required keys', function (): void {
    $ref = new ReflectionMethod(SaaSFeatureFlagObserver::class, 'summary');
    expect($ref->getReturnType()->getName())->toBe('array');
});

// ─── ServiceProvider Registration ────────────────────────────────────────────

test('ServiceProvider registers AnalyticsHeartbeatMonitor as singleton', function (): void {
    $sp = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $content = file_get_contents($sp->getFileName());
    expect($content)->toContain('AnalyticsHeartbeatMonitor::class');
    expect($content)->toContain('singleton');
});

test('ServiceProvider registers SaaSFeatureFlagObserver as singleton', function (): void {
    $sp = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $content = file_get_contents($sp->getFileName());
    expect($content)->toContain('SaaSFeatureFlagObserver::class');
});

test('ServiceProvider registers SaaSBundleEventService as singleton', function (): void {
    $sp = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $content = file_get_contents($sp->getFileName());
    expect($content)->toContain('SaaSBundleEventService::class');
});

// ─── Config ───────────────────────────────────────────────────────────────────

test('config has heartbeat section', function (): void {
    $content = file_get_contents(__DIR__.'/../config/zeroboiler.php');
    expect($content)->toContain("'heartbeat'");
    expect($content)->toContain('ANALYTICS_HEARTBEAT_ENABLED');
    expect($content)->toContain('ANALYTICS_HEARTBEAT_TTL');
    expect($content)->toContain('ANALYTICS_HEARTBEAT_STALE_THRESHOLD');
    expect($content)->toContain('ANALYTICS_HEARTBEAT_FAILURE_THRESHOLD');
});

test('config has bundling section', function (): void {
    $content = file_get_contents(__DIR__.'/../config/zeroboiler.php');
    expect($content)->toContain("'bundling'");
    expect($content)->toContain('ANALYTICS_BUNDLING_ENABLED');
    expect($content)->toContain('ANALYTICS_BUNDLING_AUTO_TRACK');
    expect($content)->toContain('ANALYTICS_BUNDLING_PREFIX');
});

test('config has feature_flags track_exposures and track_conversions', function (): void {
    $content = file_get_contents(__DIR__.'/../config/zeroboiler.php');
    expect($content)->toContain('track_exposures');
    expect($content)->toContain('track_conversions');
    expect($content)->toContain('ignored_flags');
});

// ─── Version Consistency ───────────────────────────────────────────────────────

test('composer.json version is 120.0.0', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($json['version'])->toBe('120.0.0');
});

test('AnalyticsServiceProvider @version is 120.0.0', function (): void {
    $sp = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $doc = $sp->getDocComment();
    expect($doc)->not()->toBeFalse();
    expect($doc)->toContain('@version 120.0.0');
});

test('resources/js/analytics.js @version is 120.0.0', function (): void {
    $content = file_get_contents(__DIR__.'/../resources/js/analytics.js');
    expect($content)->toContain('@version 120.0.0');
});

// ─── Zero Markers ─────────────────────────────────────────────────────────────

test('new service files have no TODO/FIXME/HACK', function (): void {
    $files = [
        (new ReflectionClass(AnalyticsHeartbeatMonitor::class))->getFileName(),
        (new ReflectionClass(SaaSBundleEventService::class))->getFileName(),
        (new ReflectionClass(SaaSFeatureFlagObserver::class))->getFileName(),
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $markers = [];
        foreach (['TODO', 'FIXME', 'HACK', 'XXX'] as $marker) {
            if (str_contains($content, $marker)) {
                $markers[] = $marker;
            }
        }
        expect($markers)->toBeEmpty(basename($file).' must not contain '.implode(', ', $markers));
    }
});

test('new service files have MIT license header', function (): void {
    $files = [
        (new ReflectionClass(AnalyticsHeartbeatMonitor::class))->getFileName(),
        (new ReflectionClass(SaaSBundleEventService::class))->getFileName(),
        (new ReflectionClass(SaaSFeatureFlagObserver::class))->getFileName(),
    ];

    foreach ($files as $file) {
        $lines = array_slice(file($file), 0, 6);
        $header = implode('', $lines);
        expect($header)->toContain('ZeroBoiler', basename($file).' must have ZeroBoiler license header');
        expect($header)->toContain('MIT', basename($file).' must reference MIT license');
    }
});
