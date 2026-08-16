<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDispatchLatencyTracker;
use ZeroBoiler\Analytics\Services\EventReplayAuditLedger;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

afterEach(function (): void {
    Mockery::close();
});

describe('EventDispatchLatencyTracker', function (): void {
    it('records latency and computes provider stats', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.latency_tracking', [])
            ->andReturn([
                'enabled' => true,
                'ttl' => 3600,
                'slow_threshold_ms' => 100.0,
                'sampling_rate' => 1.0,
                'buckets' => [1, 5, 10, 25, 50, 100, 250, 500],
            ]);

        // Track cache calls
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) {
                return $default;
            });
        $this->cache->shouldReceive('put')
            ->andReturnTrue();

        $tracker = new EventDispatchLatencyTracker($this->cache, $this->config);

        $tracker->record('ga4', 'page_view', 12.5);
        $tracker->record('ga4', 'page_view', 45.0);
        $tracker->record('ga4', 'page_view', 150.0, success: true); // slow
        $tracker->record('ga4', 'sign_up', 8.0, success: false);

        $stats = $tracker->providerStats('ga4');

        expect($stats['count'])->toBe(4);
        expect($stats['error_count'])->toBe(1);
        expect($stats['slow_count'])->toBe(1);
        expect($stats['avg_ms'])->toBeGreaterThanOrEqual(0);
    });

    it('respects sampling rate', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.latency_tracking', [])
            ->andReturn([
                'enabled' => true,
                'ttl' => 3600,
                'slow_threshold_ms' => 1000.0,
                'sampling_rate' => 0.0, // always skip
                'buckets' => [10, 100, 1000],
            ]);

        $this->cache->shouldNotReceive('put');

        $tracker = new EventDispatchLatencyTracker($this->cache, $this->config);

        $tracker->record('ga4', 'page_view', 50.0);

        // No assertions needed — we verify cache->put was never called
        expect(true)->toBeTrue();
    });

    it('returns empty stats when no data exists', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.latency_tracking', [])
            ->andReturn([
                'enabled' => true,
                'ttl' => 3600,
                'slow_threshold_ms' => 1000.0,
                'sampling_rate' => 1.0,
                'buckets' => [10, 100],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturn(null);

        $tracker = new EventDispatchLatencyTracker($this->cache, $this->config);
        $stats = $tracker->providerStats('posthog');

        expect($stats['count'])->toBe(0);
        expect($stats['avg_ms'])->toBe(0.0);
        expect($stats['p50_ms'])->toBeNull();
        expect($stats['p95_ms'])->toBeNull();
    });

    it('returns diagnostic summary with enabled flag', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.latency_tracking', [])
            ->andReturn([
                'enabled' => false,
                'ttl' => 3600,
                'slow_threshold_ms' => 500.0,
                'sampling_rate' => 0.5,
                'buckets' => [10, 50],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $tracker = new EventDispatchLatencyTracker($this->cache, $this->config);
        $summary = $tracker->diagnosticSummary();

        expect($summary['enabled'])->toBeFalse();
        expect($summary['sampling_rate'])->toBe(0.5);
        expect($summary['slow_threshold_ms'])->toBe(500.0);
        expect($summary['global']['total_recorded'])->toBe(0);
        expect($summary['providers'])->toHaveKey('ga4');
        expect($summary['providers'])->toHaveKey('meta');
    });

    it('computes percentiles correctly', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.latency_tracking', [])
            ->andReturn([
                'enabled' => true,
                'ttl' => 3600,
                'slow_threshold_ms' => 1000.0,
                'sampling_rate' => 1.0,
                'buckets' => [10, 100, 1000],
            ]);

        // Simulate cache with pre-populated data
        $providerData = [
            'samples' => [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0],
            'errors' => 0,
            'slow' => 0,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_latency_provider_ga4', Mockery::any())
            ->andReturn($providerData);
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) {
                return match (true) {
                    str_contains($key, 'histogram_') => [],
                    str_contains($key, 'event_') => [],
                    default => $default,
                };
            });

        $tracker = new EventDispatchLatencyTracker($this->cache, $this->config);
        $stats = $tracker->providerStats('ga4');

        expect($stats['count'])->toBe(10);
        expect($stats['min_ms'])->toBe(10.0);
        expect($stats['max_ms'])->toBe(100.0);
        expect($stats['p50_ms'])->toBe(55.0); // midpoint between 50 and 60
    });
});

describe('EventReplayAuditLedger', function (): void {
    it('records a replay operation and retrieves it', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn([
                'max_operations' => 500,
                'ttl' => 86400,
            ]);

        $storedOperations = [];
        $storedIndex = [];

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value) use (&$storedOperations, &$storedIndex): bool {
                if ($key === 'zb_replay_ledger_index') {
                    $storedIndex = $value;
                } else {
                    $storedOperations[$key] = $value;
                }
                return true;
            });

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use (&$storedOperations, &$storedIndex): mixed {
                if ($key === 'zb_replay_ledger_index') {
                    return $storedIndex ?: $default;
                }

                return $storedOperations[$key] ?? $default;
            });

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        $opId = $ledger->recordOperation(
            triggeredBy: 'admin@example.com',
            source: 'dlq',
            scope: 'batch',
            eventCount: 5,
            metadata: ['reason' => 'DLQ overflow'],
        );

        expect($opId)->toStartWith('replay_');

        $operation = $ledger->getOperation($opId);
        expect($operation)->not->toBeNull();
        expect($operation['operation_id'])->toBe($opId);
        expect($operation['triggered_by'])->toBe('admin@example.com');
        expect($operation['source'])->toBe('dlq');
        expect($operation['status'])->toBe('in_progress');
        expect($operation['event_count'])->toBe(5);
    });

    it('records event results within an operation', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn(['max_operations' => 500, 'ttl' => 86400]);

        $stored = [];

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value) use (&$stored): bool {
                $stored[$key] = $value;
                return true;
            });

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use (&$stored): mixed {
                return $stored[$key] ?? $default;
            });

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        $opId = $ledger->recordOperation('system', 'archive', 'batch', 3);

        $event = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client_123');
        $ledger->recordEventResult($opId, $event, 'dispatched', 'ga4');

        $event2 = new AnalyticsEvent(name: 'sign_up', params: [], clientId: 'client_456');
        $ledger->recordEventResult($opId, $event2, 'failed', 'meta', 'Invalid API key');

        $ledger->completeOperation($opId, 'partial');

        $completed = $ledger->getOperation($opId);
        expect($completed['status'])->toBe('partial');
        expect($completed['duration_ms'])->toBeGreaterThanOrEqual(0);
        expect($completed['stats']['dispatched'])->toBe(1);
        expect($completed['stats']['failed'])->toBe(1);
        expect(count($completed['events']))->toBe(2);
    });

    it('aggregates stats across operations', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn(['max_operations' => 500, 'ttl' => 86400]);

        $stored = [];

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value) use (&$stored): bool {
                $stored[$key] = $value;
                return true;
            });

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use (&$stored): mixed {
                return $stored[$key] ?? $default;
            });

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        // Create two operations
        $op1 = $ledger->recordOperation('admin', 'dlq', 'batch', 10);
        $event1 = new AnalyticsEvent(name: 'test', params: []);
        $ledger->recordEventResult($op1, $event1, 'succeeded');
        $ledger->completeOperation($op1, 'completed');

        $op2 = $ledger->recordOperation('system', 'scheduled', 'category', 5);
        $event2 = new AnalyticsEvent(name: 'test2', params: []);
        $ledger->recordEventResult($op2, $event2, 'failed', null, 'Timeout');
        $ledger->completeOperation($op2, 'failed');

        $stats = $ledger->aggregatedStats();

        expect($stats['total_operations'])->toBe(2);
        expect($stats['by_status']['completed'])->toBe(1);
        expect($stats['by_status']['failed'])->toBe(1);
        expect($stats['by_source']['dlq'])->toBe(1);
        expect($stats['by_source']['scheduled'])->toBe(1);
        expect($stats['total_succeeded'])->toBe(1);
        expect($stats['total_failed'])->toBe(1);
    });

    it('provides diagnostic summary', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn(['max_operations' => 100, 'ttl' => 7200]);

        $stored = [];

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value) use (&$stored): bool {
                $stored[$key] = $value;
                return true;
            });

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use (&$stored): mixed {
                return $stored[$key] ?? $default;
            });

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        $summary = $ledger->diagnosticSummary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['max_operations'])->toBe(100);
        expect($summary['ttl'])->toBe(7200);
        expect($summary['total_operations'])->toBe(0);
        expect($summary['recent_stats']['total_operations'])->toBe(0);
        expect($summary['top_failure_reasons'])->toBeArray();
    });

    it('returns null for unknown operation ID', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn(['max_operations' => 500, 'ttl' => 86400]);

        $this->cache->shouldReceive('get')
            ->andReturnNull();

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        expect($ledger->getOperation('nonexistent'))->toBeNull();
    });

    it('lists operations with pagination', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_ledger', [])
            ->andReturn(['max_operations' => 500, 'ttl' => 86400]);

        $stored = [];

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value) use (&$stored): bool {
                $stored[$key] = $value;
                return true;
            });

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use (&$stored): mixed {
                return $stored[$key] ?? $default;
            });

        $ledger = new EventReplayAuditLedger($this->cache, $this->config);

        // Create 3 operations
        $op1 = $ledger->recordOperation('admin', 'manual', 'single', 1);
        $ledger->completeOperation($op1);
        $op2 = $ledger->recordOperation('system', 'dlq', 'batch', 10);
        $ledger->completeOperation($op2);
        $op3 = $ledger->recordOperation('admin', 'archive', 'date_range', 50);
        $ledger->completeOperation($op3);

        $page1 = $ledger->listOperations(0, 2);
        expect(count($page1['operations']))->toBe(2);
        expect($page1['total'])->toBe(3);
        expect($page1['has_more'])->toBeTrue();
        // Most recent first
        expect($page1['operations'][0]['operation_id'])->toBe($op3);

        $page2 = $ledger->listOperations(2, 2);
        expect(count($page2['operations']))->toBe(1);
        expect($page2['has_more'])->toBeFalse();
    });
});
