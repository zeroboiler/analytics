<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS onboarding step event for tracking product activation funnels.
 *
 * Tracks individual steps in a multi-step onboarding flow (profile setup,
 * team creation, integration connect, first action). Use step_index for
 * ordering and completed to detect abandonment.
 *
 * @since 1.0.0
 */
final readonly class OnboardingStepEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $stepName  Human-readable step name (e.g. "profile_setup")
     * @param  int  $stepIndex  Zero-based step order in the funnel
     * @param  int  $totalSteps  Total number of onboarding steps
     * @param  string|null  $method  Entry method (invite, organic, paid)
     * @param  bool|null  $completed  Whether this step was completed
     * @param  int|null  $durationSeconds  Time to complete this step
     * @param  string|null  $skippedReason  Reason for skipping (if applicable)
     */
    public function __construct(
        string $stepName,
        int $stepIndex,
        int $totalSteps,
        ?string $method = null,
        ?bool $completed = null,
        ?int $durationSeconds = null,
        ?string $skippedReason = null,
    ): void {
        parent::__construct(
            name: 'onboarding_step',
            params: array_filter([
                'step_name' => $stepName,
                'step_index' => $stepIndex,
                'total_steps' => $totalSteps,
                'method' => $method,
                'completed' => $completed,
                'duration_seconds' => $durationSeconds,
                'skipped_reason' => $skippedReason,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
