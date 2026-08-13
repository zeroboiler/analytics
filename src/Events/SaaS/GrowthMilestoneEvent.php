<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Growth milestone event.
 *
 * Tracks when a user or organization reaches a significant growth milestone:
 * activation (aha moment), power user, advocate, team scale-up, revenue tier.
 * Used by SaaSGrowthMetricsService to measure North Star progress.
 *
 * @since 78.0.0
 */
final readonly class GrowthMilestoneEvent extends AnalyticsEvent
{
    /**
     * @param  string  $milestoneType  Milestone category: activation, power_user, advocate, team_scale, revenue_tier
     * @param  string  $milestoneName  Human-readable milestone name (e.g. 'Sent 100 messages', 'Reached $10k MRR')
     * @param  int|null  $milestoneValue  Numeric value associated with the milestone
     * @param  int|null  $daysSinceSignup  Days since user signed up
     * @param  string|null  $previousMilestone  Name of previous milestone reached
     */
    public function __construct(
        string $milestoneType,
        string $milestoneName,
        ?int $milestoneValue = null,
        ?int $daysSinceSignup = null,
        ?string $previousMilestone = null,
    ): void {
        parent::__construct(
            'growth_milestone',
            array_filter([
                'milestone_type' => $milestoneType,
                'milestone_name' => $milestoneName,
                'milestone_value' => $milestoneValue,
                'days_since_signup' => $daysSinceSignup,
                'previous_milestone' => $previousMilestone,
            ], fn ($v) => $v !== null),
        );
    }
}
