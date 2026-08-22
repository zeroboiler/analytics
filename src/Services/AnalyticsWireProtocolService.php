<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics Wire Protocol — Serializes analytics events into a compact,
 * self-describing wire format for cross-service, cross-process, and
 * cross-language transmission.
 *
 * The wire format is a JSON envelope with metadata, event data, and
 * an optional schema reference. Designed for:
 * - Event forwarding to external services
 * - Cross-microservice event propagation
 * - Event archival and replay
 * - Event bus integration (Redis, Kafka, SQS)
 *
 * Wire envelope structure:
 * ```json
 * {
 *   "protocol": "zb_analytics/1.0",
 *   "version": "41.0.0",
 *   "timestamp": "2026-08-12T10:00:00.000000Z",
 *   "event": {
 *     "name": "purchase",
 *     "params": {...},
 *     "client_id": "...",
 *     "user_id": "...",
 *     "priority": "critical",
 *     "source": "server"
 *   },
 *   "metadata": {
 *     "schema_version": null,
 *     "context_label": null,
 *     "correlation_id": "...",
 *     "serialized_at": 1234567890.123
 *   }
 * }
 * ```
 *
 * @since 41.0.0
 */
final class AnalyticsWireProtocol
{
    /** @var string Wire protocol identifier */
    private const PROTOCOL = 'zb_analytics/1.0';

    /** @var string Package version for envelope versioning */
    private string $sdkVersion;

    /**
     * @param  string|null  $sdkVersion  Override SDK version (defaults to AnalyticsEvent::VERSION)
     */
    public function __construct(?string $sdkVersion = null){
        $this->sdkVersion = $sdkVersion ?? AnalyticsEvent::VERSION;
    }

    /**
     * Serialize a single analytics event into wire format.
     *
     * @param  AnalyticsEvent  $event  The event to serialize
     * @param  array<string, mixed>  $metadata  Additional metadata (context_label, correlation_id, etc.)
     * @return string JSON-encoded wire envelope
     */
    public function serialize(AnalyticsEvent $event, array $metadata = []): string
    {
        $envelope = $this->buildEnvelope($event, $metadata);

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Serialize multiple events into a batch wire format.
     *
     * @param  list<AnalyticsEvent>  $events  Events to serialize
     * @param  array<string, mixed>  $metadata  Shared metadata for all events
     * @return string JSON-encoded wire envelope with event array
     */
    public function serializeBatch(array $events, array $metadata = []): string
    {
        $correlationId = $metadata['correlation_id'] ?? \Illuminate\Support\Str::uuid()->toString();

        $envelope = [
            'protocol' => self::PROTOCOL,
            'version' => $this->sdkVersion,
            'timestamp' => $this->isoNow(),
            'batch' => true,
            'count' => count($events),
            'correlation_id' => $correlationId,
            'events' => array_map(
                fn (AnalyticsEvent $event): array => $this->serializeEventToArray($event),
                $events,
            ),
            'metadata' => array_merge([
                'serialized_at' => microtime(true),
            ], $metadata),
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Deserialize a wire envelope back into an AnalyticsEvent.
     *
     * @param  string  $payload  JSON-encoded wire envelope
     * @return AnalyticsEvent The reconstructed event
     *
     * @throws \InvalidArgumentException If the payload is malformed or not a valid wire envelope
     */
    public function deserialize(string $payload): AnalyticsEvent
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException('Wire payload must be a JSON object');
        }

        // Batch envelope — extract first event
        if (isset($data['batch']) && $data['batch'] === true && isset($data['events'][0])) {
            return $this->arrayToEvent($data['events'][0]);
        }

        // Single event envelope
        if (isset($data['event'])) {
            return $this->arrayToEvent($data['event']);
        }

        throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException(
            'Wire payload missing "event" or "events" field. Got keys: ' .
            implode(', ', array_keys($data)),
        );
    }

    /**
     * Deserialize a batch wire envelope into an array of AnalyticsEvents.
     *
     * @param  string  $payload  JSON-encoded wire envelope
     * @return list<AnalyticsEvent>
     *
     * @throws \InvalidArgumentException If the payload is malformed
     */
    public function deserializeBatch(string $payload): array
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException('Wire payload must be a JSON object');
        }

        // Batch envelope
        if (isset($data['batch']) && $data['batch'] === true && isset($data['events'])) {
            if (! is_array($data['events'])) {
                throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException('Wire batch "events" must be an array');
            }

            return array_map(
                fn (array $eventData): AnalyticsEvent => $this->arrayToEvent($eventData),
                $data['events'],
            );
        }

        // Single event wrapped as batch
        if (isset($data['event'])) {
            return [$this->arrayToEvent($data['event'])];
        }

        throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException(
            'Wire payload missing "event" or "events" field',
        );
    }

    /**
     * Validate a wire envelope without deserializing.
     *
     * @param  string  $payload  JSON-encoded wire envelope
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, event_count: int}
     */
    public function validate(string $payload): array
    {
        $errors = [];
        $warnings = [];
        $eventCount = 0;

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'valid' => false,
                'errors' => ["Invalid JSON: {$e->getMessage()}"],
                'warnings' => [],
                'event_count' => 0,
            ];
        }

        if (! is_array($data)) {
            return [
                'valid' => false,
                'errors' => ['Payload must be a JSON object'],
                'warnings' => [],
                'event_count' => 0,
            ];
        }

        // Check protocol
        if (! isset($data['protocol']) || $data['protocol'] !== self::PROTOCOL) {
            $warnings[] = "Unexpected protocol: " . ($data['protocol'] ?? 'missing') .
                " (expected: " . self::PROTOCOL . ')';
        }

        // Check version
        if (! isset($data['version'])) {
            $warnings[] = 'Missing SDK version';
        }

        // Check event data
        if (isset($data['event'])) {
            $eventCount = 1;
            $eventErrors = $this->validateEventData($data['event']);
            $errors = array_merge($errors, $eventErrors);
        } elseif (isset($data['events']) && is_array($data['events'])) {
            $eventCount = count($data['events']);
            foreach ($data['events'] as $i => $eventData) {
                $eventErrors = $this->validateEventData($eventData, $i);
                $errors = array_merge($errors, $eventErrors);
            }
        } else {
            $errors[] = 'Missing "event" or "events" field';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'event_count' => $eventCount,
        ];
    }

    /**
     * Get the wire protocol identifier.
     */
    public function getProtocol(): string
    {
        return self::PROTOCOL;
    }

    /**
     * Get the SDK version used for serialization.
     */
    public function getVersion(): string
    {
        return $this->sdkVersion;
    }

    /**
     * Build a wire envelope array for a single event.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildEnvelope(AnalyticsEvent $event, array $metadata): array
    {
        $correlationId = $metadata['correlation_id'] ?? \Illuminate\Support\Str::uuid()->toString();

        return [
            'protocol' => self::PROTOCOL,
            'version' => $this->sdkVersion,
            'timestamp' => $this->isoNow(),
            'event' => $this->serializeEventToArray($event),
            'correlation_id' => $correlationId,
            'metadata' => array_merge([
                'serialized_at' => microtime(true),
            ], $metadata),
        ];
    }

    /**
     * Convert an AnalyticsEvent to an array suitable for wire format.
     *
     * @return array<string, mixed>
     */
    private function serializeEventToArray(AnalyticsEvent $event): array
    {
        return [
            'name' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'priority' => $event->priority,
            'source' => $event->source,
        ];
    }

    /**
     * Convert a wire event array back to an AnalyticsEvent.
     *
     * @param  array<string, mixed>  $data
     * @return AnalyticsEvent
     */
    private function arrayToEvent(array $data): AnalyticsEvent
    {
        $timestamp = null;

        if (isset($data['timestamp']) && is_string($data['timestamp'])) {
            try {
                $timestamp = new \DateTimeImmutable($data['timestamp']);
            } catch (\Throwable $e) {
                // Invalid timestamp — use default (null = now)
            }
        }

        return new AnalyticsEvent(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : null,
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : null,
            timestamp: $timestamp,
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : null,
            source: is_string($data['source'] ?? null) ? $data['source'] : null,
        );
    }

    /**
     * Validate a single event data structure within a wire envelope.
     *
     * @param  array<string, mixed>  $eventData
     * @param  int|null  $index  Batch index (for error messages)
     * @return list<string>
     */
    private function validateEventData(array $eventData, ?int $index = null): array
    {
        $errors = [];
        $prefix = $index !== null ? "events[{$index}]." : 'event.';

        if (! isset($eventData['name']) || ! is_string($eventData['name'])) {
            $errors[] = "{$prefix}name must be a non-empty string";
        } elseif ($eventData['name'] === '') {
            $errors[] = "{$prefix}name must not be empty";
        }

        if (isset($eventData['params']) && ! is_array($eventData['params'])) {
            $errors[] = "{$prefix}params must be an array or null";
        }

        return $errors;
    }

    /**
     * Get current time in ISO 8601 format.
     */
    private function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);
    }
}
