<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\SaaS\FeatureFlagEvaluatedEvent;

/**
 * Feature flag analytics service.
 *
 * Tracks feature flag evaluations and their downstream conversion impact.
 * Provides variant distribution analysis, first-exposure tracking,
 * flag adoption metrics, and integration with A/B testing workflows.
 *
 * Works with any feature flag provider (LaunchDarkly, Unleash, Flagsmith,
 * Optimizely, custom) by accepting standardized evaluation events.
 *
 * @since 78.0.0
 */
final class FeatureFlagAnalyticsService
{
    private const CACHE_PREFIX = 'zb_feature_flag_';

    private CacheRepository $cache;
    private AnalyticsManager $manager;
    private int $cacheTtl;

    /** @var array<string, array{control: int, treatment: int, on: int, off: int}> Variant exposure counts */
    private array $exposureCounts = [];

    public function __construct(
        CacheRepository $cache,
        AnalyticsManager $manager,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->manager = $manager;

        /** @var array{cache_ttl?: int} $ffConfig */
        $ffConfig = $config->get('zeroboiler.analytics.feature_flags', []);
        $this->cacheTtl = $ffConfig['cache_ttl'] ?? 300;

        $this->loadExposureData();
    }

    /**
     * Track a feature flag evaluation.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $variant  Assigned variant
     * @param  bool  $isFirstExposure  Whether this is the user's first exposure
     * @param  array<string, mixed>  $context  Additional context
     */
    public function trackEvaluation(
        string $flagKey,
        string $variant,
        bool $isFirstExposure = false,
        array $context = [],
    ): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: $flagKey,
            variant: $variant,
            isFirstExposure: $isFirstExposure,
            evaluationReason: $context['evaluation_reason'] ?? null,
            experimentId: $context['experiment_id'] ?? null,
            flagType: $context['flag_type'] ?? null,
        );

        $this->manager->trackEvent($event);
        $this->recordExposure($flagKey, $variant);
    }

    /**
     * Track a conversion event attributed to a feature flag variant.
     *
     * Use this after trackEvaluation() to connect a conversion (signup, purchase, etc.)
     * back to the flag variant the user was exposed to.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  string  $variant  Variant the conversion is attributed to
     * @param  string  $conversionEvent  The conversion event name (e.g. 'purchase', 'sign_up')
     * @param  array<string, mixed>  $params  Conversion parameters
     */
    public function trackConversion(
        string $flagKey,
        string $variant,
        string $conversionEvent,
        array $params = [],
    ): void {
        $this->manager->track('feature_flag_conversion', array_merge($params, [
            'flag_key' => $flagKey,
            'variant' => $variant,
            'conversion_event' => $conversionEvent,
        ]));

        $this->recordConversion($flagKey, $variant);
    }

    /**
     * Get variant distribution for a feature flag.
     *
     * @return array{flag_key: string, total_exposures: int, variants: array<string, array{count: int, percentage: float}>}
     */
    public function variantDistribution(string $flagKey): array
    {
        $counts = $this->exposureCounts[$flagKey] ?? ['control' => 0, 'treatment' => 0, 'on' => 0, 'off' => 0];
        $total = array_sum($counts);

        $variants = [];
        foreach ($counts as $variant => $count) {
            if ($count > 0) {
                $variants[$variant] = [
                    'count' => $count,
                    'percentage' => round(($count / $total) * 100, 2),
                ];
            }
        }

        return [
            'flag_key' => $flagKey,
            'total_exposures' => $total,
            'variants' => $variants,
        ];
    }

    /**
     * Get all tracked feature flags with their status.
     *
     * @return list<array{flag_key: string, total_exposures: int, variants: array<string, int>}>
     */
    public function allFlags(): array
    {
        $result = [];

        foreach ($this->exposureCounts as $flagKey => $counts) {
            $total = array_sum($counts);
            if ($total > 0) {
                $result[] = [
                    'flag_key' => $flagKey,
                    'total_exposures' => $total,
                    'variants' => $counts,
                ];
            }
        }

        return $result;
    }

    /**
     * Get conversion rates per variant for a flag.
     *
     * @return array{flag_key: string, variants: array<string, array{exposures: int, conversions: int, rate: float}>}
     */
    public function conversionRates(string $flagKey): array
    {
        $exposures = $this->exposureCounts[$flagKey] ?? [];
        $cacheKey = self::CACHE_PREFIX . 'conversions_' . $flagKey;
        $conversions = $this->cache->get($cacheKey, []);

        $rates = [];
        foreach ($exposures as $variant => $expCount) {
            $convCount = $conversions[$variant] ?? 0;
            if ($expCount > 0) {
                $rates[$variant] = [
                    'exposures' => $expCount,
                    'conversions' => $convCount,
                    'rate' => round(($convCount / $expCount) * 100, 2),
                ];
            }
        }

        return [
            'flag_key' => $flagKey,
            'variants' => $rates,
        ];
    }

    /**
     * Get feature adoption summary — which flags are most evaluated.
     *
     * @return list<array{flag_key: string, total_evaluations: int, unique_variants: int}>
     */
    public function adoptionSummary(): array
    {
        $summary = [];

        foreach ($this->exposureCounts as $flagKey => $counts) {
            $total = array_sum($counts);
            $unique = count(array_filter($counts));

            $summary[] = [
                'flag_key' => $flagKey,
                'total_evaluations' => $total,
                'unique_variants' => $unique,
            ];
        }

        usort($summary, fn (array $a, array $b): int => $b['total_evaluations'] <=> $a['total_evaluations']);

        return $summary;
    }

    /**
     * Clear feature flag analytics cache.
     */
    public function clearCache(): void
    {
        foreach (array_keys($this->exposureCounts) as $flagKey) {
            $this->cache->forget(self::CACHE_PREFIX . 'conversions_' . $flagKey);
        }
    }

    /**
     * Record an exposure for a flag/variant pair.
     */
    private function recordExposure(string $flagKey, string $variant): void
    {
        if (! isset($this->exposureCounts[$flagKey])) {
            $this->exposureCounts[$flagKey] = ['control' => 0, 'treatment' => 0, 'on' => 0, 'off' => 0];
        }

        $normalizedVariant = $this->normalizeVariant($variant);
        $this->exposureCounts[$flagKey][$normalizedVariant] = ($this->exposureCounts[$flagKey][$normalizedVariant] ?? 0) + 1;

        // Persist
        $cacheKey = self::CACHE_PREFIX . 'exposures';
        $this->cache->put($cacheKey, $this->exposureCounts, $this->cacheTtl);
    }

    /**
     * Record a conversion for a flag/variant pair.
     */
    private function recordConversion(string $flagKey, string $variant): void
    {
        $cacheKey = self::CACHE_PREFIX . 'conversions_' . $flagKey;
        $conversions = $this->cache->get($cacheKey, []);
        $normalizedVariant = $this->normalizeVariant($variant);
        $conversions[$normalizedVariant] = ($conversions[$normalizedVariant] ?? 0) + 1;
        $this->cache->put($cacheKey, $conversions, $this->cacheTtl);
    }

    /**
     * Normalize variant names to standard bucket names.
     */
    private function normalizeVariant(string $variant): string
    {
        return match (strtolower($variant)) {
            'control', 'false', 'off', '0', 'disabled' => 'control',
            'treatment', 'true', 'on', '1', 'enabled', 'variant_a', 'variant_b', 'v1', 'v2' => 'treatment',
            default => 'on',
        };
    }

    /**
     * Load persisted exposure data from cache.
     */
    private function loadExposureData(): void
    {
        $cacheKey = self::CACHE_PREFIX . 'exposures';
        $data = $this->cache->get($cacheKey, []);

        if (is_array($data)) {
            $this->exposureCounts = $data;
        }
    }
}
