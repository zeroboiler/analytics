<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\CostForecastProjection;
use ZeroBoiler\Analytics\DTO\PolicyViolation;
use ZeroBoiler\Analytics\DTO\ProviderSLARecord;
use ZeroBoiler\Analytics\Services\AnalyticsCostForecastService;
use ZeroBoiler\Analytics\Services\EventPolicyEngine;
use ZeroBoiler\Analytics\Services\ProviderSLAMonitor;

beforeEach(function () {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

afterEach(function () {
    Mockery::close();
});

describe('ProviderSLAMonitor', function () {
    it('constructs with default configuration', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.9,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => true,
                'max_breach_history' => 1000,
                'monitored_providers' => ['ga4', 'meta_pixel'],
                'providers' => [],
            ]);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor)->toBeInstanceOf(ProviderSLAMonitor::class);
    });

    it('returns null currentSLA when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn(['enabled' => false]);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor->currentSLA('ga4'))->toBeNull();
    });

    it('returns null for provider with no dispatch data', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.9,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => false,
                'max_breach_history' => 100,
                'monitored_providers' => ['ga4'],
                'providers' => [],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturn(null);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor->currentSLA('ga4'))->toBeNull();
    });

    it('builds SLA record from accumulated dispatches', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.0,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => false,
                'max_breach_history' => 100,
                'monitored_providers' => ['ga4'],
                'providers' => [],
            ]);

        $windowKey = Mockery::pattern('/^zb_sla_monitor_ga4_/');

        $this->cache->shouldReceive('get')
            ->with($windowKey, null)
            ->andReturn([
                'total' => 100,
                'success' => 99,
                'failed' => 1,
                'latencies' => array_fill(0, 100, 100.0),
            ])
            ->once();

        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);
        $record = $monitor->currentSLA('ga4');

        expect($record)->not->toBeNull();
        expect($record->provider)->toBe('ga4');
        expect($record->totalDispatches)->toBe(100);
        expect($record->successfulDispatches)->toBe(99);
        expect($record->failedDispatches)->toBe(1);
        expect($record->slaMet)->toBeTrue();
        expect($record->uptimePercentage)->toBeGreaterThanOrEqual(99.0);
    });

    it('computes failure rate correctly', function () {
        $record = new ProviderSLARecord(
            provider: 'ga4',
            window: 'test',
            totalDispatches: 1000,
            successfulDispatches: 950,
            failedDispatches: 50,
            avgLatencyMs: 120.0,
            p99LatencyMs: 500.0,
            uptimePercentage: 95.0,
            breachCount: 0,
            slaMet: true,
        );

        expect($record->failureRate())->toBe(5.0);
        expect($record->successRate())->toBe(95.0);
    });

    it('serializes to array with all fields', function () {
        $record = new ProviderSLARecord(
            provider: 'meta_pixel',
            window: '2026-08-14',
            totalDispatches: 500,
            successfulDispatches: 500,
            failedDispatches: 0,
            avgLatencyMs: 50.0,
            p99LatencyMs: 200.0,
            uptimePercentage: 100.0,
            breachCount: 0,
            slaMet: true,
        );

        $arr = $record->toArray();

        expect($arr)->toHaveKey('provider');
        expect($arr)->toHaveKey('window');
        expect($arr)->toHaveKey('total_dispatches');
        expect($arr)->toHaveKey('failure_rate');
        expect($arr)->toHaveKey('success_rate');
        expect($arr['provider'])->toBe('meta_pixel');
    });

    it('returns empty allCurrentSLA when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn(['enabled' => false]);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor->allCurrentSLA())->toBeEmpty();
    });

    it('returns 100% compliance for zero history', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.9,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => false,
                'max_breach_history' => 100,
                'monitored_providers' => ['ga4'],
                'providers' => [],
            ]);

        $this->cache->shouldReceive('get')->andReturn(null);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor->compliancePercentage('ga4', 24))->toBe(100.0);
    });

    it('returns empty breach history when no breaches', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.9,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => false,
                'max_breach_history' => 100,
                'monitored_providers' => ['ga4'],
                'providers' => [],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_sla_breaches')
            ->andReturn(null);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);

        expect($monitor->breachHistory())->toBeEmpty();
    });

    it('returns health matrix with provider summaries', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sla_monitor', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 3600,
                'retention_windows' => 168,
                'default_uptime_target' => 99.9,
                'default_latency_target' => 500.0,
                'default_p99_latency_target' => 2000.0,
                'default_error_budget' => 10,
                'alert_on_breach' => false,
                'max_breach_history' => 100,
                'monitored_providers' => ['ga4', 'posthog'],
                'providers' => [],
            ]);

        // All cache gets return null (no data)
        $this->cache->shouldReceive('get')->andReturn(null);

        $monitor = new ProviderSLAMonitor($this->cache, $this->config);
        $matrix = $monitor->healthMatrix();

        expect($matrix)->toHaveKey('providers');
        expect($matrix)->toHaveKey('summary');
        expect($matrix['summary']['total_providers'])->toBe(2);
    });
});

describe('AnalyticsCostForecastService', function () {
    it('constructs with default configuration', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'currency' => 'USD',
                'history_months' => 3,
                'projection_months' => 3,
                'growth_cap' => 50.0,
                'alert_on_exceeds_budget' => true,
                'monthly_budget' => 1000.0,
                'cache_ttl' => 3600,
                'providers' => ['posthog' => 6.25],
            ]);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);

        expect($service)->toBeInstanceOf(AnalyticsCostForecastService::class);
    });

    it('returns null forecast when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn(['enabled' => false]);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);

        expect($service->forecast('posthog'))->toBeNull();
    });

    it('returns null forecast for unconfigured provider', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'currency' => 'USD',
                'history_months' => 3,
                'projection_months' => 3,
                'growth_cap' => 50.0,
                'alert_on_exceeds_budget' => false,
                'monthly_budget' => 1000.0,
                'cache_ttl' => 3600,
                'providers' => [],
            ]);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);

        expect($service->forecast('unknown_provider'))->toBeNull();
    });

    it('generates forecast with synthetic history', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'currency' => 'USD',
                'history_months' => 3,
                'projection_months' => 3,
                'growth_cap' => 50.0,
                'alert_on_exceeds_budget' => false,
                'monthly_budget' => 1000.0,
                'cache_ttl' => 3600,
                'providers' => ['posthog' => 6.25],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturn(null);
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);
        $projection = $service->forecast('posthog');

        expect($projection)->not->toBeNull();
        expect($projection)->toBeInstanceOf(CostForecastProjection::class);
        expect($projection->provider)->toBe('posthog');
        expect($projection->projectedCost)->toBeGreaterThan(0.0);
        expect($projection->projectedEvents)->toBeGreaterThan(0);
    });

    it('computes cost change percentage correctly', function () {
        $projection = new CostForecastProjection(
            provider: 'posthog',
            period: '2026-09',
            projectedEvents: 250000,
            projectedCost: 1562.50,
            currentCost: 1250.00,
            growthRate: 12.5,
            costPerEvent: 0.00625,
            confidenceInterval: 75,
            lowerBound: 1200.0,
            upperBound: 1800.0,
        );

        expect($projection->costChangePercentage())->toBe(25.0);
        expect($projection->isSignificantIncrease())->toBeTrue();
    });

    it('detects non-significant increase', function () {
        $projection = new CostForecastProjection(
            provider: 'ga4',
            period: '2026-09',
            projectedEvents: 500000,
            projectedCost: 0.0,
            currentCost: 0.0,
            growthRate: 5.0,
            costPerEvent: 0.0,
            confidenceInterval: 90,
            lowerBound: 0.0,
            upperBound: 0.0,
        );

        expect($projection->costChangePercentage())->toBe(0.0);
        expect($projection->isSignificantIncrease())->toBeFalse();
    });

    it('serializes to array with all fields', function () {
        $projection = new CostForecastProjection(
            provider: 'posthog',
            period: '2026-09',
            projectedEvents: 300000,
            projectedCost: 1875.0,
            currentCost: 1500.0,
            growthRate: 15.0,
            costPerEvent: 0.00625,
            confidenceInterval: 75,
            lowerBound: 1400.0,
            upperBound: 2200.0,
        );

        $arr = $projection->toArray();

        expect($arr)->toHaveKey('provider');
        expect($arr)->toHaveKey('period');
        expect($arr)->toHaveKey('projected_cost');
        expect($arr)->toHaveKey('cost_change_percentage');
        expect($arr)->toHaveKey('is_significant_increase');
        expect($arr)->toHaveKey('breakdown');
    });

    it('returns empty forecastAll when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn(['enabled' => false]);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);

        expect($service->forecastAll())->toBeEmpty();
    });

    it('returns zero total cost when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn(['enabled' => false]);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);

        expect($service->totalProjectedCost())->toBe(0.0);
    });

    it('returns optimization recommendations for high-volume providers', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_forecast', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'currency' => 'USD',
                'history_months' => 3,
                'projection_months' => 3,
                'growth_cap' => 50.0,
                'alert_on_exceeds_budget' => false,
                'monthly_budget' => 10000.0,
                'cache_ttl' => 3600,
                'providers' => [
                    'posthog' => 6.25,
                    'plausible' => 9.0,
                ],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturn(null);
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $service = new AnalyticsCostForecastService($this->cache, $this->config);
        $recommendations = $service->optimizationRecommendations();

        expect($recommendations)->toBeArray();
    });
});

describe('EventPolicyEngine', function () {
    it('constructs with default configuration', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => ['email', 'password'],
                'rules' => [],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);

        expect($engine)->toBeInstanceOf(EventPolicyEngine::class);
    });

    it('passes event through when disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn(['enabled' => false]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeFalse();
        expect($result['violations'])->toBeEmpty();
        expect($result['event']->name)->toBe('page_view');
    });

    it('passes event with no rules configured', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => ['email', 'password'],
                'rules' => [],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'click', params: ['element' => 'button']);

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeFalse();
        expect($result['violations'])->toBeEmpty();
    });

    it('detects disallowed params violation', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [
                    'no_sensitive' => [
                        'type' => 'disallowed_params',
                        'action' => 'sanitize',
                        'severity' => 'high',
                        'description' => 'Remove sensitive params',
                        'config' => ['keys' => ['password', 'api_key']],
                    ],
                ],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(
            name: 'login',
            params: ['user' => 'john', 'password' => 'secret123', 'api_key' => 'abc'],
        );

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeFalse();
        expect($result['violations'])->not->toBeEmpty();
        expect($result['violations'][0]->action)->toBe(PolicyViolation::ACTION_SANITIZE);
        // Sanitized event should have sensitive keys removed
        expect($result['event']->params)->not->toHaveKey('password');
    });

    it('detects max_params violation', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [
                    'max_params' => [
                        'type' => 'max_params',
                        'action' => 'warn',
                        'severity' => 'medium',
                        'description' => 'Too many params',
                        'config' => ['max' => 2],
                    ],
                ],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(
            name: 'custom',
            params: ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
        );

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeFalse();
        expect($result['violations'])->not->toBeEmpty();
        expect($result['violations'][0]->severity)->toBe(PolicyViolation::SEVERITY_MEDIUM);
    });

    it('blocks events matching blocked_events policy', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [
                    'block_debug' => [
                        'type' => 'blocked_events',
                        'action' => 'block',
                        'severity' => 'critical',
                        'description' => 'Block debug events',
                        'config' => ['events' => ['debug_ping', 'internal_test']],
                    ],
                ],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'debug_ping', params: []);

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeTrue();
        expect($result['violations'])->not->toBeEmpty();
        expect($result['violations'][0]->isCritical())->toBeTrue();
    });

    it('detects PII in event parameters', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => ['email', 'phone', 'ssn'],
                'rules' => [
                    'pii_check' => [
                        'type' => 'pii_detection',
                        'action' => 'sanitize',
                        'severity' => 'high',
                        'description' => 'Auto-detect PII',
                        'config' => [],
                    ],
                ],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(
            name: 'signup',
            params: ['user_email' => 'john@example.com', 'name' => 'John'],
        );

        $result = $engine->evaluate($event);

        expect($result['blocked'])->toBeFalse();
        expect($result['violations'])->not->toBeEmpty();
        // After sanitization, PII should be redacted
        expect($result['event']->params['user_email'])->toBe('[REDACTED]');
    });

    it('detects missing required params', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [
                    'require_user' => [
                        'type' => 'required_params',
                        'action' => 'warn',
                        'severity' => 'medium',
                        'description' => 'User ID required',
                        'config' => ['keys' => ['user_id']],
                    ],
                ],
            ]);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'click', params: ['element' => 'button']);

        $result = $engine->evaluate($event);

        expect($result['violations'])->not->toBeEmpty();
    });

    it('returns empty violation history', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_policy_violations')
            ->andReturn(null);

        $engine = new EventPolicyEngine($this->cache, $this->config);

        expect($engine->violationHistory())->toBeEmpty();
    });

    it('returns violation stats with zero totals', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_policy_violations')
            ->andReturn(null);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $stats = $engine->violationStats();

        expect($stats['total'])->toBe(0);
        expect($stats['blocked'])->toBe(0);
        expect($stats['critical'])->toBe(0);
        expect($stats['by_event'])->toBeEmpty();
        expect($stats['by_rule'])->toBeEmpty();
    });

    it('returns summary with policy count', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.governance_policies', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'default_action' => 'warn',
                'max_violation_history' => 500,
                'cache_ttl' => 3600,
                'log_violations' => false,
                'pii_patterns' => [],
                'rules' => [
                    'rule1' => ['type' => 'max_params', 'action' => 'warn', 'severity' => 'medium'],
                    'rule2' => ['type' => 'pii_detection', 'action' => 'sanitize', 'severity' => 'high'],
                ],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_policy_violations')
            ->andReturn(null);

        $engine = new EventPolicyEngine($this->cache, $this->config);
        $summary = $engine->summary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['policy_count'])->toBe(2);
        expect($summary['policies'])->toHaveKey('rule1');
        expect($summary['policies'])->toHaveKey('rule2');
    });
});

describe('PolicyViolation DTO', function () {
    it('identifies blocked violations', function () {
        $violation = new PolicyViolation(
            ruleId: 'test_rule',
            eventName: 'debug_ping',
            action: PolicyViolation::ACTION_BLOCK,
            severity: PolicyViolation::SEVERITY_CRITICAL,
            reason: 'Event blocked by policy',
        );

        expect($violation->isBlocked())->toBeTrue();
        expect($violation->isCritical())->toBeTrue();
    });

    it('identifies non-blocked warnings', function () {
        $violation = new PolicyViolation(
            ruleId: 'warn_rule',
            eventName: 'page_view',
            action: PolicyViolation::ACTION_WARN,
            severity: PolicyViolation::SEVERITY_LOW,
            reason: 'Event has many params',
        );

        expect($violation->isBlocked())->toBeFalse();
        expect($violation->isCritical())->toBeFalse();
    });

    it('serializes to array correctly', function () {
        $violation = new PolicyViolation(
            ruleId: 'pii_rule',
            eventName: 'signup',
            action: PolicyViolation::ACTION_SANITIZE,
            severity: PolicyViolation::SEVERITY_HIGH,
            reason: 'PII detected',
            eventSnapshot: ['pii_keys' => ['email']],
            context: ['source' => 'api'],
            resolvedBy: 'EventPolicyEngine::checkPiiDetection',
        );

        $arr = $violation->toArray();

        expect($arr['rule_id'])->toBe('pii_rule');
        expect($arr['event_name'])->toBe('signup');
        expect($arr['action'])->toBe('sanitize');
        expect($arr['severity'])->toBe('high');
        expect($arr['is_blocked'])->toBeFalse();
        expect($arr['is_critical'])->toBeFalse();
        expect($arr['resolved_by'])->toBe('EventPolicyEngine::checkPiiDetection');
    });
});

describe('v84 Integration', function () {
    it('AnalyticsEvent version is 84.0.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('84.0.0');
    });

    it('all new DTO classes exist and are final', function () {
        expect((new ReflectionClass(ProviderSLARecord::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(CostForecastProjection::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(PolicyViolation::class))->isFinal())->toBeTrue();
    });

    it('all new services exist and are final', function () {
        expect((new ReflectionClass(ProviderSLAMonitor::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(AnalyticsCostForecastService::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(EventPolicyEngine::class))->isFinal())->toBeTrue();
    });

    it('new services follow strict types', function () {
        $files = [
            __DIR__ . '/../src/Services/ProviderSLAMonitor.php',
            __DIR__ . '/../src/Services/AnalyticsCostForecastService.php',
            __DIR__ . '/../src/Services/EventPolicyEngine.php',
            __DIR__ . '/../src/DTO/ProviderSLARecord.php',
            __DIR__ . '/../src/DTO/CostForecastProjection.php',
            __DIR__ . '/../src/DTO/PolicyViolation.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    it('new services have proper docblocks', function () {
        $reflection = new ReflectionClass(ProviderSLAMonitor::class);
        $doc = $reflection->getDocComment();

        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 84.0.0');
        expect($doc)->toContain('Provider SLA monitor');
    });
});
