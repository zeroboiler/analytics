<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\AnalyticsInsight;
use ZeroBoiler\Analytics\DTO\FunnelVelocityReport;
use ZeroBoiler\Analytics\Events\Ecommerce\AbandonedCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\CheckoutAbandonEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsInsightsService;
use ZeroBoiler\Analytics\Services\EventImpactService;
use ZeroBoiler\Analytics\Services\FunnelVelocityService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

// ─── v2.82.0 — SaaS Product Analytics & Event Intelligence ───

// ─── Version Consistency ─────────────────────────────────────────────

test('AnalyticsEvent VERSION is 2.82.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('2.95.0');
});

test('AnalyticsEvent VERSION is a valid semver string', function (): void {
    $version = AnalyticsEvent::VERSION;
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($version)->not->toBeEmpty();
});

// ─── AnalyticsInsight DTO ───────────────────────────────────────────

test('AnalyticsInsight immutable DTO construction', function (): void {
    $insight = new AnalyticsInsight(
        type: 'trending',
        title: 'Event X is trending',
        description: 'Event X increased by 40% in the last 24 hours',
        score: 0.85,
        category: 'engagement',
        eventName: 'sign_up',
    );

    expect($insight->type)->toBe('trending');
    expect($insight->title)->toBe('Event X is trending');
    expect($insight->description)->toBe('Event X increased by 40% in the last 24 hours');
    expect($insight->score)->toBe(0.85);
    expect($insight->category)->toBe('engagement');
    expect($insight->eventName)->toBe('sign_up');
    expect($insight->metadata)->toBe([]);
    expect($insight->observedAt)->toBeNull();
    expect($insight->recommendation)->toBeNull();
});

test('AnalyticsInsight fromArray named constructor', function (): void {
    $data = [
        'type' => 'anomaly',
        'title' => 'Anomaly detected',
        'description' => 'Event Y is 3x above normal',
        'score' => 0.92,
        'category' => 'ecommerce',
        'event_name' => 'purchase',
        'metadata' => ['z_score' => 3.2],
    ];

    $insight = AnalyticsInsight::fromArray($data);

    expect($insight->type)->toBe('anomaly');
    expect($insight->title)->toBe('Anomaly detected');
    expect($insight->score)->toBe(0.92);
    expect($insight->eventName)->toBe('purchase');
    expect($insight->metadata)->toHaveKey('z_score');
    expect($insight->metadata['z_score'])->toBe(3.2);
});

test('AnalyticsInsight toArray round-trip', function (): void {
    $insight = new AnalyticsInsight(
        type: 'funnel_drop_off',
        title: 'Checkout bottleneck',
        description: '70% drop-off at payment step',
        score: 0.95,
    );

    $array = $insight->toArray();

    expect($array)->toHaveKeys(['type', 'title', 'description', 'score', 'category', 'event_name', 'metadata', 'observed_at', 'recommendation']);
    expect($array['type'])->toBe('funnel_drop_off');
    expect($array['score'])->toBe(0.95);
});

// ─── FunnelVelocityReport DTO ───────────────────────────────────────

test('FunnelVelocityReport construction with all fields', function (): void {
    $steps = [
        ['step' => 'view_item', 'count' => 100, 'drop_off_rate' => 0.0, 'avg_seconds' => 5.0, 'median_seconds' => 4.0, 'p75_seconds' => 8.0, 'p90_seconds' => 12.0, 'drop_off_count' => 0],
    ];
    $transitions = [
        ['from' => 'view_item', 'to' => 'add_to_cart', 'count' => 60, 'avg_seconds' => 120.0, 'median_seconds' => 90.0, 'conversion_rate' => 0.6],
    ];

    $report = new FunnelVelocityReport(
        funnelName: 'checkout',
        steps: $steps,
        transitions: $transitions,
        totalAvgSeconds: 600.0,
        totalMedianSeconds: 480.0,
        completedCount: 30,
        startedCount: 100,
        overallConversionRate: 0.3,
        bottleneckStep: 'add_to_cart',
        slowestTransition: 'view_item → add_to_cart',
    );

    expect($report->funnelName)->toBe('checkout');
    expect($report->completedCount)->toBe(30);
    expect($report->overallConversionRate)->toBe(0.3);
    expect($report->bottleneckStep)->toBe('add_to_cart');
    expect($report->slowestTransition)->toBe('view_item → add_to_cart');
});

test('FunnelVelocityReport optional fields default to null', function (): void {
    $report = new FunnelVelocityReport(
        funnelName: 'test',
        steps: [],
        transitions: [],
        totalAvgSeconds: 0.0,
        totalMedianSeconds: 0.0,
        completedCount: 0,
        startedCount: 0,
        overallConversionRate: 0.0,
    );

    expect($report->bottleneckStep)->toBeNull();
    expect($report->slowestTransition)->toBeNull();
});

// ─── AbandonedCartEvent ─────────────────────────────────────────────

test('AbandonedCartEvent creates correct event name and params', function (): void {
    $event = new AbandonedCartEvent(
        items: [
            ['item_id' => 'prod-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ['item_id' => 'prod-2', 'price' => 49.99],
        ],
        cartValue: 109.97,
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('abandoned_cart');
    expect($event->params)->toHaveKey('items');
    expect($event->params)->toHaveKey('cart_value');
    expect($event->params['cart_value'])->toBe(109.97);
    expect($event->params['cart_item_count'])->toBe(2);
});

test('AbandonedCartEvent with default values', function (): void {
    $event = new AbandonedCartEvent();

    expect($event->name)->toBe('abandoned_cart');
    expect($event->params['cart_value'])->toBe(0.0);
    expect($event->params['cart_item_count'])->toBe(0);
});

// ─── CheckoutAbandonEvent ───────────────────────────────────────────

test('CheckoutAbandonEvent creates correct event name and params', function (): void {
    $event = new CheckoutAbandonEvent(
        stepReached: 'add_payment_info',
        cartValue: 249.00,
        currency: 'USD',
        cartItemCount: 5,
        timeOnStep: 180,
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('checkout_abandon');
    expect($event->params['step_reached'])->toBe('add_payment_info');
    expect($event->params['cart_value'])->toBe(249.00);
    expect($event->params['currency'])->toBe('USD');
    expect($event->params['cart_item_count'])->toBe(5);
    expect($event->params['time_on_step'])->toBe(180);
});

test('CheckoutAbandonEvent with defaults', function (): void {
    $event = new CheckoutAbandonEvent();

    expect($event->name)->toBe('checkout_abandon');
    expect($event->params['step_reached'])->toBe('');
});

// ─── EventCatalog: conversionEvents & abandonedEvents ───────────────

test('EventCatalog conversionEvents returns non-empty array', function (): void {
    $events = EventCatalog::conversionEvents();

    expect($events)->not->toBeEmpty();
    // Should include key conversion events
    $names = array_column($events, 'name');
    expect($names)->toContain('sign_up');
    expect($names)->toContain('purchase');
});

test('EventCatalog abandonedEvents returns non-empty array', function (): void {
    $events = EventCatalog::abandonedEvents();

    expect($events)->not->toBeEmpty();
    $names = array_column($events, 'name');
    expect($names)->toContain('abandoned_cart');
    expect($names)->toContain('checkout_abandon');
});

// ─── EventCatalog: EcommerceEvents has new entries ─────────────────

test('EcommerceEvents catalog has abandoned_cart entry', function (): void {
    $entry = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::get('abandoned_cart');

    expect($entry)->not->toBeNull();
    expect($entry['name'])->toBe('abandoned_cart');
    expect($entry)->toHaveKey('ga4');
    expect($entry)->toHaveKey('meta');
});

test('EcommerceEvents catalog has checkout_abandon entry', function (): void {
    $entry = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::get('checkout_abandon');

    expect($entry)->not->toBeNull();
    expect($entry['name'])->toBe('checkout_abandon');
});

// ─── AnalyticsInsightsService ───────────────────────────────────────

test('AnalyticsInsightsService construction with config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.insights', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 300,
            'min_events_for_trend' => 10,
            'anomaly_threshold' => 3.0,
            'max_insights' => 20,
            'trend_window_hours' => 24,
        ]);

    $service = new AnalyticsInsightsService($config);

    expect($service->isEnabled())->toBeTrue();
});

test('AnalyticsInsightsService handles empty event data gracefully', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.insights', [])
        ->andReturn([]);

    $service = new AnalyticsInsightsService($config);
    $result = $service->generate();

    expect($result)->toBeEmpty();
});

test('AnalyticsInsightsService trending event detection', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.insights', [])
        ->andReturn([
            'enabled' => true,
            'min_events_for_trend' => 5,
            'anomaly_threshold' => 3.0,
            'max_insights' => 20,
            'trend_window_hours' => 24,
        ]);

    $service = new AnalyticsInsightsService($config);

    // Create event data with a clear trend — flat key-value format
    $current = ['page_view' => 500, 'purchase' => 50, 'sign_up' => 200];
    $previous = ['page_view' => 300, 'purchase' => 45, 'sign_up' => 80]; // sign_up: 150% increase

    $result = $service->detectTrendingEvents($current, $previous);

    expect($result)->not->toBeEmpty();
    // sign_up should be flagged as trending (150% increase)
    $titles = array_map(fn (AnalyticsInsight $i): string => $i->title, $result);
    $hasSignUpTrend = count(array_filter($titles, fn (string $t): bool => str_contains($t, 'sign_up'))) > 0;
    expect($hasSignUpTrend)->toBeTrue();
});

test('AnalyticsInsightsService anomaly detection', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.insights', [])
        ->andReturn([
            'enabled' => true,
            'min_events_for_trend' => 5,
            'anomaly_threshold' => 2.0,
            'max_insights' => 20,
        ]);

    $service = new AnalyticsInsightsService($config);

    // Need at least 5 data points total for anomaly detection
    $current = ['page_view' => 500, 'purchase' => 400, 'sign_up' => 200, 'login' => 350, 'click' => 450];
    $previous = ['page_view' => 100, 'purchase' => 100, 'sign_up' => 80, 'login' => 120, 'click' => 90];

    $result = $service->detectAnomalies($current, $previous);

    expect($result)->not->toBeEmpty();
    // purchase should be flagged as anomalous (400 vs baseline ~100)
    $titles = array_map(fn (AnalyticsInsight $i): string => $i->title, $result);
    $hasPurchaseAnomaly = count(array_filter($titles, fn (string $t): bool => str_contains($t, 'purchase'))) > 0;
    expect($hasPurchaseAnomaly)->toBeTrue();
});

// ─── FunnelVelocityService ─────────────────────────────────────────

test('FunnelVelocityService construction with config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn([
            'enabled' => true,
            'percentile_window' => 100,
        ]);

    $service = new FunnelVelocityService($config);

    expect($service->availableFunnels())->toContain('checkout');
    expect($service->availableFunnels())->toContain('signup');
    expect($service->availableFunnels())->toContain('trial');
    expect($service->availableFunnels())->toContain('activation');
});

test('FunnelVelocityService analyze checkout funnel', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn(['enabled' => true, 'percentile_window' => 100]);

    $service = new FunnelVelocityService($config);

    $steps = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];
    $now = microtime(true);

    $userJourneys = [
        // User 1: completes full funnel
        [
            'user_id' => 'u1',
            'steps' => [
                ['step' => 'view_item', 'timestamp' => $now - 600],
                ['step' => 'add_to_cart', 'timestamp' => $now - 480],
                ['step' => 'begin_checkout', 'timestamp' => $now - 360],
                ['step' => 'purchase', 'timestamp' => $now - 300],
            ],
        ],
        // User 2: drops at add_to_cart
        [
            'user_id' => 'u2',
            'steps' => [
                ['step' => 'view_item', 'timestamp' => $now - 500],
                ['step' => 'add_to_cart', 'timestamp' => $now - 400],
            ],
        ],
        // User 3: completes full funnel fast
        [
            'user_id' => 'u3',
            'steps' => [
                ['step' => 'view_item', 'timestamp' => $now - 200],
                ['step' => 'add_to_cart', 'timestamp' => $now - 190],
                ['step' => 'begin_checkout', 'timestamp' => $now - 180],
                ['step' => 'purchase', 'timestamp' => $now - 170],
            ],
        ],
    ];

    $report = $service->analyze('checkout', $steps, $userJourneys);

    expect($report)->toBeInstanceOf(FunnelVelocityReport::class);
    expect($report->funnelName)->toBe('checkout');
    expect($report->startedCount)->toBe(3);
    expect($report->completedCount)->toBe(2);
    expect($report->overallConversionRate)->toBe(2 / 3);
    expect(count($report->steps))->toBe(4);
    expect(count($report->transitions))->toBe(3);
});

test('FunnelVelocityService identify bottleneck step', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn(['enabled' => true, 'percentile_window' => 100]);

    $service = new FunnelVelocityService($config);

    $steps = ['page_view', 'form_start', 'form_submit', 'sign_up'];
    $now = microtime(true);

    $userJourneys = [
        // 3 users drop at page_view (highest drop-off)
        ['user_id' => 'u1', 'steps' => [['step' => 'page_view', 'timestamp' => $now - 100]]],
        ['user_id' => 'u2', 'steps' => [['step' => 'page_view', 'timestamp' => $now - 90]]],
        ['user_id' => 'u3', 'steps' => [['step' => 'page_view', 'timestamp' => $now - 80]]],
        // 1 user drops at form_start
        ['user_id' => 'u4', 'steps' => [
            ['step' => 'page_view', 'timestamp' => $now - 70],
            ['step' => 'form_start', 'timestamp' => $now - 60],
        ]],
        // 1 user completes
        ['user_id' => 'u5', 'steps' => [
            ['step' => 'page_view', 'timestamp' => $now - 50],
            ['step' => 'form_start', 'timestamp' => $now - 40],
            ['step' => 'form_submit', 'timestamp' => $now - 30],
            ['step' => 'sign_up', 'timestamp' => $now - 20],
        ]],
    ];

    $report = $service->analyze('signup', $steps, $userJourneys);

    // page_view: 5 reached, 1 proceeded → 80% drop-off (highest)
    expect($report->bottleneckStep)->toBe('page_view');
});

test('FunnelVelocityService comparison', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn(['enabled' => true, 'percentile_window' => 100]);

    $service = new FunnelVelocityService($config);

    $now = microtime(true);

    $reportA = $service->analyze('funnel_a', ['step1', 'step2'], [
        ['user_id' => 'u1', 'steps' => [
            ['step' => 'step1', 'timestamp' => $now - 200],
            ['step' => 'step2', 'timestamp' => $now - 100],
        ]],
    ]);

    $reportB = $service->analyze('funnel_b', ['step1', 'step2'], [
        ['user_id' => 'u1', 'steps' => [
            ['step' => 'step1', 'timestamp' => $now - 400],
            ['step' => 'step2', 'timestamp' => $now - 50],
        ]],
    ]);

    $comparison = $service->compare($reportA, $reportB);

    expect($comparison)->toHaveKey('comparison');
    expect($comparison)->toHaveKey('total_avg_diff');
    expect($comparison)->toHaveKey('total_median_diff');
});

test('FunnelVelocityService empty data returns empty report', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn(['enabled' => true]);

    $service = new FunnelVelocityService($config);

    $report = $service->analyze('test', [], []);

    expect($report->funnelName)->toBe('test');
    expect($report->steps)->toBeEmpty();
    expect($report->startedCount)->toBe(0);
    expect($report->completedCount)->toBe(0);
});

test('FunnelVelocityService analyzeBuiltin unknown funnel', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_velocity', [])
        ->andReturn(['enabled' => true]);

    $service = new FunnelVelocityService($config);

    $report = $service->analyzeBuiltin('nonexistent', []);

    expect($report->funnelName)->toBe('nonexistent');
    expect($report->steps)->toBeEmpty();
});

// ─── EventImpactService ───────────────────────────────────────────

test('EventImpactService construction with config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_impact', [])
        ->andReturn([
            'enabled' => true,
            'min_sample_size' => 30,
            'conversion_events' => ['subscribe', 'purchase'],
            'retention_events' => ['feature_used', 'login'],
        ]);

    $service = new EventImpactService($config);

    expect($service->isEnabled())->toBeTrue();
});

test('EventImpactService insufficient sample size', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_impact', [])
        ->andReturn([
            'enabled' => true,
            'min_sample_size' => 30,
        ]);

    $service = new EventImpactService($config);

    $result = $service->calculateImpacts([
        ['user_id' => 'u1', 'events' => ['login'], 'converted' => true, 'retained' => true, 'revenue' => 99],
    ]);

    expect($result['scores'])->toBeEmpty();
    expect($result['summary']['users_analyzed'])->toBe(1);
    expect($result['summary']['events_evaluated'])->toBe(0);
});

test('EventImpactService calculates impact scores', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_impact', [])
        ->andReturn([
            'enabled' => true,
            'min_sample_size' => 5,
            'conversion_events' => ['subscribe'],
            'retention_events' => ['feature_used'],
        ]);

    $service = new EventImpactService($config);

    // Create behavioral data: users who use features convert more
    $behaviors = [];
    for ($i = 0; $i < 20; $i++) {
        $hasFeature = $i < 15; // 15 users use features
        $converted = $hasFeature && $i < 12; // 12 of 15 feature users convert
        $behaviors[] = [
            'user_id' => "u{$i}",
            'events' => $hasFeature ? ['feature_used', 'login'] : ['login'],
            'converted' => $converted,
            'retained' => $hasFeature,
            'revenue' => $converted ? 99.0 : 0.0,
        ];
    }

    $result = $service->calculateImpacts($behaviors);

    expect($result['scores'])->not->toBeEmpty();
    expect($result['summary']['users_analyzed'])->toBe(20);
    expect($result['summary']['events_evaluated'])->toBeGreaterThanOrEqual(2);

    // feature_used should have higher impact than just login
    $featureScore = null;
    $loginScore = null;
    foreach ($result['scores'] as $score) {
        if ($score['event_name'] === 'feature_used') {
            $featureScore = $score['impact_score'];
        }
        if ($score['event_name'] === 'login') {
            $loginScore = $score['impact_score'];
        }
    }

    expect($featureScore)->not->toBeNull();
    expect($loginScore)->not->toBeNull();
    // feature_used should have higher conversion correlation
    expect($featureScore)->toBeGreaterThan($loginScore);
});

test('EventImpactService conversionDrivers returns positive impact events', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_impact', [])
        ->andReturn([
            'enabled' => true,
            'min_sample_size' => 5,
            'conversion_events' => ['subscribe'],
            'retention_events' => ['feature_used'],
        ]);

    $service = new EventImpactService($config);

    $behaviors = [];
    for ($i = 0; $i < 15; $i++) {
        $behaviors[] = [
            'user_id' => "u{$i}",
            'events' => ['login', $i < 10 ? 'subscribe' : 'page_view'],
            'converted' => $i < 10,
            'retained' => true,
            'revenue' => $i < 10 ? 49.0 : 0.0,
        ];
    }

    $drivers = $service->conversionDrivers($behaviors);

    expect($drivers)->not->toBeEmpty();
    // All should have positive impact scores
    foreach ($drivers as $driver) {
        expect($driver['correlation'])->toBeGreaterThan(0);
    }
});

test('EventImpactService disabled returns empty results', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_impact', [])
        ->andReturn([
            'enabled' => false,
            'min_sample_size' => 5,
        ]);

    $service = new EventImpactService($config);

    expect($service->isEnabled())->toBeFalse();

    $behaviors = array_fill(0, 50, [
        'user_id' => 'u1',
        'events' => ['login'],
        'converted' => true,
        'retained' => true,
        'revenue' => 99,
    ]);

    $result = $service->calculateImpacts($behaviors);

    expect($result['scores'])->toBeEmpty();
});

// ─── Cross-cutting Quality Checks ─────────────────────────────────

test('all new service classes are final', function (): void {
    $reflection = new ReflectionClass(AnalyticsInsightsService::class);
    expect($reflection->isFinal())->toBeTrue();

    $reflection = new ReflectionClass(FunnelVelocityService::class);
    expect($reflection->isFinal())->toBeTrue();

    $reflection = new ReflectionClass(EventImpactService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('all new service classes have strict types declaration', function (): void {
    $classes = [
        AnalyticsInsightsService::class,
        FunnelVelocityService::class,
        EventImpactService::class,
    ];

    foreach ($classes as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $contents = file_get_contents((string) $file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all new service classes have MIT license header', function (): void {
    $classes = [
        AnalyticsInsightsService::class,
        FunnelVelocityService::class,
        EventImpactService::class,
    ];

    foreach ($classes as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $contents = file_get_contents((string) $file);
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    }
});

test('EventCatalog count includes new abandoned events', function (): void {
    $total = EventCatalog::count();

    // At minimum, catalog should have 90+ events
    expect($total)->toBeGreaterThanOrEqual(90);
});
