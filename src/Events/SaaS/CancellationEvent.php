<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a subscription cancellation.
 *
 * GA4: cancellation (custom)
 * Meta: (custom event)
 *
 * @since 1.0.0
 */
final readonly class CancellationEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $planName  Cancelled plan name
     * @param  string|null  $reason  Cancellation reason (e.g. 'too_expensive', 'not_needed')
     * @param  bool|null  $isTrial  Whether cancellation was during trial
     */
    public function __construct(?string $planName = null, ?string $reason = null, ?bool $isTrial = null){
        parent::__construct('cancellation', array_filter([
            'plan_name' => $planName,
            'reason' => $reason,
            'is_trial' => $isTrial,
        ], fn (mixed $v): bool => $v !== null));
    }
}
