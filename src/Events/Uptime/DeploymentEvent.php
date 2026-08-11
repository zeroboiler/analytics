<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a deployment occurs.
 *
 * Tracks application deployments for change-event correlation.
 * Enables correlating deployment events with error spikes, latency changes,
 * and user behavior shifts.
 *
 * @since 9.9.0
 */
final class DeploymentEvent extends AnalyticsEvent
{
    /**
     * @param  string  $environment  Deployment environment (production, staging, etc.)
     * @param  string  $version  Deployed version (git SHA, tag, or version number)
     * @param  string  $strategy  Deployment strategy (rolling, blue_green, canary)
     * @param  string|null  $service  Service being deployed (api, worker, frontend)
     */
    public function __construct(
        string $environment = 'production',
        string $version = '',
        string $strategy = 'rolling',
        ?string $service = null,
    ) {
        parent::__construct('deployment', array_filter([
            'environment' => $environment,
            'version' => $version,
            'strategy' => $strategy,
            'service' => $service,
        ]));
    }
}
