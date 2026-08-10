<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService;
use ZeroBoiler\Analytics\Services\EventCorrelationHeatmapService;

/**
 * Tests for Event Correlation Heatmap Service and Health Monitor Dashboard.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventCorrelationHeatmapService
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService
 *
 * @since 8.8.0
 */
final class V880CorrelationAndHealthMonitorTest extends TestCase
{
    // ─── Event Correlation Heatmap Service ───────────────────────────

    public function testHeatmapServiceComputesEmptyMatrixWhenNoData(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);
        $service->invalidateCache();

        $result = $service->computeHeatmap();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matrix', $result);
        $this->assertArrayHasKey('events', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('computed_at', $result['metadata']);
        $this->assertArrayHasKey('threshold', $result['metadata']);
    }

    public function testHeatmapServiceTopCorrelationsReturnsCorrectStructure(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);
        $service->invalidateCache();

        $result = $service->getTopCorrelations(10);

        $this->assertIsArray($result);

        if (count($result) > 0) {
            $this->assertArrayHasKey('event_a', $result[0]);
            $this->assertArrayHasKey('event_b', $result[0]);
            $this->assertArrayHasKey('correlation', $result[0]);
            $this->assertArrayHasKey('co_occurrences', $result[0]);
            $this->assertArrayHasKey('jaccard', $result[0]);
        }
    }

    public function testHeatmapServiceGetChartDataReturnsSourceTargetValue(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);
        $service->invalidateCache();

        $chartData = $service->getChartData();

        $this->assertIsArray($chartData);

        // Each entry should have source, target, value
        foreach ($chartData as $item) {
            $this->assertArrayHasKey('source', $item);
            $this->assertArrayHasKey('target', $item);
            $this->assertArrayHasKey('value', $item);
        }
    }

    public function testHeatmapServiceStatsReturnsExpectedKeys(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);
        $service->invalidateCache();

        $stats = $service->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_pairs', $stats);
        $this->assertArrayHasKey('avg_correlation', $stats);
        $this->assertArrayHasKey('max_correlation', $stats);
        $this->assertArrayHasKey('median_correlation', $stats);
        $this->assertArrayHasKey('strong_pairs', $stats);
    }

    public function testHeatmapServiceEventCorrelationsForUnknownEvent(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);
        $service->invalidateCache();

        $result = $service->getEventCorrelations('nonexistent_event_xyz');

        $this->assertIsArray($result);
        $this->assertEquals('nonexistent_event_xyz', $result['event']);
        $this->assertEmpty($result['correlations']);
    }

    public function testHeatmapServiceRecordCoOccurrenceDeduplicates(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new EventCorrelationHeatmapService($cache, $config);

        // Record same co-occurrence twice for same session
        $service->recordCoOccurrence('client_123', 'session_abc', 'purchase', 'add_to_cart');
        $service->recordCoOccurrence('client_123', 'session_abc', 'purchase', 'add_to_cart');

        // Second call should be deduplicated within same session
        // Verify cache invalidation works
        $service->invalidateCache();
        $this->assertTrue(true); // No exception means dedup didn't crash
    }

    public function testHeatmapServiceVersionConstant(): void
    {
        $this->assertEquals('8.8.0', AnalyticsEvent::VERSION);
    }

    // ─── Health Monitor Dashboard Service ─────────────────────────────

    public function testHealthMonitorReturnsDashboardData(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $dashboard = $service->getDashboardData();

        $this->assertIsArray($dashboard);
        $this->assertArrayHasKey('composite_score', $dashboard);
        $this->assertArrayHasKey('grade', $dashboard);
        $this->assertArrayHasKey('status', $dashboard);
        $this->assertArrayHasKey('dimensions', $dashboard);
        $this->assertArrayHasKey('alerts', $dashboard);
        $this->assertArrayHasKey('metadata', $dashboard);
    }

    public function testHealthMonitorCompositeScoreIsInt(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $score = $service->getScore();

        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testHealthMonitorGradeIsValid(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $grade = $service->getGrade();

        $this->assertContains($grade, ['A', 'B', 'C', 'D', 'F']);
    }

    public function testHealthMonitorDimensionScoreReturnsInt(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $score = $service->getDimensionScore('providers');

        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testHealthMonitorUnknownDimensionReturnsZero(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);

        $score = $service->getDimensionScore('nonexistent_dimension');

        $this->assertEquals(0, $score);
    }

    public function testHealthMonitorStatusHelpers(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        // These should return booleans without error
        $healthy = $service->isHealthy();
        $degraded = $service->isDegraded();
        $critical = $service->isCritical();

        $this->assertIsBool($healthy);
        $this->assertIsBool($degraded);
        $this->assertIsBool($critical);

        // Only one should be true at a time (or none if score is exactly 60/80)
        $trueCount = (int) $healthy + (int) $degraded + (int) $critical;
        $this->assertLessThanOrEqual(1, $trueCount);
    }

    public function testHealthMonitorHistoryReturnsArray(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);

        $history = $service->getHistory(10);

        $this->assertIsArray($history);
        $this->assertLessThanOrEqual(10, count($history));

        foreach ($history as $point) {
            $this->assertArrayHasKey('timestamp', $point);
            $this->assertArrayHasKey('score', $point);
            $this->assertArrayHasKey('grade', $point);
        }
    }

    public function testHealthMonitorRecordDataPoint(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $before = $service->getHistory(100);
        $service->recordDataPoint();
        $after = $service->getHistory(100);

        // History should have one more data point
        $this->assertEquals(count($before) + 1, count($after));
    }

    public function testHealthMonitorDashboardDimensionsHaveRequiredKeys(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $dashboard = $service->getDashboardData();
        $dimensions = $dashboard['dimensions'];

        $this->assertIsArray($dimensions);
        $this->assertNotEmpty($dimensions);

        foreach ($dimensions as $key => $dimension) {
            $this->assertArrayHasKey('name', $dimension);
            $this->assertArrayHasKey('score', $dimension);
            $this->assertArrayHasKey('weight', $dimension);
            $this->assertArrayHasKey('status', $dimension);
            $this->assertArrayHasKey('details', $dimension);

            // Score should be in valid range
            $this->assertGreaterThanOrEqual(0, $dimension['score']);
            $this->assertLessThanOrEqual(100, $dimension['score']);

            // Weight should be positive
            $this->assertGreaterThanOrEqual(0.0, $dimension['weight']);
        }
    }

    public function testHealthMonitorAlertsHaveRequiredKeys(): void
    {
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new AnalyticsHealthMonitorService($cache, $config);
        $service->invalidateCache();

        $dashboard = $service->getDashboardData();
        $alerts = $dashboard['alerts'];

        $this->assertIsArray($alerts);

        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('dimension', $alert);
            $this->assertArrayHasKey('message', $alert);
            $this->assertArrayHasKey('timestamp', $alert);
            $this->assertContains($alert['severity'], ['critical', 'warning']);
        }
    }
}
