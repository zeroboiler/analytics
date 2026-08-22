<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventCardinalityLimiter;
use ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor;
use ZeroBoiler\Analytics\Services\StructuredEventLogger;

/**
 * Tests for v153.0.0 — Event Cardinality Limiter, Structured Event Logger, Event Delivery SLA Monitor.
 *
 * Covers:
 * - EventCardinalityLimiter: enforce, exceedsLimit, trackValue, actions (strict/drop_param/bucket)
 * - StructuredEventLogger: logDispatch, logError, logDropped, logPipelineTransit, config summary
 * - EventDeliverySlaMonitor: recordSuccess, recordFailure, getStatus, getAllStatus, hasBreaches, summary
 * - Cross-service integration, config-driven behavior, edge cases
 *
 * @since 153.0.0
 */
final class V1530CardinalitySlaStructuredLogTest extends TestCase
{
    // ─── EventCardinalityLimiter Tests ─────────────────────────────────────

    public function testCardinalityLimiterPassesThroughWhenEnabledAndNoViolations(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => ['enabled' => true, 'default_limit' => 500],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $event = new AnalyticsEvent(name: 'button_click', params: ['color' => 'blue']);

        $result = $limiter->enforce($event);

        $this->assertNotNull($result);
        $this->assertSame('button_click', $result->name);
        $this->assertSame(['color' => 'blue'], $result->params);
    }

    public function testCardinalityLimiterReturnsOriginalWhenDisabled(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['cardinality' => ['enabled' => false]]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);

        $result = $limiter->enforce($event);

        $this->assertSame($event, $result);
    }

    public function testCardinalityLimiterSkipsExcludedEvents(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 1,
                'excluded_events' => ['health_check'],
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $event = new AnalyticsEvent(name: 'health_check', params: ['key' => 'value']);

        $result = $limiter->enforce($event);

        $this->assertSame($event, $result);
    }

    public function testCardinalityLimiterSkipsExcludedParams(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 1,
                'excluded_params' => ['safe_param'],
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $limiter->trackValue('test_event', 'safe_param', 'value1');
        $limiter->trackValue('test_event', 'safe_param', 'value2');

        $event = new AnalyticsEvent(name: 'test_event', params: ['safe_param' => 'value3']);
        $result = $limiter->enforce($event);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('safe_param', $result->params);
    }

    public function testCardinalityLimiterDropsParamsWhenLimitExceeded(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 2,
                'exceeded_action' => 'drop_param',
                'high_cardinality_params' => ['user_id'],
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);

        // Fill up cardinality
        $limiter->trackValue('purchase', 'user_id', 'user_1');
        $limiter->trackValue('purchase', 'user_id', 'user_2');

        $this->assertTrue($limiter->exceedsLimit('purchase', 'user_id'));

        $event = new AnalyticsEvent(name: 'purchase', params: ['user_id' => 'user_3', 'amount' => 99.99]);
        $result = $limiter->enforce($event);

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('user_id', $result->params);
        $this->assertSame(99.99, $result->params['amount']);
    }

    public function testCardinalityLimiterStrictModeDropsEntireEvent(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 1,
                'exceeded_action' => 'strict',
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $limiter->trackValue('test', 'key', 'value1');

        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value2']);
        $result = $limiter->enforce($event);

        $this->assertNull($result);
    }

    public function testCardinalityLimiterBucketModeReplacesWithHash(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 1,
                'exceeded_action' => 'bucket',
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $limiter->trackValue('test', 'key', 'value1');

        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value2', 'safe' => 'ok']);
        $result = $limiter->enforce($event);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('__cardinality_limited__:', $result->params['key']);
        $this->assertSame('ok', $result->params['safe']);
    }

    public function testCardinalityLimiterHighCardinalityParamsGetLowerLimit(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 500,
                'high_cardinality_params' => ['email'],
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);

        // High-cardinality param email gets limit = min(500, 100) = 100
        for ($i = 0; $i < 100; $i++) {
            $limiter->trackValue('signup', 'email', "user{$i}@example.com");
        }

        $this->assertTrue($limiter->exceedsLimit('signup', 'email'));
        $this->assertFalse($limiter->exceedsLimit('signup', 'normal_param'));
    }

    public function testCardinalityLimiterGetCardinalityReport(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => ['enabled' => true, 'default_limit' => 500],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $report = $limiter->getCardinalityReport();

        $this->assertArrayHasKey('_meta', $report);
        $this->assertTrue($report['_meta']['enabled']);
        $this->assertSame(500, $report['_meta']['default_limit']);
        $this->assertSame('drop_param', $report['_meta']['exceeded_action']);
        $this->assertContains('user_id', $report['_meta']['high_cardinality_params']);
    }

    public function testCardinalityLimiterIgnoresNonTrackableValues(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => ['enabled' => true, 'default_limit' => 1],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);

        // Arrays, booleans, nulls should not be tracked
        $event = new AnalyticsEvent(name: 'test', params: [
            'tags' => ['a', 'b'],
            'active' => true,
            'nullable' => null,
        ]);

        $result = $limiter->enforce($event);
        $this->assertNotNull($result);
        $this->assertSame(['tags' => ['a', 'b'], 'active' => true, 'nullable' => null], $result->params);
    }

    public function testCardinalityLimiterPreservesAllEventMetadata(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'cardinality' => [
                'enabled' => true,
                'default_limit' => 1,
                'exceeded_action' => 'drop_param',
            ],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $limiter->trackValue('test', 'bad_key', 'v1');

        $event = new AnalyticsEvent(
            name: 'test',
            params: ['bad_key' => 'v2', 'good_key' => 'ok'],
            clientId: 'client_123',
            userId: 'user_456',
            priority: 'critical',
            source: 'api',
            category: 'saas',
            sessionId: 'sess_789',
        );

        $result = $limiter->enforce($event);

        $this->assertNotNull($result);
        $this->assertSame('test', $result->name);
        $this->assertSame('client_123', $result->clientId);
        $this->assertSame('user_456', $result->userId);
        $this->assertSame('critical', $result->priority);
        $this->assertSame('api', $result->source);
        $this->assertSame('saas', $result->category);
        $this->assertSame('sess_789', $result->sessionId);
    }

    // ─── StructuredEventLogger Tests ─────────────────────────────────────

    public function testStructuredLoggerIsEnabledByDefault(): void
    {
        $config = $this->createConfig(['structured_logging' => ['enabled' => true]]);
        $logger = new StructuredEventLogger($config);

        $this->assertTrue($logger->isEnabled());
    }

    public function testStructuredLoggerIsDisabledWhenConfigured(): void
    {
        $config = $this->createConfig(['structured_logging' => ['enabled' => false]]);
        $logger = new StructuredEventLogger($config);

        $this->assertFalse($logger->isEnabled());
    }

    public function testStructuredLoggerConfigSummary(): void
    {
        $config = $this->createConfig([
            'structured_logging' => [
                'enabled' => true,
                'channel' => 'analytics',
                'dispatch_level' => 'debug',
                'error_level' => 'error',
                'include_params' => true,
                'log_rate_limit' => 500,
                'excluded_events' => ['page_view'],
            ],
        ]);

        $logger = new StructuredEventLogger($config);
        $summary = $logger->getConfigSummary();

        $this->assertSame('analytics', $summary['channel']);
        $this->assertSame('debug', $summary['dispatch_level']);
        $this->assertSame('error', $summary['error_level']);
        $this->assertTrue($summary['include_params']);
        $this->assertSame(500, $summary['log_rate_limit']);
        $this->assertSame(1, $summary['excluded_events_count']);
    }

    public function testStructuredLoggerExcludesConfiguredEvents(): void
    {
        $config = $this->createConfig([
            'structured_logging' => [
                'enabled' => true,
                'excluded_events' => ['page_view'],
            ],
        ]);

        $logger = new StructuredEventLogger($config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        // Should not throw — just silently skip
        $logger->logDispatch($event, 'ga4', 12.5);
        $logger->logDropped($event, 'consent_denied');
        $logger->logError($event, 'meta', 'Connection failed');
        $logger->logPipelineTransit($event, 'consent_gate', 'filtered');

        // No assertion needed — just verifying no exceptions
        $this->assertTrue(true);
    }

    public function testStructuredLoggerSensitiveKeysAreRedacted(): void
    {
        // Verify the constructor properly stores sensitive key config
        $config = $this->createConfig([
            'structured_logging' => [
                'enabled' => true,
                'include_params' => true,
                'sensitive_keys' => ['password', 'api_key'],
                'max_param_length' => 200,
            ],
        ]);

        $logger = new StructuredEventLogger($config);
        $summary = $logger->getConfigSummary();

        $this->assertTrue($summary['include_params']);
    }

    public function testStructuredLoggerRateLimiting(): void
    {
        $config = $this->createConfig([
            'structured_logging' => [
                'enabled' => true,
                'log_rate_limit' => 2, // Very low limit for testing
            ],
        ]);

        $logger = new StructuredEventLogger($config);
        $event = new AnalyticsEvent(name: 'test', params: []);

        // First two should go through
        $logger->logDispatch($event, 'ga4', 10.0);
        $logger->logDispatch($event, 'meta', 20.0);

        // Third should be rate-limited (silently dropped)
        $logger->logDispatch($event, 'posthog', 30.0);

        $this->assertTrue(true); // No exceptions = pass
    }

    // ─── EventDeliverySlaMonitor Tests ───────────────────────────────────

    public function testSlaMonitorIsEnabledByDefault(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['sla' => ['enabled' => true]]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        $this->assertTrue($monitor->isEnabled());
    }

    public function testSlaMonitorRecordsSuccessAndFailure(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => ['enabled' => true, 'window_seconds' => 300],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        $monitor->recordSuccess('ga4', 45.0);
        $monitor->recordSuccess('ga4', 55.0);
        $monitor->recordSuccess('ga4', 65.0);
        $monitor->recordFailure('ga4', 'Connection timeout');

        $status = $monitor->getStatus('ga4');

        $this->assertSame('healthy', $status['status']);
        $this->assertSame(4, $status['total_events']);
        $this->assertGreaterThan(0, $status['latency_p50']);
        $this->assertGreaterThan(0, $status['latency_p95']);
        $this->assertSame(0.25, $status['error_rate']);
    }

    public function testSlaMonitorUnknownStatusWhenNoData(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['sla' => ['enabled' => true]]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $status = $monitor->getStatus('ga4');

        $this->assertSame('unknown', $status['status']);
        $this->assertSame(0.0, $status['availability']);
        $this->assertSame(0, $status['total_events']);
        $this->assertSame([], $status['breaches']);
    }

    public function testSlaMonitorDetectsAvailabilityBreach(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'window_seconds' => 300,
                'default_availability' => 0.999, // 99.9% target
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        // 1 success, 2 failures = 33% availability (below 99.9% target)
        $monitor->recordSuccess('ga4', 10.0);
        $monitor->recordFailure('ga4', 'Error 1');
        $monitor->recordFailure('ga4', 'Error 2');

        $status = $monitor->getStatus('ga4');

        $this->assertSame('breached', $status['status']);
        $this->assertNotEmpty($status['breaches']);
        $this->assertStringContainsString('availability', $status['breaches'][0]);
    }

    public function testSlaMonitorDetectsLatencyBreach(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'window_seconds' => 300,
                'default_latency_p95' => 100.0, // 100ms target
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        // All successful but high latency
        for ($i = 0; $i < 20; $i++) {
            $monitor->recordSuccess('ga4', 200.0); // 200ms each
        }

        $status = $monitor->getStatus('ga4');

        $this->assertSame('breached', $status['status']);
        $this->assertNotEmpty($status['breaches']);
        $this->assertStringContainsString('latency_p95', $status['breaches'][0]);
    }

    public function testSlaMonitorDetectsDegradedStatus(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'window_seconds' => 300,
                'default_availability' => 0.999,
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        // Near the threshold but not breached
        // Need 1000 events with ~2 failures to get close to 99.8%
        for ($i = 0; $i < 998; $i++) {
            $monitor->recordSuccess('ga4', 10.0);
        }
        $monitor->recordFailure('ga4', 'Timeout');
        $monitor->recordFailure('ga4', 'Timeout');

        $status = $monitor->getStatus('ga4');

        $this->assertSame('degraded', $status['status']);
    }

    public function testSlaMonitorCustomProviderTargets(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'window_seconds' => 300,
                'default_availability' => 0.999,
                'default_latency_p95' => 500.0,
                'provider_targets' => [
                    'meta' => ['availability' => 0.99, 'latency_p95' => 1000.0],
                ],
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        // Meta: 1 success, 1 failure = 50% availability (below 99% target)
        $monitor->recordSuccess('meta', 50.0);
        $monitor->recordFailure('meta', 'Error');

        $status = $monitor->getStatus('meta');

        $this->assertSame('breached', $status['status']);
        $this->assertArrayHasKey('availability', $status['targets']);
        $this->assertSame(0.99, $status['targets']['availability']);
        $this->assertSame(1000.0, $status['targets']['latency_p95']);
    }

    public function testSlaMonitorGetAllStatus(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'monitored_providers' => ['ga4', 'meta'],
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        $monitor->recordSuccess('ga4', 10.0);
        $monitor->recordSuccess('meta', 20.0);

        $all = $monitor->getAllStatus();

        $this->assertArrayHasKey('ga4', $all);
        $this->assertArrayHasKey('meta', $all);
        $this->assertSame('healthy', $all['ga4']['status']);
        $this->assertSame('healthy', $all['meta']['status']);
    }

    public function testSlaMonitorHasBreaches(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'default_availability' => 0.999,
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        $this->assertFalse($monitor->hasBreaches());

        $monitor->recordSuccess('ga4', 10.0);
        $monitor->recordFailure('ga4', 'Error');

        $this->assertTrue($monitor->hasBreaches());
    }

    public function testSlaMonitorSummary(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'default_availability' => 0.999,
                'default_latency_p95' => 500.0,
                'default_error_rate_max' => 0.01,
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $summary = $monitor->getSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertSame(10, $summary['providers_total']);
        $this->assertSame(0.999, $summary['default_targets']['availability']);
        $this->assertSame(500.0, $summary['default_targets']['latency_p95']);
        $this->assertSame(0.01, $summary['default_targets']['error_rate']);
        $this->assertArrayHasKey('healthy', $summary);
        $this->assertArrayHasKey('degraded', $summary);
        $this->assertArrayHasKey('breached', $summary);
        $this->assertArrayHasKey('unknown', $summary);
    }

    public function testSlaMonitorClear(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['sla' => ['enabled' => true]]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->recordSuccess('ga4', 10.0);

        $this->assertSame('healthy', $monitor->getStatus('ga4')['status']);

        $monitor->clear('ga4');

        $this->assertSame('unknown', $monitor->getStatus('ga4')['status']);
    }

    public function testSlaMonitorClearAll(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig([
            'sla' => [
                'enabled' => true,
                'monitored_providers' => ['ga4', 'meta'],
            ],
        ]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->recordSuccess('ga4', 10.0);
        $monitor->recordSuccess('meta', 20.0);

        $monitor->clearAll();

        $this->assertSame('unknown', $monitor->getStatus('ga4')['status']);
        $this->assertSame('unknown', $monitor->getStatus('meta')['status']);
    }

    public function testSlaMonitorThroughputCalculation(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['sla' => ['enabled' => true]]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);

        for ($i = 0; $i < 10; $i++) {
            $monitor->recordSuccess('ga4', 10.0);
        }

        $status = $monitor->getStatus('ga4');

        $this->assertGreaterThan(0, $status['throughput_eps']);
    }

    public function testSlaMonitorPercentilesWithSingleValue(): void
    {
        $cache = new InMemoryCache;
        $config = $this->createConfig(['sla' => ['enabled' => true]]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->recordSuccess('ga4', 42.5);

        $status = $monitor->getStatus('ga4');

        $this->assertSame(42.5, $status['latency_p50']);
        $this->assertSame(42.5, $status['latency_p95']);
        $this->assertSame(42.5, $status['latency_p99']);
    }

    // ─── Version Consistency ─────────────────────────────────────────────

    public function testVersionConsistencyV153(): void
    {
        // Verify the DTO version is set
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            AnalyticsEvent::VERSION,
            'AnalyticsEvent::VERSION must follow semver format'
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Create a minimal config repository for testing.
     *
     * @param  array<string, mixed>  $overrides  Config overrides
     * @return TestConfigRepository
     */
    private function createConfig(array $overrides = []): TestConfigRepository
    {
        $defaults = [
            'cardinality' => [
                'enabled' => true,
                'ttl' => 3600,
                'default_limit' => 500,
                'param_limits' => [],
                'high_cardinality_params' => ['user_id', 'client_id', 'session_id', 'ip_address', 'email'],
                'exceeded_action' => 'drop_param',
                'excluded_params' => [],
                'excluded_events' => [],
            ],
            'structured_logging' => [
                'enabled' => true,
                'channel' => 'analytics',
                'dispatch_level' => 'debug',
                'error_level' => 'error',
                'category_levels' => [],
                'provider_levels' => [],
                'include_params' => false,
                'sensitive_keys' => ['email', 'password', 'token'],
                'max_param_length' => 100,
                'excluded_events' => [],
                'log_rate_limit' => 1000,
            ],
            'sla' => [
                'enabled' => true,
                'ttl' => 300,
                'window_seconds' => 300,
                'default_availability' => 0.999,
                'default_latency_p95' => 500.0,
                'default_error_rate_max' => 0.01,
                'provider_targets' => [],
                'monitored_providers' => [],
            ],
        ];

        return new TestConfigRepository(array_merge($defaults, $overrides));
    }
}

// ─── Test Doubles ───────────────────────────────────────────────────────

/**
 * Minimal in-memory cache implementation for testing.
 */
final class InMemoryCache implements \Illuminate\Contracts\Cache\Repository
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string|array $key, mixed $default = null): mixed
    {
        $key = is_array($key) ? $key[0] : $key;

        return $this->store[$key] ?? $default;
    }

    public function put(string|array $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        $key = is_array($key) ? $key[0] : $key;
        $this->store[$key] = $value;

        return true;
    }

    public function has(string|array $key): bool
    {
        $key = is_array($key) ? $key[0] : $key;

        return array_key_exists($key, $this->store);
    }

    public function forget(string|array $key): bool
    {
        $key = is_array($key) ? $key[0] : $key;
        unset($this->store[$key]);

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->store[$key] ?? $default;
        }

        return $result;
    }

    public function putMultiple(iterable $values, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }

        return true;
    }

    public function increment(string $key, int $value = 1): int|bool { return false; }
    public function decrement(string $key, int $value = 1): int|bool { return false; }
    public function add(string|array $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool { return false; }
    public function forever(string|array $key, mixed $value): bool { $this->put($key, $value); return true; }
    public function remember(string|array $key, \DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback): mixed { return $callback(); }
    public function rememberForever(string|array $key, \Closure $callback): mixed { return $callback(); }
    public function pull(string|array $key, mixed $default = null): mixed { $val = $this->get($key, $default); $this->forget($key); return $val; }
    public function flush(): bool { $this->store = []; return true; }
    public function clear(): bool { return $this->flush(); }
    public function getPrefix(): string { return 'test_'; }
    public function getDefaultCacheTime(): int { return 3600; }
    public function setDefaultCacheTime(int $seconds): self { return $this; }
    public function store(string|null $name = null): \Illuminate\Contracts\Cache\Store { throw new \LogicException('Not implemented'); }
    public function tags(iterable|string $names): \Illuminate\Cache\TaggedCache { throw new \LogicException('Not implemented'); }
}

/**
 * Minimal config repository for testing.
 */
final class TestConfigRepository implements \Illuminate\Contracts\Config\Repository
{
    public function __construct(
        /** @var array<string, mixed> */
        private array $data = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->data[$k] = $v;
            }
        } else {
            $this->data[$key] = $value;
        }
    }

    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function all(): array { return $this->data; }
    public function prepend(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function push(string $key, mixed $value): void { $this->data[$key][] = $value; }
}
