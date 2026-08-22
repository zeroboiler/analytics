<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event catalog schema snapshot and diff detection service.
 *
 * Captures point-in-time snapshots of the analytics event catalog structure
 * (event names, categories, provider mappings, required fields) and computes
 * diffs between versions to detect breaking changes before they reach production.
 *
 * Breaking changes detected:
 *   - Event removal (event existed in baseline but is missing now)
 *   - Category change (event moved to a different category)
 *   - Provider mapping removal (GA4/Meta/PostHog mapping went from non-null to null)
 *   - Event class change (underlying DTO class was swapped)
 *
 * Non-breaking changes tracked:
 *   - New event additions
 *   - New provider mappings (null → non-null)
 *   - Catalog count changes
 *
 * Snapshots are cached per version and can be exported as JSON for CI integration.
 *
 * Configuration: `zeroboiler.analytics.schema_snapshot`
 *
 * @since 142.0.0
 */
final class EventCatalogSchemaSnapshotService
{
    /** @var string Cache key prefix for snapshots */
    private const CACHE_PREFIX = 'zb_catalog_snapshot_';

    /** @var string Cache key for the baseline snapshot label */
    private const BASELINE_KEY = 'zb_catalog_baseline_label';

    /** @var int Snapshot cache TTL (7 days) */
    private const CACHE_TTL = 604800;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $autoSnapshot;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $snapshotConfig = $config->get('zeroboiler.analytics.schema_snapshot', []);
        /** @var array{auto_snapshot?: bool} $snapshotConfig */
        $this->autoSnapshot = (bool) ($snapshotConfig['auto_snapshot'] ?? true);
    }

    /**
     * Capture a snapshot of the current event catalog.
     *
     * Records event names, categories, provider mappings, and class references
     * for later comparison. Automatically sets as baseline if none exists.
     *
     * @param  string|null  $label  Optional label (defaults to package version)
     * @return array{label: string, timestamp: string, version: string, total_events: int, categories: array<string, int>, snapshot_hash: string}
     */
    public function capture(?string $label = null): array
    {
        $label = $label ?? AnalyticsEvent::VERSION;
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $allEvents = EventCatalog::all();
        $byCategory = EventCatalog::byCategory();

        $snapshot = [
            'label' => $label,
            'timestamp' => $timestamp,
            'version' => AnalyticsEvent::VERSION,
            'total_events' => count($allEvents),
            'categories' => array_map(
                fn (array $events): int => count($events),
                $byCategory,
            ),
            'events' => [],
        ];

        foreach ($allEvents as $name => $entry) {
            $snapshot['events'][$name] = [
                'category' => $entry['category'] ?? null,
                'ga4' => $entry['ga4'] ?? null,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? null,
                'plausible' => $entry['plausible'] ?? null,
                'mixpanel' => $entry['mixpanel'] ?? null,
                'amplitude' => $entry['amplitude'] ?? null,
                'tiktok' => $entry['tiktok'] ?? null,
                'linkedin' => $entry['linkedin'] ?? null,
                'class' => $entry['class'] ?? null,
            ];
        }

        $snapshotHash = $this->computeHash($snapshot);

        // Store snapshot
        $this->cache->put(
            self::CACHE_PREFIX . $label,
            ['snapshot' => $snapshot, 'hash' => $snapshotHash],
            self::CACHE_TTL,
        );

        // Set baseline if this is the first snapshot
        $existingBaseline = $this->cache->get(self::BASELINE_KEY);
        if ($existingBaseline === null) {
            $this->cache->put(self::BASELINE_KEY, $label, self::CACHE_TTL);
        }

        return [
            'label' => $label,
            'timestamp' => $timestamp,
            'version' => AnalyticsEvent::VERSION,
            'total_events' => count($allEvents),
            'categories' => array_map(
                fn (array $events): int => count($events),
                $byCategory,
            ),
            'snapshot_hash' => $snapshotHash,
        ];
    }

    /**
     * Compare the current catalog against a baseline snapshot.
     *
     * Computes breaking changes, non-breaking changes, and summary statistics.
     * If no baseline exists, captures one and returns an empty diff.
     *
     * @param  string|null  $baselineLabel  Specific baseline label, or null for auto-detected
     * @return array{baseline: string|null, current: string, breaking: list<array{type: string, event: string, detail: string}>, non_breaking: list<array{type: string, event: string, detail: string}>, summary: array{total_events_before: int, total_events_after: int, added: int, removed: int, modified: int, breaking_count: int}}
     */
    public function diffAgainstBaseline(?string $baselineLabel = null): array
    {
        $baselineLabel = $baselineLabel ?? $this->cache->get(self::BASELINE_KEY);

        if ($baselineLabel === null) {
            // No baseline — capture and return empty diff
            $capture = $this->capture();
            $baselineLabel = $capture['label'];
        }

        /** @var array{snapshot: array, hash: string}|null $cached */
        $cached = $this->cache->get(self::CACHE_PREFIX . $baselineLabel);

        if ($cached === null) {
            return $this->emptyDiff($baselineLabel);
        }

        $baseline = $cached['snapshot'];
        $baselineEvents = $baseline['events'] ?? [];

        // Build current snapshot on-the-fly
        $currentEvents = [];
        $allEvents = EventCatalog::all();
        foreach ($allEvents as $name => $entry) {
            $currentEvents[$name] = [
                'category' => $entry['category'] ?? null,
                'ga4' => $entry['ga4'] ?? null,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? null,
                'plausible' => $entry['plausible'] ?? null,
                'mixpanel' => $entry['mixpanel'] ?? null,
                'amplitude' => $entry['amplitude'] ?? null,
                'tiktok' => $entry['tiktok'] ?? null,
                'linkedin' => $entry['linkedin'] ?? null,
                'class' => $entry['class'] ?? null,
            ];
        }

        return $this->computeDiff(
            $baselineLabel,
            $baselineEvents,
            AnalyticsEvent::VERSION,
            $currentEvents,
        );
    }

    /**
     * Compare two specific snapshot labels.
     *
     * @param  string  $fromLabel  Earlier snapshot label
     * @param  string  $toLabel  Later snapshot label
     * @return array{baseline: string, current: string, breaking: list<array{type: string, event: string, detail: string}>, non_breaking: list<array{type: string, event: string, detail: string}>, summary: array{total_events_before: int, total_events_after: int, added: int, removed: int, modified: int, breaking_count: int}}
     */
    public function diff(string $fromLabel, string $toLabel): array
    {
        /** @var array{snapshot: array, hash: string}|null $fromCached */
        $fromCached = $this->cache->get(self::CACHE_PREFIX . $fromLabel);
        /** @var array{snapshot: array, hash: string}|null $toCached */
        $toCached = $this->cache->get(self::CACHE_PREFIX . $toLabel);

        if ($fromCached === null || $toCached === null) {
            return $this->emptyDiff($fromLabel, $toLabel);
        }

        return $this->computeDiff(
            $fromLabel,
            $fromCached['snapshot']['events'] ?? [],
            $toLabel,
            $toCached['snapshot']['events'] ?? [],
        );
    }

    /**
     * Get the current baseline label.
     *
     * @return string|null
     */
    public function getBaselineLabel(): ?string
    {
        /** @var string|null $label */
        $label = $this->cache->get(self::BASELINE_KEY);

        return is_string($label) ? $label : null;
    }

    /**
     * Set a specific snapshot as the baseline for future comparisons.
     *
     * @param  string  $label  Snapshot label to use as baseline
     */
    public function setBaseline(string $label): void
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . $label);

        if ($cached === null) {
            return;
        }

        $this->cache->put(self::BASELINE_KEY, $label, self::CACHE_TTL);
    }

    /**
     * Get all stored snapshot labels.
     *
     * @return list<string>
     */
    public function getSnapshotLabels(): array
    {
        // Since we can't scan cache by prefix in all drivers, return known labels
        $baseline = $this->getBaselineLabel();
        $current = AnalyticsEvent::VERSION;
        $labels = [];

        if ($baseline !== null) {
            $labels[] = $baseline;
        }

        if ($baseline !== $current) {
            $labels[] = $current;
        }

        return array_values(array_unique($labels));
    }

    /**
     * Delete a stored snapshot.
     */
    public function deleteSnapshot(string $label): bool
    {
        $this->cache->forget(self::CACHE_PREFIX . $label);

        // If deleting the baseline, clear it
        if ($this->getBaselineLabel() === $label) {
            $this->cache->forget(self::BASELINE_KEY);
        }

        return true;
    }

    /**
     * Export a snapshot as JSON string.
     *
     * Useful for CI integration and version control of event schemas.
     *
     * @param  string  $label
     * @return string|null  JSON string or null if not found
     */
    public function exportSnapshotJson(string $label): ?string
    {
        /** @var array{snapshot: array, hash: string}|null $cached */
        $cached = $this->cache->get(self::CACHE_PREFIX . $label);

        if ($cached === null) {
            return null;
        }

        return json_encode($cached['snapshot'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Check if auto-snapshot is enabled.
     */
    public function isAutoSnapshotEnabled(): bool
    {
        return $this->autoSnapshot;
    }

    /**
     * Compute the diff between two event maps.
     *
     * @param  string  $fromLabel
     * @param  array<string, array{category: string|null, ga4: string|null, meta: string|null, posthog: string|null, plausible: string|null, mixpanel: string|null, amplitude: string|null, tiktok: string|null, linkedin: string|null, class: string|null}>  $fromEvents
     * @param  string  $toLabel
     * @param  array<string, array{category: string|null, ga4: string|null, meta: string|null, posthog: string|null, plausible: string|null, mixpanel: string|null, amplitude: string|null, tiktok: string|null, linkedin: string|null, class: string|null}>  $toEvents
     * @return array{baseline: string, current: string, breaking: list<array{type: string, event: string, detail: string}>, non_breaking: list<array{type: string, event: string, detail: string}>, summary: array{total_events_before: int, total_events_after: int, added: int, removed: int, modified: int, breaking_count: int}}
     */
    private function computeDiff(
        string $fromLabel,
        array $fromEvents,
        string $toLabel,
        array $toEvents,
    ): array {
        $breaking = [];
        $nonBreaking = [];
        $added = 0;
        $removed = 0;
        $modified = 0;

        $providerKeys = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        // Check for removed events (breaking)
        foreach ($fromEvents as $name => $fromEntry) {
            if (! isset($toEvents[$name])) {
                $breaking[] = [
                    'type' => 'event_removed',
                    'event' => $name,
                    'detail' => "Event '{$name}' was removed from the catalog. Existing client integrations may break.",
                ];
                $removed++;
                continue;
            }

            $toEntry = $toEvents[$name];

            // Check category change (breaking for downstream consumers)
            if ($fromEntry['category'] !== $toEntry['category']) {
                $breaking[] = [
                    'type' => 'category_changed',
                    'event' => $name,
                    'detail' => sprintf(
                        "Event '%s' category changed from '%s' to '%s'.",
                        $name,
                        $fromEntry['category'] ?? 'null',
                        $toEntry['category'] ?? 'null',
                    ),
                ];
            }

            // Check class change (breaking for event constructors)
            if ($fromEntry['class'] !== $toEntry['class']) {
                $breaking[] = [
                    'type' => 'class_changed',
                    'event' => $name,
                    'detail' => sprintf(
                        "Event '%s' class changed from '%s' to '%s'. Event construction may break.",
                        $name,
                        $fromEntry['class'] ?? 'null',
                        $toEntry['class'] ?? 'null',
                    ),
                ];
            }

            // Check provider mapping removals (breaking)
            foreach ($providerKeys as $provider) {
                $fromVal = $fromEntry[$provider] ?? null;
                $toVal = $toEntry[$provider] ?? null;

                if ($fromVal !== null && $toVal === null) {
                    $breaking[] = [
                        'type' => 'provider_mapping_removed',
                        'event' => $name,
                        'detail' => sprintf(
                            "Event '%s' lost its %s provider mapping (was '%s'). Events will no longer be sent to this provider.",
                            $name,
                            $provider,
                            $fromVal,
                        ),
                    ];
                }

                // Provider mapping changed name (potentially breaking)
                if ($fromVal !== null && $toVal !== null && $fromVal !== $toVal) {
                    $breaking[] = [
                        'type' => 'provider_mapping_changed',
                        'event' => $name,
                        'detail' => sprintf(
                            "Event '%s' %s mapping changed from '%s' to '%s'. Provider dashboards may need updating.",
                            $name,
                            $provider,
                            $fromVal,
                            $toVal,
                        ),
                    ];
                }

                // New provider mapping (non-breaking)
                if ($fromVal === null && $toVal !== null) {
                    $nonBreaking[] = [
                        'type' => 'provider_mapping_added',
                        'event' => $name,
                        'detail' => sprintf(
                            "Event '%s' gained %s provider mapping: '%s'.",
                            $name,
                            $provider,
                            $toVal,
                        ),
                    ];
                }
            }

            // Count modified events
            if ($fromEntry !== $toEntry) {
                $modified++;
            }
        }

        // Check for new events (non-breaking)
        foreach ($toEvents as $name => $toEntry) {
            if (! isset($fromEvents[$name])) {
                $nonBreaking[] = [
                    'type' => 'event_added',
                    'event' => $name,
                    'detail' => sprintf(
                        "New event '%s' added in category '%s'.",
                        $name,
                        $toEntry['category'] ?? 'unknown',
                    ),
                ];
                $added++;
            }
        }

        return [
            'baseline' => $fromLabel,
            'current' => $toLabel,
            'breaking' => $breaking,
            'non_breaking' => $nonBreaking,
            'summary' => [
                'total_events_before' => count($fromEvents),
                'total_events_after' => count($toEvents),
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
                'breaking_count' => count($breaking),
            ],
        ];
    }

    /**
     * Return an empty diff structure.
     *
     * @param  string  $baseline
     * @param  string|null  $current
     * @return array{baseline: string, current: string, breaking: list<array{type: string, event: string, detail: string}>, non_breaking: list<array{type: string, event: string, detail: string}>, summary: array{total_events_before: int, total_events_after: int, added: int, removed: int, modified: int, breaking_count: int}}
     */
    private function emptyDiff(string $baseline, ?string $current = null): array
    {
        return [
            'baseline' => $baseline,
            'current' => $current ?? AnalyticsEvent::VERSION,
            'breaking' => [],
            'non_breaking' => [],
            'summary' => [
                'total_events_before' => 0,
                'total_events_after' => 0,
                'added' => 0,
                'removed' => 0,
                'modified' => 0,
                'breaking_count' => 0,
            ],
        ];
    }

    /**
     * Compute a content hash of the snapshot for comparison.
     *
     * @param  array<string, mixed>  $snapshot
     * @return string
     */
    private function computeHash(array $snapshot): string
    {
        // Extract only the event data for hashing (exclude metadata)
        $eventData = $snapshot['events'] ?? [];
        ksort($eventData);

        return hash('xxh128', json_encode($eventData, JSON_THROW_ON_ERROR));
    }
}
