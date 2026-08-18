<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event catalog diff service — tracks and compares event catalog snapshots.
 *
 * Computes differences between catalog versions (added, removed, renamed events)
 * by comparing event name sets. Supports snapshot persistence via cache, allowing
 * CI/CD pipelines to detect catalog regressions (accidental event removals).
 *
 * Usage:
 *   $diff = $service->diff();          // Compare current vs last snapshot
 *   $diff = $service->diffAgainst($previousSnapshot);  // Explicit comparison
 *   $service->takeSnapshot();          // Save current catalog as baseline
 *
 * @since 243.0.0
 */
final class EventCatalogDiffService
{
    private const CACHE_KEY = 'zb_analytics_catalog_snapshot';
    private const CACHE_TTL = 86400 * 90; // 90 days

    /** @var array<string, array{name: string, category: string, class: class-string<AnalyticsEvent>}> */
    private array $currentCatalog;

    private CacheRepository $cache;

    private string $cacheKey;

    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  string  $cacheKey  Override cache key for multi-environment support
     * @param  int  $cacheTtl  Snapshot TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        string $cacheKey = self::CACHE_KEY,
        int $cacheTtl = self::CACHE_TTL,
    ): void {
        $this->cache = $cache;
        $this->cacheKey = $cacheKey;
        $this->cacheTtl = $cacheTtl;
        $this->currentCatalog = $this->buildIndex();
    }

    /**
     * Take a snapshot of the current event catalog.
     *
     * Stores the catalog index (event names + categories) in cache.
     * Use before deploying a new version to detect regressions later.
     *
     * @return array{stored: bool, event_count: int, categories: int}
     */
    public function takeSnapshot(): array
    {
        $stored = $this->cache->put($this->cacheKey, $this->currentCatalog, $this->cacheTtl);

        return [
            'stored' => $stored,
            'event_count' => count($this->currentCatalog),
            'categories' => count(array_unique(array_column($this->currentCatalog, 'category'))),
        ];
    }

    /**
     * Check if a previous snapshot exists.
     */
    public function hasSnapshot(): bool
    {
        return $this->cache->has($this->cacheKey);
    }

    /**
     * Retrieve the stored snapshot.
     *
     * @return array<string, array{name: string, category: string, class: class-string<AnalyticsEvent>}>|null
     */
    public function getSnapshot(): ?array
    {
        /** @var array<string, array{name: string, category: string, class: class-string<AnalyticsEvent>}>|null $snapshot */
        $snapshot = $this->cache->get($this->cacheKey);

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Delete the stored snapshot.
     */
    public function clearSnapshot(): bool
    {
        return $this->cache->forget($this->cacheKey);
    }

    /**
     * Compute diff between current catalog and stored snapshot.
     *
     * If no snapshot exists, returns a "no baseline" result with all
     * current events listed as "added".
     *
     * @return array{has_baseline: bool, added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, unchanged_count: int, category_changes: list<array{event: string, from: string, to: string}>}
     */
    public function diff(): array
    {
        $baseline = $this->getSnapshot();

        if ($baseline === null) {
            return [
                'has_baseline' => false,
                'added' => array_keys($this->currentCatalog),
                'removed' => [],
                'renamed' => [],
                'unchanged_count' => 0,
                'category_changes' => [],
            ];
        }

        return $this->computeDiff($baseline);
    }

    /**
     * Compute diff against an explicit catalog snapshot.
     *
     * @param  array<string, array{name: string, category: string}>  $previousCatalog
     * @return array{has_baseline: bool, added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, unchanged_count: int, category_changes: list<array{event: string, from: string, to: string}>}
     */
    public function diffAgainst(array $previousCatalog): array
    {
        return $this->computeDiff($previousCatalog);
    }

    /**
     * Get the current catalog index.
     *
     * @return array<string, array{name: string, category: string, class: class-string<AnalyticsEvent>}>
     */
    public function currentCatalog(): array
    {
        return $this->currentCatalog;
    }

    /**
     * Get per-category event counts.
     *
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = [];

        foreach ($this->currentCatalog as $entry) {
            $cat = $entry['category'];
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Check if the catalog has changed since the last snapshot.
     */
    public function hasChanged(): bool
    {
        $diff = $this->diff();

        return count($diff['added']) > 0 || count($diff['removed']) > 0;
    }

    /**
     * Build an index of all current catalog events.
     *
     * @return array<string, array{name: string, category: string, class: class-string<AnalyticsEvent>}>
     */
    private function buildIndex(): array
    {
        $index = [];
        $all = EventCatalog::all();

        foreach ($all as $eventName => $entry) {
            $index[$eventName] = [
                'name' => $eventName,
                'category' => $entry['category'] ?? 'unknown',
                'class' => $entry['class'],
            ];
        }

        ksort($index);

        return $index;
    }

    /**
     * Core diff computation between two catalog indexes.
     *
     * Heuristic rename detection: if event X was removed and event Y was added,
     * and Y contains X as a substring (or vice versa), it's flagged as a rename.
     *
     * @param  array<string, array{name: string, category: string}>  $previous
     * @return array{has_baseline: bool, added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, unchanged_count: int, category_changes: list<array{event: string, from: string, to: string}>}
     */
    private function computeDiff(array $previous): array
    {
        $prevNames = array_keys($previous);
        $currNames = array_keys($this->currentCatalog);

        $added = array_values(array_diff($currNames, $prevNames));
        $removed = array_values(array_diff($prevNames, $currNames));
        $unchanged = array_values(array_intersect($currNames, $prevNames));

        // Heuristic rename detection
        $renamed = $this->detectRenames($removed, $added);

        // Remove renamed events from added/removed lists
        $renamedFrom = array_column($renamed, 'from');
        $renamedTo = array_column($renamed, 'to');
        $added = array_values(array_diff($added, $renamedTo));
        $removed = array_values(array_diff($removed, $renamedFrom));

        // Detect category changes for unchanged events
        $categoryChanges = $this->detectCategoryChanges($previous);

        return [
            'has_baseline' => true,
            'added' => $added,
            'removed' => $removed,
            'renamed' => $renamed,
            'unchanged_count' => count($unchanged),
            'category_changes' => $categoryChanges,
        ];
    }

    /**
     * Detect potential renames between removed and added events.
     *
     * Uses Levenshtein distance heuristic: events within distance 3
     * of each other are flagged as potential renames.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     * @return list<array{from: string, to: string}>
     */
    private function detectRenames(array $removed, array $added): array
    {
        $renamed = [];
        $usedAdded = [];

        foreach ($removed as $removedEvent) {
            $bestMatch = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($added as $addedEvent) {
                if (in_array($addedEvent, $usedAdded, true)) {
                    continue;
                }

                $distance = $this->levenshteinDistance($removedEvent, $addedEvent);

                if ($distance <= 3 && $distance < $bestDistance) {
                    $bestMatch = $addedEvent;
                    $bestDistance = $distance;
                }
            }

            if ($bestMatch !== null) {
                $renamed[] = ['from' => $removedEvent, 'to' => $bestMatch];
                $usedAdded[] = $bestMatch;
            }
        }

        return $renamed;
    }

    /**
     * Detect events that changed categories between snapshots.
     *
     * @param  array<string, array{name: string, category: string}>  $previous
     * @return list<array{event: string, from: string, to: string}>
     */
    private function detectCategoryChanges(array $previous): array
    {
        $changes = [];

        foreach ($this->currentCatalog as $name => $current) {
            if (!isset($previous[$name])) {
                continue;
            }

            $prevCategory = $previous[$name]['category'];
            $currCategory = $current['category'];

            if ($prevCategory !== $currCategory) {
                $changes[] = [
                    'event' => $name,
                    'from' => $prevCategory,
                    'to' => $currCategory,
                ];
            }
        }

        return $changes;
    }

    /**
     * Compute Levenshtein distance between two strings.
     *
     * Inline implementation to avoid dependency on ext-mbstring.
     */
    private function levenshteinDistance(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $prev = range(0, $lenB);

        for ($i = 1; $i <= $lenA; $i++) {
            $curr = [$i];

            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $curr[$j] = min(
                    $curr[$j - 1] + 1,      // insert
                    $prev[$j] + 1,           // delete
                    $prev[$j - 1] + $cost,   // replace
                );
            }

            $prev = $curr;
        }

        return $prev[$lenB];
    }
}
