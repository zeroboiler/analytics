<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an MFA challenge is initiated or completed.
 *
 * Tracks multi-factor authentication events for security monitoring.
 * Covers challenge initiation, successful completion, and failure.
 *
 * @since 9.9.0
 */
final readonly class MfaChallengeEvent extends AnalyticsEvent
{
    /**
     * @param  string  $method  MFA method (totp, sms, email, hardware_key, backup_codes)
     * @param  string  $outcome  Challenge outcome (initiated, completed, failed, bypassed)
     * @param  string|null  $reason  Additional context (code_expired, device_not_found, etc.)
     */
    public function __construct(
        string $method = 'totp',
        string $outcome = 'initiated',
        ?string $reason = null,
    ){
        parent::__construct('mfa_challenge', array_filter([
            'method' => $method,
            'outcome' => $outcome,
            'reason' => $reason,
        ]));
    }
}
