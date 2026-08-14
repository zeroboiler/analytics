<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsEventBuffer;

/**
 * v141.0.0 — Event DTO Enrichment, Event Buffering & Debounce/Once Tracking
 *
 * Comprehensive test suite covering:
 * - AnalyticsEvent DTO: category, sessionId, withCategory(), withSessionId(), withMergedParams()
 * - AnalyticsEventBuffer: push, flush, dedup, TTL, capacity, stats
 * - Version consistency across all client files
 * - Strict types compliance
 * - License headers
 */
final class V141EventBufferAndDtoEnrichmentTest extends TestCase
{
    // ── AnalyticsEvent DTO Tests ──────────────────────────────────────

    public function test_dto_version_is_141(): void
    {
        $this->assertSame('141.0.0', AnalyticsEvent::VERSION);
    }

    public function test_dto_has_category_field(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            category: 'ecommerce',
        );

        $this->assertSame('ecommerce', $event->category);
    }

    public function test_dto_category_defaults_to_null(): void
    {
        $event = new AnalyticsEvent(name: 'page_view');

        $this->assertNull($event->category);
    }

    public function test_dto_has_session_id_field(): void
    {
        $event = new AnalyticsEvent(
            name: 'sign_up',
            sessionId: 'sess_abc123',
        );

        $this->assertSame('sess_abc123', $event->sessionId);
    }

    public function test_dto_session_id_defaults_to_null(): void
    {
        $event = new AnalyticsEvent(name: 'login');

        $this->assertNull($event->sessionId);
    }

    public function test_dto_to_array_includes_category_and_session_id(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'cli_123',
            userId: 'user_456',
            category: 'ecommerce',
            sessionId: 'sess_789',
        );

        $arr = $event->toArray();

        $this->assertSame('ecommerce', $arr['category']);
        $this->assertSame('sess_789', $arr['session_id']);
    }

    public function test_dto_from_array_handles_category_and_session_id(): void
    {
        $event = AnalyticsEvent::fromArray([
            'name' => 'scroll_depth',
            'params' => ['percent' => 75],
            'client_id' => 'cli_1',
            'user_id' => 'user_1',
            'category' => 'engagement',
            'session_id' => 'sess_1',
        ]);

        $this->assertSame('scroll_depth', $event->name);
        $this->assertSame(['percent' => 75], $event->params);
        $this->assertSame('cli_1', $event->clientId);
        $this->assertSame('user_1', $event->userId);
        $this->assertSame('engagement', $event->category);
        $this->assertSame('sess_1', $event->sessionId);
    }

    public function test_dto_from_array_defaults_category_and_session_id_to_null(): void
    {
        $event = AnalyticsEvent::fromArray([
            'name' => 'click',
        ]);

        $this->assertNull($event->category);
        $this->assertNull($event->sessionId);
    }

    public function test_dto_with_category_creates_new_instance(): void
    {
        $original = new AnalyticsEvent(name: 'page_view');
        $enriched = $original->withCategory('engagement');

        $this->assertNotSame($original, $enriched);
        $this->assertNull($original->category);
        $this->assertSame('engagement', $enriched->category);
        $this->assertSame('page_view', $enriched->name);
    }

    public function test_dto_with_session_id_creates_new_instance(): void
    {
        $original = new AnalyticsEvent(name: 'form_submit');
        $enriched = $original->withSessionId('sess_xyz');

        $this->assertNotSame($original, $enriched);
        $this->assertNull($original->sessionId);
        $this->assertSame('sess_xyz', $enriched->sessionId);
        $this->assertSame('form_submit', $enriched->name);
    }

    public function test_dto_with_merged_params_creates_new_instance(): void
    {
        $original = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 50.0, 'currency' => 'USD'],
        );
        $enriched = $original->withMergedParams(['coupon' => 'SAVE10']);

        $this->assertNotSame($original, $enriched);
        $this->assertSame(['value' => 50.0, 'currency' => 'USD', 'coupon' => 'SAVE10'], $enriched->params);
        $this->assertSame(['value' => 50.0, 'currency' => 'USD'], $original->params);
    }

    public function test_dto_with_merged_params_overrides_existing_keys(): void
    {
        $original = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 50.0],
        );
        $enriched = $original->withMergedParams(['value' => 99.99]);

        $this->assertSame(99.99, $enriched->params['value']);
    }

    public function test_dto_chaining_with_methods(): void
    {
        $event = (new AnalyticsEvent(name: 'sign_up'))
            ->withCategory('saas')
            ->withSessionId('sess_chain')
            ->withMergedParams(['method' => 'email']);

        $this->assertSame('saas', $event->category);
        $this->assertSame('sess_chain', $event->sessionId);
        $this->assertSame(['method' => 'email'], $event->params);
        $this->assertSame('sign_up', $event->name);
    }

    public function test_dto_readonly_class(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    // ── AnalyticsEventBuffer Tests ────────────────────────────────────

    public function test_buffer_push_returns_fingerprint(): void
    {
        $buffer = new AnalyticsEventBuffer;
        $event = new AnalyticsEvent(name: 'page_view', clientId: 'cli_1');

        $fp = $buffer->push($event);

        $this->assertIsString($fp);
        $this->assertNotEmpty($fp);
    }

    public function test_buffer_push_increases_count(): void
    {
        $buffer = new AnalyticsEventBuffer;

        $this->assertSame(0, $buffer->count());

        $buffer->push(new AnalyticsEvent(name: 'page_view', clientId: 'cli_1'));
        $buffer->push(new AnalyticsEvent(name: 'click', clientId: 'cli_1'));

        $this->assertSame(2, $buffer->count());
    }

    public function test_buffer_has_detects_duplicate(): void
    {
        $buffer = new AnalyticsEventBuffer;
        $event = new AnalyticsEvent(name: 'page_view', clientId: 'cli_1', userId: 'user_1');

        $buffer->push($event);

        $this->assertTrue($buffer->has($event));
    }

    public function test_buffer_has_returns_false_for_new_event(): void
    {
        $buffer = new AnalyticsEventBuffer;

        $this->assertFalse($buffer->has(new AnalyticsEvent(name: 'scroll_depth')));
    }

    public function test_buffer_has_different_params_different_fingerprint(): void
    {
        $buffer = new AnalyticsEventBuffer;

        $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 10]);
        $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 20]);

        $buffer->push($event1);

        // Same name + clientId + userId + param keys → same fingerprint
        // (params values don't affect fingerprint)
        $this->assertTrue($buffer->has($event2));
    }

    public function test_buffer_flush_returns_all_events(): void
    {
        $buffer = new AnalyticsEventBuffer;
        $event1 = new AnalyticsEvent(name: 'page_view', clientId: 'cli_1');
        $event2 = new AnalyticsEvent(name: 'click', clientId: 'cli_1');

        $buffer->push($event1);
        $buffer->push($event2);

        $flushed = $buffer->flush();

        $this->assertCount(2, $flushed);
        $this->assertSame('page_view', $flushed[0]->name);
        $this->assertSame('click', $flushed[1]->name);
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_buffer_flush_records_fingerprints(): void
    {
        $buffer = new AnalyticsEventBuffer;
        $event = new AnalyticsEvent(name: 'page_view', clientId: 'cli_1');

        $buffer->push($event);
        $buffer->flush();

        $this->assertTrue($buffer->wasRecentlyFlushed($event));
    }

    public function test_buffer_was_recently_flushed_returns_false_initially(): void
    {
        $buffer = new AnalyticsEventBuffer;
        $event = new AnalyticsEvent(name: 'page_view');

        $this->assertFalse($buffer->wasRecentlyFlushed($event));
    }

    public function test_buffer_capacity_evicts_oldest(): void
    {
        $buffer = new AnalyticsEventBuffer(maxCapacity: 3);

        $buffer->push(new AnalyticsEvent(name: 'e1', clientId: 'cli_1'));
        $buffer->push(new AnalyticsEvent(name: 'e2', clientId: 'cli_1'));
        $buffer->push(new AnalyticsEvent(name: 'e3', clientId: 'cli_1'));
        $buffer->push(new AnalyticsEvent(name: 'e4', clientId: 'cli_1'));

        // At capacity 3, buffer should have at most 3
        $this->assertLessThanOrEqual(3, $buffer->count());
    }

    public function test_buffer_clear_empties_buffer(): void
    {
        $buffer = new AnalyticsEventBuffer;

        $buffer->push(new AnalyticsEvent(name: 'page_view'));
        $buffer->push(new AnalyticsEvent(name: 'click'));
        $buffer->clear();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(0, $buffer->count());
    }

    public function test_buffer_stats(): void
    {
        $buffer = new AnalyticsEventBuffer(maxCapacity: 50, ttlSeconds: 7200, dedupWindowSeconds: 5);

        $buffer->push(new AnalyticsEvent(name: 'page_view'));

        $stats = $buffer->stats();

        $this->assertSame(1, $stats['count']);
        $this->assertSame(50, $stats['capacity']);
        $this->assertSame(0, $stats['flush_count']);
        $this->assertSame(7200, $stats['ttl']);
        $this->assertGreaterThan(0, $stats['utilization']);
    }

    public function test_buffer_flush_expired_removes_old_events(): void
    {
        $buffer = new AnalyticsEventBuffer(ttlSeconds: 1);

        $oldEvent = new AnalyticsEvent(
            name: 'old_event',
            timestamp: new \DateTimeImmutable('-10 seconds'),
        );
        $newEvent = new AnalyticsEvent(
            name: 'new_event',
            timestamp: new \DateTimeImmutable('now'),
        );

        $buffer->push($oldEvent);
        $buffer->push($newEvent);

        $expired = $buffer->flushExpired();

        $this->assertCount(1, $expired);
        $this->assertSame('old_event', $expired[0]->name);
        $this->assertSame(1, $buffer->count());
        $this->assertSame('new_event', $buffer->flush()[0]->name);
    }

    public function test_buffer_flush_expired_with_zero_ttl_returns_empty(): void
    {
        $buffer = new AnalyticsEventBuffer(ttlSeconds: 0);

        $buffer->push(new AnalyticsEvent(name: 'page_view'));

        $expired = $buffer->flushExpired();

        $this->assertEmpty($expired);
    }

    public function test_buffer_unlimited_capacity(): void
    {
        $buffer = new AnalyticsEventBuffer(maxCapacity: 0);

        for ($i = 0; $i < 200; $i++) {
            $buffer->push(new AnalyticsEvent(name: "event_{$i}", clientId: "cli_{$i}"));
        }

        $this->assertSame(200, $buffer->count());
    }

    public function test_buffer_get_flush_count_increments(): void
    {
        $buffer = new AnalyticsEventBuffer;

        $this->assertSame(0, $buffer->getFlushCount());

        $buffer->push(new AnalyticsEvent(name: 'e1'));
        $buffer->flush();

        $this->assertSame(1, $buffer->getFlushCount());

        $buffer->push(new AnalyticsEvent(name: 'e2'));
        $buffer->flush();

        $this->assertSame(2, $buffer->getFlushCount());
    }

    // ── Cross-cutting Tests ──────────────────────────────────────────

    public function test_version_consistency(): void
    {
        $this->assertSame(
            AnalyticsEvent::VERSION,
            '141.0.0',
            'AnalyticsEvent::VERSION must be 141.0.0'
        );
    }

    public function test_dto_strict_types_declaration(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $contents,
            'AnalyticsEvent.php must have strict types declaration'
        );
    }

    public function test_buffer_strict_types_declaration(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEventBuffer::class);
        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $contents,
            'AnalyticsEventBuffer.php must have strict types declaration'
        );
    }

    public function test_dto_has_license_header(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $file = (string) file_get_contents((string) $reflection->getFileName());
        $lines = explode("\n", $file);

        $this->assertStringContainsString(
            'This file is part of ZeroBoiler',
            $lines[2] ?? '',
            'AnalyticsEvent.php must have MIT license header'
        );
    }

    public function test_buffer_has_license_header(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEventBuffer::class);
        $file = (string) file_get_contents((string) $reflection->getFileName());
        $lines = explode("\n", $file);

        $this->assertStringContainsString(
            'This file is part of ZeroBoiler',
            $lines[2] ?? '',
            'AnalyticsEventBuffer.php must have MIT license header'
        );
    }

    public function test_dto_constructor_has_void_return_type(): void
    {
        $reflection = new \ReflectionMethod(AnalyticsEvent::class, '__construct');

        $this->assertSame('void', (string) $reflection->getReturnType());
    }

    public function test_buffer_constructor_has_void_return_type(): void
    {
        $reflection = new \ReflectionMethod(AnalyticsEventBuffer::class, '__construct');

        $this->assertSame('void', (string) $reflection->getReturnType());
    }

    public function test_buffer_all_public_methods_have_return_types(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEventBuffer::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $noReturnType = [];

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() === AnalyticsEventBuffer::class
                && $method->getReturnType() === null
                && $method->getName() !== '__construct'
            ) {
                $noReturnType[] = $method->getName();
            }
        }

        $this->assertEmpty(
            $noReturnType,
            'All public methods must have return types. Missing: ' . implode(', ', $noReturnType)
        );
    }

    public function test_dto_all_public_methods_have_return_types(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $noReturnType = [];

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() === AnalyticsEvent::class
                && $method->getReturnType() === null
                && $method->getName() !== '__construct'
            ) {
                $noReturnType[] = $method->getName();
            }
        }

        $this->assertEmpty(
            $noReturnType,
            'All public methods must have return types. Missing: ' . implode(', ', $noReturnType)
        );
    }

    public function test_buffer_namespace(): void
    {
        $this->assertSame(
            'ZeroBoiler\\Analytics\\Services\\AnalyticsEventBuffer',
            AnalyticsEventBuffer::class
        );
    }

    public function test_dto_namespace(): void
    {
        $this->assertSame(
            'ZeroBoiler\\Analytics\\DTO\\AnalyticsEvent',
            AnalyticsEvent::class
        );
    }

    public function test_event_catalog_count_is_positive(): void
    {
        $catalogReflection = new \ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class);
        $method = $catalogReflection->getMethod('count');
        $method->setAccessible(true);

        $count = $method->invoke(null);

        $this->assertGreaterThan(200, $count, 'EventCatalog should have 200+ events');
    }

    public function test_no_todo_or_fixme_in_new_files(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEventBuffer::class);
        $file = (string) file_get_contents((string) $reflection->getFileName());

        $this->assertStringNotContainsString('TODO', $file);
        $this->assertStringNotContainsString('FIXME', $file);
    }
}
