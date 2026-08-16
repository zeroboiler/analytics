<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

describe('Serializable Queue Jobs (v76.0.0)', function () {
    describe('TrackAnalyticsEventJob', function () {
        it('is serializable (required for redis/database queue drivers)', function () {
            $job = new TrackAnalyticsEventJob(
                name: 'purchase',
                params: ['value' => 99.99, 'currency' => 'USD'],
                clientId: 'client-uuid-123',
                userId: 'user-42',
                timestamp: 1700000000,
                priority: 'critical',
            );

            $serialized = serialize($job);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TrackAnalyticsEventJob::class);
            expect($unserialized->name)->toBe('purchase');
            expect($unserialized->params)->toBe(['value' => 99.99, 'currency' => 'USD']);
            expect($unserialized->clientId)->toBe('client-uuid-123');
            expect($unserialized->userId)->toBe('user-42');
            expect($unserialized->timestamp)->toBe(1700000000);
            expect($unserialized->priority)->toBe('critical');
        });

        it('constructs with minimal required fields', function () {
            $job = new TrackAnalyticsEventJob(name: 'page_view');

            expect($job->name)->toBe('page_view');
            expect($job->params)->toBe([]);
            expect($job->clientId)->toBeNull();
            expect($job->userId)->toBeNull();
            expect($job->timestamp)->toBeNull();
            expect($job->priority)->toBeNull();
        });

        it('has sensible retry and timeout configuration', function () {
            $job = new TrackAnalyticsEventJob(name: 'test');

            expect($job->tries)->toBe(3);
            expect($job->backoff)->toBe(5);
            expect($job->timeout)->toBe(30);
        });

        it('is readonly class', function () {
            $reflection = new ReflectionClass(TrackAnalyticsEventJob::class);

            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('implements ShouldQueue', function () {
            $job = new TrackAnalyticsEventJob(name: 'test');

            expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
        });
    });

    describe('TrackAnalyticsEventBatchJob', function () {
        it('is serializable with multiple events', function () {
            $events = [
                ['name' => 'page_view', 'params' => ['page' => '/home'], 'client_id' => 'c1'],
                ['name' => 'click', 'params' => ['element' => 'cta'], 'client_id' => 'c1', 'priority' => 'normal'],
                ['name' => 'purchase', 'params' => ['value' => 49.99], 'user_id' => 'u1', 'timestamp' => 1700000000],
            ];

            $job = new TrackAnalyticsEventBatchJob(events: $events);

            $serialized = serialize($job);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TrackAnalyticsEventBatchJob::class);
            expect($unserialized->events)->toHaveCount(3);
            expect($unserialized->events[0]['name'])->toBe('page_view');
            expect($unserialized->events[2]['timestamp'])->toBe(1700000000);
        });

        it('constructs with empty events array', function () {
            $job = new TrackAnalyticsEventBatchJob(events: []);

            expect($job->events)->toBe([]);
        });

        it('has sensible retry and timeout configuration for batch', function () {
            $job = new TrackAnalyticsEventBatchJob(events: [['name' => 'test']]);

            expect($job->tries)->toBe(3);
            expect($job->backoff)->toBe(5);
            expect($job->timeout)->toBe(120);
        });

        it('is readonly class', function () {
            $reflection = new ReflectionClass(TrackAnalyticsEventBatchJob::class);

            expect($reflection->isReadOnly())->toBeTrue();
        });
    });

    describe('QueuedAnalyticsDispatcher Job-based dispatch', function () {
        it('reads max_batch_size from config', function () {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.queue', [])
                ->andReturn([
                    'enabled' => true,
                    'queue' => 'analytics',
                    'connection' => 'redis',
                    'max_batch_size' => 25,
                ]);

            $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);

            $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

            expect($dispatcher->getMaxBatchSize())->toBe(25);
        });

        it('defaults max_batch_size to 50 when not configured', function () {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.queue', [])
                ->andReturn([
                    'enabled' => true,
                    'queue' => 'analytics',
                ]);

            $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);

            $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

            expect($dispatcher->getMaxBatchSize())->toBe(50);
        });
    });
});
