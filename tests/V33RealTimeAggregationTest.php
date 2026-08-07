<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\RealTimeAggregationService;

beforeEach(function (): void {
    $this->metrics = new AnalyticsMetrics;
    $this->cache = app('cache')->driver('array');
    $this->config = app('config');
    $this->config->set('zeroboiler.analytics.realtime', [
        'enabled' => true,
        'window_seconds' => 120,
        'top_events_limit' => 20,
    ]);
    $this->service = new RealTimeAggregationService(
        $this->metrics,
        $this->cache,
        $this->config,
    );
});

test('real-time aggregation records events correctly', function (): void {
    $event = new AnalyticsEvent(name: 'page_view', params: []);
    $this->service->record($event);
    $this->service->record($event);
    $this->service->record(new AnalyticsEvent(name: 'click', params: []));

    $snapshot = $this->service->snapshot();

    expect($snapshot['total'])->toBe(3);
    expect($snapshot['events'])->toHaveKey('page_view');
    expect($snapshot['events']['page_view'])->toBe(2);
    expect($snapshot['events'])->toHaveKey('click');
    expect($snapshot['events']['click'])->toBe(1);
});

test('real-time aggregation tracks unique users', function (): void {
    $event1 = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-1', userId: 'user-1');
    $event2 = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-2', userId: 'user-1');
    $event3 = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-3', userId: 'user-2');

    $this->service->record($event1);
    $this->service->record($event2);
    $this->service->record($event3);

    $snapshot = $this->service->snapshot();

    expect($snapshot['unique_users'])->toBe(2);
    expect($snapshot['total'])->toBe(3);
});

test('real-time top events returns sorted by count', function (): void {
    $this->service->record(new AnalyticsEvent(name: 'click', params: []));
    $this->service->record(new AnalyticsEvent(name: 'click', params: []));
    $this->service->record(new AnalyticsEvent(name: 'click', params: []));
    $this->service->record(new AnalyticsEvent(name: 'page_view', params: []));
    $this->service->record(new AnalyticsEvent(name: 'form_submit', params: []));

    $top = $this->service->topEvents(2);

    expect($top)->toHaveCount(2);
    expect($top[0]['name'])->toBe('click');
    expect($top[0]['count'])->toBe(3);
    expect($top[1]['name'])->toBe('page_view');
});

test('real-time events per second calculates correctly', function (): void {
    $this->service->record(new AnalyticsEvent(name: 'event', params: []));

    $eps = $this->service->eventsPerSecond();

    expect($eps)->toBeGreaterThan(0.0);
});

test('real-time aggregation returns summary', function (): void {
    $this->service->record(new AnalyticsEvent(name: 'purchase', params: []));

    $summary = $this->service->summary();

    expect($summary)->toHaveKeys(['enabled', 'total_events', 'unique_users', 'events_per_second', 'top_events', 'providers', 'window_seconds']);
    expect($summary['enabled'])->toBeTrue();
    expect($summary['total_events'])->toBe(1);
});

test('real-time aggregation does nothing when disabled', function (): void {
    $this->config->set('zeroboiler.analytics.realtime.enabled', false);
    $disabledService = new RealTimeAggregationService($this->metrics, $this->cache, $this->config);

    $disabledService->record(new AnalyticsEvent(name: 'event', params: []));

    expect($disabledService->isEnabled())->toBeFalse();
    expect($disabledService->snapshot()['total'])->toBe(0);
});

test('real-time aggregation can be cleared', function (): void {
    $this->service->record(new AnalyticsEvent(name: 'event', params: []));
    expect($this->service->snapshot()['total'])->toBe(1);

    $this->service->clear();

    expect($this->service->snapshot()['total'])->toBe(0);
});
