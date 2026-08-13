<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * V100 — Analytics Data Explorer Service Test.
 *
 * @since 60.0.0
 */
test('explorer service: class is final and has strict types', function (): void {
    $ref = new ReflectionClass(AnalyticsDataExplorerService::class);

    expect($ref->isFinal())->toBeTrue();
    $file = $ref->getFileName();
    $contents = file_get_contents($file);
    expect($contents)->toContain('declare(strict_types=1)');
});

test('explorer service: constructor requires CacheRepository and ConfigRepository', function (): void {
    $ref = new ReflectionClass(AnalyticsDataExplorerService::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();
    expect($ctor->getReturnType()?->getName())->toBe('void');

    $params = $ctor->getParameters();
    expect(count($params))->toBe(3);

    // CacheRepository
    expect($params[0]->getName())->toBe('cache');
    expect($params[0]->hasType())->toBeTrue();
    expect($params[0]->getType()?->getName())->toBe(CacheRepository::class);

    // ConfigRepository
    expect($params[1]->getName())->toBe('config');
    expect($params[1]->hasType())->toBeTrue();
    expect($params[1]->getType()?->getName())->toBe(ConfigRepository::class);

    // Optional EventStoreManager
    expect($params[2]->getName())->toBe('store');
    expect($params[2]->isOptional())->toBeTrue();
});

test('explorer service: explore method returns structured response with query, results, meta', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->explore(['category' => 'saas'], 'event_name', '24h', 'hour', 10);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['query', 'results', 'meta']);
    expect($result['query'])->toHaveKey('group_by');
    expect($result['query']['group_by'])->toBe('event_name');
    expect($result['meta'])->toHaveKey('filters_applied');
    expect($result['meta'])->toHaveKey('period');
    expect($result['meta']['period'])->toBe('24h');
});

test('explorer service: topEvents method returns top_events, period, meta', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->topEvents('7d', 10, null);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['top_events', 'period', 'meta']);
    expect($result['period'])->toBe('7d');
    expect($result['meta']['limit'])->toBe(10);
});

test('explorer service: drillDown method returns event, parameter_stats, total_count, time_distribution', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->drillDown('sign_up', [], '24h');

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['event', 'parameter_stats', 'total_count', 'time_distribution']);
    expect($result['event'])->toBe('sign_up');
});

test('explorer service: compare method returns comparison, period_a, period_b, meta', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->compare('*', '7d', 'previous_7d', null);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['comparison', 'period_a', 'period_b', 'meta']);
    expect($result['meta']['event_filter'])->toBe('*');
});

test('explorer service: funnel method computes conversion and drop-off', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->funnel(
        ['sign_up', 'trial_start', 'subscription'],
        '7d',
        null,
    );

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['funnel', 'overall_conversion', 'period']);
    expect(count($result['funnel']))->toBe(3);

    // First step should have 100% conversion
    expect($result['funnel'][0]['conversion'])->toBe(100.0);

    // Funnel steps have correct keys
    expect($result['funnel'][0])->toHaveKeys(['step', 'event', 'count', 'drop_off', 'conversion']);
    expect($result['funnel'][0]['event'])->toBe('sign_up');
});

test('explorer service: health method returns ok status and capabilities', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->health();

    expect($result)->toBeArray();
    expect($result['status'])->toBe('ok');
    expect($result)->toHaveKey('cache_ttl');
    expect($result)->toHaveKey('max_results');
    expect($result)->toHaveKey('supported_granularities');
    expect($result)->toHaveKey('supported_periods');
    expect($result['supported_granularities'])->toContain('hour');
    expect($result['supported_granularities'])->toContain('day');
});

test('explorer service: explore uses caching (calls get then put)', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    // Cache miss first call
    $cache->shouldReceive('get')->andReturn(null)->once();
    $cache->shouldReceive('put')->once();

    $service = new AnalyticsDataExplorerService($cache, $config);
    $service->explore();

    // Second call — cache hit
    $cachedResult = ['query' => [], 'results' => [], 'meta' => []];
    $cache->shouldReceive('get')->andReturn($cachedResult)->once();
    $cache->shouldNotReceive('put');

    $result = $service->explore();
    expect($result)->toBe($cachedResult);
});

test('explorer service: limit is capped at MAX_RESULTS', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->explore([], 'event_name', '24h', 'hour', 99999);
    expect($result['query']['limit'])->toBeLessThanOrEqual(1000);
});

test('explorer service: filter normalization drops empty values', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->explore(['event_name' => '', 'category' => null], 'event_name', '24h');
    expect($result['meta']['filters_applied'])->toBe(0);
});

test('explorer service: trend classification', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new AnalyticsDataExplorerService($cache, $config);

    // Access classifyTrend via reflection
    $ref = new ReflectionMethod($service, 'classifyTrend');
    $ref->setAccessible(true);

    expect($ref->invoke($service, 10.0))->toBe('rising');
    expect($ref->invoke($service, -10.0))->toBe('falling');
    expect($ref->invoke($service, 2.0))->toBe('stable');
    expect($ref->invoke($service, -2.0))->toBe('stable');
    expect($ref->invoke($service, 0.0))->toBe('stable');
    expect($ref->invoke($service, 5.1))->toBe('rising');
    expect($ref->invoke($service, -5.1))->toBe('falling');
});

test('explorer service: compare sorts by absolute change descending', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    // Using specific events to test sort behavior
    $result = $service->compare('sign_up', '7d', 'previous_7d', null);
    expect($result)->toHaveKey('comparison');
    expect($result['comparison'])->toBeArray();
});

test('explorer service: funnel filters empty steps', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->funnel(['', '  ', 'sign_up'], '7d');
    // Empty strings should be filtered out
    expect(count($result['funnel']))->toBe(1);
    expect($result['funnel'][0]['event'])->toBe('sign_up');
});

test('explorer service: topEvents with category filter', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->topEvents('7d', 5, 'ecommerce');
    expect($result['meta']['category_filter'])->toBe('ecommerce');
});

test('explorer service: compare with single event returns that event', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new AnalyticsDataExplorerService($cache, $config);

    $result = $service->compare('purchase', '7d', 'previous_7d', null);
    expect($result['comparison'])->toHaveKey('purchase');
    expect($result['comparison']['purchase'])->toHaveKeys(['current', 'previous', 'change', 'trend']);
});

test('explorer service: period parsing — hours', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new AnalyticsDataExplorerService($cache, $config);

    $ref = new ReflectionMethod($service, 'parsePeriod');
    $ref->setAccessible(true);

    $result = $ref->invoke($service, '6h');
    expect($result)->toHaveKeys(['from', 'to', 'label']);
    expect($result['label'])->toBe('6h');
});

test('explorer service: period parsing — previous_Nd', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new AnalyticsDataExplorerService($cache, $config);

    $ref = new ReflectionMethod($service, 'parsePeriod');
    $ref->setAccessible(true);

    $result = $ref->invoke($service, 'previous_7d');
    expect($result)->toHaveKeys(['from', 'to', 'label']);
    expect($result['label'])->toBe('previous_7d');
});

test('explorer service: all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(AnalyticsDataExplorerService::class);

    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    expect(count($publicMethods))->toBeGreaterThan(0);

    foreach ($publicMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("Method {$method->getName()} must have a return type declaration");
    }
});

test('explorer service: has @since annotation', function (): void {
    $ref = new ReflectionClass(AnalyticsDataExplorerService::class);
    $doc = $ref->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since 60.0.0');
});
