<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an incident is declared/opened.
 *
 * Tracks production incidents for reliability engineering and post-mortem analysis.
 * Enables MTTR, MTBF, and incident frequency tracking.
 *
 * @since 46.0.0
 */
final readonly class IncidentStartedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $incidentId  Incident identifier
     * @param  string  $severity  Incident severity (P1-P4, sev1-sev4)
     * @param  string|null  $title  Incident title/summary
     * @param  string|null  $affectedService  Primary affected service
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $incidentId,
        string $severity,
        ?string $title = null,
        ?string $affectedService = null,
        array $params = [],
    ){
        parent::__construct('incident_started', array_merge($params, array_filter([
            'incident_id' => $incidentId,
            'severity' => $severity,
            'title' => $title,
            'affected_service' => $affectedService,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
