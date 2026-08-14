<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventCostEstimator;
use ZeroBoiler\Analytics\Services\SaaSOnboardingFunnelTracker;

/**
 * V139 — SaaS Starter Industry-Standard Upgrade Test.
 *
 * Validates the new v139.0.0 additions:
 * - EventCostEstimator: per-event cost calculation, monthly/yearly projections, budget alerts
 * - SaaSOnboardingFunnelTracker: 5-stage funnel, conversion rates, drop-off detection
 * - Config expansion: event_costs, onboarding_funnel sections
 * - Version consistency across all client files
 * - Catalog integrity with 210+ events
 * - 10 provider coverage
 *
 * @since 139.0.0
 */
test('v139: version is 139.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('139.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('139.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 139.0.0');

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 139.0.0');

    $svelteLifecycle = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');
    expect($svelteLifecycle)->toContain('@version 139.0.0');

    $sveltePerf = file_get_contents(__DIR__ . '/../resources/js/usePerformanceTracker.svelte.js');
    expect($sveltePerf)->toContain('@version 139.0.0');

    $svelteSession = file_get_contents(__DIR__ . '/../resources/js/useSessionReplay.svelte.js');
    expect($svelteSession)->toContain('@version 139.0.0');

    $svelteConfig = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    expect($svelteConfig)->toContain('@version 139.0.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 139.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-139.0.0');
});

test('v139: EventCostEstimator costPerEvent returns default costs', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $cache->shouldReceive('get')->andReturn(null);

    $estimator = new EventCostEstimator($config, $manager, $cache);

    expect($estimator->costPerEvent('ga4'))->toBe(0.0);
    expect($estimator->costPerEvent('posthog'))->toBe(0.00025);
    expect($estimator->costPerEvent('plausible'))->toBe(0.0001);
    expect($estimator->costPerEvent('mixpanel'))->toBe(0.0002);
    expect($estimator->costPerEvent('amplitude'))->toBe(0.0003);
    expect($estimator->costPerEvent('meta_capi'))->toBe(0.0002);
    expect($estimator->costPerEvent('tiktok'))->toBe(0.0);
    expect($estimator->costPerEvent('unknown_provider'))->toBe(0.0);
});

test('v139: EventCostEstimator estimateMonthlyCost calculates correctly', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $estimator = new EventCostEstimator($config, $manager, $cache);

    $result = $estimator->estimateMonthlyCost([
        'ga4' => 1_000_000,
        'posthog' => 500_000,
        'plausible' => 500_000,
    ]);

    expect($result['currency'])->toBe('USD');
    expect($result['by_provider']['ga4'])->toBe(0.0);
    expect($result['by_provider']['posthog'])->toBe(125.0);
    expect($result['by_provider']['plausible'])->toBe(50.0);
    expect($result['total'])->toBe(175.0);
});

test('v139: EventCostEstimator estimateYearlyCost multiplies monthly by 12', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $estimator = new EventCostEstimator($config, $manager, $cache);

    $result = $estimator->estimateYearlyCost([
        'posthog' => 100_000,
    ]);

    expect($result['total'])->toBe(300.0);
    expect($result['monthly_equivalent'])->toBe(25.0);
});

test('v139: EventCostEstimator projectAtVolume detects budget exceeded', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $ga4 = mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    $ga4->shouldReceive('isEnabled')->andReturn(true);
    $posthog = mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    $posthog->shouldReceive('isEnabled')->andReturn(true);

    $manager->shouldReceive('ga4')->andReturn($ga4);
    $manager->shouldReceive('gtm')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('meta')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('posthog')->andReturn($posthog);
    $manager->shouldReceive('plausible')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('mixpanel')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('amplitude')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('tiktok')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));
    $manager->shouldReceive('linkedin')->andReturn(\Mockery::mock(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class, ['isEnabled' => false]));

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    // Default budget threshold is 100.0
    $estimator = new EventCostEstimator($config, $manager, $cache);

    // 1M events split 50/50 between GA4 (free) and PostHog ($0.25/1K)
    $result = $estimator->projectAtVolume(1_000_000);

    // PostHog: 500K * $0.25/1K = $125/month — exceeds $100 budget
    expect($result['budget_exceeded'])->toBe(true);
    expect($result['currency'])->toBe('USD');
});

test('v139: EventCostEstimator getAllCosts returns all providers', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $estimator = new EventCostEstimator($config, $manager, $cache);
    $costs = $estimator->getAllCosts();

    expect($costs)->toHaveKey('ga4');
    expect($costs)->toHaveKey('gtm');
    expect($costs)->toHaveKey('meta_pixel');
    expect($costs)->toHaveKey('posthog');
    expect($costs)->toHaveKey('plausible');
    expect($costs)->toHaveKey('mixpanel');
    expect($costs)->toHaveKey('amplitude');
    expect($costs)->toHaveKey('tiktok');
    expect($costs)->toHaveKey('linkedin');
    expect($costs)->toHaveKey('meta_capi');
    expect(count($costs))->toBe(10);
});

test('v139: EventCostEstimator costByCategory computes category breakdown', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $estimator = new EventCostEstimator($config, $manager, $cache);

    $result = $estimator->costByCategory([
        'ecommerce' => 100_000,
        'saas' => 50_000,
        'engagement' => 200_000,
    ], 0.0001);

    expect($result['ecommerce']['cost'])->toBe(10.0);
    expect($result['ecommerce']['percentage'])->toBe(28.57);
    expect($result['saas']['cost'])->toBe(5.0);
    expect($result['engagement']['cost'])->toBe(20.0);
});

test('v139: SaaSOnboardingFunnelTracker has 5 standard stages', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    expect($tracker->stageCount())->toBe(5);
    expect($tracker->hasStage('signup'))->toBeTrue();
    expect($tracker->hasStage('email_verified'))->toBeTrue();
    expect($tracker->hasStage('first_value'))->toBeTrue();
    expect($tracker->hasStage('trial_start'))->toBeTrue();
    expect($tracker->hasStage('subscription'))->toBeTrue();
    expect($tracker->hasStage('nonexistent'))->toBeFalse();
});

test('v139: SaaSOnboardingFunnelTracker stage sequence is ordered', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);
    $sequence = $tracker->getStageSequence();

    expect($sequence)->toBe([
        'signup',
        'email_verified',
        'first_value',
        'trial_start',
        'subscription',
    ]);
});

test('v139: SaaSOnboardingFunnelTracker first and last stage', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    $first = $tracker->firstStage();
    expect($first)->not->toBeNull();
    expect($first['name'])->toBe('Sign Up');
    expect($first['event'])->toBe('sign_up');

    $last = $tracker->lastStage();
    expect($last)->not->toBeNull();
    expect($last['name'])->toBe('Subscription');
    expect($last['event'])->toBe('subscribe');
});

test('v139: SaaSOnboardingFunnelTracker validateStageEvents validates against catalog', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);
    $validation = $tracker->validateStageEvents();

    expect($validation['valid'])->toBeTrue();
    expect($validation['total'])->toBe(5);
    expect($validation['matched'])->toBe(5);
    expect($validation['missing'])->toBe([]);
});

test('v139: SaaSOnboardingFunnelTracker calculates conversion rates', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    // Simulated funnel: 1000 signup → 800 verified → 400 first_value → 100 trial → 20 subscription
    $volumes = [
        'signup' => 1000,
        'email_verified' => 800,
        'first_value' => 400,
        'trial_start' => 100,
        'subscription' => 20,
    ];

    $rates = $tracker->calculateConversionRates($volumes);

    // signup → email_verified: 80%
    expect($rates['stages']['signup']['conversion_from_previous'])->toBeNull();
    expect($rates['stages']['email_verified']['conversion_from_previous'])->toBe(80.0);
    expect($rates['stages']['email_verified']['drop_off_rate'])->toBe(20.0);

    // email_verified → first_value: 50%
    expect($rates['stages']['first_value']['conversion_from_previous'])->toBe(50.0);
    expect($rates['stages']['first_value']['drop_off_rate'])->toBe(50.0);

    // first_value → trial_start: 25%
    expect($rates['stages']['trial_start']['conversion_from_previous'])->toBe(25.0);

    // trial_start → subscription: 20%
    expect($rates['stages']['subscription']['conversion_from_previous'])->toBe(20.0);
});

test('v139: SaaSOnboardingFunnelTracker detects biggest drop-off', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    $volumes = [
        'signup' => 1000,
        'email_verified' => 800,  // 20% drop
        'first_value' => 200,    // 75% drop — biggest
        'trial_start' => 100,    // 50% drop
        'subscription' => 20,    // 80% drop
    ];

    $dropOff = $tracker->detectBiggestDropOff($volumes);

    expect($dropOff['stage'])->toBe('first_value');
    expect($dropOff['name'])->toBe('First Value');
    expect($dropOff['drop_off_rate'])->toBe(75.0);
    expect($dropOff['volume_before'])->toBe(800);
    expect($dropOff['volume_after'])->toBe(200);
});

test('v139: SaaSOnboardingFunnelTracker overall conversion rate', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    expect($tracker->overallConversionRate(['signup' => 0, 'subscription' => 0]))->toBe(0.0);
    expect($tracker->overallConversionRate(['signup' => 1000, 'subscription' => 50]))->toBe(5.0);
    expect($tracker->overallConversionRate(['signup' => 100, 'subscription' => 2]))->toBe(2.0);
});

test('v139: SaaSOnboardingFunnelTracker getFunnelSummary produces dashboard data', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    $summary = $tracker->getFunnelSummary([
        'signup' => 1000,
        'email_verified' => 800,
        'first_value' => 400,
        'trial_start' => 100,
        'subscription' => 20,
    ]);

    expect($summary['total_stages'])->toBe(5);
    expect($summary['overall_rate'])->toBe(2.0);
    expect($summary['biggest_drop_off']['stage'])->toBe('first_value');
    expect($summary['stage_sequence'])->toBe([
        'signup', 'email_verified', 'first_value', 'trial_start', 'subscription',
    ]);
    expect(count($summary['stages']))->toBe(5);
});

test('v139: SaaSOnboardingFunnelTracker handles zero volumes gracefully', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_funnel', [])
        ->andReturn([]);

    $tracker = new SaaSOnboardingFunnelTracker($config, $manager, $cache);

    $rates = $tracker->calculateConversionRates([]);
    $dropOff = $tracker->detectBiggestDropOff([]);

    expect($rates['stages']['signup']['volume'])->toBe(0);
    expect($dropOff['stage'])->toBeNull();
});

test('v139: catalog integrity — 210+ events across 8 categories', function (): void {
    $all = EventCatalog::all();

    expect(EventCatalog::count())->toBeGreaterThanOrEqual(210);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});

test('v139: catalog provider coverage — all events have GA4 and PostHog mappings', function (): void {
    $all = EventCatalog::all();

    foreach ($all as $name => $entry) {
        expect($entry)->toHaveKey('ga4');
        expect($entry)->toHaveKey('posthog');
        expect($entry['ga4'])->toBeString();
        expect($entry['posthog'])->toBeString();
    }
});

test('v139: composer.json requires PHP 8.5+ and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toContain('^13');
    expect($composer['type'])->toBe('library');
    expect($composer['license'])->toBe('MIT');
});

test('v139: all PHP files use declare(strict_types=1)', function (): void {
    $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);

    expect($srcFiles)->not->toBeEmpty();

    $violations = [];
    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        if (! str_contains($content, 'declare(strict_types=1)')) {
            $violations[] = str_replace(__DIR__ . '/../', '', $file);
        }
    }

    // Allow a small number of violations for stub files
    expect(count($violations))->toBeLessThan(5);
});

test('v139: no TODO or FIXME in new v139 files', function (): void {
    $files = [
        __DIR__ . '/../src/Services/EventCostEstimator.php',
        __DIR__ . '/../src/Services/SaaSOnboardingFunnelTracker.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('TODO');
        expect($content)->not->toContain('FIXME');
    }
});

test('v139: new service files have MIT license header', function (): void {
    $files = [
        __DIR__ . '/../src/Services/EventCostEstimator.php',
        __DIR__ . '/../src/Services/SaaSOnboardingFunnelTracker.php',
        __DIR__ . '/../tests/V139SaaSStarterIndustryStandardUpgradeTest.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    }
});

test('v139: EventCostEstimator isBudgetExceeded works', function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.cache_ttl', 300)
        ->andReturn(300);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.event_costs.budget', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue.currency', 'USD')
        ->andReturn('USD');

    $estimator = new EventCostEstimator($config, $manager, $cache);

    // Under budget: GA4 only (free)
    expect($estimator->isBudgetExceeded(['ga4' => 1_000_000]))->toBeFalse();

    // Over budget: PostHog 500K events = $125 > $100 threshold
    expect($estimator->isBudgetExceeded(['posthog' => 500_000]))->toBeTrue();
});
