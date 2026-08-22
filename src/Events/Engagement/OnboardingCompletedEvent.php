<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user completes the full onboarding flow.
 *
 * Tracks the total duration from signup to onboarding completion,
 * the steps completed, and any skipped steps. Provides a clear
 * signal that the user has reached the "activation" milestone.
 *
 * @since 9.7.0
 */
final readonly class OnboardingCompletedEvent extends AnalyticsEvent
{
    /**
     * @param  int|null  $stepsCompleted  Number of onboarding steps completed
     * @param  int|null  $stepsTotal  Total number of onboarding steps
     * @param  int|null  $durationSeconds  Time from sign_up to completion in seconds
     * @param  string|null  $signupMethod  How the user signed up (email, google, github)
     * @param  list<string>|null  $skippedSteps  Names of steps that were skipped
     */
    public function __construct(
        ?int $stepsCompleted = null,
        ?int $stepsTotal = null,
        ?int $durationSeconds = null,
        ?string $signupMethod = null,
        ?array $skippedSteps = null,
    ){
        parent::__construct(
            name: 'onboarding_completed',
            params: array_filter([
                'steps_completed' => $stepsCompleted,
                'steps_total' => $stepsTotal,
                'duration_seconds' => $durationSeconds,
                'signup_method' => $signupMethod,
                'skipped_steps' => $skippedSteps,
                'completion_percentage' => ($stepsCompleted !== null && $stepsTotal !== null && $stepsTotal > 0)
                    ? round(($stepsCompleted / $stepsTotal) * 100, 1)
                    : null,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
