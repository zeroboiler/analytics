<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks team/workspace creation.
 *
 * GA4: team_created (custom)
 * Meta: TeamCreated (custom)
 *
 * @since 1.0.0
 */
final readonly class TeamCreatedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $teamName  Team/workspace name
     * @param  int|null  $memberCount  Initial member count
     * @param  string|null  $plan  Subscription plan for the team
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $teamName = null, ?int $memberCount = null, ?string $plan = null, array $metadata = []): void
    {
        parent::__construct('team_created', array_filter([
            'team_name' => $teamName,
            'member_count' => $memberCount,
            'plan' => $plan,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null));
    }
}
