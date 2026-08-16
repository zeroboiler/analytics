<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService;

/**
 * Tests for the SaaS Metrics Benchmark Service (v2.87.0).
 *
 * Validates industry-standard benchmark thresholds, percentile scoring,
 * batch comparison, report cards, quick-start metrics, and category filtering.
 *
 * @see \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService
 */
final class V87SaaSBenchmarkTest extends \PHPUnit\Framework\TestCase
{
    private SaaSMetricsBenchmarkService $service;

    private \PHPUnit\Framework\MockObject\MockObject|CacheRepository $cache;

    private \PHPUnit\Framework\MockObject\MockObject|ConfigRepository $config;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheRepository::class);
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('put')->willReturn(true);

        $this->config = $this->createMock(ConfigRepository::class);
        $this->config->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            $overrides = [
                'zeroboiler.analytics.benchmarks.enabled' => true,
                'zeroboiler.analytics.benchmarks.cache_ttl' => 43200,
                'zeroboiler.analytics.benchmarks.industry' => 'saas',
                'zeroboiler.analytics.benchmarks.company_stage' => 0,
            ];

            return $overrides[$key] ?? $default;
        });

        $this->service = new SaaSMetricsBenchmarkService($this->cache, $this->config);
    }

    // ── Construction ──────────────────────────────────────────────────

    public function test_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(SaaSMetricsBenchmarkService::class, $this->service);
    }

    // ── Benchmark Data Integrity ───────────────────────────────────────

    public function test_all_benchmarks_have_required_fields(): void
    {
        $benchmarks = $this->service->allBenchmarks();

        $this->assertGreaterThan(15, count($benchmarks));

        foreach ($benchmarks as $name => $benchmark) {
            $this->assertArrayHasKey('label', $benchmark, "Missing label for {$name}");
            $this->assertArrayHasKey('unit', $benchmark, "Missing unit for {$name}");
            $this->assertArrayHasKey('p25', $benchmark, "Missing p25 for {$name}");
            $this->assertArrayHasKey('p50', $benchmark, "Missing p50 for {$name}");
            $this->assertArrayHasKey('p75', $benchmark, "Missing p75 for {$name}");
            $this->assertArrayHasKey('p90', $benchmark, "Missing p90 for {$name}");
            $this->assertArrayHasKey('direction', $benchmark, "Missing direction for {$name}");
            $this->assertArrayHasKey('category', $benchmark, "Missing category for {$name}");

            // Direction must be one of two values
            $this->assertContains(
                $benchmark['direction'],
                ['higher_better', 'lower_better'],
                "Invalid direction for {$name}: {$benchmark['direction']}",
            );

            // Percentiles must be ordered correctly
            if ($benchmark['direction'] === 'higher_better') {
                $this->assertLessThanOrEqual(
                    $benchmark['p50'],
                    $benchmark['p25'],
                    "For higher_better {$name}, p25 should be <= p50",
                );
            } else {
                $this->assertGreaterThanOrEqual(
                    $benchmark['p50'],
                    $benchmark['p25'],
                    "For lower_better {$name}, p25 should be >= p50",
                );
            }
        }
    }

    public function test_benchmarks_cover_all_five_categories(): void
    {
        $categories = $this->service->availableCategories();

        $expectedCategories = ['revenue', 'conversion', 'retention', 'engagement', 'funnel'];

        foreach ($expectedCategories as $cat) {
            $this->assertContains($cat, $categories, "Missing category: {$cat}");
        }
    }

    public function test_each_category_has_at_least_two_metrics(): void
    {
        $byCategory = $this->service->byCategory();

        foreach ($byCategory as $category => $metrics) {
            $this->assertGreaterThanOrEqual(2, count($metrics), "Category {$category} has fewer than 2 metrics");
        }
    }

    // ── Core Metrics Present ─────────────────────────────────────────

    /**
     * @dataProvider coreMetricProvider
     */
    public function test_core_saas_metric_exists(string $metric): void
    {
        $benchmark = $this->service->getBenchmark($metric);

        $this->assertNotNull($benchmark, "Core metric {$metric} not found");
        $this->assertArrayHasKey('label', $benchmark);
        $this->assertNotEmpty($benchmark['label']);
    }

    /**
     * @return array<string, array{metric: string}>
     */
    public static function coreMetricProvider(): array
    {
        return [
            'monthly_churn' => ['metric' => 'monthly_churn_rate'],
            'trial_conversion' => ['metric' => 'trial_conversion_rate'],
            'mrr_growth' => ['metric' => 'mrr_growth_rate'],
            'nrr' => ['metric' => 'net_revenue_retention'],
            'ltv_cac' => ['metric' => 'ltv_cac_ratio'],
            'cac_payback' => ['metric' => 'cac_payback_months'],
            'activation' => ['metric' => 'activation_rate'],
            'dau_mau' => ['metric' => 'dau_mau_ratio'],
        ];
    }

    // ── Percentile Scoring ───────────────────────────────────────────

    public function test_score_excellent_for_higher_better_at_p90(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('mrr_growth_rate', $benchmark['p90']);

        $this->assertEquals('excellent', $result['grade']);
        $this->assertEquals(90, $result['percentile']);
    }

    public function test_score_good_for_higher_better_at_p75(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('mrr_growth_rate', $benchmark['p75']);

        $this->assertEquals('good', $result['grade']);
    }

    public function test_score_average_for_higher_better_at_p50(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('mrr_growth_rate', $benchmark['p50']);

        $this->assertEquals('average', $result['grade']);
    }

    public function test_score_poor_for_higher_better_at_p25(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('mrr_growth_rate', $benchmark['p25']);

        $this->assertEquals('poor', $result['grade']);
    }

    public function test_score_excellent_for_lower_better_at_p90(): void
    {
        // For lower_better, p25=24 months (worst), p90=5 months (best)
        $benchmark = $this->service->getBenchmark('cac_payback_months');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('cac_payback_months', $benchmark['p90']);

        $this->assertEquals('excellent', $result['grade']);
    }

    public function test_score_poor_for_lower_better_at_p25(): void
    {
        $benchmark = $this->service->getBenchmark('cac_payback_months');
        $this->assertNotNull($benchmark);

        $result = $this->service->compare('cac_payback_months', $benchmark['p25']);

        $this->assertEquals('poor', $result['grade']);
    }

    // ── Batch Comparison ──────────────────────────────────────────────

    public function test_batch_compare_returns_results_for_all_metrics(): void
    {
        $metrics = [
            'monthly_churn_rate' => 3.5,
            'trial_conversion_rate' => 28,
            'mrr_growth_rate' => 12,
        ];

        $result = $this->service->compareBatch($metrics);

        $this->assertArrayHasKey('results', $result);
        $this->assertCount(3, $result['results']);

        foreach (array_keys($metrics) as $name) {
            $this->assertArrayHasKey($name, $result['results']);
        }
    }

    public function test_batch_compare_summary_counts(): void
    {
        // Mix of good and poor values
        $metrics = [
            'monthly_churn_rate' => 1.0,   // excellent (< 2%)
            'trial_conversion_rate' => 10,  // poor (< 15%)
        ];

        $result = $this->service->compareBatch($metrics);

        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(2, $result['summary']['total']);
        $this->assertArrayHasKey('excellent', $result['summary']);
        $this->assertArrayHasKey('poor', $result['summary']);
        $this->assertArrayHasKey('overall_grade', $result['summary']);
        $this->assertArrayHasKey('overall_score', $result['summary']);
    }

    public function test_batch_compare_empty_returns_empty_summary(): void
    {
        $result = $this->service->compareBatch([]);

        $this->assertEquals([], $result['results']);
        $this->assertEquals(0, $result['summary']['total']);
    }

    // ── Report Card ───────────────────────────────────────────────────

    public function test_report_card_contains_priorities(): void
    {
        $metrics = [
            'monthly_churn_rate' => 8.0,    // poor
            'trial_conversion_rate' => 12, // poor
            'mrr_growth_rate' => 20.0,     // excellent
        ];

        $report = $this->service->reportCard($metrics);

        $this->assertArrayHasKey('score', $report);
        $this->assertArrayHasKey('grade', $report);
        $this->assertArrayHasKey('metrics', $report);
        $this->assertArrayHasKey('priorities', $report);
        $this->assertArrayHasKey('summary', $report);

        // Priorities should list poor metrics first
        $this->assertNotEmpty($report['priorities']);
        $firstPriority = $report['priorities'][0];
        $this->assertContains($firstPriority, ['monthly_churn_rate', 'trial_conversion_rate']);
    }

    public function test_report_card_recommends_improvement_for_poor_metrics(): void
    {
        $metrics = ['monthly_churn_rate' => 10.0];
        $report = $this->service->reportCard($metrics);

        $churnMetric = $report['metrics']['monthly_churn_rate'];
        $this->assertArrayHasKey('recommendation', $churnMetric);
        $this->assertNotEmpty($churnMetric['recommendation']);
    }

    // ── Quick-Start Metrics ───────────────────────────────────────────

    public function test_quick_start_returns_eight_metrics(): void
    {
        $metrics = $this->service->quickStartMetrics();

        $this->assertCount(8, $metrics);
    }

    public function test_quick_start_contains_core_metrics(): void
    {
        $metrics = $this->service->quickStartMetrics();
        $metricNames = array_column($metrics, 'metric');

        $expected = ['monthly_churn_rate', 'trial_conversion_rate', 'mrr_growth_rate'];

        foreach ($expected as $name) {
            $this->assertContains($name, $metricNames, "Missing core metric: {$name}");
        }
    }

    public function test_quick_start_metrics_have_target(): void
    {
        $metrics = $this->service->quickStartMetrics();

        foreach ($metrics as $m) {
            $this->assertArrayHasKey('metric', $m);
            $this->assertArrayHasKey('label', $m);
            $this->assertArrayHasKey('target', $m);
            $this->assertArrayHasKey('unit', $m);
            $this->assertGreaterThan(0, $m['target']);
        }
    }

    // ── Category Filtering ───────────────────────────────────────────

    public function test_category_returns_only_matching_metrics(): void
    {
        $revenue = $this->service->category('revenue');

        $this->assertGreaterThan(0, count($revenue));

        foreach ($revenue as $benchmark) {
            $this->assertEquals('revenue', $benchmark['category']);
        }
    }

    public function test_unknown_category_returns_empty(): void
    {
        $result = $this->service->category('nonexistent');

        $this->assertEquals([], $result);
    }

    // ── Unknown Metric Handling ──────────────────────────────────────

    public function test_getBenchmark_returns_null_for_unknown(): void
    {
        $this->assertNull($this->service->getBenchmark('totally_fake_metric'));
    }

    public function test_compare_throws_for_unknown_metric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown benchmark metric');

        $this->service->compare('nonexistent_metric', 5.0);
    }

    // ── Summary ───────────────────────────────────────────────────────

    public function test_summary_returns_expected_structure(): void
    {
        $summary = $this->service->summary();

        $this->assertArrayHasKey('enabled', $summary);
        $this->assertArrayHasKey('total_metrics', $summary);
        $this->assertArrayHasKey('categories', $summary);
        $this->assertArrayHasKey('industry', $summary);
        $this->assertArrayHasKey('version', $summary);
        $this->assertTrue($summary['enabled']);
        $this->assertEquals('76.0.0', $summary['version']);
        $this->assertEquals('saas', $summary['industry']);
    }

    public function test_available_metrics_returns_all_keys(): void
    {
        $metrics = $this->service->availableMetrics();
        $benchmarks = $this->service->allBenchmarks();

        $this->assertEquals(count($benchmarks), count($metrics));

        foreach ($metrics as $metric) {
            $this->assertArrayHasKey($metric, $benchmarks);
        }
    }

    // ── Score Boundary Conditions ─────────────────────────────────────

    public function test_score_below_p25_is_poor_for_higher_better(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        // Below p25 should still be "poor"
        $result = $this->service->compare('mrr_growth_rate', $benchmark['p25'] - 1);

        $this->assertEquals('poor', $result['grade']);
        $this->assertLessThan(25, $result['percentile']);
    }

    public function test_score_above_p90_capped_at_100(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        // Way above p90
        $result = $this->service->compare('mrr_growth_rate', $benchmark['p90'] * 3);

        $this->assertEquals('excellent', $result['grade']);
        $this->assertLessThanOrEqual(100, $result['percentile']);
    }

    public function test_score_gap_calculated_correctly_higher_better(): void
    {
        $benchmark = $this->service->getBenchmark('mrr_growth_rate');
        $this->assertNotNull($benchmark);

        // Value at p50, gap should be p75 - p50 (positive)
        $result = $this->service->compare('mrr_growth_rate', $benchmark['p50']);

        $this->assertGreaterThan(0, $result['gap']);
    }

    public function test_score_gap_calculated_correctly_lower_better(): void
    {
        $benchmark = $this->service->getBenchmark('cac_payback_months');
        $this->assertNotNull($benchmark);

        // Value at p50, gap should be p50 - p75 (positive, since p75 < p50 for lower_better)
        $result = $this->service->compare('cac_payback_months', $benchmark['p50']);

        $this->assertGreaterThan(0, $result['gap']);
    }

    // ── Disabled Service ─────────────────────────────────────────────

    public function test_disabled_service_returns_empty_results(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            $overrides = [
                'zeroboiler.analytics.benchmarks.enabled' => false,
            ];

            return $overrides[$key] ?? $default;
        });

        $disabledService = new SaaSMetricsBenchmarkService($this->cache, $disabledConfig);

        $this->assertEquals([], $disabledService->allBenchmarks());
        $this->assertEquals([], $disabledService->availableMetrics());
        $this->assertEquals([], $disabledService->availableCategories());
        $this->assertNull($disabledService->getBenchmark('monthly_churn_rate'));
    }

    // ── Consistency ───────────────────────────────────────────────────

    public function test_version_constant_matches_service_version(): void
    {
        $summary = $this->service->summary();

        $this->assertArrayHasKey('version', $summary);
        $this->assertEquals('76.0.0', $summary['version']);
    }

    public function test_benchmark_count_matches_available_metrics(): void
    {
        $all = $this->service->allBenchmarks();
        $metrics = $this->service->availableMetrics();

        $this->assertEquals(count($all), count($metrics));
    }
}
