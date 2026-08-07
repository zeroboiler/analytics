<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Typed runtime property schema validation for analytics events.
 *
 * Validates event parameters against type, required, enum, format, and range
 * constraints defined in schemas. Provides per-event and per-category validation.
 *
 * Unlike EventSchemaRegistry (which validates event existence and name structure),
 * this service validates the actual parameter values and types.
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 */
final class EventPropertySchema
{
    /** @var array<string, array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}>> */
    private array $schemas = [];

    /** @var array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}> */
    private array $globalRules = [];

    /**
     * Register a property schema for a specific event.
     *
     * @param  string  $eventName  Event name (e.g. 'purchase', 'sign_up')
     * @param  string  $propertyName  Parameter name (e.g. 'transaction_id', 'currency')
     * @param  array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}  $rules  Property rules
     */
    public function defineProperty(string $eventName, string $propertyName, array $rules): self
    {
        $this->schemas[$eventName][$propertyName] = $rules;

        return $this;
    }

    /**
     * Register multiple property schemas for an event.
     *
     * @param  string  $eventName
     * @param  array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}>  $properties
     */
    public function defineEventSchema(string $eventName, array $properties): self
    {
        foreach ($properties as $name => $rules) {
            $this->defineProperty($eventName, $name, $rules);
        }

        return $this;
    }

    /**
     * Define a global rule applied to all events.
     *
     * @param  string  $propertyName
     * @param  array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}  $rules
     */
    public function defineGlobalRule(string $propertyName, array $rules): self
    {
        $this->globalRules[$propertyName] = $rules;

        return $this;
    }

    /**
     * Validate an analytics event against its registered schema.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $eventName = $event->name;
        $params = $event->params;
        $schema = $this->schemas[$eventName] ?? [];
        $allRules = array_merge($this->globalRules, $schema);

        // Check required fields
        foreach ($allRules as $prop => $rules) {
            if (($rules['required'] ?? false) && ! array_key_exists($prop, $params)) {
                $errors[] = "Property '{$prop}' is required for event '{$eventName}'";
            }
        }

        // Validate present properties
        foreach ($params as $prop => $value) {
            $rules = $allRules[$prop] ?? null;

            // No schema for this property — skip
            if ($rules === null) {
                continue;
            }

            $expectedType = $rules['type'] ?? 'string';

            // Type validation
            $typeError = $this->validateType($prop, $value, $expectedType);
            if ($typeError !== null) {
                $errors[] = $typeError;
            }

            // Enum validation
            if (isset($rules['enum']) && is_array($rules['enum'])) {
                if (! in_array($value, $rules['enum'], true) && $value !== null) {
                    $allowed = implode(', ', array_map('json_encode', $rules['enum']));
                    $errors[] = "Property '{$prop}' value {$this->fmtValue($value)} is not in allowed values: [{$allowed}]";
                }
            }

            // Format validation (string patterns)
            if (isset($rules['format']) && is_string($value)) {
                $formatError = $this->validateFormat($prop, $value, $rules['format']);
                if ($formatError !== null) {
                    $errors[] = $formatError;
                }
            }

            // Range validation
            if (is_numeric($value)) {
                $rangeError = $this->validateRange($prop, (float) $value, $rules);
                if ($rangeError !== null) {
                    $errors[] = $rangeError;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the type of a property value.
     *
     * @return string|null Error message or null if valid
     */
    private function validateType(string $prop, mixed $value, string $expectedType): ?string
    {
        return match ($expectedType) {
            'string' => (! is_string($value) && $value !== null) ? "Property '{$prop}' must be a string, got " . get_debug_type($value) : null,
            'int', 'integer' => (! is_int($value) && $value !== null) ? "Property '{$prop}' must be an integer, got " . get_debug_type($value) : null,
            'float', 'number', 'numeric' => (! is_int($value) && ! is_float($value) && $value !== null) ? "Property '{$prop}' must be a number, got " . get_debug_type($value) : null,
            'bool', 'boolean' => (! is_bool($value) && $value !== null) ? "Property '{$prop}' must be a boolean, got " . get_debug_type($value) : null,
            'array', 'object' => (! is_array($value) && $value !== null) ? "Property '{$prop}' must be an array, got " . get_debug_type($value) : null,
            default => null, // Unknown types are not validated
        };
    }

    /**
     * Validate a string value against a format pattern.
     *
     * @return string|null Error message or null if valid
     */
    private function validateFormat(string $prop, string $value, string $format): ?string
    {
        return match ($format) {
            'email' => (! filter_var($value, FILTER_VALIDATE_EMAIL)) ? "Property '{$prop}' is not a valid email" : null,
            'url' => (! filter_var($value, FILTER_VALIDATE_URL)) ? "Property '{$prop}' is not a valid URL" : null,
            'currency' => (! preg_match('/^[A-Z]{3}$/', $value)) ? "Property '{$prop}' must be a valid 3-letter currency code" : null,
            'iso_date' => (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? "Property '{$prop}' must be a valid ISO date (YYYY-MM-DD)" : null,
            'uuid' => (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) ? "Property '{$prop}' must be a valid UUID" : null,
            'alpha' => (! preg_match('/^[a-zA-Z]+$/', $value)) ? "Property '{$prop}' must contain only alphabetic characters" : null,
            'alpha_dash' => (! preg_match('/^[a-zA-Z0-9\-_]+$/', $value)) ? "Property '{$prop}' must contain only alphanumeric, dash, and underscore characters" : null,
            default => null, // Unknown formats are not validated
        };
    }

    /**
     * Validate a numeric value against min/max range constraints.
     *
     * @param  array{min?: int|float, max?: int|float}  $rules
     * @return string|null Error message or null if valid
     */
    private function validateRange(string $prop, float $value, array $rules): ?string
    {
        if (isset($rules['min']) && $value < $rules['min']) {
            return "Property '{$prop}' value {$value} is below minimum {$rules['min']}";
        }

        if (isset($rules['max']) && $value > $rules['max']) {
            return "Property '{$prop}' value {$value} exceeds maximum {$rules['max']}";
        }

        return null;
    }

    /**
     * Format a value for error messages.
     */
    private function fmtValue(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . mb_substr($value, 0, 50) . "'";
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    /**
     * Get all registered schemas.
     *
     * @return array<string, array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}>>
     */
    public function getSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Check if a schema exists for a given event.
     */
    public function hasSchema(string $eventName): bool
    {
        return isset($this->schemas[$eventName]);
    }

    /**
     * Get the schema for a specific event.
     *
     * @return array<string, array{type: string, required?: bool, enum?: list<string>, format?: string, min?: int|float, max?: int|float, description?: string}>
     */
    public function getSchema(string $eventName): array
    {
        return $this->schemas[$eventName] ?? [];
    }

    /**
     * Get the number of events with registered schemas.
     */
    public function schemaCount(): int
    {
        return count($this->schemas);
    }

    /**
     * Get supported validation types.
     *
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return ['string', 'int', 'integer', 'float', 'number', 'numeric', 'bool', 'boolean', 'array', 'object'];
    }

    /**
     * Get supported format validators.
     *
     * @return list<string>
     */
    public static function supportedFormats(): array
    {
        return ['email', 'url', 'currency', 'iso_date', 'uuid', 'alpha', 'alpha_dash'];
    }

    /**
     * Register built-in SaaS starter schemas for common events.
     *
     * Validates core e-commerce, SaaS, and engagement events with
     * industry-standard type requirements.
     */
    public function registerBuiltInSchemas(): self
    {
        // ── E-commerce events ───────────────────────────────
        $this->defineEventSchema('purchase', [
            'transaction_id' => ['type' => 'string', 'required' => true, 'description' => 'Unique transaction identifier'],
            'value' => ['type' => 'numeric', 'required' => true, 'min' => 0, 'description' => 'Total revenue value'],
            'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'tax' => ['type' => 'numeric', 'min' => 0, 'description' => 'Tax amount'],
            'shipping' => ['type' => 'numeric', 'min' => 0, 'description' => 'Shipping cost'],
            'coupon' => ['type' => 'string', 'description' => 'Coupon code applied'],
            'items' => ['type' => 'array', 'description' => 'Array of purchased items'],
        ]);

        $this->defineEventSchema('refund', [
            'transaction_id' => ['type' => 'string', 'required' => true, 'description' => 'Original transaction ID'],
            'value' => ['type' => 'numeric', 'required' => true, 'min' => 0, 'description' => 'Refund amount'],
            'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        $this->defineEventSchema('add_to_cart', [
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Item value'],
            'items' => ['type' => 'array', 'description' => 'Array of cart items'],
        ]);

        $this->defineEventSchema('view_item', [
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Item value'],
            'items' => ['type' => 'array', 'description' => 'Array of viewed items'],
        ]);

        // ── SaaS events ─────────────────────────────────────
        $this->defineEventSchema('sign_up', [
            'method' => ['type' => 'string', 'enum' => ['email', 'google', 'github', 'facebook', 'apple', 'sso'], 'description' => 'Registration method'],
            'plan' => ['type' => 'string', 'description' => 'Selected plan name'],
        ]);

        $this->defineEventSchema('login', [
            'method' => ['type' => 'string', 'enum' => ['email', 'google', 'github', 'facebook', 'apple', 'sso'], 'description' => 'Login method'],
        ]);

        $this->defineEventSchema('subscribe', [
            'plan' => ['type' => 'string', 'required' => true, 'description' => 'Subscription plan name'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Subscription value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'billing_cycle' => ['type' => 'string', 'enum' => ['monthly', 'quarterly', 'annual', 'lifetime'], 'description' => 'Billing frequency'],
        ]);

        $this->defineEventSchema('plan_upgrade', [
            'from_plan' => ['type' => 'string', 'required' => true, 'description' => 'Previous plan name'],
            'to_plan' => ['type' => 'string', 'required' => true, 'description' => 'New plan name'],
        ]);

        $this->defineEventSchema('plan_downgrade', [
            'from_plan' => ['type' => 'string', 'required' => true, 'description' => 'Previous plan name'],
            'to_plan' => ['type' => 'string', 'required' => true, 'description' => 'New plan name'],
        ]);

        $this->defineEventSchema('start_trial', [
            'plan' => ['type' => 'string', 'description' => 'Trial plan name'],
            'trial_days' => ['type' => 'integer', 'min' => 1, 'description' => 'Trial duration in days'],
        ]);

        $this->defineEventSchema('cancellation', [
            'reason' => ['type' => 'string', 'enum' => ['too_expensive', 'missing_features', 'switching_competitor', 'no_longer_needed', 'poor_experience', 'other'], 'description' => 'Cancellation reason'],
            'plan' => ['type' => 'string', 'description' => 'Cancelled plan name'],
        ]);

        // ── Engagement events ───────────────────────────────
        $this->defineEventSchema('page_view', [
            'page_title' => ['type' => 'string', 'description' => 'Page title'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Full page URL'],
            'page_referrer' => ['type' => 'string', 'format' => 'url', 'description' => 'Referrer URL'],
        ]);

        $this->defineEventSchema('search', [
            'search_term' => ['type' => 'string', 'required' => true, 'description' => 'Search query'],
            'results_count' => ['type' => 'integer', 'min' => 0, 'description' => 'Number of results'],
        ]);

        $this->defineEventSchema('error', [
            'error_message' => ['type' => 'string', 'description' => 'Error message'],
            'error_code' => ['type' => 'string', 'description' => 'Error code'],
            'severity' => ['type' => 'string', 'enum' => ['critical', 'error', 'warning', 'info'], 'description' => 'Error severity'],
        ]);

        // ── Global rules ────────────────────────────────────
        $this->defineGlobalRule('user_id', ['type' => 'string', 'description' => 'Authenticated user identifier']);
        $this->defineGlobalRule('client_id', ['type' => 'string', 'format' => 'uuid', 'description' => 'Client tracking identifier']);
        $this->defineGlobalRule('session_id', ['type' => 'string', 'description' => 'Session identifier']);

        return $this;
    }
}
