<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\Services\RealTimeEventStreamService;

test('RealTimeEventStreamService constructs with defaults', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    expect($service->isEnabled())->toBeTrue();
    expect($service->getWindowSeconds())->toBe(60);
    expect($service->getBucketSizeSeconds())->toBe(5);
});

test('RealTimeEventStreamService ingests events and produces snapshot', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    $service->ingest('page_view', 'engagement');
    $service->ingest('page_view', 'engagement');
    $service->ingest('sign_up', 'saas');
    $service->ingest('purchase', 'ecommerce');

    $snapshot = $service->snapshot();

    expect($snapshot['total_events'])->toBe(4);
    expect($snapshot['by_category']['engagement'])->toBe(2);
    expect($snapshot['by_category']['saas'])->toBe(1);
    expect($snapshot['by_category']['ecommerce'])->toBe(1);
    expect($snapshot['by_event']['page_view'])->toBe(2);
    expect($snapshot['buckets'])->toBeGreaterThanOrEqual(1);
});

test('RealTimeEventStreamService topEvents ranked by count', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn(['max_top_events' => 3]);

    $service = new RealTimeEventStreamService($config);

    $service->ingest('click', 'engagement');
    $service->ingest('click', 'engagement');
    $service->ingest('click', 'engagement');
    $service->ingest('scroll', 'engagement');
    $service->ingest('scroll', 'engagement');
    $service->ingest('search', 'engagement');

    $snapshot = $service->snapshot();

    expect($snapshot['top_events'])->toHaveCount(3);
    expect($snapshot['top_events'][0]['name'])->toBe('click');
    expect($snapshot['top_events'][0]['count'])->toBe(3);
    expect($snapshot['top_events'][1]['name'])->toBe('scroll');
    expect($snapshot['top_events'][1]['count'])->toBe(2);
});

test('RealTimeEventStreamService categoryCount and eventCount', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    $service->ingest('sign_up', 'saas');
    $service->ingest('login', 'saas');
    $service->ingest('page_view', 'engagement');

    expect($service->categoryCount('saas'))->toBe(2);
    expect($service->categoryCount('engagement'))->toBe(1);
    expect($service->categoryCount('ecommerce'))->toBe(0);
    expect($service->eventCount('sign_up'))->toBe(1);
    expect($service->eventCount('login'))->toBe(1);
});

test('RealTimeEventStreamService quickSummary returns status', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    // Empty state
    $summary = $service->quickSummary();
    expect($summary['status'])->toBe('idle');
    expect($summary['events_in_window'])->toBe(0);

    // With events
    $service->ingest('page_view', 'engagement');

    $summary = $service->quickSummary();
    expect($summary['status'])->toBe('active');
    expect($summary['events_in_window'])->toBe(1);
});

test('RealTimeEventStreamService clear removes all data', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    $service->ingest('page_view', 'engagement');
    $service->clear();

    $snapshot = $service->snapshot();
    expect($snapshot['total_events'])->toBe(0);
    expect($snapshot['buckets'])->toBe(0);
});

test('RealTimeEventStreamService disabled state ignores all ingests', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn(['enabled' => false]);

    $service = new RealTimeEventStreamService($config);

    expect($service->isEnabled())->toBeFalse();

    $service->ingest('page_view', 'engagement');

    $snapshot = $service->snapshot();
    expect($snapshot['total_events'])->toBe(0);
});

test('RealTimeEventStreamService eventsPerSecond returns float', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    $eps = $service->eventsPerSecond();
    expect($eps)->toBeFloat();
    expect($eps)->toBeGreaterThanOrEqual(0.0);
});

test('RealTimeEventStreamService burst detection', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn(['burst_threshold' => 2.0]);

    $service = new RealTimeEventStreamService($config);

    // Low traffic — no burst
    expect($service->isBurstDetected())->toBeFalse();

    // Ingest events
    for ($i = 0; $i < 10; $i++) {
        $service->ingest('page_view', 'engagement');
    }

    $snapshot = $service->snapshot();
    expect($snapshot['total_events'])->toBe(10);
    expect($snapshot['burst_ratio'])->toBeFloat();
});

test('RealTimeEventStreamService snapshot structure is complete', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.realtime_stream', [])
        ->andReturn([]);

    $service = new RealTimeEventStreamService($config);

    $service->ingest('page_view', 'engagement');

    $snapshot = $service->snapshot();

    expect($snapshot)->toHaveKeys([
        'window_seconds',
        'total_events',
        'events_per_second',
        'by_category',
        'by_event',
        'top_events',
        'burst_detected',
        'burst_ratio',
        'oldest_bucket_age',
        'buckets',
        'computed_at',
    ]);
    expect($snapshot['window_seconds'])->toBe(60);
    expect($snapshot['computed_at'])->toBeString();
});
