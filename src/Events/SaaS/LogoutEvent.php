<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user logout.
 *
 * GA4: (custom event)
 * Meta: (custom event)
 *
 * @since 1.0.0
 */
final readonly class LogoutEvent extends AnalyticsEvent
{
    public function __construct(){
        parent::__construct('logout');
    }
}
