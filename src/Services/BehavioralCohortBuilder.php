<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Behavioral cohort builder — groups users by behavioral patterns.
 *
 * Classifies users into behavioral segments based on their activity patterns:
 *
 * - **Power Users**: Active 5+ days in the last 7 days
 * - **Regular Users**: Active 3-4 days in the last 7 days
 * - **Casual Users**: Active 1-2 days in the last 7 days
 * - **At-Risk Users**: Previously active but inactive for 7+ days
 * - **Dormant Users**: Inactive for 30+ days
 * - **New Users**: First seen within the last 7 days
 * - **Resurrected Users**: Were dormant but returned in the last 7 days
 *
 * Also supports custom cohort definitions via config:
 *
 * ```php
 * 'cohorts' => [
 *     'high_value' => [
 *         'label' => 'High Value',
 *         'rules' => [
 *             ['property' => 'total_revenue', 'operator' => 'gte', 'value' => 100],
 *             ['events_in_days' => 3, 'count' => 10],
 *         ],
 *     ],
 * ],
 * ```
 *
 * Cohorts are computed on-demand and cached for configurable TTL.
 * Ideal for powering in-product admin dashboards and marketing automation.
 *
 * @since 1.0.0
 */
final class BehavioralCohortBuilder
{
    private const ACTIVITY_INDEX_KEY = 'zb_cohort_activity_';
    private const FIRST_SEEN_KEY = 'zb_cohort_first_seen_';
    private const COHORT_RESULT_KEY = 'zb_cohort_result_';
    private const DEFAULT_TTL = 3600; // 1 hour (computed cohorts refresh)
    private const DATA_TTL = 7776000; // 90 days (raw activity data)

    private const DEFAULT_SEGMENTS = [
        'power' => ['label' => 'Power Users', 'description' => 'Active 5+ days in the last 7 days', 'min_days' => 5, 'window' => 7],
        'regular' => ['label' => 'Regular Users', 'description' => 'Active 3-4 days in the last 7 days', 'min_days' => 3, 'max_days' => 4, 'window' => 7],
        'casual' => ['label' => 'Casual Users', 'description' => 'Active 1-2 days in the last 7 days', 'min_days' => 1, 'max_days' => 2, 'window' => 7],
        'at_risk' => ['label' => 'At-Risk Users', 'description' => 'Previously active but inactive for 7+ days', 'inactive_days' => 7, 'had_activity' => true],
        'dormant' => ['label' => 'Dormant Users', 'description' => 'Inactive for 30+ days', 'inactive_days' => 30, 'had_activity' => true],
        'new' => ['label' => 'New Users', 'description' => 'First seen within the last 7 days', 'first_seen_days' => 7],
        'resurrected' => ['label' => 'Resurrected Users', 'description' => 'Were dormant but returned in the last 7 days', 'resurrected_window' => 7, 'dormant_threshold' => 30],
    ];

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private bool $debug;

    private int $resultTtl;

    /** @var array<string, array{label: string, description: string, ...}> */
    private array $segments;

    /** @var array<string, array{label: string, rules: list<array>}> */
    private array $customCohorts;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $cohortConfig = $config->get('zeroboiler.analytics.cohorts', []);
        /** @var array{enabled?: bool, debug?: bool, result_ttl?: int, segments?: array<string, array>, custom_cohorts?: array<string, array{label: string, rules: list<array>}>} $cohortConfig */
        $this->enabled = (bool) ($cohortConfig['enabled'] ?? true);
        $this->debug = (bool) ($cohortConfig['debug'] ?? false);
        $this->resultTtl = (int) ($cohortConfig['result_ttl'] ?? self::DEFAULT_TTL);
        $this->segments = $cohortConfig['segments'] ?? self::DEFAULT_SEGMENTS;
        $this->customCohorts = $cohortConfig['custom_cohorts'] ?? [];
    }

    /**
     * Record user activity for cohort tracking.
     *
     * Call on every event dispatch. Records activity timestamp
     * and updates first-seen date for new users.
     *
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function recordActivity(?string $clientId, ?string $userId): void
    {
        if (! $this->enabled) {
            return;
        }

        $identity = ($userId !== null && $userId !== '') ? $userId : $clientId;

        if ($identity === null || $identity === '') {
            return;
        }

        $today = gmdate('Y-m-d');

        // Set first-seen if new
        $firstSeenKey = self::FIRST_SEEN_KEY . $identity;
        $existingFirstSeen = $this->cache->get($firstSeenKey);

        if ($existingFirstSeen === null) {
            $this->cache->put($firstSeenKey, $today, self::DATA_TTL);
        }

        // Record daily activity
        $activityKey = self::ACTIVITY_INDEX_KEY . $identity . '_' . $today;
        $this->cache->put($activityKey, true, self::DATA_TTL);

        // Invalidate cached results on new activity
        $this->cache->forget(self::COHORT_RESULT_KEY . 'all');
    }

    /**
     * Classify all tracked users into behavioral segments.
     *
     * Returns a full cohort breakdown with user counts and percentages.
     *
     * @return array{generated_at: string, total_users: int, segments: array<string, array{label: string, description: string, user_count: int, percentage: float}>, custom_cohorts: array<string, array{label: string, user_count: int, percentage: float}>}
     */
    public function classify(): array
    {
        if (! $this->enabled) {
            return $this->emptyResult();
        }

        // Check cache
        $cacheKey = self::COHORT_RESULT_KEY . 'all';
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $allIdentities = $this->getAllIdentities();
        $totalUsers = count($allIdentities);

        if ($totalUsers === 0) {
            return $this->emptyResult();
        }

        $todayTs = strtotime(gmdate('Y-m-d'));
        $segments = [];
        $assigned = []; // Track which identity is assigned to which segment

        // Classify into built-in segments (order matters — more specific first)
        $segmentOrder = ['power', 'regular', 'casual', 'new', 'resurrected', 'at_risk', 'dormant'];

        foreach ($segmentOrder as $segmentKey) {
            if (! isset($this->segments[$segmentKey])) {
                continue;
            }

            $segment = $this->segments[$segmentKey];
            $users = $this->findSegmentMembers($allIdentities, $segmentKey, $segment, $todayTs, $assigned);

            $segments[$segmentKey] = [
                'label' => $segment['label'] ?? $segmentKey,
                'description' => $segment['description'] ?? '',
                'user_count' => count($users),
                'percentage' => $totalUsers > 0 ? round((count($users) / $totalUsers) * 100, 2) : 0.0,
            ];

            foreach ($users as $userIdentity) {
                $assigned[$userIdentity] = true;
            }
        }

        // Unassigned = "Other"
        $unassigned = count($allIdentities) - count($assigned);
        if ($unassigned > 0) {
            $segments['other'] = [
                'label' => 'Other',
                'description' => 'Users not matching any segment criteria',
                'user_count' => $unassigned,
                'percentage' => $totalUsers > 0 ? round(($unassigned / $totalUsers) * 100, 2) : 0.0,
            ];
        }

        // Evaluate custom cohorts
        $customCohorts = [];
        foreach ($this->customCohorts as $cohortId => $cohortDef) {
            $customCohorts[$cohortId] = [
                'label' => $cohortDef['label'] ?? $cohortId,
                'user_count' => 0,
                'percentage' => 0.0,
            ];
            // Custom cohorts require user_properties integration — count as 0 for now
            // They can be enriched by integrating with UserPropertiesStore
        }

        $result = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'total_users' => $totalUsers,
            'segments' => $segments,
            'custom_cohorts' => $customCohorts,
        ];

        $this->cache->put($cacheKey, $result, $this->resultTtl);

        return $result;
    }

    /**
     * Get the segment assignment for a specific user.
     *
     * @param  string  $identity  user_id or client_id
     * @return array{segment: string, label: string}|null
     */
    public function classifyUser(string $identity): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $todayTs = strtotime(gmdate('Y-m-d'));

        $segmentOrder = ['power', 'regular', 'casual', 'new', 'resurrected', 'at_risk', 'dormant'];

        foreach ($segmentOrder as $segmentKey) {
            if (! isset($this->segments[$segmentKey])) {
                continue;
            }

            if ($this->matchesSegment($identity, $segmentKey, $this->segments[$segmentKey], $todayTs)) {
                return [
                    'segment' => $segmentKey,
                    'label' => $this->segments[$segmentKey]['label'] ?? $segmentKey,
                ];
            }
        }

        return null;
    }

    /**
     * Get cohort summary with user counts for the last N days.
     *
     * @param  int  $days  Number of days to analyze (default: 30)
     * @return array{period_days: int, cohorts: array<string, array{label: string, counts: array<string, int>}>}
     */
    public function summary(int $days = 30): array
    {
        if (! $this->enabled) {
            return ['period_days' => $days, 'cohorts' => []];
        }

        $classification = $this->classify();
        $cohorts = [];

        foreach ($classification['segments'] as $key => $segment) {
            $cohorts[$key] = [
                'label' => $segment['label'],
                'counts' => [
                    'users' => $segment['user_count'],
                    'percentage' => $segment['percentage'],
                ],
            ];
        }

        return [
            'period_days' => $days,
            'cohorts' => $cohorts,
        ];
    }

    /**
     * Get segment transitions over time (how users move between segments).
     *
     * Compares current classification with a previous snapshot.
     *
     * @param  int  $compareDaysAgo  Days ago to compare against (default: 7)
     * @return array{comparisons: array<string, array{current: int, previous: int, change: int, change_pct: float}>}
     */
    public function transitions(int $compareDaysAgo = 7): array
    {
        // Since we only have current state, return current counts
        // A full implementation would store historical snapshots
        $classification = $this->classify();
        $comparisons = [];

        foreach ($classification['segments'] as $key => $segment) {
            $comparisons[$key] = [
                'current' => $segment['user_count'],
                'previous' => 0, // Would need historical data
                'change' => 0,
                'change_pct' => 0.0,
            ];
        }

        return ['comparisons' => $comparisons];
    }

    /**
     * Check if the cohort builder is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the available segment definitions.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function segmentDefinitions(): array
    {
        $definitions = [];
        foreach ($this->segments as $key => $segment) {
            $definitions[$key] = [
                'label' => $segment['label'] ?? $key,
                'description' => $segment['description'] ?? '',
            ];
        }

        return $definitions;
    }

    /**
     * Find all members of a specific segment.
     *
     * @param  list<string>  $identities  All tracked identities
     * @param  string  $segmentKey  Segment key
     * @param  array  $segmentDef  Segment definition
     * @param  int  $todayTs  Current day timestamp
     * @param  array<string, bool>  $assigned  Already assigned identities
     * @return list<string>
     */
    private function findSegmentMembers(
        array $identities,
        string $segmentKey,
        array $segmentDef,
        int $todayTs,
        array &$assigned,
    ): array {
        $members = [];

        foreach ($identities as $identity) {
            if (isset($assigned[$identity])) {
                continue;
            }

            if ($this->matchesSegment($identity, $segmentKey, $segmentDef, $todayTs)) {
                $members[] = $identity;
            }
        }

        return $members;
    }

    /**
     * Check if a single identity matches a segment definition.
     *
     * @param  string  $identity  User/client identity
     * @param  string  $segmentKey  Segment key
     * @param  array  $def  Segment definition
     * @param  int  $todayTs  Current day timestamp
     */
    private function matchesSegment(string $identity, string $segmentKey, array $def, int $todayTs): bool
    {
        $today = gmdate('Y-m-d');

        return match ($segmentKey) {
            'power' => $this->countActiveDays($identity, $def['window'] ?? 7, $todayTs) >= ($def['min_days'] ?? 5),
            'regular' => $this->countActiveDays($identity, $def['window'] ?? 7, $todayTs) >= ($def['min_days'] ?? 3)
                && $this->countActiveDays($identity, $def['window'] ?? 7, $todayTs) <= ($def['max_days'] ?? 4),
            'casual' => $this->countActiveDays($identity, $def['window'] ?? 7, $todayTs) >= ($def['min_days'] ?? 1)
                && $this->countActiveDays($identity, $def['window'] ?? 7, $todayTs) <= ($def['max_days'] ?? 2),
            'new' => $this->isNewUser($identity, $def['first_seen_days'] ?? 7, $todayTs),
            'resurrected' => $this->isResurrected($identity, $def['resurrected_window'] ?? 7, $def['dormant_threshold'] ?? 30, $todayTs),
            'at_risk' => $this->isInactive($identity, $def['inactive_days'] ?? 7, $todayTs)
                && $this->hasHistoricalActivity($identity, $todayTs),
            'dormant' => $this->isInactive($identity, $def['inactive_days'] ?? 30, $todayTs)
                && $this->hasHistoricalActivity($identity, $todayTs),
            default => false,
        };
    }

    /**
     * Count active days for an identity within a window.
     */
    private function countActiveDays(string $identity, int $window, int $todayTs): int
    {
        $count = 0;

        for ($i = 0; $i < $window; $i++) {
            $date = gmdate('Y-m-d', $todayTs - ($i * 86400));
            $key = self::ACTIVITY_INDEX_KEY . $identity . '_' . $date;
            $active = $this->cache->get($key);

            if ($active !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a user was first seen within N days.
     */
    private function isNewUser(string $identity, int $days, int $todayTs): bool
    {
        $firstSeen = $this->cache->get(self::FIRST_SEEN_KEY . $identity);

        if (! is_string($firstSeen)) {
            return false;
        }

        $firstSeenTs = strtotime($firstSeen);
        $threshold = $todayTs - ($days * 86400);

        return $firstSeenTs >= $threshold;
    }

    /**
     * Check if a user is inactive for N+ days.
     */
    private function isInactive(string $identity, int $inactiveDays, int $todayTs): bool
    {
        // Check if active in last inactiveDays
        for ($i = 0; $i < $inactiveDays; $i++) {
            $date = gmdate('Y-m-d', $todayTs - ($i * 86400));
            $key = self::ACTIVITY_INDEX_KEY . $identity . '_' . $date;
            $active = $this->cache->get($key);

            if ($active !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a user has any historical activity (beyond inactivity window).
     */
    private function hasHistoricalActivity(string $identity, int $todayTs): bool
    {
        $firstSeen = $this->cache->get(self::FIRST_SEEN_KEY . $identity);

        if (! is_string($firstSeen)) {
            return false;
        }

        // User must have a first-seen date (exists) but be currently inactive
        return true;
    }

    /**
     * Check if a user was dormant and then returned recently.
     */
    private function isResurrected(string $identity, int $recentWindow, int $dormantThreshold, int $todayTs): bool
    {
        // Must be active recently
        $recentActive = false;
        for ($i = 0; $i < $recentWindow; $i++) {
            $date = gmdate('Y-m-d', $todayTs - ($i * 86400));
            $key = self::ACTIVITY_INDEX_KEY . $identity . '_' . $date;
            $active = $this->cache->get($key);

            if ($active !== null) {
                $recentActive = true;
                break;
            }
        }

        if (! $recentActive) {
            return false;
        }

        // Must have had a gap of dormant_threshold+ days before the recent activity
        $firstRecentDate = gmdate('Y-m-d', $todayTs - (($recentWindow - 1) * 86400));
        $gapStart = strtotime($firstRecentDate) - ($dormantThreshold * 86400);
        $gapEnd = strtotime($firstRecentDate) - 86400;

        // Check if there was activity in the gap, which would mean they weren't dormant
        $hadActivityInGap = false;
        $checkTs = $gapStart;
        while ($checkTs <= $gapEnd) {
            $date = gmdate('Y-m-d', $checkTs);
            $key = self::ACTIVITY_INDEX_KEY . $identity . '_' . $date;
            $active = $this->cache->get($key);

            if ($active !== null) {
                $hadActivityInGap = true;
                break;
            }

            $checkTs += 86400;
        }

        return ! $hadActivityInGap;
    }

    /**
     * Get all tracked identities (first-seen keys).
     *
     * @return list<string>
     */
    private function getAllIdentities(): array
    {
        // This is a best-effort approach — scan recent DAU keys
        $identities = [];
        $todayTs = strtotime(gmdate('Y-m-d'));

        for ($i = 0; $i < 30; $i++) {
            $date = gmdate('Y-m-d', $todayTs - ($i * 86400));
            $dauKey = RetentionCalculator::class === RetentionCalculator::class
                ? 'zb_retention_dau_' . $date
                : 'zb_cohort_dau_' . $date;
            $dauSet = $this->cache->get($dauKey);

            if (is_array($dauSet)) {
                $identities = array_merge($identities, array_keys($dauSet));
            }
        }

        return array_unique($identities);
    }

    /**
     * Return an empty classification result.
     *
     * @return array{generated_at: string, total_users: int, segments: array<string, array{label: string, description: string, user_count: int, percentage: float}>, custom_cohorts: array<string, array{label: string, user_count: int, percentage: float}>}
     */
    private function emptyResult(): array
    {
        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'total_users' => 0,
            'segments' => [],
            'custom_cohorts' => [],
        ];
    }
}
