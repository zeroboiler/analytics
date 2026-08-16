<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDebugCaptureService;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for EventDebugCaptureService and its API integration.
 *
 * Covers: capture lifecycle, filtering, replay, simulation, batch replay,
 * observer pattern, statistics, clear operation, constructor configuration,
 * edge cases, and version consistency.
 *
 * @since 161.0.0
 */
final class V1610EventDebugCaptureApiTest extends TestCase
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private EventDebugCaptureService $service;

    private array $cacheStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheStore = [];

        $this->cache = $this->createMock(CacheRepository::class);
        $this->cache->method('get')->willReturnCallback(
            fn (string $key) => $this->cacheStore[$key] ?? null,
        );
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('forget')->willReturnCallback(
            function (string $key): bool {
                $existed = array_key_exists($key, $this->cacheStore);
                unset($this->cacheStore[$key]);

                return $existed;
            },
        );

        $this->config = $this->createMock(ConfigRepository::class);
        $this->config->method('get')->willReturnMap([
            ['zeroboiler.analytics.debug_capture', [], [
                'enabled' => true,
                'debug' => false,
                'cache_prefix' => 'zb_test_',
                'capture_ttl' => 3600,
                'max_events' => 100,
            ]],
        ]);

        $this->service = new EventDebugCaptureService($this->cache, $this->config);
    }

    // ─── Constructor & Configuration ────────────────────────────────────

    public function testConstructorReadsConfig(): void
    {
        $service = new EventDebugCaptureService($this->cache, $this->config);

        $this->assertTrue($service->isEnabled());
    }

    public function testConstructorDefaultsWhenConfigEmpty(): void
    {
        $emptyConfig = $this->createMock(ConfigRepository::class);
        $emptyConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([]);

        $service = new EventDebugCaptureService($this->cache, $emptyConfig);

        $this->assertFalse($service->isEnabled());
    }

    public function testConstructorDisabledWhenEnabledFalse(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => false,
        ]);

        $service = new EventDebugCaptureService($this->cache, $disabledConfig);

        $this->assertFalse($service->isEnabled());
    }

    // ─── Capture ───────────────────────────────────────────────────────

    public function testCaptureReturnsNullWhenDisabled(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => false,
        ]);
        $service = new EventDebugCaptureService($this->cache, $disabledConfig);

        $event = new AnalyticsEvent(
            name: 'test_event',
            params: ['key' => 'value'],
        );

        $result = $service->capture($event);

        $this->assertNull($result);
    }

    public function testCaptureStoresEventAndReturnsId(): void
    {
        $event = new AnalyticsEvent(
            name: 'button_click',
            params: ['element' => 'buy_now', 'page' => '/pricing'],
            clientId: 'client_abc123',
            userId: 'user_42',
        );

        $captureId = $this->service->capture($event, ['provider' => 'ga4']);

        $this->assertNotNull($captureId);
        $this->assertStringStartsWith('cap_', $captureId);
        $this->assertStringContainsString('_', $captureId);
    }

    public function testCaptureStoresCorrectEventData(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99, 'currency' => 'USD'],
            clientId: 'cli_xyz',
            userId: 'usr_1',
            timestamp: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            priority: 'high',
            source: 'server',
        );

        $captureId = $this->service->capture($event, ['dispatch_ms' => 12.5]);

        // Retrieve from the cache store directly
        $storedCaptures = [];
        foreach ($this->cacheStore as $key => $value) {
            if (str_starts_with($key, 'zb_test_cap_')) {
                $storedCaptures[] = $value;
            }
        }

        $this->assertCount(1, $storedCaptures);
        $capture = $storedCaptures[0];

        $this->assertSame('purchase', $capture['event_name']);
        $this->assertSame(['value' => 99.99, 'currency' => 'USD'], $capture['params']);
        $this->assertSame('cli_xyz', $capture['client_id']);
        $this->assertSame('usr_1', $capture['user_id']);
        $this->assertSame('high', $capture['priority']);
        $this->assertSame('server', $capture['source']);
        $this->assertSame(['dispatch_ms' => 12.5], $capture['context']);
        $this->assertArrayHasKey('captured_at', $capture);
        $this->assertArrayHasKey('capture_version', $capture);
    }

    public function testCaptureMaintainsIndex(): void
    {
        $event = new AnalyticsEvent(name: 'evt_a', params: []);
        $event2 = new AnalyticsEvent(name: 'evt_b', params: []);

        $id1 = $this->service->capture($event);
        $id2 = $this->service->capture($event2);

        $index = $this->cacheStore['zb_debug_index'] ?? [];

        $this->assertCount(2, $index);
        $this->assertSame($id1, $index[0]);
        $this->assertSame($id2, $index[1]);
    }

    // ─── GetCapture ────────────────────────────────────────────────────

    public function testGetCaptureReturnsNullForNonExistent(): void
    {
        $result = $this->service->getCapture('non_existent_id');

        $this->assertNull($result);
    }

    public function testGetCaptureReturnsCapturedEvent(): void
    {
        $event = new AnalyticsEvent(name: 'form_submit', params: ['form_id' => 'contact']);

        $captureId = $this->service->capture($event);
        $capture = $this->service->getCapture($captureId);

        $this->assertNotNull($capture);
        $this->assertSame('form_submit', $capture['event_name']);
        $this->assertSame(['form_id' => 'contact'], $capture['params']);
        $this->assertSame($captureId, $capture['id']);
    }

    // ─── GetCapturedEvents (Filtering) ─────────────────────────────────

    public function testGetCapturedEventsReturnsEmptyWhenDisabled(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => false,
        ]);
        $service = new EventDebugCaptureService($this->cache, $disabledConfig);

        $result = $service->getCapturedEvents();

        $this->assertSame(['events' => [], 'total' => 0, 'filters' => []], $result);
    }

    public function testGetCapturedEventsWithoutFilters(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'event_a', params: []));
        $this->service->capture(new AnalyticsEvent(name: 'event_b', params: []));

        $result = $this->service->getCapturedEvents();

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['events']);
    }

    public function testGetCapturedEventsFilterByName(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'button_click', params: []));
        $this->service->capture(new AnalyticsEvent(name: 'page_view', params: []));
        $this->service->capture(new AnalyticsEvent(name: 'button_hover', params: []));

        $result = $this->service->getCapturedEvents(['name' => 'button']);

        $this->assertSame(2, $result['total']);
        foreach ($result['events'] as $evt) {
            $this->assertStringContainsString('button', $evt['event_name']);
        }
    }

    public function testGetCapturedEventsFilterByClientId(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'evt', params: [], clientId: 'cli_1'));
        $this->service->capture(new AnalyticsEvent(name: 'evt', params: [], clientId: 'cli_2'));

        $result = $this->service->getCapturedEvents(['client_id' => 'cli_1']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('cli_1', $result['events'][0]['client_id']);
    }

    public function testGetCapturedEventsFilterByUserId(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'evt', params: [], userId: 'usr_a'));
        $this->service->capture(new AnalyticsEvent(name: 'evt', params: [], userId: 'usr_b'));

        $result = $this->service->getCapturedEvents(['user_id' => 'usr_a']);

        $this->assertSame(1, $result['total']);
    }

    public function testGetCapturedEventsFilterBySource(): void
    {
        $this->service->capture((new AnalyticsEvent(name: 'evt', params: []))->withSource('server'));
        $this->service->capture((new AnalyticsEvent(name: 'evt', params: []))->withSource('client'));

        $result = $this->service->getCapturedEvents(['source' => 'server']);

        $this->assertSame(1, $result['total']);
    }

    public function testGetCapturedEventsWithPagination(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->service->capture(new AnalyticsEvent(name: "event_{$i}", params: []));
        }

        $page1 = $this->service->getCapturedEvents(['limit' => 3, 'offset' => 0]);
        $page2 = $this->service->getCapturedEvents(['limit' => 3, 'offset' => 3]);

        $this->assertSame(10, $page1['total']);
        $this->assertCount(3, $page1['events']);
        $this->assertCount(3, $page2['events']);
    }

    public function testGetCapturedEventsWithNullFilterValues(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'test_event', params: []));

        $result = $this->service->getCapturedEvents([
            'name' => null,
            'client_id' => null,
            'user_id' => null,
        ]);

        $this->assertSame(1, $result['total']);
    }

    // ─── Replay ────────────────────────────────────────────────────────

    public function testReplayReturnsNullForNonExistent(): void
    {
        $result = $this->service->replay('non_existent');

        $this->assertNull($result);
    }

    public function testReplayReconstructsEventCorrectly(): void
    {
        $original = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 49.99],
            clientId: 'cli_replay',
            userId: 'usr_replay',
            timestamp: new \DateTimeImmutable('2026-06-01T12:00:00+00:00'),
            priority: 'high',
            source: 'server',
        );

        $captureId = $this->service->capture($original);
        $replayed = $this->service->replay($captureId);

        $this->assertNotNull($replayed);
        $this->assertInstanceOf(AnalyticsEvent::class, $replayed);
        $this->assertSame('purchase', $replayed->name);
        $this->assertSame(['value' => 49.99], $replayed->params);
        $this->assertSame('cli_replay', $replayed->clientId);
        $this->assertSame('usr_replay', $replayed->userId);
        $this->assertSame('replay', $replayed->source);
    }

    public function testReplaySetsSourceToReplay(): void
    {
        $original = new AnalyticsEvent(
            name: 'signup',
            params: [],
            source: 'server',
        );

        $captureId = $this->service->capture($original);
        $replayed = $this->service->replay($captureId);

        $this->assertSame('replay', $replayed->source);
    }

    // ─── Simulate ──────────────────────────────────────────────────────

    public function testSimulateCreatesEvent(): void
    {
        $event = $this->service->simulate(
            name: 'test_signup',
            params: ['method' => 'email'],
            clientId: 'cli_sim',
            userId: 'usr_sim',
        );

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('test_signup', $event->name);
        $this->assertSame(['method' => 'email'], $event->params);
        $this->assertSame('cli_sim', $event->clientId);
        $this->assertSame('usr_sim', $event->userId);
        $this->assertSame('simulation', $event->source);
    }

    public function testSimulateWithEmptyParams(): void
    {
        $event = $this->service->simulate(name: 'minimal_event');

        $this->assertSame('minimal_event', $event->name);
        $this->assertSame([], $event->params);
        $this->assertNull($event->clientId);
        $this->assertNull($event->userId);
    }

    public function testSimulateGeneratesTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $event = $this->service->simulate(name: 'timed_event');
        $after = new \DateTimeImmutable();

        $this->assertNotNull($event->timestamp);
        $this->assertGreaterThanOrEqual($before, $event->timestamp);
        $this->assertLessThanOrEqual($after, $event->timestamp);
    }

    // ─── Batch Replay ───────────────────────────────────────────────────

    public function testBatchReplayWithValidIds(): void
    {
        $e1 = new AnalyticsEvent(name: 'evt_1', params: []);
        $e2 = new AnalyticsEvent(name: 'evt_2', params: []);

        $id1 = $this->service->capture($e1);
        $id2 = $this->service->capture($e2);

        $result = $this->service->replayBatch([$id1, $id2]);

        $this->assertSame(2, $result['replayed']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('replay', $result['events'][0]->source);
    }

    public function testBatchReplayWithInvalidIds(): void
    {
        $result = $this->service->replayBatch(['fake_id_1', 'fake_id_2']);

        $this->assertSame(0, $result['replayed']);
        $this->assertSame(2, $result['failed']);
        $this->assertEmpty($result['events']);
    }

    public function testBatchReplayMixedValidInvalid(): void
    {
        $id = $this->service->capture(new AnalyticsEvent(name: 'valid', params: []));

        $result = $this->service->replayBatch([$id, 'invalid_id']);

        $this->assertSame(1, $result['replayed']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['events']);
    }

    public function testBatchReplayRespectsMaxSize(): void
    {
        $ids = [];
        for ($i = 0; $i < 110; $i++) {
            $ids[] = $this->service->capture(new AnalyticsEvent(name: "batch_{$i}", params: []));
        }

        $result = $this->service->replayBatch($ids);

        $this->assertSame(100, $result['replayed']);
        $this->assertSame(0, $result['failed']);
    }

    // ─── Clear ─────────────────────────────────────────────────────────

    public function testClearRemovesAllCaptures(): void
    {
        $this->service->capture(new AnalyticsEvent(name: 'evt_1', params: []));
        $this->service->capture(new AnalyticsEvent(name: 'evt_2', params: []));
        $this->service->capture(new AnalyticsEvent(name: 'evt_3', params: []));

        $cleared = $this->service->clear();

        $this->assertSame(3, $cleared);
        $this->assertEmpty($this->service->getCapturedEvents()['events']);
    }

    public function testClearOnEmptyStore(): void
    {
        $cleared = $this->service->clear();

        $this->assertSame(0, $cleared);
    }

    // ─── Observer Pattern ───────────────────────────────────────────────

    public function testObserverCalledOnCapture(): void
    {
        $observedEvents = [];

        $this->service->registerObserver(function (AnalyticsEvent $event, array $capture) use (&$observedEvents): void {
            $observedEvents[] = ['name' => $event->name, 'capture_id' => $capture['id']];
        });

        $this->service->capture(new AnalyticsEvent(name: 'observed', params: []));

        $this->assertCount(1, $observedEvents);
        $this->assertSame('observed', $observedEvents[0]['name']);
        $this->assertStringStartsWith('cap_', $observedEvents[0]['capture_id']);
    }

    public function testMultipleObservers(): void
    {
        $count1 = 0;
        $count2 = 0;

        $this->service->registerObserver(function () use (&$count1): void { $count1++; });
        $this->service->registerObserver(function () use (&$count2): void { $count2++; });

        $this->service->capture(new AnalyticsEvent(name: 'multi_obs', params: []));

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    public function testObserverNotCalledWhenDisabled(): void
    {
        $called = false;
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => false,
        ]);
        $service = new EventDebugCaptureService($this->cache, $disabledConfig);

        $service->registerObserver(function () use (&$called): void { $called = true; });
        $service->capture(new AnalyticsEvent(name: 'disabled_obs', params: []));

        $this->assertFalse($called);
    }

    public function testObserverFailureDoesNotBreakCapture(): void
    {
        $this->service->registerObserver(function (): void {
            throw new \RuntimeException('Observer error');
        });

        $event = new AnalyticsEvent(name: 'resilient', params: []);
        $captureId = $this->service->capture($event);

        $this->assertNotNull($captureId);
        $capture = $this->service->getCapture($captureId);
        $this->assertNotNull($capture);
    }

    // ─── Stats ─────────────────────────────────────────────────────────

    public function testStatsReturnsCorrectStructure(): void
    {
        $stats = $this->service->stats();

        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('captured_count', $stats);
        $this->assertArrayHasKey('max_events', $stats);
        $this->assertArrayHasKey('capture_ttl', $stats);
        $this->assertArrayHasKey('observers', $stats);
    }

    public function testStatsReflectsObserverCount(): void
    {
        $stats = $this->service->stats();
        $this->assertSame(0, $stats['observers']);

        $this->service->registerObserver(function (): void {});

        $stats = $this->service->stats();
        $this->assertSame(1, $stats['observers']);
    }

    public function testStatsReflectsEnabledState(): void
    {
        $this->assertTrue($this->service->stats()['enabled']);

        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => false,
        ]);
        $service = new EventDebugCaptureService($this->cache, $disabledConfig);

        $this->assertFalse($service->stats()['enabled']);
    }

    public function testStatsMaxEventsFromConfig(): void
    {
        $customConfig = $this->createMock(ConfigRepository::class);
        $customConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => true,
            'max_events' => 250,
            'capture_ttl' => 7200,
        ]);

        $service = new EventDebugCaptureService($this->cache, $customConfig);
        $stats = $service->stats();

        $this->assertSame(250, $stats['max_events']);
        $this->assertSame(7200, $stats['capture_ttl']);
    }

    // ─── IsEnabled ──────────────────────────────────────────────────────

    public function testIsEnabledReturnsTrueWhenConfigured(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $emptyConfig = $this->createMock(ConfigRepository::class);
        $emptyConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([]);

        $service = new EventDebugCaptureService($this->cache, $emptyConfig);

        $this->assertFalse($service->isEnabled());
    }

    // ─── Capture ID Generation ──────────────────────────────────────────

    public function testCaptureIdsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->service->capture(new AnalyticsEvent(name: "unique_{$i}", params: []));
        }

        $uniqueIds = array_unique($ids);
        $this->assertCount(20, $uniqueIds);
    }

    public function testCaptureIdFormat(): void
    {
        $id = $this->service->capture(new AnalyticsEvent(name: 'format_test', params: []));

        $this->assertMatchesRegularExpression('/^cap_\d{8}_\d{6}_[a-f0-9]{12}$/', $id);
    }

    // ─── Max Events Enforcement ────────────────────────────────────────

    public function testMaxEventsEnforcementRemovesOldest(): void
    {
        $limitedConfig = $this->createMock(ConfigRepository::class);
        $limitedConfig->method('get')->with('zeroboiler.analytics.debug_capture', [])->willReturn([
            'enabled' => true,
            'max_events' => 3,
        ]);

        $service = new EventDebugCaptureService($this->cache, $limitedConfig);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $service->capture(new AnalyticsEvent(name: "overflow_{$i}", params: []));
        }

        // Only 3 should remain in the index
        $index = $this->cacheStore['zb_debug_index'] ?? [];
        $this->assertCount(3, $index);

        // The first 2 should have been evicted
        $first = $service->getCapture($ids[0]);
        $second = $service->getCapture($ids[1]);

        // They may or may not be in the cache (forget was called)
        // But the index should only contain the last 3
        $this->assertContains($ids[2], $index);
        $this->assertContains($ids[3], $index);
        $this->assertContains($ids[4], $index);
    }

    // ─── Strict Types & Return Types ───────────────────────────────────

    public function testServiceClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(EventDebugCaptureService::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function testServiceHasStrictTypes(): void
    {
        $file = (string) file_get_contents(
            dirname(__DIR__) . '/src/Services/EventDebugCaptureService.php',
        );

        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function testCaptureMethodReturnType(): void
    {
        $method = new \ReflectionMethod(EventDebugCaptureService::class, 'capture');

        $this->assertSame('?string', $method->getReturnType()?->getName() ?? ($method->getReturnType()?->allowsNull() ? '?string' : 'string'));
    }

    public function testReplayMethodReturnType(): void
    {
        $method = new \ReflectionMethod(EventDebugCaptureService::class, 'replay');

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame(AnalyticsEvent::class, $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }

    public function testSimulateMethodReturnType(): void
    {
        $method = new \ReflectionMethod(EventDebugCaptureService::class, 'simulate');

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame(AnalyticsEvent::class, $returnType->getName());
    }

    public function testAllPublicMethodsHaveReturnTypeDeclarations(): void
    {
        $class = new \ReflectionClass(EventDebugCaptureService::class);
        $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            $this->assertNotNull(
                $method->getReturnType(),
                "Method {$method->getName()} is missing return type declaration",
            );
        }
    }

    // ─── Docblocks ─────────────────────────────────────────────────────

    public function testServiceHasDocblock(): void
    {
        $class = new \ReflectionClass(EventDebugCaptureService::class);

        $this->assertStringContainsString('Event debug capture', $class->getDocComment() ?: '');
    }

    public function testPublicMethodsHaveDocblocks(): void
    {
        $class = new \ReflectionClass(EventDebugCaptureService::class);
        $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            $doc = $method->getDocComment();
            $this->assertNotFalse(
                $doc,
                "Method {$method->getName()} is missing docblock",
            );
            $this->assertStringContainsString(
                '*',
                $doc ?: '',
                "Method {$method->getName()} docblock is malformed",
            );
        }
    }

    // ─── Version Consistency ────────────────────────────────────────────

    public function testVersionConsistency(): void
    {
        $this->assertSame('160.0.0', AnalyticsEvent::VERSION);
    }

    public function testServiceFileHasLicenseHeader(): void
    {
        $file = (string) file_get_contents(
            dirname(__DIR__) . '/src/Services/EventDebugCaptureService.php',
        );

        $this->assertStringContainsString('This file is part of ZeroBoiler', $file);
        $this->assertStringContainsString('MIT license', $file);
    }

    // ─── Integration: AnalyticsEvent withSource ────────────────────────

    public function testCaptureWithVariousEventSources(): void
    {
        $sources = ['server', 'client', 'replay', 'simulation', 'api'];
        $ids = [];

        foreach ($sources as $source) {
            $event = (new AnalyticsEvent(name: "src_{$source}", params: []))->withSource($source);
            $ids[$source] = $this->service->capture($event);
        }

        foreach ($sources as $source) {
            $capture = $this->service->getCapture($ids[$source]);
            $this->assertSame($source, $capture['source'], "Source mismatch for {$source}");
        }
    }

    // ─── Edge Cases ─────────────────────────────────────────────────────

    public function testCaptureEventWithNullTimestamp(): void
    {
        $event = new AnalyticsEvent(name: 'no_timestamp', params: [], timestamp: null);

        $captureId = $this->service->capture($event);
        $capture = $this->service->getCapture($captureId);

        $this->assertNotNull($capture);
        $this->assertArrayHasKey('timestamp', $capture);
    }

    public function testCaptureWithEmptyContext(): void
    {
        $event = new AnalyticsEvent(name: 'no_context', params: []);

        $captureId = $this->service->capture($event);
        $capture = $this->service->getCapture($captureId);

        $this->assertSame([], $capture['context']);
    }

    public function testReplayWithCorruptTimestamp(): void
    {
        // Manually inject a corrupt capture
        $corruptCapture = [
            'id' => 'corrupt_1',
            'event_name' => 'corrupt_event',
            'params' => [],
            'client_id' => null,
            'user_id' => null,
            'timestamp' => 'not-a-date',
            'priority' => null,
            'source' => 'server',
            'context' => [],
            'captured_at' => date('c'),
            'capture_version' => '30.0.0',
        ];

        $this->cache->put('zb_test_corrupt_1', $corruptCapture, 3600);
        $this->cacheStore['zb_debug_index'] = ['corrupt_1'];

        $replayed = $this->service->replay('corrupt_1');

        $this->assertNotNull($replayed);
        $this->assertSame('corrupt_event', $replayed->name);
        $this->assertNull($replayed->timestamp);
    }

    public function testReplayWithMissingFields(): void
    {
        $incompleteCapture = [
            'id' => 'incomplete_1',
            'event_name' => 'partial_event',
            'context' => [],
            'captured_at' => date('c'),
        ];

        $this->cache->put('zb_test_incomplete_1', $incompleteCapture, 3600);
        $this->cacheStore['zb_debug_index'] = ['incomplete_1'];

        $replayed = $this->service->replay('incomplete_1');

        $this->assertNotNull($replayed);
        $this->assertSame('partial_event', $replayed->name);
        $this->assertSame([], $replayed->params);
        $this->assertNull($replayed->clientId);
    }
}
