<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a conversion event within a cohort.
 *
 * Used for tracking key conversion milestones (e.g. trial → paid,
 * free → premium) within cohort-based analytics.
 *
 * GA4: cohort_conversion
 *
 * @since 1.0.0
 */
final readonly class CohortConversionEvent extends AnalyticsEvent
{
    /**
     * @param  string  $cohortName  Cohort identifier
     * @param  string  $userId  User ID
     * @param  string  $conversionType  Conversion type (e.g. 'trial_to_paid')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $cohortName,
        string $userId,
        string $conversionType,
        array $params = [],
    ){
        parent::__construct('cohort_conversion', array_filter([
            'cohort_name' => $cohortName,
            'user_id' => $userId,
            'conversion_type' => $conversionType,
            ...$params,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
