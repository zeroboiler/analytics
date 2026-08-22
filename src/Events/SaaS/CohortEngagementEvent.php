<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks cohort engagement summary for periodic aggregation.
 *
 * Fired periodically (e.g. weekly, monthly) to summarize engagement
 * metrics for an entire cohort. Includes engagement rate, active users,
 * total users, and time period.
 *
 * GA4: cohort_engagement
 *
 * @since 1.0.0
 */
final readonly class CohortEngagementEvent extends AnalyticsEvent
{
    /**
     * @param  string  $cohortName  Cohort identifier
     * @param  int  $activeUsers  Number of active users in the period
     * @param  int  $totalUsers  Total users in the cohort
     * @param  string|null  $period  Period label (e.g. 'weekly', 'monthly')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortName,
        int $activeUsers,
        int $totalUsers,
        ?string $period = null,
        array $params = [],
    ){
        $engagementRate = $totalUsers > 0
            ? round(($activeUsers / $totalUsers) * 100, 2)
            : 0.0;

        parent::__construct('cohort_engagement', array_filter([
            'cohort_name' => $cohortName,
            'active_users' => $activeUsers,
            'total_users' => $totalUsers,
            'engagement_rate' => $engagementRate,
            'period' => $period,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
