<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventParam;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Runtime property type validator for analytics event parameters.
 *
 * Validates event parameters against their registered schemas in EventSchemaRegistry.
 * Performs deep type checking, range validation, string length enforcement, and
 * nested array structure validation. Provides structured diagnostics with error
 * codes, severity levels, and per-parameter violation details.
 *
 * Use cases:
 * - Pre-dispatch validation in the event pipeline
 * - API request parameter validation
 * - Client SDK payload verification
 * - CI/CD event contract testing
 * - Debug capture event inspection
 *
 * Inspired by Segment's Event Validation API, PostHog's event property types,
 * and Mixpanel's event schema enforcement.
 *
 * @since 231.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 * @see \ZeroBoiler\Analytics\Schema\EventSchema
 * @see \ZeroBoiler\Analytics\Schema\EventParam
 */
final class EventPropertyTypeValidator
{
    /**
     * Validation severity levels.
     */
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    /**
     * Error codes for programmatic handling.
     */
    public const CODE_MISSING_REQUIRED = 'missing_required';
    public const CODE_TYPE_MISMATCH = 'type_mismatch';
    public const CODE_RANGE_VIOLATION = 'range_violation';
    public const CODE_LENGTH_EXCEEDED = 'length_exceeded';
    public const CODE_UNKNOWN_PARAM = 'unknown_param';
    public const CODE_NO_SCHEMA = 'no_schema';
    public const CODE_INVALID_PARAM_KEY = 'invalid_param_key';

    private EventSchemaRegistry $schemaRegistry;

    private bool $strictTypes;

    private bool $allowUnknownParams;

    private bool $enforceRequired;

    private int $maxParamCount;

    private int $maxKeyLength;

    private int $maxStringLength;

    /**
     * @param  EventSchemaRegistry  $schemaRegistry  Schema registry for looking up event definitions
     * @param  bool  $strictTypes  If true, reject events with any type mismatch (vs coerce)
     * @param  bool  $allowUnknownParams  If true, params not in schema are allowed (warning only)
     * @param  bool  $enforceRequired  If true, missing required params cause validation failure
     * @param  int  $maxParamCount  Maximum number of parameters allowed per event
     * @param  int  $maxKeyLength  Maximum parameter key length
     * @param  int  $maxStringLength  Maximum string parameter value length (global cap)
     */
    public function __construct(
        EventSchemaRegistry $schemaRegistry,
        bool $strictTypes = false,
        bool $allowUnknownParams = true,
        bool $enforceRequired = true,
        int $maxParamCount = 100,
        int $maxKeyLength = 100,
        int $maxStringLength = 4096,
    ){
        $this->schemaRegistry = $schemaRegistry;
        $this->strictTypes = $strictTypes;
        $this->allowUnknownParams = $allowUnknownParams;
        $this->enforceRequired = $enforceRequired;
        $this->maxParamCount = $maxParamCount;
        $this->maxKeyLength = $maxKeyLength;
        $this->maxStringLength = $maxStringLength;
    }

    /**
     * Validate an analytics event's parameters against its registered schema.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @return PropertyValidationResult Structured validation result with diagnostics
     */
    public function validate(AnalyticsEvent $event): PropertyValidationResult
    {
        $schema = $this->schemaRegistry->get($event->name);

        if ($schema === null) {
            return new PropertyValidationResult(
                eventName: $event->name,
                valid: $this->allowUnknownParams,
                violations: [],
                warnings: [
                    new PropertyViolation(
                        code: self::CODE_NO_SCHEMA,
                        message: "No schema registered for event '{$event->name}'. All parameters accepted.",
                        severity: self::SEVERITY_WARNING,
                    ),
                ],
            );
        }

        $violations = [];
        $warnings = [];
        $params = $event->params;

        // Global parameter count check
        if (count($params) > $this->maxParamCount) {
            $violations[] = new PropertyViolation(
                code: 'param_count_exceeded',
                message: "Event has " . count($params) . " parameters, exceeding maximum of {$this->maxParamCount}.",
                severity: self::SEVERITY_ERROR,
            );
        }

        // Required params check
        if ($this->enforceRequired) {
            foreach ($schema->requiredParams as $paramName => $paramDef) {
                if (! array_key_exists($paramName, $params)) {
                    $violations[] = new PropertyViolation(
                        code: self::CODE_MISSING_REQUIRED,
                        message: "Missing required parameter '{$paramName}'.",
                        severity: self::SEVERITY_ERROR,
                        param: $paramName,
                    );
                }
            }
        }

        // Type and constraint validation for present params
        foreach ($params as $key => $value) {
            // Key validation
            $keyViolation = $this->validateKey($key);
            if ($keyViolation !== null) {
                $violations[] = new PropertyViolation(
                    code: self::CODE_INVALID_PARAM_KEY,
                    message: $keyViolation,
                    severity: self::SEVERITY_ERROR,
                    param: $key,
                );

                continue;
            }

            $requiredDef = $schema->requiredParams[$key] ?? null;
            $optionalDef = $schema->optionalParams[$key] ?? null;
            $paramDef = $requiredDef ?? $optionalDef;

            if ($paramDef === null) {
                if (! $this->allowUnknownParams) {
                    $violations[] = new PropertyViolation(
                        code: self::CODE_UNKNOWN_PARAM,
                        message: "Unknown parameter '{$key}' not defined in schema for event '{$event->name}'.",
                        severity: self::SEVERITY_ERROR,
                        param: $key,
                    );
                } else {
                    $warnings[] = new PropertyViolation(
                        code: self::CODE_UNKNOWN_PARAM,
                        message: "Unknown parameter '{$key}' not defined in schema for event '{$event->name}'.",
                        severity: self::SEVERITY_WARNING,
                        param: $key,
                    );
                }

                continue;
            }

            // Type validation
            $typeError = $paramDef->validateType($value);
            if ($typeError !== null) {
                $severity = $this->strictTypes ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;
                $violation = new PropertyViolation(
                    code: self::CODE_TYPE_MISMATCH,
                    message: "Parameter '{$key}': {$typeError}.",
                    severity: $severity,
                    param: $key,
                    expected: $paramDef->type,
                    actual: get_debug_type($value),
                );

                if ($severity === self::SEVERITY_ERROR) {
                    $violations[] = $violation;
                } else {
                    $warnings[] = $violation;
                }
            }

            // Range validation for numeric types
            $rangeViolation = $this->validateRange($key, $value, $paramDef);
            if ($rangeViolation !== null) {
                $violations[] = new PropertyViolation(
                    code: self::CODE_RANGE_VIOLATION,
                    message: "Parameter '{$key}': {$rangeViolation}.",
                    severity: self::SEVERITY_ERROR,
                    param: $key,
                );
            }

            // String length validation
            $lengthViolation = $this->validateLength($key, $value, $paramDef);
            if ($lengthViolation !== null) {
                $severity = $this->strictTypes ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;
                $violation = new PropertyViolation(
                    code: self::CODE_LENGTH_EXCEEDED,
                    message: "Parameter '{$key}': {$lengthViolation}.",
                    severity: $severity,
                    param: $key,
                );

                if ($severity === self::SEVERITY_ERROR) {
                    $violations[] = $violation;
                } else {
                    $warnings[] = $violation;
                }
            }

            // Deep array validation for array-type params
            if ($paramDef->type === 'array' && is_array($value) && $this->strictTypes) {
                $arrayWarnings = $this->validateArrayStructure($key, $value);
                foreach ($arrayWarnings as $arrayWarning) {
                    $warnings[] = new PropertyViolation(
                        code: 'array_structure',
                        message: "Parameter '{$key}': {$arrayWarning}",
                        severity: self::SEVERITY_WARNING,
                        param: $key,
                    );
                }
            }
        }

        return new PropertyValidationResult(
            eventName: $event->name,
            valid: empty($violations),
            violations: $violations,
            warnings: $warnings,
        );
    }

    /**
     * Validate a raw params array against a named event schema.
     *
     * Useful for pre-dispatch validation when you don't have an AnalyticsEvent instance.
     *
     * @param  string  $eventName  Event name to look up schema
     * @param  array<string, mixed>  $params  Parameters to validate
     * @return PropertyValidationResult
     */
    public function validateParams(string $eventName, array $params): PropertyValidationResult
    {
        $event = new AnalyticsEvent(name: $eventName, params: $params);

        return $this->validate($event);
    }

    /**
     * Validate only a single parameter value against a specific param definition.
     *
     * Useful for form validation or API request validation.
     *
     * @param  string  $paramName  Parameter name for error messages
     * @param  mixed  $value  Value to validate
     * @param  EventParam  $paramDef  Schema definition for this param
     * @return PropertyValidationResult
     */
    public function validateSingleParam(string $paramName, mixed $value, EventParam $paramDef): PropertyValidationResult
    {
        $violations = [];
        $warnings = [];

        $typeError = $paramDef->validateType($value);
        if ($typeError !== null) {
            $severity = $this->strictTypes ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;
            $violation = new PropertyViolation(
                code: self::CODE_TYPE_MISMATCH,
                message: "Parameter '{$paramName}': {$typeError}.",
                severity: $severity,
                param: $paramName,
                expected: $paramDef->type,
                actual: get_debug_type($value),
            );

            if ($severity === self::SEVERITY_ERROR) {
                $violations[] = $violation;
            } else {
                $warnings[] = $violation;
            }
        }

        $rangeViolation = $this->validateRange($paramName, $value, $paramDef);
        if ($rangeViolation !== null) {
            $violations[] = new PropertyViolation(
                code: self::CODE_RANGE_VIOLATION,
                message: "Parameter '{$paramName}': {$rangeViolation}.",
                severity: self::SEVERITY_ERROR,
                param: $paramName,
            );
        }

        $lengthViolation = $this->validateLength($paramName, $value, $paramDef);
        if ($lengthViolation !== null) {
            $violations[] = new PropertyViolation(
                code: self::CODE_LENGTH_EXCEEDED,
                message: "Parameter '{$paramName}': {$lengthViolation}.",
                severity: self::SEVERITY_ERROR,
                param: $paramName,
            );
        }

        return new PropertyValidationResult(
            eventName: $paramName,
            valid: empty($violations),
            violations: $violations,
            warnings: $warnings,
        );
    }

    /**
     * Get the schema registry used by this validator.
     */
    public function getSchemaRegistry(): EventSchemaRegistry
    {
        return $this->schemaRegistry;
    }

    /**
     * Check if strict type mode is enabled.
     */
    public function isStrictTypes(): bool
    {
        return $this->strictTypes;
    }

    /**
     * Validate a parameter key for valid characters and length.
     *
     * @return string|null Error message, or null if valid
     */
    private function validateKey(string $key): ?string
    {
        if ($key === '') {
            return 'Empty parameter key is not allowed.';
        }

        if (strlen($key) > $this->maxKeyLength) {
            return "Parameter key '{$key}' exceeds maximum length of {$this->maxKeyLength} characters.";
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            return "Parameter key '{$key}' must start with a lowercase letter and contain only lowercase letters, numbers, and underscores.";
        }

        return null;
    }

    /**
     * Validate numeric range constraints.
     *
     * @return string|null Error message, or null if valid
     */
    private function validateRange(string $key, mixed $value, EventParam $paramDef): ?string
    {
        if ($paramDef->type !== 'int' && $paramDef->type !== 'float') {
            return null;
        }

        $numeric = is_numeric($value) ? (float) $value : null;
        if ($numeric === null) {
            return null;
        }

        if ($paramDef->min !== null && $numeric < $paramDef->min) {
            return "Value {$numeric} is below minimum {$paramDef->min}";
        }

        if ($paramDef->max !== null && $numeric > $paramDef->max) {
            return "Value {$numeric} exceeds maximum {$paramDef->max}";
        }

        return null;
    }

    /**
     * Validate string length constraints.
     *
     * @return string|null Error message, or null if valid
     */
    private function validateLength(string $key, mixed $value, EventParam $paramDef): ?string
    {
        if ($paramDef->type !== 'string' || ! is_string($value)) {
            return null;
        }

        if (mb_strlen($value) > $this->maxStringLength) {
            return "String length " . mb_strlen($value) . " exceeds global maximum of {$this->maxStringLength} characters";
        }

        if ($paramDef->maxLength !== null && mb_strlen($value) > $paramDef->maxLength) {
            return "String length " . mb_strlen($value) . " exceeds maximum {$paramDef->maxLength} for this parameter";
        }

        return null;
    }

    /**
     * Validate deep array structure for type consistency.
     *
     * Checks that array elements have consistent structure (useful for items arrays).
     *
     * @param  string  $key  Parameter name
     * @param  array<mixed>  $value  Array value to validate
     * @return list<string> Warning messages
     */
    private function validateArrayStructure(string $key, array $value): array
    {
        $warnings = [];
        $count = count($value);

        if ($count > 0) {
            $firstKeyType = $this->getArrayKeyType($value);
            if ($firstKeyType !== 'int') {
                $warnings[] = "Array parameter uses non-integer keys ({$firstKeyType}). Expect sequential array.";
            }

            $types = array_unique(array_map(get_debug_type(...), $value));
            if (count($types) > 3) {
                $warnings[] = "Array has " . count($types) . " different value types. Consider normalizing.";
            }
        }

        return $warnings;
    }

    /**
     * Detect the primary key type of an array.
     */
    private function getArrayKeyType(array $value): string
    {
        if (empty($value)) {
            return 'none';
        }

        $keys = array_keys($value);
        $firstKey = $keys[0];

        return get_debug_type($firstKey);
    }
}
