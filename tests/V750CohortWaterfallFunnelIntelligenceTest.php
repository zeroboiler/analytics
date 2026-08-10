<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\CohortWaterfallService;
use ZeroBoiler\Analytics\Services\FunnelDropoffIntelligenceService;

/**
 * V7.5.0 — Cohort Waterfall + Funnel Drop-off Intelligence.
 *
 * Validates the two new services for industry-standard SaaS analytics:
 * - CohortWaterfallService: revenue flow decomposition by cohort
 * - FunnelDropoffIntelligenceService: smart funnel drop-off analysis
 * - Config sections for both services
 * - API endpoints registered
 * - Version consistency (7.5.0 across all entry points)
 * - PHP 8.5 patterns (final, strict types, return types, docblocks)
 * - ServiceProvider registration of both singletons
 */

// ─── Version Consistency ───────────────────────────────────────────────

test('version is 7.5.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('7.6.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('7.6.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 7.6.0');
    expect($js)->toContain("'7.6.0'");

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 7.6.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 7.6.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-7.6.0');

    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->toContain('@version 7.6.0');
});

// ─── Composer Config ───────────────────────────────────────────────────

test('composer.json requires PHP 8.5+ and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toContain('^13');
    expect($composer['type'])->toBe('library');
    expect($composer['license'])->toBe('MIT');
});

// ─── PHP 8.5 Patterns ──────────────────────────────────────────────────

test('CohortWaterfallService uses PHP 8.5 patterns', function (): void {
    $reflection = new ReflectionClass(CohortWaterfallService::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->getFileName())->toContain('CohortWaterfallService.php');

    $constructor = $reflection->getMethod('__construct');
    expect($constructor->hasReturnType())->toBeTrue();
    expect($constructor->getReturnType()?->getName())->toBe('void');

    // Check all public methods have return types
    $methods = ['report', 'quickSummary', 'compare', 'isEnabled', 'stages'];
    foreach ($methods as $method) {
        $m = $reflection->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("{$method}() must have return type");
    }

    // Check strict types
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

test('FunnelDropoffIntelligenceService uses PHP 8.5 patterns', function (): void {
    $reflection = new ReflectionClass(FunnelDropoffIntelligenceService::class);

    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getMethod('__construct');
    expect($constructor->hasReturnType())->toBeTrue();
    expect($constructor->getReturnType()?->getName())->toBe('void');

    $methods = ['analyze', 'comparePeriods', 'isEnabled'];
    foreach ($methods as $method) {
        $m = $reflection->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("{$method}() must have return type");
    }

    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

// ─── Docblock Coverage ─────────────────────────────────────────────────

test('CohortWaterfallService has docblocks on all public methods', function (): void {
    $reflection = new ReflectionClass(CohortWaterfallService::class);
    $contents = file_get_contents($reflection->getFileName());

    $methods = ['report', 'quickSummary', 'compare', 'isEnabled', 'stages'];
    foreach ($methods as $method) {
        $doc = $reflection->getMethod($method)->getDocComment();
        expect($doc)->not()->toBeFalse("{$method}() must have docblock");
    }
});

test('FunnelDropoffIntelligenceService has docblocks on public methods', function (): void {
    $reflection = new ReflectionClass(FunnelDropoffIntelligenceService::class);

    $methods = ['analyze', 'comparePeriods', 'isEnabled'];
    foreach ($methods as $method) {
        $doc = $reflection->getMethod($method)->getDocComment();
        expect($doc)->not()->toBeFalse("{$method}() must have docblock");
    }
});

// ─── CohortWaterfallService Functionality ──────────────────────────────

test('CohortWaterfallService report generates full waterfall data', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    $data = [
        'cohorts' => [
            '2026-07' => [
                'entered' => 1000,
                'trial_starts' => 800,
                'conversions' => 400,
                'active' => 350,
                'renewals' => 300,
                'expansions' => 5000.0,
                'contractions' => 1000.0,
                'churned' => 50,
                'churned_mrr' => 2500.0,
                'mrr' => 40000.0,
            ],
            '2026-08' => [
                'entered' => 1200,
                'trial_starts' => 950,
                'conversions' => 500,
                'active' => 450,
                'renewals' => 380,
                'expansions' => 6500.0,
                'contractions' => 1200.0,
                'churned' => 40,
                'churned_mrr' => 2000.0,
                'mrr' => 50000.0,
            ],
        ],
    ];

    $report = $service->report($data);

    // Structure
    expect($report)->toHaveKeys(['generated_at', 'currency', 'granularity', 'stages', 'cohorts', 'summary', 'insights', 'stage_averages']);
    expect($report['stages'])->toEqual([
        'signed_up', 'trial_started', 'trial_converted',
        'active', 'renewing', 'expansion', 'contraction', 'churned',
    ]);

    // Cohort entries
    expect($report['cohorts'])->toHaveKeys(['2026-07', '2026-08']);
    expect($report['cohorts']['2026-07']['stages'])->toHaveKeys(['signed_up', 'trial_started', 'trial_converted', 'active', 'renewing', 'expansion', 'contraction', 'churned']);

    // Stage-level data
    $signedUp = $report['cohorts']['2026-07']['stages']['signed_up'];
    expect($signedUp)->toHaveKeys(['count', 'revenue', 'drop_off_count', 'drop_off_rate', 'cumulative_rate']);
    expect($signedUp['count'])->toBe(1000);
    expect($signedUp['drop_off_rate'])->toBe(0.0); // First step, no drop-off
    expect($signedUp['cumulative_rate'])->toBe(100.0);

    // Drop-off at trial→converted
    $trialConverted = $report['cohorts']['2026-07']['stages']['trial_converted'];
    expect($trialConverted['count'])->toBe(400);
    expect($trialConverted['drop_off_count'])->toBe(400); // 800 - 400
    expect($trialConverted['drop_off_rate'])->toBe(50.0);

    // Summary
    expect($report['summary'])->toHaveKeys([
        'total_cohorts', 'total_entries', 'total_conversions', 'total_churned',
        'overall_conversion_rate', 'overall_churn_rate', 'total_mrr',
        'net_mrr_movement', 'expansion_mrr', 'contraction_mrr', 'churned_mrr', 'nrr',
    ]);
    expect($report['summary']['total_cohorts'])->toBe(2);
    expect($report['summary']['total_entries'])->toBe(2200);
    expect($report['summary']['total_conversions'])->toBe(900);
    expect($report['summary']['overall_conversion_rate'])->toBeGreaterThanOrEqual(30.0);
});

test('CohortWaterfallService quickSummary provides lightweight data', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    $data = [
        'cohorts' => [
            '2026-08' => [
                'entered' => 500,
                'conversions' => 250,
                'churned' => 25,
                'mrr' => 20000.0,
                'expansions' => 3000.0,
                'contractions' => 500.0,
                'churned_mrr' => 1000.0,
            ],
        ],
    ];

    $summary = $service->quickSummary($data);

    expect($summary)->toHaveKeys([
        'total_entries', 'total_conversions', 'total_churned',
        'conversion_rate', 'churn_rate', 'nrr', 'net_mrr_movement', 'generated_at',
    ]);
    expect($summary['total_entries'])->toBe(500);
    expect($summary['conversion_rate'])->toBe(50.0);
    expect($summary['churn_rate'])->toBe(10.0);
    expect($summary['net_mrr_movement'])->toBe(1500.0); // 3000 - 500 - 1000
});

test('CohortWaterfallService compare returns delta analysis', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    $cohortA = [
        'period' => '2026-07',
        'entered' => 1000,
        'trial_starts' => 800,
        'conversions' => 400,
        'active' => 350,
        'renewals' => 300,
        'expansions' => 5000.0,
        'contractions' => 1000.0,
        'churned' => 50,
        'churned_mrr' => 2500.0,
        'mrr' => 40000.0,
    ];

    $cohortB = [
        'period' => '2026-08',
        'entered' => 1200,
        'trial_starts' => 950,
        'conversions' => 500,
        'active' => 450,
        'renewals' => 380,
        'expansions' => 6500.0,
        'contractions' => 1200.0,
        'churned' => 40,
        'churned_mrr' => 2000.0,
        'mrr' => 50000.0,
    ];

    $comparison = $service->compare($cohortA, $cohortB);

    expect($comparison)->toHaveKeys(['period_a', 'period_b', 'comparison']);
    expect($comparison['period_a'])->toBe('2026-07');
    expect($comparison['period_b'])->toBe('2026-08');
    expect($comparison['comparison'])->toHaveKeys(['signed_up', 'trial_started', 'trial_converted', 'churned']);

    // Signed up: 1000 → 1200 = +200 (+20%)
    expect($comparison['comparison']['signed_up']['delta'])->toBe(200);
    expect($comparison['comparison']['signed_up']['delta_pct'])->toBe(20.0);
});

test('CohortWaterfallService stages returns correct defaults', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    $stages = $service->stages();

    expect($stages)->toEqual([
        'signed_up', 'trial_started', 'trial_converted',
        'active', 'renewing', 'expansion', 'contraction', 'churned',
    ]);
    expect($service->isEnabled())->toBeTrue();
});

test('CohortWaterfallService handles empty cohorts gracefully', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    $report = $service->report(['cohorts' => []]);

    expect($report['cohorts'])->toBeEmpty();
    expect($report['summary']['total_entries'])->toBe(0);
    expect($report['summary']['overall_conversion_rate'])->toBe(0.0);
});

test('CohortWaterfallService report generates insights', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    // Low conversion scenario
    $data = [
        'cohorts' => [
            '2026-08' => [
                'entered' => 1000,
                'trial_starts' => 100,
                'conversions' => 15, // 1.5% conversion (very low)
                'active' => 10,
                'renewals' => 8,
                'expansions' => 500.0,
                'contractions' => 200.0,
                'churned' => 2,
                'churned_mrr' => 100.0,
                'mrr' => 10000.0,
            ],
        ],
    ];

    $report = $service->report($data);

    expect($report['insights'])->not()->toBeEmpty();
    // Should flag low conversion
    $hasConversionInsight = collect($report['insights'])->contains(
        fn (string $i): bool => str_contains($i, 'Low trial-to-paid conversion'),
    );
    expect($hasConversionInsight)->toBeTrue();
});

// ─── FunnelDropoffIntelligenceService Functionality ─────────────────────

test('FunnelDropoffIntelligenceService analyze produces full funnel analysis', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    $steps = ['landing', 'signup', 'trial', 'subscribe', 'active'];
    $data = [
        'step_counts' => [
            'landing' => 10000,
            'signup' => 3000,
            'trial' => 1500,
            'subscribe' => 450,
            'active' => 400,
        ],
        'step_times' => [
            'landing' => 0.0,
            'signup' => 45.0,
            'trial' => 120.0,
            'subscribe' => 300.0,
            'active' => 0.0,
        ],
    ];

    $analysis = $service->analyze($steps, $data);

    // Structure
    expect($analysis)->toHaveKeys(['generated_at', 'funnel', 'analysis', 'bottlenecks', 'anomalies', 'summary', 'recommendations']);
    expect($analysis['funnel'])->toBe($steps);
    expect($analysis['analysis'])->toHaveCount(5);

    // Step-level analysis
    $landing = $analysis['analysis'][0];
    expect($landing['step'])->toBe('landing');
    expect($landing['count'])->toBe(10000);
    expect($landing['is_entry'])->toBeTrue();
    expect($landing['drop_off_rate'])->toBe(0.0);

    $signup = $analysis['analysis'][1];
    expect($signup['drop_off_count'])->toBe(7000); // 10000 - 3000
    expect($signup['drop_off_rate'])->toBe(70.0);
    expect($signup['avg_time_seconds'])->toBe(45.0);

    // Bottleneck detection (>50% drop-off)
    expect($analysis['bottlenecks'])->not()->toBeEmpty();
    expect($analysis['bottlenecks'][0]['step'])->toBe('signup');
    expect($analysis['bottlenecks'][0]['drop_off_rate'])->toBe(70.0);
    expect($analysis['bottlenecks'][0]['severity'])->toBe('critical');

    // Summary
    expect($analysis['summary']['entry_count'])->toBe(10000);
    expect($analysis['summary']['total_conversions'])->toBe(400);
    expect($analysis['summary']['overall_conversion_rate'])->toBe(4.0);
    expect($analysis['summary']['has_bottlenecks'])->toBeTrue();

    // Recommendations
    expect($analysis['recommendations'])->not()->toBeEmpty();
});

test('FunnelDropoffIntelligenceService detects anomalies', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    $steps = ['step1', 'step2', 'step3', 'step4'];
    $data = [
        'step_counts' => [
            'step1' => 1000,
            'step2' => 800,  // 20% drop-off
            'step3' => 100,  // 87.5% drop-off — massive spike
            'step4' => 90,
        ],
        'step_times' => [
            'step1' => 0.0,
            'step2' => 10.0,
            'step3' => 50.0,
            'step4' => 0.0,
        ],
    ];

    $analysis = $service->analyze($steps, $data);

    // Should detect anomaly at step3
    expect($analysis['anomalies'])->not()->toBeEmpty();
    expect($analysis['anomalies'][0]['step'])->toBe('step3');
    expect($analysis['anomalies'][0]['spike_multiplier'])->toBeGreaterThan(2.0);
});

test('FunnelDropoffIntelligenceService comparePeriods works', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    $steps = ['landing', 'signup', 'trial', 'subscribe'];
    $periodA = [
        'step_counts' => ['landing' => 1000, 'signup' => 300, 'trial' => 150, 'subscribe' => 45],
        'step_times' => ['landing' => 0, 'signup' => 30, 'trial' => 60, 'subscribe' => 120],
    ];
    $periodB = [
        'step_counts' => ['landing' => 1200, 'signup' => 400, 'trial' => 200, 'subscribe' => 70],
        'step_times' => ['landing' => 0, 'signup' => 25, 'trial' => 55, 'subscribe' => 100],
    ];

    $comparison = $service->comparePeriods($steps, $periodA, $periodB);

    expect($comparison)->toHaveKeys(['comparison', 'improved', 'degraded', 'unchanged']);
    expect($comparison['comparison'])->toHaveCount(4);
    expect($comparison['comparison'][0]['step'])->toBe('landing');
    expect($comparison['comparison'][0]['count_delta'])->toBe(200);
    expect($comparison['comparison'][0]['count_delta_pct'])->toBe(20.0);

    // signup improved (300→400, +33%)
    expect($comparison['improved'])->toContain('signup');
});

test('FunnelDropoffIntelligenceService isEnabled', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    expect($service->isEnabled())->toBeTrue();
});

test('FunnelDropoffIntelligenceService handles empty steps', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    $analysis = $service->analyze([], []);

    expect($analysis['analysis'])->toBeEmpty();
    expect($analysis['bottlenecks'])->toBeEmpty();
});

// ─── Config Sections ───────────────────────────────────────────────────

test('config has cohort_waterfall section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';

    expect($config)->toHaveKey('analytics');
    expect($config['analytics'])->toHaveKey('cohort_waterfall');
    expect($config['analytics']['cohort_waterfall'])->toHaveKeys([
        'enabled', 'cache_ttl', 'granularity', 'currency', 'projection_months',
    ]);
    expect($config['analytics']['cohort_waterfall']['enabled'])->toBeTrue();
});

test('config has funnel_intelligence section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';

    expect($config['analytics'])->toHaveKey('funnel_intelligence');
    expect($config['analytics']['funnel_intelligence'])->toHaveKeys([
        'enabled', 'cache_ttl', 'bottleneck_threshold', 'anomaly_threshold',
    ]);
    expect($config['analytics']['funnel_intelligence']['enabled'])->toBeTrue();
    expect($config['analytics']['funnel_intelligence']['bottleneck_threshold'])->toBe(50.0);
    expect($config['analytics']['funnel_intelligence']['anomaly_threshold'])->toBe(2.0);
});

// ─── Route Registration ────────────────────────────────────────────────

test('routes file includes cohort-waterfall endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($routes)->toContain("cohort-waterfall");
    expect($routes)->toContain('cohortWaterfall');
    expect($routes)->toContain('cohortWaterfallSummary');
    expect($routes)->toContain('cohortWaterfallCompare');
    expect($routes)->toContain('cohortWaterfallStages');
});

test('routes file includes funnel-intelligence endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($routes)->toContain('funnel-intelligence');
    expect($routes)->toContain('funnelIntelligence');
    expect($routes)->toContain('funnelIntelligenceCompare');
});

test('ServiceProvider registers cohort-waterfall routes', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($provider)->toContain('cohortWaterfall');
    expect($provider)->toContain('cohortWaterfallSummary');
    expect($provider)->toContain('cohortWaterfallCompare');
    expect($provider)->toContain('cohortWaterfallStages');
});

test('ServiceProvider registers funnel-intelligence routes', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($provider)->toContain('funnelIntelligence');
    expect($provider)->toContain('funnelIntelligenceCompare');
});

// ─── ServiceProvider Singleton Registration ────────────────────────────

test('ServiceProvider registers CohortWaterfallService singleton', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($provider)->toContain('CohortWaterfallService::class');
    expect($provider)->toContain('new CohortWaterfallService($cache, $config)');
});

test('ServiceProvider registers FunnelDropoffIntelligenceService singleton', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($provider)->toContain('FunnelDropoffIntelligenceService::class');
    expect($provider)->toContain('new FunnelDropoffIntelligenceService($cache, $config)');
});

// ─── Event Catalog Integrity ───────────────────────────────────────────

test('event catalog has 100+ events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
});

test('event catalog validates cleanly', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

// ─── Bottleneck Severity Levels ────────────────────────────────────────

test('FunnelDropoffIntelligenceService bottleneck severity classification', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new FunnelDropoffIntelligenceService($cache, $config);

    // 80% drop-off = critical
    $result = $service->analyze(['step1', 'step2'], [
        'step_counts' => ['step1' => 100, 'step2' => 20],
        'step_times' => ['step1' => 0, 'step2' => 10],
    ]);

    expect($result['bottlenecks'][0]['severity'])->toBe('critical');
});

// ─── CohortWaterfall Revenue Analysis ───────────────────────────────────

test('CohortWaterfallService correctly computes NRR', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new CohortWaterfallService($cache, $config);

    // MRR=100000, expansion=5000, contraction=1000, churned=2000
    // ending MRR = 100000 + 5000 - 1000 - 2000 = 102000
    // NRR = 102000/100000 = 102%
    $data = [
        'cohorts' => [
            '2026-08' => [
                'entered' => 500,
                'trial_starts' => 400,
                'conversions' => 200,
                'active' => 190,
                'renewals' => 180,
                'expansions' => 5000.0,
                'contractions' => 1000.0,
                'churned' => 10,
                'churned_mrr' => 2000.0,
                'mrr' => 100000.0,
            ],
        ],
    ];

    $report = $service->report($data);

    expect($report['summary']['nrr'])->toBe(102.0);
    expect($report['summary']['net_mrr_movement'])->toBe(2000.0); // 5000 - 1000 - 2000
});

// ─── File Existence ───────────────────────────────────────────────────

test('source files exist', function (): void {
    expect(file_exists(__DIR__ . '/../src/Services/CohortWaterfallService.php'))->toBeTrue();
    expect(file_exists(__DIR__ . '/../src/Services/FunnelDropoffIntelligenceService.php'))->toBeTrue();
});
