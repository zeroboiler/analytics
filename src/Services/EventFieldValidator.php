<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Config-driven per-event field validation for analytics events.
 *
 * Provides declarative validation rules for event parameters, similar to
 * Laravel Form Requests but for analytics events. Rules are defined in
 * config under `zeroboiler.analytics.field_validation`.
 *
 * Supported rule types:
 * - **required**: Field must be present and non-empty
 * - **type**: Enforce PHP type (string, int, float, bool, array, numeric)
 * - **nullable**: Allow null values (bypasses required + type checks)
 * - **min**: Minimum value for numbers, minimum length for strings/arrays
 * - **max**: Maximum value for numbers, maximum length for strings/arrays
 * - **enum**: Value must be one of a predefined set
 * - **regex**: Value must match a regular expression pattern
 * - **format**: Structural format (email, url, uuid, currency_code, iso_date)
 * - **default**: Default value if field is missing
 *
 * Can be used standalone or as part of the EventValidationPipeline.
 *
 * @phpstan-type FieldRule array{type?: string, required?: bool, nullable?: bool, min?: int|float, max?: int|float, enum?: list<string>, regex?: string, format?: string, default?: mixed, coerce?: bool, message?: string}
 * @phpstan-type FieldErrors list<array{field: string, rule: string, message: string, value?: mixed, severity: 'error'|'warning'}>
 * @phpstan-type ValidationResult array{valid: bool, errors: FieldErrors, coerced_params: array<string, mixed>, coercions: int}
 *
 * @see \ZeroBoiler\Analytics\Services\EventFieldCoercer
 *
 * @since 125.0.0
 */
final class EventFieldValidator
{
    /** @var array<string, array<string, FieldRule>> Per-event field rules */
    private array $rules = [];

    /** @var array<string, FieldRule> Global rules applied to all events */
    private array $globalRules = [];

    private EventFieldCoercer $coercer;

    private bool $enabled;

    private bool $debug;

    /**
     * @param  array<string, array<string, FieldRule>>  $rules  Per-event field rules
     * @param  array<string, FieldRule>  $globalRules  Global field rules
     * @param  bool  $enabled  Whether validation is active
     * @param  bool  $debug  Log validation details
     */
    public function __construct(
        array $rules = [],
        array $globalRules = [],
        bool $enabled = true,
        bool $debug = false,
    ) {
        $this->rules = $rules;
        $this->globalRules = $globalRules;
        $this->coercer = new EventFieldCoercer($debug);
        $this->enabled = $enabled;
        $this->debug = $debug;
    }

    /**
     * Validate an analytics event's parameters against configured rules.
     *
     * Runs coercion first, then validation. Returns a structured result
     * with coerced parameters and any validation errors.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @return ValidationResult Validation result with coerced params and errors
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'valid' => true,
                'errors' => [],
                'coerced_params' => $event->params,
                'coercions' => 0,
            ];
        }

        $eventName = $event->name;
        $eventRules = $this->getMergedRules($eventName);
        $params = $event->params;

        // Apply defaults for missing fields
        $params = $this->applyDefaults($params, $eventRules);

        // Coerce field types
        $coercionResult = $this->coercer->coerceParams($params, $eventRules);
        $params = $coercionResult['params'];
        $coercionCount = count($coercionResult['coercions']);

        // Run validation
        $errors = $this->validateParams($params, $eventRules, $eventName);

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'coerced_params' => $params,
            'coercions' => $coercionCount,
        ];
    }

    /**
     * Validate raw parameters against rules for a specific event.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return ValidationResult Validation result
     */
    public function validateRaw(string $eventName, array $params): array
    {
        if (! $this->enabled) {
            return [
                'valid' => true,
                'errors' => [],
                'coerced_params' => $params,
                'coercions' => 0,
            ];
        }

        $eventRules = $this->getMergedRules($eventName);

        // Apply defaults
        $params = $this->applyDefaults($params, $eventRules);

        // Coerce
        $coercionResult = $this->coercer->coerceParams($params, $eventRules);
        $params = $coercionResult['params'];

        // Validate
        $errors = $this->validateParams($params, $eventRules, $eventName);

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'coerced_params' => $params,
            'coercions' => count($coercionResult['coercions']),
        ];
    }

    /**
     * Apply default values for missing fields.
     *
     * @param  array<string, mixed>  $params  Current params
     * @param  array<string, FieldRule>  $rules  Merged rules
     * @return array<string, mixed> Params with defaults applied
     */
    private function applyDefaults(array $params, array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (array_key_exists($field, $params)) {
                continue;
            }

            if (array_key_exists('default', $rule)) {
                $params[$field] = $rule['default'];
            }
        }

        return $params;
    }

    /**
     * Run all validation checks on parameters.
     *
     * @param  array<string, mixed>  $params  Event parameters (after coercion)
     * @param  array<string, FieldRule>  $rules  Merged rules
     * @param  string  $eventName  Event name for error context
     * @return FieldErrors List of validation errors
     */
    private function validateParams(array $params, array $rules, string $eventName): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $params[$field] ?? null;
            $isNullable = (bool) ($rule['nullable'] ?? false);
            $isRequired = (bool) ($rule['required'] ?? false);
            $customMessage = $rule['message'] ?? null;

            // Nullable check: null is always valid if nullable
            if ($value === null && $isNullable) {
                continue;
            }

            // Required check
            if ($isRequired && ($value === null || $value === '')) {
                $errors[] = $this->makeError(
                    $field,
                    'required',
                    $customMessage ?? "Field '{$field}' is required for event '{$eventName}'",
                    $value,
                );
                continue;
            }

            // Skip further checks if field is absent and not required
            if (! array_key_exists($field, $params)) {
                continue;
            }

            // Type check
            if (isset($rule['type']) && $value !== null) {
                $typeError = $this->checkType($field, $value, $rule['type'], $customMessage);
                if ($typeError !== null) {
                    $errors[] = $typeError;
                }
            }

            // Min check
            if (isset($rule['min']) && $value !== null) {
                $minError = $this->checkMin($field, $value, $rule['min'], $rule['type'] ?? null, $customMessage);
                if ($minError !== null) {
                    $errors[] = $minError;
                }
            }

            // Max check
            if (isset($rule['max']) && $value !== null) {
                $maxError = $this->checkMax($field, $value, $rule['max'], $rule['type'] ?? null, $customMessage);
                if ($maxError !== null) {
                    $errors[] = $maxError;
                }
            }

            // Enum check
            if (isset($rule['enum']) && $value !== null) {
                $enumError = $this->checkEnum($field, $value, $rule['enum'], $customMessage);
                if ($enumError !== null) {
                    $errors[] = $enumError;
                }
            }

            // Regex check
            if (isset($rule['regex']) && $value !== null && is_string($value)) {
                $regexError = $this->checkRegex($field, $value, $rule['regex'], $customMessage);
                if ($regexError !== null) {
                    $errors[] = $regexError;
                }
            }

            // Format check
            if (isset($rule['format']) && $value !== null) {
                $formatError = $this->checkFormat($field, $value, $rule['format'], $customMessage);
                if ($formatError !== null) {
                    $errors[] = $formatError;
                }
            }
        }

        return $errors;
    }

    /**
     * Check type constraint.
     *
     * @param  mixed  $value  Field value
     * @param  string  $expectedType  Expected type
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkType(string $field, mixed $value, string $expectedType, ?string $customMessage): ?array
    {
        $matches = match ($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_int($value) || is_float($value),
            default => true,
        };

        if ($matches) {
            return null;
        }

        return $this->makeError(
            $field,
            'type',
            $customMessage ?? "Field '{$field}' expected type '{$expectedType}', got '" . get_debug_type($value) . "'",
            $value,
        );
    }

    /**
     * Check minimum value/length constraint.
     *
     * @param  mixed  $value  Field value
     * @param  int|float  $min  Minimum value
     * @param  string|null  $fieldType  Expected field type
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkMin(string $field, mixed $value, int|float $min, ?string $fieldType, ?string $customMessage): ?array
    {
        if (is_numeric($value) && ($fieldType === 'int' || $fieldType === 'float' || $fieldType === 'numeric')) {
            if ($value < $min) {
                return $this->makeError(
                    $field,
                    'min',
                    $customMessage ?? "Field '{$field}' value {$value} is less than minimum {$min}",
                    $value,
                );
            }
        } elseif (is_string($value) || is_array($value)) {
            $len = is_string($value) ? mb_strlen($value) : count($value);
            if ($len < $min) {
                return $this->makeError(
                    $field,
                    'min',
                    $customMessage ?? "Field '{$field}' length {$len} is less than minimum {$min}",
                    $value,
                );
            }
        }

        return null;
    }

    /**
     * Check maximum value/length constraint.
     *
     * @param  mixed  $value  Field value
     * @param  int|float  $max  Maximum value
     * @param  string|null  $fieldType  Expected field type
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkMax(string $field, mixed $value, int|float $max, ?string $fieldType, ?string $customMessage): ?array
    {
        if (is_numeric($value) && ($fieldType === 'int' || $fieldType === 'float' || $fieldType === 'numeric')) {
            if ($value > $max) {
                return $this->makeError(
                    $field,
                    'max',
                    $customMessage ?? "Field '{$field}' value {$value} exceeds maximum {$max}",
                    $value,
                );
            }
        } elseif (is_string($value) || is_array($value)) {
            $len = is_string($value) ? mb_strlen($value) : count($value);
            if ($len > $max) {
                return $this->makeError(
                    $field,
                    'max',
                    $customMessage ?? "Field '{$field}' length {$len} exceeds maximum {$max}",
                    $value,
                );
            }
        }

        return null;
    }

    /**
     * Check enum constraint.
     *
     * @param  mixed  $value  Field value
     * @param  list<string>  $allowedValues  Allowed values
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkEnum(string $field, mixed $value, array $allowedValues, ?string $customMessage): ?array
    {
        $stringValue = is_string($value) ? $value : ((is_int($value) || is_float($value)) ? (string) $value : null);

        if ($stringValue === null) {
            return $this->makeError(
                $field,
                'enum',
                $customMessage ?? "Field '{$field}' value cannot be checked against enum (non-scalar)",
                $value,
            );
        }

        if (! in_array($stringValue, $allowedValues, true)) {
            return $this->makeError(
                $field,
                'enum',
                $customMessage ?? "Field '{$field}' value '{$stringValue}' is not one of: " . implode(', ', $allowedValues),
                $value,
            );
        }

        return null;
    }

    /**
     * Check regex constraint.
     *
     * @param  mixed  $value  Field value (string)
     * @param  string  $pattern  Regex pattern
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkRegex(string $field, mixed $value, string $pattern, ?string $customMessage): ?array
    {
        if (preg_match($pattern, $value) === 1) {
            return null;
        }

        return $this->makeError(
            $field,
            'regex',
            $customMessage ?? "Field '{$field}' value does not match pattern '{$pattern}'",
            $value,
        );
    }

    /**
     * Check structural format constraint.
     *
     * @param  mixed  $value  Field value
     * @param  string  $format  Format name (email, url, uuid, currency_code, iso_date)
     * @param  string|null  $customMessage  Custom error message
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}|null
     */
    private function checkFormat(string $field, mixed $value, string $format, ?string $customMessage): ?array
    {
        if (! is_string($value)) {
            return $this->makeError(
                $field,
                'format',
                $customMessage ?? "Field '{$field}' must be a string for format '{$format}' check",
                $value,
            );
        }

        $pattern = match ($format) {
            'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
            'url' => '/^https?:\/\/.+\..+$/',
            'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'currency_code' => '/^[A-Z]{3}$/',
            'iso_date' => '/^\d{4}-\d{2}-\d{2}$/',
            'iso_datetime' => '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/',
            default => null,
        };

        if ($pattern === null) {
            return null; // Unknown format — skip
        }

        if (preg_match($pattern, $value) !== 1) {
            return $this->makeError(
                $field,
                'format',
                $customMessage ?? "Field '{$field}' value does not match format '{$format}'",
                $value,
            );
        }

        return null;
    }

    /**
     * Create a structured validation error.
     *
     * @return array{field: string, rule: string, message: string, value: mixed, severity: 'error'}
     */
    private function makeError(string $field, string $rule, string $message, mixed $value): array
    {
        return [
            'field' => $field,
            'rule' => $rule,
            'message' => $message,
            'value' => $value,
            'severity' => 'error',
        ];
    }

    /**
     * Merge event-specific rules with global rules.
     *
     * Event-specific rules override global rules for the same field.
     *
     * @param  string  $eventName  Event name
     * @return array<string, FieldRule> Merged rules
     */
    private function getMergedRules(string $eventName): array
    {
        // Check for wildcard rules (e.g., "purchase" matches "purchase_*")
        $eventRules = $this->rules[$eventName] ?? [];

        // Apply wildcard matches (e.g., "saas_*" matches all SaaS events)
        foreach ($this->rules as $pattern => $rules) {
            if ($pattern === $eventName) {
                continue; // Already applied exact match
            }

            if ($this->matchesPattern($eventName, $pattern)) {
                foreach ($rules as $field => $rule) {
                    if (! isset($eventRules[$field])) {
                        $eventRules[$field] = $rule;
                    }
                }
            }
        }

        // Merge global rules (lowest priority)
        foreach ($this->globalRules as $field => $rule) {
            if (! isset($eventRules[$field])) {
                $eventRules[$field] = $rule;
            }
        }

        return $eventRules;
    }

    /**
     * Check if an event name matches a pattern (with wildcard support).
     *
     * Supports: "saas_*" matches "saas_login", "saas_signup", etc.
     */
    private function matchesPattern(string $eventName, string $pattern): bool
    {
        if (! str_contains($pattern, '*')) {
            return false;
        }

        $prefix = rtrim($pattern, '*');

        return str_starts_with($eventName, $prefix);
    }

    /**
     * Get all configured event rules.
     *
     * @return array<string, array<string, FieldRule>>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get rules for a specific event.
     *
     * @return array<string, FieldRule>
     */
    public function getEventRules(string $eventName): array
    {
        return $this->getMergedRules($eventName);
    }

    /**
     * Get the number of events with configured rules.
     */
    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /**
     * Check if validation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the underlying coercer instance.
     */
    public function coercer(): EventFieldCoercer
    {
        return $this->coercer;
    }

    /**
     * Get a diagnostic summary of the validator state.
     *
     * @return array{enabled: bool, event_count: int, global_rules: int, debug: bool, events: list<string>}
     */
    public function diagnosticSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'event_count' => count($this->rules),
            'global_rules' => count($this->globalRules),
            'debug' => $this->debug,
            'events' => array_keys($this->rules),
        ];
    }

    /**
     * Build predefined SaaS field validation rules.
     *
     * Returns a rules array with common SaaS event field validations
     * for purchase, sign_up, page_view, and other standard events.
     *
     * @return array<string, array<string, FieldRule>>
     */
    public static function saasPresetRules(): array
    {
        return [
            'purchase' => [
                'transaction_id' => ['type' => 'string', 'required' => true, 'min' => 1, 'max' => 100],
                'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency_code', 'default' => 'USD'],
                'tax' => ['type' => 'float', 'nullable' => true, 'min' => 0],
                'shipping' => ['type' => 'float', 'nullable' => true, 'min' => 0],
                'coupon' => ['type' => 'string', 'nullable' => true],
                'items' => ['type' => 'array', 'required' => true, 'min' => 1],
            ],
            'sign_up' => [
                'method' => ['type' => 'string', 'nullable' => true, 'enum' => ['email', 'google', 'github', 'password', 'sso']],
            ],
            'page_view' => [
                'page_title' => ['type' => 'string', 'nullable' => true, 'max' => 500],
                'page_location' => ['type' => 'string', 'nullable' => true, 'format' => 'url'],
                'page_referrer' => ['type' => 'string', 'nullable' => true],
            ],
            'add_to_cart' => [
                'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency_code', 'default' => 'USD'],
                'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                'items' => ['type' => 'array', 'required' => true, 'min' => 1],
            ],
            'begin_checkout' => [
                'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency_code', 'default' => 'USD'],
                'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                'items' => ['type' => 'array', 'required' => true, 'min' => 1],
                'coupon' => ['type' => 'string', 'nullable' => true],
            ],
            'refund' => [
                'transaction_id' => ['type' => 'string', 'required' => true],
                'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency_code', 'default' => 'USD'],
            ],
            'login' => [
                'method' => ['type' => 'string', 'nullable' => true],
            ],
            'plan_upgrade' => [
                'from_plan' => ['type' => 'string', 'required' => true],
                'to_plan' => ['type' => 'string', 'required' => true],
                'currency' => ['type' => 'string', 'nullable' => true, 'format' => 'currency_code'],
                'value' => ['type' => 'float', 'nullable' => true, 'min' => 0],
            ],
            'start_trial' => [
                'plan_name' => ['type' => 'string', 'nullable' => true],
                'trial_days' => ['type' => 'int', 'nullable' => true, 'min' => 1],
            ],
            'search' => [
                'search_term' => ['type' => 'string', 'required' => true, 'min' => 1, 'max' => 200],
                'results_count' => ['type' => 'int', 'nullable' => true, 'min' => 0],
            ],
            'share' => [
                'method' => ['type' => 'string', 'nullable' => true, 'enum' => ['email', 'twitter', 'facebook', 'linkedin', 'whatsapp', 'copy']],
                'content_type' => ['type' => 'string', 'nullable' => true],
            ],
        ];
    }

    /**
     * Build from config repository.
     *
     * @param  array{enabled?: bool, debug?: bool, rules?: array<string, array<string, FieldRule>>, global_rules?: array<string, FieldRule>}  $config
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            rules: $config['rules'] ?? [],
            globalRules: $config['global_rules'] ?? [],
            enabled: (bool) ($config['enabled'] ?? true),
            debug: (bool) ($config['debug'] ?? false),
        );
    }
}
