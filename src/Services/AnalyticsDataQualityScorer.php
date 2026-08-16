<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Composite analytics data quality scoring engine.
 *
 * Computes a holistic quality score (0-100) for analytics events
 * by evaluating multiple quality dimensions:
 *
 * - **Schema Compliance** — Does the event have all required parameters?
 * - **Provider Coverage** — Is the event mapped to all providers?
 * - **Payload Health** — Is the payload size reasonable? Are there no empty params?
 * - **Naming Convention** — Does the event name follow snake_case convention?
 * - **Identity Completeness** — Does the event have client_id and/or user_id?
 * - **Timestamp Accuracy** — Is the timestamp present and recent?
 *
 * Each dimension contributes a weighted score to the final composite.
 * The service also provides per-dimension breakdowns and actionable
 * improvement recommendations.
 *
 * @since 21.0.0
 */
final class AnalyticsDataQualityScorer
{
    /** @var array<string, int> Dimension weights (must sum to 100) */
    private const DIMENSION_WEIGHTS = [
        'schema_compliance' => 25,
        'provider_coverage' => 20,
        'payload_health' => 15,
        'naming_convention' => 10,
        'identity_completeness' => 15,
        'timestamp_accuracy' => 15,
    ];

    /**
     * Score a single analytics event across all quality dimensions.
     *
     * @return array{score: float, grade: string, dimensions: array<string, array{score: float, weight: int, issues: list<string>, max_score: float}>, recommendations: list<string>}
     */
    public function score(AnalyticsEvent $event): array
    {
        $dimensions = [
            'schema_compliance' => $this->scoreSchemaCompliance($event),
            'provider_coverage' => $this->scoreProviderCoverage($event),
            'payload_health' => $this->scorePayloadHealth($event),
            'naming_convention' => $this->scoreNamingConvention($event),
            'identity_completeness' => $this->scoreIdentityCompleteness($event),
            'timestamp_accuracy' => $this->scoreTimestampAccuracy($event),
        ];

        $totalScore = 0.0;
        $maxPossible = 0.0;

        foreach ($dimensions as $name => $dimension) {
            $weight = self::DIMENSION_WEIGHTS[$name] ?? 0;
            $contribution = $dimension['score'] * $weight / 100.0;
            $totalScore += $contribution;
            $maxPossible += $weight;
        }

        $compositeScore = $maxPossible > 0 ? round(($totalScore / $maxPossible) * 100, 2) : 0.0;

        return [
            'score' => $compositeScore,
            'grade' => $this->grade($compositeScore),
            'dimensions' => $dimensions,
            'recommendations' => $this->recommendations($event, $dimensions),
        ];
    }

    /**
     * Score multiple events and return aggregate statistics.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{average_score: float, min_score: float, max_score: float, grade_distribution: array<string, int>, dimension_averages: array<string, float>, total_events: int}
     */
    public function scoreBatch(array $events): array
    {
        if ($events === []) {
            return [
                'average_score' => 0.0,
                'min_score' => 0.0,
                'max_score' => 0.0,
                'grade_distribution' => [],
                'dimension_averages' => [],
                'total_events' => 0,
            ];
        }

        $scores = [];
        $gradeDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        $dimensionSums = array_fill_keys(array_keys(self::DIMENSION_WEIGHTS), 0.0);

        foreach ($events as $event) {
            $result = $this->score($event);
            $scores[] = $result['score'];
            $gradeDistribution[$result['grade']]++;

            foreach ($result['dimensions'] as $name => $dim) {
                $dimensionSums[$name] += $dim['score'];
            }
        }

        $count = count($events);
        $dimensionAverages = [];
        foreach ($dimensionSums as $name => $sum) {
            $dimensionAverages[$name] = round($sum / $count, 2);
        }

        return [
            'average_score' => round(array_sum($scores) / $count, 2),
            'min_score' => round(min($scores), 2),
            'max_score' => round(max($scores), 2),
            'grade_distribution' => $gradeDistribution,
            'dimension_averages' => $dimensionAverages,
            'total_events' => $count,
        ];
    }

    /**
     * Get the overall catalog quality score.
     *
     * Evaluates the entire event catalog's quality by checking
     * provider coverage, naming conventions, and structure.
     *
     * @return array{catalog_score: float, grade: string, total_events: int, dimensions: array<string, float>}
     */
    public function catalogScore(): array
    {
        $allEvents = EventCatalog::all();
        $total = count($allEvents);

        if ($total === 0) {
            return [
                'catalog_score' => 0.0,
                'grade' => 'N/A',
                'total_events' => 0,
                'dimensions' => [],
            ];
        }

        $namingScore = 0.0;
        $providerScores = [];

        foreach ($allEvents as $name => $entry) {
            // Naming convention check
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $namingScore += 100.0;
            }

            // Provider coverage per event
            $providers = self::PROVIDERS;
            foreach ($providers as $provider) {
                if (! isset($providerScores[$provider])) {
                    $providerScores[$provider] = 0.0;
                }
                if (isset($entry[$provider]) && $entry[$provider] !== null && $entry[$provider] !== '') {
                    $providerScores[$provider] += 100.0;
                }
            }
        }

        $namingAvg = round($namingScore / $total, 2);
        $providerAverages = [];
        foreach ($providerScores as $provider => $score) {
            $providerAverages[$provider] = round($score / $total, 2);
        }

        $overallProviderAvg = count($providerAverages) > 0
            ? round(array_sum($providerAverages) / count($providerAverages), 2)
            : 0.0;

        $compositeScore = round(($namingAvg * 0.3) + ($overallProviderAvg * 0.7), 2);

        return [
            'catalog_score' => $compositeScore,
            'grade' => $this->grade($compositeScore),
            'total_events' => $total,
            'dimensions' => array_merge(
                ['naming_convention' => $namingAvg],
                $providerAverages,
            ),
        ];
    }

    /**
     * Score schema compliance dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scoreSchemaCompliance(AnalyticsEvent $event): array
    {
        $issues = [];
        $score = 100.0;

        if (! EventCatalog::has($event->name)) {
            $issues[] = "Event '{$event->name}' is not registered in the catalog";
            $score -= 40.0;
        }

        $paramCount = count($event->params);
        if ($paramCount === 0) {
            // Only penalize for events that typically need params
            if (in_array($event->name, ['purchase', 'add_to_cart', 'sign_up', 'search', 'login'], true)) {
                $issues[] = 'Event has no parameters — typical usage requires parameters';
                $score -= 30.0;
            }
        }

        foreach ($event->params as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $issues[] = "Parameter '{$key}' is an empty string";
                $score -= 5.0;
            }
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['schema_compliance'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Score provider coverage dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scoreProviderCoverage(AnalyticsEvent $event): array
    {
        $issues = [];
        $entry = EventCatalog::get($event->name);
        $score = 100.0;

        if ($entry === null) {
            return [
                'score' => 0.0,
                'weight' => self::DIMENSION_WEIGHTS['provider_coverage'],
                'issues' => ['Event not in catalog — cannot assess provider coverage'],
                'max_score' => 100.0,
            ];
        }

        $providers = self::PROVIDERS;
        $missingProviders = [];

        foreach ($providers as $provider) {
            $mapping = $entry[$provider] ?? null;
            if ($mapping === null || $mapping === '') {
                $missingProviders[] = $provider;
                $penalty = match ($provider) {
                    'ga4' => 30.0,
                    'meta' => 20.0,
                    'posthog' => 15.0,
                    'mixpanel' => 10.0,
                    'amplitude' => 10.0,
                    'plausible' => 10.0,
                    default => 10.0,
                };
                $score -= $penalty;
            }
        }

        if ($missingProviders !== []) {
            $issues[] = 'Missing provider mappings: ' . implode(', ', $missingProviders);
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['provider_coverage'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Score payload health dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scorePayloadHealth(AnalyticsEvent $event): array
    {
        $issues = [];
        $score = 100.0;

        $jsonSize = strlen(json_encode($event->params));

        if ($jsonSize > 64000) {
            $issues[] = 'Payload exceeds 64KB limit';
            $score -= 50.0;
        } elseif ($jsonSize > 32000) {
            $issues[] = "Payload is large ({$jsonSize} bytes)";
            $score -= 20.0;
        } elseif ($jsonSize > 16000) {
            $score -= 5.0;
        }

        if (count($event->params) > 50) {
            $issues[] = 'Too many parameters (' . count($event->params) . ')';
            $score -= 15.0;
        } elseif (count($event->params) > 30) {
            $issues[] = 'High parameter count (' . count($event->params) . ')';
            $score -= 5.0;
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['payload_health'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Score naming convention dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scoreNamingConvention(AnalyticsEvent $event): array
    {
        $issues = [];
        $score = 100.0;

        if (! preg_match('/^[a-z][a-z0-9_]{1,99}$/', $event->name)) {
            $issues[] = "Event name '{$event->name}' does not follow snake_case convention";
            $score -= 50.0;
        }

        foreach (array_keys($event->params) as $key) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key) && ! str_starts_with($key, '_')) {
                $issues[] = "Parameter key '{$key}' does not follow snake_case convention";
                $score -= 5.0;
            }
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['naming_convention'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Score identity completeness dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scoreIdentityCompleteness(AnalyticsEvent $event): array
    {
        $issues = [];
        $score = 100.0;

        $hasClientId = $event->clientId !== null && $event->clientId !== '';
        $hasUserId = $event->userId !== null && $event->userId !== '';

        if (! $hasClientId && ! $hasUserId) {
            $issues[] = 'No identity context (client_id or user_id) attached to event';
            $score -= 60.0;
        } elseif (! $hasClientId) {
            $issues[] = 'No client_id — cross-device tracking will be limited';
            $score -= 20.0;
        } elseif (! $hasUserId) {
            $issues[] = 'No user_id — user-level analytics will be anonymous';
            $score -= 15.0;
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['identity_completeness'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Score timestamp accuracy dimension.
     *
     * @return array{score: float, weight: int, issues: list<string>, max_score: float}
     */
    private function scoreTimestampAccuracy(AnalyticsEvent $event): array
    {
        $issues = [];
        $score = 100.0;

        if ($event->timestamp === null) {
            $issues[] = 'No timestamp provided — will use dispatch time (may cause ordering issues)';
            $score -= 30.0;
        } else {
            $now = new \DateTimeImmutable();
            $diff = abs($now->getTimestamp() - $event->timestamp->getTimestamp());

            if ($diff > 3600) {
                $issues[] = "Timestamp is {$diff}s away from current time (may indicate clock skew)";
                $score -= 20.0;
            } elseif ($diff > 300) {
                $issues[] = "Timestamp is {$diff}s old — consider using dispatch time";
                $score -= 5.0;
            }
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'weight' => self::DIMENSION_WEIGHTS['timestamp_accuracy'],
            'issues' => $issues,
            'max_score' => 100.0,
        ];
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function grade(float $score): string
    {
        return match (true) {
            $score >= 90.0 => 'A',
            $score >= 80.0 => 'B',
            $score >= 70.0 => 'C',
            $score >= 60.0 => 'D',
            default => 'F',
        };
    }

    /**
     * Generate actionable improvement recommendations.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, array{score: float, issues: list<string>}>  $dimensions
     * @return list<string>
     */
    private function recommendations(AnalyticsEvent $event, array $dimensions): array
    {
        $recs = [];

        if (empty($dimensions['identity_completeness']['issues'])) {
            // Already good
        } else {
            $recs[] = 'Attach both client_id and user_id to improve identity resolution and cross-device tracking.';
        }

        if (empty($dimensions['provider_coverage']['issues'])) {
            // Already good
        } else {
            $recs[] = 'Add missing provider mappings in the event catalog for broader analytics coverage.';
        }

        if (empty($dimensions['schema_compliance']['issues'])) {
            // Already good
        } else {
            $recs[] = 'Register the event in the EventCatalog and provide required parameters.';
        }

        if ($dimensions['payload_health']['score'] < 80.0) {
            $recs[] = 'Reduce payload size by removing unnecessary parameters or truncating string values.';
        }

        if ($dimensions['naming_convention']['score'] < 80.0) {
            $recs[] = 'Follow snake_case naming convention for event names and parameter keys.';
        }

        return $recs;
    }

    /**
     * List of all supported analytics providers.
     *
     * @var list<string>
     */
    private const PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];
}
