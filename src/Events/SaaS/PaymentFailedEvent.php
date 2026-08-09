<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a failed payment attempt.
 *
 * GA4: payment_failed (custom)
 * Meta: PaymentFailed (custom)
 *
 * @since 1.0.0
 */
final readonly class PaymentFailedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $reason  Failure reason ('card_declined', 'insufficient_funds', 'expired', 'network_error')
     * @param  float|null  $amount  Attempted payment amount
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  string|null  $paymentMethod  Payment method type ('card', 'paypal', 'bank_transfer', 'crypto')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        ?string $reason = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $paymentMethod = null,
        array $metadata = [],
    ): void {
        parent::__construct('payment_failed', array_filter([
            'reason' => $reason,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
