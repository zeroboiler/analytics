<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks account deactivation/suspension.
 *
 * GA4: account_deactivated (custom)
 * Meta: AccountDeactivated (custom)
 *
 * @since 1.0.0
 */
final readonly class AccountDeactivatedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $reason  Deactivation reason ('user_request', 'admin', 'inactivity', 'tos_violation')
     * @param  bool|null  $permanent  Whether this is a permanent deletion
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $reason = null, ?bool $permanent = null, array $metadata = []){
        parent::__construct('account_deactivated', array_filter([
            'reason' => $reason,
            'permanent' => $permanent,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
