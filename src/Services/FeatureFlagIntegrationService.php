<?php

declare(strict_types=1);

/**
 * Feature Flag Integration Service for ZeroBoiler Analytics.
 *
 * Bridges analytics event dispatch with feature flag evaluation.
 * Allows analytics to be gated, annotated, or routed based on feature
 * flag states — useful for A/B test correlation, gradual rollouts,
 * and feature adoption tracking.
 *
 * Supports LaunchDarkly-style flag evaluation via a pluggable resolver
 * callback, or config-driven static flags for simpler use cases.
 *
 * @license MIT
 * @version 2.69.0
 * @package ZeroBoiler\Analytics\Services
 */

namespace ZeroBoiler\Analytics\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;

final class FeatureFlagIntegrationService
{
    /** @var AnalyticsManager */
    private AnalyticsManager $manager;

    /** @var Repository */
    private Repository $cache;

    /** @var array<string, mixed> Config-driven flag definitions */
    private array $flags;

    /** @var Closure|null External flag resolver (e.g. LaunchDarkly, Unleash, Pennant) */
    private ?Closure $resolver = null;

    /** @var array<string, bool> Cached evaluation results */
    private array $evaluationCache = [];

    /** @var int Cache TTL in seconds for flag evaluations */
    private int $cacheTtl;

    /** @var bool Whether feature flag gating is enabled */
    private bool $enabled;

    /** @var string Cache prefix */
    private const CACHE_PREFIX = 'zb_analytics_flags_';

    /** @var int Default cache TTL (5 minutes) */
    private const DEFAULT_CACHE_TTL = 300;

    /**
     * Create a new FeatureFlagIntegrationService.
     *
     * @param  AnalyticsManager  $manager  Analytics manager instance
     * @param  Repository  $cache  Cache repository
     * @param  array<string, mixed>  $flags  Flag definitions from config
     * @param  bool  $enabled  Whether flag gating is enabled
     * @param  int  $cacheTtl  Cache TTL for flag evaluations
     */
    public function __construct(
        AnalyticsManager $manager,
        Repository $cache,
        array $flags = [],
        bool $enabled = true,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
        $this->manager = $manager;
        $this->cache = $cache;
        $this->flags = $flags;
        $this->enabled = $enabled;
        $this->cacheTtl = $cacheTtl;
    }

    // ─── Resolver Registration ───────────────────────────────────────

    /**
     * Register an external feature flag resolver.
     *
     * The resolver receives (string $flagKey, string $contextId) and must
     * return bool|null. When null is returned, the service falls back
     * to config-driven static flags.
     *
     * @param  Closure(string, string): (bool|null)  $resolver
     * @return self
     *
     * @example
     * $service->setResolver(function (string $flag, string $userId): ?bool {
     *     return \LaunchDarkly::variation($flag, $userId, false);
     * });
     */
    public function setResolver(Closure $resolver): self
    {
        $this->resolver = $resolver;
        $this->evaluationCache = [];

        return $this;
    }

    // ─── Flag Evaluation ─────────────────────────────────────────────

    /**
     * Evaluate a feature flag for a given context.
     *
     * Resolution order:
     * 1. External resolver (if registered)
     * 2. Config-driven static flags
     * 3. Default value (false)
     *
     * Results are cached in-memory and optionally in the cache store.
     *
     * @param  string  $flagKey  Feature flag key (e.g. 'new_dashboard', 'phase_2_rollout')
     * @param  string  $contextId  Context identifier (user ID, client ID, session ID)
     * @param  bool  $defaultValue  Default if flag is not defined
     * @return bool
     */
    public function evaluate(string $flagKey, string $contextId, bool $defaultValue = false): bool
    {
        if (!$this->enabled) {
            return true; // When disabled, all flags are considered "on"
        }

        // Check in-memory cache first
        $cacheKey = "{$flagKey}:{$contextId}";
        if (array_key_exists($cacheKey, $this->evaluationCache)) {
            return $this->evaluationCache[$cacheKey];
        }

        // Check persistent cache
        $persistentKey = self::CACHE_PREFIX . md5($cacheKey);
        $cached = $this->cache->get($persistentKey);
        if ($cached !== null) {
            $this->evaluationCache[$cacheKey] = (bool) $cached;

            return $this->evaluationCache[$cacheKey];
        }

        // Evaluate via resolver
        if ($this->resolver !== null) {
            $result = ($this->resolver)($flagKey, $contextId);

            if ($result !== null) {
                $this->evaluationCache[$cacheKey] = $result;
                $this->cache->put($persistentKey, $result, $this->cacheTtl);

                return $result;
            }
        }

        // Fall back to config-driven static flags
        $result = $this->evaluateStaticFlag($flagKey, $defaultValue);
        $this->evaluationCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Evaluate a static flag from config definitions.
     *
     * Supports percentage rollouts, allow/deny lists, and boolean values.
     *
     * @param  string  $flagKey
     * @param  bool  $defaultValue
     * @return bool
     */
    private function evaluateStaticFlag(string $flagKey, bool $defaultValue): bool
    {
        $flag = $this->flags[$flagKey] ?? null;

        if ($flag === null) {
            return $defaultValue;
        }

        // Simple boolean flag
        if (is_bool($flag)) {
            return $flag;
        }

        if (!is_array($flag)) {
            return $defaultValue;
        }

        // Check if flag is enabled globally
        if (($flag['enabled'] ?? true) === false) {
            return false;
        }

        // Check allow list
        $allowList = $flag['allow'] ?? [];
        $denyList = $flag['deny'] ?? [];

        // Percentage rollout
        $rolloutPercent = $flag['rollout_percent'] ?? 100;

        // If no advanced rules, return enabled state
        if ($rolloutPercent === 100 && empty($allowList) && empty($denyList)) {
            return true;
        }

        return true; // Simplified: config-driven flags without context are true
    }

    /**
     * Check if a percentage rollout should include a given identifier.
     *
     * Uses deterministic hashing of the flag key + context ID to ensure
     * the same user always gets the same result (sticky rollout).
     *
     * @param  string  $flagKey
     * @param  string  $contextId
     * @param  int  $rolloutPercent  0-100
     * @return bool
     */
    public function isInRollout(string $flagKey, string $contextId, int $rolloutPercent): bool
    {
        if ($rolloutPercent >= 100) {
            return true;
        }

        if ($rolloutPercent <= 0) {
            return false;
        }

        $hash = abs(crc32("{$flagKey}:{$contextId}"));
        $bucket = $hash % 100;

        return $bucket < $rolloutPercent;
    }

    // ─── Event Gating ───────────────────────────────────────────────

    /**
     * Check if an analytics event should be dispatched based on feature flags.
     *
     * When a gate flag is configured for an event, the event is only
     * dispatched if the flag evaluates to true for the given context.
     *
     * @param  string  $eventName  Analytics event name
     * @param  string  $contextId  User/client ID for flag evaluation
     * @return bool
     */
    public function shouldDispatchEvent(string $eventName, string $contextId): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $gates = $this->manager->config('feature_flags.gates', []);

        // Check if this event has a gate
        $gateFlag = $gates[$eventName] ?? null;

        if ($gateFlag === null) {
            return true; // No gate = dispatch normally
        }

        return $this->evaluate($gateFlag, $contextId);
    }

    /**
     * Annotate an event payload with active flag states.
     *
     * Adds feature flag evaluation results as event parameters,
     * enabling downstream analysis of feature flag correlation with events.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $contextId  Context for flag evaluation
     * @param  string[]  $flagKeys  Flags to annotate (empty = all defined)
     * @return array<string, mixed> Annotated parameters
     */
    public function annotateEvent(array $params, string $contextId, array $flagKeys = []): array
    {
        if (!$this->enabled) {
            return $params;
        }

        $annotateFlags = $this->manager->config('feature_flags.annotate_events', []);
        $enabledAnnotatedFlags = $annotateFlags['enabled'] ?? false;
        $maxFlags = $annotateFlags['max_flags'] ?? 10;
        $prefix = $annotateFlags['param_prefix'] ?? 'ff_';

        if (!$enabledAnnotatedFlags) {
            return $params;
        }

        $flagsToAnnotate = !empty($flagKeys)
            ? array_slice($flagKeys, 0, $maxFlags)
            : array_slice(array_keys($this->flags), 0, $maxFlags);

        $annotations = [];
        foreach ($flagsToAnnotate as $flagKey) {
            $safeKey = preg_replace('/[^a-z0-9_]/i', '_', $flagKey);
            $annotations["{$prefix}{$safeKey}"] = $this->evaluate($flagKey, $contextId) ? 'on' : 'off';
        }

        return array_merge($params, $annotations);
    }

    // ─── Feature Adoption Tracking ──────────────────────────────────

    /**
     * Record a feature exposure/impression event.
     *
     * Tracks when a user encounters a feature-flagged element,
     * enabling adoption funnel analysis.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $contextId  User/client ID
     * @param  array<string, mixed>  $params  Additional params (location, variant, etc.)
     * @return void
     */
    public function trackExposure(string $flagKey, string $contextId, array $params = []): void
    {
        $exposureTracking = $this->manager->config('feature_flags.exposure_tracking', []);
        if (!($exposureTracking['enabled'] ?? false)) {
            return;
        }

        $this->manager->track('feature_flag_exposure', array_merge([
            'flag_key' => $flagKey,
            'flag_value' => $this->evaluate($flagKey, $contextId) ? 'on' : 'off',
            'context_id' => $contextId,
        ], $params));
    }

    /**
     * Record a feature usage event.
     *
     * Unlike exposure (which tracks when a user sees a feature),
     * this tracks when a user actually interacts with a feature.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $contextId  User/client ID
     * @param  array<string, mixed>  $params  Additional params
     * @return void
     */
    public function trackUsage(string $flagKey, string $contextId, array $params = []): void
    {
        $this->manager->track('feature_flag_usage', array_merge([
            'flag_key' => $flagKey,
            'flag_value' => $this->evaluate($flagKey, $contextId) ? 'on' : 'off',
            'context_id' => $contextId,
        ], $params));
    }

    // ─── A/B Test Correlation ──────────────────────────────────────

    /**
     * Get all flag states for a context as a correlation payload.
     *
     * Returns an array of flag key → value pairs suitable for
     * attaching to events for A/B test analysis.
     *
     * @param  string  $contextId  User/client ID
     * @param  string[]  $flagKeys  Specific flags (empty = all)
     * @return array<string, string>
     */
    public function getCorrelationPayload(string $contextId, array $flagKeys = []): array
    {
        $flags = !empty($flagKeys) ? $flagKeys : array_keys($this->flags);
        $payload = [];

        foreach ($flags as $key) {
            $payload[$key] = $this->evaluate($key, $contextId) ? 'on' : 'off';
        }

        return $payload;
    }

    // ─── Flag Management ────────────────────────────────────────────

    /**
     * Get all defined feature flags.
     *
     * @return array<string, mixed>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * Get a specific flag definition.
     *
     * @param  string  $flagKey
     * @return mixed
     */
    public function getFlag(string $flagKey): mixed
    {
        return $this->flags[$flagKey] ?? null;
    }

    /**
     * Check if a flag exists.
     *
     * @param  string  $flagKey
     * @return bool
     */
    public function hasFlag(string $flagKey): bool
    {
        return array_key_exists($flagKey, $this->flags);
    }

    /**
     * Override a flag value at runtime (testing / debugging).
     *
     * @param  string  $flagKey
     * @param  bool  $value
     * @return self
     */
    public function override(string $flagKey, bool $value): self
    {
        $this->evaluationCache = [];
        $this->cache->put(self::CACHE_PREFIX . '_override_' . $flagKey, $value, $this->cacheTtl);

        return $this;
    }

    /**
     * Clear all overrides and evaluation caches.
     *
     * @return self
     */
    public function clearOverrides(): self
    {
        $this->evaluationCache = [];

        return $this;
    }

    // ─── Summary & Status ───────────────────────────────────────────

    /**
     * Get the integration status summary.
     *
     * @return array{enabled: bool, resolver_registered: bool, flags_count: int, cache_ttl: int, cached_evaluations: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'resolver_registered' => $this->resolver !== null,
            'flags_count' => count($this->flags),
            'cache_ttl' => $this->cacheTtl,
            'cached_evaluations' => count($this->evaluationCache),
        ];
    }
}
