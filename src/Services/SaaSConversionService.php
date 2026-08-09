<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialConvertedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionResumedEvent;
use ZeroBoiler\Analytics\Events\SaaS\MilestoneReachedEvent;

/**
 * SaaS conversion analytics service.
 *
 * Tracks and analyzes the trial-to-paid conversion funnel, user activation
 * milestones, and subscription reactivation patterns. Provides cohort-based
 * conversion metrics, activation scoring, and time-to-conversion analysis.
 *
 * Designed for SaaS products that need to understand:
 * - What percentage of trial users convert to paid?
 * - How long does it take for users to convert?
 * - Which activation milestones predict conversion?
 * - What's the win-back rate for cancelled subscriptions?
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\SaasKpiTracker
 *
 * @since 1.0.0
 */
final class SaaSConversionService
{
    /** @var string Cache prefix for conversion data */
    private const CACHE_PREFIX = 'zb_conversion_';

    /** Activation milestones that predict trial conversion. */
    private const DEFAULT_ACTIVATION_MILESTONES = [
        'first_login' => ['weight' => 0.10, 'category' => 'activation'],
        'profile_completed' => ['weight' => 0.15, 'category' => 'activation'],
        'first_feature_used' => ['weight' => 0.25, 'category' => 'activation'],
        'team_created' => ['weight' => 0.10, 'category' => 'growth'],
        'integration_connected' => ['weight' => 0.15, 'category' => 'activation'],
        'invite_sent' => ['weight' => 0.10, 'category' => 'growth'],
        'search_performed' => ['weight' => 0.05, 'category' => 'engagement'],
        'three_day_retention' => ['weight' => 0.10, 'category' => 'retention'],
    ];

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private array $activationMilestones;

    /** @var array<string, int> Activation milestone weights */
    private array $milestoneWeights;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $conversionConfig = $config->get('zeroboiler.analytics.conversion_analytics', []);
        /** @var array{enabled?: bool, cache_ttl?: int, activation_milestones?: array<string, array{weight: float, category: string}>} $conversionConfig */

        $this->enabled = (bool) ($conversionConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($conversionConfig['cache_ttl'] ?? 86400);
        $this->activationMilestones = $conversionConfig['activation_milestones'] ?? self::DEFAULT_ACTIVATION_MILESTONES;

        // Build milestone weights map
        $this->milestoneWeights = [];
        foreach ($this->activationMilestones as $milestone => $config) {
            $this->milestoneWeights[$milestone] = (float) ($config['weight'] ?? 0.0);
        }
    }

    // ── Trial Conversion Tracking ────────────────────────────────────

    /**
     * Track a trial-to-paid conversion event.
     *
     * Fires a `trial_converted` event with plan, trial duration,
     * and conversion source. Also updates the conversion metrics cache.
     *
     * @param  string  $plan  Plan the user converted to
     * @param  string|null  $trialPlan  Trial plan name
     * @param  int|null  $trialDurationDays  Days in trial
     * @param  string|null  $conversionSource  Where conversion happened
     * @param  string|null  $userId  User ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function trackTrialConversion(
        string $plan,
        ?string $trialPlan = null,
        ?int $trialDurationDays = null,
        ?string $conversionSource = null,
        ?string $userId = null,
        ?string $clientId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $event = new TrialConvertedEvent(
            plan: $plan,
            trialPlan: $trialPlan,
            trialDurationDays: $trialDurationDays,
            conversionSource: $conversionSource,
            userId: $userId,
            clientId: $clientId,
        );

        $this->manager->trackEvent($event);
        $this->incrementMetric('trial_conversions_total');
    }

    /**
     * Record a trial start for conversion funnel tracking.
     *
     * @param  string  $plan  Trial plan name
     * @param  string|null  $userId  User ID
     */
    public function recordTrialStart(string $plan, ?string $userId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->incrementMetric('trial_starts_total');

        // Track per-plan trial starts
        $this->incrementMetric("trial_starts_{$plan}");
    }

    /**
     * Calculate the trial-to-paid conversion rate.
     *
     * @return array{rate: float, trial_starts: int, conversions: int, period: string}
     */
    public function trialConversionRate(): array
    {
        $starts = $this->getMetric('trial_starts_total', 0);
        $conversions = $this->getMetric('trial_conversions_total', 0);
        $rate = $starts > 0 ? round(($conversions / $starts) * 100, 2) : 0.0;

        return [
            'rate' => $rate,
            'trial_starts' => $starts,
            'conversions' => $conversions,
            'period' => 'all_time',
        ];
    }

    /**
     * Get conversion rate by plan.
     *
     * @return array<string, array{rate: float, starts: int, conversions: int}>
     */
    public function trialConversionRateByPlan(): array
    {
        $results = [];
        $totalConversions = $this->getMetric('trial_conversions_total', 0);

        // Get all plan-specific keys from cache prefix scan
        $plans = $this->getKnownPlans();

        foreach ($plans as $plan) {
            $starts = $this->getMetric("trial_starts_{$plan}", 0);
            $conversions = $this->estimatePlanConversions($plan, $totalConversions);
            $rate = $starts > 0 ? round(($conversions / $starts) * 100, 2) : 0.0;

            $results[$plan] = [
                'rate' => $rate,
                'starts' => $starts,
                'conversions' => $conversions,
            ];
        }

        return $results;
    }

    // ── Subscription Resume (Win-Back) Tracking ──────────────────────

    /**
     * Track a subscription resumption (win-back).
     *
     * @param  string  $plan  Resumed plan
     * @param  string|null  $previousPlan  Plan before cancellation
     * @param  int|null  $daysSinceCancellation  Days between cancel and resume
     * @param  string|null  $reactivationSource  Win-back source
     * @param  string|null  $userId  User ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function trackSubscriptionResumed(
        string $plan,
        ?string $previousPlan = null,
        ?int $daysSinceCancellation = null,
        ?string $reactivationSource = null,
        ?string $userId = null,
        ?string $clientId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $event = new SubscriptionResumedEvent(
            plan: $plan,
            previousPlan: $previousPlan,
            daysSinceCancellation: $daysSinceCancellation,
            reactivationSource: $reactivationSource,
            userId: $userId,
            clientId: $clientId,
        );

        $this->manager->trackEvent($event);
        $this->incrementMetric('subscriptions_resumed_total');
    }

    /**
     * Calculate the win-back rate (resumed / cancelled).
     *
     * @param  int  $totalCancellations  Total cancellations to compare against
     * @return array{rate: float, resumed: int, cancelled: int}
     */
    public function winBackRate(int $totalCancellations = 0): array
    {
        $resumed = $this->getMetric('subscriptions_resumed_total', 0);
        $cancelled = max($totalCancellations, 1);
        $rate = round(($resumed / $cancelled) * 100, 2);

        return [
            'rate' => $rate,
            'resumed' => $resumed,
            'cancelled' => $cancelled,
        ];
    }

    // ── Activation Scoring ──────────────────────────────────────────

    /**
     * Track an activation milestone for a user.
     *
     * Fires a `milestone_reached` event and updates the user's activation score.
     *
     * @param  string  $userId  User ID
     * @param  string  $milestone  Milestone identifier
     * @param  string|null  $category  Milestone category
     * @param  int|null  $value  Numeric milestone value
     */
    public function trackActivationMilestone(
        string $userId,
        string $milestone,
        ?string $category = null,
        ?int $value = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $resolvedCategory = $category ?? $this->activationMilestones[$milestone]['category'] ?? 'general';

        $event = new MilestoneReachedEvent(
            milestone: $milestone,
            category: $resolvedCategory,
            value: $value,
            userId: $userId,
        );

        $this->manager->trackEvent($event);
        $this->recordUserMilestone($userId, $milestone);
    }

    /**
     * Calculate an activation score for a user based on completed milestones.
     *
     * Score is 0-100, computed as a weighted sum of completed milestones.
     *
     * @param  string  $userId  User ID
     * @return array{score: float, completed: int, total: int, completed_milestones: list<string>, missing_milestones: list<string>, category_scores: array<string, float>}
     */
    public function activationScore(string $userId): array
    {
        $completedMilestones = $this->getUserMilestones($userId);
        $total = count($this->activationMilestones);
        $completed = 0;
        $score = 0.0;
        $categoryScores = [];

        foreach ($this->activationMilestones as $milestone => $config) {
            $cat = $config['category'] ?? 'general';
            $weight = (float) ($config['weight'] ?? 0.0);

            if (in_array($milestone, $completedMilestones, true)) {
                $completed++;
                $score += $weight * 100;
                $categoryScores[$cat] = ($categoryScores[$cat] ?? 0) + ($weight * 100);
            } else {
                $categoryScores[$cat] ??= 0;
            }
        }

        $missing = array_keys(array_diff_key($this->activationMilestones, array_flip($completedMilestones)));

        return [
            'score' => round(min($score, 100.0), 1),
            'completed' => $completed,
            'total' => $total,
            'completed_milestones' => $completedMilestones,
            'missing_milestones' => $missing,
            'category_scores' => $categoryScores,
        ];
    }

    /**
     * Get average activation score across all tracked users.
     *
     * @return array{average_score: float, users_tracked: int, fully_activated: int, partially_activated: int}
     */
    public function averageActivationScore(): array
    {
        $allScores = $this->getAllUserScores();
        $total = count($allScores);

        if ($total === 0) {
            return [
                'average_score' => 0.0,
                'users_tracked' => 0,
                'fully_activated' => 0,
                'partially_activated' => 0,
            ];
        }

        $sum = array_sum($allScores);
        $fullyActivated = count(array_filter($allScores, fn (float $s): bool => $s >= 100.0));
        $partiallyActivated = count(array_filter($allScores, fn (float $s): bool => $s > 0 && $s < 100.0));

        return [
            'average_score' => round($sum / $total, 1),
            'users_tracked' => $total,
            'fully_activated' => $fullyActivated,
            'partially_activated' => $partiallyActivated,
        ];
    }

    // ── Time-to-Conversion Analysis ─────────────────────────────────

    /**
     * Record the start time for a trial user (for time-to-conversion tracking).
     *
     * @param  string  $userId  User ID
     */
    public function recordTrialStartTime(string $userId): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . "trial_start_{$userId}",
            time(),
            $this->cacheTtl,
        );
    }

    /**
     * Calculate the average time-to-conversion across all recorded conversions.
     *
     * @return array{average_hours: float, median_hours: float, sample_size: int, distribution: array<string, int>}
     */
    public function timeToConversion(): array
    {
        $durations = $this->getMetric('conversion_durations_hours', []);

        if (! is_array($durations) || empty($durations)) {
            return [
                'average_hours' => 0.0,
                'median_hours' => 0.0,
                'sample_size' => 0,
                'distribution' => [],
            ];
        }

        /** @var list<int> $durations */
        $count = count($durations);
        $average = array_sum($durations) / $count;
        sort($durations);
        $median = $count % 2 === 0
            ? ($durations[$count / 2 - 1] + $durations[$count / 2]) / 2
            : $durations[(int) floor($count / 2)];

        // Build distribution buckets
        $distribution = [
            '< 1 hour' => 0,
            '1-6 hours' => 0,
            '6-24 hours' => 0,
            '1-3 days' => 0,
            '3-7 days' => 0,
            '7-14 days' => 0,
            '> 14 days' => 0,
        ];

        foreach ($durations as $hours) {
            if ($hours < 1) {
                $distribution['< 1 hour']++;
            } elseif ($hours < 6) {
                $distribution['1-6 hours']++;
            } elseif ($hours < 24) {
                $distribution['6-24 hours']++;
            } elseif ($hours < 72) {
                $distribution['1-3 days']++;
            } elseif ($hours < 168) {
                $distribution['3-7 days']++;
            } elseif ($hours < 336) {
                $distribution['7-14 days']++;
            } else {
                $distribution['> 14 days']++;
            }
        }

        return [
            'average_hours' => round($average, 1),
            'median_hours' => round($median, 1),
            'sample_size' => $count,
            'distribution' => $distribution,
        ];
    }

    /**
     * Record the time-to-conversion for a user who just converted.
     *
     * Call this after trackTrialConversion() if you have a recorded start time.
     *
     * @param  string  $userId  User ID
     */
    public function recordTimeToConversion(string $userId): void
    {
        $startKey = self::CACHE_PREFIX . "trial_start_{$userId}";
        $startTime = $this->cache->get($startKey);

        if ($startTime === null) {
            return;
        }

        $hours = (int) round((time() - (int) $startTime) / 3600);
        $durations = $this->getMetric('conversion_durations_hours', []);
        /** @var list<int> $durations */
        $durations[] = $hours;

        // Keep last 1000 entries
        if (count($durations) > 1000) {
            $durations = array_slice($durations, -1000);
        }

        $this->setMetric('conversion_durations_hours', $durations);
        $this->cache->forget($startKey);
    }

    // ── Conversion Funnel Analysis ─────────────────────────────────

    /**
     * Get the full trial-to-paid conversion funnel.
     *
     * Returns step-by-step funnel from trial start to paid conversion
     * with drop-off rates at each step.
     *
     * @return array{steps: list<array{name: string, count: int, rate: float}>, overall_rate: float}
     */
    public function conversionFunnel(): array
    {
        $trialStarts = $this->getMetric('trial_starts_total', 0);
        $firstFeature = $this->getMetric('milestone_first_feature_used_total', 0);
        $profileCompleted = $this->getMetric('milestone_profile_completed_total', 0);
        $checkoutStarted = $this->getMetric('milestone_checkout_started_total', 0);
        $conversions = $this->getMetric('trial_conversions_total', 0);

        $steps = [
            ['name' => 'trial_started', 'count' => $trialStarts, 'rate' => 100.0],
            ['name' => 'first_feature_used', 'count' => $firstFeature, 'rate' => $this->stepRate($firstFeature, $trialStarts)],
            ['name' => 'profile_completed', 'count' => $profileCompleted, 'rate' => $this->stepRate($profileCompleted, $trialStarts)],
            ['name' => 'checkout_started', 'count' => $checkoutStarted, 'rate' => $this->stepRate($checkoutStarted, $trialStarts)],
            ['name' => 'converted_to_paid', 'count' => $conversions, 'rate' => $this->stepRate($conversions, $trialStarts)],
        ];

        return [
            'steps' => $steps,
            'overall_rate' => $trialStarts > 0 ? round(($conversions / $trialStarts) * 100, 2) : 0.0,
        ];
    }

    // ── Summary ─────────────────────────────────────────────────────

    /**
     * Get a comprehensive conversion analytics summary.
     *
     * @return array{trial_conversion: array{rate: float, starts: int, conversions: int}, activation: array{average_score: float, users: int, fully_activated: int}, time_to_conversion: array{average_hours: float, median_hours: float, sample_size: int}, win_back: array{resumed: int}, funnel: array{steps: list<array{name: string, count: int, rate: float}>, overall_rate: float}}
     */
    public function summary(): array
    {
        $trialRate = $this->trialConversionRate();
        $activation = $this->averageActivationScore();
        $ttc = $this->timeToConversion();
        $winBack = $this->winBackRate();
        $funnel = $this->conversionFunnel();

        return [
            'trial_conversion' => [
                'rate' => $trialRate['rate'],
                'starts' => $trialRate['trial_starts'],
                'conversions' => $trialRate['conversions'],
            ],
            'activation' => [
                'average_score' => $activation['average_score'],
                'users' => $activation['users_tracked'],
                'fully_activated' => $activation['fully_activated'],
            ],
            'time_to_conversion' => [
                'average_hours' => $ttc['average_hours'],
                'median_hours' => $ttc['median_hours'],
                'sample_size' => $ttc['sample_size'],
            ],
            'win_back' => [
                'resumed' => $winBack['resumed'],
            ],
            'funnel' => $funnel,
        ];
    }

    // ── Internal Helpers ────────────────────────────────────────────

    /**
     * Calculate step-to-first-step conversion rate.
     *
     * @param  int  $count  Count at this step
     * @param  int  $base  Base (first step) count
     * @return float Percentage (0-100)
     */
    private function stepRate(int $count, int $base): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        return round(($count / $base) * 100, 2);
    }

    /**
     * Increment a metric counter in cache.
     *
     * @param  string  $key  Metric key
     */
    private function incrementMetric(string $key): void
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, $this->cacheTtl);
    }

    /**
     * Get a metric value from cache.
     *
     * @param  string  $key  Metric key
     * @param  mixed  $default  Default value
     * @return mixed
     */
    private function getMetric(string $key, mixed $default = null): mixed
    {
        return $this->cache->get(self::CACHE_PREFIX . $key, $default);
    }

    /**
     * Set a metric value in cache.
     *
     * @param  string  $key  Metric key
     * @param  mixed  $value  Metric value
     */
    private function setMetric(string $key, mixed $value): void
    {
        $this->cache->put(self::CACHE_PREFIX . $key, $value, $this->cacheTtl);
    }

    /**
     * Record a milestone completion for a user.
     *
     * @param  string  $userId  User ID
     * @param  string  $milestone  Milestone identifier
     */
    private function recordUserMilestone(string $userId, string $milestone): void
    {
        $cacheKey = self::CACHE_PREFIX . "user_{$userId}_milestones";
        $milestones = $this->cache->get($cacheKey, []);
        /** @var list<string> $milestones */

        if (! in_array($milestone, $milestones, true)) {
            $milestones[] = $milestone;
            $this->cache->put($cacheKey, $milestones, $this->cacheTtl);
        }

        // Track total completions for this milestone
        $this->incrementMetric("milestone_{$milestone}_total");
    }

    /**
     * Get completed milestones for a user.
     *
     * @param  string  $userId  User ID
     * @return list<string> Completed milestone identifiers
     */
    private function getUserMilestones(string $userId): array
    {
        $milestones = $this->cache->get(self::CACHE_PREFIX . "user_{$userId}_milestones", []);

        return is_array($milestones) ? $milestones : [];
    }

    /**
     * Get all known plans from cache.
     *
     * @return list<string> Plan names
     */
    private function getKnownPlans(): array
    {
        $plans = $this->cache->get(self::CACHE_PREFIX . 'known_plans', ['free', 'starter', 'pro', 'enterprise']);

        return is_array($plans) ? $plans : ['free', 'starter', 'pro', 'enterprise'];
    }

    /**
     * Estimate plan-specific conversion counts from total.
     *
     * This is an approximation since we don't track per-plan conversions
     * directly. In production, use event stream data for accuracy.
     *
     * @param  string  $plan  Plan name
     * @param  int  $totalConversions  Total conversions
     * @return int Estimated conversions for this plan
     */
    private function estimatePlanConversions(string $plan, int $totalConversions): int
    {
        $starts = $this->getMetric("trial_starts_{$plan}", 0);
        $totalStarts = $this->getMetric('trial_starts_total', 1);

        if ($totalStarts <= 0) {
            return 0;
        }

        return (int) round(($starts / $totalStarts) * $totalConversions);
    }

    /**
     * Get activation scores for all tracked users.
     *
     * @return array<string, float> User ID → score
     */
    private function getAllUserScores(): array
    {
        // This is a simplified implementation.
        // In production, use a dedicated cache store or database.
        $scores = $this->cache->get(self::CACHE_PREFIX . 'all_activation_scores', []);

        return is_array($scores) ? $scores : [];
    }
}
