<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event correlation and pattern detection service for SaaS analytics.
 *
 * Detects sequential event patterns, calculates conversion paths,
 * identifies common user journeys, and provides predictive analytics
 * based on event sequence analysis. All analysis is performed in-memory
 * with optional cache persistence.
 *
 * This service does NOT dispatch events — it reads from the event stream
 * and metrics to build correlation data for dashboards and insights.
 *
 * @since 1.0.0
 */
final class EventCorrelationService
{
    /** @var array<string, int> */
    private array $eventCounts = [];

    /** @var array<string, array<string, int>> */
    private array $transitions = [];

    /** @var array<string, list<string>> */
    private array $userJourneys = [];

    /** @var array<string, array{first: string, last: string, count: int, duration_ms: int}> */
    private array $sessionSummaries = [];

    /** @var list<array{pattern: list<string>, count: int, conversion_rate: float}> */
    private array $detectedPatterns = [];

    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    private int $cacheTtl;

    private int $maxPatternLength;

    private int $maxJourneysPerUser;

    private bool $useCache;

    /**
     * @param  AnalyticsMetrics  $metrics
     * @param  CacheRepository  $cache
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 300 = 5 minutes)
     * @param  int  $maxPatternLength  Maximum sequence length to detect (default: 5)
     * @param  int  $maxJourneysPerUser  Max journey steps stored per user (default: 100)
     * @param  bool  $useCache  Whether to cache analysis results
     */
    public function __construct(
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        int $cacheTtl = 300,
        int $maxPatternLength = 5,
        int $maxJourneysPerUser = 100,
        bool $useCache = true,
    ): void {
        $this->metrics = $metrics;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->maxPatternLength = $maxPatternLength;
        $this->maxJourneysPerUser = $maxJourneysPerUser;
        $this->useCache = $useCache;
    }

    /**
     * Record an event for correlation analysis.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $sessionId
     * @param  string|null  $userId
     */
    public function record(AnalyticsEvent $event, ?string $sessionId = null, ?string $userId = null): void
    {
        $name = $event->name;

        // Count event occurrences
        $this->eventCounts[$name] = ($this->eventCounts[$name] ?? 0) + 1;

        // Track user journey
        $identity = $userId ?? $sessionId ?? 'anonymous';

        if (! isset($this->userJourneys[$identity])) {
            $this->userJourneys[$identity] = [];
        }

        $this->userJourneys[$identity][] = $name;

        // Limit journey length per user
        if (count($this->userJourneys[$identity]) > $this->maxJourneysPerUser) {
            array_shift($this->userJourneys[$identity]);
        }

        // Track transitions (event A → event B)
        $journey = $this->userJourneys[$identity];
        $journeyLength = count($journey);

        if ($journeyLength >= 2) {
            $previous = $journey[$journeyLength - 2];
            $current = $journey[$journeyLength - 1];

            if (! isset($this->transitions[$previous])) {
                $this->transitions[$previous] = [];
            }

            $this->transitions[$previous][$current] = ($this->transitions[$previous][$current] ?? 0) + 1;
        }
    }

    /**
     * Record an event transition explicitly.
     *
     * Useful when events come from different sources and you want to
     * manually record the sequence without tracking the full journey.
     *
     * @param  string  $fromEvent
     * @param  string  $toEvent
     */
    public function recordTransition(string $fromEvent, string $toEvent): void
    {
        if (! isset($this->transitions[$fromEvent])) {
            $this->transitions[$fromEvent] = [];
        }

        $this->transitions[$fromEvent][$toEvent] = ($this->transitions[$fromEvent][$toEvent] ?? 0) + 1;
    }

    /**
     * Get the most common event sequences (frequent patterns).
     *
     * Analyzes user journeys to find repeated patterns of length 2–N.
     *
     * @param  int  $minLength  Minimum sequence length (default: 2)
     * @param  int  $limit  Maximum patterns to return (default: 20)
     * @return list<array{pattern: list<string>, count: int, frequency: float}>
     */
    public function frequentPatterns(int $minLength = 2, int $limit = 20): array
    {
        $cacheKey = "zeroboiler.analytics.correlation.patterns.{$minLength}";

        if ($this->useCache) {
            try {
                $cached = $this->cache->get($cacheKey);

                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable) {
                // Cache miss
            }
        }

        $patternCounts = [];

        foreach ($this->userJourneys as $journey) {
            $this->extractPatterns($journey, $minLength, $patternCounts);
        }

        // Sort by count descending
        arsort($patternCounts);

        $totalSequences = array_sum($patternCounts) ?: 1;
        $results = [];
        $count = 0;

        foreach ($patternCounts as $patternKey => $patternCount) {
            if ($count >= $limit) {
                break;
            }

            $events = explode('>', $patternKey);

            $results[] = [
                'pattern' => $events,
                'count' => $patternCount,
                'frequency' => round(($patternCount / $totalSequences) * 100, 2),
            ];

            $count++;
        }

        $this->detectedPatterns = $results;

        try {
            $this->cache->put($cacheKey, $results, $this->cacheTtl);
        } catch (\Throwable) {
            // Cache unavailable
        }

        return $results;
    }

    /**
     * Get the most common event transition pairs.
     *
     * @param  int  $limit  Maximum transitions to return (default: 20)
     * @return list<array{from: string, to: string, count: int, probability: float}>
     */
    public function topTransitions(int $limit = 20): array
    {
        $flatTransitions = [];

        foreach ($this->transitions as $from => $targets) {
            $fromTotal = array_sum($targets);

            foreach ($targets as $to => $count) {
                $flatTransitions[] = [
                    'from' => $from,
                    'to' => $to,
                    'count' => $count,
                    'probability' => $fromTotal > 0 ? round(($count / $fromTotal) * 100, 2) : 0.0,
                ];
            }
        }

        // Sort by count descending
        usort($flatTransitions, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($flatTransitions, 0, $limit);
    }

    /**
     * Get the predicted next events after a given event.
     *
     * Uses transition probabilities to predict what events are likely
     * to follow a given event. Useful for proactive analytics.
     *
     * @param  string  $afterEvent  The event to predict after
     * @param  int  $limit  Maximum predictions (default: 5)
     * @return list<array{event: string, probability: float, count: int}>
     */
    public function predictNext(string $afterEvent, int $limit = 5): array
    {
        $transitions = $this->transitions[$afterEvent] ?? [];

        if (empty($transitions)) {
            return [];
        }

        $total = array_sum($transitions);
        $predictions = [];

        arsort($transitions);

        $count = 0;
        foreach ($transitions as $event => $transitionCount) {
            if ($count >= $limit) {
                break;
            }

            $predictions[] = [
                'event' => $event,
                'probability' => $total > 0 ? round(($transitionCount / $total) * 100, 2) : 0.0,
                'count' => $transitionCount,
            ];

            $count++;
        }

        return $predictions;
    }

    /**
     * Calculate the conversion rate for a specific event sequence.
     *
     * Given a sequence like ['sign_up', 'start_trial', 'subscription.created'],
     * returns the percentage of users who completed the full sequence.
     *
     * @param  list<string>  $sequence
     * @return array{sequence: list<string>, total_starting: int, total_completed: int, conversion_rate: float, drop_off: list<array{step: int, event: string, retained: int, rate: float}>}
     */
    public function conversionRate(array $sequence): array
    {
        if (count($sequence) < 2) {
            return [
                'sequence' => $sequence,
                'total_starting' => 0,
                'total_completed' => 0,
                'conversion_rate' => 0.0,
                'drop_off' => [],
            ];
        }

        $firstEvent = $sequence[0];
        $startingUsers = $this->usersWithEvent($firstEvent);
        $retained = $startingUsers;
        $dropOff = [];

        foreach ($sequence as $i => $event) {
            if ($i === 0) {
                $dropOff[] = [
                    'step' => $i + 1,
                    'event' => $event,
                    'retained' => count($retained),
                    'rate' => 100.0,
                ];
                continue;
            }

            $newRetained = [];
            foreach ($retained as $userId => $journey) {
                if ($this->journeyContainsSequence($journey, array_slice($sequence, 0, $i + 1))) {
                    $newRetained[$userId] = $journey;
                }
            }

            $retained = $newRetained;
            $prevCount = $dropOff[$i - 1]['retained'];
            $stepRate = $prevCount > 0 ? round((count($retained) / $prevCount) * 100, 2) : 0.0;

            $dropOff[] = [
                'step' => $i + 1,
                'event' => $event,
                'retained' => count($retained),
                'rate' => $stepRate,
            ];
        }

        $totalStarting = count($startingUsers);
        $totalCompleted = count($retained);

        return [
            'sequence' => $sequence,
            'total_starting' => $totalStarting,
            'total_completed' => $totalCompleted,
            'conversion_rate' => $totalStarting > 0 ? round(($totalCompleted / $totalStarting) * 100, 2) : 0.0,
            'drop_off' => $dropOff,
        ];
    }

    /**
     * Get the most common user journeys (full event sequences).
     *
     * @param  int  $limit  Maximum journeys (default: 10)
     * @return list<array{user: string, steps: list<string>, step_count: int}>
     */
    public function topJourneys(int $limit = 10): array
    {
        $journeys = [];

        foreach ($this->userJourneys as $userId => $steps) {
            $journeys[] = [
                'user' => $userId,
                'steps' => $steps,
                'step_count' => count($steps),
            ];
        }

        // Sort by step count descending (longer journeys first)
        usort($journeys, fn (array $a, array $b): int => $b['step_count'] <=> $a['step_count']);

        return array_slice($journeys, 0, $limit);
    }

    /**
     * Get event correlation matrix for a set of events.
     *
     * Returns a matrix showing how often events appear together in user journeys.
     *
     * @param  list<string>  $events  Events to correlate (empty = all events)
     * @return array{events: list<string>, matrix: array<string, array<string, int>>}
     */
    public function correlationMatrix(array $events = []): array
    {
        if ($events === []) {
            $events = array_keys($this->eventCounts);
        }

        $matrix = [];

        foreach ($events as $row) {
            $matrix[$row] = [];
            foreach ($events as $col) {
                $matrix[$row][$col] = $this->coOccurrence($row, $col);
            }
        }

        return [
            'events' => $events,
            'matrix' => $matrix,
        ];
    }

    /**
     * Get a comprehensive analysis summary.
     *
     * @return array{total_events: int, unique_events: int, total_transitions: int, unique_users: int, top_events: list<array{name: string, count: int}>, top_transitions: list<array{from: string, to: string, count: int, probability: float}>, detected_patterns: int, avg_journey_length: float}
     */
    public function summary(): array
    {
        $totalEvents = array_sum($this->eventCounts);
        $uniqueEvents = count($this->eventCounts);
        $totalTransitions = 0;

        foreach ($this->transitions as $targets) {
            $totalTransitions += array_sum($targets);
        }

        // Calculate average journey length
        $totalJourneySteps = 0;
        $totalJourneys = count($this->userJourneys);
        foreach ($this->userJourneys as $journey) {
            $totalJourneySteps += count($journey);
        }

        // Top events by count
        $sortedEvents = $this->eventCounts;
        arsort($sortedEvents);
        $topEvents = [];
        $count = 0;
        foreach ($sortedEvents as $name => $eventCount) {
            if ($count >= 10) {
                break;
            }
            $topEvents[] = ['name' => $name, 'count' => $eventCount];
            $count++;
        }

        return [
            'total_events' => $totalEvents,
            'unique_events' => $uniqueEvents,
            'total_transitions' => $totalTransitions,
            'unique_users' => $totalJourneys,
            'top_events' => $topEvents,
            'top_transitions' => $this->topTransitions(10),
            'detected_patterns' => count($this->detectedPatterns),
            'avg_journey_length' => $totalJourneys > 0 ? round($totalJourneySteps / $totalJourneys, 2) : 0.0,
        ];
    }

    /**
     * Clear all correlation data.
     */
    public function clear(): void
    {
        $this->eventCounts = [];
        $this->transitions = [];
        $this->userJourneys = [];
        $this->sessionSummaries = [];
        $this->detectedPatterns = [];
    }

    /**
     * Invalidate analysis caches.
     */
    public function invalidateCache(): void
    {
        try {
            $this->cache->forget('zeroboiler.analytics.correlation.patterns');
        } catch (\Throwable) {
            // Cache unavailable
        }
    }

    /**
     * Extract n-gram patterns from a user journey.
     *
     * @param  list<string>  $journey
     * @param  int  $minLength
     * @param  array<string, int>  $patternCounts
     */
    private function extractPatterns(array $journey, int $minLength, array &$patternCounts): void
    {
        $length = count($journey);

        for ($n = $minLength; $n <= min($this->maxPatternLength, $length); $n++) {
            for ($i = 0; $i <= $length - $n; $i++) {
                $sequence = array_slice($journey, $i, $n);
                $key = implode('>', $sequence);

                $patternCounts[$key] = ($patternCounts[$key] ?? 0) + 1;
            }
        }
    }

    /**
     * Get users who have performed a specific event.
     *
     * @return array<string, list<string>>
     */
    private function usersWithEvent(string $event): array
    {
        $users = [];

        foreach ($this->userJourneys as $userId => $journey) {
            if (in_array($event, $journey, true)) {
                $users[$userId] = $journey;
            }
        }

        return $users;
    }

    /**
     * Check if a journey contains a sequence of events in order.
     *
     * @param  list<string>  $journey
     * @param  list<string>  $sequence
     */
    private function journeyContainsSequence(array $journey, array $sequence): bool
    {
        if (count($sequence) > count($journey)) {
            return false;
        }

        $sequenceCount = count($sequence);

        for ($i = 0; $i <= count($journey) - $sequenceCount; $i++) {
            $match = true;

            for ($j = 0; $j < $sequenceCount; $j++) {
                if ($journey[$i + $j] !== $sequence[$j]) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate co-occurrence count between two events.
     *
     * Returns the number of users who have performed both events.
     */
    private function coOccurrence(string $eventA, string $eventB): int
    {
        $count = 0;

        foreach ($this->userJourneys as $journey) {
            $hasA = in_array($eventA, $journey, true);
            $hasB = in_array($eventB, $journey, true);

            if ($hasA && $hasB) {
                $count++;
            }
        }

        return $count;
    }
}
