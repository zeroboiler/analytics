<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the result of an event payload marshalling operation.
 *
 * The marshaller takes raw request data (array), applies coercion, validation,
 * default population, and enrichment, producing this structured result with
 * the assembled payload, any warnings, and extracted metadata.
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\Services\EventPayloadMarshallerService
 */
final readonly class MarshalledPayload
{
    /**
     * @param  bool  $valid  Whether marshalling produced a valid payload
     * @param  array<string, mixed>  $payload  The assembled, coerced, and validated payload
     * @param  list<array{field: string, message: string, severity: 'info'|'warning'|'error'}>  $messages  Warnings and errors from marshalling
     * @param  list<string>  $coercedFields  Fields that were type-coerced during marshalling
     * @param  list<string>  $missingRequired  Required fields that were missing (and not defaulted)
     * @param  list<string>  $unknownFields  Fields not in the schema that were preserved or stripped
     * @param  string  $eventName  Resolved canonical event name
     * @param  string  $schemaVersion  Schema version used for marshalling
     * @param  string  $marshalledAt  ISO-8601 timestamp
     */
    public function __construct(
        public bool $valid,
        public array $payload,
        public array $messages,
        public array $coercedFields,
        public array $missingRequired,
        public array $unknownFields,
        public string $eventName,
        public string $schemaVersion,
        public string $marshalledAt,
    ): void  {}

    /**
     * Create a successful marshalled payload.
     *
     * @param  array<string, mixed>  $payload  The assembled payload
     * @param  list<array{field: string, message: string, severity: 'info'|'warning'|'error'}>  $messages
     * @param  list<string>  $coercedFields
     * @param  list<string>  $unknownFields
     * @param  string  $eventName
     * @param  string  $schemaVersion
     */
    public static function success(
        array $payload,
        array $messages = [],
        array $coercedFields = [],
        array $unknownFields = [],
        string $eventName = '',
        string $schemaVersion = '1.0.0',
    ): self {
        return new self(
            valid: empty(array_filter($messages, static fn (array $m): bool => $m['severity'] === 'error')),
            payload: $payload,
            messages: $messages,
            coercedFields: $coercedFields,
            missingRequired: [],
            unknownFields: $unknownFields,
            eventName: $eventName,
            schemaVersion: $schemaVersion,
            marshalledAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Create a failed marshalled payload.
     *
     * @param  list<string>  $missingRequired  Required fields that were missing
     * @param  list<array{field: string, message: string, severity: 'info'|'warning'|'error'}>  $messages
     * @param  array<string, mixed>  $partialPayload  Any payload data assembled before failure
     * @param  string  $eventName
     */
    public static function failure(
        array $missingRequired,
        array $messages = [],
        array $partialPayload = [],
        string $eventName = '',
    ): self {
        return new self(
            valid: false,
            payload: $partialPayload,
            messages: $messages,
            coercedFields: [],
            missingRequired: $missingRequired,
            unknownFields: [],
            eventName: $eventName,
            schemaVersion: '1.0.0',
            marshalledAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Serialize to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'payload' => $this->payload,
            'messages' => $this->messages,
            'coerced_fields' => $this->coercedFields,
            'missing_required' => $this->missingRequired,
            'unknown_fields' => $this->unknownFields,
            'event_name' => $this->eventName,
            'schema_version' => $this->schemaVersion,
            'marshalled_at' => $this->marshalledAt,
        ];
    }
}
