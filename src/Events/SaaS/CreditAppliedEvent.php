<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a credit or discount is applied to an account.
 *
 * GA4: credit_applied (custom)
 * Meta: CreditApplied (custom)
 *
 * @since 1.0.0
 */
final readonly class CreditAppliedEvent extends AnalyticsEvent
{
    /**
     * @param  float|null  $amount  Credit amount
     * @param  string|null  $currency  ISO 4217 currency code
     * @param  string|null  $reason  Reason for credit ('referral', 'support', 'promotion', 'overpayment')
     * @param  string|null  $source  Credit source code
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        ?float $amount = null,
        ?string $currency = null,
        ?string $reason = null,
        ?string $source = null,
        array $metadata = [],
    ): void {
        parent::__construct('credit_applied', array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
            'source' => $source,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
