<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a password change event.
 *
 * GA4: password_changed (custom)
 * Meta: PasswordChanged (custom)
 */
final readonly class PasswordChangedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  How the password was changed ('settings', 'reset', 'admin')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $method = null, array $metadata = []): void
: void {
        parent::__construct('password_changed', array_filter([
            'method' => $method,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
