<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an SLO breach is detected.
 *
 * Tracks Service Level Objective violations for reliability governance.
 * Correlates with deployment events, traffic spikes, and infrastructure changes.
 *
 * @since 46.0.0
 */
final readonly class SLOBreachEvent extends AnalyticsEvent
{
    /**
     * @param  string  $sloName  SLO identifier
     * @param  float  $currentValue  Current SLO value (e.g. 99.2)
     * @param  float  $target  SLO target (e.g. 99.9)
     * @param  string|null  $sliName  Underlying SLI name (availability, latency_p99)
     * @param  string|null  $severity  Breach severity (minor, major, critical)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $sloName,
        float $currentValue,
        float $target,
        ?string $sliName = null,
        ?string $severity = null,
        array $params = [],
    ){
        parent::__construct('slo_breach', array_merge($params, array_filter([
            'slo_name' => $sloName,
            'current_value' => $currentValue,
            'target' => $target,
            'sli_name' => $sliName,
            'severity' => $severity,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
