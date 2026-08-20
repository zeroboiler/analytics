<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Queue\EventBackpressureService;
use ZeroBoiler\Analytics\Services\LifecycleMappingValidator;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

beforeEach(function (): void {
    // Ensure no leftover cache state
    cache()->clear();
});

describe('LifecycleMappingValidator', function (): void {
    test('validates empty custom mappings with zero issues', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeTrue();
        expect($validator->getErrorCount())->toBe(0);
        expect($validator->getIssues())->toBe([]);
        expect($validator->summary()['valid'])->toBeTrue();
    });

    test('detects missing source field', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'team.invited' => [
                        'target' => SignUpEvent::class,
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeFalse();
        expect($validator->getErrorCount())->toBe(1);
        expect($validator->getIssues()[0]['severity'])->toBe('error');
        expect($validator->getIssues()[0]['key'])->toBe('team.invited');
        expect($validator->getIssues()[0]['message'])->toContain('source');
    });

    test('detects missing target field', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'team.invited' => [
                        'source' => 'team.invited',
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeFalse();
        expect($validator->getErrorCount())->toBe(1);
    });

    test('detects non-existent target class', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'team.invited' => [
                        'source' => 'team.invited',
                        'target' => 'App\\Analytics\\NonExistentEvent',
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeFalse();
        expect($validator->getErrorCount())->toBe(1);
        expect($validator->getIssues()[0]['message'])->toContain('does not exist');
    });

    test('detects target class that does not extend AnalyticsEvent', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'team.invited' => [
                        'source' => 'team.invited',
                        'target' => \stdClass::class,
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeFalse();
        expect($validator->getErrorCount())->toBe(1);
        expect($validator->getIssues()[0]['message'])->toContain('does not extend');
    });

    test('accepts valid custom mappings', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'team.invited' => [
                        'source' => 'team.invited',
                        'target' => SignUpEvent::class,
                        'params_extractor' => 'extractTeamParams',
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeTrue();
        expect($validator->getErrorCount())->toBe(0);
    });

    test('warns on non-standard key naming', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'INVALID-KEY' => [
                        'source' => 'custom.event',
                        'target' => LoginEvent::class,
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        expect($validator->isValid())->toBeTrue();
        expect($validator->getWarningCount())->toBe(1);
        expect($validator->getIssues()[0]['severity'])->toBe('warning');
        expect($validator->getIssues()[0]['message'])->toContain('pattern');
    });

    test('static validateMapping checks a single mapping', function (): void {
        $issues = LifecycleMappingValidator::validateMapping(
            'test.event',
            ['source' => 'test.event', 'target' => 'NonExistentClass'],
        );

        expect($issues)->not->toBeEmpty();
        expect($issues[0]['severity'])->toBe('error');

        $valid = LifecycleMappingValidator::validateMapping(
            'test.event',
            ['source' => 'test.event', 'target' => PurchaseEvent::class],
        );

        expect($valid)->toBe([]);
    });

    test('summary returns structured result', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);
        $summary = $validator->summary();

        expect($summary)->toHaveKeys(['valid', 'errors', 'warnings', 'info', 'total_issues', 'issues']);
        expect($summary['valid'])->toBeTrue();
        expect($summary['errors'])->toBe(0);
    });

    test('counts errors, warnings, and info separately', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'bad-key' => [
                        'source' => 'custom.source',
                        'target' => SignUpEvent::class,
                        'params_extractor' => 'customExtractor',
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        // bad-key triggers warning for naming convention
        // customExtractor triggers info for non-built-in extractor
        expect($validator->getErrorCount())->toBe(0);
        expect($validator->getWarningCount())->toBeGreaterThanOrEqual(1);
        expect($validator->getInfoCount())->toBeGreaterThanOrEqual(1);
    });
});

describe('EventBackpressureService', function (): void {
    test('allows events when enabled and under limits', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(0);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 10,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);
        $event = new AnalyticsEvent(name: 'test_event', params: [], clientId: 'client-123');

        expect($service->allow($event))->toBeTrue();
        expect($service->getRejectedCount())->toBe(0);
    });

    test('bypasses when disabled', function (): void {
        $cache = mock(CacheRepository::class);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn(['enabled' => false]);

        $service = new EventBackpressureService($cache, $config);
        $event = new AnalyticsEvent(name: 'test_event', params: [], clientId: 'client-123');

        expect($service->allow($event))->toBeTrue();
    });

    test('circuit breaker trips after threshold failures', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 3,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);

        // Record failures up to threshold
        $service->recordFailure();
        $service->recordFailure();
        expect($service->isCircuitBreakerOpen())->toBeFalse();

        $service->recordFailure();
        expect($service->isCircuitBreakerOpen())->toBeTrue();
    });

    test('circuit breaker resets on success', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 3,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);

        $service->recordFailure();
        $service->recordFailure();
        $service->recordFailure();
        expect($service->isCircuitBreakerOpen())->toBeTrue();

        $service->recordSuccess();
        expect($service->isCircuitBreakerOpen())->toBeFalse();
    });

    test('rejects events when circuit breaker is open', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 1,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);
        $event = new AnalyticsEvent(name: 'test_event', params: [], clientId: 'client-123');

        $service->recordFailure();
        expect($service->isCircuitBreakerOpen())->toBeTrue();
        expect($service->allow($event))->toBeFalse();
        expect($service->getRejectedCount())->toBe(1);
    });

    test('manual reset clears circuit breaker', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 1,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);

        $service->recordFailure();
        expect($service->isCircuitBreakerOpen())->toBeTrue();

        $service->resetCircuitBreaker();
        expect($service->isCircuitBreakerOpen())->toBeFalse();
        expect($service->circuitBreakerState()['open'])->toBeFalse();
        expect($service->circuitBreakerState()['consecutive_failures'])->toBe(0);
    });

    test('summary returns complete diagnostic structure', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 5,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);
        $summary = $service->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('max_events_per_minute');
        expect($summary)->toHaveKey('max_global_per_second');
        expect($summary)->toHaveKey('circuit_breaker');
        expect($summary)->toHaveKey('rejected_this_request');
        expect($summary['enabled'])->toBeTrue();
        expect($summary['max_events_per_minute'])->toBe(600);
        expect($summary['circuit_breaker']['threshold'])->toBe(5);
    });

    test('circuitBreakerState includes resets_in field when open', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->with('zb_bp_circuit_breaker')
            ->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.backpressure', [])
            ->andReturn([
                'enabled' => true,
                'max_events_per_minute' => 600,
                'max_global_per_second' => 100,
                'circuit_breaker_threshold' => 1,
                'circuit_breaker_reset_seconds' => 60,
                'cache_prefix' => 'zb_bp_',
            ]);

        $service = new EventBackpressureService($cache, $config);

        $service->recordFailure();
        $state = $service->circuitBreakerState();

        expect($state['open'])->toBeTrue();
        expect($state['tripped_at'])->not->toBeNull();
        expect($state['resets_in'])->toBeInt();
        expect($state['resets_in'])->toBeGreaterThan(0);
    });
});

describe('LifecycleEventMapper DEFAULT_COUNT constant', function (): void {
    test('DEFAULT_COUNT matches DEFAULT_MAPPING_COUNT', function (): void {
        expect(LifecycleEventMapper::DEFAULT_COUNT)
            ->toBe(LifecycleEventMapper::DEFAULT_MAPPING_COUNT);
    });
});
