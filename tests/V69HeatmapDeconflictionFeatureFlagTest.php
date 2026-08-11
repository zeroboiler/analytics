<?php

declare(strict_types=1);

/**
 * v2.69.0 SaaS Starter Upgrade: Heatmap, Deconfliction, Schema Inference,
 * Rate Limit Dashboard, and Feature Flag Integration tests.
 *
 * @license MIT
 * @version 10.3.0
 * @package ZeroBoiler\Analytics
 */

use ZeroBoiler\Analytics\Services\HeatmapAggregationService;
use ZeroBoiler\Analytics\Services\EventDeconflictionService;
use ZeroBoiler\Analytics\Services\EventSchemaInferenceService;
use ZeroBoiler\Analytics\Services\AnalyticsRateLimitDashboardService;
use ZeroBoiler\Analytics\Services\FeatureFlagIntegrationService;

beforeEach(function () {
    $this->manager = Mockery::mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $this->manager->shouldReceive('config')->andReturnNull();
    $this->manager->shouldReceive('metrics')->andReturn([]);
    $this->cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $this->cache->shouldReceive('get')->andReturnNull();
    $this->cache->shouldReceive('put')->andReturnNull();
});

afterEach(function () {
    Mockery::close();
});

// ─── HeatmapAggregationService ──────────────────────────────────────

describe('HeatmapAggregationService', function () {
    it('initializes with enabled flag', function () {
        $service = new HeatmapAggregationService(
            cache: $this->cache,
            enabled: true,
        );

        expect($service->isEnabled())->toBeTrue();
    });

    it('initializes disabled', function () {
        $service = new HeatmapAggregationService(
            cache: $this->cache,
            enabled: false,
        );

        expect($service->isEnabled())->toBeFalse();
    });

    it('returns summary with correct structure', function () {
        $this->cache->shouldReceive('get')->andReturn(null);

        $service = new HeatmapAggregationService(
            cache: $this->cache,
            enabled: true,
        );

        $summary = $service->getSummary();

        expect($summary)->toHaveKey('enabled');
        expect($summary['enabled'])->toBeTrue();
    });
});

// ─── EventDeconflictionService ───────────────────────────────────────

describe('EventDeconflictionService', function () {
    it('returns analysis with zero conflicts for empty catalog', function () {
        $this->manager->shouldReceive('config')->with('providers', [])->andReturn([]);
        $this->manager->shouldReceive('getEventCatalog')->andReturn([]);

        $service = new EventDeconflictionService($this->manager);
        $result = $service->analyze();

        expect($result)->toHaveKey('summary');
        expect($result['summary'])->toHaveKey('total_conflicts');
        expect($result['summary']['total_conflicts'])->toBe(0);
    });

    it('has analyze method returning array with conflicts key', function () {
        $this->manager->shouldReceive('getEventCatalog')->andReturn([]);

        $service = new EventDeconflictionService($this->manager);
        $result = $service->analyze();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('conflicts');
        expect($result)->toHaveKey('warnings');
        expect($result)->toHaveKey('summary');
    });
});

// ─── EventSchemaInferenceService ─────────────────────────────────────

describe('EventSchemaInferenceService', function () {
    it('returns inference result with inferred_count', function () {
        $schemaBuilder = Mockery::mock(\ZeroBoiler\Analytics\Schema\EventPropertySchema::class);

        $service = new EventSchemaInferenceService($schemaBuilder);
        $result = $service->inferAll();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('inferred_count');
        expect($result)->toHaveKey('schemas');
    });
});

// ─── AnalyticsRateLimitDashboardService ─────────────────────────────

describe('AnalyticsRateLimitDashboardService', function () {
    it('returns dashboard with enabled key', function () {
        $this->cache->shouldReceive('get')->andReturn(null);

        $service = new AnalyticsRateLimitDashboardService(
            cache: $this->cache,
            metrics: [],
        );

        $dashboard = $service->getDashboard();

        expect($dashboard)->toBeArray();
        expect($dashboard)->toHaveKey('enabled');
    });

    it('returns client status with zero counters', function () {
        $this->cache->shouldReceive('get')->andReturn(null);

        $service = new AnalyticsRateLimitDashboardService(
            cache: $this->cache,
            metrics: [],
        );

        $status = $service->getClientStatus('test-client-123');

        expect($status)->toBeArray();
        expect($status)->toHaveKey('client_id');
        expect($status['client_id'])->toBe('test-client-123');
    });
});

// ─── FeatureFlagIntegrationService ───────────────────────────────────

describe('FeatureFlagIntegrationService', function () {
    it('initializes with default values', function () {
        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: [],
            enabled: true,
        );

        expect($service->isEnabled())->toBeTrue();
        expect($service->getFlags())->toBe([]);
        expect($service->hasFlag('nonexistent'))->toBeFalse();
    });

    it('returns true for all flags when disabled', function () {
        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: [],
            enabled: false,
        );

        expect($service->evaluate('any_flag', 'user-1'))->toBeTrue();
    });

    it('stores and retrieves flags', function () {
        $flags = [
            'new_checkout' => ['enabled' => true, 'rollout_percent' => 100],
        ];

        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: $flags,
        );

        expect($service->hasFlag('new_checkout'))->toBeTrue();
        expect($service->getFlag('new_checkout'))->toBe($flags['new_checkout']);
    });

    it('returns summary with correct structure', function () {
        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: ['flag_a' => true, 'flag_b' => false],
        );

        $summary = $service->summary();

        expect($summary)->toBeArray();
        expect($summary['flags_count'])->toBe(2);
        expect($summary['enabled'])->toBeTrue();
        expect($summary['resolver_registered'])->toBeFalse();
    });

    it('deterministic rollout hashing returns consistent results', function () {
        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: [],
        );

        // 100% rollout should always be true
        expect($service->isInRollout('flag', 'user-1', 100))->toBeTrue();
        // 0% rollout should always be false
        expect($service->isInRollout('flag', 'user-1', 0))->toBeFalse();

        // Same user + same flag should always return same result (deterministic)
        $result1 = $service->isInRollout('flag', 'user-42', 50);
        $result2 = $service->isInRollout('flag', 'user-42', 50);
        expect($result1)->toBe($result2);
    });

    it('returns correlation payload with on/off values', function () {
        $flags = ['flag_a' => true, 'flag_b' => false];

        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: $flags,
        );

        $payload = $service->getCorrelationPayload('user-1');

        expect($payload)->toBeArray();
        expect($payload)->toHaveKey('flag_a');
        expect($payload)->toHaveKey('flag_b');
        expect(in_array($payload['flag_a'], ['on', 'off']))->toBeTrue();
        expect(in_array($payload['flag_b'], ['on', 'off']))->toBeTrue();
    });

    it('should dispatch events when no gates are configured', function () {
        $this->manager->shouldReceive('config')->with('feature_flags.gates', [])->andReturn([]);

        $service = new FeatureFlagIntegrationService(
            manager: $this->manager,
            cache: $this->cache,
            flags: [],
        );

        expect($service->shouldDispatchEvent('page_view', 'user-1'))->toBeTrue();
    });
});
