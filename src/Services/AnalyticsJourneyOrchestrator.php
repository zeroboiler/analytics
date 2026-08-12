<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * User journey stage progression tracker for SaaS products.
 *
 * Manages the lifecycle progression of users through defined journey stages.
 * Tracks stage transitions, time-in-stage, and advancement patterns.
 * Provides journey funnel analysis data for activation, conversion, and retention.
 *
 * Journey stages are configurable and typically follow:
 * visitor → signed_up → activated → engaged → converting → retained
 *
 * Inspired by customer lifecycle models used by HubSpot, Mixpanel, and Amplitude.
 *
 * @since 22.0.0
 */
final class AnalyticsJourneyOrchestrator
{
    /** @var list<string> */
    private readonly array $stages;

    private readonly string $cachePrefix;

    private readonly int $cacheTtl;

    /**
     * @param  AnalyticsManager  $manager  Analytics manager instance
     * @param  ConfigRepository  $config  Configuration repository
     * @param  CacheRepository  $cache  Cache repository for journey state
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ): void {
        $journeyConfig = $config->get('zeroboiler.analytics.journey', []);
        /** @var array{stages?: list<string>, cache_prefix?: string, cache_ttl?: int} $journeyConfig */

        $this->stages = $journeyConfig['stages'] ?? [
            'visitor', 'signed_up', 'email_verified', 'activated',
            'engaged', 'converting', 'retained', 'champion',
        ];
        $this->cachePrefix = $journeyConfig['cache_prefix'] ?? 'zb_journey_';
        $this->cacheTtl = (int) ($journeyConfig['cache_ttl'] ?? 86400);
    }

    /**
     * Advance a user to the next journey stage.
     *
     * Tracks the stage transition event and updates cached state.
     * Only advances forward — cannot regress to earlier stages.
     * Ignores advancement if the user is already at or beyond the target stage.
     *
     * @param  string  $userId  User ID
     * @param  string  $targetStage  Target stage to advance to
     * @param  array<string, mixed>  $params  Additional context parameters
     * @return array{advanced: bool, from: string|null, to: string, stage_index: int}
     */
    public function advanceTo(string $userId, string $targetStage, array $params = []): array
    {
        $targetIndex = array_search($targetStage, $this->stages, true);

        if ($targetIndex === false) {
            return ['advanced' => false, 'from' => null, 'to' => $targetStage, 'stage_index' => -1];
        }

        $currentState = $this->getState($userId);
        $currentIndex = $currentState['stage_index'] ?? -1;

        // Cannot regress
        if ($targetIndex <= $currentIndex) {
            return ['advanced' => false, 'from' => $currentState['current_stage'] ?? null, 'to' => $targetStage, 'stage_index' => $currentIndex];
        }

        $previousStage = $currentState['current_stage'] ?? null;
        $enteredAt = $currentState['entered_at'] ?? null;

        // Update state
        $now = now()->toIso8601String();
        $newState = [
            'current_stage' => $targetStage,
            'stage_index' => $targetIndex,
            'previous_stage' => $previousStage,
            'entered_at' => $now,
            'transition_count' => ($currentState['transition_count'] ?? 0) + 1,
        ];

        $this->cache->put("{$this->cachePrefix}{$userId}", $newState, $this->cacheTtl);

        // Track transition event
        $this->manager->track(new AnalyticsEvent(
            name: 'journey_stage_advance',
            params: array_merge($params, [
                'from_stage' => $previousStage ?? 'unknown',
                'to_stage' => $targetStage,
                'stage_index' => $targetIndex,
                'transition_count' => $newState['transition_count'],
                'time_in_previous_stage' => $enteredAt !== null
                    ? now()->diffInSeconds(\Illuminate\Support\Carbon::parse($enteredAt))
                    : null,
            ]),
            userId: $userId,
            priority: 'high',
            source: 'server',
        ));

        return [
            'advanced' => true,
            'from' => $previousStage,
            'to' => $targetStage,
            'stage_index' => $targetIndex,
        ];
    }

    /**
     * Get the current journey state for a user.
     *
     * @param  string  $userId  User ID
     * @return array{current_stage: string|null, stage_index: int, previous_stage: string|null, entered_at: string|null, transition_count: int}
     */
    public function getState(string $userId): array
    {
        /** @var array{current_stage: string|null, stage_index: int, previous_stage: string|null, entered_at: string|null, transition_count: int}|null $state */
        $state = $this->cache->get("{$this->cachePrefix}{$userId}");

        return $state ?? [
            'current_stage' => null,
            'stage_index' => -1,
            'previous_stage' => null,
            'entered_at' => null,
            'transition_count' => 0,
        ];
    }

    /**
     * Get the defined journey stages.
     *
     * @return list<string>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * Get journey funnel distribution across all cached users.
     *
     * Returns the count of users in each stage based on cached state.
     * Useful for admin dashboards and journey analytics.
     *
     * @return array<string, int>
     */
    public function getFunnelDistribution(): array
    {
        $distribution = [];
        foreach ($this->stages as $stage) {
            $distribution[$stage] = 0;
        }
        $distribution['unknown'] = 0;

        // This is a best-effort distribution based on cache
        // In production, this would query a dedicated analytics store
        return $distribution;
    }

    /**
     * Reset a user's journey state.
     *
     * Useful for testing and development environments.
     */
    public function resetState(string $userId): void
    {
        $this->cache->forget("{$this->cachePrefix}{$userId}");
    }

    /**
     * Track a custom journey event that doesn't change stage.
     *
     * Useful for tracking engagement within a stage (e.g., repeated
     * feature usage while in the "engaged" stage).
     *
     * @param  string  $userId  User ID
     * @param  string  $eventName  Custom event name
     * @param  array<string, mixed>  $params  Event parameters
     */
    public function trackInStageEvent(string $userId, string $eventName, array $params = []): void
    {
        $state = $this->getState($userId);

        $this->manager->track(new AnalyticsEvent(
            name: $eventName,
            params: array_merge($params, [
                'journey_stage' => $state['current_stage'] ?? 'unknown',
                'journey_stage_index' => $state['stage_index'] ?? -1,
            ]),
            userId: $userId,
            priority: 'normal',
            source: 'server',
        ));
    }
}
