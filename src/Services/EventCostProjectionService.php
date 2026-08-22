<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Event Cost Projection Engine — forecasts analytics event costs by provider
 * with budget projection, threshold alerts, and cost optimization recommendations.
 *
 * Provides forward-looking cost analytics for SaaS products running multi-provider
 * analytics pipelines. Combines historical dispatch volume data with configured
 * per-event cost rates to project monthly costs, detect budget overshoot risk,
 * and recommend cost-saving actions.
 *
 * Cost model:
 * - Per-event rates per provider (configurable in cost_rates config section)
 * - Volume projections based on rolling window extrapolation
 * - Budget thresholds with percentage-based alerts (50%, 75%, 90%, 100%)
 * - Cost-per-conversion analysis (event cost ÷ conversion count)
 * - Provider cost comparison for optimization decisions
 *
 * Configuration is read from `zeroboiler.analytics.cost_projection`.
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderDispatchTelemetry
 * @see \ZeroBoiler\Analytics\Services\EventCostTracker
 * @see \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService
 *
 * @since 236.0.0
 */
final class EventCostProjectionService
{
    /** @var string Cache key prefix for projection data */
    private const CACHE_PREFIX = 'zb_cost_projection_';

    /** @var int Default projection cache TTL in seconds */
    private const DEFAULT_CACHE_TTL = 300;

    /** Known provider identifiers */
    private const PROVIDERS = [
        'ga4' => 'Google Analytics 4',
        'gtm' => 'Google Tag Manager',
        'meta_pixel' => 'Meta Pixel',
        'plausible' => 'Plausible',
        'posthog' => 'PostHog',
        'mixpanel' => 'Mixpanel',
        'amplitude' => 'Amplitude',
        'webhook' => 'Webhook',
        'tiktok' => 'TikTok Pixel',
        'linkedin' => 'LinkedIn Insight',
    ];

    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var ConfigRepository */
    private ConfigRepository $config;

    /** @var bool Whether the service is enabled */
    private bool $enabled;

    /** @var int Cache TTL for projections */
    private int $cacheTtl;

    /** @var int Rolling window for volume calculation (days) */
    private int $rollingWindowDays;

    /** @var int Days to project forward */
    private int $projectionDays;

    /** @var array<string, float> Per-event cost rates by provider ($/1000 events) */
    private array $costRates;

    /** @var float|null Monthly budget threshold (null = unlimited) */
    private ?float $monthlyBudget;

    /** @var list<int> Alert threshold percentages */
    private array $alertThresholds;

    /** @var float Growth rate assumption for projections (1.0 = flat, 1.1 = 10% growth) */
    private float $growthAssumption;

    /** @var int Minimum sample days required for projections */
    private int $minSampleDays;

    /**
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->config = $config;

        $projConfig = $config->get('zeroboiler.analytics.cost_projection', []);
        /** @var array{enabled?: bool, cache_ttl?: int, rolling_window_days?: int, projection_days?: int, cost_rates?: array<string, float>, monthly_budget?: float|null, alert_thresholds?: list<int>, growth_assumption?: float, min_sample_days?: int} $projConfig */

        $this->enabled = (bool) ($projConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($projConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->rollingWindowDays = (int) ($projConfig['rolling_window_days'] ?? 7);
        $this->projectionDays = (int) ($projConfig['projection_days'] ?? 30);
        $this->costRates = (array) ($projConfig['cost_rates'] ?? $this->defaultCostRates());
        $this->monthlyBudget = isset($projConfig['monthly_budget']) ? (float) $projConfig['monthly_budget'] : null;
        $this->alertThresholds = (array) ($projConfig['alert_thresholds'] ?? [50, 75, 90, 100]);
        $this->growthAssumption = (float) ($projConfig['growth_assumption'] ?? 1.0);
        $this->minSampleDays = (int) ($projConfig['min_sample_days'] ?? 3);
    }

    /**
     * Project monthly costs for all providers.
     *
     * Uses rolling window dispatch volumes and per-event cost rates
     * to calculate projected monthly cost with growth adjustment.
     *
     * @return array{providers: array<string, array{provider: string, name: string, daily_volume: int, projected_monthly_volume: int, cost_rate_per_1k: float, projected_monthly_cost: float, budget_usage_pct: float|null}>, total_projected_cost: float, total_budget_usage_pct: float|null, alerts: list<array{level: string, provider: string|null, message: string, projected_cost: float, threshold_pct: int}>, recommendations: list<string>, metadata: array{rolling_window_days: int, projection_days: int, growth_assumption: float, sample_days: int}}
     */
    public function projectMonthlyCosts(): array
    {
        if (! $this->enabled) {
            return $this->emptyProjection();
        }

        $cacheKey = self::CACHE_PREFIX . 'monthly_' . date('Ymd');

        /** @var array|false $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $providerProjections = [];
        $totalProjectedCost = 0.0;

        foreach (self::PROVIDERS as $provider => $name) {
            $dailyVolume = $this->getDailyVolume($provider);
            $projectedMonthlyVolume = $this->extrapolateVolume($dailyVolume);
            $costRate = $this->costRates[$provider] ?? $this->costRates['default'] ?? 0.0;
            $projectedCost = $this->calculateCost($projectedMonthlyVolume, $costRate);

            $budgetUsage = $this->monthlyBudget !== null
                ? round(($projectedCost / $this->monthlyBudget) * 100, 2)
                : null;

            $providerProjections[$provider] = [
                'provider' => $provider,
                'name' => $name,
                'daily_volume' => $dailyVolume,
                'projected_monthly_volume' => $projectedMonthlyVolume,
                'cost_rate_per_1k' => $costRate,
                'projected_monthly_cost' => round($projectedCost, 2),
                'budget_usage_pct' => $budgetUsage,
            ];

            $totalProjectedCost += $projectedCost;
        }

        $totalBudgetUsage = $this->monthlyBudget !== null
            ? round(($totalProjectedCost / $this->monthlyBudget) * 100, 2)
            : null;

        $alerts = $this->generateAlerts($providerProjections, $totalProjectedCost);
        $recommendations = $this->generateRecommendations($providerProjections, $totalProjectedCost);

        $projection = [
            'providers' => $providerProjections,
            'total_projected_cost' => round($totalProjectedCost, 2),
            'total_budget_usage_pct' => $totalBudgetUsage,
            'alerts' => $alerts,
            'recommendations' => $recommendations,
            'metadata' => [
                'rolling_window_days' => $this->rollingWindowDays,
                'projection_days' => $this->projectionDays,
                'growth_assumption' => $this->growthAssumption,
                'sample_days' => $this->minSampleDays,
            ],
        ];

        $this->cache->put($cacheKey, $projection, $this->cacheTtl);

        return $projection;
    }

    /**
     * Project cost for a specific provider.
     *
     * @param  string  $provider  Provider key (ga4, meta_pixel, etc.)
     * @return array{provider: string, name: string, daily_volume: int, projected_monthly_volume: int, cost_rate_per_1k: float, projected_monthly_cost: float, budget_usage_pct: float|null, cost_per_day: float, cost_per_event: float}
     */
    public function projectProviderCost(string $provider): array
    {
        $projection = $this->projectMonthlyCosts();

        if (! isset($projection['providers'][$provider])) {
            return [
                'provider' => $provider,
                'name' => 'Unknown',
                'daily_volume' => 0,
                'projected_monthly_volume' => 0,
                'cost_rate_per_1k' => 0.0,
                'projected_monthly_cost' => 0.0,
                'budget_usage_pct' => null,
                'cost_per_day' => 0.0,
                'cost_per_event' => 0.0,
            ];
        }

        $data = $projection['providers'][$provider];

        return array_merge($data, [
            'cost_per_day' => round($data['projected_monthly_cost'] / 30, 2),
            'cost_per_event' => $data['projected_monthly_volume'] > 0
                ? round(($data['cost_rate_per_1k'] / 1000) * $this->growthAssumption, 6)
                : 0.0,
        ]);
    }

    /**
     * Get cost comparison across all providers.
     *
     * Ranks providers by projected monthly cost (highest to lowest).
     *
     * @return list<array{provider: string, name: string, projected_monthly_cost: float, cost_share_pct: float, rank: int}>
     */
    public function costComparison(): array
    {
        $projection = $this->projectMonthlyCosts();
        $total = $projection['total_projected_cost'];

        if ($total <= 0.0) {
            return [];
        }

        $comparison = [];
        foreach ($projection['providers'] as $provider => $data) {
            $comparison[] = [
                'provider' => $provider,
                'name' => $data['name'],
                'projected_monthly_cost' => $data['projected_monthly_cost'],
                'cost_share_pct' => round(($data['projected_monthly_cost'] / $total) * 100, 2),
            ];
        }

        usort($comparison, fn (array $a, array $b): int => $b['projected_monthly_cost'] <=> $a['projected_monthly_cost']);

        foreach ($comparison as $i => &$item) {
            $item['rank'] = $i + 1;
        }

        return $comparison;
    }

    /**
     * Calculate cost efficiency score (events per dollar).
     *
     * Higher is better — more events tracked for less cost.
     *
     * @return array{overall: float, by_provider: array<string, float>, recommendation: string}
     */
    public function costEfficiency(): array
    {
        $projection = $this->projectMonthlyCosts();
        $totalCost = $projection['total_projected_cost'];
        $totalVolume = 0;

        foreach ($projection['providers'] as $data) {
            $totalVolume += $data['projected_monthly_volume'];
        }

        $overall = $totalCost > 0.0 ? round($totalVolume / $totalCost, 2) : 0.0;

        $byProvider = [];
        foreach ($projection['providers'] as $provider => $data) {
            $byProvider[$provider] = $data['projected_monthly_cost'] > 0.0
                ? round($data['projected_monthly_volume'] / $data['projected_monthly_cost'], 2)
                : 0.0;
        }

        $leastEfficient = null;
        $lowestScore = PHP_FLOAT_MAX;

        foreach ($byProvider as $provider => $score) {
            if ($score > 0.0 && $score < $lowestScore) {
                $lowestScore = $score;
                $leastEfficient = $provider;
            }
        }

        $recommendation = $leastEfficient !== null
            ? sprintf('Consider optimizing or replacing %s (efficiency: %.0f events/$)', $leastEfficient, $lowestScore)
            : 'Cost efficiency looks healthy across all providers.';

        return [
            'overall' => $overall,
            'by_provider' => $byProvider,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Check if budget thresholds have been triggered.
     *
     * @return list<array{threshold_pct: int, projected_cost: float, budget: float, status: 'ok'|'warning'|'critical', provider: string|null}>
     */
    public function budgetStatus(): array
    {
        $projection = $this->projectMonthlyCosts();
        $statuses = [];

        if ($this->monthlyBudget === null) {
            return [
                [
                    'threshold_pct' => 0,
                    'projected_cost' => $projection['total_projected_cost'],
                    'budget' => 0.0,
                    'status' => 'ok',
                    'provider' => null,
                    'message' => 'No monthly budget configured',
                ],
            ];
        }

        $totalUsage = $projection['total_budget_usage_pct'] ?? 0.0;

        foreach ($this->alertThresholds as $threshold) {
            if ($totalUsage >= $threshold) {
                $status = $threshold >= 90 ? 'critical' : 'warning';
                $statuses[] = [
                    'threshold_pct' => $threshold,
                    'projected_cost' => $projection['total_projected_cost'],
                    'budget' => $this->monthlyBudget,
                    'status' => $status,
                    'provider' => null,
                    'message' => sprintf(
                        'Projected cost (%.2f) exceeds %d%% of budget (%.2f)',
                        $projection['total_projected_cost'],
                        $threshold,
                        $this->monthlyBudget,
                    ),
                ];
            }
        }

        if (empty($statuses)) {
            $statuses[] = [
                'threshold_pct' => 0,
                'projected_cost' => $projection['total_projected_cost'],
                'budget' => $this->monthlyBudget,
                'status' => 'ok',
                'provider' => null,
                'message' => sprintf(
                    'Projected cost (%.2f) is within budget (%.2f) — %.1f%% used',
                    $projection['total_projected_cost'],
                    $this->monthlyBudget,
                    $totalUsage,
                ),
            ];
        }

        return $statuses;
    }

    /**
     * Update cost rates for providers.
     *
     * @param  array<string, float>  $rates  Provider → cost rate per 1000 events
     */
    public function setCostRates(array $rates): void
    {
        foreach ($rates as $provider => $rate) {
            $this->costRates[$provider] = (float) $rate;
        }

        // Invalidate projection cache
        $this->cache->forget(self::CACHE_PREFIX . 'monthly_' . date('Ymd'));
    }

    /**
     * Get the current cost rates configuration.
     *
     * @return array<string, float>
     */
    public function getCostRates(): array
    {
        return $this->costRates;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured monthly budget.
     *
     * @return float|null
     */
    public function getMonthlyBudget(): ?float
    {
        return $this->monthlyBudget;
    }

    /**
     * Get average daily event volume for a provider.
     *
     * Falls back to cache-based telemetry data from ProviderDispatchTelemetry.
     *
     * @param  string  $provider  Provider key
     * @return int  Average daily event count
     */
    private function getDailyVolume(string $provider): int
    {
        $telemetryKey = 'zb_telemetry_dispatch_' . $provider . '_total';
        /** @var int|string|null $cached */
        $cached = $this->cache->get($telemetryKey);

        if (is_numeric($cached)) {
            return (int) round((float) $cached / max($this->rollingWindowDays, 1));
        }

        // Zero fallback for providers with no data
        return 0;
    }

    /**
     * Extrapolate daily volume to monthly projection with growth.
     *
     * @param  int  $dailyVolume  Average daily events
     * @return int  Projected monthly volume
     */
    private function extrapolateVolume(int $dailyVolume): int
    {
        $baseMonthly = $dailyVolume * $this->projectionDays;
        $adjusted = (int) round($baseMonthly * $this->growthAssumption);

        return max($adjusted, 0);
    }

    /**
     * Calculate cost from volume and rate.
     *
     * Cost rate is per 1000 events, so: cost = (volume / 1000) * rate
     *
     * @param  int  $volume  Number of events
     * @param  float  $costRate  Cost per 1000 events
     * @return float  Total cost
     */
    private function calculateCost(int $volume, float $costRate): float
    {
        return ($volume / 1000) * $costRate;
    }

    /**
     * Generate budget threshold alerts from projections.
     *
     * @param  array<string, array{projected_monthly_cost: float, budget_usage_pct: float|null}>  $providerProjections
     * @param  float  $totalCost  Total projected monthly cost
     * @return list<array{level: string, provider: string|null, message: string, projected_cost: float, threshold_pct: int}>
     */
    private function generateAlerts(array $providerProjections, float $totalCost): array
    {
        $alerts = [];

        if ($this->monthlyBudget === null) {
            return $alerts;
        }

        $totalUsage = ($totalCost / $this->monthlyBudget) * 100;

        foreach ($this->alertThresholds as $threshold) {
            if ($totalUsage >= $threshold) {
                $level = $threshold >= 90 ? 'critical' : ($threshold >= 75 ? 'warning' : 'info');
                $alerts[] = [
                    'level' => $level,
                    'provider' => null,
                    'message' => sprintf(
                        'Total projected cost ($%.2f) has reached %d%% of monthly budget ($%.2f)',
                        $totalCost,
                        $threshold,
                        $this->monthlyBudget,
                    ),
                    'projected_cost' => round($totalCost, 2),
                    'threshold_pct' => $threshold,
                ];
            }
        }

        // Per-provider alerts
        foreach ($providerProjections as $provider => $data) {
            if ($data['budget_usage_pct'] !== null && $data['budget_usage_pct'] >= 100) {
                $alerts[] = [
                    'level' => 'critical',
                    'provider' => $provider,
                    'message' => sprintf(
                        '%s projected cost ($%.2f) exceeds monthly budget',
                        $data['name'],
                        $data['projected_monthly_cost'],
                    ),
                    'projected_cost' => $data['projected_monthly_cost'],
                    'threshold_pct' => 100,
                ];
            }
        }

        return $alerts;
    }

    /**
     * Generate cost optimization recommendations.
     *
     * @param  array<string, array{provider: string, name: string, projected_monthly_cost: float, projected_monthly_volume: int}>  $providerProjections
     * @param  float  $totalCost  Total projected monthly cost
     * @return list<string>
     */
    private function generateRecommendations(array $providerProjections, float $totalCost): array
    {
        $recommendations = [];

        // Identify high-cost providers (> 30% of total)
        foreach ($providerProjections as $data) {
            if ($totalCost > 0.0) {
                $share = ($data['projected_monthly_cost'] / $totalCost) * 100;
                if ($share > 30.0) {
                    $recommendations[] = sprintf(
                        '%s accounts for %.1f%% of total analytics cost ($%.2f/mo). Consider volume optimization or provider evaluation.',
                        $data['name'],
                        $share,
                        $data['projected_monthly_cost'],
                    );
                }
            }
        }

        // Zero-cost alternative suggestion
        $paidProviders = array_filter(
            $providerProjections,
            fn (array $d): bool => $d['projected_monthly_cost'] > 0.0,
        );

        if (count($paidProviders) > 2) {
            $savings = 0.0;
            foreach ($paidProviders as $data) {
                $savings += $data['projected_monthly_cost'];
            }
            $recommendations[] = sprintf(
                '%d paid providers active — consider consolidating to reduce complexity. Potential savings: $%.2f/mo by removing lowest-value providers.',
                count($paidProviders),
                $savings * 0.1, // 10% savings assumption
            );
        }

        // Growth warning
        if ($this->growthAssumption > 1.1 && $this->monthlyBudget !== null) {
            $projectedWithoutGrowth = $totalCost / $this->growthAssumption;
            $growthExtra = $totalCost - $projectedWithoutGrowth;
            $recommendations[] = sprintf(
                'Growth assumption (%.0f%%) adds $%.2f/mo to projected costs. Consider volume sampling if budget is constrained.',
                ($this->growthAssumption - 1.0) * 100,
                $growthExtra,
            );
        }

        return $recommendations;
    }

    /**
     * Get default cost rates for providers ($/1000 events).
     *
     * These are baseline estimates — users should configure actual rates.
     *
     * @return array<string, float>
     */
    private function defaultCostRates(): array
    {
        return [
            'ga4' => 0.0,
            'gtm' => 0.0,
            'meta_pixel' => 0.0,
            'plausible' => 0.25,
            'posthog' => 0.10,
            'mixpanel' => 0.20,
            'amplitude' => 0.15,
            'webhook' => 0.001,
            'tiktok' => 0.0,
            'linkedin' => 0.0,
            'default' => 0.0,
        ];
    }

    /**
     * Return an empty projection structure.
     *
     * @return array{providers: array<string, mixed>, total_projected_cost: float, total_budget_usage_pct: float|null, alerts: list<mixed>, recommendations: list<mixed>, metadata: array<string, mixed>}
     */
    private function emptyProjection(): array
    {
        return [
            'providers' => [],
            'total_projected_cost' => 0.0,
            'total_budget_usage_pct' => null,
            'alerts' => [],
            'recommendations' => [],
            'metadata' => [
                'rolling_window_days' => $this->rollingWindowDays,
                'projection_days' => $this->projectionDays,
                'growth_assumption' => $this->growthAssumption,
                'sample_days' => $this->minSampleDays,
            ],
        ];
    }
}
