<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\V86;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService;
use ZeroBoiler\Analytics\Services\EventCostLedgerService;
use ZeroBoiler\Analytics\Services\EventSequencePredictionService;

/**
 * Tests for v86.0.0 — Event Sequence Prediction, Event Cost Ledger, Compliance Report.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventSequencePredictionService
 * @covers \ZeroBoiler\Analytics\Services\EventCostLedgerService
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService
 */
final class V86SequencePredictionCostLedgerComplianceTest extends \PHPUnit\Framework\TestCase
{
    // ── Event Sequence Prediction Service Tests ─────────────────────

    public function test_record_sequence_updates_transitions(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
            'cache_ttl' => 3600,
            'min_observations' => 10,
            'top_n' => 5,
            'confidence_threshold' => 0.05,
            'use_second_order' => true,
            'excluded_events' => ['page_view'],
        ]);

        $storedValues = [];
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$storedValues): void {
            $storedValues[$key] = $value;
        });
        $cache->method('get')->willReturnCallback(function (string $key) use (&$storedValues): mixed {
            return $storedValues[$key] ?? null;
        });
        $cache->method('increment')->willReturnCallback(function (string $key): int {
            return 1;
        });

        $service = new EventSequencePredictionService($cache, $config);

        $result = $service->recordSequence('client-123', ['sign_up', 'login', 'feature_used', 'start_trial']);

        $this->assertTrue($result['recorded']);
        $this->assertGreaterThanOrEqual(3, $result['transitions_updated']);
        $this->assertSame(4, $result['sequence_length']);
    }

    public function test_record_sequence_filters_excluded_events(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
            'cache_ttl' => 3600,
            'min_observations' => 10,
            'top_n' => 5,
            'confidence_threshold' => 0.05,
            'use_second_order' => true,
            'excluded_events' => ['page_view', 'scroll_depth'],
        ]);

        $storedValues = [];
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$storedValues): void {
            $storedValues[$key] = $value;
        });
        $cache->method('get')->willReturnCallback(function (string $key) use (&$storedValues): mixed {
            return $storedValues[$key] ?? null;
        });
        $cache->method('increment')->willReturn(1);

        $service = new EventSequencePredictionService($cache, $config);

        $result = $service->recordSequence('client-456', ['page_view', 'sign_up', 'scroll_depth', 'login']);

        $this->assertTrue($result['recorded']);
        // page_view and scroll_depth should be filtered — only sign_up→login transition
        $this->assertGreaterThanOrEqual(1, $result['transitions_updated']);
    }

    public function test_record_sequence_returns_not_recorded_when_too_short(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
            'cache_ttl' => 3600,
        ]);

        $service = new EventSequencePredictionService($cache, $config);

        $result = $service->recordSequence('client-789', ['single_event']);

        $this->assertFalse($result['recorded']);
        $this->assertSame(0, $result['transitions_updated']);
    }

    public function test_record_sequence_returns_not_recorded_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => false,
        ]);

        $service = new EventSequencePredictionService($cache, $config);

        $result = $service->recordSequence('client-disabled', ['a', 'b', 'c']);

        $this->assertFalse($result['recorded']);
    }

    public function test_predict_next_returns_empty_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => false,
        ]);

        $service = new EventSequencePredictionService($cache, $config);

        $predictions = $service->predictNext(['sign_up', 'login']);

        $this->assertEmpty($predictions);
    }

    public function test_predict_next_returns_empty_when_no_recent_events(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
        ]);

        $service = new EventSequencePredictionService($cache, $config);

        $predictions = $service->predictNext([]);

        $this->assertEmpty($predictions);
    }

    public function test_get_transition_matrix_returns_empty_for_unknown_event(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventSequencePredictionService($cache, $config);

        $matrix = $service->getTransitionMatrix('nonexistent_event');

        $this->assertSame('nonexistent_event', $matrix['from']);
        $this->assertSame(0, $matrix['total_transitions']);
        $this->assertEmpty($matrix['transitions']);
    }

    public function test_get_stats_returns_model_info(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
            'use_second_order' => true,
            'min_observations' => 10,
            'confidence_threshold' => 0.05,
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventSequencePredictionService($cache, $config);

        $stats = $service->getStats();

        $this->assertTrue($stats['enabled']);
        $this->assertStringContainsString('second', $stats['model']);
        $this->assertSame(10, $stats['min_observations']);
        $this->assertSame(0, $stats['first_order_events']);
    }

    public function test_clear_model_returns_cleared(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => true,
        ]);

        $cache->method('get')->willReturn(null);
        $cache->method('delete')->willReturn(true);

        $service = new EventSequencePredictionService($cache, $config);

        $result = $service->clearModel();

        $this->assertTrue($result['cleared']);
    }

    public function test_detect_anomalies_returns_empty_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.sequence_prediction')->willReturn([
            'enabled' => false,
        ]);

        $service = new EventSequencePredictionService($cache, $config);

        $anomalies = $service->detectAnomalies(['a', 'b', 'c']);

        $this->assertEmpty($anomalies);
    }

    // ── Event Cost Ledger Service Tests ──────────────────────────────

    public function test_record_dispatch_records_cost(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'cache_ttl' => 86400,
            'daily_budget' => 100.0,
            'monthly_budget' => 3000.0,
            'provider_cost_rates' => ['ga4' => 0.001, 'meta' => 0.001],
            'exempt_events' => ['page_view'],
        ]);

        $storedValues = [];
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$storedValues): void {
            $storedValues[$key] = $value;
        });
        $cache->method('get')->willReturnCallback(function (string $key) use (&$storedValues): mixed {
            return $storedValues[$key] ?? null;
        });

        $service = new EventCostLedgerService($cache, $config);

        $result = $service->recordDispatch('purchase', 'ga4', 42.5, true);

        $this->assertTrue($result['recorded']);
        $this->assertGreaterThan(0.0, $result['cost']);
        $this->assertGreaterThanOrEqual(0.0, $result['budget_remaining']);
    }

    public function test_record_dispatch_skips_exempt_events(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'exempt_events' => ['page_view', 'scroll_depth'],
        ]);

        $service = new EventCostLedgerService($cache, $config);

        $result = $service->recordDispatch('page_view', 'ga4');

        $this->assertFalse($result['recorded']);
        $this->assertSame(0.0, $result['cost']);
    }

    public function test_record_dispatch_skips_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => false,
        ]);

        $service = new EventCostLedgerService($cache, $config);

        $result = $service->recordDispatch('purchase', 'ga4');

        $this->assertFalse($result['recorded']);
    }

    public function test_get_daily_summary_returns_empty_when_no_data(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'daily_budget' => 100.0,
            'exempt_events' => [],
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventCostLedgerService($cache, $config);

        $summary = $service->getDailySummary();

        $this->assertSame(0, $summary['total_events']);
        $this->assertSame(0.0, $summary['total_cost']);
        $this->assertFalse($summary['is_budget_alert']);
        $this->assertEmpty($summary['top_events']);
    }

    public function test_check_budget_status_returns_no_recommendation(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'daily_budget' => 100.0,
            'exempt_events' => [],
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventCostLedgerService($cache, $config);

        $status = $service->checkBudgetStatus();

        $this->assertFalse($status['exceeded']);
        $this->assertSame(100.0, $status['budget']);
        $this->assertNull($status['recommendation']);
    }

    public function test_get_optimization_recommendations_returns_empty_when_no_data(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'daily_budget' => 100.0,
            'exempt_events' => [],
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventCostLedgerService($cache, $config);

        $recommendations = $service->getOptimizationRecommendations();

        $this->assertEmpty($recommendations);
    }

    public function test_get_historical_data_returns_requested_days(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics.cost_ledger')->willReturn([
            'enabled' => true,
            'daily_budget' => 100.0,
            'exempt_events' => [],
        ]);

        $cache->method('get')->willReturn(null);

        $service = new EventCostLedgerService($cache, $config);

        $history = $service->getHistoricalData(3);

        $this->assertCount(3, $history);
        foreach ($history as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('total_events', $day);
            $this->assertArrayHasKey('total_cost', $day);
        }
    }

    // ── Analytics Compliance Report Service Tests ──────────────────

    public function test_generate_gdpr_report_returns_score_and_checks(): void
    {
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'consent' => [
                'mode' => 'v2',
                'log_enabled' => true,
                'purposes' => ['analytics' => ['label' => 'Analytics', 'required' => true, 'default' => false], 'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false]],
                'cookie_banner_enabled' => true,
                'default' => 'denied',
                'enforce_consent' => true,
            ],
            'gdpr' => [
                'anonymize_ip' => true,
                'pii_detection_enabled' => true,
                'data_minimization' => true,
            ],
            'data_retention' => [
                'default_retention_days' => 90,
                'gdpr_erase_enabled' => true,
            ],
            'regional_consent' => [
                'enabled' => true,
            ],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        $report = $service->generateGDPRReport();

        $this->assertArrayHasKey('score', $report);
        $this->assertArrayHasKey('grade', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertGreaterThanOrEqual(0.5, $report['score']);
        $this->assertNotEmpty($report['checks']);

        // Check structure of each check
        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('notes', $check);
            $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
        }
    }

    public function test_generate_ccpa_report_returns_score_and_checks(): void
    {
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'consent' => ['default' => 'denied'],
            'gdpr' => ['pii_detection_enabled' => true],
            'data_retention' => ['default_retention_days' => 90],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        $report = $service->generateCCPAReport();

        $this->assertArrayHasKey('score', $report);
        $this->assertArrayHasKey('grade', $report);
        $this->assertArrayHasKey('checks', $report);
    }

    public function test_generate_soc2_report_returns_score_and_checks(): void
    {
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'sdk_auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => true],
            'audit_log' => ['enabled' => true],
            'providers' => ['ga4' => ['measurement_protocol' => 'https']],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        $report = $service->generateSOC2Report();

        $this->assertArrayHasKey('score', $report);
        $this->assertArrayHasKey('grade', $report);
        $this->assertArrayHasKey('checks', $report);
    }

    public function test_generate_full_report_includes_all_frameworks(): void
    {
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'consent' => [
                'mode' => 'v2',
                'log_enabled' => true,
                'purposes' => ['analytics' => []],
                'default' => 'denied',
                'enforce_consent' => true,
            ],
            'gdpr' => [
                'anonymize_ip' => true,
                'pii_detection_enabled' => true,
                'data_minimization' => true,
            ],
            'data_retention' => ['default_retention_days' => 90, 'gdpr_erase_enabled' => true],
            'sdk_auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => true],
            'audit_log' => ['enabled' => true],
            'regional_consent' => ['enabled' => true],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        $report = $service->generateFullReport();

        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('overall_score', $report);
        $this->assertArrayHasKey('overall_grade', $report);
        $this->assertArrayHasKey('frameworks', $report);
        $this->assertArrayHasKey('recommendations', $report);
        $this->assertArrayHasKey('gdpr', $report['frameworks']);
        $this->assertArrayHasKey('ccpa', $report['frameworks']);
        $this->assertArrayHasKey('soc2', $report['frameworks']);
        $this->assertArrayHasKey('eprivacy', $report['frameworks']);
    }

    public function test_get_health_summary_returns_all_framework_scores(): void
    {
        $config = $this->createMock(ConfigRepository::class);

        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'consent' => ['mode' => 'v2', 'log_enabled' => true, 'purposes' => ['a' => []]],
            'gdpr' => ['anonymize_ip' => true, 'pii_detection_enabled' => true],
            'data_retention' => ['default_retention_days' => 90, 'gdpr_erase_enabled' => true],
            'sdk_auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => true],
            'audit_log' => ['enabled' => true],
            'regional_consent' => ['enabled' => true],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        $summary = $service->getHealthSummary();

        $this->assertArrayHasKey('gdpr_score', $summary);
        $this->assertArrayHasKey('ccpa_score', $summary);
        $this->assertArrayHasKey('soc2_score', $summary);
        $this->assertArrayHasKey('eprivacy_score', $summary);
        $this->assertArrayHasKey('overall_score', $summary);
        $this->assertArrayHasKey('overall_grade', $summary);
        $this->assertArrayHasKey('critical_gaps', $summary);
        $this->assertArrayHasKey('generated_at', $summary);
        $this->assertGreaterThanOrEqual(0.0, $summary['overall_score']);
        $this->assertLessThanOrEqual(1.0, $summary['overall_score']);
    }

    public function test_score_to_grade_classification(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics')->willReturn([
            'consent' => [],
            'gdpr' => [],
            'data_retention' => [],
        ]);

        $service = new AnalyticsComplianceReportService($config);

        // Test with minimal config — all checks should be warn/fail but score should be >= 0
        $report = $service->generateGDPRReport();
        $this->assertGreaterThanOrEqual(0.0, $report['score']);
        $this->assertLessThanOrEqual(1.0, $report['score']);
        $this->assertNotEmpty($report['grade']);
    }
}
