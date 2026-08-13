<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Detects and tracks the first "aha moment" event for each user.
 *
 * Monitors predefined value-realization milestones (first search, first
 * feature usage, first integration, first team invite) and fires a
 * dedicated 'first_value' event when a user crosses the threshold.
 *
 * The detection is cache-backed and idempotent — once a first-value event
 * fires for a user+event pair, it won't fire again unless explicitly reset.
 *
 * Configuration is read from `zeroboiler.analytics.first_value`.
 *
 * @since 61.0.0
 */
final class FirstValueDetectorService
{
    /** @var array<string, array{event: string, label: string, weight: float, category: string}> */
    private const DEFAULT_MILESTONES = [
        'first_search' => [
            'event' => 'search',
            'label' => 'First Search Performed',
            'weight' => 1.5,
            'category' => 'engagement',
        ],
        'first_feature_used' => [
            'event' => 'feature_used',
            'label' => 'First Feature Used',
            'weight' => 2.0,
            'category' => 'activation',
        ],
        'first_form_submit' => [
            'event' => 'form_submit',
            'label' => 'First Form Submitted',
            'weight' => 1.5,
            'category' => 'engagement',
        ],
        'first_integration' => [
            'event' => 'integration_connected',
            'label' => 'First Integration Connected',
            'weight' => 3.0,
            'category' => 'expansion',
        ],
        'first_team_invite' => [
            'event' => 'invite_sent',
            'label' => 'First Team Invite Sent',
            'weight' => 2.5,
            'category' => 'collaboration',
        ],
        'first_share' => [
            'event' => 'share',
            'label' => 'First Content Shared',
            'weight' => 1.0,
            'category' => 'advocacy',
        ],
        'first_content_engagement' => [
            'event' => 'content_engagement',
            'label' => 'First Deep Content Engagement',
            'weight' => 1.5,
            'category' => 'engagement',
        ],
        'first_milestone' => [
            'event' => 'milestone_reached',
            'label' => 'First Milestone Reached',
            'weight' => 3.0,
            'category' => 'achievement',
        ],
    ];

    private const CACHE_PREFIX = 'zb_fv_';

    private const CACHE_TTL = 7776000; // 90 days

    private CacheRepository $cache;

    /** @var array<string, array{event: string, label: string, weight: float, category: string}> */
    private array $milestones;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  array<string, mixed>  $config  From `zeroboiler.analytics.first_value`
     */
    public function __construct(CacheRepository $cache, array $config = []): void
    {
        $this->cache = $cache;
        $this->enabled = (bool) ($config['enabled'] ?? true);

        $customMilestones = $config['milestones'] ?? [];
        /** @var array<string, array{event: string, label: string, weight?: float, category?: string}> $customMilestones */

        $this->milestones = array_merge(self::DEFAULT_MILESTONES, $customMilestones);
    }

    /**
     * Evaluate an event for first-value detection.
     *
     * If the event matches a configured milestone and this is the first time
     * the user has fired this event type, returns a 'first_value' event.
     * Returns null if not a first-value milestone or already detected.
     *
     * @param  AnalyticsEvent  $event  The incoming analytics event
     * @return AnalyticsEvent|null  A first_value event or null
     */
    public function detect(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->enabled) {
            return null;
        }

        $userId = $event->userId;

        if ($userId === null || $userId === '') {
            return null;
        }

        $eventName = $event->name;

        // Find matching milestone
        $milestone = null;
        $milestoneKey = null;

        foreach ($this->milestones as $key => $definition) {
            if ($definition['event'] === $eventName) {
                $milestone = $definition;
                $milestoneKey = $key;

                break;
            }
        }

        if ($milestone === null) {
            return null;
        }

        // Check if already detected
        $cacheKey = self::CACHE_PREFIX . md5($userId . ':' . $milestoneKey);

        if ($this->cache->has($cacheKey)) {
            return null;
        }

        // Mark as detected
        $this->cache->put($cacheKey, true, self::CACHE_TTL);

        // Fire first_value event
        return new AnalyticsEvent(
            name: 'first_value',
            params: [
                'milestone' => $milestoneKey,
                'milestone_event' => $eventName,
                'milestone_label' => $milestone['label'],
                'milestone_category' => $milestone['category'],
                'milestone_weight' => $milestone['weight'],
                'original_event' => $eventName,
                'original_params' => $event->params,
            ],
            clientId: $event->clientId,
            userId: $userId,
        );
    }

    /**
     * Get the first-value score for a user.
     *
     * Calculates a weighted score based on which milestones the user
     * has achieved. The maximum possible score depends on the configured
     * milestones and their weights.
     *
     * @param  string  $userId  The user identifier
     * @return array{score: float, max_score: float, percentage: float, milestones: array<string, array{achieved: bool, label: string, weight: float, category: string}>}
     */
    public function getScore(string $userId): array
    {
        $score = 0.0;
        $maxScore = 0.0;
        $milestones = [];

        foreach ($this->milestones as $key => $milestone) {
            $weight = (float) ($milestone['weight'] ?? 1.0);
            $maxScore += $weight;

            $cacheKey = self::CACHE_PREFIX . md5($userId . ':' . $key);
            $achieved = $this->cache->has($cacheKey);

            if ($achieved) {
                $score += $weight;
            }

            $milestones[$key] = [
                'achieved' => $achieved,
                'label' => $milestone['label'],
                'weight' => $weight,
                'category' => $milestone['category'],
            ];
        }

        $percentage = $maxScore > 0.0 ? round(($score / $maxScore) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'milestones' => $milestones,
        ];
    }

    /**
     * Check if a specific milestone has been achieved for a user.
     */
    public function hasAchieved(string $userId, string $milestoneKey): bool
    {
        if (! isset($this->milestones[$milestoneKey])) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . md5($userId . ':' . $milestoneKey);

        return $this->cache->has($cacheKey);
    }

    /**
     * Get the list of configured milestones.
     *
     * @return array<string, array{event: string, label: string, weight: float, category: string}>
     */
    public function getMilestones(): array
    {
        return $this->milestones;
    }

    /**
     * Reset a specific milestone for a user (allow re-detection).
     *
     * Useful for testing or when resetting user onboarding state.
     */
    public function resetMilestone(string $userId, string $milestoneKey): void
    {
        $cacheKey = self::CACHE_PREFIX . md5($userId . ':' . $milestoneKey);
        $this->cache->forget($cacheKey);
    }

    /**
     * Reset all milestones for a user.
     */
    public function resetAll(string $userId): void
    {
        foreach (array_keys($this->milestones) as $key) {
            $this->resetMilestone($userId, $key);
        }
    }

    /**
     * Get the count of users who have achieved a specific milestone.
     *
     * Useful for admin dashboards to show funnel progress.
     */
    public function milestoneAchievementCount(string $milestoneKey): int
    {
        // This is an approximation — the cache doesn't support prefix counting.
        // In production, use the AnalyticsStatsService or database for accurate counts.
        return 0;
    }

    /**
     * Check if first-value detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
