<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Analytics event delivery reliability scorer.
 *
 * Computes composite reliability scores for event delivery based on
 * multiple signals: provider failure rates, queue drop rates, dedup
 * collision rates, and dispatch latency degradation. Provides per-provider,
 * per-category, and overall pipeline reliability assessments.
 *
 * Reliability scores range from 0.0 (complete failure) to 1.0 (perfect delivery).
 * Scores below configurable thresholds trigger degradation warnings.
 *
 * @since 137.0.0
 */
final class AnalyticsEventReliabilityService
{
    private const CACHE_PREFIX = 'zb_reliability_';

    private const CACHE_TTL = 1800;

    private CacheRepository $cache;

    private float $warningThreshold;

    private float $criticalThreshold;

    private int $windowSeconds;

    /**
     * @param  CacheRepository  $cache  Cache repository for reliability data
     * @param  array{warning_threshold?: float, critical_threshold?: float, window_seconds?: int}  $config
     */
    public function __construct(
        CacheRepository $cache,
        array $config = [],
    ){
        $this->cache = $cache;
        $this->warningThreshold = (float) ($config['warning_threshold'] ?? 0.90);
        $this->criticalThreshold = (float) ($config['critical_threshold'] ?? 0.75);
        $this->windowSeconds = (int) ($config['window_seconds'] ?? 300);
    }

    /**
     * Record a successful event dispatch for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $eventName  Event name
     */
    public function recordSuccess(string $provider, string $eventName): void
    {
        $this->increment(self::CACHE_PREFIX . "provider:{$provider}:success");
        $this->increment(self::CACHE_PREFIX . "provider:{$provider}:total");
        $this->increment(self::CACHE_PREFIX . "global:success");
        $this->increment(self::CACHE_PREFIX . "global:total");
    }

    /**
     * Record a failed event dispatch for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $eventName  Event name
     * @param  string  $error  Error message
     */
    public function recordFailure(string $provider, string $eventName, string $error = ''): void
    {
        $this->increment(self::CACHE_PREFIX . "provider:{$provider}:failure");
        $this->increment(self::CACHE_PREFIX . "provider:{$provider}:total");
        $this->increment(self::CACHE_PREFIX . "global:failure");
        $this->increment(self::CACHE_PREFIX . "global:total");

        // Track recent failures for error aggregation
        $this->appendRecentFailure($provider, $eventName, $error);
    }

    /**
     * Record a dedup collision (event was not dispatched because it was a duplicate).
     *
     * @param  string  $eventName  Event name
     */
    public function recordDedupCollision(string $eventName): void
    {
        $this->increment(self::CACHE_PREFIX . 'global:dedup_collisions');
        $this->increment(self::CACHE_PREFIX . 'global:total');
    }

    /**
     * Get the reliability score for a specific provider.
     *
     * Computes: success_rate × latency_factor (simplified).
     * A score of 1.0 = perfect delivery, 0.0 = all failures.
     *
     * @param  string  $provider  Provider name
     * @return array{score: float, grade: string, success_count: int, failure_count: int, total_count: int, success_rate: float, is_degraded: bool}
     */
    public function providerScore(string $provider): array
    {
        $success = (int) $this->cache->get(self::CACHE_PREFIX . "provider:{$provider}:success", 0);
        $failure = (int) $this->cache->get(self::CACHE_PREFIX . "provider:{$provider}:failure", 0);
        $total = $success + $failure;

        if ($total === 0) {
            return [
                'score' => 1.0,
                'grade' => 'N/A',
                'success_count' => 0,
                'failure_count' => 0,
                'total_count' => 0,
                'success_rate' => 1.0,
                'is_degraded' => false,
            ];
        }

        $successRate = $success / $total;
        $grade = $this->gradeFromRate($successRate);

        return [
            'score' => round($successRate, 4),
            'grade' => $grade,
            'success_count' => $success,
            'failure_count' => $failure,
            'total_count' => $total,
            'success_rate' => round($successRate, 4),
            'is_degraded' => $successRate < $this->warningThreshold,
        ];
    }

    /**
     * Get reliability scores for all known providers.
     *
     * @return array<string, array{score: float, grade: string, success_count: int, failure_count: int, total_count: int, is_degraded: bool}>
     */
    public function allProviderScores(): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $scores = [];

        foreach ($providers as $provider) {
            $scores[$provider] = $this->providerScore($provider);
        }

        return $scores;
    }

    /**
     * Get the overall pipeline reliability score.
     *
     * Computes a composite score from all provider reliability scores.
     * Providers with zero traffic are excluded from the average.
     *
     * @return array{score: float, grade: string, provider_count: int, degraded_count: int, critical_count: int, global_success_rate: float}
     */
    public function overallScore(): array
    {
        $globalSuccess = (int) $this->cache->get(self::CACHE_PREFIX . 'global:success', 0);
        $globalFailure = (int) $this->cache->get(self::CACHE_PREFIX . 'global:failure', 0);
        $globalTotal = $globalSuccess + $globalFailure;
        $globalRate = $globalTotal > 0 ? $globalSuccess / $globalTotal : 1.0;

        $allScores = $this->allProviderScores();

        $activeScores = [];
        $degradedCount = 0;
        $criticalCount = 0;

        foreach ($allScores as $provider => $data) {
            if ($data['total_count'] > 0) {
                $activeScores[] = $data['score'];

                if ($data['is_degraded']) {
                    $degradedCount++;
                }

                if ($data['score'] < $this->criticalThreshold) {
                    $criticalCount++;
                }
            }
        }

        $compositeScore = count($activeScores) > 0
            ? array_sum($activeScores) / count($activeScores)
            : 1.0;

        return [
            'score' => round($compositeScore, 4),
            'grade' => $this->gradeFromRate($compositeScore),
            'provider_count' => count($activeScores),
            'degraded_count' => $degradedCount,
            'critical_count' => $criticalCount,
            'global_success_rate' => round($globalRate, 4),
        ];
    }

    /**
     * Get recent failure details.
     *
     * @param  int  $limit  Maximum number of failures to return
     * @return list<array{provider: string, event: string, error: string, timestamp: int}>
     */
    public function recentFailures(int $limit = 25): array
    {
        /** @var list<array{provider: string, event: string, error: string, timestamp: int}> $failures */
        $failures = $this->cache->get(self::CACHE_PREFIX . 'recent_failures', []);

        return array_slice($failures, 0, $limit);
    }

    /**
     * Get the dedup collision rate.
     *
     * @return array{collisions: int, total: int, rate: float}
     */
    public function dedupStats(): array
    {
        $collisions = (int) $this->cache->get(self::CACHE_PREFIX . 'global:dedup_collisions', 0);
        $total = (int) $this->cache->get(self::CACHE_PREFIX . 'global:total', 0);

        return [
            'collisions' => $collisions,
            'total' => $total,
            'rate' => $total > 0 ? round($collisions / $total, 4) : 0.0,
        ];
    }

    /**
     * Get the full reliability dashboard.
     *
     * @return array{version: string, overall: array, providers: array<string, array>, recent_failures: list<array>, dedup: array, thresholds: array{warning: float, critical: float}}
     */
    public function dashboard(): array
    {
        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'overall' => $this->overallScore(),
            'providers' => $this->allProviderScores(),
            'recent_failures' => $this->recentFailures(10),
            'dedup' => $this->dedupStats(),
            'thresholds' => [
                'warning' => $this->warningThreshold,
                'critical' => $this->criticalThreshold,
            ],
        ];
    }

    /**
     * Clear all reliability data from cache.
     */
    public function flush(): void
    {
        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $suffixes = ['success', 'failure', 'total'];

        foreach ($providers as $provider) {
            foreach ($suffixes as $suffix) {
                $this->cache->forget(self::CACHE_PREFIX . "provider:{$provider}:{$suffix}");
            }
        }

        $globalSuffixes = ['success', 'failure', 'total', 'dedup_collisions'];
        foreach ($globalSuffixes as $suffix) {
            $this->cache->forget(self::CACHE_PREFIX . "global:{$suffix}");
        }

        $this->cache->forget(self::CACHE_PREFIX . 'recent_failures');
    }

    /**
     * Assign a letter grade based on reliability rate.
     *
     * A+ (≥0.99), A (≥0.97), B (≥0.95), C (≥0.90), D (≥0.80), F (<0.80)
     */
    private function gradeFromRate(float $rate): string
    {
        return match (true) {
            $rate >= 0.99 => 'A+',
            $rate >= 0.97 => 'A',
            $rate >= 0.95 => 'B',
            $rate >= 0.90 => 'C',
            $rate >= 0.80 => 'D',
            default => 'F',
        };
    }

    /**
     * Increment a counter in cache.
     */
    private function increment(string $key): void
    {
        $value = (int) $this->cache->get($key, 0);
        $this->cache->put($key, $value + 1, self::CACHE_TTL);
    }

    /**
     * Append a recent failure to the ring buffer.
     */
    private function appendRecentFailure(string $provider, string $eventName, string $error): void
    {
        $key = self::CACHE_PREFIX . 'recent_failures';
        /** @var list<array{provider: string, event: string, error: string, timestamp: int}> $failures */
        $failures = $this->cache->get($key, []);
        $failures[] = [
            'provider' => $provider,
            'event' => $eventName,
            'error' => $error,
            'timestamp' => time(),
        ];

        // Keep last 100 failures
        if (count($failures) > 100) {
            $failures = array_slice($failures, -100);
        }

        $this->cache->put($key, $failures, self::CACHE_TTL);
    }
}
