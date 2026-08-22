<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event priority gate service for SaaS analytics.
 *
 * Determines whether an event should be dispatched based on its priority level,
 * current system load, and configured thresholds. Critical events always pass;
 * lower-priority events may be dropped under load or budget constraints.
 *
 * Configuration is read from `zeroboiler.analytics.priority`.
 *
 * Features:
 * - Per-priority event rate limiting (events/minute)
 * - Automatic priority assignment for known catalog events
 * - Budget-aware gate decisions (drops low-priority events when budget exceeded)
 * - Cache-backed counters for cross-process state
 * - Custom priority overrides via config
 * - Admin diagnostics summary
 *
 * @see \ZeroBoiler\Analytics\DTO\EventPriority
 *
 * @since 1.0.0
 */
final class EventPriorityGate
{
    /** @var array<value-of<EventPriority>, int> Default rate limits per priority (events/minute) */
    private const DEFAULT_RATE_LIMITS = [
        'critical' => 10_000,
        'normal' => 1_000,
        'low' => 200,
        'background' => 50,
    ];

    /** @var array<string, value-of<EventPriority>> Built-in priority overrides for specific events */
    private const EVENT_PRIORITY_OVERRIDES = [
        // Revenue-critical events
        'purchase' => 'critical',
        'subscription' => 'critical',
        'payment_succeeded' => 'critical',
        'subscribe' => 'critical',
        'revenue_tracked' => 'critical',

        // High-value conversion events
        'sign_up' => 'critical',
        'start_trial' => 'critical',
        'account_activated' => 'critical',

        // Low-priority high-volume events
        'scroll_depth' => 'low',
        'outbound_click' => 'low',
        'timing' => 'low',
        'time_on_page' => 'low',
        'web_vitals' => 'low',
        'js_error' => 'low',
        'session_start' => 'low',
        'session_end' => 'low',

        // Background telemetry
        'ab_test_exposure' => 'background',
        'notification' => 'background',
    ];

    private bool $enabled;

    /** @var array<value-of<EventPriority>, int> */
    private array $rateLimits;

    /** @var array<string, value-of<EventPriority>> */
    private array $customOverrides;

    private int $cacheTtl;

    private string $cachePrefix;

    private bool $budgetAware;

    private int $budgetThreshold;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $priorityConfig = $config->get('zeroboiler.analytics.priority', []);
        /** @var array{enabled?: bool, rate_limits?: array<string, int>, overrides?: array<string, string>, cache_ttl?: int, cache_prefix?: string, budget_aware?: bool, budget_threshold?: int} $priorityConfig */

        $this->enabled = (bool) ($priorityConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($priorityConfig['cache_ttl'] ?? 60);
        $this->cachePrefix = (string) ($priorityConfig['cache_prefix'] ?? 'zb_priority_');
        $this->budgetAware = (bool) ($priorityConfig['budget_aware'] ?? true);
        $this->budgetThreshold = (int) ($priorityConfig['budget_threshold'] ?? 5000);

        // Rate limits: merge defaults with config overrides
        $configuredLimits = $priorityConfig['rate_limits'] ?? [];
        /** @var array<string, int> $configuredLimits */
        $this->rateLimits = array_merge(self::DEFAULT_RATE_LIMITS, $configuredLimits);

        // Custom event-level priority overrides from config
        $overrides = $priorityConfig['overrides'] ?? [];
        /** @var array<string, string> $overrides */
        $this->customOverrides = array_filter(
            $overrides,
            fn (string $p): bool => EventPriority::tryFrom($p) !== null,
        );
    }

    /**
     * Check if an event should be dispatched based on its priority.
     *
     * Critical events always pass. Lower-priority events are checked
     * against per-priority rate limits and optional budget thresholds.
     *
     * @param  AnalyticsEvent  $event
     * @return bool True if the event should be dispatched
     */
    public function allows(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $priority = $this->resolvePriority($event);

        // Critical events always pass
        if ($priority === EventPriority::Critical) {
            return true;
        }

        if (! $this->checkRateLimit($priority)) {
            return false;
        }

        // Budget-aware check for low and background events
        if ($this->budgetAware && $priority->subjectToBudget()) {
            if (! $this->checkBudget()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the priority for a given event.
     *
     * Resolution order:
     * 1. Explicit _priority param in the event
     * 2. Custom config override for the event name
     * 3. Built-in priority override for the event name
     * 4. Default priority for the event's catalog category
     * 5. Normal (fallback)
     */
    public function resolvePriority(AnalyticsEvent $event): EventPriority
    {
        // 1. Explicit param override
        $explicit = $event->params['_priority'] ?? null;
        if (is_string($explicit)) {
            $resolved = EventPriority::fromString($explicit);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 2. Custom config override
        if (isset($this->customOverrides[$event->name])) {
            return EventPriority::from($this->customOverrides[$event->name]);
        }

        // 3. Built-in override
        if (isset(self::EVENT_PRIORITY_OVERRIDES[$event->name])) {
            return EventPriority::from(self::EVENT_PRIORITY_OVERRIDES[$event->name]);
        }

        // 4. Category-based default
        $category = EventCatalog::getCategory($event->name);
        $categoryPriority = match ($category) {
            'ecommerce' => EventPriority::Normal,
            'saas' => EventPriority::Normal,
            'security' => EventPriority::Normal,
            'uptime' => EventPriority::Normal,
            'infrastructure' => EventPriority::Normal,
            'engagement' => EventPriority::Low,
            default => EventPriority::Normal,
        };

        // 5. Fallback
        return $categoryPriority;
    }

    /**
     * Get the effective rate limit for a priority level.
     */
    public function getRateLimit(EventPriority $priority): int
    {
        return $this->rateLimits[$priority->value] ?? self::DEFAULT_RATE_LIMITS[$priority->value] ?? 1000;
    }

    /**
     * Get current dispatch count for a priority level in the current window.
     */
    public function getCurrentCount(EventPriority $priority): int
    {
        $cacheKey = $this->cachePrefix . 'count_' . $priority->value;
        /** @var int|mixed $count */
        $count = $this->cache->get($cacheKey, 0);

        return is_int($count) ? $count : 0;
    }

    /**
     * Increment the dispatch counter for a priority level.
     */
    public function incrementCount(EventPriority $priority): void
    {
        $cacheKey = $this->cachePrefix . 'count_' . $priority->value;
        $this->cache->increment($cacheKey);

        if ($this->cache->get($cacheKey) === 1) {
            $this->cache->put($cacheKey, 1, $this->cacheTtl);
        }
    }

    /**
     * Reset dispatch counters (useful for testing or admin commands).
     */
    public function resetCounters(): void
    {
        foreach (EventPriority::cases() as $priority) {
            $this->cache->forget($this->cachePrefix . 'count_' . $priority->value);
        }
        $this->cache->forget($this->cachePrefix . 'total_dispatched');
    }

    /**
     * Check if the priority gate is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a comprehensive diagnostics summary.
     *
     * @return array{enabled: bool, budget_aware: bool, budget_threshold: int, rate_limits: array<string, int>, current_counts: array<string, int>, override_count: int, builtin_override_count: int}
     */
    public function summary(): array
    {
        $currentCounts = [];
        foreach (EventPriority::cases() as $priority) {
            $currentCounts[$priority->value] = $this->getCurrentCount($priority);
        }

        return [
            'enabled' => $this->enabled,
            'budget_aware' => $this->budgetAware,
            'budget_threshold' => $this->budgetThreshold,
            'rate_limits' => $this->rateLimits,
            'current_counts' => $currentCounts,
            'override_count' => count($this->customOverrides),
            'builtin_override_count' => count(self::EVENT_PRIORITY_OVERRIDES),
        ];
    }

    /**
     * Get all built-in priority overrides (for admin/display).
     *
     * @return array<string, string>
     */
    public static function getBuiltinOverrides(): array
    {
        return self::EVENT_PRIORITY_OVERRIDES;
    }

    /**
     * Get all custom priority overrides from config.
     *
     * @return array<string, string>
     */
    public function getCustomOverrides(): array
    {
        return $this->customOverrides;
    }

    /**
     * Check per-priority rate limit.
     */
    private function checkRateLimit(EventPriority $priority): bool
    {
        $limit = $this->getRateLimit($priority);
        $current = $this->getCurrentCount($priority);

        if ($current >= $limit) {
            return false;
        }

        $this->incrementCount($priority);

        return true;
    }

    /**
     * Check global budget threshold (total events across all priorities).
     */
    private function checkBudget(): bool
    {
        $totalKey = $this->cachePrefix . 'total_dispatched';
        /** @var int|mixed $total */
        $total = $this->cache->get($totalKey, 0);

        if (! is_int($total) || $total >= $this->budgetThreshold) {
            return false;
        }

        $this->cache->increment($totalKey);
        if ($this->cache->get($totalKey) === 1) {
            $this->cache->put($totalKey, 1, $this->cacheTtl);
        }

        return true;
    }
}
