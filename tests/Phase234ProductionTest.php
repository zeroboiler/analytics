<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ReflectionClass;
use ReflectionAttribute;

test('Phase 2: strict types on all source files', function (): void {
    $files = glob(__DIR__.'/../src/**/*.php');
    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(file_get_contents($file), "strict_types missing in {$file}")->toContain('declare(strict_types=1)');
    }
});

test('Phase 2: no TODO/FIXME markers', function (): void {
    $files = glob(__DIR__.'/../src/**/*.php');
    foreach ($files as $file) {
        $c = file_get_contents($file);
        expect($c)->not->toContain('TODO');
        expect($c)->not->toContain('FIXME');
    }
});

test('Phase 2: composer.json PHP 8.5+ and stable', function (): void {
    $c = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($c['require']['php'])->toBe('^8.5');
    expect($c['minimum-stability'])->toBe('stable');
    expect($c['prefer-stable'])->toBeTrue();
});

test('Phase 3: ServiceProvider final with #[Override]', function (): void {
    $r = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    expect($r->isFinal())->toBeTrue();
    foreach (['register', 'boot'] as $m) {
        $method = $r->getMethod($m);
        $has = array_any($method->getAttributes(), fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
        expect($has, "ServiceProvider::{$m}() needs #[Override]")->toBeTrue();
    }
});

test('Phase 3: Facade final with #[Override]', function (): void {
    $r = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
    expect($r->isFinal())->toBeTrue();
    $m = $r->getMethod('getFacadeAccessor');
    $has = array_any($m->getAttributes(), fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
    expect($has)->toBeTrue();
});

test('Phase 3: AnalyticsManager is final', function (): void {
    $r = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
    expect($r->isFinal())->toBeTrue();
});

test('Phase 4: version consistency', function (): void {
    $c = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($c['version'])->toMatch('/^\d+\.\d+\.\d+$/');
});
