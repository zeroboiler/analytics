<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * User journey mapping service for SaaS analytics.
 *
 * Reconstructs user navigation paths from event sequences, identifies
 * common conversion paths, drop-off points, and multi-touch attribution.
 * Designed for product analytics dashboards and funnel optimization.
 *
 * Stores event sequences per user/client session and provides methods
 * for path analysis, most common journeys, and journey comparison.
 *
 * @see \ZeroBoiler\Analytics\Services\FunnelAnalyticsService
 */
final class UserJourneyService
{
    /** @var array<string, array{steps: list<array{name: string, params: array<string, mixed>, timestamp: string, page?: string|null}>, user_id: string|null, client_id: string|null, started_at: string|null}> */
    private array $journeys = [];

    /** @var int Maximum active journeys to track in memory */
    private int $maxJourneys;

    /** @var int Maximum steps per journey before truncation */
    private int $maxStepsPerJourney;

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $async;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  bool  $async  Use async dispatch for journey events
     * @param  int  $maxJourneys  Maximum concurrent journeys tracked
     * @param  int  $maxStepsPerJourney  Max steps before truncation
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        bool $async = true,
        int $maxJourneys = 5000,
        int $maxStepsPerJourney = 200,
    ) {
        $this->manager = $manager;
        $this->queue = $queue;
        $this->async = $async;
        $this->maxJourneys = $maxJourneys;
        $this->maxStepsPerJourney = $maxStepsPerJourney;
    }

    /**
     * Record a step in a user's journey.
     *
     * Each step is appended to the journey for the given session ID.
     * Journeys are automatically created on first step and evicted
     * when the max journey limit is reached (LRU eviction).
     *
     * @param  string  $journeyId  Unique journey/session identifier
     * @param  string  $eventName  Analytics event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $page  Current page URL (optional)
     */
    public function recordStep(
        string $journeyId,
        string $eventName,
        array $params = [],
        ?string $userId = null,
        ?string $clientId = null,
        ?string $page = null,
    ): void {
        if (! isset($this->journeys[$journeyId])) {
            if (count($this->journeys) >= $this->maxJourneys) {
                // Evict oldest journey
                array_shift($this->journeys);
            }

            $this->journeys[$journeyId] = [
                'steps' => [],
                'user_id' => $userId,
                'client_id' => $clientId,
                'started_at' => date('c'),
            ];
        }

        // Update identity if now known
        if ($userId !== null) {
            $this->journeys[$journeyId]['user_id'] = $userId;
        }
        if ($clientId !== null) {
            $this->journeys[$journeyId]['client_id'] = $clientId;
        }

        $step = [
            'name' => $eventName,
            'params' => $params,
            'timestamp' => date('c'),
        ];

        if ($page !== null) {
            $step['page'] = $page;
        }

        $this->journeys[$journeyId]['steps'][] = $step;

        // Truncate if exceeds max steps
        if (count($this->journeys[$journeyId]['steps']) > $this->maxStepsPerJourney) {
            $this->journeys[$journeyId]['steps'] = array_slice(
                $this->journeys[$journeyId]['steps'],
                -$this->maxStepsPerJourney,
            );
        }
    }

    /**
     * Get the full journey for a given session.
     *
     * @param  string  $journeyId
     * @return array{journey_id: string, steps: list<array{name: string, params: array<string, mixed>, timestamp: string, page?: string|null}>, step_count: int, user_id: string|null, client_id: string|null, started_at: string|null, event_sequence: string}|null
     */
    public function getJourney(string $journeyId): ?array
    {
        $journey = $this->journeys[$journeyId] ?? null;

        if ($journey === null) {
            return null;
        }

        return [
            'journey_id' => $journeyId,
            'steps' => $journey['steps'],
            'step_count' => count($journey['steps']),
            'user_id' => $journey['user_id'],
            'client_id' => $journey['client_id'],
            'started_at' => $journey['started_at'],
            'event_sequence' => $this->extractSequence($journey['steps']),
        ];
    }

    /**
     * Get an array of unique page paths visited in a journey.
     *
     * Useful for visualizing navigation flow.
     *
     * @param  string  $journeyId
     * @return list<string>
     */
    public function getPageFlow(string $journeyId): array
    {
        $journey = $this->journeys[$journeyId] ?? null;

        if ($journey === null) {
            return [];
        }

        $pages = [];
        foreach ($journey['steps'] as $step) {
            if (isset($step['page']) && $step['page'] !== null) {
                if ($pages === [] || end($pages) !== $step['page']) {
                    $pages[] = $step['page'];
                }
            }
        }

        return $pages;
    }

    /**
     * Find the most common journey patterns across all tracked sessions.
     *
     * Normalizes journeys to N-step sequences and counts their frequency.
     * Useful for identifying dominant user flows in the application.
     *
     * @param  int  $steps  Number of steps to consider (0 = full journey)
     * @param  int  $limit  Maximum number of patterns to return
     * @return list<array{pattern: string, count: int, steps: list<string>}>
     */
    public function mostCommonPatterns(int $steps = 0, int $limit = 20): array
    {
        $patternCounts = [];

        foreach ($this->journeys as $journey) {
            $sequence = $this->extractSequence($journey['steps']);

            if ($sequence === '') {
                continue;
            }

            $pattern = $steps > 0
                ? $this->truncateSequence($sequence, $steps)
                : $sequence;

            if ($pattern === '') {
                continue;
            }

            $patternCounts[$pattern] = ($patternCounts[$pattern] ?? 0) + 1;
        }

        arsort($patternCounts);

        $result = [];
        $count = 0;

        foreach ($patternCounts as $pattern => $cnt) {
            $result[] = [
                'pattern' => $pattern,
                'count' => $cnt,
                'steps' => explode(' → ', $pattern),
            ];
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Identify drop-off points across all journeys.
     *
     * Analyzes where journeys end most frequently, helping identify
     * UX bottlenecks and friction points.
     *
     * @param  int  $limit  Maximum drop-off points to return
     * @return list<array{event: string, drop_offs: int, total_journeys: int, rate: float}>
     */
    public function dropOffPoints(int $limit = 20): array
    {
        $lastEventCounts = [];
        $totalJourneyCount = count($this->journeys);

        if ($totalJourneyCount === 0) {
            return [];
        }

        foreach ($this->journeys as $journey) {
            $steps = $journey['steps'];
            $stepCount = count($steps);

            if ($stepCount > 0) {
                $lastEvent = $steps[$stepCount - 1]['name'];
                $lastEventCounts[$lastEvent] = ($lastEventCounts[$lastEvent] ?? 0) + 1;
            }
        }

        arsort($lastEventCounts);

        $result = [];
        $count = 0;

        foreach ($lastEventCounts as $event => $dropOffs) {
            $result[] = [
                'event' => $event,
                'drop_offs' => $dropOffs,
                'total_journeys' => $totalJourneyCount,
                'rate' => round(($dropOffs / $totalJourneyCount) * 100, 2),
            ];
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Find journeys that match a specific event sequence pattern.
     *
     * Supports glob-style wildcards: '*' matches any single event.
     *
     * @param  string  $pattern  Pattern like "page_view → * → purchase" or "page_view → add_to_cart → purchase"
     * @param  int  $limit  Maximum matching journeys to return
     * @return list<array{journey_id: string, steps: list<string>, match_position: int, total_steps: int}>
     */
    public function findMatchingJourneys(string $pattern, int $limit = 50): array
    {
        $patternSteps = array_map('trim', explode('→', $pattern));
        $patternSteps = array_map(
            fn (string $s): string => trim($s, ' '),
            $patternSteps,
        );

        $matches = [];

        foreach ($this->journeys as $journeyId => $journey) {
            $eventSequence = array_column($journey['steps'], 'name');
            $position = $this->matchPattern($patternSteps, $eventSequence);

            if ($position !== -1) {
                $matches[] = [
                    'journey_id' => $journeyId,
                    'steps' => $eventSequence,
                    'match_position' => $position,
                    'total_steps' => count($eventSequence),
                ];
            }

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Get conversion rate for a specific funnel within journeys.
     *
     * Counts how many journeys contain the final step of the funnel
     * out of those that contain the first step.
     *
     * @param  list<string>  $funnelSteps  Ordered list of event names defining the funnel
     * @return array{entrances: int, completions: int, conversion_rate: float, step_rates: list<array{step: string, count: int, drop_off: int, rate: float}>}
     */
    public function funnelConversion(array $funnelSteps): array
    {
        if (count($funnelSteps) === 0) {
            return [
                'entrances' => 0,
                'completions' => 0,
                'conversion_rate' => 0.0,
                'step_rates' => [],
            ];
        }

        $firstStep = $funnelSteps[0];
        $lastStep = $funnelSteps[count($funnelSteps) - 1];

        $entrances = 0;
        $completions = 0;
        $stepCounts = array_fill_keys($funnelSteps, 0);

        foreach ($this->journeys as $journey) {
            $eventNames = array_column($journey['steps'], 'name');

            $hasEntrance = false;
            foreach ($eventNames as $name) {
                if ($name === $firstStep && ! $hasEntrance) {
                    $entrances++;
                    $hasEntrance = true;
                    $stepCounts[$firstStep] = ($stepCounts[$firstStep] ?? 0) + 1;
                }

                if ($hasEntrance && isset($stepCounts[$name])) {
                    // Only count if it hasn't been counted yet for this funnel progression
                    // Simple approach: count all occurrences after entrance
                }
            }

            // Check step presence (after first step)
            if ($hasEntrance) {
                $foundSteps = [];
                foreach ($funnelSteps as $step) {
                    if (in_array($step, $eventNames, true)) {
                        $foundSteps[$step] = true;
                        $stepCounts[$step] = ($stepCounts[$step] ?? 0) + 1;
                    }
                }

                if (isset($foundSteps[$lastStep])) {
                    $completions++;
                }
            }
        }

        $stepRates = [];
        $previousCount = $entrances;

        foreach ($funnelSteps as $step) {
            $count = $stepCounts[$step] ?? 0;
            $dropOff = $previousCount - $count;
            $rate = $previousCount > 0 ? round(($count / $previousCount) * 100, 2) : 0.0;

            $stepRates[] = [
                'step' => $step,
                'count' => $count,
                'drop_off' => max(0, $dropOff),
                'rate' => $rate,
            ];

            $previousCount = $count;
        }

        return [
            'entrances' => $entrances,
            'completions' => $completions,
            'conversion_rate' => $entrances > 0
                ? round(($completions / $entrances) * 100, 2)
                : 0.0,
            'step_rates' => $stepRates,
        ];
    }

    /**
     * End a journey and optionally dispatch it as an analytics event.
     *
     * @param  string  $journeyId
     * @param  string|null  $outcome  Journey outcome ('completed', 'abandoned', 'converted', null)
     * @param  array<string, mixed>  $extraParams  Additional params for the journey summary event
     */
    public function endJourney(string $journeyId, ?string $outcome = null, array $extraParams = []): void
    {
        $journey = $this->journeys[$journeyId] ?? null;

        if ($journey === null) {
            return;
        }

        $eventParams = array_filter([
            'journey_id' => $journeyId,
            'journey_outcome' => $outcome,
            'journey_step_count' => count($journey['steps']),
            'journey_event_sequence' => $this->extractSequence($journey['steps']),
            'journey_page_flow' => $this->getPageFlowForSteps($journey['steps']),
            'journey_duration_estimate' => $this->estimateDuration($journey),
            'user_id' => $journey['user_id'],
            'client_id' => $journey['client_id'],
        ]);

        $event = new AnalyticsEvent(
            name: 'user_journey_completed',
            params: array_merge($eventParams, $extraParams),
            userId: $journey['user_id'],
            clientId: $journey['client_id'],
        );

        if ($this->async) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }

        // Remove from memory
        unset($this->journeys[$journeyId]);
    }

    /**
     * Get aggregate journey statistics across all tracked journeys.
     *
     * @return array{total_journeys: int, avg_steps: float, max_steps: int, journeys_with_identity: int, journeys_anonymous: int, unique_event_types: int, event_frequency: array<string, int>}
     */
    public function getStats(): array
    {
        $totalJourneys = count($this->journeys);
        $totalSteps = 0;
        $maxSteps = 0;
        $withIdentity = 0;
        $eventFrequency = [];

        foreach ($this->journeys as $journey) {
            $stepCount = count($journey['steps']);
            $totalSteps += $stepCount;
            $maxSteps = max($maxSteps, $stepCount);

            if ($journey['user_id'] !== null) {
                $withIdentity++;
            }

            foreach ($journey['steps'] as $step) {
                $eventFrequency[$step['name']] = ($eventFrequency[$step['name']] ?? 0) + 1;
            }
        }

        arsort($eventFrequency);

        return [
            'total_journeys' => $totalJourneys,
            'avg_steps' => $totalJourneys > 0
                ? round($totalSteps / $totalJourneys, 2)
                : 0.0,
            'max_steps' => $maxSteps,
            'journeys_with_identity' => $withIdentity,
            'journeys_anonymous' => $totalJourneys - $withIdentity,
            'unique_event_types' => count($eventFrequency),
            'event_frequency' => $eventFrequency,
        ];
    }

    /**
     * Clear all tracked journeys from memory.
     */
    public function flush(): void
    {
        $this->journeys = [];
    }

    /**
     * Get the number of currently tracked journeys.
     */
    public function count(): int
    {
        return count($this->journeys);
    }

    /**
     * Extract a string sequence from journey steps (e.g. "page_view → add_to_cart → purchase").
     *
     * @param  list<array{name: string, params: array<string, mixed>, timestamp: string, page?: string|null}>  $steps
     */
    private function extractSequence(array $steps): string
    {
        if (count($steps) === 0) {
            return '';
        }

        return implode(' → ', array_column($steps, 'name'));
    }

    /**
     * Truncate a sequence string to N steps.
     */
    private function truncateSequence(string $sequence, int $steps): string
    {
        $parts = explode(' → ', $sequence);

        if (count($parts) <= $steps) {
            return $sequence;
        }

        return implode(' → ', array_slice($parts, -$steps)) . ' …';
    }

    /**
     * Match a pattern against an event sequence, supporting '*' wildcards.
     *
     * @param  list<string>  $pattern  Pattern steps
     * @param  list<string>  $events  Event sequence
     * @return int Position of match or -1
     */
    private function matchPattern(array $pattern, array $events): int
    {
        $patternLen = count($pattern);
        $eventsLen = count($events);

        if ($patternLen === 0 || $eventsLen === 0 || $patternLen > $eventsLen) {
            return -1;
        }

        for ($i = 0; $i <= $eventsLen - $patternLen; $i++) {
            $match = true;

            for ($j = 0; $j < $patternLen; $j++) {
                if ($pattern[$j] !== '*' && $pattern[$j] !== $events[$i + $j]) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Extract unique page flow from journey steps.
     *
     * @param  list<array{name: string, params: array<string, mixed>, timestamp: string, page?: string|null}>  $steps
     * @return list<string>
     */
    private function getPageFlowForSteps(array $steps): array
    {
        $pages = [];

        foreach ($steps as $step) {
            if (isset($step['page']) && $step['page'] !== null) {
                if ($pages === [] || end($pages) !== $step['page']) {
                    $pages[] = $step['page'];
                }
            }
        }

        return $pages;
    }

    /**
     * Estimate journey duration from first to last step timestamps.
     *
     * @param  array{steps: list<array{name: string, params: array<string, mixed>, timestamp: string, page?: string|null}>, user_id: string|null, client_id: string|null, started_at: string|null}  $journey
     */
    private function estimateDuration(array $journey): ?int
    {
        $steps = $journey['steps'];

        if (count($steps) < 2) {
            return null;
        }

        $firstTs = strtotime($steps[0]['timestamp']);
        $lastTs = strtotime($steps[count($steps) - 1]['timestamp']);

        if ($firstTs === false || $lastTs === false) {
            return null;
        }

        return $lastTs - $firstTs;
    }
}
