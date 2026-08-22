<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Deterministic event fingerprinting for idempotent dispatch and deduplication.
 *
 * Generates content-based hashes for analytics events, enabling:
 * - Exact deduplication across retries and batch re-dispatch (cache-backed
 *   check-and-mark with TTL window)
 * - Idempotency keys for API endpoints
 * - Event identity comparison without object equality
 *
 * The fingerprint is computed from the event name, normalized parameters,
 * optional client/user identity, and a configurable time-bucket window so
 * identical events within the window collapse to one hash. Computed
 * fingerprints are memoized in memory (bounded by max_cache_size); seen-state
 * for deduplication is tracked separately so computing a fingerprint never
 * marks an event as duplicate.
 *
 * With a null cache the service still fingerprints, but seen-state tracking
 * (hasSeen/markSeen/checkAndMark/isDuplicate) degrades to per-instance only.
 *
 * Configuration (`zeroboiler.analytics.fingerprint`): time_bucket ('second'|
 * 'minute'|'hour'|'day'|int seconds, 0 disables bucketing), window_seconds,
 * include_client_id, include_user_id, ignore_internal_params, exclude_params,
 * algorithm, max_cache_size, cache_prefix, ttl.
 *
 * @since 21.0.0
 */
final class EventFingerprintService
{
    private const TIME_BUCKETS = [
        'second' => 1,
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
    ];

    private bool $enabled;

    private int $windowSeconds;

    private int $timeBucketSeconds;

    private bool $includeClientId;

    private bool $includeUserId;

    private bool $ignoreInternalParams;

    private bool $excludeParams;

    private string $algorithm;

    private int $maxCacheSize;

    private string $cachePrefix;

    private int $ttl;

    private ?CacheRepository $cache;

    /** @var array<string, string> Identity → hash memoization (bounded) */
    private array $memo = [];

    /** @var array<string, true> Hashes marked as seen for deduplication */
    private array $seen = [];

    /**
     * @param  CacheRepository|array<string, mixed>|null  $cache  Cache repository, or a config array when constructed config-first
     * @param  array<string, mixed>|null  $config  Override config (ignored when the first argument is an array)
     */
    public function __construct(
        CacheRepository|array|null $cache = null,
        ?array $config = null,
    ) {
        if ($cache instanceof CacheRepository) {
            $this->cache = $cache;
            $settings = $config ?? [];
        } else {
            $this->cache = null;
            $settings = is_array($cache) ? $cache : ($config ?? []);
        }

        $this->enabled = (bool) ($settings['enabled'] ?? true);
        $this->windowSeconds = max(1, (int) ($settings['window_seconds'] ?? 10));
        $this->timeBucketSeconds = $this->resolveBucketSeconds($settings);
        $this->includeClientId = (bool) ($settings['include_client_id'] ?? true);
        $this->includeUserId = (bool) ($settings['include_user_id'] ?? true);
        $this->ignoreInternalParams = (bool) ($settings['ignore_internal_params'] ?? true);
        $this->excludeParams = (bool) ($settings['exclude_params'] ?? false);
        $this->algorithm = (string) ($settings['algorithm'] ?? 'sha256');
        if (! in_array($this->algorithm, hash_algos(), true)) {
            $this->algorithm = 'sha256';
        }
        $this->maxCacheSize = (int) ($settings['max_cache_size'] ?? $settings['max_fingerprints'] ?? 10000);
        $this->cachePrefix = (string) ($settings['cache_prefix'] ?? 'zb_fp_');
        $this->ttl = (int) ($settings['ttl'] ?? 86400);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function getMaxFingerprints(): int
    {
        return $this->maxCacheSize;
    }

    /**
     * @return array{enabled: bool, window_seconds: int, max_fingerprints: int, cache_prefix: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'window_seconds' => $this->windowSeconds,
            'max_fingerprints' => $this->maxCacheSize,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Generate a deterministic fingerprint for an analytics event.
     *
     * The fingerprint includes the event name, sorted parameter key-value
     * pairs (nulls dropped, floats rounded, internal params optionally
     * stripped, params fully excludable), optional client/user identity,
     * and the time bucket.
     */
    public function fingerprint(AnalyticsEvent $event): string
    {
        $identity = $this->identityString($event);

        if (isset($this->memo[$identity])) {
            return $this->memo[$identity];
        }

        $hash = hash($this->algorithm, $identity);

        if (count($this->memo) >= $this->maxCacheSize) {
            $this->memo = array_slice($this->memo, -(int) max(1, intdiv($this->maxCacheSize, 2)), null, true);
        }
        $this->memo[$identity] = $hash;

        return $hash;
    }

    /**
     * Generate an idempotency key suitable for API requests.
     *
     * Returns a prefixed, URL-safe key that can be sent as an
     * `Idempotency-Key` header.
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
     * Mark an event as seen; returns its fingerprint.
     */
    public function markSeen(AnalyticsEvent $event): string
    {
        $fingerprint = $this->fingerprint($event);
        $this->remember($fingerprint);

        return $fingerprint;
    }

    public function hasSeen(AnalyticsEvent $event): bool
    {
        return $this->seen($this->fingerprint($event));
    }

    /**
     * Check-and-mark an event for deduplication.
     *
     * @return array{is_duplicate: bool, fingerprint: string}
     */
    public function checkAndMark(AnalyticsEvent $event): array
    {
        $fingerprint = $this->fingerprint($event);
        $isDuplicate = $this->seen($fingerprint);
        $this->remember($fingerprint);

        return ['is_duplicate' => $isDuplicate, 'fingerprint' => $fingerprint];
    }

    public function isDuplicate(AnalyticsEvent $event): bool
    {
        return $this->seen($this->fingerprint($event));
    }

    /**
     * Stable fingerprint for a batch of events (order-insensitive).
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function batchFingerprint(array $events): string
    {
        $hashes = array_map(fn (AnalyticsEvent $event): string => $this->fingerprint($event), $events);
        sort($hashes);

        return hash($this->algorithm, 'batch:' . implode(';', $hashes));
    }

    /**
     * @param  list<AnalyticsEvent>  $events
     */
    public function hasSeenBatch(array $events): bool
    {
        return $this->seen($this->batchFingerprint($events));
    }

    /**
     * @param  list<AnalyticsEvent>  $events
     */
    public function markBatchSeen(array $events): string
    {
        $fingerprint = $this->batchFingerprint($events);
        $this->remember($fingerprint);

        return $fingerprint;
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
        return hash($this->algorithm, $eventName . '|' . $this->canonicalParams($params));
    }

    /**
     * Clear the in-memory memoization and seen-state.
     */
    public function clearCache(): void
    {
        $this->memo = [];
        $this->seen = [];
    }

    public function cacheSize(): int
    {
        return count($this->memo);
    }

    /**
     * @return array{ttl: int, time_bucket_seconds: int, exclude_timestamp: bool, exclude_params: bool, cache_prefix: string}
     */
    public function stats(): array
    {
        return [
            'ttl' => $this->ttl,
            'time_bucket_seconds' => $this->timeBucketSeconds,
            'exclude_timestamp' => $this->timeBucketSeconds === 0,
            'exclude_params' => $this->excludeParams,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    private function identityString(AnalyticsEvent $event): string
    {
        $parts = [$event->name];

        if (! $this->excludeParams) {
            $parts[] = $this->canonicalParams($event->params);
        }

        if ($this->includeClientId && $event->clientId !== null) {
            $parts[] = 'cid:' . $event->clientId;
        }

        if ($this->includeUserId && $event->userId !== null) {
            $parts[] = 'uid:' . $event->userId;
        }

        if ($this->timeBucketSeconds > 0) {
            $timestamp = $event->timestamp ?? new \DateTimeImmutable;
            $parts[] = 'tb:' . intdiv($timestamp->getTimestamp(), $this->timeBucketSeconds);
        }

        return implode('|', $parts);
    }

    /**
     * Normalize params into a stable canonical string: nulls dropped, floats
     * rounded to 6 decimals, arrays recursively key-sorted.
     *
     * @param  array<string, mixed>  $params
     */
    private function canonicalParams(array $params): string
    {
        if ($this->ignoreInternalParams) {
            $params = array_filter(
                $params,
                fn (string $key): bool => ! str_starts_with($key, '_'),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return json_encode(
            $this->normalize($params),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalize(array $params): array
    {
        ksort($params);

        $result = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[$key] = is_float($value)
                ? round($value, 6)
                : (is_array($value) ? $this->normalize($value) : $value);
        }

        return $result;
    }

    private function seen(string $fingerprint): bool
    {
        if (isset($this->seen[$fingerprint])) {
            return true;
        }

        if ($this->cache !== null) {
            return (bool) $this->cache->get($this->cachePrefix . $fingerprint, false);
        }

        return false;
    }

    private function remember(string $fingerprint): void
    {
        $this->seen[$fingerprint] = true;

        $this->cache?->put($this->cachePrefix . $fingerprint, true, $this->ttl);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveBucketSeconds(array $settings): int
    {
        if (array_key_exists('time_bucket_seconds', $settings)) {
            return (int) $settings['time_bucket_seconds'];
        }

        $bucket = $settings['time_bucket'] ?? 'minute';

        return is_int($bucket) ? $bucket : (self::TIME_BUCKETS[$bucket] ?? self::TIME_BUCKETS['minute']);
    }
}
