<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Represents a detected event sequence pattern from the stream processor.
 *
 * Stores the sequence of event names, statistical metadata (frequency,
 * occurrence count, average duration), and the user/client IDs where
 * this pattern was observed.
 *
 * Used by EventStreamProcessorService for pattern discovery output.
 *
 * @since 31.0.0
 */
final readonly class EventSequencePattern
{
    /**
     * @param  non-empty-string  $id  Unique pattern identifier (SHA-256 of sequence)
     * @param  list<string>  $sequence  Ordered event names in the pattern
     * @param  int  $occurrences  How many times this pattern was observed
     * @param  int  $uniqueUsers  How many distinct users performed this sequence
     * @param  float  $averageDurationSeconds  Average time to complete this sequence (seconds)
     * @param  float  $medianDurationSeconds  Median time to complete this sequence (seconds)
     * @param  float  $conversionRate  Fraction of sequences that complete the full pattern (0.0-1.0)
     * @param  list<string>  $sampleClientIds  Up to 10 client IDs that exhibited this pattern
     * @param  array<string, mixed>  $metadata  Additional computed metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $sequence,
        public int $occurrences = 0,
        public int $uniqueUsers = 0,
        public float $averageDurationSeconds = 0.0,
        public readonly float $medianDurationSeconds = 0.0,
        public readonly float $conversionRate = 0.0,
        public readonly array $sampleClientIds = [],
        public readonly array $metadata = [],
    ){}

    /**
     * Serialize the pattern to an array.
     *
     * @return array{id: string, sequence: list<string>, occurrences: int, unique_users: int, avg_duration: float, median_duration: float, conversion_rate: float, sample_client_ids: list<string>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'occurrences' => $this->occurrences,
            'unique_users' => $this->uniqueUsers,
            'avg_duration' => round($this->averageDurationSeconds, 2),
            'median_duration' => round($this->medianDurationSeconds, 2),
            'conversion_rate' => round($this->conversionRate, 4),
            'sample_client_ids' => $this->sampleClientIds,
            'metadata' => $this->metadata,
        ];
    }
}
