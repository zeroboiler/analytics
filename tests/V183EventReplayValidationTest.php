<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventReplayValidationService;

/**
 * Tests for the Event Replay Validation Service.
 *
 * Validates that events are properly checked before replay dispatch:
 * - Catalog membership validation
 * - Blocked event filtering
 * - Payload content screening (PII patterns)
 * - Timestamp sanity checks (stale/future events)
 * - Idempotency detection
 * - Data quality checks (empty names, large payloads)
 * - Batch validation
 * - Sanitization of replayed events
 *
 * @since 183.0.0
 */
final class EventReplayValidationServiceTest extends TestCase
{
    private EventReplayValidationService $service;

    private \PHPUnit\Framework\MockObject\MockObject|\Illuminate\Contracts\Cache\Repository $cache;

    private array $cacheStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->method('has')->willReturnCallback(fn(string $key): bool => isset($this->cacheStore[$key]));
        $this->cache->method('get')->willReturnCallback(fn(string $key, mixed $default = null) => $this->cacheStore[$key] ?? $default);
        $this->cache->method('put')->willReturnCallback(function (string $key, mixed $value, int $ttl = 60): bool {
            $this->cacheStore[$key] = $value;

            return true;
        });
        $this->cache->method('forget')->willReturnCallback(function (string $key): bool {
            unset($this->cacheStore[$key]);

            return true;
        });

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.replay_validation', null, [
                'cache_prefix' => 'test_replay_',
                'idempotency_ttl' => 86400,
                'enforce_catalog' => true,
                'enforce_consent' => true,
                'enforce_quality' => true,
                'blocked_events' => ['internal_debug'],
                'blocked_patterns' => ['/password/i', '/credit_card/i', '/ssn/i'],
            ]],
        ]);

        $catalogValidator = $this->createMock(\ZeroBoiler\Analytics\Services\EventCatalogValidator::class);

        $this->service = new EventReplayValidationService($this->cache, $config, $catalogValidator);
        $this->cacheStore = [];
    }

    public function testValidEventPassesValidation(): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['url' => 'https://example.com'],
            clientId: 'client_123',
            timestamp: new \DateTimeImmutable(),
        );

        $result = $this->service->validate($event);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['issues']);
        $this->assertNotNull($result['sanitized_event']);
        $this->assertSame('page_view', $result['sanitized_event']->name);
    }

    public function testBlockedEventFailsValidation(): void
    {
        $event = new AnalyticsEvent(
            name: 'internal_debug',
            params: [],
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['issues']);
        $blockedIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'EVENT_BLOCKED');
        $this->assertCount(1, $blockedIssues);
        $this->assertNull($result['sanitized_event']);
    }

    public function testSensitivePayloadContentBlocked(): void
    {
        $event = new AnalyticsEvent(
            name: 'form_submit',
            params: ['form_data' => ['password' => 'secret123']],
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertFalse($result['valid']);
        $sensitiveIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'SENSITIVE_CONTENT');
        $this->assertCount(1, $sensitiveIssues);
    }

    public function testCreditCardPatternBlocked(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['payment' => ['credit_card' => '4111111111111111']],
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertFalse($result['valid']);
        $sensitiveIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'SENSITIVE_CONTENT');
        $this->assertNotEmpty($sensitiveIssues);
    }

    public function testStaleEventFlagsWarning(): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [],
            clientId: 'client_123',
            timestamp: new \DateTimeImmutable('-100 days'),
        );

        $result = $this->service->validate($event);

        // Should still be valid (warning only, not error)
        $this->assertTrue($result['valid']);
        $staleIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'STALE_EVENT');
        $this->assertCount(1, $staleIssues);
        $this->assertSame('warning', reset($staleIssues)['severity']);
    }

    public function testFutureEventFlagsWarning(): void
    {
        $event = new AnalyticsEvent(
            name: 'click',
            params: [],
            clientId: 'client_123',
            timestamp: new \DateTimeImmutable('+1 hour'),
        );

        $result = $this->service->validate($event);

        $this->assertTrue($result['valid']);
        $futureIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'FUTURE_EVENT');
        $this->assertCount(1, $futureIssues);
    }

    public function testEmptyEventNameFailsValidation(): void
    {
        $event = new AnalyticsEvent(
            name: '',
            params: ['url' => 'https://example.com'],
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertFalse($result['valid']);
        $emptyIssues = array_filter($result['issues'], fn(array $i): bool => in_array($i['code'], ['EMPTY_NAME', 'INVALID_NAME'], true));
        $this->assertNotEmpty($emptyIssues);
    }

    public function testSanitizedEventHasReplaySource(): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['url' => 'https://example.com', '_replay_id' => 'old', '_original_timestamp' => '2024-01-01'],
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertTrue($result['valid']);
        $sanitized = $result['sanitized_event'];
        $this->assertNotNull($sanitized);
        $this->assertSame('replay', $sanitized->source);
        $this->assertArrayNotHasKey('_replay_id', $sanitized->params);
        $this->assertArrayNotHasKey('_original_timestamp', $sanitized->params);
    }

    public function testBatchValidationCountsCorrectly(): void
    {
        $validEvent = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'c1');
        $invalidEvent = new AnalyticsEvent(name: 'internal_debug', params: [], clientId: 'c2');

        $result = $this->service->validateBatch([$validEvent, $invalidEvent]);

        $this->assertSame(1, $result['valid_count']);
        $this->assertSame(1, $result['invalid_count']);
        $this->assertCount(2, $result['results']);
        $this->assertTrue($result['results'][0]['valid']);
        $this->assertFalse($result['results'][1]['valid']);
    }

    public function testGetBlockedEventsReturnsList(): void
    {
        $blocked = $this->service->getBlockedEvents();

        $this->assertContains('internal_debug', $blocked);
    }

    public function testBlockAndUnblockEvent(): void
    {
        $this->service->blockEvent('custom_blocked');

        $this->assertContains('custom_blocked', $this->service->getBlockedEvents());

        $this->service->unblockEvent('custom_blocked');

        $this->assertNotContains('custom_blocked', $this->service->getBlockedEvents());
    }

    public function testIdempotencyPreventsDuplicateReplay(): void
    {
        $event = new AnalyticsEvent(
            name: 'click',
            params: ['element' => 'button'],
            clientId: 'client_123',
        );

        // First validation passes
        $result1 = $this->service->validate($event);
        $this->assertTrue($result1['valid']);

        // Mark as replayed
        $this->service->markReplayed($event);

        // Second validation should detect duplicate
        $result2 = $this->service->validate($event);
        $this->assertFalse($result2['valid']);
        $dupIssues = array_filter($result2['issues'], fn(array $i): bool => $i['code'] === 'REPLAY_DUPLICATE');
        $this->assertCount(1, $dupIssues);
    }

    public function testLargePayloadFlagsWarning(): void
    {
        $largeParams = ['data' => str_repeat('x', 11000)];

        $event = new AnalyticsEvent(
            name: 'custom_event',
            params: $largeParams,
            clientId: 'client_123',
        );

        $result = $this->service->validate($event);

        $this->assertTrue($result['valid']);
        $sizeIssues = array_filter($result['issues'], fn(array $i): bool => $i['code'] === 'PAYLOAD_TOO_LARGE');
        $this->assertCount(1, $sizeIssues);
        $this->assertSame('warning', reset($sizeIssues)['severity']);
    }

    public function testStatsReturnsDefaultWhenEmpty(): void
    {
        $stats = $this->service->stats();

        $this->assertSame(0, $stats['total_validated']);
        $this->assertSame(0, $stats['total_passed']);
        $this->assertSame(0, $stats['total_failed']);
        $this->assertSame(1.0, $stats['pass_rate']);
    }

    public function testResetStatsClearsData(): void
    {
        $this->cacheStore['test_replay_stats'] = ['total' => 10, 'passed' => 8, 'failed' => 2];

        $this->service->resetStats();

        $this->assertFalse(isset($this->cacheStore['test_replay_stats']));
    }
}
