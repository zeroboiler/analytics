<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDailyHealthReportCommand;
use ZeroBoiler\Analytics\Services\AnalyticsDailyHealthReportService;

test('AnalyticsDailyHealthReportCommand is final', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);

    expect($reflection->isFinal())->toBeTrue();
});

test('AnalyticsDailyHealthReportCommand has strict_types declaration', function (): void {
    $file = file_get_contents((new \ReflectionClass(AnalyticsDailyHealthReportCommand::class))->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
});

test('AnalyticsDailyHealthReportCommand has MIT license header', function (): void {
    $file = file_get_contents((new \ReflectionClass(AnalyticsDailyHealthReportCommand::class))->getFileName());
    expect($file)->toContain('This file is part of ZeroBoiler');
});

test('AnalyticsDailyHealthReportCommand has @since docblock tag', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since');
    expect($doc)->toContain('116.0.0');
});

test('handle method has Override attribute', function (): void {
    $method = new \ReflectionMethod(AnalyticsDailyHealthReportCommand::class, 'handle');

    $attributes = $method->getAttributes(\Override::class);
    expect($attributes)->toHaveCount(1);
});

test('handle method has return type int', function (): void {
    $method = new \ReflectionMethod(AnalyticsDailyHealthReportCommand::class, 'handle');
    expect((string) $method->getReturnType())->toBe('int');
});

test('command signature contains zb:analytics:health-report', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('zb:analytics:health-report');
});

test('command has force option', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('--force');
});

test('command has json option', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('--json');
});

test('command has domain option', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('--domain=*');
});

test('command has compact option', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('--compact');
});

test('command has clear-cache option', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('signature');
    $prop->setAccessible(true);
    $signature = $prop->getValue(new ($reflection->getName()));

    expect($signature)->toContain('--clear-cache');
});

test('command description is non-empty', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $prop = $reflection->getProperty('description');
    $prop->setAccessible(true);
    $description = $prop->getValue(new ($reflection->getName()));

    expect($description)->toBeString()
        ->not->toBeEmpty();
});

test('all private methods have return type declarations', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);

    foreach ($reflection->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("Private method {$method->getName()} is missing return type declaration");
    }
});

test('statusEmoji returns correct emoji for score ranges', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $method = $reflection->getMethod('statusEmoji');
    $method->setAccessible(true);

    expect($method->invoke($instance, 95))->toBe('🟢');
    expect($method->invoke($instance, 80))->toBe('🟢');
    expect($method->invoke($instance, 65))->toBe('🟡');
    expect($method->invoke($instance, 50))->toBe('🟠');
    expect($method->invoke($instance, 40))->toBe('🟠');
    expect($method->invoke($instance, 10))->toBe('🔴');
});

test('domainStatusEmoji returns correct emoji for statuses', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $method = $reflection->getMethod('domainStatusEmoji');
    $method->setAccessible(true);

    expect($method->invoke($instance, 'healthy'))->toBe('✅');
    expect($method->invoke($instance, 'degraded'))->toBe('⚠️');
    expect($method->invoke($instance, 'critical'))->toBe('❌');
    expect($method->invoke($instance, 'unknown'))->toBe('⚪');
});

test('countLabel formats singular and plural', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $method = $reflection->getMethod('countLabel');
    $method->setAccessible(true);

    expect($method->invoke($instance, 1))->toBe('1 issue');
    expect($method->invoke($instance, 5))->toBe('5 issues');
    expect($method->invoke($instance, 0))->toBe('0 issues');
});

test('scoreBar generates 20 character bar', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportCommand::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $method = $reflection->getMethod('scoreBar');
    $method->setAccessible(true);

    $bar100 = $method->invoke($instance, 100);
    expect($bar100)->toContain('20');

    $bar0 = $method->invoke($instance, 0);
    expect($bar0)->toContain('0%');
});
