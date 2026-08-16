<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\AnalyticsObservabilityService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.observability', [])
        ->andReturn([
            'enabled' => true,
            'ttl' => 300,
            'providers' => [],
            'error_budget_threshold' => 0.01,
            'slow_dispatch_ms' => 1000.0,
            'latency_buckets' => 50,
        ]);

    $this->service = new AnalyticsObservabilityService($this->cache, $this->config);
});

describe('AnalyticsObservabilityService', function (): void {
    describe('isEnabled', function (): void {
        it('returns true when observability is enabled', function (): void {
            expect($this->service->isEnabled())->toBeTrue();
        });
    });

    describe('recordSuccess', function (): void {
        it('records a successful dispatch and increments counter', function (): void {
            // registerKey calls: get + put for _index (2 calls)
            // incrementCounter: get success + put success (2 calls)
            // recordLatency: get latency + put latency (2 calls)
            $this->cache->shouldReceive('get')
                ->andReturn(0, null, null);

            $this->cache->shouldReceive('put')
                ->times(6); // index x2 + success + latency_index + latency + index

            $this->service->recordSuccess('ga4', 42.5);
        });

        it('records per-event metrics when event name is provided', function (): void {
            // registerKey x3 (success, latency_ga4, event_success) + incrementCounter x2 (event success + event latency)
            // registerKey for latency_ga4 and event latency
            // Total: 5 registerKey calls + 2 incrementCounter + 2 recordLatency
            $this->cache->shouldReceive('get')
                ->andReturn(0, 0, null, null, null, null, null);

            $this->cache->shouldReceive('put')
                ->times(12); // generous allowance for registerKey + put operations

            $this->service->recordSuccess('ga4', 100.0, 'purchase');
        });

        it('does not record when provider is not observed', function (): void {
            // Create service with restricted providers
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.observability', [])
                ->andReturn([
                    'enabled' => true,
                    'ttl' => 300,
                    'providers' => ['ga4'], // only ga4
                    'error_budget_threshold' => 0.01,
                    'slow_dispatch_ms' => 1000.0,
                    'latency_buckets' => 50,
                ]);

            $service = new AnalyticsObservabilityService($this->cache, $this->config);

            // meta is not in the observed list — should not record
            $service->recordSuccess('meta', 50.0);
            // No assertions needed — if cache->put is called, the test would fail
        });
    });

    describe('recordFailure', function (): void {
        it('records a failure with error type and message', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(0, null, null, null);

            $this->cache->shouldReceive('put')
                ->times(6); // index x2 + failure + error_type_index + error_type + error_log_index + error_log

            $this->service->recordFailure('ga4', 'timeout', 'Connection timed out after 5000ms');
        });
    });

    describe('recordFiltered', function (): void {
        it('records filtered events by filter name', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(5, null);

            $this->cache->shouldReceive('put')
                ->times(3); // index + consent_filtered + index

            $this->service->recordFiltered('consent');
        });
    });

    describe('getProviderMetrics', function (): void {
        it('returns correct metrics structure', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(100, 2, [50.0, 100.0, 150.0, 200.0, 250.0], []);

            $metrics = $this->service->getProviderMetrics('ga4');

            expect($metrics)->toHaveKeys([
                'total', 'success', 'failure', 'success_rate',
                'avg_latency_ms', 'p50_latency_ms', 'p95_latency_ms', 'p99_latency_ms',
                'slow_dispatches', 'error_budget_remaining', 'error_budget_breached', 'recent_errors',
            ]);

            expect($metrics['total'])->toBe(102);
            expect($metrics['success_rate'])->toBeGreaterThan(0.9);
            expect($metrics['error_budget_breached'])->toBeTrue(); // 2/102 > 1% threshold
        });

        it('returns default values when no data exists', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(0, 0, [], []);

            $metrics = $this->service->getProviderMetrics('unknown');

            expect($metrics['total'])->toBe(0);
            expect($metrics['success_rate'])->toBe(1.0);
            expect($metrics['error_budget_breached'])->toBeFalse();
            expect($metrics['avg_latency_ms'])->toBe(0.0);
        });
    });

    describe('getDashboard', function (): void {
        it('returns comprehensive dashboard data', function (): void {
            // Mock cache returns for all default providers
            $providerCount = 8; // ga4, gtm, meta, posthog, plausible, mixpanel, amplitude, webhook
            $this->cache->shouldReceive('get')
                ->times($providerCount * 4) // 4 get calls per provider: success, failure, latencies, errors
                ->andReturn(0, 0, [], []);

            $dashboard = $this->service->getDashboard();

            expect($dashboard)->toHaveKey('enabled');
            expect($dashboard)->toHaveKey('providers');
            expect($dashboard)->toHaveKey('summary');
            expect($dashboard['enabled'])->toBeTrue();
            expect($dashboard['summary'])->toHaveKeys([
                'total_dispatches', 'total_failures', 'overall_success_rate',
                'slowest_provider', 'most_errors_provider',
            ]);
        });
    });

    describe('percentile calculation', function (): void {
        it('calculates p50 correctly', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(0, 0, [10.0, 20.0, 30.0, 40.0, 50.0], []);

            $metrics = $this->service->getProviderMetrics('ga4');
            expect($metrics['p50_latency_ms'])->toBe(30.0);
        });

        it('calculates p95 correctly', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(0, 0, array_fill(0, 100, 100.0), []);

            $metrics = $this->service->getProviderMetrics('ga4');
            expect($metrics['p95_latency_ms'])->toBe(100.0);
        });
    });

    describe('error budget', function (): void {
        it('detects error budget breach', function (): void {
            // 10 failures out of 100 total = 10% > 1% threshold
            $this->cache->shouldReceive('get')
                ->andReturn(90, 10, [], []);

            $metrics = $this->service->getProviderMetrics('ga4');
            expect($metrics['error_budget_breached'])->toBeTrue();
            expect($metrics['error_budget_remaining'])->toBe(0.0);
        });

        it('shows remaining budget when under threshold', function (): void {
            // 0 failures = full budget remaining
            $this->cache->shouldReceive('get')
                ->andReturn(50, 0, [], []);

            $metrics = $this->service->getProviderMetrics('ga4');
            expect($metrics['error_budget_breached'])->toBeFalse();
            expect($metrics['error_budget_remaining'])->toBe(1.0);
        });
    });
});
