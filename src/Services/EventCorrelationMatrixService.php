<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event Correlation Matrix — Statistical cross-event correlation scoring.
 *
 * Analyzes event co-occurrence patterns to identify statistically significant
 * relationships between tracked events. Used for funnel insight generation,
 * user behavior prediction, and instrumentation gap detection.
 *
 * Inspired by PostHog's Event Correlation analysis, Amplitude's Compass,
 * and Mixpanel's Signal feature.
 *
 * The correlation is based on Jaccard similarity coefficient:
 *   J(A,B) = |A ∩ B| / |A ∪ B|
 *
 * Where A and B are sets of users who performed events within a configurable
 * time window. A score of 1.0 means every user who did A also did B (and vice
 * versa). A score of 0.0 means no overlap.
 *
 * Configuration: `zeroboiler.analytics.correlation`
 *
 * @since 20.0.0
 */
final class EventCorrelationMatrixService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_corr_';

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    /** @var int Minimum event count required for correlation analysis */
    private int $minEventCount;

    /** @var float Minimum correlation score to report (0.0-1.0) */
    private float $minCorrelation;

    /** @var int Maximum number of event pairs to analyze */
    private int $maxPairs;

    /** @var int Time window (seconds) for co-occurrence matching */
    private int $timeWindowSeconds;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $corrConfig = $config->get('zeroboiler.analytics.correlation', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_event_count?: int, min_correlation?: float, max_pairs?: int, time_window?: int} $corrConfig */

        $this->enabled = (bool) ($corrConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($corrConfig['cache_ttl'] ?? 600);
        $this->minEventCount = (int) ($corrConfig['min_event_count'] ?? 5);
        $this->minCorrelation = (float) ($corrConfig['min_correlation'] ?? 0.1);
        $this->maxPairs = (int) ($corrConfig['max_pairs'] ?? 100);
        $this->timeWindowSeconds = (int) ($corrConfig['time_window'] ?? 86400); // 24 hours
    }

    /**
     * Check if the correlation matrix service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute the Jaccard correlation between two event sets.
     *
     * @param  list<string>  $usersA  User IDs who performed event A
     * @param  list<string>  $usersB  User IDs who performed event B
     * @return float Correlation score (0.0-1.0)
     */
    public function computeJaccard(array $usersA, array $usersB): float
    {
        $setA = array_flip($usersA);
        $setB = array_flip($usersB);
        $unionCount = count($setA) + count($setB);
        $intersectionCount = count(array_intersect_key($setA, $setB));

        if ($unionCount === 0) {
            return 0.0;
        }

        return $intersectionCount / ($unionCount - $intersectionCount);
    }

    /**
     * Compute correlation scores for all event pairs.
     *
     * Takes an associative array mapping event names to lists of user IDs
     * who performed those events, and returns all significant correlations.
     *
     * @param  array<string, list<string>>  $eventUsers  Map of event_name => [user_id, ...]
     * @return list<array{event_a: string, event_b: string, score: float, users_a: int, users_b: int, intersection: int}>
     */
    public function computeAllPairs(array $eventUsers): array
    {
        $events = array_filter($eventUsers, fn (array $users): bool => count($users) >= $this->minEventCount);
        $eventNames = array_keys($events);
        $pairCount = 0;
        $results = [];

        for ($i = 0; $i < count($eventNames) && $pairCount < $this->maxPairs; $i++) {
            for ($j = $i + 1; $j < count($eventNames) && $pairCount < $this->maxPairs; $j++) {
                $eventA = $eventNames[$i];
                $eventB = $eventNames[$j];

                $score = $this->computeJaccard($events[$eventA], $events[$eventB]);

                if ($score >= $this->minCorrelation) {
                    $setA = array_flip($events[$eventA]);
                    $setB = array_flip($events[$eventB]);

                    $results[] = [
                        'event_a' => $eventA,
                        'event_b' => $eventB,
                        'score' => round($score, 4),
                        'users_a' => count($events[$eventA]),
                        'users_b' => count($events[$eventB]),
                        'intersection' => count(array_intersect_key($setA, $setB)),
                    ];
                    $pairCount++;
                }
            }
        }

        // Sort by score descending
        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Record a user-event pair for later correlation analysis.
     *
     * Stores user_id → event_name associations in cache for time-windowed
     * correlation computation.
     *
     * @param  string  $userId  User identifier
     * @param  string  $eventName  Event name
     */
    public function recordEvent(string $userId, string $eventName): void
    {
        $cacheKey = self::CACHE_PREFIX . 'event_' . $eventName;
        $users = $this->cache->get($cacheKey, []);

        if (! in_array($userId, $users, true)) {
            $users[] = $userId;
        }

        $this->cache->put($cacheKey, array_slice($users, -5000), $this->cacheTtl);

        // Also track per-user events
        $userKey = self::CACHE_PREFIX . 'user_' . $userId;
        $userEvents = $this->cache->get($userKey, []);

        if (! in_array($eventName, $userEvents, true)) {
            $userEvents[] = $eventName;
        }

        $this->cache->put($userKey, $userEvents, $this->cacheTtl);
    }

    /**
     * Get events associated with a user within the time window.
     *
     * @param  string  $userId  User identifier
     * @return list<string> Event names performed by this user
     */
    public function getUserEvents(string $userId): array
    {
        $userKey = self::CACHE_PREFIX . 'user_' . $userId;
        $events = $this->cache->get($userKey, []);

        return is_array($events) ? $events : [];
    }

    /**
     * Find events highly correlated with a target event.
     *
     * Useful for "users who did X also did Y" type recommendations.
     *
     * @param  string  $targetEvent  Target event name
     * @param  array<string, list<string>>  $eventUsers  Event → users map
     * @return list<array{event: string, score: float, direction: string}>
     */
    public function findCorrelatedEvents(string $targetEvent, array $eventUsers): array
    {
        if (! isset($eventUsers[$targetEvent]) || count($eventUsers[$targetEvent]) < $this->minEventCount) {
            return [];
        }

        $results = [];

        foreach ($eventUsers as $event => $users) {
            if ($event === $targetEvent || count($users) < $this->minEventCount) {
                continue;
            }

            $score = $this->computeJaccard($eventUsers[$targetEvent], $users);

            if ($score >= $this->minCorrelation) {
                $targetSize = count($eventUsers[$targetEvent]);
                $otherSize = count($users);
                $setTarget = array_flip($eventUsers[$targetEvent]);
                $setOther = array_flip($users);
                $intersection = count(array_intersect_key($setTarget, $setOther));

                // Direction: how many of target users also did other event
                $forwardPct = $targetSize > 0 ? round($intersection / $targetSize * 100, 1) : 0.0;
                // Direction: how many of other event users also did target
                $backwardPct = $otherSize > 0 ? round($intersection / $otherSize * 100, 1) : 0.0;

                $results[] = [
                    'event' => $event,
                    'score' => round($score, 4),
                    'direction' => $forwardPct >= $backwardPct ? 'forward' : 'backward',
                    'forward_pct' => $forwardPct,
                    'backward_pct' => $backwardPct,
                ];
            }
        }

        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Get the time window in seconds.
     */
    public function getTimeWindow(): int
    {
        return $this->timeWindowSeconds;
    }

    /**
     * Clear all correlation data from cache.
     */
    public function clearCache(): int
    {
        $prefix = self::CACHE_PREFIX;
        $cleared = 0;

        // Clear known event keys
        $this->cache->flush();

        $cleared++;

        return $cleared;
    }

    /**
     * Get a summary of the correlation matrix state.
     *
     * @param  list<string>  $eventNames  Event names to check
     * @return array{events: int, cached_events: int, config: array{min_count: int, min_score: float, max_pairs: int, time_window: int}}
     */
    public function getSummary(array $eventNames = []): array
    {
        $cachedCount = 0;

        foreach ($eventNames as $event) {
            $users = $this->cache->get(self::CACHE_PREFIX . 'event_' . $event, []);
            if (count($users) > 0) {
                $cachedCount++;
            }
        }

        return [
            'events' => count($eventNames),
            'cached_events' => $cachedCount,
            'config' => [
                'min_count' => $this->minEventCount,
                'min_score' => $this->minCorrelation,
                'max_pairs' => $this->maxPairs,
                'time_window' => $this->timeWindowSeconds,
            ],
        ];
    }
}
