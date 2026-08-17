<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable readonly DTO representing a full compaction report.
 *
 * Aggregates multiple compaction results into a single report with
 * summary statistics, storage savings estimates, and actionable
 * recommendations for optimizing analytics archive storage.
 *
 * @since 224.0.0
 */
final readonly class CompactionReport
{
    /**
     * @param  string  $dateRange  Report date range
     * @param  list<CompactionResult>  $results  Individual compaction operation results
     * @param  int  $totalEventsBefore  Total events across all scopes before compaction
     * @param  int  $totalEventsAfter  Total events across all scopes after compaction
     * @param  int  $totalEventsCompacted  Total events compacted
     * @param  float  $totalBytesSaved  Total estimated bytes saved (KB)
     * @param  float  $overallCompressionRatio  Overall compression ratio
     * @param  int  $successfulScopes  Number of successful compactions
     * @param  int  $failedScopes  Number of failed compactions
     * @param  float  $durationMs  Total compaction duration (ms)
     * @param  string  $healthGrade  Storage health grade (A through F)
     * @param  list<string>  $recommendations  Actionable optimization recommendations
     */
    public function __construct(
        public string $dateRange,
        public array $results,
        public int $totalEventsBefore,
        public int $totalEventsAfter,
        public int $totalEventsCompacted,
        public float $totalBytesSaved,
        public float $overallCompressionRatio,
        public int $successfulScopes,
        public int $failedScopes,
        public float $durationMs,
        public string $healthGrade,
        public array $recommendations,
    ) {}

    /**
     * Build a compaction report from individual results.
     *
     * @param  string  $dateRange
     * @param  list<CompactionResult>  $results
     * @param  float  $durationMs
     * @param  float  $storageBudgetKb  Monthly storage budget in KB (for health grading)
     * @param  list<string>  $recommendations
     * @return self
     */
    public static function fromResults(
        string $dateRange,
        array $results,
        float $durationMs,
        float $storageBudgetKb = 1048576.0, // 1 GB default
        array $recommendations = [],
    ): self {
        $totalBefore = 0;
        $totalAfter = 0;
        $totalBytesSaved = 0.0;
        $successful = 0;
        $failed = 0;

        foreach ($results as $result) {
            $totalBefore += $result->eventsBefore;
            $totalAfter += $result->eventsAfter;
            $totalBytesSaved += $result->bytesSaved;
            if ($result->success) {
                $successful++;
            } else {
                $failed++;
            }
        }

        $compressionRatio = $totalBefore > 0 ? $totalAfter / $totalBefore : 0.0;
        $healthGrade = self::computeGrade($totalBytesSaved, $storageBudgetKb, $failed);

        return new self(
            dateRange: $dateRange,
            results: $results,
            totalEventsBefore: $totalBefore,
            totalEventsAfter: $totalAfter,
            totalEventsCompacted: $totalBefore - $totalAfter,
            totalBytesSaved: $totalBytesSaved,
            overallCompressionRatio: $compressionRatio,
            successfulScopes: $successful,
            failedScopes: $failed,
            durationMs: $durationMs,
            healthGrade: $healthGrade,
            recommendations: $recommendations,
        );
    }

    /**
     * Compute storage health grade based on savings and failures.
     *
     * @param  float  $bytesSavedKb  Bytes saved in KB
     * @param  float  $budgetKb  Monthly storage budget in KB
     * @param  int  $failures  Number of failed compactions
     * @return string  Letter grade (A through F)
     */
    private static function computeGrade(float $bytesSavedKb, float $budgetKb, int $failures): string
    {
        if ($failures > 0) {
            return 'D';
        }

        $savingsPct = $budgetKb > 0 ? ($bytesSavedKb / $budgetKb) * 100 : 0;

        if ($savingsPct >= 20.0) {
            return 'A';
        }

        if ($savingsPct >= 10.0) {
            return 'B';
        }

        if ($savingsPct >= 5.0) {
            return 'C';
        }

        return 'D';
    }

    /**
     * Serialize to array for JSON/API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_range' => $this->dateRange,
            'total_events_before' => $this->totalEventsBefore,
            'total_events_after' => $this->totalEventsAfter,
            'total_events_compacted' => $this->totalEventsCompacted,
            'total_bytes_saved' => round($this->totalBytesSaved, 2),
            'overall_compression_ratio' => round($this->overallCompressionRatio, 4),
            'successful_scopes' => $this->successfulScopes,
            'failed_scopes' => $this->failedScopes,
            'duration_ms' => round($this->durationMs, 2),
            'health_grade' => $this->healthGrade,
            'recommendations' => $this->recommendations,
            'results' => array_map(fn (CompactionResult $r): array => $r->toArray(), $this->results),
        ];
    }
}
