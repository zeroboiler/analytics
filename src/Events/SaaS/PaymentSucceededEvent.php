<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a successful payment.
 *
 * GA4: payment_succeeded (custom)
 * Meta: PaymentSucceeded (custom)
 *
 * @since 1.0.0
 */
final readonly class PaymentSucceededEvent extends AnalyticsEvent
{
    /**
     * @param  float|null  $amount  Payment amount
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  string|null  $paymentMethod  Payment method type ('card', 'paypal', 'bank_transfer', 'crypto')
     * @param  string|null  $invoiceId  Associated invoice ID
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        ?float $amount = null,
        ?string $currency = null,
        ?string $paymentMethod = null,
        ?string $invoiceId = null,
        array $metadata = [],
    ): void {
        parent::__construct('payment_succeeded', array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'invoice_id' => $invoiceId,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
