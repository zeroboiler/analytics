<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventSessionContext;
use ZeroBoiler\Analytics\Services\EventSessionContextService;
use ZeroBoiler\Analytics\Services\ProviderDispatchDedupService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('has')->andReturn(false);
    $this->cache->shouldReceive('put');

    $this->config = mock(ConfigRepository::class);
});

describe('EventSessionContext', function (): void {
    test('can be constructed with no arguments', function (): void {
        $ctx = new EventSessionContext();

        expect($ctx->sessionId)->toBeNull()
            ->and($ctx->clientId)->toBeNull()
            ->and($ctx->userId)->toBeNull()
            ->and($ctx->ip)->toBeNull()
            ->and($ctx->browser)->toBeNull()
            ->and($ctx->deviceType)->toBeNull()
            ->and($ctx->extra)->toBe([]);
    });

    test('can be constructed with all fields', function (): void {
        $ctx = new EventSessionContext(
            sessionId: 'sess_123',
            clientId: 'client_abc',
            userId: 'user_1',
            fingerprint: 'fp_hash',
            ip: '192.168.1.1',
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            browser: 'Chrome 126',
            os: 'macOS',
            deviceType: 'desktop',
            screenWidth: '1920',
            screenHeight: '1080',
            language: 'en-US',
            timezone: 'America/New_York',
            country: 'US',
            region: 'CA',
            city: 'San Francisco',
            pageUrl: 'https://example.com/page',
            pageTitle: 'Test Page',
            referrer: 'https://google.com',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'spring_sale',
        );

        expect($ctx->sessionId)->toBe('sess_123')
            ->and($ctx->clientId)->toBe('client_abc')
            ->and($ctx->userId)->toBe('user_1')
            ->and($ctx->fingerprint)->toBe('fp_hash')
            ->and($ctx->ip)->toBe('192.168.1.1')
            ->and($ctx->browser)->toBe('Chrome 126')
            ->and($ctx->os)->toBe('macOS')
            ->and($ctx->deviceType)->toBe('desktop')
            ->and($ctx->screenWidth)->toBe('1920')
            ->and($ctx->country)->toBe('US')
            ->and($ctx->city)->toBe('San Francisco')
            ->and($ctx->utmSource)->toBe('google')
            ->and($ctx->utmCampaign)->toBe('spring_sale');
    });

    test('toArray serializes all fields', function (): void {
        $ctx = new EventSessionContext(
            sessionId: 'sess_1',
            clientId: 'c1',
            userId: 'u1',
            ip: '10.0.0.1',
        );

        $arr = $ctx->toArray();

        expect($arr)->toHaveKey('session_id')
            ->and($arr['session_id'])->toBe('sess_1')
            ->and($arr['client_id'])->toBe('c1')
            ->and($arr['user_id'])->toBe('u1')
            ->and($arr['ip'])->toBe('10.0.0.1')
            ->and($arr)->not->toHaveKey('utm_source');
    });

    test('toArray includes UTM when present', function (): void {
        $ctx = new EventSessionContext(utmSource: 'fb', utmMedium: 'social');

        $arr = $ctx->toArray();

        expect($arr)->toHaveKey('utm_source')
            ->and($arr['utm_source'])->toBe('fb')
            ->and($arr['utm_medium'])->toBe('social');
    });

    test('fromArray round-trips correctly', function (): void {
        $original = new EventSessionContext(
            sessionId: 'sess_round',
            clientId: 'c_round',
            userId: 'u_round',
            browser: 'Safari 17',
            os: 'macOS',
            deviceType: 'desktop',
            country: 'US',
            utmSource: 'newsletter',
            utmCampaign: 'q3',
        );

        $arr = $original->toArray();
        $restored = EventSessionContext::fromArray($arr);

        expect($restored->sessionId)->toBe('sess_round')
            ->and($restored->clientId)->toBe('c_round')
            ->and($restored->userId)->toBe('u_round')
            ->and($restored->browser)->toBe('Safari 17')
            ->and($restored->os)->toBe('macOS')
            ->and($restored->deviceType)->toBe('desktop')
            ->and($restored->country)->toBe('US')
            ->and($restored->utmSource)->toBe('newsletter')
            ->and($restored->utmCampaign)->toBe('q3');
    });

    test('with returns a new instance with updated fields', function (): void {
        $original = new EventSessionContext(sessionId: 'sess_1', clientId: 'c1');

        $updated = $original->with(['client_id' => 'c2', 'country' => 'DE']);

        expect($original->clientId)->toBe('c1') // original unchanged
            ->and($updated->clientId)->toBe('c2')
            ->and($updated->sessionId)->toBe('sess_1') // preserved
            ->and($updated->country)->toBe('DE');
    });

    test('hasUtmData returns true when UTM fields present', function (): void {
        $withUtm = new EventSessionContext(utmSource: 'google');
        $withoutUtm = new EventSessionContext();

        expect($withUtm->hasUtmData())->toBeTrue()
            ->and($withoutUtm->hasUtmData())->toBeFalse();
    });

    test('utmArray returns only non-null UTM fields', function (): void {
        $ctx = new EventSessionContext(utmSource: 'google', utmCampaign: 'sale');

        $utm = $ctx->utmArray();

        expect($utm)->toHaveCount(2)
            ->and($utm)->toHaveKey('utm_source')
            ->and($utm)->toHaveKey('utm_campaign')
            ->and($utm)->not->toHaveKey('utm_medium');
    });

    test('fromRequest extracts HTTP request data', function (): void {
        $request = Request::create('/test?page=1', 'GET', [
            'utm_source' => 'twitter',
            'utm_medium' => 'social',
        ]);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Referer', 'https://twitter.com/');
        $request->headers->set('X-Timezone', 'Europe/London');

        $ctx = EventSessionContext::fromRequest($request, 'client_xyz', 'user_42', 'sess_abc');

        expect($ctx->clientId)->toBe('client_xyz')
            ->and($ctx->userId)->toBe('user_42')
            ->and($ctx->sessionId)->toBe('sess_abc')
            ->and($ctx->utmSource)->toBe('twitter')
            ->and($ctx->utmMedium)->toBe('social')
            ->and($ctx->language)->toBe('en-US,en;q=0.9')
            ->and($ctx->referrer)->toBe('https://twitter.com/')
            ->and($ctx->timezone)->toBe('Europe/London')
            ->and($ctx->userAgent)->toContain('iPhone');
    });
});

describe('EventSessionContextService', function (): void {
    test('buildFromRequest creates context from HTTP request', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn(['enabled' => false]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $request = Request::create('/dashboard', 'GET', [
            'utm_source' => 'direct',
        ]);
        $request->headers->set('User-Agent', 'Mozilla/5.0');

        $ctx = $service->buildFromRequest($request, 'c1', 'u1', 's1');

        expect($ctx->clientId)->toBe('c1')
            ->and($ctx->userId)->toBe('u1')
            ->and($ctx->sessionId)->toBe('s1')
            ->and($ctx->utmSource)->toBe('direct');
    });

    test('buildFromRequest enriches device context when enabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([
                'enabled' => true,
                'device_parsing' => true,
                'geolocation' => false,
                'fingerprinting' => false,
            ]);

        $this->cache->shouldReceive('get')->andReturn(null);

        $service = new EventSessionContextService($this->cache, $this->config);

        $request = Request::create('/page', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0');

        $ctx = $service->buildFromRequest($request);

        expect($ctx->browser)->toContain('Chrome')
            ->and($ctx->os)->toBe('macOS')
            ->and($ctx->deviceType)->toBe('desktop');
    });

    test('attachToEvent merges context into event params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'page_view', params: ['page' => '/home']);
        $ctx = new EventSessionContext(sessionId: 's1', clientId: 'c1', country: 'US');

        $enriched = $service->attachToEvent($event, $ctx);

        expect($enriched->name)->toBe('page_view')
            ->and($enriched->params)->toHaveKey('page')
            ->and($enriched->params['page'])->toBe('/home')
            ->and($enriched->params)->toHaveKey('session_context')
            ->and($enriched->params['session_context']['session_id'])->toBe('s1')
            ->and($enriched->params['session_context']['country'])->toBe('US');
    });

    test('attachToEvent filters out null values', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'click', params: []);
        $ctx = new EventSessionContext(clientId: 'c1'); // only clientId set

        $enriched = $service->attachToEvent($event, $ctx);

        $sessionCtx = $enriched->params['session_context'];
        expect($sessionCtx)->toHaveKey('client_id')
            ->and($sessionCtx)->not->toHaveKey('session_id')
            ->and($sessionCtx)->not->toHaveKey('user_id')
            ->and($sessionCtx)->not->toHaveKey('country');
    });

    test('parseUserAgent detects Chrome on macOS', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $result = $service->parseUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0.0.0');

        expect($result['browser'])->toContain('Chrome')
            ->and($result['os'])->toBe('macOS')
            ->and($result['device_type'])->toBe('desktop');
    });

    test('parseUserAgent detects Firefox on Windows', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $result = $service->parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0');

        expect($result['browser'])->toContain('Firefox')
            ->and($result['os'])->toBe('Windows 10')
            ->and($result['device_type'])->toBe('desktop');
    });

    test('parseUserAgent detects mobile iPhone', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $result = $service->parseUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1');

        expect($result['device_type'])->toBe('mobile')
            ->and($result['os'])->toBe('iOS');
    });

    test('parseUserAgent detects bots', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([]);

        $service = new EventSessionContextService($this->cache, $this->config);

        $result = $service->parseUserAgent('Googlebot/2.1 (+http://www.google.com/bot.html)');

        expect($result['device_type'])->toBe('bot');
    });

    test('getStats returns service configuration', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.session_context', [])
            ->andReturn([
                'enabled' => true,
                'device_parsing' => true,
                'geolocation' => true,
                'fingerprinting' => false,
                'device_cache_ttl' => 3600,
                'geo_cache_ttl' => 7200,
            ]);

        $service = new EventSessionContextService($this->cache, $this->config);
        $stats = $service->getStats();

        expect($stats['enabled'])->toBeTrue()
            ->and($stats['features']['device_parsing'])->toBeTrue()
            ->and($stats['features']['geolocation'])->toBeTrue()
            ->and($stats['features']['fingerprinting'])->toBeFalse()
            ->and($stats['device_cache_ttl'])->toBe(3600)
            ->and($stats['geo_cache_ttl'])->toBe(7200);
    });
});

describe('ProviderDispatchDedupService', function (): void {
    test('shouldDispatch returns true when dedup is disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn(['enabled' => false]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view');

        expect($service->shouldDispatch($event, 'ga4'))->toBeTrue();
    });

    test('shouldDispatch returns true for critical priority events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 10,
                'hash_algorithm' => 'xxh128',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'critical_error', priority: 'critical');

        expect($service->shouldDispatch($event, 'ga4'))->toBeTrue();
    });

    test('shouldDispatch returns true for new events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 10,
                'hash_algorithm' => 'xxh128',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('put');

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        expect($service->shouldDispatch($event, 'ga4'))->toBeTrue();
    });

    test('shouldDispatch returns false for duplicate events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 10,
                'hash_algorithm' => 'xxh128',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $this->cache->shouldReceive('has')->andReturn(true);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        expect($service->shouldDispatch($event, 'ga4'))->toBeFalse();
    });

    test('buildHash produces consistent deterministic hashes', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'hash_algorithm' => 'sha256',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);

        $event1 = new AnalyticsEvent(name: 'click', clientId: 'c1', params: ['button' => 'submit']);
        $event2 = new AnalyticsEvent(name: 'click', clientId: 'c1', params: ['button' => 'submit']);

        expect($service->buildHash($event1, 'ga4'))->toBe($service->buildHash($event2, 'ga4'));
    });

    test('buildHash differs across providers', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'hash_algorithm' => 'sha256',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 50]);

        expect($service->buildHash($event, 'ga4'))->not->toBe($service->buildHash($event, 'meta'));
    });

    test('filterParamsForHash excludes volatile fields', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);

        $params = [
            'item_id' => 'prod_1',
            'value' => 29.99,
            'timestamp' => 1723456789,
            'session_id' => 'sess_abc',
            '_zb_internal' => 'should_be_removed',
            'nested' => [
                'timestamp' => 1723456789,
                'name' => 'Widget',
            ],
        ];

        $filtered = $service->filterParamsForHash($params);

        expect($filtered)->toHaveKey('item_id')
            ->and($filtered)->toHaveKey('value')
            ->and($filtered)->not->toHaveKey('timestamp')
            ->and($filtered)->not->toHaveKey('session_id')
            ->and($filtered)->not->toHaveKey('_zb_internal')
            ->and($filtered['nested'])->toHaveKey('name')
            ->and($filtered['nested'])->not->toHaveKey('timestamp');
    });

    test('batchShouldDispatch checks multiple providers', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 10,
                'hash_algorithm' => 'xxh128',
                'cache_prefix' => 'zb_dedup_',
            ]);

        // First call: cache miss for all (new event)
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('put');

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'signup', params: ['method' => 'email']);

        $results = $service->batchShouldDispatch($event, ['ga4', 'meta', 'posthog']);

        expect($results)->toHaveCount(3)
            ->and($results['ga4'])->toBeTrue()
            ->and($results['meta'])->toBeTrue()
            ->and($results['posthog'])->toBeTrue();
    });

    test('shouldDispatchWithWindow uses custom TTL', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'hash_algorithm' => 'xxh128',
                'cache_prefix' => 'zb_dedup_',
            ]);

        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('put')->withArgs(function (string $key, mixed $value, int $ttl): bool {
            return $ttl === 60; // custom window
        });

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'scroll_depth');

        expect($service->shouldDispatchWithWindow($event, 'ga4', 60))->toBeTrue();
    });

    test('markAsDispatched and hasBeenDispatched work together', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'cache_prefix' => 'zb_dedup_',
            ]);

        $this->cache->shouldReceive('put')->with('zb_dedup_custom_hash', true, 30);
        $this->cache->shouldReceive('has')->with('zb_dedup_custom_hash')->andReturn(true);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $service->markAsDispatched('custom_hash', 30);

        expect($service->hasBeenDispatched('custom_hash'))->toBeTrue();
    });

    test('getStats returns dedup configuration', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.dispatch_dedup', [])
            ->andReturn([
                'enabled' => true,
                'window_seconds' => 15,
                'hash_algorithm' => 'sha256',
                'cache_prefix' => 'zb_dedup_custom_',
            ]);

        $service = new ProviderDispatchDedupService($this->cache, $this->config);
        $stats = $service->getStats();

        expect($stats['enabled'])->toBeTrue()
            ->and($stats['window_seconds'])->toBe(15)
            ->and($stats['hash_algorithm'])->toBe('sha256')
            ->and($stats['cache_prefix'])->toBe('zb_dedup_custom_');
    });

    test('version consistency across entry points', function (): void {
        $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        // Read from composer.json
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        expect($dtoVersion)->toBe('63.0.0')
            ->and($composer['version'])->toBe('63.0.0');
    });
});
