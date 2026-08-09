<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Workspace/organization creation event for multi-tenant SaaS.
 *
 * Tracks when a user creates a new workspace or organization.
 * Differentiates from team_created (which is adding to existing workspace).
 * Important for measuring product-led growth and account expansion.
 *
 * @since 1.0.0
 */
final readonly class WorkspaceCreatedEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $workspaceName  Name of the created workspace
     * @param  string|null  $plan  Selected plan tier
     * @param  string|null  $industry  Industry vertical (from onboarding)
     * @param  string|null  $size  Company/team size bucket
     */
    public function __construct(
        string $workspaceName,
        ?string $plan = null,
        ?string $industry = null,
        ?string $size = null,
    ): void {
        parent::__construct(
            name: 'workspace_created',
            params: array_filter([
                'workspace_name' => $workspaceName,
                'plan' => $plan,
                'industry' => $industry,
                'size' => $size,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
