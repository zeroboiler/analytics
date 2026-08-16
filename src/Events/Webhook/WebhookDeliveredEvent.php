<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Webhook;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an outbound webhook is delivered.
 *
 * @since 182.0.0
 */
final class WebhookDeliveredEvent extends AnalyticsEvent
{
    public function __construct(
        string $webhookId,
        string $url,
        int $statusCode,
        int $responseTimeMs,
        ?string $payloadHash = null,
    ) {
        parent::__construct('webhook_delivered', array_filter([
            'webhook_id' => $webhookId,
            'url' => $url,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'payload_hash' => $payloadHash,
        ]));
    }
}
