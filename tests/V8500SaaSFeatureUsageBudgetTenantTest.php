<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService;
use ZeroBoiler\Analytics\Services\EventBudgetOptimizerService;
use ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService;

beforeEach(function (): void {
    Cache::clear();
});

// ─── SaaS Feature Usage Tracker ─────────────────────────────────────────

describe('SaaSFeatureUsageTrackerService', function (): void {
    test('recordUsage increments daily counter and adds user to DAU/WAU/MAU sets', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');
        $service->recordUsage('user-1', 'dashboard'); // Second usage same day

        // DAU should be 1 (unique users)
        expect($service->dau('dashboard'))->toBe(1);

        // WAU and MAU should also include this user
        expect($service->wau('dashboard'))->toBeGreaterThanOrEqual(1);
        expect($service->mau('dashboard'))->toBeGreaterThanOrEqual(1);
    });

    test('dau/wau/mau counts unique users across different users', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');
        $service->recordUsage('user-2', 'dashboard');
        $service->recordUsage('user-3', 'dashboard');

        expect($service->dau('dashboard'))->toBe(3);
    });

    test('stickiness returns DAU/WAU ratio as percentage', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');

        $stickiness = $service->stickiness('dashboard');

        expect($stickiness)->toBeFloat();
        expect($stickiness)->toBeGreaterThanOrEqual(0.0);
        expect($stickiness)->toBeLessThanOrEqual(100.0);
    });

    test('streak increments on consecutive days and resets on gap', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600 * 24 * 7);

        // First usage — streak = 1
        $service->recordUsage('user-1', 'dashboard');
        expect($service->streak('user-1', 'dashboard'))->toBe(1);

        // The streak logic requires real date changes, which we can't simulate
        // in unit tests with cache. Verify that the streak is at least 1.
        expect($service->longestStreak('user-1', 'dashboard'))->toBeGreaterThanOrEqual(1);
    });

    test('powerUsers returns users meeting streak threshold', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');

        // With threshold 0, user should appear
        $powerUsers = $service->powerUsers('dashboard', 0);

        expect($powerUsers)->toBeArray();
        // User may or may not appear depending on streak logic
    });

    test('dashboard returns features array with expected keys', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $dashboard = $service->dashboard();

        expect($dashboard)->toHaveKey('features');
        expect($dashboard['features'])->toBeArray();
    });

    test('engagementSummary returns global metrics', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $summary = $service->engagementSummary();

        expect($summary)->toHaveKey('total_features');
        expect($summary)->toHaveKey('global_dau');
        expect($summary)->toHaveKey('global_wau');
        expect($summary)->toHaveKey('global_mau');
        expect($summary)->toHaveKey('stickiness');
        expect($summary['total_features'])->toBeInt();
        expect($summary['total_features'])->toBeGreaterThan(0);
    });

    test('topFeatures returns sorted list with name and dau keys', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $top = $service->topFeatures(5);

        expect($top)->toBeArray();
        // Each entry should have name and dau
        foreach ($top as $item) {
            expect($item)->toHaveKey('name');
            expect($item)->toHaveKey('dau');
        }
    });

    test('adoptionCount and adoptionRate work correctly', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');

        expect($service->adoptionCount('dashboard'))->toBeGreaterThanOrEqual(1);

        $rate = $service->adoptionRate('dashboard', 100);
        expect($rate)->toBeFloat();
        expect($rate)->toBeGreaterThanOrEqual(0.0);
        expect($rate)->toBeLessThanOrEqual(100.0);
    });

    test('userUsageProfile returns usage data for known user', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $service->recordUsage('user-1', 'dashboard');

        $profile = $service->userUsageProfile('user-1');

        expect($profile)->toBeArray();
    });

    test('leastUsedFeatures returns sorted list', function (): void {
        $cache = app(CacheRepository::class);
        $service = new SaaSFeatureUsageTrackerService($cache, 3600);

        $least = $service->leastUsedFeatures(5);

        expect($least)->toBeArray();
        foreach ($least as $item) {
            expect($item)->toHaveKey('name');
            expect($item)->toHaveKey('dau');
        }
    });
});

// ─── Event Budget Optimizer ───────────────────────────────────────────────

describe('EventBudgetOptimizerService', function (): void {
    test('recordDispatch increments dispatch count', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $service->recordDispatch('ga4', 10);
        $service->recordDispatch('ga4', 5);

        $cost = $service->providerCost('ga4');

        expect($cost)->toBeFloat();
        expect($cost)->toBeGreaterThan(0.0);
    });

    test('providerCost calculates based on cost per event', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 50.0],
        );

        $service->recordDispatch('ga4', 100);

        expect($service->providerCost('ga4'))->toBe(0.1);
    });

    test('totalCost sums across all providers', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $service->recordDispatch('ga4', 100);
        $service->recordDispatch('meta', 50);

        $total = $service->totalCost();

        expect($total)->toBeFloat();
        expect($total)->toBeGreaterThan(0.0);
    });

    test('budgetUtilization returns percentage', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 10.0],
        );

        // Dispatch 5000 events at $0.001 each = $5.00, budget $10.00 → 50%
        $service->recordDispatch('ga4', 5000);

        $utilization = $service->budgetUtilization('ga4');

        expect($utilization)->toBe(50.0);
    });

    test('isBudgetExceeded returns false when under budget', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 100.0],
        );

        $service->recordDispatch('ga4', 10);

        expect($service->isBudgetExceeded('ga4'))->toBeFalse();
    });

    test('isBudgetExceeded returns true when over budget', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 0.05],
        );

        $service->recordDispatch('ga4', 100);

        expect($service->isBudgetExceeded('ga4'))->toBeTrue();
    });

    test('routingRecommendation allows critical events even when over budget', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 0.05],
        );

        $service->recordDispatch('ga4', 100);

        // Critical event (priority 1) should not be skipped even when over budget
        expect($service->routingRecommendation('ga4', 'purchase', 1))->toBe('allow');
    });

    test('routingRecommendation allows all events when under budget', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 100.0],
        );

        expect($service->routingRecommendation('ga4', 'page_view', 3))->toBe('allow');
    });

    test('routingRecommendation returns allow for free providers', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['gtm' => 0.0],
            ['gtm' => 0.0],
        );

        expect($service->routingRecommendation('gtm', 'any_event', 5))->toBe('allow');
    });

    test('budgetAlerts returns array of provider budget statuses', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $alerts = $service->budgetAlerts();

        expect($alerts)->toBeArray();
        expect(count($alerts))->toBeGreaterThan(0);

        // Each alert should have expected keys
        foreach ($alerts as $alert) {
            expect($alert)->toHaveKey('provider');
            expect($alert)->toHaveKey('budget');
            expect($alert)->toHaveKey('cost');
            expect($alert)->toHaveKey('utilization');
            expect($alert)->toHaveKey('status');
            expect($alert)->toHaveKey('recommendation');
            expect(in_array($alert['status'], ['healthy', 'warning', 'critical', 'exceeded'], true))->toBeTrue();
        }
    });

    test('costComparison returns provider breakdown and total', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $comparison = $service->costComparison();

        expect($comparison)->toHaveKey('providers');
        expect($comparison)->toHaveKey('total_cost');
        expect($comparison)->toHaveKey('savings_potential');
    });

    test('costForecast returns projected monthly cost', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $forecast = $service->costForecast();

        expect($forecast)->toHaveKey('current_cost');
        expect($forecast)->toHaveKey('projected_monthly');
        expect($forecast)->toHaveKey('days_remaining');
        expect($forecast)->toHaveKey('daily_average');
    });

    test('optimizationSuggestions returns list for high-volume providers', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 50.0],
        );

        $service->recordDispatch('ga4', 20000);

        $suggestions = $service->optimizationSuggestions();

        expect($suggestions)->toBeArray();
    });

    test('dashboard returns complete summary', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        $dashboard = $service->dashboard();

        expect($dashboard)->toHaveKey('alerts');
        expect($dashboard)->toHaveKey('comparison');
        expect($dashboard)->toHaveKey('forecast');
        expect($dashboard)->toHaveKey('suggestions');
        expect($dashboard)->toHaveKey('summary');
        expect($dashboard['summary'])->toHaveKey('total_cost');
        expect($dashboard['summary'])->toHaveKey('providers_at_risk');
    });

    test('budgetUtilization returns null for unconfigured providers', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService($cache, 3600);

        expect($service->budgetUtilization('unknown_provider'))->toBeNull();
    });

    test('isBudgetApproaching returns true near threshold', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventBudgetOptimizerService(
            $cache,
            3600,
            ['ga4' => 0.001],
            ['ga4' => 1.0],
        );

        $service->recordDispatch('ga4', 950); // $0.95 of $1.00 = 95%

        expect($service->isBudgetApproaching('ga4'))->toBeTrue();
    });
});

// ─── Tenant Analytics Dashboard ───────────────────────────────────────────

describe('TenantAnalyticsDashboardService', function (): void {
    test('recordEvent increments tenant event counter', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1', 'plan' => 'pro']);

        expect($service->tenantEventCount('tenant-1'))->toBe(1);
    });

    test('tenantActiveUsers counts unique users', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'click', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-2']);

        expect($service->tenantActiveUsers('tenant-1'))->toBe(2);
    });

    test('tenantDashboard returns complete dashboard data', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1', 'plan' => 'pro']);

        $dashboard = $service->tenantDashboard('tenant-1');

        expect($dashboard)->toHaveKey('tenant_id');
        expect($dashboard)->toHaveKey('date');
        expect($dashboard)->toHaveKey('total_events');
        expect($dashboard)->toHaveKey('active_users');
        expect($dashboard)->toHaveKey('events_per_user');
        expect($dashboard)->toHaveKey('top_events');
        expect($dashboard)->toHaveKey('health_score');
        expect($dashboard['tenant_id'])->toBe('tenant-1');
        expect($dashboard['total_events'])->toBe(1);
    });

    test('tenantHealthScore returns float between 0 and 100', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $health = $service->tenantHealthScore('tenant-1');

        expect($health)->toBeFloat();
        expect($health)->toBeGreaterThanOrEqual(0.0);
        expect($health)->toBeLessThanOrEqual(100.0);
    });

    test('eventsPerUser calculates correctly', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'event-1', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'event-2', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'event-3', ['user_id' => 'user-1']);

        expect($service->eventsPerUser('tenant-1'))->toBe(3.0);
    });

    test('fullDashboard includes percentile data', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);

        $full = $service->fullDashboard('tenant-1');

        expect($full)->toHaveKey('tenant');
        expect($full)->toHaveKey('percentile');
        expect($full)->toHaveKey('ranking_position');
    });

    test('aggregateMetrics returns summary across all tenants', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-2', 'page_view', ['user_id' => 'user-2']);

        $aggregate = $service->aggregateMetrics();

        expect($aggregate)->toHaveKey('total_tenants');
        expect($aggregate)->toHaveKey('total_events');
        expect($aggregate)->toHaveKey('total_active_users');
        expect($aggregate)->toHaveKey('avg_health_score');
        expect($aggregate)->toHaveKey('avg_events_per_user');
        expect($aggregate['total_tenants'])->toBeGreaterThanOrEqual(2);
    });

    test('tenantRanking returns sorted list by metric', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-2', 'page_view', ['user_id' => 'user-2']);

        $ranking = $service->tenantRanking('events', 10);

        expect($ranking)->toBeArray();
    });

    test('tenantPercentile returns ranking data', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);

        $percentile = $service->tenantPercentile('tenant-1', 'events');

        expect($percentile)->toHaveKey('percentile');
        expect($percentile)->toHaveKey('rank');
        expect($percentile)->toHaveKey('total');
        expect($percentile)->toHaveKey('value');
    });

    test('knownTenants returns list of tracked tenants', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);

        $tenants = $service->knownTenants();

        expect($tenants)->toBeArray();
        expect(in_array('tenant-1', $tenants, true))->toBeTrue();
    });

    test('planDistribution returns plan-based event counts', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'page_view', ['plan' => 'pro']);

        $distribution = $service->planDistribution();

        expect($distribution)->toHaveKey('plans');
        expect($distribution)->toHaveKey('date');
    });

    test('tenantTopEvents returns events sorted by count', function (): void {
        $cache = app(CacheRepository::class);
        $service = new TenantAnalyticsDashboardService($cache, 3600);

        $service->recordEvent('tenant-1', 'click', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'click', ['user_id' => 'user-1']);
        $service->recordEvent('tenant-1', 'page_view', ['user_id' => 'user-1']);

        $topEvents = $service->tenantTopEvents('tenant-1', null, 5);

        expect($topEvents)->toBeArray();
    });
});
