<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Checkout abandonment event — fired when a user begins checkout but leaves without completing.
 *
 * Tracks the checkout step reached, cart value, and time spent before abandonment.
 * Use with FunnelVelocityService to identify the slowest checkout step and optimize.
 *
 * @see \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents
 *
 * @since 1.0.0
 */
final readonly class CheckoutAbandonEvent extends AnalyticsEvent
{
    /**
     * @param  string  $stepReached  Last checkout step reached
     * @param  float  $cartValue  Cart value at time of abandonment
     * @param  string|null  $currency  Currency code (ISO 4217)
     * @param  int|null  $cartItemCount  Number of items in cart
     * @param  int|null  $timeOnStep  Seconds spent on the last step before leaving
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $stepReached = '',
        float $cartValue = 0.0,
        ?string $currency = null,
        ?int $cartItemCount = null,
        ?int $timeOnStep = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'checkout_abandon',
            params: array_filter(array_merge([
                'step_reached' => $stepReached,
                'cart_value' => $cartValue,
                'currency' => $currency,
                'cart_item_count' => $cartItemCount,
                'time_on_step' => $timeOnStep,
            ], $params)),
        );
    }
}
