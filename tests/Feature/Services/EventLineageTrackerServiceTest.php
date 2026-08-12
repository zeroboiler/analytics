<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Services\EventLineageTrackerService;

/**
 * Tests for the Event Lineage Tracker Service.
 *
 * Verifies that the lineage tracker correctly records event lifecycle stages,
 * retrieves entries, computes statistics, identifies failure patterns, and
 * supports purge operations for GDPR compliance.
 *
 * @since 49.0.0
 */
final class EventLineageTrackerServiceTest extends \Orchestra\Testbench\TestCase
{
    private EventLineageTrackerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EventLineageTrackerService(
            Cache::store('array'),
            $this->app->make('config'),
        );
    }

    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('zeroboiler.analytics.event_lineage', [
            'enabled' => true,
            'cache_prefix' => 'test_lineage_',
            'retention_ttl' => 3600,
            'max_entries' => 100,
            'auto_track' => false,
            'track_enrichment' => true,
            'track_providers' => true,
            'skip_stages' => [],
        ]);
    }

    /** @test */
    public function it_can_generate_lineage_id(): void
    {
        $id = $this->service->generateLineageId();

        $this->assertIsString($id);
        $this->assertEquals(12, strlen($id));
        $this->assertNotEquals($id, $this->service->generateLineageId());
    }

    /** @test */
    public function it_reports_enabled_status(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    /** @test */
    public function it_reports_auto_track_status(): void
    {
        $this->assertFalse($this->service->isAutoTrackEnabled());
    }

    /** @test */
    public function it_records_source_origin(): void
    {
        $id = $this->service->generateLineageId();
        $result = $this->service->recordSource(
            lineageId: $id,
            eventName: 'purchase',
            source: 'api',
            clientId: 'client_123',
            userId: 'user_456',
            context: ['ip' => '1.2.3.4'],
        );

        $this->assertTrue($result);
        $entry = $this->service->getLineage($id);

        $this->assertNotNull($entry);
        $this->assertEquals('purchase', $entry['event_name']);
        $this->assertEquals('api', $entry['source']);
        $this->assertEquals('client_123', $entry['client_id']);
        $this->assertEquals('user_456', $entry['user_id']);
        $this->assertEquals('in_progress', $entry['status']);
        $this->assertEquals(['ip' => '1.2.3.4'], $entry['source_context']);
    }

    /** @test */
    public function it_records_enrichment_stages(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'signup', 'server');

        $result = $this->service->recordEnrichmentStage(
            lineageId: $id,
            stage: 'utm',
            modified: true,
            details: ['utm_source' => 'google'],
            durationMs: 0.5,
        );

        $this->assertTrue($result);
        $entry = $this->service->getLineage($id);

        $this->assertNotNull($entry);
        $this->assertCount(1, $entry['enrichment_stages']);
        $this->assertEquals('utm', $entry['enrichment_stages'][0]['stage']);
        $this->assertTrue($entry['enrichment_stages'][0]['modified']);
        $this->assertEquals(0.5, $entry['enrichment_stages'][0]['duration_ms']);
    }

    /** @test */
    public function it_skips_configured_stages(): void
    {
        $this->app['config']->set('zeroboiler.analytics.event_lineage.skip_stages', ['timestamp']);
        $service = new EventLineageTrackerService(Cache::store('array'), $this->app->make('config'));

        $id = $service->generateLineageId();
        $service->recordSource($id, 'page_view', 'client');
        $result = $service->recordEnrichmentStage($id, 'timestamp', false);

        // Should succeed but not record
        $this->assertTrue($result);
        $entry = $service->getLineage($id);
        $this->assertCount(0, $entry['enrichment_stages']);
    }

    /** @test */
    public function it_records_provider_dispatches(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'purchase', 'api');

        $this->service->recordProviderDispatch($id, 'ga4', true, 45.2);
        $this->service->recordProviderDispatch($id, 'meta', false, 120.0, 'Network timeout');

        $entry = $this->service->getLineage($id);

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry['provider_dispatches']);
        $this->assertEquals('ga4', $entry['provider_dispatches'][0]['provider']);
        $this->assertTrue($entry['provider_dispatches'][0]['success']);
        $this->assertEquals('meta', $entry['provider_dispatches'][1]['provider']);
        $this->assertFalse($entry['provider_dispatches'][1]['success']);
        $this->assertEquals('Network timeout', $entry['provider_dispatches'][1]['error']);
    }

    /** @test */
    public function it_records_completion(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'purchase', 'api');

        $result = $this->service->recordCompletion($id, 'delivered', ['providers_sent' => 2]);

        $this->assertTrue($result);
        $entry = $this->service->getLineage($id);

        $this->assertNotNull($entry);
        $this->assertEquals('delivered', $entry['status']);
        $this->assertNotNull($entry['completed_at']);
        $this->assertNotNull($entry['total_duration_ms']);
        $this->assertEquals(['providers_sent' => 2], $entry['summary']);
    }

    /** @test */
    public function it_gets_recent_lineages(): void
    {
        $id1 = $this->service->generateLineageId();
        $id2 = $this->service->generateLineageId();
        $this->service->recordSource($id1, 'purchase', 'api');
        $this->service->recordCompletion($id1, 'delivered');
        $this->service->recordSource($id2, 'signup', 'server');
        $this->service->recordCompletion($id2, 'failed');

        $recent = $this->service->getRecentLineages(limit: 10);

        $this->assertCount(2, $recent);
    }

    /** @test */
    public function it_filters_recent_lineages_by_event_name(): void
    {
        $id1 = $this->service->generateLineageId();
        $id2 = $this->service->generateLineageId();
        $this->service->recordSource($id1, 'purchase', 'api');
        $this->service->recordSource($id2, 'signup', 'server');

        $filtered = $this->service->getRecentLineages(limit: 10, eventName: 'purchase');

        $this->assertCount(1, $filtered);
        $this->assertEquals('purchase', $filtered[0]['event_name']);
    }

    /** @test */
    public function it_filters_recent_lineages_by_status(): void
    {
        $id1 = $this->service->generateLineageId();
        $id2 = $this->service->generateLineageId();
        $this->service->recordSource($id1, 'page_view', 'client');
        $this->service->recordCompletion($id1, 'delivered');
        $this->service->recordSource($id2, 'page_view', 'client');
        $this->service->recordCompletion($id2, 'failed');

        $failed = $this->service->getRecentLineages(limit: 10, status: 'failed');

        $this->assertCount(1, $failed);
        $this->assertEquals('failed', $failed[0]['status']);
    }

    /** @test */
    public function it_computes_stats(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'purchase', 'api');
        $this->service->recordProviderDispatch($id, 'ga4', true, 10.0);
        $this->service->recordCompletion($id, 'delivered');

        $stats = $this->service->getStats();

        $this->assertEquals(1, $stats['total_tracked']);
        $this->assertEquals(1, $stats['delivered']);
        $this->assertEquals(0, $stats['failed']);
        $this->assertNotNull($stats['avg_duration_ms']);
        $this->assertEquals(['api' => 1], $stats['by_source']);
        $this->assertEquals(['ga4' => 1], $stats['by_provider_success']);
    }

    /** @test */
    public function it_identifies_failure_patterns(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'purchase', 'api');
        $this->service->recordProviderDispatch($id, 'ga4', false, null, 'Connection refused');
        $this->service->recordCompletion($id, 'failed');

        $patterns = $this->service->getFailurePatterns();

        $this->assertCount(1, $patterns);
        $this->assertEquals('ga4:Connection refused', $patterns[0]['pattern']);
        $this->assertEquals(1, $patterns[0]['count']);
    }

    /** @test */
    public function it_computes_stage_performance(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'signup', 'server');
        $this->service->recordEnrichmentStage($id, 'utm', true, durationMs: 1.0);
        $this->service->recordEnrichmentStage($id, 'metadata', false, durationMs: 0.3);

        $stageStats = $this->service->getStagePerformanceStats();

        $this->assertArrayHasKey('utm', $stageStats);
        $this->assertEquals(1, $stageStats['utm']['count']);
        $this->assertEquals(1.0, $stageStats['utm']['avg_ms']);
        $this->assertArrayHasKey('metadata', $stageStats);
    }

    /** @test */
    public function it_computes_provider_reliability(): void
    {
        $id1 = $this->service->generateLineageId();
        $id2 = $this->service->generateLineageId();
        $this->service->recordSource($id1, 'purchase', 'api');
        $this->service->recordProviderDispatch($id1, 'ga4', true, 10.0);
        $this->service->recordSource($id2, 'signup', 'server');
        $this->service->recordProviderDispatch($id2, 'ga4', true, 15.0);
        $this->service->recordProviderDispatch($id2, 'meta', false, 50.0, 'Timeout');

        $reliability = $this->service->getProviderReliabilityStats();

        $this->assertArrayHasKey('ga4', $reliability);
        $this->assertEquals(2, $reliability['ga4']['total']);
        $this->assertEquals(2, $reliability['ga4']['success']);
        $this->assertEquals(100.0, $reliability['ga4']['success_rate']);
        $this->assertEquals(12.5, $reliability['ga4']['avg_duration_ms']);

        $this->assertArrayHasKey('meta', $reliability);
        $this->assertEquals(1, $reliability['meta']['failure']);
        $this->assertEquals(0.0, $reliability['meta']['success_rate']);
    }

    /** @test */
    public function it_purges_all_entries(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'page_view', 'client');

        $this->assertEquals(1, $this->service->count());

        $purged = $this->service->purge();

        $this->assertEquals(1, $purged);
        $this->assertEquals(0, $this->service->count());
        $this->assertNull($this->service->getLineage($id));
    }

    /** @test */
    public function it_purges_before_timestamp(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'page_view', 'client');

        // Purge entries older than far in the future (should not purge current)
        $futureTimestamp = microtime(true) + 3600;
        $purged = $this->service->purgeBefore($futureTimestamp);

        $this->assertEquals(1, $purged);
        $this->assertEquals(0, $this->service->count());
    }

    /** @test */
    public function it_exports_for_compliance(): void
    {
        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'purchase', 'api');
        $this->service->recordCompletion($id, 'delivered');

        $export = $this->service->exportForCompliance();

        $this->assertArrayHasKey('entries', $export);
        $this->assertArrayHasKey('exported_at', $export);
        $this->assertArrayHasKey('total', $export);
        $this->assertEquals(1, $export['total']);
        $this->assertCount(1, $export['entries']);
    }

    /** @test */
    public function it_does_not_record_when_disabled(): void
    {
        $this->app['config']->set('zeroboiler.analytics.event_lineage.enabled', false);
        $service = new EventLineageTrackerService(Cache::store('array'), $this->app->make('config'));

        $id = $service->generateLineageId();
        $result = $service->recordSource($id, 'purchase', 'api');

        $this->assertFalse($result);
        $this->assertNull($service->getLineage($id));
    }

    /** @test */
    public function it_does_not_track_enrichment_when_disabled(): void
    {
        $this->app['config']->set('zeroboiler.analytics.event_lineage.track_enrichment', false);
        $service = new EventLineageTrackerService(Cache::store('array'), $this->app->make('config'));

        $id = $service->generateLineageId();
        $service->recordSource($id, 'purchase', 'api');
        $result = $service->recordEnrichmentStage($id, 'utm', true);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_does_not_track_providers_when_disabled(): void
    {
        $this->app['config']->set('zeroboiler.analytics.event_lineage.track_providers', false);
        $service = new EventLineageTrackerService(Cache::store('array'), $this->app->make('config'));

        $id = $service->generateLineageId();
        $service->recordSource($id, 'purchase', 'api');
        $result = $service->recordProviderDispatch($id, 'ga4', true);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_lineage(): void
    {
        $this->assertNull($this->service->getLineage('nonexistent'));
    }

    /** @test */
    public function it_has_correct_entry_count(): void
    {
        $this->assertEquals(0, $this->service->count());

        $id = $this->service->generateLineageId();
        $this->service->recordSource($id, 'page_view', 'client');

        $this->assertEquals(1, $this->service->count());
    }
}
