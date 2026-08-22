<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Pipeline middleware that deduplicates events using content hashing.
 *
 * Computes a deterministic SHA-256 hash from the event name + sorted parameters
 * and checks against an in-memory seen-set for the current request lifecycle.
 * Events with the same content hash within a single request are silently dropped,
 * preventing duplicate dispatch to providers when the same event is tracked
 * multiple times in one HTTP cycle (e.g., eager Inertia re-renders).
 *
 * Optionally uses the cache driver for cross-request deduplication with
 * configurable TTL, useful for API retry scenarios and batch processing.
 *
 * Inspired by Segment's messageId dedup, RudderStack's event dedup,
 * and the idempotency pattern from Stripe API.
 *
 * @since 25.0.0
 */
final class EventHashDedupFilter
{
    /** @var array<string, true> In-memory seen hashes for current request lifecycle */
    private array $seenHashes = [];

    /** @var positive-int */
    private int $maxMemoryEntries;

    private bool $crossRequestDedup;

    /** @var positive-int */
    private int $crossRequestTtl;

    private string $cachePrefix;

    /**
     * @param  array{max_memory_entries?: int, cross_request_dedup?: bool, cross_request_ttl?: int, cache_prefix?: string}  $config
     */
    public function __construct(array $config = []){
        $this->maxMemoryEntries = $config['max_memory_entries'] ?? 1000;
        $this->crossRequestDedup = $config['cross_request_dedup'] ?? false;
        $this->crossRequestTtl = $config['cross_request_ttl'] ?? 60;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_event_dedup_';
    }

    /**
     * Filter an event through the deduplication pipeline stage.
     *
     * Returns null if the event is a duplicate (same name + params within TTL),
     * or the original event if it passes deduplication.
     *
     * @return AnalyticsEvent|null The event if unique, null if duplicate
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $hash = $this->computeHash($event);

        // Check in-memory seen-set (per-request dedup)
        if (isset($this->seenHashes[$hash])) {
            return null;
        }

        // Check cross-request cache (if enabled)
        if ($this->crossRequestDedup) {
            $cacheKey = $this->cachePrefix . $hash;

            try {
                $cache = app(\Illuminate\Contracts\Cache\Repository::class);

                if ($cache->has($cacheKey)) {
                    return null;
                }

                $cache->put($cacheKey, true, $this->crossRequestTtl);
            } catch (\Throwable $e) {
                // Cache unavailable — skip cross-request check, allow event through
            }
        }

        // Evict oldest entries if at capacity (FIFO eviction)
        if (count($this->seenHashes) >= $this->maxMemoryEntries) {
            $this->seenHashes = array_slice($this->seenHashes, -($this->maxMemoryEntries / 2), null, true);
        }

        $this->seenHashes[$hash] = true;

        return $event;
    }

    /**
     * Compute a deterministic content hash for the event.
     *
     * Uses SHA-256 of the event name + JSON-encoded sorted parameters.
     * The sorting ensures that {a:1, b:2} and {b:2, a:1} produce the same hash.
     */
    public function computeHash(AnalyticsEvent $event): string
    {
        $params = $event->params;

        ksort($params);

        return hash('sha256', $event->name . ':' . json_encode($params, JSON_THROW_ON_ERROR));
    }

    /**
     * Check if a specific hash has been seen in the current request.
     */
    public function hasSeenHash(string $hash): bool
    {
        return isset($this->seenHashes[$hash]);
    }

    /**
     * Get the count of unique events seen in the current request.
     */
    public function seenCount(): int
    {
        return count($this->seenHashes);
    }

    /**
     * Reset the in-memory dedup state (useful between test scenarios).
     */
    public function reset(): void
    {
        $this->seenHashes = [];
    }

    /**
     * Get the dedup statistics for the current request.
     *
     * @return array{seen_count: int, max_entries: int, cross_request_enabled: bool, cross_request_ttl: int}
     */
    public function stats(): array
    {
        return [
            'seen_count' => count($this->seenHashes),
            'max_entries' => $this->maxMemoryEntries,
            'cross_request_enabled' => $this->crossRequestDedup,
            'cross_request_ttl' => $this->crossRequestTtl,
        ];
    }
}
