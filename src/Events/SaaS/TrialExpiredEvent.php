<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a trial period expires without conversion or cancellation.
 *
 * Unlike TrialEndEvent (which fires on any trial end, including conversion),
 * TrialExpiredEvent specifically fires when the trial lapsed without user
 * action — the user never converted to paid and never explicitly cancelled.
 * This is a key signal for re-engagement campaigns.
 *
 * GA4: trial_expired
 * Meta: CustomEvent
 * PostHog: trial_expired
 *
 * @since 1.0.0
 */
final readonly class TrialExpiredEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $plan  The trial plan (e.g. 'pro', 'business')
     * @param  int|null  $trialLengthDays  Trial duration in days
     * @param  int|null  $featuresUsedCount  Number of features the user tried during trial
     * @param  string|null  $lastActivity  Last activity date (ISO 8601)
     */
    public function __construct(
        ?string $plan = null,
        ?int $trialLengthDays = null,
        ?int $featuresUsedCount = null,
        ?string $lastActivity = null,
    ){
        parent::__construct('trial_expired', array_filter([
            'plan' => $plan,
            'trial_length_days' => $trialLengthDays,
            'features_used_count' => $featuresUsedCount,
            'last_activity' => $lastActivity,
        ]));
    }
}
