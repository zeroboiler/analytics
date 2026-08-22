<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks webhook delivery outcomes.
 *
 * Monitors outgoing webhook reliability for integrations. Combined with
 * WebhookEventSubscriptionService for delivery confirmation and retry analytics.
 * Essential for monitoring integration health and SLA compliance.
 *
 * GA4: webhook_delivered (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class WebhookDeliveredEvent extends AnalyticsEvent
{
    /**
     * @param  string  $webhookUrl  Destination URL (sanitized)
     * @param  string  $status  Delivery status: 'success', 'failed', 'timeout', 'retrying'
     * @param  int|null  $statusCode  HTTP response status code
     * @param  string|null  $eventType  The event type that triggered the webhook
     * @param  int|null  $responseTimeMs  Response time in milliseconds
     * @param  int|null  $attemptNumber  Which delivery attempt (1 = first)
     */
    public function __construct(
        string $webhookUrl,
        string $status,
        ?int $statusCode = null,
        ?string $eventType = null,
        ?int $responseTimeMs = null,
        ?int $attemptNumber = null,
    ){
        parent::__construct('webhook_delivered', array_filter([
            'webhook_url' => $this->sanitizeUrl($webhookUrl),
            'status' => $status,
            'status_code' => $statusCode,
            'event_type' => $eventType,
            'response_time_ms' => $responseTimeMs,
            'attempt_number' => $attemptNumber,
        ], fn (mixed $v): bool => $v !== null));
    }

    /**
     * Sanitize webhook URL to prevent logging sensitive credentials.
     */
    private function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return '[invalid_url]';
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $path = $parsed['path'] ?? '/';

        return "{$scheme}://{$host}{$port}{$path}";
    }
}
