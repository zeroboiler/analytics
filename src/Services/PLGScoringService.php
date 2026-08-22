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
 * Product-Led Growth (PLG) scoring engine for SaaS products.
 *
 * Computes per-identity PLG scores based on activation signals,
 * engagement depth, retention behavior, and feature adoption breadth.
 * Each dimension contributes a weighted sub-score to a composite
 * PLG score (0–100) with a letter grade.
 *
 * Scores are cache-backed per identity with configurable TTL.
 * Designed for admin dashboards, cohort segmentation, and
 * automated lifecycle triggers.
 *
 * Dimensions:
 *   - Activation (0–100): Did the user reach their aha moment?
 *   - Engagement (0–100): How deeply is the user using the product?
 *   - Retention (0–100): Is the user coming back consistently?
 *   - Feature Breadth (0–100): How many distinct features has the user tried?
 *
 * @phpstan-type PLGScore array{score: float, grade: string, activation: float, engagement: float, retention: float, feature_breadth: float, segment: string, signals: list<string>, identity: string, computed_at: string}
 *
 * @since 6.0.0
 */
final class PLGScoringService
{
    private const CACHE_PREFIX = 'zb_plg_';

    /** @var int Default cache TTL in seconds (1 hour) */
    private const DEFAULT_TTL = 3600;

    /** @var array<string, float> Dimension weights (must sum to 1.0) */
    private const DEFAULT_WEIGHTS = [
        'activation' => 0.30,
        'engagement' => 0.30,
        'retention' => 0.25,
        'feature_breadth' => 0.15,
    ];

    /** @var array<string, int> Event weights for engagement scoring */
    private const ENGAGEMENT_EVENT_WEIGHTS = [
        'session_start' => 1,
        'page_view' => 1,
        'click' => 2,
        'form_start' => 3,
        'form_submit' => 4,
        'search' => 3,
        'share' => 5,
        'content_engagement' => 3,
        'feature_used' => 5,
    ];

    /** @var list<string> Activation signal events */
    private const ACTIVATION_SIGNALS = [
        'milestone_reached',
        'feature_used',
        'form_submit',
        'search',
        'content_engagement',
    ];

    /** @var list<string> Retention signal events */
    private const RETENTION_SIGNALS = [
        'session_start',
        'login',
        'page_view',
    ];

    /** @var list<string> Feature breadth signal events */
    private const BREADTH_SIGNALS = [
        'feature_used',
        'search',
        'form_submit',
        'share',
        'content_engagement',
        'click',
        'file_download',
        'video_play',
        'export',
        'import',
        'integration_connected',
    ];

    private array $weights;

    private int $cacheTtl;

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    /**
     * @param  AnalyticsManager  $manager  Analytics manager for event queries
     * @param  CacheRepository  $cache  Cache store for score persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $plgConfig = $config->get('zeroboiler.analytics.plg_scoring', []);
        /** @var array{weights?: array<string, float>, cache_ttl?: int} $plgConfig */

        $this->weights = $plgConfig['weights'] ?? self::DEFAULT_WEIGHTS;
        $this->cacheTtl = (int) ($plgConfig['cache_ttl'] ?? self::DEFAULT_TTL);
    }

    /**
     * Compute PLG score for a given identity.
     *
     * @param  string  $identity  User ID or client tracking ID
     * @return PLGScore
     */
    public function score(string $identity): array
    {
        $cacheKey = self::CACHE_PREFIX . hash('xxh128', $identity);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['score'])) {
            return $cached;
        }

        $activation = $this->computeActivation($identity);
        $engagement = $this->computeEngagement($identity);
        $retention = $this->computeRetention($identity);
        $featureBreadth = $this->computeFeatureBreadth($identity);

        $score = ($activation * ($this->weights['activation'] ?? 0.30))
            + ($engagement * ($this->weights['engagement'] ?? 0.30))
            + ($retention * ($this->weights['retention'] ?? 0.25))
            + ($featureBreadth * ($this->weights['feature_breadth'] ?? 0.15));

        $score = round(min($score, 100.0), 1);
        $grade = $this->grade($score);
        $segment = $this->segment($score);
        $signals = $this->collectSignals($identity);

        $result = [
            'score' => $score,
            'grade' => $grade,
            'activation' => $activation,
            'engagement' => $engagement,
            'retention' => $retention,
            'feature_breadth' => $featureBreadth,
            'segment' => $segment,
            'signals' => $signals,
            'identity' => $identity,
            'computed_at' => now()->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Batch-compute PLG scores for multiple identities.
     *
     * @param  list<string>  $identities  User/client IDs
     * @return array<string, PLGScore>
     */
    public function scoreBatch(array $identities): array
    {
        $results = [];

        foreach ($identities as $identity) {
            $results[$identity] = $this->score($identity);
        }

        uasort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Get segment distribution across a set of identities.
     *
     * @param  list<string>  $identities  User/client IDs
     * @return array{champions: int, loyal: int, potential: int, at_risk: int, dormant: int, unsegmented: int}
     */
    public function segmentDistribution(array $identities): array
    {
        $distribution = [
            'champions' => 0,
            'loyal' => 0,
            'potential' => 0,
            'at_risk' => 0,
            'dormant' => 0,
            'unsegmented' => 0,
        ];

        foreach ($identities as $identity) {
            $plg = $this->score($identity);
            $segment = $plg['segment'];

            if (isset($distribution[$segment])) {
                $distribution[$segment]++;
            } else {
                $distribution['unsegmented']++;
            }
        }

        return $distribution;
    }

    /**
     * Get top activation signals for an identity.
     *
     * @param  string  $identity  User/client ID
     * @return list<string>  Signal descriptions
     */
    public function activationSignals(string $identity): array
    {
        return $this->collectSignals($identity);
    }

    /**
     * Invalidate cached PLG score for an identity.
     */
    public function invalidateScore(string $identity): void
    {
        $cacheKey = self::CACHE_PREFIX . hash('xxh128', $identity);
        $this->cache->forget($cacheKey);
    }

    /**
     * Get the average PLG score across all cached scores.
     *
     * Scans the cache prefix and averages all stored scores.
     * Useful for dashboard-level metrics.
     *
     * @return array{avg_score: float, total_cached: int, grade_distribution: array<string, int>}
     */
    public function aggregateStats(): array
    {
        $scores = [];
        $gradeDist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

        // This is a best-effort scan — not all cache drivers support prefix scanning
        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(500);

            $seenIdentities = [];
            foreach ($recent as $event) {
                $userId = $event['user_id'] ?? $event['client_id'] ?? null;
                if ($userId !== null && is_string($userId) && $userId !== '' && ! isset($seenIdentities[$userId])) {
                    $seenIdentities[$userId] = true;
                    $plg = $this->score($userId);
                    $scores[] = $plg['score'];
                    $grade = $plg['grade'];
                    if (isset($gradeDist[$grade])) {
                        $gradeDist[$grade]++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Services not available
        }

        $avgScore = count($scores) > 0
            ? round(array_sum($scores) / count($scores), 1)
            : 0.0;

        return [
            'avg_score' => $avgScore,
            'total_cached' => count($scores),
            'grade_distribution' => $gradeDist,
        ];
    }

    /**
     * Compute activation score (0–100).
     *
     * Activation = ratio of activation signal events detected for this identity
     * relative to the total possible signals. A user who has triggered at least
     * 2 distinct activation signals scores above 60.
     */
    private function computeActivation(string $identity): float
    {
        $signalCount = 0;
        $totalSignals = count(self::ACTIVATION_SIGNALS);

        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(1000);

            $seenSignals = [];
            foreach ($recent as $event) {
                $userId = $event['user_id'] ?? $event['client_id'] ?? null;
                if ($userId !== $identity) {
                    continue;
                }

                $eventName = $event['event'] ?? '';
                if (in_array($eventName, self::ACTIVATION_SIGNALS, true) && ! isset($seenSignals[$eventName])) {
                    $seenSignals[$eventName] = true;
                    $signalCount++;
                }
            }
        } catch (\Throwable $e) {
            return 0.0;
        }

        if ($totalSignals === 0) {
            return 0.0;
        }

        // Non-linear scaling: 2 signals = 60%, 3+ = 80%+
        $ratio = $signalCount / $totalSignals;
        $boosted = $signalCount >= 2 ? min($ratio * 1.8, 1.0) : $ratio;

        return round($boosted * 100, 1);
    }

    /**
     * Compute engagement score (0–100).
     *
     * Engagement = weighted sum of engagement events divided by the
     * maximum possible weighted engagement depth.
     */
    private function computeEngagement(string $identity): float
    {
        $weightedScore = 0;
        $maxPossibleScore = 0;

        foreach (self::ENGAGEMENT_EVENT_WEIGHTS as $event => $weight) {
            $maxPossibleScore += $weight * 3; // Assume 3 occurrences per event as baseline
        }

        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(1000);

            $eventCounts = [];
            foreach ($recent as $entry) {
                $userId = $entry['user_id'] ?? $entry['client_id'] ?? null;
                if ($userId !== $identity) {
                    continue;
                }

                $eventName = $entry['event'] ?? '';
                if (isset(self::ENGAGEMENT_EVENT_WEIGHTS[$eventName])) {
                    $eventCounts[$eventName] = ($eventCounts[$eventName] ?? 0) + 1;
                }
            }

            foreach ($eventCounts as $eventName => $count) {
                $weight = self::ENGAGEMENT_EVENT_WEIGHTS[$eventName];
                $weightedScore += $weight * min($count, 5); // Cap at 5 per event
            }
        } catch (\Throwable $e) {
            return 0.0;
        }

        if ($maxPossibleScore === 0) {
            return 0.0;
        }

        return round(min(($weightedScore / $maxPossibleScore) * 100, 100.0), 1);
    }

    /**
     * Compute retention score (0–100).
     *
     * Retention = frequency of retention signal events (login, session_start, page_view)
     * relative to the total retention signals in the stream.
     */
    private function computeRetention(string $identity): float
    {
        $identityRetentionEvents = 0;
        $totalRetentionEvents = 0;

        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(1000);

            foreach ($recent as $entry) {
                $eventName = $entry['event'] ?? '';

                if (! in_array($eventName, self::RETENTION_SIGNALS, true)) {
                    continue;
                }

                $totalRetentionEvents++;
                $userId = $entry['user_id'] ?? $entry['client_id'] ?? null;

                if ($userId === $identity) {
                    $identityRetentionEvents++;
                }
            }
        } catch (\Throwable $e) {
            return 0.0;
        }

        if ($totalRetentionEvents === 0) {
            return 0.0;
        }

        // Scale: a user with retention events equal to 10% of total retention = 100%
        // (assuming many users in the system)
        $ratio = $identityRetentionEvents / $totalRetentionEvents;
        $scaled = min($ratio * 20, 1.0); // 5% of total = full score

        return round($scaled * 100, 1);
    }

    /**
     * Compute feature breadth score (0–100).
     *
     * Feature breadth = number of distinct feature-related events the user
     * has triggered, relative to the total possible feature signals.
     */
    private function computeFeatureBreadth(string $identity): float
    {
        $uniqueFeatures = 0;
        $totalFeatures = count(self::BREADTH_SIGNALS);

        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(1000);

            $seenFeatures = [];
            foreach ($recent as $entry) {
                $userId = $entry['user_id'] ?? $entry['client_id'] ?? null;
                if ($userId !== $identity) {
                    continue;
                }

                $eventName = $entry['event'] ?? '';
                if (in_array($eventName, self::BREADTH_SIGNALS, true) && ! isset($seenFeatures[$eventName])) {
                    $seenFeatures[$eventName] = true;
                    $uniqueFeatures++;
                }
            }
        } catch (\Throwable $e) {
            return 0.0;
        }

        if ($totalFeatures === 0) {
            return 0.0;
        }

        // Non-linear scaling: using 50%+ of features = 80%+ score
        $ratio = $uniqueFeatures / $totalFeatures;
        $boosted = $uniqueFeatures >= 3 ? min($ratio * 1.5, 1.0) : $ratio;

        return round($boosted * 100, 1);
    }

    /**
     * Determine letter grade from numeric score.
     */
    private function grade(float $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 65 => 'B',
            $score >= 50 => 'C',
            $score >= 35 => 'D',
            default => 'F',
        };
    }

    /**
     * Determine user segment from numeric score.
     */
    private function segment(float $score): string
    {
        return match (true) {
            $score >= 80 => 'champions',
            $score >= 65 => 'loyal',
            $score >= 45 => 'potential',
            $score >= 25 => 'at_risk',
            $score > 0 => 'dormant',
            default => 'unsegmented',
        };
    }

    /**
     * Collect human-readable activation signal descriptions.
     *
     * @param  string  $identity  User/client ID
     * @return list<string>
     */
    private function collectSignals(string $identity): array
    {
        $signals = [];

        try {
            $streamService = app(EventStreamService::class);
            $recent = $streamService->getRecentEvents(500);

            $seen = [];
            foreach ($recent as $entry) {
                $userId = $entry['user_id'] ?? $entry['client_id'] ?? null;
                if ($userId !== $identity) {
                    continue;
                }

                $eventName = $entry['event'] ?? '';
                $category = EventCatalog::getCategory($eventName);

                if ($category !== null && ! isset($seen[$eventName])) {
                    $seen[$eventName] = true;
                    $signals[] = "{$eventName} ({$category})";
                }
            }
        } catch (\Throwable $e) {
            // Services not available
        }

        return $signals;
    }
}
