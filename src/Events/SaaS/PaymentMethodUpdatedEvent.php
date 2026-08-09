<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Payment method update/change event.
 *
 * Tracks when a user updates their payment method, changes card details,
 * switches payment processors, or updates billing information. Important
 * for revenue operations analytics and payment optimization.
 *
 * @phpstan-type PaymentMethodUpdatedParams array{
 *     payment_method: string,
 *     change_type: string,
 *     processor?: string|null,
 *     is_default?: bool,
 *     ...array<string, mixed>
 * }
 *
 * @since 1.0.0
 */
final class PaymentMethodUpdatedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $paymentMethod  New payment method type (credit_card, bank_transfer, paypal, etc.)
     * @param  string  $changeType  Type of change (added, updated, removed, set_default)
     * @param  string|null  $processor  Payment processor (stripe, paypal, braintree)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $paymentMethod,
        string $changeType,
        ?string $processor = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'payment_method_updated',
            params: array_merge([
                'payment_method' => $paymentMethod,
                'change_type' => $changeType,
                'processor' => $processor,
            ], $params),
        );
    }
}
