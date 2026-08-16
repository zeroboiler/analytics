<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Cohort Revenue Attribution Service.
 *
 * Correlates cohort membership with revenue events to produce industry-standard
 * LTV-by-cohort analysis, cumulative revenue curves, payback period estimation,
 * and cohort-based revenue attribution. Designed for SaaS teams measuring
 * the economic value of user cohorts over time.
 *
 * This service bridges CohortAnalyticsService and RevenueAnalyticsService,
 * providing a unified view of how different signup periods, acquisition channels,
 * or plan tiers contribute to revenue over their lifetime.
 *
 * Revenue data is aggregated in-memory from the AnalyticsMetrics counter
 * and enriched with cache-persisted cohort metadata. No database required.
 *
 * Configuration: `zeroboiler.analytics.cohort_revenue`
 *
 * @phpstan-type CohortRevenueEntry array{cohort: string, cohort_type: string, period: string, users: int, revenue: float, events: int, avg_revenue_per_user: float, cumulative_revenue: float, ltv_estimate: float, retention_pct: float, payback_months: float|null}
 * @phpstan-type CohortRevenueMatrix array{cohorts: list<CohortRevenueEntry>, periods: list<string>, total_revenue: float, total_users: int, avg_ltv: float, best_cohort: string|null, worst_cohort: string|null, generated_at: string}
 * @phpstan-type CohortComparisonEntry array{cohort: string, period: string, revenue: float, ltv: float, retention: float, payback_months: float|null, vs_avg: float}
 *
 * @see \ZeroBoiler\Analytics\Services\CohortAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\RevenueAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService
 *
 * @since 1.0.0
 */
final class CohortRevenueAttributionService
{
    private const CACHE_PREFIX = 'zb_cohort_revenue_';

    private const DEFAULT_CACHE_TTL = 3600; // 1 hour

    private bool $enabled;

    private readonly int $cacheTtl;

    private readonly float $defaultMonthlyChurnRate;

    private readonly float $defaultArpu;

    private readonly int $maxCohorts;

    private readonly int $projectionMonths;

    private readonly string $currency;

    private readonly CacheRepository $cache;

    private readonly AnalyticsManager $manager;

    private readonly AnalyticsMetrics $metrics;

    /**
     * Revenue event names that count towards cohort revenue attribution.
     *
     * @var list<string>
     */
    private const REVENUE_EVENT_NAMES = [
        'purchase',
        'subscribe',
        'subscription_created',
        'subscription_renewal',
        'plan_upgrade',
        'payment_succeeded',
        'revenue_tracked',
        'expansion_revenue',
        'trial_converted',
        'invoice_generated',
        'credit_applied',
        'subscription_value_changed',
        'billing_retry',
        'add_to_cart',
        'begin_checkout',
        'add_payment_info',
    ];

    /**
     * Retention signal event names used to estimate cohort retention.
     *
     * @var list<string>
     */
    private const RETENTION_EVENT_NAMES = [
        'login',
        'feature_used',
        'page_view',
        'session_start',
        'search',
        'form_submit',
        'content_engagement',
        'share',
    ];

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->cache = $cache;

        $crConfig = $config->get('zeroboiler.analytics.cohort_revenue', []);
        /** @var array{enabled?: bool, cache_ttl?: int, monthly_churn_rate?: float, arpu?: float, max_cohorts?: int, projection_months?: int, currency?: string} $crConfig */

        $this->enabled = (bool) ($crConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($crConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->defaultMonthlyChurnRate = (float) ($crConfig['monthly_churn_rate'] ?? 0.05);
        $this->defaultArpu = (float) ($crConfig['arpu'] ?? 49.0);
        $this->maxCohorts = (int) ($crConfig['max_cohorts'] ?? 24);
        $this->projectionMonths = (int) ($crConfig['projection_months'] ?? 12);
        $this->currency = (string) ($crConfig['currency'] ?? 'USD');
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a revenue event attributed to a cohort.
     *
     * Call this after revenue events are dispatched to maintain cohort-revenue
     * aggregation in cache. The data feeds cohort LTV calculations.
     *
     * @param  string  $cohortId  Cohort identifier (e.g. '2026-W32', '2026-08')
     * @param  string  $cohortType  Cohort type (signup, trial, plan, channel)
     * @param  float  $revenue  Revenue amount in configured currency
     * @param  string  $eventName  Revenue event name
     * @param  string|null  $userId  User identifier
     */
    public function recordRevenue(
        string $cohortId,
        string $cohortType,
        float $revenue,
        string $eventName,
        ?string $userId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        if (! isset($aggregates[$cohortId])) {
            $aggregates[$cohortId] = [
                'type' => $cohortType,
                'users' => 0,
                'revenue' => 0.0,
                'events' => [],
                'user_ids' => [],
            ];
        }

        $aggregates[$cohortId]['revenue'] = round($aggregates[$cohortId]['revenue'] + $revenue, 4);
        $aggregates[$cohortId]['events'][$eventName] = ($aggregates[$cohortId]['events'][$eventName] ?? 0) + 1;

        if ($userId !== null && $userId !== '' && ! isset($aggregates[$cohortId]['user_ids'][$userId])) {
            $aggregates[$cohortId]['user_ids'][$userId] = true;
            $aggregates[$cohortId]['users']++;
        }

        // Enforce max cohorts limit
        if (count($aggregates) > $this->maxCohorts) {
            $aggregates = array_slice($aggregates, -$this->maxCohorts, null, true);
        }

        $this->cache->put($cacheKey, $aggregates, $this->cacheTtl);
    }

    /**
     * Record cohort membership (user count) without revenue.
     *
     * Call this when a user is assigned to a cohort but hasn't generated
     * revenue yet. Used to build the denominator for per-user metrics.
     *
     * @param  string  $cohortId  Cohort identifier
     * @param  string  $cohortType  Cohort type
     * @param  string  $userId  User identifier
     */
    public function recordCohortMember(string $cohortId, string $cohortType, string $userId): void
    {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        if (! isset($aggregates[$cohortId])) {
            $aggregates[$cohortId] = [
                'type' => $cohortType,
                'users' => 0,
                'revenue' => 0.0,
                'events' => [],
                'user_ids' => [],
            ];
        }

        $aggregates[$cohortId]['type'] = $cohortType;

        if (! isset($aggregates[$cohortId]['user_ids'][$userId])) {
            $aggregates[$cohortId]['user_ids'][$userId] = true;
            $aggregates[$cohortId]['users']++;
        }

        $this->cache->put($cacheKey, $aggregates, $this->cacheTtl);
    }

    /**
     * Get the full cohort revenue matrix.
     *
     * Returns all tracked cohorts with their revenue metrics,
     * sorted by cohort period descending (most recent first).
     *
     * @return CohortRevenueMatrix
     */
    public function matrix(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        $cohorts = [];
        $totalRevenue = 0.0;
        $totalUsers = 0;
        $bestCohort = null;
        $worstCohort = null;
        $bestRevenue = -1.0;
        $worstRevenue = PHP_FLOAT_MAX;

        foreach ($aggregates as $cohortId => $data) {
            $users = $data['users'];
            $revenue = $data['revenue'];
            $eventCount = array_sum($data['events']);
            $avgRevenue = $users > 0 ? round($revenue / $users, 2) : 0.0;
            $ltvEstimate = $this->estimateLtv($avgRevenue);
            $retentionPct = $this->estimateRetention($cohortId);
            $paybackMonths = $avgRevenue > 0 && $this->defaultArpu > 0
                ? round(($this->defaultArpu / $avgRevenue) * 30, 1)
                : null;

            $cohorts[] = [
                'cohort' => $cohortId,
                'cohort_type' => $data['type'],
                'period' => $cohortId,
                'users' => $users,
                'revenue' => round($revenue, 2),
                'events' => $eventCount,
                'avg_revenue_per_user' => $avgRevenue,
                'cumulative_revenue' => round($revenue, 2),
                'ltv_estimate' => round($ltvEstimate, 2),
                'retention_pct' => round($retentionPct, 1),
                'payback_months' => $paybackMonths,
            ];

            $totalRevenue += $revenue;
            $totalUsers += $users;

            if ($revenue > $bestRevenue) {
                $bestRevenue = $revenue;
                $bestCohort = $cohortId;
            }

            if ($revenue < $worstRevenue && $users > 0) {
                $worstRevenue = $revenue;
                $worstCohort = $cohortId;
            }
        }

        // Sort by period descending
        usort($cohorts, fn (array $a, array $b): int => strcmp($b['period'], $a['period']));

        $avgLtv = $totalUsers > 0
            ? round($this->estimateLtv($totalRevenue / $totalUsers), 2)
            : 0.0;

        return [
            'cohorts' => $cohorts,
            'periods' => array_column($cohorts, 'period'),
            'total_revenue' => round($totalRevenue, 2),
            'total_users' => $totalUsers,
            'avg_ltv' => $avgLtv,
            'best_cohort' => $bestCohort,
            'worst_cohort' => $worstCohort,
            'currency' => $this->currency,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Compare cohorts against each other and the average.
     *
     * Returns a comparative view showing each cohort's performance
     * relative to the portfolio average.
     *
     * @return array{comparisons: list<CohortComparisonEntry>, average_revenue: float, average_ltv: float, average_retention: float, total_cohorts: int}
     */
    public function compare(): array
    {
        $matrix = $this->matrix();
        $cohorts = $matrix['cohorts'];

        if (empty($cohorts)) {
            return [
                'comparisons' => [],
                'average_revenue' => 0.0,
                'average_ltv' => 0.0,
                'average_retention' => 0.0,
                'total_cohorts' => 0,
            ];
        }

        $totalRevenue = 0.0;
        $totalLtv = 0.0;
        $totalRetention = 0.0;
        $count = count($cohorts);

        foreach ($cohorts as $c) {
            $totalRevenue += $c['revenue'];
            $totalLtv += $c['ltv_estimate'];
            $totalRetention += $c['retention_pct'];
        }

        $avgRevenue = $totalRevenue / $count;
        $avgLtv = $totalLtv / $count;
        $avgRetention = $totalRetention / $count;

        $comparisons = [];
        foreach ($cohorts as $c) {
            $vsAvg = $avgRevenue > 0
                ? round((($c['revenue'] - $avgRevenue) / $avgRevenue) * 100, 1)
                : 0.0;

            $comparisons[] = [
                'cohort' => $c['cohort'],
                'period' => $c['period'],
                'revenue' => $c['revenue'],
                'ltv' => $c['ltv_estimate'],
                'retention' => $c['retention_pct'],
                'payback_months' => $c['payback_months'],
                'vs_avg' => $vsAvg,
            ];
        }

        return [
            'comparisons' => $comparisons,
            'average_revenue' => round($avgRevenue, 2),
            'average_ltv' => round($avgLtv, 2),
            'average_retention' => round($avgRetention, 1),
            'total_cohorts' => $count,
        ];
    }

    /**
     * Generate a cohort LTV projection curve.
     *
     * Projects cumulative revenue and LTV for each cohort over the
     * configured projection horizon using churn-based decay modeling.
     *
     * @param  string|null  $cohortId  Specific cohort (null = all)
     * @return array{cohort_id: string|null, projections: list<array{month: int, retained_pct: float, monthly_revenue: float, cumulative_revenue: float, ltv_estimate: float}>, total_projected_ltv: float, payback_months: int|null, currency: string}
     */
    public function projectLtv(?string $cohortId = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        // Determine target cohort(s)
        $targets = [];
        if ($cohortId !== null && isset($aggregates[$cohortId])) {
            $targets[$cohortId] = $aggregates[$cohortId];
        } else {
            $targets = $aggregates;
        }

        // Aggregate across targets
        $totalUsers = 0;
        $totalMonthlyRevenue = 0.0;

        foreach ($targets as $data) {
            $totalUsers += $data['users'];
            $totalMonthlyRevenue += $data['revenue'];
        }

        $initialMonthlyRevenue = $totalUsers > 0
            ? $totalMonthlyRevenue / max(1, $totalUsers)
            : $this->defaultArpu;

        $projections = [];
        $cumulativeRevenue = 0.0;
        $churnRate = $this->defaultMonthlyChurnRate;

        for ($month = 1; $month <= $this->projectionMonths; $month++) {
            $retainedPct = pow(1 - $churnRate, $month) * 100;
            $monthlyRevenue = $initialMonthlyRevenue * $retainedPct / 100 * $totalUsers;
            $cumulativeRevenue += $monthlyRevenue;
            $ltvEstimate = $totalUsers > 0 ? $cumulativeRevenue / $totalUsers : 0.0;

            $projections[] = [
                'month' => $month,
                'retained_pct' => round($retainedPct, 1),
                'monthly_revenue' => round($monthlyRevenue, 2),
                'cumulative_revenue' => round($cumulativeRevenue, 2),
                'ltv_estimate' => round($ltvEstimate, 2),
            ];
        }

        $totalProjectedLtv = $totalUsers > 0
            ? round($cumulativeRevenue / $totalUsers, 2)
            : 0.0;

        $cacEstimate = $this->defaultArpu * 2; // Assume CAC = 2x ARPU
        $paybackMonths = $initialMonthlyRevenue > 0 && $cacEstimate > 0
            ? (int) ceil($cacEstimate / ($initialMonthlyRevenue * $totalUsers / max(1, $totalUsers)))
            : null;

        // Recalculate payback more accurately
        if ($initialMonthlyRevenue > 0 && $totalUsers > 0) {
            $paybackMonths = null;
            $cumul = 0.0;
            $perUserMonthly = $initialMonthlyRevenue;

            for ($m = 1; $m <= $this->projectionMonths; $m++) {
                $retained = pow(1 - $churnRate, $m);
                $cumul += $perUserMonthly * $retained;

                if ($cumul >= $cacEstimate && $paybackMonths === null) {
                    $paybackMonths = $m;
                }
            }
        }

        return [
            'cohort_id' => $cohortId,
            'projections' => $projections,
            'total_projected_ltv' => $totalProjectedLtv,
            'payback_months' => $paybackMonths,
            'currency' => $this->currency,
            'assumptions' => [
                'churn_rate' => $this->defaultMonthlyChurnRate,
                'arpu' => $initialMonthlyRevenue,
                'cac_estimate' => $cacEstimate,
                'projection_months' => $this->projectionMonths,
                'total_users' => $totalUsers,
            ],
        ];
    }

    /**
     * Get revenue breakdown by cohort type.
     *
     * Aggregates revenue across cohorts grouped by their type
     * (signup, trial, plan, channel, custom).
     *
     * @return array{types: array<string, array{cohorts: int, users: int, revenue: float, avg_ltv: float, avg_retention: float}>, total_revenue: float}
     */
    public function byType(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        $types = [];
        $totalRevenue = 0.0;

        foreach ($aggregates as $cohortId => $data) {
            $type = $data['type'];
            if (! isset($types[$type])) {
                $types[$type] = [
                    'cohorts' => 0,
                    'users' => 0,
                    'revenue' => 0.0,
                    'ltv_sum' => 0.0,
                    'retention_sum' => 0.0,
                ];
            }

            $types[$type]['cohorts']++;
            $types[$type]['users'] += $data['users'];
            $types[$type]['revenue'] += $data['revenue'];

            $avgRev = $data['users'] > 0 ? $data['revenue'] / $data['users'] : 0.0;
            $types[$type]['ltv_sum'] += $this->estimateLtv($avgRev);
            $types[$type]['retention_sum'] += $this->estimateRetention($cohortId);

            $totalRevenue += $data['revenue'];
        }

        $result = [];
        foreach ($types as $type => $data) {
            $result[$type] = [
                'cohorts' => $data['cohorts'],
                'users' => $data['users'],
                'revenue' => round($data['revenue'], 2),
                'avg_ltv' => $data['cohorts'] > 0
                    ? round($data['ltv_sum'] / $data['cohorts'], 2)
                    : 0.0,
                'avg_retention' => $data['cohorts'] > 0
                    ? round($data['retention_sum'] / $data['cohorts'], 1)
                    : 0.0,
            ];
        }

        return [
            'types' => $result,
            'total_revenue' => round($totalRevenue, 2),
            'currency' => $this->currency,
        ];
    }

    /**
     * Get top revenue-generating cohorts.
     *
     * Returns cohorts sorted by total revenue, with enrichment
     * showing per-user metrics and growth indicators.
     *
     * @param  int  $limit  Number of cohorts to return (default: 10)
     * @return list<array{cohort: string, cohort_type: string, users: int, revenue: float, avg_revenue_per_user: float, ltv_estimate: float, retention_pct: float, rank: int}>
     */
    public function topCohorts(int $limit = 10): array
    {
        $matrix = $this->matrix();
        $cohorts = $matrix['cohorts'];

        // Sort by revenue descending
        usort($cohorts, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $result = [];
        foreach (array_slice($cohorts, 0, $limit) as $index => $c) {
            $result[] = [
                'cohort' => $c['cohort'],
                'cohort_type' => $c['cohort_type'],
                'users' => $c['users'],
                'revenue' => $c['revenue'],
                'avg_revenue_per_user' => $c['avg_revenue_per_user'],
                'ltv_estimate' => $c['ltv_estimate'],
                'retention_pct' => $c['retention_pct'],
                'rank' => $index + 1,
            ];
        }

        return $result;
    }

    /**
     * Get a summary suitable for CLI output and admin dashboards.
     *
     * @return array{enabled: bool, total_cohorts: int, total_users: int, total_revenue: float, avg_ltv: float, best_cohort: string|null, currency: string, top_cohorts: list<array{cohort: string, revenue: float, ltv: float}>, projection: array{projected_ltv: float, payback_months: int|null, months: int}}
     */
    public function summary(): array
    {
        $matrix = $this->matrix();
        $topCohorts = $this->topCohorts(5);
        $projection = $this->projectLtv();

        return [
            'enabled' => $this->enabled,
            'total_cohorts' => count($matrix['cohorts']),
            'total_users' => $matrix['total_users'],
            'total_revenue' => $matrix['total_revenue'],
            'avg_ltv' => $matrix['avg_ltv'],
            'best_cohort' => $matrix['best_cohort'],
            'currency' => $this->currency,
            'top_cohorts' => array_map(
                fn (array $c): array => [
                    'cohort' => $c['cohort'],
                    'revenue' => $c['revenue'],
                    'ltv' => $c['ltv_estimate'],
                ],
                $topCohorts,
            ),
            'projection' => [
                'projected_ltv' => $projection['total_projected_ltv'],
                'payback_months' => $projection['payback_months'],
                'months' => $this->projectionMonths,
            ],
        ];
    }

    /**
     * Get a health score for cohort revenue tracking.
     *
     * Evaluates data quality, cohort coverage, and revenue attribution
     * completeness. Returns a score from 0-100.
     *
     * @return array{score: int, grade: string, details: array{cohorts_tracked: int, cohorts_with_revenue: int, revenue_coverage: float, avg_events_per_cohort: float, data_freshness: string|null, issues: list<string>}}
     */
    public function healthScore(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        $totalCohorts = count($aggregates);
        $cohortsWithRevenue = 0;
        $totalEvents = 0;
        $issues = [];

        if ($totalCohorts === 0) {
            return [
                'score' => 0,
                'grade' => 'F',
                'details' => [
                    'cohorts_tracked' => 0,
                    'cohorts_with_revenue' => 0,
                    'revenue_coverage' => 0.0,
                    'avg_events_per_cohort' => 0.0,
                    'data_freshness' => null,
                    'issues' => ['No cohorts tracked yet'],
                ],
            ];
        }

        foreach ($aggregates as $cohortId => $data) {
            if ($data['revenue'] > 0) {
                $cohortsWithRevenue++;
            }
            $totalEvents += array_sum($data['events']);

            if ($data['users'] === 0) {
                $issues[] = "Cohort '{$cohortId}' has no users";
            }
        }

        $revenueCoverage = $totalCohorts > 0
            ? round(($cohortsWithRevenue / $totalCohorts) * 100, 1)
            : 0.0;
        $avgEvents = $totalCohorts > 0
            ? round($totalEvents / $totalCohorts, 1)
            : 0.0;

        // Scoring
        $score = 0;

        // Cohort coverage (max 30 points)
        $score += min(30, $totalCohorts * 5);

        // Revenue coverage (max 30 points)
        $score += (int) ($revenueCoverage * 0.3);

        // Data richness (max 20 points)
        $score += min(20, (int) $avgEvents * 2);

        // No issues bonus (max 20 points)
        $score += max(0, 20 - count($issues) * 5);

        $score = min(100, $score);

        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'details' => [
                'cohorts_tracked' => $totalCohorts,
                'cohorts_with_revenue' => $cohortsWithRevenue,
                'revenue_coverage' => $revenueCoverage,
                'avg_events_per_cohort' => $avgEvents,
                'data_freshness' => date('c'),
                'issues' => $issues,
            ],
        ];
    }

    /**
     * Clear all cohort revenue data from cache.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'aggregates');
    }

    /**
     * Estimate LTV from average monthly revenue per user.
     *
     * Uses the formula: LTV = ARPU / Monthly Churn Rate
     *
     * @param  float  $avgMonthlyRevenue  Average monthly revenue per user
     */
    private function estimateLtv(float $avgMonthlyRevenue): float
    {
        if ($this->defaultMonthlyChurnRate <= 0) {
            return $avgMonthlyRevenue * 24; // Assume 24 months if no churn
        }

        return $avgMonthlyRevenue / $this->defaultMonthlyChurnRate;
    }

    /**
     * Estimate retention rate for a cohort based on its age.
     *
     * Uses time-since-cohort-start as a proxy for retention decay.
     * Newer cohorts get higher retention estimates.
     *
     * @param  string  $cohortId  Cohort identifier (expected format: YYYY-WW or YYYY-MM)
     */
    private function estimateRetention(string $cohortId): float
    {
        // Try to parse cohort age
        $now = time();
        $cohortTime = $this->parseCohortTimestamp($cohortId);

        if ($cohortTime === 0) {
            return 80.0; // Default estimate
        }

        $daysSinceStart = max(0, ($now - $cohortTime) / 86400);
        $monthsSinceStart = $daysSinceStart / 30;

        // Exponential decay based on churn rate
        $retentionPct = pow(1 - $this->defaultMonthlyChurnRate, $monthsSinceStart) * 100;

        return round($retentionPct, 1);
    }

    /**
     * Parse a cohort identifier into a Unix timestamp.
     *
     * Supports formats:
     * - YYYY-WXX (e.g., '2026-W32' → start of week 32)
     * - YYYY-MM (e.g., '2026-08' → start of August)
     * - YYYY (e.g., '2026' → start of year)
     *
     * @return int Unix timestamp (0 if unparseable)
     */
    private function parseCohortTimestamp(string $cohortId): int
    {
        // YYYY-WXX format
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $cohortId, $m)) {
            $year = (int) $m[1];
            $week = (int) $m[2];

            // ISO week date to timestamp
            $jan4 = strtotime("{$year}-01-04");
            $mondayOffset = (int) date('N', $jan4) - 1;
            $firstMonday = $jan4 - ($mondayOffset * 86400);

            return $firstMonday + (($week - 1) * 7 * 86400);
        }

        // YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{2})$/', $cohortId, $m)) {
            return strtotime("{$m[1]}-{$m[2]}-01");
        }

        // YYYY format
        if (preg_match('/^(\d{4})$/', $cohortId, $m)) {
            return strtotime("{$m[1]}-01-01");
        }

        return 0;
    }

    /**
     * Get the configured currency.
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Get the configured projection horizon in months.
     */
    public function getProjectionMonths(): int
    {
        return $this->projectionMonths;
    }

    /**
     * Get the configured monthly churn rate.
     */
    public function getMonthlyChurnRate(): float
    {
        return $this->defaultMonthlyChurnRate;
    }

    /**
     * Get the configured ARPU.
     */
    public function getDefaultArpu(): float
    {
        return $this->defaultArpu;
    }

    /**
     * Get cohort revenue data for a specific cohort.
     *
     * @return array{cohort: string, type: string, users: int, revenue: float, events: int, avg_revenue: float, ltv_estimate: float}|null
     */
    public function getCohort(string $cohortId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        $data = $aggregates[$cohortId] ?? null;

        if ($data === null) {
            return null;
        }

        $users = $data['users'];
        $avgRevenue = $users > 0 ? round($data['revenue'] / $users, 2) : 0.0;

        return [
            'cohort' => $cohortId,
            'type' => $data['type'],
            'users' => $users,
            'revenue' => round($data['revenue'], 2),
            'events' => array_sum($data['events']),
            'avg_revenue' => $avgRevenue,
            'ltv_estimate' => round($this->estimateLtv($avgRevenue), 2),
        ];
    }

    /**
     * Get all tracked cohort identifiers.
     *
     * @return list<string>
     */
    public function cohortIds(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, mixed> $aggregates */

        return array_keys($aggregates);
    }

    /**
     * Get revenue attribution by event type for a specific cohort.
     *
     * Shows which revenue events contributed to a cohort's total revenue.
     *
     * @param  string  $cohortId  Cohort identifier
     * @return array{cohort: string, events: array<string, int>, total_events: int, total_revenue: float}|null
     */
    public function revenueByEvent(string $cohortId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'aggregates';
        $aggregates = $this->cache->get($cacheKey, []);
        /** @var array<string, array{type: string, users: int, revenue: float, events: array<string, int>, user_ids: array<string, bool>}> $aggregates */

        $data = $aggregates[$cohortId] ?? null;

        if ($data === null) {
            return null;
        }

        $events = $data['events'];
        arsort($events);

        return [
            'cohort' => $cohortId,
            'events' => $events,
            'total_events' => array_sum($events),
            'total_revenue' => round($data['revenue'], 2),
        ];
    }
}
