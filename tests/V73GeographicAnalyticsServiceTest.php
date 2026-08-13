<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\GeographicAnalyticsService;

/**
 * V73.0.0 — Geographic Analytics Service Test.
 *
 * Validates:
 * 1. GeographicAnalyticsService class exists and is final
 * 2. GeographicAnalyticsService is registered in ServiceProvider
 * 3. Config section geographic_analytics exists with all required keys
 * 4. Constructor accepts CacheRepository, ConfigRepository, AnalyticsMetrics
 * 5. countryBreakdown() returns valid structure
 * 6. regionBreakdown() returns valid structure with optional country filter
 * 7. cityBreakdown() returns valid structure
 * 8. timezoneDistribution() returns valid structure
 * 9. engagementHeatmap() returns valid structure with grades
 * 10. regionalConversion() returns valid structure
 * 11. topEventsPerCountry() returns valid structure
 * 12. detectAnomalies() returns valid structure
 * 13. continentBreakdown() returns valid structure
 * 14. summary() returns valid structure
 * 15. ingestEvent() accumulates data into aggregates
 * 16. snapshotBaseline() works correctly
 * 17. clearCache() works correctly
 * 18. isEnabled() returns bool
 * 19. AnalyticsConfig has geo accessors
 * 20. CLI command exists and has correct signature
 * 21. API routes registered (10 geo endpoints)
 * 22. Event catalog still valid
 * 23. Version consistency across all files
 * 24. PHP 8.5 syntax compliance
 */
test('v73.0.0 feature 1: GeographicAnalyticsService class exists and is final', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class))->toBeTrue();

    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v73.0.0 feature 2: GeographicAnalyticsService is registered in ServiceProvider', function (): void {
    $provider = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);

    $file = file_get_contents((string) $provider->getFileName());
    expect($file)->toContain('use ZeroBoiler\\Analytics\\Services\\GeographicAnalyticsService');
    expect($file)->toContain('GeographicAnalyticsService::class');
});

test('v73.0.0 feature 3: config has geographic_analytics section with all required keys', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    // Main section
    expect($config)->toContain("'geographic_analytics' => [");
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_ENABLED');

    // Config keys
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_CACHE_TTL');
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_TOP_REGIONS');
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_TOP_EVENTS');
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_ANOMALY_THRESHOLD');

    // Engagement weights
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_WEIGHT_EVENTS');
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_WEIGHT_USERS');
    expect($config)->toContain('ANALYTICS_GEO_ANALYTICS_WEIGHT_SESSIONS');
});

test('v73.0.0 feature 4: constructor accepts CacheRepository, ConfigRepository, AnalyticsMetrics', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(3);
    expect($params[0]->getName())->toBe('cache');
    expect($params[1]->getName())->toBe('config');
    expect($params[2]->getName())->toBe('metrics');
    expect($params[0]->hasType())->toBeTrue();
    expect($params[1]->hasType())->toBeTrue();
    expect($params[2]->hasType())->toBeTrue();
});

test('v73.0.0 feature 5: countryBreakdown returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->countryBreakdown();

    expect($result)->toHaveKeys(['countries', 'total_events', 'total_countries']);
    expect($result['countries'])->toBeArray();
    expect($result['total_events'])->toBeInt();
    expect($result['total_countries'])->toBeInt();
});

test('v73.0.0 feature 6: regionBreakdown returns valid structure with optional country filter', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);

    // Without filter
    $result = $service->regionBreakdown(null);
    expect($result)->toHaveKeys(['regions', 'total_events', 'total_regions']);

    // With filter
    $resultFiltered = $service->regionBreakdown('US');
    expect($resultFiltered)->toHaveKeys(['regions', 'total_events', 'total_regions']);
});

test('v73.0.0 feature 7: cityBreakdown returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->cityBreakdown(null, 10);

    expect($result)->toHaveKeys(['cities', 'total_events']);
    expect($result['cities'])->toBeArray();
});

test('v73.0.0 feature 8: timezoneDistribution returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->timezoneDistribution();

    expect($result)->toHaveKeys(['timezones', 'total_timezones']);
    expect($result['timezones'])->toBeArray();
    expect($result['total_timezones'])->toBeInt();
});

test('v73.0.0 feature 9: engagementHeatmap returns valid structure with grades', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->engagementHeatmap();

    expect($result)->toHaveKeys(['countries', 'average_score']);
    expect($result['average_score'])->toBeFloat();

    // If there are countries, verify grade field
    if (! empty($result['countries'])) {
        $first = $result['countries'][0];
        expect($first)->toHaveKeys(['country', 'score', 'grade', 'events', 'users']);
        expect($first['grade'])->toBeString();
        expect(['A', 'B', 'C', 'D', 'F'])->toContain($first['grade']);
    }
});

test('v73.0.0 feature 10: regionalConversion returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->regionalConversion('sign_up', 'purchase');

    expect($result)->toHaveKeys(['regions', 'global_rate']);
    expect($result['global_rate'])->toBeFloat();
    expect($result['regions'])->toBeArray();
});

test('v73.0.0 feature 11: topEventsPerCountry returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->topEventsPerCountry();

    expect($result)->toHaveKeys(['countries']);
    expect($result['countries'])->toBeArray();
});

test('v73.0.0 feature 12: detectAnomalies returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->detectAnomalies();

    expect($result)->toHaveKeys(['anomalies', 'checked']);
    expect($result['anomalies'])->toBeArray();
    expect($result['checked'])->toBeInt();
});

test('v73.0.0 feature 13: continentBreakdown returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->continentBreakdown();

    expect($result)->toHaveKeys(['continents', 'total_events']);
    expect($result['continents'])->toBeArray();
});

test('v73.0.0 feature 14: summary returns valid structure', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);
    $result = $service->summary();

    expect($result)->toHaveKeys(['enabled', 'top_countries', 'total_events', 'total_countries', 'total_timezones', 'average_engagement', 'anomalies_detected']);
    expect($result['enabled'])->toBeBool();
    expect($result['top_countries'])->toBeInt();
    expect($result['total_events'])->toBeInt();
    expect($result['average_engagement'])->toBeFloat();
    expect($result['anomalies_detected'])->toBeInt();
});

test('v73.0.0 feature 15: ingestEvent accumulates data into aggregates', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);

    // Clear any existing data
    $service->clearCache();

    // Ingest sample events
    $service->ingestEvent([
        'geo_country' => 'US',
        'geo_region' => 'California',
        'geo_city' => 'San Francisco',
        'geo_timezone' => 'America/Los_Angeles',
        'geo_continent' => 'North America',
        'event_name' => 'page_view',
    ], 'client_1');

    $service->ingestEvent([
        'geo_country' => 'US',
        'geo_region' => 'New York',
        'geo_city' => 'New York City',
        'geo_timezone' => 'America/New_York',
        'geo_continent' => 'North America',
        'event_name' => 'sign_up',
    ], 'client_2');

    $service->ingestEvent([
        'geo_country' => 'GB',
        'geo_region' => 'England',
        'geo_city' => 'London',
        'geo_timezone' => 'Europe/London',
        'geo_continent' => 'Europe',
        'event_name' => 'purchase',
    ], 'client_3');

    // Verify country breakdown has data
    $breakdown = $service->countryBreakdown();
    expect($breakdown['total_events'])->toBeGreaterThanOrEqual(3);
    expect($breakdown['total_countries'])->toBeGreaterThanOrEqual(2);

    // Verify timezone distribution
    $tz = $service->timezoneDistribution();
    expect($tz['total_timezones'])->toBeGreaterThanOrEqual(3);

    // Verify continent breakdown
    $continents = $service->continentBreakdown();
    expect($continents['continents'])->not->toBeEmpty();

    // Cleanup
    $service->clearCache();
});

test('v73.0.0 feature 16: snapshotBaseline works without errors', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);

    // Should not throw
    $service->snapshotBaseline();
    expect(true)->toBeTrue();
});

test('v73.0.0 feature 17: clearCache works without errors', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);

    // Should not throw
    $service->clearCache();
    expect(true)->toBeTrue();
});

test('v73.0.0 feature 18: isEnabled returns bool', function (): void {
    $cache = app('cache');
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $metrics = app(\ZeroBoiler\Analytics\AnalyticsMetrics::class);

    $service = new \ZeroBoiler\Analytics\Services\GeographicAnalyticsService($cache, $config, $metrics);

    expect($service->isEnabled())->toBeBool();
});

test('v73.0.0 feature 19: AnalyticsConfig has geo analytics accessors', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Support\AnalyticsConfig::class);
    $methods = array_map(fn (\ReflectionMethod $m): string => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    // Required geo accessors
    expect($methods)->toContain('geoAnalyticsEnabled');
    expect($methods)->toContain('geoAnalyticsCacheTtl');
    expect($methods)->toContain('geoAnalyticsTopRegionsLimit');
    expect($methods)->toContain('geoAnalyticsTopEventsPerRegion');
    expect($methods)->toContain('geoAnalyticsAnomalyThreshold');
    expect($methods)->toContain('geoAnalyticsEngagementWeightEvents');
    expect($methods)->toContain('geoAnalyticsEngagementWeightUsers');
    expect($methods)->toContain('geoAnalyticsEngagementWeightSessions');
});

test('v73.0.0 feature 20: CLI command AnalyticsGeoCommand exists with correct signature', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsGeoCommand::class))->toBeTrue();

    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsGeoCommand::class);
    expect($ref->isFinal())->toBeTrue();

    // Verify it extends Command
    expect($ref->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue();
});

test('v73.0.0 feature 20b: AnalyticsGeoCommand has handle method with GeographicAnalyticsService injection', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsGeoCommand::class);
    $method = $ref->getMethod('handle');
    $params = $method->getParameters();

    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('service');
    expect($params[0]->hasType())->toBeTrue();
    expect((string) $params[0]->getType())->toBe(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);
});

test('v73.0.0 feature 21: API routes for geographic analytics are registered', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->not->toBeFalse();

    // 10 geo endpoints
    expect($sp)->toContain("Route::get('analytics/geo/summary'");
    expect($sp)->toContain("Route::get('analytics/geo/countries'");
    expect($sp)->toContain("Route::get('analytics/geo/regions'");
    expect($sp)->toContain("Route::get('analytics/geo/cities'");
    expect($sp)->toContain("Route::get('analytics/geo/timezones'");
    expect($sp)->toContain("Route::get('analytics/geo/engagement'");
    expect($sp)->toContain("Route::get('analytics/geo/funnel'");
    expect($sp)->toContain("Route::get('analytics/geo/top-events'");
    expect($sp)->toContain("Route::get('analytics/geo/anomalies'");
    expect($sp)->toContain("Route::get('analytics/geo/continents'");
});

test('v73.0.0 feature 21b: Controller has geo methods', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
    $methods = array_map(fn (\ReflectionMethod $m): string => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('geoSummary');
    expect($methods)->toContain('geoCountries');
    expect($methods)->toContain('geoRegions');
    expect($methods)->toContain('geoCities');
    expect($methods)->toContain('geoTimezones');
    expect($methods)->toContain('geoEngagement');
    expect($methods)->toContain('geoFunnel');
    expect($methods)->toContain('geoTopEvents');
    expect($methods)->toContain('geoAnomalies');
    expect($methods)->toContain('geoContinents');
});

test('v73.0.0 feature 22: event catalog still valid and complete', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();

    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
});

test('v73.0.0 feature 23: version is 73.0.0 everywhere', function (): void {
    // PHP DTO
    expect(AnalyticsEvent::VERSION)->toBe('73.0.0');

    // Composer
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('73.0.0');

    // JS Client
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 73.0.0');
    expect($js)->toContain("'73.0.0'");

    // Svelte Composable
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 73.0.0');

    // Config Composable
    $configSvelte = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    expect($configSvelte)->toContain('@version 73.0.0');

    // TypeScript
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 73.0.0');

    // IntegrityCommand
    $integrity = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
    expect($integrity)->toContain("'73.0.0'");
});

test('v73.0.0 feature 24: GeographicAnalyticsService has PHP 8.5 syntax', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/Services/GeographicAnalyticsService.php');

    // Strict types
    expect($file)->toContain('declare(strict_types=1)');

    // Final class
    expect($file)->toContain('final class GeographicAnalyticsService');

    // Readonly properties
    expect($file)->toContain('private readonly bool');
    expect($file)->toContain('private readonly int');
    expect($file)->toContain('private readonly float');

    // Return type declarations
    expect($file)->toContain('): array');
    expect($file)->toContain('): bool');
    expect($file)->toContain('): int');
    expect($file)->toContain('): float');
    expect($file)->toContain('): void');
    expect($file)->toContain('): ?array');

    // PHPDoc with @since annotation
    expect($file)->toContain('@since v73.0.0');

    // Namespace
    expect($file)->toContain('namespace ZeroBoiler\\Analytics\\Services');
});

test('v73.0.0 feature 24b: AnalyticsGeoCommand has PHP 8.5 syntax', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsGeoCommand.php');

    expect($file)->toContain('declare(strict_types=1)');
    expect($file)->toContain('final class AnalyticsGeoCommand');
    expect($file)->toContain('@since v73.0.0');
    expect($file)->toContain('namespace ZeroBoiler\\Analytics\\Console\\Commands');
    expect($file)->toContain('#[\\Override]');
});
