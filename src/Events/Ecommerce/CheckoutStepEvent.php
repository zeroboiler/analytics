<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Checkout step event for multi-step e-commerce funnels.
 *
 * Tracks individual checkout steps (shipping, payment, review) with
 * step index for funnel analysis and optional payment method info.
 *
 * @since 1.0.0
 */
final readonly class CheckoutStepEvent extends AnalyticsEvent
{
    /**
     * @param  int  $stepIndex  One-based checkout step number (1 = shipping, 2 = payment, etc.)
     * @param  string|null  $stepName  Human-readable step name
     * @param  string|null  $paymentMethod  Payment method type (credit_card, paypal, etc.)
     * @param  float|null  $orderTotal  Running order total at this step
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  list<array<string, mixed>>|null  $items  Cart items at this step
     */
    public function __construct(
        int $stepIndex,
        ?string $stepName = null,
        ?string $paymentMethod = null,
        ?float $orderTotal = null,
        ?string $currency = null,
        ?array $items = null,
    ){
        parent::__construct(
            name: 'checkout_step',
            params: array_filter([
                'step_index' => $stepIndex,
                'step_name' => $stepName,
                'payment_method' => $paymentMethod,
                'order_total' => $orderTotal,
                'currency' => $currency,
                'items' => $items,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
