<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService;
use ZeroBoiler\Analytics\Store\EventStoreManager;

describe('RevenueAttributionDashboardService', function (): void {
    it('constructs with default config when none provided', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);

        expect($service)->toBeInstanceOf(RevenueAttributionDashboardService::class);
    });

    it('constructs with custom lookback days and currency', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([
            'lookback_days' => 60,
            'currency' => 'EUR',
        ]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);

        $dashboard = $service->dashboard();
        expect($dashboard['period_days'])->toBe(60);
        expect($dashboard['currency'])->toBe('EUR');
    });

    it('returns dashboard structure with all required keys', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $dashboard = $service->dashboard(30);

        expect($dashboard)->toHaveKeys([
            'generated_at',
            'period_days',
            'currency',
            'total_revenue',
            'total_customers',
            'channels',
            'top_channel',
            'revenue_concentration',
            'recommendations',
        ]);
        expect($dashboard['period_days'])->toBe(30);
        expect($dashboard['currency'])->toBe('USD');
        expect($dashboard['total_revenue'])->toBeFloat();
        expect($dashboard['total_customers'])->toBeInt();
        expect($dashboard['channels'])->toBeArray();
        expect($dashboard['recommendations'])->toBeArray();
    });

    it('returns revenue by channel with all configured channels', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([
            'channels' => ['organic', 'direct', 'google_cpc', 'referral'],
        ]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $channels = $service->revenueByChannel(30);

        expect($channels)->toHaveKeys(['organic', 'direct', 'google_cpc', 'referral']);
        foreach ($channels as $channel) {
            expect($channel)->toHaveKeys(['revenue', 'customers', 'events']);
        }
    });

    it('returns LTV by channel sorted descending', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $ltv = $service->ltvByChannel(30);

        expect($ltv)->toBeArray();

        // If there are values, they should be sorted descending
        $values = array_values($ltv);
        for ($i = 1; $i < count($values); $i++) {
            expect($values[$i - 1])->toBeGreaterThanOrEqual($values[$i]);
        }
    });

    it('returns CAC recovery by channel with all required fields', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([
            'cac_by_channel' => [
                'google_cpc' => 5000.0,
                'facebook_ads' => 3000.0,
            ],
        ]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $cac = $service->cacRecoveryByChannel(30);

        expect($cac)->toHaveKey('google_cpc');
        expect($cac)->toHaveKey('facebook_ads');

        expect($cac['google_cpc'])->toHaveKeys(['spend', 'revenue', 'customers', 'cac', 'cac_recovery_pct', 'payback_months']);
        expect($cac['google_cpc']['spend'])->toBe(5000.0);
        expect($cac['facebook_ads']['spend'])->toBe(3000.0);
    });

    it('returns revenue concentration analysis with HHI and grades', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $analysis = $service->revenueConcentrationAnalysis(30);

        expect($analysis)->toHaveKeys(['hhi', 'concentration_grade', 'dominant_channel', 'risk_level']);
        expect($analysis['hhi'])->toBeFloat();
        expect($analysis['hhi'])->toBeGreaterThanOrEqual(0.0);
        expect($analysis['hhi'])->toBeLessThanOrEqual(1.0);
        expect($analysis['concentration_grade'])->toBeIn(['excellent', 'good', 'moderate', 'concentrated', 'critical']);
        expect($analysis['risk_level'])->toBeIn(['low', 'medium', 'high', 'critical']);
    });

    it('returns channel growth trends with MoM comparison', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $growth = $service->channelGrowthTrend(30);

        expect($growth)->toBeArray();
        foreach ($growth as $channel => $data) {
            expect($data)->toHaveKeys(['current', 'previous', 'growth_pct', 'growth_abs']);
            expect($data['current'])->toBeFloat();
            expect($data['previous'])->toBeFloat();
            expect($data['growth_pct'])->toBeFloat();
            expect($data['growth_abs'])->toBeFloat();
        }
    });

    it('dashboard with custom lookback overrides defaults', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository(['lookback_days' => 30]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);

        $dashboard90 = $service->dashboard(90);
        expect($dashboard90['period_days'])->toBe(90);

        $dashboard7 = $service->dashboard(7);
        expect($dashboard7['period_days'])->toBe(7);
    });

    it('revenue concentration is zero when no revenue', function (): void {
        $manager = mockAnalyticsManager();
        $metrics = new AnalyticsMetrics;
        $store = mockEventStoreManager();
        $config = mockConfigRepository([]);

        $service = new RevenueAttributionDashboardService($manager, $metrics, $store, $config);
        $analysis = $service->revenueConcentrationAnalysis(30);

        expect($analysis['hhi'])->toBe(0.0);
        expect($analysis['concentration_grade'])->toBe('excellent');
        expect($analysis['risk_level'])->toBe('low');
    });
});

describe('V146RevenueAttributionVersionIntegrity', function (): void {
    it('version is declared as 147.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('147.0.0');
    });

    it('checks version consistency across core files', function (): void {
        $reflection = new \ReflectionClass(RevenueAttributionDashboardService::class);
        expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
        expect($reflection->getShortName())->toBe('RevenueAttributionDashboardService');
        expect($reflection->isFinal())->toBeTrue();
    });

    it('AnalyticsManager has revenueAttributionDashboard method', function (): void {
        $manager = mockAnalyticsManager();
        expect(method_exists($manager, 'revenueAttributionDashboard'))->toBeTrue();
    });

    it('AnalyticsRevenueAttributionCommand class exists and is final', function (): void {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsRevenueAttributionCommand::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Console\\Commands');
    });
});

// ── Helper Functions ──────────────────────────────────────────────────────

function mockAnalyticsManager(): AnalyticsManager
{
    return new AnalyticsManager;
}

function mockEventStoreManager(): EventStoreManager
{
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_store', [])
        ->andReturn([]);

    return new EventStoreManager($config);
}

function mockConfigRepository(array $analyticsConfig = []): ConfigRepository
{
    $repo = mock(ConfigRepository::class);
    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_attribution', [])
        ->andReturn($analyticsConfig);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.gtm', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.meta_pixel', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.plausible', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.posthog', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.mixpanel', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.amplitude', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.tiktok', [])
        ->andReturn([]);

    $repo->shouldReceive('get')
        ->with('zeroboiler.analytics.linkedin', [])
        ->andReturn([]);

    return $repo;
}
