<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\UniversalEventNormalizer;
use ZeroBoiler\Analytics\Services\EventSchemaMigrationService;

beforeEach(function (): void {
    $this->normalizer = new UniversalEventNormalizer;
    $this->cache = app('cache');
    $this->migrationService = new EventSchemaMigrationService(
        cache: $this->cache,
    );
});

// ─── UniversalEventNormalizer Tests ──────────────────────────────

describe('UniversalEventNormalizer', function (): void {
    test('normalizes event name for GA4 provider', function (): void {
        $event = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']);
        $result = $this->normalizer->normalize($event, 'ga4');

        expect($result['name'])->toBe('sign_up');
        expect($result['params'])->toHaveKey('method');
    });

    test('normalizes event name for Meta Pixel provider', function (): void {
        $event = new AnalyticsEvent(name: 'sign_up', params: []);
        $result = $this->normalizer->normalize($event, 'meta');

        expect($result['name'])->toBe('CompleteRegistration');
    });

    test('normalizes event name for PostHog provider', function (): void {
        $event = new AnalyticsEvent(name: 'sign_up', params: []);
        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['name'])->toBe('$signup');
    });

    test('normalizes page_view for Meta Pixel', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']);
        $result = $this->normalizer->normalize($event, 'meta');

        expect($result['name'])->toBe('PageView');
    });

    test('attaches client_id in GA4 format', function (): void {
        $event = new AnalyticsEvent(name: 'click', clientId: 'client-123', params: []);
        $result = $this->normalizer->normalize($event, 'ga4');

        expect($result['params']['client_id'])->toBe('client-123');
    });

    test('attaches client_id as distinct_id for PostHog', function (): void {
        $event = new AnalyticsEvent(name: 'click', clientId: 'client-123', params: []);
        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['params']['distinct_id'])->toBe('client-123');
    });

    test('attaches client_id as distinct_id for Mixpanel', function (): void {
        $event = new AnalyticsEvent(name: 'click', clientId: 'client-456', params: []);
        $result = $this->normalizer->normalize($event, 'mixpanel');

        expect($result['params']['distinct_id'])->toBe('client-456');
    });

    test('attaches client_id as device_id for Amplitude', function (): void {
        $event = new AnalyticsEvent(name: 'click', clientId: 'client-789', params: []);
        $result = $this->normalizer->normalize($event, 'amplitude');

        expect($result['params']['device_id'])->toBe('client-789');
    });

    test('attaches user_id in GA4 format', function (): void {
        $event = new AnalyticsEvent(name: 'login', userId: 'user-1', params: []);
        $result = $this->normalizer->normalize($event, 'ga4');

        expect($result['params']['user_id'])->toBe('user-1');
    });

    test('attaches user_id as $user_id for PostHog', function (): void {
        $event = new AnalyticsEvent(name: 'login', userId: 'user-1', params: []);
        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['params']['$user_id'])->toBe('user-1');
    });

    test('attaches user_id as external_id for Meta Pixel', function (): void {
        $event = new AnalyticsEvent(name: 'login', userId: 'user-1', params: []);
        $result = $this->normalizer->normalize($event, 'meta');

        expect($result['params']['external_id'])->toBe('user-1');
    });

    test('attaches timestamp for PostHog in ISO format', function (): void {
        $ts = new \DateTimeImmutable('2026-08-12T10:00:00+00:00');
        $event = new AnalyticsEvent(name: 'click', params: [], timestamp: $ts);
        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['params']['timestamp'])->toBe('2026-08-12T10:00:00+00:00');
    });

    test('attaches timestamp for Mixpanel in milliseconds', function (): void {
        $ts = new \DateTimeImmutable('2026-08-12T10:00:00+00:00');
        $event = new AnalyticsEvent(name: 'click', params: [], timestamp: $ts);
        $result = $this->normalizer->normalize($event, 'mixpanel');

        expect($result['params']['time'])->toBeInt();
        expect($result['params']['time'])->toBeGreaterThan(0);
    });

    test('does not attach timestamp when null', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: [], timestamp: null);
        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['params'])->not->toHaveKey('timestamp');
    });

    test('normalizes e-commerce purchase for Meta Pixel', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'SKU-1', 'item_name' => 'Product', 'price' => 49.99, 'quantity' => 2],
                ],
            ],
        );

        $result = $this->normalizer->normalize($event, 'meta');

        expect($result['name'])->toBe('Purchase');
        expect($result['params'])->toHaveKey('contents');
        expect($result['params'])->toHaveKey('content_ids');
        expect($result['params'])->toHaveKey('num_items');
        expect($result['params']['num_items'])->toBe(1);
    });

    test('normalizes e-commerce purchase for PostHog', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-002',
                'value' => 49.99,
                'currency' => 'EUR',
                'items' => [
                    ['item_id' => 'SKU-2', 'item_name' => 'Item', 'price' => 49.99, 'quantity' => 1],
                ],
            ],
        );

        $result = $this->normalizer->normalize($event, 'posthog');

        expect($result['name'])->toBe('purchase');
        expect($result['params'])->toHaveKey('items');
        expect($result['params'])->toHaveKey('total_value');
        expect($result['params']['$currency'])->toBe('EUR');
    });

    test('normalizeForAll returns all providers', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: ['element' => 'button']);
        $providers = ['ga4', 'meta', 'posthog'];

        $result = $this->normalizer->normalizeForAll($event, $providers);

        expect($result)->toHaveKeys(['ga4', 'meta', 'posthog']);
        expect($result['ga4']['name'])->toBe('click');
        expect($result['meta']['name'])->toBe('Click');
    });

    test('falls back to original name for unknown provider', function (): void {
        $event = new AnalyticsEvent(name: 'custom_event', params: []);
        $result = $this->normalizer->normalize($event, 'webhook');

        expect($result['name'])->toBe('custom_event');
    });

    test('clearCache clears internal catalog cache', function (): void {
        $event = new AnalyticsEvent(name: 'sign_up', params: []);
        $this->normalizer->normalize($event, 'ga4');
        $this->normalizer->clearCache();

        // After clearing cache, the normalizer should still work
        $result = $this->normalizer->normalize($event, 'ga4');
        expect($result['name'])->toBe('sign_up');
    });
});

// ─── EventSchemaMigrationService Tests ────────────────────────────

describe('EventSchemaMigrationService', function (): void {
    test('registers and retrieves schema', function (): void {
        $schemas = $this->migrationService->getAllSchemas();

        expect($schemas)->toHaveKey('purchase');
        expect($schemas['purchase']['version'])->toBe(2);
        expect($schemas['purchase']['params'])->toHaveKey('transaction_id');
    });

    test('validates event against schema successfully', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 99.99,
            ],
        );

        $result = $this->migrationService->validateEvent($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validates event with missing required parameter', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
        );

        $result = $this->migrationService->validateEvent($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain('Missing required parameter: \'transaction_id\'');
    });

    test('validates event with type mismatch', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 'not-a-float',
            ],
        );

        $result = $this->migrationService->validateEvent($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Parameter 'value' expected type 'float', got 'string'");
    });

    test('warns about unknown parameters', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 99.99,
                'unknown_field' => 'test',
            ],
        );

        $result = $this->migrationService->validateEvent($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->toContain("Unknown parameter: 'unknown_field'");
    });

    test('migrates purchase event from v1 to v2', function (): void {
        $this->migrationService->setEventSchemaVersion('purchase', 1);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 50.0,
            ],
        );

        $migrated = $this->migrationService->migrateEvent($event);

        expect($migrated->params)->toHaveKey('currency');
        expect($migrated->params['currency'])->toBe('USD');
        expect($migrated->params['_schema_version'])->toBe(2);
    });

    test('migrates sign_up event from v1 to v2', function (): void {
        $this->migrationService->setEventSchemaVersion('sign_up', 1);

        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: ['auth_method' => 'google'],
        );

        $migrated = $this->migrationService->migrateEvent($event);

        expect($migrated->params)->toHaveKey('method');
        expect($migrated->params['method'])->toBe('google');
        expect($migrated->params)->not->toHaveKey('auth_method');
        expect($migrated->params['_schema_version'])->toBe(2);
    });

    test('does not migrate event already at latest version', function (): void {
        $this->migrationService->setEventSchemaVersion('purchase', 2);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['transaction_id' => 'TX-001', 'value' => 10.0],
        );

        $migrated = $this->migrationService->migrateEvent($event);

        expect($migrated->name)->toBe($event->name);
        expect($migrated->params)->toEqual($event->params);
    });

    test('registers custom migration', function (): void {
        $this->migrationService->registerMigration('custom_event', 1, 2, function (array $params): array {
            $params['version'] = 2;

            return $params;
        });

        $migrations = $this->migrationService->getAllMigrations();
        expect($migrations)->toHaveKey('custom_event');
    });

    test('registers custom schema', function (): void {
        $this->migrationService->registerSchema('custom_event', [
            'version' => 1,
            'params' => [
                'foo' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $schemas = $this->migrationService->getAllSchemas();
        expect($schemas)->toHaveKey('custom_event');
    });

    test('computes schema diff between two events', function (): void {
        $diff = $this->migrationService->diffSchemas('purchase', 'page_view');

        expect($diff)->toHaveKeys(['added', 'removed', 'changed', 'compatible']);
        expect($diff['compatible'])->toBeBool();
    });

    test('getLatestSchemaVersion returns correct version', function (): void {
        expect($this->migrationService->getLatestSchemaVersion('purchase'))->toBe(2);
        expect($this->migrationService->getLatestSchemaVersion('page_view'))->toBe(1);
        expect($this->migrationService->getLatestSchemaVersion('nonexistent'))->toBe(1);
    });

    test('validates event with no schema definition passes with warning', function (): void {
        $event = new AnalyticsEvent(
            name: 'totally_unknown_event',
            params: ['foo' => 'bar'],
        );

        $result = $this->migrationService->validateEvent($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->toContain('No schema definition found for event');
    });
});

// ─── Version Sweep Tests ─────────────────────────────────────────

describe('Version Sweep v28.0.0', function (): void {
    test('AnalyticsEvent version is 28.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('28.0.0');
    });

    test('EventCatalog contains all categories', function (): void {
        $byCategory = EventCatalog::byCategory();

        expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement', 'security', 'uptime']);
    });

    test('EventCatalog count is positive', function (): void {
        expect(EventCatalog::count())->toBeGreaterThan(0);
    });

    test('EventCatalog validate passes', function (): void {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });
});
