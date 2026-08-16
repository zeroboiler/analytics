<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventReprocessorService;

beforeEach(function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.reprocessor', [])
        ->andReturn([
            'enabled' => true,
            'dry_run' => false,
            'batch_size' => 50,
            'max_events' => 10000,
            'apply_migrations' => true,
            'validate_before_dispatch' => true,
            'audit_results' => false,
            'audit_ttl' => 86400,
        ]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.archive.cache_prefix', 'zb_archive:events')
        ->andReturn('zb_archive:events');

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.schema_migration', [])
        ->andReturn(['migrations' => []]);

    $cache->shouldReceive('put')->zeroOrMoreTimes();
    $cache->shouldReceive('get')
        ->with('zb_archive:events', [])
        ->andReturn([
            [
                'name' => 'page_view',
                'params' => ['page_path' => '/home'],
                'client_id' => 'client_001',
                'category' => 'engagement',
            ],
            [
                'name' => 'purchase',
                'params' => ['items' => [['id' => 'SKU-1', 'name' => 'Widget']]],
                'client_id' => 'client_001',
                'user_id' => 'user_123',
                'category' => 'ecommerce',
            ],
        ]);

    $cache->shouldReceive('forget')->zeroOrMoreTimes();
    $cache->shouldReceive('get')
        ->withArgs(fn (string $key): bool => str_starts_with($key, 'zb_reprocessor:'))
        ->andReturn(null)
        ->zeroOrMoreTimes();

    $this->reprocessor = new EventReprocessorService($cache, $config);
    $this->cache = $cache;
    $this->config = $config;
});

describe('EventReprocessorService', function (): void {
    it('returns enabled state', function (): void {
        expect($this->reprocessor->isEnabled())->toBeTrue();
    });

    it('returns dry run state', function (): void {
        expect($this->reprocessor->isDryRun())->toBeFalse();
    });

    it('returns config summary with all keys', function (): void {
        $summary = $this->reprocessor->configSummary();

        expect($summary)->toHaveKeys([
            'enabled', 'dry_run', 'batch_size', 'max_events',
            'apply_migrations', 'validate_before_dispatch',
            'audit_results', 'audit_ttl',
        ]);
        expect($summary['enabled'])->toBeTrue();
        expect($summary['batch_size'])->toBe(50);
        expect($summary['max_events'])->toBe(10000);
    });

    it('reprocesses archived events with dry run', function (): void {
        $result = $this->reprocessor->reprocess(['dry_run' => true]);

        expect($result['processed'])->toBe(2);
        expect($result['dispatched'])->toBe(2);
        expect($result['failed'])->toBe(0);
        expect($result['skipped'])->toBe(0);
        expect($result['metrics']['dispatch_rate'])->toBe(1.0);
    });

    it('skips events with empty names', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_archive:events', [])
            ->andReturn([
                ['name' => '', 'params' => []],
                ['name' => 'page_view', 'params' => ['page_path' => '/']],
            ]);

        $result = $this->reprocessor->reprocess(['dry_run' => true]);

        expect($result['processed'])->toBe(2);
        expect($result['skipped'])->toBe(1);
        expect($result['dispatched'])->toBe(1);
    });

    it('applies schema migrations when enabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.schema_migration', [])
            ->andReturn([
                'migrations' => [
                    'page_view' => [
                        'rename' => ['page_path' => 'url'],
                        'defaults' => ['source' => 'web'],
                    ],
                ],
            ]);

        $result = $this->reprocessor->reprocess([
            'dry_run' => true,
            'apply_migrations' => true,
        ]);

        expect($result['processed'])->toBe(2);
        expect($result['dispatched'])->toBe(2);
    });

    it('filters by event name', function (): void {
        $result = $this->reprocessor->reprocess([
            'dry_run' => true,
            'event_names' => ['page_view'],
        ]);

        expect($result['processed'])->toBe(1);
        expect($result['results'][0]['event'])->toBe('page_view');
    });

    it('filters by category', function (): void {
        $result = $this->reprocessor->reprocess([
            'dry_run' => true,
            'categories' => ['ecommerce'],
        ]);

        expect($result['processed'])->toBe(1);
        expect($result['results'][0]['event'])->toBe('purchase');
    });

    it('filters by client ID', function (): void {
        $result = $this->reprocessor->reprocess([
            'dry_run' => true,
            'client_id' => 'client_001',
        ]);

        expect($result['processed'])->toBe(2);
    });

    it('returns zero results when no archived events', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_archive:events', [])
            ->andReturn([]);

        $result = $this->reprocessor->reprocess(['dry_run' => true]);

        expect($result['processed'])->toBe(0);
        expect($result['dispatched'])->toBe(0);
    });

    it('returns disabled result when disabled', function (): void {
        $disabledConfig = mock(ConfigRepository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.reprocessor', [])
            ->andReturn(['enabled' => false]);

        $disabledConfig->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturn([]);

        $disabledReprocessor = new EventReprocessorService($this->cache, $disabledConfig);

        $result = $disabledReprocessor->reprocess();

        expect($result['processed'])->toBe(0);
        expect($result['dispatched'])->toBe(0);
    });

    it('audits archived events', function (): void {
        $result = $this->reprocessor->audit();

        expect($result['total'])->toBe(2);
        // page_view has no required params, purchase requires 'items'
        expect($result['valid'])->toBe(2);
        expect($result['invalid'])->toBe(0);
    });

    it('detects missing schema in audit', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_archive:events', [])
            ->andReturn([
                ['name' => 'nonexistent_event', 'params' => []],
            ]);

        $result = $this->reprocessor->audit();

        expect($result['total'])->toBe(1);
        expect($result['missing_schema'])->toBe(1);
    });

    it('returns metrics with zero runs', function (): void {
        $metrics = $this->reprocessor->metrics();

        expect($metrics['total_runs'])->toBe(0);
        expect($metrics['last_run'])->toBeNull();
        expect($metrics['recent_summary'])->toBeNull();
    });

    it('clears metrics', function (): void {
        $this->cache->shouldReceive('forget')
            ->with('zb_reprocessor:audit_runs')
            ->once();
        $this->cache->shouldReceive('forget')
            ->with('zb_reprocessor:last_result')
            ->once();

        $result = $this->reprocessor->clearMetrics();

        expect($result)->toBeTrue();
    });

    it('records audit when enabled', function (): void {
        $this->cache->shouldReceive('put')
            ->withArgs(function (string $key, mixed $value, int $ttl): bool {
                return str_starts_with($key, 'zb_reprocessor:');
            })
            ->atLeast(2);

        $this->reprocessor->reprocess(['dry_run' => true]);
    });

    it('returns null for last result when none exists', function (): void {
        expect($this->reprocessor->lastResult())->toBeNull();
    });

    it('dispatch rate is calculated correctly', function (): void {
        $result = $this->reprocessor->reprocess(['dry_run' => true]);

        expect($result['metrics']['dispatch_rate'])->toBe(1.0);
        expect($result['metrics']['validation_rate'])->toBe(1.0);
    });

    it('filters by user ID', function (): void {
        $result = $this->reprocessor->reprocess([
            'dry_run' => true,
            'user_id' => 'user_123',
        ]);

        expect($result['processed'])->toBe(1);
        expect($result['results'][0]['event'])->toBe('purchase');
    });
});
