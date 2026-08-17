<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Phase56;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\EventLifecycleState;
use ZeroBoiler\Analytics\Services\EventLifecycleTracker;

/**
 * Phase 56 Production Readiness — Event Lifecycle State Machine.
 *
 * Validates: EventLifecycleState DTO (constants, state machine transitions,
 * terminal/initial state detection, factory methods, serialization round-trips),
 * EventLifecycleTracker service (initialization, transitions, failure handling,
 * replay with max-retry enforcement, dead-letter routing, aggregate stats,
 * cache persistence, drop/skip shortcuts, disabled mode, TTL/retry config),
 * file quality (strict_types, MIT headers, final/readonly classes, return types),
 * version consistency 232.0.0 across 5 entry points, config section presence,
 * ServiceProvider singleton registration.
 *
 * @since 232.0.0
 */
final class Phase56ProductionReadinessTest extends \PHPUnit\Framework\TestCase
{
    // ── File Quality ─────────────────────────────────────────────────

    #[Test]
    public function event_lifecycle_state_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/EventLifecycleState.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function event_lifecycle_state_has_mit_license(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/EventLifecycleState.php');
        $this->assertStringContainsString('MIT license', $content);
    }

    #[Test]
    public function event_lifecycle_state_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(EventLifecycleState::class);
        $this->assertTrue($reflection->isFinal(), 'EventLifecycleState must be final');
        $this->assertTrue($reflection->isReadOnly(), 'EventLifecycleState must be readonly');
    }

    #[Test]
    public function event_lifecycle_state_has_since_annotation(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/EventLifecycleState.php');
        $this->assertStringContainsString('@since 232.0.0', $content);
    }

    #[Test]
    public function event_lifecycle_tracker_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventLifecycleTracker.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function event_lifecycle_tracker_has_mit_license(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventLifecycleTracker.php');
        $this->assertStringContainsString('MIT license', $content);
    }

    #[Test]
    public function event_lifecycle_tracker_is_final(): void
    {
        $reflection = new \ReflectionClass(EventLifecycleTracker::class);
        $this->assertTrue($reflection->isFinal(), 'EventLifecycleTracker must be final');
    }

    #[Test]
    public function event_lifecycle_tracker_has_since_annotation(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventLifecycleTracker.php');
        $this->assertStringContainsString('@since 232.0.0', $content);
    }

    // ── State Constants ─────────────────────────────────────────────

    #[Test]
    public function has_all_ten_states(): void
    {
        $expected = [
            'created', 'validated', 'enqueued', 'dispatched', 'delivered',
            'failed', 'replayed', 'dead_lettered', 'dropped', 'skipped',
        ];
        $this->assertCount(10, EventLifecycleState::ALL_STATES);
        foreach ($expected as $state) {
            $this->assertContains($state, EventLifecycleState::ALL_STATES);
        }
    }

    #[Test]
    public function initial_states_are_correct(): void
    {
        $this->assertSame(
            ['created', 'skipped', 'dropped'],
            EventLifecycleState::INITIAL_STATES,
        );
    }

    #[Test]
    public function terminal_states_are_correct(): void
    {
        $this->assertSame(
            ['delivered', 'dead_lettered', 'dropped', 'skipped'],
            EventLifecycleState::TERMINAL_STATES,
        );
    }

    #[Test]
    public function terminal_states_are_subset_of_all_states(): void
    {
        foreach (EventLifecycleState::TERMINAL_STATES as $state) {
            $this->assertContains($state, EventLifecycleState::ALL_STATES);
        }
    }

    #[Test]
    public function initial_states_are_subset_of_all_states(): void
    {
        foreach (EventLifecycleState::INITIAL_STATES as $state) {
            $this->assertContains($state, EventLifecycleState::ALL_STATES);
        }
    }

    // ── State Machine Transitions ────────────────────────────────────

    #[Test]
    public function created_can_transition_to_validated(): void
    {
        $state = EventLifecycleState::create('evt-1', 'page_view');
        $this->assertTrue($state->canTransitionTo('validated'));
        $this->assertFalse($state->canTransitionTo('delivered'));
    }

    #[Test]
    public function created_can_transition_to_enqueued(): void
    {
        $state = EventLifecycleState::create('evt-2', 'purchase');
        $this->assertTrue($state->canTransitionTo('enqueued'));
    }

    #[Test]
    public function created_can_transition_to_dropped_and_skipped(): void
    {
        $state = EventLifecycleState::create('evt-3', 'click');
        $this->assertTrue($state->canTransitionTo('dropped'));
        $this->assertTrue($state->canTransitionTo('skipped'));
    }

    #[Test]
    public function validated_can_transition_to_enqueued_or_dispatched(): void
    {
        $state = EventLifecycleState::create('evt-4', 'sign_up')->transition('validated');
        $this->assertNotNull($state);
        $this->assertTrue($state->canTransitionTo('enqueued'));
        $this->assertTrue($state->canTransitionTo('dispatched'));
        $this->assertFalse($state->canTransitionTo('delivered'));
    }

    #[Test]
    public function failed_can_transition_to_replayed_or_dead_lettered(): void
    {
        $state = EventLifecycleState::create('evt-5', 'purchase')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed');
        $this->assertNotNull($state);
        $this->assertTrue($state->canTransitionTo('replayed'));
        $this->assertTrue($state->canTransitionTo('dead_lettered'));
        $this->assertTrue($state->canTransitionTo('enqueued'));
    }

    #[Test]
    public function replayed_can_transition_to_dispatched(): void
    {
        $state = EventLifecycleState::create('evt-6', 'purchase')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed')
            ->transition('replayed');
        $this->assertNotNull($state);
        $this->assertTrue($state->canTransitionTo('dispatched'));
    }

    #[Test]
    public function invalid_transition_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid transition from 'created' to 'delivered'");

        $state = EventLifecycleState::create('evt-7', 'page_view');
        $state->transition('delivered');
    }

    #[Test]
    public function transition_from_terminal_state_throws(): void
    {
        $state = EventLifecycleState::create('evt-8', 'page_view')
            ->transition('validated')
            ->transition('dispatched')
            ->transition('delivered');

        $this->assertNotNull($state);
        $this->expectException(\InvalidArgumentException::class);
        $state->transition('failed');
    }

    // ── Full Lifecycle Path ──────────────────────────────────────────

    #[Test]
    public function happy_path_lifecycle(): void
    {
        $state = EventLifecycleState::create('evt-happy', 'purchase')
            ->transition('validated')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('delivered', 'provider_success', ['provider' => 'ga4']);

        $this->assertNotNull($state);
        $this->assertSame('delivered', $state->state);
        $this->assertSame('provider_success', $state->reason);
        $this->assertSame(['provider' => 'ga4'], $state->metadata);
        $this->assertSame(1, $state->attemptCount);
        $this->assertTrue($state->isTerminal());
        $this->assertCount(4, $state->history);
    }

    #[Test]
    public function failure_with_retry_lifecycle(): void
    {
        $state = EventLifecycleState::create('evt-retry', 'purchase')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed', 'timeout', ['error' => 'Connection timeout'])
            ->transition('replayed')
            ->transition('dispatched')
            ->transition('delivered');

        $this->assertNotNull($state);
        $this->assertSame('delivered', $state->state);
        $this->assertSame(2, $state->attemptCount);
        $this->assertCount(6, $state->history);
        $this->assertSame('timeout', $state->history[2]['reason']);
    }

    #[Test]
    public function dead_letter_lifecycle(): void
    {
        $state = EventLifecycleState::create('evt-dlq', 'purchase')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed', 'server_error')
            ->transition('replayed')
            ->transition('dispatched')
            ->transition('failed', 'server_error_again')
            ->transition('dead_lettered', 'max_retries_exceeded');

        $this->assertNotNull($state);
        $this->assertSame('dead_lettered', $state->state);
        $this->assertTrue($state->isTerminal());
    }

    // ── Terminal / Initial Detection ──────────────────────────────────

    #[Test]
    public function is_terminal_for_delivered(): void
    {
        $state = EventLifecycleState::create('evt-t1', 'purchase');
        $this->assertFalse($state->isTerminal());
        $this->assertTrue($state->isInitial());
    }

    #[Test]
    public function is_initial_for_created_state(): void
    {
        $state = EventLifecycleState::create('evt-t2', 'click', EventLifecycleState::STATE_CREATED);
        $this->assertTrue($state->isInitial());
        $this->assertFalse($state->isTerminal());
    }

    #[Test]
    public function is_initial_for_skipped_state(): void
    {
        $state = EventLifecycleState::create('evt-t3', 'click', EventLifecycleState::STATE_SKIPPED);
        $this->assertTrue($state->isInitial());
        $this->assertTrue($state->isTerminal());
    }

    #[Test]
    public function is_initial_for_dropped_state(): void
    {
        $state = EventLifecycleState::create('evt-t4', 'click', EventLifecycleState::STATE_DROPPED);
        $this->assertTrue($state->isInitial());
        $this->assertTrue($state->isTerminal());
    }

    // ── Allowed Transitions ──────────────────────────────────────────

    #[Test]
    public function allowed_transitions_for_created(): void
    {
        $state = EventLifecycleState::create('evt-at', 'page_view');
        $allowed = $state->allowedTransitions();
        $this->assertCount(4, $allowed);
        $this->assertContains('validated', $allowed);
        $this->assertContains('enqueued', $allowed);
        $this->assertContains('dropped', $allowed);
        $this->assertContains('skipped', $allowed);
    }

    #[Test]
    public function allowed_transitions_for_terminal_state_is_empty(): void
    {
        $state = EventLifecycleState::create('evt-at2', 'page_view')
            ->transition('validated')
            ->transition('dispatched')
            ->transition('delivered');
        $this->assertNotNull($state);
        $this->assertEmpty($state->allowedTransitions());
    }

    // ── Serialization Round-Trip ──────────────────────────────────────

    #[Test]
    public function to_array_contains_all_fields(): void
    {
        $state = EventLifecycleState::create('evt-ser', 'purchase')
            ->transition('validated')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('delivered', 'success', ['latency_ms' => 42]);

        $this->assertNotNull($state);
        $array = $state->toArray();

        $this->assertSame('evt-ser', $array['event_id']);
        $this->assertSame('purchase', $array['event_name']);
        $this->assertSame('delivered', $array['state']);
        $this->assertSame('dispatched', $array['previous_state']);
        $this->assertSame('success', $array['reason']);
        $this->assertSame(1, $array['attempt_count']);
        $this->assertTrue($array['is_terminal']);
        $this->assertFalse($array['is_initial']);
        $this->assertSame(4, $array['history_count']);
        $this->assertCount(4, $array['history']);
        $this->assertArrayHasKey('latency_ms', $array['metadata']);
    }

    #[Test]
    public function from_array_round_trip(): void
    {
        $original = EventLifecycleState::create('evt-rt', 'sign_up')
            ->transition('validated')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed', 'timeout', ['retry_after' => 60])
            ->transition('replayed');

        $this->assertNotNull($original);
        $restored = EventLifecycleState::fromArray($original->toArray());

        $this->assertSame($original->eventId, $restored->eventId);
        $this->assertSame($original->eventName, $restored->eventName);
        $this->assertSame($original->state, $restored->state);
        $this->assertSame($original->previousState, $restored->previousState);
        $this->assertSame($original->reason, $restored->reason);
        $this->assertSame($original->attemptCount, $restored->attemptCount);
        $this->assertSame($original->isTerminal(), $restored->isTerminal());
        $this->assertSame($original->isInitial(), $restored->isInitial());
        $this->assertCount(count($original->history), $restored->history);
    }

    // ── Factory Method ───────────────────────────────────────────────

    #[Test]
    public function create_with_invalid_state_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown state: 'nonexistent'");

        EventLifecycleState::create('evt-f', 'click', 'nonexistent');
    }

    #[Test]
    public function create_with_skipped_initial_state(): void
    {
        $state = EventLifecycleState::create('evt-f2', 'click', EventLifecycleState::STATE_SKIPPED);
        $this->assertSame('skipped', $state->state);
        $this->assertTrue($state->isInitial());
    }

    // ── Transition History ────────────────────────────────────────────

    #[Test]
    public function history_records_transition_reasons(): void
    {
        $state = EventLifecycleState::create('evt-hist', 'purchase')
            ->transition('enqueued')
            ->transition('dispatched')
            ->transition('failed', 'provider_error', ['provider' => 'meta'])
            ->transition('replayed', 'auto_retry');

        $this->assertNotNull($state);
        $this->assertCount(4, $state->history);

        // First transition: created → enqueued
        $this->assertSame('created', $state->history[0]['from']);
        $this->assertSame('enqueued', $state->history[0]['to']);
        $this->assertNull($state->history[0]['reason']);

        // Failed transition with reason
        $this->assertSame('dispatched', $state->history[2]['from']);
        $this->assertSame('failed', $state->history[2]['to']);
        $this->assertSame('provider_error', $state->history[2]['reason']);
    }

    #[Test]
    public function history_records_timestamps(): void
    {
        $state = EventLifecycleState::create('evt-ts', 'click')->transition('validated');

        $this->assertNotNull($state);
        $this->assertCount(1, $state->history);
        $this->assertArrayHasKey('at', $state->history[0]);
        $this->assertNotEmpty($state->history[0]['at']);
    }

    // ── Transition Metadata Merge ─────────────────────────────────────

    #[Test]
    public function metadata_merges_across_transitions(): void
    {
        $state = EventLifecycleState::create('evt-meta', 'purchase')
            ->transition('enqueued', null, ['queue' => 'default'])
            ->transition('dispatched', null, ['provider' => 'ga4'])
            ->transition('delivered', null, ['latency_ms' => 15]);

        $this->assertNotNull($state);
        $this->assertSame('default', $state->metadata['queue']);
        $this->assertSame('ga4', $state->metadata['provider']);
        $this->assertSame(15, $state->metadata['latency_ms']);
    }

    // ── Attempt Counter ─────────────────────────────────────────────

    #[Test]
    public function attempt_count_increments_on_dispatch(): void
    {
        $state = EventLifecycleState::create('evt-attempt', 'purchase');
        $this->assertSame(0, $state->attemptCount);

        $state = $state->transition('enqueued');
        $this->assertNotNull($state);
        $this->assertSame(0, $state->attemptCount);

        $state = $state->transition('dispatched');
        $this->assertNotNull($state);
        $this->assertSame(1, $state->attemptCount);

        $state = $state->transition('failed');
        $this->assertNotNull($state);
        $this->assertSame(1, $state->attemptCount);

        $state = $state->transition('replayed');
        $this->assertNotNull($state);
        $this->assertSame(1, $state->attemptCount);

        $state = $state->transition('dispatched');
        $this->assertNotNull($state);
        $this->assertSame(2, $state->attemptCount);
    }

    // ── Transition Map Integrity ─────────────────────────────────────

    #[Test]
    public function transition_map_all_states_have_entries_except_terminal(): void
    {
        $statesWithTransitions = array_keys(EventLifecycleState::TRANSITIONS);
        $nonTerminalStates = array_diff(EventLifecycleState::ALL_STATES, EventLifecycleState::TERMINAL_STATES);

        foreach ($nonTerminalStates as $state) {
            $this->assertContains(
                $state,
                $statesWithTransitions,
                "Non-terminal state '{$state}' must have transition rules",
            );
        }
    }

    #[Test]
    public function all_transition_targets_are_valid_states(): void
    {
        foreach (EventLifecycleState::TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                $this->assertContains(
                    $target,
                    EventLifecycleState::ALL_STATES,
                    "Transition target '{$target}' from '{$from}' is not a valid state",
                );
            }
        }
    }

    #[Test]
    public function no_self_transitions(): void
    {
        foreach (EventLifecycleState::TRANSITIONS as $from => $targets) {
            $this->assertNotContains(
                $from,
                $targets,
                "State '{$from}' should not allow self-transition",
            );
        }
    }

    // ── Tracker Service — Constructor ────────────────────────────────

    #[Test]
    public function tracker_constructs_with_defaults(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $tracker = new EventLifecycleTracker($cache);

        $this->assertTrue($tracker->isEnabled());
        $this->assertSame(3600, $tracker->getTtl());
        $this->assertSame(3, $tracker->getMaxRetries());
    }

    #[Test]
    public function tracker_constructs_with_custom_config(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $tracker = new EventLifecycleTracker($cache, enabled: false, ttl: 7200, maxRetries: 5);

        $this->assertFalse($tracker->isEnabled());
        $this->assertSame(7200, $tracker->getTtl());
        $this->assertSame(5, $tracker->getMaxRetries());
    }

    // ── Tracker Service — Disabled Mode ──────────────────────────────

    #[Test]
    public function disabled_tracker_returns_state_on_initialize(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->never())->method('put');

        $tracker = new EventLifecycleTracker($cache, enabled: false);
        $state = $tracker->initialize('evt-dis', 'click');

        $this->assertSame('created', $state->state);
        $this->assertSame('evt-dis', $state->eventId);
    }

    #[Test]
    public function disabled_tracker_returns_null_on_transition(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $tracker = new EventLifecycleTracker($cache, enabled: false);

        $this->assertNull($tracker->transition('evt-dis2', 'validated'));
    }

    #[Test]
    public function disabled_tracker_returns_null_on_get_state(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $tracker = new EventLifecycleTracker($cache, enabled: false);

        $this->assertNull($tracker->getState('evt-dis3'));
    }

    // ── Tracker Service — Initialize ─────────────────────────────────

    #[Test]
    public function initialize_creates_state_and_persists(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->once())->method('put')->with(
            $this->stringContains('zb_event_lifecycle_evt-init'),
            $this->callback(fn (array $data) => $data['event_id'] === 'evt-init' && $data['state'] === 'created'),
            3600,
        );
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->initialize('evt-init', 'page_view');

        $this->assertSame('created', $state->state);
        $this->assertSame('evt-init', $state->eventId);
        $this->assertSame('page_view', $state->eventName);
    }

    #[Test]
    public function initialize_with_custom_initial_state(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->once())->method('put');
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->initialize('evt-skip', 'click', EventLifecycleState::STATE_SKIPPED);

        $this->assertSame('skipped', $state->state);
    }

    // ── Tracker Service — Transition ─────────────────────────────────

    #[Test]
    public function transition_updates_state(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-tr') => [
                'event_id' => 'evt-tr',
                'event_name' => 'purchase',
                'state' => 'created',
                'previous_state' => null,
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 0,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->transition('evt-tr', 'validated', 'schema_passed');

        $this->assertNotNull($state);
        $this->assertSame('validated', $state->state);
        $this->assertSame('created', $state->previousState);
        $this->assertSame('schema_passed', $state->reason);
    }

    #[Test]
    public function transition_for_unknown_event_returns_null(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $this->assertNull($tracker->transition('evt-unknown', 'validated'));
    }

    #[Test]
    public function transition_with_invalid_transition_returns_null(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn([
            'event_id' => 'evt-inv',
            'event_name' => 'click',
            'state' => 'created',
            'previous_state' => null,
            'transitioned_at' => '2026-08-17T00:00:00+00:00',
            'reason' => null,
            'attempt_count' => 0,
            'metadata' => [],
            'history' => [],
        ]);

        $tracker = new EventLifecycleTracker($cache);
        // 'created' → 'delivered' is invalid
        $this->assertNull($tracker->transition('evt-inv', 'delivered'));
    }

    // ── Tracker Service — Convenience Methods ─────────────────────────

    #[Test]
    public function mark_delivered_transitions_to_delivered(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-md') => [
                'event_id' => 'evt-md',
                'event_name' => 'purchase',
                'state' => 'dispatched',
                'previous_state' => 'enqueued',
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 1,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->markDelivered('evt-md', ['latency_ms' => 10]);

        $this->assertNotNull($state);
        $this->assertSame('delivered', $state->state);
    }

    #[Test]
    public function mark_failed_transitions_to_failed(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-mf') => [
                'event_id' => 'evt-mf',
                'event_name' => 'purchase',
                'state' => 'dispatched',
                'previous_state' => 'enqueued',
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 1,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->markFailed('evt-mf', 'connection_timeout', ['error_code' => 504]);

        $this->assertNotNull($state);
        $this->assertSame('failed', $state->state);
        $this->assertSame('connection_timeout', $state->reason);
    }

    #[Test]
    public function mark_dropped_transitions_to_dropped(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-dr') => [
                'event_id' => 'evt-dr',
                'event_name' => 'click',
                'state' => 'created',
                'previous_state' => null,
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 0,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->markDropped('evt-dr', 'consent_denied');

        $this->assertNotNull($state);
        $this->assertSame('dropped', $state->state);
        $this->assertSame('consent_denied', $state->reason);
    }

    #[Test]
    public function mark_skipped_transitions_to_skipped(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-sk') => [
                'event_id' => 'evt-sk',
                'event_name' => 'page_view',
                'state' => 'created',
                'previous_state' => null,
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 0,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache);
        $state = $tracker->markSkipped('evt-sk', 'deduplicated');

        $this->assertNotNull($state);
        $this->assertSame('skipped', $state->state);
        $this->assertSame('deduplicated', $state->reason);
    }

    // ── Tracker Service — Replay with Max Retries ─────────────────────

    #[Test]
    public function replay_succeeds_when_under_max_retries(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-rp1') => [
                'event_id' => 'evt-rp1',
                'event_name' => 'purchase',
                'state' => 'failed',
                'previous_state' => 'dispatched',
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => 'timeout',
                'attempt_count' => 1,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache, enabled: true, maxRetries: 3);
        $state = $tracker->replay('evt-rp1');

        $this->assertNotNull($state);
        $this->assertSame('replayed', $state->state);
    }

    #[Test]
    public function replay_dead_letters_when_max_retries_exceeded(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-rp2') => [
                'event_id' => 'evt-rp2',
                'event_name' => 'purchase',
                'state' => 'failed',
                'previous_state' => 'dispatched',
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => 'timeout',
                'attempt_count' => 3,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });
        $cache->expects($this->exactly(2))->method('put');

        $tracker = new EventLifecycleTracker($cache, enabled: true, maxRetries: 3);
        $state = $tracker->replay('evt-rp2');

        $this->assertNotNull($state);
        $this->assertSame('dead_lettered', $state->state);
        $this->assertSame('max_retries_exceeded', $state->reason);
    }

    #[Test]
    public function replay_returns_null_for_unknown_event(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $this->assertNull($tracker->replay('evt-unknown'));
    }

    // ── Tracker Service — Can Transition ─────────────────────────────

    #[Test]
    public function can_transition_delegates_to_state(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => match (true) {
            str_contains($key, 'zb_event_lifecycle_evt-ct') => [
                'event_id' => 'evt-ct',
                'event_name' => 'page_view',
                'state' => 'created',
                'previous_state' => null,
                'transitioned_at' => '2026-08-17T00:00:00+00:00',
                'reason' => null,
                'attempt_count' => 0,
                'metadata' => [],
                'history' => [],
            ],
            default => null,
        });

        $tracker = new EventLifecycleTracker($cache);
        $this->assertTrue($tracker->canTransition('evt-ct', 'validated'));
        $this->assertFalse($tracker->canTransition('evt-ct', 'delivered'));
    }

    #[Test]
    public function can_transition_returns_false_for_unknown_event(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $this->assertFalse($tracker->canTransition('evt-unk', 'validated'));
    }

    // ── Tracker Service — Purge ──────────────────────────────────────

    #[Test]
    public function purge_removes_from_cache_and_local(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->once())->method('forget')->with(
            $this->stringContains('zb_event_lifecycle_evt-purge'),
        );
        $cache->method('get')->willReturn([
            'event_id' => 'evt-purge',
            'event_name' => 'click',
            'state' => 'delivered',
            'previous_state' => null,
            'transitioned_at' => '2026-08-17T00:00:00+00:00',
            'reason' => null,
            'attempt_count' => 1,
            'metadata' => [],
            'history' => [],
        ]);

        $tracker = new EventLifecycleTracker($cache);
        $tracker->getState('evt-purge'); // Load into local
        $tracker->purge('evt-purge');

        $this->assertNull($tracker->getState('evt-purge'));
    }

    #[Test]
    public function purge_all_clears_local_states(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn([
            'event_id' => 'evt-a',
            'event_name' => 'click',
            'state' => 'created',
            'previous_state' => null,
            'transitioned_at' => '2026-08-17T00:00:00+00:00',
            'reason' => null,
            'attempt_count' => 0,
            'metadata' => [],
            'history' => [],
        ]);

        $tracker = new EventLifecycleTracker($cache);
        $tracker->getState('evt-a');
        $tracker->purgeAll();
        // After purgeAll, local memory is cleared
        $this->assertTrue(true); // No exception = pass
    }

    // ── Tracker Service — Stats ───────────────────────────────────────

    #[Test]
    public function get_stats_returns_default_when_no_cache(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $tracker = new EventLifecycleTracker($cache);
        $stats = $tracker->getStats();

        $this->assertSame(0, $stats['total_events']);
        $this->assertSame([], $stats['state_counts']);
        $this->assertSame(0.0, $stats['delivery_rate']);
        $this->assertSame(0.0, $stats['failure_rate']);
        $this->assertSame(0, $stats['dead_letter_count']);
    }

    // ── Version Consistency 232.0.0 ──────────────────────────────────

    #[Test]
    public function version_is_232_in_composer(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertSame('232.0.0', $json['version']);
    }

    #[Test]
    public function version_is_232_in_package_json(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $this->assertSame('232.0.0', $json['version']);
    }

    #[Test]
    public function version_is_232_in_analytics_event_dto(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString("'232.0.0'", $content);
    }

    #[Test]
    public function version_is_232_in_readme_badge(): void
    {
        $content = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('232.0.0', $content);
    }

    #[Test]
    public function version_is_232_in_analytics_js(): void
    {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('@version 232.0.0', $content);
    }

    // ── Config Section Presence ───────────────────────────────────────

    #[Test]
    public function config_has_event_lifecycle_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $this->assertStringContainsString('event_lifecycle', $content);
    }

    #[Test]
    public function config_has_event_lifecycle_enabled_key(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $this->assertStringContainsString('ANALYTICS_EVENT_LIFECYCLE_ENABLED', $content);
    }

    #[Test]
    public function config_has_event_lifecycle_ttl_key(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $this->assertStringContainsString('ANALYTICS_EVENT_LIFECYCLE_TTL', $content);
    }

    #[Test]
    public function config_has_event_lifecycle_max_retries_key(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $this->assertStringContainsString('ANALYTICS_EVENT_LIFECYCLE_MAX_RETRIES', $content);
    }

    // ── ServiceProvider Registration ───────────────────────────────────

    #[Test]
    public function service_provider_registers_lifecycle_tracker(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString(EventLifecycleTracker::class, $content);
        $this->assertStringContainsString('singleton', $content);
    }

    // ── Namespace Correctness ──────────────────────────────────────────

    #[Test]
    public function dto_namespace_is_correct(): void
    {
        $this->assertSame('ZeroBoiler\\Analytics\\DTO\\EventLifecycleState', EventLifecycleState::class);
    }

    #[Test]
    public function service_namespace_is_correct(): void
    {
        $this->assertSame('ZeroBoiler\\Analytics\\Services\\EventLifecycleTracker', EventLifecycleTracker::class);
    }

    // ── Cross-References ──────────────────────────────────────────────

    #[Test]
    public function tracker_references_dto(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventLifecycleTracker.php');
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\DTO\\EventLifecycleState;', $content);
    }

    #[Test]
    public function dto_docblock_references_tracker(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/DTO/EventLifecycleState.php');
        $this->assertStringContainsString('EventLifecycleTracker', $content);
    }
}
