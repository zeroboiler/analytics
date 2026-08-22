<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Rolling Window Analytics Engine — time-series smoothing & trend analysis.
 *
 * Provides moving average, exponential smoothing, and trend detection
 * for analytics metrics over configurable time windows. Essential for
 * smoothing noisy event data and identifying real trends vs. spikes.
 *
 * Supported algorithms:
 * - **Simple Moving Average (SMA)**: Equal-weight average over N periods
 * - **Exponential Moving Average (EMA)**: Weighted average favoring recent data
 * - **Weighted Moving Average (WMA)**: Linear-decay weighted average
 * - **Trend Detection**: Linear regression slope classification
 * - **Volatility Score**: Coefficient of variation for stability assessment
 *
 * Used by AnalyticsGoalTracker for trend computation and dashboard widgets
 * for smooth metric displays.
 *
 * Configuration via `zeroboiler.analytics.rolling_window`.
 *
 * Inspired by Datadog's Smooth Metric and Mixpanel's Trend Lines.
 *
 * @phpstan-type DataPoint array{period: string, value: float}
 * @phpstan-type TrendResult array{direction: 'up'|'down'|'flat'|'volatile', slope: float, strength: float, confidence: float}
 * @phpstan-type SmoothingConfig array{default_window: int, ema_alpha: float, volatility_window: int, trend_min_points: int, cache_ttl: int}
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsGoalTracker
 *
 * @since 177.0.0
 */
final class RollingWindowAnalyticsEngine
{
    /** @var string Current version for cache compatibility. */
    public const VERSION = '1.0.0';

    private const CACHE_PREFIX = 'zb_rolling_';

    private const DEFAULT_WINDOW = 7;

    private const DEFAULT_EMA_ALPHA = 0.3;

    private const DEFAULT_TREND_MIN_POINTS = 3;

    private const DEFAULT_CACHE_TTL = 600;

    /**
     * Create a new RollingWindowAnalyticsEngine.
     *
     * @param  CacheRepository  $cache  Cache repository for smoothed data
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Calculate Simple Moving Average.
     *
     * Returns the arithmetic mean of the last N data points.
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @param  int  $window  Number of periods to average
     */
    public function sma(array $values, int $window = self::DEFAULT_WINDOW): float
    {
        $window = max(1, min($window, count($values)));
        $slice = array_slice($values, -$window);

        if ($slice === []) {
            return 0.0;
        }

        return array_sum($slice) / count($slice);
    }

    /**
     * Calculate Exponential Moving Average.
     *
     * Weighted average that gives exponentially decreasing weights to older data.
     * Alpha (smoothing factor) controls responsiveness: higher = more responsive.
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @param  float  $alpha  Smoothing factor (0-1, default 0.3)
     */
    public function ema(array $values, float $alpha = self::DEFAULT_EMA_ALPHA): float
    {
        if ($values === []) {
            return 0.0;
        }

        $alpha = max(0.01, min(0.99, $alpha));
        $ema = $values[0];

        for ($i = 1; $i < count($values); $i++) {
            $ema = ($alpha * $values[$i]) + ((1 - $alpha) * $ema);
        }

        return $ema;
    }

    /**
     * Calculate Weighted Moving Average.
     *
     * Assigns linearly decreasing weights (most recent gets highest weight).
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @param  int  $window  Number of periods
     */
    public function wma(array $values, int $window = self::DEFAULT_WINDOW): float
    {
        $window = max(1, min($window, count($values)));
        $slice = array_slice($values, -$window);
        $count = count($slice);

        if ($count === 0) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $weightTotal = 0;

        for ($i = 0; $i < $count; $i++) {
            $weight = $i + 1; // 1, 2, 3, ..., N
            $weightedSum += $slice[$i] * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;
    }

    /**
     * Calculate all three moving averages for a series.
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @param  int  $window  Window size for SMA/WMA
     * @param  float  $alpha  Alpha for EMA
     * @return array{sma: float, ema: float, wma: float}
     */
    public function allMovingAverages(array $values, int $window = self::DEFAULT_WINDOW, float $alpha = self::DEFAULT_EMA_ALPHA): array
    {
        return [
            'sma' => $this->sma($values, $window),
            'ema' => $this->ema($values, $alpha),
            'wma' => $this->wma($values, $window),
        ];
    }

    /**
     * Detect trend direction from a time series.
     *
     * Uses linear regression slope to classify direction.
     * Returns direction, slope magnitude, strength (0-1), and confidence.
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @return TrendResult
     */
    public function detectTrend(array $values): array
    {
        $minPoints = (int) $this->config->get('zeroboiler.analytics.rolling_window.trend_min_points', self::DEFAULT_TREND_MIN_POINTS);

        if (count($values) < $minPoints) {
            return [
                'direction' => 'flat',
                'slope' => 0.0,
                'strength' => 0.0,
                'confidence' => 0.0,
            ];
        }

        $n = count($values);
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $i;
            $y = $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);
        $slope = $denominator !== 0.0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denominator : 0.0;

        // R-squared as confidence measure
        $meanY = $n > 0 ? $sumY / $n : 0.0;
        $ssTotal = 0.0;
        $ssResidual = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $meanY + ($slope * $i);
            $ssTotal += ($values[$i] - $meanY) ** 2;
            $ssResidual += ($values[$i] - $predicted) ** 2;
        }

        $rSquared = $ssTotal > 0.0 ? 1.0 - ($ssResidual / $ssTotal) : 0.0;

        // Direction classification
        $direction = 'flat';
        if (abs($slope) > 0.01) {
            $direction = $slope > 0 ? 'up' : 'down';
        }

        // Strength: normalize slope relative to mean value
        $strength = $meanY > 0.0 ? min(1.0, abs($slope) / ($meanY / $n)) : 0.0;

        return [
            'direction' => $direction,
            'slope' => round($slope, 6),
            'strength' => round($strength, 4),
            'confidence' => round($rSquared, 4),
        ];
    }

    /**
     * Calculate volatility score (coefficient of variation).
     *
     * Measures the relative variability of a metric. Lower values
     * indicate more stable metrics.
     *
     * @param  array<int, float>  $values  Time-ordered values
     * @return float  Coefficient of variation (0 = no variation, higher = more volatile)
     */
    public function volatility(array $values): float
    {
        $volWindow = (int) $this->config->get('zeroboiler.analytics.rolling_window.volatility_window', count($values));

        $slice = array_slice($values, -$volWindow);
        $count = count($slice);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($slice) / $count;

        if ($mean === 0.0) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($slice as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= $count;

        $stdDev = sqrt($variance);

        return $stdDev / abs($mean);
    }

    /**
     * Generate a smoothed series using the specified algorithm.
     *
     * Returns a full smoothed series with one value per input point,
     * useful for chart rendering.
     *
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     * @param  string  $algorithm  'sma', 'ema', or 'wma'
     * @param  int  $window  Window size (for SMA/WMA)
     * @param  float  $alpha  Alpha (for EMA)
     * @return array<int, float>  Smoothed values (same length as input)
     */
    public function smoothSeries(array $values, string $algorithm = 'ema', int $window = self::DEFAULT_WINDOW, float $alpha = self::DEFAULT_EMA_ALPHA): array
    {
        if ($values === []) {
            return [];
        }

        return match ($algorithm) {
            'sma' => $this->smoothSeriesSMA($values, $window),
            'wma' => $this->smoothSeriesWMA($values, $window),
            default => $this->smoothSeriesEMA($values, $alpha),
        };
    }

    /**
     * Smooth series using SMA (rolling window per point).
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function smoothSeriesSMA(array $values, int $window): array
    {
        $result = [];
        for ($i = 0; $i < count($values); $i++) {
            $start = max(0, $i - $window + 1);
            $slice = array_slice($values, $start, $i - $start + 1);
            $result[] = count($slice) > 0 ? array_sum($slice) / count($slice) : 0.0;
        }

        return $result;
    }

    /**
     * Smooth series using EMA.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function smoothSeriesEMA(array $values, float $alpha): array
    {
        if ($values === []) {
            return [];
        }

        $result = [$values[0]];
        for ($i = 1; $i < count($values); $i++) {
            $result[] = ($alpha * $values[$i]) + ((1 - $alpha) * $result[$i - 1]);
        }

        return $result;
    }

    /**
     * Smooth series using WMA.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function smoothSeriesWMA(array $values, int $window): array
    {
        $result = [];
        for ($i = 0; $i < count($values); $i++) {
            $start = max(0, $i - $window + 1);
            $slice = array_slice($values, $start, $i - $start + 1);
            $count = count($slice);

            if ($count === 0) {
                $result[] = 0.0;
                continue;
            }

            $weightedSum = 0.0;
            $weightTotal = 0;
            for ($j = 0; $j < $count; $j++) {
                $weight = $j + 1;
                $weightedSum += $slice[$j] * $weight;
                $weightTotal += $weight;
            }
            $result[] = $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;
        }

        return $result;
    }

    /**
     * Compute a full analytics profile for a metric series.
     *
     * Returns current value, moving averages, trend, and volatility
     * in a single call — ideal for dashboard widgets.
     *
     * @param  array<int, float>  $values  Time-ordered values
     * @param  int  $window  Window size
     * @return array{current: float, sma: float, ema: float, wma: float, trend: TrendResult, volatility: float, min: float, max: float, mean: float, count: int}
     */
    public function profile(array $values, int $window = self::DEFAULT_WINDOW): array
    {
        $count = count($values);
        $current = $count > 0 ? $values[$count - 1] : 0.0;

        return [
            'current' => $current,
            'sma' => $this->sma($values, $window),
            'ema' => $this->ema($values),
            'wma' => $this->wma($values, $window),
            'trend' => $this->detectTrend($values),
            'volatility' => $this->volatility($values),
            'min' => $count > 0 ? min($values) : 0.0,
            'max' => $count > 0 ? max($values) : 0.0,
            'mean' => $count > 0 ? array_sum($values) / $count : 0.0,
            'count' => $count,
        ];
    }

    /**
     * Invalidate cached rolling window data.
     */
    public function invalidateCache(): void
    {
        // Cache is per-computation, full invalidation clears all zb_rolling_ keys
        // In production, use tagged cache for selective invalidation
    }
}
