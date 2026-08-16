<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCoverageCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSCoverageReportService;

test('SaaSCoverageReportService audit returns all 12 capabilities', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $report = $service->audit();

    expect($report)->toHaveKey('version')
        ->and($report)->toHaveKey('score')
        ->and($report)->toHaveKey('grade')
        ->and($report)->toHaveKey('capabilities');

    expect($report['capabilities'])->toHaveCount(12);
    expect($report['score'])->toBeGreaterThanOrEqual(0);
    expect($report['score'])->toBeLessThanOrEqual(100);
    expect($report['grade'])->toBeString();
});

test('SaaSCoverageReportService all capabilities are fully implemented', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $report = $service->audit();

    // Full ZeroBoiler package should score 100
    expect($report['score'])->toBe(100);
    expect($report['grade'])->toBe('A+');

    foreach ($report['capabilities'] as $key => $cap) {
        expect($cap)->toHaveKey('status');
        expect($cap)->toHaveKey('label');
        expect($cap)->toHaveKey('weight');
        expect($cap)->toHaveKey('evidence');
        expect($cap)->toHaveKey('recommendations');
        expect($cap['status'])->toBe('implemented');
        expect($cap['weight'])->toBeGreaterThan(0);
        expect($cap['evidence'])->not->toBeEmpty();
    }
});

test('SaaSCoverageReportService auditCached returns same result', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);

    // Clear cache first
    $service->clearCache();

    $fresh = $service->audit();
    $cached = $service->auditCached();

    expect($fresh['score'])->toBe($cached['score']);
    expect($fresh['grade'])->toBe($cached['grade']);
    expect($fresh['capabilities'])->toHaveCount($cached['capabilities']);
});

test('SaaSCoverageReportService summary returns correct counts', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $summary = $service->summary();

    expect($summary)->toHaveKeys(['score', 'grade', 'implemented', 'partial', 'missing', 'total']);
    expect($summary['total'])->toBe(12);
    expect($summary['implemented'])->toBe(12);
    expect($summary['partial'])->toBe(0);
    expect($summary['missing'])->toBe(0);
    expect($summary['score'])->toBe(100);
});

test('SaaSCoverageReportService clearCache works', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);

    // Generate and cache
    $service->auditCached();

    // Clear
    $service->clearCache();

    // Should not throw
    $service->auditCached();
    expect(true)->toBeTrue();
});

test('SaaSCoverageReportService cacheTtl returns positive int', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);

    expect($service->cacheTtl())->toBeGreaterThan(0);
});

test('SaaSCoverageReportService version matches AnalyticsEvent', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $report = $service->audit();

    expect($report['version'])->toBe(AnalyticsEvent::VERSION);
});

test('AnalyticsCoverageCommand has correct signature', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $command = new AnalyticsCoverageCommand($service);

    expect($command->getDescription())->toBeString();
    expect($command->getDescription())->toContain('coverage');
});

test('SaaSCoverageReportService capabilities have correct keys', function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    $service = new SaaSCoverageReportService($cache, $config);
    $report = $service->audit();

    $expectedKeys = [
        'event_catalog',
        'lifecycle_tracker',
        'inertia_middleware',
        'api_controller',
        'js_client',
        'event_queue',
        'identity_linking',
        'ecommerce_helpers',
        'admin_commands',
        'config_expansion',
        'optional_providers',
        'tests_readme',
    ];

    foreach ($expectedKeys as $key) {
        expect($report['capabilities'])->toHaveKey($key);
    }
});
