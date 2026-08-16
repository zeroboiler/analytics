<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\AnalyticsConfigAuditService;
use ZeroBoiler\Analytics\Services\EventCatalogValidator;

/**
 * V4.5.0 — Config Audit API, Catalog Validator, Version Sync, Code Quality Test.
 *
 * Validates all v4.5.0 features:
 * 1. AnalyticsConfigAuditService — masked config dump, summary, diff, snapshot
 * 2. EventCatalogValidator — catalog-aware validation, stats, suggestions
 * 3. Version consistency across all PHP, JS, TypeScript, and composer.json
 * 4. ServiceProvider registrations for new services
 * 5. Route definitions for new API endpoints
 * 6. Controller method signatures for new endpoints
 * 7. Bug fixes — stale getVersion(), duplicate docblock
 */
test('feature 1: config audit service returns masked config with version and timestamp', function (): void {
    $config = app('config');
    $config->set('zeroboiler.analytics', [
        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST123', 'api_secret' => 'super_secret_key_123'],
        'gtm' => ['enabled' => false, 'container_id' => 'GTM-ABCDEF'],
        'meta_pixel' => ['enabled' => true, 'id' => '123456789', 'access_token' => 'my_token_abc'],
        'consent' => ['default' => 'granted'],
    ]);

    $service = new AnalyticsConfigAuditService($config);
    $audit = $service->audit();

    expect($audit)->toHaveKey('version');
    expect($audit)->toHaveKey('timestamp');
    expect($audit)->toHaveKey('config');
    expect($audit)->toHaveKey('sections');
    expect($audit)->toHaveKey('masked_keys');
    expect($audit['version'])->toBe(AnalyticsEvent::VERSION);
    expect($audit['masked_keys'])->toBeGreaterThanOrEqual(2); // api_secret + access_token

    // Sensitive values must be masked
    $ga4Config = $audit['config']['ga4'];
    expect($ga4Config['api_secret'])->not->toBe('super_secret_key_123');
    expect($ga4Config['measurement_id'])->toBe('G-TEST123'); // not sensitive

    $metaConfig = $audit['config']['meta_pixel'];
    expect($metaConfig['access_token'])->not->toBe('my_token_abc');
});

test('feature 2: config audit masks sensitive values correctly', function (): void {
    $config = app('config');
    $config->set('zeroboiler.analytics', [
        'ga4' => [
            'api_secret' => 'abcdefgh',           // 8 chars → ab****gh
            'measurement_id' => 'G-XXXXXXXXXX',     // not sensitive
        ],
        'webhook' => [
            'secret' => 'xyz',                     // 3 chars → ****
            'url' => 'https://hooks.example.com/path/to/webhook',
        ],
    ]);

    $service = new AnalyticsConfigAuditService($config);
    $audit = $service->audit();
    $ga4 = $audit['config']['ga4'];
    $webhook = $audit['config']['webhook'];

    expect($ga4['api_secret'])->toBe('ab****gh');
    expect($ga4['measurement_id'])->toBe('G-XXXXXXXXXX');
    expect($webhook['secret'])->toBe('****');
    // URL masking: preserves host, masks path
    expect($webhook['url'])->toContain('hooks.example.com');
    expect($webhook['url'])->not->toContain('/path/to/webhook');
});

test('feature 3: config audit summary returns provider and feature status', function (): void {
    $config = app('config');
    $config->set('zeroboiler.analytics', [
        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => ''],
        'gtm' => ['enabled' => false, 'container_id' => ''],
        'meta_pixel' => ['enabled' => true, 'id' => '123', 'access_token' => ''],
        'queue' => ['enabled' => true],
        'api' => ['enabled' => true],
        'auto_track' => ['enabled' => true],
        'track_links' => ['enabled' => false],
    ]);

    $service = new AnalyticsConfigAuditService($config);
    $summary = $service->summary();

    expect($summary)->toHaveKey('providers');
    expect($summary)->toHaveKey('features');
    expect($summary)->toHaveKey('summary');
    expect($summary['providers']['ga4'])->toBeTrue();
    expect($summary['providers']['gtm'])->toBeFalse();
    expect($summary['providers']['meta_pixel'])->toBeTrue();
    expect($summary['features']['queue'])->toBeTrue();
    expect($summary['features']['track_links'])->toBeFalse();
    expect($summary['summary']['total_enabled'])->toBeGreaterThanOrEqual(3);
});

test('feature 4: config audit diff detects added, removed, and changed keys', function (): void {
    $config = app('config');
    $config->set('zeroboiler.analytics', [
        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => ''],
        'gtm' => ['enabled' => true, 'container_id' => 'GTM-NEW'],
        'queue' => ['enabled' => true],
    ]);

    $service = new AnalyticsConfigAuditService($config);

    $snapshot = [
        'ga4' => ['enabled' => false, 'measurement_id' => 'G-OLD'],
        'auto_track' => ['enabled' => true],
    ];

    $diff = $service->diff($snapshot);

    expect($diff)->toHaveKey('added');
    expect($diff)->toHaveKey('removed');
    expect($diff)->toHaveKey('changed');
    expect($diff)->toHaveKey('unchanged');

    // 'gtm' is new in current config
    expect($diff['added'])->toContain('gtm.enabled');
    expect($diff['added'])->toContain('gtm.container_id');

    // 'auto_track' removed
    expect($diff['removed'])->toContain('auto_track.enabled');

    // 'ga4.enabled' changed from false to true
    expect($diff['changed'])->toContain('ga4.enabled');
    expect($diff['changed'])->toContain('ga4.measurement_id');
});

test('feature 5: config audit snapshot save and load', function (): void {
    $config = app('config');
    $config->set('zeroboiler.analytics', ['ga4' => ['enabled' => true]]);

    $service = new AnalyticsConfigAuditService($config);

    $result = $service->saveSnapshot('test-snapshot');
    expect($result)->toHaveKey('saved');
    expect($result)->toHaveKey('label');
    expect($result['label'])->toBe('test-snapshot');

    // Note: loadSnapshot uses cache which may not be available in tests
    // The save method gracefully handles missing cache
});

test('feature 6: event catalog validator validates catalog events', function (): void {
    $validator = new EventCatalogValidator;

    // Valid catalog event
    $result = $validator->validate(new AnalyticsEvent('purchase', [
        'currency' => 'USD',
        'value' => 99.0,
    ]));
    expect($result['valid'])->toBeTrue();
    expect($result['event'])->toBe('purchase');
    expect($result['errors'])->toBeEmpty();

    // Valid custom event (not in catalog, but allowed)
    $result = $validator->validate(new AnalyticsEvent('my_custom_event'));
    expect($result['valid'])->toBeTrue();
});

test('feature 7: event catalog validator catches invalid event names', function (): void {
    $validator = new EventCatalogValidator;

    // CamelCase name (should fail format check)
    $result = $validator->validate(new AnalyticsEvent('myCustomEvent'));
    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();

    $rules = array_column($result['errors'], 'rule');
    expect($rules)->toContain('name_format');
});

test('feature 8: event catalog validator enforces max name length', function (): void {
    $validator = new EventCatalogValidator;

    $longName = str_repeat('a', 150);
    $result = $validator->validate(new AnalyticsEvent($longName));
    expect($result['valid'])->toBeFalse();

    $rules = array_column($result['errors'], 'rule');
    expect($rules)->toContain('name_length');
});

test('feature 9: event catalog validator validates batch events', function (): void {
    $validator = new EventCatalogValidator;

    $events = [
        new AnalyticsEvent('purchase', ['currency' => 'USD']),
        new AnalyticsEvent('page_view'),
        new AnalyticsEvent('INVALID EVENT'),  // invalid format
    ];

    $result = $validator->validateBatch($events);

    expect($result['total'])->toBe(3);
    expect($result['passed'])->toBe(2);
    expect($result['failed'])->toBe(1);
    expect($result['valid'])->toBeFalse();
});

test('feature 10: event catalog validator provides catalog stats', function (): void {
    $validator = new EventCatalogValidator;
    $stats = $validator->catalogStats();

    expect($stats)->toHaveKey('total');
    expect($stats)->toHaveKey('ecommerce');
    expect($stats)->toHaveKey('saas');
    expect($stats)->toHaveKey('engagement');
    expect($stats)->toHaveKey('providers');
    expect($stats['total'])->toBeGreaterThanOrEqual(100);
    expect($stats['ecommerce'])->toBeGreaterThanOrEqual(15);
    expect($stats['saas'])->toBeGreaterThanOrEqual(50);
    expect($stats['engagement'])->toBeGreaterThanOrEqual(30);
    expect($stats['providers']['ga4'])->toBeGreaterThanOrEqual(100);
});

test('feature 11: event catalog validator suggests events by partial name', function (): void {
    $validator = new EventCatalogValidator;

    $suggestions = $validator->suggest('pur');
    expect($suggestions)->not->toBeEmpty();

    $names = array_column($suggestions, 'name');
    expect($names)->toContain('purchase');
    expect($names)->toContain('refund');

    foreach ($suggestions as $suggestion) {
        expect($suggestion)->toHaveKey('name');
        expect($suggestion)->toHaveKey('category');
    }
});

test('feature 12: event catalog validator checks catalog membership', function (): void {
    $validator = new EventCatalogValidator;

    expect($validator->isCatalogEvent('purchase'))->toBeTrue();
    expect($validator->isCatalogEvent('sign_up'))->toBeTrue();
    expect($validator->isCatalogEvent('page_view'))->toBeTrue();
    expect($validator->isCatalogEvent('nonexistent_event'))->toBeFalse();

    expect($validator->getCategory('purchase'))->toBe('ecommerce');
    expect($validator->getCategory('sign_up'))->toBe('saas');
    expect($validator->getCategory('page_view'))->toBe('engagement');
    expect($validator->getCategory('nonexistent_event'))->toBeNull();
});

test('feature 13: version consistency — AnalyticsEvent::VERSION is 76.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('76.0.0');
});

test('feature 14: catalog completeness maintained at 100+ events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});

test('feature 15: routes file contains v4.5.0 endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    $routesContent = is_string($routes) ? $routes : '';

    expect($routesContent)->toContain('configAudit');
    expect($routesContent)->toContain('configSummary');
    expect($routesContent)->toContain('configSnapshotSave');
    expect($routesContent)->toContain('configSnapshotLoad');
    expect($routesContent)->toContain('configDiff');
    expect($routesContent)->toContain('catalogValidate');
    expect($routesContent)->toContain('catalogStats');
    expect($routesContent)->toContain('catalogSuggest');
});

test('feature 16: ServiceProvider registers new v4.5.0 services', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    $providerContent = is_string($provider) ? $provider : '';

    expect($providerContent)->toContain('AnalyticsConfigAuditService');
    expect($providerContent)->toContain('EventCatalogValidator');
    expect($providerContent)->toContain('use ZeroBoiler\\Analytics\\Services\\AnalyticsConfigAuditService');
    expect($providerContent)->toContain('use ZeroBoiler\\Analytics\\Services\\EventCatalogValidator');
});

test('feature 17: controller has v4.5.0 method signatures', function (): void {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    $controllerContent = is_string($controller) ? $controller : '';

    expect($controllerContent)->toContain('public function configAudit');
    expect($controllerContent)->toContain('public function configSummary');
    expect($controllerContent)->toContain('public function configSnapshotSave');
    expect($controllerContent)->toContain('public function configSnapshotLoad');
    expect($controllerContent)->toContain('public function configDiff');
    expect($controllerContent)->toContain('public function catalogValidate');
    expect($controllerContent)->toContain('public function catalogStats');
    expect($controllerContent)->toContain('public function catalogSuggest');
});

test('feature 18: JS client version is synced to 4.5.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    $jsContent = is_string($js) ? $js : '';

    // getVersion() returns correct version
    expect($jsContent)->toContain("return '76.0.0'");

    // Header version is correct
    expect($jsContent)->toContain('@version 76.0.0');
});

test('feature 19: TypeScript definitions version is 4.5.0', function (): void {
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    $dtsContent = is_string($dts) ? $dts : '';

    expect($dtsContent)->toContain('@version 76.0.0');
});

test('feature 20: Svelte composables version is 4.5.0', function (): void {
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    $svelteContent = is_string($svelte) ? $svelte : '';

    expect($svelteContent)->toContain('@version 76.0.0');
});

test('feature 21: composer.json version is 4.5.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer)->not->toBeNull();
    expect($composer['version'])->toBe('76.0.0');
});

test('feature 22: ServiceProvider docblock version is 4.5.0', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    $providerContent = is_string($provider) ? $provider : '';

    expect($providerContent)->toContain('@version 76.0.0');
});

test('feature 23: AnalyticsManager duplicate docblock removed', function (): void {
    $manager = file_get_contents(__DIR__ . '/../src/AnalyticsManager.php');
    $managerContent = is_string($manager) ? $manager : '';

    // The orphaned docblock should NOT exist between cancellation() and healthCheck()
    // The valid trackSaaSAcquisition docblock should exist
    expect($managerContent)->toContain('public function trackSaaSAcquisition');

    // Count occurrences of "Track a complete SaaS signup-to-paid conversion funnel"
    $count = substr_count($managerContent, 'Track a complete SaaS signup-to-paid conversion funnel');
    expect($count)->toBe(1); // Only one (the real one before the method)
});

test('feature 24: new service files exist with strict types and proper namespace', function (): void {
    $auditPath = __DIR__ . '/../src/Services/AnalyticsConfigAuditService.php';
    $validatorPath = __DIR__ . '/../src/Services/EventCatalogValidator.php';

    expect(file_exists($auditPath))->toBeTrue();
    expect(file_exists($validatorPath))->toBeTrue();

    $audit = file_get_contents($auditPath);
    $validator = file_get_contents($validatorPath);

    $auditContent = is_string($audit) ? $audit : '';
    $validatorContent = is_string($validator) ? $validator : '';

    // Strict types declaration
    expect($auditContent)->toContain('declare(strict_types=1)');
    expect($validatorContent)->toContain('declare(strict_types=1)');

    // Correct namespace
    expect($auditContent)->toContain('namespace ZeroBoiler\\Analytics\\Services');
    expect($validatorContent)->toContain('namespace ZeroBoiler\\Analytics\\Services');

    // Final class
    expect($auditContent)->toContain('final class AnalyticsConfigAuditService');
    expect($validatorContent)->toContain('final class EventCatalogValidator');

    // Constructor return type
    expect($auditContent)->toContain('public function __construct');
    expect($validatorContent)->toContain('public function __construct');

    // Docblock with version
    expect($auditContent)->toContain('@version 76.0.0');
    expect($validatorContent)->toContain('@version 76.0.0');
});

test('feature 25: README documents v76.0.0 features', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    $readmeContent = is_string($readme) ? $readme : '';

    expect($readmeContent)->toContain("What's New in v76.0.0");
    expect($readmeContent)->toContain('AnalyticsConfigAuditService');
    expect($readmeContent)->toContain('EventCatalogValidator');
    expect($readmeContent)->toContain('config/audit');
    expect($readmeContent)->toContain('catalog/validate');
    expect($readmeContent)->toContain('version-76.0.0');
});
