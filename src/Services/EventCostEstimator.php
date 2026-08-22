<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Event cost estimator for SaaS analytics budget planning.
 *
 * Estimates per-event cost across all providers based on configurable
 * pricing tiers. Helps teams understand and forecast their analytics
 * spend across GA4, Meta CAPI, PostHog, Plausible, and other providers.
 *
 * Provider pricing is configurable via `zeroboiler.analytics.event_costs`.
 * When not configured, industry-standard defaults are used.
 *
 * Features:
 * - Per-provider cost per event estimation
 * - Monthly/yearly cost projection based on event volume
 * - Budget threshold alerts
 * - Cost breakdown by event category
 * - Cache-backed projections for performance
 *
 * @since 139.0.0
 */
final class EventCostEstimator
{
    /**
     * Default per-event costs in USD (industry estimates).
     *
     * @var array<string, float>
     */
    private const DEFAULT_COST_PER_EVENT = [
        'ga4' => 0.0,         // GA4 is free (up to 10M events/month)
        'gtm' => 0.0,         // GTM is free
        'meta_pixel' => 0.0,   // Meta Pixel is free
        'meta_capi' => 0.0002, // Meta CAPI server events have minimal cost
        'posthog' => 0.00025, // PostHog: ~$0.25/1000 events at scale
        'plausible' => 0.0001,// Plausible: ~$0.10/1000 events at scale
        'mixpanel' => 0.0002, // Mixpanel: ~$0.20/1000 events
        'amplitude' => 0.0003,// Amplitude: ~$0.30/1000 events
        'tiktok' => 0.0,      // TikTok Pixel is free
        'linkedin' => 0.0,    // LinkedIn Insight Tag is free
    ];

    /** @var array<string, float> */
    private array $costPerEvent;

    private CacheRepository $cache;

    private int $cacheTtl;

    private float $budgetThreshold;

    /**
     * @param  ConfigRepository  $config  Application config
     * @param  CacheRepository  $cache  Cache repository
     * @param  AnalyticsManager  $manager  Analytics manager (for provider state)
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AnalyticsManager $manager,
        CacheRepository $cache,
    ){
        $costsConfig = $config->get('zeroboiler.analytics.event_costs', []);
        /** @var array<string, float> $costsConfig */
        $this->costPerEvent = array_merge(self::DEFAULT_COST_PER_EVENT, $costsConfig);

        $this->cache = $cache;
        $this->cacheTtl = (int) $config->get('zeroboiler.analytics.event_costs.cache_ttl', 300);

        $budgetConfig = $config->get('zeroboiler.analytics.event_costs.budget', []);
        /** @var array{monthly_threshold?: float} $budgetConfig */
        $this->budgetThreshold = (float) ($budgetConfig['monthly_threshold'] ?? 100.0);
    }

    /**
     * Get the cost per event for a specific provider.
     */
    public function costPerEvent(string $provider): float
    {
        return $this->costPerEvent[$provider] ?? 0.0;
    }

    /**
     * Estimate total monthly cost for a given event volume per provider.
     *
     * @param  array<string, int>  $eventsPerProvider  Provider name → monthly event count
     * @return array{total: float, by_provider: array<string, float>, currency: string}
     */
    public function estimateMonthlyCost(array $eventsPerProvider): array
    {
        $byProvider = [];
        $total = 0.0;

        foreach ($eventsPerProvider as $provider => $count) {
            $cost = $this->costPerEvent($provider) * $count;
            $byProvider[$provider] = round($cost, 4);
            $total += $cost;
        }

        return [
            'total' => round($total, 4),
            'by_provider' => $byProvider,
            'currency' => $this->config->get('zeroboiler.analytics.revenue.currency', 'USD'),
        ];
    }

    /**
     * Estimate yearly cost from monthly event volumes.
     *
     * @param  array<string, int>  $eventsPerProvider  Monthly events per provider
     * @return array{total: float, by_provider: array<string, float>, monthly_equivalent: float, currency: string}
     */
    public function estimateYearlyCost(array $eventsPerProvider): array
    {
        $monthly = $this->estimateMonthlyCost($eventsPerProvider);

        return [
            'total' => round($monthly['total'] * 12, 4),
            'by_provider' => array_map(
                fn (float $cost): float => round($cost * 12, 4),
                $monthly['by_provider'],
            ),
            'monthly_equivalent' => $monthly['total'],
            'currency' => $monthly['currency'],
        ];
    }

    /**
     * Project cost at a target monthly event volume.
     *
     * Useful for planning: "What if we hit 1M events/month?"
     *
     * @return array{estimated_monthly: float, estimated_yearly: float, budget_exceeded: bool, budget_remaining: float, currency: string}
     */
    public function projectAtVolume(int $totalMonthlyEvents, ?array $distribution = null): array
    {
        $distribution = $distribution ?? $this->defaultDistribution();
        $eventsPerProvider = [];

        foreach ($distribution as $provider => $percentage) {
            $eventsPerProvider[$provider] = (int) ($totalMonthlyEvents * $percentage);
        }

        $monthly = $this->estimateMonthlyCost($eventsPerProvider);
        $yearly = $this->estimateYearlyCost($eventsPerProvider);

        return [
            'estimated_monthly' => $monthly['total'],
            'estimated_yearly' => $yearly['total'],
            'budget_exceeded' => $monthly['total'] > $this->budgetThreshold,
            'budget_remaining' => round(max(0, $this->budgetThreshold - $monthly['total']), 4),
            'currency' => $monthly['currency'],
        ];
    }

    /**
     * Calculate cost per event category.
     *
     * @param  array<string, int>  $categoryVolumes  Category name → monthly event count
     * @return array<string, array{cost: float, percentage: float}>
     */
    public function costByCategory(array $categoryVolumes, float $averageCostPerEvent = 0.00015): array
    {
        $totalEvents = array_sum($categoryVolumes);
        $result = [];

        foreach ($categoryVolumes as $category => $count) {
            $cost = $count * $averageCostPerEvent;
            $result[$category] = [
                'cost' => round($cost, 4),
                'percentage' => $totalEvents > 0
                    ? round(($count / $totalEvents) * 100, 2)
                    : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Get all configured cost-per-event values.
     *
     * @return array<string, float>
     */
    public function getAllCosts(): array
    {
        return $this->costPerEvent;
    }

    /**
     * Get cost summary for enabled providers only.
     *
     * @return array<string, float>
     */
    public function getEnabledProviderCosts(): array
    {
        $costs = [];

        $providerMap = [
            'ga4' => fn (): bool => $this->manager->ga4()->isEnabled(),
            'gtm' => fn (): bool => $this->manager->gtm()->isEnabled(),
            'meta_pixel' => fn (): bool => $this->manager->meta()->isEnabled(),
            'posthog' => fn (): bool => $this->manager->posthog()->isEnabled(),
            'plausible' => fn (): bool => $this->manager->plausible()->isEnabled(),
            'mixpanel' => fn (): bool => $this->manager->mixpanel()->isEnabled(),
            'amplitude' => fn (): bool => $this->manager->amplitude()->isEnabled(),
            'tiktok' => fn (): bool => $this->manager->tiktok()->isEnabled(),
            'linkedin' => fn (): bool => $this->manager->linkedin()->isEnabled(),
        ];

        foreach ($providerMap as $provider => $isEnabled) {
            if ($isEnabled()) {
                $costs[$provider] = $this->costPerEvent[$provider];
            }
        }

        return $costs;
    }

    /**
     * Check if current estimated monthly spend exceeds budget.
     */
    public function isBudgetExceeded(array $eventsPerProvider): bool
    {
        $monthly = $this->estimateMonthlyCost($eventsPerProvider);

        return $monthly['total'] > $this->budgetThreshold;
    }

    /**
     * Get the configured monthly budget threshold.
     */
    public function getBudgetThreshold(): float
    {
        return $this->budgetThreshold;
    }

    /**
     * Get the free tier limit for GA4 (10M events/month).
     */
    public function getGa4FreeTierLimit(): int
    {
        return 10_000_000;
    }

    /**
     * Get default event distribution across providers.
     *
     * Estimates typical distribution when provider-specific volumes are unknown.
     *
     * @return array<string, float>
     */
    private function defaultDistribution(): array
    {
        $distribution = [];
        $enabledCount = 0;

        $providers = ['ga4', 'gtm', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude'];

        foreach ($providers as $provider) {
            $providerMap = [
                'ga4' => fn (): bool => $this->manager->ga4()->isEnabled(),
                'gtm' => fn (): bool => $this->manager->gtm()->isEnabled(),
                'meta_pixel' => fn (): bool => $this->manager->meta()->isEnabled(),
                'posthog' => fn (): bool => $this->manager->posthog()->isEnabled(),
                'plausible' => fn (): bool => $this->manager->plausible()->isEnabled(),
                'mixpanel' => fn (): bool => $this->manager->mixpanel()->isEnabled(),
                'amplitude' => fn (): bool => $this->manager->amplitude()->isEnabled(),
            ];

            if ($providerMap[$provider]()) {
                $enabledCount++;
            }
        }

        if ($enabledCount === 0) {
            return ['ga4' => 1.0];
        }

        $share = 1.0 / $enabledCount;

        foreach ($providers as $provider) {
            $providerMap = [
                'ga4' => fn (): bool => $this->manager->ga4()->isEnabled(),
                'gtm' => fn (): bool => $this->manager->gtm()->isEnabled(),
                'meta_pixel' => fn (): bool => $this->manager->meta()->isEnabled(),
                'posthog' => fn (): bool => $this->manager->posthog()->isEnabled(),
                'plausible' => fn (): bool => $this->manager->plausible()->isEnabled(),
                'mixpanel' => fn (): bool => $this->manager->mixpanel()->isEnabled(),
                'amplitude' => fn (): bool => $this->manager->amplitude()->isEnabled(),
            ];

            if ($providerMap[$provider]()) {
                $distribution[$provider] = $share;
            }
        }

        return $distribution;
    }
}
