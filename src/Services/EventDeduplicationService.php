<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Cache-based event deduplication service.
 *
 * Prevents the same event from being dispatched multiple times within a
 * configurable time window. Uses a sliding window of recent event fingerprints
 * stored in the cache to detect duplicates.
 *
 * Configuration:
 *   zeroboiler.analytics.validation.deduplication_window (default: 10 seconds)
 *   zeroboiler.analytics.validation.max_recent_events (default: 500)
 *
 * The fingerprint is computed from the event name, client ID, user ID, and
 * a hash of the params. This ensures that truly identical events are
 * deduplicated while allowing events with different params through.
 *
 * @since 1.0.0
 */
final class EventDeduplicationService
{
    private const CACHE_PREFIX = 'zb_analytics_dedup:';
    private const RECENT_CACHE_PREFIX = 'zb_analytics_recent:';
    private const RECENT_LOCK_PREFIX = 'zb_analytics_recent_lock:';

    private int $deduplicationWindow;

    private int $maxRecentEvents;

    private bool $enabled;

    /** @var CacheRepository|null */
    private ?CacheRepository $cache;

    /**
     * @param  ConfigRepository|null  $config  Optional config for testing
     * @param  CacheRepository|null  $cache  Optional cache for testing
     */
    public function __construct(?ConfigRepository $config = null, ?CacheRepository $cache = null){
        if ($config !== null) {
            $validationConfig = $config->get('zeroboiler.analytics.validation', []);
            /** @var array{deduplication_window?: int, max_recent_events?: int} $validationConfig */
            $this->deduplicationWindow = (int) ($validationConfig['deduplication_window'] ?? 10);
            $this->maxRecentEvents = (int) ($validationConfig['max_recent_events'] ?? 500);

            $dedupEnabled = $config->get('zeroboiler.analytics.dedup.enabled', true);
            $this->enabled = (bool) $dedupEnabled;
        } else {
            $this->deduplicationWindow = 10;
            $this->maxRecentEvents = 500;
            $this->enabled = true;
        }

        $this->cache = $cache;
    }

    /**
     * Check if an event is a duplicate and should be skipped.
     *
     * @param  string  $eventName  Event name
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool True if the event is a duplicate (should be skipped)
     */
    public function isDuplicate(string $eventName, ?string $clientId = null, ?string $userId = null, array $params = []): bool
    {
        if (! $this->enabled || $this->cache === null) {
            return false;
        }

        $fingerprint = $this->computeFingerprint($eventName, $clientId, $userId, $params);
        $cacheKey = self::CACHE_PREFIX . $fingerprint;

        // Check if fingerprint exists in cache (still within dedup window)
        if ($this->cache->has($cacheKey)) {
            return true;
        }

        // Store fingerprint in cache for dedup window
        $this->cache->put($cacheKey, true, $this->deduplicationWindow);

        // Track in recent events list (sliding window for cleanup)
        $this->addToRecentEvents($fingerprint);

        return false;
    }

    /**
     * Compute a deterministic fingerprint for an event.
     *
     * Combines event name, client ID, user ID, and a hash of the params
     * to create a unique but reproducible identifier.
     *
     * @param  string  $eventName
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @param  array<string, mixed>  $params
     * @return string 64-character hex fingerprint
     */
    public function computeFingerprint(string $eventName, ?string $clientId, ?string $userId, array $params): string
    {
        // Sort params by key for deterministic hashing
        ksort($params);

        $data = implode('|', [
            $eventName,
            $clientId ?? '',
            $userId ?? '',
            md5(json_encode($params, JSON_THROW_ON_ERROR)),
        ]);

        return hash('sha256', $data);
    }

    /**
     * Add a fingerprint to the recent events sliding window.
     *
     * Maintains a bounded list of recent fingerprints in the cache,
     * preventing unbounded cache growth over time.
     */
    private function addToRecentEvents(string $fingerprint): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $recentKey = self::RECENT_CACHE_PREFIX . 'list';
            $lockKey = self::RECENT_LOCK_PREFIX . 'lock';

            // Simple atomic-like append (best effort, non-blocking)
            $recent = $this->cache->get($recentKey, []);

            if (! is_array($recent)) {
                $recent = [];
            }

            $recent[] = $fingerprint;

            // Trim to max size
            if (count($recent) > $this->maxRecentEvents) {
                $recent = array_slice($recent, -$this->maxRecentEvents);
            }

            $this->cache->put($recentKey, $recent, $this->deduplicationWindow * 2);
        } catch (\Throwable $e) {
            // Non-critical — dedup fingerprint is already stored
        }
    }

    /**
     * Get the list of recent event fingerprints.
     *
     * Useful for debugging and testing.
     *
     * @return list<string>
     */
    public function getRecentFingerprints(): array
    {
        if ($this->cache === null) {
            return [];
        }

        $recent = $this->cache->get(self::RECENT_CACHE_PREFIX . 'list', []);

        return is_array($recent) ? $recent : [];
    }

    /**
     * Get the total number of deduplicated events.
     *
     * Returns the number of fingerprints currently in the dedup cache.
     */
    public function getDeduplicationCount(): int
    {
        return count($this->getRecentFingerprints());
    }

    /**
     * Clear all deduplication data from cache.
     */
    public function clear(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $recent = $this->getRecentFingerprints();

            foreach ($recent as $fingerprint) {
                $this->cache->forget(self::CACHE_PREFIX . $fingerprint);
            }

            $this->cache->forget(self::RECENT_CACHE_PREFIX . 'list');
        } catch (\Throwable $e) {
            // Silent fail during cleanup
        }
    }

    /**
     * Check if deduplication is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the deduplication window in seconds.
     */
    public function getWindow(): int
    {
        return $this->deduplicationWindow;
    }

    /**
     * Get the maximum number of recent events tracked.
     */
    public function getMaxRecentEvents(): int
    {
        return $this->maxRecentEvents;
    }
}
