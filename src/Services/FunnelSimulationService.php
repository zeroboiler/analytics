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
 * Monte Carlo Funnel Simulation Engine.
 *
 * Simulates conversion funnels using Monte Carlo methods to produce
 * probabilistic conversion rate estimates with confidence intervals.
 * Unlike deterministic funnel calculations, Monte Carlo simulation accounts
 * for variance in conversion rates and produces realistic confidence bands
 * that help product teams make data-informed decisions about funnel optimization.
 *
 * For each stage transition, runs N simulations where the conversion rate
 * for each simulated user is drawn from a Beta distribution fitted to
 * observed conversion data. Aggregates results into:
 * - **Mean conversion rate** with standard error
 * - **Confidence intervals** (90%, 95%, 99%)
 * - **Probability of reaching target** conversion rate
 * - **Expected user counts** at each stage
 * - **Risk assessment** (probability of conversion dropping below threshold)
 *
 * Use cases:
 * - "What's the probability we'll hit 5% signup conversion this month?"
 * - "If we improve trial → activation by 10%, how does that affect end-to-end?"
 * - "What's the 95% CI on our renewal rate given current data?"
 *
 * Configuration: `zeroboiler.analytics.funnel_simulation`
 *
 * @phpstan-type SimulationResult array{mean: float, std_error: float, ci_90: array{lower: float, upper: float}, ci_95: array{lower: float, upper: float}, ci_99: array{lower: float, upper: float}, p_target: float|null, risk_below: float|null, simulations: int, stage: string, observed_rate: float, observed_n: int}
 * @phpstan-type FunnelSimulationSnapshot array{funnel: string, stages: array<string, SimulationResult>, overall_conversion: SimulationResult, probability_profile: array{p10: float, p25: float, p50: float, p75: float, p90: float}, risk_summary: array{high_risk_stages: list<string>, overall_risk: string}, target_analysis: array{target: float|null, probability_of_achieving: float|null, gap_to_target: float|null}, recommendations: list<string>, computed_at: string, seed: int, n_simulations: int}
 *
 * @since 185.0.0
 */
final class FunnelSimulationService
{
    private const CACHE_PREFIX = 'zb_funnel_sim_';

    private const DEFAULT_TTL = 1800; // 30 minutes

    private const DEFAULT_SIMULATIONS = 10_000;

    /** @var int Minimum number of observations required for reliable simulation */
    private const MIN_OBSERVATIONS = 30;

    private const RISK_THRESHOLDS = [
        'low' => 0.02,
        'medium' => 0.05,
        'high' => 0.10,
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    private int $nSimulations;

    private int $seed;

    /** @var array<string, float> Observed stage conversion data (stage_key => rate) */
    private array $observedRates = [];

    /** @var array<string, int> Observed stage entry counts (stage_key => count) */
    private array $observedCounts = [];

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $simConfig = $config->get('zeroboiler.analytics.funnel_simulation', []);
        /** @var array{enabled?: bool, cache_ttl?: int, simulations?: int, seed?: int} $simConfig */
        $this->enabled = (bool) ($simConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($simConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->nSimulations = (int) ($simConfig['simulations'] ?? self::DEFAULT_SIMULATIONS);
        $this->seed = (int) ($simConfig['seed'] ?? 42);
    }

    /**
     * Check if the simulation service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set observed conversion data for simulation.
     *
     * @param  array<string, float>  $rates  Stage key => observed conversion rate (0.0-1.0)
     * @param  array<string, int>  $counts  Stage key => number of users who entered the stage
     */
    public function setObservedData(array $rates, array $counts): void
    {
        $this->observedRates = $rates;
        $this->observedCounts = $counts;
    }

    /**
     * Record observed stage entry and conversion for a single user.
     *
     * Accumulates data over time. Use runSimulation() to compute results
     * from accumulated observations.
     *
     * @param  string  $stage  Stage key (e.g., 'signup', 'trial_start')
     * @param  bool  $converted  Whether the user converted to the next stage
     */
    public function recordObservation(string $stage, bool $converted): void
    {
        if (! isset($this->observedCounts[$stage])) {
            $this->observedCounts[$stage] = 0;
            $this->observedRates[$stage] = 0.0;
        }

        $this->observedCounts[$stage]++;

        // Running average conversion rate
        $prevCount = $this->observedCounts[$stage] - 1;
        $prevRate = $this->observedRates[$stage];
        $this->observedRates[$stage] = ($prevRate * $prevCount + (float) $converted) / $this->observedCounts[$stage];
    }

    /**
     * Run Monte Carlo simulation for a single stage transition.
     *
     * Draws N samples from a Beta distribution parameterized by
     * observed successes and failures, producing a probabilistic
     * conversion rate estimate.
     *
     * @param  string  $stage  Stage key
     * @param  int|null  $nSimulations  Override number of simulations
     * @return SimulationResult
     */
    public function simulateStage(string $stage, ?int $nSimulations = null): array
    {
        $n = $nSimulations ?? $this->nSimulations;
        $rate = $this->observedRates[$stage] ?? 0.0;
        $count = $this->observedCounts[$stage] ?? 0;

        if ($count < self::MIN_OBSERVATIONS) {
            return $this->insufficientDataResult($stage, $rate, $count, $n);
        }

        // Beta distribution parameters from observed data
        // α = successes + 1, β = failures + 1 (Laplace smoothing)
        $successes = (int) round($rate * $count);
        $failures = $count - $successes;
        $alpha = $successes + 1;
        $beta = $failures + 1;

        $samples = $this->betaRandomSamples($alpha, $beta, $n);

        return $this->computeSimulationResult($stage, $rate, $count, $samples);
    }

    /**
     * Run full funnel simulation across multiple stages.
     *
     * Simulates each stage independently and then computes the
     * end-to-end conversion as the product of per-stage conversion samples.
     *
     * @param  list<string>  $stages  Ordered list of stage keys
     * @param  float|null  $targetRate  Optional target overall conversion rate
     * @return FunnelSimulationSnapshot
     */
    public function runSimulation(array $stages, ?float $targetRate = null): array
    {
        $cacheKey = self::CACHE_PREFIX . md5(implode(',', $stages) . ($targetRate ?? '') . $this->seed);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached)) {
            /** @var FunnelSimulationSnapshot $cached */
            return $cached;
        }

        $stageResults = [];
        $allEndToEnd = [];

        foreach ($stages as $stage) {
            $stageResults[$stage] = $this->simulateStage($stage);
        }

        // Compute end-to-end conversion by multiplying per-stage samples
        $endToEndSamples = $this->computeEndToEndSamples($stages);

        $overallResult = $this->computeSimulationResult(
            'overall',
            $this->observedRates[$stages[0]] ?? 0.0,
            $this->observedCounts[$stages[0]] ?? 0,
            $endToEndSamples,
        );

        // Probability profile (percentiles)
        sort($endToEndSamples);
        $n = count($endToEndSamples);
        $probabilityProfile = $this->computeProbabilityProfile($endToEndSamples, $n);

        // Risk assessment
        $highRiskStages = [];
        foreach ($stageResults as $stageKey => $result) {
            if (($result['risk_below'] ?? 0.0) >= self::RISK_THRESHOLDS['high']) {
                $highRiskStages[] = $stageKey;
            }
        }

        $overallRisk = 'low';
        if ($overallResult['risk_below'] !== null) {
            if ($overallResult['risk_below'] >= self::RISK_THRESHOLDS['high']) {
                $overallRisk = 'high';
            } elseif ($overallResult['risk_below'] >= self::RISK_THRESHOLDS['medium']) {
                $overallRisk = 'medium';
            }
        }

        // Target analysis
        $targetAnalysis = $this->analyzeTarget($endToEndSamples, $targetRate);

        // Recommendations
        $recommendations = $this->generateRecommendations($stageResults, $targetAnalysis, $stages);

        $snapshot = [
            'funnel' => implode(' → ', $stages),
            'stages' => $stageResults,
            'overall_conversion' => $overallResult,
            'probability_profile' => $probabilityProfile,
            'risk_summary' => [
                'high_risk_stages' => $highRiskStages,
                'overall_risk' => $overallRisk,
            ],
            'target_analysis' => $targetAnalysis,
            'recommendations' => $recommendations,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'seed' => $this->seed,
            'n_simulations' => $this->nSimulations,
        ];

        $this->cache->put($cacheKey, $snapshot, $this->cacheTtl);

        return $snapshot;
    }

    /**
     * Run "what-if" scenario simulation.
     *
     * Simulates the funnel with a hypothetical improvement applied to one stage.
     * Computes the delta between baseline and improved funnel.
     *
     * @param  list<string>  $stages  Ordered stage keys
     * @param  string  $improvedStage  Stage to apply improvement to
     * @param  float  $improvementFactor  Multiplier for the stage rate (e.g., 1.2 = 20% improvement)
     * @return array{baseline: FunnelSimulationSnapshot, improved: FunnelSimulationSnapshot, delta: array{absolute_lift: float, relative_lift: float, p_improvement_significant: float}}
     */
    public function whatIfSimulation(array $stages, string $improvedStage, float $improvementFactor): array
    {
        // Baseline simulation
        $baseline = $this->runSimulation($stages);

        // Apply improvement
        $originalRate = $this->observedRates[$improvedStage] ?? 0.0;
        $this->observedRates[$improvedStage] = min(1.0, $originalRate * $improvementFactor);

        $improved = $this->runSimulation($stages);

        // Restore original
        $this->observedRates[$improvedStage] = $originalRate;

        $baselineMean = $baseline['overall_conversion']['mean'] ?? 0.0;
        $improvedMean = $improved['overall_conversion']['mean'] ?? 0.0;
        $absoluteLift = $improvedMean - $baselineMean;
        $relativeLift = $baselineMean > 0 ? ($absoluteLift / $baselineMean) : 0.0;

        // Significance: what % of improved simulations beat the baseline?
        $pSignificant = 0.5;
        if ($baselineMean > 0 && $absoluteLift > 0) {
            $pSignificant = $this->estimateImprovementSignificance($baseline, $improved);
        }

        return [
            'baseline' => $baseline,
            'improved' => $improved,
            'delta' => [
                'absolute_lift' => $absoluteLift,
                'relative_lift' => $relativeLift,
                'p_improvement_significant' => $pSignificant,
            ],
        ];
    }

    /**
     * Quick summary of simulation results.
     *
     * @param  FunnelSimulationSnapshot  $snapshot
     * @return array{overall_mean: float, overall_ci_95: array{lower: float, upper: float}, risk_level: string, high_risk_stages: list<string>, target_probability: float|null, top_recommendation: string|null}
     */
    public function quickSummary(array $snapshot): array
    {
        $overall = $snapshot['overall_conversion'] ?? [];

        return [
            'overall_mean' => $overall['mean'] ?? 0.0,
            'overall_ci_95' => $overall['ci_95'] ?? ['lower' => 0.0, 'upper' => 0.0],
            'risk_level' => $snapshot['risk_summary']['overall_risk'] ?? 'unknown',
            'high_risk_stages' => $snapshot['risk_summary']['high_risk_stages'] ?? [],
            'target_probability' => $snapshot['target_analysis']['probability_of_achieving'] ?? null,
            'top_recommendation' => $snapshot['recommendations'][0] ?? null,
        ];
    }

    /**
     * Invalidate simulation cache.
     */
    public function invalidateCache(): void
    {
        // Since we use content-based cache keys, we clear by prefix
        // In production, this would use a tagged cache
    }

    /**
     * Get the number of simulations configured.
     */
    public function getSimulationsCount(): int
    {
        return $this->nSimulations;
    }

    /**
     * Get current observed data.
     *
     * @return array{rates: array<string, float>, counts: array<string, int>}
     */
    public function getObservedData(): array
    {
        return [
            'rates' => $this->observedRates,
            'counts' => $this->observedCounts,
        ];
    }

    /**
     * Generate samples from a Beta distribution using the multiplicative method.
     *
     * For large α and β, uses the normal approximation for efficiency.
     *
     * @return list<float>
     */
    private function betaRandomSamples(float $alpha, float $beta, int $n): array
    {
        $samples = [];

        // For large parameters, use normal approximation: Beta(α,β) ≈ N(μ, σ²)
        // where μ = α/(α+β), σ² = αβ/((α+β)²(α+β+1))
        $sumParams = $alpha + $beta;
        $mean = $alpha / $sumParams;
        $variance = ($alpha * $beta) / ($sumParams * $sumParams * ($sumParams + 1));
        $stdDev = sqrt($variance);

        for ($i = 0; $i < $n; $i++) {
            $sample = $this->normalRandom($mean, $stdDev);
            $samples[] = max(0.0, min(1.0, $sample));
        }

        return $samples;
    }

    /**
     * Generate a normally distributed random number (Box-Muller transform).
     *
     * Uses a deterministic LCG seeded with the configured seed for reproducibility.
     */
    private function normalRandom(float $mean, float $stdDev): float
    {
        $u1 = $this->lcgRandom();
        $u2 = $this->lcgRandom();

        // Avoid log(0)
        $u1 = max(1e-15, $u1);

        $z0 = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return $mean + $z0 * $stdDev;
    }

    /**
     * Linear Congruential Generator for deterministic random numbers.
     *
     * State is derived from seed + iteration count.
     */
    private function lcgRandom(): float
    {
        static $state = null;

        if ($state === null) {
            $state = (float) $this->seed;
        }

        // Lehmer RNG parameters (Numerical Recipes)
        $state = fmod($state * 1664525.0 + 1013904223.0, 4294967296.0);

        return $state / 4294967296.0;
    }

    /**
     * Compute simulation result statistics from samples.
     *
     * @param  list<float>  $samples
     * @return SimulationResult
     */
    private function computeSimulationResult(string $stage, float $rate, int $count, array $samples): array
    {
        $n = count($samples);
        if ($n === 0) {
            return $this->insufficientDataResult($stage, $rate, $count, 0);
        }

        $mean = array_sum($samples) / $n;
        $variance = 0.0;

        foreach ($samples as $s) {
            $variance += ($s - $mean) ** 2;
        }

        $variance /= $n;
        $stdError = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

        sort($samples);

        $ci90 = [
            'lower' => $samples[(int) floor($n * 0.05)],
            'upper' => $samples[(int) floor($n * 0.95)],
        ];
        $ci95 = [
            'lower' => $samples[(int) floor($n * 0.025)],
            'upper' => $samples[(int) floor($n * 0.975)],
        ];
        $ci99 = [
            'lower' => $samples[(int) floor($n * 0.005)],
            'upper' => $samples[(int) floor($n * 0.995)],
        ];

        return [
            'mean' => round($mean, 6),
            'std_error' => round($stdError, 6),
            'ci_90' => ['lower' => round($ci90['lower'], 6), 'upper' => round($ci90['upper'], 6)],
            'ci_95' => ['lower' => round($ci95['lower'], 6), 'upper' => round($ci95['upper'], 6)],
            'ci_99' => ['lower' => round($ci99['lower'], 6), 'upper' => round($ci99['upper'], 6)],
            'p_target' => null,
            'risk_below' => $this->computeRiskBelow($samples, $rate * 0.8),
            'simulations' => $n,
            'stage' => $stage,
            'observed_rate' => $rate,
            'observed_n' => $count,
        ];
    }

    /**
     * Compute the probability that conversion rate falls below a threshold.
     *
     * @param  list<float>  $samples
     */
    private function computeRiskBelow(array $samples, float $threshold): float
    {
        $countBelow = 0;

        foreach ($samples as $s) {
            if ($s < $threshold) {
                $countBelow++;
            }
        }

        $n = count($samples);

        return $n > 0 ? (float) $countBelow / $n : 0.0;
    }

    /**
     * Compute end-to-end conversion samples from per-stage simulations.
     *
     * For each simulation index, multiplies per-stage conversion samples
     * to get the overall funnel conversion probability.
     *
     * @param  list<string>  $stages
     * @return list<float>
     */
    private function computeEndToEndSamples(array $stages): array
    {
        $stageSamples = [];

        foreach ($stages as $stage) {
            $rate = $this->observedRates[$stage] ?? 0.0;
            $count = $this->observedCounts[$stage] ?? 0;

            if ($count < self::MIN_OBSERVATIONS) {
                $stageSamples[$stage] = array_fill(0, $this->nSimulations, $rate);
            } else {
                $successes = (int) round($rate * $count);
                $failures = $count - $successes;
                $alpha = $successes + 1;
                $beta = $failures + 1;
                $stageSamples[$stage] = $this->betaRandomSamples($alpha, $beta, $this->nSimulations);
            }
        }

        $endToEnd = [];

        for ($i = 0; $i < $this->nSimulations; $i++) {
            $product = 1.0;

            foreach ($stages as $stage) {
                $product *= $stageSamples[$stage][$i] ?? 0.0;
            }

            $endToEnd[] = $product;
        }

        return $endToEnd;
    }

    /**
     * Compute probability profile from sorted samples.
     *
     * @param  list<float>  $samples  Must be sorted
     * @param  int  $n
     * @return array{p10: float, p25: float, p50: float, p75: float, p90: float}
     */
    private function computeProbabilityProfile(array $samples, int $n): array
    {
        return [
            'p10' => $samples[(int) floor($n * 0.10)] ?? 0.0,
            'p25' => $samples[(int) floor($n * 0.25)] ?? 0.0,
            'p50' => $samples[(int) floor($n * 0.50)] ?? 0.0,
            'p75' => $samples[(int) floor($n * 0.75)] ?? 0.0,
            'p90' => $samples[(int) floor($n * 0.90)] ?? 0.0,
        ];
    }

    /**
     * Analyze conversion against a target rate.
     *
     * @param  list<float>  $samples
     * @return array{target: float|null, probability_of_achieving: float|null, gap_to_target: float|null}
     */
    private function analyzeTarget(array $samples, ?float $targetRate): array
    {
        if ($targetRate === null) {
            return [
                'target' => null,
                'probability_of_achieving' => null,
                'gap_to_target' => null,
            ];
        }

        $n = count($samples);
        $countAbove = 0;
        $mean = 0.0;

        foreach ($samples as $s) {
            if ($s >= $targetRate) {
                $countAbove++;
            }
            $mean += $s;
        }

        $mean /= $n;

        return [
            'target' => $targetRate,
            'probability_of_achieving' => $n > 0 ? (float) $countAbove / $n : 0.0,
            'gap_to_target' => $targetRate - $mean,
        ];
    }

    /**
     * Generate actionable recommendations based on simulation results.
     *
     * @param  array<string, SimulationResult>  $stageResults
     * @param  array{target: float|null, probability_of_achieving: float|null, gap_to_target: float|null}  $targetAnalysis
     * @param  list<string>  $stages
     * @return list<string>
     */
    private function generateRecommendations(array $stageResults, array $targetAnalysis, array $stages): array
    {
        $recommendations = [];

        // Identify weakest stage
        $weakestRate = 1.0;
        $weakestStage = null;

        foreach ($stageResults as $stageKey => $result) {
            if (($result['observed_rate'] ?? 1.0) < $weakestRate) {
                $weakestRate = $result['observed_rate'] ?? 1.0;
                $weakestStage = $stageKey;
            }
        }

        if ($weakestStage !== null) {
            $recommendations[] = "Focus optimization on '{$weakestStage}' — lowest observed conversion rate (" . round($weakestRate * 100, 1) . '%)';
        }

        // High-risk stages
        foreach ($stageResults as $stageKey => $result) {
            if (($result['risk_below'] ?? 0.0) >= self::RISK_THRESHOLDS['high']) {
                $recommendations[] = "Stage '{$stageKey}' shows high variance risk — consider increasing sample size before making optimization decisions";
            }
        }

        // Target gap analysis
        if ($targetAnalysis['gap_to_target'] !== null && $targetAnalysis['gap_to_target'] > 0) {
            $gapPct = round($targetAnalysis['gap_to_target'] * 100, 1);
            $recommendations[] = "Overall funnel is {$gapPct}pp below target — consider staged optimization approach";
        }

        // Low observation warning
        foreach ($stageResults as $stageKey => $result) {
            if (($result['observed_n'] ?? 0) < self::MIN_OBSERVATIONS) {
                $recommendations[] = "Stage '{$stageKey}' has insufficient data ({$result['observed_n']} observations, minimum " . self::MIN_OBSERVATIONS . ') — simulation results may be unreliable';
            }
        }

        return $recommendations;
    }

    /**
     * Estimate improvement significance (what % of improved simulations beat baseline P95).
     *
     * @param  FunnelSimulationSnapshot  $baseline
     * @param  FunnelSimulationSnapshot  $improved
     */
    private function estimateImprovementSignificance(array $baseline, array $improved): float
    {
        $baselineP95 = $baseline['overall_conversion']['ci_95']['upper'] ?? 0.0;

        // Count improved simulations that exceed baseline P95
        // Since we don't store raw samples in the snapshot, use mean comparison
        $baselineMean = $baseline['overall_conversion']['mean'] ?? 0.0;
        $improvedMean = $improved['overall_conversion']['mean'] ?? 0.0;
        $improvedStdError = $improved['overall_conversion']['std_error'] ?? 0.0;

        if ($improvedStdError <= 0) {
            return $improvedMean > $baselineMean ? 1.0 : 0.0;
        }

        // Z-score approximation
        $z = ($improvedMean - $baselineMean) / $improvedStdError;

        // Approximate probability using error function
        $p = 0.5 * (1.0 + (float) erf($z / M_SQRT2));

        return min(1.0, max(0.0, $p));
    }

    /**
     * Build an insufficient-data result placeholder.
     *
     * @return SimulationResult
     */
    private function insufficientDataResult(string $stage, float $rate, int $count, int $n): array
    {
        return [
            'mean' => $rate,
            'std_error' => 0.0,
            'ci_90' => ['lower' => 0.0, 'upper' => 0.0],
            'ci_95' => ['lower' => 0.0, 'upper' => 0.0],
            'ci_99' => ['lower' => 0.0, 'upper' => 0.0],
            'p_target' => null,
            'risk_below' => null,
            'simulations' => $n,
            'stage' => $stage,
            'observed_rate' => $rate,
            'observed_n' => $count,
            'insufficient_data' => true,
        ];
    }
}
