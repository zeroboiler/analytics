<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Schema-driven event builder service.
 *
 * Builds AnalyticsEvent DTOs using registered schemas for type coercion,
 * default values, required field enforcement, and validation in a single call.
 * Integrates with both EventPropertySchema (typed validation) and
 * EventSchemaRegistry (structural validation) for comprehensive guard rails.
 *
 * Usage:
 *   $event = $builder->build('purchase', [
 *       'transaction_id' => 'TXN-123',
 *       'value' => 99.99,
 *       'currency' => 'USD',
 *   ]);
 *
 * @see \ZeroBoiler\Analytics\Schema\EventPropertySchema
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 * @version 5.0.0
 */
final class SchemaDrivenEventBuilder
{
    private ?EventPropertySchema $propertySchema;

    private ?EventSchemaRegistry $schemaRegistry;

    private bool $strictMode;

    /**
     * @param  EventPropertySchema|null  $propertySchema  Optional property schema validator
     * @param  EventSchemaRegistry|null  $schemaRegistry  Optional structural schema registry
     * @param  bool  $strictMode  When true, throws on validation errors; when false, returns null
     */
    public function __construct(
        ?EventPropertySchema $propertySchema = null,
        ?EventSchemaRegistry $schemaRegistry = null,
        bool $strictMode = false,
    ): void {
        $this->propertySchema = $propertySchema;
        $this->schemaRegistry = $schemaRegistry;
        $this->strictMode = $strictMode;
    }

    /**
     * Build an AnalyticsEvent from params using registered schemas.
     *
     * Validates parameters against EventPropertySchema (type, format, enum, range),
     * then validates structure against EventSchemaRegistry (required params).
     * Coerces types where safe (string → int for numeric strings, etc.).
     *
     * @param  string  $eventName  Event name from catalog
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Optional client tracking ID
     * @param  string|null  $userId  Optional authenticated user ID
     * @return AnalyticsEvent|null Built event, or null if validation fails (non-strict mode)
     *
     * @throws \InvalidArgumentException In strict mode when validation fails
     */
    public function build(
        string $eventName,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ): ?AnalyticsEvent {
        // Coerce parameter types based on schema
        $coerced = $this->coerceParams($eventName, $params);

        // Merge identity parameters
        if ($clientId !== null) {
            $coerced['client_id'] = $clientId;
        }

        if ($userId !== null) {
            $coerced['user_id'] = $userId;
        }

        // Validate against EventPropertySchema if available
        if ($this->propertySchema !== null && $this->propertySchema->hasSchema($eventName)) {
            $result = $this->propertySchema->validate(new AnalyticsEvent(
                name: $eventName,
                params: $coerced,
                clientId: $clientId,
                userId: $userId,
            ));

            if (! $result['valid']) {
                $errors = implode('; ', $result['errors']);

                if ($this->strictMode) {
                    throw new \InvalidArgumentException(
                        "Schema validation failed for event '{$eventName}': {$errors}",
                    );
                }

                return null;
            }
        }

        // Validate against EventSchemaRegistry if available
        if ($this->schemaRegistry !== null && $this->schemaRegistry->has($eventName)) {
            $result = $this->schemaRegistry->validate($eventName, $coerced);

            if (! $result['valid']) {
                $errors = implode('; ', $result['errors']);

                if ($this->strictMode) {
                    throw new \InvalidArgumentException(
                        "Schema registry validation failed for event '{$eventName}': {$errors}",
                    );
                }

                return null;
            }

            $coerced = $result['sanitized'];
        }

        return new AnalyticsEvent(
            name: $eventName,
            params: $coerced,
            clientId: $clientId,
            userId: $userId,
        );
    }

    /**
     * Build multiple events in batch from the same schema.
     *
     * Useful for bulk event import, replay, or migration scenarios.
     * Each event is validated independently — failures are collected
     * rather than thrown (batch mode is never strict).
     *
     * @param  string  $eventName  Event name
     * @param  list<array<string, mixed>>  $paramsList  List of parameter arrays
     * @return array{events: list<AnalyticsEvent>, errors: list<array{index: int, errors: list<string>}>}
     */
    public function buildBatch(string $eventName, array $paramsList): array
    {
        $events = [];
        $errors = [];
        $wasStrict = $this->strictMode;

        // Temporarily disable strict mode for batch processing
        $this->strictMode = false;

        foreach ($paramsList as $index => $params) {
            $event = $this->build($eventName, $params);

            if ($event === null) {
                // Collect validation errors
                $validationErrors = [];

                if ($this->propertySchema !== null && $this->propertySchema->hasSchema($eventName)) {
                    $result = $this->propertySchema->validate(new AnalyticsEvent(
                        name: $eventName,
                        params: $params,
                    ));
                    $validationErrors = $result['errors'];
                }

                if ($this->schemaRegistry !== null && $this->schemaRegistry->has($eventName)) {
                    $result = $this->schemaRegistry->validate($eventName, $params);
                    $validationErrors = array_merge($validationErrors, $result['errors']);
                }

                $errors[] = [
                    'index' => $index,
                    'errors' => $validationErrors,
                ];
            } else {
                $events[] = $event;
            }
        }

        // Restore strict mode
        $this->strictMode = $wasStrict;

        return [
            'events' => $events,
            'errors' => $errors,
        ];
    }

    /**
     * Coerce parameter types based on registered schema definitions.
     *
     * Converts numeric strings to numbers, ensures booleans are booleans,
     * and applies safe type transformations. Does NOT modify parameters
     * that don't have a schema definition.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function coerceParams(string $eventName, array $params): array
    {
        $schema = $this->getPropertySchemaRules($eventName);

        if (empty($schema)) {
            return $params;
        }

        $coerced = [];

        foreach ($params as $key => $value) {
            $rules = $schema[$key] ?? null;

            if ($rules === null || $value === null) {
                $coerced[$key] = $value;

                continue;
            }

            $coerced[$key] = $this->coerceValue($value, $rules['type'] ?? 'string');
        }

        return $coerced;
    }

    /**
     * Coerce a single value to the expected type.
     *
     * @param  mixed  $value
     * @param  string  $expectedType
     * @return mixed
     */
    private function coerceValue(mixed $value, string $expectedType): mixed
    {
        return match ($expectedType) {
            'int', 'integer' => is_numeric($value) ? (int) $value : $value,
            'float', 'number', 'numeric' => is_numeric($value) ? (float) $value : $value,
            'bool', 'boolean' => is_string($value) ? in_array(strtolower($value), ['true', '1', 'yes'], true) : $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /**
     * Get property schema rules for an event from EventPropertySchema.
     *
     * @return array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}>
     */
    private function getPropertySchemaRules(string $eventName): array
    {
        if ($this->propertySchema === null) {
            return [];
        }

        return $this->propertySchema->getSchema($eventName);
    }

    /**
     * Validate an event without building it.
     *
     * Returns comprehensive validation results from both validators.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @return array{valid: bool, property_errors: list<string>, registry_errors: list<string>, coerced: array<string, mixed>}
     */
    public function validateOnly(string $eventName, array $params): array
    {
        $propertyErrors = [];
        $registryErrors = [];

        // Property schema validation
        if ($this->propertySchema !== null) {
            $propertyResult = $this->propertySchema->validate(new AnalyticsEvent(
                name: $eventName,
                params: $params,
            ));

            if (! $propertyResult['valid']) {
                $propertyErrors = $propertyResult['errors'];
            }
        }

        // Registry validation
        if ($this->schemaRegistry !== null) {
            $registryResult = $this->schemaRegistry->validate($eventName, $params);

            if (! $registryResult['valid']) {
                $registryErrors = $registryResult['errors'];
            }
        }

        return [
            'valid' => empty($propertyErrors) && empty($registryErrors),
            'property_errors' => $propertyErrors,
            'registry_errors' => $registryErrors,
            'coerced' => $this->coerceParams($eventName, $params),
        ];
    }

    /**
     * Check if a schema exists for the given event name in either validator.
     */
    public function hasSchema(string $eventName): bool
    {
        return ($this->propertySchema !== null && $this->propertySchema->hasSchema($eventName))
            || ($this->schemaRegistry !== null && $this->schemaRegistry->has($eventName));
    }

    /**
     * Get the schema summary for an event.
     *
     * Returns the combined property schema rules and registry schema info.
     *
     * @return array{property_schema: array<string, mixed>, registry_schema: array{name: string, category: string, description: string, required_params: list<string>, optional_params: list<string>, providers: array<string, string>}|null}
     */
    public function getSchemaInfo(string $eventName): array
    {
        $propertySchema = $this->propertySchema?->getSchema($eventName) ?? [];

        $registrySchema = null;

        if ($this->schemaRegistry !== null) {
            $schema = $this->schemaRegistry->get($eventName);

            if ($schema !== null) {
                $registrySchema = [
                    'name' => $schema->name,
                    'category' => $schema->category,
                    'description' => $schema->description,
                    'required_params' => array_keys($schema->requiredParams),
                    'optional_params' => array_keys($schema->optionalParams),
                    'providers' => $schema->providerMapping,
                ];
            }
        }

        return [
            'property_schema' => $propertySchema,
            'registry_schema' => $registrySchema,
        ];
    }

    /**
     * Check if strict mode is enabled.
     */
    public function isStrict(): bool
    {
        return $this->strictMode;
    }

    /**
     * Set strict mode.
     */
    public function setStrict(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }
}
