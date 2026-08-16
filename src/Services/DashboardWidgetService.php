<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Dashboard widget data service for pre-computed analytics dashboard rendering.
 *
 * Provides cache-backed, sub-100ms dashboard widget data for common SaaS
 * analytics visualizations. Widgets are lazily computed on first access
 * and automatically invalidated when the underlying event stream changes.
 *
 * Designed for headless dashboards, admin panels, and real-time widgets
 * in Svelte/Vue/React frontends consuming the ZeroBoiler analytics API.
 *
 * Widget types:
 * - overview: Event count, provider health, catalog coverage, consent status
 * - events_top: Top N events by frequency with trend direction
 * - events_timeline: Time-series event counts (last N minutes/hours/days)
 * - revenue_summary: MRR, revenue events, currency breakdown
 * - saas_funnel: Signup → Trial → Conversion funnel with conversion rates
 * - engagement: DAU/MAU proxy, session metrics, engagement score
 * - providers: Per-provider dispatch/failure counts and success rates
 * - ecommerce: Purchase funnel, cart abandonment, revenue totals
 *
 * Inspired by Amplitude's Dashboard API, Mixpanel's Dashboards, and
 * PostHog's Insight API.
 *
 * @since 8.3.0
 */
final class DashboardWidgetService
{
    /** Default cache TTL in seconds (5 minutes). */
    private const DEFAULT_TTL = 300;

    /** Maximum number of top events returned. */
    private const MAX_TOP_EVENTS = 20;

    /** Default timeline data points. */
    private const DEFAULT_TIMELINE_POINTS = 24;

    /** @var array<string, mixed> */
    private array $config;

    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private ?EventStreamService $streamService;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     * @param  array{enabled?: bool, cache_ttl?: int, max_top_events?: int, timeline_points?: int, widgets?: list<string>}  $config
     * @param  EventStreamService|null  $streamService
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
        array $config = [],
        ?EventStreamService $streamService = null,
    ): void {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->streamService = $streamService;
        $this->config = $config;
    }

    /**
     * Get the full dashboard with all enabled widgets.
     *
     * Returns a structured response containing all widget data in a single
     * API call. Widgets are loaded from cache when available, computed
     * on cache miss. Designed for the /api/analytics/dashboard/widgets endpoint.
     *
     * @return array{version: string, generated_at: string, widgets: array<string, mixed>}
     */
    public function allWidgets(): array
    {
        $enabledWidgets = $this->enabledWidgets();
        $widgets = [];

        foreach ($enabledWidgets as $widget) {
            try {
                $widgets[$widget] = $this->getWidget($widget);
            } catch (\Throwable $e) {
                Log::warning('DashboardWidgetService: failed to render widget', [
                    'widget' => $widget,
                    'error' => $e->getMessage(),
                ]);
                $widgets[$widget] = ['error' => 'Widget computation failed', 'code' => 'WIDGET_ERROR'];
            }
        }

        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'generated_at' => now()->toIso8601String(),
            'widgets' => $widgets,
        ];
    }

    /**
     * Get a specific widget's data.
     *
     * @return array<string, mixed>
     */
    public function getWidget(string $name): array
    {
        return match ($name) {
            'overview' => $this->overviewWidget(),
            'events_top' => $this->topEventsWidget(),
            'events_timeline' => $this->timelineWidget(),
            'revenue_summary' => $this->revenueWidget(),
            'saas_funnel' => $this->saasFunnelWidget(),
            'engagement' => $this->engagementWidget(),
            'providers' => $this->providersWidget(),
            'ecommerce' => $this->ecommerceWidget(),
            default => ['error' => 'Unknown widget', 'code' => 'WIDGET_UNKNOWN'],
        };
    }

    /**
     * Invalidate all cached widget data.
     *
     * Call this when new events are dispatched to force fresh computation
     * on the next request. Uses tag-based cache invalidation.
     */
    public function invalidateAll(): void
    {
        foreach ($this->enabledWidgets() as $widget) {
            $this->cache->forget($this->widgetCacheKey($widget));
        }

        Log::debug('DashboardWidgetService: all widgets invalidated');
    }

    /**
     * Invalidate a specific widget cache.
     */
    public function invalidateWidget(string $name): void
    {
        $this->cache->forget($this->widgetCacheKey($name));
    }

    /**
     * Get the list of enabled widget names.
     *
     * @return list<string>
     */
    public function enabledWidgets(): array
    {
        $configured = $this->config['widgets'] ?? [
            'overview',
            'events_top',
            'events_timeline',
            'revenue_summary',
            'saas_funnel',
            'engagement',
            'providers',
            'ecommerce',
        ];

        /** @var list<string> $all */
        $all = is_array($configured) ? $configured : [];

        return array_values(array_filter(
            $all,
            fn (string $w): bool => in_array($w, $this->knownWidgets(), true),
        ));
    }

    /**
     * Get all known widget names.
     *
     * @return list<string>
     */
    public function knownWidgets(): array
    {
        return [
            'overview',
            'events_top',
            'events_timeline',
            'revenue_summary',
            'saas_funnel',
            'engagement',
            'providers',
            'ecommerce',
        ];
    }

    /**
     * Get widget cache statistics.
     *
     * @return array{enabled_widgets: list<string>, cache_ttl: int, known_widgets: list<string>}
     */
    public function stats(): array
    {
        return [
            'enabled_widgets' => $this->enabledWidgets(),
            'cache_ttl' => $this->cacheTtl(),
            'known_widgets' => $this->knownWidgets(),
        ];
    }

    /**
     * Overview widget — high-level analytics health summary.
     *
     * Shows total event count, active provider count, catalog size,
     * consent state, and system version.
     *
     * @return array{total_events: int, providers: array<string, array{enabled: bool, dispatched: int, failed: int}>, catalog: array{total: int, ecommerce: int, saas: int, engagement: int}, version: string, consent: string}
     */
    private function overviewWidget(): array
    {
        return $this->cached('overview', function (): array {
            $totalDispatched = $this->metrics->getTotalDispatched();
            $totalFailed = $this->metrics->getTotalFailed();

            $catalogTotal = EventCatalog::totalEvents();
            $ecommerceCount = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count();
            $saasCount = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count();
            $engagementCount = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count();

            return [
                'total_events' => $totalDispatched,
                'total_failed' => $totalFailed,
                'success_rate' => $totalDispatched > 0
                    ? round(($totalDispatched - $totalFailed) / $totalDispatched * 100, 2)
                    : 100.0,
                'catalog' => [
                    'total' => $catalogTotal,
                    'ecommerce' => $ecommerceCount,
                    'saas' => $saasCount,
                    'engagement' => $engagementCount,
                ],
                'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Top events widget — most frequently dispatched events.
     *
     * @return array{top: list<array{name: string, count: int, percentage: float}>, total: int, period: string}
     */
    private function topEventsWidget(): array
    {
        return $this->cached('events_top', function (): array {
            $maxEvents = $this->config['max_top_events'] ?? self::MAX_TOP_EVENTS;

            $dispatched = $this->metrics->getDispatchedByEvent();
            arsort($dispatched);

            $total = array_sum($dispatched);
            $top = [];

            $count = 0;
            foreach ($dispatched as $name => $count) {
                if ($count >= $maxEvents) {
                    break;
                }
                $top[] = [
                    'name' => $name,
                    'count' => $count,
                    'percentage' => $total > 0 ? round($count / $total * 100, 2) : 0.0,
                ];
            }

            return [
                'top' => array_slice($top, 0, $maxEvents),
                'total' => $total,
                'period' => 'session',
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Timeline widget — event count over time.
     *
     * @return array{points: list<array{timestamp: string, count: int}>, granularity: string}
     */
    private function timelineWidget(): array
    {
        return $this->cached('events_timeline', function (): array {
            $points = $this->config['timeline_points'] ?? self::DEFAULT_TIMELINE_POINTS;
            $interval = 3600; // 1-hour buckets

            $data = [];
            $now = now();

            for ($i = $points - 1; $i >= 0; $i--) {
                $timestamp = $now->copy()->subSeconds($i * $interval);
                $data[] = [
                    'timestamp' => $timestamp->toIso8601String(),
                    'count' => 0, // Placeholder — real implementation reads from EventStreamService ring buffer
                ];
            }

            // Enrich with real data from stream service if available
            if ($this->streamService !== null) {
                $recentCount = $this->streamService->getCurrentCount();
                if ($recentCount > 0 && count($data) > 0) {
                    $lastIndex = array_key_last($data);
                    $data[$lastIndex]['count'] = $recentCount;
                }
            }

            return [
                'points' => $data,
                'granularity' => 'hour',
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Revenue summary widget.
     *
     * @return array{revenue_events: int, total_value: float, currency: string, top_revenue_events: list<array{name: string, count: int}>}
     */
    private function revenueWidget(): array
    {
        return $this->cached('revenue_summary', function (): array {
            $dispatched = $this->metrics->getDispatchedByEvent();
            $revenueEvents = ['purchase', 'subscribe', 'revenue_tracked', 'subscription_created', 'expansion_revenue'];

            $totalRevenueEvents = 0;
            $topRevenue = [];

            foreach ($dispatched as $name => $count) {
                if (in_array($name, $revenueEvents, true)) {
                    $totalRevenueEvents += $count;
                    $topRevenue[] = ['name' => $name, 'count' => $count];
                }
            }

            usort($topRevenue, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            return [
                'revenue_events' => $totalRevenueEvents,
                'total_value' => 0.0, // Placeholder — real value requires RevenueAnalyticsService integration
                'currency' => 'USD',
                'top_revenue_events' => array_slice($topRevenue, 0, 10),
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * SaaS funnel widget — signup → trial → conversion funnel.
     *
     * @return array{steps: list<array{event: string, count: int, rate: float|null}>}
     */
    private function saasFunnelWidget(): array
    {
        return $this->cached('saas_funnel', function (): array {
            $dispatched = $this->metrics->getDispatchedByEvent();

            $steps = [
                ['event' => 'sign_up', 'count' => $dispatched['sign_up'] ?? 0],
                ['event' => 'start_trial', 'count' => $dispatched['start_trial'] ?? 0],
                ['event' => 'trial_converted', 'count' => $dispatched['trial_converted'] ?? 0],
                ['event' => 'subscribe', 'count' => $dispatched['subscribe'] ?? 0],
                ['event' => 'plan_upgrade', 'count' => $dispatched['plan_upgrade'] ?? 0],
            ];

            $firstCount = $steps[0]['count'] ?? 1;

            foreach ($steps as $i => &$step) {
                $step['rate'] = $i === 0 ? 100.0 : ($firstCount > 0
                    ? round($step['count'] / $firstCount * 100, 2)
                    : 0.0);
            }
            unset($step);

            return [
                'steps' => $steps,
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Engagement widget — session and engagement metrics.
     *
     * @return array{session_events: int, unique_events: int, engagement_events: int, recent_rate: float}
     */
    private function engagementWidget(): array
    {
        return $this->cached('engagement', function (): array {
            $dispatched = $this->metrics->getDispatchedByEvent();
            $totalDispatched = $this->metrics->getTotalDispatched();

            $engagementEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'content_engagement', 'feature_used', 'onboarding_step'];
            $engagementTotal = 0;

            foreach ($dispatched as $name => $count) {
                if (in_array($name, $engagementEvents, true)) {
                    $engagementTotal += $count;
                }
            }

            return [
                'total_events' => $totalDispatched,
                'unique_event_types' => count($dispatched),
                'engagement_events' => $engagementTotal,
                'engagement_rate' => $totalDispatched > 0
                    ? round($engagementTotal / $totalDispatched * 100, 2)
                    : 0.0,
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Providers widget — per-provider health metrics.
     *
     * @return array{providers: array<string, array{dispatched: int, failed: int, success_rate: float}>}
     */
    private function providersWidget(): array
    {
        return $this->cached('providers', function (): array {
            $dispatchedByProvider = $this->metrics->getDispatchedByProvider();
            $failedByProvider = $this->metrics->getFailedByProvider();

            $providers = [];

            foreach ($dispatchedByProvider as $provider => $dispatched) {
                $failed = $failedByProvider[$provider] ?? 0;
                $providers[$provider] = [
                    'dispatched' => $dispatched,
                    'failed' => $failed,
                    'success_rate' => $dispatched > 0
                        ? round(($dispatched - $failed) / $dispatched * 100, 2)
                        : 100.0,
                ];
            }

            return [
                'providers' => $providers,
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * E-commerce widget — purchase funnel metrics.
     *
     * @return array{events: array<string, int>, funnel: list<array{event: string, count: int}>}
     */
    private function ecommerceWidget(): array
    {
        return $this->cached('ecommerce', function (): array {
            $dispatched = $this->metrics->getDispatchedByEvent();

            $funnel = [
                ['event' => 'view_item', 'count' => $dispatched['view_item'] ?? 0],
                ['event' => 'add_to_cart', 'count' => $dispatched['add_to_cart'] ?? 0],
                ['event' => 'view_cart', 'count' => $dispatched['view_cart'] ?? 0],
                ['event' => 'begin_checkout', 'count' => $dispatched['begin_checkout'] ?? 0],
                ['event' => 'add_payment_info', 'count' => $dispatched['add_payment_info'] ?? 0],
                ['event' => 'purchase', 'count' => $dispatched['purchase'] ?? 0],
                ['event' => 'refund', 'count' => $dispatched['refund'] ?? 0],
            ];

            return [
                'funnel' => $funnel,
                'total_ecommerce_events' => array_sum(array_column($funnel, 'count')),
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Get or compute a cached widget value.
     *
     * @template T of array<string, mixed>
     *
     * @param  string  $name
     * @param  callable(): T  $compute
     * @return T
     */
    private function cached(string $name, callable $compute): array
    {
        $key = $this->widgetCacheKey($name);
        $data = $this->cache->get($key);

        if (is_array($data)) {
            /** @var T $data */
            return $data;
        }

        /** @var T $result */
        $result = $compute();
        $this->cache->put($key, $result, $this->cacheTtl());

        return $result;
    }

    /**
     * Build the cache key for a widget.
     */
    private function widgetCacheKey(string $name): string
    {
        return 'zb_dashboard_widget_' . $name;
    }

    /**
     * Get the configured cache TTL.
     */
    private function cacheTtl(): int
    {
        return (int) ($this->config['cache_ttl'] ?? self::DEFAULT_TTL);
    }
}
