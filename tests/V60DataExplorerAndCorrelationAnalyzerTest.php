<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService;
use ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->cache->shouldReceive('get')->andReturnNull();
    $this->cache->shouldReceive('put')->andReturnTrue();
});

describe('AnalyticsDataExplorerService', function (): void {
    test('health returns expected structure', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $health = $service->health();

        expect($health)->toBeArray();
        expect($health['status'])->toBe('ok');
        expect($health['store_available'])->toBeFalse();
        expect($health['cache_ttl'])->toBe(300);
        expect($health['max_results'])->toBe(1000);
        expect($health['supported_granularities'])->toContain('minute', 'hour', 'day', 'week', 'month');
        expect($health['supported_periods'])->toContain('1h', '24h', '7d', '30d', '90d');
    });

    test('explore returns valid structure with empty filters', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->explore([], 'event_name', '24h', 'hour', 50);

        expect($result)->toHaveKeys(['query', 'results', 'meta']);
        expect($result['query']['group_by'])->toBe('event_name');
        expect($result['query']['granularity'])->toBe('hour');
        expect($result['query']['limit'])->toBe(50);
        expect($result['meta']['total_results'])->toBe(0);
        expect($result['meta']['period'])->toBe('24h');
    });

    test('topEvents returns valid structure with trend classification', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->topEvents('24h', 20, null);

        expect($result)->toHaveKeys(['top_events', 'period', 'meta']);
        expect($result['period'])->toBe('24h');
        expect($result['meta']['limit'])->toBe(20);
    });

    test('topEvents with category filter', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->topEvents('7d', 10, 'ecommerce');

        expect($result['meta']['category_filter'])->toBe('ecommerce');
        expect($result['meta']['limit'])->toBe(10);
    });

    test('drillDown returns parameter stats structure', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->drillDown('purchase', [], '24h');

        expect($result)->toHaveKeys(['event', 'parameter_stats', 'total_count', 'time_distribution']);
        expect($result['event'])->toBe('purchase');
        expect($result['total_count'])->toBe(0);
    });

    test('compare returns comparison structure', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->compare('purchase', '7d', 'previous_7d', null);

        expect($result)->toHaveKeys(['comparison', 'period_a', 'period_b', 'meta']);
        expect($result['meta']['event_filter'])->toBe('purchase');
    });

    test('compare with wildcard event', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->compare('*', '7d', 'previous_7d', 'ecommerce');

        expect($result['meta']['event_filter'])->toBe('*');
        expect($result['meta']['category_filter'])->toBe('ecommerce');
    });

    test('funnel returns valid funnel structure', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->funnel(
            ['sign_up', 'trial_start', 'subscription'],
            '7d',
        );

        expect($result)->toHaveKeys(['funnel', 'overall_conversion', 'period']);
        expect($result['period'])->toBe('7d');
        expect(count($result['funnel']))->toBe(3);

        // Each step should have required fields
        foreach ($result['funnel'] as $step) {
            expect($step)->toHaveKeys(['step', 'event', 'count', 'drop_off', 'conversion']);
        }

        // First step should have 100% conversion
        expect($result['funnel'][0]['conversion'])->toBe(100.0);
    });

    test('funnel with empty steps returns empty funnel', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->funnel([], '7d');

        expect($result['funnel'])->toBeEmpty();
        expect($result['overall_conversion'])->toBe(0.0);
    });

    test('explore respects limit cap', function (): void {
        $service = new AnalyticsDataExplorerService($this->cache, $this->config);

        $result = $service->explore([], 'event_name', '24h', 'hour', 9999);

        expect($result['query']['limit'])->toBe(1000); // MAX_RESULTS
    });

    test('explore caches results', function (): void {
        $this->cache->shouldReceive('get')->once()->andReturnNull();
        $this->cache->shouldReceive('put')->once()->andReturnTrue();

        $service = new AnalyticsDataExplorerService($this->cache, $this->config);
        $service->explore(['category' => 'saas'], 'event_name', '24h', 'hour', 10);
    });

    test('topEvents caches results', function (): void {
        $this->cache->shouldReceive('get')->once()->andReturnNull();
        $this->cache->shouldReceive('put')->once()->andReturnTrue();

        $service = new AnalyticsDataExplorerService($this->cache, $this->config);
        $service->topEvents('7d', 10);
    });
});

describe('EventCorrelationAnalyzerService', function (): void {
    test('health returns expected structure', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $health = $service->health();

        expect($health)->toBeArray();
        expect($health['status'])->toBe('ok');
        expect($health['store_available'])->toBeFalse();
        expect($health['cache_ttl'])->toBe(600);
        expect($health['max_lag_steps'])->toBe(24);
        expect($health['correlation_threshold'])->toBe(0.3);
        expect($health['default_lag_offsets'])->toContain(0, 1, 2, 4, 8, 12, 24);
    });

    test('crossCorrelation returns valid CCF structure', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->crossCorrelation('sign_up', 'purchase', '30d');

        expect($result)->toHaveKeys(['event_a', 'event_b', 'ccf', 'peak_lag', 'period']);
        expect($result['event_a'])->toBe('sign_up');
        expect($result['event_b'])->toBe('purchase');
        expect($result['period'])->toBe('30d');
        expect(count($result['ccf']))->toBeGreaterThan(0);

        // Each CCF entry should have required fields
        foreach ($result['ccf'] as $entry) {
            expect($entry)->toHaveKeys(['lag_hours', 'correlation', 'significance', 'sample_size']);
            expect($entry['correlation'])->toBeGreaterThanOrEqual(-1.0);
            expect($entry['correlation'])->toBeLessThanOrEqual(1.0);
        }

        // Peak lag should have required fields
        expect($result['peak_lag'])->toHaveKeys(['lag_hours', 'correlation', 'direction']);
        expect($result['peak_lag']['direction'])->toBeIn(['positive', 'negative', 'none']);
    });

    test('crossCorrelation with custom lag offsets', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->crossCorrelation(
            'page_view',
            'sign_up',
            '7d',
            [0, 1, 2, 6, 12],
        );

        expect(count($result['ccf']))->toBe(5);
        $lagHours = array_column($result['ccf'], 'lag_hours');
        expect($lagHours)->toBe([0, 1, 2, 6, 12]);
    });

    test('crossCorrelation respects max lag steps', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        // Pass 30 lag offsets — should be capped at MAX_LAG_STEPS (24)
        $excessiveLags = range(0, 29);
        $result = $service->crossCorrelation('a', 'b', '7d', $excessiveLags);

        expect(count($result['ccf']))->toBe(24);
    });

    test('transitionAnalysis returns valid structure', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->transitionAnalysis('page_view', 'sign_up', '30d', 24);

        expect($result)->toHaveKeys(['transitions', 'window_hours', 'period', 'confidence']);
        expect($result['transitions'])->toHaveKeys(['total_a', 'a_then_b', 'conversion_rate', 'lift', 'baseline_rate']);
        expect($result['window_hours'])->toBe(24);
        expect($result['confidence'])->toBeIn(['low', 'medium', 'high']);
    });

    test('correlationMatrix returns valid matrix structure', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->correlationMatrix(
            ['sign_up', 'login', 'purchase'],
            '30d',
            0,
        );

        expect($result)->toHaveKeys(['events', 'matrix', 'lag_hours', 'period']);
        expect($result['events'])->toBe(['sign_up', 'login', 'purchase']);
        expect($result['lag_hours'])->toBe(0);
        expect(count($result['matrix']))->toBe(3);

        // Matrix should be square with correlation values between -1 and 1
        foreach ($result['matrix'] as $event => $row) {
            expect(count($row))->toBe(3);
            foreach ($row as $corr) {
                expect($corr)->toBeGreaterThanOrEqual(-1.0);
                expect($corr)->toBeLessThanOrEqual(1.0);
            }
        }

        // Diagonal should be 1.0 (perfect self-correlation)
        foreach ($result['matrix'] as $event => $row) {
            expect($row[$event])->toBe(1.0);
        }
    });

    test('correlationMatrix with time lag', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->correlationMatrix(
            ['sign_up', 'purchase'],
            '30d',
            4,
        );

        expect($result['lag_hours'])->toBe(4);
    });

    test('correlationMatrix with less than 2 events returns empty', function (): void {
        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);

        $result = $service->correlationMatrix(['sign_up'], '30d', 0);

        expect($result['events'])->toBe(['sign_up']);
        expect($result['matrix'])->toBeEmpty();
    });

    test('crossCorrelation caches results', function (): void {
        $this->cache->shouldReceive('get')->once()->andReturnNull();
        $this->cache->shouldReceive('put')->once()->andReturnTrue();

        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);
        $service->crossCorrelation('a', 'b', '7d');
    });

    test('transitionAnalysis caches results', function (): void {
        $this->cache->shouldReceive('get')->once()->andReturnNull();
        $this->cache->shouldReceive('put')->once()->andReturnTrue();

        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);
        $service->transitionAnalysis('a', 'b', '7d', 24);
    });

    test('correlationMatrix caches results', function (): void {
        $this->cache->shouldReceive('get')->once()->andReturnNull();
        $this->cache->shouldReceive('put')->once()->andReturnTrue();

        $service = new EventCorrelationAnalyzerService($this->cache, $this->config);
        $service->correlationMatrix(['a', 'b', 'c'], '30d', 0);
    });
});

describe('Version Consistency', function (): void {
    test('all version references are 61.0.0', function (): void {
        // Verify DTO version
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('61.0.0');

        // Verify composer.json version
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        expect($composer['version'])->toBe('61.0.0');

        // Verify package.json version
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        expect($package['version'])->toBe('61.0.0');
    });
});
