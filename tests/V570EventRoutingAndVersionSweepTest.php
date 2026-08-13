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
use ZeroBoiler\Analytics\Services\AnalyticsEventRouter;
use ZeroBoiler\Analytics\Services\ProviderHealthMonitor;

/**
 * V570 — Event Routing, Provider Health Monitor, and Version Sweep Test.
 *
 * Validates v75.0.0 features:
 * - Full version sweep to 5.9.0 across PHP, JS, Svelte, TypeScript, composer
 * - AnalyticsEventRouter with pattern matching and provider targeting
 * - ProviderHealthMonitor with score tracking and unhealthy detection
 * - Config routing and provider_health sections
 * - New API routes for routing and provider health
 * - Client-side trackEventWithProviders and trackEcommerceWithProviders
 * - Config section count increase (26+ sections now)
 */
test('v75.0.0 version sweep: all version references are 5.9.0', function (): void {
    // PHP AnalyticsEvent constant
    expect(AnalyticsEvent::VERSION)->toBe('75.0.0');

    // composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('75.0.0');

    // JS client
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 75.0.0');
    expect($js)->toContain("'75.0.0'");

    // Svelte composables
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 75.0.0');

    // TypeScript definitions
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 75.0.0');

    // README badge
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-75.0.0');
});

test('v75.0.0 feature: AnalyticsEventRouter exists with required methods', function (): void {
    expect(class_exists(AnalyticsEventRouter::class))->toBeTrue();

    $ref = new ReflectionClass(AnalyticsEventRouter::class);
    $methods = array_map(fn (\ReflectionMethod $m
    ): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

    // Core routing methods
    expect($methods)->toContain('route');
    expect($methods)->toContain('matchProviders');
    expect($methods)->toContain('dispatchToProviders');
    expect($methods)->toContain('isEnabled');
    expect($methods)->toContain('getRules');
    expect($methods)->toContain('addRule');
    expect($methods)->toContain('removeRule');
    expect($methods)->toContain('clearRules');
    expect($methods)->toContain('summary');
    expect($methods)->toContain('ruleCount');
    expect($methods)->toContain('hasRule');
});

test('v75.0.0 feature: AnalyticsEventRouter pattern matching works correctly', function (): void {
    $manager = new AnalyticsManager;
    $config = new Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'routing' => [
                    'enabled' => true,
                    'rules' => [
                        'purchase' => ['ga4', 'meta'],
                        'add_to_*' => ['ga4', 'meta', 'posthog'],
                        'page_view' => ['ga4', 'plausible'],
                    ],
                ],
                'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => ''],
                'gtm' => ['enabled' => false, 'container_id' => ''],
                'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                'webhook' => ['enabled' => false, 'url' => '', 'secret' => '', 'timeout' => 5, 'retries' => 1],
            ],
        ],
    ]);

    $router = new AnalyticsEventRouter($manager, $config);

    // Exact match
    expect($router->matchProviders('purchase'))->toBe(['ga4', 'meta']);

    // Wildcard prefix match
    expect($router->matchProviders('add_to_cart'))->toBe(['ga4', 'meta', 'posthog']);
    expect($router->matchProviders('add_to_wishlist'))->toBe(['ga4', 'meta', 'posthog']);

    // No match falls through
    expect($router->matchProviders('custom_event'))->toBe([]);

    // Exact match
    expect($router->matchProviders('page_view'))->toBe(['ga4', 'plausible']);
});

test('v75.0.0 feature: AnalyticsEventRouter runtime rule management', function (): void {
    $manager = new AnalyticsManager;
    $config = new Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'routing' => [
                    'enabled' => true,
                    'rules' => [],
                ],
                'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => ''],
                'gtm' => ['enabled' => false, 'container_id' => ''],
                'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                'webhook' => ['enabled' => false, 'url' => '', 'secret' => '', 'timeout' => 5, 'retries' => 1],
            ],
        ],
    ]);

    $router = new AnalyticsEventRouter($manager, $config);

    expect($router->ruleCount())->toBe(0);
    expect($router->hasRule('test_event'))->toBeFalse();

    // Add rule
    $router->addRule('test_event', ['ga4']);
    expect($router->ruleCount())->toBe(1);
    expect($router->hasRule('test_event'))->toBeTrue();
    expect($router->matchProviders('test_event'))->toBe(['ga4']);

    // Remove rule
    $router->removeRule('test_event');
    expect($router->ruleCount())->toBe(0);
    expect($router->hasRule('test_event'))->toBeFalse();
});

test('v75.0.0 feature: ProviderHealthMonitor exists with required methods', function (): void {
    expect(class_exists(ProviderHealthMonitor::class))->toBeTrue();

    $ref = new ReflectionClass(ProviderHealthMonitor::class);
    $methods = array_map(fn (\ReflectionMethod $m
    ): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('recordSuccess');
    expect($methods)->toContain('recordFailure');
    expect($methods)->toContain('isHealthy');
    expect($methods)->toContain('getScore');
    expect($methods)->toContain('getStatus');
    expect($methods)->toContain('summary');
    expect($methods)->toContain('reset');
    expect($methods)->toContain('resetAll');
    expect($methods)->toContain('providersByHealth');
    expect($methods)->toContain('activeProviders');
});

test('v75.0.0 feature: ProviderHealthMonitor tracks health scores', function (): void {
    $metrics = new ZeroBoiler\Analytics\AnalyticsMetrics;
    $config = new Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'provider_health' => [
                    'enabled' => true,
                    'window_duration' => 300,
                ],
            ],
        ],
    ]);

    $monitor = new ProviderHealthMonitor($metrics, $config);

    // Fresh provider starts at 100
    expect($monitor->getScore('ga4'))->toBe(100);
    expect($monitor->isHealthy('ga4'))->toBeTrue();

    // Record successes
    $monitor->recordSuccess('ga4');
    $monitor->recordSuccess('ga4');
    $monitor->recordSuccess('ga4');
    expect($monitor->getScore('ga4'))->toBe(100);

    // Record failures (75% success = score 75)
    $monitor->recordFailure('ga4');
    expect($monitor->getScore('ga4'))->toBe(75);
    expect($monitor->isHealthy('ga4'))->toBeTrue();

    // More failures (60% success = score 60)
    $monitor->recordFailure('ga4');
    expect($monitor->getScore('ga4'))->toBe(60);

    // Still healthy
    expect($monitor->isHealthy('ga4'))->toBeTrue();
});

test('v75.0.0 feature: ProviderHealthMonitor summary returns all providers', function (): void {
    $metrics = new ZeroBoiler\Analytics\AnalyticsMetrics;
    $config = new Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'provider_health' => [
                    'enabled' => true,
                    'window_duration' => 300,
                ],
            ],
        ],
    ]);

    $monitor = new ProviderHealthMonitor($metrics, $config);
    $summary = $monitor->summary();

    expect($summary)->toHaveKeys(['overall_score', 'healthy_count', 'unhealthy_providers', 'version']);
    expect($summary['version'])->toBe('75.0.0');
    expect($summary['overall_score'])->toBe(100);
    expect($summary['healthy_count'])->toBe(6); // 6 providers
    expect($summary['unhealthy_providers'])->toBeEmpty();
});

test('v75.0.0 feature: config has routing and provider_health sections', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    expect($config)->toContain("'routing' => [");
    expect($config)->toContain('ANALYTICS_ROUTING_ENABLED');

    expect($config)->toContain("'provider_health' => [");
    expect($config)->toContain('ANALYTICS_PROVIDER_HEALTH_ENABLED');
});

test('v75.0.0 feature: routes include routing and provider-health endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    // Routing endpoints
    expect($routes)->toContain("Route::get('routing'");
    expect($routes)->toContain("Route::get('routing/rules'");
    expect($routes)->toContain("Route::post('routing/rules'");
    expect($routes)->toContain("Route::delete('routing/rules/{pattern}'");
    expect($routes)->toContain("Route::post('routing/match'");
    expect($routes)->toContain("Route::post('routing/test'");

    // Provider health endpoints
    expect($routes)->toContain("Route::get('provider-health'");
    expect($routes)->toContain("Route::get('provider-health/{provider}'");
    expect($routes)->toContain("Route::post('provider-health/reset'");
});

test('v75.0.0 feature: JS client has trackEventWithProviders and trackEcommerceWithProviders', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->not->toBeFalse();

    expect($js)->toContain('export async function trackEventWithProviders(');
    expect($js)->toContain('export async function trackEcommerceWithProviders(');
    expect($js)->toContain('_target_providers');
});

test('v75.0.0 feature: config section count is 27+', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    preg_match_all("/'(\w+)' => \[/", $config, $matches);
    $sections = array_unique($matches[1]);

    // Must have all original 26 sections plus the 2 new ones
    expect(count($sections))->toBeGreaterThanOrEqual(28);

    // Check critical sections exist
    $requiredSections = [
        'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
        'queue', 'identity', 'ecommerce', 'revenue', 'track_links',
        'api', 'plausible', 'posthog', 'webhook', 'audit_log',
        'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
        'replay', 'metrics', 'stream', 'client_auto_track', 'performance',
        'routing', 'provider_health',
    ];

    foreach ($requiredSections as $section) {
        expect($config)->toContain("'{$section}' => [");
    }
});

test('v75.0.0 feature: route count is 155+', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    preg_match_all("/Route::(get|post|put|patch|delete)\\(/", $routes, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(155);
});

test('v75.0.0 backward compatibility: event catalog still intact', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);

    // Core events still exist
    expect(EventCatalog::has('purchase'))->toBeTrue();
    expect(EventCatalog::has('sign_up'))->toBeTrue();
    expect(EventCatalog::has('login'))->toBeTrue();
    expect(EventCatalog::has('page_view'))->toBeTrue();
    expect(EventCatalog::has('start_trial'))->toBeTrue();
    expect(EventCatalog::has('plan_upgrade'))->toBeTrue();
});
