<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Event Lifecycle State — formal state machine states for analytics events.
 *
 * Tracks each event through its complete lifecycle from creation to final
 * delivery or failure. Provides state transition validation and transition
 * history tracking for observability and debugging.
 *
 * Lifecycle: created → validated → enqueued → dispatched → delivered
 *                                                          ↘ failed → replayed → delivered
 *                                                          ↘ failed → dead_lettered
 *
 * @since 232.0.0
 */
final readonly class EventLifecycleState
{
    /** Event created, not yet validated. */
    public const STATE_CREATED = 'created';

    /** Event passed schema validation. */
    public const STATE_VALIDATED = 'validated';

    /** Event enqueued for async dispatch. */
    public const STATE_ENQUEUED = 'enqueued';

    /** Event dispatched to provider(s). */
    public const STATE_DISPATCHED = 'dispatched';

    /** Event successfully delivered to at least one provider. */
    public const STATE_DELIVERED = 'delivered';

    /** Event dispatch failed (provider error, network issue). */
    public const STATE_FAILED = 'failed';

    /** Event replayed after failure. */
    public const STATE_REPLAYED = 'replayed';

    /** Event moved to dead letter queue after max retries. */
    public const STATE_DEAD_LETTERED = 'dead_lettered';

    /** Event dropped (e.g., consent denied, budget exceeded). */
    public const STATE_DROPPED = 'dropped';

    /** Event skipped (e.g., deduplication, sampling). */
    public const STATE_SKIPPED = 'skipped';

    /**
     * Valid initial states for new events.
     *
     * @var array<string>
     */
    public const INITIAL_STATES = [
        self::STATE_CREATED,
        self::STATE_SKIPPED,
        self::STATE_DROPPED,
    ];

    /**
     * Valid terminal (final) states.
     *
     * @var array<string>
     */
    public const TERMINAL_STATES = [
        self::STATE_DELIVERED,
        self::STATE_DEAD_LETTERED,
        self::STATE_DROPPED,
        self::STATE_SKIPPED,
    ];

    /**
     * All valid states.
     *
     * @var array<string>
     */
    public const ALL_STATES = [
        self::STATE_CREATED,
        self::STATE_VALIDATED,
        self::STATE_ENQUEUED,
        self::STATE_DISPATCHED,
        self::STATE_DELIVERED,
        self::STATE_FAILED,
        self::STATE_REPLAYED,
        self::STATE_DEAD_LETTERED,
        self::STATE_DROPPED,
        self::STATE_SKIPPED,
    ];

    /**
     * State transition map — defines valid from → to transitions.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATE_CREATED    => [self::STATE_VALIDATED, self::STATE_ENQUEUED, self::STATE_DROPPED, self::STATE_SKIPPED],
        self::STATE_VALIDATED  => [self::STATE_ENQUEUED, self::STATE_DISPATCHED, self::STATE_DROPPED],
        self::STATE_ENQUEUED   => [self::STATE_DISPATCHED, self::STATE_FAILED, self::STATE_DROPPED],
        self::STATE_DISPATCHED  => [self::STATE_DELIVERED, self::STATE_FAILED],
        self::STATE_FAILED     => [self::STATE_REPLAYED, self::STATE_DEAD_LETTERED, self::STATE_ENQUEUED],
        self::STATE_REPLAYED   => [self::STATE_DISPATCHED, self::STATE_FAILED, self::STATE_ENQUEUED],
    ];

    /**
     * @param  string  $eventId  Unique event identifier (UUID)
     * @param  string  $eventName  Analytics event name (e.g., 'purchase')
     * @param  string  $state  Current lifecycle state
     * @param  string|null  $previousState  Previous state (null for initial)
     * @param  \DateTimeImmutable  $transitionedAt  Timestamp of last transition
     * @param  string|null  $reason  Human-readable reason for transition (e.g., 'provider_timeout')
     * @param  int  $attemptCount  Number of dispatch attempts
     * @param  array<string, mixed>  $metadata  Additional metadata (provider, error details)
     * @param  list<array{from: string, to: string, at: string, reason: string|null}>  $history  Transition history
     */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public string $state,
        public ?string $previousState = null,
        public \DateTimeImmutable $transitionedAt = new \DateTimeImmutable(),
        public ?string $reason = null,
        public int $attemptCount = 0,
        public array $metadata = [],
        public array $history = [],
    ) {}

    /**
     * Check if the transition from current state to target is valid.
     */
    public function canTransitionTo(string $targetState): bool
    {
        $allowed = self::TRANSITIONS[$this->state] ?? [];

        return in_array($targetState, $allowed, true);
    }

    /**
     * Check if this state is a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    /**
     * Check if this state is an initial state.
     */
    public function isInitial(): bool
    {
        return in_array($this->state, self::INITIAL_STATES, true);
    }

    /**
     * Create a new state with the given transition applied.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If transition is invalid
     */
    public function transition(string $toState, ?string $reason = null, array $metadata = []): self
    {
        if (! $this->canTransitionTo($toState)) {
            throw new \InvalidArgumentException(
                "Invalid transition from '{$this->state}' to '{$toState}' for event '{$this->eventName}'."
            );
        }

        return new self(
            eventId: $this->eventId,
            eventName: $this->eventName,
            state: $toState,
            previousState: $this->state,
            transitionedAt: new \DateTimeImmutable(),
            reason: $reason,
            attemptCount: $toState === self::STATE_DISPATCHED ? $this->attemptCount + 1 : $this->attemptCount,
            metadata: array_merge($this->metadata, $metadata),
            history: [
                ...$this->history,
                [
                    'from' => $this->state,
                    'to' => $toState,
                    'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'reason' => $reason,
                ],
            ],
        );
    }

    /**
     * Create an initial state for a new event.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $eventName  Analytics event name
     * @param  string  $initialState  Initial state (default: created)
     * @return self
     */
    public static function create(
        string $eventId,
        string $eventName,
        string $initialState = self::STATE_CREATED,
    ): self {
        if (! in_array($initialState, self::ALL_STATES, true)) {
            throw new \InvalidArgumentException("Unknown state: '{$initialState}'");
        }

        return new self(
            eventId: $eventId,
            eventName: $eventName,
            state: $initialState,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'state' => $this->state,
            'previous_state' => $this->previousState,
            'transitioned_at' => $this->transitionedAt->format(\DateTimeInterface::ATOM),
            'reason' => $this->reason,
            'attempt_count' => $this->attemptCount,
            'is_terminal' => $this->isTerminal(),
            'is_initial' => $this->isInitial(),
            'metadata' => $this->metadata,
            'history_count' => count($this->history),
            'history' => $this->history,
        ];
    }

    /**
     * Create from array representation.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            eventName: is_string($data['event_name'] ?? null) ? $data['event_name'] : '',
            state: is_string($data['state'] ?? null) ? $data['state'] : self::STATE_CREATED,
            previousState: is_string($data['previous_state'] ?? null) ? $data['previous_state'] : null,
            transitionedAt: isset($data['transitioned_at'])
                ? new \DateTimeImmutable(is_string($data['transitioned_at']) ? $data['transitioned_at'] : 'now')
                : new \DateTimeImmutable(),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
            attemptCount: is_int($data['attempt_count'] ?? null) ? $data['attempt_count'] : 0,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            history: is_array($data['history'] ?? null) ? $data['history'] : [],
        );
    }

    /**
     * Get all valid target states for the current state.
     *
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->state] ?? [];
    }
}
