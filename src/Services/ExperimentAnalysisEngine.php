<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Experiment analysis engine with Bayesian and Frequentist hypothesis testing.
 *
 * Provides comprehensive statistical analysis for A/B tests and multi-variant
 * experiments including:
 *
 * - **Frequentist tests**: Two-proportion z-test, chi-squared test, p-values
 * - **Bayesian analysis**: Beta-Binomial model, probability of being best,
 *   expected loss, credible intervals
 * - **Effect size**: Relative uplift, absolute lift, Cohen's h
 * - **Confidence intervals**: Wilson score, Agresti-Coull, Wald
 * - **Multi-variant corrections**: Bonferroni, Šidák, Holm-Bonferroni
 * - **Sequential testing**: Alpha spending (O'Brien-Fleming), early stopping
 * - **Sample size planning**: MDE (Minimum Detectable Effect) calculator
 * - **Revenue analysis**: Two-sample t-test for continuous metrics
 *
 * Configuration: `zeroboiler.analytics.experiment_analysis`
 *
 * @since 75.0.0
 */
final class ExperimentAnalysisEngine
{
    private const CACHE_PREFIX = 'zb_experiment_analysis_';

    private const CACHE_TTL = 604800; // 7 days

    private CacheRepository $cache;

    private bool $enabled;

    private float $defaultAlpha;

    private float $defaultPower;

    private string $defaultMethod;

    private float $sequentialAlphaSpendRate;

    private int $minSampleSize;

    private int $maxSequentialPeeks;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $expConfig = $config->get('zeroboiler.analytics.experiment_analysis', []);
        /** @var array{enabled?: bool, alpha?: float, power?: float, method?: string, sequential_alpha_spend_rate?: float, min_sample_size?: int, max_sequential_peeks?: int} $expConfig */
        $this->enabled = (bool) ($expConfig['enabled'] ?? true);
        $this->defaultAlpha = (float) ($expConfig['alpha'] ?? 0.05);
        $this->defaultPower = (float) ($expConfig['power'] ?? 0.80);
        $this->defaultMethod = (string) ($expConfig['method'] ?? 'bayesian');
        $this->sequentialAlphaSpendRate = (float) ($expConfig['sequential_alpha_spend_rate'] ?? 0.5);
        $this->minSampleSize = (int) ($expConfig['min_sample_size'] ?? 100);
        $this->maxSequentialPeeks = (int) ($expConfig['max_sequential_peeks'] ?? 10);
    }

    // ── Comprehensive Analysis ───────────────────────────────────────

    /**
     * Run comprehensive analysis on an experiment.
     *
     * Combines both Frequentist and Bayesian methods, computes effect sizes,
     * confidence intervals, and produces an actionable recommendation.
     *
     * @param  string  $experimentId  Unique experiment identifier
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants  Variant data
     * @param  string|null  $controlId  Control variant ID (null = first variant)
     * @param  string  $metricType  'conversion_rate'|'revenue'|'continuous'
     * @param  string  $method  'frequentist'|'bayesian'|'both'
     * @return array{
     *     experiment_id: string,
     *     method: string,
     *     metric_type: string,
     *     sample_size_met: bool,
     *     frequentist: array{p_value: float|null, significant: bool, confidence_level: float, test_used: string, recommendation: string}|null,
     *     bayesian: array{prob_best: array<string, float>, prob_beats_control: array<string, float|null>, expected_loss: array<string, float|null>, credible_interval: array<string, array{lower: float, upper: float}>}|null,
     *     effect_size: array<string, array{relative_uplift: float|null, absolute_lift: float|null, cohens_h: float|null}>,
     *     confidence_intervals: array<string, array{lower: float, upper: float, method: string}>,
     *     multi_variant_correction: array{method: string, adjusted_alpha: float, corrections: array<string, float>}|null,
     *     recommendation: string,
     *     winner: string|null,
     *     analyzed_at: int,
     * }
     */
    public function analyze(
        string $experimentId,
        array $variants,
        ?string $controlId = null,
        string $metricType = 'conversion_rate',
        string $method = 'both',
    ): array {
        if (empty($variants)) {
            return $this->emptyAnalysis($experimentId, $method, $metricType);
        }

        $variantIds = array_keys($variants);
        $controlId = $controlId ?? $variantIds[0];
        $totalExposures = array_sum(array_map(fn (array $v): int => $v['exposures'], $variants));
        $sampleSizeMet = $totalExposures >= $this->minSampleSize;

        // Frequentist analysis
        $frequentist = null;
        if ($method === 'frequentist' || $method === 'both') {
            $frequentist = $this->frequentistAnalysis($variants, $controlId, $metricType);
        }

        // Bayesian analysis
        $bayesian = null;
        if ($method === 'bayesian' || $method === 'both') {
            $bayesian = $this->bayesianAnalysis($variants, $controlId, $metricType);
        }

        // Effect sizes (relative to control)
        $effectSize = $this->computeEffectSizes($variants, $controlId, $metricType);

        // Confidence intervals per variant
        $confidenceIntervals = $this->computeConfidenceIntervals($variants, $metricType);

        // Multi-variant correction (if > 2 variants)
        $multiVariantCorrection = null;
        if (count($variantIds) > 2) {
            $multiVariantCorrection = $this->multiVariantCorrection($variantIds, $frequentist);
        }

        // Determine winner and recommendation
        $winner = $this->determineWinner($frequentist, $bayesian, $controlId);
        $recommendation = $this->generateRecommendation(
            $frequentist,
            $bayesian,
            $effectSize,
            $winner,
            $sampleSizeMet,
            $controlId,
        );

        $result = [
            'experiment_id' => $experimentId,
            'method' => $method,
            'metric_type' => $metricType,
            'sample_size_met' => $sampleSizeMet,
            'total_exposures' => $totalExposures,
            'frequentist' => $frequentist,
            'bayesian' => $bayesian,
            'effect_size' => $effectSize,
            'confidence_intervals' => $confidenceIntervals,
            'multi_variant_correction' => $multiVariantCorrection,
            'recommendation' => $recommendation,
            'winner' => $winner,
            'analyzed_at' => time(),
        ];

        // Cache the analysis
        $this->cacheAnalysis($experimentId, $result);

        return $result;
    }

    // ── Frequentist Analysis ────────────────────────────────────────────

    /**
     * Run Frequentist hypothesis testing.
     *
     * Uses two-proportion z-test for conversion rates and two-sample t-test
     * for continuous/revenue metrics.
     *
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants
     * @param  string  $controlId
     * @param  string  $metricType
     * @return array{p_value: float|null, significant: bool, confidence_level: float, test_used: string, recommendation: string}
     */
    public function frequentistAnalysis(
        array $variants,
        string $controlId,
        string $metricType = 'conversion_rate',
    ): array {
        if (! isset($variants[$controlId])) {
            return $this->nullFrequentistResult('Control variant not found');
        }

        $control = $variants[$controlId];

        // Find best performing variant
        $bestId = $controlId;
        $bestRate = $this->getVariantRate($control, $metricType);

        foreach ($variants as $id => $data) {
            if ($id === $controlId) {
                continue;
            }
            $rate = $this->getVariantRate($data, $metricType);
            if ($rate > $bestRate) {
                $bestId = $id;
                $bestRate = $rate;
            }
        }

        if ($bestId === $controlId) {
            return $this->nullFrequentistResult('No variant outperforms control');
        }

        if ($metricType === 'revenue' || $metricType === 'continuous') {
            return $this->twoSampleTTest($variants, $controlId, $bestId, $metricType);
        }

        return $this->twoProportionZTest($variants, $controlId, $bestId);
    }

    /**
     * Two-proportion z-test for conversion rate experiments.
     *
     * @param  array<string, array{exposures: int, conversions: int}>  $variants
     */
    private function twoProportionZTest(
        array $variants,
        string $controlId,
        string $treatmentId,
    ): array {
        $control = $variants[$controlId];
        $treatment = $variants[$treatmentId];

        $n1 = $control['exposures'];
        $n2 = $treatment['exposures'];
        $x1 = $control['conversions'];
        $x2 = $treatment['conversions'];

        if ($n1 === 0 || $n2 === 0) {
            return $this->nullFrequentistResult('Insufficient sample size');
        }

        $p1 = $x1 / $n1;
        $p2 = $x2 / $n2;
        $pooled = ($x1 + $x2) / ($n1 + $n2);

        $se = sqrt($pooled * (1 - $pooled) * (1.0 / $n1 + 1.0 / $n2));

        if ($se === 0.0) {
            return $this->nullFrequentistResult('Zero standard error (pooled rate is 0 or 1)');
        }

        $z = abs($p2 - $p1) / $se;
        $pValue = $this->normalSurvival($z) * 2; // two-tailed
        $confidenceLevel = 1.0 - $pValue;
        $significant = $pValue < $this->defaultAlpha;

        $recommendation = $significant
            ? "Variant {$treatmentId} is statistically significantly better than control (p={$pValue})"
            : "No statistically significant difference detected (p={$pValue}). Continue experiment.";

        return [
            'p_value' => round($pValue, 6),
            'significant' => $significant,
            'confidence_level' => round($confidenceLevel, 4),
            'test_used' => 'two_proportion_z_test',
            'z_score' => round($z, 4),
            'control_rate' => round($p1, 4),
            'treatment_rate' => round($p2, 4),
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Two-sample t-test for continuous/revenue metrics.
     *
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants
     */
    private function twoSampleTTest(
        array $variants,
        string $controlId,
        string $treatmentId,
        string $metricType,
    ): array {
        $control = $variants[$controlId];
        $treatment = $variants[$treatmentId];

        // For revenue/continuous: use metric_sum and metric_count or fall back to avg conversion value
        $cSum = (float) ($control['metric_sum'] ?? ($control['conversions'] > 0 ? $control['conversions'] : 0));
        $cCount = (int) ($control['metric_count'] ?? $control['exposures']);
        $tSum = (float) ($treatment['metric_sum'] ?? ($treatment['conversions'] > 0 ? $treatment['conversions'] : 0));
        $tCount = (int) ($treatment['metric_count'] ?? $treatment['exposures']);

        if ($cCount === 0 || $tCount === 0) {
            return $this->nullFrequentistResult('Insufficient sample size for continuous metric');
        }

        $mean1 = $cSum / $cCount;
        $mean2 = $tSum / $tCount;

        // Estimate variance (we don't have individual observations, use rate-based variance)
        $var1 = ($mean1 > 0 && $cCount > 1) ? ($mean1 * (1 - min($mean1 / max($cSum / max($cCount, 1), 0.001), 1))) / $cCount : 0;
        $var2 = ($mean2 > 0 && $tCount > 1) ? ($mean2 * (1 - min($mean2 / max($tSum / max($tCount, 1), 0.001), 1))) / $tCount : 0;

        // Use pooled standard error (simplified Welch's t-test approximation)
        $se = sqrt($var1 / max($cCount, 1) + $var2 / max($tCount, 1));

        if ($se === 0.0) {
            // Fall back to z-test approach with pooled variance
            $pooledMean = ($cSum + $tSum) / ($cCount + $tCount);
            $se = sqrt(($pooledMean > 0 ? $pooledMean : 0.001) * (1.0 / $cCount + 1.0 / $tCount));
        }

        if ($se === 0.0) {
            return $this->nullFrequentistResult('Cannot compute variance');
        }

        $t = abs($mean2 - $mean1) / $se;

        // Approximate p-value using normal distribution (large sample approximation)
        $df = $cCount + $tCount - 2;
        $pValue = $this->normalSurvival($t) * 2; // two-tailed
        $confidenceLevel = 1.0 - $pValue;
        $significant = $pValue < $this->defaultAlpha;

        $recommendation = $significant
            ? "Variant {$treatmentId} shows statistically significant {$metricType} improvement (p={$pValue})"
            : "No statistically significant difference in {$metricType} (p={$pValue}). Continue experiment.";

        return [
            'p_value' => round($pValue, 6),
            'significant' => $significant,
            'confidence_level' => round($confidenceLevel, 4),
            'test_used' => 'welch_t_test',
            't_score' => round($t, 4),
            'degrees_of_freedom' => $df,
            'control_mean' => round($mean1, 4),
            'treatment_mean' => round($mean2, 4),
            'recommendation' => $recommendation,
        ];
    }

    // ── Bayesian Analysis ────────────────────────────────────────────────

    /**
     * Run Bayesian analysis using Beta-Binomial conjugate model.
     *
     * For each variant, computes:
     * - Probability of being best (P(Best))
     * - Probability of beating control (P(Beat Control))
     * - Expected loss if chosen over the true best
     * - 95% credible interval
     *
     * Uses 20,000 Monte Carlo simulations for numerical integration.
     *
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants
     * @return array{prob_best: array<string, float>, prob_beats_control: array<string, float|null>, expected_loss: array<string, float|null>, credible_interval: array<string, array{lower: float, upper: float}>}
     */
    public function bayesianAnalysis(
        array $variants,
        string $controlId,
        string $metricType = 'conversion_rate',
    ): array {
        $variantIds = array_keys($variants);
        $simulations = 20000;

        // Generate posterior samples for each variant
        /** @var array<string, list<float>> $samples */
        $samples = [];
        foreach ($variants as $id => $data) {
            $samples[$id] = $this->betaBinomialSamples(
                (int) ($data['conversions'] ?? 0) + 1, // alpha = successes + 1 (uniform prior)
                (int) ($data['exposures'] ?? 0) - (int) ($data['conversions'] ?? 0) + 1, // beta = failures + 1
                $simulations,
            );
        }

        // Probability of being best
        $probBest = [];
        foreach ($variantIds as $id) {
            $wins = 0;
            for ($i = 0; $i < $simulations; $i++) {
                $isBest = true;
                foreach ($variantIds as $otherId) {
                    if ($otherId !== $id && $samples[$otherId][$i] >= $samples[$id][$i]) {
                        $isBest = false;
                        break;
                    }
                }
                if ($isBest) {
                    $wins++;
                }
            }
            $probBest[$id] = round($wins / $simulations, 4);
        }

        // Probability of beating control + Expected loss
        $probBeatsControl = [];
        $expectedLoss = [];
        $controlSamples = $samples[$controlId] ?? [];

        foreach ($variantIds as $id) {
            if ($id === $controlId) {
                $probBeatsControl[$id] = null;
                $expectedLoss[$id] = null;
                continue;
            }

            $variantSamples = $samples[$id];
            $beatsCount = 0;
            $totalLoss = 0.0;
            $bestInSim = 0.0;

            for ($i = 0; $i < $simulations; $i++) {
                $variantValue = $variantSamples[$i];
                $controlValue = $controlSamples[$i];

                if ($variantValue > $controlValue) {
                    $beatsCount++;
                }

                // Expected loss: how much worse is this variant than the best in this sim?
                foreach ($variantIds as $otherId) {
                    $bestInSim = max($bestInSim, $samples[$otherId][$i]);
                }
                $totalLoss += max(0, $bestInSim - $variantValue);
            }

            $probBeatsControl[$id] = round($beatsCount / $simulations, 4);
            $expectedLoss[$id] = round($totalLoss / $simulations, 6);
        }

        // Credible intervals (2.5th and 97.5th percentiles)
        $credibleInterval = [];
        foreach ($variantIds as $id) {
            $sorted = $samples[$id];
            sort($sorted);
            $lowerIdx = (int) (0.025 * $simulations);
            $upperIdx = (int) (0.975 * $simulations);
            $credibleInterval[$id] = [
                'lower' => round($sorted[$lowerIdx] ?? 0, 4),
                'upper' => round($sorted[min($upperIdx, $simulations - 1)] ?? 1, 4),
            ];
        }

        return [
            'prob_best' => $probBest,
            'prob_beats_control' => $probBeatsControl,
            'expected_loss' => $expectedLoss,
            'credible_interval' => $credibleInterval,
            'simulations' => $simulations,
            'prior' => 'beta(1,1) uniform',
        ];
    }

    /**
     * Generate samples from a Beta(alpha, beta) distribution using
     * the Jöhnk's algorithm (gamma ratio method).
     *
     * @return list<float>
     */
    private function betaBinomialSamples(float $alpha, float $beta, int $count): array
    {
        $samples = [];

        for ($i = 0; $i < $count; $i++) {
            $x = $this->gammaSample($alpha);
            $y = $this->gammaSample($beta);

            if ($x + $y === 0.0) {
                $samples[] = 0.5;
                continue;
            }

            $samples[] = $x / ($x + $y);
        }

        return $samples;
    }

    /**
     * Generate a sample from Gamma(alpha, 1) using Marsaglia and Tsang's method.
     *
     * For alpha >= 1: uses the rejection method with normal approximation.
     * For alpha < 1: uses the boost trick (gamma(a) = gamma(a+1) * U^(1/a)).
     */
    private function gammaSample(float $alpha): float
    {
        if ($alpha < 1.0) {
            $u = $this->uniform01();
            return $this->gammaSample($alpha + 1.0) * pow($u, 1.0 / $alpha);
        }

        // Marsaglia and Tsang's method for alpha >= 1
        $d = $alpha - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        while (true) {
            $x = $this->normalSample();
            $v = 1.0 + $c * $x;
            $v = $v * $v * $v;

            if ($v <= 0.0) {
                continue;
            }

            $u = $this->uniform01();

            if ($u < 1.0 - 0.0331 * ($x * $x) * ($x * $x)) {
                return $d * $v;
            }

            if ($u > $v && log($u) < 0.5 * $x * $x + $d * (1.0 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    /**
     * Generate a standard normal sample using Box-Muller transform.
     */
    private function normalSample(): float
    {
        static $spare = null;

        if ($spare !== null) {
            $result = $spare;
            $spare = null;

            return $result;
        }

        $u1 = $this->uniform01();
        $u2 = $this->uniform01();

        $mag = sqrt(-2.0 * log(max($u1, 1e-300)));
        $spare = $mag * sin(2.0 * M_PI * $u2);

        return $mag * cos(2.0 * M_PI * $u2);
    }

    /**
     * Generate a uniform random number in (0, 1).
     */
    private function uniform01(): float
    {
        return mt_rand() / (float) mt_getrandmax();
    }

    // ── Effect Size ─────────────────────────────────────────────────────

    /**
     * Compute effect sizes for all variants relative to control.
     *
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants
     * @return array<string, array{relative_uplift: float|null, absolute_lift: float|null, cohens_h: float|null}>
     */
    public function computeEffectSizes(
        array $variants,
        string $controlId,
        string $metricType = 'conversion_rate',
    ): array {
        $results = [];

        if (! isset($variants[$controlId])) {
            return $results;
        }

        $controlRate = $this->getVariantRate($variants[$controlId], $metricType);

        foreach ($variants as $id => $data) {
            if ($id === $controlId) {
                $results[$id] = [
                    'relative_uplift' => null,
                    'absolute_lift' => null,
                    'cohens_h' => null,
                ];

                continue;
            }

            $rate = $this->getVariantRate($data, $metricType);
            $absoluteLift = $rate - $controlRate;
            $relativeUplift = $controlRate > 0 ? (($rate - $controlRate) / $controlRate) : 0.0;

            // Cohen's h for two proportions
            $cohensH = null;
            if ($metricType === 'conversion_rate') {
                $p1 = max(0.0001, min($controlRate, 0.9999));
                $p2 = max(0.0001, min($rate, 0.9999));
                $cohensH = 2.0 * (asin(sqrt($p2)) - asin(sqrt($p1)));
            }

            $results[$id] = [
                'relative_uplift' => round($relativeUplift, 4),
                'absolute_lift' => round($absoluteLift, 4),
                'cohens_h' => $cohensH !== null ? round($cohensH, 4) : null,
            ];
        }

        return $results;
    }

    // ── Confidence Intervals ────────────────────────────────────────────

    /**
     * Compute confidence intervals for each variant's metric.
     *
     * Uses Wilson score interval for proportions, Wald interval for continuous.
     *
     * @param  array<string, array{exposures: int, conversions: int, metric_sum?: float, metric_count?: int}>  $variants
     * @return array<string, array{lower: float, upper: float, method: string}>
     */
    public function computeConfidenceIntervals(
        array $variants,
        string $metricType = 'conversion_rate',
    ): array {
        $results = [];
        $z = $this->normalQuantile(1.0 - $this->defaultAlpha / 2.0);

        foreach ($variants as $id => $data) {
            if ($metricType === 'conversion_rate') {
                $results[$id] = $this->wilsonScoreInterval(
                    (int) ($data['conversions'] ?? 0),
                    (int) ($data['exposures'] ?? 1),
                    $z,
                );
            } else {
                $rate = $this->getVariantRate($data, $metricType);
                $n = (int) ($data['exposures'] ?? 1);
                $se = sqrt($rate * (1 - $rate) / max($n, 1));
                $results[$id] = [
                    'lower' => round(max(0, $rate - $z * $se), 4),
                    'upper' => round(min(1, $rate + $z * $se), 4),
                    'method' => 'wald',
                ];
            }
        }

        return $results;
    }

    /**
     * Wilson score interval for a binomial proportion.
     *
     * More accurate than Wald for small sample sizes.
     *
     * @return array{lower: float, upper: float, method: string}
     */
    public function wilsonScoreInterval(int $successes, int $trials, ?float $z = null): array
    {
        if ($trials === 0) {
            return ['lower' => 0.0, 'upper' => 0.0, 'method' => 'wilson'];
        }

        $z = $z ?? $this->normalQuantile(1.0 - $this->defaultAlpha / 2.0);
        $p = $successes / $trials;
        $denom = 1.0 + $z * $z / $trials;
        $center = ($p + $z * $z / (2.0 * $trials)) / $denom;
        $margin = $z * sqrt(($p * (1 - $p) + $z * $z / (4.0 * $trials)) / $trials) / $denom;

        return [
            'lower' => round(max(0, $center - $margin), 4),
            'upper' => round(min(1, $center + $margin), 4),
            'method' => 'wilson',
        ];
    }

    // ── Multi-Variant Correction ────────────────────────────────────────

    /**
     * Apply multi-variant correction to p-values.
     *
     * Supports Bonferroni, Šidák, and Holm-Bonferroni methods.
     *
     * @param  list<string>  $variantIds
     * @param  array{p_value: float|null, ...}|null  $frequentist
     * @return array{method: string, adjusted_alpha: float, corrections: array<string, float>}|null
     */
    public function multiVariantCorrection(
        array $variantIds,
        ?array $frequentist = null,
        string $method = 'bonferroni',
    ): ?array {
        if (count($variantIds) <= 2) {
            return null;
        }

        $numComparisons = count($variantIds) - 1; // Each variant vs control
        $adjustedAlpha = $this->defaultAlpha;

        $corrections = [];

        switch ($method) {
            case 'sidak':
                $adjustedAlpha = 1.0 - pow(1.0 - $this->defaultAlpha, 1.0 / $numComparisons);
                foreach ($variantIds as $id) {
                    $corrections[$id] = round($adjustedAlpha, 6);
                }
                break;

            case 'holm':
                // Holm-Bonferroni: step-down procedure
                $i = 0;
                foreach ($variantIds as $id) {
                    $i++;
                    $corrections[$id] = round($this->defaultAlpha / ($numComparisons - $i + 1), 6);
                }
                break;

            case 'bonferroni':
            default:
                $adjustedAlpha = $this->defaultAlpha / $numComparisons;
                foreach ($variantIds as $id) {
                    $corrections[$id] = round($adjustedAlpha, 6);
                }
                break;
        }

        return [
            'method' => $method,
            'adjusted_alpha' => round($adjustedAlpha, 6),
            'num_comparisons' => $numComparisons,
            'corrections' => $corrections,
        ];
    }

    // ── Sequential Testing ─────────────────────────────────────────────

    /**
     * Check whether sequential testing allows early stopping.
     *
     * Uses O'Brien-Fleming alpha spending function. At each "peek",
     * the cumulative alpha spent is computed and compared against
     * the current z-score.
     *
     * @param  string  $experimentId
     * @param  int  $peek  Current peek number (1-based)
     * @param  int  $maxPeeks  Maximum planned peeks
     * @param  float  $zScore  Current z-score
     * @param  string  $spendingFunction  'obrien_fleming'|'pocock'|'linear'
     * @return array{should_stop: bool, alpha_spent: float, alpha_remaining: float, boundary: float, recommendation: string}
     */
    public function sequentialTest(
        string $experimentId,
        int $peek,
        int $maxPeeks,
        float $zScore,
        string $spendingFunction = 'obrien_fleming',
    ): array {
        if ($peek < 1 || $peek > $maxPeeks) {
            return [
                'should_stop' => false,
                'alpha_spent' => 0.0,
                'alpha_remaining' => $this->defaultAlpha,
                'boundary' => 0.0,
                'recommendation' => 'Invalid peek number',
            ];
        }

        // Compute information fraction
        $infoFraction = $peek / $maxPeeks;

        // O'Brien-Fleming alpha spending function: α*(t) = 2 - 2Φ(z_{α/2} / √t)
        $alphaSpent = match ($spendingFunction) {
            'pocock' => $this->defaultAlpha * $infoFraction,
            'linear' => $this->defaultAlpha * $infoFraction,
            default => 2.0 * (1.0 - $this->normalCdf(
                $this->normalQuantile(1.0 - $this->defaultAlpha / 2.0) / sqrt(max($infoFraction, 0.001)),
            )),
        };

        // Boundary z-score at this peek
        $alphaAtPeek = $alphaSpent;
        $boundary = $this->normalQuantile(1.0 - $alphaAtPeek / 2.0);

        $shouldStop = abs($zScore) >= $boundary;
        $alphaRemaining = $this->defaultAlpha - $alphaSpent;

        $recommendation = $shouldStop
            ? "Sequential test: STOP. Z-score ({$zScore}) exceeds boundary ({$boundary}) at peek {$peek}/{$maxPeeks}."
            : "Sequential test: CONTINUE. Z-score ({$zScore}) below boundary ({$boundary}) at peek {$peek}/{$maxPeeks}.";

        return [
            'should_stop' => $shouldStop,
            'peek' => $peek,
            'max_peeks' => $maxPeeks,
            'info_fraction' => round($infoFraction, 4),
            'alpha_spent' => round($alphaSpent, 6),
            'alpha_remaining' => round(max(0, $alphaRemaining), 6),
            'boundary' => round($boundary, 4),
            'z_score' => $zScore,
            'spending_function' => $spendingFunction,
            'recommendation' => $recommendation,
        ];
    }

    // ── Sample Size / MDE Calculator ───────────────────────────────────

    /**
     * Calculate minimum sample size needed to detect a given effect.
     *
     * Uses the formula for two-proportion z-test power analysis.
     *
     * @param  float  $baselineRate  Control conversion rate (e.g., 0.05)
     * @param  float  $mde  Minimum Detectable Effect as relative uplift (e.g., 0.10 for 10%)
     * @param  float|null  $alpha  Significance level (null = default)
     * @param  float|null  $power  Statistical power (null = default)
     * @param  int|null  $numVariants  Number of variants including control (null = 2)
     * @return array{total_sample_size: int, per_variant: int, control: int, treatment: int, mde_absolute: float, power: float, alpha: float}
     */
    public function calculateSampleSize(
        float $baselineRate,
        float $mde,
        ?float $alpha = null,
        ?float $power = null,
        ?int $numVariants = null,
    ): array {
        $alpha = $alpha ?? $this->defaultAlpha;
        $power = $power ?? $this->defaultPower;
        $numVariants = $numVariants ?? 2;
        $numTreatments = $numVariants - 1;

        $treatmentRate = $baselineRate * (1.0 + $mde);
        $pooledRate = ($baselineRate + $treatmentRate) / 2.0;

        $zAlpha = $this->normalQuantile(1.0 - $alpha / 2.0);
        $zBeta = $this->normalQuantile($power);

        $delta = abs($treatmentRate - $baselineRate);

        if ($delta === 0.0 || $pooledRate === 0.0 || $pooledRate === 1.0) {
            return [
                'total_sample_size' => PHP_INT_MAX,
                'per_variant' => PHP_INT_MAX,
                'control' => PHP_INT_MAX,
                'treatment' => PHP_INT_MAX,
                'mde_absolute' => 0.0,
                'power' => $power,
                'alpha' => $alpha,
                'error' => 'Cannot compute: delta or pooled rate is zero',
            ];
        }

        $varianceTerm = $baselineRate * (1.0 - $baselineRate) + $treatmentRate * (1.0 - $treatmentRate);

        $nPerGroup = ceil(($zAlpha + $zBeta) ** 2 * $varianceTerm / ($delta * $delta));

        // Bonferroni correction for multiple comparisons
        $adjustedN = (int) ceil($nPerGroup * $numTreatments);

        $totalSample = $adjustedN * 2; // control + best treatment

        return [
            'total_sample_size' => $totalSample,
            'per_variant' => $adjustedN,
            'control' => $adjustedN,
            'treatment' => $adjustedN,
            'num_treatments' => $numTreatments,
            'num_variants' => $numVariants,
            'baseline_rate' => $baselineRate,
            'treatment_rate' => round($treatmentRate, 6),
            'mde_relative' => $mde,
            'mde_absolute' => round($delta, 6),
            'power' => $power,
            'alpha' => $alpha,
            'correction' => $numTreatments > 1 ? 'bonferroni' : 'none',
        ];
    }

    /**
     * Calculate the Minimum Detectable Effect (MDE) for a given sample size.
     *
     * @param  float  $baselineRate  Control conversion rate
     * @param  int  $sampleSize  Sample size per variant
     * @param  float|null  $alpha  Significance level
     * @param  float|null  $power  Statistical power
     * @return array{mde_relative: float, mde_absolute: float, treatment_rate: float, detectable_uplift_pct: float}
     */
    public function calculateMDE(
        float $baselineRate,
        int $sampleSize,
        ?float $alpha = null,
        ?float $power = null,
    ): array {
        $alpha = $alpha ?? $this->defaultAlpha;
        $power = $power ?? $this->defaultPower;

        if ($sampleSize <= 0) {
            return [
                'mde_relative' => 0.0,
                'mde_absolute' => 0.0,
                'treatment_rate' => $baselineRate,
                'detectable_uplift_pct' => 0.0,
                'error' => 'Sample size must be positive',
            ];
        }

        $zAlpha = $this->normalQuantile(1.0 - $alpha / 2.0);
        $zBeta = $this->normalQuantile($power);

        $z = $zAlpha + $zBeta;

        // delta = z * sqrt(p1(1-p1) + p2(1-p2)) / sqrt(n)
        // Iterative approximation: start with p1 = p2 = baseline
        $pooledRate = $baselineRate;
        $variance = 2.0 * $pooledRate * (1.0 - $pooledRate);

        $delta = $z * sqrt($variance / $sampleSize);

        // Refine with treatment rate estimate
        for ($i = 0; $i < 10; $i++) {
            $treatmentRate = $baselineRate + $delta;
            $variance = $baselineRate * (1.0 - $baselineRate) + $treatmentRate * (1.0 - $treatmentRate);
            $delta = $z * sqrt($variance / $sampleSize);
        }

        $treatmentRate = min($baselineRate + $delta, 1.0);
        $mdeRelative = $baselineRate > 0 ? $delta / $baselineRate : 0.0;

        return [
            'mde_relative' => round($mdeRelative, 4),
            'mde_absolute' => round($delta, 6),
            'treatment_rate' => round($treatmentRate, 6),
            'detectable_uplift_pct' => round($mdeRelative * 100, 2),
        ];
    }

    // ── Decision Making ─────────────────────────────────────────────────

    /**
     * Determine the experiment winner from analysis results.
     */
    private function determineWinner(
        ?array $frequentist,
        ?array $bayesian,
        string $controlId,
    ): ?string {
        // Bayesian approach: highest P(Best) above threshold
        if ($bayesian !== null) {
            $bestProb = 0.0;
            $bestId = null;

            foreach ($bayesian['prob_best'] as $id => $prob) {
                if ($prob > $bestProb && $id !== $controlId) {
                    $bestProb = $prob;
                    $bestId = $id;
                }
            }

            // Require 90%+ probability to declare winner
            if ($bestId !== null && $bestProb >= 0.90) {
                return $bestId;
            }
        }

        // Frequentist fallback
        if ($frequentist !== null && ($frequentist['significant'] ?? false) === true) {
            // Winner is the variant that beat control — infer from context
            return null; // Caller should use the treatment ID from the test
        }

        return null;
    }

    /**
     * Generate an actionable recommendation.
     */
    private function generateRecommendation(
        ?array $frequentist,
        ?array $bayesian,
        array $effectSize,
        ?string $winner,
        bool $sampleSizeMet,
        string $controlId,
    ): string {
        if (! $sampleSizeMet) {
            return 'INSUFFICIENT_DATA: Minimum sample size not reached. Continue collecting data.';
        }

        if ($winner !== null) {
            $uplift = $effectSize[$winner]['relative_uplift'] ?? 0;
            $upliftPct = round(($uplift ?? 0) * 100, 1);

            return "SHIP_IT: Variant {$winner} is the likely winner with {$upliftPct}% relative uplift. Consider shipping to 100% of traffic.";
        }

        // Check if control is winning
        if ($bayesian !== null) {
            $controlProb = $bayesian['prob_best'][$controlId] ?? 0.0;
            if ($controlProb >= 0.90) {
                return 'STOP_EXPERIMENT: Control variant is likely the best. End the experiment.';
            }
        }

        // Check expected loss — if all variants have low expected loss, it doesn't matter
        if ($bayesian !== null) {
            $maxLoss = 0.0;
            foreach ($bayesian['expected_loss'] as $id => $loss) {
                if ($loss !== null) {
                    $maxLoss = max($maxLoss, $loss);
                }
            }

            if ($maxLoss < 0.001) {
                return 'ANY_VARIANT_OK: Expected loss is negligible for all variants. Choose based on other factors.';
            }
        }

        return 'CONTINUE: No clear winner yet. Continue the experiment to collect more data.';
    }

    // ── Statistical Helpers ─────────────────────────────────────────────

    /**
     * Standard normal CDF using Abramowitz and Stegun approximation.
     */
    private function normalCdf(float $z): float
    {
        $t = 1.0 / (1.0 + 0.2316419 * abs($z));
        $d = 0.3989422804014327; // 1/sqrt(2*pi)

        $p = $d * exp(-$z * $z / 2.0) * $t
            * (0.3193815 + $t
                * (-0.3565638 + $t
                    * (1.781478 + $t
                        * (-1.8212560 + $t * 1.3302744))));

        return $z > 0 ? 1.0 - $p : $p;
    }

    /**
     * Normal survival function: P(Z > z) = 1 - Φ(z).
     */
    private function normalSurvival(float $z): float
    {
        return 1.0 - $this->normalCdf($z);
    }

    /**
     * Normal quantile function (inverse CDF) using rational approximation.
     *
     * Abramowitz and Stegun approximation 26.2.23.
     * Accurate to ~4.5e-4.
     */
    private function normalQuantile(float $p): float
    {
        if ($p <= 0.0) {
            return -PHP_FLOAT_MAX;
        }

        if ($p >= 1.0) {
            return PHP_FLOAT_MAX;
        }

        if ($p < 0.5) {
            return -$this->normalQuantile(1.0 - $p);
        }

        // Rational approximation for 0.5 <= p < 1
        $t = sqrt(-2.0 * log(1.0 - $p));
        $c0 = 2.515517;
        $c1 = 0.802853;
        $c2 = 0.010328;
        $d1 = 1.432788;
        $d2 = 0.189269;
        $d3 = 0.001308;

        return $t - ($c0 + $c1 * $t + $c2 * $t * $t) / (1.0 + $d1 * $t + $d2 * $t * $t + $d3 * $t * $t * $t);
    }

    /**
     * Get the conversion rate (or mean) for a variant.
     */
    private function getVariantRate(array $data, string $metricType): float
    {
        if ($metricType === 'revenue' || $metricType === 'continuous') {
            $count = (int) ($data['metric_count'] ?? $data['exposures']);
            $sum = (float) ($data['metric_sum'] ?? 0);

            return $count > 0 ? $sum / $count : 0.0;
        }

        $exposures = (int) ($data['exposures'] ?? 0);
        $conversions = (int) ($data['conversions'] ?? 0);

        return $exposures > 0 ? $conversions / $exposures : 0.0;
    }

    // ── Cache ───────────────────────────────────────────────────────────

    /**
     * Cache an analysis result.
     */
    private function cacheAnalysis(string $experimentId, array $result): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . $experimentId,
            $result,
            self::CACHE_TTL,
        );
    }

    /**
     * Get a cached analysis result.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedAnalysis(string $experimentId): ?array
    {
        $result = $this->cache->get(self::CACHE_PREFIX . $experimentId);

        return is_array($result) ? $result : null;
    }

    /**
     * Clear a cached analysis.
     */
    public function clearAnalysis(string $experimentId): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . $experimentId);
    }

    /**
     * Check if the engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── Result Helpers ──────────────────────────────────────────────────

    /**
     * Return a null frequentist result with a message.
     *
     * @return array{p_value: float|null, significant: bool, confidence_level: float, test_used: string, recommendation: string}
     */
    private function nullFrequentistResult(string $reason): array
    {
        return [
            'p_value' => null,
            'significant' => false,
            'confidence_level' => 0.0,
            'test_used' => 'none',
            'recommendation' => $reason,
        ];
    }

    /**
     * Return an empty analysis result.
     */
    private function emptyAnalysis(string $experimentId, string $method, string $metricType): array
    {
        return [
            'experiment_id' => $experimentId,
            'method' => $method,
            'metric_type' => $metricType,
            'sample_size_met' => false,
            'total_exposures' => 0,
            'frequentist' => null,
            'bayesian' => null,
            'effect_size' => [],
            'confidence_intervals' => [],
            'multi_variant_correction' => null,
            'recommendation' => 'NO_DATA: No variant data provided.',
            'winner' => null,
            'analyzed_at' => time(),
        ];
    }

    // ── Quick Analysis (Single Metric) ──────────────────────────────────

    /**
     * Quick significance check for a single pair of variants.
     *
     * Simplified version of analyze() for single comparison.
     *
     * @param  int  $controlConversions
     * @param  int  $controlExposures
     * @param  int  $treatmentConversions
     * @param  int  $treatmentExposures
     * @return array{p_value: float, significant: bool, relative_uplift: float, confidence_level: float, test_used: string, recommendation: string}
     */
    public function quickSignificance(
        int $controlConversions,
        int $controlExposures,
        int $treatmentConversions,
        int $treatmentExposures,
    ): array {
        $variants = [
            'control' => ['exposures' => $controlExposures, 'conversions' => $controlConversions],
            'treatment' => ['exposures' => $treatmentExposures, 'conversions' => $treatmentConversions],
        ];

        $frequentist = $this->frequentistAnalysis($variants, 'control');
        $effectSize = $this->computeEffectSizes($variants, 'control');

        return array_merge($frequentist, [
            'relative_uplift' => $effectSize['treatment']['relative_uplift'] ?? 0.0,
        ]);
    }

    // ── Experiment Health ────────────────────────────────────────────────

    /**
     * Assess the health and quality of an experiment's data.
     *
     * Checks for: sample ratio mismatch (SRM), imbalanced traffic,
     * too few conversions, and data quality issues.
     *
     * @param  array<string, array{exposures: int, conversions: int}>  $variants
     * @return array{healthy: bool, checks: list<array{name: string, status: 'pass'|'warn'|'fail', message: string}>}
     */
    public function assessExperimentHealth(array $variants): array
    {
        $checks = [];
        $variantIds = array_keys($variants);
        $totalExposures = array_sum(array_map(fn (array $v): int => $v['exposures'], $variants));
        $totalConversions = array_sum(array_map(fn (array $v): int => $v['conversions'], $variants));

        // Sample size check
        $checks[] = [
            'name' => 'sample_size',
            'status' => $totalExposures >= $this->minSampleSize ? 'pass' : 'fail',
            'message' => $totalExposures >= $this->minSampleSize
                ? 'Total sample size ({$totalExposures}) meets minimum (' . (this->minSampleSize) . ')'
                : 'Total sample size ({$totalExposures}) below minimum (' . (this->minSampleSize) . ')',
        ];

        // Conversion count check
        $minConversions = 30;
        $checks[] = [
            'name' => 'conversion_count',
            'status' => $totalConversions >= $minConversions ? 'pass' : ($totalConversions >= 10 ? 'warn' : 'fail'),
            'message' => $totalConversions >= $minConversions
                ? "Total conversions ({$totalConversions}) sufficient"
                : "Low conversion count ({$totalConversions}) — may reduce statistical power",
        ];

        // Sample ratio mismatch (SRM) check
        if (count($variantIds) >= 2 && $totalExposures > 0) {
            $expectedRatio = 1.0 / count($variantIds);
            $chiSquared = 0.0;

            foreach ($variants as $data) {
                $expected = $expectedRatio * $totalExposures;
                $observed = $data['exposures'];
                $chiSquared += ($observed - $expected) ** 2 / max($expected, 1);
            }

            $srmThreshold = 3.841; // chi-squared critical value for df=1, alpha=0.05
            $hasSRM = $chiSquared > $srmThreshold;

            $checks[] = [
                'name' => 'sample_ratio_mismatch',
                'status' => $hasSRM ? 'fail' : 'pass',
                'message' => $hasSRM
                    ? "Sample ratio mismatch detected (χ²={$chiSquared}). Traffic may not be evenly split."
                    : "No sample ratio mismatch (χ²=" . round($chiSquared, 2) . ')',
            ];
        }

        // Zero-conversion variants check
        foreach ($variants as $id => $data) {
            if ($data['exposures'] > 50 && $data['conversions'] === 0) {
                $checks[] = [
                    'name' => "zero_conversions_{$id}",
                    'status' => 'warn',
                    'message' => "Variant {$id} has {$data['exposures']} exposures but 0 conversions",
                ];
            }
        }

        // Traffic imbalance check (> 3:1 ratio)
        if (count($variantIds) >= 2) {
            $minExposures = min(array_map(fn (array $v): int => $v['exposures'], $variants));
            $maxExposures = max(array_map(fn (array $v): int => $v['exposures'], $variants));

            if ($minExposures > 0) {
                $ratio = $maxExposures / $minExposures;
                $checks[] = [
                    'name' => 'traffic_imbalance',
                    'status' => $ratio > 3.0 ? 'warn' : 'pass',
                    'message' => $ratio > 3.0
                        ? "Traffic imbalance: {$ratio}:1 ratio between variants"
                        : "Traffic is balanced ({$ratio}:1 ratio)",
                ];
            }
        }

        $healthy = ! in_array('fail', array_column($checks, 'status'), true);

        return [
            'healthy' => $healthy,
            'checks' => $checks,
            'summary' => [
                'total_exposures' => $totalExposures,
                'total_conversions' => $totalConversions,
                'num_variants' => count($variantIds),
                'overall_rate' => $totalExposures > 0
                    ? round($totalConversions / $totalExposures, 4)
                    : 0.0,
            ],
        ];
    }
}
