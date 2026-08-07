<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user sends an invitation (team member, collaborator, referral).
 *
 * GA4: invite_sent (custom)
 * Meta: InviteSent (custom)
 *
 * Use this to track team growth, collaboration activation, and viral loops.
 */
final readonly class InviteSentEvent extends AnalyticsEvent
{
    /**
     * @param  string  $inviteType  Type of invitation (e.g. 'team_member', 'collaborator', 'referral', 'billing_contact')
     * @param  string|null  $role  Assigned role for the invitee (e.g. 'admin', 'editor', 'viewer')
     * @param  string|null  $userId  Inviter user ID
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        string $inviteType,
        ?string $role = null,
        ?string $userId = null,
        array $extra = [],
    ): void {
        $baseParams = array_filter([
            'invite_type' => $inviteType,
            'role' => $role,
            'user_id' => $userId,
        ]);

        parent::__construct('invite_sent', array_merge($baseParams, $extra));
    }
}
