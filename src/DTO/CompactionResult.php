<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable readonly DTO representing a single compaction operation result.
 *
 * Captures the before/after state of a compaction run, including
 * events processed, bytes saved, and duration.
 *
 * @since 224.0.0
 */
final readonly class CompactionResult
{
    /**
     * @param  string  $strategy  Compaction strategy used (aggregate, truncate, expire, sample)
     * @param  string  $scope  Compaction scope (event name, category, or 'all')
     * @param  int  $eventsBefore  Event count before compaction
     * @param  int  $eventsAfter  Event count after compaction
     * @param  int  $eventsCompacted  Number of events compacted
     * @param  float  $bytesSaved  Estimated bytes saved (KB)
     * @param  float  $compressionRatio  Events after / events before (0.0–1.0)
     * @param  string  $dateRange  Compacted date range (e.g., '2026-07-01:2026-07-31')
     * @param  float  $durationMs  Compaction duration in milliseconds
     * @param  bool  $success  Whether compaction succeeded
     * @param  string|null  $error  Error message if failed
     */
    public function __construct(
        public string $strategy,
        public string $scope,
        public int $eventsBefore,
        public int $eventsAfter,
        public int $eventsCompacted,
        public float $bytesSaved,
        public float $compressionRatio,
        public string $dateRange,
        public float $durationMs,
        public bool $success,
        public ?string $error = null,
    ) {}

    /**
     * Create a successful compaction result.
     *
     * @param  string  $strategy
     * @param  string  $scope
     * @param  int  $before
     * @param  int  $after
     * @param  float  $bytesSaved
     * @param  string  $dateRange
     * @param  float  $durationMs
     * @return self
     */
    public static function success(
        string $strategy,
        string $scope,
        int $before,
        int $after,
        float $bytesSaved,
        string $dateRange,
        float $durationMs,
    ): self {
        return new self(
            strategy: $strategy,
            scope: $scope,
            eventsBefore: $before,
            eventsAfter: $after,
            eventsCompacted: $before - $after,
            bytesSaved: $bytesSaved,
            compressionRatio: $before > 0 ? $after / $before : 0.0,
            dateRange: $dateRange,
            durationMs: $durationMs,
            success: true,
        );
    }

    /**
     * Create a failed compaction result.
     *
     * @param  string  $strategy
     * @param  string  $scope
     * @param  string  $error
     * @return self
     */
    public static function failure(string $strategy, string $scope, string $error): self
    {
        return new self(
            strategy: $strategy,
            scope: $scope,
            eventsBefore: 0,
            eventsAfter: 0,
            eventsCompacted: 0,
            bytesSaved: 0.0,
            compressionRatio: 0.0,
            dateRange: '',
            durationMs: 0.0,
            success: false,
            error: $error,
        );
    }

    /**
     * Serialize to array for JSON/API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'scope' => $this->scope,
            'events_before' => $this->eventsBefore,
            'events_after' => $this->eventsAfter,
            'events_compacted' => $this->eventsCompacted,
            'bytes_saved' => round($this->bytesSaved, 2),
            'compression_ratio' => round($this->compressionRatio, 4),
            'date_range' => $this->dateRange,
            'duration_ms' => round($this->durationMs, 2),
            'success' => $this->success,
            'error' => $this->error,
        ];
    }

    /**
     * Deserialize from array.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            strategy: (string) ($data['strategy'] ?? ''),
            scope: (string) ($data['scope'] ?? ''),
            eventsBefore: (int) ($data['events_before'] ?? 0),
            eventsAfter: (int) ($data['events_after'] ?? 0),
            eventsCompacted: (int) ($data['events_compacted'] ?? 0),
            bytesSaved: (float) ($data['bytes_saved'] ?? 0.0),
            compressionRatio: (float) ($data['compression_ratio'] ?? 0.0),
            dateRange: (string) ($data['date_range'] ?? ''),
            durationMs: (float) ($data['duration_ms'] ?? 0.0),
            success: (bool) ($data['success'] ?? false),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}
