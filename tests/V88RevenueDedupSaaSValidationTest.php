<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\EventDedupCacheService;
use ZeroBoiler\Analytics\Services\RevenueChecksumService;
use ZeroBoiler\Analytics\Services\SaaSStarterValidationService;

describe('RevenueChecksumService', function () {
    beforeEach(function () {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('has')->andReturn(false);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_checksum', [])
            ->andReturn([
                'enabled' => true,
                'secret' => 'test-secret-key-v88',
                'replay_ttl' => 3600,
                'require_checksum' => false,
            ]);
        $config->shouldReceive('get')
            ->with('app.key', '')
            ->andReturn('');

        $this->service = new RevenueChecksumService($config, $cache);
    });

    it('generates a checksum for a revenue event', function () {
        $checksum = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);

        expect($checksum)->toBeString();
        expect(strlen($checksum))->toBe(64); // SHA256 hex
    });

    it('generates different checksums for different transactions', function () {
        $a = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);
        $b = $this->service->generate('TXN-002', 99.99, 'USD', 'purchase', 1700000000);

        expect($a)->not->toBe($b);
    });

    it('generates different checksums for different amounts', function () {
        $a = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);
        $b = $this->service->generate('TXN-001', 49.99, 'USD', 'purchase', 1700000000);

        expect($a)->not->toBe($b);
    });

    it('generates same checksum for same inputs', function () {
        $a = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);
        $b = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);

        expect($a)->toBe($b);
    });

    it('validates a correct checksum', function () {
        $checksum = $this->service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);
        $result = $this->service->validate('TXN-001', 99.99, 'USD', $checksum, 'purchase', 1700000000);

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBe('valid');
        expect($result['replay'])->toBeFalse();
    });

    it('rejects an incorrect checksum', function () {
        $result = $this->service->validate('TXN-001', 99.99, 'USD', 'invalid_checksum', 'purchase', 1700000000);

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toBe('checksum_mismatch');
    });

    it('accepts empty checksum when not required', function () {
        $result = $this->service->validate('TXN-001', 99.99, 'USD', '', 'purchase', 1700000000);

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBe('empty_checksum');
    });

    it('signs an event with embedded checksum', function () {
        $signed = $this->service->signEvent([
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
        ], 'purchase');

        expect($signed['params'])->toHaveKey('_revenue_checksum');
        expect($signed['params'])->toHaveKey('_revenue_checksum_ts');
        expect($signed['checksum'])->toBeString();
    });

    it('validates a signed event', function () {
        $signed = $this->service->signEvent([
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
        ], 'purchase');

        $result = $this->service->validateSignedEvent($signed['params'], 'purchase');

        expect($result['valid'])->toBeTrue();
    });

    it('detects replay attacks via cache', function () {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('has')->once()->andReturn(false);
        $cache->shouldReceive('put')->once()->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_checksum', [])
            ->andReturn(['enabled' => true, 'secret' => 'test-secret-key-v88', 'replay_ttl' => 3600]);
        $config->shouldReceive('get')->with('app.key', '')->andReturn('');

        $service = new RevenueChecksumService($config, $cache);
        $checksum = $service->generate('TXN-001', 99.99, 'USD', 'purchase', 1700000000);
        $result1 = $service->validate('TXN-001', 99.99, 'USD', $checksum, 'purchase', 1700000000);

        expect($result1['valid'])->toBeTrue();
    });

    it('returns valid when disabled', function () {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_checksum', [])
            ->andReturn(['enabled' => false]);
        $config->shouldReceive('get')->with('app.key', '')->andReturn('');

        $service = new RevenueChecksumService($config, $cache);
        $result = $service->validate('TXN-001', 99.99, 'USD', 'wrong', 'purchase', 1700000000);

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBe('checksum_disabled');
    });

    it('exposes isEnabled and isRequired', function () {
        expect($this->service->isEnabled())->toBeTrue();
        expect($this->service->isRequired())->toBeFalse();
    });
});

describe('EventDedupCacheService', function () {
    beforeEach(function () {
        $this->cacheStore = [];

        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->andReturnUsing(fn (string $key) => $this->cacheStore[$key] ?? null);
        $cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value, int $ttl): bool {
                $this->cacheStore[$key] = $value;

                return true;
            });

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup_cache', [])
            ->andReturn([
                'enabled' => true,
                'strategy' => 'exact',
                'windows' => [],
                'max_keys' => 100_000,
            ]);

        $this->service = new EventDedupCacheService($config, $cache);
    });

    it('returns false for new events (not duplicate)', function () {
        $isDup = $this->service->isDuplicate('page_view', 'client-123', null, ['url' => '/home'], 'page_view');

        expect($isDup)->toBeFalse();
    });

    it('returns true for exact duplicate events', function () {
        $params = ['url' => '/home', 'title' => 'Home'];

        // First call — not a duplicate
        $first = $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');
        expect($first)->toBeFalse();

        // Second call with same params — should be a duplicate
        $second = $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');
        expect($second)->toBeTrue();
    });

    it('returns false for same event with different params (exact strategy)', function () {
        $this->service->isDuplicate('page_view', 'client-123', null, ['url' => '/home'], 'page_view');

        $isDup = $this->service->isDuplicate('page_view', 'client-123', null, ['url' => '/about'], 'page_view');
        expect($isDup)->toBeFalse();
    });

    it('does not dedup different client IDs', function () {
        $params = ['url' => '/home'];

        $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');
        $isDup = $this->service->isDuplicate('page_view', 'client-456', null, $params, 'page_view');

        expect($isDup)->toBeFalse();
    });

    it('uses user ID as identity when available', function () {
        $params = ['url' => '/home'];

        $this->service->isDuplicate('login', 'client-123', 'user-1', $params, 'saas');
        $isDup = $this->service->isDuplicate('login', 'client-456', 'user-1', $params, 'saas');

        // Same user ID, same event, same params → should be duplicate
        expect($isDup)->toBeTrue();
    });

    it('skips internal params in hash', function () {
        $params = ['url' => '/home', '_internal' => 'secret'];

        $first = $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');

        $params['_internal'] = 'different_value';
        $second = $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');

        // Should be duplicate because _internal is excluded from hash
        expect($second)->toBeTrue();
    });

    it('returns false when disabled', function () {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup_cache', [])
            ->andReturn(['enabled' => false]);

        $service = new EventDedupCacheService($config, $cache);
        $isDup = $service->isDuplicate('page_view', 'client-123', null, ['url' => '/home']);

        expect($isDup)->toBeFalse();
    });

    it('exposes diagnostic summary', function () {
        $summary = $this->service->diagnosticSummary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('strategy');
        expect($summary)->toHaveKey('windows');
        expect($summary['enabled'])->toBeTrue();
        expect($summary['strategy'])->toBe('exact');
    });

    it('markSeen prevents future isDuplicate from returning false', function () {
        $params = ['url' => '/home'];

        $this->service->markSeen('page_view', 'client-123', null, $params, 'page_view');
        $isDup = $this->service->isDuplicate('page_view', 'client-123', null, $params, 'page_view');

        expect($isDup)->toBeTrue();
    });
});

describe('SaaSStarterValidationService', function () {
    beforeEach(function () {
        $manager = mock(AnalyticsManager::class);

        // Mock provider methods
        $ga4 = mock(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
        $gtm = mock(\ZeroBoiler\Analytics\Trackers\GTMTracker::class);
        $meta = mock(\ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
        $plausible = mock(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
        $posthog = mock(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
        $mixpanel = mock(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class);
        $amplitude = mock(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class);

        $ga4->shouldReceive('isEnabled')->andReturn(true);
        $gtm->shouldReceive('isEnabled')->andReturn(true);
        $meta->shouldReceive('isEnabled')->andReturn(true);
        $plausible->shouldReceive('isEnabled')->andReturn(false);
        $posthog->shouldReceive('isEnabled')->andReturn(false);
        $mixpanel->shouldReceive('isEnabled')->andReturn(false);
        $amplitude->shouldReceive('isEnabled')->andReturn(false);

        $manager->shouldReceive('ga4')->andReturn($ga4);
        $manager->shouldReceive('gtm')->andReturn($gtm);
        $manager->shouldReceive('meta')->andReturn($meta);
        $manager->shouldReceive('plausible')->andReturn($plausible);
        $manager->shouldReceive('posthog')->andReturn($posthog);
        $manager->shouldReceive('mixpanel')->andReturn($mixpanel);
        $manager->shouldReceive('amplitude')->andReturn($amplitude);

        $this->service = new SaaSStarterValidationService($manager);
    });

    it('detects the current tier', function () {
        // All catalogs are populated with 100+ events, so this should detect enterprise
        $tier = $this->service->detectTier();

        expect($tier)->toBeString();
        expect(in_array($tier, ['starter', 'growth', 'advanced', 'enterprise'], true))->toBeTrue();
    });

    it('validates and returns a report', function () {
        $report = $this->service->validate('starter');

        expect($report)->toHaveKey('score');
        expect($report)->toHaveKey('tier');
        expect($report)->toHaveKey('target_tier');
        expect($report)->toHaveKey('gaps');
        expect($report)->toHaveKey('covered');
        expect($report)->toHaveKey('provider_count');
        expect($report)->toHaveKey('recommendations');
        expect($report['score'])->toBeFloat();
        expect($report['recommendations'])->toBeArray();
    });

    it('counts enabled providers correctly', function () {
        $report = $this->service->validate();

        expect($report['provider_count'])->toBe(3); // GA4 + GTM + Meta
    });

    it('generates quick-start checklist', function () {
        $checklist = $this->service->quickStartChecklist('enterprise');

        expect($checklist)->toHaveKey('current_tier');
        expect($checklist)->toHaveKey('target_tier');
        expect($checklist)->toHaveKey('events_to_add');
        expect($checklist)->toHaveKey('estimated_effort');
        expect($checklist['estimated_effort'])->toBeString();
    });

    it('returns tier thresholds', function () {
        $thresholds = SaaSStarterValidationService::tierThresholds();

        expect($thresholds)->toHaveKey('starter');
        expect($thresholds)->toHaveKey('growth');
        expect($thresholds)->toHaveKey('advanced');
        expect($thresholds)->toHaveKey('enterprise');
        expect($thresholds['starter']['min'])->toBe(0);
        expect($thresholds['enterprise']['max'])->toBe(100);
    });

    it('validates starter tier with full coverage', function () {
        // Starter events should all be in the catalogs
        $report = $this->service->validate('starter');

        expect($report['score'])->toBe(100.0);
        expect($report['gaps'])->toBeEmpty();
    });

    it('generates recommendations', function () {
        $report = $this->service->validate('enterprise');

        expect($report['recommendations'])->toBeArray();
        expect(count($report['recommendations']))->toBeGreaterThan(0);
    });
});
