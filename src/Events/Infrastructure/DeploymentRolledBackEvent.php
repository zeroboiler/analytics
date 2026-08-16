<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a deployment is rolled back.
 *
 * Tracks rollback events for reliability analysis and deployment pipeline monitoring.
 * Correlates with error spikes and incident events.
 *
 * @since 46.0.0
 */
final class DeploymentRolledBackEvent extends AnalyticsEvent
{
    /**
     * @param  string  $version  Version being rolled back from
     * @param  string  $rollbackTo  Version rolling back to
     * @param  string|null  $reason  Rollback reason (errors, performance, manual)
     * @param  string|null  $environment  Environment (production, staging)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $version,
        string $rollbackTo,
        ?string $reason = null,
        ?string $environment = null,
        array $params = [],
    ): void {
        parent::__construct('deployment_rolled_back', array_merge($params, array_filter([
            'version' => $version,
            'rollback_to' => $rollbackTo,
            'reason' => $reason,
            'environment' => $environment,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
