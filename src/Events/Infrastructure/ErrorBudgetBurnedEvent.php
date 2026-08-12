<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an error budget is burned (consumed).
 *
 * Tracks SRE error budget consumption for reliability monitoring.
 * Supports burn rate alerts and SLO compliance tracking.
 *
 * @since 46.0.0
 */
final class ErrorBudgetBurnedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $sloName  SLO identifier
     * @param  float  $burnRate  Current burn rate (e.g. 2.5 means 2.5x normal consumption)
     * @param  float  $remaining  Remaining error budget percentage (0-100)
     * @param  string|null  $window  Measurement window (1h, 6h, 1d, 3d, 30d)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $sloName,
        float $burnRate,
        float $remaining,
        ?string $window = null,
        array $params = [],
    ): void {
        parent::__construct('error_budget_burned', array_merge($params, array_filter([
            'slo_name' => $sloName,
            'burn_rate' => $burnRate,
            'remaining' => $remaining,
            'window' => $window,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
