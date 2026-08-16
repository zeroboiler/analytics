<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\ProviderFailoverService;
use Mockery;

/**
 * Tests for ProviderFailoverService — auto-failover orchestration for analytics providers.
 *
 * @since 145.0.0
 *
 * @covers \ZeroBoiler\Analytics\Services\ProviderFailoverService
 */
final class V145ProviderFailoverServiceTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository $cache;
    private ConfigRepository $config;
    private ProviderFailoverService $service;

    protected function setUp(): void
    {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.failover', [])
            ->andReturn([
                'enabled' => true,
                'strategy' => 'priority',
                'max_cascade_depth' => 3,
                'recovery_ramp_up_percent' => 10,
                'audit_log_ttl' => 86400,
                'providers' => [
                    'ga4' => ['posthog', 'meta_pixel', 'webhook'],
                    'meta_pixel' => ['ga4', 'posthog', 'webhook'],
                    'posthog' => ['ga4', 'meta_pixel', 'webhook'],
                ],
                'priority' => [
                    'ga4' => 1,
                    'meta_pixel' => 2,
                    'posthog' => 3,
                    'webhook' => 9,
                ],
            ]);

        $this->cache->shouldReceive('get')
            ->andReturnNull();

        $this->service = new ProviderFailoverService($this->cache, $this->config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ── Configuration ────────────────────────────────────────

    public function test_service_is_enabled_when_configured(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function test_service_returns_configured_strategy(): void
    {
        $this->assertSame('priority', $this->service->getStrategy());
    }

    public function test_service_returns_all_providers(): void
    {
        $providers = $this->service->allProviders();

        $this->assertContains('ga4', $providers);
        $this->assertContains('meta_pixel', $providers);
        $this->assertContains('posthog', $providers);
        $this->assertContains('plausible', $providers);
        $this->assertContains('webhook', $providers);
        $this->assertCount(9, $providers);
    }

    public function test_configuration_returns_expected_structure(): void
    {
        $config = $this->service->getConfiguration();

        $this->assertTrue($config['enabled']);
        $this->assertSame('priority', $config['strategy']);
        $this->assertSame(3, $config['max_cascade_depth']);
        $this->assertSame(10, $config['recovery_ramp_up_percent']);
        $this->assertArrayHasKey('providers', $config);
    }

    // ── Target Resolution (No Failover Needed) ──────────────

    public function test_resolve_targets_returns_all_when_all_circuits_closed(): void
    {
        $result = $this->service->resolveTargets(
            ['ga4', 'meta_pixel', 'posthog'],
            ['ga4' => 'closed', 'meta_pixel' => 'closed', 'posthog' => 'closed'],
        );

        $this->assertSame(['ga4', 'meta_pixel', 'posthog'], $result['targets']);
        $this->assertCount(0, $result['failovers']);
    }

    public function test_resolve_targets_returns_unmodified_when_failover_disabled(): void
    {
        $disabledConfig = Mockery::mock(ConfigRepository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.failover', [])
            ->andReturn(['enabled' => false]);

        $disabledService = new ProviderFailoverService($this->cache, $disabledConfig);

        $result = $disabledService->resolveTargets(
            ['ga4', 'meta_pixel'],
            ['ga4' => 'open', 'meta_pixel' => 'open'],
        );

        $this->assertSame(['ga4', 'meta_pixel'], $result['targets']);
        $this->assertCount(0, $result['failovers']);
    }

    // ── Failover: Circuit Open ───────────────────────────────

    public function test_resolve_targets_fails_over_when_circuit_open(): void
    {
        $this->cache->shouldReceive('put')->once();

        $result = $this->service->resolveTargets(
            ['ga4', 'meta_pixel'],
            ['ga4' => 'open', 'meta_pixel' => 'closed'],
        );

        $this->assertContains('posthog', $result['targets']);
        $this->assertContains('meta_pixel', $result['targets']);
        $this->assertCount(1, $result['failovers']);
        $this->assertSame('ga4', $result['failovers'][0]['from']);
        $this->assertSame('posthog', $result['failovers'][0]['to']);
        $this->assertSame('circuit_open', $result['failovers'][0]['reason']);
    }

    public function test_resolve_targets_selects_priority_fallback(): void
    {
        $this->cache->shouldReceive('put')->once();

        $result = $this->service->resolveTargets(
            ['meta_pixel'],
            ['meta_pixel' => 'open', 'ga4' => 'closed', 'posthog' => 'closed'],
        );

        // ga4 has priority 1, posthog has priority 3 → ga4 should be selected
        $this->assertContains('ga4', $result['targets']);
    }

    public function test_resolve_targets_no_fallback_when_all_circuits_open(): void
    {
        $result = $this->service->resolveTargets(
            ['ga4'],
            ['ga4' => 'open', 'posthog' => 'open', 'meta_pixel' => 'open'],
        );

        // No fallback available — ga4 not in targets
        $this->assertNotContains('ga4', $result['targets']);
        $this->assertCount(1, $result['failovers']);
    }

    // ── Failover Candidates ──────────────────────────────────

    public function test_get_fallback_candidates_filters_open_circuits(): void
    {
        $candidates = $this->service->getFallbackCandidates(
            'ga4',
            [],
            ['posthog' => 'open', 'meta_pixel' => 'closed'],
        );

        $this->assertContains('meta_pixel', $candidates);
        $this->assertNotContains('posthog', $candidates);
    }

    public function test_get_fallback_candidates_filters_already_selected(): void
    {
        $candidates = $this->service->getFallbackCandidates(
            'ga4',
            ['posthog'],
            ['posthog' => 'closed', 'meta_pixel' => 'closed'],
        );

        $this->assertNotContains('posthog', $candidates);
        $this->assertContains('meta_pixel', $candidates);
    }

    public function test_get_fallback_candidates_respects_cascade_depth(): void
    {
        // 2 already selected → cascade depth = 1, max = 3 → should still work
        $candidates = $this->service->getFallbackCandidates(
            'ga4',
            ['meta_pixel', 'posthog'],
            ['meta_pixel' => 'closed', 'posthog' => 'closed', 'webhook' => 'closed'],
        );

        $this->assertContains('webhook', $candidates);
    }

    public function test_get_fallback_candidates_empty_when_no_configured_fallbacks(): void
    {
        $candidates = $this->service->getFallbackCandidates(
            'unknown_provider',
            [],
            ['ga4' => 'closed'],
        );

        $this->assertCount(0, $candidates);
    }

    // ── Fallback Selection Strategies ────────────────────────

    public function test_select_fallback_priority_strategy(): void
    {
        $selected = $this->service->selectFallback(
            'ga4',
            [],
            ['posthog' => 'closed', 'meta_pixel' => 'closed'],
        );

        // ga4 fallbacks: posthog (priority 3), meta_pixel (priority 2) → meta_pixel wins
        $this->assertSame('meta_pixel', $selected);
    }

    public function test_select_fallback_returns_null_when_no_candidates(): void
    {
        $selected = $this->service->selectFallback(
            'ga4',
            ['posthog', 'meta_pixel', 'webhook'],
            ['posthog' => 'closed', 'meta_pixel' => 'closed', 'webhook' => 'closed'],
        );

        $this->assertNull($selected);
    }

    // ── Recovery Ramp-Up ────────────────────────────────────

    public function test_recovery_ramp_up_returns_100_when_no_ramp_active(): void
    {
        $percent = $this->service->getRecoveryRampUp('ga4');
        $this->assertSame(100, $percent);
    }

    public function test_start_recovery_ramp_up_stores_ramp_data(): void
    {
        $this->cache->shouldReceive('put')
            ->with(
                Mockery::any(),
                Mockery::on(fn (array $data): bool => isset($data['started_at']) && $data['current_percent'] === 10),
                3600,
            )
            ->once();

        $this->service->startRecoveryRampUp('ga4');
    }

    public function test_reset_recovery_ramp_up_clears_cache(): void
    {
        $this->cache->shouldReceive('forget')
            ->with(Mockery::stringContains('ramp_ga4'))
            ->once();

        $this->service->resetRecoveryRampUp('ga4');
    }

    // ── Audit Trail ──────────────────────────────────────────

    public function test_log_failover_stores_entry(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $this->cache->shouldReceive('put')
            ->with(
                Mockery::stringContains('audit'),
                Mockery::on(function (array $entries): bool {
                    return count($entries) === 1
                        && $entries[0]['from'] === 'ga4'
                        && $entries[0]['to'] === 'posthog'
                        && $entries[0]['reason'] === 'circuit_open';
                }),
                86400,
            )
            ->once();

        $this->service->logFailover('ga4', 'posthog', 'circuit_open');
    }

    public function test_get_audit_trail_returns_empty_when_no_entries(): void
    {
        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $trail = $this->service->getAuditTrail();

        $this->assertCount(0, $trail);
    }

    public function test_get_audit_trail_returns_entries(): void
    {
        $entries = [
            ['timestamp' => '2026-08-14T10:00:00+00:00', 'from' => 'ga4', 'to' => 'posthog', 'reason' => 'circuit_open'],
            ['timestamp' => '2026-08-14T11:00:00+00:00', 'from' => 'meta_pixel', 'to' => 'ga4', 'reason' => 'circuit_open'],
        ];

        $this->cache->shouldReceive('get')
            ->andReturn($entries);

        $trail = $this->service->getAuditTrail();

        $this->assertCount(2, $trail);
        $this->assertSame('ga4', $trail[0]['from']);
    }

    public function test_get_failover_summary_returns_aggregated_data(): void
    {
        $entries = [
            ['timestamp' => '2026-08-14T10:00:00+00:00', 'from' => 'ga4', 'to' => 'posthog', 'reason' => 'circuit_open'],
            ['timestamp' => '2026-08-14T11:00:00+00:00', 'from' => 'ga4', 'to' => 'posthog', 'reason' => 'circuit_open'],
            ['timestamp' => '2026-08-14T12:00:00+00:00', 'from' => 'meta_pixel', 'to' => 'ga4', 'reason' => 'recovery_ramp_up:10%'],
        ];

        $this->cache->shouldReceive('get')
            ->andReturn($entries);

        $summary = $this->service->getFailoverSummary();

        $this->assertSame(3, $summary['total_failovers']);
        $this->assertSame(2, $summary['by_provider']['ga4']);
        $this->assertSame(1, $summary['by_provider']['meta_pixel']);
        $this->assertSame(2, $summary['by_reason']['circuit_open']);
        $this->assertSame(1, $summary['by_reason']['recovery_ramp_up:10%']);
    }

    // ── Health Score ─────────────────────────────────────────

    public function test_health_score_closed_circuit_high_score(): void
    {
        $score = $this->service->computeHealthScore('ga4', ['ga4' => 'closed']);
        $this->assertGreaterThanOrEqual(60);
        $this->assertLessThanOrEqual(100);
    }

    public function test_health_score_open_circuit_zero(): void
    {
        $score = $this->service->computeHealthScore('ga4', ['ga4' => 'open']);
        $this->assertSame(0, $score);
    }

    public function test_health_score_half_open_medium_score(): void
    {
        $score = $this->service->computeHealthScore('ga4', ['ga4' => 'half_open']);
        $this->assertGreaterThanOrEqual(30);
        $this->assertLessThanOrEqual(70);
    }

    public function test_health_score_adjusted_by_metrics(): void
    {
        $scorePerfect = $this->service->computeHealthScore('ga4', ['ga4' => 'closed'], [
            'ga4' => ['success_rate' => 1.0, 'error_rate' => 0.0, 'avg_latency_ms' => 100],
        ]);

        $scoreDegraded = $this->service->computeHealthScore('ga4', ['ga4' => 'closed'], [
            'ga4' => ['success_rate' => 0.85, 'error_rate' => 0.15, 'avg_latency_ms' => 800],
        ]);

        $this->assertGreaterThan($scoreDegraded, $scorePerfect);
    }

    public function test_health_score_clamped_to_0_100(): void
    {
        $score = $this->service->computeHealthScore('ga4', ['ga4' => 'closed'], [
            'ga4' => ['success_rate' => 0.5, 'error_rate' => 0.5, 'avg_latency_ms' => 2000],
        ]);

        $this->assertGreaterThanOrEqual(0);
        $this->assertLessThanOrEqual(100);
    }

    // ── Half-Open Circuit Behavior ──────────────────────────

    public function test_half_open_provider_is_included_in_targets(): void
    {
        $result = $this->service->resolveTargets(
            ['ga4'],
            ['ga4' => 'half_open'],
        );

        $this->assertContains('ga4', $result['targets']);
        $this->assertCount(0, $result['failovers']);
    }

    // ── Strict Types & Class Structure ───────────────────────

    public function test_service_is_final(): void
    {
        $reflection = new \ReflectionClass(ProviderFailoverService::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_service_constants_are_strings(): void
    {
        $this->assertSame('priority', ProviderFailoverService::STRATEGY_PRIORITY);
        $this->assertSame('round_robin', ProviderFailoverService::STRATEGY_ROUND_ROBIN);
        $this->assertSame('health_score', ProviderFailoverService::STRATEGY_HEALTH_SCORE);
    }

    public function test_service_has_declare_strict_types(): void
    {
        $reflection = new \ReflectionClass(ProviderFailoverService::class);
        $file = $reflection->getFileName();
        $contents = (string) file_get_contents((string) $file);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }
}
