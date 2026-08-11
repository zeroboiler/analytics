<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a monitored service goes down.
 *
 * Tracks service outages for uptime monitoring and SLA compliance.
 * Includes service name, error details, and impact assessment.
 *
 * @since 9.9.0
 */
final class ServiceDownEvent extends AnalyticsEvent
{
    /**
     * @param  string  $service  Service name (api, database, cache, queue, email, storage)
     * @param  string|null  $error  Error message or classification
     * @param  string  $impact  Impact level (partial, full, degraded)
     * @param  array<string, mixed>  $context  Additional context (region, host, error_code)
     */
    public function __construct(
        string $service = 'api',
        ?string $error = null,
        string $impact = 'partial',
        array $context = [],
    ): void {
        parent::__construct('service_down', array_filter([
            'service' => $service,
            'error' => $error,
            'impact' => $impact,
            ...$context,
        ]));
    }
}
