<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a team invitation is accepted by a user.
 *
 * Tracks B2B team growth signals and invitation conversion rates.
 * Used alongside team_member_joined for complete invitation funnel analysis.
 *
 * @since 131.0.0
 */
final class InviteAcceptedEvent extends AnalyticsEvent
{
    public function __construct(
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ) {
        parent::__construct(
            name: 'invite_accepted',
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }
}
