<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a member joins a team/workspace.
 *
 * GA4: team_member_joined (custom)
 * Meta: TeamMemberJoined (custom)
 *
 * @since 1.0.0
 */
final readonly class TeamMemberJoinedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $role  Assigned role ('owner', 'admin', 'member', 'viewer')
     * @param  string|null  $inviteMethod  How the member was added ('invite', 'link', 'sso', 'admin')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $role = null, ?string $inviteMethod = null, array $metadata = []): void
    {
        parent::__construct('team_member_joined', array_filter([
            'role' => $role,
            'invite_method' => $inviteMethod,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
