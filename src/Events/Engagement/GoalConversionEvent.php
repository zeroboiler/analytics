<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Custom goal conversion tracking event.
 *
 * Tracks user-defined conversion goals beyond standard e-commerce and SaaS events.
 * Useful for tracking signup completions, onboarding milestones, feature activations,
 * and any custom business objective. Supports goal value assignment for ROI calculation.
 *
 * @phpstan-type GoalConversionParams array{
 *     goal_name: string,
 *     goal_category: string|null,
 *     goal_value: float|null,
 *     goal_id?: string|null,
 *     funnel_step?: int|null,
 *     ...array<string, mixed>
 * }
 *
 * @since 1.0.0
 */
final class GoalConversionEvent extends AnalyticsEvent
{
    /**
     * @param  string  $goalName  Name of the conversion goal
     * @param  string|null  $goalCategory  Goal category (onboarding, activation, revenue, retention)
     * @param  float|null  $goalValue  Monetary value assigned to this conversion
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $goalName,
        ?string $goalCategory = null,
        ?float $goalValue = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'goal_conversion',
            params: array_merge([
                'goal_name' => $goalName,
                'goal_category' => $goalCategory,
                'goal_value' => $goalValue,
            ], $params),
        );
    }
}
