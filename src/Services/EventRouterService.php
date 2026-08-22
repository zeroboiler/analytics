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
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider-aware event router — Segment/RudderStack-style destination filtering.
 *
 * Determines which analytics providers should receive a given event based on
 * configurable routing rules. Supports:
 *
 * - Category-based routing (e.g., send ecommerce events only to GA4 + Meta)
 * - Event name pattern matching (glob and regex)
 * - Priority-based routing (critical events → all providers, background → subset)
 * - Cost-optimized routing (skip expensive providers for high-volume events)
 * - Deny-list/block-list (never send specific events to specific providers)
 * - Allow-list/whitelist (only send specific events to specific providers)
 *
 * The router is consulted before dispatch in AnalyticsManager::dispatchToTrackers().
 * When routing rules resolve to an empty provider list, the event is silently
 * dropped (no error) — this is by design for cost control.
 *
 * Configuration is read from `zeroboiler.analytics.event_router`.
 *
 * @since 37.0.0
 */
final class EventRouterService
{
    /** @var list<string> All supported provider identifiers */
    private const ALL_PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
        'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin',
    ];

    private bool $enabled;

    /** @var array<string, list<string>> Category → provider routing rules */
    private array $categoryRoutes;

    /** @var list<array{pattern: string, providers: list<string>, type: 'glob'|'regex'}> Pattern-based rules */
    private array $patternRules;

    /** @var array<string, list<string>> Priority level → provider mapping */
    private array $priorityRoutes;

    /** @var bool Whether cost-optimized routing is active */
    private bool $costOptimized;

    /** @var float Cost threshold (relative) above which providers are excluded for high-volume events */
    private float $costThreshold;

    /** @var array<string, list<string>> event_name → blocked providers */
    private array $denyList;

    /** @var array<string, list<string>> event_name → allowed providers (restrict to these only) */
    private array $allowList;

    /** @var list<string> Default providers when no rules match (null = all enabled) */
    private ?array $defaultProviders;

    private CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  ConfigRepository  $config  Application config repository
     * @param  CacheRepository  $cache  Cache repository
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $routerConfig = $config->get('zeroboiler.analytics.event_router', []);
        /** @var array{enabled?: bool, category_routes?: array<string, list<string>>, pattern_rules?: list<array{pattern: string, providers: list<string>, type?: string}>, priority_routes?: array<string, list<string>>, cost_optimized?: bool, cost_threshold?: float, deny_list?: array<string, list<string>>, allow_list?: array<string, list<string>>, default_providers?: list<string>|null, cache_ttl?: int} $routerConfig */

        $this->enabled = (bool) ($routerConfig['enabled'] ?? false);
        $this->categoryRoutes = (array) ($routerConfig['category_routes'] ?? []);
        $this->patternRules = (array) ($routerConfig['pattern_rules'] ?? []);
        $this->priorityRoutes = (array) ($routerConfig['priority_routes'] ?? []);
        $this->costOptimized = (bool) ($routerConfig['cost_optimized'] ?? false);
        $this->costThreshold = (float) ($routerConfig['cost_threshold'] ?? 0.5);
        $this->denyList = (array) ($routerConfig['deny_list'] ?? []);
        $this->allowList = (array) ($routerConfig['allow_list'] ?? []);
        $this->defaultProviders = $routerConfig['default_providers'] ?? null;
        $this->cache = $cache;
        $this->cacheTtl = (int) ($routerConfig['cache_ttl'] ?? 300);
    }

    /**
     * Check if the event router is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Route an event to its target providers.
     *
     * Evaluates all routing rules in order:
     * 1. Deny list (hard block — event is never sent to these providers)
     * 2. Allow list (restrict to only these providers)
     * 3. Category-based routing
     * 4. Pattern-based routing (glob/regex)
     * 5. Priority-based routing
     * 6. Cost-optimized routing
     * 7. Default providers (or all enabled if no default set)
     *
     * Returns an empty array to indicate the event should be dropped.
     *
     * @return list<string> Provider identifiers that should receive this event
     */
    public function route(AnalyticsEvent $event, AnalyticsManager $manager): array
    {
        if (! $this->enabled) {
            return $this->getEnabledProviders($manager);
        }

        $name = $event->name;
        $category = EventCatalog::getCategory($name);
        $priority = $event->priority ?? 'normal';

        // Step 1: Start with all enabled providers
        $providers = $this->getEnabledProviders($manager);

        // Step 2: Apply deny list (remove blocked providers)
        $providers = $this->applyDenyList($name, $providers);

        // Step 3: Apply allow list (restrict to specified providers)
        if (isset($this->allowList[$name])) {
            $allowed = $this->allowList[$name];
            $providers = array_values(array_intersect($providers, $allowed));
        }

        // Step 4: Apply category-based routing
        if ($category !== null && isset($this->categoryRoutes[$category])) {
            $categoryProviders = $this->categoryRoutes[$category];
            $providers = array_values(array_intersect($providers, $categoryProviders));
        }

        // Step 5: Apply pattern-based routing
        $patternMatch = $this->matchPatternRules($name);
        if ($patternMatch !== []) {
            $providers = array_values(array_intersect($providers, $patternMatch));
        }

        // Step 6: Apply priority-based routing
        if (isset($this->priorityRoutes[$priority])) {
            $priorityProviders = $this->priorityRoutes[$priority];
            $providers = array_values(array_intersect($providers, $priorityProviders));
        }

        // Step 7: Apply cost optimization
        if ($this->costOptimized) {
            $providers = $this->applyCostOptimization($event, $providers);
        }

        // Step 8: Apply default providers if set
        if ($this->defaultProviders !== null) {
            $providers = array_values(array_intersect($providers, $this->defaultProviders));
        }

        return $providers;
    }

    /**
     * Check if a specific event should be sent to a specific provider.
     *
     * Convenience method for single-provider checks.
     */
    public function shouldSendTo(AnalyticsEvent $event, string $provider, AnalyticsManager $manager): bool
    {
        $routed = $this->route($event, $manager);

        return in_array($provider, $routed, true);
    }

    /**
     * Get the routing decision for an event with reasoning.
     *
     * Returns the resolved providers along with which rules were applied.
     *
     * @return array{providers: list<string>, rules_applied: list<string>, dropped: bool}
     */
    public function routeWithReasoning(AnalyticsEvent $event, AnalyticsManager $manager): array
    {
        $name = $event->name;
        $category = EventCatalog::getCategory($name);
        $priority = $event->priority ?? 'normal';
        $rulesApplied = [];

        $providers = $this->getEnabledProviders($manager);

        // Deny list
        $before = count($providers);
        $providers = $this->applyDenyList($name, $providers);
        if (count($providers) < $before) {
            $rulesApplied[] = 'deny_list';
        }

        // Allow list
        if (isset($this->allowList[$name])) {
            $before = count($providers);
            $providers = array_values(array_intersect($providers, $this->allowList[$name]));
            $rulesApplied[] = 'allow_list';
        }

        // Category routing
        if ($category !== null && isset($this->categoryRoutes[$category])) {
            $before = count($providers);
            $providers = array_values(array_intersect($providers, $this->categoryRoutes[$category]));
            $rulesApplied[] = "category:{$category}";
        }

        // Pattern routing
        $patternMatch = $this->matchPatternRules($name);
        if ($patternMatch !== []) {
            $before = count($providers);
            $providers = array_values(array_intersect($providers, $patternMatch));
            $rulesApplied[] = 'pattern_match';
        }

        // Priority routing
        if (isset($this->priorityRoutes[$priority])) {
            $before = count($providers);
            $providers = array_values(array_intersect($providers, $this->priorityRoutes[$priority]));
            $rulesApplied[] = "priority:{$priority}";
        }

        // Cost optimization
        if ($this->costOptimized) {
            $before = count($providers);
            $providers = $this->applyCostOptimization($event, $providers);
            if (count($providers) < $before) {
                $rulesApplied[] = 'cost_optimization';
            }
        }

        // Default providers
        if ($this->defaultProviders !== null) {
            $providers = array_values(array_intersect($providers, $this->defaultProviders));
            $rulesApplied[] = 'default_providers';
        }

        return [
            'providers' => $providers,
            'rules_applied' => $rulesApplied,
            'dropped' => $providers === [],
        ];
    }

    /**
     * Get all routing rules as a summary.
     *
     * @return array{enabled: bool, category_routes: array<string, list<string>>, pattern_rules_count: int, priority_routes: array<string, list<string>>, deny_list_count: int, allow_list_count: int, cost_optimized: bool, default_providers: list<string>|null}
     */
    public function getRoutingSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'category_routes' => $this->categoryRoutes,
            'pattern_rules_count' => count($this->patternRules),
            'priority_routes' => $this->priorityRoutes,
            'deny_list_count' => count($this->denyList),
            'allow_list_count' => count($this->allowList),
            'cost_optimized' => $this->costOptimized,
            'default_providers' => $this->defaultProviders,
        ];
    }

    /**
     * Validate routing rules for common misconfigurations.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateRules(): array
    {
        $errors = [];
        $warnings = [];
        $validProviders = array_flip(self::ALL_PROVIDERS);

        // Check category routes reference valid providers
        foreach ($this->categoryRoutes as $category => $providers) {
            foreach ($providers as $provider) {
                if (! isset($validProviders[$provider])) {
                    $errors[] = "Category route '{$category}' references unknown provider '{$provider}'";
                }
            }
        }

        // Check pattern rules reference valid providers
        foreach ($this->patternRules as $index => $rule) {
            $type = $rule['type'] ?? 'glob';
            if (! in_array($type, ['glob', 'regex'], true)) {
                $errors[] = "Pattern rule at index {$index} has invalid type '{$type}' (use 'glob' or 'regex')";
            }

            foreach ($rule['providers'] ?? [] as $provider) {
                if (! isset($validProviders[$provider])) {
                    $errors[] = "Pattern rule '{$rule['pattern']}' references unknown provider '{$provider}'";
                }
            }

            // Validate regex patterns compile
            if ($type === 'regex') {
                $pattern = $rule['pattern'];
                $test = @preg_match($pattern, '');
                if ($test === false) {
                    $errors[] = "Pattern rule at index {$index} has invalid regex '{$pattern}'";
                }
            }
        }

        // Check priority routes reference valid priorities
        $validPriorities = ['critical', 'normal', 'low', 'background'];
        foreach ($this->priorityRoutes as $priority => $providers) {
            if (! in_array($priority, $validPriorities, true)) {
                $warnings[] = "Priority route uses unknown priority '{$priority}'";
            }
            foreach ($providers as $provider) {
                if (! isset($validProviders[$provider])) {
                    $errors[] = "Priority route '{$priority}' references unknown provider '{$provider}'";
                }
            }
        }

        // Check for overly restrictive rules (empty provider intersections)
        if ($this->defaultProviders !== null && $this->defaultProviders === []) {
            $errors[] = 'Default providers list is empty — all events will be dropped';
        }

        // Check deny list + allow list conflicts
        foreach ($this->denyList as $event => $deniedProviders) {
            if (isset($this->allowList[$event])) {
                $intersection = array_intersect($deniedProviders, $this->allowList[$event]);
                if ($intersection !== []) {
                    $warnings[] = "Event '{$event}' has providers in both deny and allow lists: " . implode(', ', $intersection);
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Cache the routing decision for an event.
     *
     * Useful for high-volume events where routing computation should be cached.
     */
    public function cacheRoute(string $eventName, array $providers): void
    {
        $key = "zb_router_{$eventName}";
        $this->cache->put($key, $providers, $this->cacheTtl);
    }

    /**
     * Get a cached routing decision for an event.
     *
     * @return list<string>|null Cached providers or null if not cached
     */
    public function getCachedRoute(string $eventName): ?array
    {
        $key = "zb_router_{$eventName}";
        $cached = $this->cache->get($key);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Clear cached routing decisions.
     */
    public function clearCache(): void
    {
        // Clear all router cache keys (best-effort)
        try {
            $this->cache->forget('zb_router_*');
        } catch (\Throwable $e) {
            Log::debug("EventRouterService: failed to clear cache: {$e->getMessage()}");
        }
    }

    /**
     * Get all supported provider identifiers.
     *
     * @return list<string>
     */
    public static function allProviders(): array
    {
        return self::ALL_PROVIDERS;
    }

    /**
     * Get the currently enabled providers from the AnalyticsManager.
     *
     * @return list<string>
     */
    private function getEnabledProviders(AnalyticsManager $manager): array
    {
        $providers = [];

        if ($manager->ga4()->isEnabled()) {
            $providers[] = 'ga4';
        }
        if ($manager->gtm()->isEnabled()) {
            $providers[] = 'gtm';
        }
        if ($manager->meta()->isEnabled()) {
            $providers[] = 'meta_pixel';
        }
        if ($manager->plausible()->isEnabled()) {
            $providers[] = 'plausible';
        }
        if ($manager->posthog()->isEnabled()) {
            $providers[] = 'posthog';
        }
        if ($manager->mixpanel()->isEnabled()) {
            $providers[] = 'mixpanel';
        }
        if ($manager->amplitude()->isEnabled()) {
            $providers[] = 'amplitude';
        }
        if ($manager->tiktok()->isEnabled()) {
            $providers[] = 'tiktok';
        }
        if ($manager->linkedin()->isEnabled()) {
            $providers[] = 'linkedin';
        }
        if ($manager->webhook()->isEnabled()) {
            $providers[] = 'webhook';
        }

        return $providers;
    }

    /**
     * Remove denied providers from the candidate list.
     *
     * @param  list<string>  $providers  Candidate providers
     * @return list<string> Filtered providers
     */
    private function applyDenyList(string $eventName, array $providers): array
    {
        $denied = $this->denyList[$eventName] ?? [];

        if ($denied === []) {
            return $providers;
        }

        return array_values(array_diff($providers, $denied));
    }

    /**
     * Match event name against pattern rules.
     *
     * Returns the providers from the first matching rule, or empty array.
     *
     * @return list<string> Matched providers
     */
    private function matchPatternRules(string $eventName): array
    {
        foreach ($this->patternRules as $rule) {
            $pattern = $rule['pattern'] ?? '';
            $type = $rule['type'] ?? 'glob';

            $matches = false;

            if ($type === 'regex') {
                $matches = (bool) @preg_match($pattern, $eventName);
            } else {
                // Glob matching: convert * to regex
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                $matches = (bool) @preg_match($regex, $eventName);
            }

            if ($matches) {
                return $rule['providers'] ?? [];
            }
        }

        return [];
    }

    /**
     * Apply cost optimization by excluding expensive providers.
     *
     * For events with 'low' or 'background' priority, providers whose
     * cost weight exceeds the threshold are excluded.
     *
     * @param  list<string>  $providers  Candidate providers
     * @return list<string> Cost-filtered providers
     */
    private function applyCostOptimization(AnalyticsEvent $event, array $providers): array
    {
        $priority = $event->priority ?? 'normal';

        // Only apply cost optimization for low-priority events
        if (! in_array($priority, ['low', 'background'], true)) {
            return $providers;
        }

        // Cost weights by provider (simplified)
        $costWeights = [
            'ga4' => 0.2,
            'gtm' => 0.1,
            'meta_pixel' => 0.3,
            'plausible' => 0.15,
            'posthog' => 0.5,
            'mixpanel' => 0.45,
            'amplitude' => 0.5,
            'webhook' => 0.1,
            'tiktok' => 0.3,
            'linkedin' => 0.25,
        ];

        return array_values(array_filter(
            $providers,
            fn (string $p): bool => ($costWeights[$p] ?? 0) <= $this->costThreshold,
        ));
    }
}
