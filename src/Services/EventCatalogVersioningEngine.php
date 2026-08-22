<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\CatalogChangeImpact;
use ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation;
use ZeroBoiler\Analytics\Services\CatalogSnapshotService;

/**
 * Event catalog semantic versioning engine.
 *
 * Analyzes catalog diffs between versions and classifies each change as
 * major (breaking), minor (feature), or patch (non-breaking) according to
 * SemVer 2.0.0 rules. Produces a version bump recommendation and optional
 * auto-generated release notes.
 *
 * Classification rules:
 *
 *   MAJOR (breaking):
 *     - Event removed from catalog (consumers will break)
 *     - Provider mapping removed (events stop reaching a provider)
 *     - Event class changed (internal contract change)
 *
 *   MINOR (feature):
 *     - Event added to catalog (new capability, non-breaking)
 *     - Provider mapping added (event reaches a new provider)
 *
 *   PATCH (non-breaking):
 *     - Category changed (internal reorganization)
 *     - Provider mapping value changed (renamed mapping, non-breaking)
 *
 * Inspired by: SemVer 2.0.0, Conventional Commits, Segment Event Protocol versioning.
 *
 * @see \ZeroBoiler\Analytics\Services\CatalogSnapshotService
 * @see \ZeroBoiler\Analytics\DTO\CatalogChangeImpact
 * @see \ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation
 *
 * @since 216.0.0
 */
final class EventCatalogVersioningEngine
{
    /** @var string Cache key for the latest version recommendation */
    private const CACHE_KEY = 'zb_catalog_versioning_latest';

    /** @var string Cache key prefix for version history */
    private const HISTORY_PREFIX = 'zb_catalog_versioning_history_';

    /** @var int Default cache TTL (7 days) */
    private const DEFAULT_TTL = 604800;

    /** @var int Maximum version history entries to keep */
    private const MAX_HISTORY = 50;

    /** @var array<string, string> Change type to default severity mapping */
    private const SEVERITY_MAP = [
        'event_added' => 'minor',
        'event_removed' => 'major',
        'event_renamed' => 'major',
        'category_changed' => 'patch',
        'provider_mapping_added' => 'minor',
        'provider_mapping_removed' => 'major',
        'provider_mapping_changed' => 'patch',
        'class_changed' => 'major',
    ];

    /** @var list<string> Change types considered breaking */
    private const BREAKING_TYPES = [
        'event_removed',
        'event_renamed',
        'provider_mapping_removed',
        'class_changed',
    ];

    private CacheRepository $cache;

    private CatalogSnapshotService $snapshotService;

    private bool $enabled;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  CatalogSnapshotService  $snapshotService  Catalog snapshot service
     * @param  bool  $enabled  Whether versioning engine is enabled
     * @param  int  $ttl  Cache TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        CatalogSnapshotService $snapshotService,
        bool $enabled = true,
        int $ttl = self::DEFAULT_TTL,
    ){
        $this->cache = $cache;
        $this->snapshotService = $snapshotService;
        $this->enabled = $enabled;
        $this->ttl = $ttl;
    }

    /**
     * Analyze catalog changes between two versions and recommend a version bump.
     *
     * @param  array  $baselineSnapshot  Baseline snapshot (older version)
     * @param  array  $currentSnapshot  Current snapshot (newer version)
     * @param  string|null  $currentVersion  Current version string (defaults to AnalyticsEvent::VERSION)
     * @return CatalogVersionRecommendation
     */
    public function analyze(array $baselineSnapshot, array $currentSnapshot, ?string $currentVersion = null): CatalogVersionRecommendation
    {
        $currentVersion = $currentVersion ?? AnalyticsEvent::VERSION;

        // Compute the raw diff from CatalogSnapshotService
        $diff = $this->snapshotService->diff($baselineSnapshot, $currentSnapshot);

        // If no changes, return no-change recommendation
        $totalChanges = $diff['summary']['total_changes'] ?? 0;
        if ($totalChanges === 0) {
            return CatalogVersionRecommendation::noChange($currentVersion);
        }

        // Classify each change into impacts
        $impacts = $this->classifyChanges($baselineSnapshot, $currentSnapshot, $diff);

        // Compute recommendation
        return $this->computeRecommendation($impacts, $currentVersion);
    }

    /**
     * Analyze the current catalog against the latest stored snapshot.
     *
     * Captures a new snapshot and diffs against the last stored baseline.
     *
     * @param  string|null  $baselineLabel  Label of the baseline snapshot (null = latest)
     * @return CatalogVersionRecommendation
     */
    public function analyzeAgainstBaseline(?string $baselineLabel = null): CatalogVersionRecommendation
    {
        $baseline = $this->findBaselineSnapshot($baselineLabel);

        if ($baseline === null) {
            // No baseline exists — capture first snapshot and return no-change
            $this->snapshotService->capture('baseline_' . AnalyticsEvent::VERSION);

            return CatalogVersionRecommendation::noChange(AnalyticsEvent::VERSION);
        }

        $current = $this->snapshotService->capture('current_' . AnalyticsEvent::VERSION);

        return $this->analyze($baseline, $current);
    }

    /**
     * Classify raw diff changes into typed impacts.
     *
     * @param  array  $baseline
     * @param  array  $current
     * @param  array  $diff
     * @return list<CatalogChangeImpact>
     */
    private function classifyChanges(array $baseline, array $current, array $diff): array
    {
        $impacts = [];
        $baselineEvents = $baseline['events'] ?? [];
        $currentEvents = $current['events'] ?? [];

        // Removed events (major)
        foreach ($diff['removed'] ?? [] as $eventName) {
            $category = $baselineEvents[$eventName]['category'] ?? null;
            $impacts[] = CatalogChangeImpact::major(
                type: 'event_removed',
                eventName: $eventName,
                description: "Event '{$eventName}' removed from catalog. Existing tracking code referencing this event will fail.",
                metadata: ['category' => $category],
            );
        }

        // Added events (minor)
        foreach ($diff['added'] ?? [] as $eventName) {
            $category = $currentEvents[$eventName]['category'] ?? null;
            $impacts[] = CatalogChangeImpact::minor(
                type: 'event_added',
                eventName: $eventName,
                description: "Event '{$eventName}' added to catalog in category '{$category}'.",
                metadata: ['category' => $category],
            );
        }

        // Category changes (patch)
        foreach ($diff['category_changed'] ?? [] as $change) {
            $impacts[] = CatalogChangeImpact::patch(
                type: 'category_changed',
                eventName: $change['event'],
                description: "Event '{$change['event']}' category changed from '{$change['from']}' to '{$change['to']}'.",
                metadata: ['from' => $change['from'], 'to' => $change['to']],
            );
        }

        // Provider mapping removals (major)
        foreach ($diff['provider_removed'] ?? [] as $change) {
            $impacts[] = CatalogChangeImpact::major(
                type: 'provider_mapping_removed',
                eventName: $change['event'],
                description: "Event '{$change['event']}' lost {$change['provider']} mapping ('{$change['old_mapping']}').",
                metadata: ['provider' => $change['provider']],
            );
        }

        // Provider mapping additions (minor)
        foreach ($diff['provider_added'] ?? [] as $change) {
            $impacts[] = CatalogChangeImpact::minor(
                type: 'provider_mapping_added',
                eventName: $change['event'],
                description: "Event '{$change['event']}' gained {$change['provider']} mapping ('{$change['mapping']}').",
                metadata: ['provider' => $change['provider']],
            );
        }

        return $impacts;
    }

    /**
     * Compute the version bump recommendation from classified impacts.
     *
     * @param  list<CatalogChangeImpact>  $impacts
     * @param  string  $currentVersion
     * @return CatalogVersionRecommendation
     */
    private function computeRecommendation(array $impacts, string $currentVersion): CatalogVersionRecommendation
    {
        $summary = ['major' => 0, 'minor' => 0, 'patch' => 0];
        $hasBreaking = false;
        $breakingDescriptions = [];
        $minorDescriptions = [];
        $patchDescriptions = [];

        foreach ($impacts as $impact) {
            $summary[$impact->severity]++;

            if ($impact->breaking) {
                $hasBreaking = true;
                $breakingDescriptions[] = "- **{$impact->type}**: {$impact->description}";
            } elseif ($impact->severity === 'minor') {
                $minorDescriptions[] = "- {$impact->type}: {$impact->description}";
            } else {
                $patchDescriptions[] = "- {$impact->type}: {$impact->description}";
            }
        }

        // Determine recommended version bump
        $recommended = $this->determineBump($summary);

        // Compute next version
        $nextVersion = $this->bumpVersion($currentVersion, $recommended);

        // Build rationale
        $rationale = $this->buildRationale($summary, $hasBreaking);

        // Generate release notes
        $releaseNotes = $this->generateReleaseNotes(
            $currentVersion,
            $nextVersion,
            $breakingDescriptions,
            $minorDescriptions,
            $patchDescriptions,
            $summary,
        );

        $recommendation = new CatalogVersionRecommendation(
            recommended: $recommended,
            currentVersion: $currentVersion,
            nextVersion: $nextVersion,
            changes: $impacts,
            summary: $summary,
            rationale: $rationale,
            hasBreaking: $hasBreaking,
            releaseNotes: $releaseNotes,
        );

        // Cache the recommendation
        $this->cacheRecommendation($recommendation);

        return $recommendation;
    }

    /**
     * Determine the version bump level from severity counts.
     *
     * @param  array{major: int, minor: int, patch: int}  $summary
     * @return string  'major', 'minor', 'patch', or 'none'
     */
    private function determineBump(array $summary): string
    {
        if ($summary['major'] > 0) {
            return 'major';
        }

        if ($summary['minor'] > 0) {
            return 'minor';
        }

        if ($summary['patch'] > 0) {
            return 'patch';
        }

        return 'none';
    }

    /**
     * Bump a version string by the specified level.
     *
     * @param  string  $version  Current version (e.g. '215.0.0')
     * @param  string  $bump  'major', 'minor', or 'patch'
     * @return string  Next version string
     */
    private function bumpVersion(string $version, string $bump): string
    {
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0);

        return match ($bump) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => $version,
        };
    }

    /**
     * Build a human-readable rationale for the version recommendation.
     *
     * @param  array{major: int, minor: int, patch: int}  $summary
     * @param  bool  $hasBreaking
     * @return string
     */
    private function buildRationale(array $summary, bool $hasBreaking): string
    {
        $total = $summary['major'] + $summary['minor'] + $summary['patch'];
        $parts = [];

        if ($summary['major'] > 0) {
            $parts[] = "{$summary['major']} breaking change(s)";
        }
        if ($summary['minor'] > 0) {
            $parts[] = "{$summary['minor']} new event(s)";
        }
        if ($summary['patch'] > 0) {
            $parts[] = "{$summary['patch']} non-breaking change(s)";
        }

        $changeSummary = implode(', ', $parts);

        if ($hasBreaking) {
            return "MAJOR bump recommended: {$changeSummary} detected across {$total} total change(s). Breaking changes require consumer action.";
        }

        if ($summary['minor'] > 0) {
            return "MINOR bump recommended: {$changeSummary} detected across {$total} total change(s). New events are backward-compatible additions.";
        }

        return "PATCH bump recommended: {$changeSummary} detected across {$total} total change(s). Non-breaking internal changes only.";
    }

    /**
     * Generate markdown release notes from the classified changes.
     *
     * @param  string  $fromVersion
     * @param  string  $toVersion
     * @param  list<string>  $breaking
     * @param  list<string>  $minor
     * @param  list<string>  $patch
     * @param  array{major: int, minor: int, patch: int}  $summary
     * @return string
     */
    private function generateReleaseNotes(
        string $fromVersion,
        string $toVersion,
        array $breaking,
        array $minor,
        array $patch,
        array $summary,
    ): string {
        $lines = [];
        $lines[] = "# Event Catalog Changes: {$fromVersion} → {$toVersion}";
        $lines[] = '';
        $totalChanges = $summary['major'] + $summary['minor'] + $summary['patch'];
        $lines[] = "**Total changes:** {$totalChanges}";
        $lines[] = '';

        if ($breaking !== []) {
            $lines[] = '## ⚠️ Breaking Changes';
            $lines[] = '';
            foreach ($breaking as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if ($minor !== []) {
            $lines[] = '## ✨ New Events';
            $lines[] = '';
            foreach ($minor as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if ($patch !== []) {
            $lines[] = '## 🔧 Non-Breaking Changes';
            $lines[] = '';
            foreach ($patch as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Find the baseline snapshot to compare against.
     *
     * @param  string|null  $label  Specific label, or null for latest
     * @return array|null
     */
    private function findBaselineSnapshot(?string $label): ?array
    {
        if ($label !== null) {
            return $this->snapshotService->getSnapshot($label);
        }

        // Look for the most recent baseline snapshot
        foreach (['baseline_' . AnalyticsEvent::VERSION, 'baseline'] as $key) {
            $snapshot = $this->snapshotService->getSnapshot($key);
            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * Cache a version recommendation.
     */
    private function cacheRecommendation(CatalogVersionRecommendation $recommendation): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->put(self::CACHE_KEY, $recommendation->toArray(), $this->ttl);

        // Also store in history
        $historyKey = self::HISTORY_PREFIX . $recommendation->nextVersion;
        $this->cache->put($historyKey, $recommendation->toArray(), $this->ttl);
    }

    /**
     * Get the latest cached version recommendation.
     *
     * @return CatalogVersionRecommendation|null
     */
    public function getLatestRecommendation(): ?CatalogVersionRecommendation
    {
        /** @var array<string, mixed>|null */
        $data = $this->cache->get(self::CACHE_KEY);

        if ($data === null) {
            return null;
        }

        return CatalogVersionRecommendation::fromArray($data);
    }

    /**
     * Get version history entries.
     *
     * @param  int  $limit  Maximum entries to return
     * @return list<CatalogVersionRecommendation>
     */
    public function getHistory(int $limit = 10): array
    {
        // Since we can't scan cache keys across all drivers,
        // return the latest recommendation as the only history entry
        $latest = $this->getLatestRecommendation();

        if ($latest === null) {
            return [];
        }

        return [$latest];
    }

    /**
     * Compute a quick severity summary for a catalog diff.
     *
     * Lightweight version that returns only counts, no full classification.
     *
     * @param  array  $diff  Raw diff from CatalogSnapshotService::diff()
     * @return array{major: int, minor: int, patch: int, total: int, has_breaking: bool}
     */
    public function quickSeveritySummary(array $diff): array
    {
        $major = count($diff['removed'] ?? [])
            + count($diff['provider_removed'] ?? []);

        $minor = count($diff['added'] ?? [])
            + count($diff['provider_added'] ?? []);

        $patch = count($diff['category_changed'] ?? []);

        $total = $major + $minor + $patch;

        return [
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
            'total' => $total,
            'has_breaking' => $major > 0,
        ];
    }

    /**
     * Get the list of change types and their default severities.
     *
     * @return array<string, string>
     */
    public static function getSeverityMap(): array
    {
        return self::SEVERITY_MAP;
    }

    /**
     * Get the list of breaking change types.
     *
     * @return list<string>
     */
    public static function getBreakingTypes(): array
    {
        return self::BREAKING_TYPES;
    }

    /**
     * Check if a specific change type is considered breaking.
     */
    public static function isBreakingType(string $type): bool
    {
        return in_array($type, self::BREAKING_TYPES, true);
    }

    /**
     * Get the default severity for a change type.
     */
    public static function getDefaultSeverity(string $type): string
    {
        return self::SEVERITY_MAP[$type] ?? 'patch';
    }
}
