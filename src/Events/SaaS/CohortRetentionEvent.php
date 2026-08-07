<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks user retention within a cohort.
 *
 * Fired when a user returns after N days, indicating active retention.
 * Used for D7, D14, D30, D60, D90 retention tracking.
 *
 * GA4: cohort_retention
 */
final readonly class CohortRetentionEvent extends AnalyticsEvent
{
    /**
     * @param  string  $cohortName  Cohort identifier
     * @param  string  $userId  User ID
     * @param  int  $daysSinceStart  Days since cohort start (e.g. 7, 30)
     * @param  string|null  $period  Period label (e.g. 'd7', 'd30')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortName,
        string $userId,
        int $daysSinceStart,
        ?string $period = null,
        array $params = [],
    ): void {
        parent::__construct('cohort_retention', array_filter([
            'cohort_name' => $cohortName,
            'user_id' => $userId,
            'days_since_start' => $daysSinceStart,
            'period' => $period,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
