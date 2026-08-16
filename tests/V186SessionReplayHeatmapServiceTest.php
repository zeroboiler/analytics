<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\Services\SessionReplayHeatmapService;

test('SessionReplayHeatmapService constructs with defaults', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    expect($service->isEnabled())->toBeTrue();
    expect($service->getWeights())->toBe([
        'click' => 1.0,
        'hover' => 0.3,
        'scroll' => 0.5,
        'time' => 0.1,
    ]);
});

test('SessionReplayHeatmapService records click interactions', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordClick('/pricing', 'cta-buy');
    $service->recordClick('/pricing', 'cta-buy');
    $service->recordClick('/pricing', 'nav-logo');

    $heatmap = $service->getPageHeatmap('/pricing');

    expect($heatmap['page'])->toBe('/pricing');
    expect($heatmap['zones'])->toHaveCount(2);
    expect($heatmap['total_interactions'])->toBeGreaterThan(0);

    // CTA should have higher heat score (more clicks)
    $ctaHeat = null;
    $navHeat = null;
    foreach ($heatmap['zones'] as $zone) {
        if ($zone['zone_id'] === 'cta-buy') {
            $ctaHeat = $zone['heat_score'];
        }
        if ($zone['zone_id'] === 'nav-logo') {
            $navHeat = $zone['heat_score'];
        }
    }

    expect($ctaHeat)->not->toBeNull();
    expect($navHeat)->not->toBeNull();
    expect($ctaHeat)->toBeGreaterThan($navHeat);
});

test('SessionReplayHeatmapService records hover and scroll', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordHover('/home', 'hero-section', 500);
    $service->recordScrollReach('/home', 'viewport-0-25');
    $service->recordScrollReach('/home', 'viewport-0-25');
    $service->recordScrollReach('/home', 'viewport-75-100');

    $heatmap = $service->getPageHeatmap('/home');

    expect($heatmap['zones'])->toHaveCount(3);
    expect($heatmap['hottest_zone'])->not->toBeNull();
    expect($heatmap['coldest_zone'])->not->toBeNull();
});

test('SessionReplayHeatmapService getSummary aggregates across pages', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordClick('/home', 'hero');
    $service->recordClick('/pricing', 'cta');

    $summary = $service->getSummary();

    expect($summary['pages'])->toBe(2);
    expect($summary['total_zones'])->toBe(2);
    expect($summary['recommendations'])->toBeArray();
    expect($summary['computed_at'])->toBeString();
});

test('SessionReplayHeatmapService quickSummary returns status', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    // Empty state
    $summary = $service->quickSummary();
    expect($summary['tracked_pages'])->toBe(0);
    expect($summary['status'])->toBe('inactive');

    // With data
    $service->recordClick('/home', 'hero');

    $summary = $service->quickSummary();
    expect($summary['tracked_pages'])->toBe(1);
    expect($summary['status'])->toBe('active_hot');
});

test('SessionReplayHeatmapService clear removes all data', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordClick('/home', 'hero');
    $service->clear();

    $summary = $service->quickSummary();
    expect($summary['tracked_pages'])->toBe(0);
});

test('SessionReplayHeatmapService disabled state ignores all records', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn(['enabled' => false]);

    $service = new SessionReplayHeatmapService($config);

    expect($service->isEnabled())->toBeFalse();

    $service->recordClick('/home', 'hero');
    $heatmap = $service->getPageHeatmap('/home');

    expect($heatmap['zones'])->toBeEmpty();
    expect($heatmap['total_interactions'])->toBe(0);
});

test('SessionReplayHeatmapService dwell time tracking', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordDwellTime('/article', 'content-section', 5000);
    $service->recordDwellTime('/article', 'content-section', 3000);

    $heatmap = $service->getPageHeatmap('/article');

    $contentZone = null;
    foreach ($heatmap['zones'] as $zone) {
        if ($zone['zone_id'] === 'content-section') {
            $contentZone = $zone;
            break;
        }
    }

    expect($contentZone)->not->toBeNull();
    expect($contentZone['time_ms'])->toBe(8000);
});

test('SessionReplayHeatmapService impression tracking', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.heatmap', [])
        ->andReturn([]);

    $service = new SessionReplayHeatmapService($config);

    $service->recordImpression('/home', 'visitor-1');
    $service->recordImpression('/home', 'visitor-2');
    $service->recordImpression('/pricing', 'visitor-3');

    $homeHeatmap = $service->getPageHeatmap('/home');
    expect($homeHeatmap['unique_visitors'])->toBe(2);

    $pricingHeatmap = $service->getPageHeatmap('/pricing');
    expect($pricingHeatmap['unique_visitors'])->toBe(1);
});
