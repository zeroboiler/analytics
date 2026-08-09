<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use ZeroBoiler\Analytics\Services\AnalyticsInsightAggregator;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsInsightAggregator
 */
final class AnalyticsInsightAggregatorTest extends \PHPUnit\Framework\TestCase
{
    private AnalyticsInsightAggregator $service;

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'anomaly_threshold' => 3.0,
            'min_events_for_trend' => 10,
            'cache_ttl' => 300,
        ];

        $this->service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: null,
            config: $config,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generate_report_returns_expected_structure(): void
    {
        $report = $this->service->generateReport();

        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('insights', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertIsString($report['generated_at']);
        $this->assertIsArray($report['insights']);
        $this->assertIsArray($report['summary']);
    }

    public function test_report_summary_has_type_and_severity_breakdown(): void
    {
        $report = $this->service->generateReport();

        $this->assertArrayHasKey('total', $report['summary']);
        $this->assertArrayHasKey('by_type', $report['summary']);
        $this->assertArrayHasKey('by_severity', $report['summary']);
        $this->assertIsInt($report['summary']['total']);
    }

    public function test_detect_trending_events_returns_empty_without_aggregation_service(): void
    {
        // Service was created with null aggregation service
        $trending = $this->service->detectTrendingEvents();

        $this->assertIsArray($trending);
        $this->assertEmpty($trending);
    }

    public function test_detect_anomalies_returns_empty_without_aggregation_service(): void
    {
        $anomalies = $this->service->detectAnomalies();

        $this->assertIsArray($anomalies);
        $this->assertEmpty($anomalies);
    }

    public function test_analyze_funnel_drop_offs_returns_empty_without_aggregation_service(): void
    {
        $dropOffs = $this->service->analyzeFunnelDropOffs();

        $this->assertIsArray($dropOffs);
        $this->assertEmpty($dropOffs);
    }

    public function test_analyze_engagement_patterns_returns_empty_without_aggregation_service(): void
    {
        $engagement = $this->service->analyzeEngagementPatterns();

        $this->assertIsArray($engagement);
        $this->assertEmpty($engagement);
    }

    public function test_identify_conversion_opportunities_returns_empty_without_aggregation_service(): void
    {
        $conversions = $this->service->identifyConversionOpportunities();

        $this->assertIsArray($conversions);
        $this->assertEmpty($conversions);
    }

    public function test_insight_items_have_expected_structure(): void
    {
        // Create a mock aggregation service with data
        $aggregation = Mockery::mock(EventAggregationService::class);
        $aggregation->shouldReceive('getTopEvents')
            ->andReturn([
                'page_view' => 100,
                'sign_up' => 50,
                'start_trial' => 30,
                'subscribe' => 5, // Low trial-to-sub conversion
                'add_to_cart' => 100,
                'purchase' => 10, // Low cart-to-purchase
                'error' => 100,
                'js_error' => 50,
                'click' => 200,
                'form_submit' => 50,
                'search' => 80,
                'feature_used' => 40,
            ]);
        $aggregation->shouldReceive('getCount')
            ->andReturn(0);
        $aggregation->shouldReceive('getEventCounts')
            ->andReturn([]);

        $service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: $aggregation,
            config: [
                'anomaly_threshold' => 3.0,
                'min_events_for_trend' => 10,
                'cache_ttl' => 300,
            ],
        );

        $report = $service->generateReport();

        // Each insight should have the expected keys
        foreach ($report['insights'] as $insight) {
            $this->assertArrayHasKey('type', $insight);
            $this->assertArrayHasKey('category', $insight);
            $this->assertArrayHasKey('title', $insight);
            $this->assertArrayHasKey('description', $insight);
            $this->assertArrayHasKey('severity', $insight);
            $this->assertArrayHasKey('metric', $insight);
            $this->assertArrayHasKey('value', $insight);
            $this->assertArrayHasKey('recommendation', $insight);

            $this->assertContains(
                $insight['severity'],
                ['critical', 'elevated', 'warning', 'info'],
            );
        }
    }

    public function test_detects_low_trial_conversion_opportunity(): void
    {
        $aggregation = Mockery::mock(EventAggregationService::class);
        $aggregation->shouldReceive('getTopEvents')
            ->andReturn([
                'start_trial' => 100,
                'trial_converted' => 0,
                'subscribe' => 0,
            ]);
        $aggregation->shouldReceive('getCount')->andReturn(0);
        $aggregation->shouldReceive('getEventCounts')->andReturn([]);

        $service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: $aggregation,
            config: ['anomaly_threshold' => 3.0, 'min_events_for_trend' => 10, 'cache_ttl' => 300],
        );

        $conversions = $service->identifyConversionOpportunities();

        $conversionInsights = array_filter(
            $conversions,
            fn (array $i): bool => $i['type'] === 'conversion_opportunity' && str_contains($i['title'], 'trial'),
        );

        $this->assertNotEmpty($conversionInsights);
    }

    public function test_detects_low_cart_conversion_opportunity(): void
    {
        $aggregation = Mockery::mock(EventAggregationService::class);
        $aggregation->shouldReceive('getTopEvents')
            ->andReturn([
                'add_to_cart' => 100,
                'purchase' => 0,
            ]);
        $aggregation->shouldReceive('getCount')->andReturn(0);
        $aggregation->shouldReceive('getEventCounts')->andReturn([]);

        $service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: $aggregation,
            config: ['anomaly_threshold' => 3.0, 'min_events_for_trend' => 10, 'cache_ttl' => 300],
        );

        $conversions = $service->identifyConversionOpportunities();

        $cartInsights = array_filter(
            $conversions,
            fn (array $i): bool => $i['type'] === 'conversion_opportunity' && str_contains($i['title'], 'cart'),
        );

        $this->assertNotEmpty($cartInsights);
    }

    public function test_detects_low_engagement_depth(): void
    {
        $aggregation = Mockery::mock(EventAggregationService::class);
        $aggregation->shouldReceive('getTopEvents')
            ->andReturn([
                'page_view' => 10000,
                'click' => 10,
                'form_submit' => 5,
                'search' => 3,
                'share' => 2,
                'add_to_cart' => 1,
                'feature_used' => 0,
                'scroll_depth' => 0,
                'error' => 0,
                'js_error' => 0,
            ]);
        $aggregation->shouldReceive('getCount')->andReturn(0);
        $aggregation->shouldReceive('getEventCounts')->andReturn([]);

        $service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: $aggregation,
            config: ['anomaly_threshold' => 3.0, 'min_events_for_trend' => 10, 'cache_ttl' => 300],
        );

        $engagement = $service->analyzeEngagementPatterns();

        $lowEngagement = array_filter(
            $engagement,
            fn (array $i): bool => $i['type'] === 'low_engagement',
        );

        $this->assertNotEmpty($lowEngagement);
    }

    public function test_detects_high_error_rate(): void
    {
        $aggregation = Mockery::mock(EventAggregationService::class);
        $aggregation->shouldReceive('getTopEvents')
            ->andReturn([
                'page_view' => 1000,
                'error' => 500,
                'js_error' => 500,
                'click' => 100,
                'form_submit' => 50,
                'search' => 30,
                'share' => 10,
                'add_to_cart' => 5,
                'feature_used' => 20,
                'scroll_depth' => 0,
            ]);
        $aggregation->shouldReceive('getCount')->andReturn(0);
        $aggregation->shouldReceive('getEventCounts')->andReturn([]);

        $service = new AnalyticsInsightAggregator(
            streamService: null,
            aggregationService: $aggregation,
            config: ['anomaly_threshold' => 3.0, 'min_events_for_trend' => 10, 'cache_ttl' => 300],
        );

        $engagement = $service->analyzeEngagementPatterns();

        $errorInsights = array_filter(
            $engagement,
            fn (array $i): bool => $i['type'] === 'high_error_rate',
        );

        $this->assertNotEmpty($errorInsights);
    }

    public function test_service_works_with_null_config(): void
    {
        $service = new AnalyticsInsightAggregator();

        $report = $service->generateReport();

        $this->assertArrayHasKey('insights', $report);
        $this->assertArrayHasKey('summary', $report);
    }
}
