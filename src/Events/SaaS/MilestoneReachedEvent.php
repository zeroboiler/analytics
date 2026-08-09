<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * Fired when a user reaches a significant product milestone.
 *
 * Examples: first project created, 100th login, 1-year anniversary,
 * team size reached 10, API calls exceeded threshold.
 * Used for activation analysis and engagement scoring.
 */
final class MilestoneReachedEvent extends \ZeroBoiler\Analytics\DTO\AnalyticsEvent
{
    /**
     * @param  string  $milestone  Milestone identifier (e.g. 'first_project', 'login_100', 'year_anniversary')
     * @param  string|null  $category  Milestone category (e.g. 'activation', 'engagement', 'retention', 'growth')
     * @param  int|null  $value  Numeric value associated with the milestone
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $milestone,
        ?string $category = null,
        ?int $value = null,
        ?string $userId = null,
        ?string $clientId = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'milestone_reached',
            params: array_filter([
                'milestone' => $milestone,
                'milestone_category' => $category,
                'milestone_value' => $value,
                ...$params,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
