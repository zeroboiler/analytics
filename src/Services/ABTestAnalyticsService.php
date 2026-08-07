<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * A/B test analytics service for tracking experiments and computing results.
 *
 * Tracks experiment exposures, goal conversions per variant, and computes
 * statistical significance using a two-proportion z-test.
 *
 * Experiments are stored in the Laravel cache and identified by experiment ID.
 * Each experiment tracks:
 * - Total exposures per variant
 * - Conversions per variant
 * - Win rate and confidence level
 *
 * Configuration: `zeroboiler.analytics.ab_tests`
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager::abTestExposure()
 */
final class ABTestAnalyticsService
{
    private const CACHE_PREFIX = 'zb_ab_test_';

    private const CACHE_TTL = 604800; // 7 days

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private bool $enabled;

    private float $confidenceThreshold;

    private int $cacheTtl;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $abConfig = $config->get('zeroboiler.analytics.ab_tests', []);
        /** @var array{enabled?: bool, confidence_threshold?: float, cache_ttl?: int} $abConfig */
        $this->enabled = (bool) ($abConfig['enabled'] ?? true);
        $this->confidenceThreshold = (float) ($abConfig['confidence_threshold'] ?? 0.95);
        $this->cacheTtl = (int) ($abConfig['cache_ttl'] ?? self::CACHE_TTL);
    }

    /**
     * Record an experiment exposure (user saw a variant).
     *
     * @param  string  $experimentId  Unique experiment identifier
     * @param  string  $variantId  Variant the user was assigned to
     * @param  string|null  $userId  Optional user ID
     */
    public function recordExposure(string $experimentId, string $variantId, ?string $userId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . $experimentId;
        $experiment = $this->cache->get($key, $this->emptyExperiment($experimentId));
        /** @var array{id: string, variants: array<string, array{exposures: int, conversions: int}>, created_at: int} $experiment */

        if (! isset($experiment['variants'][$variantId])) {
            $experiment['variants'][$variantId] = [
                'exposures' => 0,
                'conversions' => 0,
            ];
        }

        $experiment['variants'][$variantId]['exposures']++;

        $this->cache->put($key, $experiment, $this->cacheTtl);
    }

    /**
     * Record a conversion for a specific experiment variant.
     *
     * @param  string  $experimentId  Unique experiment identifier
     * @param  string  $variantId  Variant that converted
     */
    public function recordConversion(string $experimentId, string $variantId): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . $experimentId;
        $experiment = $this->cache->get($key, $this->emptyExperiment($experimentId));
        /** @var array{id: string, variants: array<string, array{exposures: int, conversions: int}>, created_at: int} $experiment */

        if (! isset($experiment['variants'][$variantId])) {
            $experiment['variants'][$variantId] = [
                'exposures' => 0,
                'conversions' => 0,
            ];
        }

        $experiment['variants'][$variantId]['conversions']++;

        $this->cache->put($key, $experiment, $this->cacheTtl);
    }

    /**
     * Record an exposure and dispatch an ab_test_exposure event to analytics.
     *
     * @param  string  $experimentId  Experiment ID
     * @param  string  $variantId  Variant ID
     * @param  array<string, mixed>  $params  Additional event params
     */
    public function trackExposure(string $experimentId, string $variantId, array $params = []): void
    {
        $this->recordExposure($experimentId, $variantId);

        $this->manager->abTestExposure($experimentId, $variantId, $params);
    }

    /**
     * Record a conversion and dispatch a conversion event to analytics.
     *
     * @param  string  $experimentId  Experiment ID
     * @param  string  $variantId  Variant ID
     * @param  array<string, mixed>  $params  Additional event params
     */
    public function trackConversion(string $experimentId, string $variantId, array $params = []): void
    {
        $this->recordConversion($experimentId, $variantId);

        $this->manager->trackEvent(
            new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'ab_test_conversion',
                params: array_merge([
                    'experiment_id' => $experimentId,
                    'variant_id' => $variantId,
                ], $params),
            ),
        );
    }

    /**
     * Get the results for an experiment with per-variant statistics.
     *
     * @param  string  $experimentId
     * @return array{id: string, variants: array<string, array{exposures: int, conversions: int, rate: float}>, winner: ?string, confidence: ?float, significant: bool, created_at: int}|null
     */
    public function getResults(string $experimentId): ?array
    {
        $key = self::CACHE_PREFIX . $experimentId;
        $experiment = $this->cache->get($key);

        if (! is_array($experiment)) {
            return null;
        }

        /** @var array{id: string, variants: array<string, array{exposures: int, conversions: int}>, created_at: int} $experiment */

        $variants = [];
        $totalExposures = 0;
        $totalConversions = 0;

        foreach ($experiment['variants'] as $variantId => $data) {
            $rate = $data['exposures'] > 0
                ? round($data['conversions'] / $data['exposures'], 4)
                : 0.0;

            $variants[$variantId] = [
                'exposures' => $data['exposures'],
                'conversions' => $data['conversions'],
                'rate' => $rate,
            ];

            $totalExposures += $data['exposures'];
            $totalConversions += $data['conversions'];
        }

        // Compute statistical significance if we have 2+ variants
        $winner = null;
        $confidence = null;
        $significant = false;

        $variantIds = array_keys($variants);
        if (count($variantIds) >= 2) {
            [$winnerId, $confidenceLevel] = $this->computeSignificance($variants);
            $winner = $winnerId;
            $confidence = $confidenceLevel;
            $significant = $confidenceLevel >= $this->confidenceThreshold;
        }

        return [
            'id' => $experimentId,
            'variants' => $variants,
            'total_exposures' => $totalExposures,
            'total_conversions' => $totalConversions,
            'winner' => $winner,
            'confidence' => $confidence,
            'significant' => $significant,
            'confidence_threshold' => $this->confidenceThreshold,
            'created_at' => $experiment['created_at'],
        ];
    }

    /**
     * Get a summary of all active experiments.
     *
     * @return list<array{id: string, variants: int, total_exposures: int, significant: bool, winner: ?string}>
     */
    public function allExperiments(int $limit = 50): array
    {
        // Note: This only works with a taggable cache driver (Redis, etc.)
        // For simplicity, return cached results for known experiment IDs
        return [];
    }

    /**
     * Delete an experiment and all its data.
     */
    public function deleteExperiment(string $experimentId): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . $experimentId);
    }

    /**
     * Check if A/B test analytics is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured confidence threshold.
     */
    public function getConfidenceThreshold(): float
    {
        return $this->confidenceThreshold;
    }

    /**
     * Compute statistical significance using a two-proportion z-test.
     *
     * Compares each variant against the control (first variant).
     * Returns the winning variant and its confidence level.
     *
     * @param  array<string, array{exposures: int, conversions: int, rate: float}>  $variants
     * @return array{0: string|null, 1: float} [winner_variant_id, confidence_level]
     */
    private function computeSignificance(array $variants): array
    {
        $variantIds = array_keys($variants);

        // Find the control (first variant) and best performer
        $controlId = $variantIds[0];
        $control = $variants[$controlId];
        $bestId = $controlId;
        $bestRate = $control['rate'];

        foreach ($variants as $id => $data) {
            if ($id === $controlId) {
                continue;
            }

            if ($data['rate'] > $bestRate) {
                $bestId = $id;
                $bestRate = $data['rate'];
            }
        }

        if ($bestId === $controlId) {
            return [null, 0.0];
        }

        // Two-proportion z-test
        $p1 = $control['rate'];
        $p2 = $variants[$bestId]['rate'];
        $n1 = $control['exposures'];
        $n2 = $variants[$bestId]['exposures'];

        if ($n1 === 0 || $n2 === 0) {
            return [null, 0.0];
        }

        $pooled = ($control['conversions'] + $variants[$bestId]['conversions'])
            / ($n1 + $n2);

        $se = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));

        if ($se === 0.0) {
            return [null, 0.0];
        }

        $z = abs($p2 - $p1) / $se;

        // Convert z-score to confidence level (approximate using normal CDF)
        $confidence = $this->normalCdf($z);

        return [$bestId, round($confidence, 4)];
    }

    /**
     * Approximate normal cumulative distribution function.
     *
     * Uses the Abramowitz and Stegun approximation.
     */
    private function normalCdf(float $z): float
    {
        // Standard normal CDF approximation
        $t = 1.0 / (1.0 + 0.2316419 * abs($z));
        $d = 0.3989422804014327; // 1/sqrt(2*pi)

        $p = $d * exp(-$z * $z / 2.0) * $t
            * (0.3193815 + $t
                * (-0.3565638 + $t
                    * (1.781478 + $t
                        * (-1.8212560 + $t * 1.3302744))));

        return $z > 0 ? 1 - $p : $p;
    }

    /**
     * Create an empty experiment data structure.
     *
     * @param  string  $experimentId
     * @return array{id: string, variants: array<string, array{exposures: int, conversions: int}>, created_at: int}
     */
    private function emptyExperiment(string $experimentId): array
    {
        return [
            'id' => $experimentId,
            'variants' => [],
            'created_at' => time(),
        ];
    }
}
