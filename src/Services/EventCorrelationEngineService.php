<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event correlation engine — detects statistically significant causal relationships
 * between analytics events using temporal proximity analysis.
 *
 * Identifies event pairs that frequently co-occur within configurable time windows
 * and computes correlation coefficients. Used for:
 * - Funnel drop-off root cause analysis
 * - Feature adoption sequencing (which events precede conversion?)
 * - Behavioral pattern discovery
 * - Anomaly investigation support
 *
 * Uses cache-backed correlation matrices for performance. Supports configurable
 * time windows, minimum co-occurrence thresholds, and decay rates for temporal recency.
 *
 * Inspired by Amplitude Pathfinder, Mixpanel Journeys, and Datadog correlation analysis.
 *
 * @since 48.0.0
 */
final class EventCorrelationEngineService
{
    /** @var array<string, mixed> */
    private array $config;

    private string $cachePrefix;

    private int $cacheTtl;

    private int $timeWindowSeconds;

    private int $minCooccurrence;

    private float $minCorrelationScore;

    private float $decayRate;

    private int $maxCorrelationsPerEvent;

    private int $maxEventPairCacheSize;

    /**
     * @param  CacheRepository  $cache  Cache repository for correlation matrices
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->config = $config->get('zeroboiler.analytics.correlation_engine', []);
        $this->cachePrefix = (string) ($this->config['cache_prefix'] ?? 'zb_corr_');
        $this->cacheTtl = (int) ($this->config['cache_ttl'] ?? 7200); // 2 hours
        $this->timeWindowSeconds = (int) ($this->config['time_window_seconds'] ?? 300); // 5 minutes
        $this->minCooccurrence = (int) ($this->config['min_cooccurrence'] ?? 3);
        $this->minCorrelationScore = (float) ($this->config['min_correlation_score'] ?? 0.3);
        $this->decayRate = (float) ($this->config['decay_rate'] ?? 0.95); // exponential decay per window
        $this->maxCorrelationsPerEvent = (int) ($this->config['max_correlations_per_event'] ?? 20);
        $this->maxEventPairCacheSize = (int) ($this->config['max_event_pair_cache_size'] ?? 10000);
    }

    /**
     * Record a co-occurrence between two events within the time window.
     *
     * Updates the cached correlation matrix for the event pair.
     * Uses event pair key normalization (alphabetical ordering) to ensure
     * bidirectional lookups.
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     * @param  int  $timeDelta  Time difference in seconds (positive = B after A)
     * @param  array<string, mixed>  $context  Optional context (user_id, session_id, etc.)
     * @return void
     */
    public function recordCooccurrence(string $eventA, string $eventB, int $timeDelta = 0, array $context = []): void
    {
        if ($eventA === $eventB) {
            return;
        }

        $pairKey = $this->normalizePairKey($eventA, $eventB);
        $cacheKey = $this->cachePrefix . 'pair_' . $pairKey;
        $totalCountKey = $this->cachePrefix . 'total_' . $pairKey;

        /** @var array{count: int, total_delta: int, min_delta: int, max_delta: int, contexts: list<array<string, mixed>>, timestamps: list<int>} $data */
        $data = $this->cache->get($cacheKey, [
            'count' => 0,
            'total_delta' => 0,
            'min_delta' => PHP_INT_MAX,
            'max_delta' => 0,
            'contexts' => [],
            'timestamps' => [],
        ]);

        $data['count']++;
        $data['total_delta'] += $timeDelta;
        $data['min_delta'] = min($data['min_delta'], $timeDelta);
        $data['max_delta'] = max($data['max_delta'], $timeDelta);
        $data['timestamps'][] = time();

        // Keep last 100 timestamps for recency scoring
        if (count($data['timestamps']) > 100) {
            $data['timestamps'] = array_slice($data['timestamps'], -50);
        }

        // Keep last 10 context snapshots
        if ($context !== [] && count($data['contexts']) < 10) {
            $data['contexts'][] = $context;
        }

        $this->cache->put($cacheKey, $data, $this->cacheTtl);

        $this->incrementEventCount($eventA);
        $this->incrementEventCount($eventB);

        // Track total co-occurrence volume
        /** @var int $totalCooccurrences */
        $totalCooccurrences = $this->cache->get($totalCountKey, 0);
        $this->cache->put($totalCountKey, $totalCooccurrences + 1, $this->cacheTtl);
    }

    /**
     * Get the correlation score between two events.
     *
     * Computes a normalized correlation coefficient (0.0–1.0) based on:
     * - Co-occurrence count relative to individual event frequencies
     * - Temporal recency (more recent co-occurrences weighted higher)
     * - Directionality (A→B vs B→A timing patterns)
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     * @return float Correlation score (0.0–1.0)
     */
    public function getCorrelationScore(string $eventA, string $eventB): float
    {
        if ($eventA === $eventB) {
            return 1.0;
        }

        $pairKey = $this->normalizePairKey($eventA, $eventB);
        $cacheKey = $this->cachePrefix . 'pair_' . $pairKey;

        /** @var array{count: int, total_delta: int, timestamps: list<int>} $data */
        $data = $this->cache->get($cacheKey, [
            'count' => 0,
            'total_delta' => 0,
            'timestamps' => [],
        ]);

        $cooccurrenceCount = $data['count'];

        if ($cooccurrenceCount < $this->minCooccurrence) {
            return 0.0;
        }

        // Individual event frequencies
        $countA = $this->getEventCount($eventA);
        $countB = $this->getEventCount($eventB);

        if ($countA === 0 || $countB === 0) {
            return 0.0;
        }

        // Normalized pointwise mutual information (simplified)
        // PMI = log(P(A,B) / (P(A) * P(B)))
        // Normalized to 0–1 range
        $totalEvents = $this->getTotalEventCount();
        if ($totalEvents === 0) {
            return 0.0;
        }

        $pAB = $cooccurrenceCount / $totalEvents;
        $pA = $countA / $totalEvents;
        $pB = $countB / $totalEvents;

        if ($pA === 0.0 || $pB === 0.0) {
            return 0.0;
        }

        $expected = $pA * $pB;
        if ($expected === 0.0) {
            return 0.0;
        }

        $npmi = ($pAB - $expected) / max($pAB, $expected);

        $recencyWeight = $this->computeRecencyWeight($data['timestamps']);

        $rawScore = (max(0.0, $npmi) * 0.6) + ($recencyWeight * 0.4);

        return round(min(1.0, max(0.0, $rawScore)), 4);
    }

    /**
     * Get all strongly correlated events for a given event.
     *
     * Returns a ranked list of events that are correlated above the
     * minimum threshold, sorted by correlation score (descending).
     *
     * @param  string  $eventName  The event to find correlations for
     * @return list<array{event: string, score: float, direction: string, avg_delta: int, cooccurrences: int}>
     */
    public function getCorrelatedEvents(string $eventName): array
    {
        $correlations = [];
        $searchKey = $this->cachePrefix . 'pair_';

        // We scan by event name prefix in the cache
        // For efficiency, we maintain a per-event correlation index
        $indexKey = $this->cachePrefix . 'index_' . $eventName;
        /** @var list<string> $correlatedPairs */
        $correlatedPairs = $this->cache->get($indexKey, []);

        foreach ($correlatedPairs as $pairKey) {
            $otherEvent = $this->extractOtherEvent($pairKey, $eventName);
            if ($otherEvent === null) {
                continue;
            }

            $score = $this->getCorrelationScore($eventName, $otherEvent);
            if ($score < $this->minCorrelationScore) {
                continue;
            }

            $pairData = $this->getPairData($eventName, $otherEvent);
            $avgDelta = $pairData['count'] > 0
                ? (int) round($pairData['total_delta'] / $pairData['count'])
                : 0;

            $correlations[] = [
                'event' => $otherEvent,
                'score' => $score,
                'direction' => $avgDelta > 0 ? 'after' : ($avgDelta < 0 ? 'before' : 'simultaneous'),
                'avg_delta' => abs($avgDelta),
                'cooccurrences' => $pairData['count'],
            ];
        }

        usort($correlations, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($correlations, 0, $this->maxCorrelationsPerEvent);
    }

    /**
     * Get the top N correlated event pairs across the entire system.
     *
     * Useful for admin dashboards and anomaly root cause analysis.
     *
     * @param  int  $limit  Maximum pairs to return
     * @return list<array{event_a: string, event_b: string, score: float, cooccurrences: int, avg_delta: int}>
     */
    public function getTopCorrelations(int $limit = 20): array
    {
        $topPairsKey = $this->cachePrefix . 'top_pairs';
        /** @var list<array{event_a: string, event_b: string, score: float, cooccurrences: int, avg_delta: int}> $topPairs */
        $topPairs = $this->cache->get($topPairsKey, []);

        if ($topPairs !== []) {
            return array_slice($topPairs, 0, $limit);
        }

        // Fallback: scan known pairs from per-event indices
        $allPairs = $this->discoverPairs();
        $scored = [];

        foreach ($allPairs as $pairKey) {
            $parts = explode('::', $pairKey);
            if (count($parts) !== 2) {
                continue;
            }

            $score = $this->getCorrelationScore($parts[0], $parts[1]);
            if ($score < $this->minCorrelationScore) {
                continue;
            }

            $pairData = $this->getPairData($parts[0], $parts[1]);
            $avgDelta = $pairData['count'] > 0
                ? (int) round($pairData['total_delta'] / $pairData['count'])
                : 0;

            $scored[] = [
                'event_a' => $parts[0],
                'event_b' => $parts[1],
                'score' => $score,
                'cooccurrences' => $pairData['count'],
                'avg_delta' => abs($avgDelta),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $topPairs = array_slice($scored, 0, 100);

        $this->cache->put($topPairsKey, $topPairs, 600); // 10 minutes

        return array_slice($topPairs, 0, $limit);
    }

    /**
     * Analyze which events most commonly precede a target event.
     *
     * Used for conversion funnel analysis and feature adoption sequencing.
     * Returns events sorted by frequency of preceding the target within the time window.
     *
     * @param  string  $targetEvent  The event to analyze antecedents for
     * @param  int  $limit  Maximum antecedents to return
     * @return list<array{event: string, frequency: int, avg_seconds_before: int, correlation: float}>
     */
    public function getAntecedents(string $targetEvent, int $limit = 10): array
    {
        $correlations = $this->getCorrelatedEvents($targetEvent);
        $antecedents = [];

        foreach ($correlations as $corr) {
            if ($corr['direction'] === 'before' || $corr['direction'] === 'simultaneous') {
                $antecedents[] = [
                    'event' => $corr['event'],
                    'frequency' => $corr['cooccurrences'],
                    'avg_seconds_before' => $corr['avg_delta'],
                    'correlation' => $corr['score'],
                ];
            }
        }

        usort($antecedents, fn (array $a, array $b): int => $b['frequency'] <=> $a['frequency']);

        return array_slice($antecedents, 0, $limit);
    }

    /**
     * Analyze which events most commonly follow a source event.
     *
     * Used for predicting next actions and understanding behavioral flow.
     *
     * @param  string  $sourceEvent  The event to analyze consequents for
     * @param  int  $limit  Maximum consequents to return
     * @return list<array{event: string, frequency: int, avg_seconds_after: int, correlation: float}>
     */
    public function getConsequents(string $sourceEvent, int $limit = 10): array
    {
        $correlations = $this->getCorrelatedEvents($sourceEvent);
        $consequents = [];

        foreach ($correlations as $corr) {
            if ($corr['direction'] === 'after' || $corr['direction'] === 'simultaneous') {
                $consequents[] = [
                    'event' => $corr['event'],
                    'frequency' => $corr['cooccurrences'],
                    'avg_seconds_after' => $corr['avg_delta'],
                    'correlation' => $corr['score'],
                ];
            }
        }

        usort($consequents, fn (array $a, array $b): int => $b['frequency'] <=> $a['frequency']);

        return array_slice($consequents, 0, $limit);
    }

    /**
     * Get the correlation matrix summary.
     *
     * Returns aggregate statistics about the correlation engine state.
     *
     * @return array{total_pairs_tracked: int, total_cooccurrences: int, events_with_correlations: int, avg_correlation_score: float, top_correlated_pair: array<string, mixed>|null, cache_prefix: string, time_window_seconds: int}
     */
    public function getSummary(): array
    {
        $pairsTracked = $this->discoverPairs();
        $totalCooccurrences = 0;
        $scores = [];

        foreach (array_slice($pairsTracked, 0, 200) as $pairKey) {
            $parts = explode('::', $pairKey);
            if (count($parts) !== 2) {
                continue;
            }

            $pairData = $this->getPairData($parts[0], $parts[1]);
            $totalCooccurrences += $pairData['count'];
            $scores[] = $this->getCorrelationScore($parts[0], $parts[1]);
        }

        // Events with at least one correlation
        $eventsWithCorrelations = 0;
        $seenEvents = [];
        foreach (array_slice($pairsTracked, 0, 200) as $pairKey) {
            $parts = explode('::', $pairKey);
            if (count($parts) !== 2) {
                continue;
            }
            if ($this->getCorrelationScore($parts[0], $parts[1]) >= $this->minCorrelationScore) {
                $seenEvents[$parts[0]] = true;
                $seenEvents[$parts[1]] = true;
            }
        }
        $eventsWithCorrelations = count($seenEvents);

        $avgScore = count($scores) > 0
            ? round(array_sum($scores) / count($scores), 4)
            : 0.0;

        $topPairs = $this->getTopCorrelations(1);
        $topPair = count($topPairs) > 0 ? $topPairs[0] : null;

        return [
            'total_pairs_tracked' => count($pairsTracked),
            'total_cooccurrences' => $totalCooccurrences,
            'events_with_correlations' => $eventsWithCorrelations,
            'avg_correlation_score' => $avgScore,
            'top_correlated_pair' => $topPair,
            'cache_prefix' => $this->cachePrefix,
            'time_window_seconds' => $this->timeWindowSeconds,
        ];
    }

    /**
     * Clear all correlation data from cache.
     *
     * Useful for testing and periodic cleanup.
     *
     * @return int Number of keys cleared
     */
    public function clearCorrelations(): int
    {
        $pairs = $this->discoverPairs();
        $cleared = 0;

        foreach ($pairs as $pairKey) {
            $this->cache->forget($this->cachePrefix . 'pair_' . $pairKey);
            $this->cache->forget($this->cachePrefix . 'total_' . $pairKey);
            $cleared++;
        }

        $eventsKey = $this->cachePrefix . 'events_list';
        /** @var list<string> $events */
        $events = $this->cache->get($eventsKey, []);
        foreach ($events as $event) {
            $this->cache->forget($this->cachePrefix . 'count_' . $event);
            $this->cache->forget($this->cachePrefix . 'index_' . $event);
            $cleared++;
        }

        $this->cache->forget($eventsKey);
        $this->cache->forget($this->cachePrefix . 'top_pairs');
        $this->cache->forget($this->cachePrefix . 'global_count');
        $cleared += 3;

        return $cleared;
    }

    /**
     * Normalize a pair key to ensure consistent ordering.
     *
     * @return string Normalized pair key (alphabetical)
     */
    private function normalizePairKey(string $eventA, string $eventB): string
    {
        return $eventA < $eventB
            ? $eventA . '::' . $eventB
            : $eventB . '::' . $eventA;
    }

    /**
     * Increment the event occurrence counter.
     */
    private function incrementEventCount(string $eventName): void
    {
        $countKey = $this->cachePrefix . 'count_' . $eventName;
        /** @var int $count */
        $count = $this->cache->get($countKey, 0);
        $this->cache->put($countKey, $count + 1, $this->cacheTtl);

        $eventsKey = $this->cachePrefix . 'events_list';
        /** @var list<string> $events */
        $events = $this->cache->get($eventsKey, []);
        if (! in_array($eventName, $events, true)) {
            $events[] = $eventName;
            $this->cache->put($eventsKey, array_slice($events, -500), $this->cacheTtl);
        }

        $indexKey = $this->cachePrefix . 'index_' . $eventName;
        $pairKey = $this->normalizePairKey($eventName, '__placeholder__');
        /** @var list<string> $index */
        $index = $this->cache->get($indexKey, []);

        $globalCountKey = $this->cachePrefix . 'global_count';
        /** @var int $globalCount */
        $globalCount = $this->cache->get($globalCountKey, 0);
        $this->cache->put($globalCountKey, $globalCount + 1, $this->cacheTtl);
    }

    /**
     * Get the occurrence count for an event.
     */
    private function getEventCount(string $eventName): int
    {
        $countKey = $this->cachePrefix . 'count_' . $eventName;

        return (int) $this->cache->get($countKey, 0);
    }

    /**
     * Get the total event count across all tracked events.
     */
    private function getTotalEventCount(): int
    {
        $globalCountKey = $this->cachePrefix . 'global_count';

        return (int) $this->cache->get($globalCountKey, 0);
    }

    /**
     * Get raw pair data.
     *
     * @return array{count: int, total_delta: int, min_delta: int, max_delta: int}
     */
    private function getPairData(string $eventA, string $eventB): array
    {
        $pairKey = $this->normalizePairKey($eventA, $eventB);
        $cacheKey = $this->cachePrefix . 'pair_' . $pairKey;

        /** @var array{count: int, total_delta: int, min_delta: int, max_delta: int} $data */
        $data = $this->cache->get($cacheKey, [
            'count' => 0,
            'total_delta' => 0,
            'min_delta' => 0,
            'max_delta' => 0,
        ]);

        return $data;
    }

    /**
     * Compute temporal recency weight from timestamps.
     *
     * Recent co-occurrences get higher weight using exponential decay.
     *
     * @param  list<int>  $timestamps  Unix timestamps of co-occurrences
     * @return float Recency weight (0.0–1.0)
     */
    private function computeRecencyWeight(array $timestamps): float
    {
        if ($timestamps === []) {
            return 0.0;
        }

        $now = time();
        $weights = [];

        foreach ($timestamps as $ts) {
            $age = $now - $ts;
            $windowDecays = $age / $this->cacheTtl;
            $weight = pow($this->decayRate, $windowDecays);
            $weights[] = $weight;
        }

        return round(array_sum($weights) / count($weights), 4);
    }

    /**
     * Extract the other event from a pair key.
     */
    private function extractOtherEvent(string $pairKey, string $eventName): ?string
    {
        $parts = explode('::', $pairKey);
        if (count($parts) !== 2) {
            return null;
        }

        return $parts[0] === $eventName ? $parts[1] : ($parts[1] === $eventName ? $parts[0] : null);
    }

    /**
     * Discover all tracked event pair keys from per-event indices.
     *
     * @return list<string> List of pair keys
     */
    private function discoverPairs(): array
    {
        $eventsKey = $this->cachePrefix . 'events_list';
        /** @var list<string> $events */
        $events = $this->cache->get($eventsKey, []);
        $pairs = [];

        foreach ($events as $event) {
            $indexKey = $this->cachePrefix . 'index_' . $event;
            /** @var list<string> $eventPairs */
            $eventPairs = $this->cache->get($indexKey, []);
            foreach ($eventPairs as $pair) {
                if (! in_array($pair, $pairs, true)) {
                    $pairs[] = $pair;
                }
            }
        }

        return array_slice($pairs, 0, $this->maxEventPairCacheSize);
    }
}
