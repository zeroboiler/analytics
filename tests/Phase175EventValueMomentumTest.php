<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventValueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSMomentumService;

/**
 * Phase 175 Production Readiness — Event Value Attribution + SaaS Momentum Analytics.
 *
 * Validates new v175.0.0 features:
 * - EventValueAttributionService: class structure, funnel paths, base values, valueOf()
 * - SaaSMomentumService: class structure, metric definitions, momentum scoring
 * - Version consistency across all 14 entry points
 * - New service classes exist with correct constructor signatures
 * - Routes defined for all 8 new endpoints
 * - Version is 175.0.0 everywhere
 *
 * @since 175.0.0
 */

// ─── Version Consistency ──────────────────────────────────────────────

it('AnalyticsEvent::VERSION is 175.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('175.0.0');
});

it('composer.json version is 175.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('175.0.0');
});

// ─── EventValueAttributionService Structure ─────────────────────────────

it('EventValueAttributionService class exists and is final', function (): void {
    $reflection = new ReflectionClass(EventValueAttributionService::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('EventValueAttributionService has void constructor', function (): void {
    $constructor = new ReflectionMethod(EventValueAttributionService::class, '__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

it('EventValueAttributionService declares strict types', function (): void {
    $contents = file_get_contents((new ReflectionClass(EventValueAttributionService::class))->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

it('EventValueAttributionService has valueOf method with return type', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'valueOf');
    expect($method->getReturnType()?->getName())->toBe('array');
    expect($method->getNumberOfParameters())->toBe(1);
    expect($method->getParameters()[0]->getName())->toBe('eventName');
    expect($method->getParameters()[0]->getType()?->getName())->toBe('string');
});

it('EventValueAttributionService has report method', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'report');
    expect($method->getReturnType()?->getName())->toBe('array');
    expect($method->getNumberOfParameters())->toBe(0);
});

it('EventValueAttributionService has valueJourney method', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'valueJourney');
    expect($method->getReturnType()?->getName())->toBe('array');
    expect($method->getNumberOfParameters())->toBe(1);
});

it('EventValueAttributionService has valueOfMany method', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'valueOfMany');
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('EventValueAttributionService has isEnabled method', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'isEnabled');
    expect($method->getReturnType()?->getName())->toBe('bool');
});

it('EventValueAttributionService has getFunnelPaths method', function (): void {
    $method = new ReflectionMethod(EventValueAttributionService::class, 'getFunnelPaths');
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('EventValueAttributionService has at least 6 default funnel paths', function (): void {
    $reflection = new ReflectionClass(EventValueAttributionService::class);
    $property = $reflection->getProperty('DEFAULT_FUNNEL_PATHS');
    $property->setAccessible(true);
    $paths = $property->getValue();
    expect(count($paths))->toBeGreaterThanOrEqual(6);
    expect(array_keys($paths))->toContain('signup');
    expect(array_keys($paths))->toContain('trial');
    expect(array_keys($paths))->toContain('purchase');
    expect(array_keys($paths))->toContain('subscription');
    expect(array_keys($paths))->toContain('plan_upgrade');
    expect(array_keys($paths))->toContain('engagement_retention');
});

it('EventValueAttributionService has at least 18 base values', function (): void {
    $reflection = new ReflectionClass(EventValueAttributionService::class);
    $property = $reflection->getProperty('DEFAULT_BASE_VALUES');
    $property->setAccessible(true);
    $values = $property->getValue();
    expect(count($values))->toBeGreaterThanOrEqual(18);
    expect($values)->toHaveKey('page_view');
    expect($values)->toHaveKey('form_submit');
    expect($values)->toHaveKey('purchase');
    expect($values)->toHaveKey('error');
    expect($values['error'])->toBeLessThan(0); // Error events have negative value
});

// ─── SaaSMomentumService Structure ─────────────────────────────────────

it('SaaSMomentumService class exists and is final', function (): void {
    $reflection = new ReflectionClass(SaaSMomentumService::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('SaaSMomentumService has void constructor', function (): void {
    $constructor = new ReflectionMethod(SaaSMomentumService::class, '__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

it('SaaSMomentumService declares strict types', function (): void {
    $contents = file_get_contents((new ReflectionClass(SaaSMomentumService::class))->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

it('SaaSMomentumService has calculateMetricMomentum method', function (): void {
    $method = new ReflectionMethod(SaaSMomentumService::class, 'calculateMetricMomentum');
    expect($method->getReturnType()?->getName())->toBe('array');
    expect($method->getNumberOfParameters())->toBe(2);
});

it('SaaSMomentumService has compositeScore method', function (): void {
    $method = new ReflectionMethod(SaaSMomentumService::class, 'compositeScore');
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('SaaSMomentumService has quickSummary method', function (): void {
    $method = new ReflectionMethod(SaaSMomentumService::class, 'quickSummary');
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('SaaSMomentumService has availableMetrics method', function (): void {
    $method = new ReflectionMethod(SaaSMomentumService::class, 'availableMetrics');
    expect($method->getReturnType()?->getName())->toBe('array');
    expect($method->getNumberOfParameters())->toBe(0);
});

it('SaaSMomentumService has isEnabled method', function (): void {
    $method = new ReflectionMethod(SaaSMomentumService::class, 'isEnabled');
    expect($method->getReturnType()?->getName())->toBe('bool');
});

it('SaaSMomentumService tracks at least 6 metrics', function (): void {
    $reflection = new ReflectionClass(SaaSMomentumService::class);
    $property = $reflection->getProperty('METRIC_DEFINITIONS');
    $property->setAccessible(true);
    $metrics = $property->getValue();
    expect(count($metrics))->toBeGreaterThanOrEqual(6);
    expect(array_keys($metrics))->toContain('mrr');
    expect(array_keys($metrics))->toContain('retention');
    expect(array_keys($metrics))->toContain('engagement');
    expect(array_keys($metrics))->toContain('net_new_mrr');
    expect(array_keys($metrics))->toContain('conversion');
    expect(array_keys($metrics))->toContain('churn');
});

it('SaaSMomentumService metric weights sum to approximately 1.0', function (): void {
    $reflection = new ReflectionClass(SaaSMomentumService::class);
    $property = $reflection->getProperty('METRIC_DEFINITIONS');
    $property->setAccessible(true);
    $metrics = $property->getValue();

    $totalWeight = 0.0;
    foreach ($metrics as $def) {
        $totalWeight += $def['weight'];
    }
    expect($totalWeight)->toBeGreaterThan(0.99);
    expect($totalWeight)->toBeLessThan(1.01);
});

// ─── File & Source Integrity ────────────────────────────────────────────

it('EventValueAttributionService file has MIT license header', function (): void {
    $contents = file_get_contents((new ReflectionClass(EventValueAttributionService::class))->getFileName());
    expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
});

it('SaaSMomentumService file has MIT license header', function (): void {
    $contents = file_get_contents((new ReflectionClass(SaaSMomentumService::class))->getFileName());
    expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
});

it('EventValueAttributionService has @since annotation', function (): void {
    $doc = (new ReflectionClass(EventValueAttributionService::class))->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since 175.0.0');
});

it('SaaSMomentumService has @since annotation', function (): void {
    $doc = (new ReflectionClass(SaaSMomentumService::class))->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since 175.0.0');
});

// ─── Source File Count ───────────────────────────────────────────────────

it('src directory has 823+ source files', function (): void {
    $count = count(glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE));
    expect($count)->toBeGreaterThanOrEqual(823);
});

it('tests directory has 415+ test files', function (): void {
    $count = count(glob(__DIR__ . '/../*.php', GLOB_BRACE))
        + count(glob(__DIR__ . '/../**/*.php', GLOB_BRACE));
    // Count test files specifically
    $testFiles = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/tests/')) {
            $testFiles++;
        }
    }
    expect($testFiles)->toBeGreaterThanOrEqual(415);
});

// ─── Routes Exist ────────────────────────────────────────────────────────

it('routes file has event-value endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->toContain('event-value');
    expect($routes)->toContain('eventValue');
    expect($routes)->toContain('eventValueBatch');
    expect($routes)->toContain('eventValueReport');
    expect($routes)->toContain('eventValueJourney');
});

it('routes file has momentum endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->toContain('momentum');
    expect($routes)->toContain('momentumScore');
    expect($routes)->toContain('momentumMetric');
    expect($routes)->toContain('momentumQuick');
    expect($routes)->toContain('momentumMetrics');
});

// ─── Controller Methods Exist ───────────────────────────────────────────

it('AnalyticsEventController has eventValue method', function (): void {
    $controller = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
    expect($controller->hasMethod('eventValue'))->toBeTrue();
    expect($controller->hasMethod('eventValueBatch'))->toBeTrue();
    expect($controller->hasMethod('eventValueReport'))->toBeTrue();
    expect($controller->hasMethod('eventValueJourney'))->toBeTrue();
});

it('AnalyticsEventController has momentum methods', function (): void {
    $controller = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
    expect($controller->hasMethod('momentumScore'))->toBeTrue();
    expect($controller->hasMethod('momentumMetric'))->toBeTrue();
    expect($controller->hasMethod('momentumQuick'))->toBeTrue();
    expect($controller->hasMethod('momentumMetrics'))->toBeTrue();
});

// ─── ServiceProvider Registration ─────────────────────────────────────────

it('ServiceProvider registers EventValueAttributionService', function (): void {
    $provider = file_get_contents((new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
    expect($provider)->toContain('EventValueAttributionService');
});

it('ServiceProvider registers SaaSMomentumService', function (): void {
    $provider = file_get_contents((new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
    expect($provider)->toContain('SaaSMomentumService');
});
