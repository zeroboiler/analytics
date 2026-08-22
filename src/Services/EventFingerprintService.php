<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Deterministic event fingerprinting for idempotent dispatch.
 *
 * Generates content-based hashes for analytics events, enabling:
 * - Exact deduplication across retries and batch re-dispatch
 * - Idempotency keys for API endpoints
 * - Event identity comparison without object equality
 * - Cache key generation for event-level operations
 *
 * The fingerprint is computed from the event name, sorted parameter keys/values,
 * client ID, user ID, and a configurable time window bucket.
 *
 * Configuration is read from `zeroboiler.analytics.fingerprinting`.
 *
 * @since 21.0.0
 */
final class EventFingerprintService
{
    /** @var int Time window in seconds for bucketing (0 = no bucketing) */
    private int $timeBucketSeconds;

    /** @var bool Whether to include client_id in the fingerprint */
    private bool $includeClientId;

    /** @var bool Whether to include user_id in the fingerprint */
    private bool $includeUserId;

    /** @var bool Whether to ignore internal params (prefixed with _) */
    private bool $ignoreInternalParams;

    /** @var string Hash algorithm */
    private string $algorithm;

    /** @var array<string, string> In-memory fingerprint cache (key → hash) */
    private array $cache = [];

    /** @var int Maximum cache size */
    private int $maxCacheSize;

    /**
     * @param  array<string, mixed>  $config  zeroboiler.analytics.fingerprinting
     */
    public function __construct(array $config){
        $this->timeBucketSeconds = (int) ($config['time_bucket_seconds'] ?? 60);
        $this->includeClientId = (bool) ($config['include_client_id'] ?? true);
        $this->includeUserId = (bool) ($config['include_user_id'] ?? true);
        $this->ignoreInternalParams = (bool) ($config['ignore_internal_params'] ?? true);
        $this->algorithm = (string) ($config['algorithm'] ?? 'xxh128');
        $this->maxCacheSize = (int) ($config['max_cache_size'] ?? 1000);
    }

    /**
     * Generate a deterministic fingerprint for an analytics event.
     *
     * The fingerprint includes:
     * - Event name
     * - Sorted parameter key-value pairs (excluding internal params if configured)
     * - Client ID (if configured)
     * - User ID (if configured)
     * - Time bucket (if configured > 0)
     */
    public function fingerprint(AnalyticsEvent $event): string
    {
        $cacheKey = $this->cacheKey($event);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $parts = [$event->name];

        $params = $event->params;
        if ($this->ignoreInternalParams) {
            $params = array_filter(
                $params,
                fn (string $key): bool => ! str_starts_with($key, '_'),
                ARRAY_FILTER_USE_KEY,
            );
        }

        ksort($params);
        $parts[] = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->includeClientId && $event->clientId !== null) {
            $parts[] = 'cid:' . $event->clientId;
        }

        if ($this->includeUserId && $event->userId !== null) {
            $parts[] = 'uid:' . $event->userId;
        }

        if ($this->timeBucketSeconds > 0) {
            $timestamp = $event->timestamp ?? new \DateTimeImmutable();
            $bucket = (int) ($timestamp->getTimestamp() / $this->timeBucketSeconds);
            $parts[] = 'tb:' . $bucket;
        }

        $fingerprint = hash($this->algorithm, implode('|', $parts));

        // Cache management
        if (count($this->cache) >= $this->maxCacheSize) {
            $this->cache = array_slice($this->cache, -($this->maxCacheSize / 2), null, true);
        }
        $this->cache[$cacheKey] = $fingerprint;

        return $fingerprint;
    }

    /**
     * Generate an idempotency key suitable for API requests.
     *
     * Returns a prefixed, URL-safe key that can be sent as
     * an `Idempotency-Key` header.
     */
    public function idempotencyKey(AnalyticsEvent $event): string
    {
        return 'zb_idem_' . $this->fingerprint($event);
    }

    /**
     * Check if two events have the same fingerprint.
     */
    public function isSameEvent(AnalyticsEvent $a, AnalyticsEvent $b): bool
    {
        return $this->fingerprint($a) === $this->fingerprint($b);
    }

    /**
     * Generate a fingerprint for a subset of parameters.
     *
     * Useful for partial deduplication (e.g., dedup by event name + userId only).
     *
     * @param  array<string, mixed>  $params
     */
    public function partialFingerprint(string $eventName, array $params): string
    {
        ksort($params);

        return hash($this->algorithm, $eventName . '|' . json_encode($params, JSON_THROW_ON_ERROR));
    }

    /**
     * Clear the fingerprint cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Get the current cache size.
     */
    public function cacheSize(): int
    {
        return count($this->cache);
    }

    /**
     * Generate a cache key for the fingerprint lookup.
     */
    private function cacheKey(AnalyticsEvent $event): string
    {
        return $event->name . ':' . ($event->clientId ?? '') . ':' . ($event->userId ?? '');
    }
}
