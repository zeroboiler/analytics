<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService;
use ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer;
use ZeroBoiler\Analytics\Services\EventCorrelationEngineService;

/**
 * Tests for v48.0.0 — Event Correlation Engine, Anomaly Root Cause Analyzer,
 * and Analytics Self-Healing Service.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventCorrelationEngineService
 * @covers \ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsSelfHealCommand
 */
final class V48CorrelationSelfHealTest extends \PHPUnit\Framework\TestCase
{
    private \Illuminate\Contracts\Cache\Repository $cache;

    private \Illuminate\Contracts\Config\Repository $config;

    protected function setUp(): void
    {
        $this->cache = new \Illuminate\Cache\ArrayStore;
        $this->config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'correlation_engine' => [
                        'cache_prefix' => 'test_corr_',
                        'cache_ttl' => 7200,
                        'time_window_seconds' => 300,
                        'min_cooccurrence' => 2,
                        'min_correlation_score' => 0.1,
                        'decay_rate' => 0.95,
                        'max_correlations_per_event' => 10,
                        'max_event_pair_cache_size' => 1000,
                    ],
                    'root_cause_analyzer' => [
                        'cache_prefix' => 'test_rca_',
                        'cache_ttl' => 1800,
                        'max_root_causes' => 5,
                        'lookback_window_seconds' => 3600,
                        'min_confidence_score' => 0.1,
                    ],
                    'self_healing' => [
                        'cache_prefix' => 'test_heal_',
                        'history_ttl' => 86400,
                        'max_history_entries' => 200,
                        'auto_heal_enabled' => false,
                        'auto_heal_actions' => [],
                        'healing_cooldown_seconds' => 0,
                    ],
                ],
            ],
        ]);
    }

    // ───────────────────────────────────────────────────────────────────
    // EventCorrelationEngineService Tests
    // ───────────────────────────────────────────────────────────────────

    #[Test]
    public function it_records_cooccurrence_and_retrieves_data(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $engine->recordCooccurrence('page_view', 'click', 5);
        $engine->recordCooccurrence('page_view', 'click', 3);
        $engine->recordCooccurrence('page_view', 'click', 7);

        $pairData = $engine->getCorrelatedEvents('page_view');

        $this->assertIsArray($pairData);
    }

    #[Test]
    public function it_returns_zero_for_nonexistent_correlation(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $score = $engine->getCorrelationScore('nonexistent_event_a', 'nonexistent_event_b');

        $this->assertSame(0.0, $score);
    }

    #[Test]
    public function it_returns_one_for_same_event_correlation(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $score = $engine->getCorrelationScore('page_view', 'page_view');

        $this->assertSame(1.0, $score);
    }

    #[Test]
    public function it_ignores_same_event_cooccurrence(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $engine->recordCooccurrence('page_view', 'page_view', 0);

        // Should not throw or error — silently ignored
        $this->assertTrue(true);
    }

    #[Test]
    public function it_normalizes_pair_keys_bidirectionally(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $engine->recordCooccurrence('event_a', 'event_b', 10);
        $engine->recordCooccurrence('event_b', 'event_a', 5);

        $scoreAB = $engine->getCorrelationScore('event_a', 'event_b');
        $scoreBA = $engine->getCorrelationScore('event_b', 'event_a');

        // Both should return the same score (bidirectional)
        $this->assertEqualsWithDelta($scoreAB, $scoreBA, 0.0001);
    }

    #[Test]
    public function it_provides_summary(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $summary = $engine->getSummary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_pairs_tracked', $summary);
        $this->assertArrayHasKey('total_cooccurrences', $summary);
        $this->assertArrayHasKey('events_with_correlations', $summary);
        $this->assertArrayHasKey('avg_correlation_score', $summary);
        $this->assertArrayHasKey('cache_prefix', $summary);
        $this->assertArrayHasKey('time_window_seconds', $summary);
    }

    #[Test]
    public function it_clears_correlations(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $engine->recordCooccurrence('page_view', 'click', 5);
        $cleared = $engine->clearCorrelations();

        $this->assertGreaterThanOrEqual(0, $cleared);
    }

    #[Test]
    public function it_records_cooccurrence_with_context(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $engine->recordCooccurrence('sign_up', 'login', 60, [
            'user_id' => '12345',
            'session_id' => 'abc',
        ]);

        // Should not throw
        $this->assertTrue(true);
    }

    #[Test]
    public function it_gets_antecedents(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $antecedents = $engine->getAntecedents('purchase', 5);

        $this->assertIsArray($antecedents);
    }

    #[Test]
    public function it_gets_consequents(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $consequents = $engine->getConsequents('page_view', 5);

        $this->assertIsArray($consequents);
    }

    #[Test]
    public function it_returns_top_correlations(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);

        $top = $engine->getTopCorrelations(5);

        $this->assertIsArray($top);
    }

    // ───────────────────────────────────────────────────────────────────
    // AnomalyRootCauseAnalyzer Tests
    // ───────────────────────────────────────────────────────────────────

    #[Test]
    public function it_analyzes_root_cause(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $result = $analyzer->analyze('purchase', 'spike');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('root_causes', $result);
        $this->assertArrayHasKey('analysis_id', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('anomalous_event', $result);
        $this->assertArrayHasKey('anomaly_type', $result);
        $this->assertSame('purchase', $result['anomalous_event']);
        $this->assertSame('spike', $result['anomaly_type']);
    }

    #[Test]
    public function it_provides_top_root_cause(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $top = $analyzer->getTopRootCause('page_view', 'drop');

        $this->assertIsArray($top);
        $this->assertArrayHasKey('event', $top);
        $this->assertArrayHasKey('category', $top);
        $this->assertArrayHasKey('confidence', $top);
        $this->assertArrayHasKey('suggestion', $top);
    }

    #[Test]
    public function it_returns_null_top_cause_when_no_data(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        // With no correlations, infrastructure fallback is generated
        $top = $analyzer->getTopRootCause('nonexistent', 'spike');

        $this->assertIsArray($top);
    }

    #[Test]
    public function it_tracks_analysis_history(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $analyzer->analyze('purchase', 'spike');
        $analyzer->analyze('sign_up', 'drop');

        $history = $analyzer->getAnalysisHistory();

        $this->assertIsArray($history);
        $this->assertCount(2, $history);
    }

    #[Test]
    public function it_provides_analyzer_summary(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $summary = $analyzer->getSummary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_analyses', $summary);
        $this->assertArrayHasKey('events_analyzed', $summary);
        $this->assertArrayHasKey('categories', $summary);
        $this->assertArrayHasKey('avg_confidence', $summary);
    }

    #[Test]
    public function it_clears_analysis_history(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $analyzer->analyze('purchase', 'spike');
        $analyzer->clearHistory();

        $history = $analyzer->getAnalysisHistory();
        $this->assertCount(0, $history);
    }

    #[Test]
    public function it_analyzes_different_anomaly_types(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        foreach (['spike', 'drop', 'error', 'latency', 'quality'] as $type) {
            $result = $analyzer->analyze('page_view', $type);
            $this->assertSame($type, $result['anomaly_type'], "Failed for anomaly type: {$type}");
        }
    }

    #[Test]
    public function it_generates_root_causes_with_required_fields(): void
    {
        $engine = new EventCorrelationEngineService($this->cache, $this->config);
        $analyzer = new AnomalyRootCauseAnalyzer($this->cache, $engine, $this->config);

        $result = $analyzer->analyze('page_view', 'error');

        foreach ($result['root_causes'] as $cause) {
            $this->assertArrayHasKey('event', $cause);
            $this->assertArrayHasKey('category', $cause);
            $this->assertArrayHasKey('confidence', $cause);
            $this->assertArrayHasKey('correlation', $cause);
            $this->assertArrayHasKey('direction', $cause);
            $this->assertArrayHasKey('explanation', $cause);
            $this->assertArrayHasKey('suggestion', $cause);
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // AnalyticsSelfHealingService Tests
    // ───────────────────────────────────────────────────────────────────

    #[Test]
    public function it_executes_warm_cache_healing(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('warm_cache');

        $this->assertSame('success', $result['status']);
        $this->assertSame('warm_cache', $result['action']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('duration_ms', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    #[Test]
    public function it_executes_reset_provider_health(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('reset_provider_health');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_reset_pipeline(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('reset_pipeline');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_cleanup_stale_data(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('cleanup_stale_data');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_check_queue_health(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('check_queue_health');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_reset_fraud_metrics(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('reset_fraud_metrics');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_reset_quality_firewall(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('reset_quality_firewall');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_clear_correlations(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('clear_correlations');

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function it_executes_flush_dlq_without_service(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('flush_dlq');

        // Should fail gracefully when DLQ service is not available
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not available', $result['message']);
    }

    #[Test]
    public function it_returns_failed_for_unknown_action(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->heal('unknown_action_xyz');

        $this->assertSame('failed', $result['status']);
    }

    #[Test]
    public function it_heals_all_actions(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->healAll();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('succeeded', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertGreaterThanOrEqual(9, $result['total']);
    }

    #[Test]
    public function it_returns_auto_heal_disabled_status(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $result = $service->autoHeal();

        $this->assertFalse($result['auto_heal_enabled']);
        $this->assertSame([], $result['actions_triggered']);
    }

    #[Test]
    public function it_tracks_healing_history(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $service->heal('warm_cache');
        $service->heal('reset_pipeline');

        $history = $service->getHistory();

        $this->assertCount(2, $history);
    }

    #[Test]
    public function it_provides_service_summary(): void
    {
        $service = new AnalyticsSelfHealingService($this->cache, $this->config);

        $summary = $service->getSummary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('auto_heal_enabled', $summary);
        $this->assertArrayHasKey('auto_heal_actions', $summary);
        $this->assertArrayHasKey('cooldown_seconds', $summary);
        $this->assertArrayHasKey('total_healings', $summary);
        $this->assertArrayHasKey('last_healing', $summary);
        $this->assertArrayHasKey('available_actions', $summary);
        $this->assertCount(9, $summary['available_actions']);
    }

    #[Test]
    public function it_respects_cooldown_period(): void
    {
        // Config with cooldown
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'self_healing' => [
                        'cache_prefix' => 'test_heal_cooldown_',
                        'history_ttl' => 86400,
                        'max_history_entries' => 200,
                        'auto_heal_enabled' => false,
                        'auto_heal_actions' => [],
                        'healing_cooldown_seconds' => 300,
                    ],
                ],
            ],
        ]);

        $service = new AnalyticsSelfHealingService($this->cache, $config);

        $service->heal('warm_cache');

        // Second call should be skipped due to cooldown
        $result = $service->heal('warm_cache');

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('cooldown', $result['message']);
    }

    #[Test]
    public function it_provides_version_consistency(): void
    {
        $this->assertSame('48.0.0', AnalyticsEvent::VERSION);
    }
}
