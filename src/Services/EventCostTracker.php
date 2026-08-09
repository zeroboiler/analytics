<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Event Cost Tracking Service.
 *
 * Estimates per-provider analytics costs based on event volume and
 * configured unit pricing. Helps SaaS teams monitor and control their
 * analytics spending across multiple providers.
 *
 * Supported cost models:
 * - Per-event (e.g., PostHog: $0.000225/event, Plausible: $9/1M events)
 * - Tiered (e.g., first 10K free, then $0.001/event)
 * - Flat (e.g., GA4 free tier, GTM no cost)
 *
 * Cost data is cached per-hour for dashboard queries and supports
 * projection (estimated monthly cost based on current velocity).
 *
 * Configuration: `zeroboiler.analytics.cost_tracking`
 *
 * @version 5.0.0
 */
final class EventCostTracker
{
    private const CACHE_PREFIX = 'zb_cost_';
    private const CACHE_TTL = 3600; // 1 hour

    /** @var array<string, array{enabled: bool, model: string, unit_cost: float, free_tier: int, currency: string}> */
    private array $providerPricing;

    private bool $enabled;

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    /**
     * Default pricing per provider (USD, as of 2026).
     *
     * @var array<string, array{model: string, unit_cost: float, free_tier: int}>
     */
    private const DEFAULT_PRICING = [
        'ga4' => [
            'model' => 'free',
            'unit_cost' => 0.0,
            'free_tier' => 0, // Unlimited free
        ],
        'gtm' => [
            'model' => 'free',
            'unit_cost' => 0.0,
            'free_tier' => 0, // No server cost (client-side)
        ],
        'meta' => [
            'model' => 'free',
            'unit_cost' => 0.0,
            'free_tier' => 0, // CAPI is free
        ],
        'plausible' => [
            'model' => 'tiered',
            'unit_cost' => 0.009, // $9 per 1M events
            'free_tier' => 0,
        ],
        'posthog' => [
            'model' => 'per_event',
            'unit_cost' => 0.000225, // ~$225 per 1M events
            'free_tier' => 1000000, // 1M free on free tier
        ],
        'webhook' => [
            'model' => 'free',
            'unit_cost' => 0.0,
            'free_tier' => 0, // Internal cost only
        ],
    ];

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->cache = $cache;

        $costConfig = $config->get('zeroboiler.analytics.cost_tracking', []);
        /** @var array{enabled?: bool, currency?: string, providers?: array<string, mixed>} $costConfig */

        $this->enabled = (bool) ($costConfig['enabled'] ?? false);
        $currency = (string) ($costConfig['currency'] ?? 'USD');

        // Merge user overrides with defaults
        $userPricing = (array) ($costConfig['providers'] ?? []);
        $this->providerPricing = [];

        foreach (self::DEFAULT_PRICING as $provider => $defaults) {
            $override = $userPricing[$provider] ?? [];
            /** @var array{model?: string, unit_cost?: float, free_tier?: int, enabled?: bool} $override */

            $this->providerPricing[$provider] = [
                'enabled' => (bool) ($override['enabled'] ?? true),
                'model' => (string) ($override['model'] ?? $defaults['model']),
                'unit_cost' => (float) ($override['unit_cost'] ?? $defaults['unit_cost']),
                'free_tier' => (int) ($override['free_tier'] ?? $defaults['free_tier']),
                'currency' => $currency,
            ];
        }
    }

    /**
     * Check if cost tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the full cost report for all providers.
     *
     * Includes current period costs, projected monthly costs,
     * cost per event, and budget utilization.
     *
     * @return array{enabled: bool, currency: string, providers: array<string, array<string, mixed>>, total: array{cost: float, events: int, projected_monthly: float}, period: string, generated_at: string}
     */
    public function report(): array
    {
        $totalCost = 0.0;
        $totalEvents = 0;
        $providerReports = [];

        foreach ($this->providerPricing as $provider => $pricing) {
            if (! $pricing['enabled']) {
                continue;
            }

            $events = $this->getProviderEventCount($provider);
            $cost = $this->calculateCost($provider, $events);
            $projected = $this->projectMonthlyCost($provider, $events);
            $costPerEvent = $events > 0 ? $cost / $events : 0.0;

            $providerReports[$provider] = [
                'events' => $events,
                'cost' => round($cost, 6),
                'cost_per_event' => round($costPerEvent, 8),
                'projected_monthly' => round($projected, 4),
                'model' => $pricing['model'],
                'unit_cost' => $pricing['unit_cost'],
                'free_tier' => $pricing['free_tier'],
                'free_tier_remaining' => max(0, $pricing['free_tier'] - $events),
                'currency' => $pricing['currency'],
            ];

            $totalCost += $cost;
            $totalEvents += $events;
        }

        return [
            'enabled' => $this->enabled,
            'currency' => $this->providerPricing['ga4']['currency'] ?? 'USD',
            'providers' => $providerReports,
            'total' => [
                'cost' => round($totalCost, 6),
                'events' => $totalEvents,
                'projected_monthly' => round($this->projectMonthlyCost(null, $totalEvents), 4),
            ],
            'period' => $this->getCurrentPeriod(),
            'generated_at' => date('c'),
        ];
    }

    /**
     * Get a cost summary suitable for CLI output.
     *
     * @return array{provider: string, events: int, cost: string, projected: string, model: string}[]
     */
    public function cliSummary(): array
    {
        $report = $this->report();
        $rows = [];

        foreach ($report['providers'] as $provider => $data) {
            $rows[] = [
                'provider' => $provider,
                'events' => $data['events'],
                'cost' => '$' . number_format($data['cost'], 4),
                'projected' => '$' . number_format($data['projected_monthly'], 2) . '/mo',
                'model' => $data['model'],
            ];
        }

        return $rows;
    }

    /**
     * Get cost for a single provider.
     *
     * @return array{events: int, cost: float, projected_monthly: float, model: string, currency: string}|null
     */
    public function providerCost(string $provider): ?array
    {
        $pricing = $this->providerPricing[$provider] ?? null;

        if ($pricing === null || ! $pricing['enabled']) {
            return null;
        }

        $events = $this->getProviderEventCount($provider);

        return [
            'events' => $events,
            'cost' => round($this->calculateCost($provider, $events), 6),
            'projected_monthly' => round($this->projectMonthlyCost($provider, $events), 4),
            'model' => $pricing['model'],
            'currency' => $pricing['currency'],
        ];
    }

    /**
     * Check if a provider is within its free tier.
     */
    public function isWithinFreeTier(string $provider): bool
    {
        $pricing = $this->providerPricing[$provider] ?? null;

        if ($pricing === null) {
            return false;
        }

        if ($pricing['free_tier'] === 0) {
            return $pricing['model'] === 'free';
        }

        return $this->getProviderEventCount($provider) < $pricing['free_tier'];
    }

    /**
     * Get the most expensive provider by projected monthly cost.
     *
     * @return array{provider: string, projected_monthly: float}|null
     */
    public function mostExpensiveProvider(): ?array
    {
        $report = $this->report();
        $maxCost = 0.0;
        $maxProvider = null;

        foreach ($report['providers'] as $provider => $data) {
            if ($data['projected_monthly'] > $maxCost) {
                $maxCost = $data['projected_monthly'];
                $maxProvider = $provider;
            }
        }

        if ($maxProvider === null) {
            return null;
        }

        return [
            'provider' => $maxProvider,
            'projected_monthly' => $maxCost,
        ];
    }

    /**
     * Calculate cost for a provider given event count.
     *
     * @param  string  $provider  Provider name
     * @param  int  $events  Event count
     */
    private function calculateCost(string $provider, int $events): float
    {
        $pricing = $this->providerPricing[$provider] ?? null;

        if ($pricing === null || $pricing['model'] === 'free') {
            return 0.0;
        }

        $billableEvents = max(0, $events - $pricing['free_tier']);

        return $billableEvents * $pricing['unit_cost'];
    }

    /**
     * Project monthly cost based on current event velocity.
     *
     * Assumes linear projection from current hourly data to 30 days.
     *
     * @param  string|null  $provider  Provider name (null for total)
     * @param  int  $currentEvents  Events in current period
     */
    private function projectMonthlyCost(?string $provider, int $currentEvents): float
    {
        if ($currentEvents === 0) {
            return 0.0;
        }

        $periodSeconds = $this->getPeriodSeconds();
        $multiplier = $periodSeconds > 0
            ? (30 * 24 * 3600) / $periodSeconds
            : 720; // Default: ~30 days / 1 hour

        $projectedEvents = (int) ($currentEvents * $multiplier);

        if ($provider !== null) {
            return $this->calculateCost($provider, $projectedEvents);
        }

        // For total, sum all providers proportionally
        $totalCost = 0.0;

        foreach ($this->providerPricing as $p => $pricing) {
            if (! $pricing['enabled']) {
                continue;
            }

            $providerEvents = $this->getProviderEventCount($p);
            $projectedProviderEvents = $providerEvents > 0
                ? (int) ($projectedEvents * ($providerEvents / max(1, $currentEvents)))
                : 0;

            $totalCost += $this->calculateCost($p, $projectedProviderEvents);
        }

        return $totalCost;
    }

    /**
     * Get event count for a provider from metrics.
     */
    private function getProviderEventCount(string $provider): int
    {
        return $this->metrics->getProviderCount($provider);
    }

    /**
     * Get the current cache period identifier (e.g., "2026-08-09-15").
     */
    private function getCurrentPeriod(): string
    {
        return date('Y-m-d-H');
    }

    /**
     * Get the number of seconds elapsed in the current period.
     */
    private function getPeriodSeconds(): int
    {
        return (int) (time() % 3600) ?: 3600;
    }

    /**
     * Get the configured provider pricing.
     *
     * @return array<string, array{enabled: bool, model: string, unit_cost: float, free_tier: int, currency: string}>
     */
    public function getProviderPricing(): array
    {
        return $this->providerPricing;
    }
}
