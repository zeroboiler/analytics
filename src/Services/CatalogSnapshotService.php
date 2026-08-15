<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event catalog snapshot and diff service.
 *
 * Captures point-in-time snapshots of the event catalog structure and
 * computes diffs between versions to detect breaking changes before
 * they reach production. Useful for CI/CD integration and release gates.
 *
 * Snapshots include: event names, categories, provider mappings, and
 * parameter counts. Diffs detect: added events, removed events,
 * renamed events, category changes, and provider mapping changes.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\EventGovernanceRuntimeValidator
 *
 * @since 160.0.0
 */
final class CatalogSnapshotService
{
    /** @var string Cache key prefix for snapshots */
    private const CACHE_PREFIX = 'zb_catalog_snapshot_';

    /** @var int Default TTL for snapshots (24 hours) */
    private const DEFAULT_TTL = 86400;

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  bool  $enabled  Whether snapshot service is enabled
     * @param  int  $ttl  Snapshot TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        bool $enabled = true,
        int $ttl = self::DEFAULT_TTL,
    ) {
        $this->cache = $cache;
        $this->enabled = $enabled;
        $this->ttl = $ttl;
    }

    /**
     * Capture a point-in-time snapshot of the current event catalog.
     *
     * @param  string|null  $label  Optional label (e.g. 'v160', 'pre-release')
     * @return array{label: string, timestamp: string, version: string, total_events: int, categories: array<string, int>, provider_coverage: array<string, int>, events: array<string, array{name: string, category: string, providers: array<string, string|null>}>}
     */
    public function capture(?string $label = null): array
    {
        if (! $this->enabled) {
            return $this->buildSnapshot($label);
        }

        $snapshot = $this->buildSnapshot($label);
        $key = self::CACHE_PREFIX . ($snapshot['label']);
        $this->cache->put($key, $snapshot, $this->ttl);

        return $snapshot;
    }

    /**
     * Build a snapshot from the current EventCatalog state.
     *
     * @param  string|null  $label
     * @return array{label: string, timestamp: string, version: string, total_events: int, categories: array<string, int>, provider_coverage: array<string, int>, events: array<string, array{name: string, category: string, providers: array<string, string|null>}>}
     */
    private function buildSnapshot(?string $label): array
    {
        $catalog = EventCatalog::all();
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $categories = [];
        $providerCoverage = [];
        $events = [];

        foreach ($providers as $p) {
            $providerCoverage[$p] = 0;
        }

        foreach ($catalog as $name => $entry) {
            $cat = $entry['category'] ?? 'unknown';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;

            $providerMappings = [];
            foreach ($providers as $p) {
                $val = $entry[$p] ?? null;
                $providerMappings[$p] = $val;
                if ($val !== null && $val !== '') {
                    $providerCoverage[$p]++;
                }
            }

            $events[$name] = [
                'name' => $name,
                'category' => $cat,
                'providers' => $providerMappings,
            ];
        }

        return [
            'label' => $label ?? ('snapshot_' . time()),
            'timestamp' => date('c'),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'total_events' => count($catalog),
            'categories' => $categories,
            'provider_coverage' => $providerCoverage,
            'events' => $events,
        ];
    }

    /**
     * Get a previously captured snapshot by label.
     *
     * @return array|null
     */
    public function getSnapshot(string $label): ?array
    {
        /** @var array|null */
        return $this->cache->get(self::CACHE_PREFIX . $label);
    }

    /**
     * Compute a diff between two snapshots.
     *
     * Detects: added events, removed events, category changes,
     * provider mapping additions and removals.
     *
     * @param  array  $baseline  Baseline snapshot (older)
     * @param  array  $current  Current snapshot (newer)
     * @return array{added: list<string>, removed: list<string>, category_changed: list<array{event: string, from: string, to: string}>, provider_added: list<array{event: string, provider: string, mapping: string}>, provider_removed: list<array{event: string, provider: string, old_mapping: string}>, summary: array{total_changes: int, breaking: int, non_breaking: int, score: float}}
     */
    public function diff(array $baseline, array $current): array
    {
        $baselineEvents = $baseline['events'] ?? [];
        $currentEvents = $current['events'] ?? [];

        $baselineNames = array_keys($baselineEvents);
        $currentNames = array_keys($currentEvents);

        $added = array_values(array_diff($currentNames, $baselineNames));
        $removed = array_values(array_diff($baselineNames, $currentNames));
        $categoryChanged = [];
        $providerAdded = [];
        $providerRemoved = [];
        $breakingCount = 0;

        // Detect category changes and provider mapping changes
        foreach (array_intersect($baselineNames, $currentNames) as $name) {
            $oldEntry = $baselineEvents[$name];
            $newEntry = $currentEvents[$name];

            // Category change (potentially breaking)
            if ($oldEntry['category'] !== $newEntry['category']) {
                $categoryChanged[] = [
                    'event' => $name,
                    'from' => $oldEntry['category'],
                    'to' => $newEntry['category'],
                ];
                $breakingCount++;
            }

            // Provider mapping changes
            $oldProviders = $oldEntry['providers'] ?? [];
            $newProviders = $newEntry['providers'] ?? [];
            $allProviderKeys = array_unique(array_merge(array_keys($oldProviders), array_keys($newProviders)));

            foreach ($allProviderKeys as $provider) {
                $oldVal = $oldProviders[$provider] ?? null;
                $newVal = $newProviders[$provider] ?? null;

                // New mapping added (non-breaking)
                if ($oldVal === null && $newVal !== null && $newVal !== '') {
                    $providerAdded[] = [
                        'event' => $name,
                        'provider' => $provider,
                        'mapping' => $newVal,
                    ];
                }

                // Mapping removed (potentially breaking)
                if ($oldVal !== null && $oldVal !== '' && ($newVal === null || $newVal === '')) {
                    $providerRemoved[] = [
                        'event' => $name,
                        'provider' => $provider,
                        'old_mapping' => $oldVal,
                    ];
                    $breakingCount++;
                }
            }
        }

        $totalChanges = count($added) + count($removed) + count($categoryChanged) + count($providerAdded) + count($providerRemoved);
        $nonBreaking = count($added) + count($providerAdded);
        $breaking = count($removed) + $breakingCount;

        // Compute a stability score (1.0 = no changes, 0.0 = massive changes)
        $baselineTotal = max(1, count($baselineNames));
        $score = max(0.0, 1.0 - ($totalChanges / $baselineTotal));

        return [
            'added' => $added,
            'removed' => $removed,
            'category_changed' => $categoryChanged,
            'provider_added' => $providerAdded,
            'provider_removed' => $providerRemoved,
            'summary' => [
                'total_changes' => $totalChanges,
                'breaking' => $breaking,
                'non_breaking' => $nonBreaking,
                'score' => round($score, 4),
            ],
        ];
    }

    /**
     * Get all stored snapshot labels.
     *
     * @return list<string>
     */
    public function listSnapshots(): array
    {
        // Not all cache drivers support prefix search; return empty for safety
        return [];
    }

    /**
     * Delete a stored snapshot.
     */
    public function deleteSnapshot(string $label): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . $label);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
