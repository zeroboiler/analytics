<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Industry-standard SaaS KPI calculator service.
 *
 * Provides pure-computation methods for the core SaaS metrics used by
 * venture-backed companies (OpenView, Bessemer, KeyBanc benchmarks):
 *
 *   - MRR (Monthly Recurring Revenue)
 *   - ARR (Annual Recurring Revenue)
 *   - ARPU (Average Revenue Per User)
 *   - ARPA (Average Revenue Per Account)
 *   - LTV (Customer Lifetime Value) — using LTV = ARPU × (1 / Churn Rate)
 *   - LTV:CAC Ratio
 *   - Net Revenue Retention (NRR)
 *   - Gross Revenue Retention (GRR)
 *   - Churn Rate (customer and revenue)
 *   - Trial-to-Paid Conversion Rate
 *   - Payback Period (months to recover CAC)
 *   - Quick Ratio (growth efficiency: (MRR New + Expansion) / Contraction + Churn)
 *   - Rule of 40 (growth rate + profit margin ≥ 40%)
 *
 * This service is a calculator — it does not persist data. Feed it raw
 * numbers from your subscription/billing database to compute metrics.
 *
 * Configuration: `zeroboiler.analytics.saas_kpi_calc`
 *
 * @see \ZeroBoiler\Analytics\Services\SaasKpiTracker
 *
 * @since 6.8.0
 */
final class SaaSKpiCalculatorService
{
    private const CACHE_PREFIX = 'zb_kpi_calc_';

    private const DEFAULT_TTL = 300; // 5 minutes (computed metrics are short-lived)

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array{mrr_goal?: float, churn_warning?: float, ltv_cac_target?: float, quick_ratio_target?: float, rule_of_40_target?: float} */
    private array $benchmarks;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $kpiConfig = $config->get('zeroboiler.analytics.saas_kpi_calc', []);
        /** @var array{enabled?: bool, cache_ttl?: int, mrr_goal?: float, churn_warning?: float, ltv_cac_target?: float, quick_ratio_target?: float, rule_of_40_target?: float} $kpiConfig */

        $this->enabled = (bool) ($kpiConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($kpiConfig['cache_ttl'] ?? self::DEFAULT_TTL);

        $this->benchmarks = [
            'mrr_goal' => (float) ($kpiConfig['mrr_goal'] ?? 10000),
            'churn_warning' => (float) ($kpiConfig['churn_warning'] ?? 0.05),
            'ltv_cac_target' => (float) ($kpiConfig['ltv_cac_target'] ?? 3.0),
            'quick_ratio_target' => (float) ($kpiConfig['quick_ratio_target'] ?? 4.0),
            'rule_of_40_target' => (float) ($kpiConfig['rule_of_40_target'] ?? 40.0),
        ];
    }

    /**
     * Check if KPI calculator is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── Core Revenue Metrics ────────────────────────────────────

    /**
     * Calculate MRR from individual subscription amounts.
     *
     * @param  list<array{amount: float, billing_cycle: string, status: string}>  $subscriptions  Active subscriptions
     * @return float Monthly Recurring Revenue
     */
    public function mrr(array $subscriptions): float
    {
        $total = 0.0;

        foreach ($subscriptions as $sub) {
            $status = (string) ($sub['status'] ?? 'active');

            if (! in_array($status, ['active', 'trialing', 'past_due'], true)) {
                continue;
            }

            $amount = (float) ($sub['amount'] ?? 0);
            $cycle = (string) ($sub['billing_cycle'] ?? 'monthly');

            $total += match ($cycle) {
                'monthly' => $amount,
                'quarterly' => $amount / 3,
                'semi_annually', 'semi-annually' => $amount / 6,
                'annually', 'yearly' => $amount / 12,
                default => $amount,
            };
        }

        return round($total, 2);
    }

    /**
     * Calculate ARR from MRR.
     *
     * @param  float  $mrr  Monthly Recurring Revenue
     * @return float Annual Recurring Revenue
     */
    public function arr(float $mrr): float
    {
        return round($mrr * 12, 2);
    }

    /**
     * Calculate ARPU (Average Revenue Per User).
     *
     * ARPU = MRR / Active Subscribers
     *
     * @param  float  $mrr  Monthly Recurring Revenue
     * @param  int  $activeSubscribers  Count of active subscribers
     * @return float ARPU (0 if no subscribers)
     */
    public function arpu(float $mrr, int $activeSubscribers): float
    {
        if ($activeSubscribers <= 0) {
            return 0.0;
        }

        return round($mrr / $activeSubscribers, 2);
    }

    // ── Churn Metrics ────────────────────────────────────────────

    /**
     * Calculate customer churn rate.
     *
     * Churn Rate = Customers Churned / Customers at Start of Period
     *
     * @param  int  $churnedCustomers  Number of customers who churned in the period
     * @param  int  $startCustomers  Number of customers at the start of the period
     * @return float Churn rate as decimal (e.g. 0.05 = 5%)
     */
    public function churnRate(int $churnedCustomers, int $startCustomers): float
    {
        if ($startCustomers <= 0) {
            return 0.0;
        }

        return round($churnedCustomers / $startCustomers, 4);
    }

    /**
     * Calculate revenue churn rate.
     *
     * Revenue Churn = MRR Lost from Churn / MRR at Start of Period
     *
     * @param  float  $mrrLost  MRR lost from churned customers
     * @param  float  $startMrr  MRR at the start of the period
     * @return float Revenue churn rate as decimal
     */
    public function revenueChurnRate(float $mrrLost, float $startMrr): float
    {
        if ($startMrr <= 0) {
            return 0.0;
        }

        return round($mrrLost / $startMrr, 4);
    }

    // ── LTV & CAC ───────────────────────────────────────────────

    /**
     * Calculate Customer Lifetime Value (simple formula).
     *
     * LTV = ARPU × (1 / Churn Rate)
     *
     * Warning: Returns 0 if churn rate is 0 (infinite LTV) or negative.
     *
     * @param  float  $arpu  Average Revenue Per User (monthly)
     * @param  float  $monthlyChurnRate  Monthly churn rate as decimal
     * @return float Estimated LTV in months of revenue
     */
    public function ltv(float $arpu, float $monthlyChurnRate): float
    {
        if ($monthlyChurnRate <= 0) {
            return 0.0; // Infinite LTV — cannot compute
        }

        return round($arpu / $monthlyChurnRate, 2);
    }

    /**
     * Calculate LTV:CAC ratio.
     *
     * Benchmark: > 3:1 is healthy, > 5:1 is excellent.
     *
     * @param  float  $ltv  Customer Lifetime Value
     * @param  float  $cac  Customer Acquisition Cost
     * @return float LTV:CAC ratio (0 if CAC is 0)
     */
    public function ltvCacRatio(float $ltv, float $cac): float
    {
        if ($cac <= 0) {
            return 0.0;
        }

        return round($ltv / $cac, 2);
    }

    /**
     * Calculate CAC payback period in months.
     *
     * Payback Period = CAC / ARPU
     *
     * Benchmark: < 12 months is healthy.
     *
     * @param  float  $cac  Customer Acquisition Cost
     * @param  float  $arpu  Monthly ARPU
     * @return float Months to recover CAC (0 if ARPU is 0)
     */
    public function paybackPeriod(float $cac, float $arpu): float
    {
        if ($arpu <= 0) {
            return 0.0;
        }

        return round($cac / $arpu, 1);
    }

    // ── Revenue Retention ────────────────────────────────────────

    /**
     * Calculate Net Revenue Retention (NRR).
     *
     * NRR = (Starting MRR + Expansion - Contraction - Churn) / Starting MRR
     *
     * Benchmark: > 100% means net negative churn (best SaaS companies hit 110-130%).
     *
     * @param  float  $startMrr  MRR at the start of the period
     * @param  float  $expansionMrr  New MRR from upsells/cross-sells
     * @param  float  $contractionMrr  MRR lost from downgrades
     * @param  float  $churnMrr  MRR lost from churn
     * @return float NRR as decimal (e.g. 1.05 = 105%)
     */
    public function netRevenueRetention(
        float $startMrr,
        float $expansionMrr,
        float $contractionMrr,
        float $churnMrr,
    ): float {
        if ($startMrr <= 0) {
            return 0.0;
        }

        $endingMrr = $startMrr + $expansionMrr - $contractionMrr - $churnMrr;

        return round($endingMrr / $startMrr, 4);
    }

    /**
     * Calculate Gross Revenue Retention (GRR).
     *
     * GRR = (Starting MRR - Contraction - Churn) / Starting MRR
     *
     * GRR ignores expansion. Benchmark: > 90% is healthy.
     *
     * @param  float  $startMrr  MRR at the start of the period
     * @param  float  $contractionMrr  MRR lost from downgrades
     * @param  float  $churnMrr  MRR lost from churn
     * @return float GRR as decimal (e.g. 0.92 = 92%)
     */
    public function grossRevenueRetention(
        float $startMrr,
        float $contractionMrr,
        float $churnMrr,
    ): float {
        if ($startMrr <= 0) {
            return 0.0;
        }

        return round(($startMrr - $contractionMrr - $churnMrr) / $startMrr, 4);
    }

    // ── Growth Efficiency ────────────────────────────────────────

    /**
     * Calculate Quick Ratio (growth efficiency).
     *
     * Quick Ratio = (New MRR + Expansion MRR) / (Contraction MRR + Churn MRR)
     *
     * Benchmark: > 4.0 is excellent.
     *
     * @param  float  $newMrr  New customer MRR
     * @param  float  $expansionMrr  Expansion MRR from existing customers
     * @param  float  $contractionMrr  Contraction MRR
     * @param  float  $churnMrr  Churn MRR
     * @return float Quick Ratio (0 if denominator is 0)
     */
    public function quickRatio(
        float $newMrr,
        float $expansionMrr,
        float $contractionMrr,
        float $churnMrr,
    ): float {
        $numerator = $newMrr + $expansionMrr;
        $denominator = $contractionMrr + $churnMrr;

        if ($denominator <= 0) {
            // No churn or contraction — infinite growth efficiency
            return $numerator > 0 ? 999.0 : 0.0;
        }

        return round($numerator / $denominator, 2);
    }

    /**
     * Calculate Rule of 40 score.
     *
     * Rule of 40 = Growth Rate (%) + Profit Margin (%)
     * Target: ≥ 40% indicates a healthy SaaS business.
     *
     * @param  float  $growthRate  Revenue growth rate as percentage (e.g. 50 for 50%)
     * @param  float  $profitMargin  Profit margin as percentage (e.g. -10 for -10%)
     * @return float Rule of 40 score
     */
    public function ruleOf40(float $growthRate, float $profitMargin): float
    {
        return round($growthRate + $profitMargin, 1);
    }

    // ── Trial & Conversion ────────────────────────────────────────

    /**
     * Calculate trial-to-paid conversion rate.
     *
     * @param  int  $trialConversions  Trials that converted to paid
     * @param  int  $totalTrials  Total trials started in the period
     * @return float Conversion rate as decimal (e.g. 0.25 = 25%)
     */
    public function trialConversionRate(int $trialConversions, int $totalTrials): float
    {
        if ($totalTrials <= 0) {
            return 0.0;
        }

        return round($trialConversions / $totalTrials, 4);
    }

    /**
     * Calculate activation rate (percentage of signups that reach a key milestone).
     *
     * @param  int  $activatedUsers  Users who reached activation milestone
     * @param  int  $totalSignups  Total signups in the period
     * @return float Activation rate as decimal
     */
    public function activationRate(int $activatedUsers, int $totalSignups): float
    {
        if ($totalSignups <= 0) {
            return 0.0;
        }

        return round($activatedUsers / $totalSignups, 4);
    }

    // ── Comprehensive Dashboard ───────────────────────────────────

    /**
     * Compute a full SaaS KPI dashboard from raw subscription data.
     *
     * @param  array{subscriptions: list<array{amount: float, billing_cycle: string, status: string}>, active_subscribers: int, churned_customers: int, start_customers: int, mrr_lost: float, start_mrr: float, expansion_mrr: float, contraction_mrr: float, new_mrr: float, churn_mrr: float, cac: float, trial_conversions: int, total_trials: int, activated_users: int, total_signups: int, growth_rate: float, profit_margin: float}  $data
     * @return array{mrr: float, arr: float, arpu: float, churn_rate: float, revenue_churn_rate: float, ltv: float, ltv_cac_ratio: float, payback_months: float, nrr: float, grr: float, quick_ratio: float, rule_of_40: float, trial_conversion_rate: float, activation_rate: float, health: array<string, string>}
     */
    public function computeDashboard(array $data): array
    {
        $mrr = $this->mrr($data['subscriptions'] ?? []);
        $arr = $this->arr($mrr);
        $arpu = $this->arpu($mrr, $data['active_subscribers'] ?? 0);
        $churnRate = $this->churnRate($data['churned_customers'] ?? 0, $data['start_customers'] ?? 0);
        $revenueChurn = $this->revenueChurnRate($data['mrr_lost'] ?? 0, $data['start_mrr'] ?? 0);
        $ltv = $this->ltv($arpu, $churnRate);
        $ltvCac = $this->ltvCacRatio($ltv, $data['cac'] ?? 0);
        $payback = $this->paybackPeriod($data['cac'] ?? 0, $arpu);
        $nrr = $this->netRevenueRetention(
            $data['start_mrr'] ?? 0,
            $data['expansion_mrr'] ?? 0,
            $data['contraction_mrr'] ?? 0,
            $data['churn_mrr'] ?? 0,
        );
        $grr = $this->grossRevenueRetention(
            $data['start_mrr'] ?? 0,
            $data['contraction_mrr'] ?? 0,
            $data['churn_mrr'] ?? 0,
        );
        $quickRatio = $this->quickRatio(
            $data['new_mrr'] ?? 0,
            $data['expansion_mrr'] ?? 0,
            $data['contraction_mrr'] ?? 0,
            $data['churn_mrr'] ?? 0,
        );
        $ruleOf40 = $this->ruleOf40($data['growth_rate'] ?? 0, $data['profit_margin'] ?? 0);
        $trialRate = $this->trialConversionRate($data['trial_conversions'] ?? 0, $data['total_trials'] ?? 0);
        $activationRate = $this->activationRate($data['activated_users'] ?? 0, $data['total_signups'] ?? 0);

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'arpu' => $arpu,
            'churn_rate' => $churnRate,
            'revenue_churn_rate' => $revenueChurn,
            'ltv' => $ltv,
            'ltv_cac_ratio' => $ltvCac,
            'payback_months' => $payback,
            'nrr' => $nrr,
            'grr' => $grr,
            'quick_ratio' => $quickRatio,
            'rule_of_40' => $ruleOf40,
            'trial_conversion_rate' => $trialRate,
            'activation_rate' => $activationRate,
            'health' => $this->assessHealth($churnRate, $ltvCac, $nrr, $quickRatio, $ruleOf40),
        ];
    }

    /**
     * Assess overall SaaS health based on computed metrics.
     *
     * @return array<string, string> Key → status ('healthy', 'warning', 'critical')
     */
    public function assessHealth(
        float $churnRate,
        float $ltvCac,
        float $nrr,
        float $quickRatio,
        float $ruleOf40,
    ): array {
        return [
            'churn' => $churnRate > $this->benchmarks['churn_warning'] ? 'warning' : 'healthy',
            'ltv_cac' => $ltvCac >= $this->benchmarks['ltv_cac_target'] ? 'healthy' : 'warning',
            'nrr' => $nrr >= 1.0 ? 'healthy' : ($nrr >= 0.9 ? 'warning' : 'critical'),
            'quick_ratio' => $quickRatio >= $this->benchmarks['quick_ratio_target'] ? 'healthy' : 'warning',
            'rule_of_40' => $ruleOf40 >= $this->benchmarks['rule_of_40_target'] ? 'healthy' : 'warning',
            'overall' => $this->overallHealth($churnRate, $ltvCac, $nrr, $quickRatio, $ruleOf40),
        ];
    }

    /**
     * Determine overall health status.
     */
    private function overallHealth(
        float $churnRate,
        float $ltvCac,
        float $nrr,
        float $quickRatio,
        float $ruleOf40,
    ): string {
        $criticalCount = 0;
        $warningCount = 0;

        if ($churnRate > $this->benchmarks['churn_warning']) {
            $warningCount++;
        }
        if ($ltvCac < $this->benchmarks['ltv_cac_target']) {
            $warningCount++;
        }
        if ($nrr < 0.9) {
            $criticalCount++;
        } elseif ($nrr < 1.0) {
            $warningCount++;
        }
        if ($quickRatio < $this->benchmarks['quick_ratio_target']) {
            $warningCount++;
        }
        if ($ruleOf40 < $this->benchmarks['rule_of_40_target']) {
            $warningCount++;
        }

        if ($criticalCount > 0) {
            return 'critical';
        }

        if ($warningCount >= 3) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get configured benchmarks.
     *
     * @return array{mrr_goal: float, churn_warning: float, ltv_cac_target: float, quick_ratio_target: float, rule_of_40_target: float}
     */
    public function getBenchmarks(): array
    {
        return $this->benchmarks;
    }
}
