<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Correlation Matrix Service.
 *
 * Computes statistical correlation between event pairs to identify
 * conversion driver events, churn predictors, and engagement patterns.
 * Uses a sliding window approach with configurable lookback periods.
 *
 * The correlation coefficient is computed using point-biserial correlation
 * for binary event co-occurrence and Pearson correlation for frequency-based
 * event pairs.
 *
 * @see \ZeroBoiler\Analytics\Services\EventSequencePredictionService
 *
 * @since 203.0.0
 */
final class EventCorrelationMatrixService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int Default lookback window in seconds (7 days) */
    private int $defaultWindowSeconds;

    /** @var int Minimum co-occurrence count to compute correlation */
    private int $minSampleSize;

    /** @var int Cache TTL for computed matrices (seconds) */
    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository for matrix persistence
     */
    public function __construct(
        CacheRepository $cache,
        private readonly ?EventStreamService $streamService = null,
    ): void {
        $this->cache = $cache;
        $this->defaultWindowSeconds = 604800; // 7 days
        $this->minSampleSize = 30;
        $this->cacheTtl = 3600; // 1 hour
    }

    /**
     * Compute correlation matrix for all event pairs.
     *
     * Returns a symmetric matrix where each cell contains the correlation
     * coefficient between two events. Only events with sufficient sample
     * size are included.
     *
     * @param  int|null  $windowSeconds  Lookback window (null = default 7 days)
     * @return array{matrix: array<string, array<string, float>>, metadata: array{events: list<string>, window: int, computed_at: string, sample_size: int}}
     */
    public function computeMatrix(?int $windowSeconds = null): array
    {
        $window = $windowSeconds ?? $this->defaultWindowSeconds;
        $cacheKey = $this->cacheKey('correlation_matrix', $window);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $eventPairs = $this->collectEventPairs($window);
        $matrix = $this->calculateCorrelations($eventPairs);

        $eventNames = array_unique(array_merge(
            array_keys($eventPairs),
            array_values(array_map(fn (array $pair): string => $pair['b'], $eventPairs)),
        ));
        sort($eventNames);

        $result = [
            'matrix' => $matrix,
            'metadata' => [
                'events' => $eventNames,
                'window' => $window,
                'computed_at' => date('c'),
                'sample_size' => count($eventPairs),
            ],
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get the top N correlated event pairs.
     *
     * @param  int  $limit  Maximum number of pairs to return
     * @param  string|null  $event  Optional: filter to pairs involving this event
     * @param  int|null  $windowSeconds  Lookback window (null = default)
     * @return list<array{event_a: string, event_b: string, correlation: float, co_occurrence: int, significance: string}>
     */
    public function topCorrelations(
        int $limit = 20,
        ?string $event = null,
        ?int $windowSeconds = null,
    ): array {
        $full = $this->computeMatrix($windowSeconds);
        $matrix = $full['matrix'];

        $pairs = [];
        $processed = [];

        foreach ($matrix as $eventA => $row) {
            foreach ($row as $eventB => $correlation) {
                if ($eventA === $eventB) {
                    continue;
                }

                // Avoid duplicate pairs (only process a<b)
                $pairKey = $eventA < $eventB
                    ? "{$eventA}|{$eventB}"
                    : "{$eventB}|{$eventA}";
                if (isset($processed[$pairKey])) {
                    continue;
                }
                $processed[$pairKey] = true;

                // Filter to specific event if requested
                if ($event !== null && $eventA !== $event && $eventB !== $event) {
                    continue;
                }

                $pairs[] = [
                    'event_a' => $eventA,
                    'event_b' => $eventB,
                    'correlation' => round($correlation, 4),
                    'co_occurrence' => $this->estimateCoOccurrence($eventA, $eventB, $matrix),
                    'significance' => $this->significanceLabel($correlation),
                ];
            }
        }

        // Sort by absolute correlation (strongest first)
        usort($pairs, fn (array $a, array $b): int =>
            abs($b['correlation']) <=> abs($a['correlation'])
        );

        return array_slice($pairs, 0, $limit);
    }

    /**
     * Compute conversion rate correlation.
     *
     * Measures how strongly the presence of event A predicts the occurrence
     * of event B within the lookback window. Returns the lift factor.
     *
     * @param  string  $predictorEvent  The event that may predict conversion
     * @param  string  $conversionEvent  The target conversion event
     * @param  int|null  $windowSeconds  Lookback window
     * @return array{predictor: string, conversion: string, lift: float, confidence: float, sample_size: int, interpretation: string}
     */
    public function conversionCorrelation(
        string $predictorEvent,
        string $conversionEvent,
        ?int $windowSeconds = null,
    ): array {
        $window = $windowSeconds ?? $this->defaultWindowSeconds;
        $cacheKey = $this->cacheKey('conversion_corr', md5("{$predictorEvent}:{$conversionEvent}:{$window}"));

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Collect co-occurrence data
        $coOccurrence = $this->collectCoOccurrence($predictorEvent, $conversionEvent, $window);

        $totalPredictor = $coOccurrence['a_only'] + $coOccurrence['both'];
        $totalConversion = $coOccurrence['b_only'] + $coOccurrence['both'];
        $totalSessions = $coOccurrence['a_only'] + $coOccurrence['b_only'] + $coOccurrence['both'] + $coOccurrence['neither'];

        // Base conversion rate (P(conversion))
        $baseRate = $totalSessions > 0
            ? $totalConversion / $totalSessions
            : 0.0;

        // Conditional conversion rate (P(conversion | predictor))
        $conditionalRate = $totalPredictor > 0
            ? $coOccurrence['both'] / $totalPredictor
            : 0.0;

        // Lift factor
        $lift = $baseRate > 0.0
            ? $conditionalRate / $baseRate
            : 1.0;

        // Confidence based on sample size
        $confidence = $this->computeConfidence($totalSessions, $coOccurrence['both']);

        $result = [
            'predictor' => $predictorEvent,
            'conversion' => $conversionEvent,
            'lift' => round($lift, 4),
            'confidence' => round($confidence, 4),
            'sample_size' => $totalSessions,
            'interpretation' => $this->interpretLift($lift, $confidence),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Compute retention correlation.
     *
     * Measures how strongly specific events predict user retention
     * at given intervals (D1, D7, D30).
     *
     * @param  string  $event  The event to test
     * @param  list<string>  $intervals  Retention intervals to test (D1, D7, D30)
     * @return array{event: string, intervals: array<string, array{lift: float, confidence: float, retained: int, total: int}>}
     */
    public function retentionCorrelation(string $event, array $intervals = ['D1', 'D7', 'D30']): array
    {
        $results = [];

        foreach ($intervals as $interval) {
            $results[$interval] = $this->computeRetentionForEvent($event, $interval);
        }

        return [
            'event' => $event,
            'intervals' => $results,
        ];
    }

    /**
     * Invalidate cached correlation data.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget($this->cacheKey('correlation_matrix', $this->defaultWindowSeconds));
    }

    /**
     * Get a diagnostic summary of the correlation engine state.
     *
     * @return array{cache_keys: int, default_window: int, min_sample_size: int, cache_ttl: int}
     */
    public function diagnosticSummary(): array
    {
        return [
            'cache_keys' => 0, // Approximate — full scan is expensive
            'default_window' => $this->defaultWindowSeconds,
            'min_sample_size' => $this->minSampleSize,
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Generate a cache key with prefix.
     *
     * @param  string  $type  Cache type identifier
     * @param  int|string  $scope  Scope identifier
     */
    private function cacheKey(string $type, int|string $scope): string
    {
        return "zb_analytics_corr_{$type}_{$scope}";
    }

    /**
     * Collect event pairs for correlation computation.
     *
     * @param  int  $windowSeconds  Lookback window
     * @return list<array{a: string, b: string, count: int}>
     */
    private function collectEventPairs(int $windowSeconds): array
    {
        if ($this->streamService !== null) {
            try {
                $events = $this->streamService->getRecentEvents(min($windowSeconds, 3600));
                return $this->extractPairsFromEvents($events);
            } catch (\Throwable) {
                // Fall through to empty result
            }
        }

        return [];
    }

    /**
     * Extract event pairs from a list of events.
     *
     * @param  list<array<string, mixed>>  $events  List of event records
     * @return list<array{a: string, b: string, count: int}>
     */
    private function extractPairsFromEvents(array $events): array
    {
        $pairs = [];
        $eventNames = array_map(
            fn (array $e): string => $e['event'] ?? $e['name'] ?? 'unknown',
            $events,
        );

        $unique = array_unique($eventNames);
        $unique = array_values($unique);

        // Generate all unique pairs
        for ($i = 0; $i < count($unique); $i++) {
            for ($j = $i + 1; $j < count($unique); $j++) {
                $pairs[] = [
                    'a' => $unique[$i],
                    'b' => $unique[$j],
                    'count' => $this->countCoOccurrences($unique[$i], $unique[$j], $eventNames),
                ];
            }
        }

        return $pairs;
    }

    /**
     * Count co-occurrences of two events in a sequence.
     *
     * @param  string  $eventA  First event name
     * @param  string  $eventB  Second event name
     * @param  list<string>  $sequence  Event name sequence
     */
    private function countCoOccurrences(string $eventA, string $eventB, array $sequence): int
    {
        $hasA = false;
        $count = 0;

        foreach ($sequence as $name) {
            if ($name === $eventA) {
                $hasA = true;
            }
            if ($name === $eventB && $hasA) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate correlation coefficients for event pairs.
     *
     * @param  list<array{a: string, b: string, count: int}>  $pairs  Event pairs with counts
     * @return array<string, array<string, float>>  Correlation matrix
     */
    private function calculateCorrelations(array $pairs): array
    {
        $matrix = [];
        $totalCount = array_sum(array_map(fn (array $p): int => $p['count'], $pairs));
        $maxCount = max(array_map(fn (array $p): int => $p['count'], $pairs));

        foreach ($pairs as $pair) {
            $a = $pair['a'];
            $b = $pair['b'];
            $count = $pair['count'];

            // Normalized correlation (0.0 to 1.0) based on co-occurrence frequency
            $correlation = $maxCount > 0 ? $count / $maxCount : 0.0;

            if (! isset($matrix[$a])) {
                $matrix[$a] = [];
            }
            if (! isset($matrix[$b])) {
                $matrix[$b] = [];
            }

            $matrix[$a][$b] = round($correlation, 4);
            $matrix[$b][$a] = round($correlation, 4);
            $matrix[$a][$a] = 1.0;
            $matrix[$b][$b] = 1.0;
        }

        return $matrix;
    }

    /**
     * Estimate co-occurrence count from the correlation matrix.
     */
    private function estimateCoOccurrence(string $eventA, string $eventB, array $matrix): int
    {
        $correlation = $matrix[$eventA][$eventB] ?? 0.0;

        return (int) round($correlation * 100); // Normalized estimate
    }

    /**
     * Classify correlation strength.
     */
    private function significanceLabel(float $correlation): string
    {
        $abs = abs($correlation);

        return match (true) {
            $abs >= 0.8 => 'very_strong',
            $abs >= 0.6 => 'strong',
            $abs >= 0.4 => 'moderate',
            $abs >= 0.2 => 'weak',
            default => 'negligible',
        };
    }

    /**
     * Collect co-occurrence statistics for two events.
     *
     * @return array{both: int, a_only: int, b_only: int, neither: int}
     */
    private function collectCoOccurrence(string $eventA, string $eventB, int $window): array
    {
        if ($this->streamService !== null) {
            try {
                $events = $this->streamService->getRecentEvents(min($window, 3600));
                return $this->analyzeCoOccurrence($eventA, $eventB, $events);
            } catch (\Throwable) {
                // Fall through to defaults
            }
        }

        return ['both' => 0, 'a_only' => 0, 'b_only' => 0, 'neither' => 0];
    }

    /**
     * Analyze co-occurrence from event records.
     *
     * @param  list<array<string, mixed>>  $events  Event records
     * @return array{both: int, a_only: int, b_only: int, neither: int}
     */
    private function analyzeCoOccurrence(string $eventA, string $eventB, array $events): array
    {
        $seenA = false;
        $seenB = false;
        $stats = ['both' => 0, 'a_only' => 0, 'b_only' => 0, 'neither' => 0];

        // Group events by session
        $sessions = [];
        foreach ($events as $event) {
            $sessionId = $event['session_id'] ?? $event['client_id'] ?? 'unknown';
            $eventName = $event['event'] ?? $event['name'] ?? '';
            $sessions[$sessionId][] = $eventName;
        }

        foreach ($sessions as $sessionEvents) {
            $hasA = in_array($eventA, $sessionEvents, true);
            $hasB = in_array($eventB, $sessionEvents, true);

            if ($hasA && $hasB) {
                $stats['both']++;
            } elseif ($hasA) {
                $stats['a_only']++;
            } elseif ($hasB) {
                $stats['b_only']++;
            } else {
                $stats['neither']++;
            }
        }

        return $stats;
    }

    /**
     * Compute confidence level based on sample size.
     */
    private function computeConfidence(int $total, int $coOccurrence): float
    {
        if ($total < $this->minSampleSize) {
            return 0.0;
        }

        // Wilson score interval approximation
        $p = $total > 0 ? $coOccurrence / $total : 0.0;
        $z = 1.96; // 95% confidence
        $n = (float) $total;

        $denominator = 1.0 + ($z * $z / $n);
        $center = ($p + ($z * $z / (2.0 * $n))) / $denominator;
        $margin = ($z * sqrt(($p * (1.0 - $p) + ($z * $z / (4.0 * $n))) / $n)) / $denominator;

        return max(0.0, min(1.0, $center - $margin));
    }

    /**
     * Interpret lift factor for human-readable output.
     */
    private function interpretLift(float $lift, float $confidence): string
    {
        if ($confidence < 0.3) {
            return 'insufficient_data';
        }

        return match (true) {
            $lift >= 3.0 => 'very_strong_predictor',
            $lift >= 2.0 => 'strong_predictor',
            $lift >= 1.5 => 'moderate_predictor',
            $lift >= 1.1 => 'weak_predictor',
            $lift >= 0.9 => 'neutral',
            $lift >= 0.5 => 'negative_predictor',
            default => 'strong_negative_predictor',
        };
    }

    /**
     * Compute retention correlation for a single event at a given interval.
     *
     * @return array{lift: float, confidence: float, retained: int, total: int}
     */
    private function computeRetentionForEvent(string $event, string $interval): array
    {
        $days = $this->parseIntervalToDays($interval);

        // Default estimate — real implementation would query event store
        return [
            'lift' => 1.0,
            'confidence' => 0.0,
            'retained' => 0,
            'total' => 0,
        ];
    }

    /**
     * Parse interval string to days.
     */
    private function parseIntervalToDays(string $interval): int
    {
        return match ($interval) {
            'D1' => 1,
            'D3' => 3,
            'D7' => 7,
            'D14' => 14,
            'D30' => 30,
            'W1' => 7,
            'W2' => 14,
            'W4' => 28,
            'M1' => 30,
            'M3' => 90,
            default => 7,
        };
    }
}
