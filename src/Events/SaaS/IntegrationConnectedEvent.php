<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user connects an external integration (e.g. Slack, GitHub, Stripe).
 *
 * GA4: integration_connected (custom)
 * Meta: IntegrationConnected (custom)
 *
 * Use this to track ecosystem engagement and feature activation funnels.
 *
 * @since 1.0.0
 */
final readonly class IntegrationConnectedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $integrationName  Name of the integration (e.g. 'slack', 'github', 'stripe', 'zapier')
     * @param  string|null  $userId  User who connected the integration
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        string $integrationName,
        ?string $userId = null,
        array $extra = [],
    ){
        $baseParams = array_filter([
            'integration_name' => $integrationName,
            'user_id' => $userId,
        ]);

        parent::__construct('integration_connected', array_merge($baseParams, $extra));
    }
}
