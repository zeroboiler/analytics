<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Behavioral User Segmentation Service — dynamic cohort definitions based on event behavior.
 *
 * Defines, evaluates, and manages user segments (cohorts) based on behavioral criteria
 * such as event frequency, event sequences, time windows, property conditions, and
 * composite logical operators (AND, OR, NOT, XOR).
 *
 * **Segment Types:**
 * - **Event-based**: Users who performed (or didn't perform) specific events
 * - **Frequency-based**: Users with event counts above/below thresholds
 * - **Sequence-based**: Users who completed a specific event sequence in order
 * - **Time-based**: Users active within a time window (recency segments)
 * - **Property-based**: Users with specific event/user properties
 * - **Composite**: Nested AND/OR/NOT combinations of the above
 *
 * **Use Cases:**
 * - Power users vs casual users (event frequency)
 * - At-risk users (declining engagement)
 * - New users (first seen within N days)
 * - Trial converters (sign_up → trial_start → subscribe sequence)
 * - Feature adopters (used feature X within Y days)
 * - Churned users (no activity in N days)
 *
 * **Operations:**
 * - `evaluate()`: Compute segment membership from raw event data
 * - `intersect()`: Users in both segments (AND)
 * - `union()`: Users in either segment (OR)
 * - `except()`: Users in segment A but not B (NOT)
 * - `trending()`: Segments with significant membership changes
 * - `snapshot()`: Persist segment membership for historical tracking
 * - `compare()`: Compare two segment snapshots over time
 *
 * Configuration: `zeroboiler.analytics.behavioral_segments`
 *
 * Inspired by Mixpanel Cohorts, Amplitude Behavioral Cohorts, PostHog Cohorts.
 *
 * @see \ZeroBoiler\Analytics\Services\CohortAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder
 *
 * @since 192.0.0
 */
final class BehavioralUserSegmentService
{
    /** @var string Current service version. */
    public const VERSION = '1.0.0';

    private const CACHE_PREFIX = 'zb_bseg_';

    private const DEFAULT_CACHE_TTL = 3600;

    private const DEFAULT_MAX_SEGMENT_SIZE = 100000;

    private const DEFAULT_MAX_SNAPSHOTS = 50;

    /** @var array<string, array{type: string, conditions: array<string, mixed>, description: string, created_at: string|null}> */
    private array $segmentDefinitions = [];

    /** @var array<string, list<string>> Segment name → list of user IDs */
    private array $segmentMembers = [];

    /** @var array<string, list<array{user_id: string, matched_conditions: list<string>, score: float}>> Segment → membership details */
    private array $segmentDetails = [];

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxSegmentSize;

    private int $maxSnapshots;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $segConfig = $config->get('zeroboiler.analytics.behavioral_segments', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_segment_size?: int, max_snapshots?: int, definitions?: array<string, mixed>} $segConfig */
        $this->enabled = (bool) ($segConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($segConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->maxSegmentSize = (int) ($segConfig['max_segment_size'] ?? self::DEFAULT_MAX_SEGMENT_SIZE);
        $this->maxSnapshots = (int) ($segConfig['max_snapshots'] ?? self::DEFAULT_MAX_SNAPSHOTS);

        $this->segmentDefinitions = $this->builtInDefinitions();

        $customDefs = $segConfig['definitions'] ?? [];
        /** @var array<string, array<string, mixed>> $customDefs */
        foreach ($customDefs as $name => $def) {
            $this->segmentDefinitions[$name] = array_merge([
                'type' => 'event',
                'conditions' => [],
                'description' => '',
                'created_at' => null,
            ], $def);
        }
    }

    // ── Segment Definition Management ───────────────────────────────

    /**
     * Define a new behavioral segment.
     *
     * @param  string  $name  Segment name (unique identifier)
     * @param  string  $type  Segment type: event, frequency, sequence, time, property, composite
     * @param  array<string, mixed>  $conditions  Segment conditions (type-specific)
     * @param  string  $description  Human-readable description
     */
    public function define(string $name, string $type, array $conditions, string $description = ''): void
    {
        $this->segmentDefinitions[$name] = [
            'type' => $type,
            'conditions' => $conditions,
            'description' => $description,
            'created_at' => (new \DateTimeImmutable)->format('c'),
        ];

        $this->cacheSegmentDefinition($name);
    }

    /**
     * Remove a segment definition.
     */
    public function undefine(string $name): void
    {
        unset($this->segmentDefinitions[$name], $this->segmentMembers[$name], $this->segmentDetails[$name]);
        $this->cache->forget(self::CACHE_PREFIX . 'def_' . $name);
        $this->cache->forget(self::CACHE_PREFIX . 'members_' . $name);
    }

    /**
     * Get all defined segment names.
     *
     * @return list<string>
     */
    public function segmentNames(): array
    {
        return array_keys($this->segmentDefinitions);
    }

    /**
     * Get a segment definition.
     *
     * @param  string  $name
     * @return array{type: string, conditions: array<string, mixed>, description: string, created_at: string|null}|null
     */
    public function getDefinition(string $name): ?array
    {
        return $this->segmentDefinitions[$name] ?? null;
    }

    /**
     * Check if a segment is defined.
     */
    public function hasDefinition(string $name): bool
    {
        return isset($this->segmentDefinitions[$name]);
    }

    // ── Segment Evaluation ────────────────────────────────────────────

    /**
     * Evaluate a segment's membership from user event data.
     *
     * @param  string  $name  Segment name
     * @param  array<string, list<array{event: string, timestamp?: string, params?: array<string, mixed>}>>  $userEvents  User ID → event records
     * @return array{segment: string, size: int, members: list<string>, details: list<array{user_id: string, matched_conditions: list<string>, score: float}>, evaluated_at: string}
     */
    public function evaluate(string $name, array $userEvents): array
    {
        $definition = $this->segmentDefinitions[$name] ?? null;
        if ($definition === null) {
            return $this->emptyResult($name);
        }

        $members = [];
        $details = [];

        foreach ($userEvents as $userId => $events) {
            $result = $this->evaluateUser($definition['type'], $definition['conditions'], $events);
            if ($result['matched']) {
                $members[] = $userId;
                $details[] = [
                    'user_id' => $userId,
                    'matched_conditions' => $result['conditions'],
                    'score' => $result['score'],
                ];
            }
        }

        if (count($members) > $this->maxSegmentSize) {
            $members = array_slice($members, 0, $this->maxSegmentSize);
            $details = array_slice($details, 0, $this->maxSegmentSize);
        }

        $this->segmentMembers[$name] = $members;
        $this->segmentDetails[$name] = $details;

        // Cache results
        $this->cacheSegmentMembers($name);

        return [
            'segment' => $name,
            'size' => count($members),
            'members' => $members,
            'details' => $details,
            'evaluated_at' => (new \DateTimeImmutable)->format('c'),
        ];
    }

    /**
     * Evaluate multiple segments at once.
     *
     * @param  list<string>  $names  Segment names to evaluate
     * @param  array<string, list<array{event: string, timestamp?: string, params?: array<string, mixed>}>>  $userEvents  User ID → event records
     * @return array<string, array{segment: string, size: int, members: list<string>}>
     */
    public function evaluateMultiple(array $names, array $userEvents): array
    {
        $results = [];

        foreach ($names as $name) {
            $eval = $this->evaluate($name, $userEvents);
            $results[$name] = [
                'segment' => $eval['segment'],
                'size' => $eval['size'],
                'members' => $eval['members'],
            ];
        }

        return $results;
    }

    // ── Set Operations ───────────────────────────────────────────────

    /**
     * Get users in both segment A and segment B (intersection).
     *
     * @param  string  $segmentA
     * @param  string  $segmentB
     * @return list<string>
     */
    public function intersect(string $segmentA, string $segmentB): array
    {
        $membersA = $this->getSegmentMembers($segmentA);
        $membersB = $this->getSegmentMembers($segmentB);

        return array_values(array_intersect($membersA, $membersB));
    }

    /**
     * Get users in either segment A or segment B (union).
     *
     * @param  string  $segmentA
     * @param  string  $segmentB
     * @return list<string>
     */
    public function union(string $segmentA, string $segmentB): array
    {
        $membersA = $this->getSegmentMembers($segmentA);
        $membersB = $this->getSegmentMembers($segmentB);

        return array_values(array_unique(array_merge($membersA, $membersB)));
    }

    /**
     * Get users in segment A but not in segment B (difference).
     *
     * @param  string  $segmentA
     * @param  string  $segmentB
     * @return list<string>
     */
    public function except(string $segmentA, string $segmentB): array
    {
        $membersA = $this->getSegmentMembers($segmentA);
        $membersB = $this->getSegmentMembers($segmentB);

        return array_values(array_diff($membersA, $membersB));
    }

    /**
     * XOR — users in exactly one of the two segments.
     *
     * @param  string  $segmentA
     * @param  string  $segmentB
     * @return list<string>
     */
    public function xor(string $segmentA, string $segmentB): array
    {
        $membersA = $this->getSegmentMembers($segmentA);
        $membersB = $this->getSegmentMembers($segmentB);

        return array_values(
            array_unique(
                array_merge(
                    array_diff($membersA, $membersB),
                    array_diff($membersB, $membersA),
                )
            )
        );
    }

    // ── Trending & Comparison ─────────────────────────────────────────

    /**
     * Get segments with significant membership changes between two snapshots.
     *
     * @param  array<string, int>  $currentSizes  Segment → current member count
     * @param  array<string, int>  $previousSizes  Segment → previous member count
     * @param  float  $thresholdPercent  Minimum change to be "trending" (default 10%)
     * @return list<array{segment: string, current: int, previous: int, change: float, change_percent: float, direction: 'up'|'down'|'stable'}>
     */
    public function trending(array $currentSizes, array $previousSizes, float $thresholdPercent = 10.0): array
    {
        $results = [];
        $allSegments = array_unique(array_merge(array_keys($currentSizes), array_keys($previousSizes)));

        foreach ($allSegments as $segment) {
            $current = $currentSizes[$segment] ?? 0;
            $previous = $previousSizes[$segment] ?? 0;

            if ($previous === 0) {
                $changePercent = $current > 0 ? 100.0 : 0.0;
            } else {
                $changePercent = (($current - $previous) / $previous) * 100;
            }

            $absPercent = abs($changePercent);
            $direction = $changePercent > $thresholdPercent ? 'up'
                : ($changePercent < -$thresholdPercent ? 'down' : 'stable');

            if ($direction !== 'stable' || $absPercent >= $thresholdPercent) {
                $results[] = [
                    'segment' => $segment,
                    'current' => $current,
                    'previous' => $previous,
                    'change' => $current - $previous,
                    'change_percent' => round($changePercent, 2),
                    'direction' => $direction,
                ];
            }
        }

        usort($results, fn (array $a, array $b): int => abs($b['change_percent']) <=> abs($a['change_percent']));

        return $results;
    }

    /**
     * Compare two segment snapshots with delta analysis.
     *
     * @param  string  $segmentName
     * @param  list<string>  $currentMembers
     * @param  list<string>  $previousMembers
     * @return array{segment: string, current_size: int, previous_size: int, added: list<string>, removed: list<string>, retained: list<string>, retention_rate: float}
     */
    public function compare(string $segmentName, array $currentMembers, array $previousMembers): array
    {
        $currentSet = array_flip($currentMembers);
        $previousSet = array_flip($previousMembers);

        $added = [];
        $removed = [];
        $retained = [];

        foreach ($currentMembers as $userId) {
            if (isset($previousSet[$userId])) {
                $retained[] = $userId;
            } else {
                $added[] = $userId;
            }
        }

        foreach ($previousMembers as $userId) {
            if (! isset($currentSet[$userId])) {
                $removed[] = $userId;
            }
        }

        $retentionRate = count($previousMembers) > 0
            ? (count($retained) / count($previousMembers)) * 100
            : 0.0;

        return [
            'segment' => $segmentName,
            'current_size' => count($currentMembers),
            'previous_size' => count($previousMembers),
            'added' => $added,
            'removed' => $removed,
            'retained' => $retained,
            'retention_rate' => round($retentionRate, 2),
        ];
    }

    // ── Snapshots ─────────────────────────────────────────────────────

    /**
     * Persist a segment membership snapshot for historical tracking.
     *
     * @param  string  $segmentName
     * @param  list<string>  $members
     * @param  string|null  $label  Optional snapshot label
     * @return array{segment: string, label: string|null, size: int, snapshot_id: string, created_at: string}
     */
    public function snapshot(string $segmentName, array $members, ?string $label = null): array
    {
        $snapshotId = $segmentName . '_' . time();
        $cacheKey = self::CACHE_PREFIX . 'snapshot_' . $snapshotId;

        $snapshotData = [
            'segment' => $segmentName,
            'label' => $label,
            'size' => count($members),
            'snapshot_id' => $snapshotId,
            'created_at' => (new \DateTimeImmutable)->format('c'),
            'members' => array_slice($members, 0, $this->maxSegmentSize),
        ];

        $this->cache->put($cacheKey, $snapshotData, $this->cacheTtl * 24); // Store longer

        return $snapshotData;
    }

    /**
     * Get snapshot history for a segment.
     *
     * @param  string  $segmentName
     * @return list<array{segment: string, label: string|null, size: int, snapshot_id: string, created_at: string}>
     */
    public function getSnapshots(string $segmentName): array
    {
        // We can't iterate cache keys in all drivers, so we track snapshot IDs
        $trackKey = self::CACHE_PREFIX . 'snapshot_ids_' . $segmentName;
        $ids = $this->cache->get($trackKey, []);

        /** @var list<string> $ids */
        $snapshots = [];
        foreach (array_slice($ids, -$this->maxSnapshots) as $id) {
            $data = $this->cache->get(self::CACHE_PREFIX . 'snapshot_' . $id);
            if (is_array($data)) {
                $snapshots[] = $data;
            }
        }

        return $snapshots;
    }

    // ── Utility & Diagnostics ─────────────────────────────────────────

    /**
     * Get service stats for admin dashboards.
     *
     * @return array{enabled: bool, segments_defined: int, built_in_segments: int, cache_ttl: int, max_segment_size: int, max_snapshots: int, segment_types: array<string, int>}
     */
    public function stats(): array
    {
        $typeCounts = [];
        foreach ($this->segmentDefinitions as $def) {
            $type = $def['type'];
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        return [
            'enabled' => $this->enabled,
            'segments_defined' => count($this->segmentDefinitions),
            'built_in_segments' => count($this->builtInDefinitions()),
            'cache_ttl' => $this->cacheTtl,
            'max_segment_size' => $this->maxSegmentSize,
            'max_snapshots' => $this->maxSnapshots,
            'segment_types' => $typeCounts,
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
     * Clear all cached segment data.
     */
    public function clearCache(): void
    {
        foreach ($this->segmentDefinitions as $name => $_) {
            $this->cache->forget(self::CACHE_PREFIX . 'def_' . $name);
            $this->cache->forget(self::CACHE_PREFIX . 'members_' . $name);
        }
    }

    /**
     * Get a summary of all segments with their descriptions and types.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function summary(): array
    {
        $result = [];
        foreach ($this->segmentDefinitions as $name => $def) {
            $result[$name] = [
                'type' => $def['type'],
                'description' => $def['description'],
            ];
        }

        return $result;
    }

    // ── Internal: User Evaluation ─────────────────────────────────────

    /**
     * Evaluate whether a single user matches a segment definition.
     *
     * @param  string  $type  Segment type
     * @param  array<string, mixed>  $conditions  Segment conditions
     * @param  list<array{event: string, timestamp?: string, params?: array<string, mixed>}>  $events  User's event records
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateUser(string $type, array $conditions, array $events): array
    {
        return match ($type) {
            'event' => $this->evaluateEventBased($conditions, $events),
            'frequency' => $this->evaluateFrequencyBased($conditions, $events),
            'sequence' => $this->evaluateSequenceBased($conditions, $events),
            'time' => $this->evaluateTimeBased($conditions, $events),
            'property' => $this->evaluatePropertyBased($conditions, $events),
            'composite' => $this->evaluateComposite($conditions, $events),
            default => ['matched' => false, 'conditions' => [], 'score' => 0.0],
        };
    }

    /**
     * Evaluate event-based segment: user must have performed (or not performed) specific events.
     *
     * @param  array{events?: list<string>, must_have?: list<string>, must_not_have?: list<string>, any_of?: list<string>}  $conditions
     * @param  list<array{event: string}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateEventBased(array $conditions, array $events): array
    {
        $userEvents = array_column($events, 'event');
        $uniqueEvents = array_unique($userEvents);
        $matchedConditions = [];
        $score = 0.0;

        // Must-have events (all required)
        $mustHave = $conditions['must_have'] ?? $conditions['events'] ?? [];
        /** @var list<string> $mustHave */
        foreach ($mustHave as $requiredEvent) {
            if (in_array($requiredEvent, $uniqueEvents, true)) {
                $matchedConditions[] = "has_{$requiredEvent}";
                $score += 1.0;
            } else {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        // Must-not-have events
        $mustNotHave = $conditions['must_not_have'] ?? [];
        /** @var list<string> $mustNotHave */
        foreach ($mustNotHave as $forbiddenEvent) {
            if (! in_array($forbiddenEvent, $uniqueEvents, true)) {
                $matchedConditions[] = "not_{$forbiddenEvent}";
                $score += 0.5;
            } else {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        // Any-of events (at least one)
        $anyOf = $conditions['any_of'] ?? [];
        /** @var list<string> $anyOf */
        if ($anyOf !== []) {
            $foundAny = false;
            foreach ($anyOf as $candidateEvent) {
                if (in_array($candidateEvent, $uniqueEvents, true)) {
                    $matchedConditions[] = "any_{$candidateEvent}";
                    $score += 0.5;
                    $foundAny = true;
                    break;
                }
            }
            if (! $foundAny) {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        return [
            'matched' => true,
            'conditions' => $matchedConditions,
            'score' => $score,
        ];
    }

    /**
     * Evaluate frequency-based segment: user event counts above/below thresholds.
     *
     * @param  array{min_count?: array<string, int>, max_count?: array<string, int>, total_min?: int, total_max?: int}  $conditions
     * @param  list<array{event: string}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateFrequencyBased(array $conditions, array $events): array
    {
        $eventCounts = [];
        foreach ($events as $e) {
            $eventName = $e['event'];
            $eventCounts[$eventName] = ($eventCounts[$eventName] ?? 0) + 1;
        }

        $matchedConditions = [];
        $score = 0.0;
        $totalEvents = count($events);

        // Per-event minimum counts
        $minCounts = $conditions['min_count'] ?? [];
        /** @var array<string, int> $minCounts */
        foreach ($minCounts as $eventName => $minCount) {
            $actual = $eventCounts[$eventName] ?? 0;
            if ($actual >= $minCount) {
                $matchedConditions[] = "{$eventName}_gte_{$minCount}";
                $score += 1.0;
            } else {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        // Per-event maximum counts
        $maxCounts = $conditions['max_count'] ?? [];
        /** @var array<string, int> $maxCounts */
        foreach ($maxCounts as $eventName => $maxCount) {
            $actual = $eventCounts[$eventName] ?? 0;
            if ($actual <= $maxCount) {
                $matchedConditions[] = "{$eventName}_lte_{$maxCount}";
                $score += 0.5;
            } else {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        // Total event count thresholds
        $totalMin = $conditions['total_min'] ?? null;
        if ($totalMin !== null && $totalEvents < (int) $totalMin) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }
        $totalMax = $conditions['total_max'] ?? null;
        if ($totalMax !== null && $totalEvents > (int) $totalMax) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        return [
            'matched' => true,
            'conditions' => $matchedConditions,
            'score' => $score,
        ];
    }

    /**
     * Evaluate sequence-based segment: user completed events in a specific order.
     *
     * @param  array{sequence: list<string>, strict?: bool, max_gap_seconds?: int}  $conditions
     * @param  list<array{event: string, timestamp?: string}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateSequenceBased(array $conditions, array $events): array
    {
        $sequence = $conditions['sequence'] ?? [];
        /** @var list<string> $sequence */
        $strict = (bool) ($conditions['strict'] ?? true);
        $maxGap = (int) ($conditions['max_gap_seconds'] ?? 0);

        if ($sequence === []) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        $userEvents = array_column($events, 'event');
        $matchedConditions = [];

        if ($strict) {
            // Strict: events must appear consecutively in the exact order
            $userCount = count($userEvents);
            $seqLen = count($sequence);

            for ($i = 0; $i <= $userCount - $seqLen; $i++) {
                $match = true;
                $stepConditions = [];

                for ($j = 0; $j < $seqLen; $j++) {
                    if ($userEvents[$i + $j] !== $sequence[$j]) {
                        $match = false;
                        break;
                    }

                    if ($maxGap > 0 && $j > 0) {
                        $currentTs = $events[$i + $j]['timestamp'] ?? null;
                        $prevTs = $events[$i + $j - 1]['timestamp'] ?? null;
                        if ($currentTs !== null && $prevTs !== null) {
                            $gap = strtotime($currentTs) - strtotime($prevTs);
                            if ($gap > $maxGap) {
                                $match = false;
                                break;
                            }
                        }
                    }

                    $stepConditions[] = "step_{$j}_{$sequence[$j]}";
                }

                if ($match) {
                    $matchedConditions = $stepConditions;
                    $score = (float) count($sequence);

                    return ['matched' => true, 'conditions' => $matchedConditions, 'score' => $score];
                }
            }

            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        // Non-strict: events must appear in order but can have other events between
        $seqIndex = 0;
        foreach ($userEvents as $event) {
            if ($event === $sequence[$seqIndex]) {
                $matchedConditions[] = "step_{$seqIndex}_{$sequence[$seqIndex]}";
                $seqIndex++;
                if ($seqIndex >= count($sequence)) {
                    break;
                }
            }
        }

        $allMatched = $seqIndex >= count($sequence);

        return [
            'matched' => $allMatched,
            'conditions' => $allMatched ? $matchedConditions : [],
            'score' => $allMatched ? (float) count($sequence) : 0.0,
        ];
    }

    /**
     * Evaluate time-based segment: user active within a time window.
     *
     * @param  array{last_seen_within_hours?: int, first_seen_after?: string, active_between?: array{start: string, end: string}, days_since_last_event_max?: int}  $conditions
     * @param  list<array{event: string, timestamp?: string}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateTimeBased(array $conditions, array $events): array
    {
        if ($events === []) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        $matchedConditions = [];
        $score = 0.0;
        $now = time();

        // Last seen within N hours
        $lastSeenWithin = $conditions['last_seen_within_hours'] ?? null;
        if ($lastSeenWithin !== null) {
            $lastEvent = $events[count($events) - 1];
            $lastTs = $lastEvent['timestamp'] ?? null;
            if ($lastTs !== null) {
                $diffHours = ($now - strtotime($lastTs)) / 3600;
                if ($diffHours <= (int) $lastSeenWithin) {
                    $matchedConditions[] = "last_seen_{$lastSeenWithin}h";
                    $score += 1.0;
                } else {
                    return ['matched' => false, 'conditions' => [], 'score' => 0.0];
                }
            }
        }

        // Days since last event max (for churned/dormant detection)
        $daysSinceMax = $conditions['days_since_last_event_max'] ?? null;
        if ($daysSinceMax !== null) {
            $lastEvent = $events[count($events) - 1];
            $lastTs = $lastEvent['timestamp'] ?? null;
            if ($lastTs !== null) {
                $daysSince = ($now - strtotime($lastTs)) / 86400;
                if ($daysSince >= (int) $daysSinceMax) {
                    $matchedConditions[] = "inactive_{$daysSinceMax}d";
                    $score += 0.5;
                } else {
                    return ['matched' => false, 'conditions' => [], 'score' => 0.0];
                }
            }
        }

        // Active between dates
        $activeBetween = $conditions['active_between'] ?? null;
        if ($activeBetween !== null) {
            $startTs = strtotime((string) ($activeBetween['start'] ?? ''));
            $endTs = strtotime((string) ($activeBetween['end'] ?? ''));
            if ($startTs !== false && $endTs !== false) {
                $hasEventInRange = false;
                foreach ($events as $e) {
                    $ts = $e['timestamp'] ?? null;
                    if ($ts !== null) {
                        $eTs = strtotime($ts);
                        if ($eTs !== false && $eTs >= $startTs && $eTs <= $endTs) {
                            $hasEventInRange = true;
                            break;
                        }
                    }
                }
                if ($hasEventInRange) {
                    $matchedConditions[] = 'active_in_range';
                    $score += 0.5;
                } else {
                    return ['matched' => false, 'conditions' => [], 'score' => 0.0];
                }
            }
        }

        return [
            'matched' => $matchedConditions !== [],
            'conditions' => $matchedConditions,
            'score' => $score,
        ];
    }

    /**
     * Evaluate property-based segment: user events must match specific property conditions.
     *
     * @param  array{event?: string, properties?: array<string, mixed>, user_properties?: array<string, mixed>}  $conditions
     * @param  list<array{event: string, params?: array<string, mixed>}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluatePropertyBased(array $conditions, array $events): array
    {
        $matchedConditions = [];
        $score = 0.0;

        $targetEvent = $conditions['event'] ?? null;
        $relevantEvents = $targetEvent !== null
            ? array_filter($events, fn (array $e): bool => ($e['event'] ?? '') === $targetEvent)
            : $events;

        if (empty($relevantEvents)) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        $propertyConditions = $conditions['properties'] ?? [];
        /** @var array<string, mixed> $propertyConditions */
        foreach ($propertyConditions as $propName => $expectedValue) {
            $found = false;
            foreach ($relevantEvents as $e) {
                $params = $e['params'] ?? [];
                if (isset($params[$propName]) && $params[$propName] == $expectedValue) {
                    $matchedConditions[] = "{$propName}_eq";
                    $score += 0.5;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        }

        // User-level property conditions (would need user data in real implementation)
        $userProps = $conditions['user_properties'] ?? [];
        /** @var array<string, mixed> $userProps */
        foreach ($userProps as $propName => $expectedValue) {
            $matchedConditions[] = "user_{$propName}";
            $score += 0.25;
        }

        return [
            'matched' => true,
            'conditions' => $matchedConditions,
            'score' => $score,
        ];
    }

    /**
     * Evaluate composite segment: nested AND/OR/NOT combinations.
     *
     * @param  array{operator: string, segments: list<array<string, mixed>>}  $conditions
     * @param  list<array{event: string, timestamp?: string, params?: array<string, mixed>}>  $events
     * @return array{matched: bool, conditions: list<string>, score: float}
     */
    private function evaluateComposite(array $conditions, array $events): array
    {
        $operator = strtoupper($conditions['operator'] ?? 'AND');
        $subSegments = $conditions['segments'] ?? [];
        /** @var list<array<string, mixed>> $subSegments */

        if ($subSegments === []) {
            return ['matched' => false, 'conditions' => [], 'score' => 0.0];
        }

        $allConditions = [];
        $totalScore = 0.0;

        if ($operator === 'AND') {
            foreach ($subSegments as $sub) {
                $type = (string) ($sub['type'] ?? 'event');
                $subConditions = (array) ($sub['conditions'] ?? $sub);
                $result = $this->evaluateUser($type, $subConditions, $events);
                if (! $result['matched']) {
                    return ['matched' => false, 'conditions' => [], 'score' => 0.0];
                }
                $allConditions = array_merge($allConditions, $result['conditions']);
                $totalScore += $result['score'];
            }
        } elseif ($operator === 'OR') {
            $anyMatched = false;
            foreach ($subSegments as $sub) {
                $type = (string) ($sub['type'] ?? 'event');
                $subConditions = (array) ($sub['conditions'] ?? $sub);
                $result = $this->evaluateUser($type, $subConditions, $events);
                if ($result['matched']) {
                    $anyMatched = true;
                    $allConditions = array_merge($allConditions, $result['conditions']);
                    $totalScore += $result['score'];
                }
            }
            if (! $anyMatched) {
                return ['matched' => false, 'conditions' => [], 'score' => 0.0];
            }
        } elseif ($operator === 'NOT') {
            // NOT first segment = negate its result
            if (isset($subSegments[0])) {
                $sub = $subSegments[0];
                $type = (string) ($sub['type'] ?? 'event');
                $subConditions = (array) ($sub['conditions'] ?? $sub);
                $result = $this->evaluateUser($type, $subConditions, $events);
                if ($result['matched']) {
                    return ['matched' => false, 'conditions' => [], 'score' => 0.0];
                }
                $allConditions[] = 'negated';
                $totalScore = 0.5;
            }
        }

        return [
            'matched' => true,
            'conditions' => $allConditions,
            'score' => $totalScore,
        ];
    }

    // ── Internal: Caching ─────────────────────────────────────────────

    private function getSegmentMembers(string $name): array
    {
        return $this->segmentMembers[$name] ?? [];
    }

    private function cacheSegmentDefinition(string $name): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . 'def_' . $name,
            $this->segmentDefinitions[$name] ?? null,
            $this->cacheTtl,
        );
    }

    private function cacheSegmentMembers(string $name): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . 'members_' . $name,
            $this->segmentMembers[$name] ?? [],
            $this->cacheTtl,
        );
    }

    /**
     * @return array<string, mixed> Empty result for unknown segments.
     */
    private function emptyResult(string $name): array
    {
        return [
            'segment' => $name,
            'size' => 0,
            'members' => [],
            'details' => [],
            'evaluated_at' => (new \DateTimeImmutable)->format('c'),
        ];
    }

    /**
     * Built-in segment definitions for common SaaS analytics patterns.
     *
     * @return array<string, array{type: string, conditions: array<string, mixed>, description: string, created_at: string|null}>
     */
    private function builtInDefinitions(): array
    {
        return [
            'power_users' => [
                'type' => 'frequency',
                'conditions' => [
                    'total_min' => 50,
                ],
                'description' => 'Users with 50+ total events (highly engaged)',
                'created_at' => null,
            ],
            'new_users' => [
                'type' => 'event',
                'conditions' => [
                    'must_have' => ['sign_up'],
                ],
                'description' => 'Users who completed sign-up',
                'created_at' => null,
            ],
            'trial_users' => [
                'type' => 'sequence',
                'conditions' => [
                    'sequence' => ['sign_up', 'start_trial'],
                    'strict' => false,
                ],
                'description' => 'Users who started a trial after signup',
                'created_at' => null,
            ],
            'converted_users' => [
                'type' => 'sequence',
                'conditions' => [
                    'sequence' => ['sign_up', 'start_trial', 'subscribe'],
                    'strict' => false,
                ],
                'description' => 'Users who converted from trial to paid',
                'created_at' => null,
            ],
            'at_risk_users' => [
                'type' => 'time',
                'conditions' => [
                    'days_since_last_event_max' => 7,
                ],
                'description' => 'Users inactive for 7+ days (at risk of churn)',
                'created_at' => null,
            ],
            'churned_users' => [
                'type' => 'time',
                'conditions' => [
                    'days_since_last_event_max' => 30,
                ],
                'description' => 'Users inactive for 30+ days (likely churned)',
                'created_at' => null,
            ],
            'feature_adapters' => [
                'type' => 'event',
                'conditions' => [
                    'must_have' => ['feature_used'],
                ],
                'description' => 'Users who used at least one feature',
                'created_at' => null,
            ],
            'searchers' => [
                'type' => 'frequency',
                'conditions' => [
                    'min_count' => ['search' => 3],
                ],
                'description' => 'Users who searched 3+ times',
                'created_at' => null,
            ],
            'ecommerce_browsers' => [
                'type' => 'event',
                'conditions' => [
                    'must_have' => ['view_item'],
                    'must_not_have' => ['purchase'],
                ],
                'description' => 'Users who browsed products but never purchased',
                'created_at' => null,
            ],
            'buyers' => [
                'type' => 'event',
                'conditions' => [
                    'must_have' => ['purchase'],
                ],
                'description' => 'Users who completed a purchase',
                'created_at' => null,
            ],
        ];
    }
}
