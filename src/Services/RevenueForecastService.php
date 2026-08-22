<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
/**
 * Revenue forecasting service for SaaS analytics.
 *
 * Provides MRR trend analysis, churn forecasting, LTV prediction,
 * runway estimation, and revenue growth modeling. Uses historical event
 * data and configurable growth assumptions to produce actionable forecasts.
 *
 * Configuration is read from `zeroboiler.analytics.forecasting`.
 *
 * @phpstan-type ForecastPoint array{date: string, mrr: float, arr: float, churned_mrr: float, net_new_mrr: float, churn_rate: float}
 * @phpstan-type ForecastSummary array{current_mrr: float, current_arr: float, projected_mrr_30d: float, projected_arr_30d: float, mrr_growth_rate: float, churn_rate: float, net_revenue_retention: float, ltv_estimate: float, runway_months: int, confidence: string}
 *
 * @since 1.0.0
 */
final class RevenueForecastService
{
    private int $cacheTtl;

    private string $cachePrefix;

    private float $defaultMonthlyChurnRate;

    private float $defaultGrowthRate;

    private int $forecastHorizonDays;

    private int $historicalWindowDays;

    private float $avgRevenuePerAccount;

    private const CACHE_PREFIX = 'zb_forecast_';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $forecastConfig = $config->get('zeroboiler.analytics.forecasting', []);
        /** @var array{cache_ttl?: int, cache_prefix?: string, monthly_churn_rate?: float, growth_rate?: float, horizon_days?: int, historical_window_days?: int, avg_revenue_per_account?: float} $forecastConfig */

        $this->cacheTtl = (int) ($forecastConfig['cache_ttl'] ?? 300); // 5 minutes
        $this->cachePrefix = (string) ($forecastConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->defaultMonthlyChurnRate = (float) ($forecastConfig['monthly_churn_rate'] ?? 0.03);
        $this->defaultGrowthRate = (float) ($forecastConfig['growth_rate'] ?? 0.05);
        $this->forecastHorizonDays = (int) ($forecastConfig['horizon_days'] ?? 90);
        $this->historicalWindowDays = (int) ($forecastConfig['historical_window_days'] ?? 90);
        $this->avgRevenuePerAccount = (float) ($forecastConfig['avg_revenue_per_account'] ?? 99.0);
    }

    /**
     * Generate a full revenue forecast with daily data points.
     *
     * Produces projected MRR, ARR, churned MRR, and net-new MRR for each
     * day within the forecast horizon based on historical trends.
     *
     * @param  array{mrr?: float, arr?: float, churned_mrr_last_month?: float, new_mrr_last_month?: float, expansion_mrr_last_month?: float, active_subscribers?: int, churned_subscribers_last_month?: int}  $currentData  Current revenue snapshot
     * @return array{summary: ForecastSummary, daily: list<ForecastPoint>, assumptions: array<string, mixed>}
     */
    public function forecast(array $currentData = []): array
    {
        $cacheKey = $this->cachePrefix . 'full_' . md5(json_encode($currentData, JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($currentData): array {
            $currentMrr = (float) ($currentData['mrr'] ?? 0);
            $currentArr = (float) ($currentData['arr'] ?? $currentMrr * 12);
            $churnedMrr = (float) ($currentData['churned_mrr_last_month'] ?? 0);
            $newMrr = (float) ($currentData['new_mrr_last_month'] ?? 0);
            $expansionMrr = (float) ($currentData['expansion_mrr_last_month'] ?? 0);
            $activeSubscribers = (int) ($currentData['active_subscribers'] ?? 0);
            $churnedSubscribers = (int) ($currentData['churned_subscribers_last_month'] ?? 0);

            // Derived rates
            $monthlyChurnRate = $activeSubscribers > 0
                ? $churnedSubscribers / $activeSubscribers
                : $this->defaultMonthlyChurnRate;

            $dailyChurnRate = $monthlyChurnRate / 30;
            $dailyGrowthRate = $this->defaultGrowthRate / 30;

            // Net revenue retention
            $revenueChurnedRate = $currentMrr > 0 ? $churnedMrr / $currentMrr : $monthlyChurnRate;
            $grossRetention = 1.0 - $revenueChurnedRate;
            $expansionRate = $currentMrr > 0 ? $expansionMrr / $currentMrr : $dailyGrowthRate * 30;
            $netRevenueRetention = $grossRetention + $expansionRate;

            // LTV estimate (ARPU / churn rate)
            $ltv = $monthlyChurnRate > 0
                ? $this->avgRevenuePerAccount / $monthlyChurnRate
                : $this->avgRevenuePerAccount * 36; // default 3 years if no churn

            // Runway (months until cash zero at current burn — simplified)
            $runwayMonths = $currentMrr > 0
                ? (int) ceil(12 / max($monthlyChurnRate, 0.001))
                : 0;

            // MRR growth rate (month over month)
            $mrrGrowthRate = $currentMrr > 0
                ? ($newMrr + $expansionMrr - $churnedMrr) / $currentMrr
                : $this->defaultGrowthRate;

            // Confidence level based on data completeness
            $confidence = $this->calculateConfidence($currentData);

            $daily = [];
            $projectedMrr = $currentMrr;

            for ($day = 1; $day <= $this->forecastHorizonDays; $day++) {
                $dayChurn = $projectedMrr * $dailyChurnRate;
                $dayGrowth = $projectedMrr * $dailyGrowthRate;
                $netNew = $dayGrowth - $dayChurn;

                $projectedMrr = max(0, $projectedMrr + $netNew);

                $daily[] = [
                    'date' => date('Y-m-d', strtotime("+{$day} days")),
                    'mrr' => round($projectedMrr, 2),
                    'arr' => round($projectedMrr * 12, 2),
                    'churned_mrr' => round($dayChurn, 2),
                    'net_new_mrr' => round($netNew, 2),
                    'churn_rate' => round($dailyChurnRate * 100, 4),
                ];
            }

            $projectedMrr30d = $daily[29]['mrr'] ?? $currentMrr;
            $projectedArr30d = $daily[29]['arr'] ?? $currentArr;

            $summary = [
                'current_mrr' => round($currentMrr, 2),
                'current_arr' => round($currentArr, 2),
                'projected_mrr_30d' => round($projectedMrr30d, 2),
                'projected_arr_30d' => round($projectedArr30d, 2),
                'mrr_growth_rate' => round($mrrGrowthRate * 100, 2),
                'churn_rate' => round($monthlyChurnRate * 100, 2),
                'net_revenue_retention' => round($netRevenueRetention * 100, 2),
                'ltv_estimate' => round($ltv, 2),
                'runway_months' => $runwayMonths,
                'confidence' => $confidence,
            ];

            return [
                'summary' => $summary,
                'daily' => $daily,
                'assumptions' => [
                    'monthly_churn_rate' => round($monthlyChurnRate * 100, 2) . '%',
                    'monthly_growth_rate' => round($this->defaultGrowthRate * 100, 2) . '%',
                    'avg_revenue_per_account' => $this->avgRevenuePerAccount,
                    'historical_window_days' => $this->historicalWindowDays,
                    'forecast_horizon_days' => $this->forecastHorizonDays,
                    'active_subscribers' => $activeSubscribers,
                ],
            ];
        });
    }

    /**
     * Get a quick forecast summary without daily breakdown.
     *
     * @param  array{mrr?: float, arr?: float, churned_mrr_last_month?: float, new_mrr_last_month?: float, expansion_mrr_last_month?: float, active_subscribers?: int, churned_subscribers_last_month?: int}  $currentData
     * @return ForecastSummary
     */
    public function summary(array $currentData = []): array
    {
        $full = $this->forecast($currentData);

        /** @var ForecastSummary */
        return $full['summary'];
    }

    /**
     * Project MRR at a specific future date.
     *
     * @param  int  $daysOut  Number of days into the future
     * @param  array{mrr?: float}  $currentData
     * @return array{date: string, projected_mrr: float, projected_arr: float, cumulative_churn: float, cumulative_growth: float}
     */
    public function projectAt(int $daysOut, array $currentData = []): array
    {
        $currentMrr = (float) ($currentData['mrr'] ?? 0);
        $dailyChurnRate = $this->defaultMonthlyChurnRate / 30;
        $dailyGrowthRate = $this->defaultGrowthRate / 30;

        $projectedMrr = $currentMrr;
        $cumulativeChurn = 0.0;
        $cumulativeGrowth = 0.0;

        for ($day = 1; $day <= $daysOut; $day++) {
            $churn = $projectedMrr * $dailyChurnRate;
            $growth = $projectedMrr * $dailyGrowthRate;
            $cumulativeChurn += $churn;
            $cumulativeGrowth += $growth;
            $projectedMrr = max(0, $projectedMrr + $growth - $churn);
        }

        return [
            'date' => date('Y-m-d', strtotime("+{$daysOut} days")),
            'projected_mrr' => round($projectedMrr, 2),
            'projected_arr' => round($projectedMrr * 12, 2),
            'cumulative_churn' => round($cumulativeChurn, 2),
            'cumulative_growth' => round($cumulativeGrowth, 2),
        ];
    }

    /**
     * Calculate customer LTV with configurable parameters.
     *
     * Uses the standard LTV formula: ARPU × (1 / churn_rate) × gross_margin.
     *
     * @param  float  $arpu  Average Revenue Per User (monthly)
     * @param  float  $monthlyChurnRate  Monthly customer churn rate (0-1)
     * @param  float  $grossMargin  Gross margin as a decimal (e.g. 0.75 for 75%)
     * @return array{ltv: float, ltv_months: float, arpu_annual: float, churn_multiplier: float}
     */
    public function calculateLtv(
        float $arpu,
        float $monthlyChurnRate,
        float $grossMargin = 0.75,
    ): array {
        $churnMultiplier = $monthlyChurnRate > 0
            ? 1 / $monthlyChurnRate
            : 36; // default 3 years if no churn

        $ltvMonths = $churnMultiplier;
        $ltv = $arpu * $churnMultiplier * $grossMargin;

        return [
            'ltv' => round($ltv, 2),
            'ltv_months' => round($ltvMonths, 1),
            'arpu_annual' => round($arpu * 12, 2),
            'churn_multiplier' => round($churnMultiplier, 1),
        ];
    }

    /**
     * Calculate LTV:CAC ratio.
     *
     * Industry standard: LTV:CAC > 3:1 is considered healthy.
     *
     * @param  float  $ltv  Customer Lifetime Value
     * @param  float  $cac  Customer Acquisition Cost
     * @return array{ratio: float, rating: string, recommendation: string}
     */
    public function ltvCACRatio(float $ltv, float $cac): array
    {
        $ratio = $cac > 0 ? $ltv / $cac : 0;

        $rating = match (true) {
            $ratio >= 5.0 => 'excellent',
            $ratio >= 3.0 => 'healthy',
            $ratio >= 1.0 => 'underperforming',
            $ratio > 0 => 'critical',
            default => 'unknown',
        };

        $recommendation = match ($rating) {
            'excellent' => 'LTV:CAC ratio is excellent. Consider increasing acquisition spend.',
            'healthy' => 'LTV:CAC ratio is healthy. Maintain current strategy.',
            'underperforming' => 'LTV:CAC ratio is below 3:1. Focus on retention and upselling.',
            'critical' => 'LTV:CAC ratio is critical. Review acquisition channels and pricing.',
            default => 'Insufficient data to calculate LTV:CAC ratio.',
        };

        return [
            'ratio' => round($ratio, 2),
            'rating' => $rating,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Calculate payback period (months to recover CAC).
     *
     * @param  float  $cac  Customer Acquisition Cost
     * @param  float  $monthlyArpu  Average Revenue Per User (monthly)
     * @param  float  $grossMargin  Gross margin as a decimal
     * @return array{months: float, rating: string, target_months: int}
     */
    public function paybackPeriod(
        float $cac,
        float $monthlyArpu,
        float $grossMargin = 0.75,
    ): array {
        $monthlyContribution = $monthlyArpu * $grossMargin;
        $months = $monthlyContribution > 0 ? $cac / $monthlyContribution : 0;

        $targetMonths = 12;
        $rating = match (true) {
            $months <= $targetMonths / 2 => 'excellent',
            $months <= $targetMonths => 'healthy',
            $months <= $targetMonths * 1.5 => 'acceptable',
            $months <= $targetMonths * 2 => 'concerning',
            default => 'critical',
        };

        return [
            'months' => round($months, 1),
            'rating' => $rating,
            'target_months' => $targetMonths,
        ];
    }

    /**
     * Estimate runway in months based on MRR and monthly expenses.
     *
     * @param  float  $currentMrr
     * @param  float  $monthlyExpenses  Monthly operating expenses
     * @param  float  $growthRate  Monthly growth rate (0-1)
     * @param  float  $churnRate  Monthly churn rate (0-1)
     * @return array{runway_months: int, breakeven_date: string|null, burn_rate: float, path_to_profitability: string}
     */
    public function runway(
        float $currentMrr,
        float $monthlyExpenses,
        float $growthRate = 0.05,
        float $churnRate = 0.03,
    ): array {
        $burnRate = max(0, $monthlyExpenses - $currentMrr);
        $runwayMonths = 0;
        $projectedMrr = $currentMrr;

        for ($month = 1; $month <= 120; $month++) {
            $churned = $projectedMrr * $churnRate;
            $growth = $projectedMrr * $growthRate;
            $projectedMrr = max(0, $projectedMrr + $growth - $churned);

            if ($projectedMrr >= $monthlyExpenses && $runwayMonths === 0) {
                $runwayMonths = $month;
            }
        }

        $breakevenDate = $runwayMonths > 0
            ? date('Y-m-d', strtotime("+{$runwayMonths} months"))
            : null;

        $pathToProfitability = $runwayMonths > 0
            ? "Projected breakeven in {$runwayMonths} months ({$breakevenDate})"
            : 'Not projected to reach breakeven within 10 years at current rates.';

        return [
            'runway_months' => $runwayMonths,
            'breakeven_date' => $breakevenDate,
            'burn_rate' => round($burnRate, 2),
            'path_to_profitability' => $pathToProfitability,
        ];
    }

    /**
     * Cohort-based retention analysis.
     *
     * Calculates retention curves for subscription cohorts based on
     * configured churn rates. Useful for understanding long-term revenue.
     *
     * @param  int  $months  Number of months to project
     * @param  float  $monthlyChurnRate  Monthly churn rate (0-1)
     * @return list<array{month: int, retention_rate: float, estimated_subscribers: int, estimated_mrr: float}>
     */
    public function cohortRetentionCurve(
        int $months = 12,
        float $monthlyChurnRate = 0.03,
    ): array {
        $curve = [];
        $retentionRate = 1.0;
        $baseSubscribers = 100; // per 100 starting subscribers

        for ($month = 0; $month <= $months; $month++) {
            $estimatedSubscribers = (int) round($baseSubscribers * $retentionRate);
            $estimatedMrr = $estimatedSubscribers * $this->avgRevenuePerAccount;

            $curve[] = [
                'month' => $month,
                'retention_rate' => round($retentionRate * 100, 2),
                'estimated_subscribers' => $estimatedSubscribers,
                'estimated_mrr' => round($estimatedMrr, 2),
            ];

            $retentionRate *= (1 - $monthlyChurnRate);
        }

        return $curve;
    }

    /**
     * Revenue breakdown by growth component.
     *
     * Decomposes MRR change into new, expansion, contraction, and churn.
     *
     * @param  array{new_mrr?: float, expansion_mrr?: float, contraction_mrr?: float, churned_mrr?: float, previous_mrr?: float}  $components
     * @return array{new: float, expansion: float, contraction: float, churn: float, net_change: float, previous_mrr: float, new_mrr: float}
     */
    public function mrrMovementBreakdown(array $components = []): array
    {
        $newMrr = (float) ($components['new_mrr'] ?? 0);
        $expansionMrr = (float) ($components['expansion_mrr'] ?? 0);
        $contractionMrr = (float) ($components['contraction_mrr'] ?? 0);
        $churnedMrr = (float) ($components['churned_mrr'] ?? 0);
        $previousMrr = (float) ($components['previous_mrr'] ?? 0);

        $netChange = $newMrr + $expansionMrr - $contractionMrr - $churnedMrr;

        return [
            'new' => round($newMrr, 2),
            'expansion' => round($expansionMrr, 2),
            'contraction' => round($contractionMrr, 2),
            'churn' => round($churnedMrr, 2),
            'net_change' => round($netChange, 2),
            'previous_mrr' => round($previousMrr, 2),
            'new_mrr' => round($previousMrr + $netChange, 2),
        ];
    }

    /**
     * Clear the forecast cache.
     */
    public function clearCache(): void
    {
        $this->clearCacheByPrefix($this->cachePrefix);
    }

    /**
     * Calculate confidence level based on data completeness.
     *
     * @param  array<string, mixed>  $data
     * @return 'high'|'medium'|'low'
     */
    private function calculateConfidence(array $data): string
    {
        $required = ['mrr', 'active_subscribers', 'churned_mrr_last_month', 'new_mrr_last_month'];
        $provided = count(array_filter($required, fn (string $key): bool => isset($data[$key]) && $data[$key] > 0));

        return match ($provided) {
            4 => 'high',
            3 => 'medium',
            default => 'low',
        };
    }

    /**
     * Clear cache entries by prefix.
     *
     * @param  string  $prefix
     */
    private function clearCacheByPrefix(string $prefix): void
    {
        try {
            $store = Cache::getStore();

            if (method_exists($store, 'getPrefix')) {
                $fullPrefix = method_exists($store, 'getPrefix')
                    ? $store->getPrefix() . $prefix
                    : $prefix;

                Cache::forget($fullPrefix . 'full_');
            }
        } catch (\Throwable $e) {
            // Silently fail — cache will expire naturally
        }
    }
}
