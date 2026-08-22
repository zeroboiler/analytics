<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Support\Collection;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Client-side event buffer with TTL, deduplication, and capacity limits.
 *
 * Provides a server-side companion to the JS client's batch queue. Events
 * can be buffered with configurable TTL, max capacity, and dedup windows.
 * Supports flush-to-dispatch for batch processing.
 *
 * Used by trackDebounced() and trackOnce() in AnalyticsManager to provide
 * industry-standard event buffering behavior.
 *
 * @since 141.0.0
 */
final class AnalyticsEventBuffer
{
    /** @var Collection<string, AnalyticsEvent> Buffered events keyed by fingerprint */
    private Collection $buffer;

    /** @var array<string, int> Deduplication fingerprints → last flush timestamp */
    private array $fingerprints = [];

    private int $maxCapacity;

    private int $ttlSeconds;

    private int $dedupWindowSeconds;

    private int $flushCount = 0;

    /**
     * @param  int  $maxCapacity  Maximum number of events to buffer (0 = unlimited)
     * @param  int  $ttlSeconds  Default TTL for buffered events (0 = no expiry)
     * @param  int  $dedupWindowSeconds  Deduplication window in seconds
     */
    public function __construct(
        int $maxCapacity = 100,
        int $ttlSeconds = 3600,
        int $dedupWindowSeconds = 10,
    ){
        $this->buffer = new Collection;
        $this->maxCapacity = $maxCapacity;
        $this->ttlSeconds = $ttlSeconds;
        $this->dedupWindowSeconds = $dedupWindowSeconds;
    }

    /**
     * Add an event to the buffer.
     *
     * If the buffer is at capacity, the oldest event is evicted (FIFO).
     * Returns the fingerprint key for deduplication checks.
     *
     * @return string Fingerprint key for this event
     */
    public function push(AnalyticsEvent $event): string
    {
        $fingerprint = $this->fingerprint($event);

        $this->buffer->put($fingerprint, $event);

        // Evict oldest entries if over capacity
        if ($this->maxCapacity > 0 && $this->buffer->count() > $this->maxCapacity) {
            $keysToDelete = $this->buffer->keys()->take(
                $this->buffer->count() - $this->maxCapacity
            );
            foreach ($keysToDelete as $key) {
                $this->buffer->forget($key);
            }
        }

        return $fingerprint;
    }

    /**
     * Check if an event is already in the buffer (deduplication).
     *
     * Uses the same fingerprinting as push() to determine if an equivalent
     * event has been buffered within the dedup window.
     */
    public function has(AnalyticsEvent $event): bool
    {
        $fingerprint = $this->fingerprint($event);

        return $this->buffer->has($fingerprint);
    }

    /**
     * Check if an event was recently flushed (for debounce dedup).
     *
     * Returns true if the same event was flushed within the dedup window.
     * This enables trackDebounced() behavior without client-side timers.
     */
    public function wasRecentlyFlushed(AnalyticsEvent $event): bool
    {
        $fingerprint = $this->fingerprint($event);
        $lastFlush = $this->fingerprints[$fingerprint] ?? 0;

        return (time() - $lastFlush) < $this->dedupWindowSeconds;
    }

    /**
     * Flush all buffered events, returning them as a list.
     *
     * Clears the buffer and records fingerprints for dedup.
     *
     * @return list<AnalyticsEvent>
     */
    public function flush(): array
    {
        $events = $this->buffer->values()->all();
        $now = time();

        foreach ($this->buffer->keys() as $key) {
            $this->fingerprints[$key] = $now;
        }

        $this->buffer = new Collection;
        $this->flushCount++;

        $this->cleanExpiredFingerprints();

        return $events;
    }

    /**
     * Flush only events that have exceeded their TTL.
     *
     * Events without a timestamp or with timestamps within TTL are retained.
     *
     * @return list<AnalyticsEvent> Expired events that were flushed
     */
    public function flushExpired(): array
    {
        if ($this->ttlSeconds <= 0) {
            return [];
        }

        $now = time();
        $expired = [];
        $retained = new Collection;

        foreach ($this->buffer as $fingerprint => $event) {
            $eventTime = $event->timestamp?->getTimestamp() ?? $now;

            if (($now - $eventTime) > $this->ttlSeconds) {
                $expired[] = $event;
                $this->fingerprints[$fingerprint] = $now;
            } else {
                $retained->put($fingerprint, $event);
            }
        }

        $this->buffer = $retained;

        return $expired;
    }

    /**
     * Get the number of events currently in the buffer.
     */
    public function count(): int
    {
        return $this->buffer->count();
    }

    /**
     * Check if the buffer is empty.
     */
    public function isEmpty(): bool
    {
        return $this->buffer->isEmpty();
    }

    /**
     * Get the total number of flushes performed.
     */
    public function getFlushCount(): int
    {
        return $this->flushCount;
    }

    /**
     * Clear the buffer without flushing.
     */
    public function clear(): void
    {
        $this->buffer = new Collection;
        $this->fingerprints = [];
    }

    /**
     * Get buffer statistics for monitoring.
     *
     * @return array{count: int, capacity: int, utilization: float, flush_count: int, dedup_fingerprints: int, ttl: int}
     */
    public function stats(): array
    {
        return [
            'count' => $this->buffer->count(),
            'capacity' => $this->maxCapacity,
            'utilization' => $this->maxCapacity > 0
                ? round(($this->buffer->count() / $this->maxCapacity) * 100, 2)
                : 0.0,
            'flush_count' => $this->flushCount,
            'dedup_fingerprints' => count($this->fingerprints),
            'ttl' => $this->ttlSeconds,
        ];
    }

    /**
     * Generate a deterministic fingerprint for an event.
     *
     * Uses event name + clientId + userId + sorted param keys for dedup.
     */
    private function fingerprint(AnalyticsEvent $event): string
    {
        $paramKeys = array_keys($event->params);
        sort($paramKeys);

        return md5(implode('|', [
            $event->name,
            $event->clientId ?? '',
            $event->userId ?? '',
            implode(',', $paramKeys),
        ]));
    }

    /**
     * Remove expired dedup fingerprints to prevent unbounded growth.
     */
    private function cleanExpiredFingerprints(): void
    {
        $cutoff = time() - $this->dedupWindowSeconds;

        $this->fingerprints = array_filter(
            $this->fingerprints,
            fn (int $timestamp): bool => $timestamp > $cutoff,
        );
    }
}
