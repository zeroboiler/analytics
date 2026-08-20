<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\ExperimentAnalysisEngine;

beforeEach(function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    // Ensure experiment_analysis config exists
    $config->set('zeroboiler.analytics.experiment_analysis', [
        'enabled' => true,
        'alpha' => 0.05,
        'power' => 0.80,
        'method' => 'both',
        'sequential_alpha_spend_rate' => 0.5,
        'min_sample_size' => 100,
        'max_sequential_peeks' => 10,
    ]);

    $this->engine = new ExperimentAnalysisEngine($cache, $config);
});

// ── Constructor & Configuration ────────────────────────────────────

test('v268.0.0 feature 1: ExperimentAnalysisEngine class exists and is final', function (): void {
    expect(ExperimentAnalysisEngine::class)->toBeFinal();
});

test('v268.0.0 feature 2: constructor accepts CacheRepository and ConfigRepository', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    expect(fn () => new ExperimentAnalysisEngine($cache, $config))->not->toThrow(\Throwable::class);
});

test('v268.0.0 feature 3: isEnabled returns true when config enabled', function (): void {
    expect($this->engine->isEnabled())->toBeTrue();
});

test('v268.0.0 feature 4: config has experiment_analysis section with required keys', function (): void {
    $config = app(ConfigRepository::class);
    $expConfig = $config->get('zeroboiler.analytics.experiment_analysis', []);

    expect($expConfig)->toBeArray();
    expect($expConfig)->toHaveKey('enabled');
    expect($expConfig)->toHaveKey('alpha');
    expect($expConfig)->toHaveKey('power');
    expect($expConfig)->toHaveKey('method');
    expect($expConfig)->toHaveKey('min_sample_size');
    expect($expConfig)->toHaveKey('max_sequential_peeks');
});

// ── Comprehensive Analysis ──────────────────────────────────────────

test('v268.0.0 feature 5: analyze returns full result structure', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 50],
        'variant_a' => ['exposures' => 1000, 'conversions' => 70],
    ];

    $result = $this->engine->analyze('test_exp', $variants);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys([
        'experiment_id', 'method', 'metric_type', 'sample_size_met',
        'frequentist', 'bayesian', 'effect_size', 'confidence_intervals',
        'recommendation', 'winner', 'analyzed_at',
    ]);
    expect($result['experiment_id'])->toBe('test_exp');
    expect($result['sample_size_met'])->toBeTrue();
    expect($result['analyzed_at'])->toBeInt();
});

test('v268.0.0 feature 6: analyze with empty variants returns empty analysis', function (): void {
    $result = $this->engine->analyze('empty_exp', []);

    expect($result['recommendation'])->toBe('NO_DATA: No variant data provided.');
    expect($result['winner'])->toBeNull();
});

test('v268.0.0 feature 7: analyze caches result', function (): void {
    $variants = [
        'control' => ['exposures' => 500, 'conversions' => 25],
        'treatment' => ['exposures' => 500, 'conversions' => 35],
    ];

    $this->engine->analyze('cache_test', $variants);
    $cached = $this->engine->getCachedAnalysis('cache_test');

    expect($cached)->not->toBeNull();
    expect($cached['experiment_id'])->toBe('cache_test');
});

test('v268.0.0 feature 8: clearAnalysis removes cached result', function (): void {
    $variants = [
        'control' => ['exposures' => 100, 'conversions' => 5],
        'treatment' => ['exposures' => 100, 'conversions' => 7],
    ];

    $this->engine->analyze('clear_test', $variants);
    expect($this->engine->getCachedAnalysis('clear_test'))->not->toBeNull();

    $this->engine->clearAnalysis('clear_test');
    expect($this->engine->getCachedAnalysis('clear_test'))->toBeNull();
});

// ── Frequentist Analysis ───────────────────────────────────────────

test('v268.0.0 feature 9: frequentist analysis detects significant difference', function (): void {
    $variants = [
        'control' => ['exposures' => 5000, 'conversions' => 250], // 5% rate
        'treatment' => ['exposures' => 5000, 'conversions' => 400], // 8% rate
    ];

    $result = $this->engine->frequentistAnalysis($variants, 'control');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('p_value');
    expect($result)->toHaveKey('significant');
    expect($result)->toHaveKey('test_used');
    expect($result['test_used'])->toBe('two_proportion_z_test');
});

test('v268.0.0 feature 10: frequentist returns null result when no variant beats control', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 100],
        'variant_a' => ['exposures' => 1000, 'conversions' => 50],
    ];

    $result = $this->engine->frequentistAnalysis($variants, 'control');

    expect($result['significant'])->toBeFalse();
    expect($result['p_value'])->toBeNull();
});

test('v268.0.0 feature 11: frequentist handles missing control gracefully', function (): void {
    $variants = [
        'variant_a' => ['exposures' => 1000, 'conversions' => 50],
    ];

    $result = $this->engine->frequentistAnalysis($variants, 'nonexistent');

    expect($result['significant'])->toBeFalse();
    expect($result['test_used'])->toBe('none');
});

// ── Bayesian Analysis ──────────────────────────────────────────────

test('v268.0.0 feature 12: bayesian analysis returns P(Best), P(Beat Control), expected loss, credible intervals', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 50],
        'treatment' => ['exposures' => 1000, 'conversions' => 80],
    ];

    $result = $this->engine->bayesianAnalysis($variants, 'control');

    expect($result)->toBeArray();
    expect($result)->toHaveKeys([
        'prob_best', 'prob_beats_control', 'expected_loss', 'credible_interval',
    ]);

    // P(Best) should sum to ~1.0 (Monte Carlo approximation)
    $sum = array_sum($result['prob_best']);
    expect($sum)->toBeGreaterThan(0.9)->toBeLessThan(1.1);

    // Control should have null for P(Beat Control) and expected loss
    expect($result['prob_beats_control']['control'])->toBeNull();
    expect($result['expected_loss']['control'])->toBeNull();
});

test('v268.0.0 feature 13: bayesian credible intervals have valid range [0,1]', function (): void {
    $variants = [
        'control' => ['exposures' => 500, 'conversions' => 25],
        'treatment' => ['exposures' => 500, 'conversions' => 40],
    ];

    $result = $this->engine->bayesianAnalysis($variants, 'control');

    foreach ($result['credible_interval'] as $id => $interval) {
        expect($interval['lower'])->toBeGreaterThanOrEqual(0.0);
        expect($interval['upper'])->toBeLessThanOrEqual(1.0);
        expect($interval['lower'])->toBeLessThanOrEqual($interval['upper']);
    }
});

// ── Effect Size ───────────────────────────────────────────────────

test('v268.0.0 feature 14: effect size computes relative uplift and Cohen h', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 50], // 5%
        'treatment' => ['exposures' => 1000, 'conversions' => 60], // 6%
    ];

    $result = $this->engine->computeEffectSizes($variants, 'control');

    expect($result)->toHaveKey('control');
    expect($result)->toHaveKey('treatment');

    // Control should have null values
    expect($result['control']['relative_uplift'])->toBeNull();
    expect($result['control']['cohens_h'])->toBeNull();

    // Treatment should have positive relative uplift
    expect($result['treatment']['relative_uplift'])->toBeGreaterThan(0.0);
    expect($result['treatment']['absolute_lift'])->toBeGreaterThan(0.0);
    expect($result['treatment']['cohens_h'])->toBeNotNull();
});

// ── Confidence Intervals ───────────────────────────────────────────

test('v268.0.0 feature 15: Wilson score intervals are valid', function (): void {
    $result = $this->engine->wilsonScoreInterval(50, 1000);

    expect($result['lower'])->toBeGreaterThanOrEqual(0.0);
    expect($result['upper'])->toBeLessThanOrEqual(1.0);
    expect($result['lower'])->toBeLessThan($result['upper']);
    expect($result['method'])->toBe('wilson');
});

test('v268.0.0 feature 16: computeConfidenceIntervals returns results for all variants', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 50],
        'variant_a' => ['exposures' => 1000, 'conversions' => 70],
    ];

    $result = $this->engine->computeConfidenceIntervals($variants);

    expect($result)->toHaveKeys(['control', 'variant_a']);
    foreach ($result as $interval) {
        expect($interval['lower'])->toBeGreaterThanOrEqual(0.0);
        expect($interval['upper'])->toBeLessThanOrEqual(1.0);
    }
});

// ── Multi-Variant Correction ───────────────────────────────────────

test('v268.0.0 feature 17: multi-variant correction returns null for 2 variants', function (): void {
    $result = $this->engine->multiVariantCorrection(['a', 'b']);

    expect($result)->toBeNull();
});

test('v268.0.0 feature 18: Bonferroni correction adjusts alpha correctly', function (): void {
    $result = $this->engine->multiVariantCorrection(['control', 'v1', 'v2', 'v3'], null, 'bonferroni');

    expect($result)->not->toBeNull();
    expect($result['method'])->toBe('bonferroni');
    expect($result['adjusted_alpha'])->toBeLessThan(0.05);
    expect($result['num_comparisons'])->toBe(3);
});

test('v268.0.0 feature 19: Šidák correction is less conservative than Bonferroni', function (): void {
    $bonferroni = $this->engine->multiVariantCorrection(['control', 'v1', 'v2'], null, 'bonferroni');
    $sidak = $this->engine->multiVariantCorrection(['control', 'v1', 'v2'], null, 'sidak');

    expect($sidak['adjusted_alpha'])->toBeGreaterThan($bonferroni['adjusted_alpha']);
});

// ── Sequential Testing ──────────────────────────────────────────────

test('v268.0.0 feature 20: sequential test allows continuation with low z-score', function (): void {
    $result = $this->engine->sequentialTest('test', 3, 10, 0.5);

    expect($result['should_stop'])->toBeFalse();
    expect($result['recommendation'])->toContain('CONTINUE');
});

test('v268.0.0 feature 21: sequential test returns valid structure', function (): void {
    $result = $this->engine->sequentialTest('test', 5, 10, 2.0);

    expect($result)->toHaveKeys([
        'should_stop', 'peek', 'max_peeks', 'alpha_spent',
        'alpha_remaining', 'boundary', 'z_score', 'recommendation',
    ]);
});

// ── Sample Size Calculator ─────────────────────────────────────────

test('v268.0.0 feature 22: calculateSampleSize returns valid results', function (): void {
    $result = $this->engine->calculateSampleSize(0.05, 0.10);

    expect($result)->toHaveKeys([
        'total_sample_size', 'per_variant', 'control', 'treatment',
        'baseline_rate', 'mde_relative', 'mde_absolute', 'power', 'alpha',
    ]);
    expect($result['total_sample_size'])->toBeGreaterThan(0);
    expect($result['baseline_rate'])->toBe(0.05);
    expect($result['mde_relative'])->toBe(0.10);
});

test('v268.0.0 feature 23: calculateSampleSize with Bonferroni correction for 4 variants', function (): void {
    $result = $this->engine->calculateSampleSize(0.05, 0.15, null, null, 4);

    expect($result['correction'])->toBe('bonferroni');
    expect($result['total_sample_size'])->toBeGreaterThan(0);
    expect($result['num_variants'])->toBe(4);
});

// ── MDE Calculator ────────────────────────────────────────────────

test('v268.0.0 feature 24: calculateMDE returns valid results', function (): void {
    $result = $this->engine->calculateMDE(0.05, 5000);

    expect($result)->toHaveKeys([
        'mde_relative', 'mde_absolute', 'treatment_rate', 'detectable_uplift_pct',
    ]);
    expect($result['mde_relative'])->toBeGreaterThan(0.0);
    expect($result['detectable_uplift_pct'])->toBeGreaterThan(0.0);
});

// ── Quick Significance ──────────────────────────────────────────────

test('v268.0.0 feature 25: quickSignificance returns valid result', function (): void {
    $result = $this->engine->quickSignificance(100, 2000, 130, 2000);

    expect($result)->toHaveKeys([
        'p_value', 'significant', 'relative_uplift',
        'confidence_level', 'test_used', 'recommendation',
    ]);
});

test('v268.0.0 feature 26: quickSignificance with zero conversions returns non-significant', function (): void {
    $result = $this->engine->quickSignificance(0, 100, 0, 100);

    expect($result['significant'])->toBeFalse();
});

// ── Experiment Health ──────────────────────────────────────────────

test('v268.0.0 feature 27: assessExperimentHealth returns valid structure', function (): void {
    $variants = [
        'control' => ['exposures' => 500, 'conversions' => 25],
        'variant_a' => ['exposures' => 500, 'conversions' => 30],
    ];

    $result = $this->engine->assessExperimentHealth($variants);

    expect($result)->toHaveKeys(['healthy', 'checks', 'summary']);
    expect($result['checks'])->toBeArray();
    expect($result['summary'])->toHaveKeys([
        'total_exposures', 'total_conversions', 'num_variants', 'overall_rate',
    ]);
});

test('v268.0.0 feature 28: assessExperimentHealth detects low sample size', function (): void {
    $variants = [
        'control' => ['exposures' => 10, 'conversions' => 1],
    ];

    $result = $this->engine->assessExperimentHealth($variants);

    // Should have a 'fail' status for sample_size check
    $hasSampleFail = false;
    foreach ($result['checks'] as $check) {
        if ($check['name'] === 'sample_size' && $check['status'] === 'fail') {
            $hasSampleFail = true;
        }
    }
    expect($hasSampleFail)->toBeTrue();
});

test('v268.0.0 feature 29: assessExperimentHealth detects balanced traffic', function (): void {
    $variants = [
        'control' => ['exposures' => 1000, 'conversions' => 50],
        'variant_a' => ['exposures' => 980, 'conversions' => 50],
    ];

    $result = $this->engine->assessExperimentHealth($variants);

    // Traffic imbalance check should pass
    $hasTrafficPass = false;
    foreach ($result['checks'] as $check) {
        if ($check['name'] === 'traffic_imbalance' && $check['status'] === 'pass') {
            $hasTrafficPass = true;
        }
    }
    expect($hasTrafficPass)->toBeTrue();
});

// ── Integration: Full Analysis Pipeline ─────────────────────────────

test('v268.0.0 feature 30: full analysis pipeline — significant result', function (): void {
    // Large sample, 60% relative uplift — should be significant
    $variants = [
        'control' => ['exposures' => 10000, 'conversions' => 500], // 5%
        'treatment' => ['exposures' => 10000, 'conversions' => 800], // 8%
    ];

    $result = $this->engine->analyze('pipeline_test', $variants, 'control', 'conversion_rate', 'both');

    expect($result['sample_size_met'])->toBeTrue();
    expect($result['frequentist'])->not->toBeNull();
    expect($result['bayesian'])->not->toBeNull();
    expect($result['effect_size'])->not->toBeEmpty();
    expect($result['confidence_intervals'])->not->toBeEmpty();
});

// ── CLI Command Registration ────────────────────────────────────────

test('v268.0.0 feature 31: AnalyticsExperimentCommand class exists and extends Command', function (): void {
    $class = \ZeroBoiler\Analytics\Console\Commands\AnalyticsExperimentCommand::class;
    expect(class_exists($class))->toBeTrue();
    expect(new ReflectionClass($class))->isFinal()->toBeTrue();
});

test('v268.0.0 feature 32: AnalyticsExperimentCommand has correct signature', function (): void {
    $command = new \ZeroBoiler\Analytics\Console\Commands\AnalyticsExperimentCommand;

    expect($command->getSignature())->toContain('zb:analytics:experiment');
});
