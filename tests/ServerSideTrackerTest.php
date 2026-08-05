<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

// ── Helpers ────────────────────────────────────────────────────────────────

function createMockConfig(array $overrides = []): ConfigRepository
{
    $defaults = [
        'zeroboiler.analytics.auto_track' => [
            'enabled' => true,
            'events' => [
                'auth.login' => true,
                'auth.register' => true,
                'auth.logout' => false,
                'subscription.created' => true,
            ],
            'models' => [],
        ],
    ];

    $config = array_merge($defaults, $overrides);

    $mock = Mockery::mock(ConfigRepository::class);
    $mock->shouldReceive('get')
        ->andReturnUsing(function (string $key, $default = null) use ($config) {
            $keys = explode('.', $key);
            $data = $config;

            foreach ($keys as $segment) {
                if (is_array($data) && array_key_exists($segment, $data)) {
                    $data = $data[$segment];
                } else {
                    return $default;
                }
            }

            return $data;
        });

    return $mock;
}

function createMockManager(): AnalyticsManager
{
    return Mockery::mock(AnalyticsManager::class);
}

describe('ServerSideTracker', function () {
    describe('constructor', function () {
        it('reads config and sets enabled flag', function () {
            $config = createMockConfig();
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            expect($tracker)->toBeInstanceOf(ServerSideTracker::class);
        });

        it('can be disabled via config', function () {
            $config = createMockConfig([
                'zeroboiler.analytics.auto_track' => [
                    'enabled' => false,
                    'events' => [],
                    'models' => [],
                ],
            ]);
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldNotReceive('listen');

            $tracker->register($dispatcher);
        });
    });

    describe('registration', function () {
        it('registers listeners for enabled events', function () {
            $config = createMockConfig();
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldReceive('listen')
                ->times(2); // Login + Registered (both enabled)

            $tracker->register($dispatcher);
        });
    });

    describe('custom event listening', function () {
        it('registers listener for custom events', function () {
            $config = createMockConfig();
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldReceive('listen')
                ->once();

            $tracker->listen('subscription.created', $dispatcher);
        });

        it('does not register for unmapped events', function () {
            $config = createMockConfig();
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldNotReceive('listen');

            $tracker->listen('unknown.event', $dispatcher);
        });
    });

    describe('event dispatching', function () {
        it('dispatches LoginEvent on auth login', function () {
            $config = createMockConfig();
            $manager = Mockery::mock(AnalyticsManager::class);
            $manager->shouldReceive('trackEvent')
                ->withArgs(fn (AnalyticsEvent $event) => $event->name === 'login')
                ->once();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldReceive('listen')
                ->andReturnUsing(function (string $event, callable $callback) {
                    if ($event === Login::class) {
                        $loginEvent = new \Illuminate\Auth\Events\Login(
                            'web',
                            Mockery::mock(Authenticatable::class),
                            false,
                        );
                        $callback($loginEvent);
                    }
                });

            $tracker->register($dispatcher);
        });

        it('dispatches SignUpEvent on auth register', function () {
            $config = createMockConfig();
            $manager = Mockery::mock(AnalyticsManager::class);
            $manager->shouldReceive('trackEvent')
                ->withArgs(fn (AnalyticsEvent $event) => $event->name === 'sign_up')
                ->once();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldReceive('listen')
                ->andReturnUsing(function (string $event, callable $callback) {
                    if ($event === Registered::class) {
                        $user = Mockery::mock(Authenticatable::class);
                        $callback(new \Illuminate\Auth\Events\Registered($user));
                    }
                });

            $tracker->register($dispatcher);
        });
    });

    describe('config key mapping', function () {
        it('respects auth.login toggle', function () {
            $config = createMockConfig([
                'zeroboiler.analytics.auto_track' => [
                    'enabled' => true,
                    'events' => [
                        'auth.login' => false,
                        'auth.register' => true,
                    ],
                    'models' => [],
                ],
            ]);
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldReceive('listen')
                ->once(); // Only Registered

            $tracker->register($dispatcher);
        });
    });
});
