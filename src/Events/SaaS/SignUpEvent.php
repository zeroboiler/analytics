<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user sign-up / registration.
 *
 * GA4: sign_up
 * Meta: CompleteRegistration
 *
 * @since 1.0.0
 */
final readonly class SignUpEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  Registration method (e.g. 'email', 'google', 'github')
     */
    public function __construct(?string $method = null): void
    {
        parent::__construct('sign_up', array_filter([
            'method' => $method,
        ]));
    }
}
