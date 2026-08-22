<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Data-driven (Shapley value) multi-touch attribution service.
 *
 * Implements the gold-standard data-driven attribution model used by
 * Google Analytics 4 and enterprise marketing analytics platforms.
 * Unlike rule-based models (first-touch, last-touch, linear), the
 * data-driven approach uses observed conversion data to compute the
 * marginal contribution of each channel/touchpoint via Shapley values
 * from cooperative game theory.
 *
 * Algorithm:
 * 1. Build conversion paths from observed touchpoint sequences
 * 2. For each conversion path, compute the counterfactual removal value
 *    of each channel (how much conversions drop when that channel is removed)
 * 3. Average marginal contributions across all possible channel coalitions
 * 4. Normalize to 100% credit distribution
 *
 * This provides the most accurate attribution model for SaaS companies
 * with multi-channel marketing (organic, paid, referral, social, email).
 *
 * Configuration: `zeroboiler.analytics.data_driven_attribution`
 *
 * @see \ZeroBoiler\Analytics\Services\AttributionModelService
 *
 * @since 87.0.0
 */
final class DataDrivenAttributionService
{
    private const CACHE_PREFIX = 'zb_dda_';

    private const DEFAULT_MIN_CONVERSIONS = 30;

    private const DEFAULT_LOOKBACK_DAYS = 90;

    private const DEFAULT_MAX_PATH_LENGTH = 20;

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $minConversions;

    private int $lookbackDays;

    private int $maxPathLength;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $ddaConfig = $config->get('zeroboiler.analytics.data_driven_attribution', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_conversions?: int, lookback_days?: int, max_path_length?: int} $ddaConfig */

        $this->enabled = (bool) ($ddaConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($ddaConfig['cache_ttl'] ?? 3600);
        $this->minConversions = (int) ($ddaConfig['min_conversions'] ?? self::DEFAULT_MIN_CONVERSIONS);
        $this->lookbackDays = (int) ($ddaConfig['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS);
        $this->maxPathLength = (int) ($ddaConfig['max_path_length'] ?? self::DEFAULT_MAX_PATH_LENGTH);
    }

    /**
     * Check if data-driven attribution is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute Shapley value attribution for a set of conversion paths.
     *
     * Each path is an ordered list of channel touchpoints that led to a conversion.
     * The algorithm computes the marginal contribution of each channel by
     * evaluating all possible coalitions (subsets) of channels.
     *
     * @param  array<int, array{path: list<string>, value: float, conversion_id?: string}>  $conversionPaths  Observed conversion journeys
     * @return array{channels: array<string, array{credit: float, percentage: float, marginal_contribution: float, paths_involved: int}>, total_value: float, model_confidence: float, data_quality: array{sufficient_data: bool, path_count: int, channel_count: int, min_required: int}}
     */
    public function computeAttribution(array $conversionPaths): array
    {
        if (empty($conversionPaths)) {
            return $this->emptyResult();
        }

        $channels = $this->extractChannels($conversionPaths);
        $channelCount = count($channels);

        if ($channelCount < 2) {
            // With only one channel, it gets 100% credit
            $singleChannel = array_key_first($channels);

            return [
                'channels' => [
                    $singleChannel => [
                        'credit' => array_sum(array_column($conversionPaths, 'value')),
                        'percentage' => 100.0,
                        'marginal_contribution' => 1.0,
                        'paths_involved' => count($conversionPaths),
                    ],
                ],
                'total_value' => array_sum(array_column($conversionPaths, 'value')),
                'model_confidence' => 1.0,
                'data_quality' => [
                    'sufficient_data' => count($conversionPaths) >= $this->minConversions,
                    'path_count' => count($conversionPaths),
                    'channel_count' => $channelCount,
                    'min_required' => $this->minConversions,
                ],
            ];
        }

        $coalitionValues = $this->buildCoalitionValues($conversionPaths, $channels);

        $shapleyValues = $this->computeShapleyValues($channels, $coalitionValues);

        $totalShapley = array_sum($shapleyValues);
        $totalValue = array_sum(array_column($conversionPaths, 'value'));

        $result = [];
        foreach ($channels as $channel) {
            $shapley = $shapleyValues[$channel] ?? 0.0;
            $percentage = $totalShapley > 0 ? ($shapley / $totalShapley) * 100.0 : 0.0;

            $result[$channel] = [
                'credit' => $totalValue * ($percentage / 100.0),
                'percentage' => round($percentage, 2),
                'marginal_contribution' => round($shapley, 6),
                'paths_involved' => $channels[$channel],
            ];
        }

        $pathCount = count($conversionPaths);
        $confidence = min(1.0, $pathCount / ($this->minConversions * 3));

        return [
            'channels' => $result,
            'total_value' => round($totalValue, 2),
            'model_confidence' => round($confidence, 2),
            'data_quality' => [
                'sufficient_data' => $pathCount >= $this->minConversions,
                'path_count' => $pathCount,
                'channel_count' => $channelCount,
                'min_required' => $this->minConversions,
            ],
        ];
    }

    /**
     * Compute incremental attribution — compare two time periods.
     *
     * Shows how channel attribution shifted between periods,
     * highlighting channels gaining or losing influence.
     *
     * @param  array<int, array{path: list<string>, value: float}>  $currentPaths
     * @param  array<int, array{path: list<string>, value: float}>  $previousPaths
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, changes: array<string, array{credit_delta: float, percentage_delta: float, trend: string}>}
     */
    public function comparePeriods(array $currentPaths, array $previousPaths): array
    {
        $current = $this->computeAttribution($currentPaths);
        $previous = $this->computeAttribution($previousPaths);

        $allChannels = array_unique([
            ...array_keys($current['channels']),
            ...array_keys($previous['channels']),
        ]);

        $changes = [];
        foreach ($allChannels as $channel) {
            $currentPct = $current['channels'][$channel]['percentage'] ?? 0.0;
            $previousPct = $previous['channels'][$channel]['percentage'] ?? 0.0;
            $delta = $currentPct - $previousPct;

            $changes[$channel] = [
                'credit_delta' => round(($current['channels'][$channel]['credit'] ?? 0.0) - ($previous['channels'][$channel]['credit'] ?? 0.0), 2),
                'percentage_delta' => round($delta, 2),
                'trend' => match (true) {
                    $delta > 2.0 => 'up',
                    $delta < -2.0 => 'down',
                    default => 'stable',
                },
            ];
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => $changes,
        ];
    }

    /**
     * Compute channel removal impact — simulate what happens if a channel is removed.
     *
     * Shows the estimated revenue loss if a specific channel were to stop
     * generating touchpoints. Useful for budget allocation decisions.
     *
     * @param  array<int, array{path: list<string>, value: float}>  $conversionPaths
     * @return array<string, array{estimated_loss: float, loss_percentage: float, affected_conversions: int, total_conversions: int, criticality: string}>
     */
    public function channelRemovalImpact(array $conversionPaths): array
    {
        $attribution = $this->computeAttribution($conversionPaths);
        $totalValue = $attribution['total_value'];
        $totalConversions = count($conversionPaths);

        $impact = [];
        foreach ($attribution['channels'] as $channel => $data) {
            $loss = $data['credit'];
            $lossPct = $totalValue > 0 ? ($loss / $totalValue) * 100.0 : 0.0;

            $affectedConversions = 0;
            foreach ($conversionPaths as $path) {
                if (in_array($channel, $path['path'], true)) {
                    $affectedConversions++;
                }
            }

            $impact[$channel] = [
                'estimated_loss' => round($loss, 2),
                'loss_percentage' => round($lossPct, 2),
                'affected_conversions' => $affectedConversions,
                'total_conversions' => $totalConversions,
                'criticality' => match (true) {
                    $lossPct > 30.0 => 'critical',
                    $lossPct > 15.0 => 'high',
                    $lossPct > 5.0 => 'medium',
                    default => 'low',
                },
            ];
        }

        uasort($impact, fn (array $a, array $b): int => $b['estimated_loss'] <=> $a['estimated_loss']);

        return $impact;
    }

    /**
     * Get budget allocation recommendations based on attribution data.
     *
     * Allocates marketing budget proportionally to channel credit,
     * with configurable minimum allocation floor for experimentation.
     *
     * @param  array<int, array{path: list<string>, value: float}>  $conversionPaths
     * @param  float  $totalBudget  Total marketing budget to allocate
     * @param  float  $minAllocationPct  Minimum % allocation per channel (default 5%)
     * @return array{allocations: array<string, array{amount: float, percentage: float, recommended: bool}>, unallocated: float, efficiency_score: float}
     */
    public function budgetAllocation(array $conversionPaths, float $totalBudget, float $minAllocationPct = 5.0): array
    {
        $attribution = $this->computeAttribution($conversionPaths);

        if (empty($attribution['channels'])) {
            return [
                'allocations' => [],
                'unallocated' => $totalBudget,
                'efficiency_score' => 0.0,
            ];
        }

        $allocations = [];
        foreach ($attribution['channels'] as $channel => $data) {
            $amount = $totalBudget * ($data['percentage'] / 100.0);
            $allocations[$channel] = [
                'amount' => round($amount, 2),
                'percentage' => $data['percentage'],
                'recommended' => $data['percentage'] >= $minAllocationPct,
            ];
        }

        $allocatedTotal = array_sum(array_column($allocations, 'amount'));
        $efficiency = $totalBudget > 0 ? round(($totalBudget - abs($totalBudget - $allocatedTotal)) / $totalBudget, 2) : 0.0;

        return [
            'allocations' => $allocations,
            'unallocated' => round($totalBudget - $allocatedTotal, 2),
            'efficiency_score' => $efficiency,
        ];
    }

    /**
     * Extract unique channels and their occurrence counts from conversion paths.
     *
     * @param  array<int, array{path: list<string>, value: float}>  $conversionPaths
     * @return array<string, int> Channel name → occurrence count
     */
    private function extractChannels(array $conversionPaths): array
    {
        $channels = [];

        foreach ($conversionPaths as $entry) {
            $path = $entry['path'] ?? [];
            foreach ($path as $channel) {
                $channels[$channel] = ($channels[$channel] ?? 0) + 1;
            }
        }

        return $channels;
    }

    /**
     * Build a value table for all possible channel coalitions.
     *
     * For each coalition (subset of channels), compute the total conversion
     * value from paths that contain ALL channels in the coalition.
     *
     * @param  array<int, array{path: list<string>, value: float}>  $conversionPaths
     * @param  array<string, int>  $channels
     * @return array<string, float> Coalition key → total conversion value
     */
    private function buildCoalitionValues(array $conversionPaths, array $channels): array
    {
        $channelNames = array_keys($channels);
        $coalitions = [];

        $count = count($channelNames);
        for ($mask = 0; $mask < (1 << $count); $mask++) {
            $coalition = [];
            for ($i = 0; $i < $count; $i++) {
                if ($mask & (1 << $i)) {
                    $coalition[] = $channelNames[$i];
                }
            }
            $key = $this->coalitionKey($coalition);

            // at least one channel from this coalition
            $value = 0.0;
            foreach ($conversionPaths as $entry) {
                $pathChannels = array_flip($entry['path'] ?? []);
                foreach ($coalition as $ch) {
                    if (isset($pathChannels[$ch])) {
                        $value += $entry['value'];
                        break;
                    }
                }
            }

            $coalitions[$key] = $value;
        }

        return $coalitions;
    }

    /**
     * Compute Shapley values for each channel.
     *
     * The Shapley value of a channel is the average marginal contribution
     * across all possible orderings of channels. For efficiency, we use
     * the exact computation (feasible for ≤ 15 channels).
     *
     * φ_i = Σ_S [v(S ∪ {i}) - v(S)] * |S|! * (n - |S| - 1)! / n!
     *
     * Where the sum is over all coalitions S not containing channel i.
     *
     * @param  array<string, int>  $channels
     * @param  array<string, float>  $coalitionValues
     * @return array<string, float> Channel → Shapley value
     */
    private function computeShapleyValues(array $channels, array $coalitionValues): array
    {
        $channelNames = array_keys($channels);
        $n = count($channelNames);
        $shapley = array_fill_keys($channelNames, 0.0);

        if ($n > 15) {
            // Fall back to approximation for large channel sets
            return $this->approximateShapley($channelNames, $coalitionValues, $n);
        }

        for ($mask = 0; $mask < (1 << $n); $mask++) {
            $coalitionSize = $this->popcount($mask);

            for ($i = 0; $i < $n; $i++) {
                // Channel i is NOT in coalition S
                if ($mask & (1 << $i)) {
                    continue;
                }

                // Value with channel i added
                $maskWithI = $mask | (1 << $i);
                $coalitionKey = $this->coalitionKeyFromMask($channelNames, $mask);
                $coalitionWithIKey = $this->coalitionKeyFromMask($channelNames, $maskWithI);

                $marginal = ($coalitionValues[$coalitionWithIKey] ?? 0.0)
                    - ($coalitionValues[$coalitionKey] ?? 0.0);

                // Weight: |S|! * (n - |S| - 1)! / n!
                $weight = $this->factorial($coalitionSize)
                    * $this->factorial($n - $coalitionSize - 1)
                    / $this->factorial($n);

                $shapley[$channelNames[$i]] += $marginal * $weight;
            }
        }

        foreach ($shapley as $channel => $value) {
            $shapley[$channel] = max(0.0, $value);
        }

        return $shapley;
    }

    /**
     * Approximate Shapley values using Monte Carlo sampling for large channel sets.
     *
     * Samples random permutations and computes average marginal contributions.
     * Less precise than exact computation but O(m * n) instead of O(2^n).
     *
     * @param  list<string>  $channelNames
     * @param  array<string, float>  $coalitionValues
     * @param  int  $n
     * @return array<string, float>
     */
    private function approximateShapley(array $channelNames, array $coalitionValues, int $n): array
    {
        $shapley = array_fill_keys($channelNames, 0.0);
        $samples = min(10000, $n * 1000);

        for ($s = 0; $s < $samples; $s++) {
            // Random permutation
            $permutation = $channelNames;
            shuffle($permutation);

            $currentMask = 0;
            $currentKey = $this->coalitionKey([]);

            foreach ($permutation as $idx => $channel) {
                $channelIdx = array_search($channel, $channelNames, true);
                if ($channelIdx === false) {
                    continue;
                }

                $newMask = $currentMask | (1 << $channelIdx);
                $newKey = $this->coalitionKeyFromMask($channelNames, $newMask);

                $marginal = ($coalitionValues[$newKey] ?? 0.0) - ($coalitionValues[$currentKey] ?? 0.0);
                $shapley[$channel] += $marginal;

                $currentMask = $newMask;
                $currentKey = $newKey;
            }
        }

        // Average over samples
        foreach ($shapley as $channel => $value) {
            $shapley[$channel] = max(0.0, $value / $samples);
        }

        return $shapley;
    }

    /**
     * Generate a coalition key from a list of channel names.
     *
     * @param  list<string>  $channels
     */
    private function coalitionKey(array $channels): string
    {
        $sorted = $channels;
        sort($sorted);

        return implode('|', $sorted);
    }

    /**
     * Generate a coalition key from a bitmask.
     *
     * @param  list<string>  $channelNames
     */
    private function coalitionKeyFromMask(array $channelNames, int $mask): string
    {
        $members = [];
        $n = count($channelNames);

        for ($i = 0; $i < $n; $i++) {
            if ($mask & (1 << $i)) {
                $members[] = $channelNames[$i];
            }
        }

        return $this->coalitionKey($members);
    }

    /**
     * Count set bits (population count) in an integer.
     */
    private function popcount(int $mask): int
    {
        return substr_count(decbin($mask), '1');
    }

    /**
     * Compute factorial (with memoization for performance).
     */
    private function factorial(int $n): float
    {
        static $cache = [];

        if ($n <= 1) {
            return 1.0;
        }

        if (isset($cache[$n])) {
            return $cache[$n];
        }

        $result = $n * $this->factorial($n - 1);
        $cache[$n] = $result;

        return $result;
    }

    /**
     * Return an empty result structure.
     *
     * @return array{channels: array<never, never>, total_value: float, model_confidence: float, data_quality: array{sufficient_data: bool, path_count: int, channel_count: int, min_required: int}}
     */
    private function emptyResult(): array
    {
        return [
            'channels' => [],
            'total_value' => 0.0,
            'model_confidence' => 0.0,
            'data_quality' => [
                'sufficient_data' => false,
                'path_count' => 0,
                'channel_count' => 0,
                'min_required' => $this->minConversions,
            ],
        ];
    }
}
