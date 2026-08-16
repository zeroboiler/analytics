<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;

// ═══════════════════════════════════════════════════════════════════════════════
// Phase 31 — Deep Production Readiness Audit
// ═══════════════════════════════════════════════════════════════════════════════

test('all 681 source files declare strict_types=1', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    $count = is_array($srcFiles) ? count($srcFiles) : 0;
    expect($count)->toBe(681);

    $violations = [];
    foreach ($srcFiles as $file) {
        if (! str_contains(file_get_contents($file), 'declare(strict_types=1)')) {
            $violations[] = basename($file);
        }
    }
    expect($violations)->toBeEmpty();
});

test('all source files have MIT license header', function (): void {
    $violations = [];
    foreach (glob(__DIR__.'/../src/**/*.php') as $file) {
        if (! str_contains(file_get_contents($file), 'This file is part of ZeroBoiler, licensed under the MIT license.')) {
            $violations[] = basename($file);
        }
    }
    expect($violations)->toBeEmpty();
});

test('no TODO/FIXME/HACK markers in source files', function (): void {
    $violations = [];
    foreach (glob(__DIR__.'/../src/**/*.php') as $file) {
        if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', file_get_contents($file), $m)) {
            $violations[] = basename($file).':'.$m[0];
        }
    }
    expect($violations)->toBeEmpty();
});

test('AnalyticsException is abstract base with Throwable previous', function (): void {
    $ref = new ReflectionClass(AnalyticsException::class);
    expect($ref->isAbstract())->toBeTrue();
    expect($ref->isFinal())->toBeFalse();

    $params = $ref->getConstructor()->getParameters();
    $prev = $params[2];
    expect($prev->getName())->toBe('previous');
    expect($prev->getType()?->getName() === 'Throwable' || $prev->getType()?->allowsNull() === true)->toBeTrue();
});

test('leaf exceptions extend AnalyticsException and are final', function (): void {
    foreach ([InvalidAnalyticsArgumentException::class, AnalyticsRuntimeException::class] as $class) {
        expect((new ReflectionClass($class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
        expect((new ReflectionClass($class))->isFinal())->toBeTrue();
    }
});

test('composer.json has PHP ^8.5 and correct namespace', function (): void {
    $c = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($c['require']['php'])->toBe('^8.5');
    expect($c['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
    expect($c['version'])->not->toBeNull();
});
