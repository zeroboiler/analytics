<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Context\AnalyticsContextBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventFlushingService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Http\Tests\Concerns;

/**
 * Tests for v17.0.0 — AnalyticsContextBus and EventFlushingService.
 *
 * @covers \ZeroBoiler\Analytics\Context\AnalyticsContextBus
 * @covers \ZeroBoiler\Analytics\Services\EventFlushingService
 */
final class V1700ContextBusAndFlushingTest extends TestCase
{
    // ── AnalyticsContextBus Tests ─────────────────────────────────────────

    public function test_context_bus_construction(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $bus = new AnalyticsContextBus($config);

        $this->assertFalse($bus->isInitialized());
        $this->assertSame([], $bus->base());
        $this->assertSame([], $bus->overrides());
        $this->assertSame([], $bus->all());
    }

    public function test_context_bus_initialize_from_request(): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['app.name', null, 'TestApp'],
            ['app.env', null, 'testing'],
            ['app.version', null, '1.0.0'],
            ['zeroboiler.analytics.tenant_context', null, []],
            ['zeroboiler.analytics.feature_flags', null, []],
        ]);

        $request = $this->createStub(Request::class);
        $request->method('fullUrl')->willReturn('https://example.com/test?utm_source=google&utm_medium=cpc');
        $request->method('path')->willReturn('test');
        $request->method('headers->get')->willReturnMap([
            ['referer', null, 'https://referrer.com'],
            ['accept-language', null, 'en-US'],
        ]);
        $request->method('userAgent')->willReturn('Mozilla/5.0 TestAgent');
        $request->method('ip')->willReturn('192.168.1.1');
        $request->method('locale')->willReturn('en');
        $request->method('query')->willReturn(['utm_source' => 'google', 'utm_medium' => 'cpc']);
        $request->method('user')->willReturn(null);

        $session = $this->createMock(Store::class);
        $session->method('getId')->willReturn('session-123');
        $request->method('session')->willReturn($session);

        $bus = new AnalyticsContextBus($config);
        $bus->initialize($request);

        $this->assertTrue($bus->isInitialized());

        $context = $bus->all();

        // App context
        $this->assertArrayHasKey('app', $context);
        $this->assertSame('TestApp', $context['app']['name']);
        $this->assertSame('testing', $context['app']['env']);

        // Device context
        $this->assertArrayHasKey('device', $context);
        $this->assertSame('Mozilla/5.0 TestAgent', $context['device']['userAgent']);
        $this->assertSame('192.168.1.1', $context['device']['ip']);

        // Session context
        $this->assertArrayHasKey('session', $context);
        $this->assertSame('session-123', $context['session']['id']);

        // Page context
        $this->assertArrayHasKey('page', $context);
        $this->assertSame('https://example.com/test?utm_source=google&utm_medium=cpc', $context['page']['url']);

        // UTM context
        $this->assertArrayHasKey('utm', $context);
        $this->assertSame('google', $context['utm']['source']);
        $this->assertSame('cpc', $context['utm']['medium']);

        // Locale
        $this->assertSame('en', $context['locale']);
    }

    public function test_context_bus_does_not_reinitialize(): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['app.name', null, 'App'],
            ['app.env', null, 'production'],
            ['app.version', null, '2.0.0'],
            ['zeroboiler.analytics.tenant_context', null, []],
            ['zeroboiler.analytics.feature_flags', null, []],
        ]);

        $request = $this->createRequestStub('https://example.com', 'Agent/1.0', '10.0.0.1');

        $bus = new AnalyticsContextBus($config);
        $bus->initialize($request);
        $firstInitAt = $bus->base()['initialized_at'];

        // Re-initialize — should be no-op
        $bus->initialize($request);
        $this->assertSame($firstInitAt, $bus->base()['initialized_at']);
    }

    public function test_context_bus_overrides(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $bus = new AnalyticsContextBus($config);

        $bus->set('custom_key', 'custom_value');
        $bus->merge(['another_key' => 'another_value']);

        $this->assertSame('custom_value', $bus->get('custom_key'));
        $this->assertSame('another_value', $bus->get('another_key'));
        $this->assertSame('default', $bus->get('nonexistent', 'default'));

        $this->assertSame([
            'custom_key' => 'custom_value',
            'another_key' => 'another_value',
        ], $bus->overrides());

        $bus->remove('custom_key');
        $this->assertNull($bus->get('custom_key'));
    }

    public function test_context_bus_as_event_params(): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['app.name', null, 'MyApp'],
            ['app.env', null, 'staging'],
            ['app.version', null, '3.0.0'],
            ['zeroboiler.analytics.tenant_context', null, []],
            ['zeroboiler.analytics.feature_flags', null, []],
        ]);

        $request = $this->createRequestStub('https://app.example.com/dashboard', 'Safari/17.0', '172.16.0.1');

        $bus = new AnalyticsContextBus($config);
        $bus->initialize($request);

        $params = $bus->asEventParams();

        // Verify _ctx_ prefix on flattened keys
        $this->assertArrayHasKey('_ctx_app.name', $params);
        $this->assertSame('MyApp', $params['_ctx_app.name']);
        $this->assertArrayHasKey('_ctx_app.env', $params);
        $this->assertArrayHasKey('_ctx_device.userAgent', $params);
        $this->assertSame('Safari/17.0', $params['_ctx_device.userAgent']);
        $this->assertArrayHasKey('_ctx_page.url', $params);
    }

    public function test_context_bus_summary(): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['app.name', null, 'SummaryApp'],
            ['app.env', null, 'production'],
            ['app.version', null, '1.0'],
            ['zeroboiler.analytics.tenant_context', null, []],
            ['zeroboiler.analytics.feature_flags', null, []],
        ]);

        $request = $this->createRequestStub('https://test.com', 'Agent', '1.2.3.4');

        $bus = new AnalyticsContextBus($config);
        $bus->initialize($request);

        $summary = $bus->summary();

        $this->assertTrue($summary['initialized']);
        $this->assertTrue($summary['has_device']);
        $this->assertTrue($summary['has_session']);
        $this->assertSame(0, $summary['override_count']);
        $this->assertSame('SummaryApp', $summary['app_name']);
        $this->assertSame('production', $summary['app_env']);
    }

    public function test_context_bus_reset(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $bus = new AnalyticsContextBus($config);

        $bus->set('a', 'b');
        $bus->set('c', 'd');

        $this->assertCount(2, $bus->overrides());

        $bus->reset();

        $this->assertSame([], $bus->overrides());
    }

    // ── EventFlushingService Tests ──────────────────────────────────────

    public function test_flushing_service_immediate_strategy(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'immediate']],
        ]);

        $service = new EventFlushingService($manager, $config);

        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);
        $manager->expects($this->once())->method('trackEvent')->with($this->callback(
            fn (AnalyticsEvent $e): bool => $e->name === 'test_event',
        ));

        $service->process($event);

        $stats = $service->stats();
        $this->assertSame('immediate', $stats['strategy']);
        $this->assertSame(1, $stats['total_immediate']);
        $this->assertFalse($stats['has_pending']);
    }

    public function test_flushing_service_buffered_strategy(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'buffered', 'max_buffer_size' => 5]],
        ]);

        $service = new EventFlushingService($manager, $config);

        // Process events — should be buffered, not dispatched
        for ($i = 0; $i < 3; $i++) {
            $service->process(new AnalyticsEvent(name: "event_{$i}", params: []));
        }

        $this->assertSame(3, $service->getBufferSize());
        $this->assertTrue($service->hasPendingEvents());
        $this->assertSame(0, $service->stats()['total_flushed']);

        // Manager should NOT have been called yet
        $manager->expects($this->never())->method('trackEvent');

        // Flush
        $flushed = $service->flush();
        $this->assertSame(3, $flushed);
        $this->assertSame(0, $service->getBufferSize());
        $this->assertFalse($service->hasPendingEvents());
        $this->assertSame(3, $service->stats()['total_flushed']);
    }

    public function test_flushing_service_auto_flush_on_max_size(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'buffered', 'max_buffer_size' => 3]],
        ]);

        $service = new EventFlushingService($manager, $config);

        // Manager expects trackEvent called 3 times when max_buffer_size is reached
        $manager->expects($this->exactly(3))->method('trackEvent');

        for ($i = 0; $i < 3; $i++) {
            $service->process(new AnalyticsEvent(name: "event_{$i}", params: []));
        }

        // Buffer should be empty after auto-flush
        $this->assertSame(0, $service->getBufferSize());
    }

    public function test_flushing_service_set_strategy_flushes_pending(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'buffered', 'max_buffer_size' => 10]],
        ]);

        $service = new EventFlushingService($manager, $config);

        $service->process(new AnalyticsEvent(name: 'pending_event', params: []));
        $this->assertSame(1, $service->getBufferSize());

        // Switch strategy — should flush pending
        $manager->expects($this->once())->method('trackEvent');
        $service->setStrategy('immediate');
        $this->assertSame('immediate', $service->getStrategy());
    }

    public function test_flushing_service_set_strategy_ignores_invalid(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'immediate']],
        ]);

        $service = new EventFlushingService($manager, $config);

        $service->setStrategy('invalid_strategy');
        $this->assertSame('immediate', $service->getStrategy());
    }

    public function test_flushing_service_reset(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'immediate']],
        ]);

        $service = new EventFlushingService($manager, $config);

        $event = new AnalyticsEvent(name: 'test', params: []);
        $manager->expects($this->once())->method('trackEvent');
        $service->process($event);

        $service->reset();

        $stats = $service->stats();
        $this->assertSame(0, $stats['total_flushed']);
        $this->assertSame(0, $stats['total_immediate']);
    }

    public function test_flushing_service_flush_empty_returns_zero(): void
    {
        $manager = $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.flushing', null, ['strategy' => 'buffered']],
        ]);

        $service = new EventFlushingService($manager, $config);

        $flushed = $service->flush();
        $this->assertSame(0, $flushed);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createRequestStub(string $url, string $userAgent, string $ip): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('fullUrl')->willReturn($url);
        $request->method('path')->willReturn(parse_url($url, PHP_URL_PATH) ?? '/');
        $request->method('userAgent')->willReturn($userAgent);
        $request->method('ip')->willReturn($ip);
        $request->method('locale')->willReturn('en');
        $request->method('user')->willReturn(null);

        $session = $this->createMock(Store::class);
        $session->method('getId')->willReturn('sess-' . substr(md5((string) mt_rand()), 0, 8));
        $request->method('session')->willReturn($session);

        return $request;
    }
}
