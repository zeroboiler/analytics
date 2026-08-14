<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics Event Cost Ledger — per-event dispatch cost tracking across providers.
 *
 * Tracks the computational and financial cost of every dispatched event,
 * providing SaaS operators with:
 * - Per-event cost breakdown by provider (API calls, bandwidth, processing)
 * - Budget alerts when spending exceeds configured thresholds
 * - Cost optimization recommendations (drop low-value events, consolidate providers)
 * - Daily/weekly/monthly cost aggregation and trend analysis
 * - Provider cost comparison for vendor negotiations
 *
 * Cost tracking is cache-backed (lightweight) and can be exported to
 * external billing/analytics systems via the wire protocol.
 *
 * Configuration: `zeroboiler.analytics.cost_ledger`
 *
 * @see \ZeroBoiler\Analytics\Services\EventBudgetService
 * @see \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService
 *
 * @since 86.0.0
 */
final class EventCostLedgerService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_cost_ledger_';

    /** @var int Default daily budget threshold for alerts (in USD) */
    private const DEFAULT_DAILY_BUDGET = 100.0;

    private readonly CacheRepository $cache;

    private readonly int $cacheTtl;

    private readonly bool $enabled;

    private readonly float $dailyBudget;

    private readonly float $monthlyBudget;

    /** @var array<string, float> Per-provider estimated cost per 1000 events (USD) */
    private readonly array $providerCostRates;

    /** @var list<string> Events exempt from cost tracking */
    private readonly array $exemptEvents;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $ledgerConfig = $config->get('zeroboiler.analytics.cost_ledger', []);
        /** @var array{enabled?: bool, cache_ttl?: int, daily_budget?: float, monthly_budget?: float, provider_cost_rates?: array<string, float>, exempt_events?: list<string>} $ledgerConfig */

        $this->enabled = (bool) ($ledgerConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($ledgerConfig['cache_ttl'] ?? 86400);
        $this->dailyBudget = (float) ($ledgerConfig['daily_budget'] ?? self::DEFAULT_DAILY_BUDGET);
        $this->monthlyBudget = (float) ($ledgerConfig['monthly_budget'] ?? ($this->dailyBudget * 30));
        $this->providerCostRates = (array) ($ledgerConfig['provider_cost_rates'] ?? [
            'ga4' => 0.001,
            'gtm' => 0.001,
            'meta' => 0.001,
            'plausible' => 0.0005,
            'posthog' => 0.0005,
            'mixpanel' => 0.001,
            'amplitude' => 0.001,
            'tiktok' => 0.001,
            'linkedin' => 0.001,
            'webhook' => 0.0001,
        ]);
        $this->exemptEvents = (array) ($ledgerConfig['exempt_events'] ?? ['page_view', 'scroll_depth']);
    }

    /**
     * Record a dispatched event's cost.
     *
     * @param  string  $eventName  Event name
     * @param  string  $provider  Provider identifier (ga4, meta, posthog, etc.)
     * @param  float|null  $latencyMs  Dispatch latency in milliseconds
     * @param  bool  $success  Whether dispatch succeeded
     * @return array{recorded: bool, cost: float, daily_total: float, budget_remaining: float}
     */
    public function recordDispatch(
        string $eventName,
        string $provider,
        ?float $latencyMs = null,
        bool $success = true,
    ): array {
        if (!$this->enabled || in_array($eventName, $this->exemptEvents, true)) {
            return ['recorded' => false, 'cost' => 0.0, 'daily_total' => 0.0, 'budget_remaining' => 0.0];
        }

        $costPerK = $this->providerCostRates[$provider] ?? 0.0001;
        $eventCost = $costPerK / 1000.0;

        $dateKey = date('Y-m-d');
        $ledgerKey = self::CACHE_PREFIX . 'day_' . $dateKey;

        /** @var array{events: array<string, array<string, mixed>>, providers: array<string, array<string, mixed>>, total_events: int, total_cost: float, failed_events: int} $ledger */
        $ledger = $this->cache->get($ledgerKey) ?? [
            'events' => [],
            'providers' => [],
            'total_events' => 0,
            'total_cost' => 0.0,
            'failed_events' => 0,
        ];

        // Update per-event counters
        if (!isset($ledger['events'][$eventName])) {
            $ledger['events'][$eventName] = [
                'count' => 0,
                'cost' => 0.0,
                'providers' => [],
            ];
        }
        $ledger['events'][$eventName]['count']++;
        $ledger['events'][$eventName]['cost'] += $eventCost;
        $ledger['events'][$eventName]['providers'][$provider] = ($ledger['events'][$eventName]['providers'][$provider] ?? 0) + 1;

        // Update per-provider counters
        if (!isset($ledger['providers'][$provider])) {
            $ledger['providers'][$provider] = [
                'count' => 0,
                'cost' => 0.0,
                'avg_latency_ms' => 0.0,
                'failed' => 0,
            ];
        }
        $ledger['providers'][$provider]['count']++;
        $ledger['providers'][$provider]['cost'] += $eventCost;

        if ($latencyMs !== null) {
            $prev = $ledger['providers'][$provider]['avg_latency_ms'];
            $prevCount = $ledger['providers'][$provider]['count'] - 1;
            $ledger['providers'][$provider]['avg_latency_ms'] = $prevCount > 0
                ? (($prev * $prevCount) + $latencyMs) / $ledger['providers'][$provider]['count']
                : $latencyMs;
        }

        if (!$success) {
            $ledger['providers'][$provider]['failed']++;
            $ledger['failed_events']++;
        }

        $ledger['total_events']++;
        $ledger['total_cost'] += $eventCost;

        $this->cache->set($ledgerKey, $ledger, $this->cacheTtl);

        return [
            'recorded' => true,
            'cost' => $eventCost,
            'daily_total' => $ledger['total_cost'],
            'budget_remaining' => max(0.0, $this->dailyBudget - $ledger['total_cost']),
        ];
    }

    /**
     * Get the current day's cost ledger summary.
     *
     * @return array{date: string, total_events: int, total_cost: float, failed_events: int, budget_remaining: float, budget_used_pct: float, top_events: list<array{name: string, count: int, cost: float}>, provider_breakdown: array<string, array{count: int, cost: float, avg_latency_ms: float, failed: int}>, is_budget_alert: bool}
     */
    public function getDailySummary(): array
    {
        $dateKey = date('Y-m-d');
        $ledgerKey = self::CACHE_PREFIX . 'day_' . $dateKey;

        /** @var array{events: array<string, array<string, mixed>>, providers: array<string, array<string, mixed>>, total_events: int, total_cost: float, failed_events: int}|null $ledger */
        $ledger = $this->cache->get($ledgerKey);

        if ($ledger === null) {
            return [
                'date' => $dateKey,
                'total_events' => 0,
                'total_cost' => 0.0,
                'failed_events' => 0,
                'budget_remaining' => $this->dailyBudget,
                'budget_used_pct' => 0.0,
                'top_events' => [],
                'provider_breakdown' => [],
                'is_budget_alert' => false,
            ];
        }

        $topEvents = [];
        foreach ($ledger['events'] as $name => $data) {
            $topEvents[] = [
                'name' => $name,
                'count' => $data['count'],
                'cost' => round($data['cost'], 6),
            ];
        }
        usort($topEvents, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $topEvents = array_slice($topEvents, 0, 10);

        $providerBreakdown = [];
        foreach ($ledger['providers'] as $provider => $data) {
            $providerBreakdown[$provider] = [
                'count' => $data['count'],
                'cost' => round($data['cost'], 6),
                'avg_latency_ms' => round($data['avg_latency_ms'], 2),
                'failed' => $data['failed'],
            ];
        }

        $budgetUsedPct = $this->dailyBudget > 0
            ? round(($ledger['total_cost'] / $this->dailyBudget) * 100, 2)
            : 0.0;

        return [
            'date' => $dateKey,
            'total_events' => $ledger['total_events'],
            'total_cost' => round($ledger['total_cost'], 6),
            'failed_events' => $ledger['failed_events'],
            'budget_remaining' => round(max(0.0, $this->dailyBudget - $ledger['total_cost']), 6),
            'budget_used_pct' => $budgetUsedPct,
            'top_events' => $topEvents,
            'provider_breakdown' => $providerBreakdown,
            'is_budget_alert' => $budgetUsedPct >= 80.0,
        ];
    }

    /**
     * Check if the daily budget has been exceeded.
     *
     * @return array{exceeded: bool, used: float, budget: float, used_pct: float, recommendation: string|null}
     */
    public function checkBudgetStatus(): array
    {
        $summary = $this->getDailySummary();
        $exceeded = $summary['total_cost'] > $this->dailyBudget;
        $recommendation = null;

        if ($exceeded) {
            $recommendation = 'Daily budget exceeded. Consider reducing event volume or disabling low-value events.';
        } elseif ($summary['budget_used_pct'] >= 80.0) {
            $recommendation = 'Approaching daily budget (80%+). Monitor closely.';
        }

        return [
            'exceeded' => $exceeded,
            'used' => $summary['total_cost'],
            'budget' => $this->dailyBudget,
            'used_pct' => $summary['budget_used_pct'],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Get cost optimization recommendations.
     *
     * Analyzes cost patterns and suggests optimizations.
     *
     * @return list<array{type: string, event: string|null, provider: string|null, current_cost: float, potential_savings: float, recommendation: string}>
     */
    public function getOptimizationRecommendations(): array
    {
        $summary = $this->getDailySummary();
        $recommendations = [];

        if (empty($summary['top_events'])) {
            return $recommendations;
        }

        // Identify high-cost low-volume events (candidates for removal)
        $avgCostPerEvent = $summary['total_events'] > 0
            ? $summary['total_cost'] / $summary['total_events']
            : 0.0;

        foreach ($summary['top_events'] as $event) {
            $eventAvg = $event['count'] > 0 ? $event['cost'] / $event['count'] : 0.0;

            if ($eventAvg > $avgCostPerEvent * 5 && $event['cost'] > $summary['total_cost'] * 0.01) {
                $recommendations[] = [
                    'type' => 'high_cost_event',
                    'event' => $event['name'],
                    'provider' => null,
                    'current_cost' => $event['cost'],
                    'potential_savings' => $event['cost'] * 0.8,
                    'recommendation' => "Event '{$event['name']}' costs {$eventAvg}x average per dispatch. Consider sampling or removing.",
                ];
            }
        }

        // Check for providers with high failure rates
        foreach ($summary['provider_breakdown'] as $provider => $data) {
            $failureRate = $data['count'] > 0 ? $data['failed'] / $data['count'] : 0.0;
            if ($failureRate > 0.05 && $data['count'] > 100) {
                $recommendations[] = [
                    'type' => 'high_failure_rate',
                    'event' => null,
                    'provider' => $provider,
                    'current_cost' => $data['cost'],
                    'potential_savings' => $data['cost'] * $failureRate,
                    'recommendation' => "Provider '{$provider}' has {$failureRate}% failure rate. Investigate connectivity issues.",
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Get historical cost data for trend analysis.
     *
     * @param  int  $days  Number of days to look back
     * @return list<array{date: string, total_events: int, total_cost: float, failed_events: int}>
     */
    public function getHistoricalData(int $days = 7): array
    {
        $history = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $ledgerKey = self::CACHE_PREFIX . 'day_' . $date;

            /** @var array{total_events: int, total_cost: float, failed_events: int}|null $ledger */
            $ledger = $this->cache->get($ledgerKey);

            $history[] = [
                'date' => $date,
                'total_events' => $ledger['total_events'] ?? 0,
                'total_cost' => round($ledger['total_cost'] ?? 0.0, 6),
                'failed_events' => $ledger['failed_events'] ?? 0,
            ];
        }

        return $history;
    }
}
