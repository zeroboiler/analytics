<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\ABTestAnalyticsService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

beforeEach(function (): void {
    $this->manager = mock(AnalyticsManager::class);
    $this->manager->shouldReceive('abTestExposure');
    $this->manager->shouldReceive('trackEvent');
    $this->cache = app('cache')->driver('array');
    $this->config = app('config');
    $this->config->set('zeroboiler.analytics.ab_tests', [
        'enabled' => true,
        'confidence_threshold' => 0.95,
        'cache_ttl' => 604800,
    ]);
    $this->service = new ABTestAnalyticsService(
        $this->manager,
        $this->cache,
        $this->config,
    );
});

test('A/B test records exposure and retrieves results', function (): void {
    $this->service->recordExposure('pricing_test', 'variant_a');
    $this->service->recordExposure('pricing_test', 'variant_a');
    $this->service->recordExposure('pricing_test', 'variant_b');

    $results = $this->service->getResults('pricing_test');

    expect($results)->not->toBeNull();
    expect($results['id'])->toBe('pricing_test');
    expect($results['variants']['variant_a']['exposures'])->toBe(2);
    expect($results['variants']['variant_b']['exposures'])->toBe(1);
});

test('A/B test records conversions', function (): void {
    $this->service->recordExposure('pricing_test', 'variant_a');
    $this->service->recordExposure('pricing_test', 'variant_b');
    $this->service->recordExposure('pricing_test', 'variant_b');
    $this->service->recordConversion('pricing_test', 'variant_b');

    $results = $this->service->getResults('pricing_test');

    expect($results['variants']['variant_a']['conversions'])->toBe(0);
    expect($results['variants']['variant_b']['conversions'])->toBe(1);
    expect($results['variants']['variant_b']['rate'])->toBe(0.5);
});

test('A/B test computes winner with significance', function (): void {
    // Create a clear winner scenario
    for ($i = 0; $i < 100; $i++) {
        $this->service->recordExposure('btn_color', 'control');
        $this->service->recordExposure('btn_color', 'green');
    }

    for ($i = 0; $i < 10; $i++) {
        $this->service->recordConversion('btn_color', 'control');
    }

    for ($i = 0; $i < 30; $i++) {
        $this->service->recordConversion('btn_color', 'green');
    }

    $results = $this->service->getResults('btn_color');

    expect($results['winner'])->toBe('green');
    expect($results['confidence'])->toBeGreaterThan(0.95);
    expect($results['significant'])->toBeTrue();
});

test('A/B test returns null for non-existent experiment', function (): void {
    expect($this->service->getResults('nonexistent'))->toBeNull();
});

test('A/B test can delete experiments', function (): void {
    $this->service->recordExposure('to_delete', 'variant_a');

    expect($this->service->getResults('to_delete'))->not->toBeNull();
    expect($this->service->deleteExperiment('to_delete'))->toBeTrue();
    expect($this->service->getResults('to_delete'))->toBeNull();
});

test('A/B test trackExposure dispatches analytics event', function (): void {
    $this->manager->shouldReceive('abTestExposure')
        ->once()
        ->with('cta_test', 'blue', []);

    $this->service->trackExposure('cta_test', 'blue');
});

test('A/B test trackConversion dispatches analytics event', function (): void {
    $this->manager->shouldReceive('trackEvent')
        ->once()
        ->andReturnUsing(function (AnalyticsEvent $e): void {
            expect($e->name)->toBe('ab_test_conversion');
            expect($e->params['experiment_id'])->toBe('cta_test');
            expect($e->params['variant_id'])->toBe('blue');
        });

    $this->service->trackConversion('cta_test', 'blue');
});

test('A/B test respects enabled flag', function (): void {
    $this->config->set('zeroboiler.analytics.ab_tests.enabled', false);
    $disabledService = new ABTestAnalyticsService($this->manager, $this->cache, $this->config);

    $disabledService->recordExposure('test', 'v1');

    expect($disabledService->getResults('test'))->toBeNull();
    expect($disabledService->isEnabled())->toBeFalse();
});

test('A/B test returns correct confidence threshold', function (): void {
    expect($this->service->getConfidenceThreshold())->toBe(0.95);
});
