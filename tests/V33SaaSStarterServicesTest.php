<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\AnalyticsSnapshotService;
use ZeroBoiler\Analytics\Services\SaasKpiTracker;
use ZeroBoiler\Analytics\Services\UtmAggregationService;

beforeEach(function (): void {
    $this->metrics = new AnalyticsMetrics;
    $this->cache = app('cache')->driver('array');
    $this->config = app('config');
});

// ── AnalyticsSnapshotService Tests ──────────────────────────────────

test('snapshot service takes daily snapshots', function (): void {
    $this->config->set('zeroboiler.analytics.snapshots', [
        'enabled' => true,
        'daily_ttl' => 7776000,
        'hourly_ttl' => 604800,
        'max_daily' => 90,
        'max_hourly' => 168,
    ]);

    $service = new AnalyticsSnapshotService($this->metrics, $this->cache, $this->config);

    $result = $service->takeDailySnapshot('2026-01-15');

    expect($result['stored'])->toBeTrue();
    expect($result['date'])->toBe('2026-01-15');
    expect($result['snapshot']['type'])->toBe('daily');
    expect($result['snapshot']['period'])->toBe('2026-01-15');
    expect($result['snapshot'])->toHaveKey('captured_at');
    expect($result['snapshot'])->toHaveKey('total_dispatched');
});

test('snapshot service retrieves daily snapshots', function (): void {
    $this->config->set('zeroboiler.analytics.snapshots', ['enabled' => true]);
    $service = new AnalyticsSnapshotService($this->metrics, $this->cache, $this->config);

    $service->takeDailySnapshot('2026-01-15');

    $retrieved = $service->getDailySnapshot('2026-01-15');

    expect($retrieved)->not->toBeNull();
    expect($retrieved['type'])->toBe('daily');
});

test('snapshot service returns null for missing snapshots', function (): void {
    $this->config->set('zeroboiler.analytics.snapshots', ['enabled' => true]);
    $service = new AnalyticsSnapshotService($this->metrics, $this->cache, $this->config);

    expect($service->getDailySnapshot('2099-01-01'))->toBeNull();
});

test('snapshot service comparison computes delta', function (): void {
    $this->config->set('zeroboiler.analytics.snapshots', ['enabled' => true]);
    $service = new AnalyticsSnapshotService($this->metrics, $this->cache, $this->config);

    // Simulate some dispatches before "yesterday" snapshot
    $this->metrics->incrementDispatched('ga4');
    $this->metrics->incrementDispatched('ga4');
    $service->takeDailySnapshot('yesterday');

    // More dispatches for "today"
    $this->metrics->incrementDispatched('ga4');
    $this->metrics->incrementDispatched('ga4');
    $this->metrics->incrementDispatched('ga4');
    $service->takeDailySnapshot('today');

    $comparison = $service->dailyComparison();

    expect($comparison['today'])->not->toBeNull();
    expect($comparison['yesterday'])->not->toBeNull();
    expect($comparison['delta'])->not->toBeNull();
});

test('snapshot service summary includes all fields', function (): void {
    $this->config->set('zeroboiler.analytics.snapshots', ['enabled' => true]);
    $service = new AnalyticsSnapshotService($this->metrics, $this->cache, $this->config);

    $summary = $service->summary();

    expect($summary)->toHaveKeys([
        'enabled', 'daily_ttl', 'hourly_ttl', 'max_daily', 'max_hourly',
        'latest_daily', 'latest_hourly', 'comparison',
    ]);
});

// ── SaasKpiTracker Tests ────────────────────────────────────────────

test('KPI tracker records subscriptions', function (): void {
    $manager = mock(AnalyticsManager::class);
    $this->config->set('zeroboiler.analytics.saas_kpi', ['enabled' => true, 'cache_ttl' => 2592000]);
    $tracker = new SaasKpiTracker($manager, $this->cache, $this->config);

    $tracker->recordSubscription('user-1', 'pro', 29.99);
    $tracker->recordSubscription('user-2', 'enterprise', 99.99);
    $tracker->recordSubscription('user-3', 'pro', 29.99);

    expect($tracker->getMrr())->toBe(159.97);
    expect($tracker->getArr())->toBe(1919.64);
    expect($tracker->getActiveSubscriberCount())->toBe(3);
    expect($tracker->getArpu())->toBe(53.32);
});

test('KPI tracker handles cancellations', function (): void {
    $manager = mock(AnalyticsManager::class);
    $this->config->set('zeroboiler.analytics.saas_kpi', ['enabled' => true]);
    $tracker = new SaasKpiTracker($manager, $this->cache, $this->config);

    $tracker->recordSubscription('user-1', 'pro', 29.99);
    $tracker->recordSubscription('user-2', 'pro', 29.99);
    $tracker->recordCancellation('user-1', 'too_expensive');

    expect($tracker->getActiveSubscriberCount())->toBe(1);
    expect($tracker->getMrr())->toBe(29.99);
});

test('KPI tracker tracks trial conversion rate', function (): void {
    $manager = mock(AnalyticsManager::class);
    $this->config->set('zeroboiler.analytics.saas_kpi', ['enabled' => true]);
    $tracker = new SaasKpiTracker($manager, $this->cache, $this->config);

    $tracker->recordTrialStart('u1', 'pro');
    $tracker->recordTrialStart('u2', 'pro');
    $tracker->recordTrialStart('u3', 'pro');
    $tracker->recordTrialStart('u4', 'pro');
    $tracker->recordTrialConversion('u1');
    $tracker->recordTrialConversion('u3');

    expect($tracker->getTrialConversionRate())->toBe(0.5);
});

test('KPI tracker plan distribution', function (): void {
    $manager = mock(AnalyticsManager::class);
    $this->config->set('zeroboiler.analytics.saas_kpi', ['enabled' => true]);
    $tracker = new SaasKpiTracker($manager, $this->cache, $this->config);

    $tracker->recordSubscription('u1', 'free', 0);
    $tracker->recordSubscription('u2', 'pro', 29.99);
    $tracker->recordSubscription('u3', 'pro', 29.99);
    $tracker->recordSubscription('u4', 'enterprise', 99.99);

    $summary = $tracker->summary();

    expect($summary['plan_distribution']['pro'])->toBe(2);
    expect($summary['plan_distribution']['enterprise'])->toBe(1);
    expect($summary['plan_distribution']['free'])->toBe(1);
});

test('KPI tracker MRR history', function (): void {
    $manager = mock(AnalyticsManager::class);
    $this->config->set('zeroboiler.analytics.saas_kpi', ['enabled' => true]);
    $tracker = new SaasKpiTracker($manager, $this->cache, $this->config);

    $tracker->recordSubscription('u1', 'pro', 29.99);
    $tracker->recordSubscription('u2', 'pro', 29.99);

    $history = $tracker->getMrrHistory();

    expect($history)->not->toBeEmpty();
    expect($history[0])->toHaveKey('timestamp');
    expect($history[0])->toHaveKey('mrr');
});

// ── UtmAggregationService Tests ──────────────────────────────────────

test('UTM aggregation records events by source', function (): void {
    $this->config->set('zeroboiler.analytics.utm_aggregation', ['enabled' => true, 'cache_ttl' => 2592000, 'max_combinations' => 5000]);
    $service = new UtmAggregationService($this->cache, $this->config);

    $service->record('page_view', ['utm_source' => 'google', 'utm_medium' => 'cpc'], 'user-1');
    $service->record('page_view', ['utm_source' => 'google', 'utm_medium' => 'cpc'], 'user-2');
    $service->record('signup', ['utm_source' => 'google', 'utm_medium' => 'cpc'], 'user-1');

    $data = $service->get(['utm_source' => 'google', 'utm_medium' => 'cpc']);

    expect($data)->not->toBeNull();
    expect($data['source'])->toBe('google');
    expect($data['total'])->toBe(3);
    expect($data['unique_users'])->toBe(2);
    expect($data['events']['page_view'])->toBe(2);
    expect($data['events']['signup'])->toBe(1);
});

test('UTM aggregation returns top sources', function (): void {
    $this->config->set('zeroboiler.analytics.utm_aggregation', ['enabled' => true, 'cache_ttl' => 2592000, 'max_combinations' => 5000]);
    $service = new UtmAggregationService($this->cache, $this->config);

    for ($i = 0; $i < 5; $i++) {
        $service->record('event', ['utm_source' => 'google']);
        $service->record('event', ['utm_source' => 'twitter']);
    }
    $service->record('event', ['utm_source' => 'facebook']);

    $top = $service->topSources(2);

    expect($top)->toHaveCount(2);
    expect($top[0]['source'])->toBe('google');
    expect($top[0]['total'])->toBe(5);
    expect($top[1]['source'])->toBe('twitter');
});

test('UTM aggregation ignores events without UTM params', function (): void {
    $this->config->set('zeroboiler.analytics.utm_aggregation', ['enabled' => true, 'cache_ttl' => 2592000, 'max_combinations' => 5000]);
    $service = new UtmAggregationService($this->cache, $this->config);

    $data = $service->get([]);
    expect($data)->toBeNull();

    $data = $service->get(['utm_source' => '']);
    expect($data)->toBeNull();
});

test('UTM aggregation summary returns all data', function (): void {
    $this->config->set('zeroboiler.analytics.utm_aggregation', ['enabled' => true, 'cache_ttl' => 2592000, 'max_combinations' => 5000]);
    $service = new UtmAggregationService($this->cache, $this->config);

    $service->record('event', ['utm_source' => 'google', 'utm_medium' => 'organic']);

    $summary = $service->summary();

    expect($summary)->toHaveKeys(['enabled', 'top_sources', 'top_campaigns', 'source_medium_breakdown']);
});

// ── GeolocationEnricher Tests ────────────────────────────────────────

test('geolocation enricher uses header strategy', function (): void {
    $enricher = new \ZeroBoiler\Analytics\Pipeline\GeolocationEnricher(
        strategy: 'header',
        countryHeader: 'X-Country',
        enabled: true,
    );

    $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
        name: 'page_view',
        params: [
            '__client_ip__' => '1.2.3.4',
            '__headers__' => ['X-Country' => 'US'],
        ],
    );

    $enriched = $enricher->process($event);

    expect($enriched)->not->toBeNull();
    expect($enriched->params['geo_country'])->toBe('US');
});

test('geolocation enricher skips private IPs', function (): void {
    $enricher = new \ZeroBoiler\Analytics\Pipeline\GeolocationEnricher(
        strategy: 'ip2country',
        enabled: true,
    );

    $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
        name: 'page_view',
        params: ['__client_ip__' => '192.168.1.1'],
    );

    $result = $enricher->process($event);

    expect($result)->not->toBeNull();
    expect($result->params)->not->toHaveKey('geo_country');
});

test('geolocation enricher does nothing when disabled', function (): void {
    $enricher = new \ZeroBoiler\Analytics\Pipeline\GeolocationEnricher(
        strategy: 'header',
        enabled: false,
    );

    $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
        name: 'page_view',
        params: [
            '__client_ip__' => '1.2.3.4',
            '__headers__' => ['CF-IPCountry' => 'US'],
        ],
    );

    $result = $enricher->process($event);

    expect($result->params)->not->toHaveKey('geo_country');
});

test('geolocation enricher preserves existing params', function (): void {
    $enricher = new \ZeroBoiler\Analytics\Pipeline\GeolocationEnricher(
        strategy: 'header',
        countryHeader: 'X-Geo',
        enabled: true,
    );

    $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
        name: 'purchase',
        params: [
            '__client_ip__' => '1.2.3.4',
            '__headers__' => ['X-Geo' => 'DE'],
            'value' => 49.99,
            'currency' => 'EUR',
        ],
    );

    $enriched = $enricher->process($event);

    expect($enriched->params['geo_country'])->toBe('DE');
    expect($enriched->params['value'])->toBe(49.99);
    expect($enriched->params['currency'])->toBe('EUR');
    expect($enriched->name)->toBe('purchase');
});
