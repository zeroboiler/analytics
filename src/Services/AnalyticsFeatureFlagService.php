<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature flag analytics service for SaaS products.
 *
 * Tracks feature flag evaluations, exposures, and gate conversions.
 * Provides time-series data for feature adoption analysis and
 * A/B test variant assignment tracking.
 *
 * Integrates with the event pipeline to dispatch `ab_test_exposure`
 * and `feature_used` events when feature flags are evaluated.
 *
 * Inspired by LaunchDarkly analytics, Amplitude Experiment, and PostHog feature flags.
 *
 * @since 22.0.0
 */
final class AnalyticsFeatureFlagService
{
    /** @var array<string, mixed> */
    private array $flags = [];

    /** @var array<string, list<string>> */
    private array $exposures = [];

    /**
     * @param  AnalyticsManager  $manager  Analytics manager instance
     * @param  ConfigRepository  $config  Configuration repository
     * @param  CacheRepository  $cache  Cache repository for flag evaluation caching
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ){}

    /**
     * Register a feature flag definition.
     *
     * @param  string  $key  Feature flag key (e.g., 'new_dashboard', 'ai_suggestions')
     * @param  array{enabled: bool, variants?: list<string>, default_variant?: string, description?: string, category?: string}  $definition
     */
    public function registerFlag(string $key, array $definition): void
    {
        $this->flags[$key] = array_merge([
            'key' => $key,
            'enabled' => false,
            'variants' => ['control', 'variant'],
            'default_variant' => 'control',
            'description' => '',
            'category' => 'general',
        ], $definition);
    }

    /**
     * Evaluate a feature flag and track the exposure.
     *
     * @param  string  $key  Feature flag key
     * @param  string|null  $userId  User ID for assignment (null = anonymous)
     * @param  array<string, mixed>  $context  Additional evaluation context
     * @return string The assigned variant or flag value
     */
    public function evaluate(string $key, ?string $userId = null, array $context = []): string
    {
        $flag = $this->flags[$key] ?? null;

        if ($flag === null) {
            return 'control';
        }

        if (! ($flag['enabled'] ?? false)) {
            return $flag['default_variant'] ?? 'control';
        }

        // Deterministic variant assignment based on user ID
        $variant = $this->assignVariant($key, $userId, $flag);

        // Track exposure event
        $this->trackExposure($key, $variant, $userId, $context);

        return $variant;
    }

    /**
     * Track a feature flag exposure event.
     *
     * @param  string  $key  Feature flag key
     * @param  string  $variant  Assigned variant
     * @param  string|null  $userId  User ID
     * @param  array<string, mixed>  $context  Additional context
     */
    public function trackExposure(string $key, string $variant, ?string $userId = null, array $context = []): void
    {
        $this->exposures[$key][] = $variant;

        $event = new AnalyticsEvent(
            name: 'ab_test_exposure',
            params: array_merge($context, [
                'experiment_id' => $key,
                'variant_id' => $variant,
                'flag_key' => $key,
                'flag_category' => $this->flags[$key]['category'] ?? 'general',
            ]),
            userId: $userId,
            priority: 'normal',
            source: 'server',
        );

        $this->manager->track($event);
    }

    /**
     * Track a feature gate conversion (user converted while flag was active).
     *
     * @param  string  $key  Feature flag key
     * @param  string  $variant  Variant that was shown
     * @param  string|null  $userId  User ID
     * @param  array<string, mixed>  $params  Conversion parameters
     */
    public function trackConversion(string $key, string $variant, ?string $userId = null, array $params = []): void
    {
        $event = new AnalyticsEvent(
            name: 'goal_conversion',
            params: array_merge($params, [
                'experiment_id' => $key,
                'variant_id' => $variant,
                'conversion_type' => 'feature_flag',
                'flag_key' => $key,
            ]),
            userId: $userId,
            priority: 'high',
            source: 'server',
        );

        $this->manager->track($event);
    }

    /**
     * Get feature adoption stats for a flag.
     *
     * Returns exposure counts by variant for the current session/cache period.
     *
     * @param  string  $key  Feature flag key
     * @return array{total: int, variants: array<string, int>, last_evaluated: string|null}
     */
    public function getAdoptionStats(string $key): array
    {
        $exposures = $this->exposures[$key] ?? [];

        $variantCounts = [];
        foreach ($exposures as $variant) {
            $variantCounts[$variant] = ($variantCounts[$variant] ?? 0) + 1;
        }

        return [
            'total' => count($exposures),
            'variants' => $variantCounts,
            'last_evaluated' => ! empty($exposures) ? now()->toIso8601String() : null,
        ];
    }

    /**
     * Get all registered feature flags.
     *
     * @return array<string, array{key: string, enabled: bool, variants: list<string>, default_variant: string, category: string}>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * Check if a feature flag is registered and enabled.
     */
    public function isEnabled(string $key): bool
    {
        return ($this->flags[$key]['enabled'] ?? false) === true;
    }

    /**
     * Deterministic variant assignment using hash-based bucketing.
     *
     * Ensures the same user always gets the same variant.
     *
     * @param  string  $key  Feature flag key
     * @param  string|null  $userId  User ID (falls back to 'anonymous')
     * @param  array<string, mixed>  $flag  Flag definition
     */
    private function assignVariant(string $key, ?string $userId, array $flag): string
    {
        $variants = $flag['variants'] ?? ['control', 'variant'];

        if (count($variants) <= 1) {
            return $variants[0] ?? 'control';
        }

        $cacheKey = "zb_ff_{$key}_" . ($userId ?? 'anonymous');
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && in_array($cached, $variants, true)) {
            return $cached;
        }

        // Deterministic hash-based assignment
        $hash = abs(crc32("{$key}:{$userId}"));
        $index = $hash % count($variants);
        $assigned = $variants[$index];

        $this->cache->put($cacheKey, $assigned, 86400);

        return $assigned;
    }
}
