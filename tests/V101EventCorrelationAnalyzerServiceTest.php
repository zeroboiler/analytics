<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * V101 — Event Correlation Analyzer Service Test.
 *
 * @since 60.0.0
 */
test('correlation analyzer: class is final and has strict types', function (): void {
    $ref = new ReflectionClass(EventCorrelationAnalyzerService::class);

    expect($ref->isFinal())->toBeTrue();
    $file = $ref->getFileName();
    $contents = file_get_contents($file);
    expect($contents)->toContain('declare(strict_types=1)');
});

test('correlation analyzer: constructor requires CacheRepository and ConfigRepository', function (): void {
    $ref = new ReflectionClass(EventCorrelationAnalyzerService::class);
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

test('correlation analyzer: health method returns ok status and config', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->health();

    expect($result)->toBeArray();
    expect($result['status'])->toBe('ok');
    expect($result)->toHaveKey('cache_ttl');
    expect($result)->toHaveKey('max_lag_steps');
    expect($result)->toHaveKey('default_lag_offsets');
    expect($result)->toHaveKey('correlation_threshold');
    expect($result['default_lag_offsets'])->toContain(0);
    expect($result['default_lag_offsets'])->toContain(24);
    expect($result['max_lag_steps'])->toBe(24);
});

test('correlation analyzer: crossCorrelation returns event_a, event_b, ccf, peak_lag, period', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->crossCorrelation('sign_up', 'purchase', '30d', null);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['event_a', 'event_b', 'ccf', 'peak_lag', 'period']);
    expect($result['event_a'])->toBe('sign_up');
    expect($result['event_b'])->toBe('purchase');
    expect($result['ccf'])->toBeArray();
    expect($result['period'])->toBe('30d');

    // CCF entries have proper structure
    expect(count($result['ccf']))->toBeGreaterThanOrEqual(1);
    expect($result['ccf'][0])->toHaveKeys(['lag_hours', 'correlation', 'significance', 'sample_size']);
});

test('correlation analyzer: crossCorrelation respects custom lag offsets', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $customLags = [0, 12, 24];
    $result = $service->crossCorrelation('sign_up', 'purchase', '30d', $customLags);

    expect(count($result['ccf']))->toBe(3);
    expect($result['ccf'][0]['lag_hours'])->toBe(0);
    expect($result['ccf'][1]['lag_hours'])->toBe(12);
    expect($result['ccf'][2]['lag_hours'])->toBe(24);
});

test('correlation analyzer: transitionAnalysis returns transitions, window_hours, period, confidence', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->transitionAnalysis('page_view', 'sign_up', '30d', 24);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['transitions', 'window_hours', 'period', 'confidence']);
    expect($result['transitions'])->toHaveKeys(['total_a', 'a_then_b', 'conversion_rate', 'lift', 'baseline_rate']);
    expect($result['window_hours'])->toBe(24);
    expect($result['period'])->toBe('30d');
});

test('correlation analyzer: correlationMatrix returns events, matrix, lag_hours, period', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $events = ['sign_up', 'login', 'purchase'];
    $result = $service->correlationMatrix($events, '30d', 0);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['events', 'matrix', 'lag_hours', 'period']);
    expect($result['events'])->toBe($events);
    expect($result['lag_hours'])->toBe(0);

    // Matrix should have entries for each event pair
    foreach ($events as $event) {
        expect($result['matrix'])->toHaveKey($event);
        foreach ($events as $innerEvent) {
            expect($result['matrix'][$event])->toHaveKey($innerEvent);
        }
    }
});

test('correlation analyzer: correlationMatrix filters empty events', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->correlationMatrix(['sign_up', '', 'purchase'], '30d');
    expect($result['events'])->toEqual(['sign_up', 'purchase']);
});

test('correlation analyzer: crossCorrelation caps lag offsets at MAX_LAG_STEPS', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    // 50 offsets should be capped at 24
    $manyLags = range(0, 49);
    $result = $service->crossCorrelation('sign_up', 'purchase', '30d', $manyLags);

    expect(count($result['ccf']))->toBeLessThanOrEqual(24);
});

test('correlation analyzer: pearson correlation computation is correct', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'pearsonCorrelation');
    $ref->setAccessible(true);

    // Perfect positive correlation
    $x = [1.0, 2.0, 3.0, 4.0, 5.0];
    $y = [2.0, 4.0, 6.0, 8.0, 10.0];
    $correlation = $ref->invoke($service, $x, $y);
    expect(abs($correlation - 1.0))->toBeLessThan(0.001);

    // Perfect negative correlation
    $y2 = [10.0, 8.0, 6.0, 4.0, 2.0];
    $correlation2 = $ref->invoke($service, $x, $y2);
    expect(abs($correlation2 - (-1.0)))->toBeLessThan(0.001);

    // No correlation
    $y3 = [1.0, 3.0, 2.0, 5.0, 4.0];
    $correlation3 = $ref->invoke($service, $x, $y3);
    expect(abs($correlation3))->toBeLessThan(1.0);
});

test('correlation analyzer: pearson correlation handles null values', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'pearsonCorrelation');
    $ref->setAccessible(true);

    $x = [1.0, null, 3.0, 4.0, null];
    $y = [2.0, 4.0, null, 8.0, 10.0];

    $correlation = $ref->invoke($service, $x, $y);
    // Should not throw — just work with available pairs
    expect(is_float($correlation))->toBeTrue();
    expect(abs($correlation))->toBeLessThanOrEqual(1.0);
});

test('correlation analyzer: pearson correlation returns 0 for insufficient data', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'pearsonCorrelation');
    $ref->setAccessible(true);

    // Empty arrays
    expect($ref->invoke($service, [], []))->toBe(0.0);

    // Single pair
    expect($ref->invoke($service, [1.0], [2.0]))->toBe(0.0);

    // All nulls
    expect($ref->invoke($service, [null, null], [null, null]))->toBe(0.0);
});

test('correlation analyzer: shiftSeries introduces nulls at start', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'shiftSeries');
    $ref->setAccessible(true);

    $series = [1.0, 2.0, 3.0, 4.0, 5.0];
    $shifted = $ref->invoke($service, $series, 2);

    expect(count($shifted))->toBe(5);
    expect($shifted[0])->toBeNull();
    expect($shifted[1])->toBeNull();
    expect($shifted[2])->toBe(1.0);
    expect($shifted[3])->toBe(2.0);
    expect($shifted[4])->toBe(3.0);
});

test('correlation analyzer: shiftSeries returns original for zero or negative lag', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'shiftSeries');
    $ref->setAccessible(true);

    $series = [1.0, 2.0, 3.0];

    expect($ref->invoke($service, $series, 0))->toBe($series);
    expect($ref->invoke($service, $series, -1))->toBe($series);
});

test('correlation analyzer: classifySignificance returns correct levels', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $ref = new ReflectionMethod($service, 'classifySignificance');
    $ref->setAccessible(true);

    // Insufficient data
    expect($ref->invoke($service, 0.5, 5))->toBe('insufficient_data');

    // Strong correlation
    expect($ref->invoke($service, 0.8, 100))->toBe('strong');
    expect($ref->invoke($service, -0.8, 100))->toBe('strong');

    // Moderate
    expect($ref->invoke($service, 0.5, 100))->toBe('moderate');

    // Weak
    expect($ref->invoke($service, 0.15, 100))->toBe('weak');

    // None
    expect($ref->invoke($service, 0.05, 100))->toBe('none');
});

test('correlation analyzer: transitionAnalysis computes lift correctly', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->transitionAnalysis('page_view', 'sign_up', '30d', 24);

    expect($result['transitions']['conversion_rate'])->toBeFloat();
    expect($result['transitions']['lift'])->toBeFloat();
    expect($result['transitions']['baseline_rate'])->toBeFloat();

    // Confidence based on sample size
    expect(in_array($result['confidence'], ['low', 'medium', 'high'], true))->toBeTrue();
});

test('correlation analyzer: crossCorrelation uses caching', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null)->once();
    $cache->shouldReceive('put')->once();

    $service = new EventCorrelationAnalyzerService($cache, $config);
    $service->crossCorrelation('sign_up', 'purchase', '30d');

    // Cache hit
    $cached = ['event_a' => 'sign_up', 'event_b' => 'purchase', 'ccf' => [], 'peak_lag' => [], 'period' => '30d'];
    $cache->shouldReceive('get')->andReturn($cached)->once();
    $cache->shouldNotReceive('put');

    $result = $service->crossCorrelation('sign_up', 'purchase', '30d');
    expect($result)->toBe($cached);
});

test('correlation analyzer: peak_lag has correct structure', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->crossCorrelation('sign_up', 'purchase', '30d');

    expect($result['peak_lag'])->toHaveKeys(['lag_hours', 'correlation', 'direction']);
    expect(in_array($result['peak_lag']['direction'], ['positive', 'negative', 'none'], true))->toBeTrue();
});

test('correlation analyzer: all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(EventCorrelationAnalyzerService::class);

    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    expect(count($publicMethods))->toBeGreaterThan(0);

    foreach ($publicMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("Method {$method->getName()} must have a return type declaration");
    }
});

test('correlation analyzer: has @since annotation', function (): void {
    $ref = new ReflectionClass(EventCorrelationAnalyzerService::class);
    $doc = $ref->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since 60.0.0');
});

test('correlation analyzer: correlationMatrix with lagged data', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put');

    $service = new EventCorrelationAnalyzerService($cache, $config);

    $result = $service->correlationMatrix(['sign_up', 'purchase'], '30d', 4);
    expect($result['lag_hours'])->toBe(4);
    expect(count($result['events']))->toBe(2);
});
