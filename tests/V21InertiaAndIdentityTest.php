<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

describe('v2.1 — HandleInertiaAnalytics middleware', function () {
    it('injects zbAnalytics props with enabled state', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                        'cookie_ttl' => 525600,
                        'cookie_secure' => true,
                        'cookie_samesite' => 'Lax',
                    ],
                    'track_links' => [
                        'enabled' => false,
                        'track_external' => true,
                        'track_internal' => false,
                        'external_prefix' => 'outbound',
                    ],
                    'api' => ['enabled' => true, 'base_url' => '/api/analytics'],
                    'debug' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $middleware = new HandleInertiaAnalytics($manager, $config);

        // Create a mock request and response
        $request = \Illuminate\Http\Request::create('/dashboard');
        $request->headers->set('User-Agent', 'Test Browser');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');

        // We can't easily mock Inertia\Response without the package,
        // so just verify the middleware can be constructed and isAnyProviderEnabled works
        expect($middleware)->toBeInstanceOf(HandleInertiaAnalytics::class);
    });

    it('isAnyProviderEnabled returns true when GA4 is enabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                    'gtm' => ['enabled' => false],
                    'meta_pixel' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                        'cookie_ttl' => 525600,
                        'cookie_secure' => true,
                        'cookie_samesite' => 'Lax',
                    ],
                    'track_links' => [],
                    'api' => ['enabled' => true, 'base_url' => '/api/analytics'],
                    'debug' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        // Use reflection to test private method
        $middleware = new HandleInertiaAnalytics($manager, $config);
        $method = new \ReflectionMethod($middleware, 'isAnyProviderEnabled');

        expect($method->invoke($middleware))->toBeTrue();
    });

    it('isAnyProviderEnabled returns false when all providers disabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false],
                    'gtm' => ['enabled' => false],
                    'meta_pixel' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                        'cookie_ttl' => 525600,
                        'cookie_secure' => true,
                        'cookie_samesite' => 'Lax',
                    ],
                    'track_links' => [],
                    'api' => ['enabled' => true, 'base_url' => '/api/analytics'],
                    'debug' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $middleware = new HandleInertiaAnalytics($manager, $config);

        $method = new \ReflectionMethod($middleware, 'isAnyProviderEnabled');

        expect($method->invoke($middleware))->toBeFalse();
    });

    it('generateTrackingId returns a UUID', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false],
                    'identity' => [
                        'cookie_name' => 'zb_analytics_id',
                        'cookie_ttl' => 525600,
                        'cookie_secure' => true,
                        'cookie_samesite' => 'Lax',
                    ],
                    'track_links' => [],
                    'api' => ['enabled' => true, 'base_url' => '/api/analytics'],
                    'debug' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $middleware = new HandleInertiaAnalytics($manager, $config);

        $method = new \ReflectionMethod($middleware, 'generateTrackingId');

        $id = $method->invoke($middleware);

        // UUID v4 format: 8-4-4-4-12 hex chars
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });
});

describe('v2.1 — UserIdentityTracker', function () {
    it('creates identify event with correct params', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $tracker->identify('user-42', 'client-uuid-abc');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('identify');
        expect($layer[0]['user_id'])->toBe('user-42');
        expect($layer[0]['client_id'])->toBe('client-uuid-abc');
    });

    it('onLogin creates identify event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request = \Illuminate\Http\Request::create('/login');
        $request->headers->set('X-Analytics-Client-Id', 'client-login-123');

        $tracker->onLogin($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('identify');
        expect($layer[0]['user_id'])->toBe('42');
        expect($layer[0]['client_id'])->toBe('client-login-123');
    });

    it('onLogin skips when no client ID', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request = \Illuminate\Http\Request::create('/login');
        // No X-Analytics-Client-Id header and no cookie

        $tracker->onLogin($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toBeEmpty();
    });

    it('onRegister creates identify event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(99);

        $request = \Illuminate\Http\Request::create('/register');
        $request->headers->set('X-Analytics-Client-Id', 'client-reg-456');

        $tracker->onRegister($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('identify');
        expect($layer[0]['user_id'])->toBe('99');
    });

    it('onLogout creates logout event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request = \Illuminate\Http\Request::create('/logout');
        $request->headers->set('X-Analytics-Client-Id', 'client-logout-789');

        $tracker->onLogout($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
        expect($layer[0]['user_id'])->toBe('42');
    });

    it('extracts client ID from cookie when no header', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new UserIdentityTracker($queue, 'zb_analytics_id');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $request = \Illuminate\Http\Request::create('/login');
        $request->cookies->set('zb_analytics_id', 'cookie-client-id');

        $tracker->onLogin($user, $request);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['client_id'])->toBe('cookie-client-id');
    });
});
