<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware;
use ZeroBoiler\Analytics\Services\EventBroadcastService;

beforeEach(function (): void {
    $this->trackedEvents = [];
    $this->config = new ConfigRepository([
        'zeroboiler' => [
            'analytics' => [
                'auto_pageview' => [
                    'enabled' => true,
                    'exclude_paths' => ['*/telescope*'],
                    'exclude_methods' => ['OPTIONS', 'HEAD'],
                    'track_api' => false,
                    'track_status_codes' => [200, 404],
                    'bot_tracking' => false,
                    'strip_query_params' => true,
                    'max_url_length' => 2048,
                    'sampling_rate' => 1.0,
                ],
                'broadcasting' => [
                    'enabled' => true,
                    'public_channel_enabled' => true,
                    'public_channel' => 'analytics.events',
                    'category_channels' => true,
                    'tenant_channels' => true,
                    'admin_channel_enabled' => false,
                    'admin_channel' => 'analytics.admin',
                    'include_params' => true,
                    'sensitive_params' => ['password', 'token', 'secret'],
                    'categories' => ['ecommerce', 'saas', 'engagement'],
                ],
            ],
        ],
    ]);

    $this->manager = Mockery::mock(AnalyticsManager::class);
    $this->manager->shouldAllowMissingMethod();
    $this->manager->shouldReceive('trackEvent')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function (string $name, array $params): void {
            $this->trackedEvents[] = ['name' => $name, 'params' => $params];
        });
});

afterEach(function (): void {
    Mockery::close();
});

describe('AutoPageViewMiddleware', function (): void {
    test('dispatches page_view event for HTML GET request with 200 status', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/dashboard', 'GET', [], [], [], [
            'HTTP_REFERER' => 'https://example.com/home',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $request->headers->set('Accept', 'text/html');

        $response = new Response('<html><head><title>Dashboard</title></head><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');

        $next = fn (Request $req): Response => $response;

        $result = $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(1);
        expect($this->trackedEvents[0]['name'])->toBe('page_view');
        expect($this->trackedEvents[0]['params'])->toHaveKey('page_url');
        expect($this->trackedEvents[0]['params'])->toHaveKey('page_path');
        expect($this->trackedEvents[0]['params']['page_path'])->toBe('/dashboard');
        expect($this->trackedEvents[0]['params']['referrer'])->toBe('https://example.com/home');
        expect($this->trackedEvents[0]['params']['http_method'])->toBe('GET');
        expect($this->trackedEvents[0]['params']['status_code'])->toBe(200);
        expect($this->trackedEvents[0]['params']['source'])->toBe('server_middleware');
        expect($this->trackedEvents[0]['params']['page_title'])->toBe('Dashboard');
        expect($result)->toBe($response);
    });

    test('skips tracking when auto_pageview is disabled', function (): void {
        $this->config->set('zeroboiler.analytics.auto_pageview.enabled', false);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/home', 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('skips tracking for OPTIONS requests', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/api/data', 'OPTIONS');
        $response = new Response('', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('skips tracking for excluded paths', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/telescope/requests', 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('skips tracking for non-HTML responses when track_api is false', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/api/events', 'GET');
        $response = new Response(json_encode([]), 200);
        $response->headers->set('Content-Type', 'application/json');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('tracks non-HTML responses when track_api is true', function (): void {
        $this->config->set('zeroboiler.analytics.auto_pageview.track_api', true);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/api/events', 'GET');
        $response = new Response(json_encode([]), 200);
        $response->headers->set('Content-Type', 'application/json');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(1);
        expect($this->trackedEvents[0]['name'])->toBe('page_view');
        expect($this->trackedEvents[0]['params']['content_type'])->toContain('application/json');
    });

    test('skips tracking for bot user agents when bot_tracking is false', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/home', 'GET');
        $request->headers->set('User-Agent', 'Googlebot/2.1');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('tracks bot user agents when bot_tracking is true', function (): void {
        $this->config->set('zeroboiler.analytics.auto_pageview.bot_tracking', true);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/home', 'GET');
        $request->headers->set('User-Agent', 'Googlebot/2.1');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(1);
        expect($this->trackedEvents[0]['params']['is_bot'])->toBeTrue();
    });

    test('strips query params when strip_query_params is true', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/search?q=analytics&sort=date', 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents[0]['params']['page_url'])->not->toContain('?');
        expect($this->trackedEvents[0]['params']['page_url'])->toBe('http://localhost/search');
    });

    test('keeps query params when strip_query_params is false', function (): void {
        $this->config->set('zeroboiler.analytics.auto_pageview.strip_query_params', false);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/search?q=analytics', 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents[0]['params']['page_url'])->toContain('?q=analytics');
    });

    test('extracts page title from HTML response', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/about', 'GET');
        $response = new Response('<html><head><title>About Us — My App</title></head><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents[0]['params']['page_title'])->toBe('About Us — My App');
    });

    test('returns null page_title for responses without title tag', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/api', 'GET');
        $response = new Response('<html><body>No title</body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents[0]['params']['page_title'])->toBeNull();
    });

    test('skips tracking for non-configured status codes', function (): void {
        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/internal-error', 'GET');
        $response = new Response('Server Error', 500);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect($this->trackedEvents)->toHaveCount(0);
    });

    test('truncates long URLs to max_url_length', function (): void {
        $this->config->set('zeroboiler.analytics.auto_pageview.max_url_length', 50);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $longPath = '/' . str_repeat('a', 100);
        $request = Request::create($longPath, 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        expect(strlen($this->trackedEvents[0]['params']['page_url']))->toBeLessThanOrEqual(50);
    });

    test('respects sampling_rate and skips some requests', function (): void {
        // Set a very low sampling rate — but this is non-deterministic
        // We just verify the code path exists and doesn't error
        $this->config->set('zeroboiler.analytics.auto_pageview.sampling_rate', 0.0);

        $middleware = new AutoPageViewMiddleware($this->manager, $this->config);

        $request = Request::create('/home', 'GET');
        $response = new Response('<html><body></body></html>', 200);
        $response->headers->set('Content-Type', 'text/html');

        $next = fn (Request $req): Response => $response;

        $middleware->handle($request, $next);

        // With 0% sampling rate, nothing should be tracked
        expect($this->trackedEvents)->toHaveCount(0);
    });
});

describe('EventBroadcastService', function (): void {
    test('isEnabled returns true when config enabled and broadcaster present', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);

        $service = new EventBroadcastService($this->config, $broadcaster);

        expect($service->isEnabled())->toBeTrue();
    });

    test('isEnabled returns false when config disabled', function (): void {
        $this->config->set('zeroboiler.analytics.broadcasting.enabled', false);

        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);

        $service = new EventBroadcastService($this->config, $broadcaster);

        expect($service->isEnabled())->toBeFalse();
    });

    test('isEnabled returns false when broadcaster is null', function (): void {
        $service = new EventBroadcastService($this->config, null);

        expect($service->isEnabled())->toBeFalse();
    });

    test('broadcast dispatches event to public channel', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->withArgs(function (array $channels, string $event, array $payload): bool {
                return $event === 'analytics.event'
                    && isset($payload['name'])
                    && isset($payload['params']);
            });

        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $service->broadcast($event, ['category' => 'ecommerce']);
    });

    test('broadcast skips events not in categories filter', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');

        $service = new EventBroadcastService($this->config, $broadcaster);

        // 'uptime' is not in the categories filter
        $event = new AnalyticsEvent('service_up', ['service' => 'api']);
        $service->broadcast($event, ['category' => 'uptime']);
    });

    test('broadcast redacts sensitive params', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->withArgs(function (array $channels, string $event, array $payload): bool {
                return $payload['params']['password'] === '[REDACTED]'
                    && $payload['params']['token'] === '[REDACTED]'
                    && $payload['params']['name'] === 'John';
            });

        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('sign_up', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'token' => 'abc123',
        ]);
        $service->broadcast($event, ['category' => 'saas']);
    });

    test('broadcast excludes params when include_params is false', function (): void {
        $this->config->set('zeroboiler.analytics.broadcasting.include_params', false);

        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->withArgs(function (array $channels, string $event, array $payload): bool {
                return $payload['params'] === [];
            });

        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('click', ['element' => 'button']);
        $service->broadcast($event, ['category' => 'engagement']);
    });

    test('channelsFor returns correct channels for an event', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $channels = $service->channelsFor($event, ['category' => 'ecommerce']);

        // Should have public + category channel (public_channel_enabled and category_channels are true)
        expect($channels)->toHaveCount(2);
    });

    test('channelsFor returns empty when disabled', function (): void {
        $this->config->set('zeroboiler.analytics.broadcasting.enabled', false);

        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $channels = $service->channelsFor($event, ['category' => 'ecommerce']);

        expect($channels)->toBeEmpty();
    });

    test('channelsFor includes tenant channel when configured', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $service = new EventBroadcastService($this->config, $broadcaster);

        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $channels = $service->channelsFor($event, [
            'category' => 'ecommerce',
            'tenant_id' => 'tenant-123',
        ]);

        // public + category + tenant = 3
        expect($channels)->toHaveCount(3);
    });

    test('broadcastBatch sends multiple events as single message', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->withArgs(function (array $channels, string $event, array $payload): bool {
                return $event === 'analytics.batch'
                    && $payload['count'] === 2
                    && count($payload['events']) === 2;
            });

        $service = new EventBroadcastService($this->config, $broadcaster);

        $events = [
            ['event' => new AnalyticsEvent('purchase', ['value' => 99]), 'metadata' => ['category' => 'ecommerce']],
            ['event' => new AnalyticsEvent('sign_up', ['plan' => 'pro']), 'metadata' => ['category' => 'saas']],
        ];
        $service->broadcastBatch($events);
    });

    test('broadcast silently catches broadcaster exceptions', function (): void {
        $broadcaster = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->andThrow(new \RuntimeException('Connection failed'));

        $service = new EventBroadcastService($this->config, $broadcaster);

        // Should not throw
        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $service->broadcast($event, ['category' => 'ecommerce']);

        // No exception thrown — test passes
        expect(true)->toBeTrue();
    });
});

describe('v92 Version Sweep', function (): void {
    test('AnalyticsEvent VERSION is 92.0.0', function (): void {
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('92.0.0');
    });

    test('AutoPageViewMiddleware class exists and implements HttpMiddlewareContract', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware::class))->toBeTrue();
        expect(new \ReflectionClass(\ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware::class))
            ->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class);
    });

    test('EventBroadcastService class exists and is final', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\EventBroadcastService::class))->toBeTrue();
        $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Services\EventBroadcastService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('config has auto_pageview section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config)->toHaveKey('auto_pageview');
        expect($config['auto_pageview'])->toHaveKey('enabled');
        expect($config['auto_pageview'])->toHaveKey('exclude_paths');
        expect($config['auto_pageview'])->toHaveKey('sampling_rate');
    });

    test('config has broadcasting section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config)->toHaveKey('broadcasting');
        expect($config['broadcasting'])->toHaveKey('enabled');
        expect($config['broadcasting'])->toHaveKey('public_channel');
        expect($config['broadcasting'])->toHaveKey('categories');
    });
});
