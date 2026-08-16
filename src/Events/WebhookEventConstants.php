<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Webhook;

/**
 * Webhook analytics event name constants.
 *
 * @since 182.0.0
 */
final class WebhookEventConstants
{
    public const WEBHOOK_DELIVERED = 'webhook_delivered';
    public const WEBHOOK_FAILED = 'webhook_failed';
    public const WEBHOOK_RECEIVED = 'webhook_received';

    /**
     * Get all webhook event constants.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WEBHOOK_DELIVERED,
            self::WEBHOOK_FAILED,
            self::WEBHOOK_RECEIVED,
        ];
    }
}
