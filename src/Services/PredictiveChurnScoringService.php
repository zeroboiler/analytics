<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Predictive churn scoring service based on event behavioral signals.
 *
 * Computes a churn probability score (0-100) for each user by analyzing
 * their event patterns, engagement trends, and behavioral features.
 * The score is computed using a weighted sigmoid model with configurable
 * feature weights.
 *
 * Features analyzed:
 * - **Login frequency decline** — Decreasing login rate over time
 * - **Feature usage decline** — Reduction in unique features used
 * - **Session duration decline** — Shorter sessions over time
 * - **Support ticket frequency** — Higher support interactions correlate with churn
 * - **Error event rate** — Product friction indicator
 * - **Engagement recency** — How recently the user was last active
 * - **Feature adoption breadth** — Number of features used (sticky users use more)
 * - **Trial-to-conversion gap** — Time since trial without converting
 * - **Negative feedback signals** — Cancellation events, downgrade, etc.
 *
 * The churn score is output on a 0-100 scale:
 * - 0-30: Healthy (low churn risk)
 * - 31-60: At risk (moderate churn probability)
 * - 61-80: High risk (likely to churn)
 * - 81-100: Critical (imminent churn)
 *
 * Inspired by Amplitude's "Churn Prediction", Segment's "Predictive Audiences",
 * and baremetrics-style churn scoring.
 *
 * Configuration: `zeroboiler.analytics.churn_prediction`
 *
 * @phpstan-type ChurnFeature array{name: string, value: float, weight: float, contribution: float, description: string}
 * @phpstan-type ChurnResult array{score: int, risk_level: 'healthy'|'at_risk'|'high_risk'|'critical', confidence: float, features: list<ChurnFeature>, trend: 'improving'|'stable'|'declining', last_active: string|null, predicted_churn_date: string|null}
 * @phpstan-type ChurnSummary array{total_users: int, healthy: int, at_risk: int, high_risk: int, critical: int, avg_score: float, highest_risk_users: list<array{user_id: string, score: int}>}
 *
 * @since 169.0.0
 */
final class PredictiveChurnScoringService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_churn_';

    /** @var int Default cache TTL (seconds) */
    private const DEFAULT_CACHE_TTL = 3600;

    /** @var int Minimum events for churn scoring */
    private const MIN_EVENTS = 10;

    /** @var int Default churn prediction horizon in days */
    private const DEFAULT_PREDICTION_HORIZON = 30;

    /** @var array<string, array{weight: float, positive: bool, description: string}> Feature definitions */
    private const FEATURES = [
        'login_frequency_decline' => [
            'weight' => 0.20,
            'positive' => false,
            'description' => 'Declining login frequency over time',
        ],
        'feature_usage_decline' => [
            'weight' => 0.15,
            'positive' => false,
            'description' => 'Reduction in unique features used per session',
        ],
        'session_duration_decline' => [
            'weight' => 0.10,
            'positive' => false,
            'description' => 'Shorter average session duration over time',
        ],
        'support_ticket_frequency' => [
            'weight' => 0.10,
            'positive' => false,
            'description' => 'High support interaction frequency',
        ],
        'error_event_rate' => [
            'weight' => 0.10,
            'positive' => false,
            'description' => 'High error event rate (product friction)',
        ],
        'engagement_recency' => [
            'weight' => 0.15,
            'positive' => true,
            'description' => 'How recently the user was last active',
        ],
        'feature_adoption_breadth' => [
            'weight' => 0.10,
            'positive' => true,
            'description' => 'Number of unique features used (sticky users use more)',
        ],
        'trial_conversion_gap' => [
            'weight' => 0.05,
            'positive' => false,
            'description' => 'Days since trial without converting to paid',
        ],
        'negative_signals' => [
            'weight' => 0.05,
            'positive' => false,
            'description' => 'Cancellation/downgrade/survey negative events',
        ],
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private int $predictionHorizon;

    /**
     * @param  CacheRepository|null  $cache  Application cache
     * @param  ConfigRepository|null  $config  Analytics configuration
     */
    public function __construct(?CacheRepository $cache = null, ?ConfigRepository $config = null): void
    {
        $this->cache = $cache ?? app(CacheRepository::class);
        $configRepo = $config ?? app(ConfigRepository::class);
        $churnConfig = $configRepo->get('zeroboiler.analytics.churn_prediction', []);
        /** @var array{enabled?: bool, cache_ttl?: int, prediction_horizon?: int} $churnConfig */

        $this->enabled = (bool) ($churnConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($churnConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->predictionHorizon = (int) ($churnConfig['prediction_horizon'] ?? self::DEFAULT_PREDICTION_HORIZON);
    }

    /**
     * Compute churn score for a single user.
     *
     * @param  string  $userId  User identifier
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events  User's event history
     * @param  array{trial_start?: int|null, subscription_start?: int|null, is_subscribed?: bool}|null  $userMeta  Optional user metadata
     * @return ChurnResult|null Churn scoring result or null if insufficient data
     */
    public function computeChurnScore(string $userId, array $events, ?array $userMeta = null): ?array
    {
        if (!$this->enabled || count($events) < self::MIN_EVENTS) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . 'score_' . md5($userId);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $features = $this->extractFeatures($events, $userMeta);
        $rawScore = $this->weightedScore($features);
        $score = $this->sigmoidTransform($rawScore);
        $scoreInt = (int) round($score * 100);
        $riskLevel = $this->classifyRisk($scoreInt);
        $confidence = $this->computeConfidence($events);
        $trend = $this->computeTrend($events);
        $lastActive = $this->getLastActiveTime($events);
        $predictedChurnDate = $this->predictChurnDate($scoreInt, $lastActive);

        $result = [
            'score' => $scoreInt,
            'risk_level' => $riskLevel,
            'confidence' => $confidence,
            'features' => $features,
            'trend' => $trend,
            'last_active' => $lastActive,
            'predicted_churn_date' => $predictedChurnDate,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Compute churn scores for a batch of users.
     *
     * @param  array<string, list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>>  $userEvents  User ID → events
     * @param  array<string, array{trial_start?: int|null, subscription_start?: int|null, is_subscribed?: bool}|null>  $userMetaMap  User ID → metadata
     * @return array<string, ChurnResult> User ID → churn result
     */
    public function computeBatchScores(array $userEvents, array $userMetaMap = []): array
    {
        $results = [];

        foreach ($userEvents as $userId => $events) {
            $meta = $userMetaMap[$userId] ?? null;
            $score = $this->computeChurnScore($userId, $events, $meta);
            if ($score !== null) {
                $results[$userId] = $score;
            }
        }

        return $results;
    }

    /**
     * Generate a churn risk summary across all scored users.
     *
     * @param  array<string, ChurnResult>  $scores  Pre-computed churn scores
     * @return ChurnSummary
     */
    public function generateSummary(array $scores): array
    {
        $total = count($scores);
        $healthy = 0;
        $atRisk = 0;
        $highRisk = 0;
        $critical = 0;
        $totalScore = 0;
        $highestRisk = [];

        foreach ($scores as $userId => $result) {
            $totalScore += $result['score'];
            $highestRisk[] = ['user_id' => $userId, 'score' => $result['score']];

            match ($result['risk_level']) {
                'healthy' => $healthy++,
                'at_risk' => $atRisk++,
                'high_risk' => $highRisk++,
                'critical' => $critical++,
                default => null,
            };
        }

        usort($highestRisk, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'total_users' => $total,
            'healthy' => $healthy,
            'at_risk' => $atRisk,
            'high_risk' => $highRisk,
            'critical' => $critical,
            'avg_score' => $total > 0 ? round($totalScore / $total, 1) : 0.0,
            'highest_risk_users' => array_slice($highestRisk, 0, 10),
        ];
    }

    /**
     * Get users above a churn score threshold for targeted intervention.
     *
     * @param  array<string, ChurnResult>  $scores  Pre-computed scores
     * @param  int  $threshold  Minimum churn score (0-100)
     * @return list<array{user_id: string, score: int, risk_level: string, trend: string, features: list<ChurnFeature>}>
     */
    public function getUsersAboveThreshold(array $scores, int $threshold = 60): array
    {
        $results = [];

        foreach ($scores as $userId => $result) {
            if ($result['score'] >= $threshold) {
                $results[] = [
                    'user_id' => $userId,
                    'score' => $result['score'],
                    'risk_level' => $result['risk_level'],
                    'trend' => $result['trend'],
                    'features' => $result['features'],
                ];
            }
        }

        usort($results, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Classify risk level from churn score.
     *
     * @param  int  $score  Churn score (0-100)
     * @return 'healthy'|'at_risk'|'high_risk'|'critical'
     */
    public function classifyRisk(int $score): string
    {
        if ($score >= 81) {
            return 'critical';
        }

        if ($score >= 61) {
            return 'high_risk';
        }

        if ($score >= 31) {
            return 'at_risk';
        }

        return 'healthy';
    }

    /**
     * Get service status.
     *
     * @return array{enabled: bool, cache_ttl: int, prediction_horizon: int, features: list<string>, risk_levels: list<string>}
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'prediction_horizon' => $this->predictionHorizon,
            'features' => array_keys(self::FEATURES),
            'risk_levels' => ['healthy', 'at_risk', 'high_risk', 'critical'],
        ];
    }

    /**
     * Extract behavioral features from event history.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @param  array{trial_start?: int|null, subscription_start?: int|null, is_subscribed?: bool}|null  $userMeta
     * @return list<ChurnFeature>
     */
    private function extractFeatures(array $events, ?array $userMeta): array
    {
        $now = time();
        $features = [];
        $timestamps = [];
        $sessionIds = [];
        $eventNames = [];
        $featureUsed = [];
        $negativeCount = 0;
        $errorCount = 0;
        $supportCount = 0;

        foreach ($events as $event) {
            $name = $event['name'] ?? 'unknown';
            $timestamp = $event['timestamp'] ?? $now;
            $sessionId = $event['session_id'] ?? 'default';

            $timestamps[] = $timestamp;
            $sessionIds[$sessionId] = ($sessionIds[$sessionId] ?? 0) + 1;
            $eventNames[] = $name;

            // Track feature usage
            if (str_contains($name, 'feature_') || str_contains($name, '_used')) {
                $featureUsed[$name] = true;
            }

            // Negative signals
            if (str_contains($name, 'cancel') || str_contains($name, 'downgrade') || str_contains($name, 'unsubscribe')) {
                $negativeCount++;
            }

            // Error signals
            if (str_contains($name, 'error') || str_contains($name, 'exception') || str_contains($name, 'failure')) {
                $errorCount++;
            }

            // Support signals
            if (str_contains($name, 'support') || str_contains($name, 'help') || str_contains($name, 'contact')) {
                $supportCount++;
            }
        }

        $totalEvents = count($events);
        sort($timestamps);

        // 1. Login frequency decline: compare first half vs second half
        $loginFrequencyDecline = $this->computeLoginDecline($eventNames, $timestamps);
        $features[] = $this->buildFeature('login_frequency_decline', $loginFrequencyDecline);

        // 2. Feature usage decline: unique features in first half vs second half
        $featureDecline = $this->computeFeatureDecline($events);
        $features[] = $this->buildFeature('feature_usage_decline', $featureDecline);

        // 3. Session duration decline (proxy: events per session)
        $sessionDurationDecline = $this->computeSessionDecline($sessionIds, $timestamps);
        $features[] = $this->buildFeature('session_duration_decline', $sessionDurationDecline);

        // 4. Support ticket frequency
        $supportRate = $totalEvents > 0 ? $supportCount / $totalEvents : 0;
        $features[] = $this->buildFeature('support_ticket_frequency', min(1.0, $supportRate * 5));

        // 5. Error event rate
        $errorRate = $totalEvents > 0 ? $errorCount / $totalEvents : 0;
        $features[] = $this->buildFeature('error_event_rate', min(1.0, $errorRate * 5));

        // 6. Engagement recency
        $lastTimestamp = $timestamps[array_key_last($timestamps)] ?? $now;
        $daysSinceActive = max(0, ($now - $lastTimestamp) / 86400);
        $recencyScore = max(0.0, 1.0 - ($daysSinceActive / 30));
        $features[] = $this->buildFeature('engagement_recency', $recencyScore);

        // 7. Feature adoption breadth
        $breadth = min(1.0, count($featureUsed) / 10);
        $features[] = $this->buildFeature('feature_adoption_breadth', $breadth);

        // 8. Trial conversion gap
        $trialGap = $this->computeTrialGap($userMeta);
        $features[] = $this->buildFeature('trial_conversion_gap', $trialGap);

        // 9. Negative signals
        $negativeRate = $totalEvents > 0 ? $negativeCount / $totalEvents : 0;
        $features[] = $this->buildFeature('negative_signals', min(1.0, $negativeRate * 10));

        return $features;
    }

    /**
     * Build a feature array with contribution calculation.
     *
     * @param  string  $name
     * @param  float  $value
     * @return ChurnFeature
     */
    private function buildFeature(string $name, float $value): array
    {
        $def = self::FEATURES[$name] ?? ['weight' => 0.1, 'positive' => true, 'description' => ''];
        $contribution = $def['positive']
            ? $value * $def['weight']
            : (1.0 - $value) * (-$def['weight']);

        return [
            'name' => $name,
            'value' => round($value, 4),
            'weight' => $def['weight'],
            'contribution' => round($contribution, 4),
            'description' => $def['description'],
        ];
    }

    /**
     * Compute login frequency decline (first half vs second half).
     *
     * @param  list<string>  $eventNames
     * @param  list<int>  $timestamps  Sorted timestamps
     * @return float 0.0 (no decline) to 1.0 (complete decline)
     */
    private function computeLoginDecline(array $eventNames, array $timestamps): float
    {
        $n = count($eventNames);
        if ($n < 10) {
            return 0.0;
        }

        $half = (int) floor($n / 2);
        $firstHalfLogins = 0;
        $secondHalfLogins = 0;

        for ($i = 0; $i < $n; $i++) {
            if (str_contains($eventNames[$i], 'login')) {
                if ($i < $half) {
                    $firstHalfLogins++;
                } else {
                    $secondHalfLogins++;
                }
            }
        }

        if ($firstHalfLogins === 0 && $secondHalfLogins === 0) {
            return 0.0;
        }

        if ($firstHalfLogins === 0) {
            return $secondHalfLogins > 0 ? 0.0 : 0.5;
        }

        $ratio = $secondHalfLogins / $firstHalfLogins;
        return max(0.0, min(1.0, 1.0 - $ratio));
    }

    /**
     * Compute feature usage decline between time halves.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @return float 0.0 to 1.0
     */
    private function computeFeatureDecline(array $events): float
    {
        $n = count($events);
        if ($n < 10) {
            return 0.0;
        }

        $half = (int) floor($n / 2);
        $firstFeatures = [];
        $secondFeatures = [];

        for ($i = 0; $i < $n; $i++) {
            $name = $events[$i]['name'] ?? '';
            if (str_contains($name, 'feature_') || str_contains($name, '_used')) {
                if ($i < $half) {
                    $firstFeatures[$name] = true;
                } else {
                    $secondFeatures[$name] = true;
                }
            }
        }

        $firstCount = count($firstFeatures);
        if ($firstCount === 0) {
            return 0.0;
        }

        $secondCount = count($secondFeatures);
        return max(0.0, min(1.0, 1.0 - ($secondCount / $firstCount)));
    }

    /**
     * Compute session engagement decline.
     *
     * @param  array<string, int>  $sessionCounts  Session ID → event count
     * @param  list<int>  $timestamps  Sorted timestamps
     * @return float 0.0 to 1.0
     */
    private function computeSessionDecline(array $sessionCounts, array $timestamps): float
    {
        if (empty($sessionCounts) || count($timestamps) < 4) {
            return 0.0;
        }

        $sessions = array_values($sessionCounts);
        $count = count($sessions);

        $half = (int) floor($count / 2);
        $firstAvg = $half > 0 ? array_sum(array_slice($sessions, 0, $half)) / $half : 0;
        $secondAvg = ($count - $half) > 0
            ? array_sum(array_slice($sessions, $half)) / ($count - $half)
            : 0;

        if ($firstAvg === 0.0) {
            return 0.0;
        }

        $ratio = $secondAvg / $firstAvg;
        return max(0.0, min(1.0, 1.0 - $ratio));
    }

    /**
     * Compute trial conversion gap score.
     *
     * @param  array{trial_start?: int|null, subscription_start?: int|null, is_subscribed?: bool}|null  $userMeta
     * @return float 0.0 (no risk) to 1.0 (high risk)
     */
    private function computeTrialGap(?array $userMeta): float
    {
        if ($userMeta === null) {
            return 0.0;
        }

        $isSubscribed = $userMeta['is_subscribed'] ?? false;
        if ($isSubscribed) {
            return 0.0;
        }

        $trialStart = $userMeta['trial_start'] ?? null;
        if ($trialStart === null) {
            return 0.0;
        }

        $daysSinceTrial = (time() - $trialStart) / 86400;

        // Risk increases after 7 days of trial without conversion
        if ($daysSinceTrial <= 7) {
            return 0.0;
        }

        // Linear increase from day 7 to day 30, capped at 1.0
        return min(1.0, ($daysSinceTrial - 7) / 23);
    }

    /**
     * Compute weighted raw score from features.
     *
     * Positive features reduce churn, negative features increase it.
     * Output: -1.0 (very healthy) to +1.0 (very likely to churn).
     *
     * @param  list<ChurnFeature>  $features
     * @return float
     */
    private function weightedScore(array $features): float
    {
        $totalContribution = 0.0;

        foreach ($features as $feature) {
            $totalContribution += $feature['contribution'];
        }

        return max(-1.0, min(1.0, $totalContribution));
    }

    /**
     * Sigmoid transform from raw score (-1 to +1) to churn probability (0 to 1).
     *
     * Uses a steepened sigmoid centered at 0:
     *   P = 1 / (1 + exp(-6 * rawScore))
     *
     * This maps:
     *   -1.0 → ~0.0025 (almost certainly not churning)
     *    0.0 → 0.50    (borderline)
     *   +1.0 → ~0.9975 (almost certainly churning)
     *
     * @param  float  $rawScore  Weighted score (-1 to +1)
     * @return float Churn probability (0 to 1)
     */
    private function sigmoidTransform(float $rawScore): float
    {
        $steepness = 6.0;
        $exponent = -$steepness * $rawScore;

        return 1.0 / (1.0 + exp($exponent));
    }

    /**
     * Compute confidence in the churn score based on data sufficiency.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @return float 0.0 to 1.0
     */
    private function computeConfidence(array $events): float
    {
        $total = count($events);
        $sessions = count(array_unique(array_column($events, 'session_id')));
        $uniqueEvents = count(array_unique(array_column($events, 'name')));
        $timeSpan = $this->computeTimeSpan($events);

        $eventSufficiency = min(1.0, $total / 50);
        $sessionSufficiency = min(1.0, $sessions / 5);
        $diversitySufficiency = min(1.0, $uniqueEvents / 8);
        $timeSufficiency = min(1.0, $timeSpan / 14); // At least 2 weeks of data

        return round(
            ($eventSufficiency * 0.30) +
            ($sessionSufficiency * 0.25) +
            ($diversitySufficiency * 0.20) +
            ($timeSufficiency * 0.25),
            4,
        );
    }

    /**
     * Compute engagement trend (improving, stable, or declining).
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @return 'improving'|'stable'|'declining'
     */
    private function computeTrend(array $events): string
    {
        $n = count($events);
        if ($n < 20) {
            return 'stable';
        }

        sort($events); // Sort by timestamp

        $chunkSize = (int) floor($n / 4);
        if ($chunkSize < 5) {
            return 'stable';
        }

        $counts = [];
        for ($i = 0; $i < 4; $i++) {
            $start = $i * $chunkSize;
            $end = ($i === 3) ? $n : ($start + $chunkSize);
            $counts[] = $end - $start;
        }

        // Compare last quarter to first quarter
        $firstQuarter = $counts[0];
        $lastQuarter = $counts[3];

        if ($firstQuarter === 0) {
            return $lastQuarter > 0 ? 'improving' : 'stable';
        }

        $change = ($lastQuarter - $firstQuarter) / $firstQuarter;

        if ($change > 0.15) {
            return 'improving';
        }

        if ($change < -0.15) {
            return 'declining';
        }

        return 'stable';
    }

    /**
     * Get the last active timestamp as ISO string.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @return string|null
     */
    private function getLastActiveTime(array $events): ?string
    {
        $latest = 0;

        foreach ($events as $event) {
            $ts = $event['timestamp'] ?? 0;
            if ($ts > $latest) {
                $latest = $ts;
            }
        }

        return $latest > 0 ? date('c', $latest) : null;
    }

    /**
     * Predict estimated churn date based on score and last activity.
     *
     * @param  int  $score  Churn score (0-100)
     * @param  string|null  $lastActive  Last active ISO date
     * @return string|null Predicted churn date or null if healthy
     */
    private function predictChurnDate(int $score, ?string $lastActive): ?string
    {
        if ($score < 31 || $lastActive === null) {
            return null;
        }

        $lastTimestamp = strtotime($lastActive);
        if ($lastTimestamp === false) {
            return null;
        }

        // Higher scores = sooner predicted churn
        $daysOffset = match (true) {
            $score >= 81 => 3,
            $score >= 61 => 14,
            default => 30,
        };

        return date('c', $lastTimestamp + ($daysOffset * 86400));
    }

    /**
     * Compute time span in days between first and last event.
     *
     * @param  list<array{name: string, timestamp: int|null, session_id: string|null, properties?: array<string, mixed>}>  $events
     * @return float Days
     */
    private function computeTimeSpan(array $events): float
    {
        $minTs = PHP_INT_MAX;
        $maxTs = 0;

        foreach ($events as $event) {
            $ts = $event['timestamp'] ?? 0;
            if ($ts > 0) {
                $minTs = min($minTs, $ts);
                $maxTs = max($maxTs, $ts);
            }
        }

        return $minTs < PHP_INT_MAX ? ($maxTs - $minTs) / 86400 : 0.0;
    }
}
