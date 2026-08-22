<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the value attribution of a detected event sequence.
 *
 * Associates a sequence of events with quantitative business value metrics:
 * - LTV (Lifetime Value) — average lifetime value of users who followed this path
 * - Revenue attribution — total and per-occurrence revenue generated
 * - Conversion lift — conversion rate delta vs. baseline
 * - Retention impact — D7/D30 retention rate for this cohort
 * - Time to value — average time from sequence start to first value event
 * - Sequence ROI — cost-benefit ratio of this path
 *
 * Used by EventSequenceValueAttributionService to rank and prioritize
 * user journey paths by their commercial impact.
 *
 * @since 212.0.0
 */
final readonly class SequenceValueAttribution
{
    /**
     * @param  non-empty-string  $sequenceId  SHA-256 hash of the event sequence
     * @param  list<string>  $sequence  Ordered event names in the path
     * @param  int  $occurrences  Total times this sequence was observed
     * @param  int  $uniqueUsers  Distinct users who completed this sequence
     * @param  float  $avgLtv  Average lifetime value of these users (currency units)
     * @param  float  $totalRevenue  Total revenue attributed to this sequence (currency units)
     * @param  float  $conversionRate  Fraction completing the full sequence (0.0–1.0)
     * @param  float  $conversionLift  Conversion delta vs. overall baseline (e.g. +0.15)
     * @param  float  $d7Retention  Day-7 retention rate for this sequence cohort (0.0–1.0)
     * @param  float  $d30Retention  Day-30 retention rate for this sequence cohort (0.0–1.0)
     * @param  float  $timeToValueSeconds  Avg time from sequence start to first value event
     * @param  float  $sequenceRoi  ROI ratio (revenue / estimated acquisition cost)
     * @param  string  $valueGrade  Letter grade: S, A, B, C, D based on composite value
     * @param  float  $compositeScore  Weighted composite value score (0.0–1.0)
     * @param  array<string, mixed>  $metadata  Additional computed metadata
     */
    public function __construct(
        public readonly string $sequenceId,
        public readonly array $sequence,
        public int $occurrences = 0,
        public int $uniqueUsers = 0,
        public float $avgLtv = 0.0,
        public readonly float $totalRevenue = 0.0,
        public readonly float $conversionRate = 0.0,
        public readonly float $conversionLift = 0.0,
        public readonly float $d7Retention = 0.0,
        public readonly float $d30Retention = 0.0,
        public readonly float $timeToValueSeconds = 0.0,
        public readonly float $sequenceRoi = 0.0,
        public readonly string $valueGrade = 'C',
        public readonly float $compositeScore = 0.0,
        public readonly array $metadata = [],
    ){}

    /**
     * Serialize the attribution to an array.
     *
     * @return array{sequence_id: string, sequence: list<string>, occurrences: int, unique_users: int, avg_ltv: float, total_revenue: float, conversion_rate: float, conversion_lift: float, d7_retention: float, d30_retention: float, time_to_value: float, sequence_roi: float, value_grade: string, composite_score: float, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'sequence_id' => $this->sequenceId,
            'sequence' => $this->sequence,
            'occurrences' => $this->occurrences,
            'unique_users' => $this->uniqueUsers,
            'avg_ltv' => round($this->avgLtv, 2),
            'total_revenue' => round($this->totalRevenue, 2),
            'conversion_rate' => round($this->conversionRate, 4),
            'conversion_lift' => round($this->conversionLift, 4),
            'd7_retention' => round($this->d7Retention, 4),
            'd30_retention' => round($this->d30Retention, 4),
            'time_to_value' => round($this->timeToValueSeconds, 1),
            'sequence_roi' => round($this->sequenceRoi, 2),
            'value_grade' => $this->valueGrade,
            'composite_score' => round($this->compositeScore, 4),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create from a stored array representation.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sequenceId: (string) ($data['sequence_id'] ?? ''),
            sequence: is_array($data['sequence'] ?? null) ? array_values($data['sequence']) : [],
            occurrences: (int) ($data['occurrences'] ?? 0),
            uniqueUsers: (int) ($data['unique_users'] ?? 0),
            avgLtv: (float) ($data['avg_ltv'] ?? 0.0),
            totalRevenue: (float) ($data['total_revenue'] ?? 0.0),
            conversionRate: (float) ($data['conversion_rate'] ?? 0.0),
            conversionLift: (float) ($data['conversion_lift'] ?? 0.0),
            d7Retention: (float) ($data['d7_retention'] ?? 0.0),
            d30Retention: (float) ($data['d30_retention'] ?? 0.0),
            timeToValueSeconds: (float) ($data['time_to_value'] ?? 0.0),
            sequenceRoi: (float) ($data['sequence_roi'] ?? 0.0),
            valueGrade: (string) ($data['value_grade'] ?? 'C'),
            compositeScore: (float) ($data['composite_score'] ?? 0.0),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
