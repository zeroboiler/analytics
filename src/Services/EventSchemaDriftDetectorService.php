<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\SchemaDriftRecord;
use ZeroBoiler\Analytics\DTO\SchemaDriftTrend;
use ZeroBoiler\Analytics\DTO\SchemaMigrationPlan;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Schema Drift Detector & Migration Planner.
 *
 * Monitors event payload schemas over time to detect structural changes
 * (added/removed/renamed fields, type changes) and generates automated
 * migration plans for downstream consumers (data warehouses, ETL pipelines,
 * dashboards, BI tools).
 *
 * This enables SaaS teams to:
 * - Detect breaking schema changes before they impact production dashboards
 * - Track event payload evolution and identify events with high schema churn
 * - Generate actionable migration plans with code examples
 * - Maintain backward compatibility across provider integrations
 * - Audit schema changes for compliance and debugging
 *
 * Schema snapshot strategy:
 * 1. Events are sampled from the event stream (configurable window)
 * 2. Field presence and type signatures are extracted from each event
 * 3. A deterministic schema hash is computed for each observation window
 * 4. Consecutive hashes are compared to detect drift
 * 5. Field-level diffs are computed when drift is detected
 *
 * @see \ZeroBoiler\Analytics\Services\EventSemanticClassifierService
 * @see \ZeroBoiler\Analytics\Services\EventNamingConventionLinter
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 *
 * @since 223.0.0
 *
 * @phpstan-type FieldSignature array{present: bool, type: string, nullable: bool, example_count: int, sample_value: mixed}
 * @phpstan-type SchemaSnapshot array{hash: string, fields: array<string, FieldSignature>, field_count: int, sample_size: int, captured_at: string}
 */
final class EventSchemaDriftDetectorService
{
    private const CACHE_PREFIX = 'zb_schema_drift_';

    private const CACHE_TTL = 86400; // 24 hours

    /** @var array<string, SchemaSnapshot> In-memory baseline registry */
    private array $baselines = [];

    /** @var array<string, list<SchemaSnapshot>> Per-event observation history (max entries) */
    private array $history = [];

    private int $maxHistoryEntries;

    private int $minSampleSize;

    private float $driftScoreThreshold;

    /** @var list<string> Provider names for impact assessment */
    private const PROVIDERS = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

    /**
     * @param  CacheRepository  $cache  Cache repository for persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $driftConfig = $config->get('zeroboiler.analytics.schema_drift', []);
        /** @var array{max_history_entries?: int, min_sample_size?: int, drift_score_threshold?: float} $driftConfig */

        $this->maxHistoryEntries = (int) ($driftConfig['max_history_entries'] ?? 20);
        $this->minSampleSize = (int) ($driftConfig['min_sample_size'] ?? 10);
        $this->driftScoreThreshold = (float) ($driftConfig['drift_score_threshold'] ?? 0.05);
    }

    /**
     * Record an event observation for schema drift tracking.
     *
     * Extracts field signatures from the event and stores them for
     * later drift detection. Called by the event pipeline or manually.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $window  Observation window identifier (defaults to current date)
     */
    public function recordObservation(string $eventName, array $params, ?string $window = null): void
    {
        $window = $window ?? date('Y-m-d');
        $cacheKey = $this->observationCacheKey($eventName, $window);

        /** @var SchemaSnapshot|null $existing */
        $existing = $this->cache->get($cacheKey);

        if ($existing === null) {
            $existing = [
                'hash' => '',
                'fields' => [],
                'field_count' => 0,
                'sample_size' => 0,
                'captured_at' => date('c'),
            ];
        }

        // Merge field signatures from this observation
        $updated = $this->mergeFieldSignatures($existing['fields'], $params);

        $existing['fields'] = $updated;
        $existing['field_count'] = count($updated);
        $existing['sample_size'] = $existing['sample_size'] + 1;
        $existing['hash'] = $this->computeSchemaHash($updated);
        $existing['captured_at'] = date('c');

        $this->cache->put($cacheKey, $existing, self::CACHE_TTL);
    }

    /**
     * Record multiple event observations in batch.
     *
     * @param  list<array{event: string, params: array<string, mixed>}>  $events
     * @param  string|null  $window  Observation window identifier
     */
    public function recordBatch(array $events, ?string $window = null): void
    {
        foreach ($events as $event) {
            $this->recordObservation(
                $event['event'],
                $event['params'],
                $window,
            );
        }
    }

    /**
     * Detect schema drift for a specific event between two windows.
     *
     * Compares the baseline window schema with the current window schema
     * and returns a detailed drift record with field-level changes.
     *
     * @param  string  $eventName  Event name to analyze
     * @param  string  $baselineWindow  Baseline observation window
     * @param  string  $currentWindow  Current observation window
     * @return SchemaDriftRecord|null  Drift record, or null if no drift detected
     */
    public function detectDrift(string $eventName, string $baselineWindow, string $currentWindow): ?SchemaDriftRecord
    {
        $baseline = $this->getSnapshot($eventName, $baselineWindow);
        $current = $this->getSnapshot($eventName, $currentWindow);

        if ($baseline === null || $current === null) {
            return null;
        }

        if ($baseline['sample_size'] < $this->minSampleSize || $current['sample_size'] < $this->minSampleSize) {
            return null;
        }

        // No drift if hashes match
        if ($baseline['hash'] === $current['hash']) {
            return null;
        }

        // Compute field-level diffs
        $changes = $this->computeFieldDiffs($baseline['fields'], $current['fields']);

        if ($changes === []) {
            return null;
        }

        // Classify severity
        $severity = $this->classifyDriftSeverity($changes);

        // Compute drift score
        $driftScore = $this->computeDriftScore(
            $baseline['fields'],
            $current['fields'],
            $changes,
        );

        // Determine affected providers based on event catalog
        $affectedProviders = $this->getAffectedProviders($eventName);

        return new SchemaDriftRecord(
            eventName: $eventName,
            baselineSnapshot: $baseline['hash'],
            currentSnapshot: $current['hash'],
            changes: $changes,
            severity: $severity,
            driftScore: $driftScore,
            totalFieldsBaseline: $baseline['field_count'],
            totalFieldsCurrent: $current['field_count'],
            detectedAt: new \DateTimeImmutable(),
            sampleSizeBaseline: $baseline['sample_size'],
            sampleSizeCurrent: $current['sample_size'],
            affectedProviders: $affectedProviders,
        );
    }

    /**
     * Detect drift across all tracked events between two windows.
     *
     * @param  string  $baselineWindow  Baseline observation window
     * @param  string  $currentWindow  Current observation window
     * @return list<SchemaDriftRecord>
     */
    public function detectDriftAll(string $baselineWindow, string $currentWindow): array
    {
        $eventNames = $this->getTrackedEventNames();
        $drifts = [];

        foreach ($eventNames as $eventName) {
            $drift = $this->detectDrift($eventName, $baselineWindow, $currentWindow);
            if ($drift !== null) {
                $drifts[] = $drift;
            }
        }

        return $drifts;
    }

    /**
     * Generate a migration plan for a detected schema drift.
     *
     * Analyzes the drift record and produces ordered, actionable migration
     * steps with code examples and rollback strategy.
     *
     * @param  SchemaDriftRecord  $drift  The detected drift to plan for
     * @return SchemaMigrationPlan
     */
    public function generateMigrationPlan(SchemaDriftRecord $drift): SchemaMigrationPlan
    {
        $steps = [];
        $criticalCount = 0;
        $highCount = 0;

        foreach ($drift->changes as $change) {
            $step = $this->buildMigrationStep($drift->eventName, $change);
            $steps[] = $step;

            if ($step['urgency'] === 'critical') {
                $criticalCount++;
            }
            if ($step['urgency'] === 'high') {
                $highCount++;
            }
        }

        // Sort steps by urgency (critical first)
        usort($steps, static fn (array $a, array $b): int => match (true) {
            $a['urgency'] === 'critical' && $b['urgency'] !== 'critical' => -1,
            $b['urgency'] === 'critical' && $a['urgency'] !== 'critical' => 1,
            $a['urgency'] === 'high' && $b['urgency'] === 'low' => -1,
            $b['urgency'] === 'high' && $a['urgency'] === 'low' => 1,
            default => 0,
        });

        // Determine overall risk
        $riskLevel = $this->computeRiskLevel($criticalCount, $highCount, count($steps));

        // Generate rollback strategy
        $rollbackStrategy = $this->generateRollbackStrategy($drift);

        // Prerequisites
        $prerequisites = $this->generatePrerequisites($drift);

        // Estimate consumer impact
        $estimatedImpactConsumers = count($drift->affectedProviders) + $criticalCount * 2 + $highCount;

        return new SchemaMigrationPlan(
            eventName: $drift->eventName,
            driftId: $drift->baselineSnapshot . ':' . $drift->currentSnapshot,
            steps: $steps,
            riskLevel: $riskLevel,
            estimatedImpactConsumers: $estimatedImpactConsumers,
            rollbackStrategy: $rollbackStrategy,
            prerequisites: $prerequisites,
            generatedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Analyze schema drift trend for an event across multiple windows.
     *
     * Builds a chronological view of how an event's schema has evolved,
     * computing drift frequency, instability score, and stability grade.
     *
     * @param  string  $eventName  Event name to analyze
     * @param  int  $windowCount  Number of past windows to analyze
     * @return SchemaDriftTrend
     */
    public function analyzeTrend(string $eventName, int $windowCount = 7): SchemaDriftTrend
    {
        $windows = $this->generateWindowRange($windowCount);
        $windowHistory = [];
        $driftCount = 0;
        $prevHash = null;
        $changedFieldCounts = [];

        foreach ($windows as $window) {
            $snapshot = $this->getSnapshot($eventName, $window);

            if ($snapshot === null) {
                continue;
            }

            $windowDrift = 0.0;

            if ($prevHash !== null && $snapshot['hash'] !== $prevHash && $snapshot['sample_size'] >= $this->minSampleSize) {
                $driftCount++;
                // Approximate drift score based on field count changes
                $windowDrift = 0.5; // Will be refined below
            }

            $windowHistory[] = [
                'window' => $window,
                'snapshot' => $snapshot['hash'],
                'field_count' => $snapshot['field_count'],
                'drift_score' => $windowDrift,
            ];

            $prevHash = $snapshot['hash'];
        }

        // Refine drift scores by comparing adjacent windows
        for ($i = 1; $i < count($windowHistory); $i++) {
            $prevSnapshot = $this->getSnapshot($eventName, $windowHistory[$i - 1]['window']);
            $currSnapshot = $this->getSnapshot($eventName, $windowHistory[$i]['window']);

            if ($prevSnapshot !== null && $currSnapshot !== null) {
                $diffs = $this->computeFieldDiffs($prevSnapshot['fields'], $currSnapshot['fields']);
                if ($diffs !== []) {
                    foreach ($diffs as $diff) {
                        $fieldName = $diff['field'];
                        $changedFieldCounts[$fieldName] = ($changedFieldCounts[$fieldName] ?? 0) + 1;
                    }
                }
                $score = count($diffs) / max(1, max($prevSnapshot['field_count'], $currSnapshot['field_count']));
                $windowHistory[$i]['drift_score'] = round($score, 4);
            }
        }

        $observationWindows = count($windowHistory);
        $driftFrequency = $observationWindows > 1 ? (float) ($driftCount / ($observationWindows - 1)) : 0.0;
        $instabilityScore = min(1.0, $driftFrequency * 2.0);

        $stabilityGrade = $this->computeStabilityGrade($instabilityScore, $driftCount);

        // Top changed fields
        arsort($changedFieldCounts);
        $topChangedFields = array_slice(array_keys($changedFieldCounts), 0, 10);

        // Recommendations
        $recommendations = $this->generateTrendRecommendations(
            $eventName,
            $instabilityScore,
            $stabilityGrade,
            $topChangedFields,
            $driftCount,
        );

        return new SchemaDriftTrend(
            eventName: $eventName,
            observationWindows: $observationWindows,
            totalDriftsDetected: $driftCount,
            driftFrequency: round($driftFrequency, 4),
            instabilityScore: round($instabilityScore, 4),
            stabilityGrade: $stabilityGrade,
            windowHistory: $windowHistory,
            topChangedFields: $topChangedFields,
            recommendations: $recommendations,
        );
    }

    /**
     * Get a comprehensive drift summary across all tracked events.
     *
     * @param  string  $baselineWindow  Baseline observation window
     * @param  string  $currentWindow  Current observation window
     * @return array{total_events: int, drifted_events: int, stable_events: int, breaking_count: int, non_breaking_count: int, avg_drift_score: float, most_drifted: list<string>, drifts: list<SchemaDriftRecord>}
     */
    public function driftSummary(string $baselineWindow, string $currentWindow): array
    {
        $drifts = $this->detectDriftAll($baselineWindow, $currentWindow);
        $trackedEvents = $this->getTrackedEventNames();
        $driftedEventNames = array_map(static fn (SchemaDriftRecord $d): string => $d->eventName, $drifts);

        $breaking = array_filter($drifts, static fn (SchemaDriftRecord $d): bool => $d->severity === 'breaking');
        $nonBreaking = array_filter($drifts, static fn (SchemaDriftRecord $d): bool => $d->severity !== 'breaking');
        $avgScore = count($drifts) > 0
            ? array_sum(array_map(static fn (SchemaDriftRecord $d): float => $d->driftScore, $drifts)) / count($drifts)
            : 0.0;

        // Sort by drift score descending
        usort($drifts, static fn (SchemaDriftRecord $a, SchemaDriftRecord $b): int => $b->driftScore <=> $a->driftScore);

        $mostDrifted = array_slice(
            array_map(static fn (SchemaDriftRecord $d): string => $d->eventName, $drifts),
            0,
            10,
        );

        return [
            'total_events' => count($trackedEvents),
            'drifted_events' => count($drifts),
            'stable_events' => count($trackedEvents) - count($drifts),
            'breaking_count' => count($breaking),
            'non_breaking_count' => count($nonBreaking),
            'avg_drift_score' => round($avgScore, 4),
            'most_drifted' => $mostDrifted,
            'drifts' => $drifts,
        ];
    }

    /**
     * Generate a date range of observation windows.
     *
     * @return list<string>
     */
    private function generateWindowRange(int $count): array
    {
        $windows = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $windows[] = date('Y-m-d', strtotime("-{$i} days"));
        }

        return $windows;
    }

    /**
     * Merge field signatures from a new observation into existing signatures.
     *
     * @param  array<string, FieldSignature>  $existing  Existing field signatures
     * @param  array<string, mixed>  $params  New event params
     * @return array<string, FieldSignature>
     */
    private function mergeFieldSignatures(array $existing, array $params): array
    {
        foreach ($params as $key => $value) {
            $type = get_debug_type($value);

            if (isset($existing[$key])) {
                // Update nullable status if we see a null after a non-null or vice versa
                $existing[$key]['nullable'] = $existing[$key]['nullable'] || $value === null;
                $existing[$key]['example_count'] = $existing[$key]['example_count'] + 1;

                // Track type changes (union types indicate evolution)
                if ($existing[$key]['type'] !== $type && ! str_contains($existing[$key]['type'], '|')) {
                    $existing[$key]['type'] = $existing[$key]['type'] . '|' . $type;
                }
            } else {
                $existing[$key] = [
                    'present' => true,
                    'type' => $type,
                    'nullable' => $value === null,
                    'example_count' => 1,
                    'sample_value' => is_scalar($value) ? $value : null,
                ];
            }
        }

        return $existing;
    }

    /**
     * Compute a deterministic hash from field signatures.
     *
     * @param  array<string, FieldSignature>  $fields  Field signatures
     */
    private function computeSchemaHash(array $fields): string
    {
        // Sort by field name for deterministic ordering
        ksort($fields);

        // Extract type-only signatures for hashing
        $signatures = [];
        foreach ($fields as $name => $sig) {
            $signatures[] = $name . ':' . $sig['type'] . ':' . ($sig['nullable'] ? '1' : '0');
        }

        return hash('xxh128', implode("\n", $signatures));
    }

    /**
     * Compute field-level diffs between two schema snapshots.
     *
     * @param  array<string, FieldSignature>  $baseline  Baseline fields
     * @param  array<string, FieldSignature>  $current  Current fields
     * @return list<FieldChange>
     */
    private function computeFieldDiffs(array $baseline, array $current): array
    {
        $changes = [];
        $baselineKeys = array_keys($baseline);
        $currentKeys = array_keys($current);
        $added = array_diff($currentKeys, $baselineKeys);
        $removed = array_diff($baselineKeys, $currentKeys);
        $common = array_intersect($baselineKeys, $currentKeys);

        // Added fields
        foreach ($added as $field) {
            $changes[] = [
                'field' => $field,
                'type' => 'added',
                'severity' => 'non_breaking',
                'details' => [
                    'new_type' => $current[$field]['type'],
                    'nullable' => $current[$field]['nullable'],
                ],
                'migration_hint' => 'Add default handling for this new field in consumers.',
            ];
        }

        // Removed fields
        foreach ($removed as $field) {
            $wasNullable = $baseline[$field]['nullable'];
            $changes[] = [
                'field' => $field,
                'type' => 'removed',
                'severity' => $wasNullable ? 'non_breaking' : 'breaking',
                'details' => [
                    'old_type' => $baseline[$field]['type'],
                    'was_nullable' => $wasNullable,
                    'example_count' => $baseline[$field]['example_count'],
                ],
                'migration_hint' => $wasNullable
                    ? 'Remove optional handling for this field — it was nullable.'
                    : 'BREAKING: This required field was removed. Consumers expecting it will fail.',
            ];
        }

        // Common fields — check for type changes
        foreach ($common as $field) {
            $baselineType = $baseline[$field]['type'];
            $currentType = $current[$field]['type'];

            if ($baselineType !== $currentType) {
                $changes[] = [
                    'field' => $field,
                    'type' => 'type_changed',
                    'severity' => $this->isTypeBreaking($baselineType, $currentType) ? 'breaking' : 'non_breaking',
                    'details' => [
                        'old_type' => $baselineType,
                        'new_type' => $currentType,
                        'became_nullable' => ! $baseline[$field]['nullable'] && $current[$field]['nullable'],
                    ],
                    'migration_hint' => 'Add type coercion or backward-compatible type handling for this field.',
                ];
            }
        }

        return $changes;
    }

    /**
     * Check if a type change is breaking.
     */
    private function isTypeBreaking(string $oldType, string $newType): bool
    {
        // widening (int → float, string → null) is generally non-breaking
        $nonBreakingWidening = [
            'int' => ['float', 'string', 'null'],
            'float' => ['string', 'null'],
            'string' => ['null'],
            'bool' => ['int', 'string'],
            'array' => ['null'],
        ];

        $baseOldType = explode('|', $oldType)[0];
        $baseNewType = explode('|', $newType)[0];

        $allowed = $nonBreakingWidening[$baseOldType] ?? [];

        return ! in_array($baseNewType, $allowed, true);
    }

    /**
     * Classify overall drift severity from field-level changes.
     *
     * @param  list<FieldChange>  $changes  Detected changes
     */
    private function classifyDriftSeverity(array $changes): string
    {
        $hasBreaking = false;
        $hasNonBreaking = false;

        foreach ($changes as $change) {
            if ($change['severity'] === 'breaking') {
                $hasBreaking = true;
            }
            if ($change['severity'] === 'non_breaking') {
                $hasNonBreaking = true;
            }
        }

        return $hasBreaking ? 'breaking' : ($hasNonBreaking ? 'non_breaking' : 'none');
    }

    /**
     * Compute a normalized drift score between two schemas.
     *
     * @param  array<string, FieldSignature>  $baseline  Baseline fields
     * @param  array<string, FieldSignature>  $current  Current fields
     * @param  list<FieldChange>  $changes  Detected changes
     */
    private function computeDriftScore(array $baseline, array $current, array $changes): float
    {
        $totalUniqueFields = count(array_unique(array_merge(array_keys($baseline), array_keys($current))));

        if ($totalUniqueFields === 0) {
            return 0.0;
        }

        $weightedScore = 0.0;
        foreach ($changes as $change) {
            $weight = match ($change['type']) {
                'removed' => $change['severity'] === 'breaking' ? 2.0 : 0.5,
                'type_changed' => $change['severity'] === 'breaking' ? 1.5 : 0.75,
                'added' => 0.25,
                default => 0.5,
            };
            $weightedScore += $weight;
        }

        return round(min(1.0, $weightedScore / $totalUniqueFields), 4);
    }

    /**
     * Get provider names affected by an event from the catalog.
     *
     * @return list<string>
     */
    private function getAffectedProviders(string $eventName): array
    {
        $catalog = EventCatalog::all();
        $entry = $catalog[$eventName] ?? null;

        if ($entry === null) {
            return self::PROVIDERS; // Unknown events affect all providers
        }

        $affected = [];
        foreach (self::PROVIDERS as $provider) {
            if (isset($entry[$provider]) && $entry[$provider] !== '') {
                $affected[] = $provider;
            }
        }

        return $affected !== [] ? $affected : ['ga4']; // Default to GA4
    }

    /**
     * Build a single migration step from a field change.
     *
     * @return MigrationStep
     */
    private function buildMigrationStep(string $eventName, array $change): array
    {
        $urgency = match (true) {
            $change['severity'] === 'breaking' => 'critical',
            $change['type'] === 'removed' => 'high',
            $change['type'] === 'type_changed' => 'high',
            default => 'medium',
        };

        $codeExample = $this->generateCodeExample($eventName, $change);

        return [
            'action' => $this->resolveAction($change),
            'field' => $change['field'],
            'description' => $change['migration_hint'],
            'code_example' => $codeExample,
            'urgency' => $urgency,
            'affected_consumers' => ['etl_pipeline', 'data_warehouse', 'dashboard'],
        ];
    }

    /**
     * Resolve the migration action type.
     */
    private function resolveAction(array $change): string
    {
        return match ($change['type']) {
            'added' => 'add_default',
            'removed' => $change['severity'] === 'breaking' ? 'alert' : 'drop',
            'type_changed' => 'transform',
            'renamed' => 'rename',
            default => 'backward_compat',
        };
    }

    /**
     * Generate a code example for a migration step.
     */
    private function generateCodeExample(string $eventName, array $change): ?string
    {
        $field = $change['field'];

        return match ($change['type']) {
            'added' => "// Handle new field '{$field}' in '{$eventName}'\n\$value = \$event['params']['{$field}'] ?? null;",
            'removed' => $change['severity'] === 'breaking'
                ? "// BREAKING: '{$field}' removed from '{$eventName}'\n// Remove all references to this field"
                : "// Optional field '{$field}' removed from '{$eventName}'\n// Remove optional handling",
            'type_changed' => "// Type change for '{$field}' in '{$eventName}'\n// Old: {$change['details']['old_type']}, New: {$change['details']['new_type']}\n\$value = \$event['params']['{$field}'] ?? null;",
            default => null,
        };
    }

    /**
     * Compute overall risk level for a migration plan.
     */
    private function computeRiskLevel(int $criticalCount, int $highCount, int $totalSteps): string
    {
        if ($criticalCount > 0) {
            return 'critical';
        }
        if ($highCount >= 3 || $totalSteps >= 10) {
            return 'high';
        }
        if ($highCount > 0 || $totalSteps >= 5) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Generate a rollback strategy description.
     */
    private function generateRollbackStrategy(SchemaDriftRecord $drift): string
    {
        if ($drift->severity === 'breaking') {
            return sprintf(
                'Restore baseline schema hash %s for event "%s". Notify downstream consumers to revert field handling to baseline. Deploy backward-compatibility shim in pipeline middleware.',
                substr($drift->baselineSnapshot, 0, 12),
                $drift->eventName,
            );
        }

        return sprintf(
            'Non-breaking drift — rollback not required. If issues arise, pin consumers to baseline snapshot %s.',
            substr($drift->baselineSnapshot, 0, 12),
        );
    }

    /**
     * Generate prerequisite tasks for a migration plan.
     *
     * @return list<string>
     */
    private function generatePrerequisites(SchemaDriftRecord $drift): array
    {
        $prereqs = [];

        if ($drift->severity === 'breaking') {
            $prereqs[] = 'Notify all downstream consumers of breaking schema change';
            $prereqs[] = 'Review and update data warehouse schema definitions';
            $prereqs[] = 'Update ETL pipeline field mappings';
        }

        $prereqs[] = 'Update event documentation with new field definitions';
        $prereqs[] = 'Run integration tests with updated schema';

        if (count($drift->affectedProviders) > 3) {
            $prereqs[] = 'Test provider-specific payload transformations';
        }

        return $prereqs;
    }

    /**
     * Compute stability grade from instability score and drift count.
     */
    private function computeStabilityGrade(float $instabilityScore, int $driftCount): string
    {
        if ($instabilityScore <= 0.1 && $driftCount === 0) {
            return 'A';
        }
        if ($instabilityScore <= 0.2 && $driftCount <= 1) {
            return 'B';
        }
        if ($instabilityScore <= 0.4 && $driftCount <= 2) {
            return 'C';
        }
        if ($instabilityScore <= 0.6 && $driftCount <= 4) {
            return 'D';
        }
        if ($instabilityScore <= 0.8) {
            return 'E';
        }

        return 'F';
    }

    /**
     * Generate recommendations based on trend analysis.
     *
     * @param  list<string>  $topChangedFields  Most frequently changed fields
     * @return list<string>
     */
    private function generateTrendRecommendations(
        string $eventName,
        float $instabilityScore,
        string $stabilityGrade,
        array $topChangedFields,
        int $driftCount,
    ): array {
        $recs = [];

        if ($stabilityGrade === 'A') {
            $recs[] = "Event '{$eventName}' has a stable schema — no action needed.";
        }

        if ($instabilityScore > 0.6) {
            $recs[] = "HIGH CHURN: Event '{$eventName}' has a highly unstable schema. Consider freezing the schema and versioning new iterations separately.";
        }

        if ($driftCount > 3) {
            $recs[] = "FREQUENT CHANGES: '{$eventName}' changed {$driftCount} times in the observation period. Review the event design for consistency.";
        }

        if ($topChangedFields !== []) {
            $fieldList = implode(', ', array_slice($topChangedFields, 0, 3));
            $recs[] = "Top volatile fields: {$fieldList}. Consider stabilizing these fields or splitting into separate events.";
        }

        if ($stabilityGrade === 'F') {
            $recs[] = "CRITICAL: '{$eventName}' is in a chaotic state. Immediate schema audit recommended.";
        }

        return $recs;
    }

    /**
     * Get a schema snapshot for an event in a given window.
     *
     * @return SchemaSnapshot|null
     */
    private function getSnapshot(string $eventName, string $window): ?array
    {
        $cacheKey = $this->observationCacheKey($eventName, $window);
        /** @var SchemaSnapshot|null $snapshot */
        $snapshot = $this->cache->get($cacheKey);

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Get all tracked event names from cache.
     *
     * @return list<string>
     */
    private function getTrackedEventNames(): array
    {
        $indexKey = self::CACHE_PREFIX . 'event_index';
        /** @var list<string>|null $index */
        $index = $this->cache->get($indexKey);

        if (is_array($index)) {
            return $index;
        }

        // Build index from event catalog
        $catalog = EventCatalog::all();
        $names = array_keys($catalog);
        $this->cache->put($indexKey, $names, self::CACHE_TTL);

        return $names;
    }

    /**
     * Build cache key for an observation window.
     */
    private function observationCacheKey(string $eventName, string $window): string
    {
        return self::CACHE_PREFIX . 'obs:' . $eventName . ':' . $window;
    }

    /**
     * Get the minimum sample size threshold.
     */
    public function getMinSampleSize(): int
    {
        return $this->minSampleSize;
    }

    /**
     * Get the drift score threshold.
     */
    public function getDriftScoreThreshold(): float
    {
        return $this->driftScoreThreshold;
    }

    /**
     * Get configured providers list.
     *
     * @return list<string>
     */
    public function getProviders(): array
    {
        return self::PROVIDERS;
    }
}
