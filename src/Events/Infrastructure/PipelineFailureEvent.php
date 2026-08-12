<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a queue or pipeline processing failure occurs.
 *
 * Tracks analytics pipeline reliability for self-monitoring.
 * Distinguishes between transient failures and persistent outages.
 *
 * @since 45.0.0
 */
final class PipelineFailureEvent extends AnalyticsEvent
{
    /**
     * @param  string  $stage  Pipeline stage that failed (ingestion, enrichment, dispatch, store)
     * @param  string|null  $provider  Affected analytics provider (ga4, meta, posthog, etc.)
     * @param  string|null  $errorType  Error classification (timeout, auth, rate_limit, validation)
     * @param  string|null  $errorMessage  Error message (truncated if long)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $stage,
        ?string $provider = null,
        ?string $errorType = null,
        ?string $errorMessage = null,
        array $params = [],
    ): void {
        parent::__construct('pipeline_failure', array_merge($params, array_filter([
            'stage' => $stage,
            'provider' => $provider,
            'error_type' => $errorType,
            'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
