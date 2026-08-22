<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user login.
 *
 * GA4: login
 * Meta: (custom event)
 *
 * @since 1.0.0
 */
final readonly class LoginEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  Login method (e.g. 'email', 'google', 'github')
     */
    public function __construct(?string $method = null){
        parent::__construct('login', array_filter([
            'method' => $method,
        ]));
    }
}
