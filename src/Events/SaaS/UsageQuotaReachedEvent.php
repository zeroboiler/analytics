<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a user reaches a usage quota or limit.
 *
 * Key expansion signal for SaaS products — tracks which features hit limits,
 * how close users are to their cap, and the plan tier. Used for upsell analytics,
 * feature gating dashboards, and churn prediction models.
 *
 * @phpstan-import-type EventParams from AnalyticsEvent
 *
 * @since 1.0.0
 */
final readonly class UsageQuotaReachedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $feature  The feature or resource that hit the limit (e.g. 'api_calls', 'storage_gb', 'team_members')
     * @param  string  $plan  The user's current plan (e.g. 'Starter', 'Pro')
     * @param  int  $currentUsage  Current usage amount (e.g. 10000)
     * @param  int  $limit  The quota limit (e.g. 10000)
     * @param  string|null  $unit  Usage unit (e.g. 'requests', 'gb', 'seats')
     * @param  float|null  $usagePercentage  Percentage of limit used (0-100)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function __construct(
        string $feature,
        string $plan,
        int $currentUsage,
        int $limit,
        ?string $unit = null,
        ?float $usagePercentage = null,
        ?string $userId = null,
        ?string $clientId = null,
    ){
        parent::__construct(
            name: 'usage_quota_reached',
            params: array_filter([
                'feature' => $feature,
                'plan' => $plan,
                'current_usage' => $currentUsage,
                'limit' => $limit,
                'unit' => $unit,
                'usage_percentage' => $usagePercentage ?? ($limit > 0 ? round(($currentUsage / $limit) * 100, 1) : null),
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
