<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Redis-backed event deduplication service for enterprise-grade idempotency.
 *
 * Prevents duplicate event processing by maintaining a short-lived cache of
 * recently seen event fingerprints. Uses a composite key of:
 * - Event name
 * - Client ID
 * - User ID
 * - Parameter hash (content-based dedup)
 *
 * Supports two dedup strategies:
 * - **exact**: deduplicates identical events within the window
 * - **fuzzy**: deduplicates events with the same name+identity within the window (rate limiting)
 *
 * Uses Laravel's cache driver (Redis/Database/File) for storage.
 * TTL is configurable per event category.
 *
 * @since 88.0.0
 */
final class EventDedupCacheService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_dedup_';

    /** Exact dedup strategy */
    public const STRATEGY_EXACT = 'exact';

    /** Fuzzy dedup strategy (same event name + identity within window) */
    public const STRATEGY_FUZZY = 'fuzzy';

    /** @var array<string, int> Default dedup windows per event category (seconds) */
    private const DEFAULT_WINDOWS = [
        'ecommerce' => 60,       // 1 minute for purchase/cart events
        'saas' => 30,           // 30 seconds for auth/subscription events
        'engagement' => 10,     // 10 seconds for clicks/scrolls
        'page_view' => 5,       // 5 seconds for page views
        'custom' => 5,          // 5 seconds for custom events
    ];

    private bool $enabled;

    private string $defaultStrategy;

    /** @var array<string, int> Category-specific dedup windows */
    private array $windows;

    private int $maxKeys;

    private CacheRepository $cache;

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $dedupConfig = $config->get('zeroboiler.analytics.dedup_cache', []);
        /** @var array{enabled?: bool, strategy?: string, windows?: array<string, int>, max_keys?: int} $dedupConfig */

        $this->enabled = (bool) ($dedupConfig['enabled'] ?? true);
        $this->defaultStrategy = (string) ($dedupConfig['strategy'] ?? self::STRATEGY_EXACT);
        $this->maxKeys = (int) ($dedupConfig['max_keys'] ?? 100_000);
        $this->cache = $cache;

        $customWindows = $dedupConfig['windows'] ?? [];
        $this->windows = array_merge(self::DEFAULT_WINDOWS, $customWindows);
    }

    /**
     * Check if an event is a duplicate and mark it as seen.
     *
     * @param  string  $eventName  Event name
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $category  Event category (ecommerce, saas, engagement, etc.)
     * @param  string|null  $strategy  Override dedup strategy for this event
     * @return bool  True if the event is a duplicate (should be discarded), false if it's new
     */
    public function isDuplicate(
        string $eventName,
        ?string $clientId,
        ?string $userId,
        array $params,
        ?string $category = null,
        ?string $strategy = null,
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        $cacheKey = $this->buildCacheKey($eventName, $clientId, $userId, $params, $strategy);
        $ttl = $this->getWindow($category);

        $alreadySeen = $this->cache->get($cacheKey);

        if ($alreadySeen !== null) {
            return true; // Duplicate
        }

        $this->cache->put($cacheKey, true, $ttl);

        return false;
    }

    /**
     * Mark an event as seen without checking (pre-emptive dedup registration).
     *
     * Useful for server-side events to prevent duplicate client-side tracking.
     *
     * @param  string  $eventName  Event name
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $category  Event category
     */
    public function markSeen(
        string $eventName,
        ?string $clientId,
        ?string $userId,
        array $params,
        ?string $category = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = $this->buildCacheKey($eventName, $clientId, $userId, $params);
        $ttl = $this->getWindow($category);

        $this->cache->put($cacheKey, true, $ttl);
    }

    /**
     * Build a cache key for deduplication.
     *
     * For exact strategy: includes event name + identity + parameter hash
     * For fuzzy strategy: includes only event name + identity
     *
     * @param  string  $eventName
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @param  array<string, mixed>  $params
     * @param  string|null  $strategy
     * @return string
     */
    private function buildCacheKey(
        string $eventName,
        ?string $clientId,
        ?string $userId,
        array $params,
        ?string $strategy = null,
    ): string {
        $effectiveStrategy = $strategy ?? $this->defaultStrategy;
        $identity = $userId ?? $clientId ?? 'anonymous';

        $parts = [
            self::CACHE_PREFIX,
            $effectiveStrategy,
            $eventName,
            $identity,
        ];

        if ($effectiveStrategy === self::STRATEGY_EXACT) {
            $parts[] = $this->hashParams($params);
        }

        return implode(':', $parts);
    }

    /**
     * Create a deterministic hash of event parameters.
     *
     * Sorts parameters by key and JSON-encodes for consistent hashing.
     * Excludes internal parameters (prefixed with _) and volatile fields.
     *
     * @param  array<string, mixed>  $params
     * @return string
     */
    private function hashParams(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            if (in_array($key, ['timestamp', 'session_id', 'page_url', 'referrer'], true)) {
                continue;
            }
            $clean[$key] = $value;
        }

        ksort($clean);

        return substr(md5(json_encode($clean, JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * Get the dedup window TTL for an event category.
     *
     * @param  string|null  $category  Event category
     * @return int  TTL in seconds
     */
    private function getWindow(?string $category): int
    {
        if ($category !== null && isset($this->windows[$category])) {
            return $this->windows[$category];
        }

        return $this->windows['custom'] ?? 5;
    }

    /**
     * Check if the dedup cache is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current dedup strategy.
     */
    public function getStrategy(): string
    {
        return $this->defaultStrategy;
    }

    /**
     * Get the configured dedup windows.
     *
     * @return array<string, int>
     */
    public function getWindows(): array
    {
        return $this->windows;
    }

    /**
     * Get diagnostic summary for admin commands.
     *
     * @return array{enabled: bool, strategy: string, windows: array<string, int>, max_keys: int}
     */
    public function diagnosticSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'strategy' => $this->defaultStrategy,
            'windows' => $this->windows,
            'max_keys' => $this->maxKeys,
        ];
    }
}
