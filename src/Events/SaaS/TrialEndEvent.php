<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user's trial period ends (converted or expired).
 *
 * GA4: trial_end (custom)
 * Meta: (custom event)
 *
 * @since 1.0.0
 */
final readonly class TrialEndEvent extends AnalyticsEvent
{
    /**
     * @param  string  $outcome  'converted' or 'expired'
     * @param  string|null  $planName  Trial plan name
     */
    public function __construct(string $outcome, ?string $planName = null){
        parent::__construct('trial_end', array_filter([
            'outcome' => $outcome,
            'plan_name' => $planName,
        ]));
    }
}
