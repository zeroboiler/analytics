<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Event co-occurrence matrix analysis service.
 *
 * Tracks which events are frequently dispatched together within the same
 * session or time window. Produces a co-occurrence matrix and correlation
 * scores that can be used for:
 *
 * - Dashboard "events frequently done together" widget
 * - User journey path analysis
 * - Cross-sell / feature discovery patterns
 * - Funnel optimization recommendations
 *
 * Inspired by Amplitude Pathfinder and Mixpanel Correlation.
 *
 * Configuration is read from `zeroboiler.analytics.cooccurrence`.
 *
 * @phpstan-type CooccurrencePair array{event_a: string, event_b: string, count: int, correlation: float}
 * @phpstan-type CooccurrenceMatrix array<string, array<string, int>>
 *
 * @since v7.2.0
 */
final class EventCooccurrenceService
{
    private const CACHE_PREFIX = 'zb_cooccurrence_';

    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly int $windowSeconds;

    private readonly int $maxEvents;

    private readonly CacheRepository $cache;

    /** @var array<string, list<string>> Session event logs */
    private array $sessionEvents = [];

    /** @var CooccurrenceMatrix Accumulated co-occurrence counts */
    private array $matrix = [];

    private readonly AnalyticsMetrics $metrics;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
    ): void {
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $coConfig = $config->get('zeroboiler.analytics.cooccurrence', []);
        /** @var array{enabled?: bool, cache_ttl?: int, window_seconds?: int, max_events?: int} $coConfig */

        $this->enabled = (bool) ($coConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($coConfig['cache_ttl'] ?? 3600); // 1 hour
        $this->windowSeconds = (int) ($coConfig['window_seconds'] ?? 1800); // 30 minutes
        $this->maxEvents = (int) ($coConfig['max_events'] ?? 50);
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    /**
     * Record an event for co-occurrence tracking.
     *
     * Call this after each event dispatch to build the co-occurrence matrix.
     * Events are grouped by session ID for per-session analysis.
     *
     * @param  string  $eventName  The event name to record
     * @param  string|null  $sessionId  Optional session ID for grouping
     */
    public function recordEvent(string $eventName, ?string $sessionId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $sessionId = $sessionId ?? 'default';

        if (! isset($this->sessionEvents[$sessionId])) {
            $this->sessionEvents[$sessionId] = [];
        }

        $this->sessionEvents[$sessionId][] = $eventName;

        // Build co-occurrence pairs within this session
        $events = $this->sessionEvents[$sessionId];
        $currentIdx = count($events) - 1;

        // Compare with all previous events in this session (within window)
        for ($i = max(0, $currentIdx - $this->maxEvents); $i < $currentIdx; $i++) {
            $otherEvent = $events[$i];
            $this->incrementPair($otherEvent, $eventName);
        }
    }

    /**
     * Get the full co-occurrence matrix.
     *
     * @return CooccurrenceMatrix
     */
    public function getMatrix(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'matrix';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Build from metrics dispatch data if no session data
        $this->buildFromMetrics();
        $this->cache->put($cacheKey, $this->matrix, $this->cacheTtl);

        return $this->matrix;
    }

    /**
     * Get the top co-occurring event pairs ranked by count.
     *
     * @param  int  $limit  Maximum pairs to return
     * @return list<CooccurrencePair>
     */
    public function topPairs(int $limit = 20): array
    {
        $matrix = $this->getMatrix();
        $pairs = [];

        foreach ($matrix as $eventA => $row) {
            foreach ($row as $eventB => $count) {
                // Avoid duplicates (a,b) and (b,a) — only include where a < b
                if ($eventA < $eventB) {
                    $pairs[] = [
                        'event_a' => $eventA,
                        'event_b' => $eventB,
                        'count' => $count,
                        'correlation' => $this->calculateCorrelation($eventA, $eventB, $matrix),
                    ];
                }
            }
        }

        usort($pairs, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($pairs, 0, $limit);
    }

    /**
     * Get events that frequently co-occur with a given event.
     *
     * Useful for "users who did X also did Y" recommendations.
     *
     * @param  string  $eventName  The reference event
     * @param  int  $limit  Maximum results
     * @return list<array{event: string, count: int, correlation: float}>
     */
    public function cooccurringWith(string $eventName, int $limit = 10): array
    {
        $matrix = $this->getMatrix();
        $results = [];

        $row = $matrix[$eventName] ?? [];
        $col = [];

        // Also check column (event appears as second in pair)
        foreach ($matrix as $otherEvent => $otherRow) {
            if (isset($otherRow[$eventName])) {
                $col[$otherEvent] = $otherRow[$eventName];
            }
        }

        // Merge row and column counts
        $allEvents = array_unique(array_merge(array_keys($row), array_keys($col)));

        foreach ($allEvents as $otherEvent) {
            if ($otherEvent === $eventName) {
                continue;
            }

            $count = ($row[$otherEvent] ?? 0) + ($col[$otherEvent] ?? 0);
            $results[] = [
                'event' => $otherEvent,
                'count' => $count,
                'correlation' => $this->calculateCorrelation($eventName, $otherEvent, $matrix),
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Get co-occurrence data for a dashboard widget.
     *
     * Returns a summary with top pairs, event degree (how connected),
     * and cluster detection for event groups.
     *
     * @return array{top_pairs: list<CooccurrencePair>, total_pairs: int, event_degrees: array<string, int>, clusters: list<list<string>>}
     */
    public function dashboardSummary(): array
    {
        $matrix = $this->getMatrix();
        $topPairs = $this->topPairs(10);

        // Calculate event degrees (number of unique co-occurring events)
        $degrees = [];
        foreach ($matrix as $event => $row) {
            $degrees[$event] = count(array_filter($row, fn (int $count): bool => $count > 0));
        }

        // Sort by degree descending
        arsort($degrees);

        // Simple cluster detection: events that co-occur frequently form clusters
        $clusters = $this->detectClusters($matrix, $topPairs);

        return [
            'top_pairs' => $topPairs,
            'total_pairs' => count($topPairs),
            'event_degrees' => $degrees,
            'clusters' => $clusters,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all cached co-occurrence data.
     */
    public function clearCache(): void
    {
        $this->matrix = [];
        $this->sessionEvents = [];

        try {
            $this->cache->forget(self::CACHE_PREFIX . '*');
        } catch (\Throwable) {
            // Cache driver may not support wildcard deletion
        }
    }

    /**
     * Reset in-memory state (useful for testing).
     */
    public function reset(): void
    {
        $this->matrix = [];
        $this->sessionEvents = [];
    }

    /**
     * Increment a co-occurrence pair count.
     */
    private function incrementPair(string $eventA, string $eventB): void
    {
        if (! isset($this->matrix[$eventA])) {
            $this->matrix[$eventA] = [];
        }

        if (! isset($this->matrix[$eventA][$eventB])) {
            $this->matrix[$eventA][$eventB] = 0;
        }

        $this->matrix[$eventA][$eventB]++;
    }

    /**
     * Build co-occurrence matrix from dispatch metrics.
     *
     * When no real session data is available, uses event dispatch counts
     * to infer common co-occurrence patterns based on event category
     * and AARRR classification.
     */
    private function buildFromMetrics(): void
    {
        $dispatched = $this->metrics->dispatchedByProvider();

        if (empty($dispatched)) {
            return;
        }

        // Build synthetic co-occurrence based on event categories
        $events = array_keys($dispatched);

        foreach ($events as $i => $eventA) {
            foreach ($events as $j => $eventB) {
                if ($i >= $j) {
                    continue;
                }

                // Infer co-occurrence based on category relationship
                $coScore = $this->inferCooccurrence($eventA, $eventB, $dispatched);

                if ($coScore > 0) {
                    $this->incrementPair($eventA, $eventB);
                    $this->incrementPair($eventB, $eventA);

                    // Apply co-score as additional counts
                    if ($coScore > 1) {
                        for ($k = 1; $k < $coScore; $k++) {
                            $this->incrementPair($eventA, $eventB);
                            $this->incrementPair($eventB, $eventA);
                        }
                    }
                }
            }
        }
    }

    /**
     * Infer co-occurrence score between two events.
     *
     * Events in the same category or AARRR pillar are more likely to
     * co-occur. Higher dispatch counts also increase the likelihood.
     *
     * @param  string  $eventA
     * @param  string  $eventB
     * @param  array<string, int>  $dispatched
     * @return int  Co-occurrence score (0 = no co-occurrence)
     */
    private function inferCooccurrence(string $eventA, string $eventB, array $dispatched): int
    {
        $catA = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($eventA);
        $catB = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($eventB);

        // Same category = likely co-occurrence
        if ($catA !== null && $catA === $catB) {
            return 1;
        }

        // SaaS events frequently co-occur with engagement events
        if (($catA === 'saas' && $catB === 'engagement') || ($catA === 'engagement' && $catB === 'saas')) {
            return 1;
        }

        // E-commerce events frequently co-occur with each other
        if ($catA === 'ecommerce' && $catB === 'ecommerce') {
            return 1;
        }

        // High-volume events co-occur more
        $countA = $dispatched[$eventA] ?? 0;
        $countB = $dispatched[$eventB] ?? 0;

        if ($countA > 10 && $countB > 10) {
            return 1;
        }

        return 0;
    }

    /**
     * Calculate correlation score between two events.
     *
     * Uses normalized pointwise mutual information (NPMI) approximation
     * based on co-occurrence counts vs. individual counts.
     *
     * @param  string  $eventA
     * @param  string  $eventB
     * @param  CooccurrenceMatrix  $matrix
     * @return float  Correlation score (0.0 to 1.0)
     */
    private function calculateCorrelation(string $eventA, string $eventB, array $matrix): float
    {
        $coCount = ($matrix[$eventA][$eventB] ?? 0) + ($matrix[$eventB][$eventA] ?? 0);

        if ($coCount === 0) {
            return 0.0;
        }

        $countA = array_sum($matrix[$eventA] ?? []);
        $countB = array_sum($matrix[$eventB] ?? []);

        $totalPairs = 0;
        foreach ($matrix as $row) {
            $totalPairs += array_sum($row);
        }

        if ($totalPairs === 0 || $countA === 0 || $countB === 0) {
            return 0.0;
        }

        // Simplified PMI
        $expected = ($countA * $countB) / $totalPairs;
        $pmi = log(max($coCount / $expected, 0.001));

        // Normalize to 0-1 range
        $maxPmi = log(max($totalPairs / max($expected, 1), 0.001));

        return $maxPmi > 0 ? min(1.0, max(0.0, $pmi / $maxPmi)) : 0.0;
    }

    /**
     * Simple cluster detection based on co-occurrence density.
     *
     * Groups events that frequently co-occur with each other into clusters.
     *
     * @param  CooccurrenceMatrix  $matrix
     * @param  list<CooccurrencePair>  $topPairs
     * @return list<list<string>>
     */
    private function detectClusters(array $matrix, array $topPairs): array
    {
        if (empty($topPairs)) {
            return [];
        }

        // Build adjacency list from top pairs
        $adjacency = [];
        foreach ($topPairs as $pair) {
            $a = $pair['event_a'];
            $b = $pair['event_b'];

            if (! isset($adjacency[$a])) {
                $adjacency[$a] = [];
            }
            if (! isset($adjacency[$b])) {
                $adjacency[$b] = [];
            }

            $adjacency[$a][] = $b;
            $adjacency[$b][] = $a;
        }

        // Simple connected components
        $visited = [];
        $clusters = [];

        foreach (array_keys($adjacency) as $node) {
            if (isset($visited[$node])) {
                continue;
            }

            $cluster = [];
            $this->dfs($node, $adjacency, $visited, $cluster);
            $clusters[] = $cluster;
        }

        // Sort clusters by size descending
        usort($clusters, fn (array $a, array $b): int => count($b) <=> count($a));

        return array_slice($clusters, 0, 5);
    }

    /**
     * Depth-first search for connected component detection.
     *
     * @param  string  $node
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, bool>  $visited
     * @param  list<string>  $cluster
     */
    private function dfs(string $node, array $adjacency, array &$visited, array &$cluster): void
    {
        if (isset($visited[$node])) {
            return;
        }

        $visited[$node] = true;
        $cluster[] = $node;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $this->dfs($neighbor, $adjacency, $visited, $cluster);
            }
        }
    }
}
