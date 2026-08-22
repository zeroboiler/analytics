<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user requests a password reset.
 *
 * Tracks the password reset flow as a security and account health signal.
 * Combined with password_changed, provides full password reset funnel visibility.
 *
 * @since 131.0.0
 */
final readonly class PasswordResetRequestedEvent extends AnalyticsEvent
{
    public function __construct(
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'password_reset_requested',
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }
}
