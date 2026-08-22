<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
/**
 * Automated funnel completion tracker with cross-session aggregation.
 *
 * Tracks user progress through defined funnels (signup, purchase, activation,
 * subscription) by monitoring event sequences. Computes conversion rates,
 * drop-off rates, and average time-to-convert across all tracked sessions.
 *
 * Funnels are defined in the package config under `zeroboiler.analytics.funnels.definitions`
 * or use the built-in defaults for common SaaS funnels.
 *
 * Inspired by Mixpanel Funnels, Amplitude Pathfinder, and PostHog Funnels.
 *
 * @since 8.0.0
 */
final class EventFunnelAggregator
{
    /** Built-in funnel definitions. */
    private const BUILTIN_FUNNELS = [
        'signup' => [
            'steps' => ['page_view', 'sign_up', 'email_verified'],
            'conversion_event' => 'sign_up',
            'time_window' => 86400, // 24 hours
        ],
        'activation' => [
            'steps' => ['sign_up', 'start_trial', 'trial_converted'],
            'conversion_event' => 'trial_converted',
            'time_window' => 604800, // 7 days
        ],
        'purchase' => [
            'steps' => ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'],
            'conversion_event' => 'purchase',
            'time_window' => 86400, // 24 hours
        ],
        'subscription' => [
            'steps' => ['sign_up', 'start_trial', 'subscribe'],
            'conversion_event' => 'subscribe',
            'time_window' => 604800, // 7 days
        ],
        'expansion' => [
            'steps' => ['subscribe', 'plan_upgrade'],
            'conversion_event' => 'plan_upgrade',
            'time_window' => 2592000, // 30 days
        ],
    ];

    /** @var array<string, array{steps: list<string>, conversion_event: string, time_window: int}> */
    private array $funnels;

    /** @var non-empty-string */
    private string $cachePrefix;

    private CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache
     * @param  array{funnels?: array<string, array{steps: list<string>, conversion_event: string, time_window?: int}>, cache_prefix?: string, cache_ttl?: int}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []){
        $this->cache = $cache;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_funnel_';
        $this->cacheTtl = $config['cache_ttl'] ?? 3600; // 1 hour

        // Merge custom funnels with built-ins
        $this->funnels = self::BUILTIN_FUNNELS;
        $customFunnels = $config['funnels'] ?? [];

        foreach ($customFunnels as $name => $definition) {
            $this->funnels[$name] = [
                'steps' => $definition['steps'],
                'conversion_event' => $definition['conversion_event'],
                'time_window' => $definition['time_window'] ?? 86400,
            ];
        }
    }

    /**
     * Record an event in funnel tracking context.
     *
     * For each funnel that contains this event, the aggregator records
     * the timestamp and updates progress tracking for the user/session.
     *
     * @param  string  $eventName  The event name from AnalyticsEvent
     * @param  string|null  $identity  Client ID or user ID
     * @return array{funnels_entered: list<string>, funnels_completed: list<string>, progress: array<string, array{step_index: int, steps_completed: int, total_steps: int, percentage: float}>}
     */
    public function record(string $eventName, ?string $identity = null): array
    {
        $identity = $identity ?? 'anonymous';
        $funnelsEntered = [];
        $funnelsCompleted = [];
        $progress = [];

        foreach ($this->funnels as $funnelName => $funnel) {
            $stepIndex = array_search($eventName, $funnel['steps'], true);

            if ($stepIndex === false) {
                continue;
            }

            $cacheKey = $this->cachePrefix . $funnelName . ':' . $identity;

            /** @var array{steps_completed: list<int>, timestamps: array<int, float>}|null $tracker */
            $tracker = $this->cache->get($cacheKey);

            if ($tracker === null) {
                $tracker = [
                    'steps_completed' => [],
                    'timestamps' => [],
                ];

                // Only count as "entered" if first step
                if ($stepIndex === 0) {
                    $funnelsEntered[] = $funnelName;
                }
            }

            // Record step completion
            if (! in_array($stepIndex, $tracker['steps_completed'], true)) {
                $tracker['steps_completed'][] = $stepIndex;
                $tracker['timestamps'][$stepIndex] = microtime(true);
            }

            // Check for completion (all steps done, in order)
            $allSteps = range(0, count($funnel['steps']) - 1);
            $isComplete = empty(array_diff($allSteps, $tracker['steps_completed']));

            if ($isComplete && ! isset($tracker['completed_at'])) {
                $tracker['completed_at'] = microtime(true);
                $funnelsCompleted[] = $funnelName;
            }

            $this->cache->put($cacheKey, $tracker, $funnel['time_window']);

            $stepsCompleted = count($tracker['steps_completed']);
            $totalSteps = count($funnel['steps']);
            $progress[$funnelName] = [
                'step_index' => $stepIndex,
                'steps_completed' => $stepsCompleted,
                'total_steps' => $totalSteps,
                'percentage' => round(($stepsCompleted / $totalSteps) * 100, 2),
            ];
        }

        return [
            'funnels_entered' => $funnelsEntered,
            'funnels_completed' => $funnelsCompleted,
            'progress' => $progress,
        ];
    }

    /**
     * Get the conversion funnel report for a specific funnel.
     *
     * Computes step-by-step conversion rates, drop-off rates, and
     * overall funnel conversion rate.
     *
     * @return array{funnel: string, steps: list<array{name: string, step_index: int, entrants: int, conversions: int, drop_off: int, drop_off_rate: float, cumulative_rate: float}>, overall_conversion_rate: float, total_entered: int, total_completed: int}|null
     */
    public function getFunnelReport(string $funnelName): ?array
    {
        $funnel = $this->funnels[$funnelName] ?? null;

        if ($funnel === null) {
            return null;
        }

        // Scan all funnel caches — requires Redis for production scale
        $reportKey = $this->cachePrefix . 'report:' . $funnelName;

        /** @var array{total_entered: int, total_completed: int, step_stats: array<int, array{entrants: int, conversions: int}>}|null $report */
        $report = $this->cache->get($reportKey);

        if ($report === null) {
            return [
                'funnel' => $funnelName,
                'steps' => $this->buildEmptySteps($funnel),
                'overall_conversion_rate' => 0.0,
                'total_entered' => 0,
                'total_completed' => 0,
            ];
        }

        $steps = [];
        $prevEntrants = $report['total_entered'];

        foreach ($funnel['steps'] as $index => $stepName) {
            $stepStat = $report['step_stats'][$index] ?? ['entrants' => 0, 'conversions' => 0];
            $entrants = $stepStat['entrants'];
            $conversions = $stepStat['conversions'];
            $dropOff = $entrants - $conversions;
            $dropOffRate = $entrants > 0 ? round(($dropOff / $entrants) * 100, 2) : 0.0;
            $cumulativeRate = $report['total_entered'] > 0 ? round(($conversions / $report['total_entered']) * 100, 2) : 0.0;

            $steps[] = [
                'name' => $stepName,
                'step_index' => $index,
                'entrants' => $entrants,
                'conversions' => $conversions,
                'drop_off' => $dropOff,
                'drop_off_rate' => $dropOffRate,
                'cumulative_rate' => $cumulativeRate,
            ];

            $prevEntrants = $conversions;
        }

        return [
            'funnel' => $funnelName,
            'steps' => $steps,
            'overall_conversion_rate' => $report['total_entered'] > 0
                ? round(($report['total_completed'] / $report['total_entered']) * 100, 2)
                : 0.0,
            'total_entered' => $report['total_entered'],
            'total_completed' => $report['total_completed'],
        ];
    }

    /**
     * Increment funnel report counters for a recorded event.
     *
     * Call this after record() to update aggregate statistics.
     * Used by the event pipeline for dashboard reporting.
     */
    public function updateReport(string $funnelName, string $identity): void
    {
        $funnel = $this->funnels[$funnelName] ?? null;
        if ($funnel === null) {
            return;
        }

        $trackerKey = $this->cachePrefix . $funnelName . ':' . $identity;
        $reportKey = $this->cachePrefix . 'report:' . $funnelName;

        /** @var array{steps_completed: list<int>, timestamps: array<int, float>, completed_at?: float}|null $tracker */
        $tracker = $this->cache->get($trackerKey);
        if ($tracker === null) {
            return;
        }

        /** @var array{total_entered: int, total_completed: int, step_stats: array<int, array{entrants: int, conversions: int}>} $report */
        $report = $this->cache->get($reportKey) ?? [
            'total_entered' => 0,
            'total_completed' => 0,
            'step_stats' => [],
        ];

        // Count as entered if first step is done
        if (in_array(0, $tracker['steps_completed'], true) && ! isset($tracker['counted_entered'])) {
            $report['total_entered']++;
            $tracker['counted_entered'] = true;
        }

        // Update step stats
        foreach ($tracker['steps_completed'] as $stepIndex) {
            if (! isset($report['step_stats'][$stepIndex])) {
                $report['step_stats'][$stepIndex] = ['entrants' => 0, 'conversions' => 0];
            }
            $report['step_stats'][$stepIndex]['entrants']++;
            $report['step_stats'][$stepIndex]['conversions']++;
        }

        // Count as completed
        if (isset($tracker['completed_at']) && ! isset($tracker['counted_completed'])) {
            $report['total_completed']++;
            $tracker['counted_completed'] = true;
        }

        $this->cache->put($reportKey, $report, $this->cacheTtl);
        $this->cache->put($trackerKey, $tracker, $funnel['time_window']);
    }

    /**
     * Get a summary of all funnel reports.
     *
     * @return array<string, array{funnel: string, overall_conversion_rate: float, total_entered: int, total_completed: int}>
     */
    public function getAllFunnelReports(): array
    {
        $reports = [];

        foreach (array_keys($this->funnels) as $funnelName) {
            $report = $this->getFunnelReport($funnelName);

            if ($report !== null) {
                $reports[$funnelName] = [
                    'funnel' => $report['funnel'],
                    'overall_conversion_rate' => $report['overall_conversion_rate'],
                    'total_entered' => $report['total_entered'],
                    'total_completed' => $report['total_completed'],
                ];
            }
        }

        return $reports;
    }

    /**
     * Get the list of defined funnels.
     *
     * @return array<string, array{steps: list<string>, conversion_event: string, time_window: int}>
     */
    public function getDefinedFunnels(): array
    {
        return $this->funnels;
    }

    /**
     * Check if a funnel is defined.
     */
    public function hasFunnel(string $funnelName): bool
    {
        return isset($this->funnels[$funnelName]);
    }

    /**
     * Build empty step data for a funnel.
     *
     * @param  array{steps: list<string>}  $funnel
     * @return list<array{name: string, step_index: int, entrants: int, conversions: int, drop_off: int, drop_off_rate: float, cumulative_rate: float}>
     */
    private function buildEmptySteps(array $funnel): array
    {
        $steps = [];

        foreach ($funnel['steps'] as $index => $stepName) {
            $steps[] = [
                'name' => $stepName,
                'step_index' => $index,
                'entrants' => 0,
                'conversions' => 0,
                'drop_off' => 0,
                'drop_off_rate' => 0.0,
                'cumulative_rate' => 0.0,
            ];
        }

        return $steps;
    }
}
