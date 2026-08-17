<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event volume anomaly detection service.
 *
 * Monitors event volume patterns using statistical methods (moving average,
 * standard deviation, Z-score) to detect anomalies like sudden spikes,
 * unexpected drops, and unusual patterns that may indicate bugs, attacks,
 * or infrastructure issues.
 *
 * Cache-backed sliding window of configurable size. Supports per-category
 * and per-event-name granularity with configurable sensitivity.
 *
 * Inspired by Datadog Anomaly Detection, Google Cloud Monitoring,
 * and Honeycomb Burn Alert patterns.
 *
 * @since 214.0.0
 */
final class EventVolumeAnomalyDetectionService
{
    /** @var string Cache key prefix for volume windows */
    private const CACHE_PREFIX = 'zb_anomaly_vol_';

    /** @var string Cache key for detected anomaly history */
    private const HISTORY_KEY = 'zb_anomaly_history';

    /** @var int Default sliding window size (number of buckets) */
    private const DEFAULT_WINDOW_SIZE = 60;

    /** @var int Default bucket interval in seconds */
    private const DEFAULT_BUCKET_INTERVAL = 60;

    /** @var float Default Z-score threshold for anomaly detection */
    private const DEFAULT_ZSCORE_THRESHOLD = 2.5;

    /** @var int Max anomalies retained in history */
    private const MAX_HISTORY = 200;

    private CacheRepository $cache;

    private bool $enabled;

    private int $windowSize;

    private int $bucketInterval;

    private float $zScoreThreshold;

    private int $cacheTtl;

    private bool $logAnomalies;

    /** @var int Minimum data points required before detection activates */
    private int $minDataPoints;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $anomalyConfig = $config->get('zeroboiler.analytics.anomaly_detection', []);
        /** @var array{enabled?: bool, window_size?: int, bucket_interval?: int, zscore_threshold?: float, cache_ttl?: int, log_anomalies?: bool, min_data_points?: int} $anomalyConfig */

        $this->enabled = (bool) ($anomalyConfig['enabled'] ?? true);
        $this->windowSize = (int) ($anomalyConfig['window_size'] ?? self::DEFAULT_WINDOW_SIZE);
        $this->bucketInterval = (int) ($anomalyConfig['bucket_interval'] ?? self::DEFAULT_BUCKET_INTERVAL);
        $this->zScoreThreshold = (float) ($anomalyConfig['zscore_threshold'] ?? self::DEFAULT_ZSCORE_THRESHOLD);
        $this->cacheTtl = (int) ($anomalyConfig['cache_ttl'] ?? 7200);
        $this->logAnomalies = (bool) ($anomalyConfig['log_anomalies'] ?? true);
        $this->minDataPoints = (int) ($anomalyConfig['min_data_points'] ?? 10);
    }

    /**
     * Record an event occurrence for volume tracking.
     *
     * Increments the current time bucket counter for the given event name
     * and category. Call this after every dispatched event.
     *
     * @param  string  $eventName  Event name (e.g. 'purchase', 'page_view')
     * @param  string|null  $category  Optional event category (e.g. 'ecommerce', 'engagement')
     */
    public function recordEvent(string $eventName, ?string $category = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->incrementBucket($this->globalKey(), $eventName);
        $this->incrementBucket($this->categoryKey($category ?? 'all'), $eventName);
        $this->incrementBucket($this->eventKey($eventName), $eventName);
    }

    /**
     * Record multiple events in a single batch.
     *
     * More efficient than calling recordEvent() in a loop.
     *
     * @param  int  $count  Number of events
     * @param  string  $eventName  Event name
     * @param  string|null  $category  Optional category
     */
    public function recordBatch(int $count, string $eventName, ?string $category = null): void
    {
        if (! $this->enabled || $count <= 0) {
            return;
        }

        $this->incrementBucket($this->globalKey(), $eventName, $count);
        $this->incrementBucket($this->categoryKey($category ?? 'all'), $eventName, $count);
        $this->incrementBucket($this->eventKey($eventName), $eventName, $count);
    }

    /**
     * Check for anomalies across all tracked dimensions.
     *
     * Returns a list of detected anomalies with severity, context, and
     * recommended actions.
     *
     * @return list<AnomalyRecord>
     */
    public function detectAnomalies(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $anomalies = [];

        // Check global volume
        $globalResult = $this->analyzeWindow($this->globalKey(), 'global');
        if ($globalResult !== null) {
            $anomalies[] = $globalResult;
        }

        // Check top categories
        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        foreach ($categories as $category) {
            $catResult = $this->analyzeWindow($this->categoryKey($category), "category:{$category}");
            if ($catResult !== null) {
                $anomalies[] = $catResult;
            }
        }

        // Persist to history
        if ($anomalies !== []) {
            $this->persistAnomalies($anomalies);
        }

        return $anomalies;
    }

    /**
     * Check for anomalies scoped to a specific dimension.
     *
     * @param  string  $scope  Dimension to check: 'global', 'category:<name>', or 'event:<name>'
     * @return list<AnomalyRecord>
     */
    public function detectAnomaliesFor(string $scope): array
    {
        if (! $this->enabled) {
            return [];
        }

        $key = match (true) {
            str_starts_with($scope, 'category:') => $this->categoryKey(substr($scope, 9)),
            str_starts_with($scope, 'event:') => $this->eventKey(substr($scope, 6)),
            default => $this->globalKey(),
        };

        $result = $this->analyzeWindow($key, $scope);

        return $result !== null ? [$result] : [];
    }

    /**
     * Get the current sliding window data for a dimension.
     *
     * @param  string  $scope  Dimension scope
     * @return WindowSnapshot
     */
    public function getWindowSnapshot(string $scope = 'global'): WindowSnapshot
    {
        $key = match (true) {
            str_starts_with($scope, 'category:') => $this->categoryKey(substr($scope, 9)),
            str_starts_with($scope, 'event:') => $this->eventKey(substr($scope, 6)),
            default => $this->globalKey(),
        };

        $window = $this->getWindow($key);
        $stats = $this->computeStats($window);

        return new WindowSnapshot(
            scope: $scope,
            buckets: $window,
            current: (int) ($window[array_key_last($window) ?? 0] ?? 0),
            mean: $stats['mean'],
            stdDev: $stats['std_dev'],
            min: $stats['min'],
            max: $stats['max'],
            trend: $this->computeTrendDirection($window),
            bucketCount: count($window),
        );
    }

    /**
     * Get anomaly detection history.
     *
     * @param  int  $limit  Max number of records to return
     * @return list<array{timestamp: string, scope: string, type: string, severity: string, z_score: float, current: int, expected: float}>
     */
    public function getHistory(int $limit = 50): array
    {
        /** @var list<array{timestamp: string, scope: string, type: string, severity: string, z_score: float, current: int, expected: float}> $history */
        $history = $this->cache->get(self::HISTORY_KEY, []);

        return array_slice($history, 0, $limit);
    }

    /**
     * Clear all anomaly detection data.
     */
    public function flush(): void
    {
        // Flush all anomaly-related cache keys
        $this->cache->forget($this->globalKey());
        $this->cache->forget(self::HISTORY_KEY);

        $categories = ['all', 'ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        foreach ($categories as $cat) {
            $this->cache->forget($this->categoryKey($cat));
        }
    }

    /**
     * Check if anomaly detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get configuration summary.
     *
     * @return array{enabled: bool, window_size: int, bucket_interval: int, zscore_threshold: float, cache_ttl: int, min_data_points: int, log_anomalies: bool}
     */
    public function getConfigSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'window_size' => $this->windowSize,
            'bucket_interval' => $this->bucketInterval,
            'zscore_threshold' => $this->zScoreThreshold,
            'cache_ttl' => $this->cacheTtl,
            'min_data_points' => $this->minDataPoints,
            'log_anomalies' => $this->logAnomalies,
        ];
    }

    // ── Internal Methods ────────────────────────────────────────────────

    /**
     * Increment the current time bucket for a tracking key.
     *
     * @param  string  $key  Cache key for the dimension
     * @param  string  $eventName  Event name (for logging context)
     * @param  int  $amount  Amount to increment
     */
    private function incrementBucket(string $key, string $eventName, int $amount = 1): void
    {
        $window = $this->getWindow($key);
        $currentBucket = $this->currentBucketKey();

        if (! isset($window[$currentBucket])) {
            $window[$currentBucket] = 0;
        }

        $window[$currentBucket] += $amount;

        // Trim to window size (keep newest buckets)
        ksort($window);
        $window = array_slice($window, -$this->windowSize, null, true);

        $this->cache->put($key, $window, $this->cacheTtl);
    }

    /**
     * Get the sliding window data for a key.
     *
     * @param  string  $key  Cache key
     * @return array<string, int>
     */
    private function getWindow(string $key): array
    {
        /** @var array<string, int>|null $window */
        $window = $this->cache->get($key, []);

        return is_array($window) ? $window : [];
    }

    /**
     * Generate the current time bucket key.
     *
     * Buckets are aligned to fixed intervals for consistent windowing.
     */
    private function currentBucketKey(): string
    {
        return (string) (int) (time() / $this->bucketInterval);
    }

    /**
     * Analyze a sliding window for anomalies.
     *
     * Computes Z-score of the latest bucket vs. the historical mean and
     * standard deviation. Returns an anomaly record if the Z-score exceeds
     * the configured threshold.
     *
     * @param  string  $key  Cache key for the dimension
     * @param  string  $scope  Human-readable scope label
     * @return AnomalyRecord|null
     */
    private function analyzeWindow(string $key, string $scope): ?AnomalyRecord
    {
        $window = $this->getWindow($key);

        if (count($window) < $this->minDataPoints) {
            return null;
        }

        ksort($window);

        $values = array_values($window);
        $latestValue = (int) array_pop($values);
        $historical = $values;

        if ($historical === []) {
            return null;
        }

        $stats = $this->computeStatsFromValues($historical);
        $mean = $stats['mean'];
        $stdDev = $stats['std_dev'];

        // Skip if no variance (flat line)
        if ($stdDev < 0.001) {
            // Flat line — only anomaly if the latest is non-zero and mean was zero
            if ($mean < 0.001 && $latestValue > 0) {
                return $this->createAnomaly(
                    scope: $scope,
                    type: 'spike',
                    severity: 'medium',
                    zScore: 99.0,
                    current: $latestValue,
                    expected: $mean,
                    message: "Unexpected activity detected in '{$scope}' — baseline was zero, now receiving events.",
                );
            }

            return null;
        }

        $zScore = ($latestValue - $mean) / $stdDev;
        $absZScore = abs($zScore);

        if ($absZScore < $this->zScoreThreshold) {
            return null;
        }

        $type = $zScore > 0 ? 'spike' : 'drop';
        $severity = $absZScore >= 5.0 ? 'critical' : ($absZScore >= 3.5 ? 'high' : 'medium');

        $message = $type === 'spike'
            ? "Volume spike detected in '{$scope}': {$latestValue} events (expected ~{$this->formatNum($mean)}, z={$this->formatNum($absZScore)})."
            : "Volume drop detected in '{$scope}': {$latestValue} events (expected ~{$this->formatNum($mean)}, z={$this->formatNum($absZScore)}).";

        return $this->createAnomaly(
            scope: $scope,
            type: $type,
            severity: $severity,
            zScore: $absZScore,
            current: $latestValue,
            expected: $mean,
            message: $message,
        );
    }

    /**
     * Create a new anomaly record with optional logging.
     *
     * @param  string  $scope  Detection scope
     * @param  'spike'|'drop'  $type  Anomaly type
     * @param  'low'|'medium'|'high'|'critical'  $severity  Severity level
     * @param  float  $zScore  Absolute Z-score
     * @param  int  $current  Current bucket value
     * @param  float  $expected  Expected mean value
     * @param  string  $message  Human-readable message
     * @return AnomalyRecord
     */
    private function createAnomaly(
        string $scope,
        string $type,
        string $severity,
        float $zScore,
        int $current,
        float $expected,
        string $message,
    ): AnomalyRecord {
        if ($this->logAnomalies) {
            $logLevel = match ($severity) {
                'critical' => 'error',
                'high' => 'warning',
                default => 'info',
            };

            Log::$logLevel("[Anomaly] {$message}", [
                'scope' => $scope,
                'type' => $type,
                'severity' => $severity,
                'z_score' => round($zScore, 2),
                'current' => $current,
                'expected' => round($expected, 2),
            ]);
        }

        return new AnomalyRecord(
            scope: $scope,
            type: $type,
            severity: $severity,
            zScore: $zScore,
            current: $current,
            expected: $expected,
            timestamp: new \DateTimeImmutable(),
            message: $message,
        );
    }

    /**
     * Persist detected anomalies to history.
     *
     * @param  list<AnomalyRecord>  $anomalies
     */
    private function persistAnomalies(array $anomalies): void
    {
        $history = $this->cache->get(self::HISTORY_KEY, []);

        foreach ($anomalies as $anomaly) {
            $history[] = [
                'timestamp' => $anomaly->timestamp->format(\DateTimeInterface::ATOM),
                'scope' => $anomaly->scope,
                'type' => $anomaly->type,
                'severity' => $anomaly->severity,
                'z_score' => round($anomaly->zScore, 2),
                'current' => $anomaly->current,
                'expected' => round($anomaly->expected, 2),
                'message' => $anomaly->message,
            ];
        }

        // Keep only the most recent anomalies
        $history = array_slice($history, -self::MAX_HISTORY);

        $this->cache->put(self::HISTORY_KEY, $history, $this->cacheTtl * 2);
    }

    /**
     * Compute basic statistics from a sliding window array.
     *
     * @param  array<string, int>  $window  Time bucket → count
     * @return array{mean: float, std_dev: float, min: int, max: int}
     */
    private function computeStats(array $window): array
    {
        return $this->computeStatsFromValues(array_values($window));
    }

    /**
     * Compute basic statistics from an array of numeric values.
     *
     * @param  list<int>  $values
     * @return array{mean: float, std_dev: float, min: int, max: int}
     */
    private function computeStatsFromValues(array $values): array
    {
        if ($values === []) {
            return ['mean' => 0.0, 'std_dev' => 0.0, 'min' => 0, 'max' => 0];
        }

        $count = count($values);
        $sum = array_sum($values);
        $mean = (float) ($sum / $count);

        if ($count < 2) {
            return ['mean' => $mean, 'std_dev' => 0.0, 'min' => (int) $values[0], 'max' => (int) $values[0]];
        }

        $variance = 0.0;
        foreach ($values as $v) {
            $diff = (float) $v - $mean;
            $variance += $diff * $diff;
        }

        $variance /= ($count - 1);
        $stdDev = (float) sqrt($variance);

        return [
            'mean' => $mean,
            'std_dev' => $stdDev,
            'min' => (int) min($values),
            'max' => (int) max($values),
        ];
    }

    /**
     * Compute the trend direction of a sliding window.
     *
     * Compares the mean of the second half to the mean of the first half.
     *
     * @param  array<string, int>  $window  Time bucket → count
     * @return 'rising'|'falling'|'stable'
     */
    private function computeTrendDirection(array $window): string
    {
        ksort($window);
        $values = array_values($window);
        $count = count($values);

        if ($count < 4) {
            return 'stable';
        }

        $mid = (int) floor($count / 2);
        $firstHalf = array_slice($values, 0, $mid);
        $secondHalf = array_slice($values, $mid);

        $firstMean = (float) (array_sum($firstHalf) / count($firstHalf));
        $secondMean = (float) (array_sum($secondHalf) / count($secondHalf));

        if ($firstMean < 0.001 && $secondMean < 0.001) {
            return 'stable';
        }

        $changePercent = $firstMean > 0.001
            ? (($secondMean - $firstMean) / $firstMean) * 100
            : ($secondMean > 0 ? 100.0 : 0.0);

        return $changePercent > 10 ? 'rising' : ($changePercent < -10 ? 'falling' : 'stable');
    }

    /**
     * Format a number for display.
     */
    private function formatNum(float $num): string
    {
        return $num >= 1000 ? number_format($num, 0) : number_format($num, 1);
    }

    // ── Cache Key Builders ──────────────────────────────────────────────

    private function globalKey(): string
    {
        return self::CACHE_PREFIX . 'global';
    }

    private function categoryKey(string $category): string
    {
        return self::CACHE_PREFIX . 'cat_' . $category;
    }

    private function eventKey(string $eventName): string
    {
        return self::CACHE_PREFIX . 'evt_' . str_replace(['.', ' ', '-'], '_', $eventName);
    }
}

/**
 * Immutable record of a detected anomaly.
 *
 * @since 214.0.0
 */
final readonly class AnomalyRecord
{
    /**
     * @param  string  $scope  Detection scope (e.g. 'global', 'category:ecommerce', 'event:purchase')
     * @param  'spike'|'drop'  $type  Type of anomaly
     * @param  'low'|'medium'|'high'|'critical'  $severity  Severity level
     * @param  float  $zScore  Absolute Z-score value
     * @param  int  $current  Current bucket event count
     * @param  float  $expected  Expected (mean) event count
     * @param  \DateTimeImmutable  $timestamp  Detection timestamp
     * @param  string  $message  Human-readable description
     */
    public function __construct(
        public string $scope,
        public string $type,
        public string $severity,
        public float $zScore,
        public int $current,
        public float $expected,
        public \DateTimeImmutable $timestamp,
        public string $message,
    ): void {}

    /**
     * Convert to array representation.
     *
     * @return array{scope: string, type: string, severity: string, z_score: float, current: int, expected: float, timestamp: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'type' => $this->type,
            'severity' => $this->severity,
            'z_score' => round($this->zScore, 2),
            'current' => $this->current,
            'expected' => round($this->expected, 2),
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'message' => $this->message,
        ];
    }
}

/**
 * Immutable snapshot of a sliding window dimension.
 *
 * @since 214.0.0
 */
final readonly class WindowSnapshot
{
    /**
     * @param  string  $scope  Dimension scope
     * @param  array<string, int>  $buckets  Time bucket → count mapping
     * @param  int  $current  Current (latest) bucket value
     * @param  float  $mean  Mean value across the window
     * @param  float  $stdDev  Standard deviation across the window
     * @param  int  $min  Minimum bucket value in the window
     * @param  int  $max  Maximum bucket value in the window
     * @param  'rising'|'falling'|'stable'  $trend  Trend direction
     * @param  int  $bucketCount  Number of buckets in the window
     */
    public function __construct(
        public string $scope,
        public array $buckets,
        public int $current,
        public float $mean,
        public float $stdDev,
        public int $min,
        public int $max,
        public string $trend,
        public int $bucketCount,
    ): void {}

    /**
     * Convert to array representation.
     *
     * @return array{scope: string, current: int, mean: float, std_dev: float, min: int, max: int, trend: string, bucket_count: int}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'current' => $this->current,
            'mean' => round($this->mean, 2),
            'std_dev' => round($this->stdDev, 2),
            'min' => $this->min,
            'max' => $this->max,
            'trend' => $this->trend,
            'bucket_count' => $this->bucketCount,
        ];
    }
}
