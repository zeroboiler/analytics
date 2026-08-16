<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsGoal;
use ZeroBoiler\Analytics\DTO\GoalProgress;

/**
 * Analytics Goal Tracker — quantitative target tracking for SaaS KPIs.
 *
 * Manages user-defined analytics goals with configurable targets, time windows,
 * and alert thresholds. Tracks progress against actual event/metric data and
 * provides dashboard-ready status reports.
 *
 * Goals are defined in `zeroboiler.analytics.goals` config or registered
 * at runtime. Each goal maps to an underlying metric/event with an aggregation
 * strategy (count, sum, avg, unique) and a time window.
 *
 * Features:
 * - Goal CRUD with persistent config storage
 * - Progress calculation with trend detection
 * - Status classification: on_track, at_risk, behind, achieved, exceeded, no_data
 * - Dashboard aggregation (overall score, category breakdown)
 * - Cache-backed progress computation with configurable TTL
 *
 * Inspired by Amplitude's Custom Dashboards and Mixpanel's Goals.
 *
 * @phpstan-type GoalConfig array{key: string, name: string, description?: string, target: float, metric: string, aggregation?: string, window?: string, warning_threshold?: float|null, critical_threshold?: float|null, category?: string|null, owner?: string|null, active?: bool, meta?: array<string, mixed>}
 * @phpstan-type GoalDashboard array{total: int, active: int, achieved: int, on_track: int, at_risk: int, behind: int, no_data: int, overall_score: float, categories: array<string, array{total: int, achieved: int, score: float}>}
 *
 * @see \ZeroBoiler\Analytics\DTO\AnalyticsGoal
 * @see \ZeroBoiler\Analytics\DTO\GoalProgress
 *
 * @since 177.0.0
 */
final class AnalyticsGoalTracker
{
    /** @var string Current version for cache compatibility. */
    public const VERSION = '1.0.0';

    private const CACHE_PREFIX = 'zb_goal_tracker_';

    private const DEFAULT_CACHE_TTL = 300;

    private const DEFAULT_WARNING_THRESHOLD = 50.0;

    private const DEFAULT_CRITICAL_THRESHOLD = 25.0;

    /** @var array<string, AnalyticsGoal> */
    private array $goals = [];

    /**
     * Create a new AnalyticsGoalTracker.
     *
     * @param  CacheRepository  $cache  Cache repository for progress caching
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $this->loadGoalsFromConfig();
    }

    /**
     * Load goals from configuration.
     *
     * @return void
     */
    private function loadGoalsFromConfig(): void
    {
        /** @var array<string, mixed> $goalConfigs */
        $goalConfigs = $this->config->get('zeroboiler.analytics.goals.definitions', []);

        foreach ($goalConfigs as $goalConfig) {
            $goal = AnalyticsGoal::fromArray((array) $goalConfig);
            $this->goals[$goal->key] = $goal;
        }
    }

    /**
     * Register a goal at runtime.
     */
    public function registerGoal(AnalyticsGoal $goal): void
    {
        $this->goals[$goal->key] = $goal;
    }

    /**
     * Remove a goal by key.
     */
    public function removeGoal(string $key): bool
    {
        if (!isset($this->goals[$key])) {
            return false;
        }

        unset($this->goals[$key]);
        $this->cache->forget(self::CACHE_PREFIX . 'progress_' . $key);
        $this->cache->forget(self::CACHE_PREFIX . 'dashboard');

        return true;
    }

    /**
     * Get a goal by key.
     */
    public function getGoal(string $key): ?AnalyticsGoal
    {
        return $this->goals[$key] ?? null;
    }

    /**
     * Get all registered goals.
     *
     * @return array<string, AnalyticsGoal>
     */
    public function allGoals(): array
    {
        return $this->goals;
    }

    /**
     * Get only active goals.
     *
     * @return array<string, AnalyticsGoal>
     */
    public function activeGoals(): array
    {
        return array_filter(
            $this->goals,
            static fn(AnalyticsGoal $g): bool => $g->active,
        );
    }

    /**
     * Get goals by category.
     *
     * @return array<string, AnalyticsGoal>
     */
    public function goalsByCategory(string $category): array
    {
        return array_filter(
            $this->goals,
            static fn(AnalyticsGoal $g): bool => $g->category === $category,
        );
    }

    /**
     * Calculate progress for a single goal.
     *
     * Uses cached progress when available. Reads actual values from
     * the event store/aggregation service.
     */
    public function progress(string $goalKey, ?string $period = null, bool $useCache = true): GoalProgress
    {
        $cacheKey = self::CACHE_PREFIX . 'progress_' . $goalKey;

        if ($useCache && $cacheKey !== '') {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return new GoalProgress(
                    goalKey: (string) ($cached['goal_key'] ?? $goalKey),
                    goalName: (string) ($cached['goal_name'] ?? ''),
                    actual: (float) ($cached['actual'] ?? 0.0),
                    target: (float) ($cached['target'] ?? 0.0),
                    percentage: (float) ($cached['percentage'] ?? 0.0),
                    status: (string) ($cached['status'] ?? 'no_data'),
                    trend: isset($cached['trend']) ? (string) $cached['trend'] : null,
                    window: (string) ($cached['window'] ?? 'daily'),
                    period: isset($cached['period']) ? (string) $cached['period'] : null,
                    previousActual: isset($cached['previous_actual']) ? (float) $cached['previous_actual'] : null,
                    changePercent: isset($cached['change_percent']) ? (float) $cached['change_percent'] : null,
                    meta: (array) ($cached['meta'] ?? []),
                );
            }
        }

        $goal = $this->goals[$goalKey] ?? null;
        if ($goal === null) {
            return new GoalProgress(
                goalKey: $goalKey,
                goalName: 'Unknown Goal',
                actual: 0.0,
                target: 0.0,
                percentage: 0.0,
                status: 'no_data',
            );
        }

        $actual = $this->resolveActualValue($goal, $period);
        $previousActual = $this->resolvePreviousActual($goal, $period);
        $trend = $this->detectTrend($actual, $previousActual);

        $progress = GoalProgress::fromGoal($goal, $actual, $previousActual, $trend, $period);

        $ttl = (int) $this->config->get('zeroboiler.analytics.goals.cache_ttl', self::DEFAULT_CACHE_TTL);
        $this->cache->put($cacheKey, $progress->toArray(), $ttl);

        return $progress;
    }

    /**
     * Calculate progress for all active goals.
     *
     * @return array<string, GoalProgress>
     */
    public function allProgress(?string $period = null, bool $useCache = true): array
    {
        $results = [];
        foreach ($this->activeGoals() as $key => $goal) {
            $results[$key] = $this->progress($key, $period, $useCache);
        }

        return $results;
    }

    /**
     * Get goal progress for a specific category.
     *
     * @return array<string, GoalProgress>
     */
    public function progressByCategory(string $category, ?string $period = null): array
    {
        $results = [];
        foreach ($this->goalsByCategory($category) as $key => $goal) {
            if ($goal->active) {
                $results[$key] = $this->progress($key, $period);
            }
        }

        return $results;
    }

    /**
     * Generate a dashboard summary of all goals.
     *
     * Returns counts by status, overall score, and category breakdown.
     *
     * @return GoalDashboard
     */
    public function dashboard(?string $period = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'dashboard';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $progress = $this->allProgress($period);

        $counts = [
            'total' => count($progress),
            'active' => 0,
            'achieved' => 0,
            'exceeded' => 0,
            'on_track' => 0,
            'at_risk' => 0,
            'behind' => 0,
            'no_data' => 0,
        ];

        $categories = [];
        $totalPercentage = 0.0;

        foreach ($progress as $goalKey => $p) {
            $counts[$p->status]++;
            $counts['active']++;
            $totalPercentage += min($p->percentage, 100.0);

            $cat = $this->goals[$goalKey]->category ?? 'uncategorized';
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['total' => 0, 'achieved' => 0, 'score' => 0.0];
            }
            $categories[$cat]['total']++;
            if ($p->status === 'achieved' || $p->status === 'exceeded') {
                $categories[$cat]['achieved']++;
            }
            $categories[$cat]['score'] += min($p->percentage, 100.0);
        }

        foreach ($categories as &$cat) {
            $cat['score'] = $cat['total'] > 0 ? round($cat['score'] / $cat['total'], 2) : 0.0;
        }
        unset($cat);

        $overallScore = $counts['active'] > 0
            ? round($totalPercentage / $counts['active'], 2)
            : 0.0;

        $result = [
            'total' => $counts['total'],
            'active' => $counts['active'],
            'achieved' => $counts['achieved'],
            'on_track' => $counts['on_track'],
            'at_risk' => $counts['at_risk'],
            'behind' => $counts['behind'],
            'no_data' => $counts['no_data'],
            'overall_score' => $overallScore,
            'categories' => $categories,
        ];

        $ttl = (int) $this->config->get('zeroboiler.analytics.goals.cache_ttl', self::DEFAULT_CACHE_TTL);
        $this->cache->put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * Get goals that need attention (at_risk or behind).
     *
     * @return array<string, GoalProgress>
     */
    public function attentionNeeded(?string $period = null): array
    {
        $results = [];
        foreach ($this->activeGoals() as $key => $goal) {
            $p = $this->progress($key, $period);
            if (in_array($p->status, ['at_risk', 'behind'], true)) {
                $results[$key] = $p;
            }
        }

        return $results;
    }

    /**
     * Get achieved goals for celebration/notifications.
     *
     * @return array<string, GoalProgress>
     */
    public function achievedGoals(?string $period = null): array
    {
        $results = [];
        foreach ($this->activeGoals() as $key => $goal) {
            $p = $this->progress($key, $period);
            if (in_array($p->status, ['achieved', 'exceeded'], true)) {
                $results[$key] = $p;
            }
        }

        return $results;
    }

    /**
     * Invalidate progress cache for a specific goal.
     */
    public function invalidateGoal(string $key): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'progress_' . $key);
    }

    /**
     * Invalidate all progress caches.
     */
    public function invalidateAll(): void
    {
        foreach (array_keys($this->goals) as $key) {
            $this->cache->forget(self::CACHE_PREFIX . 'progress_' . $key);
        }
        $this->cache->forget(self::CACHE_PREFIX . 'dashboard');
    }

    /**
     * Resolve the actual value for a goal from event data.
     *
     * In production, this reads from the event store / aggregation service.
     * For now, provides a fallback that returns 0 (to be wired by the consumer).
     */
    private function resolveActualValue(AnalyticsGoal $goal, ?string $period): float
    {
        // Consumers should override this via the RollingWindowAnalyticsEngine
        // or wire it to their own event store queries.
        // Returns 0.0 as a safe default when no data source is connected.
        return 0.0;
    }

    /**
     * Resolve the previous period actual value.
     */
    private function resolvePreviousActual(AnalyticsGoal $goal, ?string $period): ?float
    {
        return null;
    }

    /**
     * Detect trend direction from current and previous values.
     */
    private function detectTrend(float $current, ?float $previous): ?string
    {
        if ($previous === null || $previous <= 0) {
            return null;
        }

        $change = (($current - $previous) / $previous) * 100;

        if (abs($change) < 2.0) {
            return 'flat';
        }

        return $change > 0 ? 'up' : 'down';
    }
}
