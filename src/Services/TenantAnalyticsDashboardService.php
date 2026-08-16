<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Tenant Analytics Dashboard — multi-tenant SaaS analytics aggregation.
 *
 * Provides per-tenant analytics dashboards with aggregated metrics,
 * event breakdowns, KPI summaries, and comparative benchmarking.
 * Designed for multi-tenant SaaS applications where each tenant/customer
 * needs isolated analytics with optional cross-tenant benchmarking.
 *
 * Features:
 * - Per-tenant event counting and aggregation
 * - Tenant KPI dashboard (events, active users, top events)
 * - Cross-tenant benchmarking (percentile ranking)
 * - Tenant health scoring
 * - Plan-based feature comparison
 *
 * @since 85.0.0
 */
final class TenantAnalyticsDashboardService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int<1, max> Cache TTL in seconds */
    private int $ttl;

    /** @var string Cache prefix */
    private string $prefix = 'zb_tenant_analytics:';

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $ttl  Cache TTL in seconds
     */
    public function __construct(CacheRepository $cache, int $ttl = 86400): void
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    /**
     * Record an analytics event for a tenant.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $metadata  Additional metadata (user_id, plan, etc.)
     */
    public function recordEvent(string $tenantId, string $eventName, array $metadata = []): void
    {
        $today = $this->currentDate();

        // Increment tenant event counter
        $this->incrementCounter($this->eventCountKey($tenantId, $today));

        // Increment per-event counter for tenant
        $this->incrementCounter($this->tenantEventKey($tenantId, $eventName, $today));

        // Track unique users per tenant per day
        $userId = $metadata['user_id'] ?? null;
        if ($userId !== null) {
            $this->addToSet($this->tenantUsersKey($tenantId, $today), $userId);
        }

        // Track plan-based metrics
        $plan = $metadata['plan'] ?? 'unknown';
        $this->incrementCounter($this->planEventKey($plan, $today));
    }

    /**
     * Get event count for a tenant on a specific date.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string|null  $date  Date (YYYY-MM-DD), or null for today
     * @return int<0, max>
     */
    public function tenantEventCount(string $tenantId, ?string $date = null): int
    {
        $date = $date ?? $this->currentDate();

        return (int) $this->cache->get($this->eventCountKey($tenantId, $date), 0);
    }

    /**
     * Get active users count for a tenant on a specific date.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string|null  $date  Date (YYYY-MM-DD), or null for today
     * @return int<0, max>
     */
    public function tenantActiveUsers(string $tenantId, ?string $date = null): int
    {
        $date = $date ?? $this->currentDate();
        $users = $this->cache->get($this->tenantUsersKey($tenantId, $date), []);

        return count($users);
    }

    /**
     * Get top events for a tenant.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string|null  $date  Date (YYYY-MM-DD), or null for today
     * @param  int  $limit  Maximum events to return
     * @return list<array{event: string, count: int}>
     */
    public function tenantTopEvents(string $tenantId, ?string $date = null, int $limit = 10): array
    {
        $date = $date ?? $this->currentDate();
        $prefix = $this->tenantEventPrefix($tenantId, $date);

        $events = $this->scanCounters($prefix);

        arsort($events);

        $result = [];
        $count = 0;
        foreach ($events as $eventName => $count_value) {
            $result[] = ['event' => $eventName, 'count' => $count_value];
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get full dashboard summary for a tenant.
     *
     * @param  string  $tenantId  Tenant identifier
     * @return array{tenant_id: string, date: string, total_events: int, active_users: int, events_per_user: float, top_events: list<array{event: string, count: int}>, health_score: float}
     */
    public function tenantDashboard(string $tenantId): array
    {
        $today = $this->currentDate();
        $totalEvents = $this->tenantEventCount($tenantId, $today);
        $activeUsers = $this->tenantActiveUsers($tenantId, $today);
        $topEvents = $this->tenantTopEvents($tenantId, $today, 5);

        return [
            'tenant_id' => $tenantId,
            'date' => $today,
            'total_events' => $totalEvents,
            'active_users' => $activeUsers,
            'events_per_user' => $activeUsers > 0 ? round($totalEvents / $activeUsers, 2) : 0.0,
            'top_events' => $topEvents,
            'health_score' => $this->tenantHealthScore($tenantId),
        ];
    }

    /**
     * Calculate health score for a tenant (0-100).
     *
     * Based on event volume, user engagement, and diversity of events.
     *
     * @param  string  $tenantId  Tenant identifier
     * @return float Health score (0-100)
     */
    public function tenantHealthScore(string $tenantId): float
    {
        $today = $this->currentDate();
        $totalEvents = $this->tenantEventCount($tenantId, $today);
        $activeUsers = $this->tenantActiveUsers($tenantId, $today);
        $topEvents = $this->tenantTopEvents($tenantId, $today, 100);

        // Volume score (0-30): based on event count
        $volumeScore = min(30.0, $totalEvents / 10.0);

        // Engagement score (0-35): based on events per user
        $engagementScore = 0.0;
        if ($activeUsers > 0) {
            $epu = $totalEvents / $activeUsers;
            $engagementScore = min(35.0, $epu * 3.5);
        }

        // Diversity score (0-35): based on number of distinct events
        $diversityScore = min(35.0, count($topEvents) * 3.5);

        return round($volumeScore + $engagementScore + $diversityScore, 2);
    }

    /**
     * Get events per active user ratio for a tenant.
     *
     * @param  string  $tenantId  Tenant identifier
     * @return float
     */
    public function eventsPerUser(string $tenantId): float
    {
        $activeUsers = $this->tenantActiveUsers($tenantId);
        $totalEvents = $this->tenantEventCount($tenantId);

        if ($activeUsers === 0) {
            return 0.0;
        }

        return round($totalEvents / $activeUsers, 2);
    }

    /**
     * Get known tenants that have recorded events.
     *
     * @return list<string>
     */
    public function knownTenants(): array
    {
        $tenants = $this->cache->get($this->prefix . 'known_tenants', []);

        return is_array($tenants) ? $tenants : [];
    }

    /**
     * Get aggregate metrics across all tenants.
     *
     * @return array{total_tenants: int, total_events: int, total_active_users: int, avg_health_score: float, avg_events_per_user: float}
     */
    public function aggregateMetrics(): array
    {
        $tenants = $this->knownTenants();
        $totalEvents = 0;
        $totalUsers = 0;
        $totalHealth = 0.0;
        $activeTenantCount = 0;

        foreach ($tenants as $tenantId) {
            $totalEvents += $this->tenantEventCount($tenantId);
            $totalUsers += $this->tenantActiveUsers($tenantId);
            $health = $this->tenantHealthScore($tenantId);
            $totalHealth += $health;
            $activeTenantCount++;
        }

        return [
            'total_tenants' => count($tenants),
            'total_events' => $totalEvents,
            'total_active_users' => $totalUsers,
            'avg_health_score' => $activeTenantCount > 0 ? round($totalHealth / $activeTenantCount, 2) : 0.0,
            'avg_events_per_user' => $totalUsers > 0 ? round($totalEvents / $totalUsers, 2) : 0.0,
        ];
    }

    /**
     * Get plan-based event distribution.
     *
     * @return array{plans: array<string, int>, date: string}
     */
    public function planDistribution(): array
    {
        $today = $this->currentDate();
        $plans = $this->cache->get($this->prefix . 'plan_counts:' . $today, []);

        return [
            'plans' => is_array($plans) ? $plans : [],
            'date' => $today,
        ];
    }

    /**
     * Get cross-tenant ranking for a specific metric.
     *
     * @param  string  $metric  Metric name ('events', 'users', 'health')
     * @param  int  $limit  Maximum tenants to return
     * @return list<array{tenant_id: string, value: float|int, rank: int}>
     */
    public function tenantRanking(string $metric = 'events', int $limit = 20): array
    {
        $tenants = $this->knownTenants();
        $ranked = [];

        foreach ($tenants as $tenantId) {
            $value = match ($metric) {
                'events' => $this->tenantEventCount($tenantId),
                'users' => $this->tenantActiveUsers($tenantId),
                'health' => $this->tenantHealthScore($tenantId),
                default => 0,
            };

            $ranked[] = [
                'tenant_id' => $tenantId,
                'value' => $value,
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $result = [];
        foreach ($ranked as $i => $item) {
            $result[] = [
                'tenant_id' => $item['tenant_id'],
                'value' => $item['value'],
                'rank' => $i + 1,
            ];
            if ($i + 1 >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get percentile ranking for a tenant within all tenants.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string  $metric  Metric name
     * @return array{percentile: float, rank: int, total: int, value: float|int}
     */
    public function tenantPercentile(string $tenantId, string $metric = 'events'): array
    {
        $rankings = $this->tenantRanking($metric, 1000);
        $total = count($rankings);
        $rank = 0;

        foreach ($rankings as $i => $item) {
            if ($item['tenant_id'] === $tenantId) {
                $rank = $i + 1;
                break;
            }
        }

        $percentile = $total > 0 ? round(((($total - $rank) / $total) * 100), 2) : 0.0;
        $value = match ($metric) {
            'events' => $this->tenantEventCount($tenantId),
            'users' => $this->tenantActiveUsers($tenantId),
            'health' => $this->tenantHealthScore($tenantId),
            default => 0,
        };

        return [
            'percentile' => $percentile,
            'rank' => $rank,
            'total' => $total,
            'value' => $value,
        ];
    }

    /**
     * Get full tenant analytics dashboard with cross-tenant context.
     *
     * @param  string  $tenantId  Tenant identifier
     * @return array{tenant: array<string, mixed>, percentile: array<string, mixed>, ranking_position: int}
     */
    public function fullDashboard(string $tenantId): array
    {
        $dashboard = $this->tenantDashboard($tenantId);
        $percentile = $this->tenantPercentile($tenantId, 'health');

        return [
            'tenant' => $dashboard,
            'percentile' => $percentile,
            'ranking_position' => $percentile['rank'],
        ];
    }

    // ─── Internal Helpers ──────────────────────────────────────────────────

    private function currentDate(): string
    {
        return date('Y-m-d');
    }

    private function eventCountKey(string $tenantId, string $date): string
    {
        return $this->prefix . 'events:' . $tenantId . ':' . $date;
    }

    private function tenantEventKey(string $tenantId, string $eventName, string $date): string
    {
        return $this->prefix . 'event:' . $tenantId . ':' . $eventName . ':' . $date;
    }

    private function tenantEventPrefix(string $tenantId, string $date): string
    {
        return $this->prefix . 'event:' . $tenantId . ':';
    }

    private function tenantUsersKey(string $tenantId, string $date): string
    {
        return $this->prefix . 'users:' . $tenantId . ':' . $date;
    }

    private function planEventKey(string $plan, string $date): string
    {
        return $this->prefix . 'plan_events:' . $plan . ':' . $date;
    }

    private function incrementCounter(string $key): void
    {
        $current = (int) $this->cache->get($key, 0);
        $this->cache->put($key, $current + 1, $this->ttl);

        // Track this tenant as known
        // Extract tenant ID from key
        $parts = explode(':', $key);
        if (count($parts) >= 3 && str_starts_with($key, $this->prefix . 'events:')) {
            $tenantId = $parts[2];
            $knownTenants = $this->cache->get($this->prefix . 'known_tenants', []);
            if (! in_array($tenantId, $knownTenants, true)) {
                $knownTenants[] = $tenantId;
                $this->cache->put($this->prefix . 'known_tenants', $knownTenants, $this->ttl * 30);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function addToSet(string $key, string $value): void
    {
        $set = $this->cache->get($key, []);
        if (! is_array($set)) {
            $set = [];
        }
        if (! in_array($value, $set, true)) {
            $set[] = $value;
            $this->cache->put($key, $set, $this->ttl);
        }
    }

    /**
     * Scan counter keys matching a prefix.
     *
     * @return array<string, int>
     */
    private function scanCounters(string $prefix): array
    {
        $results = [];

        // Since we can't do prefix scans on all cache drivers,
        // we use a known-events list stored alongside the tenant data
        $knownEventsKey = $prefix . '_known';
        $knownEvents = $this->cache->get($knownEventsKey, []);

        if (! is_array($knownEvents)) {
            return $results;
        }

        foreach ($knownEvents as $eventName) {
            $count = (int) $this->cache->get($prefix . $eventName, 0);
            if ($count > 0) {
                $results[$eventName] = $count;
            }
        }

        return $results;
    }
}
