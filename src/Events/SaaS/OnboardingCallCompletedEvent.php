<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when an onboarding call / kickoff meeting is completed.
 *
 * Tracks high-touch onboarding completion as a proxy for activation
 * quality. Customers who complete onboarding calls show higher
 * retention and expansion rates.
 *
 * @since 135.0.0
 */
final class OnboardingCallCompletedEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters. Expected: duration_minutes (int), outcome (string), cs_rep (string|null)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'onboarding_call_completed',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $timestamp = null,
    ) {
        parent::__construct(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: $timestamp,
        );
    }
}
