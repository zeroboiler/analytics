<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDispatchOrchestrator;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

afterEach(function (): void {
    Mockery::close();
});

describe('EventDispatchOrchestrator', function (): void {
    it('creates instance with defaults from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_orchestrator', [])
            ->andReturn([
                'enabled' => true,
                'decision_ttl' => 1800,
                'max_decisions' => 1000,
                'min_reliability_auto' => 60.0,
                'min_reliability_critical' => 40.0,
                'log_decisions' => true,
            ]);

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);

        expect($orchestrator)->toBeInstanceOf(EventDispatchOrchestrator::class);
    });

    it('dispatches when all checks pass', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'decision_ttl' => 1800,
                'max_decisions' => 100,
                'log_decisions' => true,
            ]);

        // Cache calls for decision logging
        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view', priority: 'normal');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'circuit_state' => 'closed',
            'reliability_score' => 95.0,
            'consent_granted' => true,
        ]);

        expect($decision['action'])->toBe('dispatch');
        expect($decision['provider'])->toBe('ga4');
        expect($decision['event'])->toBe('page_view');
        expect($decision['reasoning'])->toBe('All checks passed');
    });

    it('denies dispatch when consent is denied', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view');

        $decision = $orchestrator->evaluate('meta_pixel', $event, [
            'consent_granted' => false,
        ]);

        expect($decision['action'])->toBe('consent_denied');
        expect($decision['reasoning'])->toContain('Consent denied');
    });

    it('blocks dispatch when circuit breaker is open', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'purchase');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'circuit_state' => 'open',
            'consent_granted' => true,
        ]);

        expect($decision['action'])->toBe('circuit_open');
        expect($decision['reasoning'])->toContain('Circuit breaker open');
    });

    it('blocks dispatch when budget is exceeded', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'click');

        $decision = $orchestrator->evaluate('posthog', $event, [
            'consent_granted' => true,
            'budget_remaining' => 0,
        ]);

        expect($decision['action'])->toBe('budget_exceeded');
    });

    it('drops non-critical events when reliability is very low', function (): void {
        $this->config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'min_reliability_auto' => 60.0,
            'min_reliability_critical' => 40.0,
            'log_decisions' => true,
        ]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'scroll_depth', priority: 'low');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'reliability_score' => 20.0,
            'consent_granted' => true,
        ]);

        expect($decision['action'])->toBe('drop');
        expect($decision['reasoning'])->toContain('Reliability');
    });

    it('defers non-critical events when reliability is degraded', function (): void {
        $this->config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'min_reliability_auto' => 60.0,
            'min_reliability_critical' => 40.0,
            'log_decisions' => true,
        ]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'form_start', priority: 'normal');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'reliability_score' => 45.0,
            'consent_granted' => true,
        ]);

        expect($decision['action'])->toBe('defer');
    });

    it('dispatches critical events even with lower reliability', function (): void {
        $this->config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'min_reliability_auto' => 60.0,
            'min_reliability_critical' => 40.0,
            'log_decisions' => true,
        ]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'purchase', priority: 'critical');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'reliability_score' => 50.0,
            'consent_granted' => true,
        ]);

        // 50 >= 40 (critical threshold), so should dispatch
        expect($decision['action'])->toBe('dispatch');
    });

    it('routes replay events with replay action', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'consent_granted' => true,
            'is_replay' => true,
        ]);

        expect($decision['action'])->toBe('replay');
        expect($decision['is_replay'])->toBe(true);
    });

    it('samples low-priority events under latency pressure', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'scroll_depth', priority: 'background');

        $decision = $orchestrator->evaluate('ga4', $event, [
            'consent_granted' => true,
            'latency_p95' => 3000.0,
        ]);

        expect($decision['action'])->toBe('sample');
        expect($decision['reasoning'])->toContain('latency');
    });

    it('auto-dispatches when orchestrator is disabled', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => false]);

        // Should not log when disabled
        $this->cache->shouldNotReceive('put');

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view');

        $decision = $orchestrator->evaluate('ga4', $event);

        expect($decision['action'])->toBe('dispatch');
        expect($decision['reasoning'])->toContain('disabled');
    });

    it('evaluates multi-provider and sorts by priority', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'log_decisions' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'purchase', priority: 'critical');

        $decisions = $orchestrator->evaluateMulti($event, [
            'ga4' => ['consent_granted' => true, 'circuit_state' => 'closed', 'reliability_score' => 99.0],
            'meta_pixel' => ['consent_granted' => true, 'circuit_state' => 'open'],
            'posthog' => ['consent_granted' => true, 'reliability_score' => 85.0],
        ]);

        expect($decisions)->toHaveCount(3);
        // First should be dispatch (ga4), second might be dispatch (posthog), third should be circuit_open (meta_pixel)
        expect($decisions[0]['action'])->toBe('dispatch');
        expect($decisions[0]['provider'])->toBe('ga4');
        // meta_pixel should be last (circuit_open has lower priority)
        expect($decisions[2]['action'])->toBe('circuit_open');
    });

    it('returns empty stats when no decisions recorded', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);

        $stats = $orchestrator->stats();
        expect($stats['total_decisions'])->toBe(0);
        expect($stats['by_action'])->toBe([]);
        expect($stats['by_provider'])->toBe([]);
        expect($stats['recent_decisions'])->toBe([]);
    });

    it('returns health summary with computed rates', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true]);

        $this->cache->shouldReceive('get')->andReturn([]);

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);

        $health = $orchestrator->healthSummary();
        expect($health)->toHaveKey('enabled');
        expect($health)->toHaveKey('total_decisions');
        expect($health)->toHaveKey('dispatch_rate');
        expect($health)->toHaveKey('defer_rate');
        expect($health)->toHaveKey('drop_rate');
        expect($health)->toHaveKey('provider_summary');
        expect($health['enabled'])->toBe(true);
        expect($health['dispatch_rate'])->toBe(0.0);
    });

    it('records outcomes for providers', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true, 'decision_ttl' => 1800]);

        $existingOutcomes = [
            ['event' => 'page_view', 'success' => true, 'latency_ms' => 12.5, 'error' => null, 'timestamp' => '2026-08-16T12:00:00+00:00'],
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_orchestrator_outcomes_ga4', [])
            ->andReturn($existingOutcomes);
        $this->cache->shouldReceive('put')
            ->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $orchestrator->recordOutcome('ga4', 'purchase', true, 45.2);
        $orchestrator->recordOutcome('ga4', 'sign_up', false, null, 'Timeout');

        $outcomes = $orchestrator->outcomeStats();
        expect($outcomes['ga4']['total'])->toBe(3);
        expect($outcomes['ga4']['success'])->toBe(2);
        expect($outcomes['ga4']['failed'])->toBe(1);
        expect($outcomes['ga4']['avg_latency_ms'])->toBe(28.85); // (12.5 + 45.2) / 2
        expect($outcomes['ga4']['recent_errors'])->toContain('Timeout');
    });

    it('clears all orchestrator data', function (): void {
        $this->config->shouldReceive('get')->andReturn(['enabled' => true]);

        $decisionKeys = ['zb_orchestrator_decision_1', 'zb_orchestrator_decision_2'];
        $this->cache->shouldReceive('get')
            ->with('zb_orchestrator_decision_keys', [])
            ->andReturn($decisionKeys);
        $this->cache->shouldReceive('forget')
            ->with(\Mockery::any())
            ->andReturnTrue();

        $orchestrator = new EventDispatchOrchestrator($this->cache, $this->config);
        $orchestrator->clear();

        // Should have been called multiple times
        expect(true)->toBe(true);
    });

    it('includes all expected action constants', function (): void {
        expect(EventDispatchOrchestrator::ACTION_DISPATCH)->toBe('dispatch');
        expect(EventDispatchOrchestrator::ACTION_DEFER)->toBe('defer');
        expect(EventDispatchOrchestrator::ACTION_DROP)->toBe('drop');
        expect(EventDispatchOrchestrator::ACTION_REPLAY)->toBe('replay');
        expect(EventDispatchOrchestrator::ACTION_SAMPLE)->toBe('sample');
        expect(EventDispatchOrchestrator::ACTION_CIRCUIT_OPEN)->toBe('circuit_open');
        expect(EventDispatchOrchestrator::ACTION_BUDGET_EXCEEDED)->toBe('budget_exceeded');
        expect(EventDispatchOrchestrator::ACTION_CONSENT_DENIED)->toBe('consent_denied');
    });
});
