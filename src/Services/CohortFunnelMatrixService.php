<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Cohort × Funnel Matrix Engine — cross-dimensional conversion analytics.
 *
 * Computes multi-dimensional matrices that intersect user cohorts with
 * conversion funnels, enabling product teams to answer questions like:
 *
 * - "What % of January signups completed onboarding vs. March signups?"
 * - "How does trial-to-paid conversion differ by acquisition source?"
 * - "Which cohort has the fastest checkout flow completion?"
 * - "What is the feature adoption funnel for free vs. enterprise tiers?"
 *
 * Produces structured matrix data suitable for heatmap rendering,
 * cohort comparison tables, and statistical breakdown charts.
 *
 * Matrix dimensions:
 * - **Rows** → cohorts (by period, source, plan, or custom segment)
 * - **Columns** → funnel steps (configurable step sequences)
 * - **Cells** → counts, conversion rates, time metrics, revenue
 *
 * Inspired by Amplitude Pathfinder × Cohort, Mixpanel Cohort Funnels,
 * and Heap's cross-dimensional funnel analysis.
 *
 * Configuration: `zeroboiler.analytics.cohort_funnel_matrix`
 *
 * @since 56.0.0
 */
final class CohortFunnelMatrixService
{
    private const CACHE_PREFIX = 'zb_cohort_funnel_matrix_';

    private const DEFAULT_CACHE_TTL = 600; // 10 minutes

    /** @var list<string> Default onboarding funnel steps */
    private const DEFAULT_ONBOARDING_FUNNEL = [
        'sign_up',
        'email_verified',
        'profile_completed',
        'first_feature_used',
        'trial_started',
    ];

    /** @var list<string> Default purchase funnel steps */
    private const DEFAULT_PURCHASE_FUNNEL = [
        'view_item',
        'add_to_cart',
        'begin_checkout',
        'add_payment_info',
        'purchase',
    ];

    /** @var list<string> Default SaaS conversion funnel steps */
    private const DEFAULT_SAAS_FUNNEL = [
        'sign_up',
        'trial_start',
        'feature_used',
        'plan_upgrade',
        'subscribe',
    ];

    /** @var list<string> Default engagement funnel steps */
    private const DEFAULT_ENGAGEMENT_FUNNEL = [
        'page_view',
        'scroll_depth',
        'form_start',
        'form_submit',
        'share',
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    /** @var array<string, list<string>> Predefined funnel templates */
    private array $funnelTemplates;

    /** @var list<string> Default cohort dimensions */
    private array $cohortDimensions;

    /** @var int Max cohorts per matrix (bounds memory) */
    private int $maxCohorts;

    /** @var int Max funnel steps per matrix */
    private int $maxSteps;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $matrixConfig = $config->get('zeroboiler.analytics.cohort_funnel_matrix', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_cohorts?: int, max_steps?: int, custom_funnels?: array<string, list<string>>, cohort_dimensions?: list<string>} $matrixConfig */

        $this->enabled = (bool) ($matrixConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($matrixConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->maxCohorts = (int) ($matrixConfig['max_cohorts'] ?? 24);
        $this->maxSteps = (int) ($matrixConfig['max_steps'] ?? 20);

        $this->cohortDimensions = $matrixConfig['cohort_dimensions'] ?? [
            'period', 'source', 'plan', 'tier', 'device',
        ];

        $customFunnels = $matrixConfig['custom_funnels'] ?? [];

        $this->funnelTemplates = array_merge([
            'onboarding' => self::DEFAULT_ONBOARDING_FUNNEL,
            'purchase' => self::DEFAULT_PURCHASE_FUNNEL,
            'saas_conversion' => self::DEFAULT_SAAS_FUNNEL,
            'engagement' => self::DEFAULT_ENGAGEMENT_FUNNEL,
        ], $customFunnels);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get service configuration summary.
     *
     * @return array{enabled: bool, cache_ttl: int, max_cohorts: int, max_steps: int, funnel_templates: list<string>, cohort_dimensions: list<string>}
     */
    public function configSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'max_cohorts' => $this->maxCohorts,
            'max_steps' => $this->maxSteps,
            'funnel_templates' => array_keys($this->funnelTemplates),
            'cohort_dimensions' => $this->cohortDimensions,
        ];
    }

    /**
     * Get all available funnel templates.
     *
     * @return array<string, list<string>>
     */
    public function funnelTemplates(): array
    {
        return $this->funnelTemplates;
    }

    /**
     * Get a specific funnel template by name.
     *
     * @return list<string>|null
     */
    public function funnelTemplate(string $name): ?array
    {
        return $this->funnelTemplates[$name] ?? null;
    }

    /**
     * Register a custom funnel template at runtime.
     *
     * @param  string  $name  Funnel template name
     * @param  list<string>  $steps  Ordered funnel step names
     */
    public function registerFunnelTemplate(string $name, array $steps): void
    {
        $this->funnelTemplates[$name] = $steps;
    }

    /**
     * Build a cohort × funnel matrix from raw event data.
     *
     * Accepts a list of cohort labels and a funnel definition,
     * then computes the full matrix with counts, rates, and timing.
     *
     * @param  list<string>  $cohortLabels  Cohort identifiers (e.g., ['2026-W01', '2026-W02', ...])
     * @param  list<string>  $funnelSteps  Ordered funnel step event names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{cohorts: list<string>, steps: list<string>, matrix: list<array{cohort: string, step: string, count: int, rate: float, cumulative_rate: float, time_to_convert: float|null, users_count: int}>, summary: array{total_cohorts: int, total_steps: int, avg_conversion: float, best_cohort: string|null, worst_cohort: string|null, bottleneck_step: string|null}}
     */
    public function buildMatrix(array $cohortLabels, array $funnelSteps, array $cohortData): array
    {
        if (! $this->enabled) {
            return $this->disabledMatrix();
        }

        $cohortLabels = array_slice($cohortLabels, 0, $this->maxCohorts);
        $funnelSteps = array_slice($funnelSteps, 0, $this->maxSteps);

        $matrix = [];
        $cohortTotals = [];

        foreach ($cohortLabels as $cohortLabel) {
            $prevStepCount = 0;

            foreach ($funnelSteps as $stepIndex => $stepName) {
                $stepData = $cohortData[$cohortLabel][$stepName] ?? ['count' => 0, 'users' => [], 'timestamps' => []];
                $count = $stepData['count'];
                $users = $stepData['users'];
                $timestamps = $stepData['timestamps'];

                // Conversion rate from previous step (or entry for first step)
                $rate = $prevStepCount > 0
                    ? round(($count / $prevStepCount) * 100, 2)
                    : ($stepIndex === 0 ? 100.0 : 0.0);

                // Cumulative rate from first step
                $entryCount = $cohortData[$cohortLabel][$funnelSteps[0]]['count'] ?? 0;
                $cumulativeRate = $entryCount > 0
                    ? round(($count / $entryCount) * 100, 2)
                    : ($stepIndex === 0 ? 100.0 : 0.0);

                // Average time to convert from previous step
                $timeToConvert = $this->computeAverageTimeToConvert($stepData, $cohortData[$cohortLabel][$funnelSteps[max($stepIndex - 1, 0)]] ?? null);

                $matrix[] = [
                    'cohort' => $cohortLabel,
                    'step' => $stepName,
                    'count' => $count,
                    'rate' => $rate,
                    'cumulative_rate' => $cumulativeRate,
                    'time_to_convert' => $timeToConvert,
                    'users_count' => count($users),
                ];

                $prevStepCount = $count;

                $cohortTotals[$cohortLabel] = $entryCount;
            }
        }

        return [
            'cohorts' => $cohortLabels,
            'steps' => $funnelSteps,
            'matrix' => $matrix,
            'summary' => $this->computeSummary($cohortLabels, $funnelSteps, $matrix, $cohortTotals),
        ];
    }

    /**
     * Build a matrix from a predefined funnel template.
     *
     * @param  string  $templateName  Funnel template name (e.g., 'onboarding', 'purchase')
     * @param  list<string>  $cohortLabels  Cohort identifiers
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{cohorts: list<string>, steps: list<string>, matrix: list<array{cohort: string, step: string, count: int, rate: float, cumulative_rate: float, time_to_convert: float|null, users_count: int}>, summary: array{total_cohorts: int, total_steps: int, avg_conversion: float, best_cohort: string|null, worst_cohort: string|null, bottleneck_step: string|null}}
     */
    public function buildFromTemplate(string $templateName, array $cohortLabels, array $cohortData): array
    {
        $steps = $this->funnelTemplates[$templateName] ?? [];

        if ($steps === []) {
            return $this->disabledMatrix();
        }

        return $this->buildMatrix($cohortLabels, $steps, $cohortData);
    }

    /**
     * Compare two cohort's funnel progression side by side.
     *
     * Produces a diff-style comparison highlighting differences in
     * step counts, rates, and timing between two cohorts.
     *
     * @param  string  $cohortA  First cohort label
     * @param  string  $cohortB  Second cohort label
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{cohort_a: string, cohort_b: string, steps: list<string>, comparison: list<array{step: string, count_a: int, count_b: int, count_delta: int, rate_a: float, rate_b: float, rate_delta: float, time_a: float|null, time_b: float|null, time_delta: float|null}>}
     */
    public function compareCohorts(string $cohortA, string $cohortB, array $funnelSteps, array $cohortData): array
    {
        if (! $this->enabled) {
            return [
                'cohort_a' => $cohortA,
                'cohort_b' => $cohortB,
                'steps' => [],
                'comparison' => [],
            ];
        }

        $comparison = [];
        $prevCountA = ($cohortData[$cohortA][$funnelSteps[0]]['count'] ?? 0);
        $prevCountB = ($cohortData[$cohortB][$funnelSteps[0]]['count'] ?? 0);

        foreach ($funnelSteps as $stepIndex => $stepName) {
            $dataA = $cohortData[$cohortA][$stepName] ?? ['count' => 0, 'users' => [], 'timestamps' => []];
            $dataB = $cohortData[$cohortB][$stepName] ?? ['count' => 0, 'users' => [], 'timestamps' => []];

            $countA = $dataA['count'];
            $countB = $dataB['count'];

            $rateA = $stepIndex === 0 ? 100.0 : ($prevCountA > 0 ? round(($countA / $prevCountA) * 100, 2) : 0.0);
            $rateB = $stepIndex === 0 ? 100.0 : ($prevCountB > 0 ? round(($countB / $prevCountB) * 100, 2) : 0.0);

            $timeA = $this->computeAverageTimeToConvert($dataA, $cohortData[$cohortA][$funnelSteps[max($stepIndex - 1, 0)]] ?? null);
            $timeB = $this->computeAverageTimeToConvert($dataB, $cohortData[$cohortB][$funnelSteps[max($stepIndex - 1, 0)]] ?? null);

            $timeDelta = ($timeA !== null && $timeB !== null) ? round($timeB - $timeA, 2) : null;

            $comparison[] = [
                'step' => $stepName,
                'count_a' => $countA,
                'count_b' => $countB,
                'count_delta' => $countB - $countA,
                'rate_a' => $rateA,
                'rate_b' => $rateB,
                'rate_delta' => round($rateB - $rateA, 2),
                'time_a' => $timeA,
                'time_b' => $timeB,
                'time_delta' => $timeDelta,
            ];

            $prevCountA = $countA;
            $prevCountB = $countB;
        }

        return [
            'cohort_a' => $cohortA,
            'cohort_b' => $cohortB,
            'steps' => $funnelSteps,
            'comparison' => $comparison,
        ];
    }

    /**
     * Compute a heatmap-ready matrix for visualization.
     *
     * Returns a 2D array where rows = cohorts, columns = steps,
     * values = conversion percentages. Suitable for D3/Chart.js heatmaps.
     *
     * @param  list<string>  $cohortLabels  Cohort identifiers
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{cohorts: list<string>, steps: list<string>, heatmap: array<string, array<string, float>>, min: float, max: float}
     */
    public function heatmap(array $cohortLabels, array $funnelSteps, array $cohortData): array
    {
        if (! $this->enabled) {
            return [
                'cohorts' => [],
                'steps' => [],
                'heatmap' => [],
                'min' => 0.0,
                'max' => 0.0,
            ];
        }

        $heatmap = [];
        $allRates = [];

        foreach ($cohortLabels as $cohortLabel) {
            $entryCount = $cohortData[$cohortLabel][$funnelSteps[0]]['count'] ?? 0;
            $heatmap[$cohortLabel] = [];

            foreach ($funnelSteps as $stepName) {
                $count = $cohortData[$cohortLabel][$stepName]['count'] ?? 0;
                $rate = $entryCount > 0 ? round(($count / $entryCount) * 100, 2) : 0.0;
                $heatmap[$cohortLabel][$stepName] = $rate;
                $allRates[] = $rate;
            }
        }

        return [
            'cohorts' => $cohortLabels,
            'steps' => $funnelSteps,
            'heatmap' => $heatmap,
            'min' => $allRates !== [] ? min($allRates) : 0.0,
            'max' => $allRates !== [] ? max($allRates) : 0.0,
        ];
    }

    /**
     * Compute the conversion velocity index for a cohort.
     *
     * Measures how quickly a cohort progresses through a funnel
     * relative to other cohorts. Returns a score 0-100 where
     * 100 = fastest completion, 0 = no completion.
     *
     * @param  string  $cohortLabel  Cohort identifier
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array{count: int, timestamps: list<int>}>  $stepData  Step → data
     * @return array{velocity_index: float, total_time_seconds: float|null, steps_completed: int, total_steps: int, avg_step_time: float|null}
     */
    public function velocityIndex(string $cohortLabel, array $funnelSteps, array $stepData): array
    {
        if (! $this->enabled || $funnelSteps === []) {
            return [
                'velocity_index' => 0.0,
                'total_time_seconds' => null,
                'steps_completed' => 0,
                'total_steps' => count($funnelSteps),
                'avg_step_time' => null,
            ];
        }

        $completedSteps = 0;
        $totalTime = 0.0;
        $stepTimes = [];

        for ($i = 0; $i < count($funnelSteps); $i++) {
            $stepName = $funnelSteps[$i];
            $count = $stepData[$stepName]['count'] ?? 0;

            if ($count > 0) {
                $completedSteps++;

                if ($i > 0) {
                    $prevTimestamps = $stepData[$funnelSteps[$i - 1]]['timestamps'] ?? [];
                    $currentTimestamps = $stepData[$stepName]['timestamps'] ?? [];

                    if ($prevTimestamps !== [] && $currentTimestamps !== []) {
                        $avgPrev = array_sum($prevTimestamps) / count($prevTimestamps);
                        $avgCurrent = array_sum($currentTimestamps) / count($currentTimestamps);
                        $stepTime = max(0.0, $avgCurrent - $avgPrev);
                        $totalTime += $stepTime;
                        $stepTimes[] = $stepTime;
                    }
                }
            }
        }

        $totalSteps = count($funnelSteps);
        $completionRatio = $totalSteps > 0 ? $completedSteps / $totalSteps : 0;
        $avgStepTime = $stepTimes !== [] ? round(array_sum($stepTimes) / count($stepTimes), 2) : null;

        // Velocity index: 0-100 composite of completion ratio and speed
        $speedFactor = $totalTime > 0 ? max(0.0, 1.0 - ($totalTime / ($totalSteps * 86400))) : 1.0; // Normalize against 1 day per step
        $velocityIndex = round($completionRatio * $speedFactor * 100, 2);

        return [
            'velocity_index' => min(100.0, max(0.0, $velocityIndex)),
            'total_time_seconds' => $totalTime > 0 ? round($totalTime, 2) : null,
            'steps_completed' => $completedSteps,
            'total_steps' => $totalSteps,
            'avg_step_time' => $avgStepTime,
        ];
    }

    /**
     * Identify the strongest and weakest funnel steps across all cohorts.
     *
     * Aggregates step performance across cohorts to find:
     * - Best performing step (highest average cumulative rate)
     * - Worst performing step (lowest average cumulative rate)
     * - Most variable step (highest rate standard deviation)
     *
     * @param  list<string>  $cohortLabels  Cohort identifiers
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{best_step: array{step: string, avg_rate: float}, worst_step: array{step: string, avg_rate: float}, most_variable: array{step: string, std_dev: float}, step_summary: list<array{step: string, avg_count: float, avg_rate: float, min_rate: float, max_rate: float}>}
     */
    public function stepPerformanceAnalysis(array $cohortLabels, array $funnelSteps, array $cohortData): array
    {
        if (! $this->enabled || $cohortLabels === [] || $funnelSteps === []) {
            return [
                'best_step' => ['step' => '', 'avg_rate' => 0.0],
                'worst_step' => ['step' => '', 'avg_rate' => 0.0],
                'most_variable' => ['step' => '', 'std_dev' => 0.0],
                'step_summary' => [],
            ];
        }

        $stepAggregates = [];
        $stepRates = [];

        foreach ($funnelSteps as $stepName) {
            $counts = [];
            $rates = [];

            foreach ($cohortLabels as $cohortLabel) {
                $entryCount = $cohortData[$cohortLabel][$funnelSteps[0]]['count'] ?? 0;
                $stepCount = $cohortData[$cohortLabel][$stepName]['count'] ?? 0;

                $counts[] = $stepCount;
                $rates[] = $entryCount > 0 ? ($stepCount / $entryCount) * 100 : 0.0;
            }

            $avgCount = round(array_sum($counts) / count($counts), 2);
            $avgRate = round(array_sum($rates) / count($rates), 2);
            $stdDev = $this->standardDeviation($rates);

            $stepAggregates[] = [
                'step' => $stepName,
                'avg_count' => $avgCount,
                'avg_rate' => $avgRate,
                'min_rate' => round(min($rates), 2),
                'max_rate' => round(max($rates), 2),
            ];

            $stepRates[$stepName] = ['avg_rate' => $avgRate, 'std_dev' => round($stdDev, 2)];
        }

        // Sort by average rate
        usort($stepAggregates, fn (array $a, array $b): int => $b['avg_rate'] <=> $a['avg_rate']);
        $stepRatesSorted = $stepRates;

        usort($stepAggregates, fn (array $a, array $b): int => $b['avg_rate'] <=> $a['avg_rate']);

        $bestStep = $stepAggregates[0] ?? ['step' => '', 'avg_rate' => 0.0];
        $worstStep = $stepAggregates[count($stepAggregates) - 1] ?? ['step' => '', 'avg_rate' => 0.0];

        // Find most variable by std dev
        $mostVariableStep = ['step' => '', 'std_dev' => 0.0];
        foreach ($stepRatesSorted as $stepName => $data) {
            if ($data['std_dev'] > $mostVariableStep['std_dev']) {
                $mostVariableStep = ['step' => $stepName, 'std_dev' => $data['std_dev']];
            }
        }

        return [
            'best_step' => ['step' => $bestStep['step'], 'avg_rate' => $bestStep['avg_rate']],
            'worst_step' => ['step' => $worstStep['step'], 'avg_rate' => $worstStep['avg_rate']],
            'most_variable' => $mostVariableStep,
            'step_summary' => $stepAggregates,
        ];
    }

    /**
     * Generate a funnel step drop-off ranking across all cohorts.
     *
     * Ranks funnel steps by average drop-off severity,
     * identifying the most impactful optimization targets.
     *
     * @param  list<string>  $cohortLabels  Cohort identifiers
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return list<array{step: string, avg_dropoff_count: float, avg_dropoff_rate: float, severity: 'critical'|'high'|'medium'|'low'}
     */
    public function dropoffRanking(array $cohortLabels, array $funnelSteps, array $cohortData): array
    {
        if (! $this->enabled || count($cohortLabels) < 1 || count($funnelSteps) < 2) {
            return [];
        }

        $ranking = [];

        for ($i = 1; $i < count($funnelSteps); $i++) {
            $prevStep = $funnelSteps[$i - 1];
            $currStep = $funnelSteps[$i];

            $dropoffCounts = [];
            $dropoffRates = [];

            foreach ($cohortLabels as $cohortLabel) {
                $prevCount = $cohortData[$cohortLabel][$prevStep]['count'] ?? 0;
                $currCount = $cohortData[$cohortLabel][$currStep]['count'] ?? 0;

                $dropoffCounts[] = $prevCount - $currCount;
                $dropoffRates[] = $prevCount > 0 ? (($prevCount - $currCount) / $prevCount) * 100 : 0.0;
            }

            $avgDropoff = round(array_sum($dropoffCounts) / count($dropoffCounts), 2);
            $avgRate = round(array_sum($dropoffRates) / count($dropoffRates), 2);

            $ranking[] = [
                'step' => $currStep,
                'avg_dropoff_count' => $avgDropoff,
                'avg_dropoff_rate' => $avgRate,
                'severity' => $this->classifySeverity($avgRate),
            ];
        }

        // Sort by severity (highest drop-off first)
        usort($ranking, fn (array $a, array $b): int => $b['avg_dropoff_rate'] <=> $a['avg_dropoff_rate']);

        return $ranking;
    }

    /**
     * Compute the matrix with caching.
     *
     * Caches the full matrix result under a composite cache key
     * derived from the cohort labels and funnel steps.
     *
     * @param  string  $cacheKeySuffix  Unique suffix for the cache key
     * @param  list<string>  $cohortLabels  Cohort identifiers
     * @param  list<string>  $funnelSteps  Funnel step names
     * @param  array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>}  $cohortData  Cohort → step → data
     * @return array{cohorts: list<string>, steps: list<string>, matrix: list<array{cohort: string, step: string, count: int, rate: float, cumulative_rate: float, time_to_convert: float|null, users_count: int}>, summary: array{total_cohorts: int, total_steps: int, avg_conversion: float, best_cohort: string|null, worst_cohort: string|null, bottleneck_step: string|null}}
     */
    public function buildMatrixCached(string $cacheKeySuffix, array $cohortLabels, array $funnelSteps, array $cohortData): array
    {
        $cacheKey = self::CACHE_PREFIX . $cacheKeySuffix;

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($cohortLabels, $funnelSteps, $cohortData): array {
            return $this->buildMatrix($cohortLabels, $funnelSteps, $cohortData);
        });
    }

    /**
     * Clear cached matrix data.
     *
     * @return bool True if any cache entries were cleared
     */
    public function clearCache(): bool
    {
        $cleared = false;

        // Clear known cache keys via prefix deletion
        $this->cache->forget(self::CACHE_PREFIX . 'onboarding');
        $this->cache->forget(self::CACHE_PREFIX . 'purchase');
        $this->cache->forget(self::CACHE_PREFIX . 'saas_conversion');
        $this->cache->forget(self::CACHE_PREFIX . 'engagement');

        return true;
    }

    /**
     * Compute average time to convert between two steps.
     *
     * @param  array{count: int, users: list<string>, timestamps: list<int>}  $currentStep  Current step data
     * @param  array{count: int, users: list<string>, timestamps: list<int>}|null  $previousStep  Previous step data
     * @return float|null Average seconds between steps, or null if not computable
     */
    private function computeAverageTimeToConvert(array $currentStep, ?array $previousStep): ?float
    {
        if ($previousStep === null || $currentStep['count'] === 0 || $previousStep['count'] === 0) {
            return null;
        }

        $currentTimestamps = $currentStep['timestamps'];
        $previousTimestamps = $previousStep['timestamps'];

        if ($currentTimestamps === [] || $previousTimestamps === []) {
            return null;
        }

        $avgCurrent = array_sum($currentTimestamps) / count($currentTimestamps);
        $avgPrevious = array_sum($previousTimestamps) / count($previousTimestamps);

        $diff = $avgCurrent - $avgPrevious;

        return $diff > 0 ? round($diff, 2) : null;
    }

    /**
     * Compute matrix summary statistics.
     *
     * @param  list<string>  $cohortLabels  Cohort labels
     * @param  list<string>  $funnelSteps  Funnel steps
     * @param  list<array{cohort: string, step: string, count: int, rate: float, cumulative_rate: float}>  $matrix  Matrix data
     * @param  array<string, int>  $cohortTotals  Cohort → entry count
     * @return array{total_cohorts: int, total_steps: int, avg_conversion: float, best_cohort: string|null, worst_cohort: string|null, bottleneck_step: string|null}
     */
    private function computeSummary(array $cohortLabels, array $funnelSteps, array $matrix, array $cohortTotals): array
    {
        $lastStep = $funnelSteps[count($funnelSteps) - 1] ?? '';

        // Compute overall conversion rate for each cohort
        $cohortConversionRates = [];

        foreach ($cohortLabels as $cohortLabel) {
            $entryCount = $cohortTotals[$cohortLabel] ?? 0;
            $lastStepCount = 0;

            foreach ($matrix as $cell) {
                if ($cell['cohort'] === $cohortLabel && $cell['step'] === $lastStep) {
                    $lastStepCount = $cell['count'];
                    break;
                }
            }

            $cohortConversionRates[$cohortLabel] = $entryCount > 0
                ? ($lastStepCount / $entryCount) * 100
                : 0.0;
        }

        // Average conversion across cohorts
        $avgConversion = $cohortConversionRates !== []
            ? round(array_sum($cohortConversionRates) / count($cohortConversionRates), 2)
            : 0.0;

        // Best and worst cohort
        $bestCohort = null;
        $worstCohort = null;
        $bestRate = -1.0;
        $worstRate = 101.0;

        foreach ($cohortConversionRates as $label => $rate) {
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $bestCohort = $label;
            }
            if ($rate < $worstRate) {
                $worstRate = $rate;
                $worstCohort = $label;
            }
        }

        // Bottleneck step: step with lowest average step-to-step conversion rate
        $bottleneckStep = null;
        $lowestAvgRate = 101.0;

        for ($i = 1; $i < count($funnelSteps); $i++) {
            $stepRates = [];

            foreach ($cohortLabels as $cohortLabel) {
                $prevCount = 0;
                $currCount = 0;

                foreach ($matrix as $cell) {
                    if ($cell['cohort'] === $cohortLabel && $cell['step'] === $funnelSteps[$i - 1]) {
                        $prevCount = $cell['count'];
                    }
                    if ($cell['cohort'] === $cohortLabel && $cell['step'] === $funnelSteps[$i]) {
                        $currCount = $cell['count'];
                    }
                }

                $stepRates[] = $prevCount > 0 ? ($currCount / $prevCount) * 100 : 0.0;
            }

            $avgStepRate = round(array_sum($stepRates) / count($stepRates), 2);

            if ($avgStepRate < $lowestAvgRate) {
                $lowestAvgRate = $avgStepRate;
                $bottleneckStep = $funnelSteps[$i];
            }
        }

        return [
            'total_cohorts' => count($cohortLabels),
            'total_steps' => count($funnelSteps),
            'avg_conversion' => $avgConversion,
            'best_cohort' => $bestCohort,
            'worst_cohort' => $worstCohort,
            'bottleneck_step' => $bottleneckStep,
        ];
    }

    /**
     * Classify drop-off severity.
     *
     * @param  float  $rate  Drop-off rate (0-100)
     * @return 'critical'|'high'|'medium'|'low'
     */
    private function classifySeverity(float $rate): string
    {
        return match (true) {
            $rate >= 80.0 => 'critical',
            $rate >= 50.0 => 'high',
            $rate >= 20.0 => 'medium',
            default => 'low',
        };
    }

    /**
     * Compute standard deviation for a list of values.
     *
     * @param  list<float>  $values  Numeric values
     * @return float Standard deviation
     */
    private function standardDeviation(array $values): float
    {
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $squaredDiffs = array_map(fn (float $v): float => ($v - $mean) ** 2, $values);
        $variance = array_sum($squaredDiffs) / $n;

        return sqrt($variance);
    }

    /**
     * Return a disabled-state matrix.
     *
     * @return array{cohorts: list<string>, steps: list<string>, matrix: list<empty>, summary: array{total_cohorts: int, total_steps: int, avg_conversion: float, best_cohort: string|null, worst_cohort: string|null, bottleneck_step: string|null}}
     */
    private function disabledMatrix(): array
    {
        return [
            'cohorts' => [],
            'steps' => [],
            'matrix' => [],
            'summary' => [
                'total_cohorts' => 0,
                'total_steps' => 0,
                'avg_conversion' => 0.0,
                'best_cohort' => null,
                'worst_cohort' => null,
                'bottleneck_step' => null,
            ],
        ];
    }
}
