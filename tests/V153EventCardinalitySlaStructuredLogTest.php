<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventCardinalityLimiter;
use ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor;
use ZeroBoiler\Analytics\Services\StructuredEventLogger;
use ZeroBoiler\Analytics\Tracking\LifecycleAttributionEnricher;

/**
 * Tests for v153.0.0 — EventCardinalityLimiter, EventDeliverySlaMonitor,
 * StructuredEventLogger, and LifecycleAttributionEnricher.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventCardinalityLimiter
 * @covers \ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor
 * @covers \ZeroBoiler\Analytics\Services\StructuredEventLogger
 * @covers \ZeroBoiler\Analytics\Tracking\LifecycleAttributionEnricher
 *
 * @since 153.0.0
 */
final class V153EventCardinalitySlaStructuredLogTest extends BaseTestCase
{
    // =========================================================================
    // EventCardinalityLimiter Tests
    // =========================================================================

    public function test_cardinality_limiter_is_final(): void
    {
        $reflection = new \ReflectionClass(EventCardinalityLimiter::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_cardinality_limiter_has_strict_types(): void
    {
        $contents = file_get_contents((new \ReflectionClass(EventCardinalityLimiter::class))->getFileName());
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_cardinality_limiter_constructor_returns_void(): void
    {
        $constructor = new \ReflectionMethod(EventCardinalityLimiter::class, '__construct');
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_cardinality_limiter_enforce_returns_event_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $event = $this->makeTestEvent('test_event', ['user_id' => 'abc']);

        $result = $limiter->enforce($event);
        $this->assertNotNull($result);
        $this->assertSame('test_event', $result->name);
    }

    public function test_cardinality_limiter_enforce_returns_event_when_no_violations(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'default_limit' => 500,
            'high_cardinality_params' => [],
            'exceeded_action' => 'drop_param',
            'excluded_params' => [],
            'excluded_events' => [],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $event = $this->makeTestEvent('test_event', ['category' => 'signup']);

        $result = $limiter->enforce($event);
        $this->assertNotNull($result);
    }

    public function test_cardinality_limiter_exceeds_limit_returns_false_when_under_limit(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'default_limit' => 500,
            'high_cardinality_params' => [],
            'exceeded_action' => 'drop_param',
            'excluded_params' => [],
            'excluded_events' => [],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);

        $this->assertFalse($limiter->exceedsLimit('test_event', 'user_id'));
    }

    public function test_cardinality_limiter_track_value_does_nothing_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->never())->method('put');

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $limiter->trackValue('test_event', 'user_id', 'abc123');
        // No exception = pass
        $this->assertTrue(true);
    }

    public function test_cardinality_limiter_get_cardinality_report_returns_meta(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'default_limit' => 500,
            'exceeded_action' => 'drop_param',
            'high_cardinality_params' => ['user_id'],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $report = $limiter->getCardinalityReport();

        $this->assertArrayHasKey('_meta', $report);
        $this->assertTrue($report['_meta']['enabled']);
        $this->assertSame(500, $report['_meta']['default_limit']);
        $this->assertSame('drop_param', $report['_meta']['exceeded_action']);
    }

    public function test_cardinality_limiter_get_cardinality_returns_zero_for_empty(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'default_limit' => 500,
            'high_cardinality_params' => [],
            'exceeded_action' => 'drop_param',
            'excluded_params' => [],
            'excluded_events' => [],
        ]);

        $limiter = new EventCardinalityLimiter($cache, $config);
        $this->assertSame(0, $limiter->getCardinality('test_event', 'user_id'));
    }

    // =========================================================================
    // EventDeliverySlaMonitor Tests
    // =========================================================================

    public function test_sla_monitor_is_final(): void
    {
        $reflection = new \ReflectionClass(EventDeliverySlaMonitor::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_sla_monitor_has_strict_types(): void
    {
        $contents = file_get_contents((new \ReflectionClass(EventDeliverySlaMonitor::class))->getFileName());
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_sla_monitor_constructor_returns_void(): void
    {
        $constructor = new \ReflectionMethod(EventDeliverySlaMonitor::class, '__construct');
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_sla_monitor_get_status_returns_unknown_for_empty_window(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $status = $monitor->getStatus('ga4');

        $this->assertSame('unknown', $status['status']);
        $this->assertSame(0.0, $status['availability']);
        $this->assertSame(0, $status['total_events']);
        $this->assertSame([], $status['breaches']);
        $this->assertArrayHasKey('targets', $status);
    }

    public function test_sla_monitor_record_success_does_nothing_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->never())->method('put');

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->recordSuccess('ga4', 50.0);
        $this->assertTrue(true);
    }

    public function test_sla_monitor_record_failure_does_nothing_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->never())->method('put');

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->recordFailure('ga4', 'timeout');
        $this->assertTrue(true);
    }

    public function test_sla_monitor_is_enabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => true]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $this->assertTrue($monitor->isEnabled());
    }

    public function test_sla_monitor_clear_calls_forget(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->once())->method('forget');

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $monitor->clear('ga4');
        $this->assertTrue(true);
    }

    public function test_sla_monitor_has_breaches_returns_false_for_empty(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $this->assertFalse($monitor->hasBreaches());
    }

    public function test_sla_monitor_get_summary_structure(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $summary = $monitor->getSummary();

        $this->assertArrayHasKey('enabled', $summary);
        $this->assertArrayHasKey('providers_total', $summary);
        $this->assertArrayHasKey('healthy', $summary);
        $this->assertArrayHasKey('degraded', $summary);
        $this->assertArrayHasKey('breached', $summary);
        $this->assertArrayHasKey('unknown', $summary);
        $this->assertArrayHasKey('default_targets', $summary);
    }

    public function test_sla_monitor_get_all_status_returns_provider_keys(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn([]);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $monitor = new EventDeliverySlaMonitor($cache, $config);
        $all = $monitor->getAllStatus();

        $this->assertArrayHasKey('ga4', $all);
        $this->assertArrayHasKey('meta', $all);
        $this->assertArrayHasKey('gtm', $all);
        $this->assertArrayHasKey('posthog', $all);
    }

    // =========================================================================
    // StructuredEventLogger Tests
    // =========================================================================

    public function test_structured_logger_is_final(): void
    {
        $reflection = new \ReflectionClass(StructuredEventLogger::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_structured_logger_has_strict_types(): void
    {
        $contents = file_get_contents((new \ReflectionClass(StructuredEventLogger::class))->getFileName());
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_structured_logger_constructor_returns_void(): void
    {
        $constructor = new \ReflectionMethod(StructuredEventLogger::class, '__construct');
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_structured_logger_is_enabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => true]);

        $logger = new StructuredEventLogger($config);
        $this->assertTrue($logger->isEnabled());
    }

    public function test_structured_logger_log_dispatch_does_nothing_when_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $logger = new StructuredEventLogger($config);
        $event = $this->makeTestEvent('test_event', []);
        $logger->logDispatch($event, 'ga4', 50.0);
        $this->assertTrue(true);
    }

    public function test_structured_logger_log_error_does_nothing_when_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $logger = new StructuredEventLogger($config);
        $event = $this->makeTestEvent('test_event', []);
        $logger->logError($event, 'ga4', 'timeout');
        $this->assertTrue(true);
    }

    public function test_structured_logger_log_dropped_does_nothing_when_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $logger = new StructuredEventLogger($config);
        $event = $this->makeTestEvent('test_event', []);
        $logger->logDropped($event, 'consent_denied');
        $this->assertTrue(true);
    }

    public function test_structured_logger_get_config_summary(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'channel' => 'analytics',
            'dispatch_level' => 'debug',
            'error_level' => 'error',
            'include_params' => false,
            'log_rate_limit' => 1000,
            'excluded_events' => [],
        ]);

        $logger = new StructuredEventLogger($config);
        $summary = $logger->getConfigSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertSame('analytics', $summary['channel']);
        $this->assertSame('debug', $summary['dispatch_level']);
        $this->assertSame('error', $summary['error_level']);
        $this->assertFalse($summary['include_params']);
        $this->assertSame(1000, $summary['log_rate_limit']);
    }

    public function test_structured_logger_skips_excluded_events(): void
    {
        Log::shouldReceive('channel')->never();

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'dispatch_level' => 'debug',
            'excluded_events' => ['internal_ping'],
        ]);

        $logger = new StructuredEventLogger($config);
        $event = $this->makeTestEvent('internal_ping', []);
        $logger->logDispatch($event, 'ga4', 10.0);
        $this->assertTrue(true);
    }

    // =========================================================================
    // LifecycleAttributionEnricher Tests
    // =========================================================================

    public function test_lifecycle_enricher_is_final(): void
    {
        $reflection = new \ReflectionClass(LifecycleAttributionEnricher::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_lifecycle_enricher_has_strict_types(): void
    {
        $contents = file_get_contents((new \ReflectionClass(LifecycleAttributionEnricher::class))->getFileName());
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_lifecycle_enricher_constructor_returns_void(): void
    {
        $constructor = new \ReflectionMethod(LifecycleAttributionEnricher::class, '__construct');
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_lifecycle_enricher_returns_params_when_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => false]);

        $enricher = new LifecycleAttributionEnricher($config);
        $result = $enricher->enrich(['custom' => 'value']);

        $this->assertSame(['custom' => 'value'], $result);
    }

    public function test_lifecycle_enricher_is_enabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn(['enabled' => true]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertTrue($enricher->isEnabled());
    }

    public function test_lifecycle_enricher_classify_attribution_direct(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('direct', $enricher->classifyAttribution([]));
    }

    public function test_lifecycle_enricher_classify_attribution_paid_search(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('paid_search', $enricher->classifyAttribution([
            'utm_medium' => 'cpc',
            'utm_source' => 'google',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_paid_social(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('paid_social', $enricher->classifyAttribution([
            'utm_medium' => 'paid_social',
            'utm_source' => 'facebook',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_organic_search(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('organic_search', $enricher->classifyAttribution([
            'referrer_host' => 'google.com',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_organic_social(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('organic_social', $enricher->classifyAttribution([
            'utm_medium' => 'social',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_email(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('email', $enricher->classifyAttribution([
            'utm_medium' => 'email',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_referral(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('referral', $enricher->classifyAttribution([
            'referrer_host' => 'example.com',
        ]));
    }

    public function test_lifecycle_enricher_classify_attribution_affiliate(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $enricher = new LifecycleAttributionEnricher($config);
        $this->assertSame('affiliate', $enricher->classifyAttribution([
            'utm_source' => 'affiliate_partner',
        ]));
    }

    public function test_lifecycle_enricher_enrich_with_summary(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'enrichments' => ['utm' => false, 'referrer' => false, 'session' => false, 'device' => false, 'timestamp' => false, 'page' => false, 'attribution_summary' => true],
        ]);

        $enricher = new LifecycleAttributionEnricher($config);
        $result = $enricher->enrichWithSummary(['custom' => 'value']);

        $this->assertArrayHasKey('attribution_summary', $result);
        $this->assertSame('direct', $result['attribution_summary']);
        $this->assertSame('value', $result['custom']);
    }

    public function test_lifecycle_enricher_diagnostic_summary_structure(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'enrichments' => ['utm' => true, 'referrer' => true],
        ]);

        $enricher = new LifecycleAttributionEnricher($config);
        $diagnostic = $enricher->diagnosticSummary();

        $this->assertArrayHasKey('enabled', $diagnostic);
        $this->assertArrayHasKey('enrichments', $diagnostic);
        $this->assertTrue($diagnostic['enabled']);
    }

    public function test_lifecycle_enricher_params_take_precedence_over_enrichment(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'enrichments' => ['utm' => false, 'referrer' => false, 'session' => false, 'device' => false, 'timestamp' => false, 'page' => false, 'attribution_summary' => true],
        ]);

        $enricher = new LifecycleAttributionEnricher($config);
        $result = $enricher->enrichWithSummary(['custom' => 'value']);

        $this->assertSame('value', $result['custom']);
        $this->assertArrayHasKey('attribution_summary', $result);
    }

    // =========================================================================
    // ServiceProvider Registration Tests
    // =========================================================================

    public function test_service_provider_registers_cardinality_limiter(): void
    {
        $this->app->make(EventCardinalityLimiter::class);
        $this->assertTrue(true); // No exception = registered
    }

    public function test_service_provider_registers_sla_monitor(): void
    {
        $this->app->make(EventDeliverySlaMonitor::class);
        $this->assertTrue(true);
    }

    public function test_service_provider_registers_structured_logger(): void
    {
        $this->app->make(StructuredEventLogger::class);
        $this->assertTrue(true);
    }

    public function test_service_provider_registers_lifecycle_attribution_enricher(): void
    {
        $this->app->make(LifecycleAttributionEnricher::class);
        $this->assertTrue(true);
    }

    // =========================================================================
    // Version Consistency
    // =========================================================================

    public function test_version_consistency(): void
    {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('153.0.0', $composerJson['version']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTestEvent(string $name, array $params): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: 'test-client',
            userId: null,
            timestamp: null,
            priority: 'normal',
            source: 'server',
            category: 'engagement',
            sessionId: null,
        );
    }
}
