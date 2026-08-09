<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a funnel velocity data point.
 *
 * Produced by FunnelVelocityService to measure how long users spend
 * at each step of a conversion funnel, identify bottlenecks, and
 * calculate median/average transition times between steps.
 *
 * @phpstan-type VelocityStep array{step: string, count: int, drop_off_count: int, drop_off_rate: float, avg_seconds: float, median_seconds: float, p75_seconds: float, p90_seconds: float}
 * @phpstan-type VelocityTransition array{from: string, to: string, count: int, avg_seconds: float, median_seconds: float, conversion_rate: float}
 *
 * @since 1.0.0
 */
final readonly class FunnelVelocityReport
{
    /**
     * @param  string  $funnelName  Name of the funnel (e.g. 'signup', 'checkout')
     * @param  list<VelocityStep>  $steps  Per-step velocity data
     * @param  list<VelocityTransition>  $transitions  Step-to-step transition data
     * @param  float  $totalAvgSeconds  Average total time through the funnel (completed only)
     * @param  float  $totalMedianSeconds  Median total time through the funnel
     * @param  int  $completedCount  Number of users who completed the funnel
     * @param  int  $startedCount  Number of users who started the funnel
     * @param  float  $overallConversionRate  Percentage of users who completed the funnel
     * @param  string|null  $bottleneckStep  The step with the highest drop-off rate
     * @param  string|null  $slowestTransition  The transition with the longest median time
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        public string $funnelName,
        public array $steps,
        public array $transitions,
        public float $totalAvgSeconds,
        public float $totalMedianSeconds,
        public int $completedCount,
        public int $startedCount,
        public float $overallConversionRate,
        public ?string $bottleneckStep = null,
        public ?string $slowestTransition = null,
        public array $metadata = [],
    ): void {}

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'funnel_name' => $this->funnelName,
            'steps' => $this->steps,
            'transitions' => $this->transitions,
            'total_avg_seconds' => round($this->totalAvgSeconds, 1),
            'total_median_seconds' => round($this->totalMedianSeconds, 1),
            'completed_count' => $this->completedCount,
            'started_count' => $this->startedCount,
            'overall_conversion_rate' => round($this->overallConversionRate, 4),
            'bottleneck_step' => $this->bottleneckStep,
            'slowest_transition' => $this->slowestTransition,
            'metadata' => $this->metadata,
        ];
    }
}
