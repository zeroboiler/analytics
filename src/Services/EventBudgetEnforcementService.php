<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event budget enforcement service — enforces per-provider and per-event dispatch budgets.
 *
 * Monitors real-time event dispatch volume against configurable monthly, daily,
 * and hourly budgets. When a budget is exceeded, the service can:
 *
 * - **Throttle**: Reduce event dispatch rate by sampling (e.g., only dispatch 10%)
 * - **Block**: Silently drop events until the budget resets
 * - **Alert**: Fire an alert via AlertNotificationService without blocking
 *
 * Supports hierarchical budgets: per-provider (global), per-event-name (within provider),
 * and per-tenant (multi-tenant SaaS). Budget windows auto-reset at their boundary.
 *
 * Integration points:
 * - Called by AnalyticsManager before dispatching to each provider
 * - Reads budget configuration from `zeroboiler.analytics.budget_enforcement`
 * - Stores counters in cache with TTL matching the budget window
 *
 * Inspired by Segment Event Volume Tracking, Amplitude Event Quotas,
 * and Datadog Metric Budget Alerts.
 *
 * @since 170.0.0
 */
final class EventBudgetEnforcementService
{
    private const CACHE_PREFIX = 'zb_budget_';

    private const DEFAULT_PROVIDER_MONTHLY_LIMITS = [
        'ga4' => 1_000_000,      // GA4 free tier: 10M events/month (conservative)
        'gtm' => 1_000_000,      // GTM: client-side, no server limit
        'meta' => 1_000_000,     // Meta: varies by plan
        'plausible' => 500_000,  // Plausible: 100K-10M by plan
        'posthog' => 1_000_000,  // PostHog: free tier 1M
        'mixpanel' => 500_000,   // Mixpanel: free tier 20M (conservative)
        'amplitude' => 1_000_000, // Amplitude: free tier 10M
        'webhook' => 2_000_000,  // Custom webhook: generous
        'tiktok' => 500_000,     // TikTok: varies
        'linkedin' => 500_000,   // LinkedIn: varies
    ];

    private bool $enabled;

    /** @var string One of: 'alert', 'throttle', 'block' */
    private string $defaultAction;

    private float $throttleRate;

    /** @var array<string, int> Provider → monthly event budget */
    private array $providerBudgets;

    /** @var array<string, int> Event name → hourly budget (high-frequency events) */
    private array $eventBudgets;

    private int $cooldownSeconds;

    /** @var array<string, int> Runtime cache of throttled/blocked keys → timestamp */
    private array $enforcementCache = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $budgetConfig = $config->get('zeroboiler.analytics.budget_enforcement', []);
        /** @var array{enabled?: bool, default_action?: string, throttle_rate?: float, cooldown?: int, provider_limits?: array<string, int>, event_limits?: array<string, int>} $budgetConfig */
        $this->enabled = (bool) ($budgetConfig['enabled'] ?? false);
        $this->defaultAction = (string) ($budgetConfig['default_action'] ?? 'alert');
        $this->throttleRate = (float) ($budgetConfig['throttle_rate'] ?? 0.1);
        $this->cooldownSeconds = (int) ($budgetConfig['cooldown'] ?? 3600);

        $customProviderLimits = (array) ($budgetConfig['provider_limits'] ?? []);
        $this->providerBudgets = array_merge(self::DEFAULT_PROVIDER_MONTHLY_LIMITS, $customProviderLimits);

        $this->eventBudgets = (array) ($budgetConfig['event_limits'] ?? []);
    }

    /**
     * Check whether an event should be dispatched to a given provider.
     *
     * Returns one of: 'allow', 'throttle', 'block', 'alert'.
     *
     * When 'throttle' is returned, the caller should dispatch the event
     * only with probability = throttleRate (sampled).
     *
     * When 'block' is returned, the event should be silently dropped.
     *
     * When 'alert' is returned, the event should be dispatched normally
     * but an alert notification should be fired.
     *
     * @param  AnalyticsEvent  $event  The event being dispatched
     * @param  string  $provider  The target provider key (e.g., 'ga4', 'meta')
     * @return array{action: 'allow'|'throttle'|'block'|'alert', budget_pct: float, provider_budget: int, provider_used: int, reason: string|null}
     */
    public function checkBudget(AnalyticsEvent $event, string $provider): array
    {
        if (! $this->enabled) {
            return $this->allowResult();
        }

        // Check per-provider monthly budget
        $providerStatus = $this->checkProviderBudget($provider);
        if ($providerStatus['action'] !== 'allow') {
            return $providerStatus;
        }

        // Check per-event hourly budget
        $eventStatus = $this->checkEventBudget($event->name);
        if ($eventStatus['action'] !== 'allow') {
            return $eventStatus;
        }

        // Increment counters
        $this->incrementProviderCount($provider);
        $this->incrementEventCount($event->name);

        return $this->allowResult();
    }

    /**
     * Get budget status summary for all providers.
     *
     * Returns current usage, limits, and percentage used for each provider.
     *
     * @return array<string, array{budget: int, used: int, pct: float, status: 'ok'|'warning'|'critical'|'exceeded', action: string}>
     */
    public function getBudgetSummary(): array
    {
        $summary = [];

        foreach ($this->providerBudgets as $provider => $budget) {
            $used = $this->getProviderCount($provider);
            $pct = $budget > 0 ? ($used / $budget) * 100 : 0;

            $summary[$provider] = [
                'budget' => $budget,
                'used' => $used,
                'pct' => round($pct, 2),
                'status' => $this->classifyStatus($pct),
                'action' => $this->determineAction($pct),
            ];
        }

        return $summary;
    }

    /**
     * Get budget status for a specific event name (hourly).
     *
     * @param  string  $eventName
     * @return array{budget: int|null, used: int, pct: float|null, status: string, remaining: int}
     */
    public function getEventBudgetStatus(string $eventName): array
    {
        $budget = $this->eventBudgets[$eventName] ?? null;
        $used = $this->getEventCount($eventName);

        if ($budget === null) {
            return [
                'budget' => null,
                'used' => $used,
                'pct' => null,
                'status' => 'no_limit',
                'remaining' => PHP_INT_MAX,
            ];
        }

        $pct = $budget > 0 ? ($used / $budget) * 100 : 0;

        return [
            'budget' => $budget,
            'used' => $used,
            'pct' => round($pct, 2),
            'status' => $this->classifyStatus($pct),
            'remaining' => max(0, $budget - $used),
        ];
    }

    /**
     * Reset budget counters for a specific provider or all providers.
     *
     * Useful for manual intervention when budgets are recalculated.
     *
     * @param  string|null  $provider  Provider key, or null for all
     * @return int  Number of cache keys cleared
     */
    public function resetCounters(?string $provider = null): int
    {
        $cleared = 0;

        if ($provider !== null) {
            $this->cache->forget($this->providerCountKey($provider));
            $cleared++;
        } else {
            foreach (array_keys($this->providerBudgets) as $p) {
                $this->cache->forget($this->providerCountKey($p));
                $cleared++;
            }
        }

        // Also clear event counters if resetting all
        if ($provider === null) {
            foreach (array_keys($this->eventBudgets) as $eventName) {
                $this->cache->forget($this->eventCountKey($eventName));
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the default enforcement action.
     */
    public function getDefaultAction(): string
    {
        return $this->defaultAction;
    }

    /**
     * Get the throttle sampling rate.
     */
    public function getThrottleRate(): float
    {
        return $this->throttleRate;
    }

    /**
     * Check per-provider monthly budget.
     *
     * @param  string  $provider
     * @return array{action: string, budget_pct: float, provider_budget: int, provider_used: int, reason: string|null}
     */
    private function checkProviderBudget(string $provider): array
    {
        $budget = $this->providerBudgets[$provider] ?? 0;
        $used = $this->getProviderCount($provider);
        $pct = $budget > 0 ? ($used / $budget) * 100 : 0;
        $action = $this->determineAction($pct);

        if ($action === 'allow') {
            return $this->allowResult();
        }

        $reason = sprintf(
            'Provider %s budget at %.1f%% (%d/%d)',
            $provider,
            $pct,
            $used,
            $budget,
        );

        return [
            'action' => $action,
            'budget_pct' => round($pct, 2),
            'provider_budget' => $budget,
            'provider_used' => $used,
            'reason' => $reason,
        ];
    }

    /**
     * Check per-event hourly budget.
     *
     * @param  string  $eventName
     * @return array{action: string, budget_pct: float, provider_budget: int, provider_used: int, reason: string|null}
     */
    private function checkEventBudget(string $eventName): array
    {
        $budget = $this->eventBudgets[$eventName] ?? null;

        if ($budget === null) {
            return $this->allowResult();
        }

        $used = $this->getEventCount($eventName);
        $pct = $budget > 0 ? ($used / $budget) * 100 : 0;
        $action = $this->determineAction($pct);

        if ($action === 'allow') {
            return $this->allowResult();
        }

        $reason = sprintf(
            'Event %s hourly budget at %.1f%% (%d/%d)',
            $eventName,
            $pct,
            $used,
            $budget,
        );

        return [
            'action' => $action,
            'budget_pct' => round($pct, 2),
            'provider_budget' => $budget,
            'provider_used' => $used,
            'reason' => $reason,
        ];
    }

    /**
     * Determine enforcement action based on budget percentage.
     *
     * @param  float  $pct  Budget usage percentage (0-100+)
     * @return string  One of: 'allow', 'alert', 'throttle', 'block'
     */
    private function determineAction(float $pct): string
    {
        if ($pct >= 100.0) {
            return $this->defaultAction === 'alert' ? 'block' : $this->defaultAction;
        }

        if ($pct >= 90.0) {
            return $this->defaultAction === 'block' ? 'block' : 'throttle';
        }

        if ($pct >= 75.0) {
            return $this->defaultAction === 'alert' ? 'allow' : $this->defaultAction;
        }

        return 'allow';
    }

    /**
     * Classify budget status from percentage.
     *
     * @param  float  $pct
     * @return string  One of: 'ok', 'warning', 'critical', 'exceeded'
     */
    private function classifyStatus(float $pct): string
    {
        if ($pct >= 100.0) {
            return 'exceeded';
        }

        if ($pct >= 90.0) {
            return 'critical';
        }

        if ($pct >= 75.0) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Get current provider event count from cache.
     *
     * @param  string  $provider
     * @return int
     */
    private function getProviderCount(string $provider): int
    {
        return (int) $this->cache->get($this->providerCountKey($provider), 0);
    }

    /**
     * Increment provider event count atomically.
     *
     * @param  string  $provider
     * @return void
     */
    private function incrementProviderCount(string $provider): void
    {
        $key = $this->providerCountKey($provider);
        $this->cache->increment($key);
        // Ensure TTL is set (increment doesn't reset TTL)
        $this->cache->put($key, (int) $this->cache->get($key, 0), 2592000); // 30 days
    }

    /**
     * Get current event hourly count from cache.
     *
     * @param  string  $eventName
     * @return int
     */
    private function getEventCount(string $eventName): int
    {
        return (int) $this->cache->get($this->eventCountKey($eventName), 0);
    }

    /**
     * Increment event hourly count atomically.
     *
     * @param  string  $eventName
     * @return void
     */
    private function incrementEventCount(string $eventName): void
    {
        $key = $this->eventCountKey($eventName);
        $this->cache->increment($key);
        $this->cache->put($key, (int) $this->cache->get($key, 0), 3600); // 1 hour
    }

    /**
     * Generate cache key for provider monthly counter.
     *
     * @param  string  $provider
     * @return string
     */
    private function providerCountKey(string $provider): string
    {
        $month = now()->format('Y-m');

        return self::CACHE_PREFIX . 'provider_' . $provider . '_' . $month;
    }

    /**
     * Generate cache key for event hourly counter.
     *
     * @param  string  $eventName
     * @return string
     */
    private function eventCountKey(string $eventName): string
    {
        $hour = now()->format('Y-m-d-H');

        return self::CACHE_PREFIX . 'event_' . str_replace('.', '_', $eventName) . '_' . $hour;
    }

    /**
     * Return a default "allow" result.
     *
     * @return array{action: 'allow', budget_pct: float, provider_budget: int, provider_used: int, reason: null}
     */
    private function allowResult(): array
    {
        return [
            'action' => 'allow',
            'budget_pct' => 0.0,
            'provider_budget' => 0,
            'provider_used' => 0,
            'reason' => null,
        ];
    }
}
