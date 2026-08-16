<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a goal progress snapshot.
 *
 * Captures the current state of a goal: actual value vs target,
 * completion percentage, trend direction, and alert status.
 *
 * @since 177.0.0
 */
final readonly class GoalProgress
{
    /**
     * @param  string  $goalKey  Goal identifier
     * @param  string  $goalName  Human-readable goal name
     * @param  float  $actual  Current actual value
     * @param  float  $target  Target value
     * @param  float  $percentage  Completion percentage (0-100, can exceed 100)
     * @param  string  $status  Goal status: 'on_track', 'at_risk', 'behind', 'achieved', 'exceeded', 'no_data'
     * @param  string|null  $trend  Trend direction: 'up', 'down', 'flat', 'volatile', null
     * @param  string  $window  Time window of the measurement
     * @param  string|null  $period  Human-readable period label (e.g. '2026-08-16')
     * @param  float|null  $previousActual  Previous period actual for comparison
     * @param  float|null  $changePercent  Percentage change from previous period
     * @param  array<string, mixed>  $meta  Additional metadata
     */
    public function __construct(
        public string $goalKey,
        public string $goalName,
        public float $actual,
        public float $target,
        public float $percentage,
        public string $status,
        public ?string $trend = null,
        public string $window = 'daily',
        public ?string $period = null,
        public ?float $previousActual = null,
        public ?float $changePercent = null,
        public array $meta = [],
    ): void {}

    /**
     * Create from goal and actual value.
     */
    public static function fromGoal(AnalyticsGoal $goal, float $actual, ?float $previousActual = null, ?string $trend = null, ?string $period = null): self
    {
        $target = $goal->target;
        $percentage = $target > 0 ? ($actual / $target) * 100 : ($actual > 0 ? 100.0 : 0.0);

        $status = self::calculateStatus($percentage, $actual, $goal->warningThreshold, $goal->criticalThreshold);

        $changePercent = null;
        if ($previousActual !== null && $previousActual > 0) {
            $changePercent = (($actual - $previousActual) / $previousActual) * 100;
        }

        return new self(
            goalKey: $goal->key,
            goalName: $goal->name,
            actual: $actual,
            target: $target,
            percentage: $percentage,
            status: $status,
            trend: $trend,
            window: $goal->window,
            period: $period,
            previousActual: $previousActual,
            changePercent: $changePercent,
            meta: $goal->meta,
        );
    }

    /**
     * Determine goal status from percentage and thresholds.
     */
    private static function calculateStatus(
        float $percentage,
        float $actual,
        ?float $warningThreshold,
        ?float $criticalThreshold,
    ): string {
        if ($actual <= 0) {
            return 'no_data';
        }

        if ($percentage >= 100) {
            return $percentage > 110 ? 'exceeded' : 'achieved';
        }

        if ($criticalThreshold !== null && $percentage < $criticalThreshold) {
            return 'behind';
        }

        if ($warningThreshold !== null && $percentage < $warningThreshold) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'goal_key' => $this->goalKey,
            'goal_name' => $this->goalName,
            'actual' => $this->actual,
            'target' => $this->target,
            'percentage' => round($this->percentage, 2),
            'status' => $this->status,
            'trend' => $this->trend,
            'window' => $this->window,
            'period' => $this->period,
            'previous_actual' => $this->previousActual,
            'change_percent' => $this->changePercent !== null ? round($this->changePercent, 2) : null,
            'meta' => $this->meta,
        ];
    }
}
