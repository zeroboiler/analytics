<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * Server-side idempotency key management for analytics events.
 *
 * Prevents duplicate event processing when clients retry requests
 * due to network failures, connection resets, or double-clicks.
 *
 * Uses a time-windowed cache to store processed event fingerprints.
 * Events with the same idempotency key within the TTL window
 * are silently deduplicated.
 *
 * Supports three deduplication strategies:
 * - `client_key`: Use the client-provided idempotency key (recommended)
 * - `fingerprint`: Auto-generate fingerprint from event name + params hash
 * - `hybrid`: Check both client key and fingerprint (most aggressive)
 *
 * Inspired by Stripe's Idempotency-Key header and Segment's message dedup.
 *
 * @since 23.0.0
 */
final class EventIdempotencyKeyService
{
    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    private string $strategy;

    private string $cachePrefix;

    /** @var array<string, bool> In-memory processed keys for current request */
    private array $requestCache = [];

    /**
     * @param  CacheRepository  $cache  Cache repository for idempotency storage
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $idempotencyConfig = $config->get('zeroboiler.analytics.idempotency', []);
        /** @var array{enabled?: bool, ttl?: int, strategy?: string, cache_prefix?: string} $idempotencyConfig */
        $this->enabled = (bool) ($idempotencyConfig['enabled'] ?? false);
        $this->ttl = (int) ($idempotencyConfig['ttl'] ?? 3600); // 1 hour default
        $this->strategy = (string) ($idempotencyConfig['strategy'] ?? 'client_key');
        $this->cachePrefix = (string) ($idempotencyConfig['cache_prefix'] ?? 'zb_idem_');
    }

    /**
     * Check if an event should be processed (not a duplicate).
     *
     * Returns true if the event is new and should be dispatched.
     * Returns false if the event was already processed within the TTL window.
     *
     * When idempotency is disabled, always returns true.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientKey  Client-provided idempotency key
     * @return bool True if event is new (should be processed)
     */
    public function shouldProcess(string $eventName, array $params = [], ?string $clientKey = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $keys = $this->resolveKeys($eventName, $params, $clientKey);

        foreach ($keys as $key) {
            // Check request-level cache first (fast path)
            if (isset($this->requestCache[$key])) {
                return false;
            }

            // Check persistent cache
            if ($this->cache->has($this->cachePrefix . $key)) {
                return false;
            }
        }

        // Mark all keys as processed
        foreach ($keys as $key) {
            $this->requestCache[$key] = true;
            $this->cache->put($this->cachePrefix . $key, true, $this->ttl);
        }

        return true;
    }

    /**
     * Generate a fingerprint hash for an event.
     *
     * Creates a deterministic hash from event name + sorted params
     * for fingerprint-based deduplication.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return string Fingerprint hash
     */
    public function fingerprint(string $eventName, array $params = []): string
    {
        $normalized = $this->normalizeParams($params);

        return hash('xxh128', $eventName . ':' . json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Mark an event as processed (explicit idempotency tracking).
     *
     * Use this to manually register an event as processed,
     * typically after successful dispatch.
     *
     * @param  string  $key  Idempotency key or fingerprint
     */
    public function markProcessed(string $key): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->requestCache[$key] = true;
        $this->cache->put($this->cachePrefix . $key, true, $this->ttl);
    }

    /**
     * Check if a specific key was already processed.
     *
     * @param  string  $key  Idempotency key
     * @return bool True if already processed
     */
    public function isProcessed(string $key): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return isset($this->requestCache[$key])
            || $this->cache->has($this->cachePrefix . $key);
    }

    /**
     * Remove a processed key entry (manual invalidation).
     *
     * Useful for DLQ replay scenarios where a previously failed event
     * needs to be re-processed.
     *
     * @param  string  $key  Idempotency key
     */
    public function forget(string $key): void
    {
        $this->cache->forget($this->cachePrefix . $key);
        unset($this->requestCache[$key]);
    }

    /**
     * Get the number of tracked idempotency keys in the current request.
     */
    public function requestCacheSize(): int
    {
        return count($this->requestCache);
    }

    /**
     * Check if idempotency tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured deduplication strategy.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Get the configured TTL in seconds.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Resolve the deduplication keys based on strategy.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientKey  Client-provided key
     * @return list<string> Keys to check
     */
    private function resolveKeys(string $eventName, array $params, ?string $clientKey): array
    {
        $keys = [];

        switch ($this->strategy) {
            case 'fingerprint':
                $keys[] = 'fp:' . $this->fingerprint($eventName, $params);
                break;

            case 'hybrid':
                if ($clientKey !== null && $clientKey !== '') {
                    $keys[] = 'ck:' . $this->sanitizeKey($clientKey);
                }
                $keys[] = 'fp:' . $this->fingerprint($eventName, $params);
                break;

            case 'client_key':
            default:
                if ($clientKey !== null && $clientKey !== '') {
                    $keys[] = 'ck:' . $this->sanitizeKey($clientKey);
                } else {
                    // Fallback to fingerprint if no client key provided
                    $keys[] = 'fp:' . $this->fingerprint($eventName, $params);
                }
                break;
        }

        return $keys;
    }

    /**
     * Normalize event parameters for consistent fingerprinting.
     *
     * Sorts keys recursively and converts values to strings.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return array<string, mixed> Normalized parameters
     */
    private function normalizeParams(array $params): array
    {
        ksort($params);

        $normalized = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeParams($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Sanitize a client-provided key for safe cache usage.
     *
     * Strips control characters and limits length.
     */
    private function sanitizeKey(string $key): string
    {
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $key);

        return Str::substr((string) $cleaned, 0, 255);
    }
}
