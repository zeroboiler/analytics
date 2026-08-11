<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ProviderFallbackService;
use ZeroBoiler\Analytics\Support\EventCatalogFactory;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

describe('ProviderFallbackService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.fallback', [])
            ->andReturn([
                'enabled' => true,
                'max_depth' => 3,
                'cache_prefix' => 'zb_fallback_',
                'chains' => [
                    'ga4' => ['gtm', 'meta', 'posthog'],
                    'meta' => ['ga4', 'posthog'],
                ],
            ]);

        $this->service = new ProviderFallbackService($this->cache, $this->config);
    });

    test('isEnabled returns configured value', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    test('isEnabled returns false when disabled in config', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $service = new ProviderFallbackService($this->cache, $this->config);

        expect($service->isEnabled())->toBeFalse();
    });

    test('getFallbackChain returns configured chain', function (): void {
        $chain = $this->service->getFallbackChain('ga4');

        expect($chain)->toBe(['gtm', 'meta', 'posthog']);
    });

    test('getFallbackChain returns empty array for unconfigured provider', function (): void {
        $chain = $this->service->getFallbackChain('plausible');

        expect($chain)->toBe([]);
    });

    test('hasFallbackChain returns true for configured provider', function (): void {
        expect($this->service->hasFallbackChain('ga4'))->toBeTrue();
    });

    test('hasFallbackChain returns false for unconfigured provider', function (): void {
        expect($this->service->hasFallbackChain('plausible'))->toBeFalse();
    });

    test('resolveProvider returns primary when healthy', function (): void {
        $resolved = $this->service->resolveProvider('ga4', [
            'ga4' => 'closed',
            'gtm' => 'closed',
            'meta' => 'closed',
        ]);

        expect($resolved)->toBe('ga4');
    });

    test('resolveProvider returns primary when half_open', function (): void {
        $resolved = $this->service->resolveProvider('ga4', [
            'ga4' => 'half_open',
        ]);

        expect($resolved)->toBe('ga4');
    });

    test('resolveProvider falls back to first healthy provider', function (): void {
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $resolved = $this->service->resolveProvider('ga4', [
            'ga4' => 'open',
            'gtm' => 'closed',
            'meta' => 'closed',
        ]);

        expect($resolved)->toBe('gtm');
    });

    test('resolveProvider skips open fallbacks', function (): void {
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $resolved = $this->service->resolveProvider('ga4', [
            'ga4' => 'open',
            'gtm' => 'open',
            'meta' => 'closed',
        ]);

        expect($resolved)->toBe('meta');
    });

    test('resolveProvider returns primary when all fallbacks are open', function (): void {
        $resolved = $this->service->resolveProvider('ga4', [
            'ga4' => 'open',
            'gtm' => 'open',
            'meta' => 'open',
            'posthog' => 'open',
        ]);

        expect($resolved)->toBe('ga4');
    });

    test('resolveProvider returns primary when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $service = new ProviderFallbackService($this->cache, $this->config);

        $resolved = $service->resolveProvider('ga4', ['ga4' => 'open']);

        expect($resolved)->toBe('ga4');
    });

    test('recordFallback increments counter and persists', function (): void {
        $this->cache->shouldReceive('get')->andReturn(5);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $this->service->recordFallback('ga4', 'gtm');

        expect($this->service->getFallbackCount('ga4', 'gtm'))->toBe(1);
        expect($this->service->getFallbackCount('ga4'))->toBe(1);
    });

    test('getFallbackCount returns 0 for unknown pair', function (): void {
        expect($this->service->getFallbackCount('plausible', 'ga4'))->toBe(0);
    });

    test('getFallbackCount aggregates all fallbacks from a provider', function (): void {
        $this->cache->shouldReceive('put')->twice()->andReturn(true);

        $this->service->recordFallback('ga4', 'gtm');
        $this->service->recordFallback('ga4', 'meta');

        expect($this->service->getFallbackCount('ga4'))->toBe(2);
        expect($this->service->getFallbackCount('ga4', 'gtm'))->toBe(1);
        expect($this->service->getFallbackCount('ga4', 'meta'))->toBe(1);
    });

    test('validate returns valid for correct configuration', function (): void {
        $result = $this->service->validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validate detects circular dependency', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'max_depth' => 3,
                'cache_prefix' => 'zb_fallback_',
                'chains' => [
                    'ga4' => ['ga4'], // self-reference
                ],
            ]);

        $service = new ProviderFallbackService($this->cache, $this->config);
        $result = $service->validate();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Circular dependency: 'ga4' cannot fallback to itself");
    });

    test('validate detects chain exceeding max depth', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'max_depth' => 2,
                'cache_prefix' => 'zb_fallback_',
                'chains' => [
                    'ga4' => ['gtm', 'meta', 'posthog'], // 3 > max 2
                ],
            ]);

        $service = new ProviderFallbackService($this->cache, $this->config);
        $result = $service->validate();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Fallback chain for 'ga4' exceeds max depth (2)");
    });

    test('validate warns about circular chains', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'max_depth' => 3,
                'cache_prefix' => 'zb_fallback_',
                'chains' => [
                    'ga4' => ['meta'],
                    'meta' => ['ga4'],
                ],
            ]);

        $service = new ProviderFallbackService($this->cache, $this->config);
        $result = $service->validate();

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->not->toBeEmpty();
    });

    test('stats returns complete service statistics', function (): void {
        $stats = $this->service->stats();

        expect($stats)->toHaveKeys(['enabled', 'chains', 'fallback_counts', 'max_depth', 'chain_count']);
        expect($stats['enabled'])->toBeTrue();
        expect($stats['max_depth'])->toBe(3);
        expect($stats['chain_count'])->toBe(2);
    });

    test('getAllChains returns all configured chains', function (): void {
        $chains = $this->service->getAllChains();

        expect($chains)->toHaveKey('ga4');
        expect($chains)->toHaveKey('meta');
        expect($chains['ga4'])->toBe(['gtm', 'meta', 'posthog']);
    });

    test('getMaxFallbackDepth returns configured value', function (): void {
        expect($this->service->getMaxFallbackDepth())->toBe(3);
    });

    test('resetCounters clears all in-memory counters', function (): void {
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $this->service->recordFallback('ga4', 'gtm');
        expect($this->service->getFallbackCount('ga4', 'gtm'))->toBe(1);

        $this->service->resetCounters();
        expect($this->service->getFallbackCount('ga4', 'gtm'))->toBe(0);
    });

    test('healthSummary returns per-provider status', function (): void {
        $summary = $this->service->healthSummary([
            'ga4' => 'open',
            'gtm' => 'closed',
            'meta' => 'closed',
            'posthog' => 'closed',
            'plausible' => 'closed',
            'webhook' => 'closed',
        ]);

        expect($summary['providers']['ga4']['state'])->toBe('open');
        expect($summary['providers']['ga4']['has_fallback'])->toBeTrue();
        expect($summary['providers']['ga4']['fallback_chain'])->toBe(['gtm', 'meta', 'posthog']);
        expect($summary['providers']['plausible']['has_fallback'])->toBeFalse();
    });
});

describe('EventCatalogFactory', function (): void {
    test('create returns factory for valid catalog event', function (): void {
        $factory = EventCatalogFactory::create('purchase');

        expect($factory)->not->toBeNull();
        expect($factory->getEventName())->toBe('purchase');
        expect($factory->isInCatalog())->toBeTrue();
    });

    test('create returns null for unknown event', function (): void {
        $factory = EventCatalogFactory::create('nonexistent_event');

        expect($factory)->toBeNull();
    });

    test('create with params stores parameters', function (): void {
        $factory = EventCatalogFactory::create('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
        ]);

        $event = $factory->build();

        expect($event->params['transaction_id'])->toBe('TXN-123');
        expect($event->params['value'])->toBe(99.99);
    });

    test('raw creates factory without catalog validation', function (): void {
        $factory = EventCatalogFactory::raw('custom_event');

        expect($factory)->not->toBeNull();
        expect($factory->getEventName())->toBe('custom_event');
        expect($factory->isInCatalog())->toBeFalse();
    });

    test('withClientId sets client ID', function (): void {
        $factory = EventCatalogFactory::create('page_view');
        $event = $factory->withClientId('client_123')->build();

        expect($event->clientId)->toBe('client_123');
    });

    test('withUserId sets user ID', function (): void {
        $factory = EventCatalogFactory::create('login');
        $event = $factory->withUserId('user_456')->build();

        expect($event->userId)->toBe('user_456');
    });

    test('withIdentity sets both IDs', function (): void {
        $factory = EventCatalogFactory::create('sign_up');
        $event = $factory->withIdentity('client_123', 'user_456')->build();

        expect($event->clientId)->toBe('client_123');
        expect($event->userId)->toBe('user_456');
    });

    test('withPriority sets priority', function (): void {
        $factory = EventCatalogFactory::create('purchase');
        $event = $factory->withPriority('critical')->build();

        expect($event->priority)->toBe('critical');
    });

    test('withTimestamp sets timestamp', function (): void {
        $ts = new \DateTimeImmutable('2026-08-11T12:00:00+00:00');
        $factory = EventCatalogFactory::create('page_view');
        $event = $factory->withTimestamp($ts)->build();

        expect($event->timestamp)->toEqual($ts);
    });

    test('mergeParams merges additional params', function (): void {
        $factory = EventCatalogFactory::create('purchase', ['value' => 50.0]);
        $event = $factory->mergeParams(['currency' => 'EUR'])->build();

        expect($event->params['value'])->toBe(50.0);
        expect($event->params['currency'])->toBe('EUR');
    });

    test('build returns AnalyticsEvent DTO', function (): void {
        $factory = EventCatalogFactory::create('page_view');
        $event = $factory->build();

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('page_view');
    });

    test('getCatalogEntry returns catalog data', function (): void {
        $factory = EventCatalogFactory::create('purchase');
        $entry = $factory->getCatalogEntry();

        expect($entry)->not->toBeNull();
        expect($entry['name'])->toBe('purchase');
        expect($entry['ga4'])->toBe('purchase');
    });

    test('getCategory returns correct category', function (): void {
        $factory = EventCatalogFactory::create('purchase');

        expect($factory->getCategory())->toBe('ecommerce');
    });

    test('getGa4Name returns GA4 event name', function (): void {
        $factory = EventCatalogFactory::create('sign_up');

        expect($factory->getGa4Name())->toBe('sign_up');
    });

    test('getMetaName returns Meta event name', function (): void {
        $factory = EventCatalogFactory::create('purchase');

        expect($factory->getMetaName())->toBe('Purchase');
    });

    test('static event creates AnalyticsEvent directly', function (): void {
        $event = EventCatalogFactory::event('custom_event', ['key' => 'value'], 'c1', 'u1');

        expect($event->name)->toBe('custom_event');
        expect($event->params['key'])->toBe('value');
        expect($event->clientId)->toBe('c1');
        expect($event->userId)->toBe('u1');
    });

    test('static critical creates critical-priority event', function (): void {
        $event = EventCatalogFactory::critical('purchase', ['value' => 99.99]);

        expect($event->name)->toBe('purchase');
        expect($event->priority)->toBe('critical');
    });

    test('ecommerceEventNames returns non-empty list', function (): void {
        $names = EventCatalogFactory::ecommerceEventNames();

        expect($names)->not->toBeEmpty();
        expect($names)->toContain('purchase');
        expect($names)->toContain('add_to_cart');
    });

    test('saasEventNames returns non-empty list', function (): void {
        $names = EventCatalogFactory::saasEventNames();

        expect($names)->not->toBeEmpty();
        expect($names)->toContain('sign_up');
        expect($names)->toContain('login');
    });

    test('engagementEventNames returns non-empty list', function (): void {
        $names = EventCatalogFactory::engagementEventNames();

        expect($names)->not->toBeEmpty();
        expect($names)->toContain('page_view');
        expect($names)->toContain('scroll_depth');
    });

    test('catalogSize returns total event count', function (): void {
        $size = EventCatalogFactory::catalogSize();

        expect($size)->toBeGreaterThan(0);
        expect($size)->toBe(EventCatalogFactory::ecommerceEventNames()
            + EventCatalogFactory::saasEventNames()
            + EventCatalogFactory::engagementEventNames() ?: 0);

        // More accurate count
        expect($size)->toBeGreaterThanOrEqual(90);
    });
});

describe('AnalyticsEvent source field (v9.4.0)', function (): void {
    test('source defaults to null', function (): void {
        $event = new AnalyticsEvent('test');

        expect($event->source)->toBeNull();
    });

    test('source can be set via constructor', function (): void {
        $event = new AnalyticsEvent('test', source: 'api');

        expect($event->source)->toBe('api');
    });

    test('source is included in toArray', function (): void {
        $event = new AnalyticsEvent('test', source: 'webhook');
        $arr = $event->toArray();

        expect($arr['source'])->toBe('webhook');
    });

    test('source is null in toArray when not set', function (): void {
        $event = new AnalyticsEvent('test');
        $arr = $event->toArray();

        expect($arr['source'])->toBeNull();
    });

    test('fromArray parses source', function (): void {
        $event = AnalyticsEvent::fromArray([
            'name' => 'test',
            'source' => 'client',
        ]);

        expect($event->source)->toBe('client');
    });

    test('fromArray defaults source to null when missing', function (): void {
        $event = AnalyticsEvent::fromArray([
            'name' => 'test',
        ]);

        expect($event->source)->toBeNull();
    });

    test('priority is included in toArray', function (): void {
        $event = new AnalyticsEvent('test', priority: 'critical');
        $arr = $event->toArray();

        expect($arr['priority'])->toBe('critical');
    });
});
