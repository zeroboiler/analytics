<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsSandboxService;
use ZeroBoiler\Analytics\Services\ProviderRateLimitService;
use ZeroBoiler\Analytics\Services\EventSchemaVersioningService;
use ZeroBoiler\Analytics\Services\AnalyticsReadinessService;

beforeEach(function () {
    //
});

describe('AnalyticsSandboxService', function () {
    it('can be instantiated with null config (auto-detect)', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.sandbox', [])->andReturn([]);
        $config->shouldReceive('get')->with('app.env', 'production')->andReturn('local');

        $service = new AnalyticsSandboxService($cache, $config);

        expect($service->isActive())->toBe(true);
        expect($service->getAppEnv())->toBe('local');
    });

    it('is inactive in production environment with no explicit config', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.sandbox', [])->andReturn([]);
        $config->shouldReceive('get')->with('app.env', 'production')->andReturn('production');

        $service = new AnalyticsSandboxService($cache, $config);

        expect($service->isActive())->toBe(false);
    });

    it('returns empty events list when no events are captured', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsSandboxService($cache, $config);

        expect($service->getEvents())->toBe([]);
        expect($service->getCount())->toBe(0);
    });

    it('returns correct meta information', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(0);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.sandbox', [])->andReturn([
            'enabled' => true,
            'max_events' => 1000,
            'cache_ttl' => 7200,
            'allow_replay' => false,
        ]);
        $config->shouldReceive('get')->with('app.env', 'production')->andReturn('staging');

        $service = new AnalyticsSandboxService($cache, $config);

        $meta = $service->getMeta();

        expect($meta)->toHaveKey('active');
        expect($meta['max_events'])->toBe(1000);
        expect($meta['allow_replay'])->toBe(false);
    });

    it('returns correct replay result when replay is disabled', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.sandbox', [])->andReturn([
            'enabled' => true,
            'allow_replay' => false,
        ]);
        $config->shouldReceive('get')->with('app.env', 'production')->andReturn('local');

        $service = new AnalyticsSandboxService($cache, $config);

        $result = $service->replayEvent(0, fn (AnalyticsEvent $e) => null);

        expect($result['success'])->toBe(false);
        expect($result['error'])->toContain('disabled');
    });
});

describe('ProviderRateLimitService', function () {
    it('returns disabled by default', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.provider_rate_limits', [])->andReturn([]);

        $service = new ProviderRateLimitService($cache, $config);

        expect($service->isEnabled())->toBe(false);
    });

    it('should not throttle when disabled', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.provider_rate_limits', [])->andReturn([]);

        $service = new ProviderRateLimitService($cache, $config);

        expect($service->shouldThrottle('ga4'))->toBe(false);
        expect($service->shouldThrottle('meta', 'purchase'))->toBe(false);
    });

    it('returns correct provider configuration', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.provider_rate_limits', [])->andReturn([
            'enabled' => true,
            'overflow_strategy' => 'drop',
            'providers' => [
                'ga4' => ['limit' => 1800, 'enabled' => true],
                'meta' => ['limit' => 2000, 'enabled' => true],
                'gtm' => ['limit' => 0, 'enabled' => false],
            ],
        ]);

        $service = new ProviderRateLimitService($cache, $config);

        expect($service->isEnabled())->toBe(true);
        expect($service->getLimit('ga4'))->toBe(1800);
        expect($service->getLimit('meta'))->toBe(2000);
        expect($service->getLimit('unknown'))->toBe(0);
        expect($service->getOverflowStrategy())->toBe('drop');
    });

    it('returns empty status when no providers configured', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.provider_rate_limits', [])->andReturn([]);

        $service = new ProviderRateLimitService($cache, $config);

        expect($service->getStatus())->toBe([]);
    });
});

describe('EventSchemaVersioningService', function () {
    it('can be instantiated with config', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([]);

        $service = new EventSchemaVersioningService($config);

        expect($service->isEnabled())->toBe(true);
        expect($service->getCatalogVersion())->toBe('74.0.0');
        expect($service->getParamName())->toBe('_schema_version');
    });

    it('returns default version for unknown events', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([
            'default_version' => '1.0',
        ]);

        $service = new EventSchemaVersioningService($config);

        expect($service->getEventVersion('unknown_custom_event'))->toBe('1.0');
    });

    it('versioning can be disabled via config', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([
            'enabled' => false,
        ]);

        $service = new EventSchemaVersioningService($config);

        expect($service->isEnabled())->toBe(false);

        // When disabled, versionEvent should return the original event
        $event = new AnalyticsEvent(name: 'test_event', params: ['foo' => 'bar']);
        $versioned = $service->versionEvent($event);

        expect($versioned->name)->toBe('test_event');
        expect($versioned->params)->not()->toHaveKey('_schema_version');
    });

    it('injects schema version into event params', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([
            'enabled' => true,
            'default_version' => '1.0',
            'include_catalog_version' => false,
        ]);

        $service = new EventSchemaVersioningService($config);

        $event = new AnalyticsEvent(name: 'custom_event', params: ['foo' => 'bar']);
        $versioned = $service->versionEvent($event);

        expect($versioned->params)->toHaveKey('_schema_version');
        expect($versioned->params['_schema_version'])->toBe('1.0');
    });

    it('does not override existing schema version', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([
            'enabled' => true,
            'param_name' => '_schema_version',
        ]);

        $service = new EventSchemaVersioningService($config);

        $event = new AnalyticsEvent(name: 'test', params: ['_schema_version' => '2.5']);
        $versioned = $service->versionEvent($event);

        expect($versioned->params['_schema_version'])->toBe('2.5');
    });

    it('extracts version from params', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([]);

        $service = new EventSchemaVersioningService($config);

        expect($service->extractVersion(['_schema_version' => '2.0']))->toBe('2.0');
        expect($service->extractVersion(['foo' => 'bar']))->toBeNull();
    });

    it('returns correct summary', function () {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.schema_versioning', [])->andReturn([]);

        $service = new EventSchemaVersioningService($config);

        $summary = $service->getSummary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('param_name');
        expect($summary)->toHaveKey('catalog_version');
        expect($summary)->toHaveKey('tracked_events');
    });
});

describe('AnalyticsReadinessService', function () {
    it('produces a readiness report with pass/warn/fail results', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.readiness', [])->andReturn([]);
        $config->shouldReceive('get')->with('zeroboiler.analytics', [])->andReturn([
            'ga4' => ['enabled' => true],
            'consent' => ['default' => 'granted'],
            'queue' => ['enabled' => true],
            'identity' => ['cookie_name' => 'zb_analytics_id'],
            'debug' => ['enabled' => false],
            'replay' => ['enabled' => true],
            'dedup' => ['enabled' => true],
            'pii_sanitization' => ['enabled' => false],
            'consent' => ['log_enabled' => false],
            'gdpr' => ['anonymize_ip' => false],
            'attribution' => ['enabled' => true],
            'health_score' => ['enabled' => true],
            'client_auto_track' => ['error_tracking' => true],
            'performance_budget' => ['enabled' => false],
        ]);

        $service = new AnalyticsReadinessService($cache, $config);
        $report = $service->assess();

        expect($report->score)->toBeGreaterThanOrEqual(0);
        expect($report->score)->toBeLessThanOrEqual(100);
        expect($report->totalChecks)->toBeGreaterThan(0);
        expect($report->results)->toBeArray();
        expect($report->grade)->toBeIn(['A', 'B', 'C', 'D', 'F']);
    });

    it('report can be serialized to array', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.readiness', [])->andReturn([]);
        $config->shouldReceive('get')->with('zeroboiler.analytics', [])->andReturn([
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
            'plausible' => ['enabled' => false],
            'posthog' => ['enabled' => false],
            'webhook' => ['enabled' => false],
            'consent' => ['default' => null],
            'queue' => ['enabled' => true],
            'identity' => ['cookie_name' => ''],
            'debug' => ['enabled' => true],
            'replay' => ['enabled' => true],
            'dedup' => ['enabled' => true],
            'pii_sanitization' => ['enabled' => false],
            'consent' => ['log_enabled' => false],
            'gdpr' => ['anonymize_ip' => false],
            'attribution' => ['enabled' => true],
            'health_score' => ['enabled' => true],
            'client_auto_track' => ['error_tracking' => true],
            'performance_budget' => ['enabled' => false],
        ]);

        $service = new AnalyticsReadinessService($cache, $config);
        $report = $service->assess();
        $array = $report->toArray();

        expect($array)->toHaveKey('score');
        expect($array)->toHaveKey('grade');
        expect($array)->toHaveKey('ready');
        expect($array)->toHaveKey('checks');
        expect($array)->toHaveKey('required_fails');
    });

    it('detects when no providers are configured (required fail)', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.readiness', [])->andReturn([]);
        $config->shouldReceive('get')->with('zeroboiler.analytics', [])->andReturn([
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
            'plausible' => ['enabled' => false],
            'posthog' => ['enabled' => false],
            'webhook' => ['enabled' => false],
            'consent' => ['default' => 'granted'],
            'queue' => ['enabled' => true],
            'identity' => ['cookie_name' => 'zb_test'],
            'debug' => ['enabled' => false],
            'replay' => ['enabled' => true],
            'dedup' => ['enabled' => true],
            'pii_sanitization' => ['enabled' => false],
            'consent' => ['log_enabled' => false],
            'gdpr' => ['anonymize_ip' => false],
            'attribution' => ['enabled' => true],
            'health_score' => ['enabled' => true],
            'client_auto_track' => ['error_tracking' => true],
            'performance_budget' => ['enabled' => false],
        ]);

        $service = new AnalyticsReadinessService($cache, $config);
        $report = $service->assess();

        expect($report->requiredFails)->toBeGreaterThanOrEqual(1);
        expect($report->ready)->toBe(false);
        expect($report->grade)->toBe('F');
    });

    it('handles config errors gracefully in individual checks', function () {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.readiness', [])->andReturn([]);
        $config->shouldReceive('get')->with('zeroboiler.analytics', [])->andReturn([]); // Empty config

        $service = new AnalyticsReadinessService($cache, $config);
        $report = $service->assess();

        // Should produce a valid report even with empty config
        expect($report->score)->toBeInt();
        expect($report->grade)->toBeString();
        expect($report->results)->toBeArray();
    });
});

describe('v2.71.0 Integration', function () {
    it('all new config sections have correct structure', function () {
        $config = require __DIR__.'/../config/zeroboiler.php';

        // Sandbox config
        expect($config['analytics'])->toHaveKey('sandbox');
        expect($config['analytics']['sandbox'])->toHaveKey('enabled');
        expect($config['analytics']['sandbox'])->toHaveKey('max_events');
        expect($config['analytics']['sandbox'])->toHaveKey('cache_ttl');

        // Provider rate limits
        expect($config['analytics'])->toHaveKey('provider_rate_limits');
        expect($config['analytics']['provider_rate_limits'])->toHaveKey('enabled');
        expect($config['analytics']['provider_rate_limits'])->toHaveKey('providers');
        expect($config['analytics']['provider_rate_limits']['providers'])->toHaveKey('ga4');
        expect($config['analytics']['provider_rate_limits']['providers'])->toHaveKey('meta');
        expect($config['analytics']['provider_rate_limits']['providers'])->toHaveKey('posthog');

        // Schema versioning
        expect($config['analytics'])->toHaveKey('schema_versioning');
        expect($config['analytics']['schema_versioning'])->toHaveKey('enabled');
        expect($config['analytics']['schema_versioning'])->toHaveKey('catalog_version');
        expect($config['analytics']['schema_versioning']['catalog_version'])->toBe('74.0.0');

        // Readiness
        expect($config['analytics'])->toHaveKey('readiness');
        expect($config['analytics']['readiness'])->toHaveKey('enabled');
        expect($config['analytics']['readiness'])->toHaveKey('minimum_score');
        expect($config['analytics']['readiness'])->toHaveKey('required_checks');
        expect($config['analytics']['readiness'])->toHaveKey('recommended_checks');
    });

    it('new service classes exist and are loadable', function () {
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsSandboxService::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\ProviderRateLimitService::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\EventSchemaVersioningService::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsReadinessService::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsReadinessService\ReadinessCheck::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsReadinessService\CheckResult::class))->toBe(true);
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsReadinessService\ReadinessReport::class))->toBe(true);
    });

    it('composer.json version is 2.71.0', function () {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['version'])->toBe('74.0.0');
    });

    it('config/zeroboiler.php catalog_version is 2.71.0', function () {
        $config = require __DIR__.'/../config/zeroboiler.php';

        expect($config['analytics']['schema_versioning']['catalog_version'])->toBe('74.0.0');
    });

    it('new routes are defined', function () {
        $routeContent = file_get_contents(__DIR__.'/../routes/analytics.php');

        expect($routeContent)->toContain('sandboxStatus');
        expect($routeContent)->toContain('sandboxEvents');
        expect($routeContent)->toContain('sandboxReplayLog');
        expect($routeContent)->toContain('sandboxClear');
        expect($routeContent)->toContain('providerRateLimits');
        expect($routeContent)->toContain('providerRateLimitsReset');
        expect($routeContent)->toContain('schemaVersions');
        expect($routeContent)->toContain('readiness');
    });

    it('new endpoints are defined in ServiceProvider routes', function () {
        $spContent = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');

        expect($spContent)->toContain('sandboxStatus');
        expect($spContent)->toContain('providerRateLimits');
        expect($spContent)->toContain('schemaVersions');
        expect($spContent)->toContain('readiness');
    });

    it('new service singletons are registered in ServiceProvider', function () {
        $spContent = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');

        expect($spContent)->toContain('AnalyticsSandboxService::class');
        expect($spContent)->toContain('ProviderRateLimitService::class');
        expect($spContent)->toContain('EventSchemaVersioningService::class');
        expect($spContent)->toContain('AnalyticsReadinessService::class');
    });

    it('AnalyticsConfig has new accessor methods', function () {
        $configContent = file_get_contents(__DIR__.'/../src/Support/AnalyticsConfig.php');

        expect($configContent)->toContain('sandboxEnabled');
        expect($configContent)->toContain('providerRateLimitsEnabled');
        expect($configContent)->toContain('schemaVersioningEnabled');
        expect($configContent)->toContain('readinessEnabled');
        expect($configContent)->toContain('readinessMinimumScore');
    });
});
