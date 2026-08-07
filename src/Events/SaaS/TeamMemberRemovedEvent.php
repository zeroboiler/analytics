<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a member is removed from a team/workspace.
 *
 * GA4: team_member_removed (custom)
 * Meta: TeamMemberRemoved (custom)
 */
final readonly class TeamMemberRemovedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $role  Role at time of removal
     * @param  string|null  $reason  Removal reason ('voluntary', 'kicked', 'inactive')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $role = null, ?string $reason = null, array $metadata = []): void
    {
        parent::__construct('team_member_removed', array_filter([
            'role' => $role,
            'reason' => $reason,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
