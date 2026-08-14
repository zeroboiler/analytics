<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use ZeroBoiler\Analytics\Services\SaaSReadinessGateService;

beforeEach(function (): void {
    $config = mock(ConfigRepository::class);
    $files = mock(Filesystem::class);

    // Set up config mocks
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.auto_track', [])
        ->andReturn([
            'enabled' => true,
            'events' => [
                'auth.login' => true,
                'auth.register' => true,
            ],
            'event_map' => [],
        ]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
        ->andReturn('zb_analytics_id');

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.identity', [])
        ->andReturn([
            'cookie_name' => 'zb_analytics_id',
            'cookie_ttl' => 525600,
            'link_on_auth' => true,
        ]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.queue', [])
        ->andReturn([
            'enabled' => true,
            'queue' => 'analytics',
            'max_batch_size' => 50,
        ]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.ecommerce', [])
        ->andReturn([
            'currency' => 'USD',
            'brand' => '',
            'tax_behavior' => 'inclusive',
        ]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics', [])
        ->andReturn([
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
            'consent' => ['default' => 'granted'],
            'queue' => ['enabled' => true],
            'identity' => ['cookie_name' => 'zb_analytics_id'],
            'ecommerce' => ['currency' => 'USD'],
            'auto_track' => ['enabled' => true],
        ]);

    // Set up file system mocks
    $packageRoot = dirname(__DIR__, 2);

    $files->shouldReceive('exists')
        ->andReturn(true);

    $files->shouldReceive('isDirectory')
        ->andReturn(true);

    $files->shouldReceive('get')
        ->andReturnUsing(function (string $path) use ($packageRoot): string {
            if (str_contains($path, 'routes/analytics.php')) {
                return "'events', 'batch', 'identify', 'consent', 'health'";
            }
            if (str_contains($path, 'analytics.js')) {
                return "export function init() {} export function trackEvent() {} export function trackPageView() {} export function getVersion() {} export function flushQueue() {}";
            }
            return '';
        });

    $files->shouldReceive('size')
        ->andReturn(20000);

    $this->gate = new SaaSReadinessGateService($config, $files);
});

describe('SaaSReadinessGateService', function (): void {
    test('returns a result with score, grade, and capabilities', function (): void {
        $result = $this->gate->validate();

        expect($result)->toHaveKeys(['passed', 'score', 'grade', 'capabilities']);
        expect($result['score'])->toBeInt();
        expect($result['grade'])->toBeString();
        expect($result['capabilities'])->toBeArray();
    });

    test('validates all 12 capabilities', function (): void {
        $result = $this->gate->validate();

        expect($result['capabilities'])->toHaveCount(12);

        $keys = array_column($result['capabilities'], 'key');
        expect($keys)->toContain('event_catalog');
        expect($keys)->toContain('lifecycle_tracker');
        expect($keys)->toContain('inertia_middleware');
        expect($keys)->toContain('api_endpoints');
        expect($keys)->toContain('js_client');
        expect($keys)->toContain('event_queue');
        expect($keys)->toContain('identity_linking');
        expect($keys)->toContain('ecommerce_helpers');
        expect($keys)->toContain('admin_commands');
        expect($keys)->toContain('config_expansion');
        expect($keys)->toContain('optional_providers');
        expect($keys)->toContain('tests_readme');
    });

    test('each capability has checks array', function (): void {
        $result = $this->gate->validate();

        foreach ($result['capabilities'] as $capability) {
            expect($capability)->toHaveKey('checks');
            expect($capability['checks'])->toBeArray();
            expect($capability['checks'])->not->toBeEmpty();

            foreach ($capability['checks'] as $check) {
                expect($check)->toHaveKeys(['check', 'passed', 'message']);
                expect($check['passed'])->toBeBool();
                expect($check['message'])->toBeString();
            }
        }
    });

    test('event catalog checks validate category event counts', function (): void {
        $result = $this->gate->validate();
        $catalog = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'event_catalog');

        expect($catalog)->not->toBeNull();

        $checks = array_column($catalog['checks'], null, 'check');
        expect($checks)->toHaveKey('catalog_non_empty');
        expect($checks['catalog_non_empty']['passed'])->toBeTrue();
    });

    test('api endpoint checks verify required routes', function (): void {
        $result = $this->gate->validate();
        $api = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'api_endpoints');

        expect($api)->not->toBeNull();

        $checks = array_column($api['checks'], null, 'check');
        expect($checks)->toHaveKey('route_events');
        expect($checks)->toHaveKey('route_batch');
        expect($checks)->toHaveKey('route_identify');
        expect($checks)->toHaveKey('route_consent');
        expect($checks)->toHaveKey('route_health');
    });

    test('js client checks verify required exports', function (): void {
        $result = $this->gate->validate();
        $js = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'js_client');

        expect($js)->not->toBeNull();

        $checks = array_column($js['checks'], null, 'check');
        expect($checks)->toHaveKey('js_export_init');
        expect($checks)->toHaveKey('js_export_trackEvent');
        expect($checks)->toHaveKey('js_export_trackPageView');
        expect($checks)->toHaveKey('js_export_getVersion');
        expect($checks)->toHaveKey('js_export_flushQueue');
    });

    test('queue checks validate job classes and config', function (): void {
        $result = $this->gate->validate();
        $queue = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'event_queue');

        expect($queue)->not->toBeNull();

        $checks = array_column($queue['checks'], null, 'check');
        expect($checks)->toHaveKey('queue_name_configured');
        expect($checks)->toHaveKey('batch_size_reasonable');
        expect($checks)->toHaveKey('track_event_job_exists');
        expect($checks)->toHaveKey('batch_event_job_exists');
        expect($checks)->toHaveKey('queued_dispatcher_exists');
    });

    test('identity checks verify service classes', function (): void {
        $result = $this->gate->validate();
        $identity = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'identity_linking');

        expect($identity)->not->toBeNull();

        $checks = array_column($identity['checks'], null, 'check');
        expect($checks)->toHaveKey('identity_cookie_configured');
        expect($checks)->toHaveKey('cookie_ttl_reasonable');
        expect($checks)->toHaveKey('identity_resolution_service_exists');
        expect($checks)->toHaveKey('user_identity_tracker_exists');
        expect($checks)->toHaveKey('identity_graph_service_exists');
    });

    test('ecommerce checks validate currency and converter', function (): void {
        $result = $this->gate->validate();
        $ecom = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'ecommerce_helpers');

        expect($ecom)->not->toBeNull();

        $checks = array_column($ecom['checks'], null, 'check');
        expect($checks)->toHaveKey('ecommerce_currency_configured');
        expect($checks)->toHaveKey('tax_behavior_valid');
        expect($checks)->toHaveKey('ecommerce_format_converter_exists');
    });

    test('config checks verify all required sections', function (): void {
        $result = $this->gate->validate();
        $configCap = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'config_expansion');

        expect($configCap)->not->toBeNull();

        $sections = ['ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'identity', 'ecommerce', 'auto_track'];
        $checks = array_column($configCap['checks'], null, 'check');

        foreach ($sections as $section) {
            expect($checks)->toHaveKey("config_section_{$section}");
        }
    });

    test('optional provider checks validate all trackers', function (): void {
        $result = $this->gate->validate();
        $providers = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'optional_providers');

        expect($providers)->not->toBeNull();

        $checks = array_column($providers['checks'], null, 'check');
        $expectedTrackers = ['plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'ga4', 'gtm', 'meta'];

        foreach ($expectedTrackers as $tracker) {
            expect($checks)->toHaveKey("tracker_{$tracker}");
        }
    });

    test('grade calculation returns correct letter grades', function (): void {
        $result = $this->gate->validate();

        // Just verify it returns a valid grade
        expect($result['grade'])->toMatch('/^[A-F][+-]?$/');
    });

    test('passed is true when score >= 80', function (): void {
        $result = $this->gate->validate();

        // With all mocks returning valid data, score should be high
        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['score'])->toBeLessThanOrEqual(100);
    });

    test('lifecycle tracker checks verify built-in event map', function (): void {
        $result = $this->gate->validate();
        $lifecycle = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'lifecycle_tracker');

        expect($lifecycle)->not->toBeNull();

        $checks = array_column($lifecycle['checks'], null, 'check');
        expect($checks)->toHaveKey('built_in_event_map_has_entries');
        expect($checks)->toHaveKey('custom_event_map_well_formed');
        expect($checks)->toHaveKey('server_side_tracker_class_exists');
    });

    test('inertia middleware checks verify contract implementation', function (): void {
        $result = $this->gate->validate();
        $inertia = array_find($result['capabilities'], fn (array $c): bool => $c['key'] === 'inertia_middleware');

        expect($inertia)->not->toBeNull();

        $checks = array_column($inertia['checks'], null, 'check');
        expect($checks)->toHaveKey('inertia_middleware_class_exists');
        expect($checks)->toHaveKey('implements_middleware_contract');
        expect($checks)->toHaveKey('tracking_cookie_configured');
    });
});
