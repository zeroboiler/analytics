<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\ConsentGateMiddleware;
use ZeroBoiler\Analytics\Middleware\ContextAttachmentMiddleware;
use ZeroBoiler\Analytics\Middleware\LoggingMiddleware;
use ZeroBoiler\Analytics\Middleware\TimestampMiddleware;
use ZeroBoiler\Analytics\Schema\EventParam;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Middleware\SchemaValidationMiddleware;

describe('AnalyticsMiddlewareStack', function () {
    it('processes events through middleware in priority order', function () {
        $executionOrder = [];

        $middleware1 = new class($executionOrder) implements \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface {
            /** @var array<int, string> */
            private array $order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(AnalyticsEvent $event): ?AnalyticsEvent {
                $this->order[] = 'first';
                return $event;
            }
            public function priority(): int { return 50; }
            public function name(): string { return 'first'; }
        };

        $middleware2 = new class($executionOrder) implements \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface {
            /** @var array<int, string> */
            private array $order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(AnalyticsEvent $event): ?AnalyticsEvent {
                $this->order[] = 'second';
                return $event;
            }
            public function priority(): int { return 10; }
            public function name(): string { return 'second'; }
        };

        $stack = new AnalyticsMiddlewareStack;
        $stack->add($middleware1);
        $stack->add($middleware2);

        $event = new AnalyticsEvent('test', []);
        $stack->process($event);

        // Priority 10 (second) should execute before priority 50 (first)
        expect($executionOrder)->toBe(['second', 'first']);
    });

    it('drops event when middleware returns null', function () {
        $dropper = new class implements \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface {
            public function process(AnalyticsEvent $event): ?AnalyticsEvent { return null; }
            public function priority(): int { return 10; }
            public function name(): string { return 'dropper'; }
        };

        $stack = new AnalyticsMiddlewareStack;
        $stack->add($dropper);

        $result = $stack->process(new AnalyticsEvent('test', []));

        expect($result)->toBeNull();
    });

    it('passes event through when stack is empty', function () {
        $stack = new AnalyticsMiddlewareStack;

        $event = new AnalyticsEvent('test', ['key' => 'value']);
        $result = $stack->process($event);

        expect($result)->not()->toBeNull();
        expect($result->name)->toBe('test');
    });

    it('processes many events', function () {
        $stack = new AnalyticsMiddlewareStack;
        $stack->add(new ContextAttachmentMiddleware(['source' => 'middleware']));

        $events = [
            new AnalyticsEvent('event_1', []),
            new AnalyticsEvent('event_2', []),
            new AnalyticsEvent('event_3', []),
        ];

        $results = $stack->processMany($events);

        expect(count($results))->toBe(3);
        foreach ($results as $result) {
            expect($result->params['source'])->toBe('middleware');
        }
    });

    it('filters out dropped events in processMany', function () {
        $stack = new AnalyticsMiddlewareStack;
        $stack->add(new class implements \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface {
            public function process(AnalyticsEvent $event): ?AnalyticsEvent {
                return $event->name === 'allowed' ? $event : null;
            }
            public function priority(): int { return 10; }
            public function name(): string { return 'filter'; }
        });

        $events = [
            new AnalyticsEvent('dropped', []),
            new AnalyticsEvent('allowed', []),
            new AnalyticsEvent('dropped_too', []),
        ];

        $results = $stack->processMany($events);

        expect(count($results))->toBe(1);
        expect($results[0]->name)->toBe('allowed');
    });

    it('removes middleware by name', function () {
        $stack = new AnalyticsMiddlewareStack;
        $stack->add(new ConsentGateMiddleware(true));
        $stack->add(new TimestampMiddleware);

        expect($stack->has('consent_gate'))->toBeTrue();
        expect($stack->has('timestamp'))->toBeTrue();

        $stack->removeByName('consent_gate');

        expect($stack->has('consent_gate'))->toBeFalse();
        expect($stack->has('timestamp'))->toBeTrue();
    });

    it('removes middleware by class', function () {
        $stack = new AnalyticsMiddlewareStack;
        $stack->add(new ConsentGateMiddleware(true));
        $stack->add(new TimestampMiddleware);

        $stack->removeByClass(ConsentGateMiddleware::class);

        expect($stack->has('consent_gate'))->toBeFalse();
        expect($stack->has('timestamp'))->toBeTrue();
    });

    it('reports middleware names in execution order', function () {
        $stack = new AnalyticsMiddlewareStack;
        $stack->add(new ConsentGateMiddleware(true)); // priority 5
        $stack->add(new TimestampMiddleware);         // priority 80
        $stack->add(new ContextAttachmentMiddleware([])); // priority 20

        $names = $stack->getMiddlewareNames();

        expect($names)->toBe(['consent_gate', 'context_attachment', 'timestamp']);
    });

    it('reports correct count', function () {
        $stack = new AnalyticsMiddlewareStack;
        expect($stack->count())->toBe(0);

        $stack->add(new ConsentGateMiddleware(true));
        $stack->add(new TimestampMiddleware);
        expect($stack->count())->toBe(2);

        $stack->clear();
        expect($stack->count())->toBe(0);
    });
});

describe('ConsentGateMiddleware', function () {
    it('passes events when analytics consent is granted', function () {
        $middleware = new ConsentGateMiddleware(true, true);
        $event = new AnalyticsEvent('page_view', []);

        $result = $middleware->process($event);

        expect($result)->not()->toBeNull();
    });

    it('drops all events when analytics consent is denied', function () {
        $middleware = new ConsentGateMiddleware(false);
        $event = new AnalyticsEvent('page_view', []);

        $result = $middleware->process($event);

        expect($result)->toBeNull();
    });

    it('drops ad-related events when ad consent is denied', function () {
        $middleware = new ConsentGateMiddleware(true, false);

        $adEvent = $middleware->process(new AnalyticsEvent('ad_impression', []));
        expect($adEvent)->toBeNull();

        $normalEvent = $middleware->process(new AnalyticsEvent('page_view', []));
        expect($normalEvent)->not()->toBeNull();
    });

    it('executes at high priority', function () {
        $middleware = new ConsentGateMiddleware(true);
        expect($middleware->priority())->toBeLessThan(50);
    });
});

describe('ContextAttachmentMiddleware', function () {
    it('attaches context to event params', function () {
        $middleware = new ContextAttachmentMiddleware([
            'session_id' => 'abc123',
            'utm_source' => 'google',
        ]);

        $event = new AnalyticsEvent('page_view', ['page' => '/home']);
        $result = $middleware->process($event);

        expect($result->params['session_id'])->toBe('abc123');
        expect($result->params['utm_source'])->toBe('google');
        expect($result->params['page'])->toBe('/home');
    });

    it('event params take precedence over context', function () {
        $middleware = new ContextAttachmentMiddleware([
            'page' => '/default',
            'source' => 'override_test',
        ]);

        $event = new AnalyticsEvent('page_view', ['page' => '/actual']);
        $result = $middleware->process($event);

        expect($result->params['page'])->toBe('/actual');
        expect($result->params['source'])->toBe('override_test');
    });

    it('preserves client_id and user_id', function () {
        $middleware = new ContextAttachmentMiddleware(['extra' => 'data']);
        $event = new AnalyticsEvent('test', [], clientId: 'client-123', userId: 'user-456');

        $result = $middleware->process($event);

        expect($result->clientId)->toBe('client-123');
        expect($result->userId)->toBe('user-456');
    });
});

describe('TimestampMiddleware', function () {
    it('adds timestamp to event params', function () {
        $middleware = new TimestampMiddleware;
        $event = new AnalyticsEvent('test', []);

        $result = $middleware->process($event);

        expect($result->params)->toHaveKey('timestamp');
        expect($result->params['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    });

    it('does not overwrite existing timestamp by default', function () {
        $middleware = new TimestampMiddleware(overwrite: false);
        $event = new AnalyticsEvent('test', ['timestamp' => '2025-01-01T00:00:00Z']);

        $result = $middleware->process($event);

        expect($result->params['timestamp'])->toBe('2025-01-01T00:00:00Z');
    });

    it('overwrites when configured', function () {
        $middleware = new TimestampMiddleware(overwrite: true);
        $event = new AnalyticsEvent('test', ['timestamp' => 'old-timestamp']);

        $result = $middleware->process($event);

        expect($result->params['timestamp'])->not()->toBe('old-timestamp');
    });

    it('uses custom param name', function () {
        $middleware = new TimestampMiddleware(paramName: 'server_timestamp');
        $event = new AnalyticsEvent('test', []);

        $result = $middleware->process($event);

        expect($result->params)->toHaveKey('server_timestamp');
        expect($result->params)->not()->toHaveKey('timestamp');
    });
});

describe('SchemaValidationMiddleware', function () {
    it('passes events that match schema', function () {
        $registry = new EventSchemaRegistry;
        $middleware = new SchemaValidationMiddleware($registry, strict: true);

        $event = new AnalyticsEvent('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
        ]);

        $result = $middleware->process($event);
        expect($result)->not()->toBeNull();
    });

    it('drops events in strict mode when required params are missing', function () {
        $registry = new EventSchemaRegistry;
        $middleware = new SchemaValidationMiddleware($registry, strict: true);

        $event = new AnalyticsEvent('purchase', []);

        $result = $middleware->process($event);
        expect($result)->toBeNull();
    });

    it('sanitizes but does not drop in permissive mode', function () {
        $registry = new EventSchemaRegistry;
        $middleware = new SchemaValidationMiddleware($registry, strict: false);

        $event = new AnalyticsEvent('purchase', []);

        $result = $middleware->process($event);
        expect($result)->not()->toBeNull();
    });

    it('passes events with no registered schema', function () {
        $registry = new EventSchemaRegistry;
        $middleware = new SchemaValidationMiddleware($registry, strict: true);

        $event = new AnalyticsEvent('custom_unlisted_event', ['foo' => 'bar']);

        $result = $middleware->process($event);
        expect($result)->not()->toBeNull();
        expect($result->params['foo'])->toBe('bar');
    });
});

describe('createDefault stack', function () {
    it('creates a stack with default middleware', function () {
        $stack = AnalyticsMiddlewareStack::createDefault(
            analyticsGranted: true,
            context: ['app' => 'test'],
        );

        expect($stack->count())->toBeGreaterThanOrEqual(3);
        expect($stack->has('consent_gate'))->toBeTrue();
        expect($stack->has('context_attachment'))->toBeTrue();
        expect($stack->has('timestamp'))->toBeTrue();
        expect($stack->has('logging'))->toBeTrue();
    });

    it('processes events through the default stack', function () {
        $stack = AnalyticsMiddlewareStack::createDefault(
            analyticsGranted: true,
            context: ['source' => 'default_stack'],
        );

        $event = new AnalyticsEvent('page_view', ['page' => '/home']);
        $result = $stack->process($event);

        expect($result)->not()->toBeNull();
        expect($result->params['source'])->toBe('default_stack');
        expect($result->params['page'])->toBe('/home');
        expect($result->params)->toHaveKey('timestamp');
    });

    it('drops events when consent is denied in default stack', function () {
        $stack = AnalyticsMiddlewareStack::createDefault(
            analyticsGranted: false,
        );

        $result = $stack->process(new AnalyticsEvent('page_view', []));
        expect($result)->toBeNull();
    });
});
