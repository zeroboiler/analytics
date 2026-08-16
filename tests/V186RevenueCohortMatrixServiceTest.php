<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\Services\RevenueCohortMatrixService;

test('RevenueCohortMatrixService constructs with defaults', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    expect($service->isEnabled())->toBeTrue();
    expect($service->getMaxPeriods())->toBe(12);
});

test('RevenueCohortMatrixService recordSignup tracks cohort data', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    $service->recordSignup('user-1', 'pro', 99.00, '2026-01');
    $service->recordSignup('user-2', 'starter', 29.00, '2026-01');
    $service->recordSignup('user-3', 'pro', 99.00, '2026-02');

    $matrix = $service->buildMatrix();

    expect($matrix['total_cohorts'])->toBe(2);
    expect($matrix['total_signup_users'])->toBe(3);

    // Find 2026-01 cohort
    $janCohort = null;
    foreach ($matrix['cohorts'] as $cohort) {
        if ($cohort['cohort'] === '2026-01') {
            $janCohort = $cohort;
            break;
        }
    }

    expect($janCohort)->not->toBeNull();
    expect($janCohort['signup_count'])->toBe(2);
    expect($janCohort['m0_mrr'])->toBe(128.0); // 99 + 29
    expect($janCohort['avg_mrr_per_user'])->toBe(64.0);
});

test('RevenueCohortMatrixService recordMovement updates cohort MRR', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    $service->recordSignup('user-1', 'pro', 99.00, '2026-01');
    $service->recordMovement('user-1', '2026-01', 1, 50.0); // Expansion
    $service->recordMovement('user-1', '2026-01', 2, -20.0); // Contraction

    $matrix = $service->buildMatrix();

    $janCohort = null;
    foreach ($matrix['cohorts'] as $cohort) {
        if ($cohort['cohort'] === '2026-01') {
            $janCohort = $cohort;
            break;
        }
    }

    expect($janCohort)->not->toBeNull();
    expect($janCohort['m1_mrr'])->toBe(50.0);
    expect($janCohort['m2_mrr'])->toBe(-20.0);
});

test('RevenueCohortMatrixService quickSummary returns health assessment', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    // Empty state
    $summary = $service->quickSummary();
    expect($summary['total_cohorts'])->toBe(0);
    expect($summary['health'])->toBe('unknown');

    // With data
    $service->recordSignup('user-1', 'pro', 99.00, '2026-01');
    $service->recordMovement('user-1', '2026-01', 1, 50.0);

    $summary = $service->quickSummary();
    expect($summary['total_cohorts'])->toBe(1);
    expect($summary['health'])->toBeString();
});

test('RevenueCohortMatrixService compareCohorts returns comparison', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    $service->recordSignup('user-1', 'pro', 99.00, '2026-01');
    $service->recordSignup('user-2', 'starter', 29.00, '2026-02');

    $comparison = $service->compareCohorts('2026-01', '2026-02');

    expect($comparison['cohort_a'])->not->toBeNull();
    expect($comparison['cohort_b'])->not->toBeNull();
    expect($comparison['comparison']['signup_diff'])->toBe(0);
    expect($comparison['comparison']['mrr_diff'])->toBe(70.0);
});

test('RevenueCohortMatrixService does nothing when disabled', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn(['enabled' => false]);

    $service = new RevenueCohortMatrixService($config);

    expect($service->isEnabled())->toBeFalse();

    $service->recordSignup('user-1', 'pro', 99.00);
    $matrix = $service->buildMatrix();

    expect($matrix['total_cohorts'])->toBe(0);
    expect($matrix['total_signup_users'])->toBe(0);
});

test('RevenueCohortMatrixService recordChurn reduces total MRR', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_cohort_matrix', [])
        ->andReturn([]);

    $service = new RevenueCohortMatrixService($config);

    $service->recordSignup('user-1', 'pro', 99.00, '2026-01');
    $service->recordChurn('user-1', '2026-01', 99.00);

    $matrix = $service->buildMatrix();
    $janCohort = null;
    foreach ($matrix['cohorts'] as $cohort) {
        if ($cohort['cohort'] === '2026-01') {
            $janCohort = $cohort;
            break;
        }
    }

    expect($janCohort)->not->toBeNull();
    expect($janCohort['total_mrr'])->toBe(0.0);
});
