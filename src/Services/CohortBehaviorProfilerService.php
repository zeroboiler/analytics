<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Automated behavioral cohort clustering service.
 *
 * Analyzes user event patterns to classify users into behavioral cohorts:
 * power_users, engaged, at_risk, dormant, new, churning, and expanding.
 *
 * Classification is based on configurable thresholds for event frequency,
 * recency, diversity, and conversion signals. Cohort assignments are
 * cache-backed for dashboard performance.
 *
 * Inspired by Amplitude Behavioral Cohorts, Mixpanel User Segmentation,
 * and PostHog Cohort Analysis.
 *
 * @since 8.1.0
 */
final class CohortBehaviorProfilerService
{
    /** Default cohort definitions with classification rules. */
    private const DEFAULT_COHORT_DEFINITIONS = [
        'power_users' => [
            'label' => 'Power Users',
            'description' => 'High-frequency, high-diversity users who drive product adoption',
            'color' => '#10b981',
            'thresholds' => [
                'min_events_per_day' => 20,
                'min_unique_events' => 8,
                'min_session_duration' => 600,
                'recency_max_hours' => 24,
            ],
            'signals' => ['feature_used', 'export', 'plan_upgrade', 'team_created'],
        ],
        'engaged' => [
            'label' => 'Engaged',
            'description' => 'Active users with consistent usage patterns',
            'color' => '#3b82f6',
            'thresholds' => [
                'min_events_per_day' => 5,
                'min_unique_events' => 3,
                'min_session_duration' => 180,
                'recency_max_hours' => 48,
            ],
            'signals' => ['page_view', 'login', 'feature_used', 'search'],
        ],
        'at_risk' => [
            'label' => 'At Risk',
            'description' => 'Users showing declining engagement patterns',
            'color' => '#f59e0b',
            'thresholds' => [
                'min_events_per_day' => 1,
                'max_events_per_day' => 3,
                'recency_max_hours' => 72,
                'decline_rate_threshold' => 0.3,
            ],
            'signals' => ['login', 'page_view'],
            'negative_signals' => ['error', 'support_contact'],
        ],
        'dormant' => [
            'label' => 'Dormant',
            'description' => 'Users with minimal or no recent activity',
            'color' => '#6b7280',
            'thresholds' => [
                'min_events_per_day' => 0,
                'recency_min_hours' => 168,
            ],
            'signals' => [],
        ],
        'new' => [
            'label' => 'New Users',
            'description' => 'Recently registered users in their first 7 days',
            'color' => '#8b5cf6',
            'thresholds' => [
                'max_account_age_days' => 7,
                'min_events_total' => 1,
            ],
            'signals' => ['sign_up', 'email_verified', 'onboarding_step'],
        ],
        'churning' => [
            'label' => 'Churning',
            'description' => 'Users with high churn probability signals',
            'color' => '#ef4444',
            'thresholds' => [
                'min_decline_rate' => 0.5,
                'recency_max_hours' => 120,
                'cancellation_signal_weight' => 2.0,
            ],
            'signals' => ['cancellation', 'plan_downgrade', 'support_contact', 'error'],
        ],
        'expanding' => [
            'label' => 'Expanding',
            'description' => 'Users showing expansion and upsell signals',
            'color' => '#06b6d4',
            'thresholds' => [
                'min_events_per_day' => 8,
                'min_expansion_signals' => 2,
                'recency_max_hours' => 24,
            ],
            'signals' => ['plan_upgrade', 'team_created', 'feature_used', 'invite_sent', 'workspace_created'],
        ],
    ];

    private CacheRepository $cache;

    /** @var array{enabled: bool, cache_ttl: int, lookback_days: int, cohort_definitions: array<string, mixed>, min_events_for_profiling: int} */
    private array $config;

    /**
     * @param  CacheRepository  $cache
     * @param  array{enabled?: bool, cache_ttl?: int, lookback_days?: int, cohort_definitions?: array<string, mixed>, min_events_for_profiling?: int}  $config
     */
    public function __construct(CacheRepository $cache, array $config = [])
    {
        $this->cache = $cache;
        $this->config = [
            'enabled' => $config['enabled'] ?? true,
            'cache_ttl' => $config['cache_ttl'] ?? 300,
            'lookback_days' => $config['lookback_days'] ?? 30,
            'cohort_definitions' => $config['cohort_definitions'] ?? [],
            'min_events_for_profiling' => $config['min_events_for_profiling'] ?? 3,
        ];
    }

    /**
     * Profile a user based on their event history.
     *
     * Analyzes the provided events and classifies the user into a
     * behavioral cohort based on frequency, recency, diversity,
     * and signal patterns.
     *
     * @param  string  $identity  User ID or client ID
     * @param  array<int, AnalyticsEvent>  $events  User's recent events
     * @return array{cohort: string, label: string, score: float, confidence: float, signals: array<string, int>, profile: array<string, mixed>}
     */
    public function profile(string $identity, array $events): array
    {
        if (! $this->config['enabled']) {
            return $this->emptyProfile($identity);
        }

        if (count($events) < $this->config['min_events_for_profiling']) {
            return [
                ...$this->emptyProfile($identity),
                'cohort' => count($events) > 0 ? 'new' : 'dormant',
                'label' => count($events) > 0 ? 'New Users' : 'Dormant',
                'confidence' => 0.3,
            ];
        }

        $metrics = $this->computeMetrics($identity, $events);
        $definitions = $this->getCohortDefinitions();

        $bestCohort = 'engaged';
        $bestScore = 0.0;
        $bestLabel = 'Engaged';
        $scores = [];

        foreach ($definitions as $cohortName => $definition) {
            $score = $this->scoreCohort($metrics, $definition);
            $scores[$cohortName] = $score;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCohort = $cohortName;
                $bestLabel = $definition['label'] ?? $cohortName;
            }
        }

        // Normalize best score to 0-1 confidence
        $totalScore = array_sum($scores);
        $confidence = $totalScore > 0 ? $bestScore / $totalScore : 0.0;

        return [
            'identity' => $identity,
            'cohort' => $bestCohort,
            'label' => $bestLabel,
            'score' => round($bestScore, 4),
            'confidence' => round($confidence, 4),
            'signals' => $metrics['signal_counts'],
            'profile' => $metrics,
            'all_scores' => array_map(fn (float $s): float => round($s, 4), $scores),
            'profiled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Batch-profile multiple users.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents  Map of identity => events
     * @return array<string, array{cohort: string, label: string, score: float, confidence: float}>
     */
    public function profileBatch(array $userEvents): array
    {
        $results = [];

        foreach ($userEvents as $identity => $events) {
            $profile = $this->profile($identity, $events);
            $results[$identity] = [
                'cohort' => $profile['cohort'],
                'label' => $profile['label'],
                'score' => $profile['score'],
                'confidence' => $profile['confidence'],
            ];
        }

        return $results;
    }

    /**
     * Get cohort distribution summary across all profiled users.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @return array{cohorts: array<string, array{count: int, percentage: float, label: string, color: string}>, total_users: int, dominant_cohort: string}
     */
    public function distribution(array $userEvents): array
    {
        $profiles = $this->profileBatch($userEvents);
        $totalUsers = count($profiles);
        $cohortCounts = [];
        $definitions = $this->getCohortDefinitions();

        foreach ($profiles as $identity => $profile) {
            $cohort = $profile['cohort'];
            if (! isset($cohortCounts[$cohort])) {
                $cohortCounts[$cohort] = 0;
            }
            $cohortCounts[$cohort]++;
        }

        $cohorts = [];
        $maxCount = 0;
        $dominantCohort = 'engaged';

        foreach ($cohortCounts as $cohort => $count) {
            $def = $definitions[$cohort] ?? [];
            $percentage = $totalUsers > 0 ? round(($count / $totalUsers) * 100, 2) : 0.0;

            $cohorts[$cohort] = [
                'count' => $count,
                'percentage' => $percentage,
                'label' => $def['label'] ?? $cohort,
                'color' => $def['color'] ?? '#6b7280',
            ];

            if ($count > $maxCount) {
                $maxCount = $count;
                $dominantCohort = $cohort;
            }
        }

        // Sort by count descending
        uasort($cohorts, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'cohorts' => $cohorts,
            'total_users' => $totalUsers,
            'dominant_cohort' => $dominantCohort,
            'profiled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get cohort transition analysis — how users move between cohorts over time.
     *
     * @param  array<string, array{previous: string, current: string}>  $transitions  Map of identity => {previous_cohort, current_cohort}
     * @return array{matrix: array<string, array<string, int>>, total_transitions: int, net_movements: array<string, int>, retention_rates: array<string, float>}
     */
    public function transitionAnalysis(array $transitions): array
    {
        $matrix = [];
        $definitions = $this->getCohortDefinitions();
        $cohortNames = array_keys($definitions);

        // Initialize matrix
        foreach ($cohortNames as $from) {
            foreach ($cohortNames as $to) {
                $matrix[$from][$to] = 0;
            }
        }

        $retentionCounts = [];
        $totalByCohort = [];

        foreach ($transitions as $transition) {
            $from = $transition['previous'];
            $to = $transition['current'];

            if (! isset($matrix[$from][$to])) {
                $matrix[$from][$to] = 0;
            }
            $matrix[$from][$to]++;

            // Track retention (stayed in same cohort)
            if ($from === $to) {
                $retentionCounts[$from] = ($retentionCounts[$from] ?? 0) + 1;
            }

            $totalByCohort[$from] = ($totalByCohort[$from] ?? 0) + 1;
        }

        // Compute retention rates
        $retentionRates = [];
        foreach ($totalByCohort as $cohort => $total) {
            $retained = $retentionCounts[$cohort] ?? 0;
            $retentionRates[$cohort] = $total > 0 ? round(($retained / $total) * 100, 2) : 0.0;
        }

        // Compute net movements
        $netMovements = [];
        foreach ($cohortNames as $cohort) {
            $gained = 0;
            $lost = 0;

            foreach ($transitions as $transition) {
                if ($transition['current'] === $cohort && $transition['previous'] !== $cohort) {
                    $gained++;
                }
                if ($transition['previous'] === $cohort && $transition['current'] !== $cohort) {
                    $lost++;
                }
            }

            $netMovements[$cohort] = $gained - $lost;
        }

        return [
            'matrix' => $matrix,
            'total_transitions' => count($transitions),
            'net_movements' => $netMovements,
            'retention_rates' => $retentionRates,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Identify users who are likely to move to a target cohort.
     *
     * Useful for proactive engagement — find "almost power users" or
     * "about to churn" users before they actually transition.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @param  string  $targetCohort  Cohort to predict movement toward
     * @param  float  $threshold  Minimum probability threshold (0.0-1.0)
     * @return array{candidates: array<string, array{current_cohort: string, probability: float, signals: array<string, int>}>}
     */
    public function predictTransitions(array $userEvents, string $targetCohort, float $threshold = 0.6): array
    {
        $definitions = $this->getCohortDefinitions();
        $targetDef = $definitions[$targetCohort] ?? null;

        if ($targetDef === null) {
            return ['candidates' => [], 'error' => "Unknown cohort: {$targetCohort}"];
        }

        $candidates = [];

        foreach ($userEvents as $identity => $events) {
            $profile = $this->profile($identity, $events);
            $currentCohort = $profile['cohort'];

            // Skip users already in the target cohort
            if ($currentCohort === $targetCohort) {
                continue;
            }

            // Check if the user's score for the target cohort exceeds the threshold
            $targetScore = $profile['all_scores'][$targetCohort] ?? 0.0;
            $totalScore = array_sum($profile['all_scores']);
            $probability = $totalScore > 0 ? $targetScore / $totalScore : 0.0;

            if ($probability >= $threshold) {
                $candidates[$identity] = [
                    'current_cohort' => $currentCohort,
                    'probability' => round($probability, 4),
                    'signals' => $profile['signals'],
                ];
            }
        }

        // Sort by probability descending
        uasort($candidates, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return [
            'candidates' => $candidates,
            'target_cohort' => $targetCohort,
            'threshold' => $threshold,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get behavioral insights for a specific cohort.
     *
     * @param  string  $cohortName
     * @param  array<string, array<int, AnalyticsEvent>>  $userEvents
     * @return array{cohort: string, label: string, user_count: int, top_events: array<string, int>, avg_events_per_user: float, common_properties: array<string, mixed>}
     */
    public function cohortInsights(string $cohortName, array $userEvents): array
    {
        $definitions = $this->getCohortDefinitions();
        $definition = $definitions[$cohortName] ?? null;

        if ($definition === null) {
            return ['cohort' => $cohortName, 'error' => 'Unknown cohort'];
        }

        $cohortMembers = [];
        $eventCounts = [];

        foreach ($userEvents as $identity => $events) {
            $profile = $this->profile($identity, $events);

            if ($profile['cohort'] === $cohortName) {
                $cohortMembers[$identity] = $events;

                foreach ($events as $event) {
                    $name = $event->name;
                    $eventCounts[$name] = ($eventCounts[$name] ?? 0) + 1;
                }
            }
        }

        // Sort event counts descending
        arsort($eventCounts);
        $topEvents = array_slice($eventCounts, 0, 10, true);

        $totalEvents = array_sum($eventCounts);
        $userCount = count($cohortMembers);
        $avgEvents = $userCount > 0 ? round($totalEvents / $userCount, 2) : 0.0;

        return [
            'cohort' => $cohortName,
            'label' => $definition['label'],
            'description' => $definition['description'] ?? '',
            'user_count' => $userCount,
            'top_events' => $topEvents,
            'avg_events_per_user' => $avgEvents,
            'total_events' => $totalEvents,
            'common_properties' => $this->extractCommonProperties($cohortMembers),
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get all cohort definitions (built-in + custom overrides).
     *
     * @return array<string, array{label: string, description: string, color: string, thresholds: array<string, mixed>, signals: list<string>}>
     */
    public function getCohortDefinitions(): array
    {
        $custom = $this->config['cohort_definitions'];

        if (empty($custom)) {
            return self::DEFAULT_COHORT_DEFINITIONS;
        }

        // Merge custom definitions with built-in (custom overrides built-in on name collision)
        return array_merge(self::DEFAULT_COHORT_DEFINITIONS, $custom);
    }

    /**
     * Compute behavioral metrics from a user's event history.
     *
     * @param  string  $identity
     * @param  array<int, AnalyticsEvent>  $events
     * @return array<string, mixed>
     */
    private function computeMetrics(string $identity, array $events): array
    {
        $eventNames = array_map(fn (AnalyticsEvent $e): string => $e->name, $events);
        $uniqueEvents = array_unique($eventNames);
        $signalCounts = array_count_values($eventNames);

        // Compute time-based metrics
        $timestamps = array_filter(
            array_map(fn (AnalyticsEvent $e): ?int => $e->timestamp ?? null, $events),
        );

        $firstEvent = ! empty($timestamps) ? min($timestamps) : time();
        $lastEvent = ! empty($timestamps) ? max($timestamps) : time();
        $spanSeconds = max(1, $lastEvent - $firstEvent);
        $recencyHours = (time() - $lastEvent) / 3600;

        // Events per day
        $spanDays = max(1, $spanSeconds / 86400);
        $eventsPerDay = count($events) / $spanDays;

        // Session duration estimate (time between first and last event)
        $sessionDuration = $spanSeconds;

        // Diversity score: unique events / total events (higher = more diverse)
        $diversity = count($events) > 0 ? count($uniqueEvents) / count($events) : 0.0;

        // Decline rate: compare recent half vs older half
        $halfIndex = (int) (count($events) / 2);
        $recentHalf = array_slice($eventNames, $halfIndex);
        $olderHalf = array_slice($eventNames, 0, $halfIndex);
        $declineRate = $this->computeDeclineRate($recentHalf, $olderHalf);

        // Expansion signals count
        $expansionSignals = array_intersect($uniqueEvents, [
            'plan_upgrade', 'team_created', 'invite_sent', 'workspace_created',
            'feature_used', 'export', 'integration_connected',
        ]);

        return [
            'identity' => $identity,
            'total_events' => count($events),
            'unique_events' => count($uniqueEvents),
            'events_per_day' => round($eventsPerDay, 2),
            'session_duration' => $sessionDuration,
            'recency_hours' => round($recencyHours, 2),
            'diversity' => round($diversity, 4),
            'decline_rate' => $declineRate,
            'expansion_signal_count' => count($expansionSignals),
            'span_days' => round($spanDays, 2),
            'first_event_at' => $firstEvent,
            'last_event_at' => $lastEvent,
            'signal_counts' => $signalCounts,
        ];
    }

    /**
     * Score a user's metrics against a cohort definition.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array{label?: string, thresholds: array<string, mixed>, signals?: list<string>, negative_signals?: list<string>}  $definition
     * @return float
     */
    private function scoreCohort(array $metrics, array $definition): float
    {
        $thresholds = $definition['thresholds'] ?? [];
        $signals = $definition['signals'] ?? [];
        $negativeSignals = $definition['negative_signals'] ?? [];
        $score = 0.0;
        $maxScore = 0.0;

        // Score threshold-based criteria
        foreach ($thresholds as $key => $threshold) {
            $maxScore += 1.0;
            $value = $metrics[$this->mapThresholdKey($key)] ?? null;

            if ($value === null) {
                continue;
            }

            // Different comparison directions based on key prefix
            if (str_starts_with($key, 'min_')) {
                if ($value >= $threshold) {
                    $score += 1.0;
                } elseif ($value >= $threshold * 0.5) {
                    $score += 0.5;
                }
            } elseif (str_starts_with($key, 'max_')) {
                if ($value <= $threshold) {
                    $score += 1.0;
                } elseif ($value <= $threshold * 1.5) {
                    $score += 0.5;
                }
            }
        }

        // Score positive signals
        if (! empty($signals)) {
            $maxScore += 1.0;
            $signalCounts = $metrics['signal_counts'] ?? [];
            $matchedSignals = array_intersect($signals, array_keys($signalCounts));
            $signalRatio = count($matchedSignals) / count($signals);
            $score += $signalRatio;
        }

        // Penalize for negative signals
        if (! empty($negativeSignals)) {
            $signalCounts = $metrics['signal_counts'] ?? [];
            $matchedNegative = array_intersect($negativeSignals, array_keys($signalCounts));
            $penalty = count($matchedNegative) * 0.3;
            $score = max(0, $score - $penalty);
        }

        return $maxScore > 0 ? $score : 0.0;
    }

    /**
     * Map threshold keys to metrics keys.
     */
    private function mapThresholdKey(string $thresholdKey): string
    {
        return match ($thresholdKey) {
            'min_events_per_day' => 'events_per_day',
            'max_events_per_day' => 'events_per_day',
            'min_unique_events' => 'unique_events',
            'min_session_duration' => 'session_duration',
            'recency_max_hours' => 'recency_hours',
            'recency_min_hours' => 'recency_hours',
            'decline_rate_threshold' => 'decline_rate',
            'min_decline_rate' => 'decline_rate',
            'max_account_age_days' => 'span_days',
            'min_events_total' => 'total_events',
            'cancellation_signal_weight' => 'decline_rate',
            'min_expansion_signals' => 'expansion_signal_count',
            default => $thresholdKey,
        };
    }

    /**
     * Compute event rate decline between two halves.
     *
     * @param  list<string>  $recentHalf
     * @param  list<string>  $olderHalf
     * @return float  0.0 = no change, positive = decline
     */
    private function computeDeclineRate(array $recentHalf, array $olderHalf): float
    {
        if (empty($olderHalf)) {
            return 0.0;
        }

        $olderCount = count($olderHalf);
        $recentCount = count($recentHalf);

        // Normalize by length difference
        $normalizedRecent = $olderCount > 0 ? ($recentCount / $olderCount) : 0.0;

        return max(0.0, 1.0 - $normalizedRecent);
    }

    /**
     * Extract common properties from cohort member events.
     *
     * @param  array<string, array<int, AnalyticsEvent>>  $members
     * @return array<string, mixed>
     */
    private function extractCommonProperties(array $members): array
    {
        if (empty($members)) {
            return [];
        }

        // Aggregate common params across all events
        $paramCounts = [];

        foreach ($members as $events) {
            foreach ($events as $event) {
                foreach ($event->params as $key => $value) {
                    if (is_string($value) || is_int($value) || is_float($value)) {
                        $paramKey = "{$key}={$value}";
                        $paramCounts[$paramKey] = ($paramCounts[$paramKey] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($paramCounts);

        return array_slice($paramCounts, 0, 10);
    }

    /**
     * Return an empty profile structure.
     *
     * @param  string  $identity
     * @return array<string, mixed>
     */
    private function emptyProfile(string $identity): array
    {
        return [
            'identity' => $identity,
            'cohort' => 'unknown',
            'label' => 'Unknown',
            'score' => 0.0,
            'confidence' => 0.0,
            'signals' => [],
            'profile' => [],
            'all_scores' => [],
            'profiled_at' => now()->toIso8601String(),
        ];
    }
}
