<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * SaaS Feature Usage Tracker — DAU/WAU/MAU, feature adoption lifecycle, and usage streaks.
 *
 * Tracks daily/weekly/monthly active user metrics per feature, computes usage streaks,
 * identifies power users, and provides feature adoption lifecycle analytics.
 * Integrates with the event pipeline to automatically aggregate feature usage data.
 *
 * @since 85.0.0
 */
final class SaaSFeatureUsageTrackerService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int<1, max> Cache TTL in seconds (default: 24 hours) */
    private int $ttl;

    /** @var string Cache prefix for feature usage data */
    private string $prefix = 'zb_saas_feature_usage:';

    /**
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  int  $ttl  Cache TTL in seconds
     */
    public function __construct(CacheRepository $cache, int $ttl = 86400){
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    /**
     * Record a feature usage event for a user.
     *
     * Increments the daily usage counter for the given user and feature,
     * updates streak data, and refreshes DAU/WAU/MAU sets.
     *
     * @param  string  $userId  Unique user identifier
     * @param  string  $featureName  Feature identifier (e.g. 'dashboard', 'api_export', 'team_invites')
     * @param  array<string, mixed>  $context  Additional context (plan, tenant, etc.)
     */
    public function recordUsage(string $userId, string $featureName, array $context = []): void
    {
        $today = $this->currentDate();
        $key = $this->usageKey($userId, $featureName, $today);

        $count = $this->cache->get($key, 0);
        $this->cache->put($key, $count + 1, $this->ttl);

        $this->addToActiveSet('dau', $today, $userId, $featureName);
        $this->addToActiveSet('wau', $this->weekKey($today), $userId, $featureName);
        $this->addToActiveSet('mau', $this->monthKey($today), $userId, $featureName);

        $this->updateStreak($userId, $featureName, $today);

        // Track global feature adoption count
        $adoptionKey = $this->prefix . 'adoption:' . $featureName;
        $adoptionUsers = $this->cache->get($adoptionKey, []);
        if (! in_array($userId, $adoptionUsers, true)) {
            $adoptionUsers[] = $userId;
            $this->cache->put($adoptionKey, $adoptionUsers, $this->ttl * 30); // 30 days
        }
    }

    /**
     * Get DAU (Daily Active Users) for a specific feature.
     *
     * @param  string|null  $featureName  Feature name, or null for all features
     * @param  string|null  $date  Date string (YYYY-MM-DD), or null for today
     * @return int<0, max>
     */
    public function dau(?string $featureName = null, ?string $date = null): int
    {
        $date = $date ?? $this->currentDate();

        if ($featureName !== null) {
            $setKey = $this->prefix . 'dau:' . $date . ':' . $featureName;

            return count($this->cache->get($setKey, []));
        }

        $prefix = $this->prefix . 'dau:' . $date . ':';
        $total = 0;
        $seen = [];

        // Scan common feature keys
        $features = $this->getKnownFeatures();
        foreach ($features as $feature) {
            $users = $this->cache->get($prefix . $feature, []);
            foreach ($users as $userId) {
                if (! in_array($userId, $seen, true)) {
                    $seen[] = $userId;
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * Get WAU (Weekly Active Users) for a specific feature.
     *
     * @param  string|null  $featureName  Feature name, or null for all features
     * @param  string|null  $weekKey  Week key, or null for current week
     * @return int<0, max>
     */
    public function wau(?string $featureName = null, ?string $weekKey = null): int
    {
        $weekKey = $weekKey ?? $this->weekKey($this->currentDate());

        if ($featureName !== null) {
            $setKey = $this->prefix . 'wau:' . $weekKey . ':' . $featureName;

            return count($this->cache->get($setKey, []));
        }

        $prefix = $this->prefix . 'wau:' . $weekKey . ':';
        $total = 0;
        $seen = [];

        foreach ($this->getKnownFeatures() as $feature) {
            $users = $this->cache->get($prefix . $feature, []);
            foreach ($users as $userId) {
                if (! in_array($userId, $seen, true)) {
                    $seen[] = $userId;
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * Get MAU (Monthly Active Users) for a specific feature.
     *
     * @param  string|null  $featureName  Feature name, or null for all features
     * @param  string|null  $monthKey  Month key (YYYY-MM), or null for current month
     * @return int<0, max>
     */
    public function mau(?string $featureName = null, ?string $monthKey = null): int
    {
        $monthKey = $monthKey ?? $this->monthKey($this->currentDate());

        if ($featureName !== null) {
            $setKey = $this->prefix . 'mau:' . $monthKey . ':' . $featureName;

            return count($this->cache->get($setKey, []));
        }

        $prefix = $this->prefix . 'mau:' . $monthKey . ':';
        $total = 0;
        $seen = [];

        foreach ($this->getKnownFeatures() as $feature) {
            $users = $this->cache->get($prefix . $feature, []);
            foreach ($users as $userId) {
                if (! in_array($userId, $seen, true)) {
                    $seen[] = $userId;
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * Get DAU/WAU/MAU stickiness ratio (DAU/WAU * 100).
     *
     * @param  string|null  $featureName  Feature name, or null for all features
     * @return float Stickiness percentage (0-100)
     */
    public function stickiness(?string $featureName = null): float
    {
        $dau = $this->dau($featureName);
        $wau = $this->wau($featureName);

        if ($wau === 0) {
            return 0.0;
        }

        return round(($dau / $wau) * 100, 2);
    }

    /**
     * Get current usage streak for a user and feature.
     *
     * @param  string  $userId  User identifier
     * @param  string  $featureName  Feature identifier
     * @return int<0, max> Current consecutive days of usage
     */
    public function streak(string $userId, string $featureName): int
    {
        $streakKey = $this->prefix . 'streak:' . $userId . ':' . $featureName;

        return (int) $this->cache->get($streakKey, 0);
    }

    /**
     * Get the longest usage streak for a user and feature.
     *
     * @param  string  $userId  User identifier
     * @param  string  $featureName  Feature identifier
     * @return int<0, max>
     */
    public function longestStreak(string $userId, string $featureName): int
    {
        $key = $this->prefix . 'longest_streak:' . $userId . ':' . $featureName;

        return (int) $this->cache->get($key, 0);
    }

    /**
     * Get power users — users with streak >= threshold for a feature.
     *
     * @param  string  $featureName  Feature identifier
     * @param  int  $minStreak  Minimum streak threshold
     * @return list<array{user_id: string, streak: int, longest: int}>
     */
    public function powerUsers(string $featureName, int $minStreak = 7): array
    {
        $result = [];
        $features = $this->getKnownFeatures();

        if (! in_array($featureName, $features, true)) {
            return $result;
        }

        $adoptionKey = $this->prefix . 'adoption:' . $featureName;
        $users = $this->cache->get($adoptionKey, []);

        foreach ($users as $userId) {
            $currentStreak = $this->streak($userId, $featureName);
            $longestStreak = $this->longestStreak($userId, $featureName);

            if ($currentStreak >= $minStreak || $longestStreak >= $minStreak) {
                $result[] = [
                    'user_id' => $userId,
                    'streak' => $currentStreak,
                    'longest' => $longestStreak,
                ];
            }
        }

        usort($result, fn (array $a, array $b): int => $b['streak'] <=> $a['streak']);

        return $result;
    }

    /**
     * Get feature usage summary dashboard.
     *
     * @return array{features: list<array{name: string, dau: int, wau: int, mau: int, stickiness: float, adoption_count: int, power_users: int}>}
     */
    public function dashboard(): array
    {
        $features = $this->getKnownFeatures();
        $result = [];

        foreach ($features as $feature) {
            $dau = $this->dau($feature);
            $wau = $this->wau($feature);
            $mau = $this->mau($feature);

            $result[] = [
                'name' => $feature,
                'dau' => $dau,
                'wau' => $wau,
                'mau' => $mau,
                'stickiness' => $wau > 0 ? round(($dau / $wau) * 100, 2) : 0.0,
                'adoption_count' => $this->adoptionCount($feature),
                'power_users' => count($this->powerUsers($feature, 7)),
            ];
        }

        usort($result, fn (array $a, array $b): int => $b['dau'] <=> $a['dau']);

        return ['features' => $result];
    }

    /**
     * Get feature adoption count — total unique users who have ever used a feature.
     *
     * @param  string  $featureName  Feature identifier
     * @return int<0, max>
     */
    public function adoptionCount(string $featureName): int
    {
        $adoptionKey = $this->prefix . 'adoption:' . $featureName;

        return count($this->cache->get($adoptionKey, []));
    }

    /**
     * Get feature adoption rate as a percentage of total tracked users.
     *
     * @param  string  $featureName  Feature identifier
     * @param  int  $totalUsers  Total registered users
     * @return float Adoption percentage (0-100)
     */
    public function adoptionRate(string $featureName, int $totalUsers): float
    {
        if ($totalUsers === 0) {
            return 0.0;
        }

        return round(($this->adoptionCount($featureName) / $totalUsers) * 100, 2);
    }

    /**
     * Get usage summary for a specific user across all features.
     *
     * @param  string  $userId  User identifier
     * @return list<array{feature: string, streak: int, longest_streak: int, last_used: string|null}>
     */
    public function userUsageProfile(string $userId): array
    {
        $features = $this->getKnownFeatures();
        $result = [];

        foreach ($features as $feature) {
            $streak = $this->streak($userId, $feature);
            $longestStreak = $this->longestStreak($userId, $feature);

            if ($streak > 0 || $longestStreak > 0) {
                $result[] = [
                    'feature' => $feature,
                    'streak' => $streak,
                    'longest_streak' => $longestStreak,
                    'last_used' => $this->lastUsedDate($userId, $feature),
                ];
            }
        }

        return $result;
    }

    /**
     * Get the most popular features sorted by DAU.
     *
     * @param  int  $limit  Maximum number of features to return
     * @return list<array{name: string, dau: int}>
     */
    public function topFeatures(int $limit = 10): array
    {
        $dashboard = $this->dashboard();

        return array_slice(
            array_map(fn (array $f): array => ['name' => $f['name'], 'dau' => $f['dau']], $dashboard['features']),
            0,
            $limit,
        );
    }

    /**
     * Get the least used features sorted by DAU ascending.
     *
     * @param  int  $limit  Maximum number of features to return
     * @return list<array{name: string, dau: int}>
     */
    public function leastUsedFeatures(int $limit = 10): array
    {
        $dashboard = $this->dashboard();

        $sorted = $dashboard['features'];
        usort($sorted, fn (array $a, array $b): int => $a['dau'] <=> $b['dau']);

        return array_slice(
            array_map(fn (array $f): array => ['name' => $f['name'], 'dau' => $f['dau']], $sorted),
            0,
            $limit,
        );
    }

    /**
     * Get overall SaaS engagement summary.
     *
     * @return array{total_features: int, global_dau: int, global_wau: int, global_mau: int, stickiness: float, most_popular: string|null}
     */
    public function engagementSummary(): array
    {
        $features = $this->getKnownFeatures();
        $top = $this->topFeatures(1);
        $globalDau = $this->dau();
        $globalWau = $this->wau();

        return [
            'total_features' => count($features),
            'global_dau' => $globalDau,
            'global_wau' => $globalWau,
            'global_mau' => $this->mau(),
            'stickiness' => $globalWau > 0 ? round(($globalDau / $globalWau) * 100, 2) : 0.0,
            'most_popular' => $top[0]['name'] ?? null,
        ];
    }

    // ─── Internal Helpers ──────────────────────────────────────────────────

    /**
     * Get the current date in YYYY-MM-DD format.
     */
    private function currentDate(): string
    {
        return date('Y-m-d');
    }

    /**
     * Get the week key for a given date (YYYY-WNN format).
     */
    private function weekKey(string $date): string
    {
        $ts = strtotime($date);

        return date('Y', $ts) . '-W' . date('W', $ts);
    }

    /**
     * Get the month key for a given date (YYYY-MM format).
     */
    private function monthKey(string $date): string
    {
        return substr($date, 0, 7);
    }

    /**
     * Build the daily usage cache key.
     */
    private function usageKey(string $userId, string $featureName, string $date): string
    {
        return $this->prefix . 'usage:' . $userId . ':' . $featureName . ':' . $date;
    }

    /**
     * Add a user to an active set (DAU/WAU/MAU).
     *
     * @param  'dau'|'wau'|'mau'  $type
     */
    private function addToActiveSet(string $type, string $periodKey, string $userId, string $featureName): void
    {
        $setKey = $this->prefix . $type . ':' . $periodKey . ':' . $featureName;
        $users = $this->cache->get($setKey, []);
        if (! in_array($userId, $users, true)) {
            $users[] = $userId;
            $this->cache->put($setKey, $users, $this->ttl);
        }
    }

    /**
     * Update the usage streak for a user and feature.
     */
    private function updateStreak(string $userId, string $featureName, string $today): void
    {
        $streakKey = $this->prefix . 'streak:' . $userId . ':' . $featureName;
        $lastUsedKey = $this->prefix . 'last_used:' . $userId . ':' . $featureName;

        $lastUsed = $this->cache->get($lastUsedKey, null);
        $currentStreak = (int) $this->cache->get($streakKey, 0);

        if ($lastUsed === null) {
            // First usage
            $this->cache->put($streakKey, 1, $this->ttl * 7);
            $this->cache->put($lastUsedKey, $today, $this->ttl * 7);

            $longestKey = $this->prefix . 'longest_streak:' . $userId . ':' . $featureName;
            $this->cache->put($longestKey, 1, $this->ttl * 30);
        } elseif ($lastUsed === $today) {
            // Already used today — streak unchanged
        } else {
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            if ($lastUsed === $yesterday) {
                // Consecutive day — increment streak
                $currentStreak++;
                $this->cache->put($streakKey, $currentStreak, $this->ttl * 7);

                $longestKey = $this->prefix . 'longest_streak:' . $userId . ':' . $featureName;
                $longestStreak = (int) $this->cache->get($longestKey, 0);
                if ($currentStreak > $longestStreak) {
                    $this->cache->put($longestKey, $currentStreak, $this->ttl * 30);
                }
            } else {
                // Streak broken — reset to 1
                $this->cache->put($streakKey, 1, $this->ttl * 7);
            }

            $this->cache->put($lastUsedKey, $today, $this->ttl * 7);
        }
    }

    /**
     * Get the last used date for a user and feature.
     */
    private function lastUsedDate(string $userId, string $featureName): ?string
    {
        $key = $this->prefix . 'last_used:' . $userId . ':' . $featureName;

        return $this->cache->get($key, null);
    }

    /**
     * Get known features from the adoption tracking keys.
     *
     * @return list<string>
     */
    private function getKnownFeatures(): array
    {
        // Track features from the engagement event catalog
        $knownFeatures = [
            'dashboard', 'api_export', 'team_invites', 'reporting', 'integrations',
            'search', 'file_download', 'onboarding', 'settings', 'billing',
            'feature_flags', 'webhooks', 'audit_log', 'notifications',
        ];

        return $knownFeatures;
    }
}
