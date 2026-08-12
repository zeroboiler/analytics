<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a scheduled maintenance window starts.
 *
 * Tracks maintenance periods for availability calculation and user impact analysis.
 * Used to exclude maintenance from SLO compliance penalties.
 *
 * @since 46.0.0
 */
final class MaintenanceStartedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $maintenanceId  Maintenance window identifier
     * @param  string|null  $affectedService  Service(s) under maintenance
     * @param  string|null  $reason  Maintenance reason (upgrade, patch, migration)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $maintenanceId,
        ?string $affectedService = null,
        ?string $reason = null,
        array $params = [],
    ): void {
        parent::__construct('maintenance_started', array_merge($params, array_filter([
            'maintenance_id' => $maintenanceId,
            'affected_service' => $affectedService,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
