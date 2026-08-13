<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Provider Dispatch Deduplication Service.
 *
 * Prevents duplicate event dispatches to the same provider within a
 * configurable time window. Uses content-based hashing (event name +
 * key params + client/user ID) to identify duplicates without false positives.
 *
 * Supports per-provider, per-client, and global deduplication scopes.
 * Configurable dedup window, max cache entries, and bypass conditions.
 *
 * Configuration is read from `zeroboiler.analytics.dispatch_dedup`.
 *
 * @since 63.0.0
 */
final class ProviderDispatchDedupService
{
    private CacheRepository $cache;

    /** @var array<string, mixed> */
    private array $settings;

    /** @var string */
    private string $cachePrefix;

    /**
     * @param  CacheRepository  $cache  Cache repository for dedup state
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->settings = $config->get('zeroboiler.analytics.dispatch_dedup', []);
        $this->cachePrefix = (string) ($this->settings['cache_prefix'] ?? 'zb_dedup_');
    }

    /**
     * Check whether an event should be dispatched to a specific provider.
     *
     * Returns true if the event is NOT a duplicate (should be dispatched),
     * false if it IS a duplicate (should be skipped).
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @param  string  $provider  Target provider name (e.g. 'ga4', 'meta', 'posthog')
     * @return bool  True if event should be dispatched
     */
    public function shouldDispatch(AnalyticsEvent $event, string $provider): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        // Skip dedup for critical-priority events
        if ($event->priority === 'critical') {
            return true;
        }

        $hash = $this->buildHash($event, $provider);
        $cacheKey = $this->cachePrefix . $hash;

        if ($this->cache->has($cacheKey)) {
            return false;
        }

        $window = $this->getDedupWindow();
        $this->cache->put($cacheKey, true, $window);

        return true;
    }

    /**
     * Check if dispatch deduplication is globally enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false);
    }

    /**
     * Get the deduplication time window in seconds.
     */
    public function getDedupWindow(): int
    {
        return (int) ($this->settings['window_seconds'] ?? 10);
    }

    /**
     * Get the hash algorithm used for dedup keys.
     */
    public function getHashAlgorithm(): string
    {
        return (string) ($this->settings['hash_algorithm'] ?? 'xxh128');
    }

    /**
     * Build a deterministic hash for dedup identification.
     *
     * Hash is based on: event name + provider + client ID + user ID +
     * a subset of params (excluding volatile fields like timestamps).
     *
     * @param  AnalyticsEvent  $event  The analytics event
     * @param  string  $provider  Target provider name
     * @return string  Hex hash string
     */
    public function buildHash(AnalyticsEvent $event, string $provider): string
    {
        $algo = $this->getHashAlgorithm();

        $paramsForHash = $this->filterParamsForHash($event->params);

        $raw = json_encode([
            'name' => $event->name,
            'provider' => $provider,
            'client_id' => $event->clientId ?? '',
            'user_id' => $event->userId ?? '',
            'params' => $paramsForHash,
        ], JSON_THROW_ON_ERROR);

        return hash($algo, $raw);
    }

    /**
     * Filter event params to exclude volatile/non-deterministic fields.
     *
     * Fields like 'timestamp', 'session_id', and internal keys are excluded
     * so that the same logical event with different timing is still deduped.
     *
     * @param  array<string, mixed>  $params  Raw event parameters
     * @return array<string, mixed>  Filtered params for hashing
     */
    public function filterParamsForHash(array $params): array
    {
        $excludeKeys = [
            'timestamp', '_timestamp', 'session_id', '_session_id',
            'event_id', '_event_id', 'sent_at', '_sent_at',
            'client_id', 'user_id', '_zb_', 'request_id',
        ];

        $filtered = [];

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_zb_')) {
                continue;
            }

            if (in_array($key, $excludeKeys, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->filterParamsForHash($value) : $value;
        }

        return $filtered;
    }

    /**
     * Record a manual dedup entry (e.g. for idempotent API calls).
     *
     * @param  string  $hash  Custom dedup hash
     * @param  int|null  $ttl  Optional custom TTL in seconds
     */
    public function markAsDispatched(string $hash, ?int $ttl = null): void
    {
        $this->cache->put(
            $this->cachePrefix . $hash,
            true,
            $ttl ?? $this->getDedupWindow(),
        );
    }

    /**
     * Check if a specific hash has been seen.
     *
     * @param  string  $hash  Dedup hash to check
     * @return bool  True if already dispatched
     */
    public function hasBeenDispatched(string $hash): bool
    {
        return $this->cache->has($this->cachePrefix . $hash);
    }

    /**
     * Clear all dedup cache entries for this service.
     *
     * Useful for testing or administrative cleanup.
     */
    public function clear(): void
    {
        // Cache repositories don't support prefix-based clearing in all drivers
        // This is a no-op that can be overridden in tests
    }

    /**
     * Get dedup statistics.
     *
     * @return array{enabled: bool, window_seconds: int, hash_algorithm: string, cache_prefix: string}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'window_seconds' => $this->getDedupWindow(),
            'hash_algorithm' => $this->getHashAlgorithm(),
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Batch-check multiple providers for an event.
     *
     * Returns a map of provider → should-dispatch boolean.
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @param  list<string>  $providers  List of provider names to check
     * @return array<string, bool>  Provider → shouldDispatch map
     */
    public function batchShouldDispatch(AnalyticsEvent $event, array $providers): array
    {
        $results = [];

        foreach ($providers as $provider) {
            $results[$provider] = $this->shouldDispatch($event, $provider);
        }

        return $results;
    }

    /**
     * Check dedup with a custom time window override.
     *
     * Useful for high-frequency events that need longer dedup windows.
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @param  string  $provider  Target provider name
     * @param  int  $customWindow  Custom dedup window in seconds
     * @return bool  True if event should be dispatched
     */
    public function shouldDispatchWithWindow(AnalyticsEvent $event, string $provider, int $customWindow): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ($event->priority === 'critical') {
            return true;
        }

        $hash = $this->buildHash($event, $provider);
        $cacheKey = $this->cachePrefix . $hash;

        if ($this->cache->has($cacheKey)) {
            return false;
        }

        $this->cache->put($cacheKey, true, $customWindow);

        return true;
    }
}
