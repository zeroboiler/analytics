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
 * Event Instrumentation Advisor — recommends which events to track for SaaS maturity.
 *
 * Analyzes the current event tracking configuration against industry-standard
 * SaaS analytics benchmarks and provides actionable recommendations. Covers
 * AARRR funnel (Acquisition, Activation, Retention, Referral, Revenue),
 * product-market fit signals, and growth metrics.
 *
 * Produces:
 * - Priority-ranked list of recommended events with rationale
 * - Coverage scores per SaaS stage (signup, activation, retention, revenue)
 * - Missing event detection with quick-start code snippets
 * - Maturity grade (Starter → Growth → Enterprise)
 *
 * @since 177.0.0
 */
final class EventInstrumentationAdvisor
{
    private const CACHE_KEY = 'zeroboiler:instrumentation:advisor';
    private const CACHE_TTL = 1800; // 30 minutes

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var array<string, array{label: string, events: list<string>, priority: 'critical'|'high'|'medium', rationale: string, code_snippet: string}> */
    private array $saasStarterRecommendations;

    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;
        $this->saasStarterRecommendations = $this->buildRecommendations();
    }

    /**
     * Get the full instrumentation advisory report.
     *
     * @return array{coverage: array<string, array{total: int, tracked: int, score: float, events: list<string>}>, recommendations: list<array{event: string, label: string, priority: string, category: string, rationale: string, tracked: bool, code_snippet: string}>, maturity: array{level: string, score: float, grade: string, next_events: list<string>}, quick_wins: list<string>, priority_matrix: array<string, list<string>>}
     */
    public function getReport(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->computeReport();
        });
    }

    /**
     * Get a quick summary of instrumentation status.
     *
     * @return array{level: string, score: float, grade: string, tracked_count: int, total_recommended: int, quick_wins: int}
     */
    public function summary(): array
    {
        $report = $this->getReport();

        return [
            'level' => $report['maturity']['level'],
            'score' => $report['maturity']['score'],
            'grade' => $report['maturity']['grade'],
            'tracked_count' => count(array_filter(
                $report['recommendations'],
                static fn (array $r): bool => $r['tracked']
            )),
            'total_recommended' => count($report['recommendations']),
            'quick_wins' => count($report['quick_wins']),
        ];
    }

    /**
     * Get only the untracked events sorted by priority.
     *
     * @return list<array{event: string, label: string, priority: string, category: string, rationale: string, tracked: bool, code_snippet: string}>
     */
    public function gaps(): array
    {
        $report = $this->getReport();

        return array_values(array_filter(
            $report['recommendations'],
            static fn (array $r): bool => ! $r['tracked']
        ));
    }

    /**
     * Get quick-win events (high-priority, low-effort instrumentation).
     *
     * @return list<string>
     */
    public function quickWins(): array
    {
        $report = $this->getReport();

        return $report['quick_wins'];
    }

    /**
     * Get events organized by priority level.
     *
     * @return array<string, list<string>>
     */
    public function priorityMatrix(): array
    {
        $report = $this->getReport();

        return $report['priority_matrix'];
    }

    /**
     * Get coverage score for a specific SaaS funnel stage.
     *
     * @param 'signup'|'activation'|'retention'|'revenue'|'referral' $stage
     * @return array{total: int, tracked: int, score: float, events: list<string>}
     */
    public function stageCoverage(string $stage): array
    {
        $report = $this->getReport();

        return $report['coverage'][$stage] ?? [
            'total' => 0,
            'tracked' => 0,
            'score' => 0.0,
            'events' => [],
        ];
    }

    /**
     * Invalidate cached report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Compute the full instrumentation report.
     *
     * @return array{coverage: array<string, array{total: int, tracked: int, score: float, events: list<string>}>, recommendations: list<array{event: string, label: string, priority: string, category: string, rationale: string, tracked: bool, code_snippet: string}>, maturity: array{level: string, score: float, grade: string, next_events: list<string>}, quick_wins: list<string>, priority_matrix: array<string, list<string>>}
     */
    private function computeReport(): array
    {
        $recommendations = $this->buildRecommendationList();
        $trackedCount = count(array_filter($recommendations, static fn (array $r): bool => $r['tracked']));
        $totalCount = count($recommendations);

        $coverage = $this->computeStageCoverage();

        $maturityScore = $totalCount > 0 ? (float) round(($trackedCount / $totalCount) * 100, 1) : 0.0;

        $quickWins = [];
        foreach ($recommendations as $rec) {
            if (! $rec['tracked'] && $rec['priority'] === 'critical') {
                $quickWins[] = $rec['event'];
            }
        }

        $priorityMatrix = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        foreach ($recommendations as $rec) {
            $priorityMatrix[$rec['priority']] ??= [];
            $priorityMatrix[$rec['priority']][] = $rec['event'];
        }

        // Determine next events to implement
        $nextEvents = array_slice($quickWins, 0, 5);
        if (count($nextEvents) < 5) {
            foreach ($recommendations as $rec) {
                if (! $rec['tracked'] && ! in_array($rec['event'], $nextEvents, true)) {
                    $nextEvents[] = $rec['event'];
                    if (count($nextEvents) >= 5) {
                        break;
                    }
                }
            }
        }

        return [
            'coverage' => $coverage,
            'recommendations' => array_values($recommendations),
            'maturity' => [
                'level' => $this->getMaturityLevel($maturityScore),
                'score' => $maturityScore,
                'grade' => $this->getMaturityGrade($maturityScore),
                'next_events' => $nextEvents,
            ],
            'quick_wins' => $quickWins,
            'priority_matrix' => $priorityMatrix,
        ];
    }

    /**
     * Build the full recommendation list with tracked status.
     *
     * @return list<array{event: string, label: string, priority: string, category: string, rationale: string, tracked: bool, code_snippet: string}>
     */
    private function buildRecommendationList(): array
    {
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track.events', []);
        $list = [];

        foreach ($this->saasStarterRecommendations as $key => $rec) {
            $list[] = [
                'event' => $key,
                'label' => $rec['label'],
                'priority' => $rec['priority'],
                'category' => $rec['category'] ?? $this->guessCategory($key),
                'rationale' => $rec['rationale'],
                'tracked' => isset($autoTrack[$key]) ? (bool) $autoTrack[$key] : EventCatalog::has($key),
                'code_snippet' => $rec['code_snippet'],
            ];
        }

        return $list;
    }

    /**
     * Compute coverage per SaaS funnel stage.
     *
     * @return array<string, array{total: int, tracked: int, score: float, events: list<string>}>
     */
    private function computeStageCoverage(): array
    {
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track.events', []);
        $stages = $this->getStageEventMap();
        $coverage = [];

        foreach ($stages as $stage => $events) {
            $tracked = 0;
            $total = count($events);
            foreach ($events as $event) {
                if (EventCatalog::has($event) && (isset($autoTrack[$event]) ? (bool) $autoTrack[$event] : true)) {
                    $tracked++;
                }
            }
            $coverage[$stage] = [
                'total' => $total,
                'tracked' => $tracked,
                'score' => $total > 0 ? (float) round(($tracked / $total) * 100, 1) : 0.0,
                'events' => $events,
            ];
        }

        return $coverage;
    }

    /**
     * Get the SaaS funnel stage → event mapping.
     *
     * @return array<string, list<string>>
     */
    private function getStageEventMap(): array
    {
        return [
            'signup' => ['sign_up', 'email_verified', 'onboarding_started'],
            'activation' => ['trial_start', 'feature_used', 'first_value', 'onboarding_completed'],
            'retention' => ['login', 'session_start', 'feature_used', 'search', 'content_engagement'],
            'revenue' => ['subscription', 'plan_upgrade', 'purchase', 'revenue_tracked', 'add_payment_info'],
            'referral' => ['share', 'invite_sent', 'invite_accepted', 'referral_conversion'],
        ];
    }

    /**
     * Build the SaaS starter event recommendations.
     *
     * @return array<string, array{label: string, events: list<string>, priority: 'critical'|'high'|'medium', rationale: string, code_snippet: string, category?: string}>
     */
    private function buildRecommendations(): array
    {
        return [
            'auth.register' => [
                'label' => 'User Registration',
                'priority' => 'critical',
                'rationale' => 'Tracks acquisition funnel entry point. Required for signup conversion rate calculation.',
                'code_snippet' => "Analytics::track('sign_up', ['method' => 'email']);",
            ],
            'auth.login' => [
                'label' => 'User Login',
                'priority' => 'critical',
                'rationale' => 'Measures DAU/MAU and stickiness. Essential for retention cohort analysis.',
                'code_snippet' => "// Auto-tracked via lifecycle events\n// Or manual: Analytics::track('login', ['method' => 'email']);",
            ],
            'trial.started' => [
                'label' => 'Trial Start',
                'priority' => 'critical',
                'rationale' => 'Entry point for trial-to-paid conversion funnel. Required for trial conversion rate.',
                'code_snippet' => "Analytics::track('trial_start', ['plan' => 'pro', 'duration_days' => 14]);",
            ],
            'subscription.created' => [
                'label' => 'Subscription Created',
                'priority' => 'critical',
                'rationale' => 'Revenue event — marks the transition from trial to paid. Required for MRR calculation.',
                'code_snippet' => "Analytics::track('subscription', ['plan' => 'pro', 'value' => 29.00, 'currency' => 'USD']);",
            ],
            'subscription.upgraded' => [
                'label' => 'Plan Upgrade',
                'priority' => 'high',
                'rationale' => 'Revenue expansion signal. Tracks expansion MRR and plan migration patterns.',
                'code_snippet' => "Analytics::track('plan_upgrade', ['from_plan' => 'starter', 'to_plan' => 'pro']);",
            ],
            'subscription.cancelled' => [
                'label' => 'Subscription Cancelled',
                'priority' => 'high',
                'rationale' => 'Churn signal. Required for churn rate calculation and cancellation funnel analysis.',
                'code_snippet' => "Analytics::track('cancellation', ['plan' => 'pro', 'reason' => 'too_expensive']);",
            ],
            'feature.used' => [
                'label' => 'Feature Used',
                'priority' => 'high',
                'rationale' => 'Activation metric. Identifies which features drive conversion and retention.',
                'code_snippet' => "Analytics::track('feature_used', ['feature' => 'export', 'category' => 'reports']);",
            ],
            'view_item' => [
                'label' => 'View Item (Product/Page)',
                'priority' => 'high',
                'rationale' => 'E-commerce engagement signal. Tracks product page views for conversion funnel.',
                'code_snippet' => "Analytics::track('view_item', ['item_id' => 'SKU-001', 'item_name' => 'Widget']);",
            ],
            'add_to_cart' => [
                'label' => 'Add to Cart',
                'priority' => 'high',
                'rationale' => 'E-commerce intent signal. Measures cart addition rate for purchase conversion funnel.',
                'code_snippet' => "Analytics::track('add_to_cart', ['item_id' => 'SKU-001', 'quantity' => 1, 'price' => 49.99]);",
            ],
            'purchase' => [
                'label' => 'Purchase',
                'priority' => 'critical',
                'rationale' => 'Revenue event. Required for revenue analytics, LTV calculation, and attribution.',
                'code_snippet' => "Analytics::purchase('TXN-123', 99.99, [['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2]]);",
            ],
            'page_view' => [
                'label' => 'Page View',
                'priority' => 'medium',
                'rationale' => 'Traffic metric. Enables page popularity ranking and navigation flow analysis.',
                'code_snippet' => "// Auto-tracked via Inertia middleware or:\nawait trackPageView();",
            ],
            'scroll_depth' => [
                'label' => 'Scroll Depth',
                'priority' => 'medium',
                'rationale' => 'Content engagement signal. Measures how far users scroll on key pages.',
                'code_snippet' => "// Auto-tracked via client_auto_track.scroll_depth\n// Or: Analytics::track('scroll_depth', ['percent' => 75, 'page' => '/pricing']);",
            ],
            'search' => [
                'label' => 'Search',
                'priority' => 'medium',
                'rationale' => 'User intent signal. Identifies what users are looking for and search-to-conversion rates.',
                'code_snippet' => "Analytics::track('search', ['query' => 'analytics', 'results_count' => 12]);",
            ],
            'share' => [
                'label' => 'Share',
                'priority' => 'medium',
                'rationale' => 'Referral/viral signal. Tracks content sharing for viral coefficient calculation.',
                'code_snippet' => "Analytics::track('share', ['method' => 'twitter', 'content_id' => 'blog-123']);",
            ],
            'form_submit' => [
                'label' => 'Form Submit',
                'priority' => 'medium',
                'rationale' => 'Conversion signal. Tracks form completion for signup/contact/purchase funnels.',
                'code_snippet' => "Analytics::track('form_submit', ['form_name' => 'contact', 'form_id' => 'contact-form']);",
            ],
            'error' => [
                'label' => 'Error',
                'priority' => 'medium',
                'rationale' => 'Quality signal. Captures client-side errors for debugging and user experience improvement.',
                'code_snippet' => "// Auto-tracked via client_auto_track.error_tracking\n// Or: Analytics::track('error', ['message' => 'API timeout', 'fatal' => false]);",
            ],
        ];
    }

    /**
     * Guess event category from name.
     */
    private function guessCategory(string $key): string
    {
        if (str_starts_with($key, 'auth.') || str_contains($key, 'sign_up') || str_contains($key, 'login')) {
            return 'saas';
        }

        if (str_contains($key, 'subscription') || str_contains($key, 'plan_') || str_contains($key, 'trial') || str_contains($key, 'cancellation')) {
            return 'saas';
        }

        if (str_contains($key, 'purchase') || str_contains($key, 'cart') || str_contains($key, 'item') || str_contains($key, 'revenue')) {
            return 'ecommerce';
        }

        if (str_contains($key, 'scroll') || str_contains($key, 'click') || str_contains($key, 'page_view') || str_contains($key, 'form') || str_contains($key, 'search') || str_contains($key, 'share') || str_contains($key, 'error')) {
            return 'engagement';
        }

        return 'saas';
    }

    /**
     * Get maturity level name from score.
     */
    private function getMaturityLevel(float $score): string
    {
        if ($score >= 80) {
            return 'Enterprise';
        }

        if ($score >= 60) {
            return 'Growth';
        }

        if ($score >= 40) {
            return 'Starter+';
        }

        if ($score >= 20) {
            return 'Starter';
        }

        return 'Minimal';
    }

    /**
     * Get maturity grade from score.
     */
    private function getMaturityGrade(float $score): string
    {
        if ($score >= 90) {
            return 'A+';
        }

        if ($score >= 80) {
            return 'A';
        }

        if ($score >= 70) {
            return 'B+';
        }

        if ($score >= 60) {
            return 'B';
        }

        if ($score >= 50) {
            return 'C';
        }

        if ($score >= 30) {
            return 'D';
        }

        return 'F';
    }
}
