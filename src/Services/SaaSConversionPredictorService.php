<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS Conversion Predictor — heuristic-based conversion probability estimation.
 *
 * Scores users against configurable positive and negative signals to predict
 * the likelihood of conversion (trial → paid, free → paid, lead → signup).
 * Uses a weighted signal scoring model inspired by industry approaches
 * from Segment, Mixpanel, and Amplitude — no external ML dependencies required.
 *
 * Signal categories:
 * - Engagement signals (page views, feature usage, session depth)
 * - Activation signals (onboarding completion, first value moment)
 * - Social signals (team invitations, referrals)
 * - Temporal signals (session recency, frequency)
 * - Negative signals (errors, support tickets, inactivity)
 *
 * @since 193.0.0
 */
final class SaaSConversionPredictorService
{
    /**
     * Default positive signals with base weights.
     *
     * @var array<string, array{weight: float, label: string, category: string}>
     */
    private const POSITIVE_SIGNALS = [
        'page_views_high' => [
            'weight' => 10.0,
            'label' => 'High page view count (≥10 in 7 days)',
            'category' => 'engagement',
        ],
        'feature_used_count' => [
            'weight' => 15.0,
            'label' => 'Multiple features used (≥3 unique)',
            'category' => 'engagement',
        ],
        'onboarding_completed' => [
            'weight' => 20.0,
            'label' => 'Onboarding flow completed',
            'category' => 'activation',
        ],
        'first_value_moment' => [
            'weight' => 25.0,
            'label' => 'First value moment reached',
            'category' => 'activation',
        ],
        'session_frequency_high' => [
            'weight' => 12.0,
            'label' => 'High session frequency (≥5 sessions in 7 days)',
            'category' => 'temporal',
        ],
        'session_recency_recent' => [
            'weight' => 15.0,
            'label' => 'Active session within 24 hours',
            'category' => 'temporal',
        ],
        'team_invited' => [
            'weight' => 18.0,
            'label' => 'Invited team members',
            'category' => 'social',
        ],
        'referral_shared' => [
            'weight' => 10.0,
            'label' => 'Shared referral link',
            'category' => 'social',
        ],
        'form_submitted' => [
            'weight' => 8.0,
            'label' => 'Completed a form submission',
            'category' => 'engagement',
        ],
        'search_used' => [
            'weight' => 7.0,
            'label' => 'Used search functionality',
            'category' => 'engagement',
        ],
    ];

    /**
     * Default negative signals with base weights.
     *
     * @var array<string, array{weight: float, label: string, category: string}>
     */
    private const NEGATIVE_SIGNALS = [
        'errors_count' => [
            'weight' => 12.0,
            'label' => 'Multiple errors encountered (≥3 in 7 days)',
            'category' => 'negative',
        ],
        'support_ticket' => [
            'weight' => 8.0,
            'label' => 'Created support ticket',
            'category' => 'negative',
        ],
        'long_inactivity' => [
            'weight' => 15.0,
            'label' => 'No activity in 3+ days',
            'category' => 'temporal',
        ],
        'session_bounce' => [
            'weight' => 10.0,
            'label' => 'Single-page session (bounce)',
            'category' => 'negative',
        ],
    ];

    private const CACHE_PREFIX = 'zb_conversion_predictor_';
    private const CACHE_TTL_DEFAULT = 3600; // 1 hour

    private ConfigRepository $config;

    private CacheRepository $cache;

    /** @var array<string, float> Custom positive signal weight overrides */
    private array $customPositiveWeights = [];

    /** @var array<string, float> Custom negative signal weight overrides */
    private array $customNegativeWeights = [];

    private bool $enabled;

    private int $cacheTtl;

    /**
     * @param  ConfigRepository  $config  Configuration repository
     * @param  CacheRepository  $cache  Cache repository for prediction caching
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $this->config = $config;
        $this->cache = $cache;

        $predictorConfig = $config->get('zeroboiler.analytics.conversion_predictor', []);
        /** @var array{enabled?: bool, cache_ttl?: int} $predictorConfig */
        $this->enabled = (bool) ($predictorConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($predictorConfig['cache_ttl'] ?? self::CACHE_TTL_DEFAULT);

        // Load custom weight overrides from config
        $customWeights = $predictorConfig['custom_weights'] ?? [];
        /** @var array{positive?: array<string, float>, negative?: array<string, float>} $customWeights */
        $this->customPositiveWeights = $customWeights['positive'] ?? [];
        $this->customNegativeWeights = $customWeights['negative'] ?? [];
    }

    /**
     * Check if the predictor service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Predict conversion probability for a user based on their event signals.
     *
     * Accepts a map of signal names to boolean/numeric values representing
     * the presence or magnitude of each signal. Returns a prediction result
     * with score, probability, grade, matched signals, and recommendations.
     *
     * @param  string  $userId  The user to predict for
     * @param  array<string, bool|float|int>  $signals  Signal name → value map
     * @return array{score: float, probability: float, grade: string, category: string, matched_positive: list<string>, matched_negative: list<string>, signal_breakdown: array<string, array{weight: float, matched: bool, value: bool|float|int, label: string, category: string}>, recommendations: list<string>, user_id: string, predicted_at: int}
     */
    public function predict(string $userId, array $signals = []): array
    {
        $cacheKey = self::CACHE_PREFIX . $userId;

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && $signals === []) {
            /** @var array{score: float, probability: float, grade: string, category: string, matched_positive: list<string>, matched_negative: list<string>, signal_breakdown: array<string, array{weight: float, matched: bool, value: bool|float|int, label: string, category: string}>, recommendations: list<string>, user_id: string, predicted_at: int} $cached */
            return $cached;
        }

        $positiveScore = 0.0;
        $maxPositiveScore = 0.0;
        $negativeScore = 0.0;
        $maxNegativeScore = 0.0;
        $matchedPositive = [];
        $matchedNegative = [];
        $signalBreakdown = [];

        // Evaluate positive signals
        foreach (self::POSITIVE_SIGNALS as $name => $def) {
            $weight = $this->customPositiveWeights[$name] ?? $def['weight'];
            $maxPositiveScore += $weight;
            $value = $signals[$name] ?? false;
            $matched = $this->isSignalMatched($value);

            if ($matched) {
                $positiveScore += $weight;
                $matchedPositive[] = $name;
            }

            $signalBreakdown[$name] = [
                'weight' => $weight,
                'matched' => $matched,
                'value' => $value,
                'label' => $def['label'],
                'category' => $def['category'],
            ];
        }

        // Evaluate negative signals
        foreach (self::NEGATIVE_SIGNALS as $name => $def) {
            $weight = $this->customNegativeWeights[$name] ?? $def['weight'];
            $maxNegativeScore += $weight;
            $value = $signals[$name] ?? false;
            $matched = $this->isSignalMatched($value);

            if ($matched) {
                $negativeScore += $weight;
                $matchedNegative[] = $name;
            }

            $signalBreakdown[$name] = [
                'weight' => $weight,
                'matched' => $matched,
                'value' => $value,
                'label' => $def['label'],
                'category' => $def['category'],
            ];
        }

        $totalMax = $maxPositiveScore + $maxNegativeScore;
        $rawScore = $totalMax > 0 ? ($positiveScore - $negativeScore) / $totalMax : 0.0;
        $normalizedScore = max(0.0, min(1.0, ($rawScore + 1.0) / 2.0));
        $grade = $this->scoreToGrade($normalizedScore);
        $category = $this->scoreToCategory($normalizedScore);
        $recommendations = $this->generateRecommendations($matchedPositive, $matchedNegative, $normalizedScore);

        $result = [
            'score' => round($normalizedScore, 4),
            'probability' => round($normalizedScore * 100, 1),
            'grade' => $grade,
            'category' => $category,
            'matched_positive' => $matchedPositive,
            'matched_negative' => $matchedNegative,
            'signal_breakdown' => $signalBreakdown,
            'recommendations' => $recommendations,
            'user_id' => $userId,
            'predicted_at' => time(),
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Predict conversion for a batch of users.
     *
     * @param  array<string, array<string, bool|float|int>>  $userSignals  Map of user_id → signals
     * @return array{results: array<string, array{score: float, probability: float, grade: string, category: string}>, summary: array{total: int, high_intent: int, medium_intent: int, low_intent: int, unlikely: int, avg_score: float}}
     */
    public function predictBatch(array $userSignals): array
    {
        $results = [];
        $totalScore = 0.0;
        $counts = ['high_intent' => 0, 'medium_intent' => 0, 'low_intent' => 0, 'unlikely' => 0];

        foreach ($userSignals as $userId => $signals) {
            $prediction = $this->predict($userId, $signals);
            $results[$userId] = [
                'score' => $prediction['score'],
                'probability' => $prediction['probability'],
                'grade' => $prediction['grade'],
                'category' => $prediction['category'],
            ];
            $totalScore += $prediction['score'];

            match ($prediction['category']) {
                'high_intent' => $counts['high_intent']++,
                'medium_intent' => $counts['medium_intent']++,
                'low_intent' => $counts['low_intent']++,
                default => $counts['unlikely']++,
            };
        }

        $total = count($results);
        $avgScore = $total > 0 ? round($totalScore / $total, 4) : 0.0;

        return [
            'results' => $results,
            'summary' => [
                'total' => $total,
                'high_intent' => $counts['high_intent'],
                'medium_intent' => $counts['medium_intent'],
                'low_intent' => $counts['low_intent'],
                'unlikely' => $counts['unlikely'],
                'avg_score' => $avgScore,
            ],
        ];
    }

    /**
     * Get the top N users most likely to convert.
     *
     * @param  array<string, array<string, bool|float|int>>  $userSignals  Map of user_id → signals
     * @param  int  $limit  Number of results to return
     * @return list<array{user_id: string, score: float, probability: float, grade: string, category: string, matched_positive: list<string>, matched_negative: list<string>}>
     */
    public function topProspects(array $userSignals, int $limit = 10): array
    {
        $predictions = [];

        foreach ($userSignals as $userId => $signals) {
            $prediction = $this->predict($userId, $signals);
            $predictions[] = [
                'user_id' => $prediction['user_id'],
                'score' => $prediction['score'],
                'probability' => $prediction['probability'],
                'grade' => $prediction['grade'],
                'category' => $prediction['category'],
                'matched_positive' => $prediction['matched_positive'],
                'matched_negative' => $prediction['matched_negative'],
            ];
        }

        usort($predictions, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($predictions, 0, $limit);
    }

    /**
     * Build a signal map from a user's event history.
     *
     * Translates raw event counts and timestamps into the signal format
     * expected by predict(). Useful for converting analytics event data
     * into prediction inputs.
     *
     * @param  array{page_views?: int, feature_used_count?: int, onboarding_completed?: bool, first_value_moment?: bool, session_count_7d?: int, last_session_hours_ago?: float, team_invited?: bool, referral_shared?: bool, form_submitted?: bool, search_count?: int, error_count_7d?: int, support_tickets?: int, pages_per_session?: float}  $eventSummary
     * @return array<string, bool|float|int>
     */
    public function buildSignalMap(array $eventSummary): array
    {
        return [
            'page_views_high' => ($eventSummary['page_views'] ?? 0) >= 10,
            'feature_used_count' => ($eventSummary['feature_used_count'] ?? 0) >= 3,
            'onboarding_completed' => (bool) ($eventSummary['onboarding_completed'] ?? false),
            'first_value_moment' => (bool) ($eventSummary['first_value_moment'] ?? false),
            'session_frequency_high' => ($eventSummary['session_count_7d'] ?? 0) >= 5,
            'session_recency_recent' => ($eventSummary['last_session_hours_ago'] ?? 999) <= 24,
            'team_invited' => (bool) ($eventSummary['team_invited'] ?? false),
            'referral_shared' => (bool) ($eventSummary['referral_shared'] ?? false),
            'form_submitted' => (bool) ($eventSummary['form_submitted'] ?? false),
            'search_used' => ($eventSummary['search_count'] ?? 0) > 0,
            'errors_count' => ($eventSummary['error_count_7d'] ?? 0) >= 3,
            'support_ticket' => ($eventSummary['support_tickets'] ?? 0) > 0,
            'long_inactivity' => ($eventSummary['last_session_hours_ago'] ?? 999) >= 72,
            'session_bounce' => ($eventSummary['pages_per_session'] ?? 1.0) <= 1.0,
        ];
    }

    /**
     * Get all available positive signals.
     *
     * @return array<string, array{weight: float, label: string, category: string}>
     */
    public static function positiveSignals(): array
    {
        return self::POSITIVE_SIGNALS;
    }

    /**
     * Get all available negative signals.
     *
     * @return array<string, array{weight: float, label: string, category: string}>
     */
    public static function negativeSignals(): array
    {
        return self::NEGATIVE_SIGNALS;
    }

    /**
     * Get service statistics and configuration info.
     *
     * @return array{enabled: bool, cache_ttl: int, positive_signal_count: int, negative_signal_count: int, total_signal_count: int, custom_weight_overrides: int, max_possible_score: float, signal_categories: list<string>, grades: array<string, array{min: float, max: float}>}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'positive_signal_count' => count(self::POSITIVE_SIGNALS),
            'negative_signal_count' => count(self::NEGATIVE_SIGNALS),
            'total_signal_count' => count(self::POSITIVE_SIGNALS) + count(self::NEGATIVE_SIGNALS),
            'custom_weight_overrides' => count($this->customPositiveWeights) + count($this->customNegativeWeights),
            'max_possible_score' => 1.0,
            'signal_categories' => ['engagement', 'activation', 'temporal', 'social', 'negative'],
            'grades' => [
                'A+' => ['min' => 0.85, 'max' => 1.0],
                'A' => ['min' => 0.70, 'max' => 0.85],
                'B+' => ['min' => 0.55, 'max' => 0.70],
                'B' => ['min' => 0.40, 'max' => 0.55],
                'C' => ['min' => 0.25, 'max' => 0.40],
                'D' => ['min' => 0.10, 'max' => 0.25],
                'F' => ['min' => 0.0, 'max' => 0.10],
            ],
        ];
    }

    /**
     * Clear cached prediction for a user.
     */
    public function clearCache(string $userId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $userId);
    }

    /**
     * Clear all cached predictions.
     */
    public function clearAllCache(): void
    {
        // Cache forget by prefix is not natively supported;
        // individual keys need to be cleared. This is a no-op placeholder
        // for implementations with cache tag support.
    }

    /**
     * Check if a signal value represents a match.
     *
     * @param  bool|float|int  $value  Signal value
     */
    private function isSignalMatched(bool|float|int $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return (float) $value > 0;
    }

    /**
     * Convert a normalized score (0.0–1.0) to a letter grade.
     */
    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'A+',
            $score >= 0.70 => 'A',
            $score >= 0.55 => 'B+',
            $score >= 0.40 => 'B',
            $score >= 0.25 => 'C',
            $score >= 0.10 => 'D',
            default => 'F',
        };
    }

    /**
     * Convert a normalized score (0.0–1.0) to an intent category.
     */
    private function scoreToCategory(float $score): string
    {
        return match (true) {
            $score >= 0.70 => 'high_intent',
            $score >= 0.40 => 'medium_intent',
            $score >= 0.15 => 'low_intent',
            default => 'unlikely',
        };
    }

    /**
     * Generate actionable recommendations based on matched signals.
     *
     * @param  list<string>  $matchedPositive
     * @param  list<string>  $matchedNegative
     * @param  float  $score
     * @return list<string>
     */
    private function generateRecommendations(array $matchedPositive, array $matchedNegative, float $score): array
    {
        $recommendations = [];

        if ($score >= 0.70) {
            $recommendations[] = 'High conversion probability — trigger upgrade prompt or trial conversion offer.';
        }

        if ($score >= 0.40 && $score < 0.70) {
            $recommendations[] = 'Medium probability — nurture with feature education and activation emails.';
        }

        if ($score < 0.40) {
            $recommendations[] = 'Low probability — focus on improving onboarding and activation signals.';
        }

        // Specific positive signal recommendations
        if (! in_array('onboarding_completed', $matchedPositive, true)) {
            $recommendations[] = 'Guide user through onboarding completion to boost activation signal.';
        }

        if (! in_array('first_value_moment', $matchedPositive, true)) {
            $recommendations[] = 'Help user reach their first value moment (aha moment) faster.';
        }

        if (! in_array('feature_used_count', $matchedPositive, true) && in_array('page_views_high', $matchedPositive, true)) {
            $recommendations[] = 'User browses but doesn\'t engage deeply — suggest feature walkthroughs.';
        }

        // Specific negative signal recommendations
        if (in_array('errors_count', $matchedNegative, true)) {
            $recommendations[] = 'User encountering errors — prioritize bug fixes and proactive support.';
        }

        if (in_array('long_inactivity', $matchedNegative, true)) {
            $recommendations[] = 'User inactive — send re-engagement email or push notification.';
        }

        if (in_array('session_bounce', $matchedNegative, true)) {
            $recommendations[] = 'High bounce rate — improve landing page or initial content relevance.';
        }

        return $recommendations;
    }
}
