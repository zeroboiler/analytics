<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a refund for a purchase.
 *
 * GA4: refund
 * Meta: (no standard equivalent — sent as custom event)
 */
final readonly class RefundEvent extends AnalyticsEvent
{
    /**
     * @param  string  $transactionId  The original transaction ID being refunded
     * @param  float|null  $refundValue  Refund amount (null for full refund)
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  array<int, array{item_id: string, item_name?: string, price?: float, quantity?: int}>  $items  Refunded items
     * @param  string|null  $reason  Refund reason
     */
    public function __construct(
        string $transactionId,
        ?float $refundValue = null,
        string $currency = 'USD',
        array $items = [],
        ?string $reason = null,
    ) {
        parent::__construct('refund', array_filter([
            'transaction_id' => $transactionId,
            'value' => $refundValue,
            'currency' => $currency,
            'items' => $items,
            'reason' => $reason,
        ]));
    }
}
