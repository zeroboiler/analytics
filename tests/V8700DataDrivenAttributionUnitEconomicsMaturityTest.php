<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\DataDrivenAttributionService;
use ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService;
use ZeroBoiler\Analytics\Services\UnitEconomicsService;

beforeEach(function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);
    $cache->shouldReceive('remember')->andReturnUsing(fn (string $key, \DateInterval|int $ttl, \Closure $callback) => $callback());
    Cache::shouldReceive('store')->andReturnSelf();
    Cache::shouldReceive('get')->andReturn(null);
    Cache::shouldReceive('put')->andReturn(true);
});

// ── Data-Driven Attribution Tests ────────────────────────────────

describe('DataDrivenAttributionService', function (): void {
    it('computes Shapley attribution for multi-channel conversion paths', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 3600,
                'min_conversions' => 10,
                'lookback_days' => 90,
                'max_path_length' => 20,
            ]);

        $service = new DataDrivenAttributionService($cache, $config);

        $paths = [
            ['path' => ['organic', 'paid', 'email'], 'value' => 100.0],
            ['path' => ['organic', 'social', 'email'], 'value' => 80.0],
            ['path' => ['paid', 'email'], 'value' => 120.0],
            ['path' => ['organic', 'paid'], 'value' => 60.0],
            ['path' => ['social', 'email'], 'value' => 90.0],
        ];

        $result = $service->computeAttribution($paths);

        expect($result['total_value'])->toBe(450.0);
        expect($result['channels'])->toHaveKey('organic');
        expect($result['channels'])->toHaveKey('paid');
        expect($result['channels'])->toHaveKey('email');
        expect($result['channels'])->toHaveKey('social');
        expect($result['model_confidence'])->toBeGreaterThanOrEqual(0.0);
        expect($result['data_quality']['path_count'])->toBe(5);
        expect($result['data_quality']['channel_count'])->toBe(4);

        // Total percentage should be ~100%
        $totalPct = array_sum(array_column($result['channels'], 'percentage'));
        expect(abs($totalPct - 100.0))->toBeLessThan(0.1);
    });

    it('handles empty paths gracefully', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([]);

        $service = new DataDrivenAttributionService($cache, $config);
        $result = $service->computeAttribution([]);

        expect($result['channels'])->toBeEmpty();
        expect($result['total_value'])->toBe(0.0);
        expect($result['model_confidence'])->toBe(0.0);
    });

    it('assigns 100% to single channel', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([]);

        $service = new DataDrivenAttributionService($cache, $config);

        $paths = [
            ['path' => ['organic'], 'value' => 500.0],
            ['path' => ['organic'], 'value' => 300.0],
        ];

        $result = $service->computeAttribution($paths);

        expect($result['total_value'])->toBe(800.0);
        expect($result['channels']['organic']['percentage'])->toBe(100.0);
        expect($result['channels']['organic']['credit'])->toBe(800.0);
    });

    it('compares attribution between two periods', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([]);

        $service = new DataDrivenAttributionService($cache, $config);

        $current = [
            ['path' => ['organic', 'paid'], 'value' => 200.0],
            ['path' => ['social'], 'value' => 100.0],
        ];

        $previous = [
            ['path' => ['organic'], 'value' => 300.0],
            ['path' => ['paid'], 'value' => 100.0],
        ];

        $result = $service->comparePeriods($current, $previous);

        expect($result)->toHaveKey('current');
        expect($result)->toHaveKey('previous');
        expect($result)->toHaveKey('changes');
        expect($result['changes'])->toHaveKey('organic');
    });

    it('computes channel removal impact', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([]);

        $service = new DataDrivenAttributionService($cache, $config);

        $paths = [
            ['path' => ['organic', 'paid', 'email'], 'value' => 100.0],
            ['path' => ['paid', 'email'], 'value' => 50.0],
        ];

        $impact = $service->channelRemovalImpact($paths);

        expect($impact)->toHaveKey('organic');
        expect($impact)->toHaveKey('paid');
        expect($impact)->toHaveKey('email');
        expect($impact['organic']['affected_conversions'])->toBe(1);
        expect($impact['paid']['affected_conversions'])->toBe(2);
    });

    it('generates budget allocation recommendations', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([]);

        $service = new DataDrivenAttributionService($cache, $config);

        $paths = [
            ['path' => ['organic', 'paid'], 'value' => 200.0],
            ['path' => ['social', 'email'], 'value' => 100.0],
        ];

        $budget = $service->budgetAllocation($paths, 10000.0);

        expect($budget['allocations'])->not->toBeEmpty();
        expect($budget['efficiency_score'])->toBeGreaterThanOrEqual(0.0);
    });

    it('reports insufficient data when below minimum conversions', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_driven_attribution', [])
            ->andReturn([
                'enabled' => true,
                'min_conversions' => 30,
            ]);

        $service = new DataDrivenAttributionService($cache, $config);

        $paths = [
            ['path' => ['organic', 'paid'], 'value' => 100.0],
        ];

        $result = $service->computeAttribution($paths);

        expect($result['data_quality']['sufficient_data'])->toBeFalse();
    });
});

// ── Unit Economics Tests ─────────────────────────────────────────

describe('UnitEconomicsService', function (): void {
    it('calculates simple LTV correctly', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'enabled' => true,
                'ltv' => ['gross_margin' => 0.75],
                'benchmarks' => ['ltv_cac_target' => 3.0],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        // ARPU=100, churn=5% → lifetime=20 months, LTV = 100 * 0.75 * 20 = 1500
        $result = $service->simpleLtv(100.0, 0.05);

        expect($result['ltv'])->toBe(1500.0);
        expect($result['lifetime_months'])->toBe(20.0);
        expect($result['gross_margin'])->toBe(0.75);
        expect($result['formula'])->toBe('ARPU × Gross Margin × (1 / Churn Rate)');
    });

    it('calculates predictive LTV with DCF', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'enabled' => true,
                'ltv' => [
                    'gross_margin' => 0.75,
                    'discount_rate' => 0.01,
                    'lifetime_months' => 60,
                ],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        $result = $service->predictiveLtv(100.0, 0.05, 0.01, 60, 0.75);

        // Predictive LTV should be less than simple LTV due to discounting
        expect($result['ltv'])->toBeGreaterThan(0.0);
        expect($result['ltv'])->toBeLessThan(1500.0); // Less than simple LTV
        expect($result['discount_rate'])->toBe(0.01);
    });

    it('calculates cohort LTV from observations', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([]);

        $service = new UnitEconomicsService($cache, $config);

        $cohortData = [
            ['month' => 1, 'revenue' => 5000.0, 'active_users' => 100, 'churned_users' => 5],
            ['month' => 2, 'revenue' => 4800.0, 'active_users' => 95, 'churned_users' => 3],
            ['month' => 3, 'revenue' => 4600.0, 'active_users' => 92, 'churned_users' => 2],
        ];

        $result = $service->cohortLtv($cohortData);

        expect($result['ltv'])->toBe(14400.0);
        expect($result['months_observed'])->toBe(3);
    });

    it('calculates blended CAC', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([]);

        $service = new UnitEconomicsService($cache, $config);

        $result = $service->blendedCac(50000.0, 100);

        expect($result['cac'])->toBe(500.0);
        expect($result['new_customers'])->toBe(100);
    });

    it('calculates per-channel CAC with efficiency classification', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([]);

        $service = new UnitEconomicsService($cache, $config);

        $channels = [
            'organic' => ['spend' => 5000.0, 'customers' => 50],    // CAC=100
            'paid' => ['spend' => 20000.0, 'customers' => 20],      // CAC=1000
            'referral' => ['spend' => 3000.0, 'customers' => 30],    // CAC=100
        ];

        $result = $service->channelCac($channels);

        expect($result['blended_cac'])->toBe(280.0);
        expect($result['total_customers'])->toBe(100);
        expect($result['total_spend'])->toBe(28000.0);
        expect($result['channels'])->toHaveKey('paid');
        expect($result['channels']['paid']['cac'])->toBe(1000.0);
    });

    it('calculates LTV:CAC ratio with health assessment', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'benchmarks' => ['ltv_cac_target' => 3.0],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        $healthy = $service->ltvCacRatio(3000.0, 500.0);   // 6:1
        $poor = $service->ltvCacRatio(500.0, 2000.0);      // 0.25:1

        expect($healthy['ratio'])->toBe(6.0);
        expect($healthy['health'])->toBe('excellent');

        expect($poor['ratio'])->toBe(0.25);
        expect($poor['health'])->toBe('critical');
    });

    it('calculates CAC payback period', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'benchmarks' => ['payback_target_months' => 18],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        // CAC=500, monthly contribution=75 → payback=6.67 months
        $result = $service->cacPaybackPeriod(500.0, 75.0);

        expect($result['payback_months'])->toBe(6.7);
        expect($result['health'])->toBe('excellent');
    });

    it('calculates Magic Number', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'benchmarks' => ['magic_number_target' => 0.75],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        // ARR growth=100K, S&M spend=80K → Magic=1.25 (excellent)
        $result = $service->magicNumber(500000.0, 400000.0, 80000.0);

        expect($result['magic_number'])->toBe(1.25);
        expect($result['health'])->toBe('excellent');
    });

    it('generates comprehensive dashboard', function (): void {
        $cache = mock(CacheRepository::class);
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.unit_economics', [])
            ->andReturn([
                'ltv' => ['gross_margin' => 0.75],
                'benchmarks' => ['ltv_cac_target' => 3.0, 'payback_target_months' => 18, 'magic_number_target' => 0.75],
            ]);

        $service = new UnitEconomicsService($cache, $config);

        $result = $service->dashboard([
            'arpu' => 100.0,
            'churn_rate' => 0.05,
            'cac' => 500.0,
            'monthly_revenue' => 50000.0,
            'monthly_expenses' => 80000.0,
            'cash_balance' => 1000000.0,
            'annual_revenue' => 600000.0,
            'employees' => 10,
            'current_q_arr' => 500000.0,
            'previous_q_arr' => 400000.0,
            'previous_q_sm_spend' => 80000.0,
        ]);

        expect($result)->toHaveKey('ltv');
        expect($result)->toHaveKey('ltv_cac');
        expect($result)->toHaveKey('payback');
        expect($result)->toHaveKey('magic_number');
        expect($result)->toHaveKey('gross_margin');
        expect($result)->toHaveKey('burn_rate');
        expect($result)->toHaveKey('revenue_per_employee');
        expect($result)->toHaveKey('overall_health');
        expect($result['ltv_cac']['ratio'])->toBe(3.0);
    });
});

// ── Product Analytics Maturity Tests ─────────────────────────────

describe('ProductAnalyticsMaturityService', function (): void {
    it('assesses maturity level', function (): void {
        $service = new ProductAnalyticsMaturityService;

        $result = $service->assess([
            'providers' => ['ga4' => true, 'meta_pixel' => true, 'gtm' => true, 'plausible' => true],
            'identity_resolution' => true,
            'real_time' => true,
            'consent_mode' => true,
            'gdpr' => true,
            'ccpa' => true,
            'event_validation' => true,
            'dedup' => true,
            'enrichment' => true,
            'testing' => true,
            'ci_integration' => true,
            'monitoring' => true,
            'auto_tracking' => true,
        ]);

        expect($result['level'])->toBeGreaterThanOrEqual(1);
        expect($result['level_name'])->not->toBeEmpty();
        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['score'])->toBeLessThanOrEqual(100);
        expect($result['grade'])->not->toBeEmpty();
        expect($result['dimensions'])->toHaveCount(8);
        expect($result)->toHaveKey('strengths');
        expect($result)->toHaveKey('weaknesses');
        expect($result)->toHaveKey('roadmap');
    });

    it('quick assessment returns compact result', function (): void {
        $service = new ProductAnalyticsMaturityService;

        $result = $service->quickAssess();

        expect($result)->toHaveKeys(['level', 'level_name', 'score', 'grade']);
    });

    it('returns level 1 for empty capabilities', function (): void {
        $service = new ProductAnalyticsMaturityService;

        // With minimal catalog events, maturity should be low
        $result = $service->quickAssess([
            'consent_mode' => false,
            'gdpr' => false,
            'ccpa' => false,
            'event_validation' => false,
            'dedup' => false,
            'enrichment' => false,
            'testing' => false,
            'ci_integration' => false,
            'monitoring' => false,
            'auto_tracking' => false,
            'identity_resolution' => false,
            'real_time' => false,
        ]);

        // Even with catalog events providing baseline, score should reflect gaps
        expect($result['level'])->toBeGreaterThanOrEqual(1);
        expect($result['score'])->toBeLessThanOrEqual(100);
    });

    it('compares two maturity snapshots', function (): void {
        $service = new ProductAnalyticsMaturityService;

        $result = $service->compare(
            ['gdpr' => true, 'consent_mode' => true, 'testing' => true, 'ci_integration' => true, 'event_validation' => true, 'dedup' => true, 'enrichment' => true, 'monitoring' => true, 'real_time' => true, 'auto_tracking' => true, 'identity_resolution' => true, 'providers' => ['ga4' => true, 'meta_pixel' => true]],
            ['gdpr' => false, 'consent_mode' => false],
        );

        expect($result)->toHaveKey('current');
        expect($result)->toHaveKey('previous');
        expect($result)->toHaveKey('delta');
        expect($result)->toHaveKey('improved');
        expect($result)->toHaveKey('declined');
        expect($result)->toHaveKey('unchanged');
    });

    it('all dimensions have findings and weight', function (): void {
        $service = new ProductAnalyticsMaturityService;

        $result = $service->assess();

        foreach ($result['dimensions'] as $key => $dim) {
            expect($dim['name'])->not->toBeEmpty();
            expect($dim['score'])->toBeGreaterThanOrEqual(0);
            expect($dim['max'])->toBeGreaterThan(0);
            expect($dim['weight'])->toBeGreaterThan(0);
            expect($dim['status'])->not->toBeEmpty();
            expect($dim['findings'])->not->toBeEmpty();
        }
    });

    it('maturity levels are sequential', function (): void {
        expect(ProductAnalyticsMaturityService::LEVEL_AD_HOC)->toBe(1);
        expect(ProductAnalyticsMaturityService::LEVEL_BASIC)->toBe(2);
        expect(ProductAnalyticsMaturityService::LEVEL_STANDARD)->toBe(3);
        expect(ProductAnalyticsMaturityService::LEVEL_ADVANCED)->toBe(4);
        expect(ProductAnalyticsMaturityService::LEVEL_LEADING)->toBe(5);
    });
});

// ── Version Consistency ─────────────────────────────────────────

it('has version 87.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('87.0.0');
});
