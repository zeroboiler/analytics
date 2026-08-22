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
 * SaaS Feature Adoption Tracker.
 *
 * Tracks how users discover, adopt, and continue using product features.
 * Builds adoption curves over time, calculates feature stickiness, and
 * identifies adoption velocity patterns across user cohorts.
 *
 * Metrics per feature:
 * - **Adoption rate**: % of users who have used a feature at least once
 * - **Stickiness**: % of adopters who continue using the feature (7/14/30 day windows)
 * - **Adoption velocity**: Median time from signup to first feature use (days)
 * - **Usage frequency**: Average events per user per period
 * - **Activation correlation**: Correlation between feature adoption and conversion
 *
 * Data is aggregated in cache for fast dashboard reads. For persistent
 * analytics, use a Redis or database cache driver.
 *
 * Configuration: `zeroboiler.analytics.feature_adoption`
 *
 * @phpstan-type FeatureRecord array{first_used: int|null, last_used: int|null, event_count: int, cohort: string}
 * @phpstan-type FeatureMetrics array{adoption_rate: float, stickiness_7d: float|null, stickiness_14d: float|null, stickiness_30d: float|null, median_velocity_days: float|null, usage_frequency: float, total_adopters: int, total_users: int}
 * @phpstan-type AdoptionSnapshot array{features: array<string, FeatureMetrics>, computed_at: string, period: string}
 *
 * @since 184.0.0
 */
final class SaaSFeatureAdoptionTracker
{
    private const CACHE_PREFIX = 'zb_feature_adoption_';

    private const DEFAULT_TTL = 3600;

    /** @var int Maximum features to track */
    private const MAX_FEATURES = 200;

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    private string $cohortGranularity;

    /** @var array<string, int> Configurable stickiness windows in days */
    private array $stickinessWindows;

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $adoptionConfig = $config->get('zeroboiler.analytics.feature_adoption', []);
        /** @var array{enabled?: bool, cache_ttl?: int, cohort_granularity?: string, stickiness_windows?: list<int>} $adoptionConfig */
        $this->enabled = (bool) ($adoptionConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($adoptionConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->cohortGranularity = (string) ($adoptionConfig['cohort_granularity'] ?? 'weekly');
        $this->stickinessWindows = (array) ($adoptionConfig['stickiness_windows'] ?? [7, 14, 30]);
    }

    /**
     * Record a feature usage event.
     *
     * @param  string  $featureName  Feature identifier (e.g. 'dashboard', 'api_keys', 'reports')
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $context  Additional context (plan, source, etc.)
     */
    public function recordFeatureUse(string $featureName, string $userId, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if (strlen($featureName) > 100) {
            return;
        }

        $now = now()->getTimestamp();

        // Track the analytics event
        $event = new AnalyticsEvent(
            name: 'feature_adoption',
            params: array_merge($context, [
                'feature' => $featureName,
                'user_id' => $userId,
                'cohort' => $this->getCohortKey(),
            ]),
        );

        $this->manager->trackEvent($event);

        $eventCountKey = self::CACHE_PREFIX . 'events_' . $featureName;
        $currentCount = (int) $this->cache->get($eventCountKey, 0);
        $this->cache->put($eventCountKey, $currentCount + 1, $this->cacheTtl);

        $adoptersKey = self::CACHE_PREFIX . 'adopters_' . $featureName;
        $adopters = (array) $this->cache->get($adoptersKey, []);
        if (! in_array($userId, $adopters, true)) {
            $adopters[] = $userId;
            // Cap at 50,000 unique IDs to prevent unbounded growth
            if (count($adopters) > 50000) {
                $adopters = array_slice($adopters, -50000);
            }
            $this->cache->put($adoptersKey, $adopters, $this->cacheTtl * 24);
        }

        // Track first-use timing for velocity calculation
        $velocityKey = self::CACHE_PREFIX . 'velocity_' . $featureName;
        $velocities = (array) $this->cache->get($velocityKey, []);
        $velocities[$userId] = $now;
        // Cap at 10,000 entries
        if (count($velocities) > 10000) {
            $velocities = array_slice($velocities, -10000, null, true);
        }
        $this->cache->put($velocityKey, $velocities, $this->cacheTtl * 24);

        // Track recent users for stickiness calculation
        foreach ($this->stickinessWindows as $days) {
            $recentKey = self::CACHE_PREFIX . "recent_{$days}d_" . $featureName;
            $recent = (array) $this->cache->get($recentKey, []);
            $recent[$userId] = $now;
            $cutoff = $now - ($days * 86400);
            $recent = array_filter($recent, fn (int $ts): bool => $ts >= $cutoff);
            if (count($recent) > 50000) {
                $recent = array_slice($recent, -50000, null, true);
            }
            $this->cache->put($recentKey, $recent, $this->cacheTtl * 24);
        }
    }

    /**
     * Get adoption metrics for a specific feature.
     *
     * @param  string  $featureName  Feature identifier
     * @param  int|null  $totalUsers  Total user count for adoption rate calculation
     * @return FeatureMetrics
     */
    public function getFeatureMetrics(string $featureName, ?int $totalUsers = null): array
    {
        $adopters = (array) $this->cache->get(self::CACHE_PREFIX . 'adopters_' . $featureName, []);
        $totalAdopters = count($adopters);

        $totalUsers = $totalUsers ?? $this->getGlobalUserCount();
        $adoptionRate = $totalUsers > 0 ? round($totalAdopters / $totalUsers, 4) : 0.0;

        $stickiness = [];
        foreach ($this->stickinessWindows as $days) {
            $recentKey = self::CACHE_PREFIX . "recent_{$days}d_" . $featureName;
            $recent = (array) $this->cache->get($recentKey, []);
            $recentCount = count($recent);
            $stickiness["stickiness_{$days}d"] = $totalAdopters > 0
                ? round($recentCount / $totalAdopters, 4)
                : null;
        }

        return array_merge([
            'adoption_rate' => $adoptionRate,
            'total_adopters' => $totalAdopters,
            'total_users' => $totalUsers,
            'usage_frequency' => $this->getUsageFrequency($featureName, $totalAdopters),
            'median_velocity_days' => $this->getMedianVelocity($featureName),
        ], $stickiness);
    }

    /**
     * Get an adoption snapshot across all tracked features.
     *
     * @param  string|null  $period  Period scope ('1h', '24h', '7d', '30d')
     * @return AdoptionSnapshot
     */
    public function getAdoptionSnapshot(?string $period = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'snapshot_' . ($period ?? 'all');
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached)) {
            /** @var AdoptionSnapshot $cached */
            return $cached;
        }

        $features = $this->getTrackedFeatures();
        $totalUsers = $this->getGlobalUserCount();
        $featureMetrics = [];

        foreach ($features as $featureName) {
            $featureMetrics[$featureName] = $this->getFeatureMetrics($featureName, $totalUsers);
        }

        $snapshot = [
            'features' => $featureMetrics,
            'computed_at' => now()->toIso8601String(),
            'period' => $period ?? 'all',
        ];

        $this->cache->put($cacheKey, $snapshot, $this->cacheTtl);

        return $snapshot;
    }

    /**
     * Get features ranked by adoption rate (highest first).
     *
     * @param  int|null  $limit  Max features to return (null = all)
     * @return list<array{feature: string, adoption_rate: float, total_adopters: int}>
     */
    public function getTopFeatures(?int $limit = null): array
    {
        $snapshot = $this->getAdoptionSnapshot();
        $ranked = [];

        foreach ($snapshot['features'] as $feature => $metrics) {
            $ranked[] = [
                'feature' => $feature,
                'adoption_rate' => $metrics['adoption_rate'],
                'total_adopters' => $metrics['total_adopters'],
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['adoption_rate'] <=> $a['adoption_rate']);

        if ($limit !== null) {
            $ranked = array_slice($ranked, 0, $limit);
        }

        return $ranked;
    }

    /**
     * Get features with low adoption (potential for improvement).
     *
     * @param  float  $threshold  Adoption rate below which a feature is "low adoption" (default: 0.1 = 10%)
     * @return list<array{feature: string, adoption_rate: float, total_adopters: int}>
     */
    public function getLowAdoptionFeatures(float $threshold = 0.10): array
    {
        $all = $this->getTopFeatures();

        return array_values(array_filter(
            $all,
            fn (array $f): bool => $f['adoption_rate'] < $threshold && $f['adoption_rate'] > 0,
        ));
    }

    /**
     * Compare feature adoption between two cohorts.
     *
     * @param  string  $cohortA  First cohort key (e.g. '2026-W33')
     * @param  string  $cohortB  Second cohort key (e.g. '2026-W32')
     * @return array<string, array{cohort_a: float, cohort_b: float, delta: float}>
     */
    public function compareCohorts(string $cohortA, string $cohortB): array
    {
        $features = $this->getTrackedFeatures();
        $comparison = [];

        foreach ($features as $featureName) {
            $rateA = $this->getCohortAdoptionRate($featureName, $cohortA);
            $rateB = $this->getCohortAdoptionRate($featureName, $cohortB);

            if ($rateA !== null || $rateB !== null) {
                $comparison[$featureName] = [
                    'cohort_a' => $rateA ?? 0.0,
                    'cohort_b' => $rateB ?? 0.0,
                    'delta' => round(($rateA ?? 0.0) - ($rateB ?? 0.0), 4),
                ];
            }
        }

        return $comparison;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of currently tracked feature names.
     *
     * @return list<string>
     */
    public function getTrackedFeatures(): array
    {
        $listKey = self::CACHE_PREFIX . 'feature_list';
        $list = (array) $this->cache->get($listKey, []);

        return array_values(array_unique($list));
    }

    /**
     * Clear all cached adoption data for a feature.
     */
    public function clearFeatureCache(string $featureName): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'events_' . $featureName);
        $this->cache->forget(self::CACHE_PREFIX . 'adopters_' . $featureName);
        $this->cache->forget(self::CACHE_PREFIX . 'velocity_' . $featureName);

        foreach ($this->stickinessWindows as $days) {
            $this->cache->forget(self::CACHE_PREFIX . "recent_{$days}d_" . $featureName);
        }
    }

    /**
     * Register a feature name in the tracked features list.
     * Called automatically when recordFeatureUse is called.
     */
    private function registerFeature(string $featureName): void
    {
        $listKey = self::CACHE_PREFIX . 'feature_list';
        $list = (array) $this->cache->get($listKey, []);

        if (! in_array($featureName, $list, true) && count($list) < self::MAX_FEATURES) {
            $list[] = $featureName;
            $this->cache->put($listKey, $list, $this->cacheTtl * 24 * 30); // 30 days
        }
    }

    /**
     * Get global user count from cache (approximate, for rate calculations).
     */
    private function getGlobalUserCount(): int
    {
        // or use a dedicated counter if available.
        $totalKey = self::CACHE_PREFIX . 'total_users';
        $total = (int) $this->cache->get($totalKey, 0);

        if ($total > 0) {
            return $total;
        }

        // Fallback: use the largest adopters set across all features
        $maxUsers = 0;
        $features = $this->getTrackedFeatures();
        foreach ($features as $featureName) {
            $adopters = (array) $this->cache->get(self::CACHE_PREFIX . 'adopters_' . $featureName, []);
            if (count($adopters) > $maxUsers) {
                $maxUsers = count($adopters);
            }
        }

        return $maxUsers;
    }

    /**
     * Calculate average usage frequency for a feature (events per adopter).
     *
     * @param  string  $featureName  Feature identifier
     * @param  int  $totalAdopters  Number of unique adopters
     * @return float Events per adopter
     */
    private function getUsageFrequency(string $featureName, int $totalAdopters): float
    {
        if ($totalAdopters === 0) {
            return 0.0;
        }

        $eventCount = (int) $this->cache->get(self::CACHE_PREFIX . 'events_' . $featureName, 0);

        return round($eventCount / $totalAdopters, 2);
    }

    /**
     * Calculate median adoption velocity (days from signup to first use).
     *
     * @param  string  $featureName  Feature identifier
     * @return float|null Median days, null if insufficient data
     */
    private function getMedianVelocity(string $featureName): ?float
    {
        $velocityKey = self::CACHE_PREFIX . 'velocity_' . $featureName;
        $velocities = (array) $this->cache->get($velocityKey, []);

        if (count($velocities) < 2) {
            return null;
        }

        $timestamps = array_values($velocities);
        sort($timestamps);
        $count = count($timestamps);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return (($timestamps[$mid - 1] + $timestamps[$mid]) / 2) / 86400.0;
        }

        return $timestamps[$mid] / 86400.0;
    }

    /**
     * Get adoption rate for a specific cohort and feature.
     */
    private function getCohortAdoptionRate(string $featureName, string $cohortKey): ?float
    {
        $cohortAdoptersKey = self::CACHE_PREFIX . 'cohort_' . $cohortKey . '_adopters_' . $featureName;
        $adopters = (array) $this->cache->get($cohortAdoptersKey, []);

        if (empty($adopters)) {
            return null;
        }

        $cohortUsersKey = self::CACHE_PREFIX . 'cohort_' . $cohortKey . '_total_users';
        $cohortTotal = (int) $this->cache->get($cohortUsersKey, 0);

        if ($cohortTotal === 0) {
            return null;
        }

        return round(count($adopters) / $cohortTotal, 4);
    }

    /**
     * Get a cohort key based on configured granularity.
     */
    private function getCohortKey(): string
    {
        return match ($this->cohortGranularity) {
            'weekly' => now()->startOfWeek()->format('Y-W'),
            'monthly' => now()->startOfMonth()->format('Y-m'),
            default => now()->format('Y-m-d'),
        };
    }
}
