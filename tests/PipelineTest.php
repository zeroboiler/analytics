<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Pipeline\ConsentFilter;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;
use ZeroBoiler\Analytics\Pipeline\UserContextEnricher;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;

// ── EventPipeline Tests ─────────────────────────────────────────────

describe('EventPipeline', function () {
    it('processes event through no pipes', function () {
        $pipeline = new EventPipeline;
        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

        $result = $pipeline->process($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('test_event')
            ->and($result->params)->toBe(['key' => 'value']);
    });

    it('processes event through single pipe', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['processed' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->toBe(['key' => 'value', 'processed' => true]);
    });

    it('processes event through multiple pipes in order', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['step1' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['step2' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        $event = new AnalyticsEvent(name: 'test_event');
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull()
            ->and($result->params['step1'])->toBeTrue()
            ->and($result->params['step2'])->toBeTrue();
    });

    it('returns null when a pipe returns null (event dropped)', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return null; // Drop the event
        });

        $event = new AnalyticsEvent(name: 'test_event');
        $result = $pipeline->process($event);

        expect($result)->toBeNull();
    });

    it('stops processing when a pipe returns null', function () {
        $called = false;

        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return null;
        });
        $pipeline->pipe(function (AnalyticsEvent $event) use (&$called): AnalyticsEvent {
            $called = true;

            return $event;
        });

        $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($called)->toBeFalse();
    });

    it('reports correct pipe count', function () {
        $pipeline = new EventPipeline;

        expect($pipeline->pipeCount())->toBe(0);

        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        expect($pipeline->pipeCount())->toBe(1);

        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        expect($pipeline->pipeCount())->toBe(2);
    });

    it('flush removes all pipes', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);

        expect($pipeline->pipeCount())->toBe(3);

        $result = $pipeline->flush();

        expect($result)->toBe($pipeline)
            ->and($pipeline->pipeCount())->toBe(0);
    });

    it('pipe returns self for chaining', function () {
        $pipeline = new EventPipeline;
        $result = $pipeline->pipe(fn (AnalyticsEvent $e) => $e);

        expect($result)->toBe($pipeline);
    });
});

// ── ConsentFilter Tests ─────────────────────────────────────────────

describe('ConsentFilter', function () {
    it('passes event through when consent is granted', function () {
        $filter = new ConsentFilter(true);
        $event = new AnalyticsEvent(name: 'test_event');

        $result = $filter($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('test_event');
    });

    it('drops event when consent is denied', function () {
        $filter = new ConsentFilter(false);
        $event = new AnalyticsEvent(name: 'test_event');

        $result = $filter($event);

        expect($result)->toBeNull();
    });

    it('defaults to granted', function () {
        $filter = new ConsentFilter;
        $event = new AnalyticsEvent(name: 'test_event');

        expect($filter($event))->not->toBeNull();
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(ConsentFilter::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── UtmEnricher Tests ───────────────────────────────────────────────

describe('UtmEnricher', function () {
    it('enriches event with UTM parameters', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
        ]);

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $enricher($event);

        expect($result)->not->toBeNull()
            ->and($result->params['utm_source'])->toBe('google')
            ->and($result->params['utm_medium'])->toBe('cpc')
            ->and($result->params['utm_campaign'])->toBe('spring_sale');
    });

    it('passes event through unchanged when no UTM params', function () {
        $enricher = new UtmEnricher([]);
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);

        $result = $enricher($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->toBe(['key' => 'value']);
    });

    it('filters out empty UTM values', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'google',
            'utm_medium' => '',
        ]);

        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params)->toHaveKey('utm_source')
            ->and($result->params)->not->toHaveKey('utm_medium');
    });

    it('detects uppercase UTM keys', function () {
        $enricher = new UtmEnricher([
            'UTM_SOURCE' => 'facebook',
            'UTM_MEDIUM' => 'social',
        ]);

        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params['utm_source'])->toBe('facebook')
            ->and($result->params['utm_medium'])->toBe('social');
    });

    it('preserves existing event params', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'twitter',
        ]);

        $event = new AnalyticsEvent(name: 'test', params: ['existing' => 'data']);
        $result = $enricher($event);

        expect($result->params['existing'])->toBe('data')
            ->and($result->params['utm_source'])->toBe('twitter');
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(UtmEnricher::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── UserContextEnricher Tests ───────────────────────────────────────

describe('UserContextEnricher', function () {
    it('enriches event with user context', function () {
        $enricher = new UserContextEnricher([
            'user_id' => '123',
            'user_email' => 'user@example.com',
            'user_name' => 'John Doe',
            'user_plan' => 'pro',
        ]);

        $event = new AnalyticsEvent(name: 'test_event');
        $result = $enricher($event);

        expect($result)->not->toBeNull()
            ->and($result->params['user_id'])->toBe('123')
            ->and($result->params['user_email'])->toBe('user@example.com')
            ->and($result->params['user_name'])->toBe('John Doe')
            ->and($result->params['user_plan'])->toBe('pro');
    });

    it('sets userId from context when event has no userId', function () {
        $enricher = new UserContextEnricher([
            'user_id' => '99',
        ]);

        $event = new AnalyticsEvent(name: 'test');
        $result = $enricher($event);

        expect($result->userId)->toBe('99');
    });

    it('preserves existing userId over context', function () {
        $enricher = new UserContextEnricher([
            'user_id' => '99',
        ]);

        $event = new AnalyticsEvent(name: 'test', userId: 'original-id');
        $result = $enricher($event);

        expect($result->userId)->toBe('original-id');
    });

    it('passes event through unchanged when no context', function () {
        $enricher = new UserContextEnricher([]);
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);

        $result = $enricher($event);

        expect($result->params)->toBe(['key' => 'value']);
    });

    it('coerces integer user_id to string', function () {
        $enricher = new UserContextEnricher([
            'user_id' => 42,
        ]);

        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params['user_id'])->toBe('42');
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(UserContextEnricher::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── TimestampEnricher Tests ─────────────────────────────────────────

describe('TimestampEnricher', function () {
    it('enriches event with timestamp fields', function () {
        $enricher = new TimestampEnricher;
        $event = new AnalyticsEvent(name: 'test_event');

        $result = $enricher($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->toHaveKey('event_timestamp')
            ->and($result->params)->toHaveKey('event_epoch');
    });

    it('provides ISO 8601 timestamp', function () {
        $enricher = new TimestampEnricher;
        $result = $enricher(new AnalyticsEvent(name: 'test'));

        $timestamp = $result->params['event_timestamp'];

        expect($timestamp)->toBeString()
            ->and($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });

    it('provides unix epoch', function () {
        $before = time();
        $enricher = new TimestampEnricher;
        $after = time();

        $result = $enricher(new AnalyticsEvent(name: 'test'));
        $epoch = $result->params['event_epoch'];

        expect($epoch)->toBeGreaterThanOrEqual($before)
            ->and($epoch)->toBeLessThanOrEqual($after);
    });

    it('attaches session_id when provided', function () {
        $enricher = new TimestampEnricher('session-abc-123');
        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params['session_id'])->toBe('session-abc-123');
    });

    it('omits session_id when null', function () {
        $enricher = new TimestampEnricher(null);
        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params)->not->toHaveKey('session_id');
    });

    it('omits session_id when empty string', function () {
        $enricher = new TimestampEnricher('');
        $result = $enricher(new AnalyticsEvent(name: 'test'));

        expect($result->params)->not->toHaveKey('session_id');
    });

    it('preserves existing event params', function () {
        $enricher = new TimestampEnricher;
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);
        $result = $enricher($event);

        expect($result->params['key'])->toBe('value');
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(TimestampEnricher::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── EventPipeline withDefaults Tests ────────────────────────────────

describe('EventPipeline::withDefaults', function () {
    it('creates pipeline with default pipes', function () {
        $pipeline = EventPipeline::withDefaults();

        expect($pipeline->pipeCount())->toBe(4);
    });

    it('enriches event with UTM from context', function () {
        $context = ['utm_source' => 'google', 'utm_medium' => 'organic'];
        $pipeline = EventPipeline::withDefaults($context);

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result)->not->toBeNull()
            ->and($result->params['utm_source'])->toBe('google');
    });

    it('enriches event with timestamp from defaults', function () {
        $pipeline = EventPipeline::withDefaults();

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result->params)->toHaveKey('event_timestamp');
    });

    it('enriches event with user context from defaults', function () {
        $context = ['user_id' => '42', 'user_plan' => 'pro'];
        $pipeline = EventPipeline::withDefaults($context);

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result->params['user_id'])->toBe('42');
    });

    it('drops event when consent is denied', function () {
        $pipeline = EventPipeline::withDefaults([], false);

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result)->toBeNull();
    });

    it('includes session_id in enriched event', function () {
        $pipeline = EventPipeline::withDefaults([], true, 'sess-xyz');

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result->params['session_id'])->toBe('sess-xyz');
    });
});

// ── Pipeline Integration Test ───────────────────────────────────────

describe('Pipeline Integration', function () {
    it('full pipeline with consent, UTM, user, timestamp', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(new ConsentFilter(true));
        $pipeline->pipe(new UtmEnricher(['utm_source' => 'email', 'utm_campaign' => 'welcome']));
        $pipeline->pipe(new UserContextEnricher(['user_id' => '55', 'user_plan' => 'business']));
        $pipeline->pipe(new TimestampEnricher('session-001'));

        $event = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'google']);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('sign_up')
            ->and($result->params['method'])->toBe('google')
            ->and($result->params['utm_source'])->toBe('email')
            ->and($result->params['utm_campaign'])->toBe('welcome')
            ->and($result->params['user_id'])->toBe('55')
            ->and($result->params['user_plan'])->toBe('business')
            ->and($result->params['session_id'])->toBe('session-001')
            ->and($result->params)->toHaveKey('event_timestamp')
            ->and($result->params)->toHaveKey('event_epoch');
    });

    it('consent filter drops event before other pipes execute', function () {
        $executed = false;

        $pipeline = new EventPipeline;
        $pipeline->pipe(new ConsentFilter(false));
        $pipeline->pipe(function (AnalyticsEvent $event) use (&$executed): AnalyticsEvent {
            $executed = true;

            return $event;
        });

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result)->toBeNull()
            ->and($executed)->toBeFalse();
    });

    it('custom callable pipe works alongside class pipes', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(new UtmEnricher(['utm_source' => 'direct']));
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['custom_field' => 'custom_value']),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        $result = $pipeline->process(new AnalyticsEvent(name: 'test'));

        expect($result->params['utm_source'])->toBe('direct')
            ->and($result->params['custom_field'])->toBe('custom_value');
    });
});
