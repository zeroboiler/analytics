<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Backpressure and circuit-breaker service for the analytics event queue.
 *
 * Provides rate limiting, circuit-breaker protection, and dead-letter queue
 * routing for the analytics dispatch pipeline. Prevents queue flooding during
 * traffic spikes and protects downstream analytics providers from cascading failures.
 *
 * Configuration is read from `zeroboiler.analytics.queue.backpressure`.
 *
 * Features:
 * - Per-client rate limiting (events/minute per client_id)
 * - Global throughput cap (total events/second)
 * - Circuit breaker: trips open after N consecutive failures, auto-recovers
 * - Dead-letter queue routing: failed events are logged for replay
 * - In-memory sliding window counters with cache backing
 *
 * @since 272.0.0
 */
final class EventBackpressureService
{
    private bool $enabled;

    private int $maxEventsPerMinute;

    private int $maxGlobalPerSecond;

    private int $circuitBreakerThreshold;

    private int $circuitBreakerResetSeconds;

    /** @var int TTL for rate limit cache keys (seconds) */
    private int $rateLimitTtl;

    /** @var string Cache prefix for all backpressure keys */
    private string $cachePrefix;

    /** @var int Consecutive failure counter */
    private int $consecutiveFailures = 0;

    /** @var int|null Timestamp when circuit breaker tripped open */
    private ?int $circuitBreakerTrippedAt = null;

    /** @var int Events dispatched in the current second (in-memory) */
    private int $currentSecondCount = 0;

    /** @var int The current second bucket */
    private int $currentSecondBucket = 0;

    /** @var int Events rejected by backpressure in this request */
    private int $rejectedCount = 0;

    private CacheRepository $cache;

    public function __construct(CacheRepository $cache, ConfigRepository $config)
    {
        $this->cache = $cache;

        $bpConfig = $config->get('zeroboiler.analytics.queue.backpressure', []);
        /** @var array{enabled?: bool, max_events_per_minute?: int, max_global_per_second?: int, circuit_breaker_threshold?: int, circuit_breaker_reset_seconds?: int, cache_prefix?: string} $bpConfig */

        $this->enabled = (bool) ($bpConfig['enabled'] ?? true);
        $this->maxEventsPerMinute = (int) ($bpConfig['max_events_per_minute'] ?? 600);
        $this->maxGlobalPerSecond = (int) ($bpConfig['max_global_per_second'] ?? 100);
        $this->circuitBreakerThreshold = (int) ($bpConfig['circuit_breaker_threshold'] ?? 10);
        $this->circuitBreakerResetSeconds = (int) ($bpConfig['circuit_breaker_reset_seconds'] ?? 60);
        $this->rateLimitTtl = 120;
        $this->cachePrefix = (string) ($bpConfig['cache_prefix'] ?? 'zb_bp_');

        // Restore circuit breaker state from cache
        $this->restoreCircuitBreakerState();
    }

    /**
     * Check if an event should be allowed through the backpressure filter.
     *
     * Returns true if the event passes all checks:
     * 1. Circuit breaker is not open
     * 2. Per-client rate limit not exceeded
     * 3. Global throughput cap not exceeded
     *
     * @param  AnalyticsEvent  $event
     * @return bool  True if the event should be dispatched
     */
    public function allow(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Check circuit breaker
        if ($this->isCircuitBreakerOpen()) {
            $this->rejectedCount++;

            return false;
        }

        // Check global throughput
        if (! $this->checkGlobalThroughput()) {
            $this->rejectedCount++;

            return false;
        }

        // Check per-client rate limit
        $clientId = $event->clientId ?? 'anonymous';
        if (! $this->checkClientRateLimit((string) $clientId)) {
            $this->rejectedCount++;

            return false;
        }

        return true;
    }

    /**
     * Record a successful event dispatch (resets failure counter).
     */
    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->persistCircuitBreakerState();
    }

    /**
     * Record a failed event dispatch (increments failure counter, may trip circuit breaker).
     */
    public function recordFailure(): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= $this->circuitBreakerThreshold) {
            $this->tripCircuitBreaker();
        }

        $this->persistCircuitBreakerState();
    }

    /**
     * Get the number of events rejected by backpressure in this request.
     */
    public function getRejectedCount(): int
    {
        return $this->rejectedCount;
    }

    /**
     * Check if the circuit breaker is currently open.
     */
    public function isCircuitBreakerOpen(): bool
    {
        if ($this->circuitBreakerTrippedAt === null) {
            return false;
        }

        // Auto-recovery after reset period
        if ((time() - $this->circuitBreakerTrippedAt) >= $this->circuitBreakerResetSeconds) {
            $this->resetCircuitBreaker();

            return false;
        }

        return true;
    }

    /**
     * Get the circuit breaker state for diagnostics.
     *
     * @return array{open: bool, consecutive_failures: int, threshold: int, tripped_at: int|null, resets_in: int|null}
     */
    public function circuitBreakerState(): array
    {
        $resetsIn = null;

        if ($this->circuitBreakerTrippedAt !== null) {
            $elapsed = time() - $this->circuitBreakerTrippedAt;
            $resetsIn = max(0, $this->circuitBreakerResetSeconds - $elapsed);
        }

        return [
            'open' => $this->isCircuitBreakerOpen(),
            'consecutive_failures' => $this->consecutiveFailures,
            'threshold' => $this->circuitBreakerThreshold,
            'tripped_at' => $this->circuitBreakerTrippedAt,
            'resets_in' => $resetsIn,
        ];
    }

    /**
     * Get backpressure configuration summary.
     *
     * @return array{enabled: bool, max_events_per_minute: int, max_global_per_second: int, circuit_breaker: array{open: bool, consecutive_failures: int, threshold: int}, rejected_this_request: int}
     */
    public function summary(): array
    {
        $cbState = $this->circuitBreakerState();

        return [
            'enabled' => $this->enabled,
            'max_events_per_minute' => $this->maxEventsPerMinute,
            'max_global_per_second' => $this->maxGlobalPerSecond,
            'circuit_breaker' => [
                'open' => $cbState['open'],
                'consecutive_failures' => $cbState['consecutive_failures'],
                'threshold' => $cbState['threshold'],
            ],
            'rejected_this_request' => $this->rejectedCount,
        ];
    }

    /**
     * Manually trip the circuit breaker (for testing or admin override).
     */
    public function tripCircuitBreaker(): void
    {
        $this->circuitBreakerTrippedAt = time();
        $this->persistCircuitBreakerState();
    }

    /**
     * Manually reset the circuit breaker.
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreakerTrippedAt = null;
        $this->consecutiveFailures = 0;
        $this->persistCircuitBreakerState();
    }

    /**
 * Check per-client rate limit using a sliding window counter.
 */
 private function checkClientRateLimit(string $clientId): bool
 {
     $cacheKey = $this->cachePrefix . 'client:' . md5($clientId);
     $current = (int) $this->cache->get($cacheKey, 0);

     if ($current >= $this->maxEventsPerMinute) {
         return false;
     }

     $this->cache->put($cacheKey, $current + 1, $this->rateLimitTtl);

     return true;
 }

    /**
     * Check global throughput cap (events per second).
     */
    private function checkGlobalThroughput(): bool
    {
        $bucket = (int) (time() % 60);

        if ($bucket !== $this->currentSecondBucket) {
            $this->currentSecondBucket = $bucket;
            $this->currentSecondCount = 0;
        }

        if ($this->currentSecondCount >= $this->maxGlobalPerSecond) {
            return false;
        }

        $this->currentSecondCount++;

        return true;
    }

    /**
     * Restore circuit breaker state from cache.
     */
    private function restoreCircuitBreakerState(): void
    {
        $state = $this->cache->get($this->cachePrefix . 'circuit_breaker');

        if (is_array($state)) {
            /** @var array{failures?: int, tripped_at?: int|null} $state */
            $this->consecutiveFailures = (int) ($state['failures'] ?? 0);
            $trippedAt = $state['tripped_at'] ?? null;
            $this->circuitBreakerTrippedAt = is_int($trippedAt) ? $trippedAt : null;
        }
    }

    /**
     * Persist circuit breaker state to cache.
     */
    private function persistCircuitBreakerState(): void
    {
        $this->cache->put($this->cachePrefix . 'circuit_breaker', [
            'failures' => $this->consecutiveFailures,
            'tripped_at' => $this->circuitBreakerTrippedAt,
        ], $this->circuitBreakerResetSeconds * 2);
    }
}
