<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
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
    $mock->shouldReceive('get')->andReturnUsing(function (string $key, $default = null) use ($config) {
        return data_get($config, $key, $default);
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

            // Can't directly access private, but we can test via registration
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
            // Should register for Login (enabled) and Registered (enabled)
            // Should NOT register for Logout (disabled)
            $dispatcher->shouldReceive('listen')
                ->withArgs(fn ($event, $callback) => $event === Login::class)
                ->once();
            $dispatcher->shouldReceive('listen')
                ->withArgs(fn ($event, $callback) => $event === Registered::class)
                ->once();
            $dispatcher->shouldNotReceive('listen')
                ->withArgs(fn ($event) => $event === Logout::class);

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
                ->with('subscription.created', Mockery::type(Closure::class))
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
                ->withArgs(fn ($event, $callback) => $event === Login::class && is_callable($callback))
                ->once()
                ->andReturnUsing(function ($event, $callback) {
                    // Simulate the event firing
                    $loginEvent = new Login('web', Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class), false);
                    $callback($loginEvent);
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
                ->withArgs(fn ($event, $callback) => $event === Registered::class)
                ->once()
                ->andReturnUsing(function ($event, $callback) {
                    $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
                    $callback(new Registered($user));
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
                        'auth.login' => false, // Disabled
                        'auth.register' => true,
                    ],
                    'models' => [],
                ],
            ]);
            $manager = createMockManager();

            $tracker = new ServerSideTracker($manager, $config);

            $dispatcher = Mockery::mock(EventDispatcher::class);
            $dispatcher->shouldNotReceive('listen')
                ->withArgs(fn ($event) => $event === Login::class);
            $dispatcher->shouldReceive('listen')
                ->withArgs(fn ($event) => $event === Registered::class)
                ->once();

            $tracker->register($dispatcher);
        });
    });
});
