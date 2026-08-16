<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * SaaS subscription metrics calculator.
 *
 * Pure calculation service for common SaaS business metrics:
 * MRR (Monthly Recurring Revenue), ARR (Annual Recurring Revenue),
 * churn rate, net revenue retention, CLV (Customer Lifetime Value),
 * ARPU (Average Revenue Per User), and runway calculations.
 *
 * All methods are stateless and accept raw data — no database or cache
 * dependencies. Designed to be called from commands, API endpoints,
 * scheduled reports, or dashboard services.
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\SaaSHealthScoreService
 *
 * @since 1.0.0
 */
final class SubscriptionMetricsCalculator
{
    // ── Core Revenue Metrics ───────────────────────────────────────────

    /**
     * Calculate Monthly Recurring Revenue.
     *
     * Sums all active subscription amounts for a single month.
     *
     * @param  array<int, array{amount: float, plan: string, status: string}>  $subscriptions  Active subscriptions
     * @return array{mrr: float, by_plan: array<string, float>, subscriber_count: int}
     */
    public static function calculateMrr(array $subscriptions): array
    {
        $mrr = 0.0;
        $byPlan = [];

        foreach ($subscriptions as $sub) {
            $status = strtolower($sub['status'] ?? 'active');
            if ($status === 'active' || $status === 'trialing') {
                $amount = (float) ($sub['amount'] ?? 0);
                $plan = (string) ($sub['plan'] ?? 'unknown');
                $mrr += $amount;
                $byPlan[$plan] = ($byPlan[$plan] ?? 0) + $amount;
            }
        }

        return [
            'mrr' => round($mrr, 2),
            'by_plan' => array_map(fn (float $v): float => round($v, 2), $byPlan),
            'subscriber_count' => count(array_filter(
                $subscriptions,
                fn (array $s): bool => in_array(strtolower($s['status'] ?? ''), ['active', 'trialing'], true),
            )),
        ];
    }

    /**
     * Calculate Annual Recurring Revenue from MRR.
     *
     * @param  float  $mrr  Monthly Recurring Revenue
     */
    public static function mrrToArr(float $mrr): float
    {
        return round($mrr * 12, 2);
    }

    // ── Churn Metrics ──────────────────────────────────────────────────

    /**
     * Calculate monthly churn rate.
     *
     * Churn rate = customers lost in period / customers at start of period.
     *
     * @param  int  $customersAtStart  Total customers at the beginning of the period
     * @param  int  $customersLost  Customers who churned during the period
     * @return array{rate: float, percentage: float, customers_lost: int, customers_remaining: int}
     */
    public static function churnRate(int $customersAtStart, int $customersLost): array
    {
        if ($customersAtStart <= 0) {
            return [
                'rate' => 0.0,
                'percentage' => 0.0,
                'customers_lost' => $customersLost,
                'customers_remaining' => 0,
            ];
        }

        $rate = $customersLost / $customersAtStart;

        return [
            'rate' => round($rate, 6),
            'percentage' => round($rate * 100, 2),
            'customers_lost' => $customersLost,
            'customers_remaining' => max(0, $customersAtStart - $customersLost),
        ];
    }

    /**
     * Calculate revenue churn rate (MRR lost vs total MRR).
     *
     * @param  float  $mrrAtStart  MRR at the beginning of the period
     * @param  float  $mrrLost  MRR from churned customers
     * @return array{rate: float, percentage: float, mrr_lost: float}
     */
    public static function revenueChurnRate(float $mrrAtStart, float $mrrLost): array
    {
        if ($mrrAtStart <= 0) {
            return ['rate' => 0.0, 'percentage' => 0.0, 'mrr_lost' => $mrrLost];
        }

        $rate = $mrrLost / $mrrAtStart;

        return [
            'rate' => round($rate, 6),
            'percentage' => round($rate * 100, 2),
            'mrr_lost' => round($mrrLost, 2),
        ];
    }

    /**
     * Calculate Net Revenue Retention (NRR).
     *
     * NRR = (Starting MRR + Expansion - Contraction - Churn) / Starting MRR.
     * Values > 100% indicate net expansion (existing customers growing).
     *
     * @param  float  $startingMrr  MRR at period start
     * @param  float  $expansion  New MRR from upgrades/cross-sells
     * @param  float  $contraction  MRR lost from downgrades
     * @param  float  $churn  MRR lost from cancellations
     * @return array{nrr: float, percentage: float, expansion: float, contraction: float, churn: float}
     */
    public static function netRevenueRetention(
        float $startingMrr,
        float $expansion,
        float $contraction,
        float $churn,
    ): array {
        if ($startingMrr <= 0) {
            return [
                'nrr' => 0.0,
                'percentage' => 0.0,
                'expansion' => round($expansion, 2),
                'contraction' => round($contraction, 2),
                'churn' => round($churn, 2),
            ];
        }

        $nrr = ($startingMrr + $expansion - $contraction - $churn) / $startingMrr;

        return [
            'nrr' => round($nrr, 6),
            'percentage' => round($nrr * 100, 2),
            'expansion' => round($expansion, 2),
            'contraction' => round($contraction, 2),
            'churn' => round($churn, 2),
        ];
    }

    // ── Unit Economics ──────────────────────────────────────────────────

    /**
     * Calculate Average Revenue Per User (ARPU).
     *
     * @param  float  $totalRevenue  Total revenue in the period
     * @param  int  $totalUsers  Total unique users (paying + non-paying)
     * @return array{arpu: float, arppu: float, paying_users: int, paying_ratio: float}
     */
    public static function arpu(
        float $totalRevenue,
        int $totalUsers,
        int $payingUsers = 0,
    ): array {
        if ($totalUsers <= 0) {
            return [
                'arpu' => 0.0,
                'arppu' => 0.0,
                'paying_users' => $payingUsers,
                'paying_ratio' => 0.0,
            ];
        }

        $arpu = $totalRevenue / $totalUsers;
        $arppu = $payingUsers > 0 ? $totalRevenue / $payingUsers : 0.0;

        return [
            'arpu' => round($arpu, 2),
            'arppu' => round($arppu, 2),
            'paying_users' => $payingUsers,
            'paying_ratio' => round($payingUsers / $totalUsers, 4),
        ];
    }

    /**
     * Estimate Customer Lifetime Value (CLV).
     *
     * Uses the simple CLV formula: ARPU × (1 / Churn Rate).
     * For a more accurate estimate, pass ARPPU (paying-user ARPU) instead.
     *
     * @param  float  $arpu  Monthly revenue per user
     * @param  float  $monthlyChurnRate  Monthly churn rate (0.0-1.0)
     * @return array{clv_monthly: float, clv_annual: float, months: float, assumption: string}
     */
    public static function customerLifetimeValue(float $arpu, float $monthlyChurnRate): array
    {
        if ($monthlyChurnRate <= 0 || $monthlyChurnRate >= 1) {
            return [
                'clv_monthly' => 0.0,
                'clv_annual' => 0.0,
                'months' => 0.0,
                'assumption' => $monthlyChurnRate <= 0
                    ? 'zero churn — infinite CLV'
                    : '100% churn — zero CLV',
            ];
        }

        $months = 1 / $monthlyChurnRate;
        $clvMonthly = $arpu * $months;

        return [
            'clv_monthly' => round($clvMonthly, 2),
            'clv_annual' => round($clvMonthly / 12, 2),
            'months' => round($months, 1),
            'assumption' => 'simple CLV = ARPU × (1 / churn_rate)',
        ];
    }

    /**
     * Calculate CLV:CAC ratio (Customer Lifetime Value to Customer Acquisition Cost).
     *
     * Healthy SaaS products target 3:1 or higher. Below 1:1 means
     * losing money on every customer acquired.
     *
     * @param  float  $clv  Customer Lifetime Value
     * @param  float  $cac  Customer Acquisition Cost
     * @return array{ratio: float, verdict: string, healthy: bool}
     */
    public static function clvToCacRatio(float $clv, float $cac): array
    {
        if ($cac <= 0) {
            return ['ratio' => 0.0, 'verdict' => 'undefined (zero CAC)', 'healthy' => false];
        }

        $ratio = $clv / $cac;

        if ($ratio >= 5) {
            $verdict = 'excellent — consider investing more in growth';
            $healthy = true;
        } elseif ($ratio >= 3) {
            $verdict = 'healthy — sustainable unit economics';
            $healthy = true;
        } elseif ($ratio >= 1) {
            $verdict = 'marginal — optimize CAC or improve retention';
            $healthy = false;
        } else {
            $verdict = 'critical — losing money per customer';
            $healthy = false;
        }

        return [
            'ratio' => round($ratio, 2),
            'verdict' => $verdict,
            'healthy' => $healthy,
        ];
    }

    // ── Runway & Growth ─────────────────────────────────────────────────

    /**
     * Calculate runway (months of operation at current burn rate).
     *
     * @param  float  $currentCash  Current cash balance
     * @param  float  $monthlyBurn  Monthly net burn (expenses - revenue)
     * @return array{months: float, days: int, healthy: bool}
     */
    public static function runway(float $currentCash, float $monthlyBurn): array
    {
        if ($monthlyBurn <= 0) {
            return ['months' => 0.0, 'days' => 0, 'healthy' => true];
        }

        $months = $currentCash / $monthlyBurn;

        return [
            'months' => round($months, 1),
            'days' => (int) floor($months * 30.44),
            'healthy' => $months >= 6,
        ];
    }

    /**
     * Calculate month-over-month growth rate.
     *
     * @param  float  $currentMonth  Current month metric (MRR, users, etc.)
     * @param  float  $previousMonth  Previous month metric
     * @return array{rate: float, percentage: float, direction: string}
     */
    public static function momGrowth(float $currentMonth, float $previousMonth): array
    {
        if ($previousMonth <= 0) {
            return [
                'rate' => 0.0,
                'percentage' => 0.0,
                'direction' => 'no_data',
            ];
        }

        $rate = ($currentMonth - $previousMonth) / $previousMonth;

        return [
            'rate' => round($rate, 6),
            'percentage' => round($rate * 100, 2),
            'direction' => $rate > 0 ? 'growth' : ($rate < 0 ? 'decline' : 'flat'),
        ];
    }

    // ── Composite Dashboard Summary ─────────────────────────────────────

    /**
     * Generate a comprehensive SaaS metrics dashboard summary.
     *
     * Computes all key metrics from raw subscription data in a single call.
     * Designed for admin dashboards and scheduled reports.
     *
     * @param  array{subscriptions: array<int, array{amount: float, plan: string, status: string}>, total_users: int, paying_users: int, mrr_previous: float, customers_at_start: int, customers_lost: int, mrr_lost: float, expansion: float, contraction: float, current_cash: float, monthly_burn: float, cac: float}  $data
     * @return array{mrr: array, arr: float, churn: array, revenue_churn: array, nrr: array, arpu: array, clv: array, clv_cac: array, runway: array, growth: array}
     */
    public static function dashboardSummary(array $data): array
    {
        $mrr = self::calculateMrr($data['subscriptions'] ?? []);
        $arr = self::mrrToArr($mrr['mrr']);

        $churn = self::churnRate(
            $data['customers_at_start'] ?? 0,
            $data['customers_lost'] ?? 0,
        );

        $revenueChurn = self::revenueChurnRate(
            $data['mrr'] ?? $mrr['mrr'],
            $data['mrr_lost'] ?? 0,
        );

        $nrr = self::netRevenueRetention(
            $data['mrr'] ?? $mrr['mrr'],
            $data['expansion'] ?? 0,
            $data['contraction'] ?? 0,
            $data['mrr_lost'] ?? 0,
        );

        $arpuResult = self::arpu(
            $data['mrr'] ?? $mrr['mrr'],
            $data['total_users'] ?? 1,
            $data['paying_users'] ?? $mrr['subscriber_count'],
        );

        $monthlyChurnRate = $churn['rate'];
        $clv = self::customerLifetimeValue(
            $arpuResult['arppu'],
            $monthlyChurnRate > 0 ? $monthlyChurnRate : 0.05,
        );

        $clvCac = self::clvToCacRatio(
            $clv['clv_monthly'],
            $data['cac'] ?? 0,
        );

        $runway = self::runway(
            $data['current_cash'] ?? 0,
            $data['monthly_burn'] ?? 0,
        );

        $growth = self::momGrowth(
            $mrr['mrr'],
            $data['mrr_previous'] ?? $mrr['mrr'],
        );

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'churn' => $churn,
            'revenue_churn' => $revenueChurn,
            'nrr' => $nrr,
            'arpu' => $arpuResult,
            'clv' => $clv,
            'clv_cac' => $clvCac,
            'runway' => $runway,
            'growth' => $growth,
        ];
    }
}
