<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventCollection;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

describe('EventCollection', function () {
    it('creates an empty collection', function () {
        $collection = EventCollection::empty();

        expect($collection)->toBeInstanceOf(EventCollection::class)
            ->and($collection->isEmpty())->toBeTrue()
            ->and($collection->count())->toBe(0);
    });

    it('creates from event data arrays', function () {
        $collection = EventCollection::fromArray([
            ['name' => 'page_view', 'params' => ['page_title' => 'Home']],
            ['name' => 'click', 'params' => ['element' => 'button']],
        ]);

        expect($collection->count())->toBe(2)
            ->and($collection->first()?->name)->toBe('page_view')
            ->and($collection->last()?->name)->toBe('click');
    });

    it('creates from AnalyticsEvent objects', function () {
        $events = [
            new AnalyticsEvent(name: 'page_view', params: ['title' => 'Home']),
            new AnalyticsEvent(name: 'click', params: ['element' => 'btn']),
        ];

        $collection = EventCollection::fromEvents($events);

        expect($collection->count())->toBe(2)
            ->and($collection->get(1)?->name)->toBe('click');
    });

    it('adds events immutably', function () {
        $collection = EventCollection::empty();
        $event = new AnalyticsEvent(name: 'test');

        $newCollection = $collection->add($event);

        expect($collection->isEmpty())->toBeTrue()
            ->and($newCollection->count())->toBe(1);
    });

    it('filters by name', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'click'),
            new AnalyticsEvent(name: 'page_view'),
        ]);

        $filtered = $collection->byName('page_view');

        expect($filtered->count())->toBe(2);
    });

    it('filters by predicate', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view', clientId: 'client-a'),
            new AnalyticsEvent(name: 'click', clientId: 'client-b'),
            new AnalyticsEvent(name: 'page_view', clientId: 'client-c'),
        ]);

        $filtered = $collection->filter(
            fn (AnalyticsEvent $e): bool => $e->clientId === 'client-a',
        );

        expect($filtered->count())->toBe(1)
            ->and($filtered->first()?->clientId)->toBe('client-a');
    });

    it('maps events via transformer', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'click'),
        ]);

        $mapped = $collection->map(
            fn (AnalyticsEvent $e): AnalyticsEvent => new AnalyticsEvent(
                name: $e->name . '_mapped',
            ),
        );

        expect($mapped->count())->toBe(2)
            ->and($mapped->first()?->name)->toBe('page_view_mapped');
    });

    it('groups events by name', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'click'),
            new AnalyticsEvent(name: 'page_view'),
        ]);

        $groups = $collection->groupByName();

        expect($groups)->toHaveKey('page_view')
            ->and($groups)->toHaveKey('click')
            ->and(count($groups['page_view']))->toBe(2);
    });

    it('merges collections', function () {
        $a = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'a'),
        ]);
        $b = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'b'),
        ]);

        $merged = $a->merge($b);

        expect($merged->count())->toBe(2)
            ->and($merged->names())->toBe(['a', 'b']);
    });

    it('takes and skips correctly', function () {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = new AnalyticsEvent(name: "event_{$i}");
        }
        $collection = EventCollection::fromEvents($events);

        $first3 = $collection->take(3);
        $skip2 = $collection->skip(2);

        expect($first3->count())->toBe(3)
            ->and($skip2->count())->toBe(3)
            ->and($skip2->first()?->name)->toBe('event_2');
    });

    it('converts to arrays', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view', params: ['title' => 'Home'], clientId: 'abc'),
        ]);

        $arrays = $collection->toArray();

        expect($arrays)->toHaveCount(1)
            ->and($arrays[0]['name'])->toBe('page_view')
            ->and($arrays[0]['client_id'])->toBe('abc');
    });

    it('implements Countable and IteratorAggregate', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'a'),
            new AnalyticsEvent(name: 'b'),
        ]);

        expect(count($collection))->toBe(2);

        $names = [];
        foreach ($collection as $event) {
            $names[] = $event->name;
        }
        expect($names)->toBe(['a', 'b']);
    });

    it('gets unique names', function () {
        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'click'),
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'search'),
        ]);

        $names = $collection->names();

        expect($names)->toContain('page_view')
            ->and($names)->toContain('click')
            ->and($names)->toContain('search');
    });

    it('reports isEmpty and isNotEmpty correctly', function () {
        $empty = EventCollection::empty();
        $nonEmpty = EventCollection::fromEvents([new AnalyticsEvent(name: 'test')]);

        expect($empty->isEmpty())->toBeTrue()
            ->and($empty->isNotEmpty())->toBeFalse()
            ->and($nonEmpty->isEmpty())->toBeFalse()
            ->and($nonEmpty->isNotEmpty())->toBeTrue();
    });

    it('addMany merges multiple events', function () {
        $collection = EventCollection::empty();
        $events = [
            new AnalyticsEvent(name: 'a'),
            new AnalyticsEvent(name: 'b'),
            new AnalyticsEvent(name: 'c'),
        ];

        $newCollection = $collection->addMany($events);

        expect($newCollection->count())->toBe(3);
    });
});

describe('AnalyticsEventDispatcher', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $this->queue = Mockery::mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
        $this->config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    });

    afterEach(function () {
        Mockery::close();
    });

    it('constructs with default config', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn([]);

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $config = $dispatcher->getConfig();

        expect($config['consent_aware'])->toBeTrue()
            ->and($config['dedup_enabled'])->toBeTrue()
            ->and($config['sampling_rate'])->toBe(1.0)
            ->and($config['debug'])->toBeFalse();
    });

    it('constructs with custom config', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn([
                'consent_aware' => false,
                'dedup_enabled' => false,
                'sampling_rate' => 0.5,
                'debug' => true,
            ]);

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $config = $dispatcher->getConfig();

        expect($config['consent_aware'])->toBeFalse()
            ->and($config['dedup_enabled'])->toBeFalse()
            ->and($config['sampling_rate'])->toBe(0.5)
            ->and($config['debug'])->toBeTrue();
    });

    it('dispatches event directly when queue is disabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn([]);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup.window_seconds', 10)
            ->andReturn(10);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('isEnabled')->andReturn(false);

        // Clear dedup state
        $GLOBALS['__zb_dedup'] = [];

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'page_view';
            });

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'page_view', clientId: 'test-client'),
        );

        expect($result)->toBeTrue();
    });

    it('dispatches event via queue when enabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn([]);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup.window_seconds', 10)
            ->andReturn(10);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('isEnabled')->andReturn(true);
        $this->queue->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'purchase';
            });

        $GLOBALS['__zb_dedup'] = [];

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'purchase', clientId: 'buyer-1'),
        );

        expect($result)->toBeTrue();
    });

    it('bypasses consent check for SaaS lifecycle events', function () {
        // Create consent state with all storage denied
        $consent = new \ZeroBoiler\Analytics\DTO\ConsentState([
            'analytics_storage' => 'denied',
            'ad_storage' => 'denied',
        ]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['consent_aware' => true, 'dedup_enabled' => false]);
        $this->config->shouldNotReceive('get')->with('zeroboiler.analytics.dedup.window_seconds', 10);

        $this->manager->shouldReceive('getConsent')
            ->andReturn($consent);

        $this->queue->shouldReceive('isEnabled')->andReturn(false);

        // SaaS events should pass even with denied consent
        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'sign_up';
            });

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'sign_up', clientId: 'user-1'),
        );

        expect($result)->toBeTrue();
    });

    it('blocks engagement events when consent is denied', function () {
        $consent = new \ZeroBoiler\Analytics\DTO\ConsentState([
            'analytics_storage' => 'denied',
            'ad_storage' => 'denied',
        ]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['consent_aware' => true, 'dedup_enabled' => false]);

        $this->manager->shouldReceive('getConsent')
            ->andReturn($consent);

        $this->manager->shouldNotReceive('trackEvent');

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'page_view', clientId: 'anon'),
        );

        expect($result)->toBeFalse();
    });

    it('dispatches a collection of events', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['dedup_enabled' => false]);
        $this->config->shouldNotReceive('get')->with('zeroboiler.analytics.dedup.window_seconds', 10);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('isEnabled')->andReturn(false);
        $this->manager->shouldReceive('trackEvent')
            ->twice();

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'page_view'),
            new AnalyticsEvent(name: 'click'),
        ]);

        $result = $dispatcher->dispatchCollection($collection);

        expect($result['dispatched'])->toBe(2)
            ->and($result['filtered'])->toBe(0)
            ->and($result['total'])->toBe(2);
    });

    it('dispatches batch via queue', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['dedup_enabled' => false]);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('dispatchBatch')
            ->once()
            ->withArgs(function (array $events): bool {
                return count($events) === 2;
            });

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $collection = EventCollection::fromEvents([
            new AnalyticsEvent(name: 'event_a'),
            new AnalyticsEvent(name: 'event_b'),
        ]);

        $result = $dispatcher->dispatchBatch($collection);

        expect($result['queued'])->toBe(2)
            ->and($result['filtered'])->toBe(0)
            ->and($result['total'])->toBe(2);
    });

    it('respects consent bypass option', function () {
        $consent = new \ZeroBoiler\Analytics\DTO\ConsentState([
            'analytics_storage' => 'denied',
        ]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['dedup_enabled' => false]);

        $this->manager->shouldReceive('getConsent')
            ->andReturn($consent);

        $this->queue->shouldReceive('isEnabled')->andReturn(false);
        $this->manager->shouldReceive('trackEvent')->once();

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        // Even though consent is denied, bypass flag should allow dispatch
        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'page_view'),
            ['consent_bypass' => true],
        );

        expect($result)->toBeTrue();
    });

    it('deduplicates identical events within window', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['dedup_enabled' => true]);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup.window_seconds', 10)
            ->andReturn(10);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('isEnabled')->andReturn(false);

        $GLOBALS['__zb_dedup'] = [];

        // First dispatch: should succeed
        $this->manager->shouldReceive('trackEvent')->once();

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $event = new AnalyticsEvent(
            name: 'click',
            params: ['element' => 'button'],
            clientId: 'same-client',
        );

        $result1 = $dispatcher->dispatch($event);
        $result2 = $dispatcher->dispatch($event); // Same event, should be deduped

        expect($result1)->toBeTrue()
            ->and($result2)->toBeFalse();
    });

    it('dispatches immediate events even when queue is enabled', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatcher', [])
            ->andReturn(['dedup_enabled' => false]);

        $this->manager->shouldReceive('getConsent')
            ->andReturn(new \ZeroBoiler\Analytics\DTO\ConsentState);

        $this->queue->shouldReceive('isEnabled')->andReturn(true);

        // Should go to manager directly (immediate=true), not queue
        $this->manager->shouldReceive('trackEvent')->once();
        $this->queue->shouldNotReceive('dispatch');

        $dispatcher = new AnalyticsEventDispatcher(
            $this->manager,
            $this->queue,
            $this->config,
        );

        $result = $dispatcher->dispatch(
            new AnalyticsEvent(name: 'purchase', clientId: 'buyer'),
            ['immediate' => true],
        );

        expect($result)->toBeTrue();
    });
});

describe('v3.4.0 Version Consistency', function () {
    it('EventCollection exists and is readonly', function () {
        $reflection = new ReflectionClass(\ZeroBoiler\Analytics\DTO\EventCollection::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    });

    it('AnalyticsEventDispatcher exists and is final', function () {
        $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('version is 5.3.0 across PHP and JS', function () {
        $phpVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        expect($phpVersion)->toBe('5.3.0');
    });

    it('EventCollection implements Countable and IteratorAggregate', function () {
        $reflection = new ReflectionClass(\ZeroBoiler\Analytics\DTO\EventCollection::class);

        expect($reflection->implementsInterface(\Countable::class))->toBeTrue()
            ->and($reflection->implementsInterface(\IteratorAggregate::class))->toBeTrue();
    });

    it('config has dispatcher section', function () {
        $config = include __DIR__ . '/../config/zeroboiler.php';

        expect($config)->toHaveKey('analytics')
            ->and($config['analytics'])->toHaveKey('dispatcher')
            ->and($config['analytics']['dispatcher'])->toHaveKey('consent_aware')
            ->and($config['analytics']['dispatcher'])->toHaveKey('dedup_enabled')
            ->and($config['analytics']['dispatcher'])->toHaveKey('sampling_rate')
            ->and($config['analytics']['dispatcher'])->toHaveKey('debug');
    });

    it('composer.json version is 5.3.0', function () {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
        );

        expect($composer['version'])->toBe('5.3.0');
    });

    it('AnalyticsEventDispatcher is registered in ServiceProvider', function () {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($contents)->toContain('AnalyticsEventDispatcher::class')
            ->and($contents)->toContain('use ZeroBoiler\\Analytics\\Bus\\AnalyticsEventDispatcher');
    });

    it('JS client library version is 3.5.0', function () {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect($js)->toContain("'5.3.0'");
    });

    it('Svelte composable version is 5.3.0', function () {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

        expect($svelte)->toContain('@version 5.3.0');
    });

    it('Svelte composable exports usePlausible composable', function () {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

        expect($svelte)->toContain('export function usePlausible()');
    });

    it('Svelte composable exports usePostHog composable', function () {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

        expect($svelte)->toContain('export function usePostHog()');
    });

    it('Svelte composable exports useEngagement composable', function () {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

        expect($svelte)->toContain('export function useEngagement()');
    });
});
