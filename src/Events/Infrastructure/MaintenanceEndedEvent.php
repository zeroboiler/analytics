<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a scheduled maintenance window ends.
 *
 * Tracks maintenance completion for availability SLA tracking.
 * Links to the corresponding maintenance_started event via maintenance_id.
 *
 * @since 46.0.0
 */
final class MaintenanceEndedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $maintenanceId  Maintenance window identifier (matches maintenance_started)
     * @param  string|null  $status  Maintenance outcome (completed, cancelled, extended)
     * @param  int|null  $durationMinutes  Maintenance duration in minutes
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $maintenanceId,
        ?string $status = null,
        ?int $durationMinutes = null,
        array $params = [],
    ){
        parent::__construct('maintenance_ended', array_merge($params, array_filter([
            'maintenance_id' => $maintenanceId,
            'status' => $status,
            'duration_minutes' => $durationMinutes,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
