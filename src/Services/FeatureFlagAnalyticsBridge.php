<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature Flag Analytics Bridge.
 *
 * Bridges feature flag evaluation events with analytics tracking.
 * When a feature flag is evaluated for a user, this service automatically
 * creates corresponding analytics events that enable:
 *
 * - A/B test conversion analysis
 * - Feature adoption funnel tracking
 * - Exposure vs. conversion rate computation
 * - Feature flag impact on user behavior
 *
 * Implements the feature flag → analytics event mapping pattern used by
 * LaunchDarkly, Split.io, and PostHog feature flags.
 *
 * @since 203.0.0
 */
final class FeatureFlagAnalyticsBridge
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int Cache TTL for exposure deduplication (seconds) */
    private int $exposureDedupTtl;

    /** @var int Max cached flag evaluation mappings */
    private int $maxFlagMappings;

    /** @var array<string, array{event_name: string, params: array<string, mixed>}> */
    private array $flagMappings;

    /**
     * @param  CacheRepository  $cache  Cache repository for dedup and persistence
     */
    public function __construct(CacheRepository $cache): void
    {
        $this->cache = $cache;
        $this->exposureDedupTtl = 86400; // 24 hours
        $this->maxFlagMappings = 1000;

        $this->flagMappings = [];
    }

    /**
     * Register a feature flag → analytics event mapping.
     *
     * When the specified flag is evaluated, the corresponding analytics
     * event will be tracked automatically.
     *
     * @param  string  $flagKey  Feature flag key (e.g., 'new_dashboard', 'dark_mode')
     * @param  string  $eventName  Analytics event name to track (e.g., 'feature_impression')
     * @param  array<string, mixed>  $defaultParams  Default event parameters
     */
    public function registerMapping(string $flagKey, string $eventName, array $defaultParams = []): void
    {
        $this->flagMappings[$flagKey] = [
            'event_name' => $eventName,
            'params' => $defaultParams,
        ];
    }

    /**
     * Track a feature flag evaluation as an analytics event.
     *
     * Creates an analytics event for the flag evaluation, deduplicated
     * per user per flag to avoid duplicate exposure tracking.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  bool|null  $variant  The evaluated variant (true/false for booleans, string for multivariate)
     * @param  string|null  $userId  User ID (for deduplication)
     * @param  string|null  $clientId  Client ID (fallback for deduplication)
     * @param  array<string, mixed>  $extraParams  Additional event parameters
     * @return AnalyticsEvent|null  The created event, or null if deduplicated
     */
    public function trackEvaluation(
        string $flagKey,
        bool|string|null $variant,
        ?string $userId = null,
        ?string $clientId = null,
        array $extraParams = [],
    ): ?AnalyticsEvent {
        // Check dedup cache
        $identity = $userId ?? $clientId ?? 'anonymous';
        $dedupKey = $this->dedupKey($flagKey, $identity);

        if ($this->isDuplicate($dedupKey)) {
            return null;
        }

        // Mark as seen
        $this->markSeen($dedupKey);

        // Resolve event name from mapping or use default
        $mapping = $this->flagMappings[$flagKey] ?? null;
        $eventName = $mapping['event_name'] ?? 'feature_impression';
        $defaultParams = $mapping['params'] ?? [];

        // Build event parameters
        $params = array_merge($defaultParams, $extraParams, [
            'flag_key' => $flagKey,
            'variant' => $variant,
            'variant_type' => is_bool($variant) ? 'boolean' : (is_string($variant) ? 'multivariate' : 'null'),
        ]);

        return new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            category: 'engagement',
            source: 'feature_flag',
        );
    }

    /**
     * Track a feature flag conversion event.
     *
     * Should be called when a user completes a conversion action
     * while a feature flag variant is active. Links the conversion
     * to the flag variant for analysis.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $conversionEvent  The conversion event name
     * @param  bool|string|null  $variant  Active variant at time of conversion
     * @param  string|null  $userId  User ID
     * @param  string|null  $clientId  Client ID
     * @param  array<string, mixed>  $extraParams  Additional event parameters
     * @return AnalyticsEvent
     */
    public function trackConversion(
        string $flagKey,
        string $conversionEvent,
        bool|string|null $variant,
        ?string $userId = null,
        ?string $clientId = null,
        array $extraParams = [],
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $conversionEvent,
            params: array_merge($extraParams, [
                'flag_key' => $flagKey,
                'variant' => $variant,
                'conversion_type' => 'feature_flag',
            ]),
            clientId: $clientId,
            userId: $userId,
            category: 'engagement',
            source: 'feature_flag',
        );
    }

    /**
     * Compute exposure-to-conversion rate for a feature flag experiment.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $conversionEvent  Target conversion event name
     * @return array{flag_key: string, conversion_event: string, total_exposures: int, total_conversions: int, conversion_rate: float, by_variant: array<string, array{exposures: int, conversions: int, rate: float}>}
     */
    public function conversionRate(string $flagKey, string $conversionEvent): array
    {
        $cacheKey = "zb_analytics_ff_conv_{$flagKey}_{$conversionEvent}";
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // Default result — real implementation queries the event store
        $result = [
            'flag_key' => $flagKey,
            'conversion_event' => $conversionEvent,
            'total_exposures' => 0,
            'total_conversions' => 0,
            'conversion_rate' => 0.0,
            'by_variant' => [],
        ];

        $this->cache->put($cacheKey, $result, 300); // 5 minutes

        return $result;
    }

    /**
     * Get all registered flag mappings.
     *
     * @return array<string, array{event_name: string, params: array<string, mixed>}>
     */
    public function getMappings(): array
    {
        return $this->flagMappings;
    }

    /**
     * Check if a mapping exists for a flag key.
     */
    public function hasMapping(string $flagKey): bool
    {
        return isset($this->flagMappings[$flagKey]);
    }

    /**
     * Remove a flag mapping.
     */
    public function removeMapping(string $flagKey): void
    {
        unset($this->flagMappings[$flagKey]);
    }

    /**
     * Get a diagnostic summary of the bridge state.
     *
     * @return array{registered_mappings: int, max_mappings: int, dedup_ttl: int, mapping_keys: list<string>}
     */
    public function diagnosticSummary(): array
    {
        return [
            'registered_mappings' => count($this->flagMappings),
            'max_mappings' => $this->maxFlagMappings,
            'dedup_ttl' => $this->exposureDedupTtl,
            'mapping_keys' => array_keys($this->flagMappings),
        ];
    }

    /**
     * Generate a deduplication key for a flag evaluation.
     */
    private function dedupKey(string $flagKey, string $identity): string
    {
        return "zb_ff_dedup_{$flagKey}_{$identity}";
    }

    /**
     * Check if a flag evaluation has already been tracked.
     */
    private function isDuplicate(string $dedupKey): bool
    {
        return (bool) $this->cache->get($dedupKey);
    }

    /**
     * Mark a flag evaluation as seen (for deduplication).
     */
    private function markSeen(string $dedupKey): void
    {
        $this->cache->put($dedupKey, true, $this->exposureDedupTtl);
    }
}
