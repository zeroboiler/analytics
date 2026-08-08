<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * High-performance event cache service.
 *
 * Provides layered caching for analytics event lookups, catalog resolution,
 * and cross-provider format conversion. Uses in-memory cache as L1 with
 * optional Laravel cache store as L2 for cross-request persistence.
 *
 * Designed to eliminate repeated catalog lookups and format conversions
 * in high-throughput scenarios (batch processing, event replay, etc.).
 *
 * Cache layers:
 * - L1 (memory): In-process array cache, per-request lifetime
 * - L2 (cache store): Optional Laravel cache (file, redis, etc.) with TTL
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 */
final class EventCacheService
{
    /** @var array<string, mixed> L1 in-memory cache */
    private array $memoryCache = [];

    /** @var array<string, int> L1 memory cache timestamps */
    private array $memoryTimestamps = [];

    /** @var int Max items in L1 memory cache */
    private int $memoryMaxItems;

    /** @var int L1 memory cache TTL in seconds */
    private int $memoryTtl;

    /** @var int L2 cache TTL in seconds */
    private int $cacheTtl;

    /** @var string Cache key prefix */
    private string $prefix;

    /** @var bool Whether L2 cache is enabled */
    private bool $cacheEnabled;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $cacheConfig = $config->get('zeroboiler.analytics.event_cache', []);
        /** @var array{enabled?: bool, memory_max_items?: int, memory_ttl?: int, cache_ttl?: int, prefix?: string} $cacheConfig */

        $this->cacheEnabled = (bool) ($cacheConfig['enabled'] ?? true);
        $this->memoryMaxItems = (int) ($cacheConfig['memory_max_items'] ?? 500);
        $this->memoryTtl = (int) ($cacheConfig['memory_ttl'] ?? 300);
        $this->cacheTtl = (int) ($cacheConfig['cache_ttl'] ?? 3600);
        $this->prefix = (string) ($cacheConfig['prefix'] ?? 'zb_analytics_');
    }

    /**
     * Get a cached catalog entry by event name.
     *
     * Checks L1 memory cache first, then L2 cache store, then
     * falls back to EventCatalog::get().
     *
     * @return array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, category: string}|null
     */
    public function getEvent(string $name): ?array
    {
        $key = $this->eventKey($name);

        // L1 memory cache
        if ($this->hasInMemory($key)) {
            $this->hits++;

            return $this->memoryCache[$key];
        }

        // L2 cache store
        if ($this->cacheEnabled) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                $this->setInMemory($key, $cached);
                $this->hits++;

                return $cached;
            }
        }

        // Cold lookup
        $this->misses++;
        $entry = EventCatalog::get($name);

        if ($entry !== null) {
            $this->setInMemory($key, $entry);
            if ($this->cacheEnabled) {
                $this->cache->put($key, $entry, $this->cacheTtl);
            }
        }

        return $entry;
    }

    /**
     * Check if an event exists (with caching).
     */
    public function hasEvent(string $name): bool
    {
        return $this->getEvent($name) !== null;
    }

    /**
     * Get the category for an event name (cached).
     *
     * @return 'ecommerce'|'saas'|'engagement'|null
     */
    public function getCategory(string $name): ?string
    {
        $entry = $this->getEvent($name);

        return $entry['category'] ?? null;
    }

    /**
     * Get the event class for a name (cached).
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public function getClass(string $name): ?string
    {
        return EventCatalog::classFor($name) ?? $this->memoryCache[$this->eventKey($name)]['class'] ?? null;
    }

    /**
     * Resolve a potentially aliased event name and get its catalog entry.
     *
     * Combines EventAliasResolver resolution with cached catalog lookup.
     *
     * @return array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, category: string}|null
     */
    public function resolveAndGet(string $name, ?EventAliasResolver $resolver = null): ?array
    {
        $canonical = $resolver !== null ? $resolver->resolve($name) : $name;

        return $this->getEvent($canonical);
    }

    /**
     * Get GA4→Meta format conversion with caching.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int, value: float}
     */
    public function getGa4ToMetaConversion(array $items): array
    {
        $hash = $this->hashItems($items);
        $key = 'ecommerce_ga4_to_meta_' . $hash;

        if ($this->hasInMemory($key)) {
            $this->hits++;

            return $this->memoryCache[$key];
        }

        $this->misses++;
        $result = EcommerceFormatConverter::ga4ToMetaContents($items);
        $this->setInMemory($key, $result);

        return $result;
    }

    /**
     * Get Meta→GA4 format conversion with caching.
     *
     * @param  array{content_ids?: list<string>, contents?: array<int, array<string, mixed>>, num_items?: int}  $contents
     * @return array{items: array<int, array<string, mixed>>, total_value: float}
     */
    public function getMetaToGa4Conversion(array $contents): array
    {
        $hash = $this->hashItems($contents);
        $key = 'ecommerce_meta_to_ga4_' . $hash;

        if ($this->hasInMemory($key)) {
            $this->hits++;

            return $this->memoryCache[$key];
        }

        $this->misses++;
        $result = EcommerceFormatConverter::metaToGa4Items($contents);
        $this->setInMemory($key, $result);

        return $result;
    }

    /**
     * Get the total event count (cached).
     */
    public function totalEventCount(): int
    {
        $key = 'catalog_total_count';

        if ($this->hasInMemory($key)) {
            $this->hits++;

            return $this->memoryCache[$key];
        }

        $this->misses++;
        $count = EventCatalog::count();
        $this->setInMemory($key, $count);

        return $count;
    }

    /**
     * Get all event names (cached).
     *
     * @return list<string>
     */
    public function allEventNames(): array
    {
        $key = 'catalog_all_names';

        if ($this->hasInMemory($key)) {
            $this->hits++;

            return $this->memoryCache[$key];
        }

        $this->misses++;
        $names = EventCatalog::names();
        $this->setInMemory($key, $names);

        return $names;
    }

    /**
     * Warm up the memory cache with commonly used lookups.
     *
     * Pre-loads all catalog entries, event names, and counts into L1.
     *
     * @return int Number of entries pre-loaded
     */
    public function warmUp(): int
    {
        $count = 0;

        // Pre-load all catalog entries
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            $this->setInMemory($this->eventKey($name), $entry);
            $count++;
        }

        // Pre-load event names
        $this->setInMemory('catalog_all_names', EventCatalog::names());
        $count++;

        // Pre-load total count
        $this->setInMemory('catalog_total_count', EventCatalog::count());
        $count++;

        return $count;
    }

    /**
     * Clear all caches (L1 and L2).
     */
    public function flush(): void
    {
        $this->memoryCache = [];
        $this->memoryTimestamps = [];
        $this->hits = 0;
        $this->misses = 0;

        if ($this->cacheEnabled) {
            $this->cache->forget($this->prefix . '*');
        }
    }

    /**
     * Clear only L1 (in-memory) cache.
     */
    public function flushMemory(): void
    {
        $this->memoryCache = [];
        $this->memoryTimestamps = [];
    }

    /**
     * Get cache statistics.
     *
     * @return array{hits: int, misses: int, hit_rate: float, memory_items: int, memory_max: int, l2_enabled: bool, l2_ttl: int, version: string}
     */
    public function stats(): array
    {
        $total = $this->hits + $this->misses;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $total > 0 ? round($this->hits / $total, 4) : 0.0,
            'memory_items' => count($this->memoryCache),
            'memory_max' => $this->memoryMaxItems,
            'l2_enabled' => $this->cacheEnabled,
            'l2_ttl' => $this->cacheTtl,
            'version' => '2.90.0',
        ];
    }

    /**
     * Check if L1 is enabled and has capacity.
     */
    public function isEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Generate a cache key for an event name.
     */
    private function eventKey(string $name): string
    {
        return 'event_' . strtolower($name);
    }

    /**
     * Check if a key exists in L1 memory cache and hasn't expired.
     */
    private function hasInMemory(string $key): bool
    {
        if (! isset($this->memoryCache[$key])) {
            return false;
        }

        // Check TTL
        if (isset($this->memoryTimestamps[$key])) {
            if ((time() - $this->memoryTimestamps[$key]) > $this->memoryTtl) {
                unset($this->memoryCache[$key], $this->memoryTimestamps[$key]);

                return false;
            }
        }

        return true;
    }

    /**
     * Store a value in L1 memory cache with eviction policy.
     *
     * @param  string  $key
     * @param  mixed  $value
     */
    private function setInMemory(string $key, mixed $value): void
    {
        // Evict oldest entries if at capacity
        if (count($this->memoryCache) >= $this->memoryMaxItems && ! isset($this->memoryCache[$key])) {
            $this->evictOldest();
        }

        $this->memoryCache[$key] = $value;
        $this->memoryTimestamps[$key] = time();
    }

    /**
     * Evict the oldest entries from L1 memory cache.
     *
     * Removes the oldest 10% of entries when capacity is reached.
     */
    private function evictOldest(): void
    {
        $toEvict = max(1, (int) ($this->memoryMaxItems * 0.1));
        asort($this->memoryTimestamps);

        $evicted = 0;
        foreach (array_keys($this->memoryTimestamps) as $key) {
            if ($evicted >= $toEvict) {
                break;
            }
            unset($this->memoryCache[$key], $this->memoryTimestamps[$key]);
            $evicted++;
        }
    }

    /**
     * Generate a simple hash for items array caching.
     *
     * @param  array<string, mixed>  $items
     */
    private function hashItems(array $items): string
    {
        return substr(md5(serialize($items)), 0, 12);
    }
}
