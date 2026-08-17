<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\EventLifecycleState;

/**
 * Event Lifecycle Tracker — manages formal state machine transitions for analytics events.
 *
 * Provides a centralized service for tracking each analytics event through its
 * complete lifecycle: creation → validation → enqueue → dispatch → delivery.
 * Enforces state transition rules, records transition history, and provides
 * observability into event processing pipelines.
 *
 * Features:
 * - State machine enforcement with validated transitions
 * - Cache-backed state storage (TTL-configurable)
 * - Transition history for debugging and audit trails
 * - Aggregate statistics (state counts, success rate, failure rate)
 * - Dead letter tracking with failure reason capture
 * - Config-driven TTL and retention settings
 *
 * Inspired by AWS EventBridge state machines, Temporal workflows, and
 * Segment's event delivery tracking.
 *
 * Configuration: `zeroboiler.analytics.event_lifecycle`
 *
 * @see \ZeroBoiler\Analytics\DTO\EventLifecycleState
 *
 * @since 232.0.0
 */
final class EventLifecycleTracker
{
    /** Cache key prefix for lifecycle states. */
    private const CACHE_PREFIX = 'zb_event_lifecycle_';

    /** Cache key for aggregate stats. */
    private const STATS_CACHE_KEY = 'zb_event_lifecycle_stats';

    /** Maximum number of dead letter entries to track in cache. */
    private const MAX_DEAD_LETTER_CACHE = 100;

    private bool $enabled;

    private int $ttl;

    private int $statsTtl;

    private int $maxRetries;

    /** @var array<string, EventLifecycleState> */
    private array $localStates = [];

    public function __construct(
        private readonly CacheRepository $cache,
        bool $enabled = true,
        int $ttl = 3600,
        int $statsTtl = 300,
        int $maxRetries = 3,
    ) {
        $this->enabled = $enabled;
        $this->ttl = $ttl;
        $this->statsTtl = $statsTtl;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Initialize a new event lifecycle.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $eventName  Analytics event name
     * @param  string  $initialState  Initial lifecycle state
     * @return EventLifecycleState
     */
    public function initialize(
        string $eventId,
        string $eventName,
        string $initialState = EventLifecycleState::STATE_CREATED,
    ): EventLifecycleState {
        if (! $this->enabled) {
            return EventLifecycleState::create($eventId, $eventName, $initialState);
        }

        $state = EventLifecycleState::create($eventId, $eventName, $initialState);

        $this->persistState($eventId, $state);
        $this->incrementStateCount($state->state);

        Log::debug('ZeroBoiler: Event lifecycle initialized', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'state' => $initialState,
        ]);

        return $state;
    }

    /**
     * Transition an event to a new state.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $toState  Target lifecycle state
     * @param  string|null  $reason  Transition reason
     * @param  array<string, mixed>  $metadata  Additional metadata
     * @return EventLifecycleState|null  New state, or null if not found
     */
    public function transition(
        string $eventId,
        string $toState,
        ?string $reason = null,
        array $metadata = [],
    ): ?EventLifecycleState {
        if (! $this->enabled) {
            return null;
        }

        $currentState = $this->getState($eventId);

        if ($currentState === null) {
            Log::warning('ZeroBoiler: Lifecycle transition attempted for unknown event', [
                'event_id' => $eventId,
                'target_state' => $toState,
            ]);

            return null;
        }

        try {
            $newState = $currentState->transition($toState, $reason, $metadata);
        } catch (\InvalidArgumentException $e) {
            Log::warning('ZeroBoiler: Invalid lifecycle transition', [
                'event_id' => $eventId,
                'from' => $currentState->state,
                'to' => $toState,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->persistState($eventId, $newState);
        $this->incrementStateCount($newState->state);

        Log::debug('ZeroBoiler: Event lifecycle transition', [
            'event_id' => $eventId,
            'event_name' => $newState->eventName,
            'from' => $currentState->state,
            'to' => $toState,
            'attempt' => $newState->attemptCount,
        ]);

        return $newState;
    }

    /**
     * Get the current lifecycle state for an event.
     *
     * @param  string  $eventId  Unique event identifier
     * @return EventLifecycleState|null
     */
    public function getState(string $eventId): ?EventLifecycleState
    {
        if (isset($this->localStates[$eventId])) {
            return $this->localStates[$eventId];
        }

        if (! $this->enabled) {
            return null;
        }

        $cached = $this->cache->get(self::CACHE_PREFIX . $eventId);

        if (! is_array($cached)) {
            return null;
        }

        $state = EventLifecycleState::fromArray($cached);
        $this->localStates[$eventId] = $state;

        return $state;
    }

    /**
     * Check if an event can transition to a target state.
     */
    public function canTransition(string $eventId, string $toState): bool
    {
        $state = $this->getState($eventId);

        if ($state === null) {
            return false;
        }

        return $state->canTransitionTo($toState);
    }

    /**
     * Mark an event as successfully delivered.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  array<string, mixed>  $metadata  Delivery metadata (provider, latency_ms)
     */
    public function markDelivered(string $eventId, array $metadata = []): ?EventLifecycleState
    {
        return $this->transition($eventId, EventLifecycleState::STATE_DELIVERED, 'delivered', $metadata);
    }

    /**
     * Mark an event as failed with reason.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $reason  Failure reason
     * @param  array<string, mixed>  $metadata  Error metadata
     */
    public function markFailed(string $eventId, string $reason, array $metadata = []): ?EventLifecycleState
    {
        return $this->transition($eventId, EventLifecycleState::STATE_FAILED, $reason, $metadata);
    }

    /**
     * Attempt replay of a failed event. Returns null if max retries exceeded.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string|null  $reason  Replay reason
     */
    public function replay(string $eventId, ?string $reason = null): ?EventLifecycleState
    {
        $state = $this->getState($eventId);

        if ($state === null) {
            return null;
        }

        $attemptCount = $state->attemptCount;

        if ($attemptCount >= $this->maxRetries) {
            return $this->transition($eventId, EventLifecycleState::STATE_DEAD_LETTERED, 'max_retries_exceeded', [
                'attempt_count' => $attemptCount,
                'max_retries' => $this->maxRetries,
            ]);
        }

        return $this->transition($eventId, EventLifecycleState::STATE_REPLAYED, $reason ?? 'auto_replay');
    }

    /**
     * Mark an event as dropped.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $reason  Drop reason (e.g., 'consent_denied', 'budget_exceeded')
     */
    public function markDropped(string $eventId, string $reason): ?EventLifecycleState
    {
        return $this->transition($eventId, EventLifecycleState::STATE_DROPPED, $reason);
    }

    /**
     * Mark an event as skipped.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $reason  Skip reason (e.g., 'deduplicated', 'sampled_out')
     */
    public function markSkipped(string $eventId, string $reason): ?EventLifecycleState
    {
        return $this->transition($eventId, EventLifecycleState::STATE_SKIPPED, $reason);
    }

    /**
     * Get aggregate lifecycle statistics.
     *
     * @return array{total_events: int, state_counts: array<string, int>, delivery_rate: float, failure_rate: float, dead_letter_count: int}
     */
    public function getStats(): array
    {
        $cached = $this->cache->get(self::STATS_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        return [
            'total_events' => 0,
            'state_counts' => [],
            'delivery_rate' => 0.0,
            'failure_rate' => 0.0,
            'dead_letter_count' => 0,
        ];
    }

    /**
     * Purge lifecycle state for a specific event.
     */
    public function purge(string $eventId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $eventId);
        unset($this->localStates[$eventId]);
    }

    /**
     * Purge all lifecycle states from local memory.
     */
    public function purgeAll(): void
    {
        $this->localStates = [];
    }

    /**
     * Check if lifecycle tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get configured TTL.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Get configured max retries.
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Persist a lifecycle state to cache.
     */
    private function persistState(string $eventId, EventLifecycleState $state): void
    {
        $this->localStates[$eventId] = $state;
        $this->cache->put(
            self::CACHE_PREFIX . $eventId,
            $state->toArray(),
            $this->ttl,
        );
    }

    /**
     * Increment state count in aggregate stats.
     */
    private function incrementStateCount(string $state): void
    {
        $stats = $this->getStats();
        $stats['state_counts'][$state] = ($stats['state_counts'][$state] ?? 0) + 1;
        $stats['total_events'] = max(1, array_sum($stats['state_counts']));

        $delivered = $stats['state_counts'][EventLifecycleState::STATE_DELIVERED] ?? 0;
        $failed = $stats['state_counts'][EventLifecycleState::STATE_FAILED] ?? 0;
        $deadLettered = $stats['state_counts'][EventLifecycleState::STATE_DEAD_LETTERED] ?? 0;

        $stats['delivery_rate'] = $stats['total_events'] > 0
            ? round($delivered / $stats['total_events'], 4)
            : 0.0;
        $stats['failure_rate'] = $stats['total_events'] > 0
            ? round(($failed + $deadLettered) / $stats['total_events'], 4)
            : 0.0;
        $stats['dead_letter_count'] = $deadLettered;

        $this->cache->put(self::STATS_CACHE_KEY, $stats, $this->statsTtl);
    }
}
