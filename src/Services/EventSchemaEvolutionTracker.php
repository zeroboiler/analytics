<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Tracks schema evolution of the event catalog to ensure backward-compatible
 * changes when event definitions are added, renamed, or deprecated across
 * versions.  Maintains a registry of catalog snapshots and can detect
 * breaking changes (removed events, changed parameter contracts, renamed
 * categories) between versions.
 *
 * Designed for use in CI pipelines and release gates to prevent silent
 * breakage of provider integrations, dashboard queries, and downstream
 * consumers that depend on stable event names.
 *
 * @since 197.0.0
 */
final class EventSchemaEvolutionTracker
{
    /** @var array<string, CatalogSnapshot> version → snapshot */
    private array $snapshots = [];

    /** @var array<string, EventChange> Registered change log */
    private array $changes = [];

    /** @var list<BreakingChangePolicy> Policies that define what constitutes a breaking change */
    private array $breakingPolicies;

    /** @var string The current version being tracked */
    private string $currentVersion;

    /**
     * @param  list<BreakingChangePolicy>|null  $breakingPolicies  Custom breaking-change policies
     */
    public function __construct(
        ?array $breakingPolicies = null,
    ): void {
        $this->breakingPolicies = $breakingPolicies ?? $this->defaultPolicies();
        $this->currentVersion    = '';
    }

    // ──────────────────────────────────────────────────────────────────
    //  Snapshot Management
    // ──────────────────────────────────────────────────────────────────

    /**
     * Register a catalog snapshot for a given version.
     *
     * @param  string  $version  Semantic version string (e.g. '196.0.0')
     * @param  array<string, list<string>>  $eventsByCategory  category → event names
     * @param  array<string, array{name: string, category: string, params: list<string>}>|null  $eventDetails  Optional per-event detail map
     */
    public function registerSnapshot(
        string $version,
        array $eventsByCategory,
        ?array $eventDetails = null,
    ): void {
        $this->snapshots[$version] = new CatalogSnapshot(
            version:       $version,
            eventsByCategory: $eventsByCategory,
            eventDetails:  $eventDetails ?? [],
        );

        $this->currentVersion = $version;
    }

    /**
     * Take a snapshot from the live event catalogs.
     *
     * @param  string  $version  Version to tag this snapshot with
     */
    public function snapshotFromCatalogs(string $version): void
    {
        $eventsByCategory = [
            'ecommerce'  => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names(),
            'saas'       => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names(),
            'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names(),
        ];

        $this->registerSnapshot($version, $eventsByCategory);
    }

    /**
     * Get a registered snapshot by version.
     *
     * @return CatalogSnapshot|null
     */
    public function getSnapshot(string $version): ?CatalogSnapshot
    {
        return $this->snapshots[$version] ?? null;
    }

    /**
     * Get all registered snapshot versions.
     *
     * @return list<string>
     */
    public function snapshotVersions(): array
    {
        return array_keys($this->snapshots);
    }

    /**
     * Get the latest registered snapshot.
     *
     * @return CatalogSnapshot|null
     */
    public function latestSnapshot(): ?CatalogSnapshot
    {
        if ($this->currentVersion === '' || ! isset($this->snapshots[$this->currentVersion])) {
            return null;
        }

        return $this->snapshots[$this->currentVersion];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Evolution Analysis
    // ──────────────────────────────────────────────────────────────────

    /**
     * Compare two versions and produce a list of changes.
     *
     * @param  string  $fromVersion  Older version
     * @param  string  $toVersion    Newer version
     * @return list<EventChange>
     */
    public function diff(string $fromVersion, string $toVersion): array
    {
        $fromSnap = $this->getSnapshot($fromVersion);
        $toSnap   = $this->getSnapshot($toVersion);

        if ($fromSnap === null || $toSnap === null) {
            return [];
        }

        $changes = [];

        // Detect added events
        $added = $toSnap->eventsNotIn($fromSnap);
        foreach ($added as $event) {
            $changes[] = new EventChange(
                type:      'added',
                eventName: $event->name,
                category:  $event->category,
                fromVersion: $fromVersion,
                toVersion:   $toVersion,
            );
        }

        // Detect removed events
        $removed = $fromSnap->eventsNotIn($toSnap);
        foreach ($removed as $event) {
            $changes[] = new EventChange(
                type:      'removed',
                eventName: $event->name,
                category:  $event->category,
                fromVersion: $fromVersion,
                toVersion:   $toVersion,
            );
        }

        // Detect category changes (event moved to a different category)
        foreach ($fromSnap->allEventNames() as $name) {
            $oldCat = $fromSnap->categoryFor($name);
            $newCat = $toSnap->categoryFor($name);

            if ($oldCat !== null && $newCat !== null && $oldCat !== $newCat) {
                $changes[] = new EventChange(
                    type:      'category_changed',
                    eventName: $name,
                    category:  $oldCat . ' → ' . $newCat,
                    fromVersion: $fromVersion,
                    toVersion:   $toVersion,
                );
            }
        }

        // Detect new categories
        $oldCategories = array_keys($fromSnap->eventsByCategory);
        $newCategories = array_keys($toSnap->eventsByCategory);
        foreach (array_diff($newCategories, $oldCategories) as $newCat) {
            $changes[] = new EventChange(
                type:      'category_added',
                eventName: $newCat,
                category:  $newCat,
                fromVersion: $fromVersion,
                toVersion:   $toVersion,
            );
        }

        // Detect removed categories
        foreach (array_diff($oldCategories, $newCategories) as $removedCat) {
            $changes[] = new EventChange(
                type:      'category_removed',
                eventName: $removedCat,
                category:  $removedCat,
                fromVersion: $fromVersion,
                toVersion:   $toVersion,
            );
        }

        $this->changes = array_merge($this->changes, $changes);

        return $changes;
    }

    /**
     * Check whether the diff between two versions contains breaking changes
     * according to the configured policies.
     *
     * @param  string  $fromVersion  Older version
     * @param  string  $toVersion    Newer version
     * @return EvolutionReport
     */
    public function analyze(string $fromVersion, string $toVersion): EvolutionReport
    {
        $changes = $this->diff($fromVersion, $toVersion);

        $breaking = [];
        $nonBreaking = [];

        foreach ($changes as $change) {
            if ($this->isBreaking($change)) {
                $breaking[] = $change;
            } else {
                $nonBreaking[] = $change;
            }
        }

        return new EvolutionReport(
            fromVersion: $fromVersion,
            toVersion:   $toVersion,
            changes:     $changes,
            breaking:    $breaking,
            nonBreaking: $nonBreaking,
            isBreaking:  count($breaking) > 0,
        );
    }

    /**
     * Check whether a single change is breaking per policy rules.
     */
    public function isBreaking(EventChange $change): bool
    {
        foreach ($this->breakingPolicies as $policy) {
            if ($policy->changeType === $change->type && $policy->breaking) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all accumulated changes.
     *
     * @return list<EventChange>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internal
    // ──────────────────────────────────────────────────────────────────

    /**
     * Default breaking-change policies.
     *
     * Event removals and category removals are breaking by default.
     * Additions are non-breaking (consumers ignore unknown events).
     *
     * @return list<BreakingChangePolicy>
     */
    private function defaultPolicies(): array
    {
        return [
            new BreakingChangePolicy(changeType: 'removed', breaking: true, description: 'Event removed from catalog — downstream consumers will lose data'),
            new BreakingChangePolicy(changeType: 'category_removed', breaking: true, description: 'Entire category removed — all events in it become unresolvable'),
            new BreakingChangePolicy(changeType: 'category_changed', breaking: false, description: 'Event moved to different category — may affect dashboard groupings'),
            new BreakingChangePolicy(changeType: 'added', breaking: false, description: 'New event added — safe, consumers ignore unknown events'),
            new BreakingChangePolicy(changeType: 'category_added', breaking: false, description: 'New category added — safe, consumers ignore unknown categories'),
        ];
    }
}

/**
 * Immutable snapshot of the event catalog at a point in time.
 *
 * @since 197.0.0
 */
final readonly class CatalogSnapshot
{
    /**
     * @param  string  $version  The version this snapshot represents
     * @param  array<string, list<string>>  $eventsByCategory  category → event names
     * @param  array<string, array{name: string, category: string, params: list<string>}>  $eventDetails  Per-event details
     */
    public function __construct(
        public string $version,
        public array $eventsByCategory,
        public array $eventDetails,
    ): void {}

    /**
     * Get all event names across all categories.
     *
     * @return list<string>
     */
    public function allEventNames(): array
    {
        $names = [];

        foreach ($this->eventsByCategory as $events) {
            foreach ($events as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Total event count across all categories.
     */
    public function totalEvents(): int
    {
        return count($this->allEventNames());
    }

    /**
     * Find which category an event belongs to.
     */
    public function categoryFor(string $eventName): ?string
    {
        foreach ($this->eventsByCategory as $category => $events) {
            if (in_array($eventName, $events, true)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Get events present in this snapshot but not in another.
     *
     * @return list<EventReference>
     */
    public function eventsNotIn(self $other): array
    {
        $otherNames = [];
        foreach ($other->eventsByCategory as $events) {
            foreach ($events as $name) {
                $otherNames[$name] = true;
            }
        }

        $diff = [];

        foreach ($this->eventsByCategory as $category => $events) {
            foreach ($events as $name) {
                if (! isset($otherNames[$name])) {
                    $diff[] = new EventReference(name: $name, category: $category);
                }
            }
        }

        return $diff;
    }

    /**
     * Get the number of categories.
     */
    public function categoryCount(): int
    {
        return count($this->eventsByCategory);
    }
}

/**
 * A reference to an event within a specific category.
 *
 * @since 197.0.0
 */
final readonly class EventReference
{
    public function __construct(
        public string $name,
        public string $category,
    ): void {}
}

/**
 * A single change between two catalog versions.
 *
 * @since 197.0.0
 */
final readonly class EventChange
{
    /**
     * @param  'added'|'removed'|'category_changed'|'category_added'|'category_removed'  $type
     */
    public function __construct(
        public string $type,
        public string $eventName,
        public string $category,
        public string $fromVersion,
        public string $toVersion,
    ): void {}

    /**
     * Check if this change type is additive (safe for consumers).
     */
    public function isAdditive(): bool
    {
        return in_array($this->type, ['added', 'category_added'], true);
    }

    /**
     * Check if this change type is destructive.
     */
    public function isDestructive(): bool
    {
        return in_array($this->type, ['removed', 'category_removed'], true);
    }
}

/**
 * Policy definition for what constitutes a breaking change.
 *
 * @since 197.0.0
 */
final readonly class BreakingChangePolicy
{
    public function __construct(
        public string $changeType,
        public bool $breaking,
        public string $description,
    ): void {}
}

/**
 * Complete evolution analysis report between two versions.
 *
 * @since 197.0.0
 */
final readonly class EvolutionReport
{
    /**
     * @param  string  $fromVersion
     * @param  string  $toVersion
     * @param  list<EventChange>  $changes
     * @param  list<EventChange>  $breaking
     * @param  list<EventChange>  $nonBreaking
     */
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
        public array $changes,
        public array $breaking,
        public array $nonBreaking,
        public bool $isBreaking,
    ): void {}

    /**
     * Get a human-readable summary of the evolution report.
     */
    public function summary(): string
    {
        $lines   = [];
        $added   = array_filter($this->changes, fn (EventChange $c): bool => $c->type === 'added');
        $removed = array_filter($this->changes, fn (EventChange $c): bool => $c->type === 'removed');

        $lines[] = sprintf(
            'Event Schema Evolution: %s → %s',
            $this->fromVersion,
            $this->toVersion,
        );

        $lines[] = sprintf('  Total changes: %d', count($this->changes));
        $lines[] = sprintf('  Added: %d', count($added));
        $lines[] = sprintf('  Removed: %d', count($removed));
        $lines[] = sprintf('  Breaking: %s', $this->isBreaking ? 'YES ⚠️' : 'NO ✓');

        if ($this->isBreaking) {
            $lines[] = '  Breaking changes:';
            foreach ($this->breaking as $change) {
                $lines[] = sprintf('    - [%s] %s (%s)', $change->type, $change->eventName, $change->category);
            }
        }

        return implode("\n", $lines);
    }
}
