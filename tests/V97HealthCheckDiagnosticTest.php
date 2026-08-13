<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\AnalyticsManager;

test('AnalyticsHealthCheckService::ping returns valid structure', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->ping();

    expect($result)->toHaveKeys(['status', 'version', 'providers_configured', 'catalog_size']);
    expect($result['version'])->toBe('75.0.0');
    expect($result['providers_configured'])->toBeInt();
    expect($result['providers_configured'])->toBeGreaterThanOrEqual(0);
    expect($result['catalog_size'])->toBeInt();
    expect($result['catalog_size'])->toBeGreaterThan(0);
    expect($result['status'])->toBeIn(['ok', 'no_providers']);
});

test('AnalyticsHealthCheckService::ping catalog_size matches EventCatalog::count', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->ping();

    expect($result['catalog_size'])->toBe(EventCatalog::count());
});

test('AnalyticsHealthCheckService::run returns valid structure', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();

    expect($result)->toHaveKeys(['status', 'version', 'overall_score', 'timestamp', 'subsystems', 'recommendations']);
    expect($result['version'])->toBe('75.0.0');
    expect($result['overall_score'])->toBeInt();
    expect($result['overall_score'])->toBeGreaterThanOrEqual(0);
    expect($result['overall_score'])->toBeLessThanOrEqual(100);
    expect($result['status'])->toBeIn(['healthy', 'degraded', 'unhealthy', 'critical']);
    expect($result['timestamp'])->toBeString();

    // Subsystems
    expect($result['subsystems'])->toBeArray();
    $expectedSubsystems = [
        'providers', 'catalog', 'aarr_coverage', 'identity', 'queue',
        'gdpr', 'consent', 'lifecycle', 'auto_track', 'dedup', 'api', 'pipeline',
    ];
    foreach ($expectedSubsystems as $subsystem) {
        expect($result['subsystems'])->toHaveKey($subsystem);
        expect($result['subsystems'][$subsystem])->toHaveKeys(['status', 'score', 'details']);
        expect($result['subsystems'][$subsystem]['score'])->toBeInt();
        expect($result['subsystems'][$subsystem]['score'])->toBeGreaterThanOrEqual(0);
        expect($result['subsystems'][$subsystem]['score'])->toBeLessThanOrEqual(100);
        expect($result['subsystems'][$subsystem]['status'])->toBeIn(['ok', 'warning', 'critical']);
    }

    // Recommendations
    expect($result['recommendations'])->toBeArray();
    foreach ($result['recommendations'] as $rec) {
        expect($rec)->toHaveKeys(['priority', 'category', 'message']);
        expect($rec['priority'])->toBeIn(['critical', 'high', 'medium', 'low']);
    }
});

test('AnalyticsHealthCheckService::run providers subsystem checks all 6 providers', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $providers = $result['subsystems']['providers']['details']['providers'];

    expect($providers)->toHaveKeys(['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook']);

    foreach (['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook'] as $provider) {
        expect($providers[$provider])->toHaveKey('enabled');
    }
});

test('AnalyticsHealthCheckService::run catalog subsystem reports correct counts', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $catalog = $result['subsystems']['catalog']['details'];

    expect($catalog['total_events'])->toBe(EventCatalog::count());
    expect($catalog['by_category'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    expect($catalog['standard_coverage'])->toHaveKey('score');
});

test('AnalyticsHealthCheckService::run AARRR subsystem has all 5 categories', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $aarr = $result['subsystems']['aarr_coverage']['details']['categories'];

    expect($aarr)->toHaveKeys(['acquisition', 'activation', 'retention', 'revenue', 'referral']);

    // All categories should have events
    foreach (['acquisition', 'activation', 'retention', 'revenue', 'referral'] as $cat) {
        expect($aarr[$cat])->toHaveKeys(['event_count', 'has_events']);
        expect($aarr[$cat]['has_events'])->toBeTrue();
    }
});

test('AnalyticsHealthCheckService::run AARRR subsystem includes maturity and funnel data', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $aarr = $result['subsystems']['aarr_coverage']['details'];

    expect($aarr['maturity'])->toHaveKeys(['score', 'grade']);
    expect($aarr['maturity']['score'])->toBeGreaterThanOrEqual(80);

    expect($aarr['funnels'])->toHaveKeys(['signup', 'purchase', 'subscription', 'overall']);
    expect($aarr['funnels']['overall'])->toBe(100.0);
});

test('AnalyticsHealthCheckService::run identity subsystem checks all identity fields', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $identity = $result['subsystems']['identity']['details'];

    expect($identity)->toHaveKeys([
        'cookie_name', 'cookie_ttl', 'cookie_secure', 'cookie_samesite',
        'link_on_auth', 'issues',
    ]);
    expect($identity['issues'])->toBeArray();
});

test('AnalyticsHealthCheckService::run queue subsystem checks configuration', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $queue = $result['subsystems']['queue']['details'];

    expect($queue)->toHaveKeys(['enabled', 'queue_name', 'connection']);
});

test('AnalyticsHealthCheckService::run GDPR subsystem checks compliance settings', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $gdpr = $result['subsystems']['gdpr']['details'];

    expect($gdpr)->toHaveKeys(['ip_anonymization', 'pii_sanitization', 'pii_strategy', 'event_replay']);
});

test('AnalyticsHealthCheckService::run consent subsystem checks consent mode', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $consent = $result['subsystems']['consent']['details'];

    expect($consent)->toHaveKeys(['default_state', 'purposes_count', 'purposes', 'log_enabled', 'issues']);
});

test('AnalyticsHealthCheckService::run lifecycle subsystem checks event mapping', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $lifecycle = $result['subsystems']['lifecycle']['details'];

    expect($lifecycle)->toHaveKeys(['enabled', 'mapped_events', 'enabled_events', 'event_list']);
    expect($lifecycle['event_list'])->toBeArray();
});

test('AnalyticsHealthCheckService::run auto_track subsystem checks server and client settings', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $autoTrack = $result['subsystems']['auto_track']['details'];

    expect($autoTrack)->toHaveKeys([
        'server_enabled', 'server_events_enabled', 'client_trackers', 'event_map_count',
    ]);
});

test('AnalyticsHealthCheckService::run dedup subsystem checks dedup configuration', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $dedup = $result['subsystems']['dedup']['details'];

    expect($dedup)->toHaveKeys(['enabled', 'window_seconds', 'max_fingerprints', 'cache_prefix']);
});

test('AnalyticsHealthCheckService::run API subsystem checks API configuration', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $api = $result['subsystems']['api']['details'];

    expect($api)->toHaveKeys(['enabled', 'throttle', 'base_url', 'auth_middleware']);
});

test('AnalyticsHealthCheckService::run pipeline subsystem checks pipeline configuration', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $pipeline = $result['subsystems']['pipeline']['details'];

    expect($pipeline)->toHaveKeys([
        'auto_utm', 'auto_metadata', 'auto_timestamp', 'schema_enrichment',
        'sampling_enabled', 'sampling_rate',
    ]);
});

test('AnalyticsHealthCheckService::run recommendations are sorted by priority', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();
    $recommendations = $result['recommendations'];

    $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    for ($i = 1; $i < count($recommendations); $i++) {
        $prevPriority = $priorityOrder[$recommendations[$i - 1]['priority']] ?? 99;
        $currPriority = $priorityOrder[$recommendations[$i]['priority']] ?? 99;
        expect($currPriority)->toBeGreaterThanOrEqual($prevPriority);
    }
});

test('AnalyticsHealthCheckService::run overall status reflects subsystem health', function (): void {
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = app('config');
    $service = new AnalyticsHealthCheckService($config);

    $result = $service->run();

    // No critical subsystems → not 'critical'
    $hasCritical = false;
    foreach ($result['subsystems'] as $subsystem) {
        if (($subsystem['status'] ?? '') === 'critical') {
            $hasCritical = true;
            break;
        }
    }

    if ($hasCritical) {
        expect($result['status'])->toBe('critical');
    } elseif ($result['overall_score'] >= 80) {
        expect($result['status'])->toBe('healthy');
    } elseif ($result['overall_score'] >= 60) {
        expect($result['status'])->toBe('degraded');
    } else {
        expect($result['status'])->toBe('unhealthy');
    }
});

test('AnalyticsHealthCheckService VERSION is 2.98.0', function (): void {
    $reflection = new ReflectionClass(AnalyticsHealthCheckService::class);
    $property = $reflection->getProperty('VERSION');

    expect($property->getValue())->toBe('75.0.0');
});

test('AnalyticsManager::healthCheck delegates to service', function (): void {
    $manager = app(AnalyticsManager::class);
    $result = $manager->healthCheck();

    expect($result)->toHaveKeys(['status', 'version', 'overall_score', 'timestamp', 'subsystems', 'recommendations']);
    expect($result['version'])->toBe('75.0.0');
    expect($result['subsystems'])->toHaveKeys(['providers', 'catalog', 'aarr_coverage']);
});

test('AnalyticsManager::ping delegates to service', function (): void {
    $manager = app(AnalyticsManager::class);
    $result = $manager->ping();

    expect($result)->toHaveKeys(['status', 'version', 'providers_configured', 'catalog_size']);
    expect($result['version'])->toBe('75.0.0');
});

test('version is 2.98.0', function (): void {
    $manager = new AnalyticsManager;

    expect($manager->version())->toBe('75.0.0');
    expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('75.0.0');
});
