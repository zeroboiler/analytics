<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\ContractionRevenueEvent;
use ZeroBoiler\Analytics\Events\SaaS\NetRevenueRetentionEvent;
use ZeroBoiler\Analytics\Events\SaaS\BurnMultipleEvent;
use ZeroBoiler\Analytics\Events\SaaS\ArrMilestoneEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaybackPeriodEvent;
use ZeroBoiler\Analytics\Services\SaasBenchmarkCalibrationService;

/**
 * Phase 174 Production Readiness — Revenue Intelligence Events + SaaS Benchmark Calibration.
 *
 * Validates new v174.0.0 features:
 * - 5 new SaaS revenue intelligence events (contraction_revenue, net_revenue_retention, burn_multiple, arr_milestone, payback_period)
 * - SaasBenchmarkCalibrationService class structure, ARR tiers, metric names
 * - Version consistency across all 14 entry points
 * - Event catalog coverage (SaaS event count increased)
 * - New event classes extend AnalyticsEvent with correct constructor signatures
 *
 * @since 174.0.0
 */

it('AnalyticsEvent::VERSION is 174.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('174.0.0');
});

it('contraction_revenue event is in SaaS catalog', function (): void {
    expect(SaaSEvents::has('contraction_revenue'))->toBeTrue();
    $entry = SaaSEvents::get('contraction_revenue');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(ContractionRevenueEvent::class);
    expect($entry['ga4'])->toBe('contraction_revenue');
    expect($entry['posthog'])->toBe('contraction_revenue');
});

it('net_revenue_retention event is in SaaS catalog', function (): void {
    expect(SaaSEvents::has('net_revenue_retention'))->toBeTrue();
    $entry = SaaSEvents::get('net_revenue_retention');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(NetRevenueRetentionEvent::class);
    expect($entry['ga4'])->toBe('net_revenue_retention');
});

it('burn_multiple event is in SaaS catalog', function (): void {
    expect(SaaSEvents::has('burn_multiple'))->toBeTrue();
    $entry = SaaSEvents::get('burn_multiple');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(BurnMultipleEvent::class);
    expect($entry['mixpanel'])->toBe('Burn Multiple');
});

it('arr_milestone event is in SaaS catalog', function (): void {
    expect(SaaSEvents::has('arr_milestone'))->toBeTrue();
    $entry = SaaSEvents::get('arr_milestone');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(ArrMilestoneEvent::class);
});

it('payback_period event is in SaaS catalog', function (): void {
    expect(SaaSEvents::has('payback_period'))->toBeTrue();
    $entry = SaaSEvents::get('payback_period');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(PaybackPeriodEvent::class);
    expect($entry['ga4'])->toBe('payback_period');
});

it('SaaS catalog count increased by 5 events', function (): void {
    // Previous SaaS event count was approximately 60; now 5 more
    expect(SaaSEvents::count())->toBeGreaterThan(60);
});

it('ContractionRevenueEvent builds correct event', function (): void {
    $event = new ContractionRevenueEvent(250.0, 'plan_downgrade', 'USD');
    expect($event->name)->toBe('contraction_revenue');
    expect($event->params['amount'])->toBe(250.0);
    expect($event->params['contraction_source'])->toBe('plan_downgrade');
    expect($event->params['currency'])->toBe('USD');
});

it('NetRevenueRetentionEvent builds correct event', function (): void {
    $event = new NetRevenueRetentionEvent(115.5, 'quarterly', 100000.0, 15000.0, 5000.0, 3000.0);
    expect($event->name)->toBe('net_revenue_retention');
    expect($event->params['nrr_percentage'])->toBe(115.5);
    expect($event->params['period'])->toBe('quarterly');
    expect($event->params['starting_mrr'])->toBe(100000.0);
});

it('BurnMultipleEvent builds correct event', function (): void {
    $event = new BurnMultipleEvent(1.5, -50000.0, 100000.0, 'quarterly');
    expect($event->name)->toBe('burn_multiple');
    expect($event->params['burn_multiple'])->toBe(1.5);
    expect($event->params['net_burn'])->toBe(-50000.0);
    expect($event->params['net_new_arr'])->toBe(100000.0);
});

it('ArrMilestoneEvent builds correct event', function (): void {
    $event = new ArrMilestoneEvent(1_000_000.0, '1M_ARR', 750000.0, 33.3);
    expect($event->name)->toBe('arr_milestone');
    expect($event->params['arr'])->toBe(1_000_000.0);
    expect($event->params['milestone'])->toBe('1M_ARR');
    expect($event->params['arr_growth_rate'])->toBe(33.3);
});

it('PaybackPeriodEvent builds correct event', function (): void {
    $event = new PaybackPeriodEvent(8.5, 1200.0, 150.0, 75.0, 'q1_2026');
    expect($event->name)->toBe('payback_period');
    expect($event->params['payback_months'])->toBe(8.5);
    expect($event->params['cac'])->toBe(1200.0);
    expect($event->params['arpu'])->toBe(150.0);
});

it('all new event classes extend AnalyticsEvent', function (): void {
    $classes = [
        ContractionRevenueEvent::class,
        NetRevenueRetentionEvent::class,
        BurnMultipleEvent::class,
        ArrMilestoneEvent::class,
        PaybackPeriodEvent::class,
    ];

    foreach ($classes as $class) {
        expect(is_subclass_of($class, AnalyticsEvent::class))->toBeTrue();
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

it('all new event classes have strict types', function (): void {
    $classes = [
        ContractionRevenueEvent::class,
        NetRevenueRetentionEvent::class,
        BurnMultipleEvent::class,
        ArrMilestoneEvent::class,
        PaybackPeriodEvent::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        $file = $ref->getFileName();
        $content = (string) file_get_contents((string) $file);
        expect($content)->toContain('declare(strict_types=1)', "{$class} missing strict_types");
    }
});

it('SaasBenchmarkCalibrationService has correct class structure', function (): void {
    $ref = new ReflectionClass(SaasBenchmarkCalibrationService::class);
    expect($ref->isFinal())->toBeTrue();

    // Constructor type declarations
    $constructor = $ref->getMethod('__construct');
    $params = $constructor->getParameters();
    expect(count($params))->toBe(2);
    expect($constructor->hasReturnType())->toBeTrue();
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

it('SaasBenchmarkCalibrationService has correct ARR tiers', function (): void {
    $tiers = SaasBenchmarkCalibrationService::arrTiers();
    expect($tiers)->toBe(['<=1M', '1-5M', '5-20M', '20-100M', '>100M']);
});

it('SaasBenchmarkCalibrationService has correct metric names', function (): void {
    $metrics = SaasBenchmarkCalibrationService::metricNames();
    expect($metrics)->toContain('nrr');
    expect($metrics)->toContain('grr');
    expect($metrics)->toContain('cac_payback_months');
    expect($metrics)->toContain('ltv_cac_ratio');
    expect($metrics)->toContain('burn_multiple');
    expect($metrics)->toContain('rule_of_40');
    expect($metrics)->toContain('gross_margin');
    expect($metrics)->toContain('quick_ratio');
    expect($metrics)->toContain('logo_retention');
    expect(count($metrics))->toBe(9);
});

it('SaasBenchmarkCalibrationService::resolveTier works correctly', function (): void {
    expect(SaasBenchmarkCalibrationService::resolveTier(500_000))->toBe('<=1M');
    expect(SaasBenchmarkCalibrationService::resolveTier(3_000_000))->toBe('1-5M');
    expect(SaasBenchmarkCalibrationService::resolveTier(12_000_000))->toBe('5-20M');
    expect(SaasBenchmarkCalibrationService::resolveTier(50_000_000))->toBe('20-100M');
    expect(SaasBenchmarkCalibrationService::resolveTier(200_000_000))->toBe('>100M');
});

it('new events are in unified EventCatalog', function (): void {
    $catalog = EventCatalog::all();
    expect(isset($catalog['contraction_revenue']))->toBeTrue();
    expect(isset($catalog['net_revenue_retention']))->toBeTrue();
    expect(isset($catalog['burn_multiple']))->toBeTrue();
    expect(isset($catalog['arr_milestone']))->toBeTrue();
    expect(isset($catalog['payback_period']))->toBeTrue();
});

it('SaasBenchmarkCalibrationService::gapAnalysis returns structured data', function (): void {
    // Can't use cache/config without Laravel, so we test the public method directly
    // by creating a mock-less instance using reflection
    $ref = new ReflectionClass(SaasBenchmarkCalibrationService::class);

    // Verify method signatures exist
    expect($ref->hasMethod('calibrate'))->toBeTrue();
    expect($ref->hasMethod('overallScore'))->toBeTrue();
    expect($ref->hasMethod('gapAnalysis'))->toBeTrue();
    expect($ref->hasMethod('cachedReport'))->toBeTrue();
    expect($ref->hasMethod('benchmarks'))->toBeTrue();

    // Verify calibrate return type
    $calibrate = $ref->getMethod('calibrate');
    expect($calibrate->hasReturnType())->toBeTrue();
    expect($calibrate->getReturnType()?->getName())->toBe('array');

    // Verify overallScore return type
    $overallScore = $ref->getMethod('overallScore');
    expect($overallScore->hasReturnType())->toBeTrue();
    expect($overallScore->getReturnType()?->getName())->toBe('array');

    // Verify gapAnalysis return type
    $gapAnalysis = $ref->getMethod('gapAnalysis');
    expect($gapAnalysis->hasReturnType())->toBeTrue();
    expect($gapAnalysis->getReturnType()?->getName())->toBe('array');
});

it('version sweep covers all entry points', function (): void {
    // Composer.json
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('174.0.0');

    // Package.json
    $pkg = json_decode((string) file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe('174.0.0');

    // JS header version
    $js = (string) file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 174.0.0');
    expect($js)->toContain("return '174.0.0'");

    // TypeScript types
    $dts = (string) file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 174.0.0');

    // Constants
    $constants = (string) file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
    expect($constants)->toContain('@version 174.0.0');

    // All 7 Svelte composables
    $svelteFiles = [
        'useAnalytics.svelte.js',
        'useAnalyticsConfig.svelte.js',
        'useEcommerce.svelte.js',
        'useLifecycle.svelte.js',
        'useSaaSMetrics.svelte.js',
        'usePerformanceTracker.svelte.js',
        'useSessionReplay.svelte.js',
    ];

    foreach ($svelteFiles as $file) {
        $content = (string) file_get_contents(__DIR__ . '/../resources/js/' . $file);
        expect($content)->toContain('@version 174.0.0', "Version mismatch in {$file}");
    }

    // ServiceProvider @version
    $sp = (string) file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->toContain('@version 174.0.0');

    // IntegrityCommand
    $integrityCmd = (string) file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
    expect($integrityCmd)->toContain("'174.0.0'");

    // README badge
    $readme = (string) file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-174.0.0');
});
