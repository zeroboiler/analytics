<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * Fired when a previously cancelled subscription is resumed.
 *
 * Tracks reactivation patterns for churn analysis and win-back metrics.
 */
final class SubscriptionResumedEvent extends \ZeroBoiler\Analytics\DTO\AnalyticsEvent
{
    /**
     * @param  string  $plan  The plan being resumed
     * @param  string|null  $previousPlan  The plan before cancellation
     * @param  int|null  $daysSinceCancellation  Days between cancellation and resumption
     * @param  string|null  $reactivationSource  How the user was reactivated (e.g. 'win_back_email', 'self_serve')
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $plan,
        ?string $previousPlan = null,
        ?int $daysSinceCancellation = null,
        ?string $reactivationSource = null,
        ?string $userId = null,
        ?string $clientId = null,
        array $params = [],
    ) {
        parent::__construct(
            name: 'subscription_resumed',
            params: array_filter([
                'plan' => $plan,
                'previous_plan' => $previousPlan,
                'days_since_cancellation' => $daysSinceCancellation,
                'reactivation_source' => $reactivationSource,
                ...$params,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
