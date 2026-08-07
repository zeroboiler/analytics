<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks account activation (email verification, admin approval).
 *
 * GA4: account_activated (custom)
 * Meta: AccountActivated (custom)
 */
final readonly class AccountActivatedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  Activation method ('email', 'admin', 'sso', 'auto')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $method = null, array $metadata = []): void
: void {
        parent::__construct('account_activated', array_filter([
            'method' => $method,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
