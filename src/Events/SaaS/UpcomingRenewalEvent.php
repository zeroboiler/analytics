<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event dispatched when a subscription renewal is approaching.
 *
 * Used for churn prediction, renewal outreach campaigns, and revenue forecasting.
 * Typically dispatched 7/14/30 days before renewal date by a scheduled command.
 *
 * @since 22.0.0
 */
final readonly class UpcomingRenewalEvent extends AnalyticsEvent
{
    /**
     * Create an upcoming renewal event.
     *
     * @param  string  $planName  Current plan name
     * @param  float  $amount  Renewal amount
     * @param  int  $daysUntilRenewal  Days until renewal date
     * @param  array<string, mixed>  $params  Additional parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        public string $planName = '',
        public float $amount = 0.0,
        public int $daysUntilRenewal = 0,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'upcoming_renewal',
            params: array_merge($params, [
                'plan_name' => $planName,
                'amount' => $amount,
                'currency' => $params['currency'] ?? 'USD',
                'days_until_renewal' => $daysUntilRenewal,
            ]),
            clientId: $clientId,
            userId: $userId,
            priority: 'normal',
            source: 'server',
        );
    }
}
