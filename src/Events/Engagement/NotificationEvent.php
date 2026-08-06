<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Notification event — tracks when a notification is sent or delivered.
 *
 * Supports email, push, in-app, and SMS notification channels.
 * Useful for measuring notification engagement and deliverability.
 */
final class NotificationEvent extends AnalyticsEvent
{
    /**
     * @param  string  $channel  Notification channel (email, push, in_app, sms)
     * @param  string  $action  Action type (sent, delivered, opened, clicked, failed)
     * @param  string|null  $notificationType  Notification type/template (e.g. 'welcome', 'invoice_overdue')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $channel,
        string $action,
        ?string $notificationType = null,
        array $params = [],
    ) {
        parent::__construct(
            name: 'notification',
            params: array_filter([
                'notification_channel' => $channel,
                'notification_action' => $action,
                'notification_type' => $notificationType,
                ...$params,
            ]),
        );
    }
}
