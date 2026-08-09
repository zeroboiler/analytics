<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Event affinity scoring service.
 *
 * Computes pairwise affinity scores between event types, measuring how often
 * events co-occur within user sessions. High affinity indicates that events
 * tend to happen together, revealing user behavior patterns.
 *
 * Use cases:
 * - "Users who trigger X are N% more likely to trigger Y"
 * - Product recommendation based on event co-occurrence
 * - Funnel optimization by identifying high-affinity event pairs
 * - Onboarding flow optimization
 *
 * Affinity score formula:
 *   P(A ∩ B) / P(A) = P(B|A)
 *   Score = lift(B|A) = P(A∩B) / (P(A) × P(B))
 *   Score > 1.0 = positive correlation (events tend to co-occur)
 *   Score < 1.0 = negative correlation (events tend NOT to co-occur)
 *   Score = 1.0 = independent events (no correlation)
 *
 * @version 5.0.0
 */
final class EventAffinityService
{
    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private bool $enabled;

    private int $cacheTtl;

    private int $minCoOccurrences;

    private float $minLiftThreshold;

    private const CACHE_PREFIX = 'zb_affinity_';

    private const MAX_PAIRS_TRACKED = 500;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, AnalyticsMetrics $metrics, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->metrics = $metrics;

        $affinityConfig = $config->get('zeroboiler.analytics.affinity', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_co_occurrences?: int, min_lift_threshold?: float} $affinityConfig */

        $this->enabled = (bool) ($affinityConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($affinityConfig['cache_ttl'] ?? 3600); // 1 hour
        $this->minCoOccurrences = (int) ($affinityConfig['min_co_occurrences'] ?? 5);
        $this->minLiftThreshold = (float) ($affinityConfig['min_lift_threshold'] ?? 1.2);
    }

    /**
     * Check if affinity tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a co-occurrence of two events within a session.
     *
     * Updates the pairwise co-occurrence counter and individual event
     * frequency counters in cache.
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     */
    public function recordCoOccurrence(string $eventA, string $eventB): void
    {
        if (! $this->enabled) {
            return;
        }

        // Normalize pair order for consistent keying
        $pairKey = $this->pairKey($eventA, $eventB);

        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        /** @var array<string, int> $pairCounts */
        $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;

        // Trim to max pairs
        if (count($pairCounts) > self::MAX_PAIRS_TRACKED) {
            arsort($pairCounts);
            $pairCounts = array_slice($pairCounts, 0, self::MAX_PAIRS_TRACKED, true);
        }

        $this->cache->put(self::CACHE_PREFIX . 'pairs', $pairCounts, $this->cacheTtl);

        // Update individual event frequencies
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        /** @var array<string, int> $eventCounts */
        $eventCounts[$eventA] = ($eventCounts[$eventA] ?? 0) + 1;
        $eventCounts[$eventB] = ($eventCounts[$eventB] ?? 0) + 1;

        $this->cache->put(self::CACHE_PREFIX . 'events', $eventCounts, $this->cacheTtl);
    }

    /**
     * Calculate the lift (affinity) score between two events.
     *
     * Lift = P(A∩B) / (P(A) × P(B))
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     * @return float Lift score (1.0 = independent, > 1.0 = positive correlation)
     */
    public function lift(string $eventA, string $eventB): float
    {
        $pairKey = $this->pairKey($eventA, $eventB);

        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        $totalPairs = $this->cache->get(self::CACHE_PREFIX . 'total_pairs', 0);

        /** @var array<string, int> $pairCounts */
        /** @var array<string, int> $eventCounts */
        /** @var int $totalPairs */

        $coOccurrence = $pairCounts[$pairKey] ?? 0;
        $countA = $eventCounts[$eventA] ?? 0;
        $countB = $eventCounts[$eventB] ?? 0;

        if ($totalPairs <= 0 || $countA <= 0 || $countB <= 0 || $coOccurrence < $this->minCoOccurrences) {
            return 0.0;
        }

        $pA = $countA / $totalPairs;
        $pB = $countB / $totalPairs;
        $pAB = $coOccurrence / $totalPairs;

        return $pAB / ($pA * $pB);
    }

    /**
     * Get the conditional probability P(B|A).
     *
     * "Given event A occurred, what's the probability event B also occurred?"
     *
     * @param  string  $eventA  Condition event
     * @param  string  $eventB  Target event
     * @return float Conditional probability (0.0 - 1.0)
     */
    public function conditionalProbability(string $eventA, string $eventB): float
    {
        $pairKey = $this->pairKey($eventA, $eventB);

        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);

        /** @var array<string, int> $pairCounts */
        /** @var array<string, int> $eventCounts */

        $coOccurrence = $pairCounts[$pairKey] ?? 0;
        $countA = $eventCounts[$eventA] ?? 0;

        if ($countA <= 0) {
            return 0.0;
        }

        return (float) $coOccurrence / (float) $countA;
    }

    /**
     * Get all high-affinity event pairs.
     *
     * Returns pairs with lift score above the configured threshold,
     * sorted by affinity strength (highest first).
     *
     * @return list<array{event_a: string, event_b: string, lift: float, co_occurrences: int, conditional: float}>
     */
    public function highAffinityPairs(): array
    {
        $pairs = [];
        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        $totalPairs = $this->cache->get(self::CACHE_PREFIX . 'total_pairs', 0);

        /** @var array<string, int> $pairCounts */
        /** @var array<string, int> $eventCounts */
        /** @var int $totalPairs */

        foreach ($pairCounts as $pairKey => $count) {
            if ($count < $this->minCoOccurrences) {
                continue;
            }

            [$a, $b] = explode('|', $pairKey, 2);
            $countA = $eventCounts[$a] ?? 0;
            $countB = $eventCounts[$b] ?? 0;

            if ($totalPairs <= 0 || $countA <= 0 || $countB <= 0) {
                continue;
            }

            $pA = $countA / $totalPairs;
            $pB = $countB / $totalPairs;
            $pAB = $count / $totalPairs;
            $liftVal = $pAB / ($pA * $pB);

            if ($liftVal >= $this->minLiftThreshold) {
                $pairs[] = [
                    'event_a' => $a,
                    'event_b' => $b,
                    'lift' => round($liftVal, 3),
                    'co_occurrences' => $count,
                    'conditional' => round((float) $count / (float) $countA, 3),
                ];
            }
        }

        // Sort by lift descending
        usort($pairs, fn (array $a, array $b): int => $b['lift'] <=> $a['lift']);

        return $pairs;
    }

    /**
     * Get events most commonly associated with a given event.
     *
     * Returns top N events sorted by conditional probability P(B|A).
     *
     * @param  string  $event  Source event name
     * @param  int  $limit  Maximum results
     * @return list<array{event: string, lift: float, conditional: float, co_occurrences: int}>
     */
    public function relatedEvents(string $event, int $limit = 10): array
    {
        $results = [];
        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        $countA = $eventCounts[$event] ?? 0;

        /** @var array<string, int> $pairCounts */
        /** @var array<string, int> $eventCounts */

        if ($countA <= 0) {
            return [];
        }

        foreach ($pairCounts as $pairKey => $count) {
            [$a, $b] = explode('|', $pairKey, 2);
            $otherEvent = ($a === $event) ? $b : $a;

            if ($count < $this->minCoOccurrences) {
                continue;
            }

            $liftVal = $this->lift($event, $otherEvent);

            $results[] = [
                'event' => $otherEvent,
                'lift' => round($liftVal, 3),
                'conditional' => round((float) $count / (float) $countA, 3),
                'co_occurrences' => $count,
            ];
        }

        // Sort by conditional probability descending
        usort($results, fn (array $a, array $b): int => $b['conditional'] <=> $a['conditional']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Increment the total pairs counter.
     *
     * Call this when processing a complete session's events.
     */
    public function incrementTotalPairs(int $count = 1): void
    {
        $total = $this->cache->get(self::CACHE_PREFIX . 'total_pairs', 0);
        $this->cache->put(self::CACHE_PREFIX . 'total_pairs', $total + $count, $this->cacheTtl);
    }

    /**
     * Get a summary of the affinity analysis.
     *
     * @return array{total_pairs: int, tracked_events: int, high_affinity_pairs: int, top_pair: array|null}
     */
    public function summary(): array
    {
        $pairCounts = $this->cache->get(self::CACHE_PREFIX . 'pairs', []);
        $eventCounts = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        $totalPairs = $this->cache->get(self::CACHE_PREFIX . 'total_pairs', 0);
        $highAffinity = $this->highAffinityPairs();

        /** @var array<string, int> $pairCounts */
        /** @var array<string, int> $eventCounts */

        return [
            'total_pairs' => (int) $totalPairs,
            'tracked_events' => count($eventCounts),
            'high_affinity_pairs' => count($highAffinity),
            'top_pair' => $highAffinity[0] ?? null,
        ];
    }

    /**
     * Clear all affinity data.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'pairs');
        $this->cache->forget(self::CACHE_PREFIX . 'events');
        $this->cache->forget(self::CACHE_PREFIX . 'total_pairs');
    }

    /**
     * Generate a normalized pair key from two event names.
     *
     * Events are sorted alphabetically to ensure consistent keying
     * regardless of argument order.
     */
    private function pairKey(string $a, string $b): string
    {
        return $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
    }
}
