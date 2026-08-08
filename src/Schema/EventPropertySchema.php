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
     * Validates core e-commerce, SaaS, engagement, and lifecycle events with
     * industry-standard type requirements. Covers all EventCatalog events
     * with typed property schemas.
     *
     * @version 2.96.0 — Expanded to cover full EventCatalog
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

        $this->defineEventSchema('remove_from_cart', [
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Item value'],
            'items' => ['type' => 'array', 'description' => 'Array of removed items'],
        ]);

        $this->defineEventSchema('view_cart', [
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Cart total value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'items' => ['type' => 'array', 'description' => 'Array of cart items'],
        ]);

        $this->defineEventSchema('begin_checkout', [
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Checkout total value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'coupon' => ['type' => 'string', 'description' => 'Coupon code'],
            'items' => ['type' => 'array', 'description' => 'Array of checkout items'],
        ]);

        $this->defineEventSchema('add_payment_info', [
            'payment_type' => ['type' => 'string', 'description' => 'Payment method type (credit_card, paypal, etc.)'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        $this->defineEventSchema('add_to_wishlist', [
            'item_id' => ['type' => 'string', 'description' => 'Item SKU or ID'],
            'item_name' => ['type' => 'string', 'description' => 'Item name'],
            'price' => ['type' => 'numeric', 'min' => 0, 'description' => 'Item price'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        $this->defineEventSchema('select_item', [
            'item_list_id' => ['type' => 'string', 'description' => 'Item list identifier'],
            'item_list_name' => ['type' => 'string', 'description' => 'Item list display name'],
            'items' => ['type' => 'array', 'description' => 'Array of selected items'],
        ]);

        $this->defineEventSchema('select_promotion', [
            'promotion_id' => ['type' => 'string', 'description' => 'Promotion ID'],
            'promotion_name' => ['type' => 'string', 'description' => 'Promotion name'],
            'creative_name' => ['type' => 'string', 'description' => 'Creative name'],
            'creative_slot' => ['type' => 'string', 'description' => 'Creative slot position'],
        ]);

        $this->defineEventSchema('view_promotion', [
            'promotion_id' => ['type' => 'string', 'description' => 'Promotion ID'],
            'promotion_name' => ['type' => 'string', 'description' => 'Promotion name'],
            'creative_name' => ['type' => 'string', 'description' => 'Creative name'],
            'creative_slot' => ['type' => 'string', 'description' => 'Creative slot position'],
        ]);

        $this->defineEventSchema('checkout_step', [
            'step_number' => ['type' => 'integer', 'min' => 1, 'description' => 'Checkout step number'],
            'step_name' => ['type' => 'string', 'description' => 'Checkout step name'],
        ]);

        $this->defineEventSchema('abandoned_cart', [
            'cart_value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Abandoned cart value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'item_count' => ['type' => 'integer', 'min' => 0, 'description' => 'Number of items in cart'],
            'time_since_add' => ['type' => 'integer', 'min' => 0, 'description' => 'Seconds since last cart modification'],
        ]);

        $this->defineEventSchema('checkout_abandon', [
            'checkout_step' => ['type' => 'string', 'description' => 'Abandoned checkout step'],
            'cart_value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Cart value at abandonment'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
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

        $this->defineEventSchema('trial_end', [
            'plan' => ['type' => 'string', 'description' => 'Trial plan name'],
            'converted' => ['type' => 'boolean', 'description' => 'Whether trial converted to paid'],
        ]);

        $this->defineEventSchema('trial_converted', [
            'plan' => ['type' => 'string', 'description' => 'Conversion plan name'],
            'trial_days' => ['type' => 'integer', 'min' => 0, 'description' => 'Days in trial before conversion'],
        ]);

        $this->defineEventSchema('trial_expired', [
            'plan' => ['type' => 'string', 'description' => 'Expired trial plan name'],
        ]);

        // ── SaaS Account Lifecycle ───────────────────────────
        $this->defineEventSchema('account_activated', [
            'activation_method' => ['type' => 'string', 'description' => 'How account was activated'],
        ]);

        $this->defineEventSchema('account_deactivated', [
            'reason' => ['type' => 'string', 'description' => 'Deactivation reason'],
        ]);

        $this->defineEventSchema('account_deleted', [
            'reason' => ['type' => 'string', 'description' => 'Deletion reason'],
        ]);

        $this->defineEventSchema('email_verified', []);

        $this->defineEventSchema('password_changed', []);

        $this->defineEventSchema('password_reset', []);

        $this->defineEventSchema('profile_updated', [
            'fields_changed' => ['type' => 'array', 'description' => 'List of updated profile fields'],
        ]);

        $this->defineEventSchema('export', [
            'export_type' => ['type' => 'string', 'description' => 'Type of data exported'],
        ]);

        $this->defineEventSchema('import', [
            'import_type' => ['type' => 'string', 'description' => 'Type of data imported'],
        ]);

        // ── SaaS Subscription Lifecycle ──────────────────────
        $this->defineEventSchema('subscription_created', [
            'plan' => ['type' => 'string', 'description' => 'Subscription plan name'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Subscription value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'billing_cycle' => ['type' => 'string', 'description' => 'Billing cycle'],
        ]);

        $this->defineEventSchema('subscription_cancelled', [
            'plan' => ['type' => 'string', 'description' => 'Cancelled plan name'],
            'reason' => ['type' => 'string', 'description' => 'Cancellation reason'],
        ]);

        $this->defineEventSchema('subscription_renewal', [
            'plan' => ['type' => 'string', 'description' => 'Renewed plan name'],
            'value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Renewal value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        $this->defineEventSchema('subscription_paused', [
            'plan' => ['type' => 'string', 'description' => 'Paused plan name'],
            'reason' => ['type' => 'string', 'description' => 'Pause reason'],
        ]);

        $this->defineEventSchema('subscription_resumed', [
            'plan' => ['type' => 'string', 'description' => 'Resumed plan name'],
        ]);

        $this->defineEventSchema('subscription_value_changed', [
            'plan' => ['type' => 'string', 'description' => 'Plan name'],
            'old_value' => ['type' => 'numeric', 'description' => 'Previous value'],
            'new_value' => ['type' => 'numeric', 'description' => 'New value'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        // ── SaaS Billing ────────────────────────────────────
        $this->defineEventSchema('payment_succeeded', [
            'amount' => ['type' => 'numeric', 'required' => true, 'min' => 0, 'description' => 'Payment amount'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'payment_method' => ['type' => 'string', 'description' => 'Payment method type'],
        ]);

        $this->defineEventSchema('payment_failed', [
            'amount' => ['type' => 'numeric', 'min' => 0, 'description' => 'Failed payment amount'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'failure_reason' => ['type' => 'string', 'description' => 'Failure reason code'],
        ]);

        $this->defineEventSchema('payment_method_added', [
            'payment_type' => ['type' => 'string', 'description' => 'Payment method type'],
        ]);

        $this->defineEventSchema('payment_method_updated', [
            'payment_type' => ['type' => 'string', 'description' => 'Updated payment method type'],
        ]);

        $this->defineEventSchema('invoice_generated', [
            'invoice_id' => ['type' => 'string', 'description' => 'Invoice identifier'],
            'amount' => ['type' => 'numeric', 'min' => 0, 'description' => 'Invoice amount'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
        ]);

        $this->defineEventSchema('credit_applied', [
            'amount' => ['type' => 'numeric', 'min' => 0, 'description' => 'Credit amount'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'reason' => ['type' => 'string', 'description' => 'Credit reason'],
        ]);

        $this->defineEventSchema('billing_retry', [
            'attempt' => ['type' => 'integer', 'min' => 1, 'description' => 'Retry attempt number'],
            'amount' => ['type' => 'numeric', 'min' => 0, 'description' => 'Retry amount'],
        ]);

        // ── SaaS Feature Usage ──────────────────────────────
        $this->defineEventSchema('feature_used', [
            'feature' => ['type' => 'string', 'required' => true, 'description' => 'Feature name'],
            'usage_count' => ['type' => 'integer', 'min' => 1, 'description' => 'Usage count'],
        ]);

        $this->defineEventSchema('feature_limit_reached', [
            'feature' => ['type' => 'string', 'required' => true, 'description' => 'Feature that hit limit'],
            'limit' => ['type' => 'integer', 'min' => 1, 'description' => 'Usage limit'],
            'current_usage' => ['type' => 'integer', 'min' => 0, 'description' => 'Current usage count'],
        ]);

        $this->defineEventSchema('feature_adopted', [
            'feature' => ['type' => 'string', 'required' => true, 'description' => 'Newly adopted feature'],
        ]);

        $this->defineEventSchema('feature_impression', [
            'feature' => ['type' => 'string', 'description' => 'Feature name shown'],
        ]);

        $this->defineEventSchema('feature_request', [
            'feature' => ['type' => 'string', 'description' => 'Requested feature name'],
        ]);

        // ── SaaS B2B / Team ────────────────────────────────
        $this->defineEventSchema('team_created', [
            'team_name' => ['type' => 'string', 'description' => 'Team name'],
            'member_count' => ['type' => 'integer', 'min' => 1, 'description' => 'Initial member count'],
        ]);

        $this->defineEventSchema('team_member_joined', [
            'team_id' => ['type' => 'string', 'description' => 'Team identifier'],
            'role' => ['type' => 'string', 'description' => 'Member role'],
        ]);

        $this->defineEventSchema('team_member_removed', [
            'team_id' => ['type' => 'string', 'description' => 'Team identifier'],
            'role' => ['type' => 'string', 'description' => 'Removed member role'],
        ]);

        $this->defineEventSchema('role_changed', [
            'old_role' => ['type' => 'string', 'description' => 'Previous role'],
            'new_role' => ['type' => 'string', 'description' => 'New role'],
        ]);

        $this->defineEventSchema('invite_sent', [
            'invite_method' => ['type' => 'string', 'description' => 'Invite method (email, link)'],
            'role' => ['type' => 'string', 'description' => 'Invited role'],
        ]);

        // ── SaaS Integrations ───────────────────────────────
        $this->defineEventSchema('integration_connected', [
            'provider' => ['type' => 'string', 'description' => 'Integration provider name'],
        ]);

        $this->defineEventSchema('integration_failed', [
            'provider' => ['type' => 'string', 'description' => 'Failed integration provider'],
            'error' => ['type' => 'string', 'description' => 'Integration error'],
        ]);

        // ── SaaS GDPR / Compliance ──────────────────────────
        $this->defineEventSchema('consent_granted', [
            'purposes' => ['type' => 'array', 'description' => 'List of granted consent purposes'],
        ]);

        $this->defineEventSchema('consent_withdrawn', [
            'purposes' => ['type' => 'array', 'description' => 'List of withdrawn consent purposes'],
        ]);

        $this->defineEventSchema('data_subject_access_request', []);

        $this->defineEventSchema('data_erasure_completed', []);

        // ── SaaS Revenue & Metrics ──────────────────────────
        $this->defineEventSchema('revenue_tracked', [
            'amount' => ['type' => 'numeric', 'required' => true, 'min' => 0, 'description' => 'Revenue amount'],
            'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'revenue_type' => ['type' => 'string', 'required' => true, 'description' => 'Revenue type (mrr, arr, one_time)'],
        ]);

        $this->defineEventSchema('expansion_revenue', [
            'amount' => ['type' => 'numeric', 'min' => 0, 'description' => 'Expansion revenue amount'],
            'currency' => ['type' => 'string', 'format' => 'currency', 'description' => 'ISO 4217 currency code'],
            'source' => ['type' => 'string', 'description' => 'Expansion source (upgrade, seat_addon, etc.)'],
        ]);

        $this->defineEventSchema('milestone_reached', [
            'milestone' => ['type' => 'string', 'required' => true, 'description' => 'Milestone name'],
            'value' => ['type' => 'numeric', 'description' => 'Milestone value'],
        ]);

        $this->defineEventSchema('usage_quota_reached', [
            'quota_type' => ['type' => 'string', 'description' => 'Quota type that was reached'],
            'limit' => ['type' => 'integer', 'min' => 1, 'description' => 'Quota limit'],
        ]);

        $this->defineEventSchema('sla_breach', [
            'sla_type' => ['type' => 'string', 'description' => 'SLA type breached'],
            'threshold' => ['type' => 'numeric', 'description' => 'SLA threshold value'],
            'actual' => ['type' => 'numeric', 'description' => 'Actual value'],
        ]);

        // ── SaaS Workspace / Onboarding ─────────────────────
        $this->defineEventSchema('workspace_created', [
            'workspace_name' => ['type' => 'string', 'description' => 'Workspace name'],
        ]);

        $this->defineEventSchema('onboarding_step', [
            'step_name' => ['type' => 'string', 'required' => true, 'description' => 'Onboarding step name'],
            'step_number' => ['type' => 'integer', 'min' => 1, 'description' => 'Step number'],
            'completed' => ['type' => 'boolean', 'description' => 'Whether step was completed'],
        ]);

        // ── SaaS Cohort Events ─────────────────────────────
        $this->defineEventSchema('cohort_assigned', [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'cohort_name' => ['type' => 'string', 'required' => true, 'description' => 'Cohort name'],
            'cohort_type' => ['type' => 'string', 'description' => 'Cohort type'],
        ]);

        $this->defineEventSchema('cohort_retention', [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'cohort_name' => ['type' => 'string', 'required' => true, 'description' => 'Cohort name'],
            'retention_day' => ['type' => 'integer', 'required' => true, 'min' => 1, 'description' => 'Retention day'],
        ]);

        $this->defineEventSchema('cohort_churn', [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'cohort_name' => ['type' => 'string', 'required' => true, 'description' => 'Cohort name'],
        ]);

        $this->defineEventSchema('cohort_conversion', [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'cohort_name' => ['type' => 'string', 'required' => true, 'description' => 'Cohort name'],
            'conversion_type' => ['type' => 'string', 'required' => true, 'description' => 'Conversion type'],
        ]);

        $this->defineEventSchema('cohort_migration', [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'from_cohort' => ['type' => 'string', 'required' => true, 'description' => 'Source cohort'],
            'to_cohort' => ['type' => 'string', 'required' => true, 'description' => 'Target cohort'],
        ]);

        $this->defineEventSchema('cohort_engagement', [
            'cohort_name' => ['type' => 'string', 'required' => true, 'description' => 'Cohort name'],
            'active_users' => ['type' => 'integer', 'required' => true, 'min' => 0, 'description' => 'Active user count'],
            'total_users' => ['type' => 'integer', 'required' => true, 'min' => 0, 'description' => 'Total user count'],
            'engagement_rate' => ['type' => 'numeric', 'min' => 0, 'max' => 100, 'description' => 'Engagement rate %'],
        ]);

        // ── Engagement events ───────────────────────────────
        $this->defineEventSchema('page_view', [
            'page_title' => ['type' => 'string', 'description' => 'Page title'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Full page URL'],
            'page_referrer' => ['type' => 'string', 'format' => 'url', 'description' => 'Referrer URL'],
        ]);

        $this->defineEventSchema('scroll_depth', [
            'percent' => ['type' => 'integer', 'min' => 0, 'max' => 100, 'description' => 'Scroll depth percentage'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Page URL'],
        ]);

        $this->defineEventSchema('click', [
            'element' => ['type' => 'string', 'description' => 'Clicked element selector'],
            'page' => ['type' => 'string', 'format' => 'url', 'description' => 'Page URL'],
        ]);

        $this->defineEventSchema('form_start', [
            'form_name' => ['type' => 'string', 'description' => 'Form name'],
            'form_id' => ['type' => 'string', 'description' => 'Form ID'],
        ]);

        $this->defineEventSchema('form_submit', [
            'form_name' => ['type' => 'string', 'description' => 'Form name'],
            'form_id' => ['type' => 'string', 'description' => 'Form ID'],
            'form_method' => ['type' => 'string', 'description' => 'HTTP method'],
        ]);

        $this->defineEventSchema('search', [
            'search_term' => ['type' => 'string', 'required' => true, 'description' => 'Search query'],
            'results_count' => ['type' => 'integer', 'min' => 0, 'description' => 'Number of results'],
        ]);

        $this->defineEventSchema('share', [
            'method' => ['type' => 'string', 'description' => 'Share method (email, twitter, etc.)'],
            'content_type' => ['type' => 'string', 'description' => 'Shared content type'],
            'item_id' => ['type' => 'string', 'description' => 'Shared item ID'],
        ]);

        $this->defineEventSchema('error', [
            'error_message' => ['type' => 'string', 'description' => 'Error message'],
            'error_code' => ['type' => 'string', 'description' => 'Error code'],
            'severity' => ['type' => 'string', 'enum' => ['critical', 'error', 'warning', 'info'], 'description' => 'Error severity'],
        ]);

        $this->defineEventSchema('time_on_page', [
            'seconds' => ['type' => 'integer', 'min' => 0, 'description' => 'Time spent in seconds'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Page URL'],
        ]);

        $this->defineEventSchema('session_start', [
            'session_id' => ['type' => 'string', 'description' => 'Session identifier'],
        ]);

        $this->defineEventSchema('session_end', [
            'session_id' => ['type' => 'string', 'description' => 'Session identifier'],
            'session_duration_ms' => ['type' => 'integer', 'min' => 0, 'description' => 'Session duration in ms'],
        ]);

        $this->defineEventSchema('outbound_click', [
            'link_url' => ['type' => 'string', 'format' => 'url', 'description' => 'Destination URL'],
            'link_text' => ['type' => 'string', 'description' => 'Link text'],
        ]);

        $this->defineEventSchema('content_engagement', [
            'content_id' => ['type' => 'string', 'description' => 'Content identifier'],
            'content_type' => ['type' => 'string', 'description' => 'Content type'],
            'duration' => ['type' => 'integer', 'min' => 0, 'description' => 'Engagement duration in seconds'],
        ]);

        $this->defineEventSchema('web_vitals', [
            'metric_name' => ['type' => 'string', 'required' => true, 'description' => 'Metric name (LCP, FID, CLS, INP, TTFB)'],
            'metric_value' => ['type' => 'numeric', 'required' => true, 'description' => 'Metric value'],
            'rating' => ['type' => 'string', 'enum' => ['good', 'needs-improvement', 'poor'], 'description' => 'Metric rating'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Page URL'],
        ]);

        $this->defineEventSchema('js_error', [
            'error_message' => ['type' => 'string', 'description' => 'JavaScript error message'],
            'error_source' => ['type' => 'string', 'description' => 'Error source file'],
            'error_line' => ['type' => 'integer', 'min' => 0, 'description' => 'Error line number'],
            'page_location' => ['type' => 'string', 'format' => 'url', 'description' => 'Page URL'],
        ]);

        $this->defineEventSchema('timing', [
            'timing_name' => ['type' => 'string', 'description' => 'Timing measurement name'],
            'timing_duration_ms' => ['type' => 'integer', 'min' => 0, 'description' => 'Duration in milliseconds'],
        ]);

        $this->defineEventSchema('screen_view', [
            'screen_name' => ['type' => 'string', 'required' => true, 'description' => 'Screen/view name'],
            'screen_class' => ['type' => 'string', 'description' => 'Screen class/type'],
        ]);

        $this->defineEventSchema('notification', [
            'notification_channel' => ['type' => 'string', 'required' => true, 'description' => 'Channel (email, push, sms)'],
            'notification_action' => ['type' => 'string', 'required' => true, 'description' => 'Action (sent, opened, clicked)'],
            'notification_type' => ['type' => 'string', 'description' => 'Notification type/template'],
        ]);

        $this->defineEventSchema('ab_test_exposure', [
            'experiment_id' => ['type' => 'string', 'required' => true, 'description' => 'Experiment ID'],
            'variant_id' => ['type' => 'string', 'required' => true, 'description' => 'Assigned variant ID'],
        ]);

        $this->defineEventSchema('campaign_attribution', [
            'source' => ['type' => 'string', 'description' => 'UTM source'],
            'medium' => ['type' => 'string', 'description' => 'UTM medium'],
            'campaign' => ['type' => 'string', 'description' => 'UTM campaign'],
            'term' => ['type' => 'string', 'description' => 'UTM term'],
            'content' => ['type' => 'string', 'description' => 'UTM content'],
        ]);

        $this->defineEventSchema('video_play', [
            'video_title' => ['type' => 'string', 'description' => 'Video title'],
            'video_provider' => ['type' => 'string', 'description' => 'Video provider (youtube, vimeo, etc.)'],
            'video_duration' => ['type' => 'numeric', 'min' => 0, 'description' => 'Video duration in seconds'],
        ]);

        $this->defineEventSchema('file_download', [
            'file_name' => ['type' => 'string', 'description' => 'Downloaded file name'],
            'file_extension' => ['type' => 'string', 'description' => 'File extension'],
            'file_size' => ['type' => 'integer', 'min' => 0, 'description' => 'File size in bytes'],
        ]);

        $this->defineEventSchema('feedback', [
            'feedback_type' => ['type' => 'string', 'description' => 'Feedback type'],
            'rating' => ['type' => 'integer', 'min' => 1, 'max' => 5, 'description' => 'Rating (1-5)'],
            'comment' => ['type' => 'string', 'description' => 'Feedback comment'],
        ]);

        $this->defineEventSchema('ad_click', [
            'ad_id' => ['type' => 'string', 'description' => 'Advertisement ID'],
            'ad_campaign' => ['type' => 'string', 'description' => 'Ad campaign name'],
            'ad_placement' => ['type' => 'string', 'description' => 'Ad placement location'],
        ]);

        $this->defineEventSchema('goal_conversion', [
            'goal_name' => ['type' => 'string', 'required' => true, 'description' => 'Goal name'],
            'goal_value' => ['type' => 'numeric', 'min' => 0, 'description' => 'Goal conversion value'],
        ]);

        // ── Global rules ────────────────────────────────────
        $this->defineGlobalRule('user_id', ['type' => 'string', 'description' => 'Authenticated user identifier']);
        $this->defineGlobalRule('client_id', ['type' => 'string', 'format' => 'uuid', 'description' => 'Client tracking identifier']);
        $this->defineGlobalRule('session_id', ['type' => 'string', 'description' => 'Session identifier']);
        $this->defineGlobalRule('timestamp', ['type' => 'string', 'description' => 'Event timestamp (ISO 8601)']);
        $this->defineGlobalRule('source', ['type' => 'string', 'description' => 'Event source (server, client, webhook)']);

        return $this;
    }
}
