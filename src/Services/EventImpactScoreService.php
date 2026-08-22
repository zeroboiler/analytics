<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

/**
 * Event Impact Scoring Service.
 *
 * Computes composite impact scores for catalog events based on:
 * - **Revenue correlation** (40%) — How directly the event impacts MRR/ARR
 * - **Funnel position weight** (25%) — Events closer to conversion carry more weight
 * - **Frequency multiplier** (20%) — High-frequency events have broader analytical value
 * - **Provider coverage** (15%) — Events tracked across more providers yield richer insights
 *
 * Impact scores help SaaS teams prioritize event instrumentation,
 * identify tracking gaps, and focus engineering effort on the events
 * that drive the most business intelligence value.
 *
 * @since 9.6.0
 */
final class EventImpactScoreService
{
    /**
     * Weight allocations for impact score computation.
     */
    private const WEIGHT_REVENUE = 0.40;

    private const WEIGHT_FUNNEL = 0.25;

    private const WEIGHT_FREQUENCY = 0.20;

    private const WEIGHT_PROVIDER = 0.15;

    /**
     * Revenue correlation scores per AARRR category.
     *
     * @var array<string, float>
     */
    private const CATEGORY_REVENUE_SCORES = [
        'revenue' => 1.0,
        'acquisition' => 0.7,
        'activation' => 0.5,
        'referral' => 0.6,
        'retention' => 0.4,
        'operational' => 0.1,
    ];

    /**
     * Funnel position weights — events in earlier funnel stages get higher weights
     * because they represent broader user behavior patterns.
     *
     * @var array<string, float>
     */
    private const FUNNEL_POSITION_WEIGHTS = [
        'acquisition' => 0.9,
        'activation' => 0.85,
        'revenue' => 1.0,
        'retention' => 0.7,
        'referral' => 0.75,
        'operational' => 0.3,
    ];

    /**
     * Estimated event frequency tiers (events/day in a mid-size SaaS).
     *
     * @var array<string, float>
     */
    private const FREQUENCY_TIERS = [
        'page_view' => 1000.0,
        'scroll_depth' => 500.0,
        'click' => 800.0,
        'session_start' => 300.0,
        'session_end' => 300.0,
        'time_on_page' => 400.0,
        'screen_view' => 200.0,
        'search' => 100.0,
        'form_start' => 80.0,
        'form_submit' => 50.0,
        'login' => 200.0,
        'logout' => 150.0,
        'feature_used' => 150.0,
        'notification' => 100.0,
        'web_vitals' => 300.0,
        'timing' => 200.0,
        'js_error' => 50.0,
        'content_engagement' => 100.0,
        // Medium frequency
        'sign_up' => 20.0,
        'email_verified' => 15.0,
        'start_trial' => 10.0,
        'view_item' => 50.0,
        'add_to_cart' => 15.0,
        'remove_from_cart' => 10.0,
        'view_cart' => 10.0,
        'begin_checkout' => 8.0,
        'select_item' => 30.0,
        'share' => 15.0,
        'outbound_click' => 40.0,
        'file_download' => 20.0,
        'video_play' => 10.0,
        'onboarding_step' => 15.0,
        'ab_test_exposure' => 50.0,
        'ad_click' => 5.0,
        'campaign_attribution' => 10.0,
        'error' => 10.0,
        'consent_granted' => 20.0,
        'consent_withdrawn' => 5.0,
        'feedback' => 8.0,
        'goal_conversion' => 5.0,
        'profile_updated' => 25.0,
        'password_changed' => 5.0,
        'password_reset' => 8.0,
        'team_member_joined' => 5.0,
        'team_member_removed' => 2.0,
        'role_changed' => 3.0,
        'feature_request' => 3.0,
        'team_created' => 3.0,
        'workspace_created' => 3.0,
        'invite_sent' => 5.0,
        'integration_connected' => 4.0,
        'feature_impression' => 50.0,
        'milestone_reached' => 2.0,
        'cohort_assigned' => 10.0,
        'cohort_retention' => 10.0,
        'cohort_engagement' => 10.0,
        'subscription_renewal' => 5.0,
        'feature_limit_reached' => 3.0,
        'usage_quota_reached' => 2.0,
        'sla_breach' => 0.5,
        'checkout_step' => 8.0,
        'abandoned_cart' => 2.0,
        'checkout_abandon' => 2.0,
        'add_to_wishlist' => 5.0,
        'select_promotion' => 10.0,
        'view_promotion' => 20.0,
        'add_payment_info' => 6.0,
        // Low frequency (high-value)
        'subscribe' => 3.0,
        'plan_upgrade' => 2.0,
        'plan_downgrade' => 1.5,
        'cancellation' => 1.0,
        'trial_converted' => 2.0,
        'trial_end' => 2.0,
        'trial_expired' => 1.0,
        'purchase' => 2.0,
        'refund' => 0.5,
        'revenue_tracked' => 1.0,
        'payment_succeeded' => 3.0,
        'payment_failed' => 1.0,
        'billing_retry' => 0.5,
        'payment_method_added' => 1.0,
        'payment_method_updated' => 0.5,
        'invoice_generated' => 3.0,
        'credit_applied' => 1.0,
        'expansion_revenue' => 1.0,
        'feature_adopted' => 5.0,
        'subscription_created' => 3.0,
        'subscription_cancelled' => 1.0,
        'subscription_paused' => 0.5,
        'subscription_resumed' => 0.5,
        'subscription_value_changed' => 2.0,
        'account_activated' => 10.0,
        'account_deactivated' => 2.0,
        'account_deleted' => 0.5,
        'export' => 5.0,
        'import' => 3.0,
        'data_erasure_completed' => 0.2,
        'data_subject_access_request' => 0.2,
        'integration_failed' => 0.5,
        'cohort_churn' => 1.0,
        'cohort_conversion' => 1.0,
        'cohort_migration' => 0.5,
    ];

    private EventPriorityCalculator $priorityCalculator;

    private ?CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  EventPriorityCalculator|null  $priorityCalculator  Optional injection for testing
     * @param  CacheRepository|null  $cache  Optional cache repository
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 300)
     */
    public function __construct(
        ?EventPriorityCalculator $priorityCalculator = null,
        ?CacheRepository $cache = null,
        int $cacheTtl = 300,
    ) {
        $this->priorityCalculator = $priorityCalculator ?? new EventPriorityCalculator();
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Compute the composite impact score for a single event.
     *
     * @return array{score: float, grade: string, dimensions: array{revenue: float, funnel: float, frequency: float, provider: float}, category: string, priority: string}
     */
    public function score(string $eventName): array
    {
        $category = $this->priorityCalculator->classify($eventName);
        $priority = $this->priorityCalculator->getEventPriority($eventName);

        // Revenue correlation dimension (0-1)
        $revenueScore = self::CATEGORY_REVENUE_SCORES[$category] ?? 0.1;

        // Funnel position dimension (0-1)
        $funnelScore = self::FUNNEL_POSITION_WEIGHTS[$category] ?? 0.3;

        // Frequency dimension (normalized 0-1, log scale)
        $rawFrequency = self::FREQUENCY_TIERS[$eventName] ?? 1.0;
        $frequencyScore = min(1.0, log10(max($rawFrequency, 0.1) + 1) / log10(1001));

        // Provider coverage dimension (0-1)
        $entry = EventCatalog::get($eventName);
        $providerCount = 0;
        if ($entry !== null) {
            $providerCount = (int) (($entry['ga4'] ?? null) !== null ? 1 : 0)
                + (int) (($entry['meta'] ?? null) !== null ? 1 : 0)
                + (int) (($entry['posthog'] ?? null) !== null ? 1 : 0)
                + (int) (($entry['plausible'] ?? null) !== null ? 1 : 0);
        }
        $providerScore = min(1.0, $providerCount / 4.0);

        // Weighted composite score (0-1)
        $composite = (
            $revenueScore * self::WEIGHT_REVENUE
            + $funnelScore * self::WEIGHT_FUNNEL
            + $frequencyScore * self::WEIGHT_FREQUENCY
            + $providerScore * self::WEIGHT_PROVIDER
        );

        $grade = $this->gradeFromScore($composite);

        return [
            'score' => round($composite, 4),
            'grade' => $grade,
            'dimensions' => [
                'revenue' => round($revenueScore, 4),
                'funnel' => round($funnelScore, 4),
                'frequency' => round($frequencyScore, 4),
                'provider' => round($providerScore, 4),
            ],
            'category' => $category,
            'priority' => $priority,
        ];
    }

    /**
     * Compute impact scores for all catalog events, sorted by score descending.
     *
     * @param  int  $limit  Max results to return (0 = all)
     * @return array{events: array<string, array{score: float, grade: string, dimensions: array<string, float>, category: string, priority: string}>, summary: array{total: int, avg_score: float, top_events: list<string>, low_impact: list<string>}}
     */
    public function scoreAll(int $limit = 0): array
    {
        $cacheKey = 'zb_analytics_impact_scores';
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $limit > 0 ? $this->applyLimit($cached, $limit) : $cached;
            }
        }

        $events = [];
        $totalScore = 0.0;
        $sorted = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $result = $this->score($name);
            $events[$name] = $result;
            $totalScore += $result['score'];
            $sorted[$name] = $result['score'];
        }

        // Sort descending by score
        arsort($sorted);

        $topEvents = array_keys(array_slice($sorted, 0, 10, true));
        $lowImpact = [];
        foreach ($sorted as $name => $score) {
            if ($score < 0.2) {
                $lowImpact[] = $name;
            }
        }

        $result = [
            'events' => $events,
            'summary' => [
                'total' => count($events),
                'avg_score' => count($events) > 0 ? round($totalScore / count($events), 4) : 0.0,
                'top_events' => $topEvents,
                'low_impact' => $lowImpact,
            ],
        ];

        if ($this->cache !== null) {
            $this->cache->put($cacheKey, $result, $this->cacheTtl);
        }

        return $limit > 0 ? $this->applyLimit($result, $limit) : $result;
    }

    /**
     * Get the top N highest-impact events.
     *
     * @return list<array{event: string, score: float, grade: string, category: string}>
     */
    public function topEvents(int $n = 10): array
    {
        $all = $this->scoreAll();
        $sorted = [];

        foreach ($all['events'] as $name => $data) {
            $sorted[$name] = $data['score'];
        }

        arsort($sorted);

        $result = [];
        foreach (array_slice($sorted, 0, $n, true) as $name => $score) {
            $event = $all['events'][$name];
            $result[] = [
                'event' => $name,
                'score' => $score,
                'grade' => $event['grade'],
                'category' => $event['category'],
            ];
        }

        return $result;
    }

    /**
     * Get events with low impact scores that may not justify instrumentation effort.
     *
     * @return list<array{event: string, score: float, grade: string, reason: string}>
     */
    public function lowImpactEvents(float $threshold = 0.2): array
    {
        $all = $this->scoreAll();
        $result = [];

        foreach ($all['events'] as $name => $data) {
            if ($data['score'] < $threshold) {
                $reasons = [];

                if ($data['dimensions']['revenue'] < 0.2) {
                    $reasons[] = 'low revenue correlation';
                }
                if ($data['dimensions']['provider'] < 0.25) {
                    $reasons[] = 'limited provider coverage';
                }
                if ($data['dimensions']['funnel'] < 0.3) {
                    $reasons[] = 'peripheral funnel position';
                }

                $result[] = [
                    'event' => $name,
                    'score' => $data['score'],
                    'grade' => $data['grade'],
                    'reason' => implode('; ', $reasons) ?: 'low composite score',
                ];
            }
        }

        return $result;
    }

    /**
     * Compare impact scores between two events.
     *
     * @return array{event_a: array{score: float, grade: string}, event_b: array{score: float, grade: string}, delta: float, recommendation: string}
     */
    public function compare(string $eventA, string $eventB): array
    {
        $scoreA = $this->score($eventA);
        $scoreB = $this->score($eventB);

        $delta = round($scoreA['score'] - $scoreB['score'], 4);

        if (abs($delta) < 0.05) {
            $recommendation = 'Comparable impact — both events provide similar analytical value.';
        } elseif ($delta > 0) {
            $recommendation = "'{$eventA}' has higher impact (+" . ($delta * 100) . '%) — prioritize instrumentation.';
        } else {
            $recommendation = "'" . $eventB . "' has higher impact (+" . (abs($delta) * 100) . '%) — prioritize instrumentation.';
        }

        return [
            'event_a' => ['score' => $scoreA['score'], 'grade' => $scoreA['grade']],
            'event_b' => ['score' => $scoreB['score'], 'grade' => $scoreB['grade']],
            'delta' => $delta,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Get a summary of impact score distribution across the catalog.
     *
     * @return array{distribution: array{critical: int, high: int, medium: int, low: int, minimal: int}, grade_breakdown: array<string, int>, category_averages: array<string, float>}
     */
    public function distribution(): array
    {
        $all = $this->scoreAll();

        $distribution = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'minimal' => 0];
        $gradeBreakdown = [];
        $categoryScores = [];

        foreach ($all['events'] as $name => $data) {
            $grade = $data['grade'];

            // Map grades to distribution buckets
            $bucket = match ($grade) {
                'A+', 'A' => 'critical',
                'B+', 'B' => 'high',
                'C+', 'C' => 'medium',
                'D' => 'low',
                default => 'minimal',
            };
            $distribution[$bucket]++;

            $gradeBreakdown[$grade] = ($gradeBreakdown[$grade] ?? 0) + 1;

            $category = $data['category'];
            $categoryScores[$category] = ($categoryScores[$category] ?? 0.0) + $data['score'];
        }

        // Compute category averages
        $categoryCounts = [];
        foreach ($all['events'] as $name => $data) {
            $cat = $data['category'];
            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
        }

        $categoryAverages = [];
        foreach ($categoryScores as $cat => $total) {
            $count = $categoryCounts[$cat] ?? 1;
            $categoryAverages[$cat] = round($total / $count, 4);
        }

        return [
            'distribution' => $distribution,
            'grade_breakdown' => $gradeBreakdown,
            'category_averages' => $categoryAverages,
        ];
    }

    /**
     * Get impact score analysis for a specific AARRR category.
     *
     * @return array{category: string, avg_score: float, total_events: int, ranked_events: list<array{event: string, score: float, grade: string}>}
     */
    public function categoryAnalysis(string $category): array
    {
        $all = $this->scoreAll();
        $events = [];
        $totalScore = 0.0;

        foreach ($all['events'] as $name => $data) {
            if ($data['category'] === $category) {
                $events[$name] = $data['score'];
                $totalScore += $data['score'];
            }
        }

        arsort($events);

        $ranked = [];
        foreach ($events as $name => $score) {
            $ranked[] = [
                'event' => $name,
                'score' => $score,
                'grade' => $all['events'][$name]['grade'],
            ];
        }

        return [
            'category' => $category,
            'avg_score' => count($events) > 0 ? round($totalScore / count($events), 4) : 0.0,
            'total_events' => count($events),
            'ranked_events' => $ranked,
        ];
    }

    /**
     * Clear the cached impact scores.
     */
    public function clearCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->forget('zb_analytics_impact_scores');
        }
    }

    /**
     * Map a composite score to a letter grade.
     */
    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'A+',
            $score >= 0.70 => 'A',
            $score >= 0.55 => 'B+',
            $score >= 0.40 => 'B',
            $score >= 0.25 => 'C+',
            $score >= 0.15 => 'C',
            $score >= 0.05 => 'D',
            default => 'F',
        };
    }

    /**
     * Apply a result limit to a scored result set.
     *
     * @param  array{events: array<string, mixed>, summary: mixed}  $result
     * @return array{events: array<string, mixed>, summary: mixed}
     */
    private function applyLimit(array $result, int $limit): array
    {
        if ($limit <= 0) {
            return $result;
        }

        $sorted = [];
        foreach ($result['events'] as $name => $data) {
            $sorted[$name] = $data['score'];
        }
        arsort($sorted);

        $topNames = array_keys(array_slice($sorted, 0, $limit, true));
        $limited = [];
        foreach ($topNames as $name) {
            $limited[$name] = $result['events'][$name];
        }

        return [
            'events' => $limited,
            'summary' => $result['summary'],
        ];
    }
}
