<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
/**
 * Real User Monitoring (RUM) — Web Vitals aggregation service.
 *
 * Collects, aggregates, and analyzes Core Web Vitals metrics (LCP, FID, CLS,
 * INP, TTFB, FCP) reported by the client-side PerformanceObserver API.
 * Computes percentile distributions (p25, p50, p75, p90, p95, p99) per metric
 * and per page, supports threshold-based alerting, and exposes aggregation
 * windows for time-series analysis.
 *
 * Designed for SaaS dashboards that monitor real-world page performance.
 * Data is stored in the cache as a ring buffer with configurable capacity.
 *
 * Config: `zeroboiler.analytics.rum`
 *
 * @since 68.0.0
 */
final class WebVitalsAggregatorService
{
    /** @var string Metric name constants for Core Web Vitals */
    public const METRIC_LCP = 'LCP';
    public const METRIC_FID = 'FID';
    public const METRIC_CLS = 'CLS';
    public const METRIC_INP = 'INP';
    public const METRIC_TTFB = 'TTFB';
    public const METRIC_FCP = 'FCP';

    /** @var list<string> All supported metric names */
    public const ALL_METRICS = [
        self::METRIC_LCP,
        self::METRIC_FID,
        self::METRIC_CLS,
        self::METRIC_INP,
        self::METRIC_TTFB,
        self::METRIC_FCP,
    ];

    /**
     * Google's "good" thresholds (in native units).
     * LCP/FID/INP/TTFB/FCP in ms, CLS as score.
     *
     * @var array<string, array{good: float, poor: float}>
     */
    public const THRESHOLDS = [
        self::METRIC_LCP  => ['good' => 2500,  'poor' => 4000],
        self::METRIC_FID  => ['good' => 100,   'poor' => 300],
        self::METRIC_CLS  => ['good' => 0.1,   'poor' => 0.25],
        self::METRIC_INP  => ['good' => 200,   'poor' => 500],
        self::METRIC_TTFB => ['good' => 800,   'poor' => 1800],
        self::METRIC_FCP  => ['good' => 1800,  'poor' => 3000],
    ];

    private const CACHE_PREFIX = 'zb_rum_';

    private const DEFAULT_MAX_SAMPLES = 10000;

    private const DEFAULT_TTL = 86400; // 24 hours

    private const DEFAULT_WINDOW = '24h';

    private CacheRepository $cache;

    private int $maxSamples;

    private int $ttl;

    private string $window;

    private bool $enabled;

    private bool $alertingEnabled;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $maxSamples  Max data points per metric per page
     * @param  int  $ttl  Cache TTL for aggregated data
     * @param  string  $window  Aggregation window key
     * @param  bool  $enabled  Whether RUM collection is enabled
     * @param  bool  $alertingEnabled  Whether threshold alerting is enabled
     */
    public function __construct(
        CacheRepository $cache,
        int $maxSamples = self::DEFAULT_MAX_SAMPLES,
        int $ttl = self::DEFAULT_TTL,
        string $window = self::DEFAULT_WINDOW,
        bool $enabled = true,
        bool $alertingEnabled = true,
    ): void {
        $this->cache = $cache;
        $this->maxSamples = $maxSamples;
        $this->ttl = $ttl;
        $this->window = $window;
        $this->enabled = $enabled;
        $this->alertingEnabled = $alertingEnabled;
    }

    /**
     * Ingest a single Web Vitals metric data point.
     *
     * @param  string  $metricName  Metric name (LCP, FID, CLS, INP, TTFB, FCP)
     * @param  float  $value  Metric value
     * @param  string|null  $rating  Good, needs-improvement, or poor
     * @param  string|null  $pagePath  Page where metric was measured
     * @param  string|null  $clientId  Anonymous client identifier
     * @param  string|null  $navigationType  Navigation type
     * @return array{stored: bool, alert: bool, alert_reason?: string}
     */
    public function ingest(
        string $metricName,
        float $value,
        ?string $rating = null,
        ?string $pagePath = null,
        ?string $clientId = null,
        ?string $navigationType = null,
    ): array {
        if (! $this->enabled) {
            return ['stored' => false, 'alert' => false];
        }

        $normalizedMetric = strtoupper($metricName);

        if (! in_array($normalizedMetric, self::ALL_METRICS, true)) {
            return ['stored' => false, 'alert' => false];
        }

        $computedRating = $rating ?? $this->computeRating($normalizedMetric, $value);
        $timestamp = now()->getTimestamp();

        $dataPoint = [
            'v' => round($value, 4),
            'r' => $computedRating,
            't' => $timestamp,
            'n' => $navigationType,
        ];

        if ($clientId !== null) {
            $dataPoint['c'] = $clientId;
        }

        $page = $pagePath ?? '__global';
        $cacheKey = self::CACHE_PREFIX . $this->window . ':' . $normalizedMetric . ':' . md5($page);

        /** @var list<array<string, mixed>> $samples */
        $samples = $this->cache->get($cacheKey, []);
        $samples[] = $dataPoint;

        // Ring buffer: keep most recent N samples
        if (count($samples) > $this->maxSamples) {
            $samples = array_slice($samples, -$this->maxSamples);
        }

        $this->cache->put($cacheKey, $samples, $this->ttl);

        // Check for threshold alert
        $alert = false;
        $alertReason = null;

        if ($this->alertingEnabled && $computedRating === 'poor') {
            $alert = true;
            $alertReason = $normalizedMetric . ' reported as poor (' . round($value, 2) . ') on ' . $page;

            Log::warning('ZeroBoiler RUM Alert', [
                'metric' => $normalizedMetric,
                'value' => round($value, 4),
                'rating' => $computedRating,
                'page' => $page,
                'client_id' => $clientId,
            ]);
        }

        return [
            'stored' => true,
            'alert' => $alert,
            'alert_reason' => $alertReason,
        ];
    }

    /**
     * Ingest multiple Web Vitals metrics at once (batch).
     *
     * @param  list<array{metric: string, value: float, rating?: string|null, page_path?: string|null, client_id?: string|null, navigation_type?: string|null}>  $metrics
     * @return array{stored: int, alerts: int, results: list<array{stored: bool, alert: bool, alert_reason?: string|null}>}
     */
    public function ingestBatch(array $metrics): array
    {
        $stored = 0;
        $alerts = 0;
        $results = [];

        foreach ($metrics as $m) {
            $result = $this->ingest(
                metricName: $m['metric'],
                value: $m['value'],
                rating: $m['rating'] ?? null,
                pagePath: $m['page_path'] ?? null,
                clientId: $m['client_id'] ?? null,
                navigationType: $m['navigation_type'] ?? null,
            );
            $results[] = $result;

            if ($result['stored']) {
                $stored++;
            }

            if ($result['alert']) {
                $alerts++;
            }
        }

        return [
            'stored' => $stored,
            'alerts' => $alerts,
            'results' => $results,
        ];
    }

    /**
     * Get percentile statistics for a specific metric.
     *
     * @param  string  $metricName  Metric name (LCP, FID, CLS, INP, TTFB, FCP)
     * @param  string|null  $pagePath  Optional page filter
     * @return array{metric: string, page: string, count: int, min: float|null, max: float|null, mean: float|null, p25: float|null, p50: float|null, p75: float|null, p90: float|null, p95: float|null, p99: float|null, good_pct: float|null, needs_improvement_pct: float|null, poor_pct: float|null}
     */
    public function percentileStats(string $metricName, ?string $pagePath = null): array
    {
        $normalizedMetric = strtoupper($metricName);
        $page = $pagePath ?? '__global';
        $cacheKey = self::CACHE_PREFIX . $this->window . ':' . $normalizedMetric . ':' . md5($page);

        /** @var list<array<string, mixed>> $samples */
        $samples = $this->cache->get($cacheKey, []);

        $values = array_map(
            fn (array $s): float => (float) ($s['v'] ?? 0),
            $samples,
        );

        $count = count($values);

        if ($count === 0) {
            return $this->emptyStats($normalizedMetric, $page);
        }

        sort($values, SORT_NUMERIC);

        $ratings = array_column($samples, 'r', 't');
        $goodCount = count(array_filter($ratings, fn (string $r): bool => $r === 'good'));
        $needsImprovementCount = count(array_filter($ratings, fn (string $r): bool => $r === 'needs-improvement'));
        $poorCount = count(array_filter($ratings, fn (string $r): bool => $r === 'poor'));

        return [
            'metric' => $normalizedMetric,
            'page' => $page,
            'count' => $count,
            'min' => round($values[0], 4),
            'max' => round($values[$count - 1], 4),
            'mean' => round(array_sum($values) / $count, 4),
            'p25' => round($this->percentile($values, 0.25), 4),
            'p50' => round($this->percentile($values, 0.50), 4),
            'p75' => round($this->percentile($values, 0.75), 4),
            'p90' => round($this->percentile($values, 0.90), 4),
            'p95' => round($this->percentile($values, 0.95), 4),
            'p99' => round($this->percentile($values, 0.99), 4),
            'good_pct' => round(($goodCount / $count) * 100, 2),
            'needs_improvement_pct' => round(($needsImprovementCount / $count) * 100, 2),
            'poor_pct' => round(($poorCount / $count) * 100, 2),
        ];
    }

    /**
     * Get a full RUM dashboard summary across all metrics.
     *
     * @param  string|null  $pagePath  Optional page filter
     * @return array{window: string, metrics: array<string, array<string, mixed>>, worst_metrics: list<string>, overall_score: float}
     */
    public function dashboardSummary(?string $pagePath = null): array
    {
        $metrics = [];
        $goodScores = [];

        foreach (self::ALL_METRICS as $metricName) {
            $stats = $this->percentileStats($metricName, $pagePath);
            $metrics[$metricName] = $stats;

            if ($stats['count'] > 0 && $stats['good_pct'] !== null) {
                $goodScores[] = $stats['good_pct'];
            }
        }

        // Compute overall score as weighted average of good percentages
        $overallScore = count($goodScores) > 0
            ? round(array_sum($goodScores) / count($goodScores), 2)
            : 0.0;

        // Identify worst-performing metrics (lowest good_pct)
        $worstMetrics = [];
        foreach ($metrics as $name => $stats) {
            if (($stats['good_pct'] ?? 100) < 50) {
                $worstMetrics[] = $name;
            }
        }

        return [
            'window' => $this->window,
            'metrics' => $metrics,
            'worst_metrics' => $worstMetrics,
            'overall_score' => $overallScore,
        ];
    }

    /**
     * Get a Core Web Vitals assessment for a specific page.
     *
     * Returns a pass/fail assessment based on Google's thresholds at p75.
     *
     * @param  string|null  $pagePath  Page path to assess
     * @return array{lcp: array{pass: bool, p75: float|null}, cls: array{pass: bool, p75: float|null}, inp: array{pass: bool, p75: float|null}, overall_pass: bool}
     */
    public function coreWebVitalsAssessment(?string $pagePath = null): array
    {
        $lcp = $this->percentileStats(self::METRIC_LCP, $pagePath);
        $cls = $this->percentileStats(self::METRIC_CLS, $pagePath);
        $inp = $this->percentileStats(self::METRIC_INP, $pagePath);

        $lcpPass = ($lcp['p75'] ?? null) !== null && $lcp['p75'] <= self::THRESHOLDS[self::METRIC_LCP]['poor'];
        $clsPass = ($cls['p75'] ?? null) !== null && $cls['p75'] <= self::THRESHOLDS[self::METRIC_CLS]['poor'];
        $inpPass = ($inp['p75'] ?? null) !== null && $inp['p75'] <= self::THRESHOLDS[self::METRIC_INP]['poor'];

        return [
            'lcp' => ['pass' => $lcpPass, 'p75' => $lcp['p75']],
            'cls' => ['pass' => $clsPass, 'p75' => $cls['p75']],
            'inp' => ['pass' => $inpPass, 'p75' => $inp['p75']],
            'overall_pass' => $lcpPass && $clsPass && $inpPass,
        ];
    }

    /**
     * Get the list of pages that have RUM data.
     *
     * @return list<string>
     */
    public function trackedPages(): array
    {
        $pages = $this->cache->get(self::CACHE_PREFIX . $this->window . ':pages');

        return is_array($pages) ? $pages : [];
    }

    /**
     * Register a page as having RUM data.
     *
     * @param  string  $pagePath
     */
    public function registerPage(string $pagePath): void
    {
        $pages = $this->trackedPages();

        if (! in_array($pagePath, $pages, true)) {
            $pages[] = $pagePath;
            $this->cache->put(self::CACHE_PREFIX . $this->window . ':pages', $pages, $this->ttl);
        }
    }

    /**
     * Clear all RUM data for a given window.
     */
    public function clear(): void
    {
        $pages = $this->trackedPages();

        foreach (self::ALL_METRICS as $metric) {
            $this->cache->forget(self::CACHE_PREFIX . $this->window . ':' . $metric . ':__global');

            foreach ($pages as $page) {
                $this->cache->forget(self::CACHE_PREFIX . $this->window . ':' . $metric . ':' . md5($page));
            }
        }

        $this->cache->forget(self::CACHE_PREFIX . $this->window . ':pages');
    }

    /**
     * Check if RUM is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute the rating for a metric value based on Google's thresholds.
     *
     * @param  string  $metricName
     * @param  float  $value
     * @return string  One of: good, needs-improvement, poor
     */
    public function computeRating(string $metricName, float $value): string
    {
        $thresholds = self::THRESHOLDS[strtoupper($metricName)] ?? null;

        if ($thresholds === null) {
            return 'good';
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
     * Compute percentile from a sorted array of values.
     *
     * @param  list<float>  $sortedValues  Must be sorted ascending
     * @param  float  $percentile  0.0 to 1.0
     * @return float
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return $sortedValues[0];
        }

        $index = $percentile * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $frac = $index - $lower;

        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $frac;
    }

    /**
     * Build empty stats response.
     *
     * @param  string  $metricName
     * @param  string  $page
     * @return array<string, mixed>
     */
    private function emptyStats(string $metricName, string $page): array
    {
        return [
            'metric' => $metricName,
            'page' => $page,
            'count' => 0,
            'min' => null,
            'max' => null,
            'mean' => null,
            'p25' => null,
            'p50' => null,
            'p75' => null,
            'p90' => null,
            'p95' => null,
            'p99' => null,
            'good_pct' => null,
            'needs_improvement_pct' => null,
            'poor_pct' => null,
        ];
    }
}
