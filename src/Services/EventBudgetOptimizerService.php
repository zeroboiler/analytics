<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Event Budget Optimizer — cost-aware intelligent event routing.
 *
 * Analyzes event costs per provider, respects budget limits, and optimizes
 * routing decisions to minimize cost while maintaining tracking quality.
 * Integrates with EventCostTracker and ProviderSLAMonitor for informed routing.
 *
 * Features:
 * - Per-provider budget tracking with configurable thresholds
 * - Cost-per-event calculation and forecasting
 * - Intelligent routing recommendations (skip/delay/batch low-value events)
 * - Budget utilization alerts at configurable percentages
 * - Provider cost comparison and optimization suggestions
 *
 * @since 85.0.0
 */
final class EventBudgetOptimizerService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int<1, max> Cache TTL in seconds */
    private int $ttl;

    /** @var string Cache prefix */
    private string $prefix = 'zb_budget_optimizer:';

    /** @var array<string, float> Per-provider cost per event (USD) */
    private array $costPerEvent;

    /** @var array<string, float> Per-provider monthly budgets (USD) */
    private array $budgets;

    /** @var array<int, float> Alert thresholds (percentages) */
    private array $alertThresholds = [50.0, 75.0, 90.0, 100.0];

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $ttl  Cache TTL in seconds
     * @param  array<string, float>  $costPerEvent  Provider cost per event
     * @param  array<string, float>  $budgets  Monthly provider budgets
     */
    public function __construct(
        CacheRepository $cache,
        int $ttl = 86400,
        array $costPerEvent = [],
        array $budgets = [],
    ): void {
        $this->cache = $cache;
        $this->ttl = $ttl;

        $this->costPerEvent = $costPerEvent !== []
            ? $costPerEvent
            : [
                'ga4' => 0.0001,
                'gtm' => 0.0,
                'meta' => 0.0002,
                'plausible' => 0.00015,
                'posthog' => 0.0001,
                'mixpanel' => 0.0002,
                'amplitude' => 0.00015,
                'tiktok' => 0.0002,
                'linkedin' => 0.0003,
                'webhook' => 0.0,
            ];

        $this->budgets = $budgets !== []
            ? $budgets
            : [
                'ga4' => 50.0,
                'gtm' => 0.0,
                'meta' => 100.0,
                'plausible' => 30.0,
                'posthog' => 75.0,
                'mixpanel' => 100.0,
                'amplitude' => 75.0,
                'tiktok' => 50.0,
                'linkedin' => 25.0,
                'webhook' => 0.0,
            ];
    }

    /**
     * Record an event dispatch to a provider for cost tracking.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, etc.)
     * @param  int  $eventCount  Number of events dispatched
     */
    public function recordDispatch(string $provider, int $eventCount = 1): void
    {
        $provider = strtolower($provider);
        $monthKey = $this->currentMonth();
        $countKey = $this->prefix . 'dispatch_count:' . $provider . ':' . $monthKey;

        $current = (int) $this->cache->get($countKey, 0);
        $this->cache->put($countKey, $current + $eventCount, $this->ttl * 30);
    }

    /**
     * Get current month's total cost for a provider.
     *
     * @param  string  $provider  Provider name
     * @return float Total cost in USD
     */
    public function providerCost(string $provider): float
    {
        $provider = strtolower($provider);
        $count = $this->dispatchCount($provider);
        $costPerEvent = $this->costPerEvent[$provider] ?? 0.0;

        return round($count * $costPerEvent, 6);
    }

    /**
     * Get current month's total cost across all providers.
     *
     * @return float Total cost in USD
     */
    public function totalCost(): float
    {
        $total = 0.0;

        foreach (array_keys($this->costPerEvent) as $provider) {
            $total += $this->providerCost($provider);
        }

        return round($total, 6);
    }

    /**
     * Get budget utilization percentage for a provider.
     *
     * @param  string  $provider  Provider name
     * @return float Budget utilization (0-100+), null if no budget set
     */
    public function budgetUtilization(string $provider): ?float
    {
        $provider = strtolower($provider);
        $budget = $this->budgets[$provider] ?? null;

        if ($budget === null || $budget <= 0.0) {
            return null; // No budget configured
        }

        $cost = $this->providerCost($provider);

        return round(($cost / $budget) * 100, 2);
    }

    /**
     * Check if a provider has exceeded its budget.
     *
     * @param  string  $provider  Provider name
     * @return bool
     */
    public function isBudgetExceeded(string $provider): bool
    {
        $utilization = $this->budgetUtilization($provider);

        return $utilization !== null && $utilization >= 100.0;
    }

    /**
     * Check if a provider is approaching its budget (>= 90%).
     *
     * @param  string  $provider  Provider name
     * @return bool
     */
    public function isBudgetApproaching(string $provider): bool
    {
        $utilization = $this->budgetUtilization($provider);

        return $utilization !== null && $utilization >= 90.0;
    }

    /**
     * Get routing recommendation for an event to a provider.
     *
     * Returns 'allow', 'delay', 'batch', or 'skip' based on budget state.
     *
     * @param  string  $provider  Provider name
     * @param  string  $eventName  Event name (for priority evaluation)
     * @param  int  $priority  Event priority (1=critical, 5=low)
     * @return 'allow'|'delay'|'batch'|'skip'
     */
    public function routingRecommendation(string $provider, string $eventName, int $priority = 3): string
    {
        $provider = strtolower($provider);

        // Critical events always pass through
        if ($priority === 1) {
            return 'allow';
        }

        // Free providers always allow
        $costPerEvent = $this->costPerEvent[$provider] ?? 0.0;
        if ($costPerEvent <= 0.0) {
            return 'allow';
        }

        // No budget configured — allow
        $budget = $this->budgets[$provider] ?? null;
        if ($budget === null || $budget <= 0.0) {
            return 'allow';
        }

        $utilization = $this->budgetUtilization($provider);

        if ($utilization === null) {
            return 'allow';
        }

        if ($utilization >= 100.0) {
            // Budget exceeded — only critical events
            return $priority <= 2 ? 'delay' : 'skip';
        }

        if ($utilization >= 90.0) {
            // Near budget — batch non-critical events
            return $priority <= 2 ? 'allow' : 'batch';
        }

        if ($utilization >= 75.0) {
            // Approaching budget — batch low-priority
            return $priority <= 2 ? 'allow' : ($priority <= 3 ? 'batch' : 'delay');
        }

        return 'allow';
    }

    /**
     * Get budget alert status for all providers.
     *
     * @return list<array{provider: string, budget: float, cost: float, utilization: float, status: 'healthy'|'warning'|'critical'|'exceeded', recommendation: string}>
     */
    public function budgetAlerts(): array
    {
        $alerts = [];

        foreach ($this->budgets as $provider => $budget) {
            if ($budget <= 0.0) {
                continue;
            }

            $cost = $this->providerCost($provider);
            $utilization = $this->budgetUtilization($provider) ?? 0.0;

            $status = match (true) {
                $utilization >= 100.0 => 'exceeded',
                $utilization >= 90.0 => 'critical',
                $utilization >= 75.0 => 'warning',
                default => 'healthy',
            };

            $alerts[] = [
                'provider' => $provider,
                'budget' => $budget,
                'cost' => $cost,
                'utilization' => $utilization,
                'status' => $status,
                'recommendation' => $this->statusRecommendation($status),
            ];
        }

        // Sort by utilization descending
        usort($alerts, fn (array $a, array $b): int => $b['utilization'] <=> $a['utilization']);

        return $alerts;
    }

    /**
     * Get cost comparison across all providers.
     *
     * @return array{providers: list<array{provider: string, cost: float, budget: float, utilization: float|null, cost_per_event: float, event_count: int}>, total_cost: float, savings_potential: float}
     */
    public function costComparison(): array
    {
        $providers = [];
        $totalCost = 0.0;
        $totalBudget = 0.0;

        foreach ($this->costPerEvent as $provider => $cpe) {
            $cost = $this->providerCost($provider);
            $count = $this->dispatchCount($provider);
            $budget = $this->budgets[$provider] ?? 0.0;
            $utilization = $this->budgetUtilization($provider);

            $providers[] = [
                'provider' => $provider,
                'cost' => $cost,
                'budget' => $budget,
                'utilization' => $utilization,
                'cost_per_event' => $cpe,
                'event_count' => $count,
            ];

            $totalCost += $cost;
            $totalBudget += $budget;
        }

        return [
            'providers' => $providers,
            'total_cost' => round($totalCost, 6),
            'savings_potential' => round(max(0.0, $totalBudget - $totalCost), 6),
        ];
    }

    /**
     * Get cost forecast for the current month (projected based on daily average).
     *
     * @return array{current_cost: float, projected_monthly: float, days_remaining: int, daily_average: float}
     */
    public function costForecast(): array
    {
        $totalCost = $this->totalCost();
        $dayOfMonth = (int) date('j');
        $daysInMonth = (int) date('t');
        $daysRemaining = $daysInMonth - $dayOfMonth;
        $dailyAverage = $dayOfMonth > 0 ? $totalCost / $dayOfMonth : 0.0;
        $projectedMonthly = $dailyAverage * $daysInMonth;

        return [
            'current_cost' => $totalCost,
            'projected_monthly' => round($projectedMonthly, 6),
            'days_remaining' => $daysRemaining,
            'daily_average' => round($dailyAverage, 6),
        ];
    }

    /**
     * Get optimization suggestions for cost reduction.
     *
     * @return list<array{type: string, provider: string, suggestion: string, estimated_savings: float}>
     */
    public function optimizationSuggestions(): array
    {
        $suggestions = [];

        foreach ($this->costPerEvent as $provider => $cpe) {
            if ($cpe <= 0.0) {
                continue;
            }

            $cost = $this->providerCost($provider);
            $count = $this->dispatchCount($provider);

            if ($count === 0) {
                continue;
            }

            // Suggest batching for high-volume providers
            if ($count > 10000) {
                $batchSavings = $count * $cpe * 0.1; // 10% savings from batching
                $suggestions[] = [
                    'type' => 'batch',
                    'provider' => $provider,
                    'suggestion' => "Batch {$provider} events — {$count} events/month could be reduced by ~10% with batch dispatch",
                    'estimated_savings' => round($batchSavings, 6),
                ];
            }

            // Suggest skipping low-value events for over-budget providers
            $utilization = $this->budgetUtilization($provider);
            if ($utilization !== null && $utilization >= 80.0) {
                $skipSavings = $count * $cpe * 0.3; // 30% savings from skipping
                $suggestions[] = [
                    'type' => 'skip_low_priority',
                    'provider' => $provider,
                    'suggestion' => "Skip low-priority events for {$provider} — budget at {$utilization}% utilization",
                    'estimated_savings' => round($skipSavings, 6),
                ];
            }

            // Suggest sampling for very high-volume providers
            if ($count > 50000) {
                $samplingSavings = $count * $cpe * 0.2; // 20% savings from sampling
                $suggestions[] = [
                    'type' => 'sample',
                    'provider' => $provider,
                    'suggestion' => "Apply 80/20 sampling for {$provider} — reduce volume by 80% while maintaining statistical significance",
                    'estimated_savings' => round($samplingSavings, 6),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Get full budget optimizer dashboard summary.
     *
     * @return array{alerts: list<mixed>, comparison: array<string, mixed>, forecast: array<string, mixed>, suggestions: list<mixed>, summary: array<string, mixed>}
     */
    public function dashboard(): array
    {
        $forecast = $this->costForecast();
        $comparison = $this->costComparison();
        $alerts = $this->budgetAlerts();

        $criticalCount = count(array_filter($alerts, fn (array $a): bool => in_array($a['status'], ['critical', 'exceeded'], true)));

        return [
            'alerts' => $alerts,
            'comparison' => $comparison,
            'forecast' => $forecast,
            'suggestions' => $this->optimizationSuggestions(),
            'summary' => [
                'total_cost' => $comparison['total_cost'],
                'projected_monthly' => $forecast['projected_monthly'],
                'savings_potential' => $comparison['savings_potential'],
                'providers_at_risk' => $criticalCount,
                'total_providers' => count($this->costPerEvent),
            ],
        ];
    }

    // ─── Internal Helpers ──────────────────────────────────────────────────

    /**
     * Get dispatch count for a provider in the current month.
     */
    private function dispatchCount(string $provider): int
    {
        $monthKey = $this->currentMonth();
        $countKey = $this->prefix . 'dispatch_count:' . $provider . ':' . $monthKey;

        return (int) $this->cache->get($countKey, 0);
    }

    /**
     * Get current month key (YYYY-MM).
     */
    private function currentMonth(): string
    {
        return date('Y-m');
    }

    /**
     * Get human-readable recommendation for a budget status.
     */
    private function statusRecommendation(string $status): string
    {
        return match ($status) {
            'healthy' => 'No action needed — budget utilization within normal range.',
            'warning' => 'Monitor closely — consider batching or sampling low-priority events.',
            'critical' => 'Reduce event volume — skip low-priority events and enable sampling.',
            'exceeded' => 'Budget exceeded — only dispatch critical events until next billing cycle.',
            default => 'Unknown status.',
        };
    }
}
