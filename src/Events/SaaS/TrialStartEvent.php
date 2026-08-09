<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user starts a trial period.
 *
 * GA4: start_trial
 * Meta: StartTrial (custom)
 *
 * @since 1.0.0
 */
final readonly class TrialStartEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $planName  Trial plan name (e.g. 'pro', 'business')
     * @param  int|null  $trialDays  Duration of the trial in days
     */
    public function __construct(?string $planName = null, ?int $trialDays = null): void
    {
        parent::__construct('start_trial', array_filter([
            'plan_name' => $planName,
            'trial_days' => $trialDays,
        ]));
    }
}
