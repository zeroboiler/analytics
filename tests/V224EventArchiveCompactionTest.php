<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\DTO\CompactionReport;
use ZeroBoiler\Analytics\DTO\CompactionResult;
use ZeroBoiler\Analytics\Services\EventArchiveCompactionService;

/**
 * Tests for Event Archive Compaction Service.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventArchiveCompactionService
 * @covers \ZeroBoiler\Analytics\DTO\CompactionResult
 * @covers \ZeroBoiler\Analytics\DTO\CompactionReport
 *
 * @since 224.0.0
 */
final class V224EventArchiveCompactionTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private EventArchiveCompactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        // Default config
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.archive_compaction', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 86400,
                'max_age_days' => 30,
                'sample_rate' => 0.1,
                'bytes_per_event' => 512,
                'aggregate_bucket_seconds' => 3600,
                'strategy_events' => [
                    'aggregate' => ['page_view', 'scroll_depth'],
                    'truncate' => [],
                    'sample' => ['click', 'hover'],
                    'expire' => ['consent_granted'],
                ],
                'event_ttl_overrides' => [],
            ]);

        $this->service = new EventArchiveCompactionService(
            $this->cache,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── Service Initialization ────────────────────────────────────────────

    public function test_service_is_enabled_by_default(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function test_service_can_be_disabled_via_config(): void
    {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.archive_compaction', Mockery::any())
            ->andReturn(['enabled' => false]);

        $service = new EventArchiveCompactionService($this->cache, $this->config);
        $this->assertFalse($service->isEnabled());
    }

    public function test_max_age_days_defaults_to_30(): void
    {
        $this->assertSame(30, $this->service->getMaxAgeDays());
    }

    public function test_max_age_days_from_config(): void
    {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.archive_compaction', Mockery::any())
            ->andReturn(['enabled' => true, 'max_age_days' => 90]);

        $service = new EventArchiveCompactionService($this->cache, $this->config);
        $this->assertSame(90, $service->getMaxAgeDays());
    }

    // ─── Strategy Detection ───────────────────────────────────────────────

    public function test_detects_aggregate_strategy_for_page_view(): void
    {
        $this->assertSame(
            EventArchiveCompactionService::STRATEGY_AGGREGATE,
            $this->service->detectStrategy('page_view'),
        );
    }

    public function test_detects_sample_strategy_for_click(): void
    {
        $this->assertSame(
            EventArchiveCompactionService::STRATEGY_SAMPLE,
            $this->service->detectStrategy('click'),
        );
    }

    public function test_detects_expire_strategy_for_ttl_override_event(): void
    {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.archive_compaction', Mockery::any())
            ->andReturn([
                'enabled' => true,
                'event_ttl_overrides' => ['custom_event' => 7],
                'strategy_events' => [],
            ]);

        $service = new EventArchiveCompactionService($this->cache, $this->config);
        $this->assertSame(
            EventArchiveCompactionService::STRATEGY_EXPIRE,
            $service->detectStrategy('custom_event'),
        );
    }

    public function test_detects_aggregate_strategy_for_unknown_event(): void
    {
        $this->assertSame(
            EventArchiveCompactionService::STRATEGY_AGGREGATE,
            $this->service->detectStrategy('totally_unknown_event'),
        );
    }

    public function test_all_strategies_constant_has_four_entries(): void
    {
        $this->assertCount(4, EventArchiveCompactionService::ALL_STRATEGIES);
        $this->assertContains(EventArchiveCompactionService::STRATEGY_AGGREGATE, EventArchiveCompactionService::ALL_STRATEGIES);
        $this->assertContains(EventArchiveCompactionService::STRATEGY_TRUNCATE, EventArchiveCompactionService::ALL_STRATEGIES);
        $this->assertContains(EventArchiveCompactionService::STRATEGY_SAMPLE, EventArchiveCompactionService::ALL_STRATEGIES);
        $this->assertContains(EventArchiveCompactionService::STRATEGY_EXPIRE, EventArchiveCompactionService::ALL_STRATEGIES);
    }

    // ─── Strategy Events ──────────────────────────────────────────────────

    public function test_get_strategy_events_returns_mapped_events(): void
    {
        $events = $this->service->getStrategyEvents();

        $this->assertArrayHasKey('aggregate', $events);
        $this->assertContains('page_view', $events['aggregate']);
        $this->assertContains('scroll_depth', $events['aggregate']);
        $this->assertArrayHasKey('sample', $events);
        $this->assertContains('click', $events['sample']);
    }

    // ─── Stats ─────────────────────────────────────────────────────────────

    public function test_stats_returns_expected_structure(): void
    {
        $stats = $this->service->stats();

        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('max_age_days', $stats);
        $this->assertArrayHasKey('sample_rate', $stats);
        $this->assertArrayHasKey('bytes_per_event', $stats);
        $this->assertArrayHasKey('aggregate_bucket_seconds', $stats);
        $this->assertArrayHasKey('strategies', $stats);
        $this->assertArrayHasKey('event_ttl_overrides', $stats);
        $this->assertArrayHasKey('all_strategies', $stats);
        $this->assertTrue($stats['enabled']);
        $this->assertSame(30, $stats['max_age_days']);
    }

    // ─── Estimate Savings ──────────────────────────────────────────────────

    public function test_estimate_savings_returns_structure(): void
    {
        $estimate = $this->service->estimateSavings();

        $this->assertArrayHasKey('strategies', $estimate);
        $this->assertArrayHasKey('total_savings_kb', $estimate);
        $this->assertArrayHasKey('total_compactable', $estimate);
        $this->assertIsArray($estimate['strategies']);
        $this->assertIsFloat($estimate['total_savings_kb']);
        $this->assertIsInt($estimate['total_compactable']);
    }

    public function test_estimate_savings_with_custom_max_age(): void
    {
        $estimate = $this->service->estimateSavings(60);
        $this->assertIsArray($estimate);
        $this->assertArrayHasKey('strategies', $estimate);
    }

    public function test_estimate_savings_truncate_strategy_has_highest_ratio(): void
    {
        $estimate = $this->service->estimateSavings();

        $truncate = $estimate['strategies']['truncate'] ?? null;
        if ($truncate !== null) {
            // Truncate should have compression ratio 1.0 (100% removed)
            $this->assertSame(1.0, $truncate['compression_ratio']);
        }
    }

    // ─── CompactionResult DTO ──────────────────────────────────────────────

    public function test_compaction_result_success_factory(): void
    {
        $result = CompactionResult::success(
            strategy: 'aggregate',
            scope: 'page_view',
            before: 1000,
            after: 100,
            bytesSaved: 460.8,
            dateRange: '2026-07-01:2026-08-01',
            durationMs: 42.5,
        );

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame('aggregate', $result->strategy);
        $this->assertSame(900, $result->eventsCompacted);
        $this->assertSame(0.1, $result->compressionRatio);
    }

    public function test_compaction_result_failure_factory(): void
    {
        $result = CompactionResult::failure('truncate', 'all', 'Connection lost');

        $this->assertFalse($result->success);
        $this->assertSame('Connection lost', $result->error);
        $this->assertSame(0, $result->eventsCompacted);
    }

    public function test_compaction_result_serialization(): void
    {
        $result = CompactionResult::success(
            strategy: 'sample',
            scope: 'click',
            before: 500,
            after: 50,
            bytesSaved: 230.4,
            dateRange: '2026-01-01:2026-02-01',
            durationMs: 12.3,
        );

        $arr = $result->toArray();
        $this->assertArrayHasKey('strategy', $arr);
        $this->assertArrayHasKey('scope', $arr);
        $this->assertArrayHasKey('events_before', $arr);
        $this->assertArrayHasKey('success', $arr);
        $this->assertArrayHasKey('compression_ratio', $arr);
    }

    public function test_compaction_result_roundtrip_serialization(): void
    {
        $original = CompactionResult::success(
            strategy: 'aggregate',
            scope: 'page_view',
            before: 100,
            after: 10,
            bytesSaved: 46.08,
            dateRange: '2026-01-01:2026-02-01',
            durationMs: 5.0,
        );

        $restored = CompactionResult::fromArray($original->toArray());

        $this->assertSame($original->strategy, $restored->strategy);
        $this->assertSame($original->scope, $restored->scope);
        $this->assertSame($original->eventsBefore, $restored->eventsBefore);
        $this->assertSame($original->eventsAfter, $restored->eventsAfter);
        $this->assertSame($original->success, $restored->success);
        $this->assertSame($original->compressionRatio, $restored->compressionRatio);
    }

    public function test_compaction_result_zero_division_safety(): void
    {
        $result = CompactionResult::success(
            strategy: 'aggregate',
            scope: 'empty',
            before: 0,
            after: 0,
            bytesSaved: 0.0,
            dateRange: '2026-01-01:2026-02-01',
            durationMs: 0.0,
        );

        $this->assertSame(0.0, $result->compressionRatio);
    }

    // ─── CompactionReport DTO ─────────────────────────────────────────────

    public function test_compaction_report_from_results(): void
    {
        $results = [
            CompactionResult::success('aggregate', 'page_view', 1000, 100, 460.0, '2026-07-01:2026-08-01', 50.0),
            CompactionResult::success('sample', 'click', 500, 50, 230.0, '2026-07-01:2026-08-01', 25.0),
        ];

        $report = CompactionReport::fromResults(
            dateRange: '2026-07-01:2026-08-01',
            results: $results,
            durationMs: 75.0,
        );

        $this->assertSame(1500, $report->totalEventsBefore);
        $this->assertSame(150, $report->totalEventsAfter);
        $this->assertSame(1350, $report->totalEventsCompacted);
        $this->assertSame(690.0, $report->totalBytesSaved);
        $this->assertSame(2, $report->successfulScopes);
        $this->assertSame(0, $report->failedScopes);
        $this->assertIsString($report->healthGrade);
        $this->assertCount(2, $report->results);
    }

    public function test_compaction_report_serialization(): void
    {
        $results = [
            CompactionResult::success('aggregate', 'page_view', 100, 10, 46.0, '2026-07-01:2026-08-01', 5.0),
        ];

        $report = CompactionReport::fromResults(
            dateRange: '2026-07-01:2026-08-01',
            results: $results,
            durationMs: 10.0,
        );

        $arr = $report->toArray();
        $this->assertArrayHasKey('date_range', $arr);
        $this->assertArrayHasKey('total_events_before', $arr);
        $this->assertArrayHasKey('total_events_after', $arr);
        $this->assertArrayHasKey('health_grade', $arr);
        $this->assertArrayHasKey('recommendations', $arr);
        $this->assertArrayHasKey('results', $arr);
        $this->assertCount(1, $arr['results']);
    }

    public function test_compaction_report_grade_a_with_high_savings(): void
    {
        $results = [
            CompactionResult::success('aggregate', 'page_view', 10000, 100, 50000.0, '2026-07-01:2026-08-01', 100.0),
        ];

        $report = CompactionReport::fromResults(
            dateRange: '2026-07-01:2026-08-01',
            results: $results,
            durationMs: 100.0,
            storageBudgetKb: 100000.0, // 100 MB budget, 50MB savings = 50%
        );

        // 50% of budget saved → grade A
        $this->assertSame('A', $report->healthGrade);
    }

    public function test_compaction_report_grade_d_with_failures(): void
    {
        $results = [
            CompactionResult::failure('aggregate', 'page_view', 'Connection error'),
        ];

        $report = CompactionReport::fromResults(
            dateRange: '2026-07-01:2026-08-01',
            results: $results,
            durationMs: 10.0,
        );

        $this->assertSame('D', $report->healthGrade);
    }

    // ─── Full Compaction ───────────────────────────────────────────────────

    public function test_compact_returns_report(): void
    {
        // Setup cache expectations for archive counting and compacted storage
        $this->cache->shouldReceive('get')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $report = $this->service->compact();

        $this->assertInstanceOf(CompactionReport::class, $report);
        $this->assertArrayHasKey('total_events_before', $report->toArray());
        $this->assertArrayHasKey('total_events_after', $report->toArray());
        $this->assertArrayHasKey('health_grade', $report->toArray());
    }

    public function test_compact_with_custom_max_age(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $report = $this->service->compact(60);

        $this->assertInstanceOf(CompactionReport::class, $report);
    }

    // ─── Single Event Compaction ──────────────────────────────────────────

    public function test_compact_event_returns_result(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $result = $this->service->compactEvent('page_view');

        $this->assertInstanceOf(CompactionResult::class, $result);
        $this->assertSame(EventArchiveCompactionService::STRATEGY_AGGREGATE, $result->strategy);
    }

    public function test_compact_event_with_strategy_override(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $result = $this->service->compactEvent('page_view', 'truncate');

        $this->assertSame(EventArchiveCompactionService::STRATEGY_TRUNCATE, $result->strategy);
    }

    // ─── Strategy-Specific Compaction ─────────────────────────────────────

    public function test_compact_by_strategy_returns_result(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $result = $this->service->compactByStrategy('aggregate');

        $this->assertInstanceOf(CompactionResult::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_compact_by_unknown_strategy_returns_failure(): void
    {
        $result = $this->service->compactByStrategy('nonexistent');

        $this->assertInstanceOf(CompactionResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
    }

    public function test_compact_by_empty_strategy_returns_failure(): void
    {
        $result = $this->service->compactByStrategy('truncate');

        $this->assertInstanceOf(CompactionResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('No events configured', $result->error);
    }

    // ─── History ───────────────────────────────────────────────────────────

    public function test_get_history_returns_empty_array_by_default(): void
    {
        $this->cache->shouldReceive('get')
            ->with('zb_compaction_history', Mockery::any())
            ->andReturn([]);

        $this->assertSame([], $this->service->getHistory());
    }

    public function test_get_history_returns_cached_entries(): void
    {
        $this->cache->shouldReceive('get')
            ->with('zb_compaction_history', Mockery::any())
            ->andReturn([
                ['strategy' => 'aggregate', 'scope' => 'page_view', 'success' => true],
                ['strategy' => 'sample', 'scope' => 'click', 'success' => true],
            ]);

        $history = $this->service->getHistory();
        $this->assertCount(2, $history);
    }

    // ─── Cache Clear ───────────────────────────────────────────────────────

    public function test_clear_cache_forgets_keys(): void
    {
        $this->cache->shouldReceive('forget')
            ->with('zb_compacted_events')
            ->once();
        $this->cache->shouldReceive('forget')
            ->with('zb_compaction_history')
            ->once();

        $this->service->clearCache();
    }
}
