<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an incident is resolved/closed.
 *
 * Tracks incident resolution for MTTR calculation and reliability analysis.
 * Links to the corresponding incident_started event via incident_id.
 *
 * @since 46.0.0
 */
final readonly class IncidentResolvedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $incidentId  Incident identifier (matches incident_started)
     * @param  int|null  $durationMinutes  Total incident duration in minutes
     * @param  string|null  $resolution  Resolution type (fixed, mitigated, false_positive)
     * @param  string|null  $rootCause  Root cause category (code, infra, third_party, config)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $incidentId,
        ?int $durationMinutes = null,
        ?string $resolution = null,
        ?string $rootCause = null,
        array $params = [],
    ){
        parent::__construct('incident_resolved', array_merge($params, array_filter([
            'incident_id' => $incidentId,
            'duration_minutes' => $durationMinutes,
            'resolution' => $resolution,
            'root_cause' => $rootCause,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
