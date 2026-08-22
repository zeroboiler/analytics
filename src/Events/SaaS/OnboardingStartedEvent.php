<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user begins the onboarding flow after registration.
 *
 * Tracks the entry point into product activation for funnel analysis.
 * Commonly used to measure time-to-first-value and onboarding completion rates.
 *
 * @since 131.0.0
 */
final class OnboardingStartedEvent extends AnalyticsEvent
{
    public function __construct(
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'onboarding_started',
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }
}
