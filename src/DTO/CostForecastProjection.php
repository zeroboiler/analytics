<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Cost forecast projection for a single analytics provider.
 *
 * Represents a predicted cost trajectory based on historical event volume
 * trends, configured per-event cost rates, and growth extrapolation.
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService
 */
final class CostForecastProjection
{
    /**
     * Create a new cost forecast projection.
     *
     * @param  string  $provider  Provider identifier (ga4, meta_pixel, posthog, etc.)
     * @param  string  $period  Forecast period (e.g., '2026-09', '2026-Q4')
     * @param  int  $projectedEvents  Predicted total event volume
     * @param  float  $projectedCost  Predicted cost in configured currency
     * @param  float  $currentCost  Current period actual cost
     * @param  float  $growthRate  Volume growth rate percentage (e.g., 12.5 for 12.5%)
     * @param  float  $costPerEvent  Average cost per event
     * @param  int  $confidenceInterval  Statistical confidence level (0-100)
     * @param  float  $lowerBound  Lower bound of cost projection
     * @param  float  $upperBound  Upper bound of cost projection
     * @param  array<string, mixed>  $breakdown  Cost breakdown by event category
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $period,
        public readonly int $projectedEvents,
        public readonly float $projectedCost,
        public readonly float $currentCost,
        public readonly float $growthRate,
        public readonly float $costPerEvent,
        public readonly int $confidenceInterval,
        public readonly float $lowerBound,
        public readonly float $upperBound,
        public array $breakdown = [],
    ){}

    /**
     * Calculate the cost change percentage vs current period.
     */
    public function costChangePercentage(): float
    {
        if ($this->currentCost === 0.0) {
            return 0.0;
        }

        return (($this->projectedCost - $this->currentCost) / $this->currentCost) * 100;
    }

    /**
     * Check if the forecast indicates a significant cost increase (>= 20%).
     */
    public function isSignificantIncrease(): bool
    {
        return $this->costChangePercentage() >= 20.0;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'period' => $this->period,
            'projected_events' => $this->projectedEvents,
            'projected_cost' => $this->projectedCost,
            'current_cost' => $this->currentCost,
            'growth_rate' => $this->growthRate,
            'cost_per_event' => $this->costPerEvent,
            'confidence_interval' => $this->confidenceInterval,
            'lower_bound' => $this->lowerBound,
            'upper_bound' => $this->upperBound,
            'cost_change_percentage' => $this->costChangePercentage(),
            'is_significant_increase' => $this->isSignificantIncrease(),
            'breakdown' => $this->breakdown,
        ];
    }
}
