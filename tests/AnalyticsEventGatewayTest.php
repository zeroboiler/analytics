<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsEventGateway;
use ZeroBoiler\Analytics\Services\EventContractTestingService;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\ProviderCircuitBreaker;
use ZeroBoiler\Analytics\Services\ProviderRateLimitService;

beforeEach(function (): void {
    $this->cache = app('cache')->store();
    $this->config = app('config');

    // Stub services that gateway depends on
    $this->dispatcher = Mockery::mock(AnalyticsEventDispatcher::class);
    $this->dedup = Mockery::mock(EventDeduplicationService::class);
    $this->circuitBreaker = Mockery::mock(ProviderCircuitBreaker::class);
    $this->rateLimiter = Mockery::mock(ProviderRateLimitService::class);

    // Default: all providers have capacity
    $this->circuitBreaker->shouldReceive('isOpen')->andReturn(false)->byDefault();
    $this->rateLimiter->shouldReceive('check')->andReturn(true)->byDefault();

    $this->gateway = new AnalyticsEventGateway(
        cache: $this->cache,
        config: $this->config,
        dispatcher: $this->dispatcher,
        dedup: $this->dedup,
        circuitBreaker: $this->circuitBreaker,
        rateLimiter: $this->rateLimiter,
    );
});

afterEach(function (): void {
    Mockery::close();
});

describe('AnalyticsEventGateway', function (): void {
    it('processes a valid event successfully', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: ['page_location' => '/test']);

        $this->dedup->shouldReceive('isDuplicate')->andReturn(false);
        $this->dispatcher->shouldReceive('dispatch')->andReturn(true);

        $result = $this->gateway->process($event);

        expect($result['success'])->toBeTrue();
        expect($result)->toHaveKey('trace_id');
        expect($result)->toHaveKey('trace_span');
        expect($result['metrics']['total_inbound'])->toBe(1);
        expect($result['metrics']['total_dispatched'])->toBe(1);
    });

    it('rejects events with empty names', function (): void {
        $event = new AnalyticsEvent(name: '', params: []);

        $result = $this->gateway->process($event);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toBe('Event name is required');
        expect($result['metrics']['total_rejected'])->toBe(1);
    });

    it('rejects events with invalid name format', function (): void {
        $event = new AnalyticsEvent(name: 'Invalid-Name!', params: []);

        $result = $this->gateway->process($event);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('Event name must start with a lowercase letter');
    });

    it('deduplicates events when detected', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: ['target' => 'button']);

        $this->dedup->shouldReceive('isDuplicate')->andReturn(true);

        $result = $this->gateway->process($event);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toBe('Duplicate event detected');
        expect($result['metrics']['total_deduplicated'])->toBe(1);
    });

    it('returns metrics with rates', function (): void {
        $metrics = $this->gateway->metrics();

        expect($metrics)->toHaveKeys([
            'total_inbound', 'total_dispatched', 'total_rejected',
            'total_deduplicated', 'total_rate_limited', 'total_capacity_rejected',
            'dispatch_rate', 'rejection_rate',
        ]);
        expect($metrics['dispatch_rate'])->toBeFloat();
        expect($metrics['rejection_rate'])->toBeFloat();
    });

    it('resets metrics', function (): void {
        // Process one event first
        $event = new AnalyticsEvent(name: 'test', params: []);
        $this->dedup->shouldReceive('isDuplicate')->andReturn(false);
        $this->dispatcher->shouldReceive('dispatch')->andReturn(true);
        $this->gateway->process($event);

        $this->gateway->resetMetrics();
        $metrics = $this->gateway->metrics();

        expect($metrics['total_inbound'])->toBe(0);
        expect($metrics['total_dispatched'])->toBe(0);
    });

    it('processes batch events with stats', function (): void {
        $events = [
            new AnalyticsEvent(name: 'page_view', params: []),
            new AnalyticsEvent(name: 'click', params: []),
            new AnalyticsEvent(name: 'sign_up', params: []),
        ];

        $this->dedup->shouldReceive('isDuplicate')->andReturn(false);
        $this->dispatcher->shouldReceive('dispatch')->andReturn(true);

        $result = $this->gateway->processBatch($events);

        expect($result['dispatched'])->toBe(3);
        expect($result['rejected'])->toBe(0);
        expect($result['results'])->toHaveCount(3);
        expect($result['metrics']['total_inbound'])->toBe(3);
    });

    it('reports catalog enforcement rejection', function (): void {
        $event = new AnalyticsEvent(name: 'nonexistent_event_xyz', params: []);

        $this->dedup->shouldReceive('isDuplicate')->andReturn(false);

        $result = $this->gateway->process($event);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('not registered in the event catalog');
    });

    it('skips gateway when skip_gateway option is true', function (): void {
        $event = new AnalyticsEvent(name: 'any_event', params: []);

        $this->dispatcher->shouldReceive('dispatch')->with($event, ['skip_gateway' => true])->andReturn(true);

        $result = $this->gateway->process($event, ['skip_gateway' => true]);

        expect($result['success'])->toBeTrue();
        expect($result['metrics']['total_dispatched'])->toBe(1);
    });

    it('provides config summary', function (): void {
        $summary = $this->gateway->configSummary();

        expect($summary)->toHaveKeys([
            'enabled', 'enforce_catalog', 'inject_trace', 'dedup_enabled',
            'dedup_window', 'global_rate_limit', 'global_rate_window',
            'per_event_rate_limit', 'per_event_rate_window',
            'max_params_size', 'max_name_length', 'metrics_enabled',
        ]);
    });

    it('reports enabled state', function (): void {
        expect($this->gateway->isEnabled())->toBeBool();
    });
});

describe('EventContractTestingService', function (): void {
    beforeEach(function (): void {
        $this->contracts = new EventContractTestingService(
            cache: app('cache')->store(),
            config: app('config'),
        );
    });

    it('validates purchase event against GA4 contract', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [['item_id' => 'P1', 'price' => 99.99, 'quantity' => 1]],
        ]);

        $result = $this->contracts->validate($event, 'ga4');

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
        expect($result['coverage'])->toBe(1.0);
    });

    it('detects missing required fields for GA4 purchase', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $result = $this->contracts->validate($event, 'ga4');

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
        expect($result['coverage'])->toBeLessThan(1.0);
    });

    it('validates page_view against GA4 contract', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: [
            'page_location' => 'https://example.com/page',
        ]);

        $result = $this->contracts->validate($event, 'ga4');

        expect($result['valid'])->toBeTrue();
    });

    it('returns valid with warnings when no contract exists (non-strict)', function (): void {
        $event = new AnalyticsEvent(name: 'custom_event_xyz', params: []);

        $result = $this->contracts->validate($event, 'ga4');

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->not->toBeEmpty();
    });

    it('validates event against all providers', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
        ]);

        $results = $this->contracts->validateAllProviders($event);

        expect($results)->toHaveKey('ga4');
        expect($results)->toHaveKey('meta');
        expect($results['ga4']['valid'])->toBeTrue();
    });

    it('provides coverage analysis', function (): void {
        $analysis = $this->contracts->coverageAnalysis();

        expect($analysis)->toHaveKeys(['providers', 'overall', 'missing']);
        expect($analysis['providers'])->toHaveKey('ga4');
        expect($analysis['providers'])->toHaveKey('meta');
        expect($analysis['overall'])->toBeFloat();
    });

    it('registers custom contracts', function (): void {
        $this->contracts->registerContract('custom_event', 'ga4', [
            'required' => ['user_id'],
            'optional' => ['source'],
            'type_rules' => ['user_id' => 'string'],
        ]);

        $contract = $this->contracts->getContract('custom_event', 'ga4');

        expect($contract)->not->toBeNull();
        expect($contract['required'])->toContain('user_id');
    });

    it('returns null for undefined contracts', function (): void {
        $contract = $this->contracts->getContract('nonexistent', 'ga4');

        expect($contract)->toBeNull();
    });

    it('reports supported providers list', function (): void {
        $providers = $this->contracts->getSupportedProviders();

        expect($providers)->toContain('ga4');
        expect($providers)->toContain('meta');
        expect($providers)->toContain('gtm');
        expect($providers)->toContain('plausible');
        expect($providers)->toContain('posthog');
    });

    it('reports contract count', function (): void {
        $count = $this->contracts->contractCount();

        expect($count)->toBeInt();
        expect($count)->toBeGreaterThan(0);
    });

    it('validates type rules correctly', function (): void {
        // Event with wrong type for a required field
        $event = new AnalyticsEvent(name: 'purchase', params: [
            'transaction_id' => 'TXN-001',
            'value' => 'not-a-number', // Should be number
            'currency' => 'USD',
        ]);

        $result = $this->contracts->validate($event, 'ga4');

        expect($result['valid'])->toBeFalse();
        $typeErrors = array_filter($result['errors'], fn (string $e): bool => str_contains($e, 'type'));
        expect($typeErrors)->not->toBeEmpty();
    });

    it('detects missing required field for Meta purchase contract', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $result = $this->contracts->validate($event, 'meta');

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });
});
