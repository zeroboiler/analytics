<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event trend forecasting and regression analysis engine.
 *
 * Provides forward-looking trend projection for analytics event streams using
 * industry-standard statistical methods — no external ML dependencies required.
 *
 * Methods:
 * - **Linear Regression**: Fits a least-squares line to project future values
 * - **Exponential Smoothing (SES/Holt's)**: Weighted moving average with trend
 * - **Seasonal Decomposition**: Identifies periodic patterns (daily/weekly)
 * - **Growth Rate Calculation**: Compound growth rate from historical data
 *
 * Produces forecast points with confidence intervals for dashboard rendering,
 * alerting, and capacity planning.
 *
 * Inspired by:
 * - Amplitude Compass (trend detection)
 * - Mixpanel Signal (predictive analytics)
 * - PostHog Trends (time-series forecasting)
 * - Google Analytics Forecasting (regression-based projection)
 *
 * Configuration: `zeroboiler.analytics.trend_forecast`
 *
 * @see \ZeroBoiler\Analytics\Services\EventTimeSeriesService
 * @see \ZeroBoiler\Analytics\Services\RevenueForecastService
 * @see \ZeroBoiler\Analytics\Services\AnomalyDetectionService
 *
 * @phpstan-type ForecastPoint array{date: string, predicted: float, lower: float, upper: float, confidence: float}
 * @phpstan-type TrendReport array{event_name: string, period: int, direction: 'up'|'down'|'flat'|'volatile', slope: float, r_squared: float, growth_rate: float, forecast: list<ForecastPoint>, seasonal: array<string, float>|null, data_points: int, computed_at: string}
 * @phpstan-type ComparativeForecast array{events: list<TrendReport>, summary: array{upward: int, downward: int, flat: int, volatile: int, total: int}}
 *
 * @since 59.0.0
 */
final class EventTrendForecastService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_trend_fc_';

    private const DEFAULT_TTL = 600; // 10 minutes

    private const DEFAULT_FORECAST_HORIZON = 7;

    private const DEFAULT_CONFIDENCE_LEVEL = 0.95;

    private const DEFAULT_SEASONAL_PERIODS = ['daily', 'weekly'];

    /** @var array<string, int> Seasonal period to data points mapping */
    private const SEASONAL_BUCKETS = [
        'daily' => 24,
        'weekly' => 7,
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private int $cacheTtl;

    private int $forecastHorizon;

    private float $confidenceLevel;

    private float $minDataPointsRatio;

    private bool $seasonalEnabled;

    private int $maxHistoryDays;

    /** @var list<string> Enabled seasonal periods */
    private array $seasonalPeriods;

    /**
     * @param  AnalyticsManager  $manager  Analytics manager
     * @param  CacheRepository  $cache  Cache store
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $fcConfig = $config->get('zeroboiler.analytics.trend_forecast', []);
        /** @var array{cache_ttl?: int, forecast_horizon?: int, confidence_level?: float, min_data_points_ratio?: float, seasonal_enabled?: bool, seasonal_periods?: list<string>, max_history_days?: int} $fcConfig */

        $this->cacheTtl = (int) ($fcConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->forecastHorizon = (int) ($fcConfig['forecast_horizon'] ?? self::DEFAULT_FORECAST_HORIZON);
        $this->confidenceLevel = (float) ($fcConfig['confidence_level'] ?? self::DEFAULT_CONFIDENCE_LEVEL);
        $this->minDataPointsRatio = (float) ($fcConfig['min_data_points_ratio'] ?? 0.3);
        $this->seasonalEnabled = (bool) ($fcConfig['seasonal_enabled'] ?? true);
        $this->seasonalPeriods = $fcConfig['seasonal_periods'] ?? self::DEFAULT_SEASONAL_PERIODS;
        $this->maxHistoryDays = (int) ($fcConfig['max_history_days'] ?? 30);
    }

    /**
     * Generate a full trend forecast for a specific event name.
     *
     * Computes linear regression, exponential smoothing forecast,
     * seasonal decomposition (if enabled), and growth rate.
     *
     * @param  string  $eventName  Catalog event name
     * @param  int|null  $days  Historical window in days (null = use config)
     * @param  int|null  $horizon  Forecast days ahead (null = use config)
     * @return TrendReport
     */
    public function forecast(string $eventName, ?int $days = null, ?int $horizon = null): array
    {
        $days = $days ?? $this->maxHistoryDays;
        $horizon = $horizon ?? $this->forecastHorizon;
        $cacheKey = self::CACHE_PREFIX . "evt_{$eventName}_{$days}_{$horizon}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['event_name'])) {
            return $cached;
        }

        $data = $this->getHistoricalData($eventName, $days);

        if (count($data) < 3) {
            $emptyForecast = $this->emptyReport($eventName, $days, $horizon, count($data));

            $this->cache->put($cacheKey, $emptyForecast, $this->cacheTtl);

            return $emptyForecast;
        }

        $regression = $this->linearRegression($data);
        $holtForecast = $this->holtSmoothing($data, $horizon);
        $growthRate = $this->compoundGrowthRate($data);
        $seasonal = $this->seasonalEnabled ? $this->seasonalDecomposition($data) : null;
        $forecastPoints = $this->buildForecastPoints($regression, $holtForecast, $data, $horizon);

        $report = [
            'event_name' => $eventName,
            'period' => $days,
            'direction' => $this->classifyDirection($regression['slope'], $regression['r_squared']),
            'slope' => $regression['slope'],
            'r_squared' => $regression['r_squared'],
            'intercept' => $regression['intercept'],
            'growth_rate' => $growthRate,
            'forecast' => $forecastPoints,
            'seasonal' => $seasonal,
            'data_points' => count($data),
            'method' => 'linear_regression + holt_smoothing',
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Generate comparative forecasts for multiple event names.
     *
     * Useful for admin dashboards showing which events are trending up/down.
     *
     * @param  list<string>  $eventNames  Event names to forecast
     * @param  int|null  $days  Historical window
     * @param  int|null  $horizon  Forecast horizon
     * @return ComparativeForecast
     */
    public function compareForecasts(array $eventNames, ?int $days = null, ?int $horizon = null): array
    {
        $reports = [];
        $summary = ['upward' => 0, 'downward' => 0, 'flat' => 0, 'volatile' => 0, 'total' => 0];

        foreach ($eventNames as $name) {
            $report = $this->forecast($name, $days, $horizon);
            $reports[] = $report;
            $summary['total']++;

            $dir = $report['direction'];
            if (isset($summary[$dir])) {
                $summary[$dir]++;
            }
        }

        // Sort: strongest upward trends first
        usort($reports, fn (array $a, array $b): int => $b['slope'] <=> $a['slope']);

        return [
            'events' => $reports,
            'summary' => $summary,
            'period' => $days ?? $this->maxHistoryDays,
            'horizon' => $horizon ?? $this->forecastHorizon,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate a category-level trend forecast.
     *
     * Aggregates event counts by category per day, then forecasts
     * the aggregate trend.
     *
     * @param  string  $category  Event category ('ecommerce', 'saas', 'engagement')
     * @param  int|null  $days  Historical window
     * @param  int|null  $horizon  Forecast horizon
     * @return TrendReport
     */
    public function forecastCategory(string $category, ?int $days = null, ?int $horizon = null): array
    {
        $days = $days ?? $this->maxHistoryDays;
        $horizon = $horizon ?? $this->forecastHorizon;
        $cacheKey = self::CACHE_PREFIX . "cat_{$category}_{$days}_{$horizon}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['event_name'])) {
            return $cached;
        }

        $catalogEvents = EventCatalog::category($category);
        $allData = [];

        foreach (array_keys($catalogEvents) as $eventName) {
            $eventData = $this->getHistoricalData($eventName, $days);
            $allData = $this->mergeDailyData($allData, $eventData);
        }

        if (count($allData) < 3) {
            $emptyForecast = $this->emptyReport("category:{$category}", $days, $horizon, count($allData));

            $this->cache->put($cacheKey, $emptyForecast, $this->cacheTtl);

            return $emptyForecast;
        }

        $regression = $this->linearRegression($allData);
        $holtForecast = $this->holtSmoothing($allData, $horizon);
        $growthRate = $this->compoundGrowthRate($allData);
        $seasonal = $this->seasonalEnabled ? $this->seasonalDecomposition($allData) : null;
        $forecastPoints = $this->buildForecastPoints($regression, $holtForecast, $allData, $horizon);

        $report = [
            'event_name' => "category:{$category}",
            'category' => $category,
            'period' => $days,
            'direction' => $this->classifyDirection($regression['slope'], $regression['r_squared']),
            'slope' => $regression['slope'],
            'r_squared' => $regression['r_squared'],
            'intercept' => $regression['intercept'],
            'growth_rate' => $growthRate,
            'forecast' => $forecastPoints,
            'seasonal' => $seasonal,
            'data_points' => count($allData),
            'method' => 'linear_regression + holt_smoothing',
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Detect events with significant trend changes (acceleration/deceleration).
     *
     * Compares the slope of the most recent half of data against the full-period slope
     * to detect changes in trajectory.
     *
     * @param  list<string>|null  $eventNames  Events to check (null = all catalog events)
     * @param  int|null  $days  Historical window
     * @return list<array{event_name: string, current_slope: float, full_slope: float, change_pct: float, acceleration: 'accelerating'|'decelerating'|'stable', direction: string}>
     */
    public function detectTrendChanges(?array $eventNames = null, ?int $days = null): array
    {
        $days = $days ?? $this->maxHistoryDays;
        $names = $eventNames ?? EventCatalog::names();
        $changes = [];

        foreach ($names as $name) {
            $data = $this->getHistoricalData($name, $days);

            if (count($data) < 6) {
                continue;
            }

            $mid = (int) floor(count($data) / 2);
            $recentData = array_slice($data, $mid);
            $fullRegression = $this->linearRegression($data);
            $recentRegression = $this->linearRegression($recentData);

            $fullSlope = $fullRegression['slope'];
            $currentSlope = $recentRegression['slope'];

            // Compute acceleration as percent change in slope
            $changePct = $fullSlope !== 0.0
                ? round((($currentSlope - $fullSlope) / abs($fullSlope)) * 100, 2)
                : ($currentSlope > 0 ? 100.0 : ($currentSlope < 0 ? -100.0 : 0.0));

            $acceleration = match (true) {
                $changePct > 20.0 => 'accelerating',
                $changePct < -20.0 => 'decelerating',
                default => 'stable',
            };

            $direction = $this->classifyDirection($currentSlope, $recentRegression['r_squared']);

            $changes[] = [
                'event_name' => $name,
                'current_slope' => round($currentSlope, 6),
                'full_slope' => round($fullSlope, 6),
                'change_pct' => $changePct,
                'acceleration' => $acceleration,
                'direction' => $direction,
                'category' => EventCatalog::getCategory($name),
            ];
        }

        // Sort by absolute change percentage descending
        usort($changes, fn (array $a, array $b): int => abs($b['change_pct']) <=> abs($a['change_pct']));

        return $changes;
    }

    /**
     * Run just linear regression on historical event data.
     *
     * Returns the regression parameters without the full forecast report.
     * Useful for lightweight trend checks.
     *
     * @param  string  $eventName  Catalog event name
     * @param  int|null  $days  Historical window
     * @return array{slope: float, intercept: float, r_squared: float, mean: float, stddev: float, data_points: int}
     */
    public function regression(string $eventName, ?int $days = null): array
    {
        $data = $this->getHistoricalData($eventName, $days ?? $this->maxHistoryDays);
        $reg = $this->linearRegression($data);

        return [
            'slope' => $reg['slope'],
            'intercept' => $reg['intercept'],
            'r_squared' => $reg['r_squared'],
            'mean' => $reg['mean'],
            'stddev' => $reg['stddev'],
            'data_points' => count($data),
        ];
    }

    /**
     * Get the forecast configuration summary.
     *
     * @return array{cache_ttl: int, forecast_horizon: int, confidence_level: float, seasonal_enabled: bool, seasonal_periods: list<string>, max_history_days: int, min_data_points_ratio: float}
     */
    public function getConfig(): array
    {
        return [
            'cache_ttl' => $this->cacheTtl,
            'forecast_horizon' => $this->forecastHorizon,
            'confidence_level' => $this->confidenceLevel,
            'seasonal_enabled' => $this->seasonalEnabled,
            'seasonal_periods' => $this->seasonalPeriods,
            'max_history_days' => $this->maxHistoryDays,
            'min_data_points_ratio' => $this->minDataPointsRatio,
        ];
    }

    // ── Core Statistical Methods ──────────────────────────────────────

    /**
     * Compute ordinary least-squares linear regression.
     *
     * @param  list<float>  $y  Time-series values (index = x)
     * @return array{slope: float, intercept: float, r_squared: float, mean: float, stddev: float}
     */
    private function linearRegression(array $y): array
    {
        $n = count($y);

        if ($n < 2) {
            return ['slope' => 0.0, 'intercept' => $n > 0 ? $y[0] : 0.0, 'r_squared' => 0.0, 'mean' => 0.0, 'stddev' => 0.0];
        }

        $x = range(0, $n - 1);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0.0;
        $sumX2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
        }

        $meanY = $sumY / $n;
        $denominator = ($n * $sumX2) - ($sumX * $sumX);

        if ($denominator === 0.0) {
            return ['slope' => 0.0, 'intercept' => $meanY, 'r_squared' => 0.0, 'mean' => $meanY, 'stddev' => 0.0];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        // Compute R² (coefficient of determination)
        $ssTotal = 0.0;
        $ssResidual = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $intercept + ($slope * $x[$i]);
            $ssTotal += ($y[$i] - $meanY) ** 2;
            $ssResidual += ($y[$i] - $predicted) ** 2;
        }

        $rSquared = $ssTotal > 0.0 ? 1.0 - ($ssResidual / $ssTotal) : 0.0;

        // Standard deviation
        $variance = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $variance += ($y[$i] - $meanY) ** 2;
        }
        $stddev = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

        return [
            'slope' => round($slope, 8),
            'intercept' => round($intercept, 8),
            'r_squared' => round(max(0.0, min(1.0, $rSquared)), 6),
            'mean' => round($meanY, 4),
            'stddev' => round($stddev, 4),
        ];
    }

    /**
     * Holt's double exponential smoothing with trend.
     *
     * Produces forecast values for the given horizon.
     *
     * @param  list<float>  $y  Time-series values
     * @param  int  $horizon  Number of periods to forecast ahead
     * @return list<float> Forecasted values
     */
    private function holtSmoothing(array $y, int $horizon): array
    {
        $n = count($y);

        if ($n < 2 || $horizon <= 0) {
            return array_fill(0, $horizon, $n > 0 ? $y[array_key_last($y)] : 0.0);
        }

        // Optimal alpha: higher for volatile data, lower for smooth
        $alpha = $this->estimateSmoothingAlpha($y);
        $beta = 0.3; // Trend smoothing factor

        // Initialize
        $level = $y[0];
        $trend = $n >= 2 ? $y[1] - $y[0] : 0.0;

        // Smooth
        for ($i = 1; $i < $n; $i++) {
            $newLevel = $alpha * $y[$i] + (1 - $alpha) * ($level + $trend);
            $newTrend = $beta * ($newLevel - $level) + (1 - $beta) * $trend;
            $level = $newLevel;
            $trend = $newTrend;
        }

        // Forecast
        $forecast = [];
        for ($h = 1; $h <= $horizon; $h++) {
            $value = $level + ($h * $trend);
            $forecast[] = round(max(0.0, $value), 4);
        }

        return $forecast;
    }

    /**
     * Estimate optimal smoothing alpha from data variance.
     *
     * Higher variance → higher alpha (more responsive).
     * Lower variance → lower alpha (smoother).
     */
    private function estimateSmoothingAlpha(array $y): float
    {
        $n = count($y);

        if ($n < 3) {
            return 0.3;
        }

        $mean = array_sum($y) / $n;
        $variance = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $variance += ($y[$i] - $mean) ** 2;
        }

        $variance = $variance / $n;
        $coefficientOfVariation = $mean > 0 ? sqrt($variance) / $mean : 0.0;

        // Map CV to alpha: low CV → 0.1, high CV → 0.7
        $alpha = 0.1 + min(0.6, $coefficientOfVariation * 2.0);

        return round($alpha, 3);
    }

    /**
     * Compute compound growth rate from time-series data.
     *
     * @param  list<float>  $y  Time-series values
     * @return float Growth rate as decimal (e.g., 0.05 = 5%)
     */
    private function compoundGrowthRate(array $y): float
    {
        $n = count($y);

        if ($n < 2) {
            return 0.0;
        }

        $start = $y[0];
        $end = $y[$n - 1];

        if ($start <= 0 || $end <= 0) {
            // Fallback: simple average change
            return $n > 1 && $start > 0 ? round(($end - $start) / ($start * $n), 6) : 0.0;
        }

        $cgr = (pow($end / $start, 1.0 / ($n - 1)) - 1.0);

        return round($cgr, 6);
    }

    /**
     * Decompose time-series into seasonal indices.
     *
     * Uses a simple averaging method to identify periodic patterns.
     * Returns an array of seasonal indices keyed by period label.
     *
     * @param  list<float>  $y  Daily time-series values
     * @return array<string, float>|null Seasonal indices (1.0 = average, >1 = above average)
     */
    private function seasonalDecomposition(array $y): ?array
    {
        $n = count($y);

        if ($n < 14) {
            return null; // Need at least 2 weeks for weekly seasonality
        }

        $seasonal = [];

        foreach ($this->seasonalPeriods as $period) {
            $buckets = self::SEASONAL_BUCKETS[$period] ?? null;

            if ($buckets === null) {
                continue;
            }

            if ($n < $buckets * 2) {
                continue; // Need at least 2 full cycles
            }

            $sums = array_fill(0, $buckets, 0.0);
            $counts = array_fill(0, $buckets, 0);
            $overallMean = array_sum($y) / $n;

            for ($i = 0; $i < $n; $i++) {
                $bucket = $i % $buckets;
                $sums[$bucket] += $y[$i];
                $counts[$bucket]++;
            }

            $indices = [];

            for ($b = 0; $b < $buckets; $b++) {
                $avg = $counts[$b] > 0 ? $sums[$b] / $counts[$b] : $overallMean;
                $index = $overallMean > 0 ? round($avg / $overallMean, 4) : 1.0;
                $indices[$period . '_' . $b] = $index;
            }

            $seasonal = array_merge($seasonal, $indices);
        }

        return $seasonal !== [] ? $seasonal : null;
    }

    /**
     * Build forecast points with confidence intervals.
     *
     * Blends linear regression and Holt's smoothing with configurable weights.
     * Confidence intervals widen as forecast horizon increases.
     *
     * @param  array{slope: float, intercept: float, r_squared: float, mean: float, stddev: float}  $regression
     * @param  list<float>  $holtForecast  Holt's smoothing forecast values
     * @param  list<float>  $data  Historical data
     * @param  int  $horizon  Forecast horizon
     * @return list<ForecastPoint>
     */
    private function buildForecastPoints(array $regression, array $holtForecast, array $data, int $horizon): array
    {
        $points = [];
        $n = count($data);
        $stddev = $regression['stddev'];

        // Z-score for confidence interval
        $zScore = $this->zScore($this->confidenceLevel);

        // Blend weight: regression 60%, Holt 40%
        $regWeight = 0.6;
        $holtWeight = 0.4;

        for ($h = 1; $h <= $horizon; $h++) {
            $xIndex = $n + $h - 1;
            $regValue = $regression['intercept'] + ($regression['slope'] * $xIndex);
            $regValue = max(0.0, $regValue);

            $holtValue = $holtForecast[$h - 1] ?? $regValue;

            $blended = ($regValue * $regWeight) + ($holtValue * $holtWeight);

            // Confidence interval widens with horizon
            $intervalMultiplier = sqrt($h);
            $margin = $zScore * $stddev * $intervalMultiplier;

            $points[] = [
                'date' => now()->addDays($h)->format('Y-m-d'),
                'predicted' => round($blended, 4),
                'lower' => round(max(0.0, $blended - $margin), 4),
                'upper' => round($blended + $margin, 4),
                'confidence' => $this->confidenceLevel,
                'horizon_day' => $h,
            ];
        }

        return $points;
    }

    /**
     * Classify trend direction based on slope and R².
     *
     * @param  float  $slope  Regression slope
     * @param  float  $rSquared  R² value
     * @return 'up'|'down'|'flat'|'volatile'
     */
    private function classifyDirection(float $slope, float $rSquared): string
    {
        // Low R² means the trend is not reliable → volatile
        if ($rSquared < 0.1) {
            return 'volatile';
        }

        if ($slope > 0.01) {
            return 'up';
        }

        if ($slope < -0.01) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * Get Z-score for a given confidence level.
     *
     * Approximated using a lookup table for common levels.
     */
    private function zScore(float $confidence): float
    {
        return match (true) {
            $confidence >= 0.99 => 2.576,
            $confidence >= 0.95 => 1.960,
            $confidence >= 0.90 => 1.645,
            $confidence >= 0.80 => 1.282,
            $confidence >= 0.70 => 1.036,
            default => 1.0,
        };
    }

    // ── Data Access ───────────────────────────────────────────────────

    /**
     * Get historical daily event counts from the event stream.
     *
     * @param  string  $eventName  Catalog event name
     * @param  int  $days  Number of days to look back
     * @return list<float> Daily event counts (chronological order)
     */
    private function getHistoricalData(string $eventName, int $days): array
    {
        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(5000);

            return $this->aggregateDailyCounts($recent, $eventName, $days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Aggregate raw events into daily counts for a specific event name.
     *
     * @param  list<array<string, mixed>>  $events  Raw event data
     * @param  string  $eventName  Event name to filter
     * @param  int  $days  Number of days
     * @return list<float> Daily counts in chronological order
     */
    private function aggregateDailyCounts(array $events, string $eventName, int $days): array
    {
        $dailyCounts = [];

        for ($d = $days - 1; $d >= 0; $d--) {
            $dateStr = now()->subDays($d)->format('Y-m-d');
            $dailyCounts[$dateStr] = 0.0;
        }

        foreach ($events as $event) {
            if (($event['event'] ?? '') !== $eventName) {
                continue;
            }

            $timestamp = $event['timestamp'] ?? '';

            if ($timestamp === '') {
                continue;
            }

            try {
                $dateStr = substr((string) $timestamp, 0, 10);

                if (isset($dailyCounts[$dateStr])) {
                    $dailyCounts[$dateStr]++;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Return values in chronological order
        ksort($dailyCounts);

        return array_values($dailyCounts);
    }

    /**
     * Merge two daily count arrays by summing values per date.
     *
     * @param  list<float>  $target  Existing data (by index)
     * @param  list<float>  $source  New data to merge (by index)
     * @return list<float> Merged daily counts
     */
    private function mergeDailyData(array $target, array $source): array
    {
        $merged = $target;

        foreach ($source as $index => $value) {
            if (isset($merged[$index])) {
                $merged[$index] += $value;
            } else {
                $merged[$index] = $value;
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Build an empty forecast report when there's insufficient data.
     *
     * @return TrendReport
     */
    private function emptyReport(string $label, int $days, int $horizon, int $dataPoints): array
    {
        $forecastPoints = [];
        for ($h = 1; $h <= $horizon; $h++) {
            $forecastPoints[] = [
                'date' => now()->addDays($h)->format('Y-m-d'),
                'predicted' => 0.0,
                'lower' => 0.0,
                'upper' => 0.0,
                'confidence' => $this->confidenceLevel,
                'horizon_day' => $h,
            ];
        }

        return [
            'event_name' => $label,
            'period' => $days,
            'direction' => 'flat',
            'slope' => 0.0,
            'r_squared' => 0.0,
            'intercept' => 0.0,
            'growth_rate' => 0.0,
            'forecast' => $forecastPoints,
            'seasonal' => null,
            'data_points' => $dataPoints,
            'method' => 'insufficient_data',
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
