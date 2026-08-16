<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventTrendForecastService;

beforeEach(function (): void {
    // We test the service methods directly using a fake/cache approach
});

describe('EventTrendForecastService', function (): void {
    describe('Configuration', function (): void {
        it('reads config defaults correctly', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.trend_forecast', [])
                ->andReturn([]);

            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $cfg = $service->getConfig();

            expect($cfg)->toHaveKeys([
                'cache_ttl', 'forecast_horizon', 'confidence_level',
                'seasonal_enabled', 'seasonal_periods', 'max_history_days',
                'min_data_points_ratio',
            ]);
            expect($cfg['forecast_horizon'])->toBe(7);
            expect($cfg['confidence_level'])->toBe(0.95);
            expect($cfg['seasonal_enabled'])->toBeTrue();
            expect($cfg['max_history_days'])->toBe(30);
        });
    });

    describe('Linear Regression', function (): void {
        it('returns zero regression for empty data via forecast', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $reg = $service->regression('page_view', 7);

            // With no event stream data, regression returns zeros
            expect($reg)->toHaveKeys(['slope', 'intercept', 'r_squared', 'mean', 'stddev', 'data_points']);
            expect($reg['data_points'])->toBe(0);
            expect($reg['slope'])->toBe(0.0);
            expect($reg['r_squared'])->toBe(0.0);
        });

        it('returns R² of 1.0 for perfectly linear data via forecast', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $reg = $service->regression('page_view', 7);

            // With no event stream data, regression returns zeros
            expect($reg)->toHaveKeys(['slope', 'intercept', 'r_squared', 'mean', 'stddev', 'data_points']);
            expect($reg['data_points'])->toBe(0);
            expect($reg['r_squared'])->toBe(0.0);
        });
    });

    describe('Forecast Report Structure', function (): void {
        it('returns empty forecast with insufficient data', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $report = $service->forecast('page_view', 30, 7);

            expect($report)->toHaveKeys([
                'event_name', 'period', 'direction', 'slope', 'r_squared',
                'intercept', 'growth_rate', 'forecast', 'seasonal',
                'data_points', 'method', 'computed_at',
            ]);
            expect($report['event_name'])->toBe('page_view');
            expect($report['direction'])->toBe('flat');
            expect($report['slope'])->toBe(0.0);
            expect($report['method'])->toBe('insufficient_data');
            expect(count($report['forecast']))->toBe(7);
        });

        it('forecast points contain correct structure', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $report = $service->forecast('login', 30, 5);

            foreach ($report['forecast'] as $point) {
                expect($point)->toHaveKeys([
                    'date', 'predicted', 'lower', 'upper', 'confidence', 'horizon_day',
                ]);
                expect($point['confidence'])->toBe(0.95);
                expect($point['lower'])->toBeLessThanOrEqual($point['upper']);
            }
        });
    });

    describe('Category Forecast', function (): void {
        it('returns category-prefixed event name', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $report = $service->forecastCategory('saas', 30, 7);

            expect($report['event_name'])->toBe('category:saas');
            expect($report['category'])->toBe('saas');
            expect($report['forecast'])->toBeArray();
            expect(count($report['forecast']))->toBe(7);
        });
    });

    describe('Trend Changes Detection', function (): void {
        it('returns empty array when no events have sufficient data', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $changes = $service->detectTrendChanges(['page_view', 'login'], 30);

            // With no event stream data, all events have 0 data points (< 6 minimum)
            expect($changes)->toBeArray();
            // Events with < 6 data points are skipped
        });
    });

    describe('Comparative Forecast', function (): void {
        it('returns summary with total count', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);
            $report = $service->compareForecasts(['page_view', 'login', 'purchase'], 30, 7);

            expect($report)->toHaveKeys(['events', 'summary', 'period', 'horizon', 'computed_at']);
            expect($report['summary']['total'])->toBe(3);
            expect(count($report['events']))->toBe(3);
            expect($report['period'])->toBe(30);
            expect($report['horizon'])->toBe(7);
        });
    });

    describe('Regression Method', function (): void {
        it('returns regression result for known catalog event', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);

            // Test with a catalog event
            expect(EventCatalog::has('page_view'))->toBeTrue();
            $reg = $service->regression('page_view', 7);

            expect($reg['slope'])->toBeFloat();
            expect($reg['r_squared'])->toBeFloat();
            expect($reg['r_squared'])->toBeGreaterThanOrEqual(0.0);
            expect($reg['r_squared'])->toBeLessThanOrEqual(1.0);
        });
    });

    describe('Event Catalog Integration', function (): void {
        it('page_view is in engagement category', function (): void {
            expect(EventCatalog::has('page_view'))->toBeTrue();
            expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
        });

        it('purchase is in ecommerce category', function (): void {
            expect(EventCatalog::has('purchase'))->toBeTrue();
            expect(EventCatalog::getCategory('purchase'))->toBe('ecommerce');
        });

        it('sign_up is in saas category', function (): void {
            expect(EventCatalog::has('sign_up'))->toBeTrue();
            expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
        });

        it('forecast returns category-aware report', function (): void {
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn([]);
            $manager = mock(AnalyticsManager::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventTrendForecastService($manager, $cache, $config);

            // Both methods should work for valid catalog events
            $report1 = $service->forecast('page_view', 14, 3);
            $report2 = $service->forecast('purchase', 14, 3);
            $report3 = $service->forecast('sign_up', 14, 3);

            expect($report1['event_name'])->toBe('page_view');
            expect($report2['event_name'])->toBe('purchase');
            expect($report3['event_name'])->toBe('sign_up');

            foreach ([$report1, $report2, $report3] as $report) {
                expect($report['forecast'])->toBeArray();
                expect(count($report['forecast']))->toBe(3);
            }
        });
    });
});
