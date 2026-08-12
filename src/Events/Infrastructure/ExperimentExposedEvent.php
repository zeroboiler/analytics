<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user is exposed to an experiment variation.
 *
 * Tracks A/B test and experiment exposures for statistical analysis.
 * Used to correlate experiment participation with downstream conversion events.
 *
 * @since 45.0.0
 */
final class ExperimentExposedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $experimentId  Experiment identifier
     * @param  string  $variation  Assigned variation (control, treatment_a, etc.)
     * @param  string|null  $source  Experiment source (launchdarkly, optimizely, internal)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $experimentId,
        string $variation,
        ?string $source = null,
        array $params = [],
    ): void {
        parent::__construct('experiment_exposed', array_merge($params, array_filter([
            'experiment_id' => $experimentId,
            'variation' => $variation,
            'source' => $source,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
