<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a support ticket is created.
 *
 * Tracks ticket creation as a customer health signal. High ticket frequency
 * may indicate product issues or low adoption maturity. Used in health scoring
 * and churn prediction models.
 *
 * @since 135.0.0
 */
final class SupportTicketCreatedEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'support_ticket_created',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $timestamp = null,
    ): void {
        parent::__construct(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: $timestamp,
        );
    }
}
