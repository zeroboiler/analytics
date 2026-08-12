<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user actively uses an integration.
 *
 * Measures integration engagement beyond initial connection.
 * Complements IntegrationConnectedEvent by tracking ongoing usage
 * patterns for each third-party integration (Slack, Stripe, GitHub, etc.).
 *
 * GA4: integration_used (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class IntegrationUsedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $integrationName  Name of the integration (slack, stripe, github, etc.)
     * @param  string|null  $action  Specific action performed (send_message, sync_data, etc.)
     * @param  string|null  $result  Outcome: 'success', 'error', 'rate_limited'
     * @param  int|null  $responseTimeMs  Integration API response time
     * @param  string|null  $userId  Authenticated user ID (if available)
     */
    public function __construct(
        string $integrationName,
        ?string $action = null,
        ?string $result = null,
        ?int $responseTimeMs = null,
        ?string $userId = null,
    ): void {
        parent::__construct('integration_used', array_filter([
            'integration_name' => $integrationName,
            'action' => $action,
            'result' => $result,
            'response_time_ms' => $responseTimeMs,
            'user_id' => $userId,
        ], fn (mixed $v): bool => $v !== null));
    }
}
