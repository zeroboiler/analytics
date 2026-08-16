<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Data Quality Scoring Engine for analytics events.
 *
 * Computes a multi-dimensional quality score for each event based on:
 * - Completeness: Are required fields present?
 * - Consistency: Do field values follow expected patterns?
 * - Timeliness: Was the event received within the expected time window?
 * - Validity: Do field types match the expected schema?
 * - Uniqueness: Is this event a duplicate?
 *
 * The composite score ranges from 0.0 (poor quality) to 100.0 (perfect quality).
 *
 * @since 203.0.0
 */
final class DataQualityScoringEngine
{
    /** @var array<string, float> Dimension weights (must sum to 1.0) */
    private array $weights;

    /** @var int Max allowed event age in seconds for full timeliness score */
    private int $freshnessThresholdSeconds;

    /** @var array<string, array{required: list<string>, recommended: list<string>, patterns: array<string, string>}> */
    private array $categorySchemas;

    /**
     * @param  int|null  $freshnessThreshold  Max event age in seconds (default 60s)
     */
    public function __construct(?int $freshnessThreshold = null): void
    {
        $this->weights = [
            'completeness' => 0.30,
            'consistency' => 0.20,
            'timeliness' => 0.15,
            'validity' => 0.20,
            'uniqueness' => 0.15,
        ];

        $this->freshnessThresholdSeconds = $freshnessThreshold ?? 60;

        $this->categorySchemas = [
            'ecommerce' => [
                'required' => ['transaction_id', 'value', 'currency'],
                'recommended' => ['item_id', 'item_name', 'quantity'],
                'patterns' => [
                    'transaction_id' => '/^[a-zA-Z0-9\-_]+$/',
                    'currency' => '/^[A-Z]{3}$/',
                ],
            ],
            'saas' => [
                'required' => [],
                'recommended' => ['user_id', 'plan', 'period'],
                'patterns' => [
                    'plan' => '/^[a-z_]+$/',
                ],
            ],
            'engagement' => [
                'required' => [],
                'recommended' => ['page_url', 'referrer'],
                'patterns' => [
                    'page_url' => '/^https?:\/\//',
                ],
            ],
        ];
    }

    /**
     * Score a single analytics event for data quality.
     *
     * @param  AnalyticsEvent  $event  The event to score
     * @return array{score: float, grade: string, dimensions: array<string, array{score: float, max: float, details: string}>, issues: list<array{severity: string, dimension: string, message: string}>}
     */
    public function scoreEvent(AnalyticsEvent $event): array
    {
        $completeness = $this->scoreCompleteness($event);
        $consistency = $this->scoreConsistency($event);
        $timeliness = $this->scoreTimeliness($event);
        $validity = $this->scoreValidity($event);
        $uniqueness = $this->scoreUniqueness($event);

        $dimensions = [
            'completeness' => $completeness,
            'consistency' => $consistency,
            'timeliness' => $timeliness,
            'validity' => $validity,
            'uniqueness' => $uniqueness,
        ];

        // Weighted composite score
        $composite = 0.0;
        foreach ($dimensions as $key => $dim) {
            $composite += $dim['score'] * $this->weights[$key];
        }

        $issues = $this->collectIssues($dimensions);

        return [
            'score' => round($composite, 2),
            'grade' => $this->gradeFromScore($composite),
            'dimensions' => $dimensions,
            'issues' => $issues,
        ];
    }

    /**
     * Score a batch of events and return aggregate statistics.
     *
     * @param  list<AnalyticsEvent>  $events  Events to score
     * @return array{avg_score: float, min_score: float, max_score: float, grade_distribution: array<string, int>, dimension_averages: array<string, float>, total_issues: int}
     */
    public function scoreBatch(array $events): array
    {
        if ($events === []) {
            return [
                'avg_score' => 0.0,
                'min_score' => 0.0,
                'max_score' => 0.0,
                'grade_distribution' => [],
                'dimension_averages' => [],
                'total_issues' => 0,
            ];
        }

        $scores = [];
        $gradeDistribution = [];
        $dimensionTotals = [];

        foreach ($events as $event) {
            $result = $this->scoreEvent($event);
            $scores[] = $result['score'];
            $grade = $result['grade'];
            $gradeDistribution[$grade] = ($gradeDistribution[$grade] ?? 0) + 1;

            foreach ($result['dimensions'] as $dim => $data) {
                if (! isset($dimensionTotals[$dim])) {
                    $dimensionTotals[$dim] = 0.0;
                }
                $dimensionTotals[$dim] += $data['score'];
            }
        }

        $count = count($events);
        $dimensionAverages = [];
        foreach ($dimensionTotals as $dim => $total) {
            $dimensionAverages[$dim] = round($total / $count, 2);
        }

        return [
            'avg_score' => round(array_sum($scores) / $count, 2),
            'min_score' => round(min($scores), 2),
            'max_score' => round(max($scores), 2),
            'grade_distribution' => $gradeDistribution,
            'dimension_averages' => $dimensionAverages,
            'total_issues' => array_sum(
                array_map(fn (AnalyticsEvent $e): int => count($this->scoreEvent($e)['issues']), $events),
            ),
        ];
    }

    /**
     * Get the quality score for a specific dimension only.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @param  string  $dimension  Dimension name (completeness|consistency|timeliness|validity|uniqueness)
     * @return float  Score from 0.0 to 100.0
     */
    public function scoreDimension(AnalyticsEvent $event, string $dimension): float
    {
        return match ($dimension) {
            'completeness' => $this->scoreCompleteness($event)['score'],
            'consistency' => $this->scoreConsistency($event)['score'],
            'timeliness' => $this->scoreTimeliness($event)['score'],
            'validity' => $this->scoreValidity($event)['score'],
            'uniqueness' => $this->scoreUniqueness($event)['score'],
            default => 0.0,
        };
    }

    /**
     * Get a diagnostic summary of the scoring engine configuration.
     *
     * @return array{weights: array<string, float>, freshness_threshold: int, categories: list<string>}
     */
    public function diagnosticSummary(): array
    {
        return [
            'weights' => $this->weights,
            'freshness_threshold' => $this->freshnessThresholdSeconds,
            'categories' => array_keys($this->categorySchemas),
        ];
    }

    /**
     * Score the completeness dimension.
     *
     * Checks if required and recommended fields are present.
     *
     * @return array{score: float, max: float, details: string}
     */
    private function scoreCompleteness(AnalyticsEvent $event): array
    {
        $category = $event->category ?? 'engagement';
        $schema = $this->categorySchemas[$category] ?? ['required' => [], 'recommended' => []];
        $params = $event->params;

        $requiredFields = $schema['required'];
        $recommendedFields = $schema['recommended'];

        $requiredPresent = 0;
        $requiredTotal = count($requiredFields);

        foreach ($requiredFields as $field) {
            if (isset($params[$field]) && $params[$field] !== null && $params[$field] !== '') {
                $requiredPresent++;
            }
        }

        $recommendedPresent = 0;
        $recommendedTotal = count($recommendedFields);

        foreach ($recommendedFields as $field) {
            if (isset($params[$field]) && $params[$field] !== null && $params[$field] !== '') {
                $recommendedPresent++;
            }
        }

        // Identity fields are universally important
        $identityScore = ($event->clientId !== null ? 50 : 0) + ($event->userId !== null ? 50 : 0);

        // Weighted completeness score
        $requiredScore = $requiredTotal > 0 ? ($requiredPresent / $requiredTotal) * 60 : 60;
        $recommendedScore = $recommendedTotal > 0 ? ($recommendedPresent / $recommendedTotal) * 20 : 20;

        $score = $requiredScore + $recommendedScore + ($identityScore * 0.2);

        return [
            'score' => min(100.0, round($score, 2)),
            'max' => 100.0,
            'details' => sprintf(
                'Required: %d/%d, Recommended: %d/%d, Identity: %s',
                $requiredPresent,
                $requiredTotal,
                $recommendedPresent,
                $recommendedTotal,
                ($event->clientId !== null ? 'client' : 'none') . ($event->userId !== null ? '+user' : ''),
            ),
        ];
    }

    /**
     * Score the consistency dimension.
     *
     * Checks if field values follow expected patterns.
     *
     * @return array{score: float, max: float, details: string}
     */
    private function scoreConsistency(AnalyticsEvent $event): array
    {
        $category = $event->category ?? 'engagement';
        $schema = $this->categorySchemas[$category] ?? ['patterns' => []];
        $patterns = $schema['patterns'] ?? [];
        $params = $event->params;

        if ($patterns === []) {
            return ['score' => 100.0, 'max' => 100.0, 'details' => 'No patterns defined'];
        }

        $matched = 0;
        $total = count($patterns);

        foreach ($patterns as $field => $pattern) {
            $value = $params[$field] ?? null;

            if ($value === null || $value === '') {
                continue; // Don't penalize missing optional fields
            }

            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $matched++;
            }
        }

        $score = $total > 0 ? ($matched / $total) * 100 : 100.0;

        return [
            'score' => round($score, 2),
            'max' => 100.0,
            'details' => sprintf('Pattern match: %d/%d', $matched, $total),
        ];
    }

    /**
     * Score the timeliness dimension.
     *
     * Checks if the event timestamp is within the freshness threshold.
     *
     * @return array{score: float, max: float, details: string}
     */
    private function scoreTimeliness(AnalyticsEvent $event): array
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();

        $age = time() - $timestamp->getTimestamp();

        if ($age <= 0) {
            // Future timestamp — suspicious
            $score = 50.0;
            $details = 'Future timestamp detected';
        } elseif ($age <= $this->freshnessThresholdSeconds) {
            // Within freshness window
            $ratio = $age / $this->freshnessThresholdSeconds;
            $score = 100.0 - ($ratio * 30.0); // 70-100 range
            $details = sprintf('Age: %ds (within %ds threshold)', $age, $this->freshnessThresholdSeconds);
        } elseif ($age <= $this->freshnessThresholdSeconds * 10) {
            // Delayed but acceptable
            $score = max(30.0, 70.0 - (($age - $this->freshnessThresholdSeconds) / ($this->freshnessThresholdSeconds * 9)) * 40.0);
            $details = sprintf('Delayed: %ds', $age);
        } else {
            // Very old event
            $score = 10.0;
            $details = sprintf('Stale: %ds', $age);
        }

        return [
            'score' => round($score, 2),
            'max' => 100.0,
            'details' => $details,
        ];
    }

    /**
     * Score the validity dimension.
     *
     * Checks field types against expected types.
     *
     * @return array{score: float, max: float, details: string}
     */
    private function scoreValidity(AnalyticsEvent $event): array
    {
        $params = $event->params;
        $issues = 0;
        $checked = 0;

        // Common field type expectations
        $typeExpectations = [
            'value' => 'numeric',
            'price' => 'numeric',
            'quantity' => 'integer',
            'revenue' => 'numeric',
            'tax' => 'numeric',
            'shipping' => 'numeric',
            'discount' => 'numeric',
            'item_id' => 'string',
            'transaction_id' => 'string',
            'currency' => 'string',
            'order_id' => 'string',
            'coupon' => 'string',
        ];

        foreach ($typeExpectations as $field => $expectedType) {
            if (! array_key_exists($field, $params)) {
                continue;
            }

            $checked++;
            $value = $params[$field];

            if ($value === null) {
                continue;
            }

            $valid = match ($expectedType) {
                'numeric' => is_numeric($value),
                'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'string' => is_string($value) && $value !== '',
                default => true,
            };

            if (! $valid) {
                $issues++;
            }
        }

        // Event name validity
        $checked++;
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $event->name)) {
            $issues++;
        }

        $score = $checked > 0 ? ((1.0 - ($issues / $checked)) * 100.0) : 100.0;

        return [
            'score' => round($score, 2),
            'max' => 100.0,
            'details' => sprintf('Type checks: %d passed, %d failed', $checked - $issues, $issues),
        ];
    }

    /**
     * Score the uniqueness dimension.
     *
     * Checks for common duplicate indicators. Full dedup requires cache/state.
     *
     * @return array{score: float, max: float, details: string}
     */
    private function scoreUniqueness(AnalyticsEvent $event): array
    {
        // Heuristic-based uniqueness scoring
        // Full implementation would use dedup cache

        $hasClientId = $event->clientId !== null && $event->clientId !== '';
        $hasTimestamp = $event->timestamp !== null;
        $hasParams = count($event->params) > 0;

        // Events with identity + timestamp + params are more likely unique
        $signals = ($hasClientId ? 1 : 0) + ($hasTimestamp ? 1 : 0) + ($hasParams ? 1 : 0);

        $score = match ($signals) {
            3 => 95.0,
            2 => 70.0,
            1 => 40.0,
            default => 10.0,
        };

        return [
            'score' => round($score, 2),
            'max' => 100.0,
            'details' => sprintf(
                'Identity signals: %d/3 (client_id: %s, timestamp: %s, params: %s)',
                $signals,
                $hasClientId ? 'yes' : 'no',
                $hasTimestamp ? 'yes' : 'no',
                $hasParams ? 'yes' : 'no',
            ),
        ];
    }

    /**
     * Collect quality issues from dimension scores.
     *
     * @return list<array{severity: string, dimension: string, message: string}>
     */
    private function collectIssues(array $dimensions): array
    {
        $issues = [];

        foreach ($dimensions as $dim => $data) {
            if ($data['score'] < 50.0) {
                $issues[] = [
                    'severity' => 'critical',
                    'dimension' => $dim,
                    'message' => $data['details'],
                ];
            } elseif ($data['score'] < 75.0) {
                $issues[] = [
                    'severity' => 'warning',
                    'dimension' => $dim,
                    'message' => $data['details'],
                ];
            }
        }

        return $issues;
    }

    /**
     * Convert numeric score to letter grade.
     */
    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 95.0 => 'A+',
            $score >= 90.0 => 'A',
            $score >= 85.0 => 'A-',
            $score >= 80.0 => 'B+',
            $score >= 75.0 => 'B',
            $score >= 70.0 => 'B-',
            $score >= 65.0 => 'C+',
            $score >= 60.0 => 'C',
            $score >= 50.0 => 'D',
            default => 'F',
        };
    }
}
