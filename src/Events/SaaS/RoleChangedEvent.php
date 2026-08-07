<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a team member role change.
 *
 * GA4: role_changed (custom)
 * Meta: RoleChanged (custom)
 */
final readonly class RoleChangedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $fromRole  Previous role
     * @param  string|null  $toRole  New role
     * @param  string|null  $changedBy  Who made the change ('self', 'admin', 'system')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $fromRole = null, ?string $toRole = null, ?string $changedBy = null, array $metadata = []): void
: void {
        parent::__construct('role_changed', array_filter([
            'from_role' => $fromRole,
            'to_role' => $toRole,
            'changed_by' => $changedBy,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
