<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * User behavioral fingerprinting service.
 *
 * Generates a unique behavioral signature for each user based on their
 * event frequency distribution, timing patterns, session characteristics,
 * and event sequence preferences. This fingerprint enables:
 *
 * - **User similarity detection** — Find users with similar behavioral patterns
 * - **Anomaly detection per-user** — Detect when a user's behavior deviates from their norm
 * - **Segment auto-assignment** — Automatically assign users to segments based on behavior
 * - **Bot detection** — Identify automated/scripted behavior patterns
 * - **Churn early warning** — Behavioral drift may indicate disengagement
 *
 * The fingerprint is a deterministic hash computed from normalized behavioral
 * features, ensuring the same user always produces the same fingerprint
 * (as long as their behavior is consistent).
 *
 * Inspired by Heap's "Define Once, Track Forever" behavioral analysis and
 * Amplitude's "Compass" user segmentation.
 *
 * Configuration: `zeroboiler.analytics.behavioral_fingerprint`
 *
 * @phpstan-type FingerprintFeatures array{event_frequency: array<string, float>, timing_variance: float, session_frequency: float, avg_events_per_session: float, top_category_ratio: float, event_diversity: float, recency_score: float}
 * @phpstan-type FingerprintResult array{hash: string, features: FingerprintFeatures, score: float, confidence: float, segment_hint: string, bot_risk: float, drift_score: float|null}
 *
 * @since 169.0.0
 */
final class EventBehavioralFingerprintService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_bfp_';

    /** @var int Default cache TTL (seconds) */
    private const DEFAULT_CACHE_TTL = 7200;

    /** @var int Number of feature bins for frequency normalization */
    private const FREQUENCY_BINS = 10;

    /** @var float Threshold above which bot risk is considered high */
    private const HIGH_BOT_RISK_THRESHOLD = 0.75;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private int $minEventsForFingerprint;

    private float $timingNormalizeFactor;

    /** @var array<string, float> Predefined behavioral segment centroids */
    private const SEGMENT_CENTROIDS = [
        'power_user' => [
            'event_frequency_weight' => 0.85,
            'timing_variance_weight' => 0.30,
            'session_frequency_weight' => 0.80,
            'event_diversity_weight' => 0.75,
            'recency_score_weight' => 0.90,
        ],
        'casual_user' => [
            'event_frequency_weight' => 0.30,
            'timing_variance_weight' => 0.50,
            'session_frequency_weight' => 0.25,
            'event_diversity_weight' => 0.40,
            'recency_score_weight' => 0.50,
        ],
        'explorer' => [
            'event_frequency_weight' => 0.50,
            'timing_variance_weight' => 0.70,
            'session_frequency_weight' => 0.40,
            'event_diversity_weight' => 0.90,
            'recency_score_weight' => 0.60,
        ],
        'at_risk' => [
            'event_frequency_weight' => 0.15,
            'timing_variance_weight' => 0.40,
            'session_frequency_weight' => 0.10,
            'event_diversity_weight' => 0.25,
            'recency_score_weight' => 0.15,
        ],
        'new_user' => [
            'event_frequency_weight' => 0.20,
            'timing_variance_weight' => 0.20,
            'session_frequency_weight' => 0.20,
            'event_diversity_weight' => 0.15,
            'recency_score_weight' => 0.95,
        ],
        'bot_like' => [
            'event_frequency_weight' => 0.95,
            'timing_variance_weight' => 0.05,
            'session_frequency_weight' => 0.95,
            'event_diversity_weight' => 0.10,
            'recency_score_weight' => 0.95,
        ],
    ];

    /**
     * @param  CacheRepository|null  $cache  Application cache
     * @param  ConfigRepository|null  $config  Analytics configuration
     */
    public function __construct(?CacheRepository $cache = null, ?ConfigRepository $config = null){
        $this->cache = $cache ?? app(CacheRepository::class);
        $configRepo = $config ?? app(ConfigRepository::class);
        $fpConfig = $configRepo->get('zeroboiler.analytics.behavioral_fingerprint', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_events?: int, timing_normalize_factor?: float} $fpConfig */

        $this->enabled = (bool) ($fpConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($fpConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->minEventsForFingerprint = (int) ($fpConfig['min_events'] ?? 10);
        $this->timingNormalizeFactor = (float) ($fpConfig['timing_normalize_factor'] ?? 3600.0);
    }

    /**
     * Generate a behavioral fingerprint for a user based on their event history.
     *
     * @param  string  $userId  User identifier
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, category: string|null}>  $events  User's event history
     * @return FingerprintResult|null Fingerprint result or null if insufficient data
     */
    public function generateFingerprint(string $userId, array $events): ?array
    {
        if (!$this->enabled || count($events) < $this->minEventsForFingerprint) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . 'fp_' . md5($userId);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $features = $this->extractFeatures($events);
        $hash = $this->computeHash($features);
        $score = $this->computeActivityScore($features);
        $segmentHint = $this->matchSegment($features);
        $botRisk = $this->computeBotRisk($features, $events);
        $confidence = $this->computeConfidence($events);

        $result = [
            'hash' => $hash,
            'features' => $features,
            'score' => $score,
            'confidence' => $confidence,
            'segment_hint' => $segmentHint,
            'bot_risk' => $botRisk,
            'drift_score' => null,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Compute the drift score between a user's current fingerprint and a stored baseline.
     *
     * A high drift score indicates significant behavioral change (potential churn risk,
     * account takeover, or feature adoption).
     *
     * @param  FingerprintResult  $current  Current fingerprint
     * @param  FingerprintResult  $baseline  Previously stored baseline fingerprint
     * @return float Drift score between 0.0 (no change) and 1.0 (complete change)
     */
    public function computeDrift(array $current, array $baseline): float
    {
        if (!isset($current['features'], $baseline['features'])) {
            return 0.0;
        }

        $driftComponents = [
            $this->featureDrift($current['features']['event_frequency'] ?? [], $baseline['features']['event_frequency'] ?? []),
            abs(($current['features']['timing_variance'] ?? 0.0) - ($baseline['features']['timing_variance'] ?? 0.0)),
            abs(($current['features']['session_frequency'] ?? 0.0) - ($baseline['features']['session_frequency'] ?? 0.0)),
            abs(($current['features']['avg_events_per_session'] ?? 0.0) - ($baseline['features']['avg_events_per_session'] ?? 0.0)),
            abs(($current['features']['event_diversity'] ?? 0.0) - ($baseline['features']['event_diversity'] ?? 0.0)),
            abs(($current['features']['recency_score'] ?? 0.0) - ($baseline['features']['recency_score'] ?? 0.0)),
        ];

        $normalizedDrift = [];
        foreach ($driftComponents as $i => $drift) {
            if ($i === 0) {
                // Frequency drift already normalized
                $normalizedDrift[] = $drift;
            } else {
                $normalizedDrift[] = min(1.0, $drift);
            }
        }

        // Weighted average drift
        $weights = [0.3, 0.1, 0.15, 0.15, 0.15, 0.15];
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($normalizedDrift as $i => $d) {
            $w = $weights[$i] ?? 0.1;
            $weightedSum += $d * $w;
            $weightTotal += $w;
        }

        return $weightTotal > 0 ? round($weightedSum / $weightTotal, 4) : 0.0;
    }

    /**
     * Store a fingerprint as the user's baseline for future drift detection.
     *
     * @param  string  $userId  User identifier
     * @param  FingerprintResult  $fingerprint  Fingerprint to store
     */
    public function storeBaseline(string $userId, array $fingerprint): void
    {
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . md5($userId);
        $this->cache->put($cacheKey, $fingerprint, $this->cacheTtl * 12);
    }

    /**
     * Retrieve the stored baseline fingerprint for a user.
     *
     * @param  string  $userId  User identifier
     * @return FingerprintResult|null Baseline fingerprint or null if not stored
     */
    public function getBaseline(string $userId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . md5($userId);
        $cached = $this->cache->get($cacheKey);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Find users with similar behavioral fingerprints.
     *
     * Computes cosine similarity between fingerprints to find behavioral twins.
     *
     * @param  string  $userId  Target user
     * @param  array<string, FingerprintResult>  $candidateFingerprints  Other users' fingerprints
     * @return list<array{user_id: string, similarity: float, shared_segment: string}> Similar users sorted by similarity (descending)
     */
    public function findSimilarUsers(string $userId, array $candidateFingerprints): array
    {
        $cacheKey = self::CACHE_PREFIX . 'similar_' . md5($userId);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $currentFp = $this->cache->get(self::CACHE_PREFIX . 'fp_' . md5($userId));

        if (!is_array($currentFp) || !isset($currentFp['features'])) {
            return [];
        }

        $results = [];

        foreach ($candidateFingerprints as $candidateId => $candidateFp) {
            if ($candidateId === $userId) {
                continue;
            }

            if (!isset($candidateFp['features']) || !isset($currentFp['features'])) {
                continue;
            }

            $similarity = $this->cosineSimilarity(
                $this->featuresToVector($currentFp['features']),
                $this->featuresToVector($candidateFp['features']),
            );

            if ($similarity > 0.5) {
                $results[] = [
                    'user_id' => $candidateId,
                    'similarity' => round($similarity, 4),
                    'shared_segment' => $currentFp['segment_hint'] ?? 'unknown',
                ];
            }
        }

        usort($results, fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        $this->cache->put($cacheKey, $results, $this->cacheTtl);

        return $results;
    }

    /**
     * Get a summary of the fingerprinting service status.
     *
     * @return array{enabled: bool, cache_ttl: int, min_events: int, segments: list<string>}
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'min_events' => $this->minEventsForFingerprint,
            'segments' => array_keys(self::SEGMENT_CENTROIDS),
        ];
    }

    /**
     * Extract behavioral features from a user's event history.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, category: string|null}>  $events
     * @return FingerprintFeatures
     */
    private function extractFeatures(array $events): array
    {
        $eventCounts = [];
        $categoryCounts = [];
        $timestamps = [];
        $sessions = [];
        $now = time();

        foreach ($events as $event) {
            $name = $event['name'] ?? 'unknown';
            $timestamp = $event['timestamp'] ?? $now;
            $sessionId = $event['session_id'] ?? 'default';
            $category = $event['category'] ?? 'engagement';

            $eventCounts[$name] = ($eventCounts[$name] ?? 0) + 1;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            $timestamps[] = $timestamp;
            $sessions[$sessionId] = true;
        }

        $totalEvents = count($events);
        $uniqueEvents = count($eventCounts);
        $totalCategories = count($categoryCounts);

        // Normalized frequency distribution
        $eventFrequency = [];
        foreach ($eventCounts as $name => $count) {
            $eventFrequency[$name] = round($count / $totalEvents, 4);
        }

        // Timing variance (seconds between events)
        sort($timestamps);
        $intervals = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $intervals[] = $timestamps[$i] - $timestamps[$i - 1];
        }

        $timingVariance = count($intervals) > 0
            ? round($this->normalizeVariance($intervals), 4)
            : 0.0;

        // Session frequency (sessions per day)
        $timeSpan = max(1, ($timestamps[array_key_last($timestamps)] ?? $now) - ($timestamps[0] ?? $now));
        $days = max(1, $timeSpan / 86400);
        $sessionFrequency = round(min(1.0, (count($sessions) / $days) / 10), 4);

        // Average events per session
        $sessionCount = count($sessions);
        $avgEventsPerSession = $sessionCount > 0
            ? round(min(1.0, ($totalEvents / $sessionCount) / 50), 4)
            : 0.0;

        // Top category ratio (dominance of one category)
        $topCategoryCount = $totalCategories > 0 ? max($categoryCounts) : 0;
        $topCategoryRatio = $totalEvents > 0
            ? round($topCategoryCount / $totalEvents, 4)
            : 0.0;

        // Event diversity (unique events / total events) — Shannon-inspired
        $eventDiversity = $totalEvents > 0
            ? round($uniqueEvents / min($totalEvents, 50), 4)
            : 0.0;

        // Recency score (how recently was the last event)
        $lastEventTime = $timestamps[array_key_last($timestamps)] ?? 0;
        $hoursSinceLastEvent = max(0, ($now - $lastEventTime) / 3600);
        $recencyScore = round(max(0.0, 1.0 - ($hoursSinceLastEvent / 168)), 4); // Decay over 7 days

        return [
            'event_frequency' => $eventFrequency,
            'timing_variance' => $timingVariance,
            'session_frequency' => $sessionFrequency,
            'avg_events_per_session' => $avgEventsPerSession,
            'top_category_ratio' => $topCategoryRatio,
            'event_diversity' => $eventDiversity,
            'recency_score' => $recencyScore,
        ];
    }

    /**
     * Compute a deterministic hash from behavioral features.
     *
     * @param  FingerprintFeatures  $features
     * @return string 16-character hex hash
     */
    private function computeHash(array $features): string
    {
        $hashable = json_encode([
            'ef' => $features['event_frequency'],
            'tv' => $features['timing_variance'],
            'sf' => $features['session_frequency'],
            'aes' => $features['avg_events_per_session'],
            'tcr' => $features['top_category_ratio'],
            'ed' => $features['event_diversity'],
            'rs' => $features['recency_score'],
        ], JSON_THROW_ON_ERROR);

        return substr(md5($hashable), 0, 16);
    }

    /**
     * Compute overall activity score (0-1).
     *
     * @param  FingerprintFeatures  $features
     * @return float
     */
    private function computeActivityScore(array $features): float
    {
        $weights = [
            'session_frequency' => 0.30,
            'avg_events_per_session' => 0.25,
            'event_diversity' => 0.20,
            'recency_score' => 0.25,
        ];

        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += ($features[$key] ?? 0.0) * $weight;
        }

        return round(min(1.0, max(0.0, $score)), 4);
    }

    /**
     * Match behavioral features against predefined segment centroids.
     *
     * Uses weighted cosine distance to find the closest segment.
     *
     * @param  FingerprintFeatures  $features
     * @return string Segment name
     */
    private function matchSegment(array $features): string
    {
        $featureVector = [
            $features['session_frequency'] ?? 0.0,
            $features['timing_variance'] ?? 0.0,
            $features['session_frequency'] ?? 0.0,
            $features['event_diversity'] ?? 0.0,
            $features['recency_score'] ?? 0.0,
        ];

        $bestSegment = 'casual_user';
        $bestDistance = PHP_FLOAT_MAX;

        foreach (self::SEGMENT_CENTROIDS as $segmentName => $centroid) {
            $centroidVector = [
                $centroid['session_frequency_weight'],
                $centroid['timing_variance_weight'],
                $centroid['session_frequency_weight'],
                $centroid['event_diversity_weight'],
                $centroid['recency_score_weight'],
            ];

            $distance = $this->euclideanDistance($featureVector, $centroidVector);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestSegment = $segmentName;
            }
        }

        return $bestSegment;
    }

    /**
     * Compute bot risk score based on behavioral patterns.
     *
     * High event frequency with low diversity, extremely regular timing,
     * and high session frequency are indicators of automated behavior.
     *
     * @param  FingerprintFeatures  $features
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, category: string|null}>  $events
     * @return float Risk score 0.0 (human) to 1.0 (bot)
     */
    private function computeBotRisk(array $features, array $events): float
    {
        $indicators = [];

        // Very low event diversity (repetitive actions)
        $indicators[] = $features['event_diversity'] < 0.15 ? 0.3 : 0.0;

        // Very high session frequency (many sessions in short time)
        $indicators[] = $features['session_frequency'] > 0.9 ? 0.25 : 0.0;

        // Very low timing variance (machine-like regularity)
        $indicators[] = $features['timing_variance'] < 0.1 ? 0.25 : 0.0;

        // High events per session (> 50 normalized)
        $indicators[] = $features['avg_events_per_session'] > 0.8 ? 0.2 : 0.0;

        if (count($events) > 20) {
            $timestamps = array_column($events, 'timestamp');
            sort($timestamps);
            $exactIntervals = 0;
            $totalIntervals = 0;

            for ($i = 1; $i < min(50, count($timestamps)); $i++) {
                if ($timestamps[$i] !== null && $timestamps[$i - 1] !== null) {
                    $interval = $timestamps[$i] - $timestamps[$i - 1];
                    if ($interval > 0 && $interval % 1 === 0) {
                        $exactIntervals++;
                    }
                    $totalIntervals++;
                }
            }

            if ($totalIntervals > 0 && $exactIntervals / $totalIntervals > 0.8) {
                $indicators[] = 0.2;
            } else {
                $indicators[] = 0.0;
            }
        } else {
            $indicators[] = 0.0;
        }

        $risk = array_sum($indicators);

        return round(min(1.0, $risk), 4);
    }

    /**
     * Compute confidence score based on data sufficiency.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, category: string|null}>  $events
     * @return float Confidence 0.0 to 1.0
     */
    private function computeConfidence(array $events): float
    {
        $totalEvents = count($events);
        $sessions = array_unique(array_column($events, 'session_id'));
        $uniqueEvents = count(array_unique(array_column($events, 'name')));

        $eventSufficiency = min(1.0, $totalEvents / 50);
        $sessionSufficiency = min(1.0, count($sessions) / 5);
        $diversitySufficiency = min(1.0, $uniqueEvents / 10);

        $confidence = ($eventSufficiency * 0.4) + ($sessionSufficiency * 0.3) + ($diversitySufficiency * 0.3);

        return round($confidence, 4);
    }

    /**
     * Compute normalized variance from intervals.
     *
     * @param  list<int>  $intervals
     * @return float Normalized variance (0-1)
     */
    private function normalizeVariance(array $intervals): float
    {
        if (count($intervals) < 2) {
            return 0.0;
        }

        $mean = array_sum($intervals) / count($intervals);
        $variance = array_sum(array_map(
            fn(int $v): float => (($v - $mean) ** 2),
            $intervals,
        )) / count($intervals);

        $cvSquared = $mean > 0 ? $variance / ($mean * $mean) : 0.0;

        return min(1.0, $cvSquared / 10);
    }

    /**
     * Compute cosine similarity between two feature vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return float Similarity 0.0 to 1.0
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += ($a[$i] ?? 0.0) * ($b[$i] ?? 0.0);
            $normA += ($a[$i] ?? 0.0) ** 2;
            $normB += ($b[$i] ?? 0.0) ** 2;
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0 ? $dotProduct / $denominator : 0.0;
    }

    /**
     * Compute Euclidean distance between two vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return float
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        $sumSquares = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $sumSquares += (($a[$i] ?? 0.0) - ($b[$i] ?? 0.0)) ** 2;
        }

        return sqrt($sumSquares);
    }

    /**
     * Compute frequency distribution drift using normalized histograms.
     *
     * @param  array<string, float>  $current
     * @param  array<string, float>  $baseline
     * @return float Drift 0.0 to 1.0
     */
    private function featureDrift(array $current, array $baseline): float
    {
        $allKeys = array_unique(array_merge(array_keys($current), array_keys($baseline)));

        if (empty($allKeys)) {
            return 0.0;
        }

        $driftSum = 0.0;

        foreach ($allKeys as $key) {
            $cv = $current[$key] ?? 0.0;
            $bv = $baseline[$key] ?? 0.0;
            $driftSum += abs($cv - $bv);
        }

        return min(1.0, $driftSum / 2);
    }

    /**
     * Convert features array to a numeric vector for similarity computation.
     *
     * @param  FingerprintFeatures  $features
     * @return list<float>
     */
    private function featuresToVector(array $features): array
    {
        return [
            $features['timing_variance'] ?? 0.0,
            $features['session_frequency'] ?? 0.0,
            $features['avg_events_per_session'] ?? 0.0,
            $features['top_category_ratio'] ?? 0.0,
            $features['event_diversity'] ?? 0.0,
            $features['recency_score'] ?? 0.0,
        ];
    }
}
