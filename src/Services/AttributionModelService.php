<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Multi-touch attribution modeling service for SaaS analytics.
 *
 * Computes weighted attribution credit across multiple touchpoints in a
 * conversion journey using industry-standard models:
 *
 * - **First-touch**: 100% credit to the first interaction
 * - **Last-touch**: 100% credit to the last interaction (conversion)
 * - **Linear**: Equal credit distributed across all touchpoints
 * - **Time-decay**: Exponentially more credit to recent interactions
 * - **Position-based (U-shaped)**: 40% first + 40% last + 20% distributed across middle
 *
 * The service works with touchpoint arrays (from AttributionService::getTouchHistory)
 * and can compute per-channel, per-campaign, and per-source attribution.
 *
 * Designed for SaaS marketing analytics, CAC calculations, and
 * campaign ROI optimization.
 *
 * Configuration: `zeroboiler.analytics.attribution_model`
 *
 * @see \ZeroBoiler\Analytics\Services\AttributionService
 *
 * @since 7.9.0
 */
final class AttributionModelService
{
    /** @var array<string, int> Position-based model weight constants */
    private const POSITION_WEIGHTS = [
        'first' => 40,
        'last' => 40,
        'middle' => 20,
    ];

    private const DEFAULT_DECAY_FACTOR = 0.5;

    private const DEFAULT_MODEL = 'position_based';

    private CacheRepository $cache;

    private int $cacheTtl;

    private string $defaultModel;

    private float $timeDecayFactor;

    /** @var array<string, bool> Enabled models */
    private array $enabledModels;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $modelConfig = $config->get('zeroboiler.analytics.attribution_model', []);
        /** @var array{default_model?: string, time_decay_factor?: float, enabled_models?: array<string, bool>, cache_ttl?: int} $modelConfig */

        $this->defaultModel = (string) ($modelConfig['default_model'] ?? self::DEFAULT_MODEL);
        $this->timeDecayFactor = (float) ($modelConfig['time_decay_factor'] ?? self::DEFAULT_DECAY_FACTOR);
        $this->cacheTtl = (int) ($modelConfig['cache_ttl'] ?? 3600);
        $this->enabledModels = $modelConfig['enabled_models'] ?? [
            'first_touch' => true,
            'last_touch' => true,
            'linear' => true,
            'time_decay' => true,
            'position_based' => true,
        ];
    }

    /**
     * Get the list of available attribution models.
     *
     * @return array<string, array{name: string, description: string, enabled: bool}>
     */
    public function availableModels(): array
    {
        return [
            'first_touch' => [
                'name' => 'First-Touch',
                'description' => '100% credit to the first interaction that brought the user',
                'enabled' => $this->enabledModels['first_touch'] ?? true,
            ],
            'last_touch' => [
                'name' => 'Last-Touch',
                'description' => '100% credit to the last interaction before conversion',
                'enabled' => $this->enabledModels['last_touch'] ?? true,
            ],
            'linear' => [
                'name' => 'Linear',
                'description' => 'Equal credit distributed across all touchpoints',
                'enabled' => $this->enabledModels['linear'] ?? true,
            ],
            'time_decay' => [
                'name' => 'Time-Decay',
                'description' => 'Exponentially more credit to recent interactions',
                'enabled' => $this->enabledModels['time_decay'] ?? true,
            ],
            'position_based' => [
                'name' => 'Position-Based (U-Shape)',
                'description' => '40% first + 40% last + 20% distributed across middle touchpoints',
                'enabled' => $this->enabledModels['position_based'] ?? true,
            ],
        ];
    }

    /**
     * Compute attribution using a specific model.
     *
     * @param  string  $model  Model name (first_touch, last_touch, linear, time_decay, position_based)
     * @param  list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null, [key: string]: mixed}>  $touchpoints  Journey touchpoints
     * @param  float  $revenue  Revenue to attribute (default: 1.0 for fractional)
     * @return array{model: string, revenue: float, touchpoints: list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null, credit: float, credit_pct: float}>}
     */
    public function attribute(string $model, array $touchpoints, float $revenue = 1.0): array
    {
        $safeModel = $this->validateModel($model);

        if (empty($touchpoints)) {
            return $this->emptyResult($safeModel, $revenue);
        }

        if (count($touchpoints) === 1) {
            $tp = $touchpoints[0];

            return [
                'model' => $safeModel,
                'revenue' => $revenue,
                'touchpoints' => [
                    [
                        'source' => $tp['source'] ?? $tp['utm_source'] ?? null,
                        'medium' => $tp['medium'] ?? $tp['utm_medium'] ?? null,
                        'campaign' => $tp['campaign'] ?? $tp['utm_campaign'] ?? null,
                        'timestamp' => $tp['timestamp'] ?? $tp['recorded_at'] ?? null,
                        'credit' => $revenue,
                        'credit_pct' => 100.0,
                    ],
                ],
            ];
        }

        $credits = match ($safeModel) {
            'first_touch' => $this->firstTouch($touchpoints, $revenue),
            'last_touch' => $this->lastTouch($touchpoints, $revenue),
            'linear' => $this->linear($touchpoints, $revenue),
            'time_decay' => $this->timeDecay($touchpoints, $revenue),
            'position_based' => $this->positionBased($touchpoints, $revenue),
        };

        $annotated = [];

        foreach ($touchpoints as $index => $tp) {
            $credit = $credits[$index] ?? 0.0;

            $annotated[] = [
                'source' => $tp['source'] ?? $tp['utm_source'] ?? null,
                'medium' => $tp['medium'] ?? $tp['utm_medium'] ?? null,
                'campaign' => $tp['campaign'] ?? $tp['utm_campaign'] ?? null,
                'timestamp' => $tp['timestamp'] ?? $tp['recorded_at'] ?? null,
                'credit' => round($credit, 6),
                'credit_pct' => $revenue > 0 ? round(($credit / $revenue) * 100, 2) : 0.0,
            ];
        }

        return [
            'model' => $safeModel,
            'revenue' => $revenue,
            'touchpoints' => $annotated,
        ];
    }

    /**
     * Run attribution across all enabled models and return comparison.
     *
     * Useful for dashboards comparing how different models credit
     * the same set of touchpoints.
     *
     * @param  list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null}>  $touchpoints
     * @param  float  $revenue
     * @return array{models: array<string, array{model: string, revenue: float, touchpoints: list<array{source: string|null, medium: string|null, campaign: string|null, credit: float, credit_pct: float}>}>, recommended: string, total_touchpoints: int}
     */
    public function compareModels(array $touchpoints, float $revenue = 1.0): array
    {
        $results = [];

        foreach ($this->availableModels() as $key => $info) {
            if (! $info['enabled']) {
                continue;
            }

            $results[$key] = $this->attribute($key, $touchpoints, $revenue);
        }

        return [
            'models' => $results,
            'recommended' => $this->defaultModel,
            'total_touchpoints' => count($touchpoints),
        ];
    }

    /**
     * Aggregate attribution by channel (source) across multiple journeys.
     *
     * @param  list<array{touchpoints: list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null}>, revenue: float}>  $journeys
     * @param  string  $model
     * @return array{channels: array<string, array{revenue: float, count: int, avg_revenue: float, pct: float}>, total_revenue: float, model: string}
     */
    public function aggregateByChannel(array $journeys, string $model): array
    {
        $safeModel = $this->validateModel($model);
        $channelTotals = [];
        $totalRevenue = 0.0;

        foreach ($journeys as $journey) {
            $touchpoints = $journey['touchpoints'] ?? [];
            $revenue = (float) ($journey['revenue'] ?? 1.0);
            $totalRevenue += $revenue;

            $result = $this->attribute($safeModel, $touchpoints, $revenue);

            foreach ($result['touchpoints'] as $tp) {
                $source = $tp['source'] ?? 'direct';

                if (! isset($channelTotals[$source])) {
                    $channelTotals[$source] = ['revenue' => 0.0, 'count' => 0];
                }

                $channelTotals[$source]['revenue'] += $tp['credit'];
                $channelTotals[$source]['count']++;
            }
        }

        $channels = [];

        foreach ($channelTotals as $source => $data) {
            $channels[$source] = [
                'revenue' => round($data['revenue'], 2),
                'count' => $data['count'],
                'avg_revenue' => $data['count'] > 0 ? round($data['revenue'] / $data['count'], 2) : 0.0,
                'pct' => $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 1) : 0.0,
            ];
        }

        // Sort by revenue descending
        uasort($channels, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'channels' => $channels,
            'total_revenue' => round($totalRevenue, 2),
            'model' => $safeModel,
        ];
    }

    /**
     * Aggregate attribution by campaign across multiple journeys.
     *
     * @param  list<array{touchpoints: list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null}>, revenue: float}>  $journeys
     * @param  string  $model
     * @return array{campaigns: array<string, array{revenue: float, count: int, avg_revenue: float, pct: float}>, total_revenue: float, model: string, no_campaign_pct: float}
     */
    public function aggregateByCampaign(array $journeys, string $model): array
    {
        $safeModel = $this->validateModel($model);
        $campaignTotals = [];
        $totalRevenue = 0.0;
        $noCampaignRevenue = 0.0;

        foreach ($journeys as $journey) {
            $touchpoints = $journey['touchpoints'] ?? [];
            $revenue = (float) ($journey['revenue'] ?? 1.0);
            $totalRevenue += $revenue;

            $result = $this->attribute($safeModel, $touchpoints, $revenue);

            foreach ($result['touchpoints'] as $tp) {
                $campaign = $tp['campaign'] ?? null;

                if ($campaign === null || $campaign === '') {
                    $noCampaignRevenue += $tp['credit'];
                    continue;
                }

                if (! isset($campaignTotals[$campaign])) {
                    $campaignTotals[$campaign] = ['revenue' => 0.0, 'count' => 0];
                }

                $campaignTotals[$campaign]['revenue'] += $tp['credit'];
                $campaignTotals[$campaign]['count']++;
            }
        }

        $campaigns = [];

        foreach ($campaignTotals as $name => $data) {
            $campaigns[$name] = [
                'revenue' => round($data['revenue'], 2),
                'count' => $data['count'],
                'avg_revenue' => $data['count'] > 0 ? round($data['revenue'] / $data['count'], 2) : 0.0,
                'pct' => $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 1) : 0.0,
            ];
        }

        uasort($campaigns, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'campaigns' => $campaigns,
            'total_revenue' => round($totalRevenue, 2),
            'model' => $safeModel,
            'no_campaign_pct' => $totalRevenue > 0
                ? round(($noCampaignRevenue / $totalRevenue) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Compute channel efficiency metrics: revenue, attributed conversions, and cost efficiency.
     *
     * Useful for CAC and ROAS calculations.
     *
     * @param  list<array{touchpoints: list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null}>, revenue: float}>  $journeys
     * @param  string  $model
     * @param  array<string, float>  $channelCosts  Optional cost per channel (source)
     * @return array{channels: array<string, array{attributed_revenue: float, conversions: int, cost: float, roas: float, cpa: float, pct: float}>, total_revenue: float, total_cost: float, model: string}
     */
    public function channelEfficiency(array $journeys, string $model, array $channelCosts = []): array
    {
        $channelAgg = $this->aggregateByChannel($journeys, $model);
        $totalCost = 0.0;
        $channels = [];

        foreach ($channelAgg['channels'] as $source => $data) {
            $cost = $channelCosts[$source] ?? 0.0;
            $totalCost += $cost;

            $channels[$source] = [
                'attributed_revenue' => $data['revenue'],
                'conversions' => $data['count'],
                'cost' => $cost,
                'roas' => $cost > 0 ? round($data['revenue'] / $cost, 2) : 0.0,
                'cpa' => $data['count'] > 0 ? round($cost / $data['count'], 2) : 0.0,
                'pct' => $data['pct'],
            ];
        }

        return [
            'channels' => $channels,
            'total_revenue' => $channelAgg['total_revenue'],
            'total_cost' => round($totalCost, 2),
            'model' => $channelAgg['model'],
        ];
    }

    /**
     * Get the default attribution model name.
     */
    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    // ── Attribution Model Implementations ─────────────────────────────

    /**
     * First-touch attribution: 100% credit to the first touchpoint.
     *
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  float  $revenue
     * @return array<int, float>
     */
    private function firstTouch(array $touchpoints, float $revenue): array
    {
        $count = count($touchpoints);
        $credits = array_fill(0, $count, 0.0);
        $credits[0] = $revenue;

        return $credits;
    }

    /**
     * Last-touch attribution: 100% credit to the last touchpoint.
     *
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  float  $revenue
     * @return array<int, float>
     */
    private function lastTouch(array $touchpoints, float $revenue): array
    {
        $count = count($touchpoints);
        $credits = array_fill(0, $count, 0.0);
        $credits[$count - 1] = $revenue;

        return $credits;
    }

    /**
     * Linear attribution: equal credit distributed across all touchpoints.
     *
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  float  $revenue
     * @return array<int, float>
     */
    private function linear(array $touchpoints, float $revenue): array
    {
        $count = count($touchpoints);
        $perTouch = $count > 0 ? $revenue / $count : 0.0;

        return array_fill(0, $count, $perTouch);
    }

    /**
     * Time-decay attribution: exponentially more credit to recent touchpoints.
     *
     * Uses a half-life decay model where each touchpoint receives credit
     * proportional to 2^(-age/half_life), normalized to sum to total revenue.
     *
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  float  $revenue
     * @return array<int, float>
     */
    private function timeDecay(array $touchpoints, float $revenue): array
    {
        $count = count($touchpoints);

        if ($count === 0) {
            return [];
        }

        // Compute raw decay weights
        $weights = [];
        $weightSum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $age = $count - 1 - $i; // age: 0 for most recent, count-1 for oldest
            $weight = pow(2, -$age * $this->timeDecayFactor);
            $weights[$i] = $weight;
            $weightSum += $weight;
        }

        // Normalize to revenue
        $credits = [];

        for ($i = 0; $i < $count; $i++) {
            $credits[$i] = $weightSum > 0
                ? ($weights[$i] / $weightSum) * $revenue
                : 0.0;
        }

        return $credits;
    }

    /**
     * Position-based (U-shaped) attribution: 40% first + 40% last + 20% middle.
     *
     * Allocates the majority of credit to the acquisition (first) and
     * conversion (last) touchpoints, with remaining credit split equally
     * across middle interactions.
     *
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  float  $revenue
     * @return array<int, float>
     */
    private function positionBased(array $touchpoints, float $revenue): array
    {
        $count = count($touchpoints);
        $credits = array_fill(0, $count, 0.0);

        if ($count === 0) {
            return $credits;
        }

        if ($count === 1) {
            $credits[0] = $revenue;

            return $credits;
        }

        if ($count === 2) {
            // Only first and last — split according to weights
            $firstWeight = (self::POSITION_WEIGHTS['first'] + self::POSITION_WEIGHTS['middle']) / 100;
            $lastWeight = (self::POSITION_WEIGHTS['last']) / 100;
            $credits[0] = $revenue * $firstWeight;
            $credits[1] = $revenue * $lastWeight;

            return $credits;
        }

        // First touchpoint: 40%
        $credits[0] = $revenue * (self::POSITION_WEIGHTS['first'] / 100);

        // Last touchpoint: 40%
        $credits[$count - 1] = $revenue * (self::POSITION_WEIGHTS['last'] / 100);

        // Middle touchpoints: share 20% equally
        $middleCount = $count - 2;
        $middleWeight = $revenue * (self::POSITION_WEIGHTS['middle'] / 100);
        $perMiddle = $middleCount > 0 ? $middleWeight / $middleCount : 0.0;

        for ($i = 1; $i < $count - 1; $i++) {
            $credits[$i] = $perMiddle;
        }

        return $credits;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Validate and normalize a model name.
     *
     * @return string Validated model name
     */
    private function validateModel(string $model): string
    {
        $valid = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'];

        if (! in_array($model, $valid, true)) {
            return $this->defaultModel;
        }

        if (! ($this->enabledModels[$model] ?? true)) {
            return $this->defaultModel;
        }

        return $model;
    }

    /**
     * Build an empty attribution result.
     *
     * @return array{model: string, revenue: float, touchpoints: list<array{source: null, medium: null, campaign: null, timestamp: null, credit: float, credit_pct: float}>}
     */
    private function emptyResult(string $model, float $revenue): array
    {
        return [
            'model' => $model,
            'revenue' => $revenue,
            'touchpoints' => [],
        ];
    }
}
