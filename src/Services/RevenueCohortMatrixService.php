<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

/**
 * Revenue Cohort Matrix Service.
 *
 * Tracks MRR/ARR movements grouped by user signup cohort.
 * Produces a matrix showing how each cohort's revenue evolves
 * over time — essential for understanding:
 *
 * - **Cohort retention value**: Do early cohorts retain higher MRR?
 * - **Expansion revenue trend**: Does expansion accelerate with tenure?
 * - **Churn pattern by cohort**: Which cohorts churn fastest?
 * - **Payback period estimation**: When does a cohort become profitable?
 *
 * The matrix rows are signup cohorts (e.g. "2026-01", "2026-02"),
 * and columns are monthly periods since signup (M0, M1, M2, ...).
 * Each cell contains aggregated MRR for that cohort in that period.
 *
 * Data is sourced from recorded analytics events (subscription events,
 * revenue events, plan changes) and aggregated via cache-backed computation.
 *
 * Configuration: `zeroboiler.analytics.revenue_cohort_matrix`
 *
 * @phpstan-type CohortRow array{cohort: string, signup_count: int, m0_mrr: float, m1_mrr: float|null, m2_mrr: float|null, m3_mrr: float|null, m4_mrr: float|null, m5_mrr: float|null, m6_mrr: float|null, total_mrr: float, avg_mrr_per_user: float, retention_rate: float|null, expansion_rate: float|null, contraction_rate: float|null}
 * @phpstan-type CohortMatrixSummary array{cohorts: list<CohortRow>, total_cohorts: int, total_signup_users: int, overall_avg_mrr: float, best_retaining_cohort: string|null, best_expansion_cohort: string|null, average_payback_months: float|null, trend: string, computed_at: string}
 *
 * @since 186.0.0
 */
final class RevenueCohortMatrixService
{
    private const CACHE_PREFIX = 'zb_rev_cohort_matrix_';

    private const DEFAULT_TTL = 3600; // 1 hour

    private const MAX_PERIODS = 12;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxPeriods;

    private float $expansionThreshold;

    private float $contractionThreshold;

    private float $paybackTargetMonths;

    private CacheRepository $cache;

    /** @var array<string, CohortRow> */
    private array $cohortData = [];

    public function __construct(
        private readonly ConfigRepository $config,
        ?CacheRepository $cache = null,
    ): void {
        $this->cache = $cache ?? app(CacheRepository::class);

        $matrixConfig = $config->get('zeroboiler.analytics.revenue_cohort_matrix', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_periods?: int, expansion_threshold?: float, contraction_threshold?: float, payback_target_months?: float} $matrixConfig */
        $this->enabled = (bool) ($matrixConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($matrixConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxPeriods = min((int) ($matrixConfig['max_periods'] ?? self::MAX_PERIODS), self::MAX_PERIODS);
        $this->expansionThreshold = (float) ($matrixConfig['expansion_threshold'] ?? 0.10);
        $this->contractionThreshold = (float) ($matrixConfig['contraction_threshold'] ?? -0.10);
        $this->paybackTargetMonths = (float) ($matrixConfig['payback_target_months'] ?? 12.0);
    }

    /**
     * Record a signup event for cohort tracking.
     *
     * @param  string  $userId  The user's unique identifier
     * @param  string  $plan  Initial subscription plan name
     * @param  float  $mrr  Monthly recurring revenue at signup
     * @param  string|null  $cohortPeriod  Override cohort period (defaults to current month "YYYY-MM")
     */
    public function recordSignup(
        string $userId,
        string $plan,
        float $mrr,
        ?string $cohortPeriod = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $cohort = $cohortPeriod ?? $this->currentMonthPeriod();

        if (! isset($this->cohortData[$cohort])) {
            $this->cohortData[$cohort] = $this->emptyCohortRow($cohort);
        }

        $this->cohortData[$cohort]['signup_count']++;
        $this->cohortData[$cohort]['m0_mrr'] += $mrr;
        $this->cohortData[$cohort]['total_mrr'] += $mrr;
    }

    /**
     * Record an MRR movement for an existing cohort member.
     *
     * @param  string  $userId  The user's unique identifier
     * @param  string  $cohortPeriod  The user's signup cohort ("YYYY-MM")
     * @param  int  $monthsSinceSignup  Number of months since signup (0-based)
     * @param  float  $mrrDelta  Change in MRR (positive = expansion, negative = contraction)
     */
    public function recordMovement(
        string $userId,
        string $cohortPeriod,
        int $monthsSinceSignup,
        float $mrrDelta,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $periodKey = 'm' . $monthsSinceSignup . '_mrr';

        if ($monthsSinceSignup === 0) {
            $periodKey = 'm0_mrr';
        }

        if (! isset($this->cohortData[$cohortPeriod])) {
            return;
        }

        if (array_key_exists($periodKey, $this->cohortData[$cohortPeriod])) {
            $this->cohortData[$cohortPeriod][$periodKey] =
                ($this->cohortData[$cohortPeriod][$periodKey] ?? 0.0) + $mrrDelta;
        }

        $this->cohortData[$cohortPeriod]['total_mrr'] += max(0.0, $mrrDelta);
    }

    /**
     * Record a churn event for a cohort member.
     *
     * @param  string  $userId  The user's unique identifier
     * @param  string  $cohortPeriod  The user's signup cohort
     * @param  float  $lostMrr  MRR lost due to churn
     */
    public function recordChurn(string $userId, string $cohortPeriod, float $lostMrr): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! isset($this->cohortData[$cohortPeriod])) {
            return;
        }

        $this->cohortData[$cohortPeriod]['total_mrr'] -= max(0.0, $lostMrr);
    }

    /**
     * Build the full cohort matrix with all computed metrics.
     *
     * @return CohortMatrixSummary
     */
    public function buildMatrix(): array
    {
        // Merge persisted cohort data
        $persisted = $this->loadPersistedData();
        $merged = $this->mergeCohortData($persisted);

        // Compute metrics for each cohort
        $cohortRows = [];
        $totalUsers = 0;
        $totalMrr = 0.0;

        foreach ($merged as $cohort => $data) {
            $signupCount = $data['signup_count'];
            $totalUsers += $signupCount;

            $m0 = $data['m0_mrr'];
            $latestMrr = $this->getLatestMrr($data);
            $totalMrr += $data['total_mrr'];

            $avgMrrPerUser = $signupCount > 0
                ? round($data['total_mrr'] / $signupCount, 2)
                : 0.0;

            $retentionRate = $this->computeRetentionRate($data);
            $expansionRate = $this->computeExpansionRate($data);
            $contractionRate = $this->computeContractionRate($data);

            $cohortRows[] = [
                'cohort' => $cohort,
                'signup_count' => $signupCount,
                'm0_mrr' => $m0,
                'm1_mrr' => $data['m1_mrr'] ?? null,
                'm2_mrr' => $data['m2_mrr'] ?? null,
                'm3_mrr' => $data['m3_mrr'] ?? null,
                'm4_mrr' => $data['m4_mrr'] ?? null,
                'm5_mrr' => $data['m5_mrr'] ?? null,
                'm6_mrr' => $data['m6_mrr'] ?? null,
                'total_mrr' => round($data['total_mrr'], 2),
                'avg_mrr_per_user' => $avgMrrPerUser,
                'retention_rate' => $retentionRate !== null ? round($retentionRate, 4) : null,
                'expansion_rate' => $expansionRate !== null ? round($expansionRate, 4) : null,
                'contraction_rate' => $contractionRate !== null ? round($contractionRate, 4) : null,
            ];
        }

        // Sort cohorts chronologically (newest first)
        usort($cohortRows, fn (array $a, array $b): int => strcmp($b['cohort'], $a['cohort']));

        // Summary metrics
        $overallAvgMrr = $totalUsers > 0 ? round($totalMrr / $totalUsers, 2) : 0.0;
        $bestRetaining = $this->findBestMetricCohort($cohortRows, 'retention_rate');
        $bestExpansion = $this->findBestMetricCohort($cohortRows, 'expansion_rate');
        $paybackMonths = $this->estimatePaybackPeriod($cohortRows);
        $trend = $this->computeTrend($cohortRows);

        return [
            'cohorts' => $cohortRows,
            'total_cohorts' => count($cohortRows),
            'total_signup_users' => $totalUsers,
            'overall_avg_mrr' => $overallAvgMrr,
            'best_retaining_cohort' => $bestRetaining,
            'best_expansion_cohort' => $bestExpansion,
            'average_payback_months' => $paybackMonths !== null ? round($paybackMonths, 1) : null,
            'trend' => $trend,
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get a quick summary of cohort health.
     *
     * @return array{total_cohorts: int, avg_retention: float|null, avg_expansion: float|null, health: string, recommendation: string}
     */
    public function quickSummary(): array
    {
        $matrix = $this->buildMatrix();
        $cohorts = $matrix['cohorts'];

        $retentionRates = array_filter(
            array_map(fn (array $c): ?float => $c['retention_rate'], $cohorts),
            fn (?float $r): bool => $r !== null,
        );

        $expansionRates = array_filter(
            array_map(fn (array $c): ?float => $c['expansion_rate'], $cohorts),
            fn (?float $r): bool => $r !== null,
        );

        $avgRetention = count($retentionRates) > 0
            ? round(array_sum($retentionRates) / count($retentionRates), 4)
            : null;

        $avgExpansion = count($expansionRates) > 0
            ? round(array_sum($expansionRates) / count($expansionRates), 4)
            : null;

        // Health classification
        $health = 'unknown';
        $recommendation = 'Not enough data for cohort analysis.';

        if ($avgRetention !== null) {
            if ($avgRetention >= 0.90 && $avgExpansion !== null && $avgExpansion >= 0.05) {
                $health = 'excellent';
                $recommendation = 'Cohorts show strong retention with healthy expansion revenue.';
            } elseif ($avgRetention >= 0.80) {
                $health = 'good';
                $recommendation = 'Retention is solid. Focus on expansion revenue drivers.';
            } elseif ($avgRetention >= 0.60) {
                $health = 'warning';
                $recommendation = 'Retention needs attention. Investigate early-churn cohorts.';
            } else {
                $health = 'critical';
                $recommendation = 'Significant churn detected. Prioritize retention initiatives.';
            }
        }

        return [
            'total_cohorts' => $matrix['total_cohorts'],
            'avg_retention' => $avgRetention,
            'avg_expansion' => $avgExpansion,
            'health' => $health,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Compare two cohorts side by side.
     *
     * @return array{cohort_a: CohortRow|null, cohort_b: CohortRow|null, comparison: array{signup_diff: int, mrr_diff: float, retention_diff: float|null, expansion_diff: float|null, winner: string|null}}
     */
    public function compareCohorts(string $cohortA, string $cohortB): array
    {
        $matrix = $this->buildMatrix();

        $a = null;
        $b = null;

        foreach ($matrix['cohorts'] as $row) {
            if ($row['cohort'] === $cohortA) {
                $a = $row;
            }
            if ($row['cohort'] === $cohortB) {
                $b = $row;
            }
        }

        $signupDiff = ($a['signup_count'] ?? 0) - ($b['signup_count'] ?? 0);
        $mrrDiff = ($a['total_mrr'] ?? 0.0) - ($b['total_mrr'] ?? 0.0);
        $retentionDiff = ($a['retention_rate'] ?? 0.0) - ($b['retention_rate'] ?? 0.0);
        $expansionDiff = ($a['expansion_rate'] ?? 0.0) - ($b['expansion_rate'] ?? 0.0);

        $winner = null;
        if ($a !== null && $b !== null) {
            $aScore = ($a['retention_rate'] ?? 0.0) + ($a['expansion_rate'] ?? 0.0);
            $bScore = ($b['retention_rate'] ?? 0.0) + ($b['expansion_rate'] ?? 0.0);
            $winner = $aScore > $bScore ? $cohortA : ($bScore > $aScore ? $cohortB : null);
        }

        return [
            'cohort_a' => $a,
            'cohort_b' => $b,
            'comparison' => [
                'signup_diff' => $signupDiff,
                'mrr_diff' => round($mrrDiff, 2),
                'retention_diff' => $retentionDiff !== 0.0 ? round($retentionDiff, 4) : null,
                'expansion_diff' => $expansionDiff !== 0.0 ? round($expansionDiff, 4) : null,
                'winner' => $winner,
            ],
        ];
    }

    /**
     * Persist current cohort data to cache.
     */
    public function persist(): void
    {
        $persisted = $this->loadPersistedData();
        $merged = $this->mergeCohortData($persisted);

        $this->cache->put(
            self::CACHE_PREFIX . 'data',
            $merged,
            $this->cacheTtl,
        );
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the maximum number of tracking periods.
     */
    public function getMaxPeriods(): int
    {
        return $this->maxPeriods;
    }

    /**
     * Create an empty cohort row.
     *
     * @return CohortRow
     */
    private function emptyCohortRow(string $cohort): array
    {
        return [
            'cohort' => $cohort,
            'signup_count' => 0,
            'm0_mrr' => 0.0,
            'm1_mrr' => null,
            'm2_mrr' => null,
            'm3_mrr' => null,
            'm4_mrr' => null,
            'm5_mrr' => null,
            'm6_mrr' => null,
            'total_mrr' => 0.0,
            'avg_mrr_per_user' => 0.0,
            'retention_rate' => null,
            'expansion_rate' => null,
            'contraction_rate' => null,
        ];
    }

    /**
     * Get the current month as a cohort period string.
     */
    private function currentMonthPeriod(): string
    {
        return date('Y-m');
    }

    /**
     * Get the latest non-null MRR value from a cohort row.
     */
    private function getLatestMrr(array $data): float
    {
        for ($i = $this->maxPeriods; $i >= 0; $i--) {
            $key = $i === 0 ? 'm0_mrr' : 'm' . $i . '_mrr';

            if (isset($data[$key]) && $data[$key] !== null) {
                return (float) $data[$key];
            }
        }

        return $data['m0_mrr'] ?? 0.0;
    }

    /**
     * Compute retention rate for a cohort.
     *
     * Compares latest MRR to M0 MRR.
     */
    private function computeRetentionRate(array $data): ?float
    {
        $m0 = $data['m0_mrr'];

        if ($m0 <= 0) {
            return null;
        }

        $latest = $this->getLatestMrr($data);

        if ($latest <= 0) {
            return 0.0;
        }

        return min(1.0, $latest / $m0);
    }

    /**
     * Compute expansion rate (MRR growth beyond M0).
     */
    private function computeExpansionRate(array $data): ?float
    {
        $m0 = $data['m0_mrr'];

        if ($m0 <= 0) {
            return null;
        }

        $latest = $this->getLatestMrr($data);
        $delta = $latest - $m0;

        if ($delta <= 0) {
            return 0.0;
        }

        return $delta / $m0;
    }

    /**
     * Compute contraction rate (MRR decline from M0).
     */
    private function computeContractionRate(array $data): ?float
    {
        $m0 = $data['m0_mrr'];

        if ($m0 <= 0) {
            return null;
        }

        $latest = $this->getLatestMrr($data);
        $delta = $latest - $m0;

        if ($delta >= 0) {
            return 0.0;
        }

        return abs($delta / $m0);
    }

    /**
     * Find the cohort with the best value for a given metric.
     */
    private function findBestMetricCohort(array $cohortRows, string $metric): ?string
    {
        $bestValue = null;
        $bestCohort = null;

        foreach ($cohortRows as $row) {
            $value = $row[$metric] ?? null;

            if ($value !== null && ($bestValue === null || $value > $bestValue)) {
                $bestValue = $value;
                $bestCohort = $row['cohort'];
            }
        }

        return $bestCohort;
    }

    /**
     * Estimate average payback period across cohorts.
     *
     * Payback = months until cumulative MRR ≥ acquisition cost assumption.
     * Uses a simplified model: payback = 1 / (avg MRR per user × assumed CAC ratio).
     */
    private function estimatePaybackPeriod(array $cohortRows): ?float
    {
        if (empty($cohortRows)) {
            return null;
        }

        $weightedPeriods = 0.0;
        $totalWeight = 0;

        foreach ($cohortRows as $row) {
            if ($row['signup_count'] <= 0) {
                continue;
            }

            $m0 = $row['m0_mrr'];
            if ($m0 <= 0) {
                continue;
            }

            // Assume CAC is 3× M0 (industry typical)
            $cac = $m0 * 3;
            $monthlyContribution = $row['avg_mrr_per_user'];

            if ($monthlyContribution <= 0) {
                continue;
            }

            $payback = $cac / $monthlyContribution;
            $weightedPeriods += $payback * $row['signup_count'];
            $totalWeight += $row['signup_count'];
        }

        if ($totalWeight === 0) {
            return null;
        }

        return $weightedPeriods / $totalWeight;
    }

    /**
     * Compute overall MRR trend across cohorts.
     *
     * Compares the 3 most recent cohorts' avg MRR against the 3 prior cohorts.
     *
     * @return string 'improving'|'declining'|'stable'|'insufficient_data'
     */
    private function computeTrend(array $cohortRows): string
    {
        if (count($cohortRows) < 4) {
            return 'insufficient_data';
        }

        // Sort chronologically (oldest first)
        $sorted = $cohortRows;
        usort($sorted, fn (array $a, array $b): int => strcmp($a['cohort'], $b['cohort']));

        $recentCount = min(3, (int) (count($sorted) / 2));
        $recent = array_slice($sorted, -$recentCount);
        $older = array_slice($sorted, 0, $recentCount);

        $recentAvg = $this->avgMetric($recent, 'avg_mrr_per_user');
        $olderAvg = $this->avgMetric($older, 'avg_mrr_per_user');

        if ($olderAvg <= 0) {
            return 'insufficient_data';
        }

        $change = ($recentAvg - $olderAvg) / $olderAvg;

        if ($change > 0.05) {
            return 'improving';
        }

        if ($change < -0.05) {
            return 'declining';
        }

        return 'stable';
    }

    /**
     * Compute average of a numeric metric across cohort rows.
     */
    private function avgMetric(array $rows, string $metric): float
    {
        $values = array_filter(
            array_map(fn (array $r): mixed => $r[$metric] ?? null, $rows),
            fn (mixed $v): bool => is_numeric($v) && $v > 0,
        );

        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Load persisted cohort data from cache.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadPersistedData(): array
    {
        /** @var array<string, array<string, mixed>>|null $data */
        $data = $this->cache->get(self::CACHE_PREFIX . 'data');

        return is_array($data) ? $data : [];
    }

    /**
     * Merge in-memory cohort data with persisted data.
     *
     * @param  array<string, array<string, mixed>>  $persisted
     * @return array<string, array<string, mixed>>
     */
    private function mergeCohortData(array $persisted): array
    {
        $merged = $persisted;

        foreach ($this->cohortData as $cohort => $data) {
            if (! isset($merged[$cohort])) {
                $merged[$cohort] = $data;
            } else {
                // Aggregate numeric fields
                foreach ($data as $key => $value) {
                    if (is_float($value) || is_int($value)) {
                        $merged[$cohort][$key] = ($merged[$cohort][$key] ?? 0) + $value;
                    } elseif ($key === 'signup_count' && is_int($value)) {
                        $merged[$cohort][$key] = ($merged[$cohort][$key] ?? 0) + $value;
                    }
                }
            }
        }

        return $merged;
    }
}
