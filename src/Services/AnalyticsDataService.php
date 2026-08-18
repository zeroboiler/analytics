<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Cache-backed analytics data aggregation service for dashboard queries.
 *
 * Provides time-series analytics data without requiring a database.
 * Aggregates DAU/MAU, conversion funnels, revenue trends, top events,
 * and provider statistics from in-memory ring buffers and cache stores.
 *
 * All data is stored in cache with configurable TTLs. Designed for
 * real-time dashboards, admin panels, and SaaS analytics widgets.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class AnalyticsDataService
{
    /** @var int Default TTL for aggregated data (seconds) */
    private const DEFAULT_TTL = 3600;

    /** @var int Default TTL for daily snapshots (seconds) */
    private const DAILY_TTL = 86400;

    private const CACHE_PREFIX = 'zb_analytics_data_';

    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private int $ttl;

    private int $dailyTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  AnalyticsMetrics  $metrics  Analytics metrics instance
     * @param  int  $ttl  TTL for aggregated data in seconds
     * @param  int  $dailyTtl  TTL for daily snapshots in seconds
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
        int $ttl = self::DEFAULT_TTL,
        int $dailyTtl = self::DAILY_TTL,
    ): void {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->ttl = $ttl;
        $this->dailyTtl = $dailyTtl;
    }

    // ── Active Users ─────────────────────────────────────────────────

    /**
     * Record an active user for DAU/MAU calculation.
     *
     * Tracks unique client IDs and user IDs per day/month for
     * active user metrics. Uses a rolling window approach.
     *
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function recordActiveUser(?string $clientId = null, ?string $userId = null): void
    {
        $today = date('Y-m-d');
        $month = date('Y-m');

        // DAU: track unique client IDs and user IDs per day
        if ($clientId !== null && $clientId !== '') {
            $this->addToSet("dau_clients_{$today}", $clientId, $this->dailyTtl);
        }

        if ($userId !== null && $userId !== '') {
            $this->addToSet("dau_users_{$today}", $userId, $this->dailyTtl);
        }

        // MAU: track unique user IDs per month
        if ($userId !== null && $userId !== '') {
            $this->addToSet("mau_users_{$month}", $userId, $this->dailyTtl * 32);
        }
    }

    /**
     * Get Daily Active Users (DAU) count for today.
     *
     * @return array{client_count: int, user_count: int, date: string}
     */
    public function getDAU(): array
    {
        $today = date('Y-m-d');

        return [
            'client_count' => $this->getSetSize("dau_clients_{$today}"),
            'user_count' => $this->getSetSize("dau_users_{$today}"),
            'date' => $today,
        ];
    }

    /**
     * Get Monthly Active Users (MAU) count for current month.
     *
     * @return array{user_count: int, month: string}
     */
    public function getMAU(): array
    {
        $month = date('Y-m');

        return [
            'user_count' => $this->getSetSize("mau_users_{$month}"),
            'month' => $month,
        ];
    }

    /**
     * Get the DAU/MAU stickiness ratio.
     *
     * Stickiness = DAU / MAU (0.0 - 1.0). Higher is better.
     * Indicates how often monthly users return daily.
     *
     * @return array{dau: int, mau: int, stickiness: float, date: string, month: string}
     */
    public function getStickiness(): array
    {
        $dau = $this->getDAU();
        $mau = $this->getMAU();
        $dauCount = $dau['user_count'];
        $mauCount = $mau['user_count'];

        return [
            'dau' => $dauCount,
            'mau' => $mauCount,
            'stickiness' => $mauCount > 0
                ? round($dauCount / $mauCount, 4)
                : 0.0,
            'date' => $dau['date'],
            'month' => $mau['month'],
        ];
    }

    // ── Event Counters ──────────────────────────────────────────────

    /**
     * Increment an event counter for the given event name.
     *
     * Tracks per-event, per-day, and total counts.
     *
     * @param  string  $eventName  Event name
     * @param  int  $count  Number of events (default: 1)
     */
    public function incrementEvent(string $eventName, int $count = 1): void
    {
        $today = date('Y-m-d');

        // Per-day counter
        $this->cache->increment("{$this->CACHE_PREFIX}evt_day_{$today}_{$eventName}", $count);

        // Total counter (rolling 7-day window)
        $this->cache->increment("{$this->CACHE_PREFIX}evt_total_{$eventName}", $count);

        // Daily total
        $this->cache->increment("{$this->CACHE_PREFIX}evt_day_total_{$today}", $count);
    }

    /**
     * Get the event count for a specific event name.
     *
     * @param  string  $eventName  Event name
     * @return int
     */
    public function getEventCount(string $eventName): int
    {
        $count = $this->cache->get("{$this->CACHE_PREFIX}evt_total_{$eventName}");

        return is_int($count) ? $count : 0;
    }

    /**
     * Get the event count for a specific event on a specific day.
     *
     * @param  string  $eventName  Event name
     * @param  string  $date  Date in Y-m-d format
     * @return int
     */
    public function getEventCountForDate(string $eventName, string $date): int
    {
        $count = $this->cache->get("{$this->CACHE_PREFIX}evt_day_{$date}_{$eventName}");

        return is_int($count) ? $count : 0;
    }

    /**
     * Get the top N events by total count.
     *
     * @param  int  $limit  Number of top events to return
     * @return list<array{name: string, count: int}>
     */
    public function getTopEvents(int $limit = 10): array
    {
        return $this->getTopCounters("evt_total_", $limit);
    }

    /**
     * Get the total event count for a specific day.
     *
     * @param  string|null  $date  Date in Y-m-d format (null = today)
     * @return int
     */
    public function getDailyTotal(?string $date = null): int
    {
        $date = $date ?? date('Y-m-d');
        $count = $this->cache->get("{$this->CACHE_PREFIX}evt_day_total_{$date}");

        return is_int($count) ? $count : 0;
    }

    // ── Revenue Tracking ────────────────────────────────────────────

    /**
     * Record a revenue event for aggregation.
     *
     * Tracks daily, monthly, and total revenue with event counts.
     *
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $source  Revenue source event (purchase, subscribe, etc.)
     */
    public function recordRevenue(float $amount, string $currency = 'USD', string $source = 'purchase'): void
    {
        $today = date('Y-m-d');
        $month = date('Y-m');

        // Daily revenue per currency
        $dailyKey = "{$this->CACHE_PREFIX}rev_day_{$today}_{$currency}";
        $dailyCountKey = "{$this->CACHE_PREFIX}rev_day_count_{$today}_{$currency}";
        $dailyAmount = (float) ($this->cache->get($dailyKey) ?? 0);
        $dailyEventCount = (int) ($this->cache->get($dailyCountKey) ?? 0);

        $this->cache->put($dailyKey, $dailyAmount + $amount, $this->dailyTtl);
        $this->cache->put($dailyCountKey, $dailyEventCount + 1, $this->dailyTtl);

        // Monthly revenue per currency
        $monthlyKey = "{$this->CACHE_PREFIX}rev_month_{$month}_{$currency}";
        $monthlyAmount = (float) ($this->cache->get($monthlyKey) ?? 0);

        $this->cache->put($monthlyKey, $monthlyAmount + $amount, $this->dailyTtl * 32);

        // Track revenue by source
        $sourceKey = "{$this->CACHE_PREFIX}rev_source_{$source}";
        $sourceAmount = (float) ($this->cache->get($sourceKey) ?? 0);

        $this->cache->put($sourceKey, $sourceAmount + $amount, $this->ttl);
    }

    /**
     * Get revenue summary for today.
     *
     * @param  string  $currency  ISO 4217 currency code
     * @return array{amount: float, currency: string, events: int, date: string}
     */
    public function getDailyRevenue(string $currency = 'USD'): array
    {
        $today = date('Y-m-d');

        return [
            'amount' => (float) ($this->cache->get("{$this->CACHE_PREFIX}rev_day_{$today}_{$currency}") ?? 0),
            'currency' => $currency,
            'events' => (int) ($this->cache->get("{$this->CACHE_PREFIX}rev_day_count_{$today}_{$currency}") ?? 0),
            'date' => $today,
        ];
    }

    /**
     * Get revenue summary for current month.
     *
     * @param  string  $currency  ISO 4217 currency code
     * @return array{amount: float, currency: string, month: string}
     */
    public function getMonthlyRevenue(string $currency = 'USD'): array
    {
        $month = date('Y-m');

        return [
            'amount' => (float) ($this->cache->get("{$this->CACHE_PREFIX}rev_month_{$month}_{$currency}") ?? 0),
            'currency' => $currency,
            'month' => $month,
        ];
    }

    /**
     * Get revenue breakdown by source.
     *
     * @return array<string, float>
     */
    public function getRevenueBySource(): array
    {
        return $this->getFloatCounters('rev_source_');
    }

    // ── Provider Stats ──────────────────────────────────────────────

    /**
     * Record a provider dispatch event for statistics.
     *
     * @param  string  $provider  Provider name (ga4, gtm, meta, plausible, posthog, webhook)
     * @param  bool  $success  Whether the dispatch succeeded
     */
    public function recordProviderDispatch(string $provider, bool $success): void
    {
        $today = date('Y-m-d');

        // Success/failure counters per provider per day
        $key = $success
            ? "{$this->CACHE_PREFIX}prov_success_{$today}_{$provider}"
            : "{$this->CACHE_PREFIX}prov_fail_{$today}_{$provider}";

        $this->cache->increment($key);
    }

    /**
     * Get provider dispatch statistics for today.
     *
     * @return array{date: string, providers: array<string, array{success: int, failure: int, total: int, success_rate: float}>}
     */
    public function getProviderStats(): array
    {
        $today = date('Y-m-d');
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        $result = [];

        foreach ($providers as $provider) {
            $success = (int) ($this->cache->get("{$this->CACHE_PREFIX}prov_success_{$today}_{$provider}") ?? 0);
            $failure = (int) ($this->cache->get("{$this->CACHE_PREFIX}prov_fail_{$today}_{$provider}") ?? 0);
            $total = $success + $failure;

            $result[$provider] = [
                'success' => $success,
                'failure' => $failure,
                'total' => $total,
                'success_rate' => $total > 0
                    ? round($success / $total, 4)
                    : 0.0,
            ];
        }

        return [
            'date' => $today,
            'providers' => $result,
        ];
    }

    // ── Conversion Funnel ───────────────────────────────────────────

    /**
     * Record a funnel step completion.
     *
     * Tracks unique users completing each step of a funnel.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'purchase')
     * @param  string  $stepName  Step identifier (e.g. 'landed', 'registered', 'confirmed')
     * @param  string|null  $userId  User ID (null = anonymous)
     */
    public function recordFunnelStep(string $funnelName, string $stepName, ?string $userId = null): void
    {
        $today = date('Y-m-d');
        $key = "{$this->CACHE_PREFIX}funnel_{$today}_{$funnelName}_{$stepName}";

        if ($userId !== null && $userId !== '') {
            $this->addToSet($key, $userId, $this->dailyTtl);
        } else {
            $this->cache->increment($key);
        }
    }

    /**
     * Get funnel conversion data for today.
     *
     * Returns step-by-step conversion rates for a named funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  list<string>  $steps  Ordered list of step names
     * @return array{funnel: string, date: string, steps: list<array{name: string, unique_users: int, conversion_rate: float, drop_off_rate: float}>, overall_conversion: float}
     */
    public function getFunnelConversion(string $funnelName, array $steps): array
    {
        $today = date('Y-m-d');
        $result = [];
        $firstStepCount = 0;

        foreach ($steps as $index => $stepName) {
            $key = "{$this->CACHE_PREFIX}funnel_{$today}_{$funnelName}_{$stepName}";
            $count = $this->getSetSize($key);

            if ($index === 0) {
                $firstStepCount = $count;
            }

            $conversionRate = $firstStepCount > 0
                ? round($count / $firstStepCount, 4)
                : 0.0;

            $dropOffRate = 0.0;
            if ($index > 0 && isset($result[$index - 1]) && $result[$index - 1]['unique_users'] > 0) {
                $prevCount = $result[$index - 1]['unique_users'];
                $dropOffRate = round(1 - ($count / $prevCount), 4);
            }

            $result[] = [
                'name' => $stepName,
                'unique_users' => $count,
                'conversion_rate' => $conversionRate,
                'drop_off_rate' => $dropOffRate,
            ];
        }

        $lastStep = end($result);
        $overallConversion = $firstStepCount > 0 && $lastStep !== false
            ? round($lastStep['unique_users'] / $firstStepCount, 4)
            : 0.0;

        return [
            'funnel' => $funnelName,
            'date' => $today,
            'steps' => $result,
            'overall_conversion' => $overallConversion,
        ];
    }

    // ── Dashboard Summary ───────────────────────────────────────────

    /**
     * Get a comprehensive dashboard summary.
     *
     * Aggregates DAU/MAU, revenue, event counts, provider stats,
     * and funnel data into a single response for dashboard widgets.
     *
     * @param  string  $currency  ISO 4217 currency code
     * @return array{dau: array{client_count: int, user_count: int, date: string}, mau: array{user_count: int, month: string}, stickiness: float, daily_revenue: array{amount: float, currency: string, events: int, date: string}, monthly_revenue: array{amount: float, currency: string, month: string}, top_events: list<array{name: string, count: int}>, total_events_today: int, provider_stats: array<string, array{success: int, failure: int, total: int, success_rate: float}>, generated_at: string}
     */
    public function getDashboardSummary(string $currency = 'USD'): array
    {
        $dau = $this->getDAU();
        $mau = $this->getMAU();
        $stickinessData = $this->getStickiness();

        return [
            'dau' => $dau,
            'mau' => $mau,
            'stickiness' => $stickinessData['stickiness'],
            'daily_revenue' => $this->getDailyRevenue($currency),
            'monthly_revenue' => $this->getMonthlyRevenue($currency),
            'top_events' => $this->getTopEvents(10),
            'total_events_today' => $this->getDailyTotal(),
            'provider_stats' => $this->getProviderStats()['providers'],
            'generated_at' => date('c'),
        ];
    }

    // ── Cache Helpers ───────────────────────────────────────────────

    /**
     * Add a member to a cache-based set.
     *
     * Uses cache tags when available for efficient set operations.
     *
     * @param  string  $key  Cache key
     * @param  string  $member  Set member
     * @param  int  $ttl  TTL in seconds
     */
    private function addToSet(string $key, string $member, int $ttl): void
    {
        $fullKey = self::CACHE_PREFIX . $key;
        /** @var list<string>|null $members */
        $members = $this->cache->get($fullKey);

        if ($members === null) {
            $members = [];
        }

        if (! in_array($member, $members, true)) {
            $members[] = $member;
            $this->cache->put($fullKey, $members, $ttl);
        }
    }

    /**
     * Get the size of a cache-based set.
     *
     * @param  string  $key  Cache key (without prefix)
     * @return int
     */
    private function getSetSize(string $key): int
    {
        $fullKey = self::CACHE_PREFIX . $key;
        /** @var list<string>|null $members */
        $members = $this->cache->get($fullKey);

        return is_array($members) ? count($members) : 0;
    }

    /**
     * Get top N integer counters matching a key prefix.
     *
     * Scans cache for keys matching the given prefix and returns
     * the top N by count. Cache drivers may not support key scanning,
     * so this returns the top from known tracked events.
     *
     * @param  string  $prefix  Key prefix to search
     * @param  int  $limit  Maximum results
     * @return list<array{name: string, count: int}>
     */
    private function getTopCounters(string $prefix, int $limit): array
    {
        // Use metrics data if available
        $report = $this->metrics->report();

        $eventCounts = $report['event_counts'] ?? [];
        arsort($eventCounts);

        $result = [];
        $count = 0;

        foreach ($eventCounts as $name => $cnt) {
            $result[] = ['name' => $name, 'count' => $cnt];
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get all float counters matching a key prefix pattern.
     *
     * @param  string  $pattern  Pattern to match (within cache prefix namespace)
     * @return array<string, float>
     */
    private function getFloatCounters(string $pattern): array
    {
        // Since cache drivers don't support scanning, return known revenue sources
        $sources = ['purchase', 'subscribe', 'revenue_tracked', 'trial_converted', 'expansion_revenue'];
        $result = [];

        foreach ($sources as $source) {
            $key = "{$this->CACHE_PREFIX}{$pattern}{$source}";
            $amount = $this->cache->get($key);

            if (is_float($amount) || is_int($amount)) {
                $result[$source] = (float) $amount;
            }
        }

        return $result;
    }
}
