<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a login attempt is made (successful or failed).
 *
 * Tracks authentication attempts including method, IP, and outcome
 * for security monitoring and fraud detection.
 *
 * @since 9.9.0
 */
final class LoginAttemptEvent extends AnalyticsEvent
{
    /**
     * @param  string  $method  Auth method used (password, oauth, sso, mfa)
     * @param  bool  $successful  Whether the attempt succeeded
     * @param  string|null  $reason  Failure reason (invalid_credentials, mfa_required, account_locked, etc.)
     * @param  array<string, mixed>  $metadata  Additional context (ip, user_agent, provider)
     */
    public function __construct(
        string $method = 'password',
        bool $successful = true,
        ?string $reason = null,
        array $metadata = [],
    ) {
        parent::__construct('login_attempt', array_filter([
            'method' => $method,
            'successful' => $successful,
            'reason' => $reason,
            ...$metadata,
        ]));
    }
}
