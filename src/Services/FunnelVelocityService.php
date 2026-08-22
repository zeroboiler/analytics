<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\FunnelVelocityReport;

/**
 * Time-based funnel velocity analysis service.
 *
 * Measures how long users spend at each step of a conversion funnel,
 * identifies bottlenecks (slowest steps and highest drop-off rates),
 * and calculates percentile-based transition times. Designed for
 * optimizing checkout, signup, and onboarding funnels.
 *
 * Unlike FunnelAnalyticsService (which tracks completion rates), this
 * service focuses on the temporal dimension — how fast users move
 * through the funnel.
 *
 * Configuration is read from `zeroboiler.analytics.funnel_velocity`.
 *
 * @phpstan-type FunnelStepData array{user_id: string, step: string, timestamp: float}
 * @phpstan-type UserJourneyData array{user_id: string, steps: list<array{step: string, timestamp: float}>}
 *
 * @since 1.0.0
 */
final class FunnelVelocityService
{
    private bool $enabled;

    private int $percentileWindow;

    /** @var array<string, list<string>> Built-in funnel definitions */
    private array $builtInFunnels;

    private const CACHE_PREFIX = 'zb_fv_';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $fvConfig = $config->get('zeroboiler.analytics.funnel_velocity', []);
        /** @var array{enabled?: bool, percentile_window?: int} $fvConfig */

        $this->enabled = (bool) ($fvConfig['enabled'] ?? true);
        $this->percentileWindow = (int) ($fvConfig['percentile_window'] ?? 100);

        $this->builtInFunnels = [
            'checkout' => ['view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase'],
            'signup' => ['page_view', 'form_start', 'form_submit', 'sign_up'],
            'trial' => ['sign_up', 'start_trial', 'feature_used', 'subscribe'],
            'activation' => ['sign_up', 'first_feature_used', 'profile_completed', 'team_created'],
        ];
    }

    /**
     * Analyze funnel velocity from user journey data.
     *
     * Processes timestamped user events to calculate per-step and
     * per-transition timing metrics.
     *
     * @param  string  $funnelName  Name of the funnel
     * @param  list<string>  $steps  Ordered funnel steps
     * @param  list<UserJourneyData>  $userJourneys  User journey data with timestamps
     * @return FunnelVelocityReport
     */
    public function analyze(string $funnelName, array $steps, array $userJourneys): FunnelVelocityReport
    {
        if (! $this->enabled || empty($steps) || empty($userJourneys)) {
            return $this->emptyReport($funnelName);
        }

        $stepMetrics = $this->calculateStepMetrics($steps, $userJourneys);
        $transitions = $this->calculateTransitions($steps, $userJourneys);

        // Calculate total funnel times for completed journeys
        $completedTimes = [];
        foreach ($userJourneys as $journey) {
            if ($this->journeyCompleted($journey, $steps)) {
                $firstStep = $journey['steps'][0];
                $lastStep = $journey['steps'][count($journey['steps']) - 1];

                // Find the timestamps for first and last funnel steps
                $firstTs = null;
                $lastTs = null;

                foreach ($journey['steps'] as $stepData) {
                    if ($stepData['step'] === $steps[0] && $firstTs === null) {
                        $firstTs = $stepData['timestamp'];
                    }
                    if ($stepData['step'] === $steps[array_key_last($steps)]) {
                        $lastTs = $stepData['timestamp'];
                    }
                }

                if ($firstTs !== null && $lastTs !== null && $lastTs > $firstTs) {
                    $completedTimes[] = $lastTs - $firstTs;
                }
            }
        }

        $completedCount = count($completedTimes);
        $startedCount = count($userJourneys);
        $totalAvg = $completedCount > 0 ? array_sum($completedTimes) / $completedCount : 0;
        $totalMedian = $completedCount > 0 ? $this->median($completedTimes) : 0;

        // Identify bottleneck (highest drop-off)
        $bottleneckStep = null;
        $maxDropOff = 0;

        foreach ($stepMetrics as $metric) {
            if ($metric['drop_off_rate'] > $maxDropOff && $metric['count'] > 0) {
                $maxDropOff = $metric['drop_off_rate'];
                $bottleneckStep = $metric['step'];
            }
        }

        // Identify slowest transition
        $slowestTransition = null;
        $maxMedianTime = 0;

        foreach ($transitions as $transition) {
            if ($transition['median_seconds'] > $maxMedianTime && $transition['count'] > 0) {
                $maxMedianTime = $transition['median_seconds'];
                $slowestTransition = $transition['from'] . ' → ' . $transition['to'];
            }
        }

        return new FunnelVelocityReport(
            funnelName: $funnelName,
            steps: $stepMetrics,
            transitions: $transitions,
            totalAvgSeconds: round($totalAvg, 1),
            totalMedianSeconds: round($totalMedian, 1),
            completedCount: $completedCount,
            startedCount: $startedCount,
            overallConversionRate: $startedCount > 0 ? $completedCount / $startedCount : 0,
            bottleneckStep: $bottleneckStep,
            slowestTransition: $slowestTransition,
            metadata: [
                'step_count' => count($steps),
                'journey_count' => $startedCount,
            ],
        );
    }

    /**
     * Analyze a built-in funnel by name.
     *
     * @param  string  $funnelName  One of: checkout, signup, trial, activation
     * @param  list<UserJourneyData>  $userJourneys
     * @return FunnelVelocityReport
     */
    public function analyzeBuiltin(string $funnelName, array $userJourneys): FunnelVelocityReport
    {
        $steps = $this->builtInFunnels[$funnelName] ?? [];

        if (empty($steps)) {
            return $this->emptyReport($funnelName);
        }

        return $this->analyze($funnelName, $steps, $userJourneys);
    }

    /**
     * Compare two funnel analyses side by side.
     *
     * @param  FunnelVelocityReport  $reportA
     * @param  FunnelVelocityReport  $reportB
     * @return array{comparison: string, total_avg_diff: float, total_median_diff: float, conversion_rate_diff: float, bottleneck_change: string|null, slowest_change: string|null}
     */
    public function compare(FunnelVelocityReport $reportA, FunnelVelocityReport $reportB): array
    {
        return [
            'comparison' => "{$reportA->funnelName} vs {$reportB->funnelName}",
            'total_avg_diff' => round($reportB->totalAvgSeconds - $reportA->totalAvgSeconds, 1),
            'total_median_diff' => round($reportB->totalMedianSeconds - $reportA->totalMedianSeconds, 1),
            'conversion_rate_diff' => round($reportB->overallConversionRate - $reportA->overallConversionRate, 4),
            'bottleneck_change' => $reportA->bottleneckStep !== $reportB->bottleneckStep
                ? "{$reportA->bottleneckStep} → {$reportB->bottleneckStep}"
                : null,
            'slowest_change' => $reportA->slowestTransition !== $reportB->slowestTransition
                ? "{$reportA->slowestTransition} → {$reportB->slowestTransition}"
                : null,
        ];
    }

    /**
     * Get available built-in funnel names.
     *
     * @return list<string>
     */
    public function availableFunnels(): array
    {
        return array_keys($this->builtInFunnels);
    }

    /**
     * Calculate per-step metrics.
     *
     * @param  list<string>  $steps
     * @param  list<UserJourneyData>  $journeys
     * @return list<array{step: string, count: int, drop_off_count: int, drop_off_rate: float, avg_seconds: float, median_seconds: float, p75_seconds: float, p90_seconds: float}>
     */
    private function calculateStepMetrics(array $steps, array $journeys): array
    {
        $metrics = [];

        foreach ($steps as $stepIndex => $stepName) {
            $stepTimes = [];
            $reachedCount = 0;
            $nextStepReachedCount = 0;

            foreach ($journeys as $journey) {
                $userId = $journey['user_id'];

                // Find if this user reached this step
                $stepTimestamp = null;
                $nextStepTimestamp = null;

                foreach ($journey['steps'] as $s) {
                    if ($s['step'] === $stepName && $stepTimestamp === null) {
                        $stepTimestamp = $s['timestamp'];
                        $reachedCount++;

                        // Check if they reached the next step
                        if (isset($steps[$stepIndex + 1])) {
                            foreach ($journey['steps'] as $ns) {
                                if ($ns['step'] === $steps[$stepIndex + 1] && $ns['timestamp'] > $stepTimestamp) {
                                    $nextStepTimestamp = $ns['timestamp'];
                                    $nextStepReachedCount++;

                                    // Time spent on this step
                                    $stepTimes[] = $nextStepTimestamp - $stepTimestamp;

                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            $dropOffCount = $reachedCount - $nextStepReachedCount;
            $dropOffRate = $reachedCount > 0 ? $dropOffCount / $reachedCount : 0;

            $metrics[] = [
                'step' => $stepName,
                'count' => $reachedCount,
                'drop_off_count' => $dropOffCount,
                'drop_off_rate' => round($dropOffRate, 4),
                'avg_seconds' => count($stepTimes) > 0 ? round(array_sum($stepTimes) / count($stepTimes), 1) : 0.0,
                'median_seconds' => count($stepTimes) > 0 ? round($this->median($stepTimes), 1) : 0.0,
                'p75_seconds' => count($stepTimes) > 0 ? round($this->percentile($stepTimes, 75), 1) : 0.0,
                'p90_seconds' => count($stepTimes) > 0 ? round($this->percentile($stepTimes, 90), 1) : 0.0,
            ];
        }

        return $metrics;
    }

    /**
     * Calculate transition metrics between consecutive steps.
     *
     * @param  list<string>  $steps
     * @param  list<UserJourneyData>  $journeys
     * @return list<array{from: string, to: string, count: int, avg_seconds: float, median_seconds: float, conversion_rate: float}>
     */
    private function calculateTransitions(array $steps, array $journeys): array
    {
        $transitions = [];

        for ($i = 0; $i < count($steps) - 1; $i++) {
            $fromStep = $steps[$i];
            $toStep = $steps[$i + 1];
            $transitionTimes = [];
            $fromCount = 0;

            foreach ($journeys as $journey) {
                $fromTimestamp = null;
                $toTimestamp = null;

                foreach ($journey['steps'] as $s) {
                    if ($s['step'] === $fromStep && $fromTimestamp === null) {
                        $fromTimestamp = $s['timestamp'];
                        $fromCount++;
                    }
                    if ($s['step'] === $toStep && $fromTimestamp !== null && $s['timestamp'] > $fromTimestamp) {
                        $toTimestamp = $s['timestamp'];
                        $transitionTimes[] = $toTimestamp - $fromTimestamp;
                        break;
                    }
                }
            }

            $transitions[] = [
                'from' => $fromStep,
                'to' => $toStep,
                'count' => count($transitionTimes),
                'avg_seconds' => count($transitionTimes) > 0 ? round(array_sum($transitionTimes) / count($transitionTimes), 1) : 0.0,
                'median_seconds' => count($transitionTimes) > 0 ? round($this->median($transitionTimes), 1) : 0.0,
                'conversion_rate' => $fromCount > 0 ? round(count($transitionTimes) / $fromCount, 4) : 0.0,
            ];
        }

        return $transitions;
    }

    /**
     * Check if a user journey completed all funnel steps.
     *
     * @param  UserJourneyData  $journey
     * @param  list<string>  $steps
     */
    private function journeyCompleted(array $journey, array $steps): bool
    {
        $reachedSteps = array_map(
            fn (array $s): string => $s['step'],
            $journey['steps'],
        );

        foreach ($steps as $requiredStep) {
            if (! in_array($requiredStep, $reachedSteps, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate the median of a numeric array.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    /**
     * Calculate a percentile value.
     *
     * @param  list<float>  $values
     * @param  int  $percentile  Percentile (0-100)
     */
    private function percentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
    }

    /**
     * Create an empty report.
     */
    private function emptyReport(string $funnelName): FunnelVelocityReport
    {
        return new FunnelVelocityReport(
            funnelName: $funnelName,
            steps: [],
            transitions: [],
            totalAvgSeconds: 0.0,
            totalMedianSeconds: 0.0,
            completedCount: 0,
            startedCount: 0,
            overallConversionRate: 0.0,
        );
    }
}
