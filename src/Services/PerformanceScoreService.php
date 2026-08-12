<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Performance score calculator for Web Vitals aggregation.
 *
 * Computes aggregate performance scores from collected Core Web Vitals
 * (LCP, INP/FID, CLS, TTFB, FCP) using Google's recommended thresholds.
 * Provides per-page, per-session, and global scoring with cache-backed
 * aggregation windows.
 *
 * Scoring model:
 *   - Each metric rated: good (3), needs-improvement (2), poor (1)
 *   - Overall score = weighted average (LCP 25%, INP 30%, CLS 25%, TTFB 20%)
 *   - FCP optionally included as diagnostic
 *   - Score range: 0-100 (normalized from weighted average)
 *
 * @since 24.0.0
 */
final class PerformanceScoreService
{
    /** @var array<string, array{good: int|float, poor: int|float}> */
    private const THRESHOLDS = [
        'LCP' => ['good' => 2500, 'poor' => 4000],
        'INP' => ['good' => 200, 'poor' => 500],
        'FID' => ['good' => 100, 'poor' => 300],
        'CLS' => ['good' => 0.1, 'poor' => 0.25],
        'TTFB' => ['good' => 800, 'poor' => 1800],
        'FCP' => ['good' => 1800, 'poor' => 3000],
    ];

    /** @var array<string, float> Metric weights for overall score */
    private const WEIGHTS = [
        'LCP' => 0.25,
        'INP' => 0.30,
        'CLS' => 0.25,
        'TTFB' => 0.20,
    ];

    /** @var int Cache TTL for aggregated scores (seconds) */
    private const CACHE_TTL = 3600;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private string $cachePrefix;

    /** @var int */
    private int $windowSeconds;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->cachePrefix = (string) ($config->get('zeroboiler.analytics.performance.cache_prefix', 'zb_perf_'));
        $this->windowSeconds = (int) ($config->get('zeroboiler.analytics.performance.aggregation_window', 900));
    }

    /**
     * Rate a single metric value.
     *
     * Returns 'good', 'needs-improvement', or 'poor' based on Google's thresholds.
     *
     * @param  string  $metric  Metric name (LCP, INP, FID, CLS, TTFB, FCP)
     * @param  float  $value  Measured value
     * @return string Rating: 'good'|'needs-improvement'|'poor'
     */
    public function rateMetric(string $metric, float $value): string
    {
        $thresholds = self::THRESHOLDS[$metric] ?? null;

        if ($thresholds === null) {
            return 'unknown';
        }

        if ($value <= $thresholds['good']) {
            return 'good';
        }

        if ($value <= $thresholds['poor']) {
            return 'needs-improvement';
        }

        return 'poor';
    }

    /**
     * Convert a rating to a numeric score (0-3).
     *
     * @param  string  $rating  Rating from rateMetric()
     * @return int Score: 3 (good), 2 (needs-improvement), 1 (poor), 0 (unknown)
     */
    public function ratingToScore(string $rating): int
    {
        return match ($rating) {
            'good' => 3,
            'needs-improvement' => 2,
            'poor' => 1,
            default => 0,
        };
    }

    /**
     * Calculate overall performance score (0-100) from collected metrics.
     *
     * Uses weighted average of LCP, INP, CLS, TTFB ratings.
     * Missing metrics are excluded from the calculation (weight redistributed).
     *
     * @param  array<string, float>  $metrics  Key-value map of metric values
     * @return array{score: int, rating: string, breakdown: array<string, array{value: float, rating: string, score: int}>, weights_used: array<string, float>}
     *
     * @example
     * $result = $service->calculateScore(['LCP' => 1200, 'INP' => 150, 'CLS' => 0.05, 'TTFB' => 400]);
     * // score: 100, rating: 'good'
     */
    public function calculateScore(array $metrics): array
    {
        $breakdown = [];
        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $usedWeights = [];

        foreach (self::WEIGHTS as $metric => $weight) {
            if (! isset($metrics[$metric])) {
                continue;
            }

            $value = (float) $metrics[$metric];
            $rating = $this->rateMetric($metric, $value);
            $score = $this->ratingToScore($rating);

            $breakdown[$metric] = [
                'value' => $value,
                'rating' => $rating,
                'score' => $score,
            ];

            $weightedSum += $score * $weight;
            $totalWeight += $weight;
            $usedWeights[$metric] = $weight;
        }

        if ($totalWeight === 0.0) {
            return [
                'score' => 0,
                'rating' => 'unknown',
                'breakdown' => [],
                'weights_used' => [],
            ];
        }

        // Normalize: max possible = 3 * totalWeight, normalize to 0-100
        $normalizedScore = (int) round(($weightedSum / (3.0 * $totalWeight)) * 100);

        $overallRating = $this->scoreToRating($normalizedScore);

        return [
            'score' => $normalizedScore,
            'rating' => $overallRating,
            'breakdown' => $breakdown,
            'weights_used' => $usedWeights,
        ];
    }

    /**
     * Convert numeric score (0-100) to a rating string.
     *
     * @param  int  $score  Score 0-100
     * @return string Rating: 'good'|'needs-improvement'|'poor'
     */
    public function scoreToRating(int $score): string
    {
        if ($score >= 90) {
            return 'good';
        }

        if ($score >= 50) {
            return 'needs-improvement';
        }

        return 'poor';
    }

    /**
     * Get the Google-recommended thresholds for all metrics.
     *
     * @return array<string, array{good: int|float, poor: int|float}>
     */
    public function getThresholds(): array
    {
        return self::THRESHOLDS;
    }

    /**
     * Get the metric weights used for overall score calculation.
     *
     * @return array<string, float>
     */
    public function getWeights(): array
    {
        return self::WEIGHTS;
    }

    /**
     * Aggregate a batch of metric values and compute summary statistics.
     *
     * Useful for computing p75 values across multiple page views,
     * which is the Google-recommended aggregation method.
     *
     * @param  list<array<string, float>>  $metricSets  List of metric value maps
     * @return array{summary: array<string, array{p75: float, median: float, min: float, max: float, count: int, good_pct: float}>, overall_p75_score: int}
     */
    public function aggregateMetrics(array $metricSets): array
    {
        if ($metricSets === []) {
            return [
                'summary' => [],
                'overall_p75_score' => 0,
            ];
        }

        $metricValues = [];
        $goodCounts = [];

        foreach ($metricSets as $set) {
            foreach ($set as $metric => $value) {
                $metricValues[$metric][] = (float) $value;
                if (! isset($goodCounts[$metric])) {
                    $goodCounts[$metric] = 0;
                }
                if ($this->rateMetric($metric, (float) $value) === 'good') {
                    $goodCounts[$metric]++;
                }
            }
        }

        $summary = [];
        $p75Scores = [];

        foreach ($metricValues as $metric => $values) {
            sort($values);
            $count = count($values);

            $summary[$metric] = [
                'p75' => $values[(int) floor($count * 0.75) - 1] ?? $values[$count - 1],
                'median' => $values[(int) floor($count * 0.5)],
                'min' => $values[0],
                'max' => $values[$count - 1],
                'count' => $count,
                'good_pct' => $count > 0 ? round(($goodCounts[$metric] / $count) * 100, 1) : 0.0,
            ];

            $p75Scores[$metric] = $summary[$metric]['p75'];
        }

        return [
            'summary' => $summary,
            'overall_p75_score' => $this->calculateScore($p75Scores)['score'],
        ];
    }

    /**
     * Store an aggregated score in the cache.
     *
     * @param  string  $key  Cache key suffix (e.g., page URL hash, session ID)
     * @param  array{score: int, rating: string}  $scoreData  Score data from calculateScore()
     * @param  int|null  $ttl  Cache TTL in seconds (null = default)
     */
    public function cacheScore(string $key, array $scoreData, ?int $ttl = null): void
    {
        $cacheKey = $this->cachePrefix.$key;
        $this->cache->put($cacheKey, $scoreData, $ttl ?? self::CACHE_TTL);
    }

    /**
     * Retrieve a cached score.
     *
     * @param  string  $key  Cache key suffix
     * @return array{score: int, rating: string}|null
     */
    public function getCachedScore(string $key): ?array
    {
        $cacheKey = $this->cachePrefix.$key;

        /** @var array{score: int, rating: string}|null $result */
        $result = $this->cache->get($cacheKey);

        return is_array($result) ? $result : null;
    }

    /**
     * Get the configured aggregation window in seconds.
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }
}
