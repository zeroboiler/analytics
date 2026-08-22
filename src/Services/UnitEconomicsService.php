<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Unit economics calculator service for SaaS analytics.
 *
 * Computes subscriber-level profitability metrics essential for
 * venture-backed SaaS companies (OpenView Partners, Bessemer Venture Partners,
 * KeyBanc Capital benchmarks):
 *
 *   - **Customer Lifetime Value (LTV)** — using both simple and predictive models
 *   - **Customer Acquisition Cost (CAC)** — blended and per-channel
 *   - **LTV:CAC Ratio** — the gold standard SaaS efficiency metric (target: 3:1)
 *   - **CAC Payback Period** — months to recover acquisition cost
 *   - **Gross Margin** — revenue minus COGS (target: 70-80% for SaaS)
 *   - **Net Margin** — after all operating expenses
 *   - **Monthly Burn Rate** — cash consumption rate
 *   - **Runway** — months of cash remaining at current burn rate
 *   - **Revenue per Employee** — operational efficiency metric
 *   - **Magic Number** — sales efficiency (target: > 0.75)
 *
 * Provides cohort-based analysis (by signup month, plan, channel) and
 * trend detection for key metrics.
 *
 * This service is a pure calculator — it does not persist data.
 * Feed it raw numbers from your billing/finance database.
 *
 * Configuration: `zeroboiler.analytics.unit_economics`
 *
 * @see \ZeroBoiler\Analytics\Services\SaaSKpiCalculatorService
 *
 * @since 87.0.0
 */
final class UnitEconomicsService
{
    private const CACHE_PREFIX = 'zb_unit_econ_';

    private const DEFAULT_GROSS_MARGIN = 0.75;

    private const DEFAULT_LTV_CAC_TARGET = 3.0;

    private const DEFAULT_PAYBACK_TARGET = 18;

    private const DEFAULT_MAGIC_NUMBER_TARGET = 0.75;

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array{lifetime_months?: int, discount_rate?: float, gross_margin?: float} */
    private array $ltvConfig;

    /** @var array{ltv_cac_target?: float, payback_target_months?: int, magic_number_target?: float} */
    private array $benchmarks;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $econConfig = $config->get('zeroboiler.analytics.unit_economics', []);
        /** @var array{enabled?: bool, cache_ttl?: int, ltv?: array{lifetime_months?: int, discount_rate?: float, gross_margin?: float}, benchmarks?: array{ltv_cac_target?: float, payback_target_months?: int, magic_number_target?: float}} $econConfig */

        $this->enabled = (bool) ($econConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($econConfig['cache_ttl'] ?? 300);

        $this->ltvConfig = $econConfig['ltv'] ?? [];
        $this->benchmarks = $econConfig['benchmarks'] ?? [];
    }

    /**
     * Check if unit economics service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── LTV Models ─────────────────────────────────────────────────

    /**
     * Calculate simple LTV (ARPU × Gross Margin × Average Lifetime).
     *
     * @param  float  $arpu  Average Revenue Per User (monthly)
     * @param  float  $churnRate  Monthly churn rate (0.0 - 1.0)
     * @param  float|null  $grossMargin  Gross margin ratio (default from config)
     * @return array{ltv: float, arpu: float, lifetime_months: float, gross_margin: float, formula: string}
     */
    public function simpleLtv(float $arpu, float $churnRate, ?float $grossMargin = null): array
    {
        $margin = $grossMargin ?? $this->ltvConfig['gross_margin'] ?? self::DEFAULT_GROSS_MARGIN;
        $lifetimeMonths = $churnRate > 0 ? 1.0 / $churnRate : 120.0;
        $ltv = $arpu * $margin * $lifetimeMonths;

        return [
            'ltv' => round($ltv, 2),
            'arpu' => round($arpu, 2),
            'lifetime_months' => round($lifetimeMonths, 1),
            'gross_margin' => round($margin, 4),
            'formula' => 'ARPU × Gross Margin × (1 / Churn Rate)',
        ];
    }

    /**
     * Calculate predictive LTV using discounted cash flow.
     *
     * Accounts for the time value of money and custom billing periods,
     * producing a more accurate LTV for long-lived subscriptions.
     *
     * @param  float  $arpu  Average Revenue Per User (monthly)
     * @param  float  $churnRate  Monthly churn rate (0.0 - 1.0)
     * @param  float|null  $discountRate  Monthly discount rate (default 1% / month ≈ 12.7% annual)
     * @param  int|null  $lifetimeMonths  Cap on customer lifetime (default from config)
     * @param  float|null  $grossMargin  Gross margin ratio
     * @return array{ltv: float, arpu: float, discounted_lifetime_months: float, gross_margin: float, discount_rate: float, formula: string}
     */
    public function predictiveLtv(
        float $arpu,
        float $churnRate,
        ?float $discountRate = null,
        ?int $lifetimeMonths = null,
        ?float $grossMargin = null,
    ): array {
        $margin = $grossMargin ?? $this->ltvConfig['gross_margin'] ?? self::DEFAULT_GROSS_MARGIN;
        $rate = $discountRate ?? $this->ltvConfig['discount_rate'] ?? 0.01;
        $maxMonths = $lifetimeMonths ?? $this->ltvConfig['lifetime_months'] ?? 120;

        // DCF: LTV = Σ (ARPU × GM × (1-churn)^t / (1+rate)^t)
        $ltv = 0.0;
        $retentionFactor = 1.0 - $churnRate;
        $discountFactor = 1.0;

        for ($t = 1; $t <= $maxMonths; $t++) {
            $retentionFactor *= (1.0 - $churnRate);
            $discountFactor = 1.0 / (1.0 + $rate) ** $t;
            $ltv += $arpu * $margin * $retentionFactor * $discountFactor;

            // Early termination if contribution becomes negligible
            if ($arpu * $margin * $retentionFactor * $discountFactor < 0.001) {
                break;
            }
        }

        return [
            'ltv' => round($ltv, 2),
            'arpu' => round($arpu, 2),
            'discounted_lifetime_months' => (float) $maxMonths,
            'gross_margin' => round($margin, 4),
            'discount_rate' => round($rate, 4),
            'formula' => 'Σ (ARPU × GM × (1-churn)^t / (1+rate)^t)',
        ];
    }

    /**
     * Calculate cohort-based LTV from actual revenue data.
     *
     * Computes LTV from real cohort revenue observations rather than
     * formula-based estimates. More accurate but requires historical data.
     *
     * @param  array<int, array{month: int, revenue: float, active_users: int, churned_users: int}>  $cohortData  Monthly cohort observations
     * @return array{ltv: float, avg_monthly_arpu: float, avg_lifetime_months: float, months_observed: int, cohort_size: int}
     */
    public function cohortLtv(array $cohortData): array
    {
        if (empty($cohortData)) {
            return ['ltv' => 0.0, 'avg_monthly_arpu' => 0.0, 'avg_lifetime_months' => 0.0, 'months_observed' => 0, 'cohort_size' => 0];
        }

        $totalRevenue = 0.0;
        $totalActiveMonths = 0;
        $maxMonth = 0;

        foreach ($cohortData as $month) {
            $totalRevenue += $month['revenue'];
            $totalActiveMonths += $month['active_users'];
            $maxMonth = max($maxMonth, $month['month']);
        }

        $monthsObserved = count($cohortData);
        $avgMonthlyActive = $totalActiveMonths > 0 ? $totalActiveMonths / $monthsObserved : 0;
        $avgMonthlyArpu = $avgMonthlyActive > 0 ? $totalRevenue / $avgMonthlyActive : 0.0;

        return [
            'ltv' => round($totalRevenue, 2),
            'avg_monthly_arpu' => round($avgMonthlyArpu, 2),
            'avg_lifetime_months' => (float) $maxMonth,
            'months_observed' => $monthsObserved,
            'cohort_size' => $avgMonthlyActive,
        ];
    }

    // ── CAC Metrics ─────────────────────────────────────────────────

    /**
     * Calculate blended CAC across all channels.
     *
     * @param  float  $totalSalesMarketingSpend  Total sales + marketing spend
     * @param  int  $newCustomers  Number of new customers acquired
     * @return array{cac: float, total_spend: float, new_customers: int, formula: string}
     */
    public function blendedCac(float $totalSalesMarketingSpend, int $newCustomers): array
    {
        $cac = $newCustomers > 0 ? $totalSalesMarketingSpend / $newCustomers : 0.0;

        return [
            'cac' => round($cac, 2),
            'total_spend' => round($totalSalesMarketingSpend, 2),
            'new_customers' => $newCustomers,
            'formula' => 'Total S&M Spend / New Customers',
        ];
    }

    /**
     * Calculate per-channel CAC with efficiency comparison.
     *
     * @param  array<string, array{spend: float, customers: int}>  $channels  Channel name → {spend, customers}
     * @return array{channels: array<string, array{cac: float, spend: float, customers: int, efficiency: string}>, blended_cac: float, total_spend: float, total_customers: int, most_efficient: string|null, least_efficient: string|null}
     */
    public function channelCac(array $channels): array
    {
        $totalSpend = 0.0;
        $totalCustomers = 0;
        $results = [];

        foreach ($channels as $name => $data) {
            $spend = (float) ($data['spend'] ?? 0);
            $customers = (int) ($data['customers'] ?? 0);
            $cac = $customers > 0 ? $spend / $customers : 0.0;

            $results[$name] = [
                'cac' => round($cac, 2),
                'spend' => round($spend, 2),
                'customers' => $customers,
                'efficiency' => 'unknown',
            ];

            $totalSpend += $spend;
            $totalCustomers += $customers;
        }

        $blendedCac = $totalCustomers > 0 ? $totalSpend / $totalCustomers : 0.0;

        // Classify efficiency relative to blended CAC
        foreach ($results as $name => &$data) {
            if ($data['cac'] > 0 && $blendedCac > 0) {
                $ratio = $data['cac'] / $blendedCac;
                $data['efficiency'] = match (true) {
                    $ratio < 0.7 => 'excellent',
                    $ratio < 0.9 => 'good',
                    $ratio < 1.1 => 'average',
                    $ratio < 1.3 => 'below_average',
                    default => 'poor',
                };
            }
        }
        unset($data);

        // Sort by CAC ascending (most efficient first)
        uasort($results, fn (array $a, array $b): int => $a['cac'] <=> $b['cac']);

        return [
            'channels' => $results,
            'blended_cac' => round($blendedCac, 2),
            'total_spend' => round($totalSpend, 2),
            'total_customers' => $totalCustomers,
            'most_efficient' => array_key_first($results),
            'least_efficient' => array_key_last($results),
        ];
    }

    // ── Efficiency Ratios ─────────────────────────────────────────

    /**
     * Calculate LTV:CAC ratio with health assessment.
     *
     * @param  float  $ltv  Customer Lifetime Value
     * @param  float  $cac  Customer Acquisition Cost
     * @return array{ratio: float, ltv: float, cac: float, health: string, interpretation: string, target: float}
     */
    public function ltvCacRatio(float $ltv, float $cac): array
    {
        $ratio = $cac > 0 ? $ltv / $cac : 0.0;
        $target = (float) ($this->benchmarks['ltv_cac_target'] ?? self::DEFAULT_LTV_CAC_TARGET);

        $health = match (true) {
            $ratio >= $target * 1.5 => 'excellent',
            $ratio >= $target => 'healthy',
            $ratio >= $target * 0.7 => 'acceptable',
            $ratio >= 1.0 => 'concerning',
            default => 'critical',
        };

        $interpretation = match ($health) {
            'excellent' => 'Unit economics are exceptional. Consider increasing acquisition spend.',
            'healthy' => 'Unit economics are sustainable. Maintain current strategy.',
            'acceptable' => 'Unit economics are tight. Focus on retention and upselling.',
            'concerning' => 'Barely profitable per customer. Optimize acquisition channels urgently.',
            'critical' => 'Losing money on every customer acquired. Immediate action required.',
            default => 'Unknown',
        };

        return [
            'ratio' => round($ratio, 2),
            'ltv' => round($ltv, 2),
            'cac' => round($cac, 2),
            'health' => $health,
            'interpretation' => $interpretation,
            'target' => $target,
        ];
    }

    /**
     * Calculate CAC payback period (months to recover acquisition cost).
     *
     * @param  float  $cac  Customer Acquisition Cost
     * @param  float  $monthlyArpuGrossMargin  Monthly ARPU × Gross Margin
     * @return array{payback_months: float, cac: float, monthly_contribution: float, health: string, target_months: int}
     */
    public function cacPaybackPeriod(float $cac, float $monthlyArpuGrossMargin): array
    {
        $months = $monthlyArpuGrossMargin > 0 ? $cac / $monthlyArpuGrossMargin : PHP_FLOAT_MAX;
        $targetMonths = (int) ($this->benchmarks['payback_target_months'] ?? self::DEFAULT_PAYBACK_TARGET);

        $health = match (true) {
            $months <= $targetMonths * 0.5 => 'excellent',
            $months <= $targetMonths => 'healthy',
            $months <= $targetMonths * 1.5 => 'acceptable',
            $months <= 36 => 'concerning',
            $months === PHP_FLOAT_MAX => 'unknown',
            default => 'critical',
        };

        return [
            'payback_months' => $months === PHP_FLOAT_MAX ? -1.0 : round($months, 1),
            'cac' => round($cac, 2),
            'monthly_contribution' => round($monthlyArpuGrossMargin, 2),
            'health' => $health,
            'target_months' => $targetMonths,
        ];
    }

    /**
     * Calculate the Magic Number (SaaS sales efficiency metric).
     *
     * Magic Number = (Current Quarter ARR - Previous Quarter ARR) / Previous Quarter S&M Spend
     * Target: > 0.75 (efficient), > 1.0 (highly efficient)
     *
     * @param  float  $currentQARR  Current quarter Annual Recurring Revenue
     * @param  float  $previousQARR  Previous quarter Annual Recurring Revenue
     * @param  float  $previousQSMSpend  Previous quarter Sales & Marketing spend
     * @return array{magic_number: float, arr_growth: float, sm_spend: float, health: string, interpretation: string, target: float}
     */
    public function magicNumber(float $currentQARR, float $previousQARR, float $previousQSMSpend): array
    {
        $arrGrowth = $currentQARR - $previousQARR;
        $target = (float) ($this->benchmarks['magic_number_target'] ?? self::DEFAULT_MAGIC_NUMBER_TARGET);
        $magic = $previousQSMSpend > 0 ? $arrGrowth / $previousQSMSpend : 0.0;

        $health = match (true) {
            $magic >= $target * 1.5 => 'excellent',
            $magic >= $target => 'healthy',
            $magic >= $target * 0.5 => 'acceptable',
            $magic >= 0 => 'below_average',
            default => 'critical',
        };

        $interpretation = match ($health) {
            'excellent' => 'Sales efficiency is outstanding. Scale acquisition confidently.',
            'healthy' => 'Good sales efficiency. Maintain or slightly increase spend.',
            'acceptable' => 'Sales efficiency is moderate. Optimize funnel before scaling.',
            'below_average' => 'Inefficient sales. Investigate conversion leaks.',
            'critical' => 'ARR declining relative to spend. Urgent review needed.',
            default => 'Unknown',
        };

        return [
            'magic_number' => round($magic, 4),
            'arr_growth' => round($arrGrowth, 2),
            'sm_spend' => round($previousQSMSpend, 2),
            'health' => $health,
            'interpretation' => $interpretation,
            'target' => $target,
        ];
    }

    /**
     * Calculate gross margin percentage.
     *
     * @param  float  $revenue  Total revenue
     * @param  float  $cogs  Cost of Goods Sold (hosting, support, etc.)
     * @return array{gross_margin: float, revenue: float, cogs: float, health: string}
     */
    public function grossMargin(float $revenue, float $cogs): array
    {
        $margin = $revenue > 0 ? (($revenue - $cogs) / $revenue) : 0.0;

        $health = match (true) {
            $margin >= 0.80 => 'excellent',
            $margin >= 0.70 => 'healthy',
            $margin >= 0.60 => 'acceptable',
            $margin >= 0.40 => 'concerning',
            default => 'critical',
        };

        return [
            'gross_margin' => round($margin, 4),
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'health' => $health,
        ];
    }

    // ── Burn & Runway ──────────────────────────────────────────────

    /**
     * Calculate monthly burn rate and runway.
     *
     * @param  float  $monthlyRevenue  Monthly recurring revenue
     * @param  float  $monthlyExpenses  Total monthly operating expenses
     * @param  float  $cashBalance  Current cash balance
     * @return array{burn_rate: float, runway_months: float, net_monthly: float, revenue: float, expenses: float, cash_balance: float}
     */
    public function burnRate(float $monthlyRevenue, float $monthlyExpenses, float $cashBalance): array
    {
        $burn = $monthlyExpenses - $monthlyRevenue;
        $runway = $burn > 0 ? $cashBalance / $burn : PHP_FLOAT_MAX;

        return [
            'burn_rate' => round(max(0.0, $burn), 2),
            'runway_months' => $runway === PHP_FLOAT_MAX ? -1.0 : round($runway, 1),
            'net_monthly' => round($monthlyRevenue - $monthlyExpenses, 2),
            'revenue' => round($monthlyRevenue, 2),
            'expenses' => round($monthlyExpenses, 2),
            'cash_balance' => round($cashBalance, 2),
        ];
    }

    /**
     * Calculate revenue per employee.
     *
     * @param  float  $annualRevenue  Annual revenue
     * @param  int  $employeeCount  Total employees
     * @return array{revenue_per_employee: float, annual_revenue: float, employee_count: int, benchmark: string}
     */
    public function revenuePerEmployee(float $annualRevenue, int $employeeCount): array
    {
        $rpe = $employeeCount > 0 ? $annualRevenue / $employeeCount : 0.0;

        $benchmark = match (true) {
            $rpe >= 250_000 => 'elite',
            $rpe >= 200_000 => 'top_quartile',
            $rpe >= 150_000 => 'above_average',
            $rpe >= 100_000 => 'average',
            $rpe > 0 => 'below_average',
            default => 'unknown',
        };

        return [
            'revenue_per_employee' => round($rpe, 2),
            'annual_revenue' => round($annualRevenue, 2),
            'employee_count' => $employeeCount,
            'benchmark' => $benchmark,
        ];
    }

    // ── Comprehensive Dashboard ────────────────────────────────────

    /**
     * Generate a comprehensive unit economics dashboard.
     *
     * @param  array{arpu?: float, churn_rate?: float, cac?: float, monthly_revenue?: float, monthly_expenses?: float, cash_balance?: float, cogs?: float, annual_revenue?: float, employees?: int, current_q_arr?: float, previous_q_arr?: float, previous_q_sm_spend?: float}  $metrics
     * @return array{ltv: array<string, mixed>, cac: array<string, mixed>, ltv_cac: array<string, mixed>, payback: array<string, mixed>, magic_number: array<string, mixed>, gross_margin: array<string, mixed>, burn_rate: array<string, mixed>, revenue_per_employee: array<string, mixed>, overall_health: string}
     */
    public function dashboard(array $metrics): array
    {
        $dashboard = [];

        // LTV
        $arpu = $metrics['arpu'] ?? 0.0;
        $churn = $metrics['churn_rate'] ?? 0.0;
        $dashboard['ltv'] = $this->simpleLtv($arpu, $churn);

        // CAC
        $cac = $metrics['cac'] ?? 0.0;
        $dashboard['cac'] = [
            'cac' => round($cac, 2),
            'source' => 'provided',
        ];

        // LTV:CAC
        $ltvValue = $dashboard['ltv']['ltv'];
        $dashboard['ltv_cac'] = $this->ltvCacRatio($ltvValue, $cac);

        // Payback
        $margin = (float) ($this->ltvConfig['gross_margin'] ?? self::DEFAULT_GROSS_MARGIN);
        $dashboard['payback'] = $this->cacPaybackPeriod($cac, $arpu * $margin);

        // Magic Number
        $dashboard['magic_number'] = $this->magicNumber(
            $metrics['current_q_arr'] ?? 0.0,
            $metrics['previous_q_arr'] ?? 0.0,
            $metrics['previous_q_sm_spend'] ?? 0.0,
        );

        // Gross Margin
        $dashboard['gross_margin'] = $this->grossMargin(
            $metrics['monthly_revenue'] ?? 0.0 * 12,
            $metrics['cogs'] ?? 0.0 * 12,
        );

        // Burn Rate
        $dashboard['burn_rate'] = $this->burnRate(
            $metrics['monthly_revenue'] ?? 0.0,
            $metrics['monthly_expenses'] ?? 0.0,
            $metrics['cash_balance'] ?? 0.0,
        );

        // Revenue per Employee
        $dashboard['revenue_per_employee'] = $this->revenuePerEmployee(
            $metrics['annual_revenue'] ?? 0.0,
            (int) ($metrics['employees'] ?? 0),
        );

        // Overall health
        $healthScores = [];
        $healthScores[] = $dashboard['ltv_cac']['health'] === 'healthy' || $dashboard['ltv_cac']['health'] === 'excellent' ? 1 : 0;
        $healthScores[] = $dashboard['payback']['health'] === 'healthy' || $dashboard['payback']['health'] === 'excellent' ? 1 : 0;
        $healthScores[] = $dashboard['gross_margin']['health'] === 'healthy' || $dashboard['gross_margin']['health'] === 'excellent' ? 1 : 0;
        $healthScores[] = $dashboard['magic_number']['health'] === 'healthy' || $dashboard['magic_number']['health'] === 'excellent' ? 1 : 0;

        $healthy = array_sum($healthScores);
        $total = count($healthScores);

        $dashboard['overall_health'] = match (true) {
            $healthy === $total => 'excellent',
            $healthy >= $total * 0.75 => 'healthy',
            $healthy >= $total * 0.5 => 'acceptable',
            $healthy >= 1 => 'concerning',
            default => 'critical',
        };

        return $dashboard;
    }
}
