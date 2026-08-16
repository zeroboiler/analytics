<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Webhook;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an outbound webhook delivery fails.
 *
 * @since 182.0.0
 */
final class WebhookFailedEvent extends AnalyticsEvent
{
    public function __construct(
        string $webhookId,
        string $url,
        string $errorType,
        string $errorMessage,
        int $attempt,
        ?string $nextRetryAt = null,
    ): void {
        parent::__construct('webhook_failed', array_filter([
            'webhook_id' => $webhookId,
            'url' => $url,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'attempt' => $attempt,
            'next_retry_at' => $nextRetryAt,
        ]));
    }
}
