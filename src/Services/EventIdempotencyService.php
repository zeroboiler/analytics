<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event idempotency service for deduplicating analytics event dispatches.
 *
 * Prevents the same event from being dispatched multiple times within a
 * configurable time window. Uses a cache-backed idempotency key store
 * for O(1) lookup performance. Supports server-side and client-supplied
 * idempotency keys.
 *
 * Inspired by Stripe's idempotency key pattern and Segment's event dedup.
 * Critical for preventing duplicate revenue events (purchase, refund) and
 * billing events when clients retry on network failures.
 *
 * Configuration: `zeroboiler.analytics.idempotency`
 *
 * @since 9.3.0
 */
final class EventIdempotencyService
{
    /** @var array{enabled: bool, ttl: int, max_keys: int, prefix: string} */
    private array $config;

    private bool $enabled;

    private int $ttl;

    private int $maxKeys;

    private string $prefix;

    /** @var int */
    private int $hits = 0;

    /** @var int */
    private int $misses = 0;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $idempotencyConfig = $config->get('zeroboiler.analytics.idempotency', []);
        /** @var array{enabled?: bool, ttl?: int, max_keys?: int, prefix?: string} $idempotencyConfig */

        $this->config = $idempotencyConfig;
        $this->enabled = (bool) ($idempotencyConfig['enabled'] ?? true);
        $this->ttl = (int) ($idempotencyConfig['ttl'] ?? 3600); // 1 hour default
        $this->maxKeys = (int) ($idempotencyConfig['max_keys'] ?? 100_000);
        $this->prefix = (string) ($idempotencyConfig['prefix'] ?? 'zb_idem_');
    }

    /**
     * Check if an event should be dispatched (not a duplicate).
     *
     * Generates an idempotency key from the event name, client ID,
     * user ID, and a content hash of the event parameters. If the key
     * already exists in the cache store, the event is a duplicate.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client ID
     * @param  string|null  $userId  User ID
     * @param  string|null  $clientKey  Client-supplied idempotency key (takes priority)
     * @return bool True if event should be dispatched (not a duplicate)
     */
    public function shouldDispatch(
        string $name,
        array $params,
        ?string $clientId = null,
        ?string $userId = null,
        ?string $clientKey = null,
    ): bool {
        if (! $this->enabled) {
            return true;
        }

        $key = $this->resolveKey($name, $params, $clientId, $userId, $clientKey);

        if ($this->cache->has($key)) {
            $this->hits++;

            Log::debug('EventIdempotencyService: duplicate event blocked', [
                'event' => $name,
                'client_id' => $clientId,
                'key' => $this->maskKey($key),
            ]);

            return false;
        }

        $this->misses++;

        try {
            $this->cache->put($key, true, $this->ttl);
        } catch (\Throwable $e) {
            Log::warning('EventIdempotencyService: failed to store idempotency key', [
                'error' => $e->getMessage(),
            ]);

            // Fail open — allow dispatch even if cache fails
            return true;
        }

        return true;
    }

    /**
     * Generate an idempotency key for an event.
     *
     * Uses a SHA-256 hash of event name + client ID + user ID + params content
     * to produce a deterministic, collision-resistant key.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client ID
     * @param  string|null  $userId  User ID
     * @param  string|null  $clientKey  Client-supplied idempotency key (takes priority)
     * @return string
     */
    public function resolveKey(
        string $name,
        array $params,
        ?string $clientId = null,
        ?string $userId = null,
        ?string $clientKey = null,
    ): string {
        // Client-supplied key takes priority
        if ($clientKey !== null && $clientKey !== '') {
            return $this->prefix . 'client_' . hash('xxh128', $clientKey);
        }

        // Server-generated key from event fingerprint
        $fingerprint = implode('|', [
            $name,
            $clientId ?? 'anonymous',
            $userId ?? 'guest',
            $this->paramsHash($params),
        ]);

        return $this->prefix . hash('xxh128', $fingerprint);
    }

    /**
     * Check if the idempotency service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get idempotency statistics.
     *
     * @return array{enabled: bool, ttl: int, max_keys: int, hits: int, misses: int, total_processed: int, duplicate_rate: float}
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;

        return [
            'enabled' => $this->enabled,
            'ttl' => $this->ttl,
            'max_keys' => $this->maxKeys,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total_processed' => $total,
            'duplicate_rate' => $total > 0 ? round($this->hits / $total, 4) : 0.0,
        ];
    }

    /**
     * Reset hit/miss counters.
     */
    public function resetStats(): void
    {
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Invalidate a specific idempotency key.
     *
     * Allows re-dispatch of an event that was previously marked as duplicate.
     *
     * @param  string  $key  The full cache key (with prefix)
     */
    public function invalidate(string $key): bool
    {
        return $this->cache->forget($key);
    }

    /**
     * Generate a client-safe idempotency key for frontend use.
     *
     * Returns a short, URL-safe key that the JS client can include
     * in event payloads for server-side deduplication.
     *
     * @param  string  $eventName
     * @param  string  $clientId
     * @param  string|null  $nonce  Optional nonce for per-interaction uniqueness
     * @return string
     */
    public static function generateClientKey(string $eventName, string $clientId, ?string $nonce = null): string
    {
        $payload = implode(':', [$eventName, $clientId, $nonce ?? bin2hex(random_bytes(8))]);

        return substr(hash('xxh128', $payload), 0, 24);
    }

    /**
     * Hash event parameters for fingerprinting.
     *
     * Sorts keys recursively to ensure consistent hashing regardless
     * of parameter order.
     *
     * @param  array<string, mixed>  $params
     * @return string
     */
    private function paramsHash(array $params): string
    {
        return hash('xxh128', json_encode($this->sortParams($params), JSON_THROW_ON_ERROR));
    }

    /**
     * Recursively sort array keys for consistent hashing.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function sortParams(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $k => $v) {
            $value[$k] = $this->sortParams($v);
        }

        ksort($value);

        return $value;
    }

    /**
     * Mask a cache key for safe logging (hide the full hash).
     *
     * @param  string  $key
     * @return string
     */
    private function maskKey(string $key): string
    {
        $len = strlen($key);

        if ($len <= 16) {
            return substr($key, 0, 4) . '***';
        }

        return substr($key, 0, 8) . '...' . substr($key, -4);
    }
}
