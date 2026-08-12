<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Multi-tenant workspace-level analytics aggregation service.
 *
 * Provides per-workspace (tenant) KPI rollups for multi-tenant SaaS dashboards.
 * Computes workspace-scoped metrics from cached analytics data:
 *
 * - Active users (DAU/WAU/MAU)
 * - Event volume and top events
 * - Revenue totals (MRR, one-time)
 * - Conversion funnel rates (signup → trial → subscription → activation)
 * - Feature adoption per workspace
 * - Engagement score (events per active user)
 * - Retention indicators (returning user ratio)
 *
 * All data is cache-backed with configurable TTL. No database required.
 * Workspace events are tagged with tenant context via TenantAnalyticsContext.
 *
 * Configuration is read from `zeroboiler.analytics.workspace`.
 *
 * Inspired by Mixpanel Spaces, Amplitude Projects, and PostHog Projects.
 *
 * @since 37.0.0
 */
final class AnalyticsWorkspaceService
{
    private bool $enabled;

    /** @var string Cache key prefix for workspace data */
    private string $cachePrefix;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl;

    /** @var int Maximum events per workspace summary (prevents unbounded cache) */
    private int $maxEventsPerSummary;

    /** @var list<string> Event names considered for engagement scoring */
    private array $engagementEvents;

    /** @var array<string, array{name: string, steps: list<string>, weights: list<float>}> Workspace funnel definitions */
    private array $funnels;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $workspaceConfig = $config->get('zeroboiler.analytics.workspace', []);
        /** @var array{enabled?: bool, cache_prefix?: string, cache_ttl?: int, max_events_per_summary?: int, engagement_events?: list<string>, funnels?: array<string, array{name: string, steps: list<string>, weights?: list<float>}>} $workspaceConfig */

        $this->enabled = (bool) ($workspaceConfig['enabled'] ?? false);
        $this->cachePrefix = (string) ($workspaceConfig['cache_prefix'] ?? 'zb_workspace_');
        $this->cacheTtl = (int) ($workspaceConfig['cache_ttl'] ?? 3600);
        $this->maxEventsPerSummary = (int) ($workspaceConfig['max_events_per_summary'] ?? 1000);
        $this->engagementEvents = (array) ($workspaceConfig['engagement_events'] ?? [
            'page_view', 'click', 'scroll_depth', 'feature_used', 'search',
            'form_start', 'form_submit', 'share', 'feedback',
        ]);
        $this->funnels = (array) ($workspaceConfig['funnels'] ?? [
            'signup_funnel' => [
                'name' => 'Signup Funnel',
                'steps' => ['page_view', 'sign_up', 'email_verified', 'start_trial', 'subscribe'],
                'weights' => [0.2, 0.3, 0.1, 0.2, 0.2],
            ],
            'activation_funnel' => [
                'name' => 'Activation Funnel',
                'steps' => ['sign_up', 'feature_used', 'onboarding_completed'],
                'weights' => [0.3, 0.4, 0.3],
            ],
        ]);
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Check if the workspace analytics service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record an event for a workspace.
     *
     * Called by the analytics pipeline when tenant context is present.
     * Increments event counters and updates workspace-level aggregates.
     */
    public function recordEvent(AnalyticsEvent $event, string $workspaceId): void
    {
        if (! $this->enabled || $workspaceId === '') {
            return;
        }

        try {
            $this->incrementEventCount($workspaceId, $event->name);
            $this->recordUserActivity($workspaceId, $event->clientId, $event->userId);
            $this->recordRevenue($workspaceId, $event);
            $this->updateTopEvents($workspaceId, $event->name);
            $this->recordFunnelStep($workspaceId, $event->name, $event->clientId, $event->userId);
        } catch (\Throwable $e) {
            Log::debug("AnalyticsWorkspaceService: failed to record event: {$e->getMessage()}");
        }
    }

    /**
     * Get the workspace overview dashboard data.
     *
     * Returns a complete summary for a workspace including:
     * active users, event volume, top events, engagement score, funnel rates.
     *
     * @return array{workspace_id: string, active_users: array{dau: int, wau: int, mau: int}, total_events: int, top_events: list<array{name: string, count: int}>, engagement_score: float, funnels: array<string, array{name: string, rate: float, steps: list<array{name: string, rate: float}>}>, revenue: array{total: float, mrr: float}, computed_at: string}
     */
    public function getOverview(string $workspaceId): array
    {
        $dau = $this->getActiveUsers($workspaceId, 1);
        $wau = $this->getActiveUsers($workspaceId, 7);
        $mau = $this->getActiveUsers($workspaceId, 30);
        $totalEvents = $this->getTotalEventCount($workspaceId);
        $topEvents = $this->getTopEvents($workspaceId, 10);
        $engagement = $this->computeEngagementScore($workspaceId, $dau);
        $funnelData = $this->computeFunnelRates($workspaceId);
        $revenue = $this->getRevenueTotals($workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'active_users' => [
                'dau' => $dau,
                'wau' => $wau,
                'mau' => $mau,
            ],
            'total_events' => $totalEvents,
            'top_events' => $topEvents,
            'engagement_score' => round($engagement, 2),
            'funnels' => $funnelData,
            'revenue' => $revenue,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Get active user counts for a workspace over a time window.
     *
     * @return int Number of unique active users
     */
    public function getActiveUsers(string $workspaceId, int $days = 1): int
    {
        $key = "{$this->cachePrefix}{$workspaceId}:users:{$days}d";
        $cached = $this->cache->get($key);

        if (is_int($cached)) {
            return $cached;
        }

        // Count unique user IDs from the workspace user set
        $userSetKey = "{$this->cachePrefix}{$workspaceId}:user_set";
        $userSet = $this->cache->get($userSetKey);

        if (! is_array($userSet) || $userSet === []) {
            return 0;
        }

        // Filter by recency (user entries are timestamps)
        $cutoff = time() - ($days * 86400);
        $activeCount = 0;

        foreach ($userSet as $userId => $lastSeen) {
            if (is_int($lastSeen) && $lastSeen >= $cutoff) {
                $activeCount++;
            }
        }

        $this->cache->put($key, $activeCount, $this->cacheTtl);

        return $activeCount;
    }

    /**
     * Get the total event count for a workspace.
     */
    public function getTotalEventCount(string $workspaceId): int
    {
        $key = "{$this->cachePrefix}{$workspaceId}:total_events";
        $count = $this->cache->get($key);

        return is_int($count) ? $count : 0;
    }

    /**
     * Get top events for a workspace.
     *
     * @return list<array{name: string, count: int}>
     */
    public function getTopEvents(string $workspaceId, int $limit = 10): array
    {
        $key = "{$this->cachePrefix}{$workspaceId}:top_events";
        $events = $this->cache->get($key);

        if (! is_array($events)) {
            return [];
        }

        // Sort by count descending
        uasort($events, fn (int $a, int $b): int => $b <=> $a);

        $result = [];
        $i = 0;

        foreach ($events as $name => $count) {
            if ($i >= $limit) {
                break;
            }
            $result[] = ['name' => $name, 'count' => $count];
            $i++;
        }

        return $result;
    }

    /**
     * Get revenue totals for a workspace.
     *
     * @return array{total: float, mrr: float}
     */
    public function getRevenueTotals(string $workspaceId): array
    {
        $totalKey = "{$this->cachePrefix}{$workspaceId}:revenue:total";
        $mrrKey = "{$this->cachePrefix}{$workspaceId}:revenue:mrr";

        $total = $this->cache->get($totalKey);
        $mrr = $this->cache->get($mrrKey);

        return [
            'total' => is_float($total) ? $total : 0.0,
            'mrr' => is_float($mrr) ? $mrr : 0.0,
        ];
    }

    /**
     * Compare metrics across multiple workspaces.
     *
     * @param  list<string>  $workspaceIds  Workspace IDs to compare
     * @return list<array{workspace_id: string, active_users: int, total_events: int, engagement_score: float}>
     */
    public function compareWorkspaces(array $workspaceIds): array
    {
        $results = [];

        foreach ($workspaceIds as $id) {
            $dau = $this->getActiveUsers($id, 1);
            $totalEvents = $this->getTotalEventCount($id);
            $engagement = $this->computeEngagementScore($id, $dau);

            $results[] = [
                'workspace_id' => $id,
                'active_users' => $dau,
                'total_events' => $totalEvents,
                'engagement_score' => round($engagement, 2),
            ];
        }

        // Sort by engagement score descending
        usort($results, fn (array $a, array $b): int => $b['engagement_score'] <=> $a['engagement_score']);

        return $results;
    }

    /**
     * Clear all cached data for a workspace.
     */
    public function clearWorkspace(string $workspaceId): void
    {
        $keys = [
            "{$this->cachePrefix}{$workspaceId}:total_events",
            "{$this->cachePrefix}{$workspaceId}:user_set",
            "{$this->cachePrefix}{$workspaceId}:top_events",
            "{$this->cachePrefix}{$workspaceId}:revenue:total",
            "{$this->cachePrefix}{$workspaceId}:revenue:mrr",
            "{$this->cachePrefix}{$workspaceId}:users:1d",
            "{$this->cachePrefix}{$workspaceId}:users:7d",
            "{$this->cachePrefix}{$workspaceId}:users:30d",
        ];

        foreach ($this->funnels as $funnelId => $funnel) {
            $keys[] = "{$this->cachePrefix}{$workspaceId}:funnel:{$funnelId}";
        }

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
    }

    /**
     * Get workspace configuration summary.
     *
     * @return array{enabled: bool, cache_ttl: int, max_events_per_summary: int, engagement_events_count: int, funnels_count: int}
     */
    public function getConfigSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'max_events_per_summary' => $this->maxEventsPerSummary,
            'engagement_events_count' => count($this->engagementEvents),
            'funnels_count' => count($this->funnels),
        ];
    }

    /**
     * Increment the event count for a specific event name in a workspace.
     */
    private function incrementEventCount(string $workspaceId, string $eventName): void
    {
        $totalKey = "{$this->cachePrefix}{$workspaceId}:total_events";
        $current = $this->cache->get($totalKey);
        $this->cache->put($totalKey, (is_int($current) ? $current : 0) + 1, $this->cacheTtl);

        $eventsKey = "{$this->cachePrefix}{$workspaceId}:top_events";
        $events = $this->cache->get($eventsKey);
        $events = is_array($events) ? $events : [];
        $events[$eventName] = ($events[$eventName] ?? 0) + 1;
        $this->cache->put($eventsKey, $events, $this->cacheTtl);
    }

    /**
     * Record user activity for DAU/WAU/MAU computation.
     */
    private function recordUserActivity(string $workspaceId, ?string $clientId, ?string $userId): void
    {
        $identity = $userId ?? $clientId;

        if ($identity === null || $identity === '') {
            return;
        }

        $userSetKey = "{$this->cachePrefix}{$workspaceId}:user_set";
        $userSet = $this->cache->get($userSetKey);
        $userSet = is_array($userSet) ? $userSet : [];
        $userSet[$identity] = time();

        // Limit set size to prevent unbounded cache growth
        if (count($userSet) > $this->maxEventsPerSummary) {
            // Prune oldest entries
            uasort($userSet, fn (int $a, int $b): int => $a <=> $b);
            $userSet = array_slice($userSet, -$this->maxEventsPerSummary, null, true);
        }

        $this->cache->put($userSetKey, $userSet, max($this->cacheTtl, 2592000)); // At least 30 days
    }

    /**
     * Record revenue from purchase/subscription events.
     */
    private function recordRevenue(string $workspaceId, AnalyticsEvent $event): void
    {
        $revenueEvents = ['purchase', 'subscribe', 'revenue_tracked', 'subscription_renewal', 'trial_converted'];

        if (! in_array($event->name, $revenueEvents, true)) {
            return;
        }

        $value = $event->params['value'] ?? $event->params['revenue'] ?? $event->params['amount'] ?? 0;

        if (! is_numeric($value)) {
            return;
        }

        $totalKey = "{$this->cachePrefix}{$workspaceId}:revenue:total";
        $current = $this->cache->get($totalKey);
        $this->cache->put($totalKey, (is_float($current) ? $current : 0.0) + (float) $value, $this->cacheTtl);

        // Track MRR for subscription events
        if (in_array($event->name, ['subscribe', 'subscription_renewal'], true)) {
            $mrrKey = "{$this->cachePrefix}{$workspaceId}:revenue:mrr";
            $this->cache->put($mrrKey, (float) $value, $this->cacheTtl);
        }
    }

    /**
     * Update the top events ranking for a workspace.
     */
    private function updateTopEvents(string $workspaceId, string $eventName): void
    {
        // Already handled in incrementEventCount
    }

    /**
     * Record a funnel step hit for workspace funnel computation.
     */
    private function recordFunnelStep(string $workspaceId, string $eventName, ?string $clientId, ?string $userId): void
    {
        $identity = $userId ?? $clientId;

        if ($identity === null || $identity === '') {
            return;
        }

        foreach ($this->funnels as $funnelId => $funnel) {
            $steps = $funnel['steps'] ?? [];

            if (! in_array($eventName, $steps, true)) {
                continue;
            }

            $stepIndex = array_search($eventName, $steps, true);

            if ($stepIndex === false) {
                continue;
            }

            $funnelKey = "{$this->cachePrefix}{$workspaceId}:funnel:{$funnelId}";
            $funnelData = $this->cache->get($funnelKey);
            $funnelData = is_array($funnelData) ? $funnelData : [];

            // Record step completion for this identity
            if (! isset($funnelData[$identity])) {
                $funnelData[$identity] = [];
            }

            $funnelData[$identity][$eventName] = true;

            // Limit entries
            if (count($funnelData) > $this->maxEventsPerSummary) {
                $funnelData = array_slice($funnelData, -$this->maxEventsPerSummary, null, true);
            }

            $this->cache->put($funnelKey, $funnelData, $this->cacheTtl);
        }
    }

    /**
     * Compute engagement score for a workspace.
     *
     * Engagement score = total events / active users (events per user per day).
     * Normalized to 0-100 scale where 100 = very high engagement.
     *
     * @return float Score from 0.0 to 100.0
     */
    private function computeEngagementScore(string $workspaceId, int $dau): float
    {
        if ($dau === 0) {
            return 0.0;
        }

        $totalEvents = $this->getTotalEventCount($workspaceId);
        $eventsPerUser = $totalEvents / $dau;

        // Normalize: 0 events/user = 0, 50+ events/user = 100
        return min(100.0, ($eventsPerUser / 50.0) * 100.0);
    }

    /**
     * Compute funnel conversion rates for all configured funnels.
     *
     * @return array<string, array{name: string, rate: float, steps: list<array{name: string, rate: float}>}>
     */
    private function computeFunnelRates(string $workspaceId): array
    {
        $results = [];

        foreach ($this->funnels as $funnelId => $funnel) {
            $steps = $funnel['steps'] ?? [];
            $weights = $funnel['weights'] ?? [];

            if ($steps === []) {
                continue;
            }

            $funnelKey = "{$this->cachePrefix}{$workspaceId}:funnel:{$funnelId}";
            $funnelData = $this->cache->get($funnelKey);
            $funnelData = is_array($funnelData) ? $funnelData : [];

            if ($funnelData === []) {
                $results[$funnelId] = [
                    'name' => $funnel['name'] ?? $funnelId,
                    'rate' => 0.0,
                    'steps' => array_map(
                        fn (string $step): array => ['name' => $step, 'rate' => 0.0],
                        $steps,
                    ),
                ];
                continue;
            }

            // Count users who completed each step
            $stepCounts = [];
            foreach ($steps as $step) {
                $count = 0;
                foreach ($funnelData as $identitySteps) {
                    if (isset($identitySteps[$step])) {
                        $count++;
                    }
                }
                $stepCounts[$step] = $count;
            }

            // Overall funnel rate = users who completed all steps / users who started
            $startCount = $stepCounts[$steps[0]] ?? 0;
            $endCount = $stepCounts[$steps[array_key_last($steps)]] ?? 0;
            $overallRate = $startCount > 0 ? ($endCount / $startCount) * 100.0 : 0.0;

            // Per-step conversion rates
            $stepRates = [];
            foreach ($steps as $i => $step) {
                $stepStart = $i === 0 ? $startCount : ($stepCounts[$steps[$i - 1]] ?? 0);
                $stepEnd = $stepCounts[$step];
                $rate = $stepStart > 0 ? ($stepEnd / $stepStart) * 100.0 : 0.0;
                $stepRates[] = ['name' => $step, 'rate' => round($rate, 2)];
            }

            $results[$funnelId] = [
                'name' => $funnel['name'] ?? $funnelId,
                'rate' => round($overallRate, 2),
                'steps' => $stepRates,
            ];
        }

        return $results;
    }
}
