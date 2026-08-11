<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a monitored service comes back online.
 *
 * Tracks service recovery for uptime monitoring and SLA compliance.
 * Includes service name, duration of downtime, and recovery context.
 *
 * @since 9.9.0
 */
final class ServiceUpEvent extends AnalyticsEvent
{
    /**
     * @param  string  $service  Service name (api, database, cache, queue, email, storage)
     * @param  float|null  $downtimeSeconds  Duration of the outage in seconds
     * @param  array<string, mixed>  $context  Additional context (region, host, previous_status)
     */
    public function __construct(
        string $service = 'api',
        ?float $downtimeSeconds = null,
        array $context = [],
    ): void {
        parent::__construct('service_up', array_filter([
            'service' => $service,
            'downtime_seconds' => $downtimeSeconds,
            ...$context,
        ]));
    }
}
