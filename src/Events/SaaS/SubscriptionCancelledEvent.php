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
 * While CancellationEvent provides general cancellation tracking,
 * SubscriptionCancelledEvent specifically captures the full cancellation
 * context including the cancellation flow, retention offer status, and
 * effective date — critical for churn analytics and win-back campaigns.
 *
 * GA4: subscription_cancelled
 * Meta: CancelSubscription
 * PostHog: subscription_cancelled
 *
 * @since 1.0.0
 */
final readonly class SubscriptionCancelledEvent extends AnalyticsEvent
{
    /**
     * @param  string  $plan  Cancelled plan name (e.g. 'pro', 'enterprise')
     * @param  string|null  $reason  Cancellation reason (e.g. 'too_expensive', 'missing_features', 'switched_competitor')
     * @param  string|null  $flow  Cancellation flow (e.g. 'self_service', 'support', 'automatic')
     * @param  string|null  $effectiveDate  When the cancellation takes effect (ISO 8601)
     * @param  bool|null  $retentionOfferAccepted  Whether the user accepted a retention offer before cancelling
     */
    public function __construct(
        string $plan,
        ?string $reason = null,
        ?string $flow = null,
        ?string $effectiveDate = null,
        ?bool $retentionOfferAccepted = null,
    ): void {
        parent::__construct('subscription_cancelled', array_filter([
            'plan' => $plan,
            'reason' => $reason,
            'flow' => $flow,
            'effective_date' => $effectiveDate,
            'retention_offer_accepted' => $retentionOfferAccepted,
        ]));
    }
}
