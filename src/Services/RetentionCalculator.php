<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * N-Day Retention and Stickiness Calculator.
 *
 * Computes industry-standard retention metrics for SaaS products:
 *
 * - **N-Day Retention**: Percentage of users who return on day N after their
 *   first-seen date (day 0 = 100%). Standard intervals: D1, D3, D7, D14, D30.
 * - **Rolling Retention**: Percentage of users active within the last N days
 *   since their first-seen date (cumulative, always >= N-Day retention).
 * - **Stickiness**: Ratio of DAU to MAU (DAU/MAU). Higher = more engaged users.
 * - **Retention Curve**: Full retention curve data for cohort visualization.
 *
 * Uses cache-backed event tracking to avoid database dependencies.
 * Call `recordActivity()` on each event dispatch, then `retention()` / `stickiness()`
 * to compute metrics.
 *
 * Time bucket precision: 1 day (midnight UTC-aligned).
 *
 * @since 1.0.0
 */
final class RetentionCalculator
{
    private const FIRST_SEEN_KEY = 'zb_retention_first_seen_';
    private const ACTIVITY_KEY = 'zb_retention_activity_';
    private const DAILY_ACTIVE_KEY = 'zb_retention_dau_';
    private const STICKINESS_CACHE_KEY = 'zb_retention_stickiness_';
    private const COHORT_KEY = 'zb_retention_cohort_';

    private const DEFAULT_RETENTION_DAYS = [1, 3, 7, 14, 30];
    private const DEFAULT_TTL = 7776000; // 90 days (seconds)

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private bool $debug;

    private int $ttl;

    /** @var list<int> */
    private array $retentionDays;

    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $retentionConfig = $config->get('zeroboiler.analytics.retention', []);
        /** @var array{enabled?: bool, debug?: bool, ttl?: int, retention_days?: list<int>} $retentionConfig */
        $this->enabled = (bool) ($retentionConfig['enabled'] ?? true);
        $this->debug = (bool) ($retentionConfig['debug'] ?? false);
        $this->ttl = (int) ($retentionConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->retentionDays = $retentionConfig['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Record user activity for retention tracking.
     *
     * Call this on every tracked event. Updates first-seen date (if new user)
     * and records activity timestamp for the current day.
     *
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function recordActivity(?string $clientId, ?string $userId): void
    {
        if (! $this->enabled) {
            return;
        }

        // Use user_id if available, else client_id
        $identity = ($userId !== null && $userId !== '') ? $userId : $clientId;

        if ($identity === null || $identity === '') {
            return;
        }

        $today = $this->todayBucket();

        // Set first-seen date if this is a new identity
        $firstSeenKey = self::FIRST_SEEN_KEY . $identity;
        $existingFirstSeen = $this->cache->get($firstSeenKey);

        if ($existingFirstSeen === null) {
            $this->cache->put($firstSeenKey, $today, $this->ttl);

            if ($this->debug) {
                Log::debug('RetentionCalculator: new user first-seen', [
                    'identity' => $identity,
                    'date' => $today,
                ]);
            }
        }

        // Record activity for today (set-based, deduped by day)
        $activityKey = self::ACTIVITY_KEY . $identity . '_' . $today;
        $this->cache->put($activityKey, true, $this->ttl);

        // Update daily active users set for stickiness calculation
        $dauKey = self::DAILY_ACTIVE_KEY . $today;
        $dauSet = $this->cache->get($dauKey);
        $dauSet = is_array($dauSet) ? $dauSet : [];
        $dauSet[$identity] = true;
        $this->cache->put($dauKey, $dauSet, $this->ttl);
    }

    /**
     * Calculate N-Day retention for a specific cohort.
     *
     * Returns retention percentages for each configured day interval.
     *
     * @param  string|null  $cohortDate  YYYY-MM-DD format. Null = overall retention.
     * @return array{cohort_date: string|null, day0_users: int, retention: array<int, float>, period: string}
     */
    public function retention(?string $cohortDate = null): array
    {
        if (! $this->enabled) {
            return $this->emptyRetentionResult($cohortDate);
        }

        // Get users who first appeared on the cohort date (or all users)
        $users = $cohortDate !== null
            ? $this->getFirstSeenUsers($cohortDate)
            : $this->getAllFirstSeenUsers();

        $totalUsers = count($users);

        if ($totalUsers === 0) {
            return $this->emptyRetentionResult($cohortDate);
        }

        $retention = [];
        $today = $this->todayBucket();
        $cohortTs = $cohortDate !== null
            ? strtotime($cohortDate)
            : 0;

        foreach ($this->retentionDays as $day) {
            $targetDate = $cohortDate !== null
                ? date('Y-m-d', strtotime($cohortDate) + ($day * 86400))
                : null;

            if ($targetDate !== null && strtotime($targetDate) > strtotime($today)) {
                // Target date is in the future
                $retention[$day] = null;
                continue;
            }

            // Count users active on day N
            $activeCount = $this->countActiveOnDay($users, $targetDate);
            $retention[$day] = $totalUsers > 0 ? round(($activeCount / $totalUsers) * 100, 2) : 0.0;
        }

        return [
            'cohort_date' => $cohortDate,
            'day0_users' => $totalUsers,
            'retention' => $retention,
            'period' => $cohortDate ?? 'overall',
        ];
    }

    /**
     * Calculate rolling retention (cumulative — users active within last N days).
     *
     * Unlike N-Day retention, rolling retention counts users who were active
     * at any point between day 0 and day N (inclusive).
     *
     * @param  string  $cohortDate  YYYY-MM-DD format
     * @param  int  $withinDays  Rolling window (default: 30)
     * @return array{cohort_date: string, within_days: int, day0_users: int, retained_users: int, rate: float}
     */
    public function rollingRetention(string $cohortDate, int $withinDays = 30): array
    {
        if (! $this->enabled) {
            return $this->emptyRollingResult($cohortDate, $withinDays);
        }

        $users = $this->getFirstSeenUsers($cohortDate);
        $totalUsers = count($users);

        if ($totalUsers === 0) {
            return $this->emptyRollingResult($cohortDate, $withinDays);
        }

        $startDate = strtotime($cohortDate);
        $endDate = $startDate + ($withinDays * 86400);
        $todayTs = strtotime($this->todayBucket());
        $endDate = min($endDate, $todayTs);

        $retainedCount = 0;

        foreach ($users as $userIdentity) {
            if ($this->wasActiveBetween($userIdentity, $cohortDate, date('Y-m-d', $endDate))) {
                $retainedCount++;
            }
        }

        return [
            'cohort_date' => $cohortDate,
            'within_days' => $withinDays,
            'day0_users' => $totalUsers,
            'retained_users' => $retainedCount,
            'rate' => $totalUsers > 0 ? round(($retainedCount / $totalUsers) * 100, 2) : 0.0,
        ];
    }

    /**
     * Calculate stickiness (DAU / MAU ratio).
     *
     * Stickiness indicates how engaging the product is. Values:
     * - < 10%: Low engagement (most users are occasional)
     * - 10-25%: Moderate engagement
     * - 25-50%: High engagement (daily habit)
     * - > 50%: Very high (essential tool)
     *
     * @param  string|null  $referenceDate  YYYY-MM-DD. Null = today.
     * @return array{reference_date: string, dau: int, wau: int, mau: int, dau_wau_ratio: float, dau_mau_ratio: float, wau_mau_ratio: float, grade: string}
     */
    public function stickiness(?string $referenceDate = null): array
    {
        if (! $this->enabled) {
            return $this->emptyStickinessResult($referenceDate ?? $this->todayBucket());
        }

        $referenceDate = $referenceDate ?? $this->todayBucket();
        $refTs = strtotime($referenceDate);

        // DAU: users active on reference date
        $dauUsers = $this->getDailyActiveUsers($referenceDate);
        $dau = count($dauUsers);

        // WAU: users active in last 7 days (inclusive)
        $wauUsers = $this->getActiveUsersBetween(
            date('Y-m-d', $refTs - (6 * 86400)),
            $referenceDate,
        );
        $wau = count($wauUsers);

        // MAU: users active in last 30 days (inclusive)
        $mauUsers = $this->getActiveUsersBetween(
            date('Y-m-d', $refTs - (29 * 86400)),
            $referenceDate,
        );
        $mau = count($mauUsers);

        $dauWau = $wau > 0 ? round(($dau / $wau) * 100, 2) : 0.0;
        $dauMau = $mau > 0 ? round(($dau / $mau) * 100, 2) : 0.0;
        $wauMau = $mau > 0 ? round(($wau / $mau) * 100, 2) : 0.0;

        // Grade
        $grade = match (true) {
            $dauMau >= 50 => 'A+ (Essential)',
            $dauMau >= 25 => 'A (Daily Habit)',
            $dauMau >= 15 => 'B (High Engagement)',
            $dauMau >= 10 => 'C (Moderate)',
            $dauMau >= 5 => 'D (Low)',
            default => 'F (Occasional)',
        };

        return [
            'reference_date' => $referenceDate,
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'dau_wau_ratio' => $dauWau,
            'dau_mau_ratio' => $dauMau,
            'wau_mau_ratio' => $wauMau,
            'grade' => $grade,
        ];
    }

    /**
     * Generate a full retention curve for cohort visualization.
     *
     * Returns day-by-day retention data for a range of days.
     * Useful for rendering retention curve charts.
     *
     * @param  string  $cohortDate  YYYY-MM-DD format
     * @param  int  $maxDays  Number of days to compute (default: 30)
     * @return array{cohort_date: string, day0_users: int, curve: array<int, array{day: int, date: string, retained: int, rate: float|null}>}
     */
    public function retentionCurve(string $cohortDate, int $maxDays = 30): array
    {
        if (! $this->enabled) {
            return [
                'cohort_date' => $cohortDate,
                'day0_users' => 0,
                'curve' => [],
            ];
        }

        $users = $this->getFirstSeenUsers($cohortDate);
        $totalUsers = count($users);
        $today = $this->todayBucket();

        $curve = [];

        // Day 0 = cohort date (always 100%)
        $curve[] = [
            'day' => 0,
            'date' => $cohortDate,
            'retained' => $totalUsers,
            'rate' => 100.0,
        ];

        for ($day = 1; $day <= $maxDays; $day++) {
            $targetDate = date('Y-m-d', strtotime($cohortDate) + ($day * 86400));

            if (strtotime($targetDate) > strtotime($today)) {
                $curve[] = [
                    'day' => $day,
                    'date' => $targetDate,
                    'retained' => 0,
                    'rate' => null, // Future date
                ];

                continue;
            }

            $activeCount = $this->countActiveOnDay($users, $targetDate);
            $rate = $totalUsers > 0 ? round(($activeCount / $totalUsers) * 100, 2) : 0.0;

            $curve[] = [
                'day' => $day,
                'date' => $targetDate,
                'retained' => $activeCount,
                'rate' => $rate,
            ];
        }

        return [
            'cohort_date' => $cohortDate,
            'day0_users' => $totalUsers,
            'curve' => $curve,
        ];
    }

    /**
     * Get retention summary across multiple cohorts.
     *
     * Compares retention rates across the last N days of new users.
     *
     * @param  int  $cohortDays  Number of cohort days to compare (default: 7)
     * @return array{cohorts: array<string, array{day0_users: int, retention: array<int, float|null>}}, averages: array<int, float|null>}
     */
    public function cohortComparison(int $cohortDays = 7): array
    {
        if (! $this->enabled) {
            return ['cohorts' => [], 'averages' => []];
        }

        $today = strtotime($this->todayBucket());
        $cohorts = [];
        $sums = [];
        $counts = [];

        for ($i = 0; $i < $cohortDays; $i++) {
            $date = date('Y-m-d', $today - ($i * 86400));
            $data = $this->retention($date);

            if ($data['day0_users'] > 0) {
                $cohorts[$date] = $data;

                foreach ($data['retention'] as $day => $rate) {
                    if ($rate !== null) {
                        $sums[$day] = ($sums[$day] ?? 0) + $rate;
                        $counts[$day] = ($counts[$day] ?? 0) + 1;
                    }
                }
            }
        }

        // Calculate averages
        $averages = [];
        foreach ($sums as $day => $sum) {
            $averages[$day] = round($sum / $counts[$day], 2);
        }

        return [
            'cohorts' => $cohorts,
            'averages' => $averages,
        ];
    }

    /**
     * Check if the retention calculator is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured retention day intervals.
     *
     * @return list<int>
     */
    public function retentionDays(): array
    {
        return $this->retentionDays;
    }

    /**
     * Get today's date bucket (YYYY-MM-DD UTC).
     */
    private function todayBucket(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * Get all identities that first appeared on a specific date.
     *
     * Uses the first-seen index to find cohort members.
     *
     * @param  string  $date  YYYY-MM-DD format
     * @return list<string>  Identity keys
     */
    private function getFirstSeenUsers(string $date): array
    {
        $cohortKey = self::COHORT_KEY . $date;
        $cohort = $this->cache->get($cohortKey);

        if (is_array($cohort)) {
            return array_keys($cohort);
        }

        return [];
    }

    /**
     * Get all identities with a first-seen date (for overall retention).
     *
     * @return list<string>
     */
    private function getAllFirstSeenUsers(): array
    {
        // Aggregate from recent cohorts (last 30 days)
        $today = strtotime($this->todayBucket());
        $allUsers = [];

        for ($i = 0; $i < 30; $i++) {
            $date = date('Y-m-d', $today - ($i * 86400));
            $cohort = $this->getFirstSeenUsers($date);
            $allUsers = array_merge($allUsers, $cohort);
        }

        return array_unique($allUsers);
    }

    /**
     * Count how many users from a list were active on a specific day.
     *
     * @param  list<string>  $users  Identity keys
     * @param  string|null  $date  YYYY-MM-DD format
     * @return int
     */
    private function countActiveOnDay(array $users, ?string $date): int
    {
        if ($date === null) {
            return 0;
        }

        $count = 0;

        foreach ($users as $userIdentity) {
            $activityKey = self::ACTIVITY_KEY . $userIdentity . '_' . $date;
            $active = $this->cache->get($activityKey);

            if ($active !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a user was active between two dates (inclusive).
     *
     * @param  string  $identity  User identity
     * @param  string  $startDate  YYYY-MM-DD
     * @param  string  $endDate  YYYY-MM-DD
     */
    private function wasActiveBetween(string $identity, string $startDate, string $endDate): bool
    {
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $activityKey = self::ACTIVITY_KEY . $identity . '_' . $date;
            $active = $this->cache->get($activityKey);

            if ($active !== null) {
                return true;
            }

            $current += 86400;
        }

        return false;
    }

    /**
     * Get daily active users for a specific date.
     *
     * @param  string  $date  YYYY-MM-DD format
     * @return array<string, bool>
     */
    private function getDailyActiveUsers(string $date): array
    {
        $dauSet = $this->cache->get(self::DAILY_ACTIVE_KEY . $date);

        return is_array($dauSet) ? $dauSet : [];
    }

    /**
     * Get active users between two dates (inclusive).
     *
     * @param  string  $startDate  YYYY-MM-DD
     * @param  string  $endDate  YYYY-MM-DD
     * @return array<string, bool>
     */
    private function getActiveUsersBetween(string $startDate, string $endDate): array
    {
        $merged = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $dau = $this->getDailyActiveUsers($date);
            $merged = array_merge($merged, $dau);
            $current += 86400;
        }

        return $merged;
    }

    /**
     * Return an empty retention result.
     *
     * @param  string|null  $cohortDate
     * @return array{cohort_date: string|null, day0_users: int, retention: array<int, float>, period: string}
     */
    private function emptyRetentionResult(?string $cohortDate): array
    {
        $retention = [];
        foreach ($this->retentionDays as $day) {
            $retention[$day] = 0.0;
        }

        return [
            'cohort_date' => $cohortDate,
            'day0_users' => 0,
            'retention' => $retention,
            'period' => $cohortDate ?? 'overall',
        ];
    }

    /**
     * Return an empty rolling retention result.
     *
     * @return array{cohort_date: string, within_days: int, day0_users: int, retained_users: int, rate: float}
     */
    private function emptyRollingResult(string $cohortDate, int $withinDays): array
    {
        return [
            'cohort_date' => $cohortDate,
            'within_days' => $withinDays,
            'day0_users' => 0,
            'retained_users' => 0,
            'rate' => 0.0,
        ];
    }

    /**
     * Return an empty stickiness result.
     *
     * @return array{reference_date: string, dau: int, wau: int, mau: int, dau_wau_ratio: float, dau_mau_ratio: float, wau_mau_ratio: float, grade: string}
     */
    private function emptyStickinessResult(string $referenceDate): array
    {
        return [
            'reference_date' => $referenceDate,
            'dau' => 0,
            'wau' => 0,
            'mau' => 0,
            'dau_wau_ratio' => 0.0,
            'dau_mau_ratio' => 0.0,
            'wau_mau_ratio' => 0.0,
            'grade' => 'N/A',
        ];
    }
}
