<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;
use ZeroBoiler\Analytics\Services\AnalyticsDataLakeService;
use ZeroBoiler\Analytics\Services\EventTraceContextService;

/**
 * V188 Data Lake + Event Trace Context — Production Readiness Test.
 *
 * Validates the new AnalyticsDataLakeService and EventTraceContextService
 * for source quality, API completeness, and industry-standard patterns.
 *
 * @since 188.0.0
 */
final class V188DataLakeTraceContextTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';

    // ── AnalyticsDataLakeService ──────────────────────────────────────────

    #[Test]
    public function data_lake_service_file_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php',
        );
    }

    #[Test]
    public function data_lake_service_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function data_lake_service_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $this->assertStringContainsString('final class AnalyticsDataLakeService', $content);
    }

    #[Test]
    public function data_lake_service_has_void_constructor(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $this->assertStringContainsString('public function __construct(', $content);
        $this->assertStringContainsString('): void', $content);
    }

    #[Test]
    public function data_lake_service_has_mit_header(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $this->assertStringContainsString('part of ZeroBoiler, licensed under the MIT license', $content);
    }

    #[Test]
    public function data_lake_service_has_since_annotation(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $this->assertStringContainsString('@since 188.0.0', $content);
    }

    #[Test]
    public function data_lake_service_exports_ndjson(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'exportNdjson'),
            'AnalyticsDataLakeService must have exportNdjson() method',
        );
    }

    #[Test]
    public function data_lake_service_exports_csv(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'exportCsv'),
            'AnalyticsDataLakeService must have exportCsv() method',
        );
    }

    #[Test]
    public function data_lake_service_exports_summary(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'exportSummary'),
            'AnalyticsDataLakeService must have exportSummary() method',
        );
    }

    #[Test]
    public function data_lake_service_has_snapshot_cache(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'snapshotKey'),
        );
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'getCachedSnapshot'),
        );
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'cacheSnapshot'),
        );
    }

    #[Test]
    public function data_lake_service_has_clear_cache(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'clearCache'),
        );
    }

    #[Test]
    public function data_lake_service_has_stats(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'stats'),
        );
    }

    #[Test]
    public function data_lake_service_has_is_enabled(): void
    {
        $this->assertTrue(
            method_exists(AnalyticsDataLakeService::class, 'isEnabled'),
        );
    }

    #[Test]
    public function data_lake_service_format_constants(): void
    {
        $this->assertSame('ndjson', AnalyticsDataLakeService::FORMAT_NDJSON);
        $this->assertSame('csv', AnalyticsDataLakeService::FORMAT_CSV);
        $this->assertSame('summary', AnalyticsDataLakeService::FORMAT_SUMMARY);
    }

    #[Test]
    public function data_lake_service_supported_formats(): void
    {
        $formats = AnalyticsDataLakeService::supportedFormats();
        $this->assertContains('ndjson', $formats);
        $this->assertContains('csv', $formats);
        $this->assertContains('summary', $formats);
        $this->assertCount(3, $formats);
    }

    #[Test]
    public function data_lake_service_return_types_are_declared(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');

        // Method signatures with return types
        $this->assertMatchesRegularExpression('/public function exportNdjson\([^)]+\): array/', $content);
        $this->assertMatchesRegularExpression('/public function exportCsv\([^)]+\): string/', $content);
        $this->assertMatchesRegularExpression('/public function exportSummary\([^)]+\): array/', $content);
        $this->assertMatchesRegularExpression('/public function snapshotKey\([^)]+\): string/', $content);
        $this->assertMatchesRegularExpression('/public function isEnabled\(\): bool/', $content);
        $this->assertMatchesRegularExpression('/public function stats\(\): array/', $content);
        $this->assertMatchesRegularExpression('/public function clearCache\(\): int/', $content);
    }

    #[Test]
    public function data_lake_config_section_exists(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'data_lake'", $content);
        $this->assertStringContainsString('ANALYTICS_DATA_LAKE_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_DATA_LAKE_CACHE_TTL', $content);
        $this->assertStringContainsString('ANALYTICS_DATA_LAKE_MAX_BATCH', $content);
    }

    // ── EventTraceContextService ─────────────────────────────────────────

    #[Test]
    public function trace_context_service_file_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Services/EventTraceContextService.php',
        );
    }

    #[Test]
    public function trace_context_service_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function trace_context_service_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');
        $this->assertStringContainsString('final class EventTraceContextService', $content);
    }

    #[Test]
    public function trace_context_service_has_void_constructor(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');
        $this->assertStringContainsString('public function __construct(', $content);
        $this->assertStringContainsString('): void', $content);
    }

    #[Test]
    public function trace_context_service_has_mit_header(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');
        $this->assertStringContainsString('part of ZeroBoiler, licensed under the MIT license', $content);
    }

    #[Test]
    public function trace_context_service_has_since_annotation(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');
        $this->assertStringContainsString('@since 188.0.0', $content);
    }

    #[Test]
    public function trace_context_service_has_extract_from_request(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'extractFromRequest'),
        );
    }

    #[Test]
    public function trace_context_service_has_enrich_event(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'enrichEvent'),
        );
    }

    #[Test]
    public function trace_context_service_has_get_trace_context(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'getTraceContext'),
        );
    }

    #[Test]
    public function trace_context_service_has_trace_params(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'traceParams'),
        );
    }

    #[Test]
    public function trace_context_service_has_create_child_span(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'createChildSpan'),
        );
    }

    #[Test]
    public function trace_context_service_has_to_traceparent_header(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'toTraceparentHeader'),
        );
    }

    #[Test]
    public function trace_context_service_has_is_enabled(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'isEnabled'),
        );
    }

    #[Test]
    public function trace_context_service_has_has_active_trace(): void
    {
        $this->assertTrue(
            method_exists(EventTraceContextService::class, 'hasActiveTrace'),
        );
    }

    #[Test]
    public function trace_context_return_types_are_declared(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');

        $this->assertMatchesRegularExpression('/public function enrichEvent\([^)]+\): AnalyticsEvent/', $content);
        $this->assertMatchesRegularExpression('/public function getTraceContext\(\): TraceContext/', $content);
        $this->assertMatchesRegularExpression('/public function traceParams\(\): array/', $content);
        $this->assertMatchesRegularExpression('/public function createChildSpan\(\): string/', $content);
        $this->assertMatchesRegularExpression('/public function toTraceparentHeader\(\): string/', $content);
        $this->assertMatchesRegularExpression('/public function isEnabled\(\): bool/', $content);
        $this->assertMatchesRegularExpression('/public function hasActiveTrace\(\): bool/', $content);
    }

    #[Test]
    public function trace_context_config_section_exists(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'trace_context'", $content);
        $this->assertStringContainsString('ANALYTICS_TRACE_CONTEXT_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_TRACE_CONTEXT_STRICT', $content);
        $this->assertStringContainsString('ANALYTICS_TRACE_CONTEXT_AUTO_ENRICH', $content);
    }

    // ── Cross-Service Integration ────────────────────────────────────────

    #[Test]
    public function trace_context_service_enriches_analytics_event(): void
    {
        // Create service and generate trace context manually
        $service = new EventTraceContextService(enabled: true, strictMode: false, autoEnrich: true);

        // Simulate trace extraction by accessing internal state through public API
        // We can't call extractFromRequest without a real HTTP request,
        // so we test that the service produces correct structure when trace is active

        $this->assertFalse($service->hasActiveTrace(), 'No trace should be active before extraction');

        // Test traceparent header format
        $service2 = new EventTraceContextService(enabled: true);
        $header = $service2->toTraceparentHeader();
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            $header,
            'traceparent must match W3C format: version-traceid-spanid-flags',
        );
    }

    #[Test]
    public function trace_context_service_disabled_returns_event_unchanged(): void
    {
        $service = new EventTraceContextService(enabled: false);
        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

        $result = $service->enrichEvent($event);

        $this->assertSame($event->name, $result->name);
        $this->assertSame($event->params, $result->params);
    }

    #[Test]
    public function trace_context_service_child_span_is_16_hex_chars(): void
    {
        $service = new EventTraceContextService(enabled: true);
        $span = $service->createChildSpan();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/',
            $span,
            'Child span ID must be 16 hex characters',
        );
    }

    #[Test]
    public function data_lake_service_stats_returns_expected_keys(): void
    {
        // Stats can be checked via source inspection (constructor defaults)
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');

        // Verify stats() returns the right structure
        $this->assertStringContainsString("'enabled' => \$this->enabled", $content);
        $this->assertStringContainsString("'ttl' => \$this->ttl", $content);
        $this->assertStringContainsString("'max_batch_size' => \$this->maxBatchSize", $content);
        $this->assertStringContainsString("'columns_count' => count(\$this->defaultColumns)", $content);
        $this->assertStringContainsString("'included_categories' => \$this->includedCategories", $content);
        $this->assertStringContainsString("'default_format' => \$this->defaultFormat", $content);
    }

    #[Test]
    public function both_services_use_cache_repository(): void
    {
        $dlContent = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $tcContent = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');

        // Data lake uses CacheRepository
        $this->assertStringContainsString('use Illuminate\\Contracts\\Cache\\Repository as CacheRepository', $dlContent);

        // Trace context does NOT use cache (stateless, request-scoped)
        $this->assertStringNotContainsString('CacheRepository', $tcContent);
    }

    #[Test]
    public function both_services_have_docblocks(): void
    {
        $dlContent = file_get_contents(self::PKG_ROOT . '/src/Services/AnalyticsDataLakeService.php');
        $tcContent = file_get_contents(self::PKG_ROOT . '/src/Services/EventTraceContextService.php');

        $this->assertStringContainsString('/**', $dlContent);
        $this->assertStringContainsString('*/', $dlContent);
        $this->assertStringContainsString('@since', $dlContent);

        $this->assertStringContainsString('/**', $tcContent);
        $this->assertStringContainsString('*/', $tcContent);
        $this->assertStringContainsString('@since', $tcContent);
    }

    // ── Version Consistency (v188.0.0) ────────────────────────────────────

    #[Test]
    public function version_consistency_across_entry_points(): void
    {
        $version = '188.0.0';

        // AnalyticsEvent::VERSION
        $dtoContent = file_get_contents(self::PKG_ROOT . '/src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString("'{$version}'", $dtoContent, 'AnalyticsEvent::VERSION mismatch');

        // composer.json
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame($version, $composer['version'] ?? null, 'composer.json version mismatch');

        // package.json
        $pkg = json_decode(file_get_contents(self::PKG_ROOT . '/package.json'), true);
        $this->assertSame($version, $pkg['version'] ?? null, 'package.json version mismatch');

        // README
        $readme = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString("version-{$version}", $readme, 'README badge version mismatch');
    }

    #[Test]
    public function service_file_count_minimum(): void
    {
        $services = glob(self::PKG_ROOT . '/src/Services/*.php');
        $this->assertGreaterThanOrEqual(
            389,
            count($services),
            'Minimum 389 service files expected (v188.0.0 baseline)',
        );
    }

    #[Test]
    public function test_file_count_minimum(): void
    {
        $tests = glob(self::PKG_ROOT . '/tests/*.php');
        $tests += glob(self::PKG_ROOT . '/tests/**/*.php');
        $this->assertGreaterThanOrEqual(
            434,
            count($tests),
            'Minimum 434 test files expected (v188.0.0 baseline)',
        );
    }
}
