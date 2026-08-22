<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Schema drift trend analysis for a single event over time.
 *
 * Tracks how an event's payload schema has evolved across multiple
 * observation windows, enabling teams to identify events with
 * high schema churn vs. stable schemas.
 *
 * @since 223.0.0
 */
final readonly class SchemaDriftTrend
{
    /**
     * @param  string  $eventName  The event being tracked
     * @param  int  $observationWindows  Number of observation windows analyzed
     * @param  int  $totalDriftsDetected  Total schema drifts across all windows
     * @param  float  $driftFrequency  Drifts per window (drifts / windows)
     * @param  float  $instabilityScore  Normalized instability (0.0 = stable, 1.0 = highly unstable)
     * @param  string  $stabilityGrade  Grade: 'A' (stable) through 'F' (chaotic)
     * @param  list<array{window: string, snapshot: string, field_count: int, drift_score: float}>  $windowHistory  Per-window schema snapshots
     * @param  list<string>  $topChangedFields  Fields that changed most frequently
     * @param  list<string>  $recommendations  Actionable recommendations
     */
    public function __construct(
        public string $eventName,
        public int $observationWindows,
        public int $totalDriftsDetected,
        public float $driftFrequency,
        public float $instabilityScore,
        public string $stabilityGrade,
        public array $windowHistory,
        public array $topChangedFields,
        public array $recommendations,
    ){}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'observation_windows' => $this->observationWindows,
            'total_drifts_detected' => $this->totalDriftsDetected,
            'drift_frequency' => $this->driftFrequency,
            'instability_score' => $this->instabilityScore,
            'stability_grade' => $this->stabilityGrade,
            'window_history' => $this->windowHistory,
            'top_changed_fields' => $this->topChangedFields,
            'recommendations' => $this->recommendations,
        ];
    }
}
