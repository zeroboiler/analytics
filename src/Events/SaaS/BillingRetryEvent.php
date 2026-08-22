<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a billing retry (dunning) attempt occurs.
 *
 * Tracks the dunning lifecycle for subscription recovery analytics:
 * failed payment retries, recovery success/failure, and the number of
 * attempts made. Critical for revenue retention dashboards and
 * payment recovery rate calculations.
 *
 * @phpstan-import-type EventParams from AnalyticsEvent
 *
 * @since 1.0.0
 */
final readonly class BillingRetryEvent extends AnalyticsEvent
{
    /**
     * @param  string  $status  Retry outcome (attempted, succeeded, failed, exhausted)
     * @param  int  $attemptNumber  Which retry attempt this is (1-based)
     * @param  string|null  $plan  The subscription plan being retried
     * @param  float|null  $amount  The charge amount being retried
     * @param  string|null  $currency  ISO 4217 currency code (default: USD)
     * @param  string|null  $failureReason  Why the retry failed (card_declined, insufficient_funds, expired_card, etc.)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function __construct(
        string $status,
        int $attemptNumber,
        ?string $plan = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $failureReason = null,
        ?string $userId = null,
        ?string $clientId = null,
    ){
        parent::__construct(
            name: 'billing_retry',
            params: array_filter([
                'status' => $status,
                'attempt_number' => $attemptNumber,
                'plan' => $plan,
                'amount' => $amount,
                'currency' => $currency ?? 'USD',
                'failure_reason' => $failureReason,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
