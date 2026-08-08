<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Checkout abandonment event — fired when a user begins checkout but leaves before payment.
 *
 * More specific than abandoned_cart — tracks the checkout step where the user dropped off.
 * Critical for identifying checkout flow friction and optimizing conversion rates.
 *
 * @see \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents
 */
final class CheckoutAbandonEvent extends AnalyticsEvent
{
    /**
     * @param  int  $checkoutStep  The checkout step where abandonment occurred (1-indexed)
     * @param  float  $cartValue  Cart value at time of abandonment
     * @param  string|null  $abandonmentReason  Why the user abandoned
     * @param  int|null  $timeOnCheckout  Seconds spent in checkout before abandoning
     * @param  string|null  $paymentMethod  Payment method selected (if any)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        int $checkoutStep = 1,
        float $cartValue = 0.0,
        ?string $abandonmentReason = null,
        ?int $timeOnCheckout = null,
        ?string $paymentMethod = null,
        array $params = [],
    ) {
        parent::__construct(
            name: 'checkout_abandon',
            params: array_filter(array_merge([
                'checkout_step' => $checkoutStep,
                'cart_value' => $cartValue,
                'abandonment_reason' => $abandonmentReason,
                'time_on_checkout' => $timeOnCheckout,
                'payment_method' => $paymentMethod,
            ], $params)),
        );
    }
}
