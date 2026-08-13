<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\SaaS\GrowthMilestoneEvent;

/**
 * SaaS Growth Metrics service.
 *
 * Computes industry-standard SaaS growth metrics:
 * - North Star Metric (customizable per product)
 * - Activation Rate (users completing aha moment)
 * - Stickiness Rate (DAU/MAU ratio)
 * - Virality Coefficient (K-factor)
 * - Retention curves (day 1, 7, 30)
 * - Growth milestone tracking
 *
 * Inspired by frameworks from Reforge, Amplitude Product Analytics,
 * and Segment's Growth Analytics.
 *
 * @since 78.0.0
 */
final class SaaSGrowthMetricsService
{
    private const CACHE_PREFIX = 'zb_growth_metrics_';
    private const VALID_MILESTONE_TYPES = ['activation', 'power_user', 'advocate', 'team_scale', 'revenue_tier'];

    private CacheRepository $cache;
    private AnalyticsManager $manager;
    private int $cacheTtl;

    /** @var list<string> Configured activation event names */
    private array $activationEvents;

    public function __construct(
        CacheRepository $cache,
        AnalyticsManager $manager,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;
        $this->manager = $manager;

        /** @var array{cache_ttl?: int, activation_events?: list<string>} $growthConfig */
        $growthConfig = $config->get('zeroboiler.analytics.growth_metrics', []);
        $this->cacheTtl = $growthConfig['cache_ttl'] ?? 3600; // 1 hour
        $this->activationEvents = $growthConfig['activation_events'] ?? [];
    }

    /**
     * Track a growth milestone event.
     *
     * @param  string  $milestoneType  One of: activation, power_user, advocate, team_scale, revenue_tier
     * @param  string  $milestoneName  Human-readable milestone name
     * @param  int|null  $milestoneValue  Numeric milestone value
     * @param  array<string, mixed>  $context  Additional context
     *
     * @throws \InvalidArgumentException if milestone type is invalid
     */
    public function trackMilestone(
        string $milestoneType,
        string $milestoneName,
        ?int $milestoneValue = null,
        array $context = [],
    ): void {
        if (! in_array($milestoneType, self::VALID_MILESTONE_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid milestone type: %s. Must be one of: %s', $milestoneType, implode(', ', self::VALID_MILESTONE_TYPES)),
            );
        }

        $event = new GrowthMilestoneEvent(
            milestoneType: $milestoneType,
            milestoneName: $milestoneName,
            milestoneValue: $milestoneValue,
            daysSinceSignup: $context['days_since_signup'] ?? null,
            previousMilestone: $context['previous_milestone'] ?? null,
        );

        $this->manager->trackEvent($event);
        $this->persistMilestone($milestoneType, $milestoneName, $milestoneValue);
    }

    /**
     * Compute activation rate.
     *
     * Activation Rate = Users completing activation event / Total signups
     *
     * @return array{rate: float, activated_count: int, total_signups: int, period: string, activation_events: list<string>}
     */
    public function activationRate(string $period = 'last_30_days'): array
    {
        $cacheKey = self::CACHE_PREFIX . 'activation_' . $period;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $totalSignups = $this->countEvents('sign_up', $period);
        $activatedCount = 0;

        foreach ($this->activationEvents as $event) {
            $activatedCount += $this->countEvents($event, $period);
        }

        // Take minimum to avoid counting users multiple times
        $activatedCount = min($activatedCount, $totalSignups);
        $rate = $totalSignups > 0 ? ($activatedCount / $totalSignups) * 100 : 0.0;

        $result = [
            'rate' => round($rate, 2),
            'activated_count' => $activatedCount,
            'total_signups' => $totalSignups,
            'period' => $period,
            'activation_events' => $this->activationEvents,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Compute stickiness rate (DAU/MAU ratio).
     *
     * Stickiness = DAU / MAU × 100
     * Higher is better — indicates how often users return.
     *
     * @return array{stickiness: float, dau: int, mau: int, period: string}
     */
    public function stickinessRate(string $period = 'last_30_days'): array
    {
        $cacheKey = self::CACHE_PREFIX . 'stickiness_' . $period;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $dau = $this->countUniqueUsers('page_view', 'last_1_day');
        $mau = $this->countUniqueUsers('page_view', $period);

        $stickiness = $mau > 0 ? ($dau / $mau) * 100 : 0.0;

        $result = [
            'stickiness' => round($stickiness, 2),
            'dau' => $dau,
            'mau' => $mau,
            'period' => $period,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Compute virality coefficient (K-factor).
     *
     * K = (invites per user) × (conversion rate of invites)
     * K > 1 means viral growth; K < 1 means declining growth.
     *
     * @return array{k_factor: float, invites_per_user: float, invite_conversion_rate: float, total_invites: int, total_conversions: int}
     */
    public function viralityCoefficient(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'virality';
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $totalInvites = $this->countEvents('invite_sent', 'last_30_days');
        $totalSignupEvents = $this->countEvents('sign_up', 'last_30_days');
        $totalUsers = max($totalSignupEvents, 1);

        $invitesPerUser = $totalInvites / $totalUsers;
        $inviteConversionRate = $totalInvites > 0
            ? ($this->countEvents('referral_converted', 'last_30_days') / $totalInvites)
            : 0.0;

        $kFactor = $invitesPerUser * $inviteConversionRate;

        $result = [
            'k_factor' => round($kFactor, 4),
            'invites_per_user' => round($invitesPerUser, 2),
            'invite_conversion_rate' => round($inviteConversionRate * 100, 2),
            'total_invites' => $totalInvites,
            'total_conversions' => $this->countEvents('referral_converted', 'last_30_days'),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get retention curve data.
     *
     * Returns retention percentages for days 1, 3, 7, 14, 30.
     *
     * @return array{day_1: float|null, day_3: float|null, day_7: float|null, day_14: float|null, day_30: float|null, cohort_size: int}
     */
    public function retentionCurve(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'retention_curve';
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $cohortSize = $this->countUniqueUsers('sign_up', '30_days_ago');
        $result = [
            'cohort_size' => $cohortSize,
            'day_1' => $this->retentionAtDay($cohortSize, 1),
            'day_3' => $this->retentionAtDay($cohortSize, 3),
            'day_7' => $this->retentionAtDay($cohortSize, 7),
            'day_14' => $this->retentionAtDay($cohortSize, 14),
            'day_30' => $this->retentionAtDay($cohortSize, 30),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get growth milestones reached.
     *
     * @return list<array{type: string, name: string, value: int|null, count: int, latest_at: int|null}>
     */
    public function milestones(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'milestones';
        $data = $this->cache->get($cacheKey, []);

        $result = [];
        foreach ($data as $milestone) {
            $result[] = [
                'type' => $milestone['type'],
                'name' => $milestone['name'],
                'value' => $milestone['value'],
                'count' => $milestone['count'] ?? 0,
                'latest_at' => $milestone['latest_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Get comprehensive growth dashboard summary.
     *
     * @return array{activation: array{rate: float}, stickiness: array{rate: float}, virality: array{k_factor: float}, retention: array{day_1: float|null, day_7: float|null, day_30: float|null}, milestones_count: int}
     */
    public function dashboardSummary(): array
    {
        return [
            'activation' => ['rate' => $this->activationRate()['rate']],
            'stickiness' => ['rate' => $this->stickinessRate()['stickiness']],
            'virality' => ['k_factor' => $this->viralityCoefficient()['k_factor']],
            'retention' => [
                'day_1' => $this->retentionCurve()['day_1'],
                'day_7' => $this->retentionCurve()['day_7'],
                'day_30' => $this->retentionCurve()['day_30'],
            ],
            'milestones_count' => count($this->milestones()),
        ];
    }

    /**
     * Clear all growth metrics cache.
     */
    public function clearCache(): void
    {
        $keys = [
            'activation_last_30_days', 'stickiness_last_30_days',
            'virality', 'retention_curve', 'milestones',
        ];

        foreach ($keys as $key) {
            $this->cache->forget(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * Count events for a given event name and period.
     * Uses cache-backed counting for efficiency.
     */
    private function countEvents(string $eventName, string $period): int
    {
        $cacheKey = self::CACHE_PREFIX . 'event_count_' . $eventName . '_' . $period;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // In production, this would query the event store
        // For now, return cached or zero
        return 0;
    }

    /**
     * Count unique users for an event and period.
     */
    private function countUniqueUsers(string $eventName, string $period): int
    {
        $cacheKey = self::CACHE_PREFIX . 'unique_users_' . $eventName . '_' . $period;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        return 0;
    }

    /**
     * Calculate retention at a specific day for a cohort.
     */
    private function retentionAtDay(int $cohortSize, int $day): ?float
    {
        if ($cohortSize === 0) {
            return null;
        }

        $returning = $this->countUniqueUsers('page_view', $day . '_days_after_signup');

        return round(($returning / $cohortSize) * 100, 2);
    }

    /**
     * Persist a milestone event to cache.
     */
    private function persistMilestone(string $type, string $name, ?int $value): void
    {
        $cacheKey = self::CACHE_PREFIX . 'milestones';
        $data = $this->cache->get($cacheKey, []);

        $key = $type . ':' . $name;

        if (! isset($data[$key])) {
            $data[$key] = [
                'type' => $type,
                'name' => $name,
                'value' => $value,
                'count' => 0,
                'latest_at' => null,
            ];
        }

        $data[$key]['count']++;
        $data[$key]['latest_at'] = time();

        $this->cache->put($cacheKey, $data, $this->cacheTtl * 24);
    }
}
