<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\EventReportingService;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

test('reporting service creates with default config', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'totalEvents' => 0,
        'dispatchedByProvider' => [],
        'failureCount' => 0,
        'eventCounts' => [],
        'trendData' => [],
        'failuresByProvider' => [],
    ]);

    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([]);

    $service = new EventReportingService($metrics, $cache, $config);

    expect($service)->toBeInstanceOf(EventReportingService::class);
    expect($service->isEnabled())->toBeTrue();
});

test('report returns correct structure with daily period', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'totalEvents' => 100,
        'dispatchedByProvider' => ['ga4' => 80, 'meta' => 20],
        'failureCount' => 2,
        'eventCounts' => ['page_view' => 50, 'sign_up' => 30, 'purchase' => 20],
        'trendData' => [],
        'failuresByProvider' => ['ga4' => 2],
    ]);

    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 300,
        'trending_window' => 3600,
        'top_events_limit' => 20,
        'trending_limit' => 10,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $report = $service->report('daily');

    expect($report)
        ->toHaveKey('period')
        ->toHaveKey('generated_at')
        ->toHaveKey('enabled')
        ->toHaveKey('total_events')
        ->toHaveKey('total_dispatched')
        ->toHaveKey('total_failed')
        ->toHaveKey('success_rate')
        ->toHaveKey('by_provider')
        ->toHaveKey('by_category')
        ->toHaveKey('top_events')
        ->toHaveKey('trending_events')
        ->toHaveKey('event_catalog_summary')
        ->toHaveKey('replay_summary');

    expect($report['period'])->toBe('daily');
    expect($report['enabled'])->toBeTrue();
    expect($report['total_events'])->toBe(100);
    expect($report['total_dispatched'])->toBe(100);
    expect($report['total_failed'])->toBe(2);
    expect($report['success_rate'])->toBe(98.0);
    expect($report['by_provider']['ga4'])->toBe(80);
    expect($report['by_provider']['meta'])->toBe(20);
});

test('report when disabled returns minimal structure', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => false,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $report = $service->report('daily');

    expect($report['enabled'])->toBeFalse();
    expect($report['total_events'])->toBe(0);
});

test('quick summary returns correct structure', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'dispatchedByProvider' => ['ga4' => 95, 'meta' => 5],
        'failureCount' => 1,
        'totalEvents' => 100,
        'eventCounts' => ['page_view' => 60, 'sign_up' => 40],
    ]);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $summary = $service->quickSummary();

    expect($summary)
        ->toHaveKey('events')
        ->toHaveKey('dispatched')
        ->toHaveKey('failed')
        ->toHaveKey('success_rate')
        ->toHaveKey('top_event');

    expect($summary['events'])->toBe(100);
    expect($summary['dispatched'])->toBe(100);
    expect($summary['failed'])->toBe(1);
    expect($summary['success_rate'])->toBe(99.0);
    expect($summary['top_event'])->toBe('page_view');
});

test('top events returns sorted by count', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'dispatchedByProvider' => [],
        'failureCount' => 0,
        'totalEvents' => 0,
        'eventCounts' => ['sign_up' => 10, 'page_view' => 50, 'purchase' => 5],
    ]);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $top = $service->topEvents(3);

    expect($top)->toHaveCount(3);
    expect($top[0]['name'])->toBe('page_view');
    expect($top[0]['count'])->toBe(50);
    expect($top[1]['name'])->toBe('sign_up');
    expect($top[2]['name'])->toBe('purchase');
});

test('trending events filters by growth threshold', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'dispatchedByProvider' => [],
        'failureCount' => 0,
        'totalEvents' => 0,
        'eventCounts' => ['sign_up' => 100, 'purchase' => 5],
        'trendData' => [
            'sign_up' => [20, 30, 40, 50], // growing fast
            'purchase' => [5, 5, 5, 5], // stable
        ],
    ]);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
        'trending_window' => 3600,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $trending = $service->trendingEvents(10);

    // sign_up should be trending (high growth), purchase should not (stable)
    $hasSignUp = false;
    $hasPurchase = false;

    foreach ($trending as $event) {
        if ($event['name'] === 'sign_up') {
            $hasSignUp = true;
        }
        if ($event['name'] === 'purchase') {
            $hasPurchase = true;
        }
    }

    expect($hasSignUp)->toBeTrue();
    expect($hasPurchase)->toBeFalse();
});

test('provider stats calculates success rates', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'dispatchedByProvider' => ['ga4' => 100, 'meta' => 50],
        'failuresByProvider' => ['ga4' => 5, 'meta' => 0],
    ]);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);
    $stats = $service->providerStats();

    expect($stats['providers']['ga4']['dispatched'])->toBe(100);
    expect($stats['providers']['ga4']['failed'])->toBe(5);
    expect($stats['providers']['ga4']['success_rate'])->toBe(95.0);
    expect($stats['providers']['meta']['success_rate'])->toBe(100.0);
    expect($stats['totals']['dispatched'])->toBe(150);
    expect($stats['totals']['failed'])->toBe(5);
    expect($stats['totals']['success_rate'])->toBe(96.67);
});

test('empty event counts returns empty top events', function (): void {
    $metrics = mock(AnalyticsMetrics::class);
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $metrics->shouldReceive('snapshot')->andReturn([
        'dispatchedByProvider' => [],
        'failureCount' => 0,
        'totalEvents' => 0,
        'eventCounts' => [],
    ]);

    $config->shouldReceive('get')->with('zeroboiler.analytics.reporting', [])->andReturn([
        'enabled' => true,
    ]);

    $service = new EventReportingService($metrics, $cache, $config);

    expect($service->topEvents())->toBe([]);
    expect($service->trendingEvents())->toBe([]);
});

