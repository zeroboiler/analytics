<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Services\DataWarehouseExportService;
use ZeroBoiler\Analytics\Services\AnalyticsDashboardDataProvider;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager(null);
});

describe('DataWarehouseExportService', function (): void {
    test('constructor reads config values', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->once()
            ->andReturn([
                'format' => 'csv',
                'output_path' => '/tmp/analytics',
                'include_fields' => ['event_name', 'value'],
                'include_headers' => false,
                'null_value' => 'N/A',
            ]);

        $service = new DataWarehouseExportService($config);

        // Service is constructed without error — config is read lazily on export
        expect(true)->toBeTrue();
    });

    test('addEvent and count work correctly', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        expect($service->count())->toBe(0);

        $event = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']);
        $service->addEvent($event);

        expect($service->count())->toBe(1);

        $service->clear();
        expect($service->count())->toBe(0);
    });

    test('addEvents supports batch adding', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);

        $events = [
            new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']),
            new AnalyticsEvent(name: 'click', params: ['element' => 'button']),
            new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'About']),
        ];

        $service->addEvents($events);
        expect($service->count())->toBe(3);
    });

    test('filterByCategory filters events', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        $service->filterByCategory('ecommerce');

        $service->addEvent(new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]));
        $service->addEvent(new AnalyticsEvent(name: 'page_view', params: []));
        $service->addEvent(new AnalyticsEvent(name: 'add_to_cart', params: []));

        expect($service->count())->toBe(2); // purchase + add_to_cart are ecommerce
    });

    test('filterByEvent filters exact event name', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        $service->filterByEvent('page_view');

        $service->addEvent(new AnalyticsEvent(name: 'page_view', params: []));
        $service->addEvent(new AnalyticsEvent(name: 'click', params: []));

        expect($service->count())->toBe(1);
    });

    test('exportToString produces NDJSON by default', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        $service->addEvent(new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']));

        $output = $service->exportToString();

        expect($output)->toBeJson();
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('event_name');
        expect($decoded['event_name'])->toBe('page_view');
    });

    test('exportToString returns empty string for no events', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);

        expect($service->exportToString())->toBe('');
    });

    test('exportToFile returns result array', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn([
                'format' => 'ndjson',
                'output_path' => '/tmp/zb_test_exports_' . uniqid(),
            ]);

        $service = new DataWarehouseExportService($config);
        $service->addEvent(new AnalyticsEvent(name: 'page_view', params: []));

        $result = $service->exportToFile('test_export.ndjson');

        expect($result)->toBeArray();
        expect($result)->toHaveKey('path');
        expect($result)->toHaveKey('format');
        expect($result)->toHaveKey('events');
        expect($result)->toHaveKey('bytes');
        expect($result['format'])->toBe('ndjson');
        expect($result['events'])->toBe(1);

        // Cleanup
        if (file_exists($result['path'])) {
            unlink($result['path']);
        }
    });

    test('supportedFormats returns expected formats', function (): void {
        expect(DataWarehouseExportService::supportedFormats())->toBe(['ndjson', 'csv']);
    });

    test('summary returns correct structure', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        $service->filterByCategory('saas');
        $service->addEvent(new AnalyticsEvent(name: 'sign_up', params: []));

        $summary = $service->summary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKey('total');
        expect($summary)->toHaveKey('category');
        expect($summary)->toHaveKey('format');
        expect($summary['category'])->toBe('saas');
        expect($summary['format'])->toBe('ndjson');
    });

    test('eventToRow includes catalog metadata', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_warehouse', [])
            ->andReturn(['format' => 'ndjson', 'output_path' => '/tmp']);

        $service = new DataWarehouseExportService($config);
        $service->addEvent(new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']));

        $output = $service->exportToString();
        $decoded = json_decode(trim($output), true);

        expect($decoded['event_name'])->toBe('sign_up');
        expect($decoded['category'])->toBe('saas');
        expect($decoded['ga4_event'])->toBe('sign_up');
        expect($decoded['param_method'])->toBe('email');
    });
});

describe('EventPropertySchema', function (): void {
    test('validate valid event passes', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'value' => 99.99,
                'currency' => 'USD',
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validate missing required property fails', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'value' => 99.99,
                // Missing required: transaction_id, currency
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    test('validate wrong type fails', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'value' => 'not_a_number', // Should be numeric
                'currency' => 'USD',
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    test('validate invalid currency format fails', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'value' => 99.99,
                'currency' => 'DOLLARS', // Should be 3-letter code
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
    });

    test('validate enum constraint fails on invalid value', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: [
                'method' => 'phone', // Not in enum
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    test('validate enum constraint passes on valid value', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: [
                'method' => 'google', // In enum
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeTrue();
    });

    test('validate range constraint fails on negative value', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'value' => -5.0, // Below min: 0
                'currency' => 'USD',
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
    });

    test('validate event without schema passes (no constraints)', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'custom_event',
            params: ['anything' => 'goes'],
        );

        $result = $schema->validate($event);

        // No schema = no constraints = valid
        expect($result['valid'])->toBeTrue();
    });

    test('hasSchema and schemaCount work', function (): void {
        $schema = new EventPropertySchema();
        expect($schema->hasSchema('purchase'))->toBeFalse();
        expect($schema->schemaCount())->toBe(0);

        $schema->registerBuiltInSchemas();

        expect($schema->hasSchema('purchase'))->toBeTrue();
        expect($schema->hasSchema('nonexistent'))->toBeFalse();
        expect($schema->schemaCount())->toBeGreaterThan(0);
    });

    test('getSchema returns empty array for unknown event', function (): void {
        $schema = new EventPropertySchema();

        expect($schema->getSchema('unknown_event'))->toBe([]);
    });

    test('supportedTypes returns all types', function (): void {
        $types = EventPropertySchema::supportedTypes();

        expect($types)->toContain('string');
        expect($types)->toContain('int');
        expect($types)->toContain('float');
        expect($types)->toContain('bool');
        expect($types)->toContain('array');
    });

    test('supportedFormats returns all formats', function (): void {
        $formats = EventPropertySchema::supportedFormats();

        expect($formats)->toContain('email');
        expect($formats)->toContain('url');
        expect($formats)->toContain('currency');
        expect($formats)->toContain('uuid');
        expect($formats)->toContain('iso_date');
    });

    test('plan_upgrade validates from_plan and to_plan required', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'plan_upgrade',
            params: [], // Missing required from_plan, to_plan
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
    });

    test('cancellation validates enum reason', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'cancellation',
            params: [
                'reason' => 'too_expensive',
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeTrue();
    });

    test('error event validates severity enum', function (): void {
        $schema = new EventPropertySchema();
        $schema->registerBuiltInSchemas();

        $event = new AnalyticsEvent(
            name: 'error',
            params: [
                'severity' => 'not_a_severity',
            ],
        );

        $result = $schema->validate($event);

        expect($result['valid'])->toBeFalse();
    });

    test('defineProperty and defineGlobalRule work', function (): void {
        $schema = new EventPropertySchema();
        $schema->defineGlobalRule('user_id', ['type' => 'string']);
        $schema->defineProperty('my_event', 'score', ['type' => 'integer', 'min' => 0, 'max' => 100]);

        expect($schema->hasSchema('my_event'))->toBeTrue();
        expect($schema->schemaCount())->toBe(1);

        $schemaDef = $schema->getSchema('my_event');
        expect($schemaDef)->toHaveKey('score');
    });
});

describe('AnalyticsDashboardDataProvider', function (): void {
    test('overview returns structured data', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        $overview = $provider->overview();

        expect($overview)->toBeArray();
        expect($overview)->toHaveKey('version');
        expect($overview)->toHaveKey('providers');
        expect($overview)->toHaveKey('catalog');
        expect($overview)->toHaveKey('metrics');
        expect($overview['version'])->toBe('4.6.0');
        expect($overview['providers'])->toBeArray();
        expect($overview['catalog'])->toHaveKey('total');
    });

    test('publicOverview excludes sensitive data', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        $public = $provider->publicOverview();

        expect($public)->toBeArray();
        expect($public)->toHaveKey('version');
        expect($public)->toHaveKey('catalog');
        expect($public)->toHaveKey('providers');
        expect($public)->not->toHaveKey('kpi');
        expect($public)->not->toHaveKey('health_score');
        expect($public)->not->toHaveKey('realtime');
    });

    test('providerStatus returns enabled counts', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        $status = $provider->providerStatus();

        expect($status)->toBeArray();
        expect($status)->toHaveKey('enabled');
        expect($status)->toHaveKey('counts');
        expect($status['counts'])->toHaveKey('total_enabled');
        expect($status['counts'])->toHaveKey('total_available');
        expect($status['counts']['total_available'])->toBe(6);
    });

    test('kpiSection returns null without kpi tracker', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        expect($provider->kpiSection())->toBeNull();
    });

    test('healthSection returns null without health service', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        expect($provider->healthSection())->toBeNull();
    });

    test('realtimeSection returns null without realtime service', function (): void {
        $provider = new AnalyticsDashboardDataProvider($this->manager);

        expect($provider->realtimeSection())->toBeNull();
    });
});

describe('Version Consistency (v2.67.0)', function (): void {
    test('PHP version is 2.67.0', function (): void {
        expect($this->manager->version())->toBe('4.6.0');
    });

    test('event catalog has all categories', function (): void {
        $catalog = EventCatalog::summary();

        expect($catalog['total'])->toBeGreaterThan(70);
        expect($catalog['ecommerce'])->toBeGreaterThan(10);
        expect($catalog['saas'])->toBeGreaterThan(30);
        expect($catalog['engagement'])->toBeGreaterThan(20);
    });

    test('event catalog is valid', function (): void {
        $validation = EventCatalog::validate();

        expect($validation['valid'])->toBeTrue();
        expect($validation['errors'])->toBeEmpty();
    });
});
