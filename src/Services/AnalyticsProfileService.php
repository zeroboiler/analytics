<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * User profile aggregation service for analytics.
 *
 * Builds and maintains per-user analytics profiles containing event counts,
 * revenue totals, first/last activity timestamps, funnel completion,
 * and engagement scores. All data is stored in the application cache
 * with a configurable TTL.
 *
 * Profile data is updated on every tracked event and can be used for
 * personalization, segmentation, and analytics dashboards.
 */
final class AnalyticsProfileService
{
    private const CACHE_PREFIX = 'zb_analytics_profile_';

    private const DEFAULT_TTL = 86400; // 24 hours

    /** @var array<string, array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>}> */
    private array $localCache = [];

    private CacheRepository $cache;

    private int $ttl;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  int|null  $ttl  Profile cache TTL in seconds (default: 24h)
     */
    public function __construct(AnalyticsManager $manager, CacheRepository $cache, ?int $ttl = null): void
    {
        $this->manager = $manager;
        $this->cache = $cache;
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
    }

    /**
     * Record an event against a user's profile.
     *
     * Increments event counts, updates timestamps, accumulates revenue,
     * tracks funnel completion, and recalculates engagement score.
     *
     * @param  AnalyticsEvent  $event  The tracked analytics event
     */
    public function recordEvent(AnalyticsEvent $event): void
    {
        $userId = $event->userId;

        if ($userId === null || $userId === '') {
            return;
        }

        $profile = $this->getProfile($userId);
        $now = date('c');

        // Update event counts
        $profile['event_counts'][$event->name] = ($profile['event_counts'][$event->name] ?? 0) + 1;
        $profile['total_events']++;

        // Update timestamps
        if ($profile['first_seen'] === null) {
            $profile['first_seen'] = $now;
        }
        $profile['last_seen'] = $now;

        // Accumulate revenue from purchase/revenue events
        $value = $event->params['value'] ?? $event->params['amount'] ?? null;
        if (is_numeric($value)) {
            $profile['total_value'] += (float) $value;
        }

        // Track funnel steps from params
        $funnel = $event->params['funnel'] ?? $event->params['funnel_name'] ?? null;
        $step = $event->params['funnel_step'] ?? $event->params['step'] ?? null;
        if (is_string($funnel) && $funnel !== '' && is_string($step) && $step !== '') {
            $profile['funnel_steps']["{$funnel}:{$step}"] = true;
        }

        // Update plan from subscription/plan events
        $plan = $event->params['plan_name'] ?? $event->params['to_plan'] ?? null;
        if (is_string($plan) && $plan !== '' && in_array($event->name, [
            'subscription', 'plan_upgrade', 'plan_downgrade',
        ], true)) {
            $profile['plan'] = $plan;
        }

        // Merge user traits from identify events
        if ($event->name === 'identify' && ! empty($event->params)) {
            foreach ($event->params as $key => $val) {
                if (is_scalar($val) && ! in_array($key, ['user_id', 'client_id'], true)) {
                    $profile['traits'][$key] = $val;
                }
            }
        }

        // Recalculate engagement score
        $profile['engagement_score'] = $this->calculateEngagementScore($profile);

        $this->saveProfile($userId, $profile);
    }

    /**
     * Get a user's analytics profile.
     *
     * @param  string  $userId  The user ID
     * @return array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>}
     */
    public function getProfile(string $userId): array
    {
        if (isset($this->localCache[$userId])) {
            return $this->localCache[$userId];
        }

        $cached = $this->cache->get(self::CACHE_PREFIX . $userId);

        if (is_array($cached)) {
            $this->localCache[$userId] = $cached;

            return $cached;
        }

        return $this->defaultProfile();
    }

    /**
     * Save a user's analytics profile to cache and local memory.
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $profile
     */
    public function saveProfile(string $userId, array $profile): void
    {
        $this->localCache[$userId] = $profile;
        $this->cache->put(self::CACHE_PREFIX . $userId, $profile, $this->ttl);
    }

    /**
     * Get the total revenue attributed to a user.
     */
    public function getLifetimeValue(string $userId): float
    {
        return $this->getProfile($userId)['total_value'];
    }

    /**
     * Get the total number of events tracked for a user.
     */
    public function getTotalEvents(string $userId): int
    {
        return $this->getProfile($userId)['total_events'];
    }

    /**
     * Get the engagement score for a user (0.0–100.0).
     *
     * Score is calculated based on event frequency, recency, diversity,
     * and funnel completion.
     */
    public function getEngagementScore(string $userId): float
    {
        return $this->getProfile($userId)['engagement_score'];
    }

    /**
     * Get the user's current plan name (from subscription events).
     */
    public function getCurrentPlan(string $userId): ?string
    {
        return $this->getProfile($userId)['plan'];
    }

    /**
     * Get the user's first-seen timestamp.
     */
    public function getFirstSeen(string $userId): ?string
    {
        return $this->getProfile($userId)['first_seen'];
    }

    /**
     * Get the user's last-seen timestamp.
     */
    public function getLastSeen(string $userId): ?string
    {
        return $this->getProfile($userId)['last_seen'];
    }

    /**
     * Check if a user has completed a specific funnel step.
     */
    public function hasCompletedFunnelStep(string $userId, string $funnel, string $step): bool
    {
        $profile = $this->getProfile($userId);

        return isset($profile['funnel_steps']["{$funnel}:{$step}"]) && $profile['funnel_steps']["{$funnel}:{$step}"] === true;
    }

    /**
     * Get all completed funnel steps for a user.
     *
     * @return list<array{funnel: string, step: string}>
     */
    public function getCompletedFunnelSteps(string $userId): array
    {
        $profile = $this->getProfile($userId);
        $steps = [];

        foreach ($profile['funnel_steps'] as $key => $completed) {
            if ($completed === true && str_contains($key, ':')) {
                [$funnel, $step] = explode(':', $key, 2);
                $steps[] = ['funnel' => $funnel, 'step' => $step];
            }
        }

        return $steps;
    }

    /**
     * Get the user's accumulated traits from identify calls.
     *
     * @return array<string, mixed>
     */
    public function getTraits(string $userId): array
    {
        return $this->getProfile($userId)['traits'];
    }

    /**
     * Get a summary of the user's analytics profile for API responses.
     *
     * @return array{user_id: string, total_events: int, lifetime_value: float, first_seen: string|null, last_seen: string|null, engagement_score: float, plan: string|null, event_types: int, funnel_steps_completed: int, traits: array<string, mixed>}
     */
    public function getProfileSummary(string $userId): array
    {
        $profile = $this->getProfile($userId);

        return [
            'user_id' => $userId,
            'total_events' => $profile['total_events'],
            'lifetime_value' => round($profile['total_value'], 2),
            'first_seen' => $profile['first_seen'],
            'last_seen' => $profile['last_seen'],
            'engagement_score' => round($profile['engagement_score'], 1),
            'plan' => $profile['plan'],
            'event_types' => count($profile['event_counts']),
            'funnel_steps_completed' => count(array_filter($profile['funnel_steps'])),
            'traits' => $profile['traits'],
        ];
    }

    /**
     * Permanently delete a user's analytics profile (GDPR compliance).
     *
     * Removes the profile from both local cache and persistent cache.
     */
    public function deleteProfile(string $userId): void
    {
        unset($this->localCache[$userId]);
        $this->cache->forget(self::CACHE_PREFIX . $userId);
    }

    /**
     * Calculate an engagement score from profile data.
     *
     * Factors: event frequency (0–40), event diversity (0–20),
     * revenue activity (0–20), funnel completion (0–20).
     *
     * @param  array{total_events: int, event_counts: array<string, int>, total_value: float, funnel_steps: array<string, bool>}  $profile
     */
    private function calculateEngagementScore(array $profile): float
    {
        $score = 0.0;

        // Event frequency: 0–40 points (logarithmic scale, caps at 100 events)
        $score += min(40.0, log10(max(1, $profile['total_events'])) * 20.0);

        // Event diversity: 0–20 points (caps at 10 unique event types)
        $uniqueTypes = count($profile['event_counts']);
        $score += min(20.0, $uniqueTypes * 2.0);

        // Revenue activity: 0–20 points (any revenue = 10, >$100 = 20)
        if ($profile['total_value'] > 0) {
            $score += $profile['total_value'] >= 100 ? 20.0 : 10.0;
        }

        // Funnel completion: 0–20 points (caps at 10 steps)
        $completedSteps = count(array_filter($profile['funnel_steps']));
        $score += min(20.0, $completedSteps * 2.0);

        return min(100.0, max(0.0, $score));
    }

    /**
     * Get a default empty profile structure.
     *
     * @return array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>}
     */
    private function defaultProfile(): array
    {
        return [
            'event_counts' => [],
            'total_events' => 0,
            'total_value' => 0.0,
            'first_seen' => null,
            'last_seen' => null,
            'funnel_steps' => [],
            'engagement_score' => 0.0,
            'plan' => null,
            'traits' => [],
        ];
    }
}
