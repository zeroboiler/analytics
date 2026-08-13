<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;
use ZeroBoiler\Analytics\Http\Requests\BatchEventRequest;
use ZeroBoiler\Analytics\Http\Requests\IdentifyRequest;
use ZeroBoiler\Analytics\Http\Requests\PageViewRequest;
use ZeroBoiler\Analytics\Http\Requests\TrackEventRequest;
use ZeroBoiler\Analytics\Http\Requests\UpdateConsentRequest;
use ZeroBoiler\Analytics\Services\EventTraceService;

// ─── Helpers ───────────────────────────────────────────────────────

/**
 * Create a mock config repository.
 *
 * @param  array<string, mixed>  $values
 */
function makeMockConfig(array $values): \Illuminate\Contracts\Config\Repository
{
    return new class implements \Illuminate\Contracts\Config\Repository {
        /** @var array<string, mixed> */
        private array $values;

        /**
         * @param  array<string, mixed>  $values
         */
        public function __construct(array $values)
        {
            $this->values = $values;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->values;
        }

        public function set(array|string $key, mixed $value = null): void {}

        public function prepend(string $key, mixed $value): void {}

        public function push(string $key, mixed $value): void {}
    }($values);
}

function makeTraceService(bool $enabled = true, string $source = 'server'): EventTraceService
{
    return new EventTraceService(makeMockConfig([
        'zeroboiler.analytics.tracing' => [
            'enabled' => $enabled,
            'source' => $source,
        ],
    ]));
}

// ─── TraceContext Tests ───────────────────────────────────────────

describe('TraceContext', function () {
    test('generate creates valid IDs', function () {
        $trace = TraceContext::generate('api');

        expect($trace->traceId())->toMatch('/^[a-f0-9]{32}$/')
            ->and($trace->spanId())->toMatch('/^[a-f0-9]{16}$/')
            ->and($trace->parentSpanId())->toBeNull()
            ->and($trace->source())->toBe('api');
    });

    test('child span inherits trace ID', function () {
        $parent = TraceContext::generate('server');
        $child = $parent->childSpan('queue');

        expect($child->traceId())->toBe($parent->traceId())
            ->and($child->parentSpanId())->toBe($parent->spanId())
            ->and($child->spanId())->not->toBe($parent->spanId())
            ->and($child->source())->toBe('queue');
    });

    test('toParams returns correct structure', function () {
        $trace = TraceContext::generate('api');
        $params = $trace->toParams();

        expect($params)->toHaveKeys(['_trace_id', '_span_id', '_parent_span_id', '_trace_source'])
            ->and($params['_trace_id'])->toBe($trace->traceId())
            ->and($params['_span_id'])->toBe($trace->spanId())
            ->and($params['_parent_span_id'])->toBeNull()
            ->and($params['_trace_source'])->toBe('api');
    });

    test('fromParams extracts existing context', function () {
        $original = TraceContext::generate('client');
        $params = $original->toParams();

        $extracted = TraceContext::fromParams($params);

        expect($extracted)->not->toBeNull()
            ->and($extracted->traceId())->toBe($original->traceId())
            ->and($extracted->spanId())->toBe($original->spanId())
            ->and($extracted->source())->toBe($original->source());
    });

    test('fromParams returns null for missing data', function () {
        expect(TraceContext::fromParams([]))->toBeNull()
            ->and(TraceContext::fromParams(['_trace_id' => 'abc']))->toBeNull()
            ->and(TraceContext::fromParams(['_span_id' => 'def']))->toBeNull();
    });

    test('toString includes all context info', function () {
        $parent = TraceContext::generate('server');
        $child = $parent->childSpan('queue');

        $str = $child->toString();

        expect($str)->toContain($child->traceId())
            ->and($str)->toContain($child->spanId())
            ->and($str)->toContain('parent: '.$parent->spanId())
            ->and($str)->toContain('[queue]');
    });

    test('child span with parent ID in toParams', function () {
        $parent = TraceContext::generate('server');
        $child = $parent->childSpan('queue');
        $params = $child->toParams();

        expect($params['_parent_span_id'])->toBe($parent->spanId());
    });
});

// ─── EventTraceService Tests ───────────────────────────────────────

describe('EventTraceService', function () {
    test('inject adds trace context to event', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(name: 'button_click', params: ['element' => 'buy']);

        $traced = $service->inject($event);

        expect($traced->params)->toHaveKeys(['_trace_id', '_span_id', '_trace_source'])
            ->and($traced->params['_trace_source'])->toBe('server')
            ->and($traced->params['element'])->toBe('buy');
    });

    test('inject with custom source', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(name: 'api_call');

        $traced = $service->inject($event, 'api');

        expect($traced->params['_trace_source'])->toBe('api');
    });

    test('disabled service does not inject', function () {
        $service = makeTraceService(enabled: false);
        $event = new AnalyticsEvent(name: 'button_click', params: ['key' => 'value']);

        $traced = $service->inject($event);

        expect($traced->params)->not->toHaveKey('_trace_id')
            ->and($traced->params)->not->toHaveKey('_span_id');
    });

    test('preserves existing trace context as child span', function () {
        $service = makeTraceService(enabled: true);
        $originalTrace = TraceContext::generate('client');
        $event = new AnalyticsEvent(
            name: 'button_click',
            params: array_merge(['key' => 'value'], $originalTrace->toParams()),
        );

        $traced = $service->inject($event, 'server');

        expect($traced->params['_trace_id'])->toBe($originalTrace->traceId())
            ->and($traced->params['_span_id'])->not->toBe($originalTrace->spanId())
            ->and($traced->params['_parent_span_id'])->toBe($originalTrace->spanId());
    });

    test('batch injection shares trace ID across events', function () {
        $service = makeTraceService(enabled: true);
        $events = [
            new AnalyticsEvent(name: 'event_a'),
            new AnalyticsEvent(name: 'event_b'),
            new AnalyticsEvent(name: 'event_c'),
        ];

        $traced = $service->injectBatch($events);

        // All share same trace ID
        expect($traced[0]->params['_trace_id'])->toBe($traced[1]->params['_trace_id'])
            ->and($traced[1]->params['_trace_id'])->toBe($traced[2]->params['_trace_id']);

        // Each has unique span ID
        $spanIds = array_map(
            static fn (AnalyticsEvent $e): string => $e->params['_span_id'],
            $traced,
        );
        expect(array_unique($spanIds))->toHaveCount(3);
    });

    test('batch injection disabled returns unchanged', function () {
        $service = makeTraceService(enabled: false);
        $events = [new AnalyticsEvent(name: 'test')];

        $traced = $service->injectBatch($events);

        expect($traced[0]->params)->not->toHaveKey('_trace_id');
    });

    test('extract retrieves trace from event', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(name: 'test');

        $traced = $service->inject($event);
        $extracted = $service->extract($traced);

        expect($extracted)->not->toBeNull()
            ->and($extracted->traceId())->toBe($traced->params['_trace_id']);
    });

    test('extract returns null when no trace', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);

        expect($service->extract($event))->toBeNull();
    });

    test('strip removes all trace metadata', function () {
        $service = makeTraceService(enabled: true);
        $params = [
            '_trace_id' => 'abc123',
            '_span_id' => 'def456',
            '_parent_span_id' => 'parent',
            '_trace_source' => 'server',
            'user_param' => 'keep_me',
            'element' => 'buy_now',
        ];

        $cleaned = $service->strip($params);

        expect($cleaned)->not->toHaveKey('_trace_id')
            ->and($cleaned)->not->toHaveKey('_span_id')
            ->and($cleaned)->not->toHaveKey('_parent_span_id')
            ->and($cleaned)->not->toHaveKey('_trace_source')
            ->and($cleaned['user_param'])->toBe('keep_me')
            ->and($cleaned['element'])->toBe('buy_now');
    });

    test('isEnabled reflects config', function () {
        expect(makeTraceService(enabled: true)->isEnabled())->toBeTrue()
            ->and(makeTraceService(enabled: false)->isEnabled())->toBeFalse();
    });

    test('createTrace generates with configured source', function () {
        $service = makeTraceService(enabled: true, source: 'custom');
        $trace = $service->createTrace();

        expect($trace->source())->toBe('custom')
            ->and($trace->traceId())->toMatch('/^[a-f0-9]{32}$/');
    });

    test('injected event preserves name, clientId, userId', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'client-abc',
            userId: 'user-123',
        );

        $traced = $service->inject($event);

        expect($traced->name)->toBe('purchase')
            ->and($traced->clientId)->toBe('client-abc')
            ->and($traced->userId)->toBe('user-123')
            ->and($traced->params['value'])->toBe(99.99);
    });

    test('batch trace round-trip: inject then extract', function () {
        $service = makeTraceService(enabled: true);
        $events = [
            new AnalyticsEvent(name: 'signup'),
            new AnalyticsEvent(name: 'login'),
        ];

        $traced = $service->injectBatch($events, 'api');
        $sharedTraceId = $traced[0]->params['_trace_id'];

        foreach ($traced as $event) {
            $ctx = $service->extract($event);
            expect($ctx)->not->toBeNull()
                ->and($ctx->traceId())->toBe($sharedTraceId);
        }
    });
});

// ─── TrackEventRequest Tests ───────────────────────────────────────

describe('TrackEventRequest', function () {
    test('rules include name, params, client_id, timestamp', function () {
        $request = TrackEventRequest::create('/', 'POST', [
            'name' => 'button_click',
            'params' => ['element' => 'buy_now'],
            'client_id' => 'client-uuid-123',
        ]);

        $rules = $request->rules();

        expect($rules)->toHaveKey('name')
            ->and($rules)->toHaveKey('params')
            ->and($rules)->toHaveKey('client_id')
            ->and($rules)->toHaveKey('timestamp')
            ->and($rules['name'])->toBe('required|string|max:100');
    });

    test('accessors return correct values', function () {
        $request = TrackEventRequest::create('/', 'POST', [
            'name' => 'button_click',
            'params' => ['element' => 'buy_now'],
            'client_id' => 'client-uuid-123',
        ]);

        expect($request->eventName())->toBe('button_click')
            ->and($request->eventParams())->toBe(['element' => 'buy_now'])
            ->and($request->clientId())->toBe('client-uuid-123')
            ->and($request->timestamp())->toBeNull();
    });

    test('accessors handle missing optional fields', function () {
        $request = TrackEventRequest::create('/', 'POST', [
            'name' => 'test_event',
        ]);

        expect($request->eventName())->toBe('test_event')
            ->and($request->eventParams())->toBe([])
            ->and($request->clientId())->toBeNull()
            ->and($request->timestamp())->toBeNull();
    });

    test('messages are defined', function () {
        $request = TrackEventRequest::create('/', 'POST', []);
        $messages = $request->messages();

        expect($messages)->toHaveKey('name.required')
            ->and($messages)->toHaveKey('name.max');
    });

    test('attributes are defined', function () {
        $request = TrackEventRequest::create('/', 'POST', []);
        $attrs = $request->attributes();

        expect($attrs['name'])->toBe('event name')
            ->and($attrs['params'])->toBe('event parameters');
    });
});

// ─── BatchEventRequest Tests ───────────────────────────────────────

describe('BatchEventRequest', function () {
    test('rules limit batch to 25 events', function () {
        $request = BatchEventRequest::create('/', 'POST', [
            'events' => array_fill(0, 3, ['name' => 'test']),
        ]);

        $rules = $request->rules();

        expect($rules['events'])->toContain('max:25')
            ->and($rules['events.*.name'])->toBe('required|string|max:100');
    });

    test('events accessor returns structured array', function () {
        $request = BatchEventRequest::create('/', 'POST', [
            'events' => [
                ['name' => 'event_a', 'params' => ['key' => 'value']],
                ['name' => 'event_b', 'params' => ['key' => 'value2']],
                ['name' => 'event_c'],
            ],
        ]);

        $events = $request->events();

        expect($events)->toHaveCount(3)
            ->and($events[0]['name'])->toBe('event_a')
            ->and($events[0]['params'])->toBe(['key' => 'value'])
            ->and($events[2]['params'])->toBe([]);
    });

    test('batchSize returns correct count', function () {
        $request = BatchEventRequest::create('/', 'POST', [
            'events' => [
                ['name' => 'a'],
                ['name' => 'b'],
                ['name' => 'c'],
            ],
        ]);

        expect($request->batchSize())->toBe(3);
    });

    test('handles empty events array', function () {
        $request = BatchEventRequest::create('/', 'POST', ['events' => []]);

        expect($request->events())->toBe([])
            ->and($request->batchSize())->toBe(0);
    });
});

// ─── IdentifyRequest Tests ─────────────────────────────────────────

describe('IdentifyRequest', function () {
    test('rules require client_id', function () {
        $request = IdentifyRequest::create('/', 'POST', [
            'client_id' => 'client-uuid-abc',
            'traits' => ['name' => 'John', 'plan' => 'pro'],
        ]);

        $rules = $request->rules();

        expect($rules['client_id'])->toBe('required|string|max:64')
            ->and($rules)->toHaveKey('traits');
    });

    test('accessors return correct values', function () {
        $request = IdentifyRequest::create('/', 'POST', [
            'client_id' => 'client-uuid-abc',
            'traits' => ['name' => 'John', 'plan' => 'pro'],
        ]);

        expect($request->clientId())->toBe('client-uuid-abc')
            ->and($request->traits())->toBe(['name' => 'John', 'plan' => 'pro']);
    });

    test('handles empty traits', function () {
        $request = IdentifyRequest::create('/', 'POST', ['client_id' => 'test-id']);

        expect($request->clientId())->toBe('test-id')
            ->and($request->traits())->toBe([]);
    });

    test('messages are defined', function () {
        $request = IdentifyRequest::create('/', 'POST', []);
        $messages = $request->messages();

        expect($messages)->toHaveKey('client_id.required')
            ->and($messages)->toHaveKey('traits.array');
    });
});

// ─── UpdateConsentRequest Tests ───────────────────────────────────

describe('UpdateConsentRequest', function () {
    test('rules validate consent signals', function () {
        $request = UpdateConsentRequest::create('/', 'POST', [
            'signals' => [
                'analytics_storage' => 'granted',
                'ad_storage' => 'denied',
            ],
            'source' => 'banner',
        ]);

        $rules = $request->rules();

        expect($rules['signals'])->toContain('in:granted,denied')
            ->and($rules)->toHaveKey('source');
    });

    test('accessors return correct values', function () {
        $request = UpdateConsentRequest::create('/', 'POST', [
            'signals' => [
                'analytics_storage' => 'granted',
                'ad_storage' => 'denied',
            ],
            'source' => 'banner',
        ]);

        $signals = $request->signals();

        expect($signals['analytics_storage'])->toBe('granted')
            ->and($signals['ad_storage'])->toBe('denied')
            ->and($request->source())->toBe('banner');
    });

    test('filters out invalid signal values', function () {
        $request = UpdateConsentRequest::create('/', 'POST', [
            'signals' => [
                'analytics_storage' => 'granted',
                'invalid_signal' => 'maybe',
                'ad_storage' => 'denied',
            ],
        ]);

        $signals = $request->signals();

        expect($signals)->toHaveKey('analytics_storage')
            ->and($signals)->toHaveKey('ad_storage')
            ->and($signals)->not->toHaveKey('invalid_signal');
    });

    test('handles non-array signals', function () {
        $request = UpdateConsentRequest::create('/', 'POST', [
            'signals' => 'not_array',
        ]);

        expect($request->signals())->toBe([]);
    });
});

// ─── PageViewRequest Tests ─────────────────────────────────────────

describe('PageViewRequest', function () {
    test('rules validate optional fields', function () {
        $request = PageViewRequest::create('/', 'POST', [
            'title' => 'Pricing Page',
            'location' => 'https://example.com/pricing',
        ]);

        $rules = $request->rules();

        expect($rules)->toHaveKey('title')
            ->and($rules)->toHaveKey('location')
            ->and($rules)->toHaveKey('referrer')
            ->and($rules)->toHaveKey('path')
            ->and($rules['title'])->toContain('max:500');
    });

    test('accessors return correct values', function () {
        $request = PageViewRequest::create('/', 'POST', [
            'title' => 'Pricing Page',
            'location' => 'https://example.com/pricing',
            'referrer' => 'https://google.com',
            'path' => '/pricing',
        ]);

        expect($request->pageTitle())->toBe('Pricing Page')
            ->and($request->pageLocation())->toBe('https://example.com/pricing')
            ->and($request->referrer())->toBe('https://google.com')
            ->and($request->path())->toBe('/pricing');
    });

    test('handles empty request', function () {
        $request = PageViewRequest::create('/', 'POST', []);

        expect($request->pageTitle())->toBeNull()
            ->and($request->pageLocation())->toBeNull()
            ->and($request->referrer())->toBeNull()
            ->and($request->path())->toBeNull();
    });
});

// ─── Cross-cutting Integration Tests ───────────────────────────────

describe('v3.8.0 Integration', function () {
    test('version constant is 76.0.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('76.0.0');
    });

    test('TraceContext generates unique IDs per call', function () {
        $a = TraceContext::generate();
        $b = TraceContext::generate();

        expect($a->traceId())->not->toBe($b->traceId())
            ->and($a->spanId())->not->toBe($b->spanId());
    });

    test('EventTraceService inject → extract round-trip preserves trace', function () {
        $service = makeTraceService(enabled: true);
        $event = new AnalyticsEvent(name: 'checkout', params: ['value' => 49.99]);

        $traced = $service->inject($event, 'api');
        $extracted = $service->extract($traced);

        expect($extracted)->not->toBeNull()
            ->and($extracted->traceId())->toBe($traced->params['_trace_id'])
            ->and($extracted->source())->toBe('api');
    });

    test('batch tracing: 10 events share trace ID', function () {
        $service = makeTraceService(enabled: true);
        $events = array_map(
            static fn (int $i): AnalyticsEvent => new AnalyticsEvent(name: "event_{$i}"),
            range(0, 9),
        );

        $traced = $service->injectBatch($events, 'queue');

        $traceIds = array_map(
            static fn (AnalyticsEvent $e): string => $e->params['_trace_id'],
            $traced,
        );

        // All share the same trace ID
        expect(array_unique($traceIds))->toHaveCount(1);

        // But each has a unique span ID
        $spanIds = array_map(
            static fn (AnalyticsEvent $e): string => $e->params['_span_id'],
            $traced,
        );
        expect(array_unique($spanIds))->toHaveCount(10);
    });

    test('FormRequest rules cover all API endpoint requirements', function () {
        $trackRules = TrackEventRequest::create('/', 'POST', ['name' => 'x'])->rules();
        $batchRules = BatchEventRequest::create('/', 'POST', ['events' => [['name' => 'x']]])->rules();
        $identifyRules = IdentifyRequest::create('/', 'POST', ['client_id' => 'x'])->rules();
        $consentRules = UpdateConsentRequest::create('/', 'POST', ['signals' => ['a' => 'granted']])->rules();
        $pvRules = PageViewRequest::create('/', 'POST', [])->rules();

        // TrackEventRequest: name required
        expect($trackRules['name'])->toContain('required');

        // BatchEventRequest: events required, max 25
        expect($batchRules['events'])->toContain('required')
            ->and($batchRules['events'])->toContain('max:25');

        // IdentifyRequest: client_id required
        expect($identifyRules['client_id'])->toContain('required');

        // UpdateConsentRequest: signals required, in:granted,denied
        expect($consentRules['signals'])->toContain('required')
            ->and($consentRules['signals.*'])->toContain('in:granted,denied');

        // PageViewRequest: all optional (sometimes)
        expect($pvRules['title'])->toContain('sometimes');
    });
});
