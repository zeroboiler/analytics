<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * SaaS KPI tracker for aggregating key business metrics.
 *
 * Tracks and aggregates SaaS-specific key performance indicators:
 * - MRR (Monthly Recurring Revenue)
 * - ARR (Annual Recurring Revenue)
 * - Churn rate
 * - Trial-to-paid conversion rate
 * - Active subscriber count
 * - Average Revenue Per User (ARPU)
 * - Customer Lifetime Value (CLV)
 * - Net Revenue Retention (NRR)
 *
 * Data is stored in the Laravel cache. For persistent analytics,
 * use a Redis or database cache driver.
 *
 * Configuration: `zeroboiler.analytics.saas_kpi`
 */
final class SaasKpiTracker
{
    private const CACHE_PREFIX = 'zb_saas_kpi_';

    private const DEFAULT_TTL = 2592000; // 30 days

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $kpiConfig = $config->get('zeroboiler.analytics.saas_kpi', []);
        /** @var array{enabled?: bool, cache_ttl?: int} $kpiConfig */
        $this->enabled = (bool) ($kpiConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($kpiConfig['cache_ttl'] ?? self::DEFAULT_TTL);
    }

    /**
     * Record a new subscription with its MRR value.
     *
     * @param  string  $userId  Subscriber user ID
     * @param  string  $planName  Plan name (e.g., 'pro', 'enterprise')
     * @param  float  $mrr  Monthly recurring revenue amount
     */
    public function recordSubscription(string $userId, string $planName, float $mrr): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . 'subscriptions';
        $subscriptions = $this->cache->get($key, []);
        /** @var array<string, array{plan: string, mrr: float, subscribed_at: int, active: bool}> $subscriptions */

        $subscriptions[$userId] = [
            'plan' => $planName,
            'mrr' => $mrr,
            'subscribed_at' => time(),
            'active' => true,
        ];

        $this->cache->put($key, $subscriptions, $this->cacheTtl);

        // Track MRR history for trend analysis
        $this->recordMrrHistory($mrr);
    }

    /**
     * Record a subscription cancellation.
     *
     * @param  string  $userId  Canceling user ID
     * @param  string|null  $reason  Optional cancellation reason
     */
    public function recordCancellation(string $userId, ?string $reason = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . 'subscriptions';
        $subscriptions = $this->cache->get($key, []);
        /** @var array<string, array{plan: string, mrr: float, subscribed_at: int, active: bool}> $subscriptions */

        if (isset($subscriptions[$userId])) {
            $subscriptions[$userId]['active'] = false;
            $this->cache->put($key, $subscriptions, $this->cacheTtl);
        }

        // Track churn event
        $churnKey = self::CACHE_PREFIX . 'churn';
        $churnLog = $this->cache->get($churnKey, []);
        /** @var list<array{user_id: string, reason: string|null, timestamp: int}> $churnLog */
        $churnLog[] = [
            'user_id' => $userId,
            'reason' => $reason,
            'timestamp' => time(),
        ];

        // Keep last 1000 churn events
        $this->cache->put($churnKey, array_slice($churnLog, -1000), $this->cacheTtl);
    }

    /**
     * Record a trial start.
     *
     * @param  string  $userId  User starting trial
     * @param  string  $planName  Trial plan name
     */
    public function recordTrialStart(string $userId, string $planName): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . 'trials';
        $trials = $this->cache->get($key, []);
        /** @var array<string, array{plan: string, started_at: int, converted: bool}> $trials */

        $trials[$userId] = [
            'plan' => $planName,
            'started_at' => time(),
            'converted' => false,
        ];

        $this->cache->put($key, $trials, $this->cacheTtl);
    }

    /**
     * Record a trial conversion (trial → paid).
     *
     * @param  string  $userId  User who converted
     */
    public function recordTrialConversion(string $userId): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . 'trials';
        $trials = $this->cache->get($key, []);
        /** @var array<string, array{plan: string, started_at: int, converted: bool}> $trials */

        if (isset($trials[$userId])) {
            $trials[$userId]['converted'] = true;
            $this->cache->put($key, $trials, $this->cacheTtl);
        }
    }

    /**
     * Record a plan upgrade.
     *
     * @param  string  $userId  User who upgraded
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float  $mrrDelta  Change in MRR
     */
    public function recordPlanUpgrade(string $userId, string $fromPlan, string $toPlan, float $mrrDelta): void
    {
        if (! $this->enabled) {
            return;
        }

        // Update subscription MRR
        $key = self::CACHE_PREFIX . 'subscriptions';
        $subscriptions = $this->cache->get($key, []);
        /** @var array<string, array{plan: string, mrr: float, subscribed_at: int, active: bool}> $subscriptions */

        if (isset($subscriptions[$userId])) {
            $subscriptions[$userId]['plan'] = $toPlan;
            $subscriptions[$userId]['mrr'] += $mrrDelta;
            $this->cache->put($key, $subscriptions, $this->cacheTtl);
        }

        $this->recordMrrHistory($subscriptions[$userId]['mrr'] ?? 0);
    }

    /**
     * Calculate current MRR (Monthly Recurring Revenue).
     */
    public function getMrr(): float
    {
        $subscriptions = $this->getActiveSubscriptions();

        $total = 0.0;
        foreach ($subscriptions as $sub) {
            $total += (float) ($sub['mrr'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Calculate current ARR (Annual Recurring Revenue).
     */
    public function getArr(): float
    {
        return round($this->getMrr() * 12, 2);
    }

    /**
     * Calculate active subscriber count.
     */
    public function getActiveSubscriberCount(): int
    {
        return count($this->getActiveSubscriptions());
    }

    /**
     * Calculate ARPU (Average Revenue Per User).
     */
    public function getArpu(): float
    {
        $count = $this->getActiveSubscriberCount();

        return $count > 0 ? round($this->getMrr() / $count, 2) : 0.0;
    }

    /**
     * Calculate churn rate for the last N days.
     */
    public function getChurnRate(int $days = 30): float
    {
        $activeCount = $this->getActiveSubscriberCount();
        $churnCount = $this->getChurnCount($days);

        if ($activeCount + $churnCount === 0) {
            return 0.0;
        }

        // Normalize to monthly churn rate
        $periodRatio = $days / 30.0;

        return round($churnCount / (($activeCount + $churnCount) * $periodRatio), 4);
    }

    /**
     * Calculate trial-to-paid conversion rate.
     */
    public function getTrialConversionRate(): float
    {
        $trials = $this->cache->get(self::CACHE_PREFIX . 'trials', []);
        /** @var array<string, array{plan: string, started_at: int, converted: bool}> $trials */

        if (empty($trials)) {
            return 0.0;
        }

        $total = count($trials);
        $converted = count(array_filter($trials, fn (array $t): bool => $t['converted'] === true));

        return round($converted / $total, 4);
    }

    /**
     * Calculate CLV estimate (Customer Lifetime Value).
     *
     * Uses simple formula: ARPU / Churn Rate
     * For more sophisticated CLV, use a dedicated CLV service.
     */
    public function getClv(): float
    {
        $churnRate = $this->getChurnRate();
        $arpu = $this->getArpu();

        return $churnRate > 0 ? round($arpu / $churnRate, 2) : 0.0;
    }

    /**
     * Get MRR history for trend visualization.
     *
     * @return list<array{timestamp: int, mrr: float}>
     */
    public function getMrrHistory(int $limit = 30): array
    {
        $history = $this->cache->get(self::CACHE_PREFIX . 'mrr_history', []);
        /** @var list<array{timestamp: int, mrr: float}> $history */

        return array_slice($history, -$limit);
    }

    /**
     * Get a comprehensive KPI summary.
     *
     * @return array{enabled: bool, mrr: float, arr: float, active_subscribers: int, arpu: float, churn_rate: float, trial_conversion_rate: float, clv: float, plan_distribution: array<string, int>, mrr_history: list<array{timestamp: int, mrr: float}>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'mrr' => $this->getMrr(),
            'arr' => $this->getArr(),
            'active_subscribers' => $this->getActiveSubscriberCount(),
            'arpu' => $this->getArpu(),
            'churn_rate' => $this->getChurnRate(),
            'trial_conversion_rate' => $this->getTrialConversionRate(),
            'clv' => $this->getClv(),
            'plan_distribution' => $this->getPlanDistribution(),
            'mrr_history' => $this->getMrrHistory(30),
        ];
    }

    /**
     * Check if SaaS KPI tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all KPI data.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'subscriptions');
        $this->cache->forget(self::CACHE_PREFIX . 'trials');
        $this->cache->forget(self::CACHE_PREFIX . 'churn');
        $this->cache->forget(self::CACHE_PREFIX . 'mrr_history');
    }

    /**
     * Get active subscriptions.
     *
     * @return array<string, array{plan: string, mrr: float, subscribed_at: int, active: bool}>
     */
    private function getActiveSubscriptions(): array
    {
        $subscriptions = $this->cache->get(self::CACHE_PREFIX . 'subscriptions', []);
        /** @var array<string, array{plan: string, mrr: float, subscribed_at: int, active: bool}> $subscriptions */

        return array_filter(
            $subscriptions,
            fn (array $sub): bool => ($sub['active'] ?? false) === true,
        );
    }

    /**
     * Get plan distribution across active subscribers.
     *
     * @return array<string, int>
     */
    private function getPlanDistribution(): array
    {
        $subs = $this->getActiveSubscriptions();
        $distribution = [];

        foreach ($subs as $sub) {
            $plan = $sub['plan'] ?? 'unknown';
            $distribution[$plan] = ($distribution[$plan] ?? 0) + 1;
        }

        arsort($distribution);

        return $distribution;
    }

    /**
     * Count churn events in the last N days.
     */
    private function getChurnCount(int $days): int
    {
        $churnLog = $this->cache->get(self::CACHE_PREFIX . 'churn', []);
        /** @var list<array{user_id: string, reason: string|null, timestamp: int}> $churnLog */

        $cutoff = time() - ($days * 86400);

        return count(array_filter(
            $churnLog,
            fn (array $event): bool => $event['timestamp'] >= $cutoff,
        ));
    }

    /**
     * Record MRR history entry.
     */
    private function recordMrrHistory(float $mrr): void
    {
        $key = self::CACHE_PREFIX . 'mrr_history';
        $history = $this->cache->get($key, []);
        /** @var list<array{timestamp: int, mrr: float}> $history */

        $history[] = [
            'timestamp' => time(),
            'mrr' => $mrr,
        ];

        // Keep last 365 entries (one year of daily snapshots)
        $this->cache->put($key, array_slice($history, -365), $this->cacheTtl);
    }
}
