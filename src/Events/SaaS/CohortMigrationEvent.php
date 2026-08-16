<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks user migration between cohorts.
 *
 * Fired when a user moves from one cohort to another (e.g. re-segmentation,
 * plan change that affects cohort assignment).
 *
 * GA4: cohort_migration
 *
 * @since 1.0.0
 */
final readonly class CohortMigrationEvent extends AnalyticsEvent
{
    /**
     * @param  string  $userId  User ID
     * @param  string  $fromCohort  Source cohort name
     * @param  string  $toCohort  Destination cohort name
     * @param  string|null  $reason  Migration reason
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $userId,
        string $fromCohort,
        string $toCohort,
        ?string $reason = null,
        array $params = [],
    ): void {
        parent::__construct('cohort_migration', array_filter([
            'user_id' => $userId,
            'from_cohort' => $fromCohort,
            'to_cohort' => $toCohort,
            'reason' => $reason,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
