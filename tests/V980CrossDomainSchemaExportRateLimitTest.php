<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;
use ZeroBoiler\Analytics\Services\AnalyticsRateLimiterService;
use ZeroBoiler\Analytics\Services\CrossDomainTrackingService;
use ZeroBoiler\Analytics\Services\EventSchemaExportService;
use ZeroBoiler\Analytics\Services\SessionRecordingBridge;

beforeEach(function (): void {
    // Reset static catalogs
    \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::reset();
    \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::reset();
    \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::reset();
});

describe('CrossDomainTrackingService', function (): void {
    it('is disabled by default', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([]);

        $service = new CrossDomainTrackingService($config);

        expect($service->isEnabled())->toBeFalse();
    });

    it('is enabled when configured with multiple domains', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com'],
                'linker_param' => '_zbclid',
                'auto_linker' => true,
                'cache_prefix' => 'zb_crossdomain_',
                'link_ttl' => 900,
                'excluded_domains' => [],
            ]);

        $service = new CrossDomainTrackingService($config);

        expect($service->isEnabled())->toBeTrue();
        expect($service->getDomains())->toBe(['app.example.com', 'docs.example.com']);
        expect($service->getLinkerParam())->toBe('_zbclid');
        expect($service->isAutoLinkerEnabled())->toBeTrue();
    });

    it('is disabled with only one domain', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com'],
            ]);

        $service = new CrossDomainTrackingService($config);

        expect($service->isEnabled())->toBeFalse();
    });

    it('extracts linker ID from request', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com'],
                'linker_param' => '_zbclid',
            ]);

        $service = new CrossDomainTrackingService($config);

        $request = Request::create('/', 'GET', ['_zbclid' => (string) Str::uuid()]);
        $linkId = $service->extractLinkIdFromRequest($request);

        expect($linkId)->not->toBeNull();
        expect(Str::isUuid($linkId))->toBeTrue();
    });

    it('returns null for invalid linker ID format', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com'],
                'linker_param' => '_zbclid',
            ]);

        $service = new CrossDomainTrackingService($config);

        $request = Request::create('/', 'GET', ['_zbclid' => 'not-a-uuid']);
        $linkId = $service->extractLinkIdFromRequest($request);

        expect($linkId)->toBeNull();
    });

    it('generates client config for JS', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com'],
                'linker_param' => '_zbclid',
                'auto_linker' => true,
            ]);

        $service = new CrossDomainTrackingService($config);
        $clientConfig = $service->getClientConfig();

        expect($clientConfig)->toHaveKey('domains');
        expect($clientConfig)->toHaveKey('linkerParam');
        expect($clientConfig)->toHaveKey('autoLinker');
        expect($clientConfig['domains'])->toBe(['app.example.com', 'docs.example.com']);
        expect($clientConfig['linkerParam'])->toBe('_zbclid');
        expect($clientConfig['autoLinker'])->toBeTrue();
    });

    it('returns stats correctly', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com', 'blog.example.com'],
                'linker_param' => '_zbclid',
            ]);

        $service = new CrossDomainTrackingService($config);
        $stats = $service->getStats();

        expect($stats['enabled'])->toBeTrue();
        expect($stats['domain_count'])->toBe(3);
        expect($stats['domains'])->toHaveCount(3);
        expect($stats['linker_param'])->toBe('_zbclid');
    });

    it('detects tracked domains', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([
                'enabled' => true,
                'domains' => ['app.example.com', 'docs.example.com'],
            ]);

        $service = new CrossDomainTrackingService($config);

        expect($service->isTrackedDomain('app.example.com'))->toBeTrue();
        expect($service->isTrackedDomain('https://docs.example.com/page'))->toBeTrue();
        expect($service->isTrackedDomain('other.example.com'))->toBeFalse();
    });
});

describe('SessionRecordingBridge', function (): void {
    it('is disabled by default', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->isEnabled())->toBeFalse();
    });

    it('is enabled when integrations are configured', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => [
                    'hotjar' => ['site_id' => '123456', 'version' => 6],
                ],
            ]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->isEnabled())->toBeTrue();
        expect($bridge->getEnabledProviders())->toBe(['hotjar']);
        expect($bridge->hasIntegration('hotjar'))->toBeTrue();
        expect($bridge->hasIntegration('logrocket'))->toBeFalse();
    });

    it('should not record when consent is denied', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => ['site_id' => '123']],
                'consent_aware' => true,
            ]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'denied'],
        ]))->toBeFalse();
    });

    it('should record when consent is granted', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => ['site_id' => '123']],
                'consent_aware' => true,
            ]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'granted'],
        ]))->toBeTrue();
    });

    it('suppresses recording for excluded roles', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => ['site_id' => '123']],
                'excluded_roles' => ['admin', 'super_admin'],
            ]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'granted'],
            'user_role' => 'admin',
        ]))->toBeFalse();

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'granted'],
            'user_role' => 'user',
        ]))->toBeTrue();
    });

    it('suppresses recording for excluded URL patterns', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => ['site_id' => '123']],
                'excluded_patterns' => ['/admin/*', '/billing/*'],
            ]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'granted'],
            'current_url' => 'https://app.example.com/admin/users',
        ]))->toBeFalse();

        expect($bridge->shouldRecord([
            'consent' => ['analytics_storage' => 'granted'],
            'current_url' => 'https://app.example.com/dashboard',
        ]))->toBeTrue();
    });

    it('generates client config correctly', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => ['site_id' => '123']],
                'consent_aware' => true,
                'mask_pii' => true,
                'mask_selectors' => ['[data-zb-mask]'],
                'block_selectors' => ['[data-zb-block]'],
            ]);

        $bridge = new SessionRecordingBridge($config);
        $clientConfig = $bridge->getClientConfig([
            'consent' => ['analytics_storage' => 'granted'],
        ]);

        expect($clientConfig['enabled'])->toBeTrue();
        expect($clientConfig['maskPii'])->toBeTrue();
        expect($clientConfig['maskSelectors'])->toBe(['[data-zb-mask]']);
        expect($clientConfig['blockSelectors'])->toBe(['[data-zb-block]']);
        expect($clientConfig['consentAware'])->toBeTrue();
        expect($clientConfig['providers'])->toHaveKey('hotjar');
        expect($clientConfig['providers']['hotjar']['enabled'])->toBeTrue();
    });

    it('returns correct stats', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([
                'enabled' => true,
                'integrations' => ['hotjar' => [], 'clarity' => []],
                'excluded_patterns' => ['/admin/*'],
                'excluded_roles' => ['admin'],
            ]);

        $bridge = new SessionRecordingBridge($config);
        $stats = $bridge->getStats();

        expect($stats['enabled'])->toBeTrue();
        expect($stats['integrations'])->toBe(['hotjar', 'clarity']);
        expect($stats['excluded_pattern_count'])->toBe(1);
        expect($stats['excluded_role_count'])->toBe(1);
    });
});

describe('EventSchemaExportService', function (): void {
    it('exports valid JSON Schema', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);
        $schema = $service->exportJsonSchema();

        expect($schema)->toHaveKey('$schema');
        expect($schema)->toHaveKey('title');
        expect($schema)->toHaveKey('properties');
        expect($schema)->toHaveKey('$defs');
        expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema');
        expect($schema['title'])->toBe('ZeroBoiler Analytics Events');
        expect($schema['properties'])->toHaveKey('name');
        expect($schema['properties'])->toHaveKey('params');
        expect($schema['properties'])->toHaveKey('client_id');
        expect($schema['properties'])->toHaveKey('user_id');

        // Event names should be in the enum
        $enum = $schema['properties']['name']['enum'];
        expect($enum)->toContain('page_view');
        expect($enum)->toContain('sign_up');
        expect($enum)->toContain('purchase');
        expect($enum)->toContain('login');
    });

    it('exports TypeScript with event name union type', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);
        $ts = $service->exportTypeScript();

        expect($ts)->toContain('export type ZbEventName =');
        expect($ts)->toContain("'page_view'");
        expect($ts)->toContain("'sign_up'");
        expect($ts)->toContain("'purchase'");
        expect($ts)->toContain('export interface ZbEvent {');
        expect($ts)->toContain('export type ZbEventPriority =');
        expect($ts)->toContain("'critical'");
        expect($ts)->toContain("'normal'");
        expect($ts)->toContain("'low'");
        expect($ts)->toContain("'background'");
        expect($ts)->toContain('export type ZbEcommerceEventName =');
        expect($ts)->toContain('export type ZbSaasEventName =');
        expect($ts)->toContain('export type ZbEngagementEventName =');
    });

    it('exports TypeScript with per-event interfaces', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);
        $ts = $service->exportTypeScript();

        expect($ts)->toContain('export interface ZbPageViewEvent');
        expect($ts)->toContain('export interface ZbSignUpEvent');
        expect($ts)->toContain('export interface ZbPurchaseEvent');
        expect($ts)->toContain('export interface ZbLoginEvent');
        expect($ts)->toContain("name: 'page_view'");
        expect($ts)->toContain("name: 'sign_up'");
    });

    it('exports OpenAPI operations for event endpoints', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);
        $openapi = $service->exportOpenApi();

        expect($openapi)->toHaveKey('post_/api/analytics/events');
        expect($openapi)->toHaveKey('post_/api/analytics/batch');
        expect($openapi)->toHaveKey('get_/api/analytics/catalog');

        $trackOp = $openapi['post_/api/analytics/events'];
        expect($trackOp['operationId'])->toBe('trackAnalyticsEvent');
        expect($trackOp['summary'])->toBe('Track a single analytics event');
        expect($trackOp['tags'])->toBe(['Analytics Events']);
        expect($trackOp['requestBody'])->not->toBeNull();
        expect($trackOp['responses'])->toHaveKey('200');
    });

    it('includes all catalog events in JSON Schema enum', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);
        $schema = $service->exportJsonSchema();

        $enum = $schema['properties']['name']['enum'];
        $catalogCount = EventCatalog::count();

        expect($enum)->toHaveCount($catalogCount);
        expect($schema['$defs'])->toHaveCount($catalogCount);
    });
});

describe('AnalyticsRateLimiterService', function (): void {
    it('is enabled by default', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.rate_limit', [])
            ->andReturn([
                'enabled' => true,
                'global_limit' => 10000,
                'client_limit' => 300,
                'user_limit' => 600,
                'batch_global_limit' => 5000,
                'batch_client_limit' => 100,
                'max_batch_size' => 50,
                'prefix' => 'zb_analytics_',
                'decay_seconds' => 60,
            ]);

        $service = new AnalyticsRateLimiterService($config);

        expect($service->isEnabled())->toBeTrue();
        expect($service->getMaxBatchSize())->toBe(50);
    });

    it('returns correct stats', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.rate_limit', [])
            ->andReturn([
                'enabled' => true,
                'global_limit' => 5000,
                'client_limit' => 200,
                'user_limit' => 400,
                'batch_global_limit' => 2500,
                'batch_client_limit' => 80,
                'max_batch_size' => 30,
                'decay_seconds' => 120,
            ]);

        $service = new AnalyticsRateLimiterService($config);
        $stats = $service->getStats();

        expect($stats['global_limit'])->toBe(5000);
        expect($stats['client_limit'])->toBe(200);
        expect($stats['user_limit'])->toBe(400);
        expect($stats['batch_global_limit'])->toBe(2500);
        expect($stats['batch_client_limit'])->toBe(80);
        expect($stats['max_batch_size'])->toBe(30);
        expect($stats['decay_seconds'])->toBe(120);
    });

    it('returns PHP_INT_MAX for remaining when disabled', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.rate_limit', [])
            ->andReturn([
                'enabled' => false,
            ]);

        $service = new AnalyticsRateLimiterService($config);

        expect($service->isEnabled())->toBeFalse();
        expect($service->remainingForClient('test-client'))->toBe(PHP_INT_MAX);
        expect($service->remainingGlobal())->toBe(PHP_INT_MAX);
        expect($service->remainingForUser('test-user'))->toBe(PHP_INT_MAX);
    });
});

describe('v9.8.0 Integration', function (): void {
    it('CrossDomainTrackingService handles empty config gracefully', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_domain', [])
            ->andReturn([]);

        $service = new CrossDomainTrackingService($config);

        expect($service->isEnabled())->toBeFalse();
        expect($service->getDomains())->toBe([]);
        expect($service->getLinkerParam())->toBe('_zbclid');
        expect($service->isAutoLinkerEnabled())->toBeTrue();
        expect($service->extractLinkIdFromRequest(Request::create('/')))->toBeNull();
        expect($service->getLinkedClientIds('test'))->toBe([]);
        expect($service->resolvePrimaryClientId('test'))->toBe('test');
        expect($service->resolveIdentityCluster('test'))->toBe(['test']);
        expect($service->getStats()['domain_count'])->toBe(0);
    });

    it('SessionRecordingBridge handles empty config gracefully', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_recording', [])
            ->andReturn([]);

        $bridge = new SessionRecordingBridge($config);

        expect($bridge->isEnabled())->toBeFalse();
        expect($bridge->getEnabledProviders())->toBe([]);
        expect($bridge->hasIntegration('hotjar'))->toBeFalse();
        expect($bridge->getIntegrationConfig('hotjar'))->toBeNull();
        expect($bridge->isConsentAware())->toBeTrue();
        expect($bridge->getExcludedPatterns())->toBe(['/admin/*', '/billing/*', '/settings/*', '/api/*']);
        expect($bridge->getExcludedRoles())->toBe(['admin', 'super_admin']);
        expect($bridge->getPiiConfig()['enabled'])->toBeTrue();
    });

    it('EventSchemaExportService produces consistent output', function (): void {
        $paramSchemas = Mockery::mock(EventParameterSchemas::class);
        $paramSchemas->shouldReceive('getSchema')->andReturnNull();

        $service = new EventSchemaExportService($paramSchemas);

        // JSON Schema should be deterministic
        $schema1 = $service->exportJsonSchema();
        $schema2 = $service->exportJsonSchema();
        expect($schema1)->toBe($schema2);

        // TypeScript should be deterministic
        $ts1 = $service->exportTypeScript();
        $ts2 = $service->exportTypeScript();
        expect($ts1)->toBe($ts2);

        // OpenAPI should be deterministic
        $openapi1 = $service->exportOpenApi();
        $openapi2 = $service->exportOpenApi();
        expect($openapi1)->toBe($openapi2);
    });
});
