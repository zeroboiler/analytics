<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
/**
 * Data quality scoring service for analytics events.
 *
 * Measures and tracks the quality of analytics data across four dimensions:
 * - **Completeness**: Percentage of events with all required parameters populated
 * - **Consistency**: Events conform to their registered schemas and naming conventions
 * - **Timeliness**: Events dispatched within expected time windows
 * - **Validity**: Events pass all validation rules (type, range, enum)
 *
 * Provides an overall quality score (0-100) suitable for dashboards
 * and alerting. Scores are cached and aggregated over configurable windows.
 *
 * Configuration is read from `zeroboiler.analytics.governance.quality`.
 *
 * @phpstan-type QualityDimension array{name: string, score: float, weight: float, issues: list<string>, max_score: float}
 * @phpstan-type QualityReport array{overall_score: float, dimensions: array<string, QualityDimension>, total_events_scored: int, last_updated: string, grade: string}
 *
 * @since 1.0.0
 */
final class DataQualityScorer
{
    private const CACHE_PREFIX = 'zb_quality_';

    private int $cacheTtl;

    /** @var array<string, int> Event dispatch counts (in-memory, per request cycle) */
    private array $eventCounts = [];

    /** @var array<string, int> Event validation failure counts */
    private array $validationFailures = [];

    /** @var array<string, int> Events with missing required params */
    private array $missingParamCounts = [];

    /** @var array<string, int> Events with timestamp anomalies */
    private array $timingAnomalies = [];

    /** @var array<string, float> Configurable dimension weights */
    private array $weights;

    private int $minSampleSize;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $qualityConfig = $config->get('zeroboiler.analytics.governance.quality', []);
        /** @var array{cache_ttl?: int, weights?: array<string, float>, min_sample_size?: int} $qualityConfig */

        $this->cacheTtl = (int) ($qualityConfig['cache_ttl'] ?? 3600);
        $this->weights = array_merge([
            'completeness' => 0.35,
            'consistency' => 0.30,
            'timeliness' => 0.15,
            'validity' => 0.20,
        ], $qualityConfig['weights'] ?? []);
        $this->minSampleSize = (int) ($qualityConfig['min_sample_size'] ?? 10);

        $this->loadMetrics();
    }

    /**
     * Record an event dispatch for quality tracking.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params  Event parameters
     * @param  bool  $valid  Whether the event passed validation
     * @param  list<string>  $missingParams  List of missing required parameter names
     * @param  bool  $timingAnomaly  Whether the event was dispatched outside expected timing
     */
    public function record(
        string $eventName,
        array $params,
        bool $valid = true,
        array $missingParams = [],
        bool $timingAnomaly = false,
    ): void {
        $this->eventCounts[$eventName] = ($this->eventCounts[$eventName] ?? 0) + 1;

        if (! $valid) {
            $this->validationFailures[$eventName] = ($this->validationFailures[$eventName] ?? 0) + 1;
        }

        if (! empty($missingParams)) {
            $this->missingParamCounts[$eventName] = ($this->missingParamCounts[$eventName] ?? 0) + 1;
        }

        if ($timingAnomaly) {
            $this->timingAnomalies[$eventName] = ($this->timingAnomalies[$eventName] ?? 0) + 1;
        }

        $this->persistMetrics();
    }

    /**
     * Calculate the overall data quality score.
     *
     * @return float 0-100
     */
    public function overallScore(): float
    {
        $dimensions = $this->calculateDimensions();

        $score = 0.0;
        foreach ($dimensions as $dimension) {
            $score += $dimension['score'] * $dimension['weight'];
        }

        return round($score, 2);
    }

    /**
     * Get a detailed quality report with all dimensions.
     *
     * @return QualityReport
     */
    public function report(): array
    {
        $dimensions = $this->calculateDimensions();
        $overall = $this->overallScore();

        return [
            'overall_score' => $overall,
            'dimensions' => $dimensions,
            'total_events_scored' => array_sum($this->eventCounts),
            'last_updated' => date('c'),
            'grade' => $this->grade($overall),
        ];
    }

    /**
     * Get quality score for a specific dimension.
     *
     * @param  'completeness'|'consistency'|'timeliness'|'validity'  $dimension
     * @return QualityDimension
     */
    public function dimensionScore(string $dimension): array
    {
        $dimensions = $this->calculateDimensions();

        return $dimensions[$dimension] ?? [
            'name' => $dimension,
            'score' => 0.0,
            'weight' => 0.0,
            'issues' => ["Unknown dimension '{$dimension}'"],
            'max_score' => 100.0,
        ];
    }

    /**
     * Get events sorted by quality issues (worst first).
     *
     * @param  int  $limit  Maximum events to return
     * @return list<array{event: string, score: float, issues: list<string>}>
     */
    public function worstEvents(int $limit = 10): array
    {
        $results = [];
        $allEvents = array_unique(array_merge(
            array_keys($this->eventCounts),
            array_keys($this->validationFailures),
            array_keys($this->missingParamCounts),
            array_keys($this->timingAnomalies),
        ));

        foreach ($allEvents as $eventName) {
            $issues = [];
            $count = $this->eventCounts[$eventName] ?? 0;
            $failures = $this->validationFailures[$eventName] ?? 0;
            $missing = $this->missingParamCounts[$eventName] ?? 0;
            $timing = $this->timingAnomalies[$eventName] ?? 0;

            if ($count > 0 && $failures > 0) {
                $issues[] = "Validation failures: {$failures}/{$count}";
            }
            if ($count > 0 && $missing > 0) {
                $issues[] = "Missing required params: {$missing}/{$count}";
            }
            if ($count > 0 && $timing > 0) {
                $issues[] = "Timing anomalies: {$timing}/{$count}";
            }

            $score = $count > 0
                ? round(max(0, 100 - (($failures + $missing + $timing) / $count) * 100), 2)
                : 0.0;

            $results[] = [
                'event' => $eventName,
                'score' => $score,
                'issues' => $issues,
            ];
        }

        usort($results, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Get the quality grade label for a given score.
     *
     * @return 'A'|'B'|'C'|'D'|'F'
     */
    public function grade(float $score): string
    {
        return match (true) {
            $score >= 95 => 'A',
            $score >= 85 => 'B',
            $score >= 70 => 'C',
            $score >= 50 => 'D',
            default => 'F',
        };
    }

    /**
     * Clear all quality metrics.
     */
    public function clear(): void
    {
        $this->eventCounts = [];
        $this->validationFailures = [];
        $this->missingParamCounts = [];
        $this->timingAnomalies = [];

        $this->persistMetrics();
    }

    /**
     * Calculate all quality dimensions.
     *
     * @return array<string, QualityDimension>
     */
    private function calculateDimensions(): array
    {
        $totalEvents = array_sum($this->eventCounts);

        if ($totalEvents < $this->minSampleSize) {
            return [
                'completeness' => $this->emptyDimension('completeness'),
                'consistency' => $this->emptyDimension('consistency'),
                'timeliness' => $this->emptyDimension('timeliness'),
                'validity' => $this->emptyDimension('validity'),
            ];
        }

        // Completeness: % of events with no missing required params
        $totalMissing = array_sum($this->missingParamCounts);
        $completenessScore = round(max(0, 100 - ($totalMissing / $totalEvents) * 100), 2);
        $completenessIssues = $this->missingParamEvents();

        // Consistency: % of events in catalog with proper naming
        $catalogNames = EventCatalog::names();
        $consistencyIssues = [];
        $inCatalog = 0;
        foreach (array_keys($this->eventCounts) as $name) {
            if (in_array($name, $catalogNames, true)) {
                $inCatalog++;
            } else {
                $consistencyIssues[] = "Event '{$name}' is not in the standard catalog";
            }
        }
        $consistencyScore = $totalEvents > 0 ? round(($inCatalog / $totalEvents) * 100, 2) : 100.0;

        // Timeliness: % of events without timing anomalies
        $totalTiming = array_sum($this->timingAnomalies);
        $timelinessScore = round(max(0, 100 - ($totalTiming / $totalEvents) * 100), 2);
        $timelinessIssues = [];
        foreach ($this->timingAnomalies as $event => $count) {
            $timelinessIssues[] = "Timing anomalies for '{$event}': {$count}";
        }

        // Validity: % of events that passed validation
        $totalFailures = array_sum($this->validationFailures);
        $validityScore = round(max(0, 100 - ($totalFailures / $totalEvents) * 100), 2);
        $validityIssues = $this->validationFailureEvents();

        return [
            'completeness' => [
                'name' => 'completeness',
                'score' => $completenessScore,
                'weight' => $this->weights['completeness'] ?? 0.35,
                'issues' => $completenessIssues,
                'max_score' => 100.0,
            ],
            'consistency' => [
                'name' => 'consistency',
                'score' => $consistencyScore,
                'weight' => $this->weights['consistency'] ?? 0.30,
                'issues' => $consistencyIssues,
                'max_score' => 100.0,
            ],
            'timeliness' => [
                'name' => 'timeliness',
                'score' => $timelinessScore,
                'weight' => $this->weights['timeliness'] ?? 0.15,
                'issues' => $timelinessIssues,
                'max_score' => 100.0,
            ],
            'validity' => [
                'name' => 'validity',
                'score' => $validityScore,
                'weight' => $this->weights['validity'] ?? 0.20,
                'issues' => $validityIssues,
                'max_score' => 100.0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function missingParamEvents(): array
    {
        $issues = [];
        foreach ($this->missingParamCounts as $event => $count) {
            $total = $this->eventCounts[$event] ?? 0;
            $rate = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $issues[] = "{$event}: {$count} events with missing params ({$rate}%)";
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function validationFailureEvents(): array
    {
        $issues = [];
        foreach ($this->validationFailures as $event => $count) {
            $total = $this->eventCounts[$event] ?? 0;
            $rate = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $issues[] = "{$event}: {$count} validation failures ({$rate}%)";
        }

        return $issues;
    }

    /**
     * @return QualityDimension
     */
    private function emptyDimension(string $name): array
    {
        return [
            'name' => $name,
            'score' => 0.0,
            'weight' => $this->weights[$name] ?? 0.0,
            'issues' => ['Insufficient data (need at least ' . $this->minSampleSize . ' events)'],
            'max_score' => 100.0,
        ];
    }

    /**
     * Load quality metrics from cache.
     */
    private function loadMetrics(): void
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'metrics');

            if (is_array($cached)) {
                $this->eventCounts = $cached['event_counts'] ?? [];
                $this->validationFailures = $cached['validation_failures'] ?? [];
                $this->missingParamCounts = $cached['missing_params'] ?? [];
                $this->timingAnomalies = $cached['timing_anomalies'] ?? [];
            }
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }

    /**
     * Persist quality metrics to cache.
     */
    private function persistMetrics(): void
    {
        try {
            $this->cache->put(
                self::CACHE_PREFIX . 'metrics',
                [
                    'event_counts' => $this->eventCounts,
                    'validation_failures' => $this->validationFailures,
                    'missing_params' => $this->missingParamCounts,
                    'timing_anomalies' => $this->timingAnomalies,
                ],
                $this->cacheTtl,
            );
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }
}
