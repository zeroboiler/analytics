<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event Correlation Heatmap Service.
 *
 * Computes a correlation heatmap matrix across tracked events, measuring
 * pairwise co-occurrence frequency within user sessions. Produces structured
 * data suitable for dashboard heatmap chart rendering.
 *
 * Uses Jaccard similarity coefficient to normalize co-occurrence by event
 * frequency — preventing high-volume events from dominating the matrix.
 *
 * Inspired by Amplitude Compass and Mixpanel Event Correlation.
 *
 * @see \ZeroBoiler\Analytics\Services\EventCooccurrenceService
 * @see \ZeroBoiler\Analytics\Services\EventCorrelationService
 *
 * @since 8.8.0
 */
final class EventCorrelationHeatmapService
{
    private const DEFAULT_CACHE_TTL = 3600; // 1 hour

    private const DEFAULT_MIN_CO_OCCURRENCES = 3;

    private const DEFAULT_MAX_EVENTS = 30;

    private const DEFAULT_JACCARD_THRESHOLD = 0.05;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private string $cachePrefix;

    private int $cacheTtl;

    private int $minCoOccurrences;

    private int $maxEvents;

    private float $jaccardThreshold;

    /** @var list<string> */
    private array $excludeEvents;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $heatmapConfig = $config->get('zeroboiler.analytics.correlation_heatmap', []);
        /** @var array{cache_prefix?: string, cache_ttl?: int, min_co_occurrences?: int, max_events?: int, jaccard_threshold?: float, exclude_events?: list<string>} $heatmapConfig */

        $this->cachePrefix = (string) ($heatmapConfig['cache_prefix'] ?? 'zb_heatmap_');
        $this->cacheTtl = (int) ($heatmapConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->minCoOccurrences = (int) ($heatmapConfig['min_co_occurrences'] ?? self::DEFAULT_MIN_CO_OCCURRENCES);
        $this->maxEvents = (int) ($heatmapConfig['max_events'] ?? self::DEFAULT_MAX_EVENTS);
        $this->jaccardThreshold = (float) ($heatmapConfig['jaccard_threshold'] ?? self::DEFAULT_JACCARD_THRESHOLD);
        $this->excludeEvents = (array) ($heatmapConfig['exclude_events'] ?? ['page_view', 'scroll_depth']);
    }

    /**
     * Compute the full correlation heatmap matrix.
     *
     * Returns a matrix of pairwise Jaccard similarity scores for the top N events.
     * Only event pairs with similarity >= jaccard_threshold are included.
     *
     * @return array{matrix: array<string, array<string, float>>, events: list<string>, metadata: array{computed_at: string, total_events: int, matrix_size: int, threshold: float}}
     */
    public function computeHeatmap(): array
    {
        $cacheKey = $this->cachePrefix . 'heatmap_full';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $cooccurrence = $this->loadCooccurrenceData();
        $eventTotals = $this->computeEventTotals($cooccurrence);

        // Filter: exclude specified events and limit to top N by frequency
        $filteredEvents = $this->filterAndSortEvents($eventTotals);
        $eventList = array_slice($filteredEvents, 0, $this->maxEvents);

        // Compute Jaccard similarity matrix
        $matrix = [];
        foreach ($eventList as $eventA) {
            $matrix[$eventA] = [];
            foreach ($eventList as $eventB) {
                if ($eventA === $eventB) {
                    $matrix[$eventA][$eventB] = 1.0;
                    continue;
                }

                $pairKey = $this->pairKey($eventA, $eventB);
                $coCount = (int) ($cooccurrence[$pairKey] ?? 0);

                if ($coCount < $this->minCoOccurrences) {
                    $matrix[$eventA][$eventB] = 0.0;
                    continue;
                }

                $countA = (int) ($eventTotals[$eventA] ?? 1);
                $countB = (int) ($eventTotals[$eventB] ?? 1);
                $union = $countA + $countB - $coCount;

                $jaccard = $union > 0 ? (float) ($coCount / $union) : 0.0;
                $matrix[$eventA][$eventB] = $jaccard >= $this->jaccardThreshold ? $jaccard : 0.0;
            }
        }

        $result = [
            'matrix' => $matrix,
            'events' => array_values($eventList),
            'metadata' => [
                'computed_at' => date('c'),
                'total_events' => count($eventList),
                'matrix_size' => count($eventList) * count($eventList),
                'threshold' => $this->jaccardThreshold,
            ],
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get the top correlated event pairs.
     *
     * Returns event pairs sorted by correlation strength (descending).
     * Useful for "Events frequently done together" dashboard widgets.
     *
     * @param  int  $limit  Maximum pairs to return
     * @return array<int, array{event_a: string, event_b: string, correlation: float, co_occurrences: int, jaccard: float}>
     */
    public function getTopCorrelations(int $limit = 20): array
    {
        $heatmap = $this->computeHeatmap();
        $matrix = $heatmap['matrix'];
        $pairs = [];

        $processed = [];
        foreach ($matrix as $eventA => $row) {
            foreach ($row as $eventB => $score) {
                if ($eventA === $eventB || $score === 0.0) {
                    continue;
                }

                $pairKey = implode('|', [$eventA, $eventB]);
                $reverseKey = implode('|', [$eventB, $eventA]);

                if (in_array($pairKey, $processed, true) || in_array($reverseKey, $processed, true)) {
                    continue;
                }
                $processed[] = $pairKey;

                $cooccurrence = $this->loadCooccurrenceData();
                $coKey = $this->pairKey($eventA, $eventB);
                $coCount = (int) ($cooccurrence[$coKey] ?? 0);

                $pairs[] = [
                    'event_a' => $eventA,
                    'event_b' => $eventB,
                    'correlation' => $score,
                    'co_occurrences' => $coCount,
                    'jaccard' => $score,
                ];
            }
        }

        usort($pairs, fn (array $a, array $b): int => $b['correlation'] <=> $a['correlation']);

        return array_slice($pairs, 0, $limit);
    }

    /**
     * Get correlation data for a specific event.
     *
     * Returns all correlated events for a given event, sorted by strength.
     *
     * @param  string  $eventName  The event to find correlations for
     * @return array{event: string, correlations: array<int, array{event: string, correlation: float}>}
     */
    public function getEventCorrelations(string $eventName): array
    {
        $heatmap = $this->computeHeatmap();
        $matrix = $heatmap['matrix'];

        if (! isset($matrix[$eventName])) {
            return [
                'event' => $eventName,
                'correlations' => [],
            ];
        }

        $correlations = [];
        foreach ($matrix[$eventName] as $otherEvent => $score) {
            if ($otherEvent !== $eventName && $score > 0.0) {
                $correlations[] = [
                    'event' => $otherEvent,
                    'correlation' => $score,
                ];
            }
        }

        usort($correlations, fn (array $a, array $b): int => $b['correlation'] <=> $a['correlation']);

        return [
            'event' => $eventName,
            'correlations' => $correlations,
        ];
    }

    /**
     * Get the heatmap data formatted for chart rendering (flat list for D3/Chart.js).
     *
     * Returns a flat array of {source, target, value} triples suitable for
     * chord diagrams, force-directed graphs, or matrix heatmaps.
     *
     * @return array<int, array{source: string, target: string, value: float}>
     */
    public function getChartData(): array
    {
        $topPairs = $this->getTopCorrelations(50);

        return array_map(
            fn (array $pair): array => [
                'source' => $pair['event_a'],
                'target' => $pair['event_b'],
                'value' => round($pair['correlation'], 4),
            ],
            $topPairs,
        );
    }

    /**
     * Get correlation summary statistics.
     *
     * @return array{total_pairs: int, avg_correlation: float, max_correlation: float, median_correlation: float, strong_pairs: int}
     */
    public function getStats(): array
    {
        $topPairs = $this->getTopCorrelations(500);

        if ($topPairs === []) {
            return [
                'total_pairs' => 0,
                'avg_correlation' => 0.0,
                'max_correlation' => 0.0,
                'median_correlation' => 0.0,
                'strong_pairs' => 0,
            ];
        }

        $correlations = array_column($topPairs, 'correlation');
        $count = count($correlations);

        return [
            'total_pairs' => $count,
            'avg_correlation' => round(array_sum($correlations) / $count, 4),
            'max_correlation' => round(max($correlations), 4),
            'median_correlation' => round($this->median($correlations), 4),
            'strong_pairs' => count(array_filter($correlations, fn (float $c): bool => $c >= 0.5)),
        ];
    }

    /**
     * Record a co-occurrence between two events for the same client/session.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  string  $sessionId  Session ID
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     */
    public function recordCoOccurrence(string $clientId, string $sessionId, string $eventA, string $eventB): void
    {
        // Sort pair alphabetically for consistent keying
        $pairKey = $this->pairKey($eventA, $eventB);
        $sessionKey = $this->cachePrefix . 'session_pair:' . $clientId . ':' . $sessionId . ':' . $pairKey;

        // Deduplicate within same session
        $exists = $this->cache->get($sessionKey);
        if ($exists !== null) {
            return;
        }
        $this->cache->put($sessionKey, true, 1800); // 30 min session

        // Increment global co-occurrence counter
        $counterKey = $this->cachePrefix . 'cooccurrence:' . $pairKey;
        $this->cache->increment($counterKey);
        $this->cache->put($counterKey, (int) $this->cache->get($counterKey, 0), 86400); // 24h TTL

        // Increment per-event totals
        foreach ([$eventA, $eventB] as $event) {
            $eventKey = $this->cachePrefix . 'event_total:' . $event;
            $this->cache->increment($eventKey);
            $this->cache->put($eventKey, (int) $this->cache->get($eventKey, 0), 86400);
        }
    }

    /**
     * Invalidate the cached heatmap.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget($this->cachePrefix . 'heatmap_full');
    }

    /**
     * Load raw co-occurrence data from cache.
     *
     * @return array<string, int>
     */
    private function loadCooccurrenceData(): array
    {
        $data = [];

        // Scan for co-occurrence keys (limited scan for performance)
        $prefix = $this->cachePrefix . 'cooccurrence:';
        $this->scanCacheKeys($prefix, $data);

        return $data;
    }

    /**
     * Compute per-event totals from co-occurrence data.
     *
     * @param  array<string, int>  $cooccurrence  Co-occurrence counters
     * @return array<string, int>  Event totals
     */
    private function computeEventTotals(array $cooccurrence): array
    {
        $totals = [];

        foreach ($cooccurrence as $pairKey => $count) {
            $parts = explode('::', $pairKey);
            $eventA = $parts[0] ?? '';
            $eventB = $parts[1] ?? '';

            $totals[$eventA] = ($totals[$eventA] ?? 0) + $count;
            $totals[$eventB] = ($totals[$eventB] ?? 0) + $count;
        }

        return $totals;
    }

    /**
     * Filter and sort events by total co-occurrence frequency.
     *
     * @param  array<string, int>  $eventTotals  Per-event totals
     * @return list<string>  Sorted event names
     */
    private function filterAndSortEvents(array $eventTotals): array
    {
        $filtered = [];
        foreach ($eventTotals as $event => $total) {
            if (! in_array($event, $this->excludeEvents, true) && $total >= $this->minCoOccurrences) {
                $filtered[$event] = $total;
            }
        }

        arsort($filtered);

        return array_keys($filtered);
    }

    /**
     * Generate a deterministic pair key from two event names.
     *
     * Events are sorted alphabetically so (A,B) and (B,A) produce the same key.
     *
     * @param  string  $a  First event name
     * @param  string  $b  Second event name
     */
    private function pairKey(string $a, string $b): string
    {
        $sorted = [$a, $b];
        sort($sorted);

        return $sorted[0] . '::' . $sorted[1];
    }

    /**
     * Scan cache for keys matching prefix (store-specific implementation).
     *
     * @param  string  $prefix  Key prefix
     * @param  array<string, int>  $data  Output data
     */
    private function scanCacheKeys(string $prefix, array &$data): void
    {
        // Use event_totals keys as a proxy for co-occurrence lookup
        $totalsPrefix = $this->cachePrefix . 'event_total:';
        $events = [];

        if (method_exists($this->cache, 'get')) {
            // Load known event totals from dedicated counters
            $totalKey = $this->cachePrefix . 'known_events';
            $knownEvents = $this->cache->get($totalKey, []);
            /** @var list<string> $knownEvents */
            foreach ($knownEvents as $event) {
                $countKey = $totalsPrefix . $event;
                $count = (int) $this->cache->get($countKey, 0);
                if ($count > 0) {
                    $events[$event] = $count;
                }
            }
        }

        // Build co-occurrence from known events
        $eventNames = array_keys($events);
        for ($i = 0; $i < count($eventNames); $i++) {
            for ($j = $i + 1; $j < count($eventNames); $j++) {
                $pairKey = $this->pairKey($eventNames[$i], $eventNames[$j]);
                $coKey = $this->cachePrefix . 'cooccurrence:' . $pairKey;
                $coCount = (int) $this->cache->get($coKey, 0);
                if ($coCount > 0) {
                    $data[$pairKey] = $coCount;
                }
            }
        }
    }

    /**
     * Compute median of numeric array.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        $sorted = $values;
        sort($sorted);
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }

        return $sorted[$mid];
    }
}
