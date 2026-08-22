<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Store\EventStoreManager;

/**
 * Event Correlation Analyzer — Time-lagged correlation analysis between events.
 *
 * Measures how strongly two events are correlated across time, including
 * forward and backward lag analysis. Helps identify causal patterns like
 * "users who do X are likely to do Y within N hours."
 *
 * This is a v2 upgrade over the basic EventCorrelationService:
 * - Time-lagged Pearson correlation (configurable lag windows)
 * - Cross-correlation function (CCF) for multiple lag offsets
 * - Event sequence pattern detection (A→B transitions)
 * - Temporal funnel analysis (conversion within time windows)
 * - Lead-lag relationship identification
 *
 * @since 60.0.0
 */
final class EventCorrelationAnalyzerService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var ConfigRepository */
    private ConfigRepository $config;

    /** @var EventStoreManager|null */
    private ?EventStoreManager $store;

    /** @var int Default cache TTL in seconds (10 minutes) */
    private const CACHE_TTL = 600;

    /** @var int Maximum lag steps to compute */
    private const MAX_LAG_STEPS = 24;

    /** @var list<int> Default lag offsets in hours */
    private const DEFAULT_LAG_OFFSETS = [0, 1, 2, 4, 8, 12, 24, 48, 72];

    /** @var float Minimum Pearson correlation threshold for significance */
    private const CORRELATION_THRESHOLD = 0.3;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  EventStoreManager|null  $store
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        ?EventStoreManager $store = null,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->store = $store;
    }

    /**
     * Compute time-lagged Pearson correlation between two events.
     *
     * Returns correlation coefficients for each lag offset, showing how
     * the relationship between events A and B changes over time.
     *
     * A positive correlation at lag=4h means "event A predicts event B 4 hours later."
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     * @param  string  $period  Analysis period (7d, 14d, 30d, 90d)
     * @param  list<int>|null  $lagOffsets  Lag offsets in hours (null = defaults)
     * @return array{event_a: string, event_b: string, ccf: list<array{lag_hours: int, correlation: float, significance: string, sample_size: int}>, peak_lag: array{lag_hours: int, correlation: float, direction: string}, period: string}
     */
    public function crossCorrelation(
        string $eventA,
        string $eventB,
        string $period = '30d',
        ?array $lagOffsets = null,
    ): array {
        $timeRange = $this->parsePeriod($period);
        $lagOffsets = $lagOffsets ?? self::DEFAULT_LAG_OFFSETS;
        $lagOffsets = array_slice($lagOffsets, 0, self::MAX_LAG_STEPS);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $seriesA = $this->getEventTimeSeries($eventA, $timeRange, 'hour');
        $seriesB = $this->getEventTimeSeries($eventB, $timeRange, 'hour');

        $ccf = [];
        $peakCorrelation = -INF;
        $peakLag = 0;
        $peakDirection = 'none';

        foreach ($lagOffsets as $lagHours) {
            // Shift series A forward by lagHours (event A → event B)
            $shiftedA = $this->shiftSeries($seriesA, $lagHours);
            $correlation = $this->pearsonCorrelation($shiftedA, $seriesB);
            $sampleSize = count(array_filter($shiftedA, static fn (?float $v): bool => $v !== null));

            $significance = $this->classifySignificance($correlation, $sampleSize);

            $ccf[] = [
                'lag_hours' => $lagHours,
                'correlation' => round($correlation, 4),
                'significance' => $significance,
                'sample_size' => $sampleSize,
            ];

            // Track peak correlation
            if (abs($correlation) > abs($peakCorrelation)) {
                $peakCorrelation = $correlation;
                $peakLag = $lagHours;
                $peakDirection = $correlation > 0 ? 'positive' : 'negative';
            }
        }

        $response = [
            'event_a' => $eventA,
            'event_b' => $eventB,
            'ccf' => $ccf,
            'peak_lag' => [
                'lag_hours' => $peakLag,
                'correlation' => round($peakCorrelation, 4),
                'direction' => $peakDirection,
            ],
            'period' => $period,
        ];

        $this->cache->put($cacheKey, $response, self::CACHE_TTL);

        return $response;
    }

    /**
     * Analyze event transition patterns (A→B sequences).
     *
     * Measures how often event B occurs after event A within a specified
     * time window, compared to the baseline rate of event B.
     *
     * @param  string  $eventA  Trigger event
     * @param  string  $eventB  Outcome event
     * @param  string  $period  Analysis period
     * @param  int  $windowHours  Time window in hours (default: 24)
     * @param  int  $limit  Maximum events to analyze
     * @return array{transitions: array{total_a: int, a_then_b: int, conversion_rate: float, lift: float, baseline_rate: float}, window_hours: int, period: string, confidence: string}
     */
    public function transitionAnalysis(
        string $eventA,
        string $eventB,
        string $period = '30d',
        int $windowHours = 24,
        int $limit = 10000,
    ): array {
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $totalA = $this->getEventCountInPeriod($eventA, $timeRange);
        $totalB = $this->getEventCountInPeriod($eventB, $timeRange);
        $baselineRate = $totalA > 0 ? $totalB / max($totalA, 1) : 0.0;

        $transitions = $this->getTransitions($eventA, $eventB, $timeRange, $windowHours, $limit);
        $aThenB = $transitions['count'] ?? 0;
        $conversionRate = $totalA > 0 ? round(($aThenB / $totalA) * 100, 2) : 0.0;
        $lift = $baselineRate > 0 ? round($aThenB / ($totalA * $baselineRate), 2) : 0.0;

        // Confidence based on sample size
        $confidence = $totalA < 30 ? 'low' : ($totalA < 100 ? 'medium' : 'high');

        $response = [
            'transitions' => [
                'total_a' => $totalA,
                'a_then_b' => $aThenB,
                'conversion_rate' => $conversionRate,
                'lift' => $lift,
                'baseline_rate' => round($baselineRate, 6),
            ],
            'window_hours' => $windowHours,
            'period' => $period,
            'confidence' => $confidence,
        ];

        $this->cache->put($cacheKey, $response, self::CACHE_TTL);

        return $response;
    }

    /**
     * Compute multi-event correlation matrix with time lag.
     *
     * Returns pairwise lagged correlations for a set of events.
     *
     * @param  list<string>  $events  List of event names
     * @param  string  $period  Analysis period
     * @param  int  $lagHours  Default lag offset for matrix
     * @return array{events: list<string>, matrix: array<string, array<string, float>>, lag_hours: int, period: string}
     */
    public function correlationMatrix(
        array $events = [],
        string $period = '30d',
        int $lagHours = 0,
    ): array {
        $events = array_values(array_filter($events, static fn (string $e): bool => $e !== ''));
        $timeRange = $this->parsePeriod($period);
        $cacheKey = $this->buildCacheKey(__FUNCTION__, func_get_args());

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $seriesMap = [];
        foreach ($events as $event) {
            $seriesMap[$event] = $this->getEventTimeSeries($event, $timeRange, 'hour');
        }

        $matrix = [];
        foreach ($events as $eventA) {
            $matrix[$eventA] = [];
            foreach ($events as $eventB) {
                if ($lagHours === 0) {
                    $correlation = $this->pearsonCorrelation($seriesMap[$eventA], $seriesMap[$eventB]);
                } else {
                    $shiftedA = $this->shiftSeries($seriesMap[$eventA], $lagHours);
                    $correlation = $this->pearsonCorrelation($shiftedA, $seriesMap[$eventB]);
                }

                $matrix[$eventA][$eventB] = round($correlation, 4);
            }
        }

        $response = [
            'events' => $events,
            'matrix' => $matrix,
            'lag_hours' => $lagHours,
            'period' => $period,
        ];

        $this->cache->put($cacheKey, $response, self::CACHE_TTL);

        return $response;
    }

    /**
     * Get service health status.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'store_available' => $this->store !== null,
            'cache_ttl' => self::CACHE_TTL,
            'max_lag_steps' => self::MAX_LAG_STEPS,
            'default_lag_offsets' => self::DEFAULT_LAG_OFFSETS,
            'correlation_threshold' => self::CORRELATION_THRESHOLD,
        ];
    }

    /**
     * Compute Pearson correlation coefficient between two numeric arrays.
     *
     * @param  array<int, float|null>  $x
     * @param  array<int, float|null>  $y
     * @return float  Correlation coefficient between -1 and 1
     */
    private function pearsonCorrelation(array $x, array $y): float
    {
        // Align to common length
        $n = min(count($x), count($y));
        if ($n < 2) {
            return 0.0;
        }

        $x = array_slice($x, 0, $n);
        $y = array_slice($y, 0, $n);

        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($x[$i] !== null && $y[$i] !== null) {
                $pairs[] = [$x[$i], $y[$i]];
            }
        }

        $n = count($pairs);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXy = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        foreach ($pairs as [$xi, $yi]) {
            $sumX += $xi;
            $sumY += $yi;
            $sumXy += $xi * $yi;
            $sumX2 += $xi * $xi;
            $sumY2 += $yi * $yi;
        }

        $numerator = $n * $sumXy - $sumX * $sumY;
        $denominator = sqrt(
            ($n * $sumX2 - $sumX * $sumX) * ($n * $sumY2 - $sumY * $sumY),
        );

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Shift a time series by N hours (introduce N null values at the start).
     *
     * @param  array<int, float|null>  $series
     * @param  int  $lagHours
     * @return array<int, float|null>
     */
    private function shiftSeries(array $series, int $lagHours): array
    {
        if ($lagHours <= 0) {
            return $series;
        }

        return array_merge(
            array_fill(0, $lagHours, null),
            array_slice($series, 0, count($series) - $lagHours),
        );
    }

    /**
     * Classify correlation significance based on value and sample size.
     *
     * @param  float  $correlation
     * @param  int  $sampleSize
     * @return string  'strong', 'moderate', 'weak', 'none'
     */
    private function classifySignificance(float $correlation, int $sampleSize): string
    {
        $absCorr = abs($correlation);

        // Require minimum sample size for any significance
        if ($sampleSize < 10) {
            return 'insufficient_data';
        }

        if ($absCorr >= 0.7) {
            return 'strong';
        }

        if ($absCorr >= self::CORRELATION_THRESHOLD) {
            return 'moderate';
        }

        if ($absCorr >= 0.1) {
            return 'weak';
        }

        return 'none';
    }

    /**
     * Get event time series data.
     *
     * @param  string  $eventName
     * @param  array{from: string, to: string}  $timeRange
     * @param  string  $granularity
     * @return array<int, float|null>
     */
    private function getEventTimeSeries(string $eventName, array $timeRange, string $granularity = 'hour'): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            $results = $this->store->query([
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_name' => $eventName,
                'analysis' => 'time_distribution',
                'granularity' => $granularity,
            ]);

            /** @var list<array{count: int}> $results */
            return array_map(static fn (array $r): ?float => $r['count'] ?? null, $results);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get event count in a period.
     *
     * @param  string  $eventName
     * @param  array{from: string, to: string}  $timeRange
     * @return int
     */
    private function getEventCountInPeriod(string $eventName, array $timeRange): int
    {
        if ($this->store === null) {
            return 0;
        }

        try {
            $results = $this->store->query([
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_name' => $eventName,
                'analysis' => 'count',
            ]);

            return is_array($results) && isset($results[0]['count'])
                ? (int) $results[0]['count']
                : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get A→B transition counts within a time window.
     *
     * @param  string  $eventA
     * @param  string  $eventB
     * @param  array{from: string, to: string}  $timeRange
     * @param  int  $windowHours
     * @param  int  $limit
     * @return array{count: int}
     */
    private function getTransitions(
        string $eventA,
        string $eventB,
        array $timeRange,
        int $windowHours,
        int $limit,
    ): array {
        if ($this->store === null) {
            return ['count' => 0];
        }

        try {
            $results = $this->store->query([
                'from' => $timeRange['from'],
                'to' => $timeRange['to'],
                'event_a' => $eventA,
                'event_b' => $eventB,
                'analysis' => 'transitions',
                'window_hours' => $windowHours,
                'limit' => $limit,
            ]);

            /** @var array{count: int} $results */
            return $results;
        } catch (\Throwable $e) {
            return ['count' => 0];
        }
    }

    /**
     * Parse a period string into from/to timestamps.
     *
     * @param  string  $period
     * @return array{from: string, to: string}
     */
    private function parsePeriod(string $period): array
    {
        $now = now();
        $to = $now->toDateTimeString();

        $matches = [];
        if (preg_match('/^(\d+)(h|d)$/', $period, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            $from = $unit === 'h'
                ? $now->copy()->subHours($value)->toDateTimeString()
                : $now->copy()->subDays($value)->toDateTimeString();
        } else {
            $from = $now->copy()->subDays(30)->toDateTimeString();
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Build a deterministic cache key.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $args
     * @return string
     */
    private function buildCacheKey(string $method, array $args): string
    {
        $hash = hash('xxh128', json_encode([$method, $args], JSON_THROW_ON_ERROR));

        return "zb_correlation_analyzer:{$method}:{$hash}";
    }
}
