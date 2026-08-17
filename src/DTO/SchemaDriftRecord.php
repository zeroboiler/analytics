<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Represents a detected schema drift between event versions.
 *
 * Captures the structural difference between a baseline and current
 * event payload schema, including field-level changes and severity.
 *
 * @phpstan-type FieldChange array{
 *     field: string,
 *     type: 'added'|'removed'|'renamed'|'type_changed',
 *     severity: 'breaking'|'non_breaking'|'neutral',
 *     details: array<string, mixed>,
 *     migration_hint: string|null
 * }
 *
 * @since 223.0.0
 */
final readonly class SchemaDriftRecord
{
    /**
     * @param  string  $eventName  The event name being analyzed
     * @param  string  $baselineSnapshot  Hash identifying the baseline schema snapshot
     * @param  string  $currentSnapshot  Hash identifying the current schema snapshot
     * @param  list<FieldChange>  $changes  List of detected field-level changes
     * @param  string  $severity  Overall drift severity: 'breaking'|'non_breaking'|'none'
     * @param  float  $driftScore  Normalized drift magnitude (0.0 = no drift, 1.0 = maximum)
     * @param  int  $totalFieldsBaseline  Number of fields in baseline schema
     * @param  int  $totalFieldsCurrent  Number of fields in current schema
     * @param  \DateTimeImmutable  $detectedAt  When the drift was detected
     * @param  int  $sampleSizeBaseline  Number of events in baseline sample
     * @param  int  $sampleSizeCurrent  Number of events in current sample
     * @param  list<string>  $affectedProviders  Provider names affected by this drift
     */
    public function __construct(
        public string $eventName,
        public string $baselineSnapshot,
        public string $currentSnapshot,
        public array $changes,
        public string $severity,
        public float $driftScore,
        public int $totalFieldsBaseline,
        public int $totalFieldsCurrent,
        public \DateTimeImmutable $detectedAt,
        public int $sampleSizeBaseline = 0,
        public int $sampleSizeCurrent = 0,
        public array $affectedProviders = [],
    ): void  {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'baseline_snapshot' => $this->baselineSnapshot,
            'current_snapshot' => $this->currentSnapshot,
            'changes' => $this->changes,
            'severity' => $this->severity,
            'drift_score' => $this->driftScore,
            'total_fields_baseline' => $this->totalFieldsBaseline,
            'total_fields_current' => $this->totalFieldsCurrent,
            'detected_at' => $this->detectedAt->format('c'),
            'sample_size_baseline' => $this->sampleSizeBaseline,
            'sample_size_current' => $this->sampleSizeCurrent,
            'affected_providers' => $this->affectedProviders,
        ];
    }
}
