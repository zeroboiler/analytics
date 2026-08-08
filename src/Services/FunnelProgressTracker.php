<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Funnel progress tracker with cache-persisted state.
 *
 * Tracks user progression through multi-step funnels (signup, checkout,
 * onboarding, trial, activation) with completion percentage, step timing,
 * and automatic advancement detection.
 *
 * All state is stored in cache (per-user, per-funnel) with configurable TTL.
 * Use for SaaS onboarding tracking, checkout abandonment analysis, and
 * activation funnel optimization.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager::funnelProgress()
 *
 * @version 2.93.0
 */
final class FunnelProgressTracker
{
    /**
     * Cache key prefix for funnel progress data.
     */
    private const CACHE_PREFIX = 'zb_funnel_progress_';

    /**
     * Default funnel names used when config has no explicit list.
     */
    private const DEFAULT_FUNNELS = ['signup', 'checkout', 'onboarding', 'trial', 'activation'];

    /**
     * Known funnel names for getAllProgress().
     *
     * @var list<string>
     */
    private readonly array $knownFunnels;

    /**
     * @param  AnalyticsManager  $manager  Analytics manager for event dispatch
     * @param  CacheRepository  $cache  Cache store for progress persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ) {
        $funnelConfig = $config->get('zeroboiler.analytics.funnel_progress', []);
        /** @var array{known_funnels?: list<string>, default_ttl?: int} $funnelConfig */

        $this->knownFunnels = $funnelConfig['known_funnels'] ?? self::DEFAULT_FUNNELS;
    }

    /**
     * Get the configured default TTL in seconds.
     */
    private function getDefaultTtl(): int
    {
        return 86400; // 24 hours
    }

    /**
     * Track progress through a funnel step.
     *
     * Persists the user's current step, computes completion percentage,
     * tracks step-level timing, and dispatches a funnel_step event.
     * Automatically detects step advancement, regression, and completion.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'checkout', 'onboarding')
     * @param  string  $stepName  Current step identifier (e.g. 'form_submit', 'payment_info')
     * @param  string  $identity  User ID or client ID for tracking
     * @param  int  $stepNumber  Current step number (1-indexed)
     * @param  int  $totalSteps  Total steps in the funnel
     * @param  array<string, mixed>  $params  Additional event parameters
     * @return array{funnel_name: string, step_name: string, step_number: int, total_steps: int, completion_pct: float, is_complete: bool, is_advancement: bool, is_regression: bool, elapsed_seconds: float|null, previous_step: string|null, previous_step_number: int|null, first_seen: string|null, last_updated: string}
     */
    public function track(
        string $funnelName,
        string $stepName,
        string $identity,
        int $stepNumber,
        int $totalSteps,
        array $params = [],
    ): array {
        $cacheKey = $this->cacheKey($funnelName, $identity);
        $now = (string) now()->toIso8601String();
        $previous = $this->cache->get($cacheKey);

        $previousStepName = $previous['step_name'] ?? null;
        $previousStepNumber = $previous['step_number'] ?? null;
        $firstSeen = $previous['first_seen'] ?? $now;
        $stepStartedAt = $previous['step_started_at'] ?? null;
        $elapsedSeconds = null;

        if ($stepStartedAt !== null) {
            $started = strtotime((string) $stepStartedAt);
            $elapsedSeconds = $started !== false ? round((time() - $started), 2) : null;
        }

        $isAdvancement = $previousStepNumber !== null && $stepNumber > $previousStepNumber;
        $isRegression = $previousStepNumber !== null && $stepNumber < $previousStepNumber;
        $isComplete = $stepNumber >= $totalSteps;
        $completionPct = $totalSteps > 0 ? round(($stepNumber / $totalSteps) * 100, 1) : 0.0;

        // Persist progress
        $this->cache->put($cacheKey, [
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'completion_pct' => $completionPct,
            'first_seen' => $firstSeen,
            'last_updated' => $now,
            'step_started_at' => $now,
            'identity' => $identity,
        ], $this->getDefaultTtl());

        // Dispatch funnel step event
        $this->manager->track('funnel_step', array_filter([
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'completion_pct' => $completionPct,
            'is_complete' => $isComplete,
            'is_advancement' => $isAdvancement,
            'is_regression' => $isRegression,
            'elapsed_seconds' => $elapsedSeconds,
            'previous_step' => $previousStepName,
            'previous_step_number' => $previousStepNumber,
            'first_seen' => $firstSeen,
            'identity' => $identity,
        ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));

        // Auto-dispatch funnel completion event
        if ($isComplete && ! ($previous['completed'] ?? false)) {
            $this->cache->put($cacheKey . '_completed', true, $this->getDefaultTtl());
            $this->cache->put($cacheKey, array_merge(
                $this->cache->get($cacheKey, []),
                ['completed' => true, 'completed_at' => $now],
            ), $this->getDefaultTtl());

            $this->manager->track('funnel_completed', array_filter([
                'funnel_name' => $funnelName,
                'total_steps' => $totalSteps,
                'total_elapsed_seconds' => $this->totalElapsed($firstSeen, $now),
                'first_seen' => $firstSeen,
                'completed_at' => $now,
                'identity' => $identity,
            ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));
        }

        return [
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'completion_pct' => $completionPct,
            'is_complete' => $isComplete,
            'is_advancement' => $isAdvancement,
            'is_regression' => $isRegression,
            'elapsed_seconds' => $elapsedSeconds,
            'previous_step' => $previousStepName,
            'previous_step_number' => $previousStepNumber,
            'first_seen' => $firstSeen,
            'last_updated' => $now,
        ];
    }

    /**
     * Get the current funnel progress for a user.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $identity  User ID or client ID
     * @return array<string, mixed>|null Progress data or null if not started
     */
    public function getProgress(string $funnelName, string $identity): ?array
    {
        return $this->cache->get($this->cacheKey($funnelName, $identity));
    }

    /**
     * Check if a user has completed a specific funnel.
     */
    public function isCompleted(string $funnelName, string $identity): bool
    {
        return (bool) $this->cache->get($this->cacheKey($funnelName, $identity) . '_completed');
    }

    /**
     * Reset/clear funnel progress for a user.
     */
    public function reset(string $funnelName, string $identity): void
    {
        $this->cache->forget($this->cacheKey($funnelName, $identity));
        $this->cache->forget($this->cacheKey($funnelName, $identity) . '_completed');
    }

    /**
     * Get funnel progress summary across all known funnels for a user.
     *
     * Uses configured funnel names from `zeroboiler.analytics.funnel_progress.known_funnels`
     * or falls back to sensible defaults (signup, checkout, onboarding, trial, activation).
     *
     * @param  string  $identity  User ID or client ID
     * @return array<string, array{step_name: string|null, step_number: int|null, total_steps: int|null, completion_pct: float, is_complete: bool, first_seen: string|null}>
     */
    public function getAllProgress(string $identity): array
    {
        $results = [];

        foreach ($this->knownFunnels as $funnelName) {
            $progress = $this->getProgress($funnelName, $identity);
            if ($progress !== null) {
                $results[$funnelName] = [
                    'step_name' => $progress['step_name'] ?? null,
                    'step_number' => $progress['step_number'] ?? null,
                    'total_steps' => $progress['total_steps'] ?? null,
                    'completion_pct' => $progress['completion_pct'] ?? 0.0,
                    'is_complete' => $this->isCompleted($funnelName, $identity),
                    'first_seen' => $progress['first_seen'] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Build the cache key for a funnel+identity pair.
     */
    private function cacheKey(string $funnelName, string $identity): string
    {
        return self::CACHE_PREFIX . $funnelName . '_' . hash('xxh128', $identity);
    }

    /**
     * Calculate total elapsed time in seconds between two ISO timestamps.
     */
    private function totalElapsed(string $firstSeen, string $now): ?float
    {
        $start = strtotime($firstSeen);
        $end = strtotime($now);

        if ($start === false || $end === false) {
            return null;
        }

        return round(($end - $start), 2);
    }
}
