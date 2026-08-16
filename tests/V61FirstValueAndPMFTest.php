<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\FirstValueDetectorService;
use ZeroBoiler\Analytics\Services\ProductMarketFitScoringService;
use Mockery;

test('first value detector fires event on first occurrence', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('has')->andReturn(false);
    $cache->shouldReceive('put')->once();

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $event = new AnalyticsEvent(
        name: 'search',
        params: ['query' => 'analytics'],
        clientId: 'client-123',
        userId: 'user-456',
    );

    $result = $service->detect($event);

    expect($result)->not->toBeNull();
    expect($result->name)->toBe('first_value');
    expect($result->params['milestone'])->toBe('first_search');
    expect($result->params['milestone_label'])->toBe('First Search Performed');
    expect($result->params['milestone_weight'])->toBe(1.5);
});

test('first value detector returns null on subsequent occurrence', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('has')->andReturn(true);
    $cache->shouldNotReceive('put');

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $event = new AnalyticsEvent(
        name: 'search',
        params: ['query' => 'analytics'],
        clientId: 'client-123',
        userId: 'user-456',
    );

    $result = $service->detect($event);

    expect($result)->toBeNull();
});

test('first value detector returns null when disabled', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new FirstValueDetectorService($cache, ['enabled' => false]);

    $event = new AnalyticsEvent(
        name: 'search',
        params: [],
        clientId: 'client-123',
        userId: 'user-456',
    );

    expect($service->detect($event))->toBeNull();
});

test('first value detector returns null for events without user id', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $event = new AnalyticsEvent(
        name: 'search',
        params: [],
        clientId: 'client-123',
        userId: null,
    );

    expect($service->detect($event))->toBeNull();
});

test('first value detector returns null for non-milestone events', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $event = new AnalyticsEvent(
        name: 'custom_event',
        params: [],
        clientId: 'client-123',
        userId: 'user-456',
    );

    expect($service->detect($event))->toBeNull();
});

test('first value score computation', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('has')->andReturn(true, false, true);
    $cache->shouldReceive('put')->once();

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $score = $service->getScore('user-456');

    expect($score)->toHaveKeys(['score', 'max_score', 'percentage', 'milestones']);
    expect($score['percentage'])->toBeGreaterThanOrEqual(0.0);
    expect($score['percentage'])->toBeLessThanOrEqual(100.0);
});

test('first value milestone reset', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('forget')->twice();

    $service = new FirstValueDetectorService($cache, ['enabled' => true]);

    $service->resetMilestone('user-456', 'first_search');
    $service->resetAll('user-456');
});

test('pmf scoring computes score with all signals', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true]);

    $result = $service->compute([
        'activation_rate' => 60.0,
        'retention_week2' => 45.0,
        'feature_depth_score' => 70.0,
        'organic_growth_rate' => 20.0,
        'nps_proxy' => 80.0,
    ]);

    expect($result)->toHaveKeys(['score', 'grade', 'grade_label', 'breakdown', 'signals_received', 'recommendations']);
    expect($result['score'])->toBeGreaterThanOrEqual(0.0);
    expect($result['score'])->toBeLessThanOrEqual(100.0);
    expect($result['grade'])->toBeString();
    expect(count($result['signals_received']))->toBe(5);
});

test('pmf scoring with partial signals', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true]);

    $result = $service->compute([
        'activation_rate' => 50.0,
        'retention_week2' => 30.0,
    ]);

    expect($result['score'])->toBeGreaterThanOrEqual(0.0);
    expect(count($result['signals_received']))->toBe(2);
    expect($result['recommendations'])->not->toBeEmpty();
});

test('pmf scoring with no signals', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true]);

    $result = $service->compute([]);

    expect($result['score'])->toBe(0.0);
    expect($result['grade'])->toBe('D');
    expect(count($result['recommendations']))->toBe(5);
});

test('pmf scoring grade thresholds', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, [
        'enabled' => true,
        'thresholds' => [
            'very_early' => 25.0,
            'early' => 40.0,
            'strong' => 60.0,
            'excellent' => 75.0,
        ],
    ]);

    // Test D grade (pre-PMF)
    $low = $service->compute(['activation_rate' => 5.0]);
    expect($low['grade'])->toBe('D');

    // Test C grade (very early)
    $midLow = $service->compute(['activation_rate' => 50.0]);
    expect($midLow['grade'])->toBe('B');

    // Test A+ grade (excellent)
    $high = $service->compute([
        'activation_rate' => 95.0,
        'retention_week2' => 75.0,
        'feature_depth_score' => 90.0,
        'organic_growth_rate' => 40.0,
        'nps_proxy' => 95.0,
    ]);
    expect($high['grade'])->toBe('A+');
});

test('pmf summary returns compact overview', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true]);

    $summary = $service->summary([
        'activation_rate' => 60.0,
        'retention_week2' => 40.0,
    ]);

    expect($summary)->toHaveKeys(['pmf_score', 'pmf_grade', 'pmf_grade_label', 'readiness', 'top_signal', 'weakest_signal']);
    expect($summary['readiness']['signals_count'])->toBe(2);
    expect($summary['readiness']['max_signals'])->toBe(5);
    expect($summary['readiness']['coverage'])->toBe(40.0);
});

test('pmf cached computation', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->with('zb_pmf_score', 3600, \Mockery::type('Closure'))
        ->andReturnUsing(function (string $key, int $ttl, callable $callback): array {
            return $callback();
        });

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true, 'cache_ttl' => 3600]);

    $result = $service->computeCached(['activation_rate' => 50.0]);

    expect($result['score'])->toBeGreaterThanOrEqual(0.0);
});

test('pmf cache invalidation', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('forget')->once()->with('zb_pmf_score');

    $service = new ProductMarketFitScoringService($cache, ['enabled' => true]);

    $service->invalidateCache();
});

test('pmf config accessors', function (): void {
    $cache = mock(CacheRepository::class);

    $service = new ProductMarketFitScoringService($cache, [
        'enabled' => true,
        'weights' => ['activation_rate' => 0.30],
        'thresholds' => ['excellent' => 80.0],
    ]);

    expect($service->isEnabled())->toBeTrue();
    expect($service->getWeights())->toHaveKey('activation_rate');
    expect($service->getThresholds())->toHaveKey('excellent');
});
