<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics event recommendation engine.
 *
 * Analyzes the application's tracked event history against the full event
 * catalog to identify instrumentation gaps and recommend events to add
 * next, ranked by business impact and AARRR category coverage.
 *
 * Uses a tiered priority model:
 *   - Critical: Direct revenue/auth events missing (high impact)
 *   - High: Conversion funnel gaps, retention signals missing
 *   - Medium: Engagement/PLG events that deepen analytics insight
 *   - Low: Nice-to-have operational events
 *
 * Results are cache-backed for dashboard performance.
 *
 * @since 7.1.0
 */
final class EventRecommendationService
{
    /** @var array<string, array{keys: list<string>, priority: string, label: string}> */
    private const PRIORITY_TIERS = [
        'critical' => [
            'keys' => [
                'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade',
                'cancellation', 'page_view', 'purchase', 'payment_succeeded',
                'payment_failed', 'trial_converted',
            ],
            'priority' => 'critical',
            'label' => 'Critical — direct revenue and auth events',
        ],
        'high' => [
            'keys' => [
                'email_verified', 'onboarding_step', 'feature_used', 'add_to_cart',
                'begin_checkout', 'add_payment_info', 'refund', 'plan_downgrade',
                'subscription_renewal', 'subscription_resumed', 'trial_end',
                'subscription_paused', 'view_item', 'search', 'form_submit',
                'share', 'revenue_tracked', 'invoice_generated', 'billing_retry',
                'subscription_value_changed',
            ],
            'priority' => 'high',
            'label' => 'High — conversion funnel and retention signals',
        ],
        'medium' => [
            'keys' => [
                'scroll_depth', 'time_on_page', 'session_start', 'session_end',
                'content_engagement', 'form_start', 'notification', 'milestone_reached',
                'team_created', 'team_member_joined', 'integration_connected',
                'invite_sent', 'profile_updated', 'view_cart', 'remove_from_cart',
                'add_to_wishlist', 'select_item', 'credit_applied', 'payment_method_added',
                'workspace_created', 'feature_adopted', 'expansion_revenue',
                'feedback', 'goal_conversion', 'export', 'import',
            ],
            'priority' => 'medium',
            'label' => 'Medium — engagement depth and PLG signals',
        ],
        'low' => [
            'keys' => [
                'click', 'outbound_click', 'file_download', 'video_play',
                'screen_view', 'web_vitals', 'timing', 'js_error', 'error',
                'ab_test_exposure', 'ad_click', 'feature_impression',
                'select_promotion', 'view_promotion', 'role_changed',
                'team_member_removed', 'logout', 'feature_limit_reached',
                'usage_quota_reached', 'password_changed', 'password_reset',
                'account_activated', 'account_deactivated', 'campaign_attribution',
            ],
            'priority' => 'low',
            'label' => 'Low — operational and diagnostic events',
        ],
    ];

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var int */
    private int $cacheTtl;

    /** @var list<string> */
    private array $excludedEvents;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $recConfig = $config->get('zeroboiler.analytics.recommendations', []);
        /** @var array{cache_ttl?: int, excluded_events?: list<string>} $recConfig */
        $this->cacheTtl = (int) ($recConfig['cache_ttl'] ?? 300);
        $this->excludedEvents = $recConfig['excluded_events'] ?? [];
    }

    /**
     * Generate event recommendations based on the tracked events.
     *
     * Compares the list of tracked event names against the full catalog
     * and returns missing events grouped by priority tier.
     *
     * @param  list<string>  $trackedEvents  Event names currently being tracked
     * @return array{gaps: array{critical: list<array{name: string, category: string|null, priority: string}>, high: list<array{name: string, category: string|null, priority: string}>, medium: list<array{name: string, category: string|null, priority: string}>, low: list<array{name: string, category: string|null, priority: string}>}, total_catalog: int, tracked_count: int, gap_count: int, coverage_percent: float, score: int, grade: string}
     */
    public function recommend(array $trackedEvents = []): array
    {
        $cacheKey = 'zb_recommendations_' . hash('xxh128', implode(',', $trackedEvents));

        /** @var array|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Normalize tracked events to lowercase
        $tracked = array_map('strtolower', $trackedEvents);
        $trackedSet = array_flip($tracked);

        // Get all catalog event names
        $catalogNames = EventCatalog::names();
        $catalogSet = array_flip($catalogNames);

        // Determine which tracked events exist in the catalog
        $validTracked = array_intersect($tracked, $catalogNames);

        // Find gaps per priority tier
        $gaps = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach (self::PRIORITY_TIERS as $tierName => $tier) {
            foreach ($tier['keys'] as $eventName) {
                // Skip if already tracked or explicitly excluded
                if (isset($trackedSet[$eventName]) || in_array($eventName, $this->excludedEvents, true)) {
                    continue;
                }

                // Skip if the event doesn't exist in the catalog
                if (! isset($catalogSet[$eventName])) {
                    continue;
                }

                $entry = EventCatalog::get($eventName);
                $gaps[$tierName][] = [
                    'name' => $eventName,
                    'category' => $entry['category'] ?? null,
                    'priority' => $tierName,
                ];
            }
        }

        // Also add catalog events not in any priority tier as 'low' gaps
        $allTierKeys = [];
        foreach (self::PRIORITY_TIERS as $tier) {
            $allTierKeys = array_merge($allTierKeys, $tier['keys']);
        }
        $allTierSet = array_flip($allTierKeys);

        foreach ($catalogNames as $catalogName) {
            if (isset($allTierSet[$catalogName]) || isset($trackedSet[$catalogName])) {
                continue;
            }
            if (in_array($catalogName, $this->excludedEvents, true)) {
                continue;
            }

            $entry = EventCatalog::get($catalogName);
            $gaps['low'][] = [
                'name' => $catalogName,
                'category' => $entry['category'] ?? null,
                'priority' => 'low',
            ];
        }

        $totalCatalog = count($catalogNames);
        $trackedCount = count($validTracked);
        $gapCount = $totalCatalog - $trackedCount;
        $coveragePercent = $totalCatalog > 0 ? round(($trackedCount / $totalCatalog) * 100, 1) : 0.0;

        // Compute a coverage score (0-100) weighted by priority
        $score = $this->computeCoverageScore($trackedSet);

        // Grade: A (90+), B (75-89), C (60-74), D (40-59), F (<40)
        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };

        $result = [
            'gaps' => $gaps,
            'total_catalog' => $totalCatalog,
            'tracked_count' => $trackedCount,
            'gap_count' => $gapCount,
            'coverage_percent' => $coveragePercent,
            'score' => $score,
            'grade' => $grade,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get the top N recommended events to add next.
     *
     * Returns events sorted by priority (critical first), limited
     * to the specified count. Useful for dashboards and quick-start guides.
     *
     * @param  list<string>  $trackedEvents  Currently tracked events
     * @param  int  $limit  Maximum recommendations to return
     * @return list<array{name: string, category: string|null, priority: string, reason: string}>
     */
    public function topRecommendations(array $trackedEvents = [], int $limit = 10): array
    {
        $recommendations = $this->recommend($trackedEvents);
        $all = [];

        $reasons = [
            'critical' => 'Direct impact on revenue and authentication tracking',
            'high' => 'Fills conversion funnel or retention signal gaps',
            'medium' => 'Deepens engagement analytics and PLG insight',
            'low' => 'Operational and diagnostic improvement',
        ];

        $priorityOrder = ['critical', 'high', 'medium', 'low'];

        foreach ($priorityOrder as $tier) {
            foreach ($recommendations['gaps'][$tier] as $gap) {
                $all[] = array_merge($gap, [
                    'reason' => $reasons[$tier] ?? '',
                ]);
            }
        }

        return array_slice($all, 0, $limit);
    }

    /**
     * Get coverage breakdown by AARRR category.
     *
     * Returns how many events in each AARRR pillar (Acquisition, Activation,
     * Retention, Revenue, Referral) are tracked vs. total.
     *
     * @param  list<string>  $trackedEvents  Currently tracked events
     * @return array{acquisition: array{tracked: int, total: int, percent: float}, activation: array{tracked: int, total: int, percent: float}, retention: array{tracked: int, total: int, percent: float}, revenue: array{tracked: int, total: int, percent: float}, referral: array{tracked: int, total: int, percent: float}}
     */
    public function aarrrBreakdown(array $trackedEvents = []): array
    {
        $trackedSet = array_flip(array_map('strtolower', $trackedEvents));

        $aarrrMap = [
            'acquisition' => ['sign_up', 'start_trial', 'login', 'email_verified', 'campaign_attribution', 'ad_click', 'share'],
            'activation' => ['onboarding_step', 'feature_used', 'milestone_reached', 'page_view', 'search', 'form_submit', 'content_engagement'],
            'retention' => ['login', 'feature_used', 'session_start', 'content_engagement', 'milestone_reached', 'plan_upgrade', 'integration_connected', 'team_member_joined', 'time_on_page', 'scroll_depth'],
            'revenue' => ['purchase', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'payment_succeeded', 'payment_failed', 'trial_converted', 'subscription_renewal', 'add_to_cart', 'begin_checkout', 'refund', 'invoice_generated', 'billing_retry', 'expansion_revenue', 'credit_applied'],
            'referral' => ['share', 'invite_sent', 'team_created', 'team_member_joined', 'export', 'feature_adopted'],
        ];

        $breakdown = [];

        foreach ($aarrrMap as $pillar => $events) {
            $total = count($events);
            $trackedCount = 0;
            foreach ($events as $event) {
                if (isset($trackedSet[$event])) {
                    $trackedCount++;
                }
            }

            $breakdown[$pillar] = [
                'tracked' => $trackedCount,
                'total' => $total,
                'percent' => $total > 0 ? round(($trackedCount / $total) * 100, 1) : 0.0,
            ];
        }

        return $breakdown;
    }

    /**
     * Compute a weighted coverage score (0-100).
     *
     * Critical events are worth 4 points each, high 3, medium 2, low 1.
     * Score is the percentage of total available points that are covered.
     *
     * @param  array<string, bool>  $trackedSet  Event names currently tracked (as keys)
     * @return int
     */
    private function computeCoverageScore(array $trackedSet): int
    {
        $weights = [
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
        ];

        $totalPoints = 0;
        $coveredPoints = 0;

        foreach (self::PRIORITY_TIERS as $tierName => $tier) {
            $weight = $weights[$tierName];
            foreach ($tier['keys'] as $eventName) {
                $totalPoints += $weight;
                if (isset($trackedSet[$eventName])) {
                    $coveredPoints += $weight;
                }
            }
        }

        if ($totalPoints === 0) {
            return 0;
        }

        return (int) round(($coveredPoints / $totalPoints) * 100);
    }

    /**
     * Get the full priority tier configuration.
     *
     * @return array<string, array{priority: string, label: string, event_count: int}>
     */
    public function tiers(): array
    {
        $result = [];
        foreach (self::PRIORITY_TIERS as $name => $tier) {
            $result[$name] = [
                'priority' => $tier['priority'],
                'label' => $tier['label'],
                'event_count' => count($tier['keys']),
            ];
        }

        return $result;
    }
}
