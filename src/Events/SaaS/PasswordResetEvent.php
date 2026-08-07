<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a password reset request.
 *
 * GA4: password_reset (custom)
 * Meta: PasswordReset (custom)
 */
final readonly class PasswordResetEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  Reset method ('email', 'sms', 'admin')
     * @param  bool|null  $success  Whether the reset was successful
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $method = null, ?bool $success = null, array $metadata = []): void
: void {
        parent::__construct('password_reset', array_filter([
            'method' => $method,
            'success' => $success,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
