<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\SaaS\MrrMovementEvent;

/**
 * SaaS Revenue Waterfall tracking service.
 *
 * Tracks and aggregates Monthly Recurring Revenue (MRR) movements
 * categorized by type: new, expansion, contraction, reactivation, churn.
 * Provides waterfall chart data, MRR trend analysis, and growth rate calculations.
 *
 * Inspired by industry-standard revenue waterfall models from ChartMogul,
 * Baremetrics, and ProfitWell/Stripe.
 *
 * @since 78.0.0
 */
final class RevenueWaterfallService
{
    private const CACHE_PREFIX = 'zb_revenue_waterfall_';
    private const VALID_MOVEMENT_TYPES = ['new', 'expansion', 'contraction', 'reactivation', 'churn'];

    private CacheRepository $cache;
    private AnalyticsManager $manager;
    private int $cacheTtl;
    private string $defaultCurrency;

    /** @var array<string, int> Movement type counts for current month */
    private array $movementCounts = [];

    /** @var array<string, float> Movement type amounts for current month */
    private array $movementAmounts = [];

    public function __construct(
        CacheRepository $cache,
        AnalyticsManager $manager,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;
        $this->manager = $manager;

        /** @var array{cache_ttl?: int, currency?: string} $waterfallConfig */
        $waterfallConfig = $config->get('zeroboiler.analytics.revenue_waterfall', []);
        $this->cacheTtl = $waterfallConfig['cache_ttl'] ?? 300; // 5 minutes
        $this->defaultCurrency = $waterfallConfig['currency'] ?? 'USD';

        $this->loadCurrentMonthData();
    }

    /**
     * Record an MRR movement event and dispatch it to analytics providers.
     *
     * @param  string  $movementType  One of: new, expansion, contraction, reactivation, churn
     * @param  float  $amount  MRR amount
     * @param  array<string, mixed>  $context  Additional context (customer_id, plan_id, etc.)
     *
     * @throws \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException if movement type is invalid
     */
    public function recordMovement(
        string $movementType,
        float $amount,
        array $context = [],
    ): void {
        if (! in_array($movementType, self::VALID_MOVEMENT_TYPES, true)) {
            throw new InvalidAnalyticsArgumentException(
                sprintf('Invalid MRR movement type: %s. Must be one of: %s', $movementType, implode(', ', self::VALID_MOVEMENT_TYPES)),
            );
        }

        $event = new MrrMovementEvent(
            movementType: $movementType,
            amount: $amount,
            customerId: $context['customer_id'] ?? null,
            planId: $context['plan_id'] ?? null,
            previousPlanId: $context['previous_plan_id'] ?? null,
            currency: $context['currency'] ?? $this->defaultCurrency,
            billingCycle: $context['billing_cycle'] ?? null,
            reason: $context['reason'] ?? null,
            effectiveDate: $context['effective_date'] ?? null,
        );

        $this->manager->trackEvent($event);
        $this->persistMovement($movementType, $amount);
    }

    /**
     * Get the revenue waterfall for a given period.
     *
     * Returns a waterfall breakdown showing starting MRR, each movement
     * type's contribution, and the ending MRR for the period.
     *
     * @return array{starting_mrr: float, new: float, expansion: float, contraction: float, reactivation: float, churn: float, net_change: float, ending_mrr: float, growth_rate: float, currency: string, period: string}
     */
    public function waterfall(string $period = 'current_month'): array
    {
        $cacheKey = self::CACHE_PREFIX . $period;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->calculateWaterfall($period);
        $this->cache->put($cacheKey, $data, $this->cacheTtl);

        return $data;
    }

    /**
     * Get MRR movement summary grouped by type.
     *
     * @return array{new: array{count: int, amount: float, avg_deal_size: float}, expansion: array{count: int, amount: float, avg_deal_size: float}, contraction: array{count: int, amount: float, avg_deal_size: float}, reactivation: array{count: int, amount: float, avg_deal_size: float}, churn: array{count: int, amount: float, avg_deal_size: float}}
     */
    public function movementSummary(): array
    {
        $summary = [];

        foreach (self::VALID_MOVEMENT_TYPES as $type) {
            $count = $this->movementCounts[$type] ?? 0;
            $amount = $this->movementAmounts[$type] ?? 0.0;
            $summary[$type] = [
                'count' => $count,
                'amount' => round($amount, 2),
                'avg_deal_size' => $count > 0 ? round($amount / $count, 2) : 0.0,
            ];
        }

        return $summary;
    }

    /**
     * Get MRR trend data for charting.
     *
     * Returns monthly MRR data points for the last N months.
     *
     * @return list<array{period: string, starting_mrr: float, ending_mrr: float, net_change: float, new: float, expansion: float, contraction: float, churn: float}>
     */
    public function mrrTrend(int $months = 12): array
    {
        $cacheKey = self::CACHE_PREFIX . 'trend_' . $months;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $trend = [];
        $currentMonth = new \DateTimeImmutable('first day of this month');

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = $currentMonth->modify("-{$i} months");
            $periodKey = $monthDate->format('Y-m');
            $data = $this->calculateWaterfall($periodKey);

            $trend[] = [
                'period' => $periodKey,
                'starting_mrr' => $data['starting_mrr'],
                'ending_mrr' => $data['ending_mrr'],
                'net_change' => $data['net_change'],
                'new' => $data['new'],
                'expansion' => $data['expansion'],
                'contraction' => $data['contraction'],
                'churn' => $data['churn'],
            ];
        }

        $this->cache->put($cacheKey, $trend, $this->cacheTtl);

        return $trend;
    }

    /**
     * Get net MRR retention rate.
     *
     * Net MRR Retention = (Starting MRR + Expansion - Contraction - Churn) / Starting MRR
     * Values above 100% indicate net negative churn (customers expanding more than churning).
     *
     * @return array{rate: float, starting_mrr: float, net_mrr: float, period: string}
     */
    public function netMrrRetentionRate(string $period = 'current_month'): array
    {
        $waterfall = $this->waterfall($period);
        $starting = $waterfall['starting_mrr'];

        $netMrr = $starting
            + $waterfall['expansion']
            - $waterfall['contraction']
            - $waterfall['churn'];

        $rate = $starting > 0 ? ($netMrr / $starting) * 100 : 0.0;

        return [
            'rate' => round($rate, 2),
            'starting_mrr' => $starting,
            'net_mrr' => round($netMrr, 2),
            'period' => $period,
        ];
    }

    /**
     * Clear all revenue waterfall cache entries.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'current_month');
        $this->cache->forget(self::CACHE_PREFIX . 'trend_12');
        $this->cache->forget(self::CACHE_PREFIX . 'trend_6');
    }

    /**
     * Calculate waterfall data for a given period.
     *
     * @return array{starting_mrr: float, new: float, expansion: float, contraction: float, reactivation: float, churn: float, net_change: float, ending_mrr: float, growth_rate: float, currency: string, period: string}
     */
    private function calculateWaterfall(string $period): array
    {
        $cacheKey = self::CACHE_PREFIX . 'movements_' . $period;
        $movements = $this->cache->get($cacheKey, []);

        $startingMrr = 0.0;
        $newMrr = 0.0;
        $expansionMrr = 0.0;
        $contractionMrr = 0.0;
        $reactivationMrr = 0.0;
        $churnMrr = 0.0;

        // Sum movements from tracked data
        foreach (self::VALID_MOVEMENT_TYPES as $type) {
            $amount = $this->movementAmounts[$type] ?? 0.0;

            match ($type) {
                'new' => $newMrr = $amount,
                'expansion' => $expansionMrr = $amount,
                'contraction' => $contractionMrr = $amount,
                'reactivation' => $reactivationMrr = $amount,
                'churn' => $churnMrr = $amount,
            };
        }

        // Merge persisted movements for historical periods
        foreach ($movements as $movement) {
            $type = $movement['type'] ?? '';
            $amount = (float) ($movement['amount'] ?? 0);

            match ($type) {
                'new' => $newMrr += $amount,
                'expansion' => $expansionMrr += $amount,
                'contraction' => $contractionMrr += $amount,
                'reactivation' => $reactivationMrr += $amount,
                'churn' => $churnMrr += $amount,
                default => null,
            };
        }

        // Calculate ending MRR from stored starting value + movements
        $startingCacheKey = self::CACHE_PREFIX . 'starting_mrr_' . $period;
        $startingMrr = $this->cache->get($startingCacheKey, 0.0);

        $netChange = $newMrr + $expansionMrr + $reactivationMrr - $contractionMrr - $churnMrr;
        $endingMrr = $startingMrr + $netChange;
        $growthRate = $startingMrr > 0 ? (($netChange / $startingMrr) * 100) : 0.0;

        return [
            'starting_mrr' => round($startingMrr, 2),
            'new' => round($newMrr, 2),
            'expansion' => round($expansionMrr, 2),
            'contraction' => round($contractionMrr, 2),
            'reactivation' => round($reactivationMrr, 2),
            'churn' => round($churnMrr, 2),
            'net_change' => round($netChange, 2),
            'ending_mrr' => round($endingMrr, 2),
            'growth_rate' => round($growthRate, 2),
            'currency' => $this->defaultCurrency,
            'period' => $period,
        ];
    }

    /**
     * Persist a movement to the current month's tracking data.
     */
    private function persistMovement(string $type, float $amount): void
    {
        $this->movementCounts[$type] = ($this->movementCounts[$type] ?? 0) + 1;
        $this->movementAmounts[$type] = ($this->movementAmounts[$type] ?? 0.0) + $amount;

        // Also persist to monthly cache for historical tracking
        $currentPeriod = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        $cacheKey = self::CACHE_PREFIX . 'movements_' . $currentPeriod;
        $movements = $this->cache->get($cacheKey, []);
        $movements[] = ['type' => $type, 'amount' => $amount, 'timestamp' => time()];
        $this->cache->put($cacheKey, $movements, $this->cacheTtl * 2);
    }

    /**
     * Load current month's cached movement data.
     */
    private function loadCurrentMonthData(): void
    {
        $currentPeriod = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        $cacheKey = self::CACHE_PREFIX . 'movements_' . $currentPeriod;
        $movements = $this->cache->get($cacheKey, []);

        foreach ($movements as $movement) {
            $type = $movement['type'] ?? '';
            $amount = (float) ($movement['amount'] ?? 0);
            $this->movementCounts[$type] = ($this->movementCounts[$type] ?? 0) + 1;
            $this->movementAmounts[$type] = ($this->movementAmounts[$type] ?? 0.0) + $amount;
        }
    }
}
