<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Cross-provider event compatibility scoring matrix.
 *
 * Analyzes the event catalog to determine how well events are mapped
 * across all configured analytics providers. Provides:
 *
 * - Per-event compatibility scores (0-100) across providers
 * - Category-level coverage analysis
 * - Provider gap detection (events missing from a provider)
 * - Coverage completeness score for the entire catalog
 * - Recommendations for improving provider coverage
 *
 * A compatibility score of 100 means the event is fully mapped
 * to all providers. Lower scores indicate missing or null mappings.
 *
 * @since 21.0.0
 */
final class ProviderEventCompatibilityMatrix
{
    /** @var list<string> All supported provider keys */
    private const PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

    /** @var array<string, int> Provider weights for scoring (1-10, higher = more important) */
    private const PROVIDER_WEIGHTS = [
        'ga4' => 10,
        'meta' => 8,
        'posthog' => 7,
        'plausible' => 5,
        'mixpanel' => 6,
        'amplitude' => 6,
        'tiktok' => 4,
        'linkedin' => 4,
    ];

    /**
     * Get the full compatibility matrix for all catalog events.
     *
     * @return array{events: array<string, array{score: float, providers: array<string, string|null>, gaps: list<string>}>, summary: array{total_events: int, avg_score: float, perfect_coverage: int, provider_coverage: array<string, float>}, recommendations: list<string>}
     */
    public function analyze(): array
    {
        $allEvents = EventCatalog::all();
        $eventAnalysis = [];
        $totalScore = 0.0;
        $perfectCoverage = 0;
        $providerMappedCounts = array_fill_keys(self::PROVIDERS, 0);
        $providerTotalCounts = array_fill_keys(self::PROVIDERS, 0);

        foreach ($allEvents as $name => $entry) {
            $score = 0.0;
            $maxScore = 0.0;
            $gaps = [];
            $providerMappings = [];

            foreach (self::PROVIDERS as $provider) {
                $providerTotalCounts[$provider]++;
                $mapping = $entry[$provider] ?? null;
                $providerMappings[$provider] = $mapping;

                $weight = self::PROVIDER_WEIGHTS[$provider] ?? 1;
                $maxScore += $weight;

                if ($mapping !== null && $mapping !== '') {
                    $score += $weight;
                    $providerMappedCounts[$provider]++;
                } else {
                    $gaps[] = $provider;
                }
            }

            $eventScore = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;
            $totalScore += $eventScore;

            if ($eventScore === 100.0) {
                $perfectCoverage++;
            }

            $eventAnalysis[$name] = [
                'score' => $eventScore,
                'providers' => $providerMappings,
                'gaps' => $gaps,
            ];
        }

        $totalEvents = count($allEvents);
        $avgScore = $totalEvents > 0 ? round($totalScore / $totalEvents, 2) : 0.0;

        // Provider coverage percentages
        $providerCoverage = [];
        foreach (self::PROVIDERS as $provider) {
            $total = $providerTotalCounts[$provider];
            $mapped = $providerMappedCounts[$provider];
            $providerCoverage[$provider] = $total > 0 ? round(($mapped / $total) * 100, 2) : 0.0;
        }

        // Generate recommendations
        $recommendations = $this->generateRecommendations($eventAnalysis, $providerCoverage);

        return [
            'events' => $eventAnalysis,
            'summary' => [
                'total_events' => $totalEvents,
                'avg_score' => $avgScore,
                'perfect_coverage' => $perfectCoverage,
                'perfect_coverage_pct' => $totalEvents > 0 ? round(($perfectCoverage / $totalEvents) * 100, 2) : 0.0,
                'provider_coverage' => $providerCoverage,
            ],
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Get the compatibility score for a single event.
     *
     * @return array{score: float, providers: array<string, string|null>, gaps: list<string>, weighted_breakdown: array<string, array{mapped: bool, weight: int, mapping: string|null}>}
     */
    public function eventScore(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return [
                'score' => 0.0,
                'providers' => array_fill_keys(self::PROVIDERS, null),
                'gaps' => self::PROVIDERS,
                'weighted_breakdown' => [],
            ];
        }

        $score = 0.0;
        $maxScore = 0.0;
        $gaps = [];
        $providerMappings = [];
        $weightedBreakdown = [];

        foreach (self::PROVIDERS as $provider) {
            $mapping = $entry[$provider] ?? null;
            $providerMappings[$provider] = $mapping;

            $weight = self::PROVIDER_WEIGHTS[$provider] ?? 1;
            $maxScore += $weight;

            $isMapped = $mapping !== null && $mapping !== '';
            if ($isMapped) {
                $score += $weight;
            } else {
                $gaps[] = $provider;
            }

            $weightedBreakdown[$provider] = [
                'mapped' => $isMapped,
                'weight' => $weight,
                'mapping' => $mapping,
            ];
        }

        return [
            'score' => $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0,
            'providers' => $providerMappings,
            'gaps' => $gaps,
            'weighted_breakdown' => $weightedBreakdown,
        ];
    }

    /**
     * Get compatibility analysis for a specific category.
     *
     * @param  'ecommerce'|'saas'|'engagement'|'security'|'uptime'  $category
     * @return array{category: string, total_events: int, avg_score: float, events: array<string, array{score: float, gaps: list<string>}>}
     */
    public function categoryScore(string $category): array
    {
        $events = EventCatalog::category($category);

        if ($events === []) {
            return [
                'category' => $category,
                'total_events' => 0,
                'avg_score' => 0.0,
                'events' => [],
            ];
        }

        $totalScore = 0.0;
        $eventScores = [];

        foreach ($events as $name => $entry) {
            $score = 0.0;
            $maxScore = 0.0;
            $gaps = [];

            foreach (self::PROVIDERS as $provider) {
                $weight = self::PROVIDER_WEIGHTS[$provider] ?? 1;
                $maxScore += $weight;
                $mapping = $entry[$provider] ?? null;

                if ($mapping !== null && $mapping !== '') {
                    $score += $weight;
                } else {
                    $gaps[] = $provider;
                }
            }

            $eventScore = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;
            $totalScore += $eventScore;

            $eventScores[$name] = [
                'score' => $eventScore,
                'gaps' => $gaps,
            ];
        }

        return [
            'category' => $category,
            'total_events' => count($events),
            'avg_score' => count($events) > 0 ? round($totalScore / count($events), 2) : 0.0,
            'events' => $eventScores,
        ];
    }

    /**
     * Get events that have the lowest compatibility scores.
     *
     * Useful for identifying events that need additional provider mappings.
     *
     * @param  int  $limit  Number of events to return (default: 20)
     * @return list<array{name: string, score: float, gaps: list<string>, category: string|null}>
     */
    public function worstCoveredEvents(int $limit = 20): array
    {
        $analysis = $this->analyze();
        $scored = [];

        foreach ($analysis['events'] as $name => $data) {
            $category = EventCatalog::getCategory($name);
            $scored[] = [
                'name' => $name,
                'score' => $data['score'],
                'gaps' => $data['gaps'],
                'category' => $category,
            ];
        }

        usort($scored, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Get events with perfect 100% coverage.
     *
     * @return list<array{name: string, category: string|null}>
     */
    public function perfectlyCoveredEvents(): array
    {
        $analysis = $this->analyze();
        $perfect = [];

        foreach ($analysis['events'] as $name => $data) {
            if ($data['score'] === 100.0) {
                $perfect[] = [
                    'name' => $name,
                    'category' => EventCatalog::getCategory($name),
                ];
            }
        }

        return $perfect;
    }

    /**
     * Get the overall catalog maturity grade.
     *
     * Returns a letter grade (A+ through F) based on average coverage.
     *
     * @return array{grade: string, score: float, threshold: float, description: string}
     */
    public function maturityGrade(): array
    {
        $analysis = $this->analyze();
        $score = $analysis['summary']['avg_score'];

        return match (true) {
            $score >= 98.0 => ['grade' => 'A+', 'score' => $score, 'threshold' => 98.0, 'description' => 'Near-perfect provider coverage across all events'],
            $score >= 95.0 => ['grade' => 'A', 'score' => $score, 'threshold' => 95.0, 'description' => 'Excellent provider coverage with minimal gaps'],
            $score >= 90.0 => ['grade' => 'A-', 'score' => $score, 'threshold' => 90.0, 'description' => 'Strong provider coverage with minor gaps'],
            $score >= 85.0 => ['grade' => 'B+', 'score' => $score, 'threshold' => 85.0, 'description' => 'Good provider coverage, some events unmapped'],
            $score >= 80.0 => ['grade' => 'B', 'score' => $score, 'threshold' => 80.0, 'description' => 'Adequate coverage, notable provider gaps'],
            $score >= 70.0 => ['grade' => 'C', 'score' => $score, 'threshold' => 70.0, 'description' => 'Moderate coverage, significant gaps exist'],
            $score >= 50.0 => ['grade' => 'D', 'score' => $score, 'threshold' => 50.0, 'description' => 'Low coverage, many events missing mappings'],
            default => ['grade' => 'F', 'score' => $score, 'threshold' => 0.0, 'description' => 'Critical coverage gaps across most providers'],
        };
    }

    /**
     * Generate improvement recommendations.
     *
     * @param  array<string, array{score: float, gaps: list<string>}>  $eventAnalysis
     * @param  array<string, float>  $providerCoverage
     * @return list<string>
     */
    private function generateRecommendations(array $eventAnalysis, array $providerCoverage): array
    {
        $recommendations = [];

        // Check for providers with low coverage
        foreach ($providerCoverage as $provider => $coverage) {
            if ($coverage < 50.0) {
                $recommendations[] = "Provider '{$provider}' has very low coverage ({$coverage}%). Consider adding mappings for high-priority events.";
            } elseif ($coverage < 80.0) {
                $recommendations[] = "Provider '{$provider}' coverage is {$coverage}%. Some events are unmapped.";
            }
        }

        // Find events with zero mappings (except to GA4 which is always present)
        $zeroMapped = [];
        foreach ($eventAnalysis as $name => $data) {
            if ($data['score'] === 0.0) {
                $zeroMapped[] = $name;
            }
        }

        if (count($zeroMapped) > 0) {
            $sample = array_slice($zeroMapped, 0, 5);
            $recommendations[] = count($zeroMapped) . ' events have no provider mappings: ' . implode(', ', $sample);
        }

        // Check for events with only GA4 mapped
        $ga4Only = 0;
        foreach ($eventAnalysis as $name => $data) {
            if (count($data['gaps']) === 5 && ! in_array('ga4', $data['gaps'], true)) {
                $ga4Only++;
            }
        }

        if ($ga4Only > 10) {
            $recommendations[] = "{$ga4Only} events are only mapped to GA4. Consider adding Meta, PostHog, or Mixpanel mappings for cross-provider analytics.";
        }

        return $recommendations;
    }
}
