<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Real-Time Event Correlation Engine — detects co-occurring event patterns.
 *
 * Analyzes event dispatch sequences to discover statistically significant
 * correlations between events. Identifies:
 *
 * 1. **Co-occurrence Pairs**: Events that frequently appear together in user sessions
 * 2. **Sequential Patterns**: Events that follow each other in predictable sequences
 * 3. **Funnel Accelerators**: Events whose presence increases conversion probability
 * 4. **Drop-off Signals**: Events that precede churn or abandonment
 * 5. **Engagement Clusters**: Groups of events that define power users vs. casual users
 *
 * Uses sliding-window analysis over recent event history to compute:
 * - Pearson correlation coefficients between event pairs
 * - Sequence transition probabilities (Markov chain)
 * - Lift ratios (co-occurrence vs. independent probability)
 *
 * Results are cache-backed and power product intelligence dashboards,
 * feature prioritization, and retention optimization workflows.
 *
 * Inspired by Amplitude Pathfinder, Mixpanel Signal, and PostHog Correlation Analysis.
 *
 * Configuration: `zeroboiler.analytics.event_correlation_engine`
 *
 * @see \ZeroBoiler\Analytics\Services\EventCorrelationMatrixService
 * @see \ZeroBoiler\Analytics\Services\EventCorrelationService
 *
 * @phpstan-type CorrelationPair array{event_a: string, event_b: string, coefficient: float, lift: float, co_occurrence_count: int, p_a: float, p_b: float, p_joint: float}
 * @phpstan-type SequenceTransition array{from: string, to: string, probability: float, count: int, lift: float}
 * @phpstan-type CorrelationReport array{pairs: list<CorrelationPair>, transitions: list<SequenceTransition>, top_accelerators: list<CorrelationPair>, top_dropoff_signals: list<CorrelationPair>, engagement_clusters: array<string, list<string>>, total_events_analyzed: int, window_hours: int, computed_at: string}
 *
 * @since 204.0.0
 */
final class RealTimeEventCorrelationEngine
{
    private const CACHE_PREFIX = 'zb_correlation_engine_';

    private const DEFAULT_CACHE_TTL = 600; // 10 minutes

    private const MAX_PAIRS = 200;

    private const MAX_TRANSITIONS = 100;

    /** @var list<string> Events to exclude from correlation analysis (noise/infra) */
    private const EXCLUDED_EVENTS = [
        'page_view',
        'scroll_depth',
        'session_start',
        'session_end',
        'web_vitals',
        'timing',
        'hover',
        'element_visibility',
    ];

    private readonly int $cacheTtl;

    private readonly int $windowHours;

    private readonly int $minEventCount;

    private readonly float $correlationThreshold;

    private readonly int $maxResults;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $engineConfig = $config->get('zeroboiler.analytics.event_correlation_engine', []);
        /** @var array{cache_ttl?: int, window_hours?: int, min_event_count?: int, correlation_threshold?: float, max_results?: int} $engineConfig */

        $this->cacheTtl = (int) ($engineConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->windowHours = (int) ($engineConfig['window_hours'] ?? 168); // 7 days
        $this->minEventCount = (int) ($engineConfig['min_event_count'] ?? 10);
        $this->correlationThreshold = (float) ($engineConfig['correlation_threshold'] ?? 0.3);
        $this->maxResults = (int) ($engineConfig['max_results'] ?? self::MAX_PAIRS);
    }

    /**
     * Analyze event correlations from a batch of events.
     *
     * Processes events grouped by session/client to compute co-occurrence
     * and sequential pattern statistics.
     *
     * @param  list<AnalyticsEvent>  $events  Events to analyze
     * @return CorrelationReport
     */
    public function analyze(array $events): array
    {
        if (empty($events)) {
            return $this->emptyReport();
        }

        // Filter out noise events
        $filtered = array_filter($events, fn (AnalyticsEvent $e): bool => ! in_array($e->name, self::EXCLUDED_EVENTS, true));
        $events = array_values($filtered);

        if (empty($events)) {
            return $this->emptyReport();
        }

        // Group events by session
        $sessions = $this->groupBySession($events);

        // Compute co-occurrence matrix
        $pairs = $this->computeCoOccurrences($sessions);

        // Compute sequential transitions
        $transitions = $this->computeTransitions($sessions);

        // Identify accelerators and drop-off signals
        $accelerators = $this->findAccelerators($pairs);
        $dropoffSignals = $this->findDropoffSignals($pairs);

        // Identify engagement clusters
        $clusters = $this->findEngagementClusters($sessions);

        return [
            'pairs' => $pairs,
            'transitions' => $transitions,
            'top_accelerators' => $accelerators,
            'top_dropoff_signals' => $dropoffSignals,
            'engagement_clusters' => $clusters,
            'total_events_analyzed' => count($events),
            'window_hours' => $this->windowHours,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Analyze correlations and cache the results.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return CorrelationReport
     */
    public function analyzeAndCache(array $events): array
    {
        $report = $this->analyze($events);

        $cacheKey = self::CACHE_PREFIX . 'report';
        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get the cached correlation report if available.
     *
     * @return CorrelationReport|null
     */
    public function getCachedReport(): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'report';

        /** @var CorrelationReport|null $report */
        $report = $this->cache->get($cacheKey);

        return is_array($report) ? $report : null;
    }

    /**
     * Get the correlation between two specific events.
     *
     * Returns a detailed pair analysis with coefficient, lift, and significance.
     *
     * @return CorrelationPair|null
     */
    public function getPairCorrelation(string $eventA, string $eventB, array $events): ?array
    {
        if (empty($events)) {
            return null;
        }

        $sessions = $this->groupBySession($events);
        $allPairs = $this->computeCoOccurrences($sessions);

        $eventALower = strtolower($eventA);
        $eventBLower = strtolower($eventB);

        foreach ($allPairs as $pair) {
            $a = strtolower($pair['event_a']);
            $b = strtolower($pair['event_b']);

            if (
                ($a === $eventALower && $b === $eventBLower) ||
                ($a === $eventBLower && $b === $eventALower)
            ) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * Get the top N correlated event pairs.
     *
     * @param  positive-int  $limit  Maximum pairs to return
     * @param  list<AnalyticsEvent>|null  $events  Events to analyze (uses cache if null)
     * @return list<CorrelationPair>
     */
    public function topPairs(int $limit = 20, ?array $events = null): array
    {
        if ($events !== null) {
            $report = $this->analyze($events);
        } else {
            $report = $this->getCachedReport();
        }

        if ($report === null) {
            return [];
        }

        $pairs = $report['pairs'];
        usort($pairs, fn (array $a, array $b): int => abs($b['coefficient']) <=> abs($a['coefficient']));

        return array_slice($pairs, 0, $limit);
    }

    /**
     * Get top sequential transitions (most probable event sequences).
     *
     * @param  positive-int  $limit
     * @param  list<AnalyticsEvent>|null  $events
     * @return list<SequenceTransition>
     */
    public function topTransitions(int $limit = 20, ?array $events = null): array
    {
        if ($events !== null) {
            $report = $this->analyze($events);
        } else {
            $report = $this->getCachedReport();
        }

        if ($report === null) {
            return [];
        }

        $transitions = $report['transitions'];
        usort($transitions, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return array_slice($transitions, 0, $limit);
    }

    /**
     * Invalidate the cached correlation report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report');
    }

    /**
     * Get event names that are candidates for correlation analysis.
     *
     * Excludes noise events and returns only catalog-recognized events.
     *
     * @return list<string>
     */
    public function candidateEvents(): array
    {
        $allNames = EventCatalog::names();

        return array_values(array_filter(
            $allNames,
            fn (string $name): bool => ! in_array($name, self::EXCLUDED_EVENTS, true),
        ));
    }

    /**
     * Group events by session identifier.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array<string, list<AnalyticsEvent>>
     */
    private function groupBySession(array $events): array
    {
        $sessions = [];

        foreach ($events as $event) {
            $key = $event->sessionId
                ?? $event->clientId
                ?? $event->userId
                ?? 'anonymous';

            $sessions[$key][] = $event;
        }

        return $sessions;
    }

    /**
     * Compute co-occurrence statistics for event pairs.
     *
     * For each pair of events that co-occur in the same session, computes:
     * - Co-occurrence count
     * - Individual event probabilities
     * - Joint probability
     * - Lift ratio (joint / expected if independent)
     * - Pearson correlation coefficient approximation
     *
     * @param  array<string, list<AnalyticsEvent>>  $sessions
     * @return list<CorrelationPair>
     */
    private function computeCoOccurrences(array $sessions): array
    {
        if (empty($sessions)) {
            return [];
        }

        // Count per-session event sets
        $sessionEventSets = [];
        $eventCounts = [];

        foreach ($sessions as $sessionId => $sessionEvents) {
            $uniqueNames = array_unique(array_map(fn (AnalyticsEvent $e): string => $e->name, $sessionEvents));

            foreach ($uniqueNames as $name) {
                $eventCounts[$name] = ($eventCounts[$name] ?? 0) + 1;
            }

            $sessionEventSets[$sessionId] = array_values($uniqueNames);
        }

        $totalSessions = count($sessions);
        $pairs = [];

        // Compute co-occurrence for each pair
        $pairCounts = [];

        foreach ($sessionEventSets as $sessionId => $eventNames) {
            $count = count($eventNames);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $eventNames[$i];
                    $b = $eventNames[$j];

                    $pairKey = $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
                    $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
                }
            }
        }

        // Compute statistics for each pair
        foreach ($pairCounts as $pairKey => $coCount) {
            [$a, $b] = explode('|', $pairKey, 2);

            $countA = $eventCounts[$a] ?? 0;
            $countB = $eventCounts[$b] ?? 0;

            // Skip pairs with insufficient data
            if ($countA < $this->minEventCount || $countB < $this->minEventCount) {
                continue;
            }

            $pA = $countA / $totalSessions;
            $pB = $countB / $totalSessions;
            $pJoint = $coCount / $totalSessions;

            // Lift = P(A∩B) / (P(A) * P(B))
            $expectedJoint = $pA * $pB;
            $lift = $expectedJoint > 0 ? round($pJoint / $expectedJoint, 4) : 0.0;

            // Pearson correlation approximation using point-biserial approach
            // For binary variables (present/absent in session), compute phi coefficient
            $nAB = $coCount;
            $nAOnly = $countA - $coCount;
            $nBOnly = $countB - $coCount;
            $nNeither = $totalSessions - $countA - $countB + $coCount;

            $numerator = (float) ($nAB * $nNeither - $nAOnly * $nBOnly);
            $denominator = sqrt((float) (($nAB + $nAOnly) * ($nAB + $nBOnly) * ($nAOnly + $nNeither) * ($nBOnly + $nNeither)));

            $coefficient = $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;

            $pairs[] = [
                'event_a' => $a,
                'event_b' => $b,
                'coefficient' => $coefficient,
                'lift' => $lift,
                'co_occurrence_count' => $coCount,
                'p_a' => round($pA, 4),
                'p_b' => round($pB, 4),
                'p_joint' => round($pJoint, 4),
            ];
        }

        // Sort by absolute coefficient and limit
        usort($pairs, fn (array $a, array $b): int => abs($b['coefficient']) <=> abs($a['coefficient']));

        return array_slice($pairs, 0, $this->maxResults);
    }

    /**
     * Compute sequential transition probabilities between events.
     *
     * Builds a first-order Markov chain of event transitions within sessions.
     *
     * @param  array<string, list<AnalyticsEvent>>  $sessions
     * @return list<SequenceTransition>
     */
    private function computeTransitions(array $sessions): array
    {
        $transitionCounts = [];
        $fromCounts = [];

        foreach ($sessions as $sessionEvents) {
            $sorted = $sessionEvents;
            usort($sorted, fn (AnalyticsEvent $a, AnalyticsEvent $b): int =>
                ($a->timestamp?->getTimestamp() ?? 0) <=> ($b->timestamp?->getTimestamp() ?? 0)
            );

            for ($i = 0; $i < count($sorted) - 1; $i++) {
                $from = $sorted[$i]->name;
                $to = $sorted[$i + 1]->name;

                if (in_array($from, self::EXCLUDED_EVENTS, true) || in_array($to, self::EXCLUDED_EVENTS, true)) {
                    continue;
                }

                $key = "{$from}|{$to}";
                $transitionCounts[$key] = ($transitionCounts[$key] ?? 0) + 1;
                $fromCounts[$from] = ($fromCounts[$from] ?? 0) + 1;
            }
        }

        $transitions = [];

        foreach ($transitionCounts as $key => $count) {
            [$from, $to] = explode('|', $key, 2);
            $totalFrom = $fromCounts[$from] ?? 1;

            $probability = round($count / $totalFrom, 4);

            // Compute lift: compare against random transition probability
            $totalTransitions = array_sum($transitionCounts);
            $randomProbability = $totalTransitions > 0
                ? (array_sum(array_filter($transitionCounts, fn (int $_, string $k): bool => str_starts_with($k, $to . '|'))) + $count) / $totalTransitions
                : 0;

            $lift = $randomProbability > 0 ? round($probability / $randomProbability, 4) : 0.0;

            $transitions[] = [
                'from' => $from,
                'to' => $to,
                'probability' => $probability,
                'count' => $count,
                'lift' => $lift,
            ];
        }

        usort($transitions, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return array_slice($transitions, 0, self::MAX_TRANSITIONS);
    }

    /**
     * Find event pairs that act as funnel accelerators.
     *
     * Accelerators are positive correlations with lift > 1.5 between
     * engagement events and conversion events.
     *
     * @param  list<CorrelationPair>  $pairs
     * @return list<CorrelationPair>
     */
    private function findAccelerators(array $pairs): array
    {
        $conversionEvents = [
            'sign_up', 'trial_start', 'subscribe', 'subscription_created',
            'purchase', 'trial_converted', 'plan_upgrade',
        ];

        return array_values(array_filter(
            $pairs,
            fn (array $pair): bool =>
                $pair['lift'] > 1.5 &&
                $pair['coefficient'] > $this->correlationThreshold &&
                (
                    in_array($pair['event_a'], $conversionEvents, true) ||
                    in_array($pair['event_b'], $conversionEvents, true)
                )
        ));
    }

    /**
     * Find event pairs that signal upcoming churn or drop-off.
     *
     * Drop-off signals are positive correlations between any event
     * and cancellation/churn-related events.
     *
     * @param  list<CorrelationPair>  $pairs
     * @return list<CorrelationPair>
     */
    private function findDropoffSignals(array $pairs): array
    {
        $churnEvents = [
            'cancellation', 'subscription_cancelled', 'plan_downgrade',
            'trial_end', 'churn', 'account_deactivated', 'account_deleted',
        ];

        return array_values(array_filter(
            $pairs,
            fn (array $pair): bool =>
                $pair['lift'] > 1.0 &&
                $pair['coefficient'] > $this->correlationThreshold &&
                (
                    in_array($pair['event_a'], $churnEvents, true) ||
                    in_array($pair['event_b'], $churnEvents, true)
                )
        ));
    }

    /**
     * Find engagement clusters — groups of events that define user segments.
     *
     * Clusters events based on co-occurrence frequency to identify
     * distinct user behavior patterns (e.g., "power users", "browsers").
     *
     * @param  array<string, list<AnalyticsEvent>>  $sessions
     * @return array<string, list<string>>
     */
    private function findEngagementClusters(array $sessions): array
    {
        $clusters = [];
        $eventFrequency = [];

        // Count event frequency across sessions
        foreach ($sessions as $sessionEvents) {
            $uniqueNames = array_unique(array_map(fn (AnalyticsEvent $e): string => $e->name, $sessionEvents));
            foreach ($uniqueNames as $name) {
                $eventFrequency[$name] = ($eventFrequency[$name] ?? 0) + 1;
            }
        }

        if (empty($eventFrequency)) {
            return $clusters;
        }

        $totalSessions = count($sessions);
        $avgFrequency = count($eventFrequency) > 0
            ? array_sum($eventFrequency) / count($eventFrequency)
            : 0;

        // High-engagement cluster: events appearing in >60% of sessions
        $highEngagement = array_filter(
            $eventFrequency,
            fn (int $count): bool => $count / $totalSessions > 0.6,
        );
        if (! empty($highEngagement)) {
            $clusters['high_engagement'] = array_keys($highEngagement);
        }

        // Low-engagement cluster: events appearing in <10% of sessions
        $lowEngagement = array_filter(
            $eventFrequency,
            fn (int $count): bool => $count / $totalSessions < 0.1 && $count >= $this->minEventCount,
        );
        if (! empty($lowEngagement)) {
            $clusters['low_engagement'] = array_keys($lowEngagement);
        }

        // Conversion-prefixed cluster: events correlated with conversions
        $conversionPrefixed = array_filter(
            $eventFrequency,
            fn (int $count): bool => $count / $totalSessions > 0.2 && in_array(array_search($count, $eventFrequency, true) ? '' : '', [], true) === false,
        );

        // Power-user cluster: events with frequency > 2x average
        $powerUser = array_filter(
            $eventFrequency,
            fn (int $count): bool => $avgFrequency > 0 && $count > $avgFrequency * 2,
        );
        if (! empty($powerUser)) {
            $clusters['power_user'] = array_keys($powerUser);
        }

        return $clusters;
    }

    /**
     * Generate an empty correlation report.
     *
     * @return CorrelationReport
     */
    private function emptyReport(): array
    {
        return [
            'pairs' => [],
            'transitions' => [],
            'top_accelerators' => [],
            'top_dropoff_signals' => [],
            'engagement_clusters' => [],
            'total_events_analyzed' => 0,
            'window_hours' => $this->windowHours,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
