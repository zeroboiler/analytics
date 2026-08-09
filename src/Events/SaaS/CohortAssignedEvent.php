<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user is assigned to a cohort.
 *
 * Used for cohort-based analytics to segment users by signup period,
 * behavior, or other criteria.
 *
 * GA4: cohort_assigned
 *
 * @since 1.0.0
 */
final readonly class CohortAssignedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $cohortName  Cohort identifier (e.g. '2026-W32', '2026-08')
     * @param  string  $userId  User ID being assigned
     * @param  string|null  $source  Acquisition source (e.g. 'signup', 'import')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortName,
        string $userId,
        ?string $source = null,
        array $params = [],
    ): void {
        parent::__construct('cohort_assigned', array_filter([
            'cohort_name' => $cohortName,
            'user_id' => $userId,
            'source' => $source,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
