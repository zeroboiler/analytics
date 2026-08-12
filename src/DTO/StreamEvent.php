<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Represents a stream-processed event with sequencing metadata.
 *
 * Extends the core event data with stream-specific context: sequence position,
 * time-since-previous event, session sequence ID, and derived stream annotations.
 * Used internally by EventStreamProcessorService.
 *
 * @since 31.0.0
 */
final class StreamEvent
{
    /**
     * @param  string  $id  Unique stream event identifier
     * @param  string  $eventName  The analytics event name
     * @param  string|null  $clientId  Client/tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  int  $position  Position in the client's event sequence (1-indexed)
     * @param  int  $timestamp  Unix timestamp when the event occurred
     * @param  float|null  $timeSincePrevious  Seconds since the previous event for this client
     * @param  string|null  $sessionSequenceId  Session-specific sequence identifier
     * @param  array<string, mixed>  $params  Original event parameters
     * @param  string|null  $category  Event catalog category
     */
    public function __construct(
        public readonly string $id,
        public readonly string $eventName,
        public readonly ?string $clientId,
        public readonly ?string $userId,
        public readonly int $position,
        public readonly int $timestamp,
        public readonly ?float $timeSincePrevious,
        public readonly ?string $sessionSequenceId,
        public readonly array $params,
        public readonly ?string $category,
    ): void {}

    /**
     * Generate a stable ID from event components.
     *
     * @param  string  $eventName
     * @param  string|null  $clientId
     * @param  int  $timestamp
     * @param  int  $position
     * @return non-empty-string
     */
    public static function generateId(string $eventName, ?string $clientId, int $timestamp, int $position): string
    {
        return hash('xxh128', implode(':', [
            $eventName,
            $clientId ?? 'anon',
            (string) $timestamp,
            (string) $position,
        ]));
    }

    /**
     * Serialize to array for storage/transmission.
     *
     * @return array{id: string, event_name: string, client_id: string|null, user_id: string|null, position: int, timestamp: int, time_since_previous: float|null, session_sequence_id: string|null, params: array<string, mixed>, category: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_name' => $this->eventName,
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'position' => $this->position,
            'timestamp' => $this->timestamp,
            'time_since_previous' => $this->timeSincePrevious !== null
                ? round($this->timeSincePrevious, 3)
                : null,
            'session_sequence_id' => $this->sessionSequenceId,
            'params' => $this->params,
            'category' => $this->category,
        ];
    }
}
