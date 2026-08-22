<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks invoice generation.
 *
 * GA4: invoice_generated (custom)
 * Meta: InvoiceGenerated (custom)
 *
 * @since 1.0.0
 */
final readonly class InvoiceGeneratedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $invoiceId  Invoice identifier
     * @param  float|null  $amount  Invoice amount
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  string|null  $status  Invoice status ('draft', 'open', 'paid', 'void')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        ?string $invoiceId = null,
        ?float $amount = null,
        ?string $currency = null,
        ?string $status = null,
        array $metadata = [],
    ){
        parent::__construct('invoice_generated', array_filter([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
