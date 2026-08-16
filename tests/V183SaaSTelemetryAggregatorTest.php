<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSTelemetryAggregatorService;

/**
 * Tests for the SaaS Telemetry Aggregation Service.
 *
 * Validates:
 * - Event recording and provider stats tracking
 * - Category and event name distribution
 * - Latency percentile computation
 * - Provider health status computation
 * - Summary generation
 * - Provider details
 * - Category breakdown
 * - Anomaly detection with baseline
 * - Quick overview
 * - Flush operation
 * - Baseline save/load
 *
 * @since 183.0.0
 */
final class V183SaaSTelemetryAggregatorTest extends TestCase
{
    private SaaSTelemetryAggregatorService $service;

    private array $cacheStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn(string $key, mixed $default = null) => $this->cacheStore[$key] ?? $default);
        $cache->method('put')->willReturnCallback(function (string $key, mixed $value, int $ttl = 60): bool {
            $this->cacheStore[$key] = $value;

            return true;
        });
        $cache->method('has')->willReturnCallback(fn(string $key): bool => isset($this->cacheStore[$key]));

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.saas_telemetry.cache_prefix', 'zb_telemetry_', 'zb_telemetry_'],
            ['zeroboiler.analytics.saas_telemetry.cache_ttl', 60, 60],
        ]);

        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);

        $this->service = new SaaSTelemetryAggregatorService($cache, $config, $manager);
        $this->cacheStore = [];
    }

    public function testRecordIncrementsProviderStats(): void
    {
        $event = new AnalyticsEvent(name: 'page_view', category: 'engagement');

        $this->service->record([
            'provider' => 'ga4',
            'success' => true,
            'latency_ms' => 50.0,
            'event' => $event,
        ]);

        $summary = $this->service->summary();

        $this->assertSame(1, $summary['providers']['ga4']['total']);
        $this->assertSame(1, $summary['providers']['ga4']['success']);
        $this->assertSame(0, $summary['providers']['ga4']['failure']);
    }

    public function testRecordTracksFailure(): void
    {
        $event = new AnalyticsEvent(name: 'purchase', category: 'ecommerce');

        $this->service->record([
            'provider' => 'meta_pixel',
            'success' => false,
            'latency_ms' => 200.0,
            'event' => $event,
        ]);

        $details = $this->service->providerDetails('meta_pixel');

        $this->assertNotNull($details);
        $this->assertSame(1, $details['stats']['failure']);
    }

    public function testCategoryBreakdownTracksCategories(): void
    {
        $e1 = new AnalyticsEvent(name: 'page_view', category: 'engagement');
        $e2 = new AnalyticsEvent(name: 'click', category: 'engagement');
        $e3 = new AnalyticsEvent(name: 'purchase', category: 'ecommerce');

        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $e1]);
        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 20.0, 'event' => $e2]);
        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 30.0, 'event' => $e3]);

        $breakdown = $this->service->categoryBreakdown();

        $this->assertSame(2, $breakdown['categories']['engagement']);
        $this->assertSame(1, $breakdown['categories']['ecommerce']);
        $this->assertSame(3, $breakdown['total']);
    }

    public function testLatencyPercentilesComputed(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');

        // Record 10 events with increasing latency
        for ($i = 1; $i <= 10; $i++) {
            $this->service->record([
                'provider' => 'ga4',
                'success' => true,
                'latency_ms' => (float) ($i * 10),
                'event' => $event,
            ]);
        }

        $details = $this->service->providerDetails('ga4');

        $this->assertNotNull($details);
        $this->assertNotNull($details['latency']['p50']);
        $this->assertNotNull($details['latency']['p95']);
        $this->assertNotNull($details['latency']['p99']);
        $this->assertSame(10, $details['latency']['samples']);
    }

    public function testHealthStatusHealthyForHighSuccessRate(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');

        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 100.0, 'event' => $event]);

        $details = $this->service->providerDetails('ga4');

        $this->assertNotNull($details);
        $this->assertSame('healthy', $details['health']);
    }

    public function testHealthStatusDownForLowSuccessRate(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');

        // Record failures
        for ($i = 0; $i < 10; $i++) {
            $this->service->record([
                'provider' => 'ga4',
                'success' => $i < 2,
                'latency_ms' => 1500.0,
                'event' => $event,
            ]);
        }

        $summary = $this->service->summary();

        $this->assertSame('down', $summary['providers']['ga4']['health']);
    }

    public function testTopEventsRanked(): void
    {
        $e1 = new AnalyticsEvent(name: 'page_view', category: 'engagement');
        $e2 = new AnalyticsEvent(name: 'click', category: 'engagement');

        for ($i = 0; $i < 5; $i++) {
            $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $e1]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $e2]);
        }

        $summary = $this->service->summary();
        $topEvents = $summary['top_events'];

        $this->assertArrayHasKey('page_view', $topEvents);
        $this->assertArrayHasKey('click', $topEvents);
        // page_view should be first (higher count)
        $this->assertSame('page_view', array_key_first($topEvents));
    }

    public function testProviderDetailsReturnsNullForUnknown(): void
    {
        $this->assertNull($this->service->providerDetails('nonexistent'));
    }

    public function testFlushClearsAllData(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');
        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $event]);

        $this->service->flush();

        $summary = $this->service->summary();
        $this->assertSame(0, $summary['totals']['events']);
        $this->assertSame(0, $summary['totals']['providers']);
    }

    public function testQuickOverviewReturnsCompactData(): void
    {
        $event = new AnalyticsEvent(name: 'page_view', category: 'engagement');
        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 50.0, 'event' => $event]);

        $overview = $this->service->quickOverview();

        $this->assertSame(1, $overview['providers']);
        $this->assertSame(1, $overview['events_total']);
        $this->assertSame(1, $overview['healthy']);
        $this->assertSame('engagement', $overview['top_category']);
        $this->assertSame('page_view', $overview['top_event']);
    }

    public function testAnomalyDetectionWithBaseline(): void
    {
        // Record baseline events
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');
        for ($i = 0; $i < 10; $i++) {
            $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $event]);
        }
        $this->service->saveBaseline();

        // Clear and record a spike
        $this->service->flush();
        for ($i = 0; $i < 50; $i++) {
            $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $event]);
        }

        $anomalies = $this->service->detectAnomalies();

        // Should detect a spike (50 vs baseline 10 = 5x = 400% deviation > 200% threshold)
        $this->assertNotEmpty($anomalies);
        $spikeAnomalies = array_filter($anomalies, fn(array $a): bool => $a['type'] === 'spike');
        $this->assertNotEmpty($spikeAnomalies);
    }

    public function testAnomalyDetectionReturnsEmptyWithoutBaseline(): void
    {
        $anomalies = $this->service->detectAnomalies();
        $this->assertEmpty($anomalies);
    }

    public function testSummaryUptimeCountsCorrect(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');

        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 50.0, 'event' => $event]);
        $this->service->record(['provider' => 'meta', 'success' => true, 'latency_ms' => 100.0, 'event' => $event]);

        $summary = $this->service->summary();

        $this->assertSame(2, $summary['uptime']['healthy']);
        $this->assertSame(0, $summary['uptime']['degraded']);
        $this->assertSame(0, $summary['uptime']['down']);
    }

    public function testWindowCountersIncremented(): void
    {
        $event = new AnalyticsEvent(name: 'click', category: 'engagement');

        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $event]);
        $this->service->record(['provider' => 'ga4', 'success' => true, 'latency_ms' => 10.0, 'event' => $event]);

        $summary = $this->service->summary();

        $this->assertSame(2, $summary['windows']['1m']);
        $this->assertSame(2, $summary['windows']['5m']);
    }
}
