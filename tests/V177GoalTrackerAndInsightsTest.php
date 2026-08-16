<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsGoal;
use ZeroBoiler\Analytics\DTO\GoalProgress;
use ZeroBoiler\Analytics\Services\AnalyticsGoalTracker;
use ZeroBoiler\Analytics\Services\RollingWindowAnalyticsEngine;
use ZeroBoiler\Analytics\Services\SaaSQuickInsightsService;

/**
 * Tests for v177.0.0 — Goal Tracker, Rolling Window Analytics Engine, and Quick Insights.
 *
 * @covers \ZeroBoiler\Analytics\DTO\AnalyticsGoal
 * @covers \ZeroBoiler\Analytics\DTO\GoalProgress
 * @covers \ZeroBoiler\Analytics\Services\RollingWindowAnalyticsEngine
 * @covers \ZeroBoiler\Analytics\Services\SaaSQuickInsightsService
 *
 * @since 177.0.0
 */
final class V177GoalTrackerAndInsightsTest extends TestCase
{
    // ─── AnalyticsGoal DTO Tests ────────────────────────────────────────

    public function testGoalDtoConstruction(): void
    {
        $goal = new AnalyticsGoal(
            key: 'daily_signups',
            name: 'Daily Signups',
            description: 'Track daily new registrations',
            target: 100.0,
            metric: 'sign_up',
            aggregation: 'count',
            window: 'daily',
            warningThreshold: 50.0,
            criticalThreshold: 25.0,
            category: 'growth',
            owner: 'growth-team',
        );

        self::assertSame('daily_signups', $goal->key);
        self::assertSame('Daily Signups', $goal->name);
        self::assertSame(100.0, $goal->target);
        self::assertSame('sign_up', $goal->metric);
        self::assertSame('count', $goal->aggregation);
        self::assertSame('daily', $goal->window);
        self::assertSame(50.0, $goal->warningThreshold);
        self::assertSame(25.0, $goal->criticalThreshold);
        self::assertSame('growth', $goal->category);
        self::assertSame('growth-team', $goal->owner);
        self::assertTrue($goal->active);
    }

    public function testGoalDtoFromArray(): void
    {
        $data = [
            'key' => 'monthly_mrr',
            'name' => 'Monthly MRR',
            'target' => 50000.0,
            'metric' => 'subscription_created',
            'aggregation' => 'sum',
            'window' => 'monthly',
            'category' => 'revenue',
        ];

        $goal = AnalyticsGoal::fromArray($data);

        self::assertSame('monthly_mrr', $goal->key);
        self::assertSame('Monthly MRR', $goal->name);
        self::assertSame(50000.0, $goal->target);
        self::assertSame('revenue', $goal->category);
        self::assertNull($goal->warningThreshold);
        self::assertTrue($goal->active);
    }

    public function testGoalDtoToArrayRoundTrip(): void
    {
        $goal = new AnalyticsGoal(
            key: 'test_goal',
            name: 'Test Goal',
            target: 42.0,
            metric: 'test_event',
        );

        $array = $goal->toArray();
        $restored = AnalyticsGoal::fromArray($array);

        self::assertSame($goal->key, $restored->key);
        self::assertSame($goal->name, $restored->name);
        self::assertSame($goal->target, $restored->target);
    }

    public function testGoalDtoImmutability(): void
    {
        $goal = new AnalyticsGoal(key: 'k', name: 'n', target: 10.0);
        $originalTarget = $goal->target;

        // readonly property — can't modify
        self::assertSame($originalTarget, $goal->target);
    }

    // ─── GoalProgress DTO Tests ──────────────────────────────────────────

    public function testGoalProgressAchievedStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g1', name: 'G1', target: 100.0);
        $progress = GoalProgress::fromGoal($goal, 120.0);

        self::assertSame('achieved', $progress->status);
        self::assertSame(120.0, $goal->target);
        self::assertSame(120.0, $progress->actual);
        self::assertSame(100.0, $progress->percentage);
    }

    public function testGoalProgressExceededStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g2', name: 'G2', target: 100.0);
        $progress = GoalProgress::fromGoal($goal, 115.0);

        self::assertSame('exceeded', $progress->status);
        self::assertSame(115.0, $progress->percentage);
    }

    public function testGoalProgressOnTrackStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g3', name: 'G3', target: 100.0, warningThreshold: 50.0, criticalThreshold: 25.0);
        $progress = GoalProgress::fromGoal($goal, 75.0);

        self::assertSame('on_track', $progress->status);
        self::assertSame(75.0, $progress->percentage);
    }

    public function testGoalProgressAtRiskStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g4', name: 'G4', target: 100.0, warningThreshold: 50.0, criticalThreshold: 25.0);
        $progress = GoalProgress::fromGoal($goal, 40.0);

        self::assertSame('at_risk', $progress->status);
    }

    public function testGoalProgressBehindStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g5', name: 'G5', target: 100.0, warningThreshold: 50.0, criticalThreshold: 25.0);
        $progress = GoalProgress::fromGoal($goal, 20.0);

        self::assertSame('behind', $progress->status);
    }

    public function testGoalProgressNoDataStatus(): void
    {
        $goal = new AnalyticsGoal(key: 'g6', name: 'G6', target: 100.0);
        $progress = GoalProgress::fromGoal($goal, 0.0);

        self::assertSame('no_data', $progress->status);
        self::assertSame(0.0, $progress->percentage);
    }

    public function testGoalProgressWithChangePercent(): void
    {
        $goal = new AnalyticsGoal(key: 'g7', name: 'G7', target: 100.0);
        $progress = GoalProgress::fromGoal($goal, 60.0, 50.0);

        self::assertSame(60.0, $progress->actual);
        self::assertSame(50.0, $progress->previousActual);
        self::assertSame(20.0, $progress->changePercent); // (60-50)/50 * 100 = 20%
    }

    public function testGoalProgressWithTrend(): void
    {
        $goal = new AnalyticsGoal(key: 'g8', name: 'G8', target: 100.0);
        $progress = GoalProgress::fromGoal($goal, 80.0, 60.0, 'up', '2026-08-16');

        self::assertSame('up', $progress->trend);
        self::assertSame('2026-08-16', $progress->period);
    }

    public function testGoalProgressToArray(): void
    {
        $goal = new AnalyticsGoal(key: 'gp', name: 'Goal P', target: 50.0);
        $progress = GoalProgress::fromGoal($goal, 25.0, null, 'down');

        $array = $progress->toArray();

        self::assertArrayHasKey('goal_key', $array);
        self::assertArrayHasKey('actual', $array);
        self::assertArrayHasKey('target', $array);
        self::assertArrayHasKey('percentage', $array);
        self::assertArrayHasKey('status', $array);
        self::assertArrayHasKey('trend', $array);
        self::assertSame('gp', $array['goal_key']);
        self::assertSame(50.0, $array['percentage']);
    }

    public function testGoalProgressZeroTargetWithActual(): void
    {
        $goal = new AnalyticsGoal(key: 'z', name: 'Zero Target', target: 0.0);
        $progress = GoalProgress::fromGoal($goal, 10.0);

        // Zero target with actual > 0 should be 100%
        self::assertSame(100.0, $progress->percentage);
        self::assertSame('achieved', $progress->status);
    }

    // ─── Rolling Window Analytics Engine Tests ────────────────────────────

    public function testSmaBasic(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $sma = $engine->sma($values, 3);

        // Last 3: 30 + 40 + 50 = 120 / 3 = 40
        self::assertSame(40.0, $sma);
    }

    public function testSmaSingleValue(): void
    {
        $engine = $this->createEngine();
        $values = [42.0];

        $sma = $engine->sma($values, 7);

        self::assertSame(42.0, $sma);
    }

    public function testSmaEmptyArray(): void
    {
        $engine = $this->createEngine();

        $sma = $engine->sma([], 7);

        self::assertSame(0.0, $sma);
    }

    public function testEmaBasic(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $ema = $engine->ema($values, 0.3);

        // EMA should be between first and last values, biased toward recent
        self::assertGreaterThan(10.0, $ema);
        self::assertLessThan(50.0, $ema);
    }

    public function testEmaEmptyArray(): void
    {
        $engine = $this->createEngine();

        $ema = $engine->ema([]);

        self::assertSame(0.0, $ema);
    }

    public function testEmaAlphaBounds(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0];

        // Very low alpha — should be close to first value
        $emaLow = $engine->ema($values, 0.01);
        // Very high alpha — should be close to last value
        $emaHigh = $engine->ema($values, 0.99);

        self::assertLessThan($emaHigh, $emaLow);
    }

    public function testWmaBasic(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $wma = $engine->wma($values, 3);

        // Last 3: (30*1 + 40*2 + 50*3) / (1+2+3) = 260/6 = 43.33
        self::assertEqualsWithDelta(43.333, $wma, 0.01);
    }

    public function testAllMovingAverages(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $result = $engine->allMovingAverages($values, 3, 0.3);

        self::assertArrayHasKey('sma', $result);
        self::assertArrayHasKey('ema', $result);
        self::assertArrayHasKey('wma', $result);
        self::assertSame(40.0, $result['sma']);
    }

    public function testDetectTrendUp(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0];

        $trend = $engine->detectTrend($values);

        self::assertSame('up', $trend['direction']);
        self::assertGreaterThan(0, $trend['slope']);
        self::assertGreaterThan(0.5, $trend['confidence']);
    }

    public function testDetectTrendDown(): void
    {
        $engine = $this->createEngine();
        $values = [70.0, 60.0, 50.0, 40.0, 30.0, 20.0, 10.0];

        $trend = $engine->detectTrend($values);

        self::assertSame('down', $trend['direction']);
        self::assertLessThan(0, $trend['slope']);
    }

    public function testDetectTrendFlat(): void
    {
        $engine = $this->createEngine();
        $values = [50.0, 51.0, 49.0, 50.0, 51.0, 49.0, 50.0];

        $trend = $engine->detectTrend($values);

        self::assertSame('flat', $trend['direction']);
    }

    public function testDetectTrendInsufficientData(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0];

        $trend = $engine->detectTrend($values);

        self::assertSame('flat', $trend['direction']);
        self::assertSame(0.0, $trend['slope']);
        self::assertSame(0.0, $trend['confidence']);
    }

    public function testVolatilityLow(): void
    {
        $engine = $this->createEngine();
        $values = [100.0, 101.0, 99.0, 100.0, 101.0, 100.0, 99.0];

        $vol = $engine->volatility($values);

        self::assertLessThan(0.1, $vol);
    }

    public function testVolatilityHigh(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 100.0, 5.0, 200.0, 15.0, 150.0, 8.0];

        $vol = $engine->volatility($values);

        self::assertGreaterThan(0.5, $vol);
    }

    public function testVolatilityEmpty(): void
    {
        $engine = $this->createEngine();

        $vol = $engine->volatility([]);

        self::assertSame(0.0, $vol);
    }

    public function testSmoothSeriesEma(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $smoothed = $engine->smoothSeries($values, 'ema', 3, 0.5);

        self::assertCount(5, $smoothed);
        self::assertSame(10.0, $smoothed[0]); // First value unchanged
        self::assertGreaterThan($smoothed[0], $smoothed[4]); // Trend preserved
    }

    public function testSmoothSeriesSma(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];

        $smoothed = $engine->smoothSeries($values, 'sma', 3);

        self::assertCount(5, $smoothed);
    }

    public function testSmoothSeriesEmpty(): void
    {
        $engine = $this->createEngine();

        $smoothed = $engine->smoothSeries([], 'ema');

        self::assertSame([], $smoothed);
    }

    public function testProfile(): void
    {
        $engine = $this->createEngine();
        $values = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0];

        $profile = $engine->profile($values, 3);

        self::assertArrayHasKey('current', $profile);
        self::assertArrayHasKey('sma', $profile);
        self::assertArrayHasKey('ema', $profile);
        self::assertArrayHasKey('wma', $profile);
        self::assertArrayHasKey('trend', $profile);
        self::assertArrayHasKey('volatility', $profile);
        self::assertArrayHasKey('min', $profile);
        self::assertArrayHasKey('max', $profile);
        self::assertArrayHasKey('mean', $profile);
        self::assertArrayHasKey('count', $profile);

        self::assertSame(70.0, $profile['current']);
        self::assertSame(10.0, $profile['min']);
        self::assertSame(70.0, $profile['max']);
        self::assertSame(7, $profile['count']);
        self::assertSame('up', $profile['trend']['direction']);
    }

    // ─── SaaS Quick Insights Tests ──────────────────────────────────────

    public function testInsightSpikeDetection(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('signups', [100.0, 105.0, 98.0, 102.0, 250.0]);

        $insights = $service->generateInsights();

        self::assertNotEmpty($insights);
        $spikeInsights = array_filter($insights, static fn(array $i): bool => $i['type'] === 'spike');
        self::assertNotEmpty($spikeInsights);

        $spike = array_values($spikeInsights)[0];
        self::assertSame('signups', $spike['metric']);
        self::assertStringContainsString('Spike', $spike['title']);
        self::assertArrayHasKey('action', $spike);
    }

    public function testInsightDropDetection(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('conversions', [100.0, 95.0, 102.0, 98.0, 30.0]);

        $insights = $service->generateInsights();

        $dropInsights = array_filter($insights, static fn(array $i): bool => $i['type'] === 'drop');
        self::assertNotEmpty($dropInsights);

        $drop = array_values($dropInsights)[0];
        self::assertSame('conversions', $drop['metric']);
        self::assertStringContainsString('Drop', $drop['title']);
    }

    public function testInsightTrendUpDetection(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('revenue', [100.0, 110.0, 105.0, 120.0, 130.0, 125.0, 140.0, 155.0, 160.0, 170.0]);

        $insights = $service->generateInsights();

        $trendInsights = array_filter($insights, static fn(array $i): bool => $i['type'] === 'trending_up');
        self::assertNotEmpty($trendInsights);
    }

    public function testInsightTrendDownDetection(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('sessions', [200.0, 190.0, 195.0, 180.0, 170.0, 175.0, 160.0, 150.0, 155.0, 140.0]);

        $insights = $service->generateInsights();

        $trendInsights = array_filter($insights, static fn(array $i): bool => $i['type'] === 'trending_down');
        self::assertNotEmpty($trendInsights);
    }

    public function testInsightOutlierDetection(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('page_views', [100.0, 102.0, 98.0, 101.0, 99.0, 103.0, 97.0, 500.0]);

        $insights = $service->generateInsights();

        $outlierInsights = array_filter($insights, static fn(array $i): bool => $i['type'] === 'outlier');
        self::assertNotEmpty($outlierInsights);
    }

    public function testInsightIgnoredMetric(): void
    {
        $service = $this->createInsightsService(['ignored_metrics' => ['noise_metric']]);
        $service->registerSeries('noise_metric', [10.0, 500.0]); // Clear spike but should be ignored

        $insights = $service->generateInsights();

        $noiseInsights = array_filter($insights, static fn(array $i): bool => $i['metric'] === 'noise_metric');
        self::assertEmpty($noiseInsights);
    }

    public function testInsightSummary(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('a', [10.0, 30.0]);
        $service->registerSeries('b', [100.0, 20.0]);

        $summary = $service->summary();

        self::assertArrayHasKey('total', $summary);
        self::assertArrayHasKey('by_severity', $summary);
        self::assertArrayHasKey('by_type', $summary);
        self::assertArrayHasKey('top_critical', $summary);
        self::assertGreaterThan(0, $summary['total']);
    }

    public function testInsightStructure(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('test_metric', [10.0, 30.0]);

        $insights = $service->generateInsights();
        self::assertNotEmpty($insights);

        $insight = $insights[0];

        // Required fields
        self::assertArrayHasKey('id', $insight);
        self::assertArrayHasKey('type', $insight);
        self::assertArrayHasKey('title', $insight);
        self::assertArrayHasKey('description', $insight);
        self::assertArrayHasKey('metric', $insight);
        self::assertArrayHasKey('severity', $insight);
        self::assertArrayHasKey('confidence', $insight);
        self::assertArrayHasKey('created_at', $insight);

        // Severity should be one of the valid values
        self::assertContains($insight['severity'], ['info', 'warning', 'critical', 'success']);

        // Confidence should be between 0 and 1
        self::assertGreaterThanOrEqual(0.0, $insight['confidence']);
        self::assertLessThanOrEqual(1.0, $insight['confidence']);
    }

    public function testInsightNoDataProducesEmpty(): void
    {
        $service = $this->createInsightsService();
        // No series registered

        $insights = $service->generateInsights();

        self::assertSame([], $insights);
    }

    public function testInsightInsufficientData(): void
    {
        $service = $this->createInsightsService();
        $service->registerSeries('single', [42.0]);

        $insights = $service->generateInsights();

        // Only 1 data point — not enough for any detection
        self::assertSame([], $insights);
    }

    public function testInsightMaxLimit(): void
    {
        $service = $this->createInsightsService(['max_insights' => 2]);

        // Register many metrics with spikes
        for ($i = 0; $i < 10; $i++) {
            $service->registerSeries("metric_{$i}", [100.0, 500.0]);
        }

        $insights = $service->generateInsights();

        self::assertLessThanOrEqual(2, count($insights));
    }

    // ─── Version Consistency ─────────────────────────────────────────────

    public function testVersionConsistency(): void
    {
        self::assertSame('1.0.0', AnalyticsGoalTracker::VERSION);
        self::assertSame('1.0.0', RollingWindowAnalyticsEngine::VERSION);
        self::assertSame('1.0.0', SaaSQuickInsightsService::VERSION);
    }

    // ─── Helper Methods ─────────────────────────────────────────────────

    private function createEngine(): RollingWindowAnalyticsEngine
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.rolling_window.trend_min_points', 3, 3],
            ['zeroboiler.analytics.rolling_window.volatility_window', 7, 7],
        ]);

        return new RollingWindowAnalyticsEngine($cache, $config);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInsightsService(array $overrides = []): SaaSQuickInsightsService
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($overrides): mixed {
                return $overrides[$key] ?? $default;
            }
        );

        return new SaaSQuickInsightsService($cache, $config);
    }
}
