<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EventValidationService;

// ── Debug Mode Tests ────────────────────────────────────────────────

describe('AnalyticsManager Debug Mode', function () {
    it('dispatches events when debug mode is off', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->track('test_event', ['key' => 'value']);

        expect($manager->gtm()->getDataLayer())->toHaveCount(1);
        expect($manager->gtm()->getDataLayer()[0]['event'])->toBe('test_event');
    });

    it('does not dispatch events when debug mode is on', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => ['enabled' => true],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->track('test_event', ['key' => 'value']);

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
        expect($manager->isDebug())->toBeTrue();
    });

    it('allows toggling debug mode at runtime', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        expect($manager->isDebug())->toBeFalse();

        $manager->setDebug(true);
        expect($manager->isDebug())->toBeTrue();

        $manager->track('debug_toggle_test');
        expect($manager->gtm()->getDataLayer())->toBeEmpty();

        $manager->setDebug(false);
        expect($manager->isDebug())->toBeFalse();

        $manager->track('debug_off_test');
        expect($manager->gtm()->getDataLayer())->toHaveCount(1);
    });

    it('reports shouldLogEvents correctly', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => ['enabled' => true, 'log_events' => true],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->isDebug())->toBeTrue();
        expect($manager->shouldLogEvents())->toBeTrue();
    });

    it('defaults debug to false and log_events to false', function () {
        $config = new Repository([]);

        $manager = new AnalyticsManager($config);

        expect($manager->isDebug())->toBeFalse();
        expect($manager->shouldLogEvents())->toBeFalse();
    });

    it('does not dispatch to any provider in debug mode', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => ['enabled' => true],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $manager->trackEvent(new AnalyticsEvent(name: 'all_providers_test'));

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });
});

// ── QueuedAnalyticsDispatcher Tests ──────────────────────────────────

describe('QueuedAnalyticsDispatcher', function () {
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
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $event = new AnalyticsEvent(name: 'sync_test');
        $queue->dispatch($event);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('sync_test');
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
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $events = [
            new AnalyticsEvent(name: 'batch_1'),
            new AnalyticsEvent(name: 'batch_2'),
            new AnalyticsEvent(name: 'batch_3'),
        ];

        $queue->dispatchBatch($events);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(3);
    });

    it('reports enabled state correctly', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => true, 'queue' => 'analytics-high'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        expect($queue->isEnabled())->toBeTrue();
    });

    it('allows toggling enabled state', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => true],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $queue->setEnabled(false);
        expect($queue->isEnabled())->toBeFalse();

        // Should dispatch synchronously now
        $queue->dispatch(new AnalyticsEvent(name: 'after_toggle'));
        expect($manager->gtm()->getDataLayer())->toHaveCount(1);
    });

    it('allows setting queue name and connection', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => true, 'queue' => 'default', 'connection' => 'redis'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $result = $queue->onQueue('custom-queue')->onConnection('database');

        expect($result)->toBe($queue);
        expect($queue->isEnabled())->toBeTrue();
    });

    it('handles empty batch gracefully', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $queue->dispatchBatch([]);

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });

    it('defaults queue config properly', function () {
        $config = new Repository([]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        expect($queue->isEnabled())->toBeTrue(); // defaults to true
    });
});

// ── AnalyticsEventController Validation Tests ───────────────────────

describe('AnalyticsEventController with Validation', function () {
    it('validates events when validator is provided', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                // Event should be sanitized — verify params are clean
                return $event->name === 'test_event'
                    && array_key_exists('valid_key', $event->params);
            });

        $validator = new EventValidationService($config);
        $controller = new AnalyticsEventController($manager, $config, $validator);

        $request = Request::create('/api/analytics/events', 'POST', [
            'name' => 'test_event',
            'params' => [
                'valid_key' => 'value',
                "\x00bad_key" => 'should_be_stripped',
            ],
        ]);

        $response = $controller->track($request);

        expect($response->getStatusCode())->toBe(200);
    });

    it('works without validator (backward compatible)', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                ],
            ],
        ]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')->once();

        // No validator — backward compatible
        $controller = new AnalyticsEventController($manager, $config, null);

        $request = Request::create('/api/analytics/events', 'POST', [
            'name' => 'no_validator_event',
            'params' => ['key' => 'value'],
        ]);

        $response = $controller->track($request);

        expect($response->getStatusCode())->toBe(200);
    });

    it('extracts client ID from header', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                ],
            ],
        ]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->clientId === 'header-client-id-123';
            });

        $controller = new AnalyticsEventController($manager, $config);

        $request = Request::create('/api/analytics/events', 'POST', [
            'name' => 'header_test',
            'params' => [],
        ]);
        $request->headers->set('X-Analytics-Client-Id', 'header-client-id-123');

        $controller->track($request);
    });

    it('extracts client ID from cookie as fallback', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                ],
            ],
        ]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->clientId === 'cookie-client-id-456';
            });

        $controller = new AnalyticsEventController($manager, $config);

        $request = Request::create('/api/analytics/events', 'POST', [
            'name' => 'cookie_test',
            'params' => [],
        ]);
        $request->cookies->set('zb_test_id', 'cookie-client-id-456');

        $controller->track($request);
    });

    it('validates batch events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')->times(3);

        $validator = new EventValidationService($config);
        $controller = new AnalyticsEventController($manager, $config, $validator);

        $request = Request::create('/api/analytics/batch', 'POST', [
            'events' => [
                ['name' => 'batch_event_1', 'params' => ['key' => 'val1']],
                ['name' => 'batch_event_2', 'params' => ['key' => 'val2']],
                ['name' => 'batch_event_3', 'params' => ['key' => 'val3']],
            ],
        ]);

        $response = $controller->batch($request);

        expect($response->getStatusCode())->toBe(200);
        $data = $response->getData(true);
        expect($data['status'])->toBe('ok');
        expect($data['count'])->toBe(3);
    });

    it('returns unauthenticated for identify without user', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $controller = new AnalyticsEventController($manager, $config);

        $request = Request::create('/api/analytics/identify', 'POST', [
            'client_id' => 'test-client-id',
        ]);
        // No user set on request

        $response = $controller->identify($request);

        expect($response->getStatusCode())->toBe(401);
    });

    it('updates consent via API', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => ['cookie_name' => 'zb_test_id'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $controller = new AnalyticsEventController($manager, $config);

        $request = Request::create('/api/analytics/consent', 'POST', [
            'signals' => [
                'analytics_storage' => 'granted',
                'ad_storage' => 'denied',
            ],
        ]);

        $response = $controller->updateConsent($request);

        expect($response->getStatusCode())->toBe(200);
        $data = $response->getData(true);
        expect($data['status'])->toBe('ok');
        expect($data['consent']['analytics_storage'])->toBe('granted');
        expect($data['consent']['ad_storage'])->toBe('denied');
    });
});

// ── UserIdentityTracker Tests ───────────────────────────────────────

describe('UserIdentityTracker', function () {
    it('creates identify event with correct params', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new \ZeroBoiler\Analytics\Tracking\UserIdentityTracker($queue);

        $tracker->identify('user-42', 'client-abc');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('identify');
        expect($layer[0]['eventParams']['user_id'])->toBe('user-42');
        expect($layer[0]['eventParams']['client_id'])->toBe('client-abc');
    });

    it('extracts client ID from request on login', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new \ZeroBoiler\Analytics\Tracking\UserIdentityTracker($queue, 'zb_test_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(99);

        $request = Request::create('/login', 'POST');
        $request->headers->set('X-Analytics-Client-Id', 'login-client-xyz');

        $tracker->onLogin($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('identify');
        expect($layer[0]['eventParams']['user_id'])->toBe('99');
        expect($layer[0]['eventParams']['client_id'])->toBe('login-client-xyz');
    });

    it('handles missing client ID on login gracefully', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new \ZeroBoiler\Analytics\Tracking\UserIdentityTracker($queue);

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(99);

        $request = Request::create('/login', 'POST');
        // No client ID header or cookie

        $tracker->onLogin($user, $request);

        // Should not dispatch anything — no client ID
        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });

    it('tracks logout event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new \ZeroBoiler\Analytics\Tracking\UserIdentityTracker($queue, 'zb_test_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request = Request::create('/logout', 'POST');
        $request->headers->set('X-Analytics-Client-Id', 'logout-client');

        $tracker->onLogout($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
        expect($layer[0]['eventParams']['user_id'])->toBe('42');
    });

    it('uses default cookie name', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new \ZeroBoiler\Analytics\Tracking\UserIdentityTracker($queue);

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $request = Request::create('/login', 'POST');
        $request->cookies->set('zb_analytics_id', 'default-cookie-id');

        $tracker->onLogin($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['eventParams']['client_id'])->toBe('default-cookie-id');
    });
});

// ── Facade Proxy Tests ──────────────────────────────────────────────

describe('Analytics Facade Proxy', function () {
    it('provides all declared methods via manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        // Verify all facade methods exist on the manager
        expect(method_exists($manager, 'track'))->toBeTrue();
        expect(method_exists($manager, 'trackEvent'))->toBeTrue();
        expect(method_exists($manager, 'headScripts'))->toBeTrue();
        expect(method_exists($manager, 'bodyScripts'))->toBeTrue();
        expect(method_exists($manager, 'push'))->toBeTrue();
        expect(method_exists($manager, 'ga4'))->toBeTrue();
        expect(method_exists($manager, 'gtm'))->toBeTrue();
        expect(method_exists($manager, 'meta'))->toBeTrue();
        expect(method_exists($manager, 'plausible'))->toBeTrue();
        expect(method_exists($manager, 'posthog'))->toBeTrue();
        expect(method_exists($manager, 'setConsent'))->toBeTrue();
        expect(method_exists($manager, 'grantConsent'))->toBeTrue();
        expect(method_exists($manager, 'denyConsent'))->toBeTrue();
        expect(method_exists($manager, 'getConsent'))->toBeTrue();
        expect(method_exists($manager, 'isDebug'))->toBeTrue();
        expect(method_exists($manager, 'shouldLogEvents'))->toBeTrue();
        expect(method_exists($manager, 'setDebug'))->toBeTrue();
    });

    it('facade accessor returns correct key', function () {
        $facade = new \ZeroBoiler\Analytics\Facades\Analytics;

        expect($facade->getFacadeAccessor())->toBe('zeroboiler.analytics');
    });
});

// ── Config Validation Tests ──────────────────────────────────────────

describe('Config Defaults', function () {
    it('provides sensible defaults for all config sections', function () {
        $config = new Repository([]);

        $manager = new AnalyticsManager($config);

        // All trackers should be disabled with empty config
        expect($manager->ga4()->isEnabled())->toBeFalse();
        expect($manager->gtm()->isEnabled())->toBeFalse();
        expect($manager->meta()->isEnabled())->toBeFalse();
        expect($manager->plausible()->isEnabled())->toBeFalse();
        expect($manager->posthog()->isEnabled())->toBeFalse();

        // Debug should default off
        expect($manager->isDebug())->toBeFalse();

        // Consent should default to granted
        expect($manager->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });

    it('EventValidationService defaults are correct', function () {
        $config = new Repository([]);

        $validator = new EventValidationService($config);

        expect($validator->isValidEventName('page_view'))->toBeTrue();
        expect($validator->isWhitelisted('anything'))->toBeTrue(); // empty whitelist = allow all
        expect($validator->getCacheSize())->toBe(0);
    });

    it('QueuedAnalyticsDispatcher defaults to enabled', function () {
        $config = new Repository([]);
        $manager = new AnalyticsManager($config);

        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        expect($queue->isEnabled())->toBeTrue();
    });
});
