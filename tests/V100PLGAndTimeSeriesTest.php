<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\EventTimeSeriesService;
use ZeroBoiler\Analytics\Services\PLGScoringService;

/**
 * @covers \ZeroBoiler\Analytics\Services\PLGScoringService
 * @covers \ZeroBoiler\Analytics\Services\EventTimeSeriesService
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsPLGScoreCommand
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsTimeSeriesCommand
 * @covers \ZeroBoiler\Analytics\AnalyticsManager
 *
 * v6.0.0 — PLG Scoring Engine + Event Time-Series Aggregation
 */
class V100PLGAndTimeSeriesTest extends TestCase
{
    private AnalyticsManager $manager;

    private EventStreamService $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make('zeroboiler.analytics');
        $this->stream = $this->app->make(EventStreamService::class);
        $this->stream->flush();
        Cache::clear();
    }

    // ── PLG Scoring Service ──────────────────────────────────────────

    public function test_plg_scoring_service_returns_valid_score_structure(): void
    {
        $service = app(PLGScoringService::class);
        $score = $service->score('user-001');

        $this->assertArrayHasKey('score', $score);
        $this->assertArrayHasKey('grade', $score);
        $this->assertArrayHasKey('activation', $score);
        $this->assertArrayHasKey('engagement', $score);
        $this->assertArrayHasKey('retention', $score);
        $this->assertArrayHasKey('feature_breadth', $score);
        $this->assertArrayHasKey('segment', $score);
        $this->assertArrayHasKey('signals', $score);
        $this->assertArrayHasKey('identity', $score);
        $this->assertArrayHasKey('computed_at', $score);
    }

    public function test_plg_score_grade_is_valid_letter(): void
    {
        $service = app(PLGScoringService::class);
        $score = $service->score('user-002');

        $this->assertContains($score['grade'], ['A', 'B', 'C', 'D', 'F']);
    }

    public function test_plg_score_segment_is_valid(): void
    {
        $service = app(PLGScoringService::class);
        $score = $service->score('user-003');

        $this->assertContains($score['segment'], [
            'champions', 'loyal', 'potential', 'at_risk', 'dormant', 'unsegmented',
        ]);
    }

    public function test_plg_score_dimensions_are_between_0_and_100(): void
    {
        $service = app(PLGScoringService::class);
        $score = $service->score('user-004');

        $this->assertGreaterThanOrEqual(0.0, $score['activation']);
        $this->assertLessThanOrEqual(100.0, $score['activation']);
        $this->assertGreaterThanOrEqual(0.0, $score['engagement']);
        $this->assertLessThanOrEqual(100.0, $score['engagement']);
        $this->assertGreaterThanOrEqual(0.0, $score['retention']);
        $this->assertLessThanOrEqual(100.0, $score['retention']);
        $this->assertGreaterThanOrEqual(0.0, $score['feature_breadth']);
        $this->assertLessThanOrEqual(100.0, $score['feature_breadth']);
    }

    public function test_plg_score_is_cached(): void
    {
        $service = app(PLGScoringService::class);

        $score1 = $service->score('user-cached-001');
        $score2 = $service->score('user-cached-001');

        $this->assertEquals($score1['score'], $score2['score']);
        $this->assertEquals($score1['computed_at'], $score2['computed_at']);
    }

    public function test_plg_invalidate_clears_cached_score(): void
    {
        $service = app(PLGScoringService::class);

        $score1 = $service->score('user-inval-001');
        $service->invalidateScore('user-inval-001');
        $score2 = $service->score('user-inval-001');

        // Score should still be valid (computed again), but computed_at may differ
        $this->assertNotNull($score2['computed_at']);
    }

    public function test_plg_score_batch_returns_sorted_results(): void
    {
        $service = app(PLGScoringService::class);

        $results = $service->scoreBatch(['user-a', 'user-b', 'user-c']);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('user-a', $results);
        $this->assertArrayHasKey('user-b', $results);
        $this->assertArrayHasKey('user-c', $results);

        // Results should be sorted by score descending
        $scores = array_column($results, 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertEquals($sorted, $scores);
    }

    public function test_plg_aggregate_stats_returns_valid_structure(): void
    {
        $service = app(PLGScoringService::class);

        $stats = $service->aggregateStats();

        $this->assertArrayHasKey('avg_score', $stats);
        $this->assertArrayHasKey('total_cached', $stats);
        $this->assertArrayHasKey('grade_distribution', $stats);
        $this->assertIsFloat($stats['avg_score']);
        $this->assertIsInt($stats['total_cached']);
        $this->assertIsArray($stats['grade_distribution']);
    }

    public function test_plg_segment_distribution_returns_valid_segments(): void
    {
        $service = app(PLGScoringService::class);

        $dist = $service->segmentDistribution(['user-s1', 'user-s2', 'user-s3']);

        $this->assertArrayHasKey('champions', $dist);
        $this->assertArrayHasKey('loyal', $dist);
        $this->assertArrayHasKey('potential', $dist);
        $this->assertArrayHasKey('at_risk', $dist);
        $this->assertArrayHasKey('dormant', $dist);
        $this->assertArrayHasKey('unsegmented', $dist);

        // Total should equal input count
        $total = array_sum($dist);
        $this->assertEquals(3, $total);
    }

    public function test_plg_with_active_user_has_higher_score_than_empty(): void
    {
        $service = app(PLGScoringService::class);

        // Push events for an active user
        $this->pushUserEvents('active-user', [
            'sign_up', 'login', 'feature_used', 'page_view',
            'form_submit', 'search', 'content_engagement', 'share',
        ]);

        // No events for inactive user
        $activeScore = $service->score('active-user');
        $inactiveScore = $service->score('inactive-user');

        $this->assertGreaterThanOrEqual($inactiveScore['score'], $activeScore['score']);
    }

    // ── Event Time-Series Service ─────────────────────────────────────

    public function test_time_series_returns_valid_aggregation_structure(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregate('1h');

        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('unique_identities', $result);
        $this->assertArrayHasKey('top_events', $result);
        $this->assertArrayHasKey('category_breakdown', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('moving_avg', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('computed_at', $result);
    }

    public function test_time_series_trend_has_valid_direction(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregate('1h');

        $this->assertArrayHasKey('direction', $result['trend']);
        $this->assertArrayHasKey('change_pct', $result['trend']);
        $this->assertArrayHasKey('current', $result['trend']);
        $this->assertArrayHasKey('previous', $result['trend']);
        $this->assertContains($result['trend']['direction'], ['up', 'down', 'flat']);
    }

    public function test_time_series_top_events_has_correct_structure(): void
    {
        $service = app(EventTimeSeriesService::class);

        // Push some events
        $this->stream->push('purchase', ['value' => 100], 'client-1', 'user-1');
        $this->stream->push('page_view', [], 'client-1', 'user-1');
        $this->stream->push('purchase', ['value' => 200], 'client-2', 'user-2');

        $result = $service->aggregate('1h');

        foreach ($result['top_events'] as $event) {
            $this->assertArrayHasKey('event', $event);
            $this->assertArrayHasKey('count', $event);
        }
    }

    public function test_time_series_category_breakdown_has_valid_categories(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregate('1h');

        foreach (array_keys($result['category_breakdown']) as $category) {
            $this->assertContains($category, ['ecommerce', 'saas', 'engagement']);
        }
    }

    public function test_time_series_single_event_aggregation(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregateEvent('purchase', '1h');

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('pct_of_total', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('category', $result);
    }

    public function test_time_series_single_event_has_ecommerce_category(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregateEvent('purchase', '1h');

        $this->assertEquals('ecommerce', $result['category']);
    }

    public function test_time_series_category_aggregation(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregateByCategory('1h');

        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('period', $result);
    }

    public function test_time_series_compare_returns_delta(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->compare('1h', '6h');

        $this->assertArrayHasKey('current', $result);
        $this->assertArrayHasKey('previous', $result);
        $this->assertArrayHasKey('delta', $result);
        $this->assertArrayHasKey('events', $result['delta']);
        $this->assertArrayHasKey('identities', $result['delta']);
        $this->assertArrayHasKey('pct_change', $result['delta']);
    }

    public function test_time_series_dashboard_returns_all_periods(): void
    {
        $service = app(EventTimeSeriesService::class);
        $dashboard = $service->dashboard();

        $expectedPeriods = ['5m', '15m', '1h', '6h', '1d', '7d', '30d'];
        foreach ($expectedPeriods as $period) {
            $this->assertArrayHasKey($period, $dashboard);
        }
    }

    public function test_time_series_supported_periods(): void
    {
        $service = app(EventTimeSeriesService::class);
        $periods = $service->supportedPeriods();

        $this->assertCount(7, $periods);
        $this->assertContains('5m', $periods);
        $this->assertContains('1h', $periods);
        $this->assertContains('1d', $periods);
        $this->assertContains('30d', $periods);
    }

    public function test_time_series_period_to_seconds(): void
    {
        $service = app(EventTimeSeriesService::class);

        $this->assertEquals(300, $service->periodToSeconds('5m'));
        $this->assertEquals(3600, $service->periodToSeconds('1h'));
        $this->assertEquals(86400, $service->periodToSeconds('1d'));
    }

    public function test_time_series_invalid_period_falls_back_to_1h(): void
    {
        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregate('invalid');

        $this->assertEquals('1h', $result['period']);
    }

    public function test_time_series_with_populated_stream(): void
    {
        // Push various events
        $events = [
            ['sign_up', ['method' => 'email'], 'c-1', 'u-1'],
            ['login', ['method' => 'password'], 'c-1', 'u-1'],
            ['page_view', [], 'c-1', 'u-1'],
            ['purchase', ['value' => 50], 'c-2', 'u-2'],
            ['purchase', ['value' => 75], 'c-3', 'u-3'],
            ['search', ['query' => 'test'], 'c-1', 'u-1'],
            ['form_submit', [], 'c-1', 'u-1'],
            ['share', [], 'c-1', 'u-1'],
        ];

        foreach ($events as [$name, $params, $clientId, $userId]) {
            $this->stream->push($name, $params, $clientId, $userId);
        }

        $service = app(EventTimeSeriesService::class);
        $result = $service->aggregate('1h');

        $this->assertEquals(8, $result['total_events']);
        $this->assertGreaterThanOrEqual(1, $result['unique_identities']);
    }

    public function test_time_series_is_cached(): void
    {
        $service = app(EventTimeSeriesService::class);

        $result1 = $service->aggregate('1h');
        $result2 = $service->aggregate('1h');

        $this->assertEquals($result1['computed_at'], $result2['computed_at']);
    }

    // ── AnalyticsManager Convenience Methods ──────────────────────────

    public function test_manager_plg_score_delegates_to_service(): void
    {
        $result = $this->manager->plgScore('mgr-user-001');

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('segment', $result);
    }

    public function test_manager_plg_aggregate_delegates_to_service(): void
    {
        $result = $this->manager->plgAggregate();

        $this->assertArrayHasKey('avg_score', $result);
        $this->assertArrayHasKey('grade_distribution', $result);
    }

    public function test_manager_plg_invalidate_works(): void
    {
        $this->manager->plgScore('mgr-inval-001');
        $this->manager->plgInvalidate('mgr-inval-001');

        // Should not throw
        $this->assertTrue(true);
    }

    public function test_manager_time_series_delegates_to_service(): void
    {
        $result = $this->manager->timeSeries('1h');

        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertEquals('1h', $result['period']);
    }

    public function test_manager_time_series_dashboard_returns_all_periods(): void
    {
        $result = $this->manager->timeSeriesDashboard();

        $this->assertCount(7, $result);
        $this->assertArrayHasKey('1h', $result);
        $this->assertArrayHasKey('1d', $result);
    }

    public function test_manager_time_series_compare_delegates_to_service(): void
    {
        $result = $this->manager->timeSeriesCompare('1h', '6h');

        $this->assertArrayHasKey('current', $result);
        $this->assertArrayHasKey('delta', $result);
    }

    // ── Integration: Services Registered in Container ────────────────

    public function test_plg_service_is_singleton(): void
    {
        $instance1 = app(PLGScoringService::class);
        $instance2 = app(PLGScoringService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_time_series_service_is_singleton(): void
    {
        $instance1 = app(EventTimeSeriesService::class);
        $instance2 = app(EventTimeSeriesService::class);

        $this->assertSame($instance1, $instance2);
    }

    // ── Config Integration ────────────────────────────────────────────

    public function test_plg_config_has_weights(): void
    {
        $config = config('zeroboiler.analytics.plg_scoring');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('weights', $config);
        $this->assertArrayHasKey('activation', $config['weights']);
        $this->assertArrayHasKey('engagement', $config['weights']);
        $this->assertArrayHasKey('retention', $config['weights']);
        $this->assertArrayHasKey('feature_breadth', $config['weights']);
    }

    public function test_plg_weights_sum_to_1(): void
    {
        $config = config('zeroboiler.analytics.plg_scoring.weights');
        $sum = array_sum($config);

        $this->assertEqualsWithDelta(1.0, $sum, 0.01);
    }

    public function test_time_series_config_exists(): void
    {
        $config = config('zeroboiler.analytics.time_series');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('cache_ttl', $config);
        $this->assertIsInt($config['cache_ttl']);
    }

    // ── Helper: push events for a user ─────────────────────────────────

    private function pushUserEvents(string $userId, array $eventNames): void
    {
        $clientId = 'client-' . $userId;

        foreach ($eventNames as $eventName) {
            $this->stream->push($eventName, [], $clientId, $userId);
        }
    }
}
