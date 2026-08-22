<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user submits payment information during checkout.
 *
 * GA4: add_payment_info
 * Meta: AddPaymentInfo
 *
 * @since 1.0.0
 */
final readonly class AddPaymentInfoEvent extends AnalyticsEvent
{
    /**
     * @param  string  $paymentType  Payment method type (e.g., 'credit_card', 'paypal')
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  float|null  $value  Order value
     * @param  string|null  $coupon  Coupon code applied
     */
    public function __construct(
        string $paymentType,
        string $currency = 'USD',
        ?float $value = null,
        ?string $coupon = null,
    ){
        parent::__construct('add_payment_info', array_filter([
            'currency' => $currency,
            'value' => $value,
            'payment_type' => $paymentType,
            'coupon' => $coupon,
        ]));
    }
}
