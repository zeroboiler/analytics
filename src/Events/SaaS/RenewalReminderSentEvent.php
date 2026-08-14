<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a renewal reminder is sent to a customer.
 *
 * Tracks outbound renewal communications as part of the retention
 * workflow. Used to measure renewal campaign effectiveness and
 * time-to-renewal conversion rates.
 *
 * @since 135.0.0
 */
final class RenewalReminderSentEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters. Expected: channel (string), days_until_renewal (int)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'renewal_reminder_sent',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $timestamp = null,
    ) {
        parent::__construct(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: $timestamp,
        );
    }
}
