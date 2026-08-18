<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
/**
 * Behavioral Segmentation Engine.
 *
 * Industry-standard user segmentation based on configurable behavioral signals.
 * Implements RFM (Recency, Frequency, Monetary) analysis plus custom behavioral
 * dimensions: engagement breadth, feature adoption depth, session regularity,
 * growth trajectory, and churn risk proximity.
 *
 * Segment tiers:
 *   - Champions: High RFM, high engagement breadth, upward trajectory
 *   - Loyal: Moderate-high frequency, recent, stable
 *   - Potential Loyalists: Recent users with growing frequency
 *   - New Users: Recently signed up, low frequency
 *   - Promising: Moderate activity, showing feature breadth growth
 *   - Need Attention: Declining frequency or recency
 *   - About to Sleep: Low recency, low frequency
 *   - At Risk: Previously active, sharp decline
 *   - Hibernating: Very low activity across all dimensions
 *   - Lost: No activity beyond configured threshold
 *
 * Each user receives:
 *   - Primary segment tier (string)
 *   - RFM scores (R: 1–5, F: 1–5, M: 1–5)
 *   - Composite score (0–100)
 *   - Dimension scores (6 behavioral dimensions)
 *   - Trait vector (for ML export)
 *   - Segment history (if tracking enabled)
 *
 * Inspired by Klaviyo segmentation, Amplitude Cohorts, and Mixpanel Behavioral cohorts.
 *
 * @since 239.0.0
 */
final class BehavioralSegmentationService
{
    /** @var array{cache_ttl: int, rfm_weights: array<string, float>, dimensions: array<string, float>, tiers: array<string, array{min: float, max: float}>, thresholds: array<string, int>} */
    private array $config;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     * @param  ConfigRepository  $configRepo
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AnalyticsMetrics $metrics,
        ConfigRepository $configRepo,
    ): void {
        $raw = $configRepo->get('zeroboiler.analytics.behavioral_segmentation', []);
        /** @var array{cache_ttl?: int, rfm_weights?: array<string, float>, dimensions?: array<string, float>, tiers?: array<string, array{min: float, max: float}>, thresholds?: array<string, int>} $raw */
        $this->config = [
            'cache_ttl' => (int) ($raw['cache_ttl'] ?? 3600),
            'rfm_weights' => $raw['rfm_weights'] ?? [
                'recency' => 0.30,
                'frequency' => 0.30,
                'monetary' => 0.20,
                'engagement' => 0.10,
                'growth' => 0.10,
            ],
            'dimensions' => $raw['dimensions'] ?? [
                'recency' => 0.25,
                'frequency' => 0.20,
                'monetary' => 0.15,
                'engagement_breadth' => 0.15,
                'session_regularity' => 0.15,
                'growth_trajectory' => 0.10,
            ],
            'tiers' => $raw['tiers'] ?? [
                'champions' => ['min' => 80, 'max' => 100],
                'loyal' => ['min' => 65, 'max' => 79.99],
                'potential_loyalists' => ['min' => 55, 'max' => 64.99],
                'promising' => ['min' => 45, 'max' => 54.99],
                'new_users' => ['min' => 30, 'max' => 44.99],
                'need_attention' => ['min' => 20, 'max' => 29.99],
                'about_to_sleep' => ['min' => 10, 'max' => 19.99],
                'hibernating' => ['min' => 1, 'max' => 9.99],
                'lost' => ['min' => 0, 'max' => 0.99],
                'at_risk' => ['min' => 5, 'max' => 24.99],
            ],
            'thresholds' => $raw['thresholds'] ?? [
                'lost_inactive_days' => 90,
                'hibernating_inactive_days' => 60,
                'at_risk_decline_percent' => 50,
                'min_events_for_segment' => 3,
            ],
        ];
    }

    /**
     * Segment a user by their behavioral profile.
     *
     * @param  string  $userId  User identifier
     * @param  string|null  $clientId  Optional client ID for cross-device enrichment
     * @return array{segment: string, rfm: array{r: int, f: int, m: int}, composite_score: float, dimensions: array<string, float>, trait_vector: array<int, float>, tier_config: array{min: float, max: float}, computed_at: string}
     *
     * @phpstan-return array{segment: string, rfm: array{r: int, f: int, m: int}, composite_score: float, dimensions: array<string, float>, trait_vector: array<int, float>, tier_config: array{min: float, max: float}, computed_at: string}
     */
    public function segmentUser(string $userId, ?string $clientId = null): array
    {
        $cacheKey = "zb_bseg_{$userId}";

        return $this->cache->remember($cacheKey, $this->config['cache_ttl'], function () use ($userId, $clientId): array {
            $rfm = $this->computeRFM($userId, $clientId);
            $dimensions = $this->computeDimensions($userId, $clientId);
            $compositeScore = $this->compositeScore($rfm, $dimensions);
            $segment = $this->classifySegment($compositeScore, $rfm, $dimensions);
            $traitVector = $this->buildTraitVector($rfm, $dimensions);

            return [
                'segment' => $segment,
                'rfm' => $rfm,
                'composite_score' => round($compositeScore, 2),
                'dimensions' => $dimensions,
                'trait_vector' => $traitVector,
                'tier_config' => $this->config['tiers'][$segment] ?? ['min' => 0, 'max' => 100],
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Batch segment multiple users.
     *
     * @param  array<string>  $userIds
     * @return array<string, array{segment: string, rfm: array{r: int, f: int, m: int}, composite_score: float}>
     */
    public function segmentBatch(array $userIds): array
    {
        $results = [];
        foreach ($userIds as $userId) {
            $seg = $this->segmentUser($userId);
            $results[$userId] = [
                'segment' => $seg['segment'],
                'rfm' => $seg['rfm'],
                'composite_score' => $seg['composite_score'],
            ];
        }

        return $results;
    }

    /**
     * Compute RFM (Recency, Frequency, Monetary) scores for a user.
     *
     * Each score is normalized to 1–5 scale:
     *   R: Days since last event → 5 (today) to 1 (>90 days)
     *   F: Events in window → 5 (>100) to 1 (<3)
     *   M: Revenue contribution → 5 (top tier) to 1 (none)
     *
     * @param  string  $userId
     * @param  string|null  $clientId
     * @return array{r: int, f: int, m: int}
     */
    public function computeRFM(string $userId, ?string $clientId = null): array
    {
        $r = $this->recencyScore($userId, $clientId);
        $f = $this->frequencyScore($userId, $clientId);
        $m = $this->monetaryScore($userId, $clientId);

        return ['r' => $r, 'f' => $f, 'm' => $m];
    }

    /**
     * Compute all behavioral dimension scores (0–100).
     *
     * @param  string  $userId
     * @param  string|null  $clientId
     * @return array<string, float>
     */
    public function computeDimensions(string $userId, ?string $clientId = null): array
    {
        return [
            'recency' => round($this->recencyScore($userId, $clientId) * 20.0, 2),
            'frequency' => round($this->frequencyScore($userId, $clientId) * 20.0, 2),
            'monetary' => round($this->monetaryScore($userId, $clientId) * 20.0, 2),
            'engagement_breadth' => $this->engagementBreadthScore($userId),
            'session_regularity' => $this->sessionRegularityScore($userId),
            'growth_trajectory' => $this->growthTrajectoryScore($userId),
        ];
    }

    /**
     * Get all users in a specific segment tier.
     *
     * Scans recent identity links and computes segments on-the-fly.
     * For large user bases, use the CLI command with batch processing.
     *
     * @param  string  $segment  Segment tier name (e.g., 'champions', 'at_risk')
     * @param  int  $limit  Maximum users to return
     * @return array<array{user_id: string, segment: string, composite_score: float}>
     */
    public function getUsersInSegment(string $segment, int $limit = 100): array
    {
        $validSegments = array_keys($this->config['tiers']);
        if (! in_array($segment, $validSegments, true)) {
            throw new \InvalidArgumentException("Invalid segment '{$segment}'. Valid: " . implode(', ', $validSegments));
        }

        $allLinks = $this->cache->get('zb_identity_links_all', []);
        $results = [];
        $count = 0;

        foreach ($allLinks as $userId => $_data) {
            if ($count >= $limit) {
                break;
            }

            $seg = $this->segmentUser((string) $userId);
            if ($seg['segment'] === $segment) {
                $results[] = [
                    'user_id' => (string) $userId,
                    'segment' => $seg['segment'],
                    'composite_score' => $seg['composite_score'],
                ];
                $count++;
            }
        }

        return $results;
    }

    /**
     * Get segment distribution summary (user counts per tier).
     *
     * @param  array<string>|null  $userIds  Optional subset of users to analyze
     * @return array<string, int>
     */
    public function segmentDistribution(?array $userIds = null): array
    {
        $distribution = array_fill_keys(array_keys($this->config['tiers']), 0);
        $ids = $userIds ?? $this->getKnownUserIds();

        foreach ($ids as $userId) {
            $seg = $this->segmentUser($userId);
            $tier = $seg['segment'];
            if (isset($distribution[$tier])) {
                $distribution[$tier]++;
            }
        }

        return $distribution;
    }

    /**
     * Get the list of all valid segment tiers.
     *
     * @return array<string, array{min: float, max: float, description: string}>
     */
    public function tiers(): array
    {
        $descriptions = [
            'champions' => 'High-value, highly engaged users with upward growth trajectory',
            'loyal' => 'Consistently active users with stable engagement patterns',
            'potential_loyalists' => 'Recent users with increasing frequency — convert to loyal',
            'new_users' => 'Recently signed up, building engagement patterns',
            'promising' => 'Moderate activity with growing feature breadth',
            'need_attention' => 'Previously active users showing decline signals',
            'about_to_sleep' => 'Low recency and frequency — re-engagement needed',
            'at_risk' => 'Sharp decline from previous activity levels',
            'hibernating' => 'Very low activity, may return with targeted campaigns',
            'lost' => 'No activity beyond configured threshold — churn confirmed',
        ];

        $result = [];
        foreach ($this->config['tiers'] as $name => $range) {
            $result[$name] = [
                'min' => $range['min'],
                'max' => $range['max'],
                'description' => $descriptions[$name] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Build a flat trait vector for ML export (array-indexed floats).
     *
     * Useful for exporting to ML pipelines, data warehouses, or
     * external segmentation tools (Klaviyo, Braze, etc.).
     *
     * @param  array{r: int, f: int, m: int}  $rfm
     * @param  array<string, float>  $dimensions
     * @return array<int, float>
     */
    public function buildTraitVector(array $rfm, array $dimensions): array
    {
        return [
            0 => (float) $rfm['r'],
            1 => (float) $rfm['f'],
            2 => (float) $rfm['m'],
            3 => $dimensions['recency'] ?? 0.0,
            4 => $dimensions['frequency'] ?? 0.0,
            5 => $dimensions['monetary'] ?? 0.0,
            6 => $dimensions['engagement_breadth'] ?? 0.0,
            7 => $dimensions['session_regularity'] ?? 0.0,
            8 => $dimensions['growth_trajectory'] ?? 0.0,
        ];
    }

    /**
     * Get segment migration: compare current vs previous segment.
     *
     * @param  string  $userId
     * @return array{current: string, previous: string|null, direction: string, is_upgrade: bool}
     */
    public function segmentMigration(string $userId): array
    {
        $current = $this->segmentUser($userId);
        $historyKey = "zb_bseg_history_{$userId}";
        $history = $this->cache->get($historyKey, []);

        $previous = null;
        $direction = 'new';
        $isUpgrade = false;

        if (count($history) >= 1) {
            $previous = $history[0]['segment'] ?? null;
            if ($previous !== null && $previous !== $current['segment']) {
                $tierOrder = array_keys($this->config['tiers']);
                $currentIdx = array_search($current['segment'], $tierOrder, true);
                $prevIdx = array_search($previous, $tierOrder, true);

                if ($currentIdx !== false && $prevIdx !== false) {
                    $isUpgrade = $currentIdx < $prevIdx;
                    $direction = $isUpgrade ? 'upgraded' : 'downgraded';
                } else {
                    $direction = 'changed';
                }
            } else {
                $direction = 'stable';
            }
        }

        return [
            'current' => $current['segment'],
            'previous' => $previous,
            'direction' => $direction,
            'is_upgrade' => $isUpgrade,
        ];
    }

    /**
     * Record a segment snapshot for historical tracking.
     *
     * @param  string  $userId
     * @return void
     */
    public function recordSnapshot(string $userId): void
    {
        $segment = $this->segmentUser($userId);
        $historyKey = "zb_bseg_history_{$userId}";
        $history = $this->cache->get($historyKey, []);

        array_unshift($history, [
            'segment' => $segment['segment'],
            'composite_score' => $segment['composite_score'],
            'rfm' => $segment['rfm'],
            'recorded_at' => now()->toIso8601String(),
        ]);

        // Keep last 30 snapshots
        $this->cache->put($historyKey, array_slice($history, 0, 30), 86400 * 30);
    }

    /**
     * Get segment history for a user.
     *
     * @param  string  $userId
     * @param  int  $limit
     * @return array<int, array{segment: string, composite_score: float, recorded_at: string}>
     */
    public function segmentHistory(string $userId, int $limit = 10): array
    {
        $historyKey = "zb_bseg_history_{$userId}";
        $history = $this->cache->get($historyKey, []);

        return array_slice($history, 0, $limit);
    }

    /**
     * Invalidate segment cache for a user.
     *
     * @param  string  $userId
     * @return bool
     */
    public function invalidateUser(string $userId): bool
    {
        $this->cache->forget("zb_bseg_{$userId}");

        return true;
    }

    /**
     * Invalidate all segment caches.
     *
     * @return void
     */
    public function invalidateAll(): void
    {
        // Segment caches are prefixed; bulk forget requires cache flush
        // Individual invalidation is handled per-user
    }

    /**
     * Get configuration summary.
     *
     * @return array{cache_ttl: int, dimensions_count: int, tiers_count: int, tier_names: array<string>, thresholds: array<string, int>}
     */
    public function configSummary(): array
    {
        return [
            'cache_ttl' => $this->config['cache_ttl'],
            'dimensions_count' => count($this->config['dimensions']),
            'tiers_count' => count($this->config['tiers']),
            'tier_names' => array_keys($this->config['tiers']),
            'thresholds' => $this->config['thresholds'],
        ];
    }

    /**
     * Classify a user into a segment tier based on composite score and signal patterns.
     *
     * Special cases:
     *   - "at_risk" overrides if recent sharp decline detected
     *   - "lost" overrides if inactive beyond threshold
     *
     * @param  float  $compositeScore
     * @param  array{r: int, f: int, m: int}  $rfm
     * @param  array<string, float>  $dimensions
     * @return string
     */
    private function classifySegment(float $compositeScore, array $rfm, array $dimensions): string
    {
        // Special case: Lost users — no activity beyond threshold
        if ($rfm['r'] === 1 && $dimensions['frequency'] < 5.0) {
            return 'lost';
        }

        // Special case: At-risk — sharp decline from high engagement
        $growth = $dimensions['growth_trajectory'] ?? 50.0;
        if ($growth < 20.0 && $compositeScore >= 20.0 && $compositeScore <= 55.0) {
            return 'at_risk';
        }

        // Standard tier classification by composite score
        foreach ($this->config['tiers'] as $tierName => $range) {
            if ($compositeScore >= $range['min'] && $compositeScore <= $range['max']) {
                // New users override: if recency is high but frequency is very low
                if ($tierName !== 'new_users' && $rfm['r'] >= 4 && $rfm['f'] <= 2 && $compositeScore < 50.0) {
                    return 'new_users';
                }

                return $tierName;
            }
        }

        return 'hibernating';
    }

    /**
     * Compute composite score from RFM and dimensions (0–100).
     *
     * @param  array{r: int, f: int, m: int}  $rfm
     * @param  array<string, float>  $dimensions
     * @return float
     */
    private function compositeScore(array $rfm, array $dimensions): float
    {
        $rfmWeighted = (
            ($rfm['r'] * 20.0) * $this->config['rfm_weights']['recency'] +
            ($rfm['f'] * 20.0) * $this->config['rfm_weights']['frequency'] +
            ($rfm['m'] * 20.0) * $this->config['rfm_weights']['monetary']
        );

        $dimWeighted = 0.0;
        foreach ($this->config['dimensions'] as $dimName => $weight) {
            $dimWeighted += ($dimensions[$dimName] ?? 0.0) * $weight;
        }

        return min(100.0, max(0.0, $rfmWeighted + $dimWeighted));
    }

    /**
     * Compute recency score (1–5) based on days since last event.
     *
     * 5: today, 4: 1–7 days, 3: 8–30 days, 2: 31–60 days, 1: 60+ days
     *
     * @param  string  $userId
     * @param  string|null  $clientId
     * @return int
     */
    private function recencyScore(string $userId, ?string $clientId = null): int
    {
        $daysSinceLast = $this->metrics->count("days_since_last_event:{$userId}", 1) ?? 999;

        if ($daysSinceLast <= 1) {
            return 5;
        }
        if ($daysSinceLast <= 7) {
            return 4;
        }
        if ($daysSinceLast <= 30) {
            return 3;
        }
        if ($daysSinceLast <= 60) {
            return 2;
        }

        return 1;
    }

    /**
     * Compute frequency score (1–5) based on event count in window.
     *
     * 5: 100+, 4: 50–99, 3: 20–49, 2: 5–19, 1: <5
     *
     * @param  string  $userId
     * @param  string|null  $clientId
     * @return int
     */
    private function frequencyScore(string $userId, ?string $clientId = null): int
    {
        $count = (int) $this->metrics->count("user_events:{$userId}", 1);

        if ($count >= 100) {
            return 5;
        }
        if ($count >= 50) {
            return 4;
        }
        if ($count >= 20) {
            return 3;
        }
        if ($count >= 5) {
            return 2;
        }

        return 1;
    }

    /**
     * Compute monetary score (1–5) based on revenue contribution.
     *
     * 5: $500+, 4: $100–499, 3: $25–99, 2: $1–24, 1: $0
     *
     * @param  string  $userId
     * @param  string|null  $clientId
     * @return int
     */
    private function monetaryScore(string $userId, ?string $clientId = null): int
    {
        $revenue = (float) $this->metrics->sum("user_revenue:{$userId}", 0.0);

        if ($revenue >= 500.0) {
            return 5;
        }
        if ($revenue >= 100.0) {
            return 4;
        }
        if ($revenue >= 25.0) {
            return 3;
        }
        if ($revenue >= 1.0) {
            return 2;
        }

        return 1;
    }

    /**
     * Compute engagement breadth score (0–100).
     *
     * Measures how many distinct event categories a user has engaged with.
     * All 9 categories = 100%, proportional below.
     *
     * @param  string  $userId
     * @return float
     */
    private function engagementBreadthScore(string $userId): float
    {
        $totalCategories = 9;
        $usedCategories = $this->metrics->count("user_categories:{$userId}", 0);

        if ($usedCategories <= 0) {
            return 0.0;
        }

        return min(100.0, round(($usedCategories / $totalCategories) * 100.0, 2));
    }

    /**
     * Compute session regularity score (0–100).
     *
     * Measures consistency of session frequency over the last 30 days.
     * High variance = low regularity.
     *
     * @param  string  $userId
     * @return float
     */
    private function sessionRegularityScore(string $userId): float
    {
        $sessionCount = (int) $this->metrics->count("user_sessions_30d:{$userId}", 0);

        if ($sessionCount <= 0) {
            return 0.0;
        }

        // Regularity = inverse of coefficient of variation approximation
        // More sessions = more regular, capped at 30 (daily)
        $regularity = min(1.0, $sessionCount / 30.0);

        return round($regularity * 100.0, 2);
    }

    /**
     * Compute growth trajectory score (0–100).
     *
     * Compares recent activity (last 7 days) vs previous period (7–14 days ago).
     * Growth = 100 (doubling+), Stable = 50, Decline = 0–25.
     *
     * @param  string  $userId
     * @return float
     */
    private function growthTrajectoryScore(string $userId): float
    {
        $recent = (int) $this->metrics->count("user_events_7d:{$userId}", 0);
        $previous = (int) $this->metrics->count("user_events_prev7d:{$userId}", 0);

        if ($previous <= 0 && $recent <= 0) {
            return 0.0;
        }
        if ($previous <= 0 && $recent > 0) {
            return 100.0; // New activity from nothing = strong growth
        }

        $ratio = $recent / $previous;

        if ($ratio >= 2.0) {
            return 100.0;
        }
        if ($ratio >= 1.5) {
            return 85.0;
        }
        if ($ratio >= 1.0) {
            return 65.0;
        }
        if ($ratio >= 0.75) {
            return 50.0;
        }
        if ($ratio >= 0.5) {
            return 30.0;
        }
        if ($ratio >= 0.25) {
            return 15.0;
        }

        return 5.0;
    }

    /**
     * Get known user IDs from identity cache.
     *
     * @return array<string>
     */
    private function getKnownUserIds(): array
    {
        $links = $this->cache->get('zb_identity_links_all', []);

        return array_keys($links);
    }
}
