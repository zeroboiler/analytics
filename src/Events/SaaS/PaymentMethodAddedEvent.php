<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a payment method is added or updated.
 *
 * GA4: payment_method_added (custom)
 * Meta: PaymentMethodAdded (custom)
 */
final readonly class PaymentMethodAddedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $paymentMethod  Payment method type ('card', 'paypal', 'bank_transfer', 'crypto')
     * @param  string|null  $brand  Card brand or provider name ('visa', 'mastercard', 'paypal')
     * @param  bool|null  $isDefault  Whether this is set as default
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $paymentMethod = null, ?string $brand = null, ?bool $isDefault = null, array $metadata = []): void
    {
        parent::__construct('payment_method_added', array_filter([
            'payment_method' => $paymentMethod,
            'brand' => $brand,
            'is_default' => $isDefault,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
