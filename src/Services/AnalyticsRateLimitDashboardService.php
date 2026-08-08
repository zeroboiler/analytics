<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Analytics Rate Limiter Dashboard Service.
 *
 * Provides per-client, per-event-type rate limiting with a dashboard API
 * for monitoring and managing rate limits across all analytics endpoints.
 *
 * Uses a sliding window counter pattern with configurable limits per
 * client ID and event type. Stores counters in the Laravel cache driver.
 *
 * Suitable for SaaS deployments that need to control analytics volume
 * per customer or per API consumer.
 */
final class AnalyticsRateLimitDashboardService
{
    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private int $defaultLimit;

    private int $windowSeconds;

    private int $cacheTtl;

    private string $cachePrefix;

    private bool $enabled;

    /** @var array<string, int> */
    private array $perEventLimits;

    /** @var array<string, int> */
    private array $perClientOverrides;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     * @param  int  $defaultLimit  Default events per window per client (default 100)
     * @param  int  $windowSeconds  Sliding window size in seconds (default 60)
     * @param  int  $cacheTtl  Counter TTL in seconds (default 120)
     * @param  string  $cachePrefix  Cache key prefix
     * @param  bool  $enabled  Whether rate limiting is active
     * @param  array<string, int>  $perEventLimits  Per-event-type overrides
     * @param  array<string, int>  $perClientOverrides  Per-client overrides
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
        int $defaultLimit = 100,
        int $windowSeconds = 60,
        int $cacheTtl = 120,
        string $cachePrefix = 'zb_rl_',
        bool $enabled = true,
        array $perEventLimits = [],
        array $perClientOverrides = [],
    ) {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->defaultLimit = $defaultLimit;
        $this->windowSeconds = $windowSeconds;
        $this->cacheTtl = $cacheTtl;
        $this->cachePrefix = $cachePrefix;
        $this->enabled = $enabled;
        $this->perEventLimits = $perEventLimits;
        $this->perClientOverrides = $perClientOverrides;
    }

    /**
     * Check if a client is allowed to dispatch an event.
     *
     * @param  string|null  $clientId  Client tracking ID
     * @param  string  $eventName  Event name
     * @return array{allowed: bool, remaining: int, limit: int, reset_at: int|null, reason: string|null}
     */
    public function check(?string $clientId, string $eventName): array
    {
        if (! $this->enabled) {
            return [
                'allowed' => true,
                'remaining' => PHP_INT_MAX,
                'limit' => 0,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        $identifier = $clientId ?? 'anonymous';
        $limit = $this->getEffectiveLimit($identifier, $eventName);
        $cacheKey = $this->buildCacheKey($identifier, $eventName);

        /** @var array{count: int, window_start: int}|null $counter */
        $counter = $this->cache->get($cacheKey);
        $now = time();

        if ($counter === null || ($now - $counter['window_start']) >= $this->windowSeconds) {
            // New window
            $newCounter = [
                'count' => 1,
                'window_start' => $now,
            ];
            $this->cache->put($cacheKey, $newCounter, $this->cacheTtl);

            return [
                'allowed' => true,
                'remaining' => $limit - 1,
                'limit' => $limit,
                'reset_at' => $now + $this->windowSeconds,
                'reason' => null,
            ];
        }

        $count = $counter['count'];
        $windowEnd = $counter['window_start'] + $this->windowSeconds;

        if ($count >= $limit) {
            $this->metrics->increment('rate_limited');

            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'reset_at' => $windowEnd,
                'reason' => "Rate limit exceeded for event '{$eventName}' ({$count}/{$limit} in {$this->windowSeconds}s)",
            ];
        }

        // Increment counter
        $counter['count'] = $count + 1;
        $remaining = $this->cacheTtl - ($now - $counter['window_start']);
        $this->cache->put($cacheKey, $counter, max($remaining, 1));

        return [
            'allowed' => true,
            'remaining' => $limit - $count - 1,
            'limit' => $limit,
            'reset_at' => $windowEnd,
            'reason' => null,
        ];
    }

    /**
     * Get rate limit status for a specific client.
     *
     * @param  string  $clientId  Client tracking ID
     * @return array{client_id: string, limits: array<string, array{count: int, limit: int, remaining: int, reset_at: int|null}>, global: array{count: int, limit: int, remaining: int}}
     */
    public function getClientStatus(string $clientId): array
    {
        $globalCount = 0;
        $globalLimit = $this->defaultLimit;
        $limits = [];

        // Scan for known event types in per-event limits
        $eventTypes = array_merge(array_keys($this->perEventLimits), ['page_view', 'scroll_depth']);

        foreach ($eventTypes as $eventType) {
            $cacheKey = $this->buildCacheKey($clientId, $eventType);

            /** @var array{count: int, window_start: int}|null $counter */
            $counter = $this->cache->get($cacheKey);

            $count = $counter['count'] ?? 0;
            $limit = $this->getEffectiveLimit($clientId, $eventType);
            $resetAt = $counter !== null ? $counter['window_start'] + $this->windowSeconds : null;

            $limits[$eventType] = [
                'count' => $count,
                'limit' => $limit,
                'remaining' => max($limit - $count, 0),
                'reset_at' => $resetAt,
            ];

            $globalCount += $count;
        }

        return [
            'client_id' => $clientId,
            'limits' => $limits,
            'global' => [
                'count' => $globalCount,
                'limit' => $globalLimit,
                'remaining' => max($globalLimit - $globalCount, 0),
            ],
        ];
    }

    /**
     * Get dashboard overview of rate limiting across all clients.
     *
     * @return array{enabled: bool, default_limit: int, window_seconds: int, top_limited_clients: list<array{client_id: string, total_limited: int}>, rate_limited_total: int, per_event_limits: array<string, int>}
     */
    public function getDashboard(): array
    {
        $rateLimitedTotal = $this->metrics->get('rate_limited') ?? 0;

        return [
            'enabled' => $this->enabled,
            'default_limit' => $this->defaultLimit,
            'window_seconds' => $this->windowSeconds,
            'rate_limited_total' => $rateLimitedTotal,
            'per_event_limits' => $this->perEventLimits,
            'per_client_overrides' => $this->perClientOverrides,
        ];
    }

    /**
     * Set a per-event rate limit override.
     */
    public function setEventLimit(string $eventName, int $limit): void
    {
        $this->perEventLimits[$eventName] = $limit;
    }

    /**
     * Set a per-client rate limit override.
     */
    public function setClientLimit(string $clientId, int $limit): void
    {
        $this->perClientOverrides[$clientId] = $limit;
    }

    /**
     * Reset rate limit counters for a specific client.
     */
    public function resetClient(string $clientId): void
    {
        // Reset all known event type counters for this client
        $eventTypes = array_merge(array_keys($this->perEventLimits), ['page_view', 'scroll_depth']);

        foreach ($eventTypes as $eventType) {
            $cacheKey = $this->buildCacheKey($clientId, $eventType);
            $this->cache->forget($cacheKey);
        }
    }

    /**
     * Get the effective rate limit for a client+event combination.
     */
    private function getEffectiveLimit(string $clientId, string $eventName): int
    {
        // Per-client override takes priority
        if (isset($this->perClientOverrides[$clientId])) {
            return $this->perClientOverrides[$clientId];
        }

        // Per-event override
        if (isset($this->perEventLimits[$eventName])) {
            return $this->perEventLimits[$eventName];
        }

        return $this->defaultLimit;
    }

    /**
     * Build a cache key for a client+event counter.
     */
    private function buildCacheKey(string $clientId, string $eventName): string
    {
        return $this->cachePrefix . md5("{$clientId}:{$eventName}");
    }
}
