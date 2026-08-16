<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsRollupCommand;
use ZeroBoiler\Analytics\Services\AnalyticsRollupService;

beforeEach(function (): void {
    Cache::clear();
});

describe('AnalyticsRollupService', function (): void {
    describe('Construction', function (): void {
        it('constructs with default config', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([]);

            $service = new AnalyticsRollupService($cache, $config);

            expect($service->isEnabled())->toBeTrue();
            expect($service->getGranularities())->toBe(['hourly', 'daily', 'weekly']);
        });

        it('constructs with custom config', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => false,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'custom_',
                    'hourly_ttl' => 3600,
                    'daily_ttl' => 172800,
                    'weekly_ttl' => 1209600,
                    'max_top_events' => 10,
                    'max_unique_trackers' => 5000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            expect($service->isEnabled())->toBeFalse();
            expect($service->getGranularities())->toBe(['daily']);
        });

        it('returns summary with config values', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['hourly', 'daily'],
                    'cache_prefix' => 'zb_test_',
                    'hourly_ttl' => 1800,
                    'daily_ttl' => 432000,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 15,
                    'max_unique_trackers' => 2000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);
            $summary = $service->summary();

            expect($summary)->toHaveKeys([
                'enabled', 'granularities', 'hourly_ttl', 'daily_ttl',
                'weekly_ttl', 'max_top_events', 'max_unique_trackers', 'cache_prefix',
            ]);
            expect($summary['enabled'])->toBeTrue();
            expect($summary['granularities'])->toBe(['hourly', 'daily']);
            expect($summary['cache_prefix'])->toBe('zb_test_');
            expect($summary['max_top_events'])->toBe(15);
        });
    });

    describe('Disabled mode', function (): void {
        it('does nothing when disabled', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn(['enabled' => false]);

            $service = new AnalyticsRollupService($cache, $config);

            // Should not call any cache methods
            $cache->shouldNotReceive('increment');

            $service->record('page_view', 'engagement', 'ga4', 'user_1', 'client_1');
        });
    });

    describe('Enabled mode', function (): void {
        it('records event counts for all active granularities', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['hourly', 'daily'],
                    'cache_prefix' => 'zb_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $service->record('page_view', 'engagement', 'ga4', 'user_1', 'client_1');
            $service->record('page_view', 'engagement', 'ga4', 'user_2', 'client_1');
            $service->record('purchase', 'ecommerce', 'meta', 'user_1', 'client_2');

            // Verify totals were incremented
            $hourlyPrefix = 'zb_test_hourly:' . now()->format('Y-m-d\TH');
            $dailyPrefix = 'zb_test_daily:' . now()->format('Y-m-d');

            // Total should be 3 for both granularities
            expect((int) Cache::get($hourlyPrefix . ':total'))->toBe(3);
            expect((int) Cache::get($dailyPrefix . ':total'))->toBe(3);

            // page_view should be 2
            expect((int) Cache::get($hourlyPrefix . ':events:page_view'))->toBe(2);
            expect((int) Cache::get($dailyPrefix . ':events:page_view'))->toBe(2);

            // purchase should be 1
            expect((int) Cache::get($hourlyPrefix . ':events:purchase'))->toBe(1);

            // engagement category should be 2
            expect((int) Cache::get($hourlyPrefix . ':categories:engagement'))->toBe(2);

            // ecommerce category should be 1
            expect((int) Cache::get($hourlyPrefix . ':categories:ecommerce'))->toBe(1);

            // ga4 provider should be 2
            expect((int) Cache::get($hourlyPrefix . ':providers:ga4'))->toBe(2);

            // meta provider should be 1
            expect((int) Cache::get($hourlyPrefix . ':providers:meta'))->toBe(1);
        });

        it('handles null category and provider gracefully', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_null_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $service->record('custom_event', null, null, null, null);

            $dailyPrefix = 'zb_null_test_daily:' . now()->format('Y-m-d');
            expect((int) Cache::get($dailyPrefix . ':total'))->toBe(1);
            expect((int) Cache::get($dailyPrefix . ':events:custom_event'))->toBe(1);
        });
    });

    describe('Unique tracking', function (): void {
        it('tracks unique users with bounded set', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_unique_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 100,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            // Same user recorded twice — should only count once
            $service->record('page_view', null, null, 'user_1', null);
            $service->record('page_view', null, null, 'user_1', null);

            $dailyPrefix = 'zb_unique_test_daily:' . now()->format('Y-m-d');

            // Total should be 2 but unique users should be 1
            expect((int) Cache::get($dailyPrefix . ':total'))->toBe(2);
            expect((int) Cache::get($dailyPrefix . ':users:_count'))->toBe(1);
        });

        it('tracks multiple unique users', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_multi_unique_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $service->record('page_view', null, null, 'user_1', null);
            $service->record('page_view', null, null, 'user_2', null);
            $service->record('page_view', null, null, 'user_3', null);

            $dailyPrefix = 'zb_multi_unique_daily:' . now()->format('Y-m-d');
            expect((int) Cache::get($dailyPrefix . ':users:_count'))->toBe(3);
        });
    });

    describe('Query', function (): void {
        it('queries rollup data for a specific period', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_query_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            // Seed data
            $dailyPrefix = 'zb_query_test_daily:' . now()->format('Y-m-d');
            Cache::put($dailyPrefix . ':total', 10, 3600);
            Cache::put($dailyPrefix . ':events:page_view', 6, 3600);
            Cache::put($dailyPrefix . ':events:purchase', 4, 3600);
            Cache::put($dailyPrefix . ':categories:engagement', 6, 3600);
            Cache::put($dailyPrefix . ':categories:ecommerce', 4, 3600);
            Cache::put($dailyPrefix . ':providers:ga4', 7, 3600);
            Cache::put($dailyPrefix . ':providers:meta', 3, 3600);
            Cache::put($dailyPrefix . ':users:_count', 5, 3600);
            Cache::put($dailyPrefix . ':clients:_count', 8, 3600);

            $data = $service->query('daily');

            expect($data['total'])->toBe(10);
            expect($data['unique_users'])->toBe(5);
            expect($data['unique_clients'])->toBe(8);
            expect($data['categories'])->toHaveKeys(['engagement', 'ecommerce']);
            expect($data['providers'])->toHaveKeys(['ga4', 'meta']);
            expect($data['category_distribution']['engagement'])->toBe(60.0);
            expect($data['category_distribution']['ecommerce'])->toBe(40.0);
            expect($data['top_events'])->toHaveCount(2);
            expect($data['top_events'][0]['name'])->toBe('purchase'); // sorted by count desc
            expect($data['top_events'][0]['count'])->toBe(4);
        });

        it('queries with period override', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_override_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            // Seed data for a specific date
            Cache::put('zb_override_daily:2026-08-01:total', 42, 3600);
            Cache::put('zb_override_daily:2026-08-01:users:_count', 10, 3600);
            Cache::put('zb_override_daily:2026-08-01:clients:_count', 15, 3600);

            $data = $service->query('daily', '2026-08-01');

            expect($data['period'])->toBe('2026-08-01');
            expect($data['total'])->toBe(42);
            expect($data['unique_users'])->toBe(10);
        });
    });

    describe('Query Summary', function (): void {
        it('returns lightweight summary', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_summary_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $dailyPrefix = 'zb_summary_test_daily:' . now()->format('Y-m-d');
            Cache::put($dailyPrefix . ':total', 100, 3600);
            Cache::put($dailyPrefix . ':users:_count', 25, 3600);
            Cache::put($dailyPrefix . ':clients:_count', 40, 3600);

            $summary = $service->querySummary('daily');

            expect($summary)->toHaveKeys(['period', 'granularity', 'total', 'unique_users', 'unique_clients']);
            expect($summary['total'])->toBe(100);
            expect($summary['unique_users'])->toBe(25);
            expect($summary['unique_clients'])->toBe(40);
        });
    });

    describe('Trend', function (): void {
        it('computes period-over-period trend', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_trend_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            // Current period
            $currentPrefix = 'zb_trend_test_daily:' . now()->format('Y-m-d');
            Cache::put($currentPrefix . ':total', 150, 3600);
            Cache::put($currentPrefix . ':users:_count', 30, 3600);
            Cache::put($currentPrefix . ':clients:_count', 50, 3600);

            // Previous period (yesterday)
            $yesterday = now()->subDay()->format('Y-m-d');
            $prevPrefix = 'zb_trend_test_daily:' . $yesterday;
            Cache::put($prevPrefix . ':total', 100, 3600);
            Cache::put($prevPrefix . ':users:_count', 20, 3600);
            Cache::put($prevPrefix . ':clients:_count', 40, 3600);

            $trend = $service->trend('daily');

            expect($trend['current']['total'])->toBe(150);
            expect($trend['previous']['total'])->toBe(100);
            expect($trend['delta']['total'])->toBe(50);
            expect($trend['pct_change']['total'])->toBe(50.0); // (150-100)/100 * 100

            expect($trend['pct_change']['unique_users'])->toBe(50.0); // (30-20)/20 * 100
            expect($trend['pct_change']['unique_clients'])->toBe(25.0); // (50-40)/40 * 100
        });

        it('handles zero previous period gracefully', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_zero_trend_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $currentPrefix = 'zb_zero_trend_daily:' . now()->format('Y-m-d');
            Cache::put($currentPrefix . ':total', 50, 3600);
            Cache::put($currentPrefix . ':users:_count', 10, 3600);
            Cache::put($currentPrefix . ':clients:_count', 20, 3600);

            $trend = $service->trend('daily');

            expect($trend['pct_change']['total'])->toBe(100.0); // 0 → any = 100%
        });
    });

    describe('Sparkline', function (): void {
        it('returns sparkline data points', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['hourly'],
                    'cache_prefix' => 'zb_spark_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            // Seed some historical data
            for ($i = 0; $i < 3; $i++) {
                $date = now()->subHours($i)->format('Y-m-d\TH');
                Cache::put("zb_spark_test_hourly:{$date}:events:page_view", $i + 1, 7200);
            }

            $sparkline = $service->sparkline('page_view', 'hourly', 3);

            expect($sparkline)->toHaveCount(3);
            expect($sparkline[0]['count'])->toBe(1);
            expect($sparkline[1]['count'])->toBe(2);
            expect($sparkline[2]['count'])->toBe(3);
        });
    });

    describe('Stats', function (): void {
        it('returns stats for all granularities', function (): void {
            $cache = app(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'enabled' => true,
                    'granularities' => ['daily'],
                    'cache_prefix' => 'zb_stats_test_',
                    'hourly_ttl' => 7200,
                    'daily_ttl' => 604800,
                    'weekly_ttl' => 2592000,
                    'max_top_events' => 20,
                    'max_unique_trackers' => 10000,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            $dailyPrefix = 'zb_stats_test_daily:' . now()->format('Y-m-d');
            Cache::put($dailyPrefix . ':total', 200, 3600);
            Cache::put($dailyPrefix . ':users:_count', 50, 3600);
            Cache::put($dailyPrefix . ':clients:_count', 75, 3600);

            $stats = $service->stats();

            expect($stats)->toHaveKey('granularities');
            expect($stats['granularities'])->toHaveKey('daily');
            expect($stats['granularities']['daily']['total'])->toBe(200);
            expect($stats['granularities']['daily']['unique_users'])->toBe(50);
        });
    });

    describe('TTL', function (): void {
        it('returns correct TTL per granularity', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.rollup', [])
                ->andReturn([
                    'hourly_ttl' => 3600,
                    'daily_ttl' => 86400,
                    'weekly_ttl' => 604800,
                ]);

            $service = new AnalyticsRollupService($cache, $config);

            expect($service->getTtlForGranularity('hourly'))->toBe(3600);
            expect($service->getTtlForGranularity('daily'))->toBe(86400);
            expect($service->getTtlForGranularity('weekly'))->toBe(604800);
            expect($service->getTtlForGranularity('unknown'))->toBe(3600);
        });
    });
});

describe('AnalyticsRollupCommand', function (): void {
    it('has correct signature', function (): void {
        $command = new AnalyticsRollupCommand;

        expect($command->getSignature())->toContain('zb:analytics:rollup');
        expect($command->getDescription())->toContain('52.0.0');
    });
});

describe('Version Consistency', function (): void {
    it('AnalyticsEvent::VERSION matches expected v53.0.0', function (): void {
        // After version sweep this should be 53.0.0
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('53.0.0');
    });
});
