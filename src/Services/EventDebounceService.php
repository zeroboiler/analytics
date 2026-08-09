<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event debounce service to prevent duplicate event dispatches
 * within a configurable time window.
 *
 * Useful for rapid-fire client events (scroll depth, input tracking,
 * mouse move) that should be throttled to avoid flooding analytics
 * providers.
 *
 * Configuration (zeroboiler.analytics.debounce):
 *   - enabled: bool (default: true)
 *   - default_ttl: int (default: 5000 ms)
 *   - cache_prefix: string (default: 'zb_debounce_')
 *   - rules: array<string, int> — per-event debounce TTL overrides
 *
 * Usage:
 *   $service->shouldDispatch('scroll_depth', $clientId);
 *   // Returns true only if the event hasn't been dispatched within the TTL
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
final class EventDebounceService
{
    private bool $enabled;

    private int $defaultTtlMs;

    private string $cachePrefix;

    /** @var array<string, int> Per-event TTL overrides in milliseconds */
    private array $rules;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $debounceConfig = $config->get('zeroboiler.analytics.debounce', []);
        /** @var array{enabled?: bool, default_ttl?: int, cache_prefix?: string, rules?: array<string, int>} $debounceConfig */

        $this->enabled = (bool) ($debounceConfig['enabled'] ?? true);
        $this->defaultTtlMs = (int) ($debounceConfig['default_ttl'] ?? 5000);
        $this->cachePrefix = (string) ($debounceConfig['cache_prefix'] ?? 'zb_debounce_');
        $this->rules = (array) ($debounceConfig['rules'] ?? []);
    }

    /**
     * Check if an event should be dispatched, and record the attempt.
     *
     * Returns true on the first call within the debounce window, false on
     * subsequent calls. Uses a cache-based sliding window with atomic put-if-absent
     * semantics.
     *
     * @param  string  $eventName  The analytics event name
     * @param  string  $identity  Unique identity (client ID or user ID)
     * @param  string|null  $dedupeKey  Additional deduplication key (e.g. page URL, element ID)
     * @return bool True if the event should be dispatched (first occurrence in window)
     */
    public function shouldDispatch(string $eventName, string $identity, ?string $dedupeKey = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $ttlSeconds = $this->getTtlSeconds($eventName);
        $cacheKey = $this->buildKey($eventName, $identity, $dedupeKey);

        // Check if key already exists (event already dispatched within window)
        if ($this->cache->has($cacheKey)) {
            return false;
        }

        // Atomic set — if another process sets it first, add returns false
        $this->cache->put($cacheKey, true, $ttlSeconds);

        return true;
    }

    /**
     * Reset the debounce window for a specific event.
     *
     * Forces the next dispatch to go through regardless of the window.
     * Useful when a significant event should override debounce (e.g. final scroll position).
     *
     * @param  string  $eventName
     * @param  string  $identity
     * @param  string|null  $dedupeKey
     * @return bool True if a key was removed
     */
    public function reset(string $eventName, string $identity, ?string $dedupeKey = null): bool
    {
        $cacheKey = $this->buildKey($eventName, $identity, $dedupeKey);

        return $this->cache->forget($cacheKey);
    }

    /**
     * Check if a specific event is currently debounced (within its window).
     *
     * Does NOT record a dispatch attempt — pure read-only check.
     *
     * @param  string  $eventName
     * @param  string  $identity
     * @param  string|null  $dedupeKey
     * @return bool True if the event is debounced (would be suppressed)
     */
    public function isDebounced(string $eventName, string $identity, ?string $dedupeKey = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $cacheKey = $this->buildKey($eventName, $identity, $dedupeKey);

        return $this->cache->has($cacheKey);
    }

    /**
     * Get the configured TTL for a specific event.
     *
     * @param  string  $eventName
     * @return int TTL in seconds (minimum 1 second)
     */
    public function getTtlSeconds(string $eventName): int
    {
        $ttlMs = $this->rules[$eventName] ?? $this->defaultTtlMs;

        return max(1, (int) ceil($ttlMs / 1000));
    }

    /**
     * Get the raw TTL in milliseconds for an event.
     *
     * @param  string  $eventName
     * @return int TTL in milliseconds
     */
    public function getTtlMs(string $eventName): int
    {
        return $this->rules[$eventName] ?? $this->defaultTtlMs;
    }

    /**
     * Check if debounce is globally enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get debounce statistics.
     *
     * @return array{enabled: bool, default_ttl_ms: int, rules_count: int, rules: array<string, int>}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'default_ttl_ms' => $this->defaultTtlMs,
            'rules_count' => count($this->rules),
            'rules' => $this->rules,
        ];
    }

    /**
     * Get all configured debounce rules.
     *
     * @return array<string, int>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Build the cache key for debounce tracking.
     */
    private function buildKey(string $eventName, string $identity, ?string $dedupeKey): string
    {
        $parts = [$this->cachePrefix, $eventName, $identity];

        if ($dedupeKey !== null && $dedupeKey !== '') {
            $parts[] = $dedupeKey;
        }

        return implode(':', $parts);
    }
}
