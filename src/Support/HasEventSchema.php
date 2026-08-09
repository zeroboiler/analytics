<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Trait providing schema-aware validation and convenience methods for event classes.
 *
 * Event classes can use this trait to get automatic param validation,
 * required field checks, and type-safe param extraction. Promotes DRY
 * across the 90+ event classes in the catalog.
 *
 * @version 4.6.0
 */
trait HasEventSchema
{
    /**
     * Get the event name (must be implemented by using class).
     */
    abstract public function eventName(): string;

    /**
     * Get the list of required parameter keys.
     *
     * @return list<string>
     */
    abstract protected function requiredParams(): array;

    /**
     * Get the parameter type map for validation.
     *
     * Maps param keys to expected types: 'string', 'int', 'float', 'bool', 'array', 'null'.
     * Params not in the map are not type-checked.
     *
     * @return array<string, string>
     */
    protected function paramTypes(): array
    {
        return [];
    }

    /**
     * Get the max allowed parameter count.
     */
    protected function maxParams(): int
    {
        return 25;
    }

    /**
     * Validate the event's parameters against its schema.
     *
     * Checks for required params, param types, and parameter count limit.
     * Returns an array of validation errors (empty if valid).
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public function validateParams(array $params): array
    {
        $errors = [];

        // Check required params
        foreach ($this->requiredParams() as $key) {
            if (! array_key_exists($key, $params)) {
                $errors[] = "Missing required parameter: '{$key}'";
            } elseif ($params[$key] === null || $params[$key] === '') {
                $errors[] = "Required parameter '{$key}' cannot be empty";
            }
        }

        // Check param count
        if (count($params) > $this->maxParams()) {
            $errors[] = "Too many parameters: " . count($params) . " exceeds maximum of {$this->maxParams()}";
        }

        // Type checking
        $types = $this->paramTypes();
        foreach ($params as $key => $value) {
            if (! isset($types[$key])) {
                continue;
            }

            $expectedType = $types[$key];
            if (! $this->checkType($value, $expectedType)) {
                $actualType = get_debug_type($value);
                $errors[] = "Parameter '{$key}' expected type '{$expectedType}', got '{$actualType}'";
            }
        }

        return $errors;
    }

    /**
     * Check if the event parameters are valid.
     *
     * @param  array<string, mixed>  $params
     */
    public function isValid(array $params): bool
    {
        return $this->validateParams($params) === [];
    }

    /**
     * Build a validated AnalyticsEvent from this schema class.
     *
     * Validates params first. If invalid, throws an InvalidArgumentException
     * with all validation errors listed.
     *
     * @param  array<string, mixed>  $params
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return AnalyticsEvent
     *
     * @throws \InvalidArgumentException
     */
    public function buildEvent(array $params, ?string $clientId = null, ?string $userId = null): AnalyticsEvent
    {
        $errors = $this->validateParams($params);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                "Invalid params for event '{$this->eventName()}': " . implode('; ', $errors)
            );
        }

        return new AnalyticsEvent(
            name: $this->eventName(),
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }

    /**
     * Get a string parameter with a fallback default.
     *
     * @param  array<string, mixed>  $params
     */
    protected function stringParam(array $params, string $key, ?string $default = null): ?string
    {
        $value = $params[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get an integer parameter with a fallback default.
     *
     * @param  array<string, mixed>  $params
     */
    protected function intParam(array $params, string $key, ?int $default = null): ?int
    {
        $value = $params[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    /**
     * Get a float parameter with a fallback default.
     *
     * @param  array<string, mixed>  $params
     */
    protected function floatParam(array $params, string $key, ?float $default = null): ?float
    {
        $value = $params[$key] ?? $default;

        return is_float($value) || is_int($value) ? (float) $value : $default;
    }

    /**
     * Get a boolean parameter with a fallback default.
     *
     * @param  array<string, mixed>  $params
     */
    protected function boolParam(array $params, string $key, bool $default = false): bool
    {
        $value = $params[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * Get an array parameter with a fallback default.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function arrayParam(array $params, string $key, array $default = []): array
    {
        $value = $params[$key] ?? $default;

        return is_array($value) ? $value : $default;
    }

    /**
     * Check a value against an expected type name.
     *
     * @param  mixed  $value
     */
    private function checkType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'null' => $value === null,
            default => true, // Unknown type — allow
        };
    }
}
