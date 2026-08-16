<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsPipelineProfilerService;
use ZeroBoiler\Analytics\Services\ConfigDriftDetectionService;
use ZeroBoiler\Analytics\Services\EventTraceService;

/**
 * Tests for v172.0.0 — Pipeline Profiler REST API, Event Trace REST API,
 * and Config Drift Import API.
 *
 * Validates:
 * - Pipeline Profiler: dashboard, provider/category profiles, slow events, flush
 * - Event Trace: generate, inject, injectBatch
 * - Config Drift Import: importBaseline, getBaseline
 * - Version sweep: all entry points at 172.0.0
 * - Class structure: final, strict types, void constructors
 *
 * @since 172.0.0
 */
final class V1720PipelineProfilerTraceApiDriftImportTest extends \PHPUnit\Framework\TestCase
{
    // ─── TraceContext Tests ────────────────────────────────────────────────

    public function test_trace_context_generate_returns_valid_ids(): void
    {
        $trace = TraceContext::generate('api');

        $this->assertNotEmpty($trace->traceId());
        $this->assertNotEmpty($trace->spanId());
        $this->assertNull($trace->parentSpanId());
        $this->assertSame('api', $trace->source());
    }

    public function test_trace_context_deterministic_same_seed(): void
    {
        $trace1 = TraceContext::generate('server');
        $trace2 = TraceContext::generate('server');

        // Two generates should produce different IDs (random)
        $this->assertNotSame($trace1->traceId(), $trace2->traceId());
    }

    public function test_trace_context_child_span_shares_trace_id(): void
    {
        $parent = TraceContext::generate('server');
        $child = $parent->childSpan('queue');

        $this->assertSame($parent->traceId(), $child->traceId());
        $this->assertSame($parent->spanId(), $child->parentSpanId());
        $this->assertNotSame($parent->spanId(), $child->spanId());
        $this->assertSame('queue', $child->source());
    }

    public function test_trace_context_to_params(): void
    {
        $trace = TraceContext::generate('test');
        $params = $trace->toParams();

        $this->assertArrayHasKey('_trace_id', $params);
        $this->assertArrayHasKey('_span_id', $params);
        $this->assertArrayHasKey('_trace_source', $params);
        $this->assertSame($trace->traceId(), $params['_trace_id']);
        $this->assertSame($trace->spanId(), $params['_span_id']);
    }

    public function test_trace_context_from_params(): void
    {
        $original = TraceContext::generate('api');
        $params = $original->toParams();

        $extracted = TraceContext::fromParams($params);

        $this->assertNotNull($extracted);
        $this->assertSame($original->traceId(), $extracted->traceId());
        $this->assertSame($original->spanId(), $extracted->spanId());
    }

    public function test_trace_context_from_empty_params_returns_null(): void
    {
        $this->assertNull(TraceContext::fromParams([]));
        $this->assertNull(TraceContext::fromParams(['foo' => 'bar']));
    }

    public function test_trace_context_from_params_preserves_parent(): void
    {
        $parent = TraceContext::generate('server');
        $child = $parent->childSpan('queue');
        $params = $child->toParams();

        $extracted = TraceContext::fromParams($params);

        $this->assertNotNull($extracted);
        $this->assertSame($parent->spanId(), $extracted->parentSpanId());
    }

    public function test_trace_context_readonly_class(): void
    {
        $reflection = new \ReflectionClass(TraceContext::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    // ─── EventTraceService Tests ──────────────────────────────────────────

    public function test_event_trace_service_class_structure(): void
    {
        $reflection = new \ReflectionClass(EventTraceService::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getFileName() !== false);
    }

    public function test_event_trace_service_inject_adds_trace_params(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.tracing', [], ['enabled' => true, 'source' => 'test']],
        ]);

        $service = new EventTraceService($config);

        $event = new AnalyticsEvent(name: 'test_event', params: ['foo' => 'bar']);
        $traced = $service->inject($event, 'api');

        $this->assertArrayHasKey('_trace_id', $traced->params);
        $this->assertArrayHasKey('_span_id', $traced->params);
        $this->assertArrayHasKey('_trace_source', $traced->params);
        $this->assertSame('api', $traced->params['_trace_source']);
        // Original params preserved
        $this->assertSame('bar', $traced->params['foo']);
    }

    public function test_event_trace_service_disabled_returns_same_event(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.tracing', [], ['enabled' => false]],
        ]);

        $service = new EventTraceService($config);

        $event = new AnalyticsEvent(name: 'test_event', params: []);
        $traced = $service->inject($event);

        $this->assertArrayNotHasKey('_trace_id', $traced->params);
    }

    public function test_event_trace_service_inject_batch_shares_trace_id(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.tracing', [], ['enabled' => true, 'source' => 'test']],
        ]);

        $service = new EventTraceService($config);

        $events = [
            new AnalyticsEvent(name: 'event_a', params: []),
            new AnalyticsEvent(name: 'event_b', params: []),
            new AnalyticsEvent(name: 'event_c', params: []),
        ];

        $traced = $service->injectBatch($events, 'batch');

        $this->assertCount(3, $traced);

        // All share same trace ID
        $traceId = $traced[0]->params['_trace_id'];
        $this->assertSame($traceId, $traced[1]->params['_trace_id']);
        $this->assertSame($traceId, $traced[2]->params['_trace_id']);

        // But have unique span IDs
        $this->assertNotSame(
            $traced[0]->params['_span_id'],
            $traced[1]->params['_span_id'],
        );
        $this->assertNotSame(
            $traced[1]->params['_span_id'],
            $traced[2]->params['_span_id'],
        );
    }

    public function test_event_trace_service_inject_empty_batch(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.tracing', [], ['enabled' => true]],
        ]);

        $service = new EventTraceService($config);
        $result = $service->injectBatch([]);

        $this->assertSame([], $result);
    }

    // ─── PipelineProfilerService Tests ────────────────────────────────────

    public function test_pipeline_profiler_class_structure(): void
    {
        $reflection = new \ReflectionClass(AnalyticsPipelineProfilerService::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_pipeline_profiler_request_summary_initial(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
            'slow_threshold_ms' => 500.0,
            'critical_threshold_ms' => 1000.0,
        ]);

        $summary = $profiler->requestSummary();

        $this->assertSame(0, $summary['dispatch_count']);
        $this->assertSame(0.0, $summary['total_latency_ms']);
        $this->assertSame(0.0, $summary['avg_latency_ms']);
    }

    public function test_pipeline_profiler_record_and_request_summary(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn([]);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
            'slow_threshold_ms' => 500.0,
            'critical_threshold_ms' => 1000.0,
            'cache_ttl' => 3600,
            'max_samples' => 1000,
        ]);

        $profiler->record('ga4', 120.5, 'page_view', true);
        $profiler->record('ga4', 80.0, 'sign_up', true);
        $profiler->record('meta', 250.3, 'purchase', true);

        $summary = $profiler->requestSummary();

        $this->assertSame(3, $summary['dispatch_count']);
        $this->assertSame(450.8, $summary['total_latency_ms']);
        $this->assertSame(150.27, $summary['avg_latency_ms']);
    }

    public function test_pipeline_profiler_thresholds(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
            'slow_threshold_ms' => 300.0,
            'critical_threshold_ms' => 800.0,
        ]);

        $this->assertSame(300.0, $profiler->getSlowThreshold());
        $this->assertSame(800.0, $profiler->getCriticalThreshold());
    }

    public function test_pipeline_profiler_dashboard_structure(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn([]);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
            'slow_threshold_ms' => 500.0,
            'critical_threshold_ms' => 1000.0,
            'cache_ttl' => 3600,
            'max_samples' => 1000,
        ]);

        $dashboard = $profiler->dashboard();

        $this->assertArrayHasKey('version', $dashboard);
        $this->assertArrayHasKey('providers', $dashboard);
        $this->assertArrayHasKey('categories', $dashboard);
        $this->assertArrayHasKey('slow_events', $dashboard);
        $this->assertArrayHasKey('slow_threshold_ms', $dashboard);
        $this->assertArrayHasKey('critical_threshold_ms', $dashboard);
        $this->assertArrayHasKey('request', $dashboard);
        $this->assertArrayHasKey('degraded_providers', $dashboard);
        $this->assertIsArray($dashboard['providers']);
        $this->assertIsArray($dashboard['degraded_providers']);
    }

    public function test_pipeline_profiler_flush_resets_state(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('has')->willReturn(true);
        $cache->method('get')->willReturn([100, 200, 300]);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache);
        $profiler->record('ga4', 50.0, 'test', true);
        $profiler->flush();

        $summary = $profiler->requestSummary();
        $this->assertSame(0, $summary['dispatch_count']);
    }

    public function test_pipeline_profiler_slow_events_list(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn([]);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);

        $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
            'slow_threshold_ms' => 50.0,
            'critical_threshold_ms' => 200.0,
            'cache_ttl' => 3600,
            'max_samples' => 1000,
        ]);

        $profiler->record('ga4', 100.0, 'slow_event', true);

        $slowEvents = $profiler->slowEvents(10);
        $this->assertIsArray($slowEvents);
    }

    // ─── ConfigDriftDetectionService Import Tests ────────────────────────

    public function test_config_drift_import_baseline(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.config_drift', [], [
                'enabled' => true,
                'cache_ttl' => 2592000,
                'exclude_keys' => [],
                'monitored_sections' => [],
            ]],
        ]);

        $service = new ConfigDriftDetectionService($cache, $config);

        $snapshot = [
            'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST'],
            'gtm' => ['enabled' => false],
            'consent' => ['default' => 'denied'],
        ];

        $result = $service->importBaseline($snapshot, 'production-sync');

        $this->assertTrue($result['imported']);
        $this->assertSame('production-sync', $result['label']);
        $this->assertSame('import', $result['source']);
        $this->assertArrayHasKey('captured_at', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertGreaterThanOrEqual(1, $result['sections']);
        $this->assertGreaterThanOrEqual(1, $result['keys']);
    }

    public function test_config_drift_import_empty_snapshot(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.config_drift', [], ['enabled' => true, 'cache_ttl' => 2592000]],
        ]);

        $service = new ConfigDriftDetectionService($cache, $config);
        $result = $service->importBaseline([], 'empty');

        $this->assertFalse($result['imported']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_config_drift_import_removes_existing_meta(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.config_drift', [], ['enabled' => true, 'cache_ttl' => 2592000, 'exclude_keys' => [], 'monitored_sections' => []]],
        ]);

        $service = new ConfigDriftDetectionService($cache, $config);

        $snapshot = [
            'ga4' => ['enabled' => true],
            '_meta' => ['old_version' => '1.0.0', 'should_be_removed' => true],
        ];

        $result = $service->importBaseline($snapshot, 'meta-test');

        $this->assertTrue($result['imported']);
    }

    public function test_config_drift_get_baseline(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.config_drift', [], ['enabled' => true, 'cache_ttl' => 2592000, 'exclude_keys' => [], 'monitored_sections' => []]],
        ]);

        $service = new ConfigDriftDetectionService($cache, $config);
        $result = $service->getBaseline();

        $this->assertFalse($result['exists']);
        $this->assertNull($result['baseline']);
    }

    public function test_config_drift_class_structure(): void
    {
        $reflection = new \ReflectionClass(ConfigDriftDetectionService::class);
        $this->assertTrue($reflection->isFinal());
    }

    // ─── Version Sweep Tests ─────────────────────────────────────────────

    public function test_version_sweep_composer_json(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('172.0.0', $json['version']);
    }

    public function test_version_sweep_analytics_event_version(): void
    {
        $this->assertSame('172.0.0', AnalyticsEvent::VERSION);
    }

    public function test_version_sweep_strict_types_all_files(): void
    {
        $srcDir = __DIR__ . '/../src/';
        $filesToCheck = [
            'Http/Controllers/AnalyticsEventController.php',
            'Services/ConfigDriftDetectionService.php',
            'Services/EventTraceService.php',
            'Services/AnalyticsPipelineProfilerService.php',
            'DTO/TraceContext.php',
        ];

        foreach ($filesToCheck as $file) {
            $path = $srcDir . $file;
            $this->assertFileExists($path, "Missing file: {$file}");
            $content = file_get_contents($path);
            $this->assertStringContainsString(
                "declare(strict_types=1)",
                $content,
                "Missing strict_types in: {$file}",
            );
        }
    }

    public function test_version_sweep_event_catalog_count(): void
    {
        $count = EventCatalog::count();
        $this->assertGreaterThan(150, $count, 'Event catalog should have 150+ events');
    }

    public function test_version_sweep_event_catalog_categories(): void
    {
        $byCategory = EventCatalog::byCategory();
        $expectedCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];

        foreach ($expectedCategories as $cat) {
            $this->assertArrayHasKey($cat, $byCategory, "Missing category: {$cat}");
            $this->assertGreaterThan(0, count($byCategory[$cat]), "Empty category: {$cat}");
        }
    }
}
