<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event fingerprinting service for deduplication and replay identification.
 *
 * Computes stable, content-addressed hashes for analytics events based on
 * their name, parameter signature, client identity, and time window.
 * Used by EventDeduplicationService and EventReplayQueue for reliable
 * event identity matching.
 *
 * Supports configurable time-granularity windowing so that identical events
 * sent within the same time bucket share a fingerprint (for dedup), while
 * events across buckets get unique fingerprints.
 *
 * Inspired by Segment's messageId, RudderStack's batchId, and Mixpanel's
 * event deduplication fingerprinting.
 *
 * @since 8.2.0
 */
final class EventFingerprintService
{
    /**
     * Default fingerprint TTL in seconds (24 hours).
     */
    private const DEFAULT_TTL = 86400;

    /**
     * Time bucket granularity constants (seconds).
     */
    private const BUCKET_SECOND = 1;
    private const BUCKET_MINUTE = 60;
    private const BUCKET_HOUR = 3600;
    private const BUCKET_DAY = 86400;

    /** @var non-empty-string */
    private string $cachePrefix;

    private int $ttl;

    private int $timeBucketSeconds;

    private bool $excludeTimestamp;

    private bool $excludeParams;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  array{cache_prefix?: string, ttl?: int, time_bucket?: string, exclude_timestamp?: bool, exclude_params?: bool}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []): void
    {
        $this->cache = $cache;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_fp_';
        $this->ttl = $config['ttl'] ?? self::DEFAULT_TTL;
        $this->excludeTimestamp = (bool) ($config['exclude_timestamp'] ?? false);
        $this->excludeParams = (bool) ($config['exclude_params'] ?? false);

        $bucket = $config['time_bucket'] ?? 'minute';
        $this->timeBucketSeconds = match ($bucket) {
            'second' => self::BUCKET_SECOND,
            'hour' => self::BUCKET_HOUR,
            'day' => self::BUCKET_DAY,
            default => self::BUCKET_MINUTE,
        };
    }

    /**
     * Compute a fingerprint hash for the given event.
     *
     * The fingerprint is a SHA-256 hash of the event name, sorted parameter keys,
     * client ID, user ID, and time bucket. Events with identical fingerprints
     * within the TTL window are considered duplicates.
     *
     * @return non-empty-string Hex-encoded SHA-256 hash (64 characters)
     */
    public function fingerprint(AnalyticsEvent $event): string
    {
        $components = [
            'name' => $event->name,
            'client_id' => $event->clientId ?? 'anonymous',
            'user_id' => $event->userId ?? 'guest',
            'bucket' => $this->timeBucket($event->timestamp),
        ];

        if (! $this->excludeParams) {
            $components['params'] = $this->paramSignature($event->params);
        }

        return hash('sha256', json_encode($components, JSON_THROW_ON_ERROR));
    }

    /**
     * Compute a fingerprint for a batch of events.
     *
     * Uses the sorted set of individual fingerprints plus the batch size
     * to produce a stable batch-level hash. Batches with the same events
     * in the same order within the same time bucket get the same fingerprint.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return non-empty-string
     */
    public function batchFingerprint(array $events): string
    {
        $fingerprints = array_map(
            fn (AnalyticsEvent $e): string => $this->fingerprint($e),
            $events,
        );

        sort($fingerprints);

        $components = [
            'count' => count($fingerprints),
            'fingerprints' => $fingerprints,
            'bucket' => $this->timeBucket(),
        ];

        return hash('sha256', json_encode($components, JSON_THROW_ON_ERROR));
    }

    /**
     * Check if an event fingerprint has been seen within the TTL window.
     *
     * Uses the cache as a bloom-filter-style seen-set. Returns true if
     * the fingerprint exists in cache (duplicate detected).
     */
    public function hasSeen(AnalyticsEvent $event): bool
    {
        $fp = $this->fingerprint($event);

        return (bool) $this->cache->get($this->cacheKey($fp));
    }

    /**
     * Mark an event fingerprint as seen.
     *
     * Stores the fingerprint in the cache with the configured TTL.
     * Returns the fingerprint hash for reference.
     *
     * @return non-empty-string
     */
    public function markSeen(AnalyticsEvent $event): string
    {
        $fp = $this->fingerprint($event);
        $this->cache->put($this->cacheKey($fp), true, $this->ttl);

        return $fp;
    }

    /**
     * Mark a batch fingerprint as seen.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return non-empty-string
     */
    public function markBatchSeen(array $events): string
    {
        $fp = $this->batchFingerprint($events);
        $this->cache->put($this->cacheKey($fp), true, $this->ttl);

        return $fp;
    }

    /**
     * Check if a batch fingerprint has been seen.
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function hasSeenBatch(array $events): bool
    {
        $fp = $this->batchFingerprint($events);

        return (bool) $this->cache->get($this->cacheKey($fp));
    }

    /**
     * Check and mark in one operation (atomic dedup check).
     *
     * If the fingerprint hasn't been seen, marks it and returns false (not a duplicate).
     * If it has been seen, returns true (duplicate).
     *
     * @return array{is_duplicate: bool, fingerprint: string}
     */
    public function checkAndMark(AnalyticsEvent $event): array
    {
        $fp = $this->fingerprint($event);
        $key = $this->cacheKey($fp);

        $seen = (bool) $this->cache->get($key);

        if (! $seen) {
            $this->cache->put($key, true, $this->ttl);
        }

        return [
            'is_duplicate' => $seen,
            'fingerprint' => $fp,
        ];
    }

    /**
     * Get the deduplication statistics.
     *
     * Returns the number of fingerprints currently tracked and the TTL.
     * Note: cache stores are opaque, so this returns config-based estimates.
     *
     * @return array{ttl: int, time_bucket_seconds: int, exclude_timestamp: bool, exclude_params: bool, cache_prefix: string}
     */
    public function stats(): array
    {
        return [
            'ttl' => $this->ttl,
            'time_bucket_seconds' => $this->timeBucketSeconds,
            'exclude_timestamp' => $this->excludeTimestamp,
            'exclude_params' => $this->excludeParams,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Compute a time bucket string for a given timestamp.
     *
     * Divides the timestamp by the bucket granularity to produce a
     * stable string that changes only at bucket boundaries.
     *
     * @param  \DateTimeImmutable|null  $timestamp
     * @return non-empty-string
     */
    private function timeBucket(?\DateTimeImmutable $timestamp = null): string
    {
        $ts = $timestamp ?? new \DateTimeImmutable();

        return (string) (int) ($ts->getTimestamp() / $this->timeBucketSeconds);
    }

    /**
     * Compute a stable parameter signature.
     *
     * Sorts parameter keys recursively and omits null values
     * to produce a canonical JSON representation for hashing.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function paramSignature(array $params): array
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->paramSignature($value);
            } else {
                $clean[$key] = $value;
            }
        }

        ksort($clean);

        return $clean;
    }

    /**
     * Build the cache key for a fingerprint.
     *
     * @param  non-empty-string  $fingerprint
     * @return non-empty-string
     */
    private function cacheKey(string $fingerprint): string
    {
        return $this->cachePrefix . $fingerprint;
    }
}
