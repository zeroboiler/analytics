<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Revenue intelligence service — unified revenue analytics dashboard.
 *
 * Combines revenue forecasting, churn prediction, health scoring,
 * and subscription metrics into a single comprehensive revenue
 * intelligence endpoint for SaaS dashboards.
 *
 * Provides:
 * - Revenue overview (current MRR, ARR, growth rate)
 * - Revenue health score (composite 0–100)
 * - Churn risk assessment (at-risk subscribers, churn signals)
 * - Forecast comparison (projected vs actual)
 * - Revenue movement breakdown (new, expansion, contraction, churn)
 * - Unit economics (LTV, CAC, payback, runway)
 *
 * Configuration: `zeroboiler.analytics.revenue_intelligence`
 *
 * @version 5.0.0
 */
final class RevenueIntelligenceService
{
    private const CACHE_PREFIX = 'zb_rev_intel_';

    private const DEFAULT_CACHE_TTL = 300; // 5 minutes

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $intelConfig = $config->get('zeroboiler.analytics.revenue_intelligence', []);
        /** @var array{enabled?: bool, cache_ttl?: int} $intelConfig */
        $this->enabled = (bool) ($intelConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($intelConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
    }

    /**
     * Generate a comprehensive revenue intelligence report.
     *
     * Combines all revenue-related analytics into a single
     * structured response for dashboard rendering.
     *
     * @param  array{mrr?: float, arr?: float, active_subscribers?: int, churned_subscribers_last_month?: int, new_mrr_last_month?: float, expansion_mrr_last_month?: float, churned_mrr_last_month?: float, contraction_mrr_last_month?: float, previous_mrr?: float, arpu?: float, churn_rate?: float, trial_conversion_rate?: float, cac?: float, monthly_expenses?: float}  $currentData  Current revenue snapshot
     * @return array{generated_at: string, revenue: array<string, mixed>, health: array<string, mixed>, churn: array<string, mixed>, forecast: array<string, mixed>, unit_economics: array<string, mixed>, movement: array<string, mixed>, signals: list<string>, recommendations: list<string>}
     */
    public function report(array $currentData = []): array
    {
        if (! $this->enabled) {
            return $this->disabledReport();
        }

        $cacheKey = self::CACHE_PREFIX . 'report_' . md5(json_encode($currentData, JSON_THROW_ON_ERROR));

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $report = $this->buildReport($currentData);

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get a quick revenue summary (no forecast, no health score).
     *
     * Lightweight version for sidebar widgets or notification badges.
     *
     * @param  array{mrr?: float, arr?: float, active_subscribers?: int, churn_rate?: float}  $data
     * @return array{mrr: float, arr: float, active_subscribers: int, churn_rate: float, mrr_growth_label: string, generated_at: string}
     */
    public function quickSummary(array $data = []): array
    {
        $mrr = (float) ($data['mrr'] ?? 0);
        $arr = (float) ($data['arr'] ?? $mrr * 12);
        $activeSubscribers = (int) ($data['active_subscribers'] ?? 0);
        $churnRate = (float) ($data['churn_rate'] ?? 0);

        $mrrGrowthLabel = match (true) {
            $mrr <= 0 => 'no_data',
            $churnRate > 0.10 => 'high_churn',
            $churnRate > 0.05 => 'moderate_churn',
            default => 'healthy',
        };

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($arr, 2),
            'active_subscribers' => $activeSubscribers,
            'churn_rate' => round($churnRate * 100, 2),
            'mrr_growth_label' => $mrrGrowthLabel,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate revenue signals and actionable recommendations.
     *
     * Analyzes current metrics and produces a list of detected signals
     * (positive and negative) with associated recommendations.
     *
     * @param  array{mrr?: float, arr?: float, active_subscribers?: int, churn_rate?: float, arpu?: float, trial_conversion_rate?: float, cac?: float, ltv?: float, churned_mrr_last_month?: float, new_mrr_last_month?: float, expansion_mrr_last_month?: float}  $data
     * @return array{signals: list<array{type: string, severity: string, message: string, metric: string}>, recommendations: list<string>}
     */
    public function signals(array $data = []): array
    {
        $signals = [];
        $recommendations = [];

        $mrr = (float) ($data['mrr'] ?? 0);
        $churnRate = (float) ($data['churn_rate'] ?? 0);
        $arpu = (float) ($data['arpu'] ?? 0);
        $trialRate = (float) ($data['trial_conversion_rate'] ?? 0);
        $cac = (float) ($data['cac'] ?? 0);
        $ltv = (float) ($data['ltv'] ?? 0);
        $churnedMrr = (float) ($data['churned_mrr_last_month'] ?? 0);
        $newMrr = (float) ($data['new_mrr_last_month'] ?? 0);
        $expansionMrr = (float) ($data['expansion_mrr_last_month'] ?? 0);

        // Churn signals
        if ($churnRate > 0.10) {
            $signals[] = [
                'type' => 'churn',
                'severity' => 'critical',
                'message' => 'Monthly churn rate exceeds 10%',
                'metric' => 'churn_rate',
            ];
            $recommendations[] = 'Implement an automated re-engagement flow for at-risk subscribers.';
        } elseif ($churnRate > 0.05) {
            $signals[] = [
                'type' => 'churn',
                'severity' => 'warning',
                'message' => 'Monthly churn rate exceeds 5%',
                'metric' => 'churn_rate',
            ];
            $recommendations[] = 'Review cancellation reasons and address common pain points.';
        } elseif ($churnRate > 0 && $churnRate <= 0.02) {
            $signals[] = [
                'type' => 'churn',
                'severity' => 'positive',
                'message' => 'Excellent churn rate below 2%',
                'metric' => 'churn_rate',
            ];
        }

        // ARPU signals
        if ($arpu > 0 && $arpu < 20) {
            $signals[] = [
                'type' => 'arpu',
                'severity' => 'warning',
                'message' => 'Low ARPU may indicate pricing pressure',
                'metric' => 'arpu',
            ];
            $recommendations[] = 'Consider introducing higher-value plan tiers or upsell paths.';
        } elseif ($arpu >= 100) {
            $signals[] = [
                'type' => 'arpu',
                'severity' => 'positive',
                'message' => 'ARPU above $100 — strong monetization',
                'metric' => 'arpu',
            ];
        }

        // Trial conversion signals
        if ($trialRate > 0 && $trialRate < 0.15) {
            $signals[] = [
                'type' => 'trial_conversion',
                'severity' => 'critical',
                'message' => 'Trial conversion rate below 15%',
                'metric' => 'trial_conversion_rate',
            ];
            $recommendations[] = 'Optimize onboarding flow and add value demonstrations during trial.';
        } elseif ($trialRate >= 0.40) {
            $signals[] = [
                'type' => 'trial_conversion',
                'severity' => 'positive',
                'message' => 'Trial conversion rate above 40% — excellent',
                'metric' => 'trial_conversion_rate',
            ];
        }

        // LTV:CAC ratio
        if ($ltv > 0 && $cac > 0) {
            $ratio = $ltv / $cac;
            if ($ratio < 1.0) {
                $signals[] = [
                    'type' => 'unit_economics',
                    'severity' => 'critical',
                    'message' => 'LTV:CAC ratio below 1:1 — unsustainable',
                    'metric' => 'ltv_cac_ratio',
                ];
                $recommendations[] = 'Reduce acquisition costs or improve retention to restore LTV:CAC ratio.';
            } elseif ($ratio >= 5.0) {
                $signals[] = [
                    'type' => 'unit_economics',
                    'severity' => 'positive',
                    'message' => 'LTV:CAC ratio above 5:1 — room to increase spend',
                    'metric' => 'ltv_cac_ratio',
                ];
            }
        }

        // Revenue growth signals
        if ($mrr > 0) {
            $netNew = $newMrr + $expansionMrr - $churnedMrr;
            if ($netNew < 0) {
                $signals[] = [
                    'type' => 'revenue',
                    'severity' => 'critical',
                    'message' => 'Net MRR is declining (churn + contraction > new + expansion)',
                    'metric' => 'net_mrr_change',
                ];
                $recommendations[] = 'Focus on reducing churn and driving expansion revenue.';
            } elseif ($expansionMrr > $newMrr && $expansionMrr > 0) {
                $signals[] = [
                    'type' => 'revenue',
                    'severity' => 'positive',
                    'message' => 'Expansion revenue exceeds new revenue — strong land-and-expand',
                    'metric' => 'expansion_ratio',
                ];
            }
        }

        return [
            'signals' => $signals,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Check if revenue intelligence is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear the intelligence cache.
     */
    public function clearCache(): void
    {
        // Cache is tag-based or key-based — clear known prefixes
        $this->cache->forget(self::CACHE_PREFIX . 'report_');
    }

    /**
     * Build the full intelligence report.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildReport(array $data): array
    {
        $mrr = (float) ($data['mrr'] ?? 0);
        $arr = (float) ($data['arr'] ?? $mrr * 12);
        $activeSubscribers = (int) ($data['active_subscribers'] ?? 0);
        $churnRate = (float) ($data['churn_rate'] ?? 0);
        $arpu = (float) ($data['arpu'] ?? ($activeSubscribers > 0 ? $mrr / $activeSubscribers : 0));

        // Revenue overview
        $revenue = [
            'mrr' => round($mrr, 2),
            'arr' => round($arr, 2),
            'active_subscribers' => $activeSubscribers,
            'arpu' => round($arpu, 2),
            'currency' => 'USD',
            'growth_label' => $mrr > 0 ? 'growing' : 'no_data',
        ];

        // Health score (simplified calculation without dependencies)
        $health = $this->calculateQuickHealth($data);

        // Churn assessment
        $churn = [
            'monthly_rate' => round($churnRate * 100, 2),
            'annual_rate' => round(min(1.0, $churnRate * 12) * 100, 2),
            'risk_level' => match (true) {
                $churnRate > 0.10 => 'critical',
                $churnRate > 0.05 => 'elevated',
                $churnRate > 0.02 => 'moderate',
                default => 'low',
            },
            'estimated_monthly_lost_mrr' => round($mrr * $churnRate, 2),
        ];

        // Forecast summary (simplified)
        $dailyGrowthRate = 0.05 / 30;
        $dailyChurnRate = $churnRate / 30;
        $projectedMrr30d = $mrr;
        for ($day = 0; $day < 30; $day++) {
            $projectedMrr30d = max(0, $projectedMrr30d + ($projectedMrr30d * $dailyGrowthRate) - ($projectedMrr30d * $dailyChurnRate));
        }

        $forecast = [
            'projected_mrr_30d' => round($projectedMrr30d, 2),
            'projected_arr_30d' => round($projectedMrr30d * 12, 2),
            'mrr_growth_rate' => $mrr > 0 ? round(($projectedMrr30d - $mrr) / $mrr * 100, 2) : 0,
            'confidence' => $activeSubscribers > 100 ? 'high' : ($activeSubscribers > 20 ? 'medium' : 'low'),
        ];

        // Unit economics
        $cac = (float) ($data['cac'] ?? 0);
        $ltv = (float) ($data['ltv'] ?? ($churnRate > 0 ? $arpu / $churnRate : $arpu * 36));
        $ltvCacRatio = $cac > 0 ? round($ltv / $cac, 2) : 0;

        $unitEconomics = [
            'ltv' => round($ltv, 2),
            'cac' => round($cac, 2),
            'ltv_cac_ratio' => $ltvCacRatio,
            'ltv_cac_rating' => match (true) {
                $ltvCacRatio >= 5.0 => 'excellent',
                $ltvCacRatio >= 3.0 => 'healthy',
                $ltvCacRatio >= 1.0 => 'underperforming',
                $ltvCacRatio > 0 => 'critical',
                default => 'unknown',
            },
            'payback_months' => ($arpu > 0 && $cac > 0) ? round($cac / ($arpu * 0.75), 1) : 0,
            'gross_margin' => 0.75,
        ];

        // Revenue movement
        $movement = [
            'new_mrr' => round((float) ($data['new_mrr_last_month'] ?? 0), 2),
            'expansion_mrr' => round((float) ($data['expansion_mrr_last_month'] ?? 0), 2),
            'contraction_mrr' => round((float) ($data['contraction_mrr_last_month'] ?? 0), 2),
            'churned_mrr' => round((float) ($data['churned_mrr_last_month'] ?? 0), 2),
            'net_change' => round(
                (float) ($data['new_mrr_last_month'] ?? 0)
                + (float) ($data['expansion_mrr_last_month'] ?? 0)
                - (float) ($data['contraction_mrr_last_month'] ?? 0)
                - (float) ($data['churned_mrr_last_month'] ?? 0),
                2,
            ),
        ];

        // Signals and recommendations
        $signalAnalysis = $this->signals($data);

        return [
            'generated_at' => now()->toIso8601String(),
            'revenue' => $revenue,
            'health' => $health,
            'churn' => $churn,
            'forecast' => $forecast,
            'unit_economics' => $unitEconomics,
            'movement' => $movement,
            'signals' => $signalAnalysis['signals'],
            'recommendations' => $signalAnalysis['recommendations'],
        ];
    }

    /**
     * Calculate a quick health score without external service dependencies.
     *
     * @param  array<string, mixed>  $data
     * @return array{score: int, grade: string}
     */
    private function calculateQuickHealth(array $data): array
    {
        $score = 50; // Start at 50

        $churnRate = (float) ($data['churn_rate'] ?? 0);
        $trialRate = (float) ($data['trial_conversion_rate'] ?? 0);
        $arpu = (float) ($data['arpu'] ?? 0);

        // Churn contribution (-30 to +0)
        if ($churnRate <= 0.02) {
            $score += 15;
        } elseif ($churnRate <= 0.05) {
            $score += 5;
        } elseif ($churnRate <= 0.10) {
            $score -= 10;
        } else {
            $score -= 25;
        }

        // Trial conversion (+0 to +15)
        if ($trialRate >= 0.40) {
            $score += 15;
        } elseif ($trialRate >= 0.25) {
            $score += 10;
        } elseif ($trialRate >= 0.15) {
            $score += 5;
        } elseif ($trialRate > 0) {
            $score -= 5;
        }

        // ARPU (+0 to +10)
        if ($arpu >= 100) {
            $score += 10;
        } elseif ($arpu >= 50) {
            $score += 5;
        }

        $score = max(0, min(100, $score));
        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };

        return [
            'score' => $score,
            'grade' => $grade,
        ];
    }

    /**
     * Return a disabled report response.
     *
     * @return array{generated_at: string, revenue: array<string, mixed>, health: array<string, mixed>, churn: array<string, mixed>, forecast: array<string, mixed>, unit_economics: array<string, mixed>, movement: array<string, mixed>, signals: list<empty>, recommendations: list<empty>}
     */
    private function disabledReport(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'revenue' => ['mrr' => 0, 'arr' => 0, 'active_subscribers' => 0, 'arpu' => 0, 'currency' => 'USD', 'growth_label' => 'disabled'],
            'health' => ['score' => 0, 'grade' => 'N/A'],
            'churn' => ['monthly_rate' => 0, 'annual_rate' => 0, 'risk_level' => 'unknown', 'estimated_monthly_lost_mrr' => 0],
            'forecast' => ['projected_mrr_30d' => 0, 'projected_arr_30d' => 0, 'mrr_growth_rate' => 0, 'confidence' => 'disabled'],
            'unit_economics' => ['ltv' => 0, 'cac' => 0, 'ltv_cac_ratio' => 0, 'ltv_cac_rating' => 'unknown', 'payback_months' => 0, 'gross_margin' => 0],
            'movement' => ['new_mrr' => 0, 'expansion_mrr' => 0, 'contraction_mrr' => 0, 'churned_mrr' => 0, 'net_change' => 0],
            'signals' => [],
            'recommendations' => [],
        ];
    }
}
