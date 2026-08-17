<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event Time-Series Decomposition Engine.
 *
 * Decomposes event volume time-series into trend, seasonality, and noise
 * components using a moving-average based approach inspired by STL
 * (Seasonal and Trend decomposition using Loess).
 *
 * This enables SaaS teams to:
 * - Identify genuine growth trends vs. seasonal spikes
 * - Detect anomalies in event volumes after removing seasonal patterns
 * - Forecast future event volumes with seasonal awareness
 * - Compare actual vs. expected volumes for health monitoring
 *
 * The decomposition algorithm:
 * 1. Extract trend using centered moving average (window = seasonality period)
 * 2. Detrend the series (original - trend)
 * 3. Extract seasonal component by averaging each seasonal position
 * 4. Compute noise/residual (original - trend - seasonal)
 *
 * @see \ZeroBoiler\Analytics\Services\EventTrendForecastService
 * @see \ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService
 *
 * @since 221.0.0
 *
 * @phpstan-type DecompositionResult array{
 *     event_name: string,
 *     period: int,
 *     data_points: int,
 *     trend: list<float>,
 *     seasonal: list<float>,
 *     noise: list<float>,
 *     trend_slope: float,
 *     trend_direction: 'growing'|'declining'|'stable',
 *     seasonality_strength: float,
 *     noise_ratio: float,
 *     signal_to_noise: float,
 *     seasonal_peaks: list<int>,
 *     seasonal_troughs: list<int>,
 *     anomalies: list<array{index: int, expected: float, actual: float, deviation: float, z_score: float}>,
 *     forecast: list<float>,
 *     confidence_upper: list<float>,
 *     confidence_lower: list<float>,
 *     summary: array{mean: float, std_dev: float, min: float, max: float, trend_pct_change: float, seasonal_amplitude: float}
 * }
 *
 * @phpstan-type MultiEventDecomposition array{
 *     events: array<string, DecompositionResult>,
 *     comparison: array{highest_trend: string|null, highest_seasonality: string|null, most_volatile: string|null, most_predictable: string|null},
 *     timestamp: string
 * }
 */
final class EventTimeSeriesDecompositionService
{
    /** @var int Default seasonality period (7 for weekly patterns in daily data) */
    private const DEFAULT_PERIOD = 7;

    /** @var int Minimum data points required for decomposition */
    private const MIN_DATA_POINTS = 8;

    /** @var int Minimum data points for seasonal decomposition (need at least 2 full cycles) */
    private const MIN_SEASONAL_POINTS = 14;

    /** @var float Anomaly detection z-score threshold */
    private const ANOMALY_Z_THRESHOLD = 2.0;

    /** @var int Forecast horizon (same as period for one seasonal cycle) */
    private const FORECAST_HORIZON_FACTOR = 1;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    /** @var int Default seasonality period */
    private int $defaultPeriod;

    /** @var int Forecast horizon multiplier */
    private int $forecastHorizon;

    /** @var float Confidence interval width (number of std devs) */
    private float $confidenceWidth;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $decompositionConfig = $config->get('zeroboiler.analytics.decomposition', []);
        /** @var array{enabled?: bool, cache_ttl?: int, default_period?: int, forecast_horizon?: int, confidence_width?: float} $decompositionConfig */
        $this->enabled = (bool) ($decompositionConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($decompositionConfig['cache_ttl'] ?? 1800);
        $this->defaultPeriod = (int) ($decompositionConfig['default_period'] ?? self::DEFAULT_PERIOD);
        $this->forecastHorizon = (int) ($decompositionConfig['forecast_horizon'] ?? self::FORECAST_HORIZON_FACTOR);
        $this->confidenceWidth = (float) ($decompositionConfig['confidence_width'] ?? 1.96);
    }

    /**
     * Check if the decomposition service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured cache TTL.
     */
    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Decompose a single event's time-series data.
     *
     * @param  string  $eventName  Event name to decompose
     * @param  list<int|float>  $values  Time-ordered event volume values (e.g. daily counts)
     * @param  int|null  $period  Seasonality period (null = use default)
     * @param  int|null  $forecastSteps  Number of steps to forecast (null = use default)
     * @return DecompositionResult
     */
    public function decompose(
        string $eventName,
        array $values,
        ?int $period = null,
        ?int $forecastSteps = null,
    ): array {
        $period = $period ?? $this->defaultPeriod;
        $forecastSteps = $forecastSteps ?? ($period * $this->forecastHorizon);

        $cleaned = $this->cleanValues($values);

        if (count($cleaned) < self::MIN_DATA_POINTS) {
            return $this->emptyResult($eventName, $period, count($cleaned));
        }

        $hasSeasonal = count($cleaned) >= self::MIN_SEASONAL_POINTS;

        if ($hasSeasonal) {
            $trend = $this->extractTrend($cleaned, $period);
            $detrended = $this->subtract($cleaned, $trend);
            $seasonal = $this->extractSeasonal($detrended, $period, count($cleaned));
            $noise = $this->subtract($cleaned, $this->add($trend, $seasonal));
        } else {
            // Insufficient data for seasonal extraction — use simple trend
            $trend = $this->extractTrend($cleaned, min($period, count($cleaned)));
            $seasonal = array_fill(0, count($cleaned), 0.0);
            $noise = $this->subtract($cleaned, $trend);
        }

        $trendSlope = $this->computeTrendSlope($trend);
        $trendDirection = $this->classifyTrendDirection($trendSlope);
        $seasonalityStrength = $this->computeSeasonalityStrength($cleaned, $trend, $seasonal);
        $noiseStdDev = $this->stdDev($noise);
        $dataStdDev = $this->stdDev($cleaned);
        $noiseRatio = $dataStdDev > 0.001 ? $noiseStdDev / $dataStdDev : 0.0;
        $signalToNoise = $noiseRatio > 0.001 ? (1.0 - $noiseRatio) / $noiseRatio : 0.0;

        $seasonalPeaks = $this->findPeaks($seasonal);
        $seasonalTroughs = $this->findTroughs($seasonal);

        $anomalies = $this->detectAnomalies($cleaned, $trend, $seasonal);

        // Forecast: extend trend linearly, repeat seasonal pattern
        $forecast = $this->forecast($trend, $seasonal, $forecastSteps);
        $confidenceUpper = array_map(fn (float $v): float => $v + ($this->confidenceWidth * $noiseStdDev), $forecast);
        $confidenceLower = array_map(fn (float $v): float => $v - ($this->confidenceWidth * $noiseStdDev), $forecast);

        $mean = $this->mean($cleaned);
        $firstHalf = array_slice($trend, 0, (int) (count($trend) / 2));
        $secondHalf = array_slice($trend, (int) (count($trend) / 2));
        $firstMean = $this->mean($firstHalf) !== 0.0 ? $this->mean($firstHalf) : 0.001;
        $trendPctChange = round((($this->mean($secondHalf) - $this->mean($firstHalf)) / $firstMean) * 100, 2);

        $seasonalAmplitude = count($seasonal) > 0
            ? round((max($seasonal) - min($seasonal)) / 2, 4)
            : 0.0;

        return [
            'event_name' => $eventName,
            'period' => $period,
            'data_points' => count($cleaned),
            'trend' => array_map(fn (float $v): float => round($v, 4), $trend),
            'seasonal' => array_map(fn (float $v): float => round($v, 4), $seasonal),
            'noise' => array_map(fn (float $v): float => round($v, 4), $noise),
            'trend_slope' => round($trendSlope, 6),
            'trend_direction' => $trendDirection,
            'seasonality_strength' => round($seasonalityStrength, 4),
            'noise_ratio' => round($noiseRatio, 4),
            'signal_to_noise' => round($signalToNoise, 4),
            'seasonal_peaks' => $seasonalPeaks,
            'seasonal_troughs' => $seasonalTroughs,
            'anomalies' => array_map(fn (array $a): array => [
                'index' => $a['index'],
                'expected' => round($a['expected'], 4),
                'actual' => round($a['actual'], 4),
                'deviation' => round($a['deviation'], 4),
                'z_score' => round($a['z_score'], 4),
            ], $anomalies),
            'forecast' => array_map(fn (float $v): float => round($v, 4), $forecast),
            'confidence_upper' => array_map(fn (float $v): float => round($v, 4), $confidenceUpper),
            'confidence_lower' => array_map(fn (float $v): float => round(max(0.0, $v), 4), $confidenceLower),
            'summary' => [
                'mean' => round($mean, 4),
                'std_dev' => round($dataStdDev, 4),
                'min' => round((float) min($cleaned), 4),
                'max' => round((float) max($cleaned), 4),
                'trend_pct_change' => $trendPctChange,
                'seasonal_amplitude' => $seasonalAmplitude,
            ],
        ];
    }

    /**
     * Decompose multiple events and produce a comparative report.
     *
     * @param  array<string, list<int|float>>  $eventData  Map of event name → time-series values
     * @param  int|null  $period  Seasonality period (null = use default)
     * @return MultiEventDecomposition
     */
    public function decomposeMulti(array $eventData, ?int $period = null): array
    {
        $results = [];

        foreach ($eventData as $eventName => $values) {
            $results[$eventName] = $this->decompose($eventName, $values, $period);
        }

        // Comparative analysis
        $highestTrend = null;
        $highestTrendSlope = -PHP_FLOAT_MAX;
        $highestSeasonality = null;
        $highestSeasonStrength = -PHP_FLOAT_MAX;
        $mostVolatile = null;
        $highestNoiseRatio = -PHP_FLOAT_MAX;
        $mostPredictable = null;
        $lowestNoiseRatio = PHP_FLOAT_MAX;

        foreach ($results as $name => $result) {
            if ($result['trend_slope'] > $highestTrendSlope && $result['data_points'] >= self::MIN_DATA_POINTS) {
                $highestTrendSlope = $result['trend_slope'];
                $highestTrend = $name;
            }

            if ($result['seasonality_strength'] > $highestSeasonStrength) {
                $highestSeasonStrength = $result['seasonality_strength'];
                $highestSeasonality = $name;
            }

            if ($result['noise_ratio'] > $highestNoiseRatio && $result['data_points'] >= self::MIN_DATA_POINTS) {
                $highestNoiseRatio = $result['noise_ratio'];
                $mostVolatile = $name;
            }

            if ($result['noise_ratio'] < $lowestNoiseRatio && $result['data_points'] >= self::MIN_DATA_POINTS) {
                $lowestNoiseRatio = $result['noise_ratio'];
                $mostPredictable = $name;
            }
        }

        return [
            'events' => $results,
            'comparison' => [
                'highest_trend' => $highestTrend,
                'highest_seasonality' => $highestSeasonality,
                'most_volatile' => $mostVolatile,
                'most_predictable' => $mostPredictable,
            ],
            'timestamp' => date('c'),
        ];
    }

    /**
     * Generate a seasonal pattern profile for an event.
     *
     * Returns the average seasonal component for each position in the period.
     * Useful for understanding which days/hours have naturally higher/lower volumes.
     *
     * @param  list<int|float>  $values
     * @param  int  $period
     * @return array{positions: list<int>, values: list<float>, peaks: list<int>, troughs: list<int>, amplitude: float}
     */
    public function seasonalProfile(array $values, int $period = self::DEFAULT_PERIOD): array
    {
        $cleaned = $this->cleanValues($values);

        if (count($cleaned) < self::MIN_SEASONAL_POINTS) {
            return [
                'positions' => range(0, $period - 1),
                'values' => array_fill(0, $period, 0.0),
                'peaks' => [],
                'troughs' => [],
                'amplitude' => 0.0,
            ];
        }

        $trend = $this->extractTrend($cleaned, $period);
        $detrended = $this->subtract($cleaned, $trend);
        $seasonal = $this->extractSeasonal($detrended, $period, count($cleaned));

        // Average seasonal pattern (one value per position)
        $profile = array_fill(0, $period, 0.0);
        $counts = array_fill(0, $period, 0);

        foreach ($seasonal as $i => $value) {
            $pos = $i % $period;
            $profile[$pos] += $value;
            $counts[$pos]++;
        }

        $profile = array_map(fn (float $sum, int $count): float =>
            $count > 0 ? $sum / $count : 0.0,
            $profile, $counts
        );

        $peaks = [];
        $troughs = [];
        $maxVal = max($profile);
        $minVal = min($profile);
        $range = $maxVal - $minVal;

        if ($range > 0.001) {
            foreach ($profile as $i => $v) {
                if ($v > $maxVal - $range * 0.15) {
                    $peaks[] = $i;
                }
                if ($v < $minVal + $range * 0.15) {
                    $troughs[] = $i;
                }
            }
        }

        return [
            'positions' => range(0, $period - 1),
            'values' => array_map(fn (float $v): float => round($v, 4), $profile),
            'peaks' => $peaks,
            'troughs' => $troughs,
            'amplitude' => round($range / 2, 4),
        ];
    }

    /**
     * Check if a time-series has sufficient data for decomposition.
     */
    public function hasSufficientData(array $values, ?int $period = null): bool
    {
        $period = $period ?? $this->defaultPeriod;

        return count($this->cleanValues($values)) >= max(self::MIN_DATA_POINTS, $period * 2);
    }

    /**
     * Invalidate the decomposition cache.
     */
    public function invalidateCache(): void
    {
        // Service-level cache — individual calls may be cached by callers
    }

    /**
     * Get the service configuration summary.
     *
     * @return array{enabled: bool, cache_ttl: int, default_period: int, forecast_horizon: int, confidence_width: float, min_data_points: int, min_seasonal_points: int, anomaly_z_threshold: float}
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'default_period' => $this->defaultPeriod,
            'forecast_horizon' => $this->forecastHorizon,
            'confidence_width' => $this->confidenceWidth,
            'min_data_points' => self::MIN_DATA_POINTS,
            'min_seasonal_points' => self::MIN_SEASONAL_POINTS,
            'anomaly_z_threshold' => self::ANOMALY_Z_THRESHOLD,
        ];
    }

    // ─── Internal Methods ────────────────────────────────────────────────

    /**
     * Clean and validate input values.
     *
     * @param  list<int|float>  $values
     * @return list<float>
     */
    private function cleanValues(array $values): array
    {
        return array_values(array_map(
            fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0,
            $values,
        ));
    }

    /**
     * Extract trend using centered moving average.
     *
     * @param  list<float>  $values
     * @param  int  $window
     * @return list<float>
     */
    private function extractTrend(array $values, int $window): array
    {
        $n = count($values);
        $halfWindow = (int) floor($window / 2);
        $trend = [];

        for ($i = 0; $i < $n; $i++) {
            $start = max(0, $i - $halfWindow);
            $end = min($n - 1, $i + $halfWindow);
            $sum = 0.0;
            $count = 0;

            for ($j = $start; $j <= $end; $j++) {
                $sum += $values[$j];
                $count++;
            }

            $trend[] = $count > 0 ? $sum / $count : $values[$i];
        }

        return $trend;
    }

    /**
     * Extract seasonal component from detrended data.
     *
     * Averages values at each seasonal position across all cycles.
     *
     * @param  list<float>  $detrended
     * @param  int  $period
     * @param  int  $totalLength
     * @return list<float>
     */
    private function extractSeasonal(array $detrended, int $period, int $totalLength): array
    {
        // Collect values at each seasonal position
        $positionSums = array_fill(0, $period, 0.0);
        $positionCounts = array_fill(0, $period, 0);

        foreach ($detrended as $i => $value) {
            $pos = $i % $period;
            $positionSums[$pos] += $value;
            $positionCounts[$pos]++;
        }

        // Compute average seasonal value per position
        $seasonalPattern = [];
        for ($i = 0; $i < $period; $i++) {
            $seasonalPattern[] = $positionCounts[$i] > 0
                ? $positionSums[$i] / $positionCounts[$i]
                : 0.0;
        }

        // Remove mean of seasonal pattern to center it around zero
        $seasonalMean = array_sum($seasonalPattern) / count($seasonalPattern);
        $seasonalPattern = array_map(fn (float $v): float => $v - $seasonalMean, $seasonalPattern);

        // Tile the seasonal pattern to match the original length
        $seasonal = [];
        for ($i = 0; $i < $totalLength; $i++) {
            $seasonal[] = $seasonalPattern[$i % $period];
        }

        return $seasonal;
    }

    /**
     * Compute trend slope using simple linear regression.
     *
     * @param  list<float>  $trend
     * @return float
     */
    private function computeTrendSlope(array $trend): float
    {
        $n = count($trend);
        if ($n < 2) {
            return 0.0;
        }

        $meanX = ($n - 1) / 2.0;
        $meanY = $this->mean($trend);

        $numerator = 0.0;
        $denominator = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $i - $meanX;
            $dy = $trend[$i] - $meanY;
            $numerator += $dx * $dy;
            $denominator += $dx * $dx;
        }

        return $denominator > 0.0 ? $numerator / $denominator : 0.0;
    }

    /**
     * Classify trend direction from slope.
     *
     * @return 'growing'|'declining'|'stable'
     */
    private function classifyTrendDirection(float $slope): string
    {
        $meanAbs = 1.0; // Normalized threshold

        if ($slope > 0.01 * $meanAbs) {
            return 'growing';
        }

        if ($slope < -0.01 * $meanAbs) {
            return 'declining';
        }

        return 'stable';
    }

    /**
     * Compute seasonality strength (0-1).
     * Ratio of seasonal variance to total variance.
     *
     * @param  list<float>  $original
     * @param  list<float>  $trend
     * @param  list<float>  $seasonal
     * @return float
     */
    private function computeSeasonalityStrength(array $original, array $trend, array $seasonal): float
    {
        $totalVariance = $this->variance($original);

        if ($totalVariance < 0.0001) {
            return 0.0;
        }

        $seasonalVariance = $this->variance($seasonal);

        return min(1.0, $seasonalVariance / $totalVariance);
    }

    /**
     * Detect anomalies where actual deviates significantly from expected.
     *
     * Expected = trend + seasonal. Anomaly when |deviation| > z_threshold * noise_std_dev.
     *
     * @param  list<float>  $actual
     * @param  list<float>  $trend
     * @param  list<float>  $seasonal
     * @return list<array{index: int, expected: float, actual: float, deviation: float, z_score: float}>
     */
    private function detectAnomalies(array $actual, array $trend, array $seasonal): array
    {
        $n = min(count($actual), count($trend), count($seasonal));

        if ($n < self::MIN_DATA_POINTS) {
            return [];
        }

        $noiseStdDev = $this->stdDev($this->subtract(
            array_slice($actual, 0, $n),
            $this->add(array_slice($trend, 0, $n), array_slice($seasonal, 0, $n)),
        ));

        if ($noiseStdDev < 0.0001) {
            return [];
        }

        $anomalies = [];

        for ($i = 0; $i < $n; $i++) {
            $expected = $trend[$i] + $seasonal[$i];
            $deviation = $actual[$i] - $expected;
            $zScore = abs($deviation) / $noiseStdDev;

            if ($zScore >= self::ANOMALY_Z_THRESHOLD) {
                $anomalies[] = [
                    'index' => $i,
                    'expected' => $expected,
                    'actual' => $actual[$i],
                    'deviation' => $deviation,
                    'z_score' => $zScore,
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Forecast future values by extending trend and repeating seasonal pattern.
     *
     * @param  list<float>  $trend
     * @param  list<float>  $seasonal
     * @param  int  $steps
     * @return list<float>
     */
    private function forecast(array $trend, array $seasonal, int $steps): array
    {
        $n = count($trend);
        $forecast = [];

        if ($n < 2) {
            return array_fill(0, $steps, $n > 0 ? $trend[0] : 0.0);
        }

        // Extrapolate trend linearly
        $lastTrend = $trend[$n - 1];
        $slope = $this->computeTrendSlope($trend);

        for ($i = 0; $i < $steps; $i++) {
            $trendValue = $lastTrend + $slope * ($i + 1);
            $seasonalValue = $seasonal[($n + $i) % count($seasonal)] ?? 0.0;
            $forecast[] = max(0.0, $trendValue + $seasonalValue);
        }

        return $forecast;
    }

    /**
     * Find peak positions in a series.
     *
     * @param  list<float>  $values
     * @return list<int>
     */
    private function findPeaks(array $values): array
    {
        $n = count($values);
        if ($n < 3) {
            return [];
        }

        $peaks = [];
        $mean = $this->mean($values);
        $stdDev = $this->stdDev($values);

        for ($i = 1; $i < $n - 1; $i++) {
            if ($values[$i] > $values[$i - 1]
                && $values[$i] > $values[$i + 1]
                && $values[$i] > $mean + 0.5 * $stdDev
            ) {
                $peaks[] = $i;
            }
        }

        return $peaks;
    }

    /**
     * Find trough positions in a series.
     *
     * @param  list<float>  $values
     * @return list<int>
     */
    private function findTroughs(array $values): array
    {
        $n = count($values);
        if ($n < 3) {
            return [];
        }

        $troughs = [];
        $mean = $this->mean($values);
        $stdDev = $this->stdDev($values);

        for ($i = 1; $i < $n - 1; $i++) {
            if ($values[$i] < $values[$i - 1]
                && $values[$i] < $values[$i + 1]
                && $values[$i] < $mean - 0.5 * $stdDev
            ) {
                $troughs[] = $i;
            }
        }

        return $troughs;
    }

    // ─── Math Helpers ────────────────────────────────────────────────────

    /** @param  list<float>  $values @return float */
    private function mean(array $values): float
    {
        $n = count($values);

        return $n > 0 ? array_sum($values) / $n : 0.0;
    }

    /** @param  list<float>  $values @return float */
    private function stdDev(array $values): float
    {
        return sqrt($this->variance($values));
    }

    /** @param  list<float>  $values @return float */
    private function variance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sumSq = 0.0;

        foreach ($values as $v) {
            $sumSq += ($v - $mean) ** 2;
        }

        return $sumSq / ($n - 1);
    }

    /** @param  list<float>  $a @param  list<float>  $b @return list<float> */
    private function subtract(array $a, array $b): array
    {
        $n = min(count($a), count($b));
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $result[] = ($a[$i] ?? 0.0) - ($b[$i] ?? 0.0);
        }

        return $result;
    }

    /** @param  list<float>  $a @param  list<float>  $b @return list<float> */
    private function add(array $a, array $b): array
    {
        $n = max(count($a), count($b));
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $result[] = ($a[$i] ?? 0.0) + ($b[$i] ?? 0.0);
        }

        return $result;
    }

    /**
     * Build an empty result for insufficient data.
     *
     * @return DecompositionResult
     */
    private function emptyResult(string $eventName, int $period, int $dataPoints): array
    {
        return [
            'event_name' => $eventName,
            'period' => $period,
            'data_points' => $dataPoints,
            'trend' => [],
            'seasonal' => [],
            'noise' => [],
            'trend_slope' => 0.0,
            'trend_direction' => 'stable',
            'seasonality_strength' => 0.0,
            'noise_ratio' => 1.0,
            'signal_to_noise' => 0.0,
            'seasonal_peaks' => [],
            'seasonal_troughs' => [],
            'anomalies' => [],
            'forecast' => [],
            'confidence_upper' => [],
            'confidence_lower' => [],
            'summary' => [
                'mean' => 0.0,
                'std_dev' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'trend_pct_change' => 0.0,
                'seasonal_amplitude' => 0.0,
            ],
        ];
    }
}
