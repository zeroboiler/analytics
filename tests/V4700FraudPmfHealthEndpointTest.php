<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventFraudDetectionService;
use ZeroBoiler\Analytics\Services\ProductMarketFitScoringService;
use ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService;

/**
 * V4700 — Event Fraud Detection, PMF Scoring, Unified Health Endpoint.
 *
 * Tests fraud signal detection, composite scoring, PMF computation,
 * health aggregation, and version consistency.
 *
 * @since 47.0.0
 */
final class V4700FraudPmfHealthEndpointTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository $cache;

    protected function setUp(): void
    {
        $this->cache = Cache::fake();
    }

    // ─── EventFraudDetectionService ───────────────────────────────────────

    public function test_fraud_service_evaluate_clean_event_passes(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']);

        $result = $service->evaluate($event, 'client_123');

        $this->assertSame('pass', $result['action']);
        $this->assertGreaterThan(0.0, $result['score']);
        $this->assertLessThan(1.0, $result['score']);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('velocity', $result['signals']);
        $this->assertArrayHasKey('burst', $result['signals']);
        $this->assertArrayHasKey('duplicate', $result['signals']);
        $this->assertArrayHasKey('injection', $result['signals']);
        $this->assertArrayHasKey('spoofed_identity', $result['signals']);
    }

    public function test_fraud_service_detects_parameter_injection(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '<script>alert(1)</script>']);

        $result = $service->evaluate($event, 'client_inject');

        $this->assertTrue($result['signals']['injection']['triggered']);
        $this->assertGreaterThan(0.0, $result['signals']['injection']['score']);
    }

    public function test_fraud_service_clean_params_no_injection(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/dashboard', 'title' => 'My Dashboard']);

        $result = $service->evaluate($event, 'client_clean');

        $this->assertFalse($result['signals']['injection']['triggered']);
        $this->assertSame(0.0, $result['signals']['injection']['score']);
    }

    public function test_fraud_service_detects_spoofed_identity(): void
    {
        $config = $this->createConfigRepository([
            'max_fingerprints_per_client' => 2,
            'spoofed_identity_window' => 3600,
        ]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        // First fingerprint
        $result1 = $service->evaluate($event, 'client_multi_fp', 'fp_001');
        $this->assertFalse($result1['signals']['spoofed_identity']['triggered']);

        // Second fingerprint
        $result2 = $service->evaluate($event, 'client_multi_fp', 'fp_002');
        $this->assertFalse($result2['signals']['spoofed_identity']['triggered']);

        // Third fingerprint — should trigger
        $result3 = $service->evaluate($event, 'client_multi_fp', 'fp_003');
        $this->assertTrue($result3['signals']['spoofed_identity']['triggered']);
        $this->assertGreaterThan(0.0, $result3['signals']['spoofed_identity']['score']);
    }

    public function test_fraud_service_no_spoofed_without_fingerprint(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $result = $service->evaluate($event, 'client_no_fp');

        $this->assertFalse($result['signals']['spoofed_identity']['triggered']);
        $this->assertSame(0.0, $result['signals']['spoofed_identity']['score']);
    }

    public function test_fraud_service_detects_duplicates(): void
    {
        $config = $this->createConfigRepository([
            'max_duplicate_hash_per_window' => 2,
            'duplicate_window' => 5,
        ]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);

        // First two should be fine
        $result1 = $service->evaluate($event, 'client_dup');
        $result2 = $service->evaluate($event, 'client_dup');
        $this->assertFalse($result1['signals']['duplicate']['triggered']);
        $this->assertFalse($result2['signals']['duplicate']['triggered']);

        // Third duplicate should trigger
        $result3 = $service->evaluate($event, 'client_dup');
        $this->assertTrue($result3['signals']['duplicate']['triggered']);
    }

    public function test_fraud_service_metrics_tracking(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $service->evaluate($event, 'client_metrics_1');
        $service->evaluate($event, 'client_metrics_2');

        $metrics = $service->getMetrics();

        $this->assertSame(2, $metrics['total_evaluated']);
        $this->assertSame(2, $metrics['passed']);
        $this->assertSame(0, $metrics['quarantined']);
        $this->assertSame(0, $metrics['blocked']);
        $this->assertGreaterThanOrEqual(0.0, $metrics['average_score']);
    }

    public function test_fraud_service_reset_metrics(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $service->evaluate($event, 'client_reset');
        $service->resetMetrics();

        $metrics = $service->getMetrics();

        $this->assertSame(0, $metrics['total_evaluated']);
    }

    public function test_fraud_service_should_block_returns_bool(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new EventFraudDetectionService($this->cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']);

        $this->assertFalse($service->shouldBlock($event, 'client_safe'));
    }

    public function test_fraud_service_thresholds(): void
    {
        $config = $this->createConfigRepository([
            'quarantine_threshold' => 0.7,
            'block_threshold' => 0.9,
        ]);
        $service = new EventFraudDetectionService($this->cache, $config);

        $this->assertSame(0.7, $service->getQuarantineThreshold());
        $this->assertSame(0.9, $service->getBlockThreshold());
    }

    // ─── ProductMarketFitScoringService ──────────────────────────────────

    public function test_pmf_compute_with_all_signals(): void
    {
        $config = $this->createConfigRepository([
            'ellis_threshold' => 0.40,
            'weights' => [
                'ellis_test' => 0.25,
                'activation_rate' => 0.20,
                'retention' => 0.20,
                'engagement' => 0.15,
                'organic_growth' => 0.10,
                'revenue_stickiness' => 0.10,
            ],
        ]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([
            'ellis_score' => 0.50,
            'activation_rate' => 0.65,
            'retention_d7' => 0.45,
            'retention_d30' => 0.35,
            'feature_depth' => 0.55,
            'organic_rate' => 0.20,
            'nrr' => 1.15,
            'weekly_active_ratio' => 0.60,
            'monthly_active_ratio' => 0.35,
        ]);

        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertIsString($result['grade']);
        $this->assertTrue(in_array($result['grade'], ['exceptional', 'strong', 'moderate', 'weak', 'none'], true));
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    public function test_pmf_strong_pmf_score(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([
            'ellis_score' => 0.55,
            'activation_rate' => 0.80,
            'retention_d7' => 0.60,
            'retention_d30' => 0.50,
            'feature_depth' => 0.70,
            'organic_rate' => 0.35,
            'nrr' => 1.30,
            'weekly_active_ratio' => 0.70,
        ]);

        $this->assertGreaterThanOrEqual(50, $result['score']);
    }

    public function test_pmf_weak_pmf_score(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([
            'ellis_score' => 0.15,
            'activation_rate' => 0.10,
            'retention_d7' => 0.10,
            'retention_d30' => 0.05,
            'feature_depth' => 0.08,
            'organic_rate' => 0.02,
            'nrr' => 0.70,
            'weekly_active_ratio' => 0.15,
        ]);

        $this->assertLessThanOrEqual(50, $result['score']);
    }

    public function test_pmf_null_signals_returns_zero_contributions(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('none', $result['grade']);
    }

    public function test_pmf_ellis_test_scoring(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        // Strong PMF signal (>40%)
        $this->assertGreaterThan(60, $service->scoreEllisTest(0.50));
        // Moderate signal (25-40%)
        $this->assertGreaterThan(30, $service->scoreEllisTest(0.30));
        $this->assertLessThan(60, $service->scoreEllisTest(0.30));
        // Weak signal (<25%)
        $this->assertLessThan(30, $service->scoreEllisTest(0.10));
    }

    public function test_pmf_activation_rate_scoring(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $this->assertGreaterThan(70, $service->scoreActivationRate(0.80));
        $this->assertLessThan(40, $service->scoreActivationRate(0.10));
    }

    public function test_pmf_retention_scoring_with_d30(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $this->assertGreaterThan(50, $service->scoreRetention(0.50, 0.45));
        $this->assertLessThan(30, $service->scoreRetention(null, 0.05));
    }

    public function test_pmf_revenue_stickiness_scoring(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        // NRR > 120% = strong
        $this->assertGreaterThan(80, $service->scoreRevenueStickiness(1.30));
        // NRR = 100% = moderate
        $this->assertGreaterThan(50, $service->scoreRevenueStickiness(1.0));
        // NRR < 80% = weak
        $this->assertLessThan(20, $service->scoreRevenueStickiness(0.60));
    }

    public function test_pmf_cache_score(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([
            'ellis_score' => 0.45,
            'activation_rate' => 0.55,
            'retention_d30' => 0.30,
            'feature_depth' => 0.40,
            'organic_rate' => 0.15,
            'nrr' => 1.05,
        ]);

        $service->cacheScore($result);
        $cached = $service->getCachedScore();

        $this->assertNotNull($cached);
        $this->assertSame($result['score'], $cached['score']);
        $this->assertSame($result['grade'], $cached['grade']);
    }

    public function test_pmf_clear_cache(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore(['ellis_score' => 0.50]);
        $service->cacheScore($result);
        $service->clearCache();

        $this->assertNull($service->getCachedScore());
    }

    public function test_pmf_config_summary(): void
    {
        $config = $this->createConfigRepository([
            'ellis_threshold' => 0.40,
        ]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $summary = $service->getConfigSummary();

        $this->assertArrayHasKey('ellis_threshold', $summary);
        $this->assertArrayHasKey('weights', $summary);
        $this->assertArrayHasKey('cache_ttl', $summary);
        $this->assertArrayHasKey('grading', $summary);
        $this->assertArrayHasKey('exceptional', $summary['grading']);
    }

    public function test_pmf_grading_scale(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $scale = $service->getGradingScale();

        $this->assertSame(5, count($scale));
        $this->assertSame(85, $scale['exceptional']['min']);
        $this->assertSame(100, $scale['exceptional']['max']);
        $this->assertSame(0, $scale['none']['min']);
        $this->assertSame(29, $scale['none']['max']);
    }

    public function test_pmf_recommendations_for_weak_signals(): void
    {
        $config = $this->createConfigRepository([]);
        $service = new ProductMarketFitScoringService($this->cache, $config);

        $result = $service->computeScore([
            'ellis_score' => 0.10,
            'activation_rate' => 0.10,
            'retention_d30' => 0.05,
            'feature_depth' => 0.05,
            'organic_rate' => 0.02,
            'nrr' => 0.60,
        ]);

        $this->assertNotEmpty($result['recommendations']);
    }

    // ─── UnifiedHealthEndpointService ────────────────────────────────────

    public function test_unified_health_returns_expected_structure(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn([
            'status' => 'healthy',
            'overall_score' => 100,
            'warnings' => [],
            'recommendations' => [],
        ]);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willReturn([
            'status' => 'healthy',
            'score' => 100,
        ]);

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);
        $healthMonitor->method('getStatus')->willReturn([
            'status' => 'healthy',
            'overall_score' => 100,
        ]);

        $service = new UnifiedHealthEndpointService(
            $healthService,
            $healthCheck,
            $healthMonitor,
        );

        $result = $service->check();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('subsystems', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertSame('47.0.0', $result['version']);
        $this->assertArrayHasKey('core', $result['subsystems']);
        $this->assertArrayHasKey('extended', $result['subsystems']);
        $this->assertArrayHasKey('monitor', $result['subsystems']);
    }

    public function test_unified_health_healthy_status(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn([
            'status' => 'healthy',
            'overall_score' => 95,
            'warnings' => [],
            'recommendations' => [],
        ]);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willReturn(['status' => 'healthy', 'score' => 90]);

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);
        $healthMonitor->method('getStatus')->willReturn(['status' => 'healthy', 'overall_score' => 92]);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);

        $result = $service->check();

        $this->assertSame('healthy', $result['status']);
        $this->assertGreaterThan(80, $result['score']);
    }

    public function test_unified_health_with_warning_status(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn([
            'status' => 'warning',
            'overall_score' => 60,
            'warnings' => ['Provider GA4 is misconfigured'],
            'recommendations' => ['Check GA4 measurement ID'],
        ]);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willReturn(['status' => 'warning', 'score' => 55]);

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);
        $healthMonitor->method('getStatus')->willReturn(['status' => 'warning', 'overall_score' => 50]);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);

        $result = $service->check();

        $this->assertSame('warning', $result['status']);
        $this->assertTrue(in_array('Provider GA4 is misconfigured', $result['warnings'], true));
        $this->assertTrue(in_array('Check GA4 measurement ID', $result['recommendations'], true));
    }

    public function test_unified_liveness_probe(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn(['status' => 'healthy']);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);
        $result = $service->liveness();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_unified_readiness_probe(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn(['status' => 'healthy']);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willReturn(['status' => 'healthy']);

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);
        $result = $service->readiness();

        $this->assertTrue($result['ready']);
        $this->assertSame('ready', $result['status']);
        $this->assertArrayHasKey('subsystems', $result);
    }

    public function test_unified_readiness_not_ready(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn(['status' => 'error']);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willThrowException(new \RuntimeException('Queue down'));

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);
        $result = $service->readiness();

        $this->assertFalse($result['ready']);
        $this->assertSame('not_ready', $result['status']);
    }

    public function test_unified_health_handles_extended_service_exception(): void
    {
        $healthService = $this->createMock(AnalyticsHealthService::class);
        $healthService->method('getHealthReport')->willReturn(['status' => 'healthy', 'overall_score' => 100]);

        $healthCheck = $this->createMock(AnalyticsHealthCheckService::class);
        $healthCheck->method('check')->willThrowException(new \RuntimeException('Service unavailable'));

        $healthMonitor = $this->createMock(AnalyticsHealthMonitorService::class);
        $healthMonitor->method('getStatus')->willReturn(['status' => 'healthy', 'overall_score' => 100]);

        $service = new UnifiedHealthEndpointService($healthService, $healthCheck, $healthMonitor);
        $result = $service->check();

        $this->assertArrayHasKey('extended', $result['subsystems']);
        $this->assertSame('unknown', $result['subsystems']['extended']['status']);
        $this->assertTrue(in_array('Extended health check service threw an exception', $result['warnings'], true));
    }

    // ─── Version Consistency ──────────────────────────────────────────────

    public function test_version_is_47(): void
    {
        $this->assertSame('47.0.0', AnalyticsEvent::VERSION);
    }

    public function test_event_catalog_has_all_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertArrayHasKey('security', $byCategory);
        $this->assertArrayHasKey('uptime', $byCategory);
        $this->assertArrayHasKey('infrastructure', $byCategory);
    }

    public function test_event_catalog_count_positive(): void
    {
        $this->assertGreaterThan(0, EventCatalog::count());
    }

    public function test_event_catalog_all_events_have_required_keys(): void
    {
        $all = EventCatalog::all();
        $required = ['name', 'class', 'ga4', 'category'];

        foreach ($all as $eventName => $entry) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $entry, "Event '{$eventName}' missing key '{$key}'");
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function createConfigRepository(array $overrides): \Illuminate\Contracts\Config\Repository
    {
        $defaults = [
            'fraud_detection' => [
                'cache_prefix' => 'zb_fraud_test_',
                'metrics_ttl' => 3600,
                'velocity_window' => 60,
                'max_events_per_window' => 200,
                'quarantine_threshold' => 0.6,
                'block_threshold' => 0.85,
                'burst_multiplier' => 5.0,
                'burst_window' => 10,
                'duplicate_window' => 5,
                'max_duplicate_hash_per_window' => 10,
                'suspicious_patterns' => ['<script', 'javascript:', 'data:', 'onerror='],
                'critical_events' => ['purchase', 'subscription_created'],
                'spoofed_identity_window' => 3600,
                'max_fingerprints_per_client' => 5,
            ],
            'pmf_scoring' => [
                'cache_prefix' => 'zb_pmf_test_',
                'cache_ttl' => 3600,
                'ellis_threshold' => 0.40,
                'weights' => [
                    'ellis_test' => 0.25,
                    'activation_rate' => 0.20,
                    'retention' => 0.20,
                    'engagement' => 0.15,
                    'organic_growth' => 0.10,
                    'revenue_stickiness' => 0.10,
                ],
            ],
        ];

        $merged = array_merge($defaults, $overrides);

        return new \Illuminate\Config\Repository([
            'zeroboiler' => ['analytics' => $merged],
        ]);
    }
}
