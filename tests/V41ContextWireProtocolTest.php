<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\AnalyticsContext;
use ZeroBoiler\Analytics\Support\TypedEventBuilder;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsWireProtocolService;

beforeEach(function (): void {
    // Verify core classes exist
    expect(class_exists(AnalyticsContext::class))->toBeTrue();
    expect(class_exists(TypedEventBuilder::class))->toBeTrue();
    expect(class_exists(AnalyticsWireProtocolService::class))->toBeTrue();
});

/* ─── AnalyticsContext ─────────────────────────────────────────── */

describe('AnalyticsContext', function (): void {
    it('creates a context with label', function (): void {
        $ctx = AnalyticsContext::create('checkout.process');

        expect($ctx->getLabel())->toBe('checkout.process');
        expect($ctx->getMetadata())->toBe([]);
        expect($ctx->isStarted())->toBeFalse();
    });

    it('creates a context with source', function (): void {
        $ctx = AnalyticsContext::create('api.sync', 'webhook');

        expect($ctx->getLabel())->toBe('api.sync');
        expect($ctx->getSource())->toBe('webhook');
    });

    it('creates a silent context with no emissions', function (): void {
        $ctx = AnalyticsContext::silent('silent.op');

        expect($ctx->getLabel())->toBe('silent.op');
        expect($ctx->isStarted())->toBeFalse();
    });

    it('chains withMetadata', function (): void {
        $ctx = AnalyticsContext::create('test')
            ->withMetadata(['region' => 'eu', 'env' => 'staging']);

        expect($ctx->getMetadata())->toBe([
            'region' => 'eu',
            'env' => 'staging',
        ]);
    });

    it('chains withClientId and withUserId', function (): void {
        $ctx = AnalyticsContext::create('test')
            ->withClientId('client_abc')
            ->withUserId('user_123')
            ->withPriority('critical');

        expect($ctx->getClientId())->toBe('client_abc');
        expect($ctx->getUserId())->toBe('user_123');
        expect($ctx->getPriority())->toBe('critical');
    });

    it('chains withTiming, withErrorCapture, withLifecycle', function (): void {
        $ctx = AnalyticsContext::create('test')
            ->withTiming(false)
            ->withErrorCapture(false)
            ->withLifecycle(true);

        expect($ctx->describe())->toBeString();
    });

    it('measures closure execution and returns result', function (): void {
        $manager = app('zeroboiler.analytics');

        $result = AnalyticsContext::measure($manager, 'test.measure', function (): int {
            return 42;
        });

        expect($result)->toBe(42);
    });

    it('measures closure with timing events emitted', function (): void {
        $manager = app('zeroboiler.analytics');

        $result = AnalyticsContext::create('test.timing')
            ->withLifecycle(true)
            ->withTiming(true)
            ->run($manager, function (): string {
                return 'measured';
            });

        expect($result)->toBe('measured');
    });

    it('captures exceptions and re-throws', function (): void {
        $manager = app('zeroboiler.analytics');

        expect(function () use ($manager): void {
            AnalyticsContext::create('test.error')
                ->withErrorCapture(true)
                ->run($manager, function (): void {
                    throw new \RuntimeException('test failure');
                });
        })->toThrow(\RuntimeException::class, 'test failure');
    });

    it('returns elapsedMs as null before start', function (): void {
        $ctx = AnalyticsContext::create('test');

        expect($ctx->elapsedMs())->toBeNull();
    });
});

/* ─── TypedEventBuilder ─────────────────────────────────────── */

describe('TypedEventBuilder', function (): void {
    it('creates a builder for any event name', function (): void {
        $builder = TypedEventBuilder::for('custom_event');

        expect($builder->getName())->toBe('custom_event');
        expect($builder->hasErrors())->toBeFalse();
    });

    it('creates a catalog-validated builder', function (): void {
        $builder = TypedEventBuilder::catalogEvent('purchase');

        expect($builder->getName())->toBe('purchase');
        expect($builder->isInCatalog())->toBeTrue();
        expect($builder->getCatalogCategory())->toBe('ecommerce');
    });

    it('throws on invalid catalog event name', function (): void {
        expect(function (): void {
            TypedEventBuilder::catalogEvent('nonexistent_event_xyz');
        })->toThrow(\InvalidArgumentException::class);
    });

    it('chains params fluently', function (): void {
        $event = TypedEventBuilder::for('purchase')
            ->param('transaction_id', 'txn_123')
            ->param('value', 99.99)
            ->param('currency', 'USD')
            ->build();

        expect($event->name)->toBe('purchase');
        expect($event->params)->toBe([
            'transaction_id' => 'txn_123',
            'value' => 99.99,
            'currency' => 'USD',
        ]);
    });

    it('chains bulk params', function (): void {
        $event = TypedEventBuilder::for('test')
            ->params([
                'key1' => 'val1',
                'key2' => 'val2',
            ])
            ->build();

        expect($event->params['key1'])->toBe('val1');
        expect($event->params['key2'])->toBe('val2');
    });

    it('sets clientId, userId, priority, source', function (): void {
        $event = TypedEventBuilder::for('test')
            ->clientId('client_abc')
            ->userId('user_123')
            ->priority('critical')
            ->source('server')
            ->build();

        expect($event->clientId)->toBe('client_abc');
        expect($event->userId)->toBe('user_123');
        expect($event->priority)->toBe('critical');
        expect($event->source)->toBe('server');
    });

    it('rejects invalid priority', function (): void {
        expect(function (): void {
            TypedEventBuilder::for('test')
                ->priority('invalid_priority')
                ->build();
        })->toThrow(\RuntimeException::class);
    });

    it('rejects invalid source', function (): void {
        $builder = TypedEventBuilder::for('test')
            ->source('invalid_source');

        expect($builder->hasErrors())->toBeTrue();
        expect($builder->getErrors()[0])->toContain('Invalid source');
    });

    it('builds unsafe without throwing', function (): void {
        $event = TypedEventBuilder::for('test')
            ->priority('invalid')
            ->buildUnsafe();

        expect($event->name)->toBe('test');
    });

    it('merges from existing event', function (): void {
        $original = new AnalyticsEvent(
            name: 'test',
            params: ['existing' => true],
            clientId: 'original_client',
        );

        $event = TypedEventBuilder::for('test')
            ->mergeFrom($original)
            ->param('new_param', 'value')
            ->build();

        expect($event->params['existing'])->toBeTrue();
        expect($event->params['new_param'])->toBe('value');
        expect($event->clientId)->toBe('original_client');
    });

    it('describes the event being built', function (): void {
        $builder = TypedEventBuilder::for('purchase')
            ->clientId('abc123')
            ->userId('user1')
            ->priority('normal');

        $desc = $builder->describe();
        expect($desc)->toContain('purchase');
        expect($desc)->toContain('user=user1');
    });

    it('checks catalog membership', function (): void {
        $catalogEvent = TypedEventBuilder::for('purchase');
        expect($catalogEvent->isInCatalog())->toBeTrue();

        $customEvent = TypedEventBuilder::for('my_custom_event');
        expect($customEvent->isInCatalog())->toBeFalse();
    });
});

/* ─── AnalyticsWireProtocolService ────────────────────────────── */

describe('AnalyticsWireProtocolService', function (): void {
    it('serializes a single event to wire format', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'client_abc',
            userId: 'user_123',
            priority: 'critical',
            source: 'server',
        );

        $json = $protocol->serialize($event);

        expect($json)->toBeJson();

        $data = json_decode($json, true);
        expect($data['protocol'])->toBe('zb_analytics/1.0');
        expect($data['version'])->toBe(AnalyticsEvent::VERSION);
        expect($data['event']['name'])->toBe('purchase');
        expect($data['event']['params']['value'])->toBe(99.99);
        expect($data['event']['client_id'])->toBe('client_abc');
        expect($data['event']['user_id'])->toBe('user_123');
        expect($data['event']['priority'])->toBe('critical');
        expect($data['event']['source'])->toBe('server');
        expect($data['correlation_id'])->toBeString();
        expect($data['timestamp'])->toBeString();
    });

    it('serializes batch events', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $events = [
            new AnalyticsEvent(name: 'purchase', params: ['value' => 50.0]),
            new AnalyticsEvent(name: 'sign_up', params: ['method' => 'google']),
        ];

        $json = $protocol->serializeBatch($events);

        $data = json_decode($json, true);
        expect($data['batch'])->toBeTrue();
        expect($data['count'])->toBe(2);
        expect($data['events'][0]['name'])->toBe('purchase');
        expect($data['events'][1]['name'])->toBe('sign_up');
    });

    it('deserializes a single event wire payload', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $original = new AnalyticsEvent(
            name: 'page_view',
            params: ['url' => '/home'],
            clientId: 'c1',
        );

        $json = $protocol->serialize($original);
        $restored = $protocol->deserialize($json);

        expect($restored->name)->toBe('page_view');
        expect($restored->params['url'])->toBe('/home');
        expect($restored->clientId)->toBe('c1');
    });

    it('deserializes a batch wire payload', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $events = [
            new AnalyticsEvent(name: 'purchase', params: []),
            new AnalyticsEvent(name: 'refund', params: []),
        ];

        $json = $protocol->serializeBatch($events);
        $restored = $protocol->deserializeBatch($json);

        expect(count($restored))->toBe(2);
        expect($restored[0]->name)->toBe('purchase');
        expect($restored[1]->name)->toBe('refund');
    });

    it('validates a well-formed wire envelope', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'val']);
        $json = $protocol->serialize($event);

        $result = $protocol->validate($json);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBe([]);
        expect($result['event_count'])->toBe(1);
    });

    it('detects malformed JSON', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $result = $protocol->validate('{not valid json');

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    it('detects missing event field', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $result = $protocol->validate(json_encode([
            'protocol' => 'zb_analytics/1.0',
            'version' => '41.0.0',
        ], JSON_THROW_ON_ERROR));

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('Missing');
    });

    it('detects invalid event name', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $result = $protocol->validate(json_encode([
            'protocol' => 'zb_analytics/1.0',
            'version' => '41.0.0',
            'event' => [
                'name' => '',
                'params' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('must not be empty');
    });

    it('validates batch events and reports per-event errors', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $result = $protocol->validate(json_encode([
            'protocol' => 'zb_analytics/1.0',
            'batch' => true,
            'events' => [
                ['name' => 'valid_event', 'params' => []],
                ['params' => []], // missing name
                ['name' => 123, 'params' => []], // invalid type
            ],
        ], JSON_THROW_ON_ERROR));

        expect($result['event_count'])->toBe(3);
        expect($result['errors'])->not->toBeEmpty();
    });

    it('detects wrong protocol version as warning', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $result = $protocol->validate(json_encode([
            'protocol' => 'wrong/protocol',
            'event' => ['name' => 'test', 'params' => []],
        ], JSON_THROW_ON_ERROR));

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'][0])->toContain('Unexpected protocol');
    });

    it('roundtrips an event with all fields', function (): void {
        $protocol = new AnalyticsWireProtocolService;

        $original = new AnalyticsEvent(
            name: 'subscription',
            params: ['plan' => 'Pro', 'value' => 49.99],
            clientId: 'client_round',
            userId: 'user_round',
            timestamp: new \DateTimeImmutable('2026-08-12T10:30:00+00:00'),
            priority: 'normal',
            source: 'api',
        );

        $json = $protocol->serialize($original, [
            'context_label' => 'billing.process',
        ]);

        $restored = $protocol->deserialize($json);

        expect($restored->name)->toBe('subscription');
        expect($restored->params['plan'])->toBe('Pro');
        expect($restored->clientId)->toBe('client_round');
        expect($restored->userId)->toBe('user_round');
        expect($restored->priority)->toBe('normal');
        expect($restored->source)->toBe('api');
        expect($restored->timestamp)->not->toBeNull();
    });

    it('returns protocol and version', function (): void {
        $protocol = new AnalyticsWireProtocolService('custom');

        expect($protocol->getProtocol())->toBe('zb_analytics/1.0');
        expect($protocol->getVersion())->toBe('custom');
    });

    it('serializes with custom metadata', function (): void {
        $protocol = new AnalyticsWireProtocolService;
        $event = new AnalyticsEvent(name: 'test', params: []);
        $json = $protocol->serialize($event, [
            'context_label' => 'pipeline.sync',
            'custom_field' => 'custom_value',
        ]);

        $data = json_decode($json, true);
        expect($data['metadata']['context_label'])->toBe('pipeline.sync');
        expect($data['metadata']['custom_field'])->toBe('custom_value');
    });
});

/* ─── Version Sweep ──────────────────────────────────────────── */

describe('Version Sweep v41.0.0', function (): void {
    it('has consistent version across all markers', function (): void {
        $version = '41.0.0';

        // Check DTO version
        expect(AnalyticsEvent::VERSION)->toBe($version);

        // Check class existence
        expect(class_exists(\ZeroBoiler\Analytics\Support\AnalyticsContext::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Support\TypedEventBuilder::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsWireProtocolService::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Middleware\EventContextMiddleware::class))->toBeTrue();
    });

    it('EventCatalog validates cleanly', function (): void {
        $result = EventCatalog::validate();
        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBe([]);
    });

    it('all new classes use strict types', function (): void {
        $files = [
            __DIR__ . '/../src/Support/AnalyticsContext.php',
            __DIR__ . '/../src/Support/TypedEventBuilder.php',
            __DIR__ . '/../src/Services/AnalyticsWireProtocolService.php',
            __DIR__ . '/../src/Middleware/EventContextMiddleware.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    it('all new classes are final', function (): void {
        $reflection = new \ReflectionClass(AnalyticsContext::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new \ReflectionClass(TypedEventBuilder::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new \ReflectionClass(AnalyticsWireProtocolService::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Middleware\EventContextMiddleware::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('all new classes have docblocks', function (): void {
        $classes = [
            AnalyticsContext::class,
            TypedEventBuilder::class,
            AnalyticsWireProtocolService::class,
            \ZeroBoiler\Analytics\Middleware\EventContextMiddleware::class,
        ];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $doc = $reflection->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@since');
        }
    });
});
