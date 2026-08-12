<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Store\NullEventStore;
use ZeroBoiler\Analytics\Store\CacheEventStore;
use ZeroBoiler\Analytics\Store\DatabaseEventStore;
use ZeroBoiler\Analytics\Store\EventStoreManager;
use ZeroBoiler\Analytics\Models\AnalyticsEventModel;

describe('Event Store Layer (v30.0.0)', function () {

    describe('AnalyticsEventStoreInterface contract', function () {
        test('all store implementations implement the interface', function () {
            expect(new NullEventStore)->toBeInstanceOf(AnalyticsEventStoreInterface::class);
            expect(new CacheEventStore())->toBeInstanceOf(AnalyticsEventStoreInterface::class);
            expect(new DatabaseEventStore())->toBeInstanceOf(AnalyticsEventStoreInterface::class);
            expect(app(EventStoreManager::class))->toBeInstanceOf(AnalyticsEventStoreInterface::class);
        });
    });

    describe('NullEventStore', function () {
        test('store returns null', function () {
            $store = new NullEventStore;
            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

            expect($store->store($event))->toBeNull();
        });

        test('storeBatch returns empty array', function () {
            $store = new NullEventStore;
            $events = [
                new AnalyticsEvent(name: 'event_a'),
                new AnalyticsEvent(name: 'event_b'),
            ];

            expect($store->storeBatch($events))->toBe([]);
        });

        test('retrieve returns null', function () {
            $store = new NullEventStore;

            expect($store->retrieve('non-existent'))->toBeNull();
        });

        test('query returns empty array', function () {
            $store = new NullEventStore;

            expect($store->query())->toBe([]);
            expect($store->query(['event_name' => 'purchase']))->toBe([]);
        });

        test('count returns zero', function () {
            $store = new NullEventStore;

            expect($store->count())->toBe(0);
            expect($store->count(['event_name' => 'page_view']))->toBe(0);
        });

        test('delete returns zero', function () {
            $store = new NullEventStore;

            expect($store->delete(['event_name' => 'old_event']))->toBe(0);
        });

        test('deleteById returns false', function () {
            $store = new NullEventStore;

            expect($store->deleteById('any-id'))->toBeFalse();
        });

        test('purge returns true', function () {
            $store = new NullEventStore;

            expect($store->purge())->toBeTrue();
        });

        test('aggregateBy returns empty array', function () {
            $store = new NullEventStore;

            expect($store->aggregateBy('event_name'))->toBe([]);
        });

        test('isHealthy returns true', function () {
            $store = new NullEventStore;

            expect($store->isHealthy())->toBeTrue();
        });
    });

    describe('CacheEventStore', function () {
        test('stores and retrieves an event', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 99.99, 'currency' => 'USD'],
                clientId: 'client-abc',
                userId: 'user-123',
                priority: 'critical',
                source: 'api',
            );

            $id = $store->store($event);
            expect($id)->not->toBeNull();
            expect($id)->toBeString();

            $retrieved = $store->retrieve($id);
            expect($retrieved)->not->toBeNull();
            expect($retrieved->name)->toBe('purchase');
            expect($retrieved->params['value'])->toBe(99.99);
            expect($retrieved->clientId)->toBe('client-abc');
            expect($retrieved->userId)->toBe('user-123');
            expect($retrieved->priority)->toBe('critical');
            expect($retrieved->source)->toBe('api');
        });

        test('storeBatch stores multiple events', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $ids = $store->storeBatch([
                new AnalyticsEvent(name: 'event_a'),
                new AnalyticsEvent(name: 'event_b'),
                new AnalyticsEvent(name: 'event_c'),
            ]);

            expect(count($ids))->toBe(3);
        });

        test('query returns stored events', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $store->store(new AnalyticsEvent(name: 'purchase', params: ['value' => 100]));
            $store->store(new AnalyticsEvent(name: 'page_view'));
            $store->store(new AnalyticsEvent(name: 'purchase', params: ['value' => 200]));

            $purchases = $store->query(['event_name' => 'purchase']);
            expect(count($purchases))->toBe(2);

            $all = $store->query();
            expect(count($all))->toBe(3);
        });

        test('count returns correct totals', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $store->store(new AnalyticsEvent(name: 'purchase'));
            $store->store(new AnalyticsEvent(name: 'purchase'));
            $store->store(new AnalyticsEvent(name: 'signup'));

            expect($store->count())->toBe(3);
            expect($store->count(['event_name' => 'purchase']))->toBe(2);
        });

        test('delete removes matching events', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $store->store(new AnalyticsEvent(name: 'old_event'));
            $store->store(new AnalyticsEvent(name: 'keep_event'));

            $deleted = $store->delete(['event_name' => 'old_event']);
            expect($deleted)->toBe(1);
            expect($store->count())->toBe(1);
        });

        test('deleteById removes single event', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $id = $store->store(new AnalyticsEvent(name: 'test'));
            expect($store->deleteById($id))->toBeTrue();
            expect($store->retrieve($id))->toBeNull();
        });

        test('aggregateBy groups events correctly', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $store->store(new AnalyticsEvent(name: 'purchase'));
            $store->store(new AnalyticsEvent(name: 'purchase'));
            $store->store(new AnalyticsEvent(name: 'signup'));
            $store->store(new AnalyticsEvent(name: 'signup'));
            $store->store(new AnalyticsEvent(name: 'signup'));

            $byName = $store->aggregateBy('event_name');
            expect($byName['purchase'])->toBe(2);
            expect($byName['signup'])->toBe(3);
        });

        test('purge removes all events', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            $store->store(new AnalyticsEvent(name: 'a'));
            $store->store(new AnalyticsEvent(name: 'b'));

            expect($store->count())->toBe(2);
            $store->purge();
            expect($store->count())->toBe(0);
        });

        test('isHealthy returns true for array store', function () {
            $store = new CacheEventStore(store: 'array', ttl: 60);

            expect($store->isHealthy())->toBeTrue();
        });
    });

    describe('AnalyticsEventModel', function () {
        test('model has correct fillable attributes', function () {
            $model = new AnalyticsEventModel;

            expect($model->getFillable())->toContain('name', 'params', 'user_id', 'client_id', 'provider', 'source');
        });

        test('model casts params to array', function () {
            $model = new AnalyticsEventModel;

            expect($model->getCasts())->toHaveKey('params');
            expect($model->getCasts()['params'])->toBe('array');
        });

        test('model casts priority to integer', function () {
            $model = new AnalyticsEventModel;

            expect($model->getCasts())->toHaveKey('priority');
            expect($model->getCasts()['priority'])->toBe('integer');
        });

        test('model casts dedup to boolean', function () {
            $model = new AnalyticsEventModel;

            expect($model->getCasts())->toHaveKey('dedup');
            expect($model->getCasts()['dedup'])->toBe('boolean');
        });

        test('toDto converts model to AnalyticsEvent', function () {
            $model = new AnalyticsEventModel;
            $model->name = 'purchase';
            $model->params = ['value' => 99.99];

            $dto = $model->toDto();
            expect($dto)->toBeInstanceOf(AnalyticsEvent::class);
            expect($dto->name)->toBe('purchase');
        });
    });

    describe('Version sweep', function () {
        test('AnalyticsEvent VERSION is 30.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('30.0.0');
        });
    });
});
