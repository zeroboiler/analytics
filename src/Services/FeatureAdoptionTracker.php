<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feature adoption tracking service for SaaS products.
 *
 * Tracks which features each user has adopted, when they were first adopted,
 * and provides adoption funnels, streaks, and cohort analysis.
 *
 * Used for product-led growth (PLG) analysis, activation scoring,
 * feature usage dashboards, and retention correlation.
 *
 * Data is stored in the application cache with configurable TTL.
 *
 * @version 4.6.0
 */
final class FeatureAdoptionTracker
{
    private const CACHE_PREFIX = 'zb_feat_adopt_';

    private const DEFAULT_TTL = 2592000; // 30 days

    private CacheRepository $cache;

    private int $ttl;

    private int $streakWindowDays;

    /**
     * @param  CacheRepository  $cache  Application cache store
     * @param  ConfigRepository  $config  Analytics config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $adoptionConfig = $config->get('zeroboiler.analytics.feature_adoption', []);
        /** @var array{cache_ttl?: int, streak_window_days?: int} $adoptionConfig */

        $this->ttl = (int) ($adoptionConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->streakWindowDays = (int) ($adoptionConfig['streak_window_days'] ?? 7);
    }

    /**
     * Record that a user has adopted a feature.
     *
     * If this is the first time the user uses this feature, records
     * the timestamp. If already adopted, updates the last-used timestamp.
     *
     * @param  string  $userId  The user ID
     * @param  string  $featureName  Feature identifier (e.g., 'dashboard_export', 'api_integration')
     * @param  array<string, mixed>  $context  Additional context (plan, role, etc.)
     */
    public function recordAdoption(string $userId, string $featureName, array $context = []): void
    {
        $key = self::CACHE_PREFIX . $userId;
        $profile = $this->cache->get($key, []);

        if (! is_array($profile)) {
            $profile = [];
        }

        $now = date('c');
        $today = date('Y-m-d');

        if (! isset($profile['features'][$featureName])) {
            // First adoption
            $profile['features'][$featureName] = [
                'first_used' => $now,
                'last_used' => $now,
                'use_count' => 1,
                'context' => $context,
            ];

            // Track adoption streak
            $profile['streaks'][$featureName] = [$today];
        } else {
            // Subsequent use
            $profile['features'][$featureName]['last_used'] = $now;
            $profile['features'][$featureName]['use_count'] = ($profile['features'][$featureName]['use_count'] ?? 0) + 1;

            // Update streak if not already recorded today
            $streaks = $profile['streaks'][$featureName] ?? [];
            if (! in_array($today, $streaks, true)) {
                $streaks[] = $today;
                // Keep only last N days for streak tracking
                $profile['streaks'][$featureName] = array_slice($streaks, -$this->streakWindowDays);
            }
        }

        $profile['total_features'] = count($profile['features'] ?? []);
        $profile['last_activity'] = $now;

        $this->cache->put($key, $profile, $this->ttl);
    }

    /**
     * Get a user's feature adoption profile.
     *
     * @param  string  $userId  The user ID
     * @return array{total_features: int, features: array<string, array{first_used: string, last_used: string, use_count: int, context: array<string, mixed>}>, streaks: array<string, list<string>>, last_activity: string|null}
     */
    public function getProfile(string $userId): array
    {
        $key = self::CACHE_PREFIX . $userId;
        $profile = $this->cache->get($key, []);

        if (! is_array($profile)) {
            return [
                'total_features' => 0,
                'features' => [],
                'streaks' => [],
                'last_activity' => null,
            ];
        }

        return $profile;
    }

    /**
     * Check if a user has adopted a specific feature.
     *
     * @param  string  $userId  The user ID
     * @param  string  $featureName  Feature identifier
     */
    public function hasAdopted(string $userId, string $featureName): bool
    {
        $profile = $this->getProfile($userId);

        return isset($profile['features'][$featureName]);
    }

    /**
     * Get the adoption streak for a specific feature.
     *
     * Returns the number of consecutive days the user has used
     * the feature within the configured streak window.
     *
     * @param  string  $userId  The user ID
     * @param  string  $featureName  Feature identifier
     * @return int Current streak in days (0 = not adopted or streak broken)
     */
    public function getStreak(string $userId, string $featureName): int
    {
        $profile = $this->getProfile($userId);
        $days = $profile['streaks'][$featureName] ?? [];

        if (empty($days)) {
            return 0;
        }

        // Sort descending and count consecutive days from today
        rsort($days);
        $streak = 0;
        $checkDate = new \DateTimeImmutable('today');

        foreach ($days as $day) {
            $dayDate = new \DateTimeImmutable($day);
            $diff = $checkDate->diff($dayDate);

            if ($diff->days === 0 && $diff->invert === 0) {
                $streak++;
                $checkDate = $checkDate->modify('-1 day');
            } elseif ($diff->days === 1 && $diff->invert === 1) {
                $streak++;
                $checkDate = $dayDate;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Get the feature adoption funnel for a set of features.
     *
     * Shows how many users have adopted each feature in sequence,
     * revealing drop-off rates between features.
     *
     * @param  list<string>  $featureNames  Ordered feature names forming the funnel
     * @param  list<string>  $userIds  User IDs to analyze
     * @return list<array{feature: string, adopted: int, adoption_rate: float, drop_off: float}>
     */
    public function adoptionFunnel(array $featureNames, array $userIds): array
    {
        if (empty($featureNames) || empty($userIds)) {
            return [];
        }

        $total = count($userIds);
        $funnel = [];
        $prevAdopted = $total;

        foreach ($featureNames as $feature) {
            $adopted = 0;
            foreach ($userIds as $uid) {
                if ($this->hasAdopted($uid, $feature)) {
                    $adopted++;
                }
            }

            $adoptionRate = $total > 0 ? round($adopted / $total, 4) : 0.0;
            $dropOff = $prevAdopted > 0 ? round(($prevAdopted - $adopted) / $prevAdopted, 4) : 0.0;

            $funnel[] = [
                'feature' => $feature,
                'adopted' => $adopted,
                'adoption_rate' => $adoptionRate,
                'drop_off' => $dropOff,
            ];

            $prevAdopted = $adopted;
        }

        return $funnel;
    }

    /**
     * Get the adoption count for a specific feature across all tracked users.
     *
     * This is a lightweight implementation that doesn't scan all cache keys.
     * For accurate global counts, use the EventWindowAggregator instead.
     *
     * @param  string  $featureName  Feature identifier
     * @param  list<string>  $userIds  User IDs to check
     * @return int Number of users who have adopted the feature
     */
    public function adoptionCount(string $featureName, array $userIds): int
    {
        $count = 0;
        foreach ($userIds as $uid) {
            if ($this->hasAdopted($uid, $featureName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the most recently adopted features for a user.
     *
     * @param  string  $userId  User ID
     * @param  int  $limit  Maximum features to return
     * @return list<array{feature: string, first_used: string, last_used: string, use_count: int}>
     */
    public function recentFeatures(string $userId, int $limit = 10): array
    {
        $profile = $this->getProfile($userId);
        $features = $profile['features'] ?? [];

        // Sort by last_used descending
        uasort($features, function (array $a, array $b): int {
            return strcmp($b['last_used'], $a['last_used']);
        });

        $result = [];
        $count = 0;
        foreach ($features as $name => $data) {
            $result[] = [
                'feature' => $name,
                'first_used' => $data['first_used'],
                'last_used' => $data['last_used'],
                'use_count' => $data['use_count'],
            ];
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Clear a user's adoption profile.
     *
     * @param  string  $userId  User ID
     */
    public function clearProfile(string $userId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $userId);
    }

    /**
     * Clear all adoption profiles (requires cache driver with prefix support).
     */
    public function clearAll(): void
    {
        // Implementation depends on cache driver
        // For Redis: use SCAN with zb_feat_adopt_ prefix
        // For file/array: iterate and delete matching keys
    }
}
