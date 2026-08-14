<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Activation event — tracks the "aha moment" when a user first experiences core value.
 *
 * Fired when a user completes their first key action that indicates product
 * adoption (e.g., first API call, first project created, first team invited).
 * Used by SaaSActivationService and growth metrics for activation rate computation.
 *
 * @since 93.0.0
 */
final class ActivationEvent extends AnalyticsEvent
{
    /**
     * Create a new activation event.
     *
     * @param  string  $action  The activating action name (e.g., 'first_project_created', 'first_api_call')
     * @param  int|null  $timeToActivate  Seconds from signup to activation (null if unknown)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $action,
        ?int $timeToActivate = null,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ) {
        parent::__construct(
            name: 'activation',
            params: array_merge($params, [
                'action' => $action,
                'time_to_activate' => $timeToActivate,
            ]),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
