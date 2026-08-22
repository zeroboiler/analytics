<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Webhook;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an inbound webhook is received.
 *
 * @since 182.0.0
 */
final readonly class WebhookReceivedEvent extends AnalyticsEvent
{
    public function __construct(
        string $source,
        string $event,
        ?string $payloadHash = null,
        ?string $ipAddress = null,
    ){
        parent::__construct('webhook_received', array_filter([
            'source' => $source,
            'event' => $event,
            'payload_hash' => $payloadHash,
            'ip_address' => $ipAddress,
        ]));
    }
}
