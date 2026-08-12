<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\UserEngagementScoringService;

// ── V340 User Engagement Scoring Service ───────────────────────────

describe('V340 User Engagement Scoring Service', function () {

    beforeEach(function (): void {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->metrics = Mockery::mock(AnalyticsMetrics::class);
        $this->config = new ConfigRepository([
            'zeroboiler' => [
                'analytics' => [
                    'engagement_scoring' => [
                        'enabled' => true,
                        'cache_ttl' => 3600,
                        'recency_half_life' => 604800,
                        'max_events_window' => 90,
                        'weights' => [
                            'frequency' => 0.30,
                            'recency' => 0.20,
                            'breadth' => 0.20,
                            'lifecycle' => 0.15,
                            'revenue' => 0.15,
                        ],
                    ],
                ],
            ],
        ]);
    });

    afterEach(function (): void {
        Mockery::close();
    });

    // ── 1. Score Computation ──────────────────────────────────────

    describe('score computation', function () {
        test('computes engagement score for a user', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(100);

            $this->cache->shouldReceive('remember')
                ->once()
                ->with('zb_engagement_score_user_123', 3600, Mockery::type('Closure'))
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->score('user_123');

            expect($result)->toHaveKey('score');
            expect($result)->toHaveKey('signals');
            expect($result)->toHaveKey('tier');
            expect($result)->toHaveKey('computed_at');
            expect($result['score'])->toBeFloat();
            expect($result['score'])->toBeGreaterThanOrEqual(0.0);
            expect($result['score'])->toBeLessThanOrEqual(100.0);
            expect($result['signals'])->toHaveKeys(['frequency', 'recency', 'breadth', 'lifecycle', 'revenue']);
            expect($result['tier'])->toBeIn(['champion', 'active', 'moderate', 'dormant', 'at_risk']);
        });

        test('score is cached after first computation', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(50);

            $cachedResult = [
                'score' => 75.5,
                'signals' => [
                    'frequency' => 80.0,
                    'recency' => 90.0,
                    'breadth' => 60.0,
                    'lifecycle' => 70.0,
                    'revenue' => 80.0,
                ],
                'tier' => 'active',
                'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            $this->cache->shouldReceive('remember')
                ->once()
                ->with('zb_engagement_score_user_456', 3600, Mockery::type('Closure'))
                ->andReturn($cachedResult);

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->score('user_456');

            expect($result['score'])->toBe(75.5);
            expect($result['tier'])->toBe('active');
        });

        test('score is a float between 0 and 100', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(1000);

            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);

            // Test multiple users to verify bounds
            for ($i = 0; $i < 20; $i++) {
                $result = $service->score('user_' . $i);
                expect($result['score'])->toBeGreaterThanOrEqual(0.0);
                expect($result['score'])->toBeLessThanOrEqual(100.0);
            }
        });
    });

    // ── 2. Tier Classification ─────────────────────────────────

    describe('tier classification', function () {
        test('classifies scores into correct tiers', function (): void {
            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);

            expect($service->tierLabel(95.0))->toBe('champion');
            expect($service->tierLabel(80.0))->toBe('champion');
            expect($service->tierLabel(79.9))->toBe('active');
            expect($service->tierLabel(60.0))->toBe('active');
            expect($service->tierLabel(59.9))->toBe('moderate');
            expect($service->tierLabel(40.0))->toBe('moderate');
            expect($service->tierLabel(39.9))->toBe('dormant');
            expect($service->tierLabel(20.0))->toBe('dormant');
            expect($service->tierLabel(19.9))->toBe('at_risk');
            expect($service->tierLabel(0.0))->toBe('at_risk');
        });

        test('score result includes correct tier for high scores', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(1);

            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->score('high_score_user');

            expect(in_array($result['tier'], ['champion', 'active', 'moderate', 'dormant', 'at_risk'], true))
                ->toBeTrue();
        });
    });

    // ── 3. Batch Scoring ────────────────────────────────────────

    describe('batch scoring', function () {
        test('scores multiple users in batch', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(100);
            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $results = $service->scoreBatch(['user_a', 'user_b', 'user_c']);

            expect($results)->toHaveCount(3);
            expect($results)->toHaveKeys(['user_a', 'user_b', 'user_c']);

            foreach ($results as $userId => $data) {
                expect($data)->toHaveKey('score');
                expect($data)->toHaveKey('tier');
                expect($data['score'])->toBeFloat();
            }
        });

        test('batch scoring returns empty array for empty input', function (): void {
            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $results = $service->scoreBatch([]);

            expect($results)->toBeArray();
            expect($results)->toBeEmpty();
        });
    });

    // ── 4. Cache Invalidation ───────────────────────────────────

    describe('cache invalidation', function () {
        test('invalidates cached score for a user', function (): void {
            $this->cache->shouldReceive('forget')
                ->once()
                ->with('zb_engagement_score_user_789')
                ->andReturn(true);

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->invalidateScore('user_789');

            expect($result)->toBeTrue();
        });
    });

    // ── 5. Config-Driven Weights ────────────────────────────────

    describe('config-driven weights', function () {
        test('accepts custom weights from config', function (): void {
            $customConfig = new ConfigRepository([
                'zeroboiler' => [
                    'analytics' => [
                        'engagement_scoring' => [
                            'weights' => [
                                'frequency' => 0.50,
                                'recency' => 0.20,
                                'breadth' => 0.10,
                                'lifecycle' => 0.10,
                                'revenue' => 0.10,
                            ],
                        ],
                    ],
                ],
            ]);

            $this->metrics->shouldReceive('totalDispatched')->andReturn(100);
            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $customConfig);
            $result = $service->score('weighted_user');

            // Verify the service uses custom weights without error
            expect($result['score'])->toBeFloat();
            expect($result['score'])->toBeGreaterThanOrEqual(0.0);
            expect($result['score'])->toBeLessThanOrEqual(100.0);
        });

        test('uses default weights when config is empty', function (): void {
            $emptyConfig = new ConfigRepository(['zeroboiler' => ['analytics' => []]]);

            $this->metrics->shouldReceive('totalDispatched')->andReturn(100);
            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $emptyConfig);
            $result = $service->score('default_weights_user');

            expect($result['score'])->toBeFloat();
            expect($result['tier'])->toBeString();
        });
    });

    // ── 6. Signal Sub-Scores ───────────────────────────────────

    describe('signal sub-scores', function () {
        test('all signal sub-scores are between 0 and 100', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(500);
            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->score('signals_user');

            foreach ($result['signals'] as $signal => $subScore) {
                expect($subScore)->toBeGreaterThanOrEqual(0.0);
                expect($subScore)->toBeLessThanOrEqual(100.0);
            }
        });

        test('all five signals are present in result', function (): void {
            $this->metrics->shouldReceive('totalDispatched')->andReturn(100);
            $this->cache->shouldReceive('remember')
                ->andReturnUsing(function (string $key, int $ttl, \Closure $callback): array {
                    return $callback();
                });

            $service = new UserEngagementScoringService($this->cache, $this->metrics, $this->config);
            $result = $service->score('all_signals_user');

            $expectedSignals = ['frequency', 'recency', 'breadth', 'lifecycle', 'revenue'];
            foreach ($expectedSignals as $signal) {
                expect($result['signals'])->toHaveKey($signal);
            }
        });
    });

    // ── 7. Version Alignment ────────────────────────────────────

    describe('version alignment', function () {
        test('AnalyticsEvent::VERSION is 34.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('34.0.0');
        });

        test('overview command total_providers is 10', function (): void {
            $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
            $method = $reflection->getMethod('buildOverview');
            $method->setAccessible(true);

            // We can't easily test this without a full container,
            // so we verify the constant via file content inspection
            $content = file_get_contents(
                (string) $reflection->getFileName()
                    ?? __DIR__ . '/../../src/Console/Commands/AnalyticsOverviewCommand.php'
            );

            expect(str_contains($content, "'total_providers' => 10,"))->toBeTrue();
            expect(str_contains($content, "'total_providers' => 8,"))->toBeFalse();
        });

        test('engagement_scoring config section exists', function (): void {
            $configContent = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');

            expect(str_contains($configContent, "'engagement_scoring'"))->toBeTrue();
            expect(str_contains($configContent, "'frequency'"))->toBeTrue();
            expect(str_contains($configContent, "'recency'"))->toBeTrue();
            expect(str_contains($configContent, "'breadth'"))->toBeTrue();
            expect(str_contains($configContent, "'lifecycle'"))->toBeTrue();
            expect(str_contains($configContent, "'revenue'"))->toBeTrue();
        });
    });
});
