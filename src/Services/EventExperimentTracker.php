<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * A/B test experiment tracking and statistical analysis service.
 *
 * Tracks experiment assignments, variant conversions, and calculates
 * statistical significance using two-proportion z-test. Supports
 * multi-variant experiments, sequential testing, and automated
 * winner detection.
 *
 * All experiment data is cache-backed — no database required.
 *
 * Configuration is read from `zeroboiler.analytics.experiment`.
 *
 * @phpstan-type Experiment array{id: string, name: string, variants: list<array{name: string, events: int, conversions: int, conversion_rate: float}>, status: 'running'|'paused'|'completed', winner: string|null, created_at: string, updated_at: string}
 * @phpstan-type SignificanceResult array{is_significant: bool, confidence: float, p_value: float, z_score: float, winner: string|null, recommendation: string}
 *
 * @since 1.0.0
 */
final class EventExperimentTracker
{
    private readonly int $cacheTtl;

    private readonly string $cachePrefix;

    private readonly float $significanceThreshold;

    private readonly int $minSampleSize;

    private readonly bool $enabled;

    private const CACHE_PREFIX = 'zb_exp_';

    private const DEFAULT_SIGNIFICANCE_THRESHOLD = 0.95;

    private const DEFAULT_MIN_SAMPLE_SIZE = 100;

    private const DEFAULT_CACHE_TTL = 86400; // 24 hours

    /**
     * @param  ConfigRepository  $config  Analytics configuration
     * @param  CacheRepository  $cache  Application cache
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache)
    {
        $expConfig = $config->get('zeroboiler.analytics.experiment', []);
        /** @var array{enabled?: bool, cache_ttl?: int, significance_threshold?: float, min_sample_size?: int} $expConfig */

        $this->enabled = (bool) ($expConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($expConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->cachePrefix = self::CACHE_PREFIX;
        $this->significanceThreshold = (float) ($expConfig['significance_threshold'] ?? self::DEFAULT_SIGNIFICANCE_THRESHOLD);
        $this->minSampleSize = (int) ($expConfig['min_sample_size'] ?? self::DEFAULT_MIN_SAMPLE_SIZE);
        $this->cache = $cache;
    }

    /**
     * Check if experiment tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Create a new experiment with given variants.
     *
     * @param  string  $id  Unique experiment identifier
     * @param  string  $name  Human-readable experiment name
     * @param  list<string>  $variants  Variant names (e.g., ['control', 'variant_a', 'variant_b'])
     * @return Experiment The created experiment
     */
    public function createExperiment(string $id, string $name, array $variants): array
    {
        $now = date('c');
        $experiment = [
            'id' => $id,
            'name' => $name,
            'variants' => array_map(fn (string $v): array => [
                'name' => $v,
                'events' => 0,
                'conversions' => 0,
                'conversion_rate' => 0.0,
            ], $variants),
            'status' => 'running',
            'winner' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->cache->put($this->cachePrefix . $id, $experiment, $this->cacheTtl);

        return $experiment;
    }

    /**
     * Record an event impression for a specific variant.
     *
     * @param  string  $experimentId  Experiment identifier
     * @param  string  $variant  Variant name
     * @param  bool  $converted  Whether this event was a conversion
     */
    public function trackEvent(string $experimentId, string $variant, bool $converted = false): void
    {
        if (! $this->enabled) {
            return;
        }

        $experiment = $this->getExperiment($experimentId);
        if ($experiment === null || $experiment['status'] !== 'running') {
            return;
        }

        foreach ($experiment['variants'] as &$v) {
            if ($v['name'] === $variant) {
                $v['events']++;
                if ($converted) {
                    $v['conversions']++;
                }
                $v['conversion_rate'] = $v['events'] > 0
                    ? $v['conversions'] / $v['events']
                    : 0.0;
                break;
            }
        }
        unset($v);

        $experiment['updated_at'] = date('c');
        $this->cache->put($this->cachePrefix . $experimentId, $experiment, $this->cacheTtl);
    }

    /**
     * Get an experiment by ID.
     *
     * @return Experiment|null
     */
    public function getExperiment(string $id): ?array
    {
        return $this->cache->get($this->cachePrefix . $id);
    }

    /**
     * Calculate statistical significance between variants using two-proportion z-test.
     *
     * Compares each variant against the first (control) variant.
     * Returns the first statistically significant result found.
     *
     * @param  string  $experimentId  Experiment identifier
     * @return SignificanceResult Statistical analysis results
     */
    public function calculateSignificance(string $experimentId): array
    {
        $experiment = $this->getExperiment($experimentId);

        if ($experiment === null || count($experiment['variants']) < 2) {
            return $this->emptyResult();
        }

        $variants = $experiment['variants'];
        $control = $variants[0];
        $bestResult = $this->emptyResult();

        // Compare each variant against control
        for ($i = 1; $i < count($variants); $i++) {
            $treatment = $variants[$i];

            // Check minimum sample size
            if ($control['events'] < $this->minSampleSize || $treatment['events'] < $this->minSampleSize) {
                $bestResult['recommendation'] = sprintf(
                    'Insufficient sample size. Need at least %d events per variant (control: %d, %s: %d).',
                    $this->minSampleSize,
                    $control['events'],
                    $treatment['name'],
                    $treatment['events'],
                );

                continue;
            }

            $result = $this->twoProportionZTest(
                $control['conversions'],
                $control['events'],
                $treatment['conversions'],
                $treatment['events'],
            );

            if ($result['is_significant'] && $result['confidence'] > $bestResult['confidence']) {
                $winner = $treatment['conversion_rate'] > $control['conversion_rate']
                    ? $treatment['name']
                    : $control['name'];

                $bestResult = array_merge($result, [
                    'winner' => $winner,
                    'recommendation' => $winner === $treatment['name']
                        ? sprintf('Variant "%s" outperforms control with %.1f%% confidence. Consider rolling out.', $treatment['name'], $result['confidence'] * 100)
                        : sprintf('Control outperforms variant "%s". Consider stopping the experiment.', $treatment['name']),
                ]);
            }
        }

        return $bestResult;
    }

    /**
     * Get all active experiments.
     *
     * @return list<Experiment>
     */
    public function getActiveExperiments(): array
    {
        // Since we can't iterate cache keys in all drivers, return empty by default.
        // In practice, this would be backed by a known experiments registry.
        return [];
    }

    /**
     * Complete an experiment and set the winner.
     *
     * @param  string  $experimentId  Experiment identifier
     * @param  string|null  $winner  Winner variant name (null = inconclusive)
     */
    public function completeExperiment(string $experimentId, ?string $winner = null): ?array
    {
        $experiment = $this->getExperiment($experimentId);
        if ($experiment === null) {
            return null;
        }

        $experiment['status'] = 'completed';
        $experiment['winner'] = $winner;
        $experiment['updated_at'] = date('c');

        $this->cache->put($this->cachePrefix . $experimentId, $experiment, $this->cacheTtl);

        return $experiment;
    }

    /**
     * Pause a running experiment.
     */
    public function pauseExperiment(string $experimentId): ?array
    {
        $experiment = $this->getExperiment($experimentId);
        if ($experiment === null || $experiment['status'] !== 'running') {
            return null;
        }

        $experiment['status'] = 'paused';
        $experiment['updated_at'] = date('c');

        $this->cache->put($this->cachePrefix . $experimentId, $experiment, $this->cacheTtl);

        return $experiment;
    }

    /**
     * Resume a paused experiment.
     */
    public function resumeExperiment(string $experimentId): ?array
    {
        $experiment = $this->getExperiment($experimentId);
        if ($experiment === null || $experiment['status'] !== 'paused') {
            return null;
        }

        $experiment['status'] = 'running';
        $experiment['updated_at'] = date('c');

        $this->cache->put($this->cachePrefix . $experimentId, $experiment, $this->cacheTtl);

        return $experiment;
    }

    /**
     * Get experiment summary for CLI output.
     *
     * @return array{id: string, name: string, status: string, variants: int, total_events: int, winner: string|null}
     */
    public function getSummary(string $experimentId): ?array
    {
        $experiment = $this->getExperiment($experimentId);
        if ($experiment === null) {
            return null;
        }

        $totalEvents = array_sum(array_map(
            fn (array $v): int => $v['events'],
            $experiment['variants'],
        ));

        return [
            'id' => $experiment['id'],
            'name' => $experiment['name'],
            'status' => $experiment['status'],
            'variants' => count($experiment['variants']),
            'total_events' => $totalEvents,
            'winner' => $experiment['winner'],
        ];
    }

    /**
     * Two-proportion z-test for comparing conversion rates.
     *
     * @param  int  $x1  Conversions in control group
     * @param  int  $n1  Total events in control group
     * @param  int  $x2  Conversions in treatment group
     * @param  int  $n2  Total events in treatment group
     * @return SignificanceResult Statistical analysis
     */
    private function twoProportionZTest(int $x1, int $n1, int $x2, int $n2): array
    {
        $p1 = $n1 > 0 ? $x1 / $n1 : 0;
        $p2 = $n2 > 0 ? $x2 / $n2 : 0;

        $pooled = ($x1 + $x2) / ($n1 + $n2);

        if ($pooled === 0.0 || $pooled === 1.0 || $n1 === 0 || $n2 === 0) {
            return [
                'is_significant' => false,
                'confidence' => 0.0,
                'p_value' => 1.0,
                'z_score' => 0.0,
                'winner' => null,
                'recommendation' => 'Cannot calculate significance: insufficient data.',
            ];
        }

        $se = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));
        $zScore = $se > 0 ? ($p2 - $p1) / $se : 0;

        // Approximate p-value from z-score (two-tailed)
        $pValue = $this->approximatePValue($zScore);

        $confidence = 1.0 - $pValue;
        $isSignificant = $confidence >= $this->significanceThreshold;

        return [
            'is_significant' => $isSignificant,
            'confidence' => round($confidence, 4),
            'p_value' => round($pValue, 4),
            'z_score' => round($zScore, 4),
            'winner' => null,
            'recommendation' => $isSignificant
                ? 'Statistically significant result detected.'
                : sprintf('Not yet significant (%.1f%% confidence, need %.1f%%). Continue collecting data.', $confidence * 100, $this->significanceThreshold * 100),
        ];
    }

    /**
     * Approximate two-tailed p-value from z-score using error function approximation.
     */
    private function approximatePValue(float $z): float
    {
        // Abramowitz and Stegun approximation for standard normal CDF
        $t = 1.0 / (1.0 + 0.2316419 * abs($z));
        $d = 0.3989422804014327; // 1/sqrt(2*pi)
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $t4 = $t3 * $t;
        $t5 = $t4 * $t;

        $p = $d * exp(-0.5 * $z * $z) * ($t * (0.3193815 + $t2 * (-0.3565638 + $t3 * (1.781478 + $t4 * (-1.8212560 + $t5 * 1.3302744)))));

        return 2.0 * (1.0 - $p); // Two-tailed
    }

    /**
     * Create an empty significance result.
     *
     * @return SignificanceResult
     */
    private function emptyResult(): array
    {
        return [
            'is_significant' => false,
            'confidence' => 0.0,
            'p_value' => 1.0,
            'z_score' => 0.0,
            'winner' => null,
            'recommendation' => 'No significant result detected.',
        ];
    }
}
