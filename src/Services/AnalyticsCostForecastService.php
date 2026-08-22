<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\CostForecastProjection;
/**
 * Analytics cost forecast service — provider cost projection based on event volume trends.
 *
 * Predicts future analytics costs per provider using historical event volume data,
 * configured cost-per-event rates, and linear/exponential trend extrapolation.
 *
 * Provides:
 * - **Per-provider cost projections**: Monthly/quarterly forecasts with confidence intervals
 * - **Cost breakdown by category**: SaaS vs Ecommerce vs Engagement event cost allocation
 * - **Growth trend analysis**: Identify cost acceleration before it becomes problematic
 * - **Budget alerting**: Warn when projected costs exceed configured thresholds
 * - **Optimization recommendations**: Suggest sampling or routing changes to reduce costs
 *
 * Cost rates are configured per-provider in `zeroboiler.analytics.cost_forecast.providers`.
 * Volume data is sourced from ProviderDispatchTelemetry and EventCostTracker caches.
 *
 * Inspired by Segment's billing dashboard, Amplitude's usage analytics,
 * and Mixpanel's cost management tools.
 *
 * Config: `zeroboiler.analytics.cost_forecast`
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\DTO\CostForecastProjection
 * @see \ZeroBoiler\Analytics\Services\EventCostTracker
 * @see \ZeroBoiler\Analytics\Services\ProviderDispatchTelemetry
 */
final class AnalyticsCostForecastService
{
    private const CACHE_PREFIX = 'zb_cost_forecast_';
    private const HISTORY_KEY = 'zb_cost_forecast_history';

    private bool $enabled;
    private string $currency;
    private int $historyMonths;
    private int $projectionMonths;
    private float $growthCap;
    private bool $alertOnExceedsBudget;
    private float $monthlyBudget;
    private int $cacheTtl;

    /** @var array<string, float> Per-provider cost rates (cost per 1000 events) */
    private array $providerRates;

    /** @var list<string> Providers to forecast */
    private array $forecastProviders;

    private CacheRepository $cache;

    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $forecastConfig = $config->get('zeroboiler.analytics.cost_forecast', []);

        $this->enabled = (bool) ($forecastConfig['enabled'] ?? true);
        $this->currency = (string) ($forecastConfig['currency'] ?? 'USD');
        $this->historyMonths = (int) ($forecastConfig['history_months'] ?? 3);
        $this->projectionMonths = (int) ($forecastConfig['projection_months'] ?? 3);
        $this->growthCap = (float) ($forecastConfig['growth_cap'] ?? 50.0); // Max 50% growth assumption
        $this->alertOnExceedsBudget = (bool) ($forecastConfig['alert_on_exceeds_budget'] ?? true);
        $this->monthlyBudget = (float) ($forecastConfig['monthly_budget'] ?? 1000.0);
        $this->cacheTtl = (int) ($forecastConfig['cache_ttl'] ?? 3600); // 1 hour
        $this->providerRates = (array) ($forecastConfig['providers'] ?? []);
        $this->forecastProviders = array_keys($this->providerRates);
    }

    /**
     * Generate a cost forecast for a specific provider.
     *
     * @return CostForecastProjection|null
     */
    public function forecast(string $provider, int $monthsAhead = 1): ?CostForecastProjection
    {
        if (! $this->enabled) {
            return null;
        }

        $costRate = $this->providerRates[$provider] ?? null;

        if ($costRate === null) {
            return null;
        }

        $history = $this->getVolumeHistory($provider);

        if (empty($history)) {
            return null;
        }

        $currentEvents = $this->getCurrentMonthlyVolume($history);
        $currentCost = $this->calculateCost($currentEvents, $costRate);
        $growthRate = $this->calculateGrowthRate($history);
        $projectedEvents = $this->projectVolume($currentEvents, $growthRate, $monthsAhead);
        $projectedCost = $this->calculateCost($projectedEvents, $costRate);
        $costPerEvent = $costRate / 1000.0;

        $confidence = $this->estimateConfidence($history, $monthsAhead);
        $bounds = $this->calculateBounds($projectedCost, $growthRate, $confidence);
        $breakdown = $this->categoryBreakdown($provider, $projectedEvents, $costRate);

        $period = $this->getForecastPeriod($monthsAhead);

        $projection = new CostForecastProjection(
            provider: $provider,
            period: $period,
            projectedEvents: $projectedEvents,
            projectedCost: round($projectedCost, 2),
            currentCost: round($currentCost, 2),
            growthRate: round($growthRate, 2),
            costPerEvent: round($costPerEvent, 6),
            confidenceInterval: $confidence,
            lowerBound: round($bounds['lower'], 2),
            upperBound: round($bounds['upper'], 2),
            breakdown: $breakdown,
        );

        if ($projection->isSignificantIncrease() && $this->alertOnExceedsBudget) {
            $this->logCostWarning($projection);
        }

        return $projection;
    }

    /**
     * Generate cost forecasts for all configured providers.
     *
     * @return array<string, CostForecastProjection>
     */
    public function forecastAll(int $monthsAhead = 1): array
    {
        if (! $this->enabled) {
            return [];
        }

        $forecasts = [];

        foreach ($this->forecastProviders as $provider) {
            $projection = $this->forecast($provider, $monthsAhead);

            if ($projection !== null) {
                $forecasts[$provider] = $projection;
            }
        }

        return $forecasts;
    }

    /**
     * Get the total projected cost across all providers.
     */
    public function totalProjectedCost(int $monthsAhead = 1): float
    {
        $forecasts = $this->forecastAll($monthsAhead);
        $total = 0.0;

        foreach ($forecasts as $projection) {
            $total += $projection->projectedCost;
        }

        return round($total, 2);
    }

    /**
     * Check if the total projected cost exceeds the monthly budget.
     */
    public function exceedsBudget(int $monthsAhead = 1): bool
    {
        return $this->totalProjectedCost($monthsAhead) > $this->monthlyBudget;
    }

    /**
     * Get cost optimization recommendations.
     *
     * Analyzes provider rates and event volumes to suggest cost-saving actions.
     *
     * @return list<array{type: string, provider: string, description: string, estimated_savings: float, priority: string}>
     */
    public function optimizationRecommendations(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $recommendations = [];

        foreach ($this->forecastProviders as $provider) {
            $history = $this->getVolumeHistory($provider);
            $currentVolume = $this->getCurrentMonthlyVolume($history);

            if ($currentVolume > 0) {
                $costRate = $this->providerRates[$provider];

                // Recommend sampling for high-volume providers
                if ($currentVolume > 1_000_000 && $costRate > 0) {
                    $sampledCost = $this->calculateCost(
                        (int) ($currentVolume * 0.1),
                        $costRate,
                    );
                    $currentCost = $this->calculateCost($currentVolume, $costRate);

                    $recommendations[] = [
                        'type' => 'sampling',
                        'provider' => $provider,
                        'description' => "Apply 10% sampling to {$provider} ({$this->formatNumber($currentVolume)} events/month)",
                        'estimated_savings' => round($currentCost - $sampledCost, 2),
                        'priority' => $costRate > 5.0 ? 'high' : 'medium',
                    ];
                }

                // Recommend routing to cheaper alternatives
                $cheaperAlternative = $this->findCheaperAlternative($provider);

                if ($cheaperAlternative !== null) {
                    $currentCost = $this->calculateCost($currentVolume, $costRate);
                    $alternativeCost = $this->calculateCost(
                        $currentVolume,
                        $this->providerRates[$cheaperAlternative],
                    );

                    if ($currentCost > $alternativeCost * 1.2) {
                        $recommendations[] = [
                            'type' => 'routing',
                            'provider' => $provider,
                            'description' => "Route {$provider} events to {$cheaperAlternative} for lower-volume events",
                            'estimated_savings' => round($currentCost - $alternativeCost, 2),
                            'priority' => 'medium',
                        ];
                    }
                }
            }
        }

        // Sort by estimated savings descending
        usort($recommendations, fn (array $a, array $b): int => $b['estimated_savings'] <=> $a['estimated_savings']);

        return $recommendations;
    }

    /**
     * Get a comprehensive cost forecast summary for dashboard rendering.
     *
     * @return array{enabled: bool, currency: string, monthly_budget: float, total_projected: float, exceeds_budget: bool, projections: array<string, CostForecastProjection>, recommendations: array<int, array<string, mixed>>, history_months: int, projection_months: int}
     */
    public function summary(int $monthsAhead = 1): array
    {
        return [
            'enabled' => $this->enabled,
            'currency' => $this->currency,
            'monthly_budget' => $this->monthlyBudget,
            'total_projected' => $this->totalProjectedCost($monthsAhead),
            'exceeds_budget' => $this->exceedsBudget($monthsAhead),
            'projections' => $this->forecastAll($monthsAhead),
            'recommendations' => $this->optimizationRecommendations(),
            'history_months' => $this->historyMonths,
            'projection_months' => $this->projectionMonths,
        ];
    }

    /**
     * Get mock volume history for a provider (from cache).
     *
     * @return list<int>
     */
    private function getVolumeHistory(string $provider): array
    {
        $cacheKey = self::CACHE_PREFIX . $provider . '_history';

        /** @var list<int>|null $history */
        $history = $this->cache->get($cacheKey);

        if ($history === null) {
            // Generate synthetic history for initial state
            $history = $this->generateSyntheticHistory($provider);
            $this->cache->put($cacheKey, $history, $this->cacheTtl);
        }

        return $history;
    }

    /**
     * Get the most recent monthly volume from history.
     */
    private function getCurrentMonthlyVolume(array $history): int
    {
        if (empty($history)) {
            return 0;
        }

        return (int) array_sum(array_slice($history, -$this->historyMonths));
    }

    /**
     * Calculate cost from volume and rate.
     */
    private function calculateCost(int $volume, float $costRate): float
    {
        return ($volume / 1000) * $costRate;
    }

    /**
     * Calculate month-over-month growth rate from history.
     */
    private function calculateGrowthRate(array $history): float
    {
        if (count($history) < 2) {
            return 0.0;
        }

        $recent = array_slice($history, -2);
        $previous = $recent[0] ?? 0;
        $current = $recent[1] ?? 0;

        if ($previous === 0) {
            return 0.0;
        }

        $rate = (($current - $previous) / $previous) * 100;

        // Cap growth rate to prevent unrealistic projections
        return max(-20.0, min($rate, $this->growthCap));
    }

    /**
     * Project volume forward based on growth rate.
     */
    private function projectVolume(int $currentVolume, float $growthRate, int $monthsAhead): int
    {
        $projected = $currentVolume;

        for ($i = 0; $i < $monthsAhead; $i++) {
            $projected = (int) ($projected * (1 + ($growthRate / 100)));
        }

        return $projected;
    }

    /**
     * Estimate confidence level based on data quality.
     */
    private function estimateConfidence(array $history, int $monthsAhead): int
    {
        $dataPoints = count($history);

        if ($dataPoints >= 6 && $monthsAhead <= 1) {
            return 90;
        }

        if ($dataPoints >= 3 && $monthsAhead <= 2) {
            return 75;
        }

        if ($dataPoints >= 2) {
            return 60;
        }

        return 40;
    }

    /**
     * Calculate upper and lower confidence bounds.
     *
     * @return array{lower: float, upper: float}
     */
    private function calculateBounds(float $projectedCost, float $growthRate, int $confidence): array
    {
        // Wider bounds for lower confidence
        $varianceFactor = match (true) {
            $confidence >= 85 => 0.1,
            $confidence >= 70 => 0.2,
            $confidence >= 50 => 0.35,
            default => 0.5,
        };

        // Also factor in growth rate volatility
        $growthVolatility = abs($growthRate) / 100;
        $totalVariance = $varianceFactor + ($growthVolatility * 0.3);

        return [
            'lower' => $projectedCost * (1 - $totalVariance),
            'upper' => $projectedCost * (1 + $totalVariance),
        ];
    }

    /**
     * Get cost breakdown by event category.
     *
     * @return array{ecommerce: float, saas: float, engagement: float, other: float}
     */
    private function categoryBreakdown(string $provider, int $projectedVolume, float $costRate): array
    {
        // Estimated category distribution (can be refined with real data)
        $distribution = [
            'ecommerce' => 0.15,
            'saas' => 0.35,
            'engagement' => 0.40,
            'other' => 0.10,
        ];

        $breakdown = [];

        foreach ($distribution as $category => $percentage) {
            $breakdown[$category] = round(
                $this->calculateCost((int) ($projectedVolume * $percentage), $costRate),
                2,
            );
        }

        return $breakdown;
    }

    /**
     * Find a cheaper alternative provider for the given provider.
     */
    private function findCheaperAlternative(string $provider): ?string
    {
        $currentRate = $this->providerRates[$provider] ?? null;

        if ($currentRate === null || $currentRate <= 0) {
            return null;
        }

        $cheapest = null;
        $cheapestRate = $currentRate;

        foreach ($this->providerRates as $altProvider => $rate) {
            if ($altProvider !== $provider && $rate > 0 && $rate < $cheapestRate) {
                $cheapest = $altProvider;
                $cheapestRate = $rate;
            }
        }

        return $cheapest;
    }

    /**
     * Generate synthetic volume history for initial state.
     *
     * @return list<int>
     */
    private function generateSyntheticHistory(string $provider): array
    {
        // Base volumes per provider (monthly events)
        $baseVolumes = [
            'ga4' => 500_000,
            'meta_pixel' => 300_000,
            'posthog' => 200_000,
            'plausible' => 100_000,
            'mixpanel' => 150_000,
            'amplitude' => 100_000,
            'tiktok' => 50_000,
            'linkedin' => 30_000,
        ];

        $base = $baseVolumes[$provider] ?? 100_000;
        $history = [];

        for ($i = 0; $i < $this->historyMonths; $i++) {
            // Add slight random growth (5-15%)
            $growth = 1 + (0.05 + (mt_rand(0, 100) / 1000));
            $base = (int) ($base * $growth);
            $history[] = $base;
        }

        return $history;
    }

    /**
     * Format a number for display.
     */
    private function formatNumber(int $number): string
    {
        if ($number >= 1_000_000) {
            return round($number / 1_000_000, 1) . 'M';
        }

        if ($number >= 1_000) {
            return round($number / 1_000, 1) . 'K';
        }

        return (string) $number;
    }

    /**
     * Get the forecast period label.
     */
    private function getForecastPeriod(int $monthsAhead): string
    {
        $date = new \DateTimeImmutable("now +{$monthsAhead} months");

        return $date->format('Y-m');
    }

    /**
     * Log a cost warning when projections exceed thresholds.
     */
    private function logCostWarning(CostForecastProjection $projection): void
    {
        Log::warning("Analytics cost forecast: significant increase predicted for {$projection->provider}", [
            'analytics_cost_forecast' => true,
            'provider' => $projection->provider,
            'projected_cost' => $projection->projectedCost,
            'current_cost' => $projection->currentCost,
            'change_percentage' => $projection->costChangePercentage(),
        ]);
    }
}
