<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventDebounceService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\EventDebounceService
 */
final class EventDebounceServiceTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository&MockInterface $cache;

    private ConfigRepository&MockInterface $config;

    private EventDebounceService $service;

    protected function setUp(): void
    {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.debounce', [])
            ->andReturn([
                'enabled' => true,
                'default_ttl' => 5000,
                'cache_prefix' => 'zb_debounce_',
                'rules' => [
                    'scroll_depth' => 10000,
                    'page_view' => 5000,
                ],
            ]);

        $this->service = new EventDebounceService(
            $this->cache,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_first_dispatch_passes_through(): void
    {
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->with('zb_debounce_:scroll_depth:client-1', true, 10)
            ->once();

        $result = $this->service->shouldDispatch('scroll_depth', 'client-1');

        $this->assertTrue($result);
    }

    public function test_subsequent_dispatch_is_debounced(): void
    {
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(true);

        $result = $this->service->shouldDispatch('scroll_depth', 'client-1');

        $this->assertFalse($result);
    }

    public function test_dispatch_passes_when_debounce_disabled(): void
    {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => false,
                'default_ttl' => 5000,
                'cache_prefix' => 'zb_debounce_',
                'rules' => [],
            ]);

        $service = new EventDebounceService($this->cache, $this->config);

        // shouldDispatch should return true without touching cache
        $result = $service->shouldDispatch('any_event', 'client-1');

        $this->assertTrue($result);
    }

    public function test_dedupe_key_creates_unique_cache_key(): void
    {
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:page_view:client-1:/about')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->with('zb_debounce_:page_view:client-1:/about', true, 5)
            ->once();

        $result = $this->service->shouldDispatch('page_view', 'client-1', '/about');

        $this->assertTrue($result);
    }

    public function test_reset_clears_debounce(): void
    {
        $this->cache->shouldReceive('forget')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(true);

        $result = $this->service->reset('scroll_depth', 'client-1');

        $this->assertTrue($result);
    }

    public function test_is_debounced_returns_false_when_not_debounced(): void
    {
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(false);

        $this->assertFalse($this->service->isDebounced('scroll_depth', 'client-1'));
    }

    public function test_is_debounced_returns_true_when_debounced(): void
    {
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(true);

        $this->assertTrue($this->service->isDebounced('scroll_depth', 'client-1'));
    }

    public function test_custom_rule_uses_correct_ttl(): void
    {
        $this->assertSame(10000, $this->service->getTtlMs('scroll_depth'));
        $this->assertSame(10, $this->service->getTtlSeconds('scroll_depth'));
    }

    public function test_default_ttl_for_unknown_event(): void
    {
        $this->assertSame(5000, $this->service->getTtlMs('unknown_event'));
        $this->assertSame(5, $this->service->getTtlSeconds('unknown_event'));
    }

    public function test_ttl_minimum_is_1_second(): void
    {
        // 500ms should be rounded up to 1 second
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'default_ttl' => 500,
                'cache_prefix' => 'zb_debounce_',
                'rules' => [],
            ]);

        $service = new EventDebounceService($this->cache, $this->config);

        $this->assertSame(500, $service->getTtlMs('any_event'));
        $this->assertSame(1, $service->getTtlSeconds('any_event'));
    }

    public function test_stats_returns_configured_values(): void
    {
        $stats = $this->service->stats();

        $this->assertTrue($stats['enabled']);
        $this->assertSame(5000, $stats['default_ttl_ms']);
        $this->assertSame(2, $stats['rules_count']);
        $this->assertArrayHasKey('scroll_depth', $stats['rules']);
        $this->assertArrayHasKey('page_view', $stats['rules']);
    }

    public function test_different_identities_are_independent(): void
    {
        // Client 1: not debounced
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-1')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->once();

        $result1 = $this->service->shouldDispatch('scroll_depth', 'client-1');
        $this->assertTrue($result1);

        // Client 2: also not debounced (different identity)
        $this->cache->shouldReceive('has')
            ->with('zb_debounce_:scroll_depth:client-2')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->once();

        $result2 = $this->service->shouldDispatch('scroll_depth', 'client-2');
        $this->assertTrue($result2);
    }

    public function test_is_enabled(): void
    {
        $this->assertTrue($this->service->isEnabled());

        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => false,
                'default_ttl' => 5000,
                'cache_prefix' => 'zb_debounce_',
                'rules' => [],
            ]);

        $disabledService = new EventDebounceService($this->cache, $this->config);
        $this->assertFalse($disabledService->isEnabled());
    }
}
