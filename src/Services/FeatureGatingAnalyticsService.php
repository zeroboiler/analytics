<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Feature gating analytics service for plan-based event eligibility.
 *
 * Controls which analytics events can be tracked based on the user's
 * subscription plan. Higher-tier plans unlock premium analytics events
 * (cohort analytics, churn prediction, revenue forecasting) while
 * lower tiers are restricted to core tracking events.
 *
 * Configuration is read from `zeroboiler.analytics.feature_gating`.
 * Results are cached to avoid repeated config parsing.
 *
 * Usage:
 *   $service->isEventAllowed('cohort_retention', 'pro');  // true
 *   $service->isEventAllowed('churn_score', 'free');     // false
 *   $service->allowedEventsForPlan('enterprise');        // all events
 *
 * @since 135.0.0
 */
final class FeatureGatingAnalyticsService
{
    /** Default plan hierarchy from lowest to highest tier */
    private const DEFAULT_PLAN_HIERARCHY = ['free', 'starter', 'pro', 'enterprise'];

    /** Events available to all plans (cannot be gated) */
    private const UNGATED_EVENTS = [
        'page_view', 'click', 'scroll_depth', 'error', 'js_error',
        'session_start', 'session_end', 'time_on_page',
        'sign_up', 'login', 'logout', 'email_verified',
        'search', 'share', 'form_start', 'form_submit',
    ];

    /** Cache TTL in seconds (1 hour) */
    private const CACHE_TTL = 3600;

    private const CACHE_KEY_PREFIX = 'zb_feature_gating_';

    /** @var array<string, list<string>|null|null> Resolved plan → event lists */
    private array $resolvedPlans = [];

    private bool $enabled;

    /** @var array<string, list<string>> Plan hierarchy from config */
    private array $planHierarchy;

    /** @var array<string, list<string>> Plan → allowed event patterns */
    private array $planEventRules;

    /** @var list<string> Premium event categories restricted to higher tiers */
    private array $premiumCategories;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config  Configuration repository
     * @param  CacheRepository  $cache  Cache repository
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $this->config = $config;
        $this->cache = $cache;

        $gatingConfig = $config->get('zeroboiler.analytics.feature_gating', []);
        /** @var array{enabled?: bool, plan_hierarchy?: list<string>, premium_categories?: list<string>, plans?: array<string, list<string>>} $gatingConfig */

        $this->enabled = (bool) ($gatingConfig['enabled'] ?? false);
        $this->planHierarchy = (array) ($gatingConfig['plan_hierarchy'] ?? self::DEFAULT_PLAN_HIERARCHY);
        $this->premiumCategories = (array) ($gatingConfig['premium_categories'] ?? ['cohort', 'retention', 'revenue_intelligence']);
        $this->planEventRules = (array) ($gatingConfig['plans'] ?? []);
    }

    /**
     * Check if feature gating is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a specific event is allowed for a given plan.
     *
     * When feature gating is disabled, all events are allowed.
     * Ungated events (core tracking) are always allowed regardless of plan.
     *
     * @param  string  $eventName  The analytics event name
     * @param  string  $plan  The user's subscription plan
     * @return bool Whether the event is allowed
     */
    public function isEventAllowed(string $eventName, string $plan): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Ungated events are always allowed
        if (in_array($eventName, self::UNGATED_EVENTS, true)) {
            return true;
        }

        $allowedEvents = $this->resolveAllowedEvents($plan);

        if ($allowedEvents === null) {
            // No explicit rules — allow all for unrecognized plans
            return true;
        }

        // If the allowed list is empty, deny all gated events
        if ($allowedEvents === []) {
            return false;
        }

        return in_array($eventName, $allowedEvents, true);
    }

    /**
     * Get the list of allowed events for a given plan.
     *
     * @param  string  $plan  The subscription plan name
     * @return list<string>|null List of allowed event names, null if no gating rules exist
     */
    public function allowedEventsForPlan(string $plan): ?array
    {
        return $this->resolveAllowedEvents($plan);
    }

    /**
     * Get the plan hierarchy from lowest to highest tier.
     *
     * @return list<string>
     */
    public function getPlanHierarchy(): array
    {
        return $this->planHierarchy;
    }

    /**
     * Get the list of ungated (core) event names.
     *
     * @return list<string>
     */
    public function getUngatedEvents(): array
    {
        return self::UNGATED_EVENTS;
    }

    /**
     * Get the list of premium event categories.
     *
     * @return list<string>
     */
    public function getPremiumCategories(): array
    {
        return $this->premiumCategories;
    }

    /**
     * Get all plan event rules.
     *
     * @return array<string, list<string>>
     */
    public function getPlanEventRules(): array
    {
        return $this->planEventRules;
    }

    /**
     * Check if a plan tier is at or above a minimum tier.
     *
     * Compares plan positions in the hierarchy. Returns true if the
     * subject plan is at the same or higher tier than the minimum.
     *
     * @param  string  $plan  The plan to check
     * @param  string  $minimumTier  The minimum required tier
     * @return bool
     */
    public function isPlanAtOrAbove(string $plan, string $minimumTier): bool
    {
        $planIndex = array_search($plan, $this->planHierarchy, true);
        $minimumIndex = array_search($minimumTier, $this->planHierarchy, true);

        if ($planIndex === false || $minimumIndex === false) {
            return true; // Unknown plans default to allowed
        }

        return $planIndex >= $minimumIndex;
    }

    /**
     * Filter a list of events to only those allowed for a given plan.
     *
     * @param  list<string>  $eventNames  Event names to filter
     * @param  string  $plan  The subscription plan
     * @return list<string> Filtered event names
     */
    public function filterAllowedEvents(array $eventNames, string $plan): array
    {
        if (! $this->enabled) {
            return $eventNames;
        }

        return array_values(array_filter(
            $eventNames,
            fn (string $event): bool => $this->isEventAllowed($event, $plan),
        ));
    }

    /**
     * Get blocked events for a given plan.
     *
     * Returns events from the full catalog that are NOT allowed for the plan.
     *
     * @param  string  $plan  The subscription plan
     * @return list<string> Blocked event names
     */
    public function blockedEventsForPlan(string $plan): array
    {
        $allEvents = EventCatalog::names();
        $allowedEvents = $this->allowedEventsForPlan($plan);

        if ($allowedEvents === null) {
            return [];
        }

        return array_values(array_diff(
            $allEvents,
            self::UNGATED_EVENTS,
            $allowedEvents,
        ));
    }

    /**
     * Get a summary of feature gating configuration.
     *
     * @return array{enabled: bool, plan_count: int, ungated_count: int, premium_categories: list<string>, hierarchy: list<string>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'plan_count' => count($this->planHierarchy),
            'ungated_count' => count(self::UNGATED_EVENTS),
            'premium_categories' => $this->premiumCategories,
            'hierarchy' => $this->planHierarchy,
        ];
    }

    /**
     * Resolve allowed events for a plan, using cache.
     *
     * @param  string  $plan  The subscription plan
     * @return list<string>|null
     */
    private function resolveAllowedEvents(string $plan): ?array
    {
        if (isset($this->resolvedPlans[$plan])) {
            return $this->resolvedPlans[$plan];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $plan;

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            /** @var list<string>|null $cached */
            $this->resolvedPlans[$plan] = $cached;

            return $cached;
        }

        $allowed = $this->computeAllowedEvents($plan);

        $this->cache->put($cacheKey, $allowed, self::CACHE_TTL);
        $this->resolvedPlans[$plan] = $allowed;

        return $allowed;
    }

    /**
     * Compute the list of allowed events for a plan.
     *
     * Uses the configured plan event rules. If no explicit rules for the plan,
     * builds the allowed list from all plans at or below the plan's tier.
     *
     * @param  string  $plan  The subscription plan
     * @return list<string>|null
     */
    private function computeAllowedEvents(string $plan): ?array
    {
        // Check for explicit event rules for this plan
        if (isset($this->planEventRules[$plan])) {
            return $this->planEventRules[$plan];
        }

        // No explicit rules — check if any plans have rules
        if ($this->planEventRules === []) {
            return null; // No gating configured
        }

        // Build allowed list from all plans at or below this plan's tier
        $planIndex = array_search($plan, $this->planHierarchy, true);

        if ($planIndex === false) {
            return null; // Unknown plan — no gating
        }

        $allowed = [];
        for ($i = 0; $i <= $planIndex; $i++) {
            $tierPlan = $this->planHierarchy[$i];
            if (isset($this->planEventRules[$tierPlan])) {
                foreach ($this->planEventRules[$tierPlan] as $event) {
                    $allowed[] = $event;
                }
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Clear the resolved plans cache.
     */
    public function clearCache(): void
    {
        $this->resolvedPlans = [];

        foreach ($this->planHierarchy as $plan) {
            $this->cache->forget(self::CACHE_KEY_PREFIX . $plan);
        }
    }
}
