<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * Fired when a user pauses their subscription (temporarily suspends billing).
 *
 * Tracks pause patterns for retention analysis and revenue forecasting.
 * Common in SaaS with pause-resume functionality (e.g., seasonal businesses,
 * freelancers between projects).
 *
 * @since 1.0.0
 */
final readonly class SubscriptionPausedEvent extends \ZeroBoiler\Analytics\DTO\AnalyticsEvent
{
    /**
     * @param  string  $plan  The plan being paused
     * @param  string|null  $reason  Reason for pausing (e.g. 'budget', 'not_using', 'seasonal', 'temporary')
     * @param  int|null  $pauseDurationDays  Expected pause duration (if known)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $plan,
        ?string $reason = null,
        ?int $pauseDurationDays = null,
        ?string $userId = null,
        ?string $clientId = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'subscription_paused',
            params: array_filter([
                'plan' => $plan,
                'reason' => $reason,
                'pause_duration_days' => $pauseDurationDays,
                ...$params,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
