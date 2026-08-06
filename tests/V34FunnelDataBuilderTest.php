<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\FunnelDataBuilderService;

beforeEach(function (): void {
    $this->manager = Mockery::mock(AnalyticsManager::class);
    $this->metrics = Mockery::mock(AnalyticsMetrics::class);
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnels', [])
        ->andReturn([
            'cache_enabled' => false,
            'cache_ttl' => 300,
        ]);

    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'funnel_started' => 1000,
            'funnel_completed' => 400,
            'funnel_step_landing_view' => 800,
            'funnel_step_form_start' => 600,
            'funnel_step_form_submit' => 500,
            'funnel_step_confirmation' => 400,
        ]);
});

afterEach(function (): void {
    Mockery::close();
});

test('funnel data builder can be instantiated', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    expect($service)->toBeInstanceOf(FunnelDataBuilderService::class);
});

test('build returns funnel data with correct structure', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $data = $service->build('signup');

    expect($data)->toHaveKey('funnel');
    expect($data)->toHaveKey('steps');
    expect($data)->toHaveKey('total_entries');
    expect($data)->toHaveKey('total_conversions');
    expect($data)->toHaveKey('overall_conversion');
    expect($data)->toHaveKey('built_at');
    expect($data['funnel'])->toBe('signup');
    expect($data['total_entries'])->toBe(1000);
    expect($data['total_conversions'])->toBe(400);
});

test('build calculates overall conversion rate', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $data = $service->build('signup');

    // 400 / 1000 = 40%
    expect($data['overall_conversion'])->toBe(40.0);
});

test('build with custom steps uses provided step definitions', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $steps = [
        ['name' => 'step_a', 'order' => 1],
        ['name' => 'step_b', 'order' => 2],
        ['name' => 'step_c', 'order' => 3],
    ];

    $data = $service->build('custom_funnel', $steps);

    expect($data['steps'])->toHaveCount(3);
    expect($data['steps'][0]['name'])->toBe('step_a');
});

test('build identifies drop-off bottleneck', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $data = $service->build('signup');

    expect($data['drop_off_bottleneck'])->not->toBeNull();
});

test('compare returns side-by-side funnel data', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    // Build two funnels first
    $service->build('signup');
    $service->build('purchase');

    $comparison = $service->compare(['signup', 'purchase']);

    expect($comparison)->toHaveKey('funnels');
    expect($comparison)->toHaveKey('best_performer');
    expect($comparison)->toHaveKey('built_at');
    expect($comparison['funnels'])->toHaveKey('signup');
    expect($comparison['funnels'])->toHaveKey('purchase');
});

test('compare identifies best performing funnel', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $service->build('signup');
    $service->build('purchase');

    $comparison = $service->compare(['signup', 'purchase']);

    expect($comparison['best_performer'])->not->toBeNull();
});

test('buildTimeSeries returns period data', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $ts = $service->buildTimeSeries('signup', 3, 86400);

    expect($ts)->toHaveKey('funnel');
    expect($ts)->toHaveKey('periods');
    expect($ts['funnel'])->toBe('signup');
    expect($ts['periods'])->toHaveCount(3);

    foreach ($ts['periods'] as $period) {
        expect($period)->toHaveKey('period');
        expect($period)->toHaveKey('start');
        expect($period)->toHaveKey('end');
        expect($period)->toHaveKey('entries');
        expect($period)->toHaveKey('conversions');
        expect($period)->toHaveKey('conversion_rate');
    }
});

test('buildDropOffAnalysis returns bottleneck info', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    // Build the funnel first
    $service->build('signup');

    $analysis = $service->buildDropOffAnalysis('signup');

    expect($analysis)->toHaveKey('funnel');
    expect($analysis)->toHaveKey('bottleneck');
    expect($analysis)->toHaveKey('steps');
    expect($analysis['funnel'])->toBe('signup');
});

test('buildChartData returns chart-ready format', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    // Build the funnel first
    $service->build('signup');

    $chart = $service->buildChartData('signup');

    expect($chart)->toHaveKey('labels');
    expect($chart)->toHaveKey('values');
    expect($chart)->toHaveKey('conversion_rates');
    expect($chart)->toHaveKey('chart_data');
    expect($chart['labels'])->toHaveCount(4);
    expect($chart['chart_data'][0])->toHaveKey('label');
    expect($chart['chart_data'][0])->toHaveKey('value');
    expect($chart['chart_data'][0])->toHaveKey('rate');
});

test('invalidateCache removes built funnel data', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $service->build('signup');
    expect($service->getAllFunnelData())->toHaveKey('signup');

    $this->cache->shouldReceive('forget')
        ->with('zeroboiler.analytics.funnel_data.signup')
        ->andReturn(true);

    $service->invalidateCache('signup');
    expect($service->getAllFunnelData())->not->toHaveKey('signup');
});

test('invalidateAllCaches clears all built funnel data', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $service->build('signup');
    $service->build('purchase');

    $this->cache->shouldReceive('forget')
        ->with('zeroboiler.analytics.funnel_data.all')
        ->andReturn(true);

    $service->invalidateAllCaches();
    expect($service->getAllFunnelData())->toBeEmpty();
});

test('summary returns correct structure', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $summary = $service->summary();

    expect($summary)->toHaveKey('built_funnels');
    expect($summary)->toHaveKey('funnel_names');
    expect($summary)->toHaveKey('cache_enabled');
    expect($summary)->toHaveKey('cache_ttl');
    expect($summary['built_funnels'])->toBe(0);
    expect($summary['cache_enabled'])->toBeFalse();
});

test('steps have rate, drop_off, and cumulative_rate fields', function (): void {
    $service = new FunnelDataBuilderService(
        $this->manager,
        $this->metrics,
        $this->cache,
        $this->config,
    );

    $data = $service->build('signup');

    foreach ($data['steps'] as $step) {
        expect($step)->toHaveKey('name');
        expect($step)->toHaveKey('count');
        expect($step)->toHaveKey('rate');
        expect($step)->toHaveKey('drop_off');
        expect($step)->toHaveKey('avg_time_ms');
        expect($step)->toHaveKey('cumulative_rate');
    }
});

test('build with empty metrics uses generic fallback steps', function (): void {
    $emptyMetrics = Mockery::mock(AnalyticsMetrics::class);
    $emptyMetrics->shouldReceive('getCounts')
        ->andReturn([]);

    $service = new FunnelDataBuilderService(
        $this->manager,
        $emptyMetrics,
        $this->cache,
        $this->config,
    );

    $data = $service->build('empty_funnel');

    expect($data['steps'])->toHaveCount(4); // Generic fallback steps
    expect($data['total_entries'])->toBe(0);
    expect($data['overall_conversion'])->toBe(0.0);
});
