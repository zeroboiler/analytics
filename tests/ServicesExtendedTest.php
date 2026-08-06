<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

describe('QueuedAnalyticsDispatcher', function () {
    it('reads config and sets defaults', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => [
                        'enabled' => true,
                        'queue' => 'analytics',
                        'connection' => 'redis',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        expect($dispatcher->isEnabled())->toBeTrue();
    });

    it('can be disabled via config', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        expect($dispatcher->isEnabled())->toBeFalse();
    });

    it('defaults to enabled when config is missing', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        expect($dispatcher->isEnabled())->toBeTrue();
    });

    it('dispatches synchronously when queue is disabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $dispatcher->dispatch(new AnalyticsEvent(name: 'test_sync'));

        // Event should be in GTM dataLayer since dispatched synchronously
        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('test_sync');
    });

    it('dispatches batch synchronously when queue is disabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $events = [
            new AnalyticsEvent(name: 'batch_1'),
            new AnalyticsEvent(name: 'batch_2'),
            new AnalyticsEvent(name: 'batch_3'),
        ];

        $dispatcher->dispatchBatch($events);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(3);
    });

    it('handles empty batch gracefully', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $dispatcher->dispatchBatch([]);

        // No exception, no events
        expect(true)->toBeTrue();
    });

    it('setEnable toggles the flag', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        expect($dispatcher->isEnabled())->toBeTrue();

        $dispatcher->setEnabled(false);
        expect($dispatcher->isEnabled())->toBeFalse();

        $dispatcher->setEnabled(true);
        expect($dispatcher->isEnabled())->toBeTrue();
    });

    it('setEnable returns self for chaining', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $result = $dispatcher->setEnabled(true);

        expect($result)->toBe($dispatcher);
    });

    it('onConnection sets and returns self', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $result = $dispatcher->onConnection('sqs');

        expect($result)->toBe($dispatcher);
    });

    it('onQueue sets and returns self', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);

        $result = $dispatcher->onQueue('high_priority');

        expect($result)->toBe($dispatcher);
    });
});

describe('UserIdentityTracker', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($dispatcher);

        expect($tracker)->toBeInstanceOf(UserIdentityTracker::class);
    });

    it('accepts custom cookie name', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($dispatcher, 'custom_tracking_id');

        expect($tracker)->toBeInstanceOf(UserIdentityTracker::class);
    });

    describe('identify', function () {
        it('dispatches identify event to manager', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'queue' => ['enabled' => false],
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $dispatcher = new QueuedAnalyticsDispatcher($manager, $config);
            $tracker = new UserIdentityTracker($dispatcher);

            $tracker->identify('user-123', 'client-abc');

            $layer = $manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('identify');
            expect($layer[0]['eventParams']['user_id'])->toBe('user-123');
            expect($layer[0]['eventParams']['client_id'])->toBe('client-abc');
        });
    });
});

describe('AnalyticsEventController', function () {
    it('can be instantiated', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);

        $controller = new \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController($manager, $config);

        expect($controller)->toBeInstanceOf(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
    });
});
