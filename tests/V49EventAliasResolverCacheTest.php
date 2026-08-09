<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 *
 * V49 — EventAliasResolver, EventCacheService, version unification,
 * config aliases/event_cache sections, facade proxy methods,
 * AnalyticsConfig accessors, and source file counts.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventAliasResolver;
use ZeroBoiler\Analytics\Services\EventCacheService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.gtm', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.meta_pixel', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.plausible', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.posthog', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.webhook', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.default', 'granted')
        ->andReturn('granted');
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.debug', [])
        ->andReturn(['enabled' => false]);
    $this->manager = new AnalyticsManager($this->config);
});

// ═══════════════════════════════════════════════════════════════════════
// EventAliasResolver — Basic Resolution
// ═══════════════════════════════════════════════════════════════════════

describe('EventAliasResolver — v2.49 core', function (): void {
    it('resolves canonical names to themselves', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('page_view'))->toBe('page_view');
        expect($resolver->resolve('sign_up'))->toBe('sign_up');
        expect($resolver->resolve('purchase'))->toBe('purchase');
        expect($resolver->resolve('add_to_cart'))->toBe('add_to_cart');
    });

    it('resolves common abbreviations', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('signup'))->toBe('sign_up');
        expect($resolver->resolve('register'))->toBe('sign_up');
        expect($resolver->resolve('signin'))->toBe('login');
        expect($resolver->resolve('signout'))->toBe('logout');
        expect($resolver->resolve('pageview'))->toBe('page_view');
        expect($resolver->resolve('scroll'))->toBe('scroll_depth');
        expect($resolver->resolve('button_click'))->toBe('click');
        expect($resolver->resolve('download'))->toBe('file_download');
    });

    it('resolves e-commerce aliases', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('addtocart'))->toBe('add_to_cart');
        expect($resolver->resolve('cart_add'))->toBe('add_to_cart');
        expect($resolver->resolve('view_product'))->toBe('view_item');
        expect($resolver->resolve('checkout'))->toBe('begin_checkout');
        expect($resolver->resolve('order_complete'))->toBe('purchase');
        expect($resolver->resolve('transaction'))->toBe('purchase');
        expect($resolver->resolve('wish_list'))->toBe('add_to_wishlist');
        expect($resolver->resolve('product_select'))->toBe('select_item');
    });

    it('resolves SaaS lifecycle aliases', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('trial_start'))->toBe('start_trial');
        expect($resolver->resolve('trial_started'))->toBe('start_trial');
        expect($resolver->resolve('trial_end'))->toBe('trial_end');
        expect($resolver->resolve('plan_upgraded'))->toBe('plan_upgrade');
        expect($resolver->resolve('downgrade_plan'))->toBe('plan_downgrade');
        expect($resolver->resolve('churn'))->toBe('cancellation');
        expect($resolver->resolve('subscribed'))->toBe('subscribe');
        expect($resolver->resolve('renewal'))->toBe('subscription_renewal');
    });

    it('returns original name for unknown events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('unknown_event_xyz'))->toBe('unknown_event_xyz');
        expect($resolver->resolve('my_custom_tracking'))->toBe('my_custom_tracking');
    });

    it('resolves PostHog convention names ($prefix)', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        // $signup should resolve via alias stripping
        expect($resolver->resolve('$signup'))->toBe('sign_up');
    });

    it('resolves CamelCase to snake_case', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('AddToCart'))->toBe('add_to_cart');
        expect($resolver->resolve('PageView'))->toBe('page_view');
        expect($resolver->resolve('Purchase'))->toBe('purchase');
        expect($resolver->resolve('ScrollDepth'))->toBe('scroll_depth');
    });
});

// ═══════════════════════════════════════════════════════════════════════
// EventAliasResolver — Alias Management
// ═══════════════════════════════════════════════════════════════════════

describe('EventAliasResolver — v2.49 alias management', function (): void {
    it('can check if a name is an alias', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->isAlias('signup'))->toBeTrue();
        expect($resolver->isAlias('pageview'))->toBeTrue();
        expect($resolver->isAlias('sign_up'))->toBeFalse(); // canonical
        expect($resolver->isAlias('page_view'))->toBeFalse(); // canonical
    });

    it('can check if a name is canonical', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->isCanonical('sign_up'))->toBeTrue();
        expect($resolver->isCanonical('signup'))->toBeFalse();
        expect($resolver->isCanonical('unknown_event'))->toBeFalse();
    });

    it('can get all aliases for a canonical name', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        $aliases = $resolver->getAliasesFor('sign_up');
        expect($aliases)->toContain('signup');
        expect($aliases)->toContain('register');
        expect($aliases)->toContain('signup_complete');
        expect($aliases)->toContain('registration');
    });

    it('can add custom aliases at runtime', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        $resolver->addAlias('my_custom_event', 'page_view');

        expect($resolver->resolve('my_custom_event'))->toBe('page_view');
        expect($resolver->isAlias('my_custom_event'))->toBeTrue();
        expect($resolver->getAliasesFor('page_view'))->toContain('my_custom_event');
    });

    it('can remove aliases at runtime', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->isAlias('signup'))->toBeTrue();

        $resolver->removeAlias('signup');

        expect($resolver->isAlias('signup'))->toBeFalse();
    });

    it('can resolve batch of names', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        $result = $resolver->resolveBatch(['signup', 'page_view', 'addtocart', 'unknown']);

        expect($result['signup'])->toBe('sign_up');
        expect($result['page_view'])->toBe('page_view');
        expect($result['addtocart'])->toBe('add_to_cart');
        expect($result['unknown'])->toBe('unknown');
    });

    it('returns a valid summary', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);
        $summary = $resolver->summary();

        expect($summary)->toHaveKeys(['alias_count', 'canonical_count', 'categories', 'version']);
        expect($summary['version'])->toBe('4.6.0');
        expect($summary['alias_count'])->toBeGreaterThan(50);
        expect($summary['canonical_count'])->toBe(EventCatalog::count());
        expect($summary['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    });

    it('loads custom aliases from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([
                'app:install' => 'feature_used',
                'my_event' => 'sign_up',
            ]);

        $resolver = new EventAliasResolver($this->config);

        expect($resolver->resolve('app:install'))->toBe('feature_used');
        expect($resolver->resolve('my_event'))->toBe('sign_up');
    });
});

// ═══════════════════════════════════════════════════════════════════════
// EventCacheService — Basic Functionality
// ═══════════════════════════════════════════════════════════════════════

describe('EventCacheService — v2.49 core', function (): void {
    it('is enabled by default', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        expect($service->isEnabled())->toBeTrue();
    });

    it('caches event lookups in memory', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        // First call — cache miss
        $event1 = $service->getEvent('page_view');
        expect($event1)->not->toBeNull();
        expect($event1['name'])->toBe('page_view');

        // Second call — cache hit (from memory)
        $event2 = $service->getEvent('page_view');
        expect($event2)->not->toBeNull();
        expect($event2['name'])->toBe('page_view');

        $stats = $service->stats();
        expect($stats['hits'])->toBe(1);
        expect($stats['misses'])->toBe(1);
    });

    it('returns null for non-existent events', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        expect($service->getEvent('non_existent_event'))->toBeNull();
        expect($service->hasEvent('non_existent_event'))->toBeFalse();
    });

    it('caches event category lookups', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        expect($service->getCategory('page_view'))->toBe('engagement');
        expect($service->getCategory('purchase'))->toBe('ecommerce');
        expect($service->getCategory('sign_up'))->toBe('saas');
    });

    it('caches total event count', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $count1 = $service->totalEventCount();
        $count2 = $service->totalEventCount();

        expect($count1)->toBe(EventCatalog::count());
        expect($count2)->toBe($count1);

        $stats = $service->stats();
        expect($stats['hits'])->toBe(1);
    });

    it('caches all event names', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $names1 = $service->allEventNames();
        $names2 = $service->allEventNames();

        expect($names1)->toBe($names2);
        expect(count($names1))->toBe(EventCatalog::count());
    });

    it('caches GA4→Meta ecommerce format conversion', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 9.99, 'quantity' => 2],
        ];

        $result1 = $service->getGa4ToMetaConversion($items);
        $result2 = $service->getGa4ToMetaConversion($items);

        expect($result1)->toBe($result2);
        expect($result1['value'])->toBe(19.98);

        $stats = $service->stats();
        expect($stats['hits'])->toBe(1);
    });

    it('can warm up cache', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $loaded = $service->warmUp();

        expect($loaded)->toBeGreaterThanOrEqual(EventCatalog::count() + 2);
        expect($service->stats()['memory_items'])->toBeGreaterThanOrEqual(EventCatalog::count());
    });

    it('flushes all caches', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('forget')->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $service->warmUp();
        expect($service->stats()['memory_items'])->toBeGreaterThan(0);

        $service->flush();
        expect($service->stats()['memory_items'])->toBe(0);
        expect($service->stats()['hits'])->toBe(0);
        expect($service->stats()['misses'])->toBe(0);
    });

    it('flushes only memory cache', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);

        $service->warmUp();
        $service->flushMemory();

        expect($service->stats()['memory_items'])->toBe(0);
    });

    it('returns valid stats', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([
                'enabled' => true,
                'memory_max_items' => 100,
                'memory_ttl' => 60,
                'cache_ttl' => 7200,
            ]);

        $service = new EventCacheService($cache, $this->config);
        $stats = $service->stats();

        expect($stats)->toHaveKeys(['hits', 'misses', 'hit_rate', 'memory_items', 'memory_max', 'l2_enabled', 'l2_ttl', 'version']);
        expect($stats['version'])->toBe('4.6.0');
        expect($stats['memory_max'])->toBe(100);
        expect($stats['l2_ttl'])->toBe(7200);
        expect($stats['l2_enabled'])->toBeTrue();
    });

    it('respects disabled state', function (): void {
        $cache = mock(CacheRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn(['enabled' => false]);

        $service = new EventCacheService($cache, $this->config);

        expect($service->isEnabled())->toBeFalse();
    });

    it('resolves and gets event with alias resolver', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_cache', [])
            ->andReturn([]);

        $service = new EventCacheService($cache, $this->config);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.aliases', [])
            ->andReturn([]);

        $resolver = new EventAliasResolver($this->config);

        $event = $service->resolveAndGet('signup', $resolver);

        expect($event)->not->toBeNull();
        expect($event['name'])->toBe('sign_up');
        expect($event['category'])->toBe('saas');
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Version Consistency
// ═══════════════════════════════════════════════════════════════════════

describe('Version — v2.49 consistency', function (): void {
    it('manager version is 2.50.0', function (): void {
        expect($this->manager->version())->toBe('4.6.0');
    });

    it('composer version is 2.50.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        expect($composer['version'])->toBe('4.6.0');
    });

    it('JS client version is 2.50.0', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect($js)->toContain("'4.6.0'");
    });

    it('TypeScript definitions version is 2.50.0', function (): void {
        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

        expect($dts)->toContain('4.6.0');
    });

    it('EventSourceTagger version is 2.50.0', function (): void {
        $tagger = file_get_contents(__DIR__ . '/../src/Services/EventSourceTagger.php');

        expect($tagger)->toContain("'4.6.0'");
    });

    it('EventForwardingService version is 2.50.0', function (): void {
        $forwarding = file_get_contents(__DIR__ . '/../src/Services/EventForwardingService.php');

        expect($forwarding)->toContain("'4.6.0'");
    });

    it('AnalyticsEventRouter version is 2.50.0', function (): void {
        $router = file_get_contents(__DIR__ . '/../src/Services/AnalyticsEventRouter.php');

        expect($router)->toContain("'4.6.0'");
    });

    it('controller version strings are 2.50.0', function (): void {
        $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');

        expect($controller)->toContain("'4.6.0'");
        expect($controller)->not->toContain("'4.6.0'");
        expect($controller)->not->toContain("'4.6.0'");
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Config & Source File Integrity
// ═══════════════════════════════════════════════════════════════════════

describe('Config & Source Integrity — v2.49', function (): void {
    it('aliases config section exists', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        expect($config)->toContain("'aliases'");
    });

    it('event_cache config section exists', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        expect($config)->toContain("'event_cache'");
        expect($config)->toContain('ANALYTICS_EVENT_CACHE_ENABLED');
        expect($config)->toContain('ANALYTICS_EVENT_CACHE_MEMORY_MAX');
        expect($config)->toContain('ANALYTICS_EVENT_CACHE_MEMORY_TTL');
        expect($config)->toContain('ANALYTICS_EVENT_CACHE_TTL');
    });

    it('EventAliasResolver source file exists', function (): void {
        $path = __DIR__ . '/../src/Services/EventAliasResolver.php';

        expect(file_exists($path))->toBeTrue();

        $contents = file_get_contents($path);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('final class EventAliasResolver');
    });

    it('EventCacheService source file exists', function (): void {
        $path = __DIR__ . '/../src/Services/EventCacheService.php';

        expect(file_exists($path))->toBeTrue();

        $contents = file_get_contents($path);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('final class EventCacheService');
    });

    it('ServiceProvider registers EventAliasResolver', function (): void {
        $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($provider)->toContain('EventAliasResolver');
    });

    it('ServiceProvider registers EventCacheService', function (): void {
        $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($provider)->toContain('EventCacheService');
    });

    it('AnalyticsConfig has alias and event_cache accessors', function (): void {
        $config = file_get_contents(__DIR__ . '/../src/Support/AnalyticsConfig.php');

        expect($config)->toContain('aliases()');
        expect($config)->toContain('eventCacheEnabled');
        expect($config)->toContain('eventCacheMemoryMaxItems');
        expect($config)->toContain('eventCacheMemoryTtl');
        expect($config)->toContain('eventCacheTtl');
        expect($config)->toContain('eventCachePrefix');
    });

    it('source file count is at least 191', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        expect($count)->toBeGreaterThanOrEqual(191);
    });

    it('test file count is at least 93', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        expect($count)->toBeGreaterThanOrEqual(93);
    });

    it('config section count is at least 44', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        preg_match_all("/'\\w+'\\s*=>\\s*\\[/", $config, $matches);
        $sections = count($matches[0]);

        expect($sections)->toBeGreaterThanOrEqual(44);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Architecture Validation
// ═══════════════════════════════════════════════════════════════════════

describe('Architecture — v2.49 new services', function (): void {
    it('EventAliasResolver is final', function (): void {
        $reflection = new ReflectionClass(EventAliasResolver::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('EventCacheService is final', function (): void {
        $reflection = new ReflectionClass(EventCacheService::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('EventAliasResolver has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Services/EventAliasResolver.php');

        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('EventCacheService has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Services/EventCacheService.php');

        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('all EventAliasResolver public methods have return types', function (): void {
        $reflection = new ReflectionClass(EventAliasResolver::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() === EventAliasResolver::class) {
                expect($method->hasReturnType())->toBeTrue(
                    "EventAliasResolver::{$method->getName()}() missing return type",
                );
            }
        }
    });

    it('all EventCacheService public methods have return types', function (): void {
        $reflection = new ReflectionClass(EventCacheService::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() === EventCacheService::class) {
                expect($method->hasReturnType())->toBeTrue(
                    "EventCacheService::{$method->getName()}() missing return type",
                );
            }
        }
    });

    it('Facade proxy methods include resolveEventName and trackWithAlias', function (): void {
        $facade = file_get_contents(__DIR__ . '/../src/Facades/Analytics.php');

        expect($facade)->toContain('resolveEventName');
        expect($facade)->toContain('trackWithAlias');
    });
});
