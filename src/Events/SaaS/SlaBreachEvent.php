<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SLA breach event for enterprise SaaS monitoring.
 *
 * Tracks when service level agreement thresholds are violated:
 * uptime, response time, resolution time. Critical for B2B SaaS
 * compliance dashboards and customer success analytics.
 *
 * @phpstan-type SlaBreachParams array{
 *     sla_type: string,
 *     threshold: float,
 *     actual: float,
 *     unit: string,
 *     severity: string,
 *     customer_id?: string|null,
 *     ...array<string, mixed>
 * }
 *
 * @since 1.0.0
 */
final readonly class SlaBreachEvent extends AnalyticsEvent
{
    /**
     * @param  string  $slaType  Type of SLA breached (uptime, response_time, resolution_time)
     * @param  float  $threshold  SLA threshold value
     * @param  float  $actual  Actual value that breached the threshold
     * @param  string  $unit  Unit of measurement (%, ms, hours)
     * @param  string|null  $severity  Severity level (minor, major, critical)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $slaType,
        float $threshold,
        float $actual,
        string $unit = '%',
        ?string $severity = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'sla_breach',
            params: array_merge([
                'sla_type' => $slaType,
                'threshold' => $threshold,
                'actual' => $actual,
                'unit' => $unit,
                'severity' => $severity ?? 'minor',
            ], $params),
        );
    }
}
