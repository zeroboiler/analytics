<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Redis-backed rate limiter for analytics API endpoints.
 *
 * Protects analytics endpoints from abuse by enforcing per-client and
 * per-user rate limits. Uses Laravel's built-in RateLimiter facade with
 * Redis backend for high-performance distributed rate limiting.
 *
 * Supports three tiers:
 * - Global: Total events per minute across all clients
 * - Per-client: Events per minute per client ID
 * - Per-user: Events per minute per authenticated user
 *
 * @since 9.8.0
 */
final class AnalyticsRateLimiterService
{
    private bool $enabled;

    /** @var int Max requests per minute globally */
    private int $globalLimit;

    /** @var int Max requests per minute per client */
    private int $clientLimit;

    /** @var int Max requests per minute per user */
    private int $userLimit;

    /** @var int Max batch events per minute globally */
    private int $batchGlobalLimit;

    /** @var int Max batch events per minute per client */
    private int $batchClientLimit;

    /** @var int Max batch size (events per request) */
    private int $maxBatchSize;

    /** @var string Rate limiter key prefix */
    private string $prefix;

    /** @var int Decay time in seconds (window) */
    private int $decaySeconds;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $rateLimit = $config->get('zeroboiler.analytics.api.rate_limit', []);
        /** @var array{enabled?: bool, global_limit?: int, client_limit?: int, user_limit?: int, batch_global_limit?: int, batch_client_limit?: int, max_batch_size?: int, prefix?: string, decay_seconds?: int} $rateLimit */

        $this->enabled = (bool) ($rateLimit['enabled'] ?? true);
        $this->globalLimit = (int) ($rateLimit['global_limit'] ?? 10000);
        $this->clientLimit = (int) ($rateLimit['client_limit'] ?? 300);
        $this->userLimit = (int) ($rateLimit['user_limit'] ?? 600);
        $this->batchGlobalLimit = (int) ($rateLimit['batch_global_limit'] ?? 5000);
        $this->batchClientLimit = (int) ($rateLimit['batch_client_limit'] ?? 100);
        $this->maxBatchSize = (int) ($rateLimit['max_batch_size'] ?? 50);
        $this->prefix = (string) ($rateLimit['prefix'] ?? 'zb_analytics_');
        $this->decaySeconds = (int) ($rateLimit['decay_seconds'] ?? 60);
    }

    /**
     * Check if rate limiting is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Attempt a single event tracking request.
     *
     * Returns true if the request is within rate limits, false if rate-limited.
     * Throws nothing — returns false for all limit violations.
     *
     * @param  string|null  $clientId  Client identifier (from cookie/header)
     * @param  string|null  $userId  Authenticated user identifier
     * @return bool True if within limits
     */
    public function attemptTrack(?string $clientId = null, ?string $userId = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Global rate limit check
        if (! $this->checkGlobalLimit()) {
            return false;
        }

        // Per-client rate limit check
        if ($clientId !== null && ! $this->checkClientLimit($clientId)) {
            return false;
        }

        // Per-user rate limit check
        if ($userId !== null && ! $this->checkUserLimit($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Attempt a batch event tracking request.
     *
     * @param  int  $eventCount  Number of events in the batch
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return bool True if within limits
     */
    public function attemptBatch(int $eventCount, ?string $clientId = null, ?string $userId = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Batch size check
        if ($eventCount > $this->maxBatchSize) {
            return false;
        }

        // Global batch rate limit
        if (! $this->checkBatchGlobalLimit($eventCount)) {
            return false;
        }

        // Per-client batch rate limit
        if ($clientId !== null && ! $this->checkBatchClientLimit($clientId, $eventCount)) {
            return false;
        }

        // Per-user rate limit (reuse single event limit for batch)
        if ($userId !== null && ! $this->checkUserLimit($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Get the number of remaining attempts for a client.
     */
    public function remainingForClient(string $clientId): int
    {
        if (! $this->enabled) {
            return PHP_INT_MAX;
        }

        $key = $this->prefix . 'client_' . $clientId;
        $remaining = RateLimiter::remaining($key, $this->clientLimit);

        return max(0, $remaining);
    }

    /**
     * Get the number of remaining global attempts.
     */
    public function remainingGlobal(): int
    {
        if (! $this->enabled) {
            return PHP_INT_MAX;
        }

        $key = $this->prefix . 'global';
        $remaining = RateLimiter::remaining($key, $this->globalLimit);

        return max(0, $remaining);
    }

    /**
     * Get the number of remaining attempts for a user.
     */
    public function remainingForUser(string $userId): int
    {
        if (! $this->enabled) {
            return PHP_INT_MAX;
        }

        $key = $this->prefix . 'user_' . $userId;
        $remaining = RateLimiter::remaining($key, $this->userLimit);

        return max(0, $remaining);
    }

    /**
     * Get the maximum batch size.
     */
    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }

    /**
     * Get rate limiter statistics.
     *
     * @return array{enabled: bool, global_limit: int, client_limit: int, user_limit: int, batch_global_limit: int, batch_client_limit: int, max_batch_size: int, decay_seconds: int}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'global_limit' => $this->globalLimit,
            'client_limit' => $this->clientLimit,
            'user_limit' => $this->userLimit,
            'batch_global_limit' => $this->batchGlobalLimit,
            'batch_client_limit' => $this->batchClientLimit,
            'max_batch_size' => $this->maxBatchSize,
            'decay_seconds' => $this->decaySeconds,
        ];
    }

    /**
     * Check the global rate limit.
     */
    private function checkGlobalLimit(): bool
    {
        $key = $this->prefix . 'global';

        return RateLimiter::attempt($key, $this->globalLimit, function (): void {
            // No-op — just checking availability
        }, $this->decaySeconds);
    }

    /**
     * Check per-client rate limit.
     */
    private function checkClientLimit(string $clientId): bool
    {
        $key = $this->prefix . 'client_' . $clientId;

        return RateLimiter::attempt($key, $this->clientLimit, function (): void {
            // No-op
        }, $this->decaySeconds);
    }

    /**
     * Check per-user rate limit.
     */
    private function checkUserLimit(string $userId): bool
    {
        $key = $this->prefix . 'user_' . $userId;

        return RateLimiter::attempt($key, $this->userLimit, function (): void {
            // No-op
        }, $this->decaySeconds);
    }

    /**
     * Check global batch rate limit (counts individual events).
     */
    private function checkBatchGlobalLimit(int $eventCount): bool
    {
        $key = $this->prefix . 'batch_global';

        return RateLimiter::attempt($key, $this->batchGlobalLimit, function (): void {
            // No-op
        }, $this->decaySeconds, $eventCount);
    }

    /**
     * Check per-client batch rate limit (counts individual events).
     */
    private function checkBatchClientLimit(string $clientId, int $eventCount): bool
    {
        $key = $this->prefix . 'batch_client_' . $clientId;

        return RateLimiter::attempt($key, $this->batchClientLimit, function (): void {
            // No-op
        }, $this->decaySeconds, $eventCount);
    }
}
