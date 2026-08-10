<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\CohortRevenueAttributionService;

/**
 * V660 Cohort Revenue Attribution Service Test.
 *
 * Validates the CohortRevenueAttributionService:
 * - Service instantiation and configuration
 * - Revenue recording and cohort member tracking
 * - Cohort revenue matrix generation
 * - Cohort comparison against averages
 * - LTV projection curves with churn-based decay
 * - Revenue breakdown by cohort type
 * - Top cohorts ranking
 * - Summary and health score
 * - Individual cohort lookups
 * - Revenue by event type breakdown
 * - Config section presence
 * - ServiceProvider registration
 */
test('service can be instantiated with default config', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);

    expect($service->isEnabled())->toBeTrue();
    expect($service->getCurrency())->toBe('USD');
    expect($service->getProjectionMonths())->toBe(12);
    expect($service->getMonthlyChurnRate())->toBe(0.05);
    expect($service->getDefaultArpu())->toBe(49.0);
});

test('config has cohort_revenue section', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();
    expect($config)->toContain("'cohort_revenue' => [");
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_ENABLED');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_CHURN');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_ARPU');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_MAX_COHORTS');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_PROJECTION');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_CURRENCY');
    expect($config)->toContain('ANALYTICS_COHORT_REVENUE_CACHE_TTL');
});

test('service class exists and is final', function (): void {
    expect(class_exists(CohortRevenueAttributionService::class))->toBeTrue();

    $ref = new ReflectionClass(CohortRevenueAttributionService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('service implements proper PHP 8.5 patterns', function (): void {
    $ref = new ReflectionClass(CohortRevenueAttributionService::class);

    // Has strict types declaration
    $content = file_get_contents((string) $ref->getFileName());
    expect($content)->toContain('declare(strict_types=1)');

    // Constructor has return type
    $ctor = $ref->getMethod('__construct');
    expect($ctor->hasReturnType())->toBeTrue();
    expect($ctor->getReturnType()?->getName())->toBe('void');

    // Key public methods have return types
    $methodNames = [
        'isEnabled', 'matrix', 'compare', 'projectLtv', 'byType',
        'topCohorts', 'summary', 'healthScore', 'clear',
        'getCurrency', 'getProjectionMonths', 'getMonthlyChurnRate',
        'getDefaultArpu', 'getCohort', 'cohortIds', 'revenueByEvent',
    ];

    foreach ($methodNames as $name) {
        $method = $ref->getMethod($name);
        expect($method->hasReturnType())->toBeTrue("Method {$name} should have return type");
    }
});

test('recordRevenue and recordCohortMember store data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // Record cohort members
    $service->recordCohortMember('2026-W32', 'signup', 'user-1');
    $service->recordCohortMember('2026-W32', 'signup', 'user-2');
    $service->recordCohortMember('2026-W32', 'signup', 'user-3');

    // Record revenue
    $service->recordRevenue('2026-W32', 'signup', 147.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W32', 'signup', 49.0, 'subscribe', 'user-2');
    $service->recordRevenue('2026-W32', 'signup', 98.0, 'plan_upgrade', 'user-1');

    $cohort = $service->getCohort('2026-W32');
    expect($cohort)->not->toBeNull();
    expect($cohort['users'])->toBe(3);
    expect($cohort['revenue'])->toBe(294.0);
    expect($cohort['events'])->toBe(3);
    expect($cohort['type'])->toBe('signup');
    expect($cohort['avg_revenue'])->toBe(98.0);
    expect($cohort['ltv_estimate'])->toBeGreaterThan(0);

    // Cleanup
    $service->clear();
});

test('matrix returns valid structure with cohort data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordCohortMember('2026-W31', 'signup', 'user-a');
    $service->recordCohortMember('2026-W31', 'signup', 'user-b');
    $service->recordRevenue('2026-W31', 'signup', 100.0, 'purchase', 'user-a');

    $service->recordCohortMember('2026-W32', 'signup', 'user-c');
    $service->recordCohortMember('2026-W32', 'signup', 'user-d');
    $service->recordCohortMember('2026-W32', 'signup', 'user-e');
    $service->recordRevenue('2026-W32', 'signup', 300.0, 'subscribe', 'user-c');
    $service->recordRevenue('2026-W32', 'signup', 50.0, 'purchase', 'user-d');

    $matrix = $service->matrix();

    expect($matrix)->toHaveKeys(['cohorts', 'periods', 'total_revenue', 'total_users', 'avg_ltv', 'best_cohort', 'worst_cohort', 'currency', 'generated_at']);
    expect(count($matrix['cohorts']))->toBe(2);
    expect($matrix['total_revenue'])->toBe(450.0);
    expect($matrix['total_users'])->toBe(5);
    expect($matrix['currency'])->toBe('USD');
    expect($matrix['best_cohort'])->toBe('2026-W32');
    expect($matrix['worst_cohort'])->toBe('2026-W31');

    // Each cohort has required fields
    foreach ($matrix['cohorts'] as $cohort) {
        expect($cohort)->toHaveKeys([
            'cohort', 'cohort_type', 'period', 'users', 'revenue',
            'events', 'avg_revenue_per_user', 'cumulative_revenue',
            'ltv_estimate', 'retention_pct', 'payback_months',
        ]);
    }

    $service->clear();
});

test('compare returns relative performance metrics', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W30', 'signup', 200.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W31', 'signup', 100.0, 'subscribe', 'user-2');
    $service->recordRevenue('2026-W32', 'signup', 400.0, 'purchase', 'user-3');

    $comparison = $service->compare();

    expect($comparison)->toHaveKeys(['comparisons', 'average_revenue', 'average_ltv', 'average_retention', 'total_cohorts']);
    expect($comparison['total_cohorts'])->toBe(3);
    expect($comparison['average_revenue'])->toBe(700.0 / 3);

    // Each comparison has required fields
    foreach ($comparison['comparisons'] as $c) {
        expect($c)->toHaveKeys(['cohort', 'period', 'revenue', 'ltv', 'retention', 'payback_months', 'vs_avg']);
    }

    $service->clear();
});

test('projectLtv returns projection curve with churn decay', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W32', 'signup', 294.0, 'purchase', 'user-1');

    $projection = $service->projectLtv('2026-W32');

    expect($projection)->toHaveKeys(['cohort_id', 'projections', 'total_projected_ltv', 'payback_months', 'currency', 'assumptions']);
    expect($projection['cohort_id'])->toBe('2026-W32');
    expect($projection['currency'])->toBe('USD');
    expect(count($projection['projections']))->toBe(12);

    // Projections should decay (retained_pct decreasing)
    $firstMonth = $projection['projections'][0];
    $lastMonth = $projection['projections'][11];
    expect($firstMonth['month'])->toBe(1);
    expect($lastMonth['month'])->toBe(12);
    expect($firstMonth['retained_pct'])->toBeGreaterThan($lastMonth['retained_pct']);

    // Cumulative revenue should increase
    expect($firstMonth['cumulative_revenue'])->toBeLessThan($lastMonth['cumulative_revenue']);

    // Assumptions
    expect($projection['assumptions'])->toHaveKeys(['churn_rate', 'arpu', 'cac_estimate', 'projection_months', 'total_users']);
    expect($projection['assumptions']['churn_rate'])->toBe(0.05);

    $service->clear();
});

test('projectLtv works with null cohort (all cohorts)', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $projection = $service->projectLtv();

    expect($projection['cohort_id'])->toBeNull();
    expect(count($projection['projections']))->toBe(12);
    expect($projection['total_projected_ltv'])->toBe(0.0); // No data
});

test('byType groups cohorts by their type', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // Signup cohorts
    $service->recordRevenue('2026-W30', 'signup', 100.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W31', 'signup', 200.0, 'subscribe', 'user-2');

    // Trial cohorts
    $service->recordRevenue('2026-W30', 'trial', 50.0, 'purchase', 'user-3');

    $byType = $service->byType();

    expect($byType)->toHaveKeys(['types', 'total_revenue', 'currency']);
    expect($byType['types'])->toHaveKey('signup');
    expect($byType['types'])->toHaveKey('trial');
    expect($byType['total_revenue'])->toBe(350.0);

    expect($byType['types']['signup']['cohorts'])->toBe(2);
    expect($byType['types']['signup']['revenue'])->toBe(300.0);
    expect($byType['types']['trial']['cohorts'])->toBe(1);
    expect($byType['types']['trial']['revenue'])->toBe(50.0);

    $service->clear();
});

test('topCohorts returns ranked cohort list', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W30', 'signup', 100.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W31', 'signup', 500.0, 'subscribe', 'user-2');
    $service->recordRevenue('2026-W32', 'signup', 250.0, 'purchase', 'user-3');

    $top = $service->topCohorts(3);

    expect(count($top))->toBe(3);
    expect($top[0]['revenue'])->toBe(500.0);
    expect($top[0]['cohort'])->toBe('2026-W31');
    expect($top[0]['rank'])->toBe(1);

    expect($top[1]['revenue'])->toBe(250.0);
    expect($top[2]['revenue'])->toBe(100.0);

    // Each entry has rank
    foreach ($top as $entry) {
        expect($entry)->toHaveKeys(['cohort', 'cohort_type', 'users', 'revenue', 'avg_revenue_per_user', 'ltv_estimate', 'retention_pct', 'rank']);
    }

    $service->clear();
});

test('summary returns dashboard-ready data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $summary = $service->summary();

    expect($summary)->toHaveKeys([
        'enabled', 'total_cohorts', 'total_users', 'total_revenue',
        'avg_ltv', 'best_cohort', 'currency', 'top_cohorts', 'projection',
    ]);

    // Empty state
    expect($summary['total_cohorts'])->toBe(0);
    expect($summary['total_revenue'])->toBe(0.0);
    expect($summary['projection']['months'])->toBe(12);
});

test('healthScore returns valid scoring with no data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $health = $service->healthScore();

    expect($health)->toHaveKeys(['score', 'grade', 'details']);
    expect($health['score'])->toBe(0);
    expect($health['grade'])->toBe('F');
    expect($health['details'])->toHaveKeys([
        'cohorts_tracked', 'cohorts_with_revenue', 'revenue_coverage',
        'avg_events_per_cohort', 'data_freshness', 'issues',
    ]);
    expect($health['details']['cohorts_tracked'])->toBe(0);
    expect($health['details']['issues'])->not->toBeEmpty();
});

test('healthScore improves with data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // Add data for multiple cohorts
    $service->recordRevenue('2026-W28', 'signup', 100.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W29', 'signup', 200.0, 'subscribe', 'user-2');
    $service->recordRevenue('2026-W30', 'signup', 150.0, 'purchase', 'user-3');
    $service->recordRevenue('2026-W31', 'signup', 300.0, 'plan_upgrade', 'user-4');
    $service->recordRevenue('2026-W32', 'signup', 250.0, 'subscribe', 'user-5');

    $health = $service->healthScore();

    expect($health['score'])->toBeGreaterThan(0);
    expect($health['details']['cohorts_tracked'])->toBe(5);
    expect($health['details']['cohorts_with_revenue'])->toBe(5);
    expect($health['details']['revenue_coverage'])->toBe(100.0);
    expect(in_array($health['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();

    $service->clear();
});

test('getCohort returns null for unknown cohort', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    expect($service->getCohort('nonexistent'))->toBeNull();
});

test('cohortIds returns tracked cohort identifiers', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W30', 'signup', 100.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W31', 'trial', 50.0, 'subscribe', 'user-2');

    $ids = $service->cohortIds();

    expect($ids)->toContain('2026-W30');
    expect($ids)->toContain('2026-W31');
    expect(count($ids))->toBe(2);

    $service->clear();
});

test('revenueByEvent returns event breakdown for a cohort', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W32', 'signup', 100.0, 'purchase', 'user-1');
    $service->recordRevenue('2026-W32', 'signup', 49.0, 'subscribe', 'user-2');
    $service->recordRevenue('2026-W32', 'signup', 100.0, 'purchase', 'user-3');

    $byEvent = $service->revenueByEvent('2026-W32');

    expect($byEvent)->not->toBeNull();
    expect($byEvent)->toHaveKeys(['cohort', 'events', 'total_events', 'total_revenue']);
    expect($byEvent['cohort'])->toBe('2026-W32');
    expect($byEvent['total_events'])->toBe(3);
    expect($byEvent['total_revenue'])->toBe(249.0);
    expect($byEvent['events'])->toHaveKey('purchase');
    expect($byEvent['events']['purchase'])->toBe(2);
    expect($byEvent['events'])->toHaveKey('subscribe');
    expect($byEvent['events']['subscribe'])->toBe(1);

    $service->clear();
});

test('revenueByEvent returns null for unknown cohort', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    expect($service->revenueByEvent('nonexistent'))->toBeNull();
});

test('clear removes all cohort data', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    $service->recordRevenue('2026-W32', 'signup', 100.0, 'purchase', 'user-1');
    expect($service->getCohort('2026-W32'))->not->toBeNull();

    $service->clear();
    expect($service->getCohort('2026-W32'))->toBeNull();
});

test('duplicate user IDs are not double-counted', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // Record same user twice
    $service->recordCohortMember('2026-W32', 'signup', 'user-1');
    $service->recordCohortMember('2026-W32', 'signup', 'user-1');
    $service->recordCohortMember('2026-W32', 'signup', 'user-2');

    $cohort = $service->getCohort('2026-W32');
    expect($cohort['users'])->toBe(2); // Not 3

    $service->clear();
});

test('end-to-end cohort revenue lifecycle', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // Simulate 3 weekly signup cohorts
    $weeks = ['2026-W30', '2026-W31', '2026-W32'];

    foreach ($weeks as $week) {
        $userCount = 10;
        for ($i = 1; $i <= $userCount; $i++) {
            $service->recordCohortMember($week, 'signup', "user-{$week}-{$i}");
        }

        // Revenue events
        $service->recordRevenue($week, 'signup', 490.0, 'subscribe', "user-{$week}-1");
        $service->recordRevenue($week, 'signup', 245.0, 'purchase', "user-{$week}-2");
        $service->recordRevenue($week, 'signup', 735.0, 'plan_upgrade', "user-{$week}-3");
    }

    // Verify matrix
    $matrix = $service->matrix();
    expect(count($matrix['cohorts']))->toBe(3);
    expect($matrix['total_users'])->toBe(30);
    expect($matrix['total_revenue'])->toBe(4410.0); // 1470 * 3

    // Verify comparison
    $comparison = $service->compare();
    expect($comparison['total_cohorts'])->toBe(3);
    expect($comparison['average_revenue'])->toBe(1470.0);

    // Verify LTV projection
    $projection = $service->projectLtv();
    expect($projection['total_projected_ltv'])->toBeGreaterThan(0);

    // Verify by type
    $byType = $service->byType();
    expect($byType['types']['signup']['cohorts'])->toBe(3);
    expect($byType['types']['signup']['revenue'])->toBe(4410.0);

    // Verify top cohorts
    $top = $service->topCohorts(2);
    expect(count($top))->toBe(2);
    expect($top[0]['rank'])->toBe(1);

    // Verify health
    $health = $service->healthScore();
    expect($health['score'])->toBeGreaterThan(50);

    // Verify summary
    $summary = $service->summary();
    expect($summary['total_cohorts'])->toBe(3);
    expect($summary['total_users'])->toBe(30);

    $service->clear();
});

test('service is registered in ServiceProvider', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $content = file_get_contents((string) $ref->getFileName());

    expect($content)->toContain('CohortRevenueAttributionService');
    expect($content)->toContain("use ZeroBoiler\\Analytics\\Services\\CohortRevenueAttributionService");
});

test('LTV estimation formula: ARPU / churn rate', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // ARPU = $100/month, churn = 5%, LTV should be 100/0.05 = 2000
    $service->recordCohortMember('2026-W32', 'signup', 'user-1');
    $service->recordRevenue('2026-W32', 'signup', 100.0, 'subscribe', 'user-1');

    $cohort = $service->getCohort('2026-W32');
    expect($cohort['avg_revenue'])->toBe(100.0);
    expect($cohort['ltv_estimate'])->toBe(2000.0); // 100 / 0.05

    $service->clear();
});

test('retention estimate decays with cohort age', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $metrics = $manager->metrics();
    $cache = app('cache');
    $config = app('config');

    $service = new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
    $service->clear();

    // New cohort (current week) should have higher retention
    $currentWeek = date('Y-\WW');
    $oldCohort = '2026-W01';

    $service->recordCohortMember($currentWeek, 'signup', 'user-new');
    $service->recordCohortMember($oldCohort, 'signup', 'user-old');

    $newCohort = $service->getCohort($currentWeek);
    $oldCohortData = $service->getCohort($oldCohort);

    expect($newCohort['retention_pct'])->toBeGreaterThan($oldCohortData['retention_pct']);

    $service->clear();
});
