<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Real-Time Event Stream Processor.
 *
 * Provides sliding-window aggregation over analytics events for real-time
 * dashboards and monitoring. Maintains in-memory counters organized by:
 *
 * - **Event name**: count of each event type in the current window
 * - **Category**: aggregated counts by event category
 * - **Time buckets**: events distributed into sub-second time intervals
 * - **Top events**: ranked event list by frequency
 * - **Rate tracking**: events per second (EPS) with burst detection
 *
 * The sliding window concept allows "the last N seconds" queries without
 * storing every individual event. Events are aggregated into time buckets
 * and older buckets are expired as the window slides forward.
 *
 * Use cases:
 * - **Live dashboards**: "How many signups in the last 60 seconds?"
 * - **Anomaly detection**: "Did event rate spike beyond 3σ?"
 * - **Rate limiting validation**: "Are we within our provider quota?"
 * - **Operational monitoring**: "Is the analytics pipeline healthy?"
 *
 * Configuration: `zeroboiler.analytics.realtime_stream`
 *
 * @phpstan-type TimeBucket array{timestamp: float, events: int, categories: array<string, int>, event_names: array<string, int>}
 * @phpstan-type StreamSnapshot array{window_seconds: int, total_events: int, events_per_second: float, by_category: array<string, int>, by_event: array<string, int>, top_events: list<array{name: string, count: int}>, burst_detected: bool, burst_ratio: float, oldest_bucket_age: float|null, buckets: int, computed_at: string}
 *
 * @since 186.0.0
 */
final class RealTimeEventStreamService
{
    private const CACHE_PREFIX = 'zb_rt_stream_';

    private const DEFAULT_TTL = 10; // 10 seconds (real-time data)

    private const DEFAULT_WINDOW = 60; // 60 seconds

    private const DEFAULT_BUCKET_SIZE = 5; // 5-second buckets

    private const MAX_WINDOW = 3600; // 1 hour max

    private const MIN_BUCKET_SIZE = 1; // 1 second min

    private bool $enabled;

    private int $windowSeconds;

    private int $bucketSizeSeconds;

    private float $burstThreshold;

    private int $maxTopEvents;

    private CacheRepository $cache;

    /** @var list<TimeBucket> */
    private array $buckets = [];

    /** @var array<string, int> Running totals by event name */
    private array $eventTotals = [];

    /** @var array<string, int> Running totals by category */
    private array $categoryTotals = [];

    /** @var int Grand total in current window */
    private int $grandTotal = 0;

    /** @var float Timestamp of the last ingest call */
    private float $lastIngestAt = 0.0;

    /** @var list<int> Per-second event counts for EPS calculation */
    private array $epsHistory = [];

    public function __construct(
        private readonly ConfigRepository $config,
        ?CacheRepository $cache = null,
    ): void {
        $this->cache = $cache ?? app(CacheRepository::class);

        $streamConfig = $config->get('zeroboiler.analytics.realtime_stream', []);
        /** @var array{enabled?: bool, window_seconds?: int, bucket_size?: int, burst_threshold?: float, max_top_events?: int} $streamConfig */
        $this->enabled = (bool) ($streamConfig['enabled'] ?? true);
        $this->windowSeconds = min(
            max((int) ($streamConfig['window_seconds'] ?? self::DEFAULT_WINDOW), 10),
            self::MAX_WINDOW,
        );
        $this->bucketSizeSeconds = max(
            (int) ($streamConfig['bucket_size'] ?? self::DEFAULT_BUCKET_SIZE),
            self::MIN_BUCKET_SIZE,
        );
        $this->burstThreshold = (float) ($streamConfig['burst_threshold'] ?? 3.0);
        $this->maxTopEvents = (int) ($streamConfig['max_top_events'] ?? 20);
    }

    /**
     * Ingest an event into the real-time stream.
     *
     * @param  string  $eventName  The analytics event name
     * @param  string  $category  The event category (ecommerce, saas, engagement, etc.)
     * @param  array<string, mixed>  $params  Optional event parameters
     */
    public function ingest(string $eventName, string $category, array $params = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $now = microtime(true);
        $this->lastIngestAt = $now;

        // Expire old buckets
        $this->expireBuckets($now);

        // Find or create the current bucket
        $bucketKey = $this->bucketKey($now);
        $bucketIndex = $this->findBucketIndex($bucketKey);

        if ($bucketIndex === null) {
            $this->buckets[] = [
                'timestamp' => $now,
                'events' => 0,
                'categories' => [],
                'event_names' => [],
            ];
            $bucketIndex = array_key_last($this->buckets);
        }

        // Update bucket
        $this->buckets[$bucketIndex]['events']++;
        $this->buckets[$bucketIndex]['categories'][$category] =
            ($this->buckets[$bucketIndex]['categories'][$category] ?? 0) + 1;
        $this->buckets[$bucketIndex]['event_names'][$eventName] =
            ($this->buckets[$bucketIndex]['event_names'][$eventName] ?? 0) + 1;

        // Update running totals
        $this->eventTotals[$eventName] = ($this->eventTotals[$eventName] ?? 0) + 1;
        $this->categoryTotals[$category] = ($this->categoryTotals[$category] ?? 0) + 1;
        $this->grandTotal++;

        // Track per-second counts for EPS
        $secondKey = (int) floor($now);
        if (! isset($this->epsHistory[$secondKey])) {
            $this->epsHistory[$secondKey] = 0;
        }
        $this->epsHistory[$secondKey]++;

        // Trim EPS history to window
        $this->trimEpsHistory($now);
    }

    /**
     * Get a snapshot of the current stream state.
     *
     * @return StreamSnapshot
     */
    public function snapshot(): array
    {
        $now = microtime(true);
        $this->expireBuckets($now);

        // Recompute totals from active buckets
        $windowEvents = 0;
        $windowCategories = [];
        $windowEventNames = [];

        foreach ($this->buckets as $bucket) {
            $windowEvents += $bucket['events'];

            foreach ($bucket['categories'] as $cat => $count) {
                $windowCategories[$cat] = ($windowCategories[$cat] ?? 0) + $count;
            }

            foreach ($bucket['event_names'] as $name => $count) {
                $windowEventNames[$name] = ($windowEventNames[$name] ?? 0) + $count;
            }
        }

        // Compute EPS (events per second)
        $eps = $this->computeEps($now);

        // Compute burst detection
        $burstDetected = false;
        $burstRatio = 0.0;
        if ($eps > 0) {
            $avgEps = $this->grandTotal > 0 ? $this->grandTotal / max(1, $this->windowSeconds) : 0.0;
            $burstRatio = $avgEps > 0 ? $eps / $avgEps : 0.0;
            $burstDetected = $burstRatio >= $this->burstThreshold;
        }

        // Top events ranked by count
        arsort($windowEventNames);
        $topEvents = [];
        $count = 0;
        foreach ($windowEventNames as $name => $eventCount) {
            $topEvents[] = ['name' => $name, 'count' => $eventCount];
            $count++;
            if ($count >= $this->maxTopEvents) {
                break;
            }
        }

        // Oldest bucket age
        $oldestBucketAge = null;
        if (! empty($this->buckets)) {
            $oldestBucketAge = round($now - $this->buckets[0]['timestamp'], 2);
        }

        return [
            'window_seconds' => $this->windowSeconds,
            'total_events' => $windowEvents,
            'events_per_second' => round($eps, 2),
            'by_category' => $windowCategories,
            'by_event' => $windowEventNames,
            'top_events' => $topEvents,
            'burst_detected' => $burstDetected,
            'burst_ratio' => round($burstRatio, 2),
            'oldest_bucket_age' => $oldestBucketAge,
            'buckets' => count($this->buckets),
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get events per second for the current stream.
     */
    public function eventsPerSecond(): float
    {
        return $this->computeEps(microtime(true));
    }

    /**
     * Check if a burst (traffic spike) is currently detected.
     */
    public function isBurstDetected(): bool
    {
        $now = microtime(true);
        $eps = $this->computeEps($now);

        if ($eps <= 0) {
            return false;
        }

        $avgEps = $this->grandTotal > 0 ? $this->grandTotal / max(1, $this->windowSeconds) : 0.0;

        if ($avgEps <= 0) {
            return false;
        }

        return ($eps / $avgEps) >= $this->burstThreshold;
    }

    /**
     * Get the count of events for a specific category in the current window.
     */
    public function categoryCount(string $category): int
    {
        $count = 0;
        foreach ($this->buckets as $bucket) {
            $count += $bucket['categories'][$category] ?? 0;
        }

        return $count;
    }

    /**
     * Get the count of a specific event name in the current window.
     */
    public function eventCount(string $eventName): int
    {
        $count = 0;
        foreach ($this->buckets as $bucket) {
            $count += $bucket['event_names'][$eventName] ?? 0;
        }

        return $count;
    }

    /**
     * Get a quick health summary of the stream.
     *
     * @return array{status: string, events_in_window: int, eps: float, burst: bool, buckets: int, lag_seconds: float|null}
     */
    public function quickSummary(): array
    {
        $snap = $this->snapshot();

        $status = 'idle';
        if ($snap['total_events'] > 0) {
            $status = $snap['burst_detected'] ? 'burst' : 'active';
        }

        $lag = null;
        if ($this->lastIngestAt > 0) {
            $lag = round(microtime(true) - $this->lastIngestAt, 2);
        }

        return [
            'status' => $status,
            'events_in_window' => $snap['total_events'],
            'eps' => $snap['events_per_second'],
            'burst' => $snap['burst_detected'],
            'buckets' => $snap['buckets'],
            'lag_seconds' => $lag,
        ];
    }

    /**
     * Clear all stream data.
     */
    public function clear(): void
    {
        $this->buckets = [];
        $this->eventTotals = [];
        $this->categoryTotals = [];
        $this->grandTotal = 0;
        $this->lastIngestAt = 0.0;
        $this->epsHistory = [];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured window size.
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Get the configured bucket size.
     */
    public function getBucketSizeSeconds(): int
    {
        return $this->bucketSizeSeconds;
    }

    /**
     * Expire buckets that are older than the sliding window.
     */
    private function expireBuckets(float $now): void
    {
        $cutoff = $now - $this->windowSeconds;

        while (! empty($this->buckets) && $this->buckets[0]['timestamp'] < $cutoff) {
            $expired = array_shift($this->buckets);

            // Subtract expired counts from totals
            $this->grandTotal -= $expired['events'];
            if ($this->grandTotal < 0) {
                $this->grandTotal = 0;
            }

            foreach ($expired['categories'] as $cat => $count) {
                if (isset($this->categoryTotals[$cat])) {
                    $this->categoryTotals[$cat] -= $count;
                    if ($this->categoryTotals[$cat] <= 0) {
                        unset($this->categoryTotals[$cat]);
                    }
                }
            }

            foreach ($expired['event_names'] as $name => $count) {
                if (isset($this->eventTotals[$name])) {
                    $this->eventTotals[$name] -= $count;
                    if ($this->eventTotals[$name] <= 0) {
                        unset($this->eventTotals[$name]);
                    }
                }
            }
        }
    }

    /**
     * Generate a bucket key from a timestamp.
     */
    private function bucketKey(float $timestamp): int
    {
        return (int) (floor($timestamp / $this->bucketSizeSeconds));
    }

    /**
     * Find the index of a bucket by key, or null if not found.
     */
    private function findBucketIndex(int $bucketKey): ?int
    {
        foreach ($this->buckets as $i => $bucket) {
            if ($this->bucketKey($bucket['timestamp']) === $bucketKey) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Compute events per second from recent history.
     */
    private function computeEps(float $now): float
    {
        $recentSeconds = min(10, $this->windowSeconds);
        $cutoff = (int) floor($now) - $recentSeconds;

        $recentCount = 0;
        foreach ($this->epsHistory as $second => $count) {
            if ($second >= $cutoff) {
                $recentCount += $count;
            }
        }

        return $recentCount > 0 ? (float) $recentCount / $recentSeconds : 0.0;
    }

    /**
     * Trim EPS history to the current window.
     */
    private function trimEpsHistory(float $now): void
    {
        $cutoff = (int) floor($now) - $this->windowSeconds;

        foreach ($this->epsHistory as $second => $count) {
            if ($second < $cutoff) {
                unset($this->epsHistory[$second]);
            }
        }
    }
}
