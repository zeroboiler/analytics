<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Retention cohort event — tracks user retention status at cohort boundaries.
 *
 * Fired when a user's retention status is evaluated at a cohort interval
 * (e.g., D1, D7, D30). Captures whether the user returned, churned, or
 * is in a dormant state. Used by RetentionCalculator and cohort waterfall analysis.
 *
 * @since 93.0.0
 */
final class RetentionCohortEvent extends AnalyticsEvent
{
    /**
     * Create a new retention cohort event.
     *
     * @param  string  $cohortDay  Cohort day marker (e.g., 'D1', 'D7', 'D30', 'W1', 'M1')
     * @param  string  $status  Retention status: 'retained', 'returning', 'dormant', 'churned'
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortDay,
        string $status,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ) {
        parent::__construct(
            name: 'retention_cohort',
            params: array_merge($params, [
                'cohort_day' => $cohortDay,
                'status' => $status,
            ]),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
