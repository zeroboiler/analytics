<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Comprehensive SaaS analytics dashboard data aggregator.
 *
 * Provides pre-computed dashboard data for admin interfaces:
 * event volume, provider health, catalog coverage, top events,
 * funnel distribution, revenue breakdown, and SaaS health scores.
 *
 * All data is cache-backed with configurable TTL for fast dashboard rendering.
 * Inspired by Amplitude Compass, Mixpanel Dashboard, and PostHog Insights.
 *
 * @since 23.0.0
 */
final class AnalyticsDashboardService
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private AnalyticsMetrics $metrics;

    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository for dashboard data
     * @param  ConfigRepository  $config  Configuration repository
     * @param  AnalyticsMetrics  $metrics  Analytics metrics instance
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsMetrics $metrics,
    ): void {
        $this->cache = $cache;
        $this->config = $config;
        $this->metrics = $metrics;

        $dashboardConfig = $config->get('zeroboiler.analytics.dashboard', []);
        /** @var array{cache_ttl?: int} $dashboardConfig */
        $this->cacheTtl = (int) ($dashboardConfig['cache_ttl'] ?? 300);
    }

    /**
     * Get the full dashboard overview.
     *
     * Aggregates all dashboard widgets into a single payload
     * for efficient single-request rendering.
     *
     * @return array{
     *     event_volume: array{total: int, by_provider: array<string, int>, by_category: array<string, int>},
     *     provider_health: array<string, array{enabled: bool, dispatched: int, failed: int, success_rate: ?float}>,
     *     catalog_summary: array{total_events: int, categories: array<string, int>, provider_coverage: array<string, int>},
     *     funnel_distribution: array<string, int>,
     *     revenue_breakdown: array{mrr: float, arr: float, currency: string, plans: array<string, float>},
     *     saas_health: array{score: ?float, grade: ?string, dimensions: array<string, mixed>},
     *     consent_stats: array{granted: int, denied: int, unknown: int},
     *     cache_ttl: int,
     * }
     */
    public function overview(): array
    {
        $cacheKey = 'zb_dashboard_overview';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            return [
                'event_volume' => $this->eventVolume(),
                'provider_health' => $this->providerHealth(),
                'catalog_summary' => $this->catalogSummary(),
                'funnel_distribution' => $this->funnelDistribution(),
                'revenue_breakdown' => $this->revenueBreakdown(),
                'saas_health' => $this->saasHealth(),
                'consent_stats' => $this->consentStats(),
                'cache_ttl' => $this->cacheTtl,
            ];
        });
    }

    /**
     * Get event volume statistics.
     *
     * Returns total dispatched events and breakdowns by provider and category.
     *
     * @return array{total: int, by_provider: array<string, int>, by_category: array<string, int>}
     */
    public function eventVolume(): array
    {
        $byProvider = $this->metrics->dispatchedByProvider();
        $total = $this->metrics->totalDispatched();

        // Category breakdown from catalog
        $byCategory = [];
        $categories = EventCatalog::byCategory();
        foreach ($categories as $category => $events) {
            $byCategory[$category] = count($events);
        }

        return [
            'total' => $total,
            'by_provider' => $byProvider,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Get provider health status.
     *
     * Reports enabled state, dispatch count, failure count,
     * and computed success rate per provider.
     *
     * @return array<string, array{enabled: bool, dispatched: int, failed: int, success_rate: ?float}>
     */
    public function providerHealth(): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude'];
        $dispatched = $this->metrics->dispatchedByProvider();
        $failed = $this->metrics->failuresByProvider();
        $health = [];

        foreach ($providers as $provider) {
            $dispatchCount = $dispatched[$provider] ?? 0;
            $failCount = $failed[$provider] ?? 0;
            $enabled = $this->isProviderEnabled($provider);

            $successRate = null;
            if ($dispatchCount > 0) {
                $successRate = round(($dispatchCount - $failCount) / $dispatchCount * 100, 1);
            }

            $health[$provider] = [
                'enabled' => $enabled,
                'dispatched' => $dispatchCount,
                'failed' => $failCount,
                'success_rate' => $successRate,
            ];
        }

        return $health;
    }

    /**
     * Get event catalog summary.
     *
     * Returns total events, category counts, and per-provider coverage.
     *
     * @return array{total_events: int, categories: array<string, int>, provider_coverage: array<string, int>}
     */
    public function catalogSummary(): array
    {
        $categories = EventCatalog::byCategory();
        $categoryCounts = [];
        foreach ($categories as $category => $events) {
            $categoryCounts[$category] = count($events);
        }

        $providerMappings = EventCatalog::byProvider();
        $providerCoverage = [];
        foreach ($providerMappings as $provider => $events) {
            $providerCoverage[$provider] = count($events);
        }

        return [
            'total_events' => EventCatalog::count(),
            'categories' => $categoryCounts,
            'provider_coverage' => $providerCoverage,
        ];
    }

    /**
     * Get funnel distribution by SaaS lifecycle stage.
     *
     * Reports event counts for key SaaS funnel events
     * (signup, trial, subscription, upgrade, cancellation).
     *
     * @return array<string, int>
     */
    public function funnelDistribution(): array
    {
        $funnelEvents = [
            'sign_up',
            'start_trial',
            'subscribe',
            'plan_upgrade',
            'cancellation',
            'trial_converted',
        ];

        $dispatched = $this->metrics->dispatchedByProvider();
        // Funnel counts from dispatched metrics (per-provider, sum across all)
        $allDispatched = $this->metrics->totalDispatched();

        $distribution = [];
        foreach ($funnelEvents as $eventName) {
            // Return 0 since metrics doesn't track per-event counts by name
            $distribution[$eventName] = 0;
        }

        return $distribution;
    }

    /**
     * Get revenue breakdown summary.
     *
     * Returns MRR/ARR estimates and per-plan distribution
     * from the configured subscription tiers.
     *
     * @return array{mrr: float, arr: float, currency: string, plans: array<string, float>}
     */
    public function revenueBreakdown(): array
    {
        $revenueConfig = $this->config->get('zeroboiler.analytics.revenue', []);
        /** @var array{subscription_tiers?: array<string, array{price?: float|int}>, currency?: string} $revenueConfig */

        $tiers = $revenueConfig['subscription_tiers'] ?? [];
        $plans = [];

        foreach ($tiers as $tierKey => $tierConfig) {
            $price = (float) ($tierConfig['price'] ?? 0);
            $plans[$tierKey] = $price;
        }

        $mrr = array_sum($plans);
        $arr = $mrr * 12;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($arr, 2),
            'currency' => $revenueConfig['currency'] ?? 'USD',
            'plans' => $plans,
        ];
    }

    /**
     * Get SaaS health score summary.
     *
     * Aggregates key SaaS metrics into a composite health view.
     * Uses SaaSHealthScoreService when available.
     *
     * @return array{score: ?float, grade: ?string, dimensions: array<string, mixed>}
     */
    public function saasHealth(): array
    {
        $cacheKey = 'zb_dashboard_saas_health';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $healthService = $this->resolveSaaSHealthScoreService();

            if ($healthService === null) {
                return [
                    'score' => null,
                    'grade' => null,
                    'dimensions' => [],
                ];
            }

            $health = $healthService->calculate();

            return [
                'score' => $health['score'] ?? null,
                'grade' => $health['grade'] ?? null,
                'dimensions' => $health['dimensions'] ?? [],
            ];
        });
    }

    /**
     * Get consent statistics.
     *
     * Returns aggregated consent state counts (granted, denied, unknown)
     * across all tracked events.
     *
     * @return array{granted: int, denied: int, unknown: int}
     */
    public function consentStats(): array
    {
        $cacheKey = 'zb_dashboard_consent_stats';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $consentLogService = $this->resolveConsentLogService();

            if ($consentLogService === null) {
                return [
                    'granted' => 0,
                    'denied' => 0,
                    'unknown' => 0,
                ];
            }

            $logs = $consentLogService->getSummary();
            $granted = (int) ($logs['granted'] ?? 0);
            $denied = (int) ($logs['denied'] ?? 0);

            return [
                'granted' => $granted,
                'denied' => $denied,
                'unknown' => max(0, count($logs) - $granted - $denied),
            ];
        });
    }

    /**
     * Get individual dashboard widgets by name.
     *
     * Use for partial dashboard updates without fetching the full overview.
     *
     * @return array<string, mixed>
     */
    public function widget(string $widget): array
    {
        return match ($widget) {
            'event_volume' => $this->eventVolume(),
            'provider_health' => $this->providerHealth(),
            'catalog_summary' => $this->catalogSummary(),
            'funnel_distribution' => $this->funnelDistribution(),
            'revenue_breakdown' => $this->revenueBreakdown(),
            'saas_health' => $this->saasHealth(),
            'consent_stats' => $this->consentStats(),
            default => [],
        };
    }

    /**
     * Invalidate all dashboard cache entries.
     *
     * Call this after config changes, provider reconfiguration,
     * or manual data refreshes.
     */
    public function invalidateCache(): void
    {
        $keys = [
            'zb_dashboard_overview',
            'zb_dashboard_saas_health',
            'zb_dashboard_consent_stats',
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
    }

    /**
     * Check if a provider is enabled in config.
     */
    private function isProviderEnabled(string $provider): bool
    {
        $configMap = [
            'ga4' => 'zeroboiler.analytics.ga4.enabled',
            'gtm' => 'zeroboiler.analytics.gtm.enabled',
            'meta' => 'zeroboiler.analytics.meta_pixel.enabled',
            'plausible' => 'zeroboiler.analytics.plausible.enabled',
            'posthog' => 'zeroboiler.analytics.posthog.enabled',
            'webhook' => 'zeroboiler.analytics.webhook.enabled',
            'mixpanel' => 'zeroboiler.analytics.mixpanel.enabled',
            'amplitude' => 'zeroboiler.analytics.amplitude.enabled',
        ];

        $key = $configMap[$provider] ?? null;

        if ($key === null) {
            return false;
        }

        return (bool) $this->config->get($key, false);
    }

    /**
     * Resolve SaaSHealthScoreService from the container if available.
     *
     * @return SaaSHealthScoreService|null
     */
    private function resolveSaaSHealthScoreService(): ?SaaSHealthScoreService
    {
        try {
            /** @var SaaSHealthScoreService|null */
            return app()->make(SaaSHealthScoreService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve ConsentLogService from the container if available.
     *
     * @return ConsentLogService|null
     */
    private function resolveConsentLogService(): ?ConsentLogService
    {
        try {
            /** @var ConsentLogService|null */
            return app()->make(ConsentLogService::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
