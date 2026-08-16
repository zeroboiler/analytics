<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks user churn within a cohort.
 *
 * Fired when a user cancels, deactivates, or stops using the product.
 * Essential for cohort-based churn analysis.
 *
 * GA4: cohort_churn
 *
 * @since 1.0.0
 */
final readonly class CohortChurnEvent extends AnalyticsEvent
{
    /**
     * @param  string  $cohortName  Cohort identifier
     * @param  string  $userId  User ID
     * @param  int  $daysSinceStart  Days since cohort start
     * @param  string|null  $reason  Churn reason (e.g. 'too_expensive', 'inactive')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortName,
        string $userId,
        int $daysSinceStart,
        ?string $reason = null,
        array $params = [],
    ): void {
        parent::__construct('cohort_churn', array_filter([
            'cohort_name' => $cohortName,
            'user_id' => $userId,
            'days_since_start' => $daysSinceStart,
            'reason' => $reason,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
