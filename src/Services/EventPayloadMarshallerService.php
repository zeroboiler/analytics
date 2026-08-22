<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\DTO\MarshalledPayload;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Event Payload Marshaller — unified request-to-DTO assembly pipeline.
 *
 * Takes raw event data (array from HTTP request, queue payload, or manual input)
 * and produces a validated, type-coerced MarshalledPayload DTO. The marshaller
 * performs four operations in sequence:
 *
 * 1. **Schema Lookup** — Resolves the event name to its schema definition
 * 2. **Field Coercion** — Converts values to expected types (string→int, etc.)
 * 3. **Validation** — Checks required fields, type constraints, and value ranges
 * 4. **Default Population** — Fills missing optional fields with configured defaults
 *
 * Handles:
 * - Single event marshalling (API, queue)
 * - Batch event marshalling (bulk API)
 * - Schema-less events (permissive passthrough with warnings)
 * - E-commerce event item array normalization
 * - Identity field extraction (client_id, user_id)
 * - PII field detection (warning-level, not blocking)
 *
 * Inspired by Segment's Event Protocol, mParticle's Batch API, and PostHog's
 * event ingestion pipeline.
 *
 * Configuration: `zeroboiler.analytics.marshaller`
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\DTO\MarshalledPayload
 * @see \ZeroBoiler\Analytics\Services\EventFieldCoercer
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 */
final class EventPayloadMarshallerService
{
    /** @var string Reserved identity fields that are extracted from params */
    private const IDENTITY_FIELDS = ['client_id', 'user_id', 'anonymous_id', 'session_id'];

    /**
     * Common PII field patterns to detect and warn about.
     *
     * @var list<string>
     */
    private const PII_FIELD_PATTERNS = [
        'email', 'phone', 'ssn', 'social_security', 'credit_card',
        'password', 'ip_address', 'address', 'postal_code',
        'first_name', 'last_name', 'full_name', 'date_of_birth',
    ];

    private EventSchemaRegistry $schemaRegistry;

    private EventFieldCoercer $coercer;

    private bool $strictMode;

    private bool $stripUnknownFields;

    private bool $detectPii;

    private bool $populateDefaults;

    /** @var array<string, mixed> Global defaults applied to all events */
    private array $globalDefaults;

    /**
     * @param  EventSchemaRegistry  $schemaRegistry  Schema registry for event definitions
     * @param  EventFieldCoercer  $coercer  Type coercion service
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        EventSchemaRegistry $schemaRegistry,
        EventFieldCoercer $coercer,
        ConfigRepository $config,
    ) {
        $this->schemaRegistry = $schemaRegistry;
        $this->coercer = $coercer;
        $marshallerConfig = $config->get('zeroboiler.analytics.marshaller', []);
        /** @var array{strict?: bool, strip_unknown?: bool, detect_pii?: bool, populate_defaults?: bool, global_defaults?: array<string, mixed>} $marshallerConfig */
        $this->strictMode = $marshallerConfig['strict'] ?? false;
        $this->stripUnknownFields = $marshallerConfig['strip_unknown'] ?? true;
        $this->detectPii = $marshallerConfig['detect_pii'] ?? true;
        $this->populateDefaults = $marshallerConfig['populate_defaults'] ?? true;
        $this->globalDefaults = $marshallerConfig['global_defaults'] ?? [];
    }

    /**
     * Marshal a single event payload from raw data.
     *
     * Takes an associative array (typically from a JSON API request body),
     * resolves the event schema, coerces types, validates fields, populates
     * defaults, and returns a MarshalledPayload DTO.
     *
     * @param  string  $eventName  Canonical event name
     * @param  array<string, mixed>  $rawPayload  Raw event data
     * @param  array<string, mixed>  $context  Additional context (client_id, user_id, ip, etc.)
     * @return MarshalledPayload Result DTO with payload, messages, and metadata
     */
    public function marshal(string $eventName, array $rawPayload, array $context = []): MarshalledPayload
    {
        $messages = [];
        $coercedFields = [];
        $missingRequired = [];
        $unknownFields = [];

        $identityFields = $this->extractIdentityFields($rawPayload, $context);

        // Lookup schema
        $schema = $this->schemaRegistry->get($eventName);

        $payload = $rawPayload;

        if ($this->populateDefaults && ! empty($this->globalDefaults)) {
            foreach ($this->globalDefaults as $field => $defaultValue) {
                if (! array_key_exists($field, $payload)) {
                    $payload[$field] = $defaultValue;
                }
            }
        }

        if ($schema !== null) {
            // Schema-driven marshalling
            $payload = $this->coerceFields($payload, $schema, $coercedFields, $messages);
            $missingRequired = $this->validateFields($payload, $schema, $messages);

            if ($this->stripUnknownFields) {
                $payload = $this->stripUnknown($payload, $schema, $unknownFields);
            }
        } else {
            // Schema-less permissive pass-through
            $messages[] = [
                'field' => '_schema',
                'message' => "No schema found for event '{$eventName}' — accepting all fields (permissive mode)",
                'severity' => 'info',
            ];
        }

        foreach ($identityFields as $field => $value) {
            if ($value !== null && ! isset($payload[$field])) {
                $payload[$field] = $value;
            }
        }

        // PII detection
        if ($this->detectPii) {
            $this->detectPiiFields($payload, $messages);
        }

        $hasErrors = ! empty(
            array_filter($messages, static fn (array $m): bool => $m['severity'] === 'error')
        );

        return new MarshalledPayload(
            valid: ! $hasErrors && empty($missingRequired),
            payload: $payload,
            messages: $messages,
            coercedFields: $coercedFields,
            missingRequired: $missingRequired,
            unknownFields: $unknownFields,
            eventName: $eventName,
            schemaVersion: $schema !== null ? '1.0.0' : 'none',
            marshalledAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Marshal multiple events in batch.
     *
     * Each event is marshalled independently. Returns an array of results
     * with the same indices as the input events.
     *
     * @param  list<array{event?: string, name?: string, properties?: array<string, mixed>, params?: array<string, mixed>, context?: array<string, mixed>}>  $events  Batch of events
     * @return array{results: list<MarshalledPayload>, valid_count: int, invalid_count: int, total: int}
     */
    public function marshalBatch(array $events): array
    {
        $results = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($events as $eventData) {
            $eventName = $eventData['event'] ?? $eventData['name'] ?? 'unknown';
            $properties = $eventData['properties'] ?? $eventData['params'] ?? $eventData;
            $context = $eventData['context'] ?? [];

            unset($properties['event'], $properties['name'], $properties['context']);

            $result = $this->marshal($eventName, $properties, $context);
            $results[] = $result;

            if ($result->valid) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        return [
            'results' => $results,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'total' => count($events),
        ];
    }

    /**
     * Get the marshalling configuration summary.
     *
     * @return array{strict: bool, strip_unknown: bool, detect_pii: bool, populate_defaults: bool, global_defaults_count: int}
     */
    public function getConfig(): array
    {
        return [
            'strict' => $this->strictMode,
            'strip_unknown' => $this->stripUnknownFields,
            'detect_pii' => $this->detectPii,
            'populate_defaults' => $this->populateDefaults,
            'global_defaults_count' => count($this->globalDefaults),
        ];
    }

    /**
     * Extract identity fields from raw payload and context.
     *
     * Identity fields are extracted so they can be handled separately
     * from event parameters.
     *
     * @param  array<string, mixed>  $rawPayload  Raw event payload
     * @param  array<string, mixed>  $context  Additional context
     * @return array<string, mixed> Extracted identity fields (client_id, user_id, etc.)
     */
    private function extractIdentityFields(array $rawPayload, array $context): array
    {
        $identity = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            if (isset($context[$field])) {
                $identity[$field] = $context[$field];
            } elseif (isset($rawPayload[$field])) {
                $identity[$field] = $rawPayload[$field];
            }
        }

        return $identity;
    }

    /**
     * Coerce field types based on schema definitions.
     *
     * Iterates over the payload and coerces values to match the expected
     * types defined in the EventSchema's EventParam definitions.
     *
     * @param  array<string, mixed>  $payload  Current payload
     * @param  EventSchema  $schema  Event schema
     * @param  list<string>  $coercedFields  [ref] List of fields that were coerced
     * @param  list<array{field: string, message: string, severity: string}>  $messages  [ref] Warning/error messages
     * @return array<string, mixed> Payload with coerced fields
     */
    private function coerceFields(array $payload, EventSchema $schema, array &$coercedFields, array &$messages): array
    {
        $allParams = array_merge($schema->requiredParams, $schema->optionalParams);

        foreach ($payload as $field => $value) {
            if ($value === null || ! isset($allParams[$field])) {
                continue;
            }

            $param = $allParams[$field];
            $expectedType = $param->type;
            $actualType = get_debug_type($value);

            if ($this->typesMatch($actualType, $expectedType)) {
                continue;
            }

            $coerced = $this->coercer->coerce($value, $expectedType);

            if ($coerced !== $value) {
                $payload[$field] = $coerced;
                $coercedFields[] = $field;

                $messages[] = [
                    'field' => $field,
                    'message' => "Coerced '{$field}' from {$actualType} to {$expectedType}",
                    'severity' => 'info',
                ];
            }
        }

        return $payload;
    }

    /**
     * Validate fields against schema requirements.
     *
     * Checks all required params are present and validates types.
     *
     * @param  array<string, mixed>  $payload  Current payload
     * @param  EventSchema  $schema  Event schema
     * @param  list<array{field: string, message: string, severity: string}>  $messages  [ref] Error messages
     * @return list<string> Missing required field names
     */
    private function validateFields(array $payload, EventSchema $schema, array &$messages): array
    {
        $missingRequired = [];

        foreach ($schema->requiredParams as $fieldName => $param) {
            if (! array_key_exists($fieldName, $payload) || $payload[$fieldName] === null) {
                $missingRequired[] = $fieldName;

                $messages[] = [
                    'field' => $fieldName,
                    'message' => "Required field '{$fieldName}' is missing",
                    'severity' => 'error',
                ];

                continue;
            }

            // Type validation using EventParam's built-in validation
            $typeError = $param->validateType($payload[$fieldName]);
            if ($typeError !== null) {
                $messages[] = [
                    'field' => $fieldName,
                    'message' => $typeError,
                    'severity' => $this->strictMode ? 'error' : 'warning',
                ];
            }
        }

        // Type validation for optional params that exist
        foreach ($schema->optionalParams as $fieldName => $param) {
            if (! array_key_exists($fieldName, $payload) || $payload[$fieldName] === null) {
                continue;
            }

            $typeError = $param->validateType($payload[$fieldName]);
            if ($typeError !== null) {
                $messages[] = [
                    'field' => $fieldName,
                    'message' => $typeError,
                    'severity' => $this->strictMode ? 'error' : 'warning',
                ];
            }
        }

        return $missingRequired;
    }

    /**
     * Strip fields that aren't defined in the schema.
     *
     * @param  array<string, mixed>  $payload  Current payload
     * @param  EventSchema  $schema  Event schema
     * @param  list<string>  $unknownFields  [ref] Stripped field names
     * @return array<string, mixed> Payload with unknown fields removed
     */
    private function stripUnknown(array $payload, EventSchema $schema, array &$unknownFields): array
    {
        $knownFields = $schema->getAllParamNames();
        // Always keep identity fields and common metadata
        $knownFields = array_merge($knownFields, self::IDENTITY_FIELDS, ['source', 'timestamp', 'session_id']);

        foreach (array_keys($payload) as $field) {
            if (! in_array($field, $knownFields, true)) {
                $unknownFields[] = $field;
                unset($payload[$field]);
            }
        }

        return $payload;
    }

    /**
     * Detect potential PII fields and emit warning messages.
     *
     * Does NOT block the event — just warns for awareness.
     *
     * @param  array<string, mixed>  $payload  Current payload
     * @param  list<array{field: string, message: string, severity: string}>  $messages  [ref] Warning messages
     */
    private function detectPiiFields(array $payload, array &$messages): void
    {
        $payloadKeys = array_keys($payload);

        foreach ($payloadKeys as $field) {
            foreach (self::PII_FIELD_PATTERNS as $piiPattern) {
                if (Str::contains(strtolower($field), $piiPattern)) {
                    $messages[] = [
                        'field' => $field,
                        'message' => "Potential PII field detected: '{$field}'",
                        'severity' => 'warning',
                    ];

                    break;
                }
            }
        }
    }

    /**
     * Check if the actual type matches the expected schema type.
     *
     * Handles loose matching (e.g. 'int' matches actual type 'int',
     * 'float' matches 'int' or 'float', 'numeric' matches 'int' or 'float').
     */
    private function typesMatch(string $actualType, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => $actualType === 'string',
            'int' => $actualType === 'int',
            'float' => $actualType === 'float' || $actualType === 'int',
            'bool' => $actualType === 'bool',
            'array' => $actualType === 'array',
            'numeric' => $actualType === 'int' || $actualType === 'float',
            default => true,
        };
    }
}
