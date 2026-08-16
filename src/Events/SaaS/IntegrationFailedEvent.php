<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a third-party integration connection fails.
 *
 * Captures error details for debugging, monitoring integration health,
 * and alerting on degraded external services.
 *
 * GA4: integration_failed (custom)
 * Meta: IntegrationFailed (custom)
 *
 * @since 1.0.0
 */
final readonly class IntegrationFailedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $integrationName  Integration identifier (e.g. 'stripe', 'slack', 'github')
     * @param  string  $errorType  Error classification ('timeout', 'auth', 'validation', 'network', 'unknown')
     * @param  string  $errorMessage  Human-readable error description
     * @param  bool  $isRetryable  Whether the failure is transient and retryable
     * @param  array<string, mixed>  $metadata  Additional context (request ID, endpoint, etc.)
     */
    public function __construct(
        string $integrationName,
        string $errorType,
        string $errorMessage,
        bool $isRetryable = false,
        array $metadata = [],
    ): void {
        parent::__construct('integration_failed', [
            'integration_name' => $integrationName,
            'error_type' => $errorType,
            'error_message' => mb_substr($errorMessage, 0, 500),
            'is_retryable' => $isRetryable,
            ...$metadata,
        ]);
    }
}
