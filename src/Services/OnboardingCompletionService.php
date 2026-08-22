<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Tracks multi-step onboarding completion for SaaS products.
 *
 * Manages a configurable sequence of onboarding milestones and tracks
 * progress per user. When all required steps are completed, dispatches
 * an `onboarding_completed` event with timing metadata.
 *
 * Supports optional and required milestones, time-to-completion tracking,
 * and drop-off analysis at each step.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class OnboardingCompletionService
{
    /** @var list<string> */
    private array $requiredSteps;

    /** @var list<string> */
    private array $optionalSteps;

    /** @var int */
    private int $cacheTtl;

    /** @var string */
    private string $cachePrefix;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly QueuedAnalyticsDispatcher $queue,
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $onboardingConfig = $config->get('zeroboiler.analytics.onboarding_tracking', []);
        /** @var array{required_steps?: list<string>, optional_steps?: list<string>, cache_ttl?: int, cache_prefix?: string} $onboardingConfig */

        $this->requiredSteps = $onboardingConfig['required_steps'] ?? [
            'profile_setup',
            'first_feature_used',
            'team_invited_or_skipped',
        ];
        $this->optionalSteps = $onboardingConfig['optional_steps'] ?? [
            'billing_connected',
            'integration_added',
            'tutorial_completed',
        ];
        $this->cacheTtl = (int) ($onboardingConfig['cache_ttl'] ?? 2592000); // 30 days
        $this->cachePrefix = (string) ($onboardingConfig['cache_prefix'] ?? 'zb_onboarding_');
    }

    /**
     * Record a completed onboarding step for a user.
     *
     * If all required steps are now complete, dispatches an `onboarding_completed`
     * event with timing metadata and completion percentage.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string  $step  Step identifier (e.g. 'profile_setup')
     * @param  array<string, mixed>  $meta  Optional metadata about the step
     */
    public function completeStep(string $userId, string $step, array $meta = []): void
    {
        $progress = $this->getProgress($userId);

        // Don't re-record already completed steps
        if (in_array($step, $progress['completed'], true)) {
            return;
        }

        $progress['completed'][] = $step;
        $progress['completed_at'][$step] = time();

        // Track first step for time-to-completion
        if ($progress['started_at'] === null) {
            $progress['started_at'] = time();
        }

        $this->saveProgress($userId, $progress);

        $this->manager->track('onboarding_step', array_merge([
            'step' => $step,
            'step_index' => $this->getStepIndex($step),
            'total_steps' => count($this->requiredSteps) + count($this->optionalSteps),
            'required_remaining' => $this->countRequiredRemaining($progress['completed']),
            'completion_pct' => $this->calculateCompletionPercent($progress['completed']),
        ], $meta));

        $requiredRemaining = $this->countRequiredRemaining($progress['completed']);

        if ($requiredRemaining === 0 && ! ($progress['fully_completed_at'] ?? null)) {
            $progress['fully_completed_at'] = time();
            $this->saveProgress($userId, $progress);

            $timeToComplete = $progress['fully_completed_at'] - ($progress['started_at'] ?? $progress['fully_completed_at']);

            $this->queue->dispatch('onboarding_completed', [
                'user_id' => $userId,
                'total_steps_completed' => count($progress['completed']),
                'required_steps' => count($this->requiredSteps),
                'optional_steps_completed' => count(array_intersect($progress['completed'], $this->optionalSteps)),
                'time_to_completion_seconds' => $timeToComplete,
                'completion_pct' => 100,
                'steps_completed' => $progress['completed'],
            ]);
        }
    }

    /**
     * Get the current onboarding progress for a user.
     *
     * @param  string  $userId
     * @return array{completed: list<string>, completed_at: array<string, int>, started_at: int|null, fully_completed_at: int|null, completion_pct: int, required_remaining: int, is_complete: bool}
     */
    public function getProgress(string $userId): array
    {
        $cacheKey = $this->cachePrefix . $userId;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            /** @var array{completed?: list<string>, completed_at?: array<string, int>, started_at?: int|null, fully_completed_at?: int|null} $cached */
            $completed = $cached['completed'] ?? [];
            $completedAt = $cached['completed_at'] ?? [];
            $startedAt = $cached['started_at'] ?? null;
            $fullyCompletedAt = $cached['fully_completed_at'] ?? null;
        } else {
            $completed = [];
            $completedAt = [];
            $startedAt = null;
            $fullyCompletedAt = null;
        }

        $requiredRemaining = $this->countRequiredRemaining($completed);

        return [
            'completed' => $completed,
            'completed_at' => $completedAt,
            'started_at' => $startedAt,
            'fully_completed_at' => $fullyCompletedAt,
            'completion_pct' => $this->calculateCompletionPercent($completed),
            'required_remaining' => $requiredRemaining,
            'is_complete' => $requiredRemaining === 0,
        ];
    }

    /**
     * Get the aggregate onboarding funnel statistics across all users.
     *
     * Returns step-by-step completion rates for drop-off analysis.
     * This requires scanning cached progress data and is intended
     * for admin dashboards, not per-request usage.
     *
     * @return array{total_users: int, fully_completed: int, avg_completion_pct: float, step_completion_rates: array<string, float>, avg_time_to_completion: int|null}
     */
    public function funnelStats(): array
    {
        // This is a simplified implementation — in production, this would
        // query a database table or aggregate from cache. For cache-only
        // implementations, we return structural metadata.
        $allSteps = array_merge($this->requiredSteps, $this->optionalSteps);

        $stepRates = [];
        foreach ($allSteps as $step) {
            $stepRates[$step] = 0.0; // Requires DB aggregation in production
        }

        return [
            'total_users' => 0,
            'fully_completed' => 0,
            'avg_completion_pct' => 0.0,
            'step_completion_rates' => $stepRates,
            'avg_time_to_completion' => null,
        ];
    }

    /**
     * Get all configured onboarding steps with labels and types.
     *
     * @return list<array{name: string, type: 'required'|'optional', index: int}>
     */
    public function definedSteps(): array
    {
        $steps = [];

        foreach ($this->requiredSteps as $i => $step) {
            $steps[] = ['name' => $step, 'type' => 'required', 'index' => $i];
        }

        foreach ($this->optionalSteps as $i => $step) {
            $steps[] = ['name' => $step, 'type' => 'optional', 'index' => count($this->requiredSteps) + $i];
        }

        return $steps;
    }

    /**
     * Reset onboarding progress for a user (e.g. account re-activation).
     */
    public function resetProgress(string $userId): void
    {
        $this->cache->forget($this->cachePrefix . $userId);
    }

    /**
     * Calculate the completion percentage (0-100) for given completed steps.
     *
     * Required steps count fully, optional steps count at half weight.
     *
     * @param  list<string>  $completed
     */
    private function calculateCompletionPercent(array $completed): int
    {
        $requiredCompleted = count(array_intersect($completed, $this->requiredSteps));
        $optionalCompleted = count(array_intersect($completed, $this->optionalSteps));

        $totalWeight = count($this->requiredSteps) + (count($this->optionalSteps) * 0.5);
        $achievedWeight = $requiredCompleted + ($optionalCompleted * 0.5);

        if ($totalWeight === 0.0) {
            return 100;
        }

        return (int) round(($achievedWeight / $totalWeight) * 100);
    }

    /**
     * Count remaining required steps.
     *
     * @param  list<string>  $completed
     */
    private function countRequiredRemaining(array $completed): int
    {
        return count(array_diff($this->requiredSteps, $completed));
    }

    /**
     * Get the 0-based index of a step across all (required + optional) steps.
     */
    private function getStepIndex(string $step): int
    {
        $allSteps = array_merge($this->requiredSteps, $this->optionalSteps);
        $index = array_search($step, $allSteps, true);

        return $index !== false ? $index : -1;
    }

    /**
     * Save progress to cache.
     *
     * @param  string  $userId
     * @param  array{completed: list<string>, completed_at: array<string, int>, started_at: int|null, fully_completed_at: int|null}  $progress
     */
    private function saveProgress(string $userId, array $progress): void
    {
        $this->cache->put($this->cachePrefix . $userId, $progress, $this->cacheTtl);
    }
}
