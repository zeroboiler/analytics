<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user removes a payment method from their account.
 *
 * Tracks payment method churn signals that may precede cancellation.
 * Used for billing health dashboards and revenue risk prediction.
 *
 * @since 131.0.0
 */
final readonly class PaymentMethodRemovedEvent extends AnalyticsEvent
{
    public function __construct(
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'payment_method_removed',
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }
}
