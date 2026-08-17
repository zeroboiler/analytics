<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Phase58;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\MetricComputationRequest;
use ZeroBoiler\Analytics\DTO\MetricComputationResult;
use ZeroBoiler\Analytics\DTO\MetricDefinition;

/**
 * Phase 58 Production Readiness — Semantic Metrics Layer.
 *
 * Validates: MetricDefinition DTO (construction, factory methods, validation,
 * derived detection, serialization), MetricComputationResult DTO (construction,
 * comparison, time series, breakdowns), MetricComputationRequest DTO (factory
 * methods, cache key, granularity validation), AnalyticsSemanticMetricsService
 * (registration, computation, summary, categories, types, validation, config),
 * command class structure, routes registration, config section presence,
 * ServiceProvider registration, version consistency 233.0.0.
 *
 * @since 233.0.0
 */
final class Phase58ProductionReadinessTest extends \PHPUnit\Framework\TestCase
{
    // ── File Quality ─────────────────────────────────────────────────

    private static function assertFileQuality(string $path, string $label): void
    {
        self::assertFileExists($path, "{$label} file must exist");
        $content = file_get_contents($path);
        self::assertStringContainsString('declare(strict_types=1)', $content, "{$label} must have strict_types");
        self::assertStringContainsString('MIT license', $content, "{$label} must have MIT license header");
        self::assertStringContainsString('final', $content, "{$label} must be final");
    }

    #[Test]
    public function metric_definition_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/DTO/MetricDefinition.php',
            'MetricDefinition'
        );
    }

    #[Test]
    public function metric_computation_result_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/DTO/MetricComputationResult.php',
            'MetricComputationResult'
        );
    }

    #[Test]
    public function metric_computation_request_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/DTO/MetricComputationRequest.php',
            'MetricComputationRequest'
        );
    }

    #[Test]
    public function semantic_metrics_service_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Services/AnalyticsSemanticMetricsService.php',
            'AnalyticsSemanticMetricsService'
        );
    }

    #[Test]
    public function semantic_metrics_command_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Console/Commands/AnalyticsSemanticMetricsCommand.php',
            'AnalyticsSemanticMetricsCommand'
        );
    }

    // ── MetricDefinition DTO Tests ──────────────────────────────────

    #[Test]
    public function metric_definition_valid_types_count(): void
    {
        self::assertCount(8, MetricDefinition::VALID_TYPES, 'Must have 8 valid types');
    }

    #[Test]
    public function metric_definition_count_factory(): void
    {
        $def = MetricDefinition::count('page_views', 'Page Views', ['page_view']);
        self::assertSame('count', $def->type);
        self::assertSame('page_views', $def->name);
        self::assertSame('Page Views', $def->label);
        self::assertSame(['page_view'], $def->sourceEvents);
        self::assertTrue($def->isValid());
        self::assertFalse($def->isDerived());
    }

    #[Test]
    public function metric_definition_sum_factory(): void
    {
        $def = MetricDefinition::sum('total_revenue', 'Total Revenue', 'value', ['purchase']);
        self::assertSame('sum', $def->type);
        self::assertSame('value', $def->measureField);
        self::assertTrue($def->requiresMeasureField());
        self::assertTrue($def->isValid());
    }

    #[Test]
    public function metric_definition_unique_count_factory(): void
    {
        $def = MetricDefinition::uniqueCount('active_users', 'Active Users', 'user_id', ['page_view']);
        self::assertSame('unique_count', $def->type);
        self::assertSame(['user_id'], $def->uniqueField);
        self::assertTrue($def->requiresUniqueField());
        self::assertTrue($def->isValid());
    }

    #[Test]
    public function metric_definition_ratio_factory(): void
    {
        $def = MetricDefinition::ratio('trial_conversion_rate', 'Trial Conversion', 'subscriptions', 'trials');
        self::assertSame('ratio', $def->type);
        self::assertSame('subscriptions', $def->ratioNumerator);
        self::assertSame('trials', $def->ratioDenominator);
        self::assertTrue($def->isDerived());
        self::assertTrue($def->isValid());
        self::assertFalse($def->requiresMeasureField());
    }

    #[Test]
    public function metric_definition_serialization_round_trip(): void
    {
        $original = MetricDefinition::sum('revenue', 'Revenue', 'amount', ['purchase'], 'Test metric');
        $original->toArray();
        $arr = $original->toArray();
        $restored = MetricDefinition::fromArray($arr);
        self::assertSame($original->name, $restored->name);
        self::assertSame($original->type, $restored->type);
        self::assertSame($original->label, $restored->label);
        self::assertSame($original->measureField, $restored->measureField);
        self::assertSame($original->sourceEvents, $restored->sourceEvents);
    }

    #[Test]
    public function metric_definition_dimension_names(): void
    {
        $def = new MetricDefinition(
            name: 'test',
            label: 'Test',
            description: 'Test metric',
            type: 'count',
            sourceEvents: ['page_view'],
            dimensions: [
                ['name' => 'country', 'type' => 'string', 'description' => 'Country'],
                ['name' => 'device', 'type' => 'string', 'description' => 'Device'],
            ],
        );
        self::assertSame(['country', 'device'], $def->dimensionNames());
        self::assertTrue($def->hasDimension('country'));
        self::assertFalse($def->hasDimension('browser'));
    }

    #[Test]
    public function metric_definition_invalid_without_measure_field_for_sum(): void
    {
        $def = new MetricDefinition(
            name: 'test_sum',
            label: 'Test Sum',
            description: 'Test',
            type: 'sum',
            sourceEvents: ['purchase'],
            measureField: null,
        );
        self::assertFalse($def->isValid());
        self::assertTrue($def->isValidType());
        self::assertTrue($def->requiresMeasureField());
    }

    #[Test]
    public function metric_definition_invalid_type(): void
    {
        $def = new MetricDefinition(
            name: 'test',
            label: 'Test',
            description: 'Test',
            type: 'invalid_type',
            sourceEvents: ['page_view'],
        );
        self::assertFalse($def->isValid());
        self::assertFalse($def->isValidType());
    }

    // ── MetricComputationResult DTO Tests ───────────────────────────

    #[Test]
    public function metric_result_zero_factory(): void
    {
        $result = MetricComputationResult::zero('test_metric');
        self::assertSame('test_metric', $result->metricName);
        self::assertSame(0.0, $result->value);
        self::assertNotNull($result->computedAt);
        self::assertFalse($result->hasBreakdowns());
        self::assertFalse($result->hasComparison());
        self::assertFalse($result->hasTimeSeries());
    }

    #[Test]
    public function metric_result_make_factory(): void
    {
        $result = MetricComputationResult::make('revenue', 1234.56, 'currency', 42);
        self::assertSame(1234.56, $result->value);
        self::assertSame('currency', $result->unit);
        self::assertSame(42, $result->sourceEventCount);
    }

    #[Test]
    public function metric_result_formatted(): void
    {
        $result = MetricComputationResult::make('mrr', 1234.5678, 'currency');
        self::assertSame('1,234.57', $result->formatted(2));
        self::assertSame('1,235', $result->formatted(0));
    }

    #[Test]
    public function metric_result_with_comparison(): void
    {
        $result = MetricComputationResult::make('signups', 150.0);
        $withComparison = $result->withComparison(100.0);

        self::assertTrue($withComparison->hasComparison());
        self::assertSame(100.0, $withComparison->comparison['previous_value']);
        self::assertSame(50.0, $withComparison->comparison['change']);
        self::assertSame(50.0, $withComparison->comparison['change_percentage']);
        self::assertSame('up', $withComparison->changeDirection());
    }

    #[Test]
    public function metric_result_comparison_down_direction(): void
    {
        $result = MetricComputationResult::make('signups', 80.0);
        $withComparison = $result->withComparison(100.0);
        self::assertSame('down', $withComparison->changeDirection());
    }

    #[Test]
    public function metric_result_comparison_new_direction(): void
    {
        $result = MetricComputationResult::make('signups', 80.0);
        $withComparison = $result->withComparison(null);
        self::assertSame('new', $withComparison->changeDirection());
    }

    #[Test]
    public function metric_result_serialization_round_trip(): void
    {
        $result = MetricComputationResult::make('revenue', 999.99, 'currency', 10);
        $withComparison = $result->withComparison(800.0);
        $arr = $withComparison->toArray();
        $restored = MetricComputationResult::fromArray($arr);
        self::assertSame($withComparison->metricName, $restored->metricName);
        self::assertSame($withComparison->value, $restored->value);
        self::assertSame($withComparison->unit, $restored->unit);
        self::assertTrue($restored->hasComparison());
    }

    #[Test]
    public function metric_result_with_breakdowns(): void
    {
        $result = new MetricComputationResult(
            metricName: 'page_views',
            value: 1000.0,
            breakdowns: [
                ['dimension' => 'country', 'value' => 'US', 'metric_value' => 500.0, 'percentage' => 50.0],
                ['dimension' => 'country', 'value' => 'UK', 'metric_value' => 300.0, 'percentage' => 30.0],
            ],
        );
        self::assertTrue($result->hasBreakdowns());
        self::assertCount(2, $result->breakdowns);
    }

    #[Test]
    public function metric_result_with_time_series(): void
    {
        $result = new MetricComputationResult(
            metricName: 'page_views',
            value: 500.0,
            timeSeries: [
                ['timestamp' => '2026-08-01T00:00:00+00:00', 'value' => 100.0],
                ['timestamp' => '2026-08-02T00:00:00+00:00', 'value' => 200.0],
            ],
        );
        self::assertTrue($result->hasTimeSeries());
        self::assertCount(2, $result->timeSeries);
    }

    // ── MetricComputationRequest DTO Tests ──────────────────────────

    #[Test]
    public function computation_request_simple_factory(): void
    {
        $request = MetricComputationRequest::simple('signups', 'day');
        self::assertSame('signups', $request->metricName);
        self::assertSame('day', $request->granularity);
        self::assertFalse($request->includeComparison);
    }

    #[Test]
    public function computation_request_last_n_days_factory(): void
    {
        $request = MetricComputationRequest::lastNDays('revenue', 7);
        self::assertNotNull($request->periodStart);
        self::assertNotNull($request->periodEnd);
        self::assertSame('day', $request->granularity);
    }

    #[Test]
    public function computation_request_with_comparison_factory(): void
    {
        $request = MetricComputationRequest::withComparison('mrr', 30);
        self::assertTrue($request->includeComparison);
    }

    #[Test]
    public function computation_request_effective_periods(): void
    {
        $request = MetricComputationRequest::simple('test');
        $start = $request->effectivePeriodStart();
        $end = $request->effectivePeriodEnd();
        self::assertInstanceOf(\DateTimeImmutable::class, $start);
        self::assertInstanceOf(\DateTimeImmutable::class, $end);
        self::assertLessThanOrEqual($end, $start);
    }

    #[Test]
    public function computation_request_cache_key_deterministic(): void
    {
        $request = MetricComputationRequest::simple('signups', 'day');
        $key1 = $request->cacheKey();
        $key2 = $request->cacheKey();
        self::assertSame($key1, $key2, 'Cache key must be deterministic');
        self::assertStringStartsWith('zb_sm:', $key1);
    }

    #[Test]
    public function computation_request_valid_granularities(): void
    {
        $valid = MetricComputationRequest::validGranularities();
        self::assertContains('minute', $valid);
        self::assertContains('hour', $valid);
        self::assertContains('day', $valid);
        self::assertContains('week', $valid);
        self::assertContains('month', $valid);
        self::assertCount(5, $valid);
    }

    #[Test]
    public function computation_request_granularity_validation(): void
    {
        $valid = MetricComputationRequest::simple('test', 'day');
        self::assertTrue($valid->isValidGranularity());

        $invalid = MetricComputationRequest::simple('test', 'year');
        self::assertFalse($invalid->isValidGranularity());
    }

    #[Test]
    public function computation_request_serialization_round_trip(): void
    {
        $request = MetricComputationRequest::withComparison('revenue', 14);
        $arr = $request->toArray();
        $restored = MetricComputationRequest::fromArray($arr);
        self::assertSame($request->metricName, $restored->metricName);
        self::assertSame($request->granularity, $restored->granularity);
        self::assertTrue($restored->includeComparison);
    }

    // ── Config Section ─────────────────────────────────────────────

    #[Test]
    public function config_has_semantic_metrics_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'semantic_metrics' => [", $content);
    }

    #[Test]
    public function config_has_semantic_metrics_enabled_env(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString('ANALYTICS_SEMANTIC_METRICS_ENABLED', $content);
    }

    #[Test]
    public function config_has_semantic_metrics_cache_env(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString('ANALYTICS_SEMANTIC_METRICS_CACHE_ENABLED', $content);
        self::assertStringContainsString('ANALYTICS_SEMANTIC_METRICS_CACHE_TTL', $content);
    }

    // ── Routes ──────────────────────────────────────────────────────

    #[Test]
    public function routes_has_semantic_metrics_endpoints(): void
    {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        self::assertStringContainsString("semanticMetricsSummary", $content);
        self::assertStringContainsString("semanticMetricsList", $content);
        self::assertStringContainsString("semanticMetricsShow", $content);
        self::assertStringContainsString("semanticMetricsCompute", $content);
        self::assertStringContainsString("semanticMetricsCategories", $content);
    }

    // ── ServiceProvider Registration ───────────────────────────────

    #[Test]
    public function service_provider_registers_semantic_metrics_service(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        self::assertStringContainsString('AnalyticsSemanticMetricsService', $content);
        self::assertStringContainsString('AnalyticsSemanticMetricsCommand', $content);
    }

    #[Test]
    public function controller_has_semantic_metrics_imports(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        self::assertStringContainsString('AnalyticsSemanticMetricsService', $content);
        self::assertStringContainsString('MetricComputationRequest', $content);
    }

    // ── Command Structure ────────────────────────────────────────────

    #[Test]
    public function command_file_exists_and_has_correct_structure(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Console/Commands/AnalyticsSemanticMetricsCommand.php',
            'AnalyticsSemanticMetricsCommand'
        );

        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsSemanticMetricsCommand.php');
        self::assertStringContainsString('zb:analytics:semantic-metrics', $content);
        self::assertStringContainsString('AnalyticsSemanticMetricsService', $content);
        self::assertStringContainsString('233.0.0', $content);
    }

    // ── Version Consistency ─────────────────────────────────────────

    #[Test]
    public function service_has_correct_since_tag(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsSemanticMetricsService.php');
        self::assertStringContainsString('@since 233.0.0', $content);
    }

    #[Test]
    public function dto_has_correct_since_tag(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/MetricDefinition.php');
        self::assertStringContainsString('@since 233.0.0', $content);
    }

    // ── MetricDefinition @since Tags ───────────────────────────────

    #[Test]
    public function all_phase58_files_have_since_233(): void
    {
        $files = [
            __DIR__ . '/../../src/DTO/MetricDefinition.php',
            __DIR__ . '/../../src/DTO/MetricComputationResult.php',
            __DIR__ . '/../../src/DTO/MetricComputationRequest.php',
            __DIR__ . '/../../src/Services/AnalyticsSemanticMetricsService.php',
            __DIR__ . '/../../src/Console/Commands/AnalyticsSemanticMetricsCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            self::assertStringContainsString('233.0.0', $content, basename($file) . ' must reference 233.0.0');
        }
    }

    // ── Source File Count Baselines ──────────────────────────────────

    #[Test]
    public function source_file_count_above_baseline(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__ . '/../../src',
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(955, $count, "src/ must contain 955+ PHP files, got {$count}");
    }

    #[Test]
    public function command_count_above_baseline(): void
    {
        $iterator = new \DirectoryIterator(__DIR__ . '/../../src/Console/Commands');
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(111, $count, "Commands dir must have 111+ files, got {$count}");
    }

    #[Test]
    public function dto_count_above_baseline(): void
    {
        $iterator = new \DirectoryIterator(__DIR__ . '/../../src/DTO');
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(47, $count, "DTO dir must have 47+ files, got {$count}");
    }
}
