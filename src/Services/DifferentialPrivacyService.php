<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Differential privacy service for privacy-safe analytics aggregation.
 *
 * Adds calibrated Laplace noise to aggregate analytics values (counts, sums,
 * averages) so that individual user contributions cannot be inferred from
 * the published results. Follows the Google RAPPOR / Apple differential
 * privacy model adapted for SaaS analytics dashboards.
 *
 * When enabled, all aggregate metrics returned by dashboard APIs and commands
 * pass through this service, which applies noise calibrated to the configured
 * privacy budget (epsilon ε) and sensitivity (Δ).
 *
 * Higher epsilon = more accurate results but less privacy.
 * Lower epsilon = stronger privacy but noisier results.
 *
 * Recommended values:
 * - ε = 1.0: Strong privacy (usable for public dashboards)
 * - ε = 0.5: Very strong privacy (internal use only)
 * - ε = 5.0: Weak privacy (practically no noise, compliance theater)
 *
 * Config: `zeroboiler.analytics.differential_privacy`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsDataQualityScorer
 * @see \ZeroBoiler\Analytics\Services\PrivacyManifestService
 *
 * @since 42.0.0
 */
final class DifferentialPrivacyService
{
    /** @var float Natural logarithm of 2 */
    private const LN2 = 0.6931471805599453;

    private CacheRepository $cache;

    private bool $enabled;

    private float $epsilon;

    private float $defaultDelta;

    private int $cacheTtl;

    private string $cachePrefix;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $dpConfig = $config->get('zeroboiler.analytics.differential_privacy', []);
        /** @var array{enabled?: bool, epsilon?: float, default_delta?: float, cache_ttl?: int, cache_prefix?: string} $dpConfig */

        $this->enabled = (bool) ($dpConfig['enabled'] ?? false);
        $this->epsilon = (float) ($dpConfig['epsilon'] ?? 1.0);
        $this->defaultDelta = (float) ($dpConfig['default_delta'] ?? 1.0);
        $this->cacheTtl = (int) ($dpConfig['cache_ttl'] ?? 300);
        $this->cachePrefix = $dpConfig['cache_prefix'] ?? 'zb_dp_';
    }

    /**
     * Check if differential privacy is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current privacy budget (epsilon).
     */
    public function getEpsilon(): float
    {
        return $this->epsilon;
    }

    /**
     * Add Laplace noise to a numeric value.
     *
     * Uses the Laplace mechanism: noisy_value = true_value + Laplace(Δ/ε)
     * where Δ is the sensitivity (maximum contribution of one individual).
     *
     * For count queries: Δ = 1 (each user contributes at most 1 to a count).
     * For sum queries: Δ = max possible individual contribution.
     *
     * @param  float  $value  The true aggregate value
     * @param  float|null  $delta  Sensitivity (max individual contribution). Default: 1.0
     * @return float  The noisy value (never negative for counts)
     */
    public function addNoise(float $value, ?float $delta = null): float
    {
        if (! $this->enabled) {
            return $value;
        }

        $sensitivity = $delta ?? $this->defaultDelta;
        $scale = $sensitivity / $this->epsilon;
        $noise = $this->laplaceSample($scale);

        $result = $value + $noise;

        // Clamp to non-negative (counts can't be negative)
        return max(0.0, $result);
    }

    /**
     * Add noise to a percentage value (0-100).
     *
     * Uses sensitivity Δ = 100/n where n is the population size.
     * Falls back to Δ = 5.0 if population is unknown.
     *
     * @param  float  $percentage  True percentage (0-100)
     * @param  int|null  $populationSize  Total number of data points
     * @return float  Noisy percentage clamped to [0, 100]
     */
    public function addNoiseToPercentage(float $percentage, ?int $populationSize = null): float
    {
        if (! $this->enabled) {
            return $percentage;
        }

        if ($populationSize !== null && $populationSize > 0) {
            $delta = 100.0 / $populationSize;
        } else {
            $delta = 5.0;
        }

        return max(0.0, min(100.0, $this->addNoise($percentage, $delta)));
    }

    /**
     * Add noise to a monetary value (revenue, MRR, etc.).
     *
     * Uses configurable sensitivity — the maximum revenue a single
     * user could contribute in the measurement period.
     *
     * @param  float  $amount  True monetary value
     * @param  float|null  $maxIndividualContribution  Max revenue from one user (default from config)
     * @return float  Noisy monetary value (never negative)
     */
    public function addNoiseToRevenue(float $amount, ?float $maxIndividualContribution = null): float
    {
        if (! $this->enabled) {
            return $amount;
        }

        $delta = $maxIndividualContribution ?? $this->defaultDelta;

        return max(0.0, $this->addNoise($amount, $delta));
    }

    /**
     * Anonymize a small count with k-anonymity threshold.
     *
     * If the count is below the k-anonymity threshold, returns null
     * to prevent small-group re-identification.
     *
     * @param  int  $count  True count
     * @param  int  $k  K-anonymity threshold (default: 10)
     * @return int|null  Noisy count or null if below threshold
     */
    public function anonymizeCount(int $count, int $k = 10): ?int
    {
        if (! $this->enabled) {
            return $count;
        }

        if ($count < $k) {
            return null;
        }

        return (int) round($this->addNoise((float) $count, 1.0));
    }

    /**
     * Generate a privacy-safe histogram bucket.
     *
     * Adds noise to each bucket count in a histogram while preserving
     * the total (approximately) by redistributing noise proportionally.
     *
     * @param  array<string, int>  $buckets  Bucket label → count
     * @param  int  $k  K-anonymity threshold
     * @return array<string, int|null>  Bucket label → noisy count (null if suppressed)
     */
    public function anonymizeHistogram(array $buckets, int $k = 10): array
    {
        if (! $this->enabled) {
            return $buckets;
        }

        $result = [];
        foreach ($buckets as $label => $count) {
            $result[$label] = $this->anonymizeCount($count, $k);
        }

        return $result;
    }

    /**
     * Privacy-safe top-N ranking with noise injection.
     *
     * Adds noise to values before ranking to prevent inference
     * of exact values from rank positions.
     *
     * @param  array<string, float>  $items  Label → value
     * @param  int  $topN  Number of items to return
     * @return list<array{label: string, value: float, rank: int}>
     */
    public function privacySafeTopN(array $items, int $topN = 10): array
    {
        if (! $this->enabled) {
            arsort($items);
            $ranked = [];
            $rank = 1;
            foreach (array_slice($items, 0, $topN, true) as $label => $value) {
                $ranked[] = ['label' => $label, 'value' => $value, 'rank' => $rank++];
            }

            return $ranked;
        }

        $noisy = [];
        foreach ($items as $label => $value) {
            $noisy[$label] = max(0.0, $this->addNoise($value));
        }

        arsort($noisy);
        $ranked = [];
        $rank = 1;
        foreach (array_slice($noisy, 0, $topN, true) as $label => $noisyValue) {
            $ranked[] = [
                'label' => $label,
                'value' => $noisyValue,
                'rank' => $rank++,
            ];
        }

        return $ranked;
    }

    /**
     * Compute privacy budget consumed by a query.
     *
     * Tracks cumulative epsilon usage per time period to prevent
     * privacy budget exhaustion (which would weaken guarantees).
     *
     * @param  float  $queryEpsilon  Epsilon consumed by this query
     * @param  string  $period  Time period key (default: current hour)
     * @return array{consumed: float, remaining: float, budget: float, exhausted: bool}
     */
    public function consumeBudget(float $queryEpsilon, ?string $period = null): array
    {
        if (! $this->enabled) {
            return [
                'consumed' => 0.0,
                'remaining' => 0.0,
                'budget' => 0.0,
                'exhausted' => false,
            ];
        }

        $periodKey = $period ?? date('Y-m-d-H');
        $budgetKey = $this->cachePrefix . 'budget_' . $periodKey;
        $totalBudget = $this->epsilon * 3.0; // 3x epsilon per hour = daily budget spread

        /** @var float $consumed */
        $consumed = (float) $this->cache->get($budgetKey, 0.0);
        $remaining = max(0.0, $totalBudget - $consumed);
        $exhausted = $consumed >= $totalBudget;

        if (! $exhausted) {
            $this->cache->put($budgetKey, $consumed + $queryEpsilon, $this->cacheTtl);
            $remaining -= $queryEpsilon;
        }

        return [
            'consumed' => round($consumed + ($exhausted ? 0 : $queryEpsilon), 4),
            'remaining' => round(max(0.0, $remaining), 4),
            'budget' => round($totalBudget, 4),
            'exhausted' => $exhausted,
        ];
    }

    /**
     * Get the current privacy budget status.
     *
     * @return array{enabled: bool, epsilon: float, sensitivity: float, period_budget: float, current_consumed: float, remaining: float}
     */
    public function status(): array
    {
        $periodKey = date('Y-m-d-H');
        $budgetKey = $this->cachePrefix . 'budget_' . $periodKey;
        $totalBudget = $this->epsilon * 3.0;

        /** @var float $consumed */
        $consumed = (float) $this->cache->get($budgetKey, 0.0);

        return [
            'enabled' => $this->enabled,
            'epsilon' => $this->epsilon,
            'sensitivity' => $this->defaultDelta,
            'period_budget' => round($totalBudget, 4),
            'current_consumed' => round($consumed, 4),
            'remaining' => round(max(0.0, $totalBudget - $consumed), 4),
        ];
    }

    /**
     * Reset the privacy budget for the current period.
     */
    public function resetBudget(): void
    {
        $periodKey = date('Y-m-d-H');
        $this->cache->forget($this->cachePrefix . 'budget_' . $periodKey);
    }

    /**
     * Generate a sample from the Laplace distribution.
     *
     * Uses the inverse CDF method: L = scale * ln(U) * sign(0.5 - U)
     * where U is uniform random on (0,1).
     *
     * @param  float  $scale  Laplace scale parameter (b = Δ/ε)
     * @return float  Laplace-distributed random sample
     */
    private function laplaceSample(float $scale): float
    {
        if ($scale <= 0.0) {
            return 0.0;
        }

        $u = mt_rand() / mt_getrandmax();

        if ($u === 0.0) {
            $u = 1e-15; // Avoid log(0)
        }

        return $scale * self::LN2 * (0.5 - $u);
    }
}
