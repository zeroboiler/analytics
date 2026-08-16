<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ProviderCircuitBreaker;

/**
 * Per-provider health monitoring service.
 *
 * Tracks dispatch success/failure rates per provider over a sliding window
 * and computes a health score (0-100). Providers with scores below the
 * threshold are flagged as unhealthy and can be automatically bypassed
 * during event dispatch.
 *
 * Integrates with ProviderCircuitBreaker for coordinated failover.
 *
 * @since 1.0.0
 */
final class ProviderHealthMonitor
{
    /** Minimum health score before provider is flagged as unhealthy. */
    private const UNHEALTHY_THRESHOLD = 50;

    /** Maximum health score. */
    private const MAX_SCORE = 100;

    /** @var array<string, array{successes: int, failures: int, last_failure: float|null, last_success: float|null, score: int}> */
    private array $stats = [];

    /** @var array<string, int> Sliding window counters per provider */
    private array $windowSuccesses = [];

    /** @var array<string, int> */
    private array $windowFailures = [];

    /** Sliding window duration in seconds. */
    private int $windowDuration;

    private AnalyticsMetrics $metrics;

    private ?ProviderCircuitBreaker $circuitBreaker;

    /**
     * @param  AnalyticsMetrics  $metrics
     * @param  ConfigRepository  $config
     * @param  ProviderCircuitBreaker|null  $circuitBreaker
     */
    public function __construct(
        AnalyticsMetrics $metrics,
        ConfigRepository $config,
        ?ProviderCircuitBreaker $circuitBreaker = null,
    ): void {
        $this->metrics = $metrics;
        $this->circuitBreaker = $circuitBreaker;

        $healthConfig = $config->get('zeroboiler.analytics.provider_health', []);
        /** @var array{window_duration?: int, unhealthy_threshold?: int} $healthConfig */
        $this->windowDuration = (int) ($healthConfig['window_duration'] ?? 300);
    }

    /**
     * Record a successful dispatch for a provider.
     */
    public function recordSuccess(string $provider): void
    {
        $this->initializeProvider($provider);
        $this->stats[$provider]['successes']++;
        $this->stats[$provider]['last_success'] = microtime(true);
        $this->windowSuccesses[$provider] = ($this->windowSuccesses[$provider] ?? 0) + 1;
        $this->recalculateScore($provider);
    }

    /**
     * Record a failed dispatch for a provider.
     */
    public function recordFailure(string $provider, string $error): void
    {
        $this->initializeProvider($provider);
        $this->stats[$provider]['failures']++;
        $this->stats[$provider]['last_failure'] = microtime(true);
        $this->windowFailures[$provider] = ($this->windowFailures[$provider] ?? 0) + 1;
        $this->recalculateScore($provider);
    }

    /**
     * Check if a provider is currently healthy.
     *
     * A provider is unhealthy if its score is below the threshold
     * or its circuit breaker is open.
     */
    public function isHealthy(string $provider): bool
    {
        if ($this->circuitBreaker !== null && ! $this->circuitBreaker->isAvailable($provider)) {
            return false;
        }

        $this->initializeProvider($provider);

        return $this->stats[$provider]['score'] >= self::UNHEALTHY_THRESHOLD;
    }

    /**
     * Get the health score for a provider (0-100).
     */
    public function getScore(string $provider): int
    {
        $this->initializeProvider($provider);

        return $this->stats[$provider]['score'];
    }

    /**
     * Get health status for all known providers.
     *
     * @return array<string, array{score: int, healthy: bool, successes: int, failures: int, last_success: float|null, last_failure: float|null, rate: float}>
     */
    public function getStatus(): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        $result = [];

        foreach ($providers as $provider) {
            $this->initializeProvider($provider);
            $stats = $this->stats[$provider];
            $total = $stats['successes'] + $stats['failures'];
            $rate = $total > 0 ? round(($stats['successes'] / $total) * 100, 2) : 100.0;

            $result[$provider] = [
                'score' => $stats['score'],
                'healthy' => $this->isHealthy($provider),
                'successes' => $stats['successes'],
                'failures' => $stats['failures'],
                'last_success' => $stats['last_success'],
                'last_failure' => $stats['last_failure'],
                'rate' => $rate,
            ];
        }

        return $result;
    }

    /**
     * Get a summary of provider health for dashboard display.
     *
     * @return array{overall_score: int, healthy_count: int, unhealthy_providers: list<string>, version: string}
     */
    public function summary(): array
    {
        $status = $this->getStatus();
        $scores = array_map(fn (array $s): int => $s['score'], $status);
        $overall = count($scores) > 0 ? (int) round(array_sum($scores) / count($scores)) : 100;
        $unhealthy = [];

        foreach ($status as $provider => $data) {
            if (! $data['healthy']) {
                $unhealthy[] = $provider;
            }
        }

        return [
            'overall_score' => $overall,
            'healthy_count' => count($status) - count($unhealthy),
            'unhealthy_providers' => $unhealthy,
            'version' => AnalyticsEvent::VERSION,
        ];
    }

    /**
     * Reset health stats for a specific provider.
     */
    public function reset(string $provider): void
    {
        unset($this->stats[$provider], $this->windowSuccesses[$provider], $this->windowFailures[$provider]);
    }

    /**
     * Reset all provider health stats.
     */
    public function resetAll(): void
    {
        $this->stats = [];
        $this->windowSuccesses = [];
        $this->windowFailures = [];
    }

    /**
     * Get the list of providers ordered by health score (worst first).
     *
     * @return list<string>
     */
    public function providersByHealth(): array
    {
        $status = $this->getStatus();
        uasort($status, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_keys($status);
    }

    /**
     * Get providers that are healthy enough to receive events.
     *
     * @return list<string>
     */
    public function activeProviders(): array
    {
        $status = $this->getStatus();
        $active = [];

        foreach ($status as $provider => $data) {
            if ($data['healthy']) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    /**
     * Initialize provider stats if not yet tracked.
     */
    private function initializeProvider(string $provider): void
    {
        if (! isset($this->stats[$provider])) {
            $this->stats[$provider] = [
                'successes' => 0,
                'failures' => 0,
                'last_failure' => null,
                'last_success' => null,
                'score' => self::MAX_SCORE,
            ];
        }
    }

    /**
     * Recalculate the health score based on success/failure ratio.
     */
    private function recalculateScore(string $provider): void
    {
        $stats = $this->stats[$provider];
        $total = $stats['successes'] + $stats['failures'];

        if ($total === 0) {
            $this->stats[$provider]['score'] = self::MAX_SCORE;

            return;
        }

        $successRate = $stats['successes'] / $total;
        $this->stats[$provider]['score'] = (int) round($successRate * self::MAX_SCORE);

        // Penalty for recent failures (exponential decay)
        if ($stats['last_failure'] !== null) {
            $secondsSinceFailure = microtime(true) - $stats['last_failure'];
            $decay = exp(-$secondsSinceFailure / 60); // 60s half-life
            $penalty = (int) round((1 - $decay) * 20); // Max 20 point penalty
            $this->stats[$provider]['score'] = max(0, $this->stats[$provider]['score'] - $penalty);
        }
    }
}
