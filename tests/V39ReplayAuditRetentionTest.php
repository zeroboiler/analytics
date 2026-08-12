<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventReplayAuditService;
use ZeroBoiler\Analytics\Services\AnalyticsDataRetentionService;

describe('Event Replay Audit Service', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('forget')->andReturn(true);
        $this->cache->shouldReceive('has')->andReturn(false);

        $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_audit', [])
            ->andReturn([
                'enabled' => true,
                'cache_prefix' => 'zb_replay_audit_',
                'retention_ttl' => 2592000,
                'max_entries' => 5000,
                'auto_record' => true,
            ]);

        $this->service = new EventReplayAuditService($this->cache, $this->config);
    });

    it('is enabled by default', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    it('auto-record is enabled by default', function (): void {
        expect($this->service->isAutoRecord())->toBeTrue();
    });

    it('records a single event replay and returns audit ID', function (): void {
        $auditId = $this->service->recordSingle(
            eventName: 'purchase',
            archiveId: 42,
            clientId: 'client_abc',
            userId: 'user_123',
            providerResults: ['ga4' => true, 'meta' => false],
            source: 'archive',
            durationMs: 150.5,
        );

        expect($auditId)->toBeString();
        expect(strlen($auditId))->toBe(12);
    });

    it('records a single replay with no providers', function (): void {
        $auditId = $this->service->recordSingle(
            eventName: 'page_view',
            source: 'manual',
        );

        expect($auditId)->toBeString();
    });

    it('records a bulk replay operation', function (): void {
        $auditId = $this->service->recordBulk(
            totalEvents: 100,
            replayed: 95,
            failed: 5,
            filters: ['event_name' => 'purchase'],
            userId: 'admin_1',
            source: 'command',
            durationMs: 3200.0,
        );

        expect($auditId)->toBeString();
    });

    it('returns empty string when disabled', function (): void {
        $disabledConfig = mock(\Illuminate\Contracts\Config\Repository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.replay_audit', [])
            ->andReturn(['enabled' => false]);

        $disabledService = new EventReplayAuditService($this->cache, $disabledConfig);

        expect($disabledService->isEnabled())->toBeFalse();
        expect($disabledService->recordSingle('test'))->toBe('');
        expect($disabledService->recordBulk(1, 1, 0))->toBe('');
    });

    it('returns summary with correct fields', function (): void {
        $summary = $this->service->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('auto_record');
        expect($summary)->toHaveKey('total_entries');
        expect($summary)->toHaveKey('max_entries');
        expect($summary)->toHaveKey('retention_ttl');
        expect($summary)->toHaveKey('cache_prefix');
        expect($summary)->toHaveKey('utilization');
        expect($summary['enabled'])->toBeTrue();
        expect($summary['max_entries'])->toBe(5000);
    });

    it('returns empty search when no entries exist', function (): void {
        $results = $this->service->search();

        expect($results['entries'])->toBeEmpty();
        expect($results['total'])->toBe(0);
    });

    it('returns empty statistics when no entries exist', function (): void {
        $stats = $this->service->statistics();

        expect($stats['total_replays'])->toBe(0);
        expect($stats['success_rate'])->toBeNull();
        expect($stats['avg_duration_ms'])->toBeNull();
        expect($stats['by_source'])->toBeEmpty();
    });

    it('returns zero total count when empty', function (): void {
        expect($this->service->totalCount())->toBe(0);
    });

    it('reports correct total entries', function (): void {
        // After recording, lastId should be > 0
        $this->service->recordSingle('test_event', source: 'manual');

        // The cache.put stores the incremented counter
        expect($this->service->totalCount())->toBeGreaterThan(0);
    });

    describe('search filters', function (): void {
        it('accepts source filter', function (): void {
            $results = $this->service->search(['source' => 'archive']);

            expect($results)->toHaveKey('entries');
            expect($results)->toHaveKey('total');
            expect($results)->toHaveKey('limit');
        });

        it('accepts type filter', function (): void {
            $results = $this->service->search(['type' => 'bulk']);

            expect($results)->toHaveKey('entries');
        });

        it('accepts event_name filter', function (): void {
            $results = $this->service->search(['event_name' => 'purchase']);

            expect($results)->toHaveKey('entries');
        });

        it('accepts success filter', function (): void {
            $results = $this->service->search(['success' => true]);

            expect($results)->toHaveKey('entries');
        });

        it('accepts since filter', function (): void {
            $results = $this->service->search(['since' => '2026-01-01T00:00:00+00:00']);

            expect($results)->toHaveKey('entries');
        });
    });
});

describe('Analytics Data Retention Service', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('forget')->andReturn(true);

        $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_retention', [])
            ->andReturn([
                'enabled' => true,
                'default_days' => 90,
                'categories' => [
                    'ecommerce' => 90,
                    'saas' => 180,
                    'engagement' => 30,
                    'security' => 365,
                    'uptime' => 30,
                ],
                'cache_prefix' => 'zb_retention_',
                'cache_ttl' => 3600,
                'gdpr_erase_enabled' => true,
                'purge_batch_size' => 500,
                'log_purge' => true,
            ]);

        $this->archive = mock(\ZeroBoiler\Analytics\Services\EventArchiveService::class);
        $this->archive->shouldReceive('totalArchived')->andReturn(0);

        $this->service = new AnalyticsDataRetentionService($this->cache, $this->archive, $this->config);
    });

    it('is enabled by default', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    it('returns category-specific retention in seconds', function (): void {
        expect($this->service->retentionFor('ecommerce'))->toBe(90 * 86400);
        expect($this->service->retentionFor('saas'))->toBe(180 * 86400);
        expect($this->service->retentionFor('engagement'))->toBe(30 * 86400);
        expect($this->service->retentionFor('security'))->toBe(365 * 86400);
    });

    it('returns default retention for unknown category', function (): void {
        expect($this->service->retentionFor('unknown_category'))->toBe(90 * 86400);
    });

    it('returns category-specific retention in days', function (): void {
        expect($this->service->retentionDaysFor('ecommerce'))->toBe(90);
        expect($this->service->retentionDaysFor('saas'))->toBe(180);
        expect($this->service->retentionDaysFor('engagement'))->toBe(30);
    });

    it('detects expired timestamps correctly', function (): void {
        // Event from 100 days ago in engagement (30-day retention) = expired
        $oldTimestamp = now()->subDays(100)->format('c');
        expect($this->service->isExpired($oldTimestamp, 'engagement'))->toBeTrue();

        // Event from 10 days ago in engagement (30-day retention) = not expired
        $recentTimestamp = now()->subDays(10)->format('c');
        expect($this->service->isExpired($recentTimestamp, 'engagement'))->toBeFalse();

        // Event from 200 days ago in saas (180-day retention) = expired
        $oldSaas = now()->subDays(200)->format('c');
        expect($this->service->isExpired($oldSaas, 'saas'))->toBeTrue();

        // Event from 100 days ago in saas (180-day retention) = not expired
        $recentSaas = now()->subDays(100)->format('c');
        expect($this->service->isExpired($recentSaas, 'saas'))->toBeFalse();
    });

    it('does not purge when disabled', function (): void {
        $disabledConfig = mock(\Illuminate\Contracts\Config\Repository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.data_retention', [])
            ->andReturn(['enabled' => false]);

        $disabledService = new AnalyticsDataRetentionService($this->cache, $this->archive, $disabledConfig);

        $result = $disabledService->purgeExpired();

        expect($result['purged'])->toBe(0);
        expect($result['dry_run'])->toBeFalse();
    });

    it('returns correct purge result structure', function (): void {
        $result = $this->service->purgeExpired(dryRun: true);

        expect($result)->toHaveKey('purged');
        expect($result)->toHaveKey('scanned');
        expect($result)->toHaveKey('dry_run');
        expect($result)->toHaveKey('category');
        expect($result)->toHaveKey('timestamp');
        expect($result['dry_run'])->toBeTrue();
    });

    it('GDPR purge for client ID returns correct structure', function (): void {
        $result = $this->service->purgeForClientId('client_abc');

        expect($result)->toHaveKey('purged');
        expect($result)->toHaveKey('client_id');
        expect($result)->toHaveKey('timestamp');
        expect($result['client_id'])->toBe('client_abc');
    });

    it('GDPR purge for user ID returns correct structure', function (): void {
        $result = $this->service->purgeForUserId('user_123');

        expect($result)->toHaveKey('purged');
        expect($result)->toHaveKey('user_id');
        expect($result)->toHaveKey('timestamp');
        expect($result['user_id'])->toBe('user_123');
    });

    it('GDPR purge does nothing when disabled', function (): void {
        $disabledConfig = mock(\Illuminate\Contracts\Config\Repository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.data_retention', [])
            ->andReturn([
                'enabled' => true,
                'default_days' => 90,
                'gdpr_erase_enabled' => false,
            ]);

        $disabledService = new AnalyticsDataRetentionService($this->cache, $this->archive, $disabledConfig);

        $result = $disabledService->purgeForClientId('client_abc');
        expect($result['purged'])->toBe(0);

        $result = $disabledService->purgeForUserId('user_123');
        expect($result['purged'])->toBe(0);
    });

    it('returns statistics with correct fields', function (): void {
        $stats = $this->service->statistics();

        expect($stats)->toHaveKey('enabled');
        expect($stats)->toHaveKey('default_days');
        expect($stats)->toHaveKey('categories');
        expect($stats)->toHaveKey('total_archived');
        expect($stats)->toHaveKey('gdpr_erase_enabled');
        expect($stats)->toHaveKey('purge_batch_size');
        expect($stats['enabled'])->toBeTrue();
        expect($stats['default_days'])->toBe(90);
        expect($stats['gdpr_erase_enabled'])->toBeTrue();
    });

    it('returns summary with correct fields', function (): void {
        $summary = $this->service->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('gdpr_erase_enabled');
        expect($summary)->toHaveKey('default_days');
        expect($summary)->toHaveKey('category_count');
        expect($summary)->toHaveKey('cache_prefix');
        expect($summary['category_count'])->toBe(5);
    });

    it('returns configured categories in days', function (): void {
        $categories = $this->service->configuredCategories();

        expect($categories)->toBeArray();
        expect($categories['ecommerce'])->toBe(90);
        expect($categories['saas'])->toBe(180);
        expect($categories['engagement'])->toBe(30);
        expect($categories['security'])->toBe(365);
        expect($categories['uptime'])->toBe(30);
    });

    it('returns empty purge logs by default', function (): void {
        $logs = $this->service->getPurgeLogs();

        expect($logs)->toBeEmpty();
    });
});

describe('Version Sweep v39.0.0', function (): void {
    it('AnalyticsEvent version is 39.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('39.0.0');
    });

    it('composer.json version is 39.0.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('39.0.0');
    });

    it('Integrity command expected version is 39.0.0', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
        $property = $reflection->getProperty('EXPECTED_VERSION');
        $property->setAccessible(true);
        expect($property->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand))->toBe('39.0.0');
    });
});
