<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Per-event health scoring engine for SaaS analytics observability.
 *
 * Computes a composite health score (0-100) for each tracked event across
 * five dimensions:
 *
 *   1. **Freshness** — How recently was this event last seen? Stale events
 *      indicate broken integrations, removed UI components, or client bugs.
 *
 *   2. **Volume** — Is the event volume within expected bounds? Sudden drops
 *      suggest regressions; spikes may indicate misconfigurations or abuse.
 *
 *   3. **Schema Compliance** — Does the event payload pass schema validation?
 *      Failing events may have been corrupted by a bad deploy.
 *
 *   4. **Provider Delivery** — Are all enabled providers receiving this event?
 *      Per-provider success/failure rates are tracked per event.
 *
 *   5. **Data Quality** — Are required fields present? Is PII properly
 *      sanitized? Composite quality score from DataQualityScorer.
 *
 * Health scores are cached with a configurable TTL and refreshed on each
 * event dispatch. Degrading events generate health alerts automatically.
 *
 * Configuration: `zeroboiler.analytics.event_health`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsDeployGate
 * @see \ZeroBoiler\Analytics\Services\AnalyticsDataQualityScorer
 *
 * @since 80.0.0
 */
final class EventHealthScoringEngine
{
    /** @var array<string, int> Health score weights per dimension */
    private const DIMENSION_WEIGHTS = [
        'freshness' => 20,
        'volume' => 20,
        'schema' => 25,
        'delivery' => 25,
        'quality' => 10,
    ];

    /** @var int Cache TTL for health scores (seconds) */
    private const CACHE_TTL = 300;

    private const CACHE_PREFIX = 'zb_event_health_';

    private const ALERT_CACHE_PREFIX = 'zb_event_health_alert_';

    private const ALERT_COOLDOWN = 900; // 15 minutes between alerts per event

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    /** @var int Freshness threshold in seconds — events older than this are penalized */
    private int $freshnessThreshold;

    /** @var float Volume drop threshold (0-1) — events dropping below this % of their baseline are flagged */
    private float $volumeDropThreshold;

    /** @var float Volume spike threshold — events exceeding this multiplier of their baseline are flagged */
    private float $volumeSpikeMultiplier;

    /** @var int Minimum events required before volume scoring is activated */
    private int $minVolumeSampleSize;

    /** @var list<array{event: string, dimension: string, severity: string, message: string, timestamp: string}> */
    private array $recentAlerts;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $healthConfig = $config->get('zeroboiler.analytics.event_health', []);
        /** @var array{enabled?: bool, freshness_threshold?: int, volume_drop_threshold?: float, volume_spike_multiplier?: float, min_volume_sample?: int} $healthConfig */
        $this->enabled = (bool) ($healthConfig['enabled'] ?? true);
        $this->freshnessThreshold = (int) ($healthConfig['freshness_threshold'] ?? 3600);
        $this->volumeDropThreshold = (float) ($healthConfig['volume_drop_threshold'] ?? 0.3);
        $this->volumeSpikeMultiplier = (float) ($healthConfig['volume_spike_multiplier'] ?? 5.0);
        $this->minVolumeSampleSize = (int) ($healthConfig['min_volume_sample'] ?? 10);
        $this->recentAlerts = [];
    }

    /**
     * Record an event dispatch for health tracking.
     *
     * Called by AnalyticsManager after each event is dispatched.
     * Updates freshness timestamp, volume counter, and provider delivery stats.
     *
     * @param  string  $eventName  The event name
     * @param  bool  $schemaValid  Whether the event passed schema validation
     * @param  array<string, bool>  $providerResults  Map of provider name → dispatch success
     * @param  int  $qualityScore  Data quality score (0-100)
     * @return void
     */
    public function recordDispatch(
        string $eventName,
        bool $schemaValid,
        array $providerResults,
        int $qualityScore,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $now = time();
        $cacheKey = self::CACHE_PREFIX . $eventName;

        /** @var array{last_seen: int, count: int, schema_valid: int, schema_total: int, provider_success: array<string, int>, provider_total: array<string, int>, quality_scores: list<int>, volume_buckets: array<string, int>}|null $stats */
        $stats = $this->cache->get($cacheKey);

        if ($stats === null) {
            $stats = $this->emptyStats();
        }

        $stats['last_seen'] = $now;
        $stats['count']++;

        // Schema compliance tracking
        $stats['schema_total']++;
        if ($schemaValid) {
            $stats['schema_valid']++;
        }

        // Per-provider delivery tracking
        foreach ($providerResults as $provider => $success) {
            $provider = strtolower($provider);
            if (! isset($stats['provider_success'][$provider])) {
                $stats['provider_success'][$provider] = 0;
                $stats['provider_total'][$provider] = 0;
            }
            $stats['provider_total'][$provider]++;
            if ($success) {
                $stats['provider_success'][$provider]++;
            }
        }

        // Quality score tracking (keep last 100)
        $stats['quality_scores'][] = $qualityScore;
        if (count($stats['quality_scores']) > 100) {
            array_shift($stats['quality_scores']);
        }

        // Volume bucketing (hourly)
        $bucketKey = gmdate('Y-m-d-H', $now);
        $stats['volume_buckets'][$bucketKey] = ($stats['volume_buckets'][$bucketKey] ?? 0) + 1;

        // Keep only last 24 buckets
        $buckets = $stats['volume_buckets'];
        if (count($buckets) > 24) {
            $buckets = array_slice($buckets, -24, null, true);
            $stats['volume_buckets'] = $buckets;
        }

        $this->cache->put($cacheKey, $stats, self::CACHE_TTL);

        // Check for health degradation
        $this->checkForAlerts($eventName, $stats);
    }

    /**
     * Get the composite health score for a specific event.
     *
     * @param  string  $eventName
     * @return array{score: int, grade: string, dimensions: array<string, array{score: int, max: int, pct: float, status: string}>, last_seen: int|null, recommendations: list<string>}
     */
    public function scoreEvent(string $eventName): array
    {
        $cacheKey = self::CACHE_PREFIX . $eventName;

        /** @var array{last_seen: int, count: int, schema_valid: int, schema_total: int, provider_success: array<string, int>, provider_total: array<string, int>, quality_scores: list<int>, volume_buckets: array<string, int>}|null $stats */
        $stats = $this->cache->get($cacheKey);

        if ($stats === null) {
            return [
                'score' => 0,
                'grade' => 'N/A',
                'dimensions' => [],
                'last_seen' => null,
                'recommendations' => ['No data recorded for this event'],
            ];
        }

        $dimensions = [
            'freshness' => $this->scoreFreshness($stats),
            'volume' => $this->scoreVolume($stats),
            'schema' => $this->scoreSchema($stats),
            'delivery' => $this->scoreDelivery($stats),
            'quality' => $this->scoreQuality($stats),
        ];

        $compositeScore = $this->computeCompositeScore($dimensions);
        $grade = $this->scoreToGrade($compositeScore);
        $recommendations = $this->generateRecommendations($eventName, $dimensions);

        return [
            'score' => $compositeScore,
            'grade' => $grade,
            'dimensions' => $dimensions,
            'last_seen' => $stats['last_seen'],
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Get health scores for all tracked events.
     *
     * @return array<string, array{score: int, grade: string, last_seen: int|null}>
     */
    public function scoreAllEvents(): array
    {
        $results = [];
        $eventNames = $this->getTrackedEventNames();

        foreach ($eventNames as $eventName) {
            $score = $this->scoreEvent($eventName);
            $results[$eventName] = [
                'score' => $score['score'],
                'grade' => $score['grade'],
                'last_seen' => $score['last_seen'],
            ];
        }

        return $results;
    }

    /**
     * Get only events with degrading health (score below threshold).
     *
     * @param  int  $threshold  Health score threshold (0-100)
     * @return array<string, array{score: int, grade: string, dimensions: array<string, array{score: int, max: int, pct: float, status: string}>, recommendations: list<string>}>
     */
    public function getDegradingEvents(int $threshold = 60): array
    {
        $degrading = [];
        $eventNames = $this->getTrackedEventNames();

        foreach ($eventNames as $eventName) {
            $score = $this->scoreEvent($eventName);
            if ($score['score'] > 0 && $score['score'] < $threshold) {
                $degrading[$eventName] = $score;
            }
        }

        return $degrading;
    }

    /**
     * Get the overall system health score across all events.
     *
     * @return array{score: int, grade: string, total_events: int, healthy: int, degrading: int, unknown: int, critical_events: list<string>}
     */
    public function systemHealth(): array
    {
        $scores = $this->scoreAllEvents();
        $total = count($scores);

        if ($total === 0) {
            return [
                'score' => 0,
                'grade' => 'N/A',
                'total_events' => 0,
                'healthy' => 0,
                'degrading' => 0,
                'unknown' => 0,
                'critical_events' => [],
            ];
        }

        $totalScore = 0;
        $healthy = 0;
        $degrading = 0;
        $unknown = 0;
        $critical = [];

        foreach ($scores as $eventName => $data) {
            $s = $data['score'];

            if ($s === 0) {
                $unknown++;
            } elseif ($s >= 70) {
                $healthy++;
                $totalScore += $s;
            } elseif ($s >= 40) {
                $degrading++;
                $totalScore += $s;
            } else {
                $degrading++;
                $totalScore += $s;
                $critical[] = $eventName;
            }
        }

        $avgScore = $total > 0 ? (int) round($totalScore / $total) : 0;

        return [
            'score' => $avgScore,
            'grade' => $this->scoreToGrade($avgScore),
            'total_events' => $total,
            'healthy' => $healthy,
            'degrading' => $degrading,
            'unknown' => $unknown,
            'critical_events' => $critical,
        ];
    }

    /**
     * Clear health stats for a specific event.
     *
     * @param  string  $eventName
     * @return bool
     */
    public function clearEventStats(string $eventName): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . $eventName);
    }

    /**
     * Clear all cached health stats.
     *
     * @return void
     */
    public function clearAllStats(): void
    {
        $eventNames = $this->getTrackedEventNames();
        foreach ($eventNames as $eventName) {
            $this->clearEventStats($eventName);
        }
    }

    /**
     * Get recent health alerts.
     *
     * @return list<array{event: string, dimension: string, severity: string, message: string, timestamp: string}>
     */
    public function getRecentAlerts(): array
    {
        return $this->recentAlerts;
    }

    /**
     * Score the freshness dimension.
     *
     * @param  array{last_seen: int, count: int}  $stats
     * @return array{score: int, max: int, pct: float, status: string}
     */
    private function scoreFreshness(array $stats): array
    {
        $maxScore = self::DIMENSION_WEIGHTS['freshness'];
        $elapsed = time() - $stats['last_seen'];

        if ($elapsed <= 300) {
            $pct = 1.0;
            $status = 'healthy';
        } elseif ($elapsed <= $this->freshnessThreshold) {
            $pct = 1.0 - (($elapsed - 300) / ($this->freshnessThreshold - 300)) * 0.5;
            $status = 'warning';
        } elseif ($elapsed <= $this->freshnessThreshold * 3) {
            $pct = 0.3;
            $status = 'degraded';
        } else {
            $pct = 0.0;
            $status = 'critical';
        }

        return [
            'score' => (int) round($maxScore * $pct),
            'max' => $maxScore,
            'pct' => round($pct * 100, 1),
            'status' => $status,
        ];
    }

    /**
     * Score the volume dimension.
     *
     * Compares current hourly volume to the baseline (average of previous hours).
     *
     * @param  array{volume_buckets: array<string, int>, count: int}  $stats
     * @return array{score: int, max: int, pct: float, status: string}
     */
    private function scoreVolume(array $stats): array
    {
        $maxScore = self::DIMENSION_WEIGHTS['volume'];

        if ($stats['count'] < $this->minVolumeSampleSize) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $buckets = $stats['volume_buckets'];
        $currentBucket = array_pop($buckets);
        $previousBuckets = array_values($buckets);

        if (count($previousBuckets) === 0 || $currentBucket === 0) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $baseline = (float) array_sum($previousBuckets) / count($previousBuckets);

        if ($baseline < 0.001) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $ratio = $currentBucket / $baseline;

        if ($ratio >= 0.7 && $ratio <= $this->volumeSpikeMultiplier) {
            $pct = 1.0;
            $status = 'healthy';
        } elseif ($ratio < 0.7 && $ratio >= $this->volumeDropThreshold) {
            $pct = 0.7;
            $status = 'warning';
        } elseif ($ratio < $this->volumeDropThreshold) {
            $pct = max(0.0, 0.3 * ($ratio / $this->volumeDropThreshold));
            $status = 'critical';
        } elseif ($ratio > $this->volumeSpikeMultiplier) {
            $excess = $ratio / $this->volumeSpikeMultiplier;
            $pct = max(0.3, 1.0 - (0.7 * min(1.0, ($excess - 1.0) / 2.0)));
            $status = 'warning';
        } else {
            $pct = 1.0;
            $status = 'healthy';
        }

        return [
            'score' => (int) round($maxScore * $pct),
            'max' => $maxScore,
            'pct' => round($pct * 100, 1),
            'status' => $status,
        ];
    }

    /**
     * Score the schema compliance dimension.
     *
     * @param  array{schema_valid: int, schema_total: int}  $stats
     * @return array{score: int, max: int, pct: float, status: string}
     */
    private function scoreSchema(array $stats): array
    {
        $maxScore = self::DIMENSION_WEIGHTS['schema'];

        if ($stats['schema_total'] === 0) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $pct = $stats['schema_valid'] / $stats['schema_total'];
        $status = match (true) {
            $pct >= 0.99 => 'healthy',
            $pct >= 0.95 => 'warning',
            $pct >= 0.80 => 'degraded',
            default => 'critical',
        };

        return [
            'score' => (int) round($maxScore * $pct),
            'max' => $maxScore,
            'pct' => round($pct * 100, 1),
            'status' => $status,
        ];
    }

    /**
     * Score the provider delivery dimension.
     *
     * @param  array{provider_success: array<string, int>, provider_total: array<string, int>}  $stats
     * @return array{score: int, max: int, pct: float, status: string}
     */
    private function scoreDelivery(array $stats): array
    {
        $maxScore = self::DIMENSION_WEIGHTS['delivery'];

        if (empty($stats['provider_total'])) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $totalSuccess = 0;
        $totalAttempts = 0;

        foreach ($stats['provider_total'] as $provider => $attempts) {
            $totalAttempts += $attempts;
            $totalSuccess += $stats['provider_success'][$provider] ?? 0;
        }

        if ($totalAttempts === 0) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $pct = $totalSuccess / $totalAttempts;
        $status = match (true) {
            $pct >= 0.99 => 'healthy',
            $pct >= 0.95 => 'warning',
            $pct >= 0.80 => 'degraded',
            default => 'critical',
        };

        return [
            'score' => (int) round($maxScore * $pct),
            'max' => $maxScore,
            'pct' => round($pct * 100, 1),
            'status' => $status,
        ];
    }

    /**
     * Score the data quality dimension.
     *
     * @param  array{quality_scores: list<int>}  $stats
     * @return array{score: int, max: int, pct: float, status: string}
     */
    private function scoreQuality(array $stats): array
    {
        $maxScore = self::DIMENSION_WEIGHTS['quality'];

        if (empty($stats['quality_scores'])) {
            return [
                'score' => $maxScore,
                'max' => $maxScore,
                'pct' => 100.0,
                'status' => 'insufficient_data',
            ];
        }

        $avgQuality = (float) array_sum($stats['quality_scores']) / count($stats['quality_scores']);
        $pct = $avgQuality / 100.0;
        $status = match (true) {
            $pct >= 0.9 => 'healthy',
            $pct >= 0.7 => 'warning',
            $pct >= 0.5 => 'degraded',
            default => 'critical',
        };

        return [
            'score' => (int) round($maxScore * $pct),
            'max' => $maxScore,
            'pct' => round($pct * 100, 1),
            'status' => $status,
        ];
    }

    /**
     * Compute the composite health score from individual dimensions.
     *
     * @param  array<string, array{score: int, max: int}>  $dimensions
     * @return int
     */
    private function computeCompositeScore(array $dimensions): int
    {
        $totalScore = 0;
        $totalMax = 0;

        foreach ($dimensions as $dim => $data) {
            $totalScore += $data['score'];
            $totalMax += $data['max'];
        }

        return $totalMax > 0 ? (int) round(($totalScore / $totalMax) * 100) : 0;
    }

    /**
     * Convert a numeric score to a letter grade.
     *
     * @param  int  $score
     * @return string
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'A-',
            $score >= 80 => 'B+',
            $score >= 70 => 'B',
            $score >= 60 => 'B-',
            $score >= 50 => 'C+',
            $score >= 40 => 'C',
            $score >= 30 => 'C-',
            $score >= 20 => 'D',
            $score > 0 => 'D-',
            default => 'N/A',
        };
    }

    /**
     * Generate actionable recommendations for a scored event.
     *
     * @param  string  $eventName
     * @param  array<string, array{score: int, max: int, status: string}>  $dimensions
     * @return list<string>
     */
    private function generateRecommendations(string $eventName, array $dimensions): array
    {
        $recs = [];

        foreach ($dimensions as $dim => $data) {
            if ($data['status'] === 'insufficient_data') {
                continue;
            }

            $pct = $data['max'] > 0 ? ($data['score'] / $data['max']) * 100 : 0;

            if ($pct < 70) {
                $recs[] = match ($dim) {
                    'freshness' => "Event '{$eventName}' hasn't been seen recently — check if the tracking code is still active",
                    'volume' => "Event '{$eventName}' volume is outside normal range — investigate recent code or traffic changes",
                    'schema' => "Event '{$eventName}' has schema validation failures — review recent payload changes",
                    'delivery' => "Event '{$eventName}' has provider delivery failures — check provider status and credentials",
                    'quality' => "Event '{$eventName}' has low data quality — check required fields and PII handling",
                    default => "Event '{$eventName}' {$dim} score is low — investigate",
                };
            }
        }

        return $recs;
    }

    /**
     * Check for health degradation and generate alerts.
     *
     * @param  string  $eventName
     * @param  array{last_seen: int, count: int, schema_valid: int, schema_total: int, provider_success: array<string, int>, provider_total: array<string, int>, quality_scores: list<int>, volume_buckets: array<string, int>}  $stats
     * @return void
     */
    private function checkForAlerts(string $eventName, array $stats): void
    {
        $alertKey = self::ALERT_CACHE_PREFIX . $eventName;

        // Cooldown check
        if ($this->cache->has($alertKey)) {
            return;
        }

        $elapsed = time() - $stats['last_seen'];
        $freshnessDim = $this->scoreFreshness($stats);
        $volumeDim = $this->scoreVolume($stats);
        $schemaDim = $this->scoreSchema($stats);

        $alerts = [];

        if ($freshnessDim['status'] === 'critical') {
            $alerts[] = [
                'event' => $eventName,
                'dimension' => 'freshness',
                'severity' => 'critical',
                'message' => "Event '{$eventName}' has not been seen for {$elapsed}s — possible broken integration",
                'timestamp' => gmdate('c'),
            ];
        }

        if ($volumeDim['status'] === 'critical' && $stats['count'] >= $this->minVolumeSampleSize) {
            $buckets = $stats['volume_buckets'];
            $current = array_pop($buckets);
            $previous = array_values($buckets);
            $baseline = count($previous) > 0 ? array_sum($previous) / count($previous) : 0;
            $alerts[] = [
                'event' => $eventName,
                'dimension' => 'volume',
                'severity' => 'critical',
                'message' => "Event '{$eventName}' volume dropped to {$current} (baseline: " . (int) $baseline . ') — investigate immediately',
                'timestamp' => gmdate('c'),
            ];
        }

        if ($schemaDim['status'] === 'critical' && $stats['schema_total'] > 5) {
            $rate = ($stats['schema_total'] > 0)
                ? round(($stats['schema_valid'] / $stats['schema_total']) * 100, 1)
                : 0.0;
            $alerts[] = [
                'event' => $eventName,
                'dimension' => 'schema',
                'severity' => 'critical',
                'message' => "Event '{$eventName}' schema validation rate dropped to {$rate}% — recent payload changes may be incompatible",
                'timestamp' => gmdate('c'),
            ];
        }

        foreach ($alerts as $alert) {
            $this->recentAlerts[] = $alert;
            Log::warning('ZeroBoiler Event Health Alert', $alert);
        }

        // Keep only last 50 alerts in memory
        if (count($this->recentAlerts) > 50) {
            $this->recentAlerts = array_slice($this->recentAlerts, -50);
        }

        if (! empty($alerts)) {
            $this->cache->put($alertKey, true, self::ALERT_COOLDOWN);
        }
    }

    /**
     * Get all tracked event names from cache.
     *
     * @return list<string>
     */
    private function getTrackedEventNames(): array
    {
        /** @var list<string>|null $names */
        $names = $this->cache->get('zb_event_health_tracked_names');

        if ($names !== null) {
            return $names;
        }

        return [];
    }

    /**
     * Create empty stats structure.
     *
     * @return array{last_seen: int, count: int, schema_valid: int, schema_total: int, provider_success: array<string, int>, provider_total: array<string, int>, quality_scores: list<int>, volume_buckets: array<string, int>}
     */
    private function emptyStats(): array
    {
        return [
            'last_seen' => time(),
            'count' => 0,
            'schema_valid' => 0,
            'schema_total' => 0,
            'provider_success' => [],
            'provider_total' => [],
            'quality_scores' => [],
            'volume_buckets' => [],
        ];
    }
}
