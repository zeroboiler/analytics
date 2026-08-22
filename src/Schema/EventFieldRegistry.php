<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Industry-standard event parameter schema registry.
 *
 * Defines the expected parameters, types, and constraints for each
 * catalog event. Used by EventSchemaRuntimeValidator, API form requests,
 * and the OpenAPI schema generator to enforce consistent event payloads.
 *
 * Provides per-event field definitions with:
 * - Type (string, int, float, bool, array)
 * - Required/optional status
 * - Description for documentation generation
 * - Allowed values for enum-like fields
 * - Default values
 *
 * @since 133.0.0
 */
final class EventFieldRegistry
{
    /** @var array<string, array<string, FieldDefinition>> Event name → field name → definition */
    private static array $schemas = [];

    /**
     * Get the field schema for a specific event.
     *
     * @return array<string, FieldDefinition>
     */
    public static function forEvent(string $eventName): array
    {
        return self::buildSchema($eventName);
    }

    /**
     * Get all field definitions for all events.
     *
     * @return array<string, array<string, FieldDefinition>>
     */
    public static function all(): array
    {
        $result = [];

        foreach (self::eventNames() as $name) {
            $result[$name] = self::buildSchema($name);
        }

        return $result;
    }

    /**
     * Get required field names for a specific event.
     *
     * @return list<string>
     */
    public static function requiredFields(string $eventName): array
    {
        $schema = self::forEvent($eventName);
        $required = [];

        foreach ($schema as $fieldName => $definition) {
            if ($definition->required) {
                $required[] = $fieldName;
            }
        }

        return $required;
    }

    /**
     * Validate an event payload against its registered schema.
     *
     * Returns a list of validation errors. Empty array means valid.
     *
     * @param  array<string, mixed>  $payload  Event parameters to validate
     * @return list<string>
     */
    public static function validate(string $eventName, array $payload): array
    {
        $schema = self::forEvent($eventName);
        $errors = [];

        foreach ($schema as $fieldName => $definition) {
            if ($definition->required && ! array_key_exists($fieldName, $payload)) {
                $errors[] = "Missing required field: '{$fieldName}'";
            }
        }

        foreach ($payload as $fieldName => $value) {
            $definition = $schema[$fieldName] ?? null;

            if ($definition !== null) {
                $typeError = self::checkType($fieldName, $value, $definition);

                if ($typeError !== null) {
                    $errors[] = $typeError;
                }

                if ($definition->allowedValues !== [] && ! in_array($value, $definition->allowedValues, true)) {
                    $errors[] = "Field '{$fieldName}' has invalid value: " . json_encode($value) .
                        ', expected one of: ' . implode(', ', array_map('json_encode', $definition->allowedValues));
                }
            }
        }

        return $errors;
    }

    /**
     * Get all events that have registered field schemas.
     *
     * @return list<string>
     */
    public static function eventNames(): array
    {
        return [
            // E-commerce events
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'add_payment_info', 'purchase', 'refund',
            'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion',
            'checkout_step', 'abandoned_cart', 'checkout_abandon',
            // SaaS lifecycle events
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'feature_used', 'revenue_tracked', 'plan_changed',
            // Engagement events
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'time_on_page',
        ];
    }

    /**
     * Check the type of a value against a field definition.
     */
    private static function checkType(string $fieldName, mixed $value, FieldDefinition $def): ?string
    {
        return match ($def->type) {
            'string' => is_string($value) ? null : "Field '{$fieldName}' must be a string, got " . gettype($value),
            'int' => is_int($value) ? null : "Field '{$fieldName}' must be an integer, got " . gettype($value),
            'float' => (is_int($value) || is_float($value)) ? null : "Field '{$fieldName}' must be a number, got " . gettype($value),
            'bool' => is_bool($value) ? null : "Field '{$fieldName}' must be a boolean, got " . gettype($value),
            'array' => is_array($value) ? null : "Field '{$fieldName}' must be an array, got " . gettype($value),
            'numeric' => is_numeric($value) ? null : "Field '{$fieldName}' must be numeric, got " . gettype($value),
            default => null,
        };
    }

    /**
     * Build the field schema for a specific event.
     *
     * @return array<string, FieldDefinition>
     */
    private static function buildSchema(string $eventName): array
    {
        if (isset(self::$schemas[$eventName])) {
            return self::$schemas[$eventName];
        }

        $schema = match ($eventName) {
            // ── E-commerce ────────────────────────────────
            'view_item' => [
                'item_id' => new FieldDefinition('string', true, 'Unique identifier for the item'),
                'item_name' => new FieldDefinition('string', true, 'Name of the item'),
                'item_category' => new FieldDefinition('string', false, 'Category of the item'),
                'price' => new FieldDefinition('float', false, 'Price of the item'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)', ['USD', 'EUR', 'GBP', 'TRY']),
                'value' => new FieldDefinition('float', false, 'Monetary value of the item'),
            ],
            'add_to_cart' => [
                'item_id' => new FieldDefinition('string', true, 'Unique identifier for the item'),
                'item_name' => new FieldDefinition('string', true, 'Name of the item'),
                'item_category' => new FieldDefinition('string', false, 'Category of the item'),
                'price' => new FieldDefinition('float', true, 'Price of the item'),
                'quantity' => new FieldDefinition('int', true, 'Number of items added'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'value' => new FieldDefinition('float', false, 'Total value (price × quantity)'),
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
            ],
            'remove_from_cart' => [
                'item_id' => new FieldDefinition('string', true, 'Unique identifier for the item'),
                'item_name' => new FieldDefinition('string', false, 'Name of the item'),
                'price' => new FieldDefinition('float', false, 'Price of the item'),
                'quantity' => new FieldDefinition('int', false, 'Number of items removed'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
            ],
            'view_cart' => [
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
                'items' => new FieldDefinition('array', false, 'Array of items in the cart'),
                'total_value' => new FieldDefinition('float', false, 'Total cart value'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'total_items' => new FieldDefinition('int', false, 'Total number of items'),
            ],
            'begin_checkout' => [
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
                'items' => new FieldDefinition('array', false, 'Array of items being purchased'),
                'total_value' => new FieldDefinition('float', true, 'Total checkout value'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'coupon' => new FieldDefinition('string', false, 'Coupon code applied'),
                'checkout_type' => new FieldDefinition('string', false, 'Type of checkout (standard, guest, express)'),
            ],
            'add_payment_info' => [
                'payment_type' => new FieldDefinition('string', false, 'Payment method type (credit_card, paypal, etc.)'),
                'total_value' => new FieldDefinition('float', false, 'Total payment amount'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
            ],
            'purchase' => [
                'transaction_id' => new FieldDefinition('string', true, 'Unique transaction identifier'),
                'value' => new FieldDefinition('float', true, 'Total purchase value'),
                'currency' => new FieldDefinition('string', true, 'Currency code (ISO 4217)'),
                'items' => new FieldDefinition('array', false, 'Array of purchased items'),
                'tax' => new FieldDefinition('float', false, 'Tax amount'),
                'shipping' => new FieldDefinition('float', false, 'Shipping cost'),
                'coupon' => new FieldDefinition('string', false, 'Coupon code applied'),
                'affiliation' => new FieldDefinition('string', false, 'Store or affiliation name'),
                'payment_method' => new FieldDefinition('string', false, 'Payment method used'),
            ],
            'refund' => [
                'transaction_id' => new FieldDefinition('string', true, 'Original transaction identifier'),
                'value' => new FieldDefinition('float', true, 'Refund amount'),
                'currency' => new FieldDefinition('string', true, 'Currency code (ISO 4217)'),
                'reason' => new FieldDefinition('string', false, 'Refund reason'),
                'items' => new FieldDefinition('array', false, 'Array of refunded items'),
            ],
            'add_to_wishlist' => [
                'item_id' => new FieldDefinition('string', true, 'Unique identifier for the item'),
                'item_name' => new FieldDefinition('string', false, 'Name of the item'),
                'item_category' => new FieldDefinition('string', false, 'Category of the item'),
                'price' => new FieldDefinition('float', false, 'Price of the item'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'wishlist_id' => new FieldDefinition('string', false, 'Wishlist identifier'),
            ],
            'select_item' => [
                'item_id' => new FieldDefinition('string', true, 'Unique identifier for the item'),
                'item_name' => new FieldDefinition('string', false, 'Name of the item'),
                'item_category' => new FieldDefinition('string', false, 'Category of the item'),
                'list_id' => new FieldDefinition('string', false, 'List/collection identifier'),
                'list_name' => new FieldDefinition('string', false, 'Name of the list'),
            ],
            'select_promotion' => [
                'promotion_id' => new FieldDefinition('string', false, 'Promotion identifier'),
                'promotion_name' => new FieldDefinition('string', false, 'Name of the promotion'),
                'creative_name' => new FieldDefinition('string', false, 'Creative variation name'),
                'creative_slot' => new FieldDefinition('string', false, 'Creative slot position'),
            ],
            'view_promotion' => [
                'promotion_id' => new FieldDefinition('string', false, 'Promotion identifier'),
                'promotion_name' => new FieldDefinition('string', false, 'Name of the promotion'),
                'creative_name' => new FieldDefinition('string', false, 'Creative variation name'),
                'creative_slot' => new FieldDefinition('string', false, 'Creative slot position'),
                'location_id' => new FieldDefinition('string', false, 'Location of the promotion'),
            ],
            'checkout_step' => [
                'step' => new FieldDefinition('int', true, 'Checkout step number'),
                'option' => new FieldDefinition('string', false, 'Selected option at this step'),
                'checkout_type' => new FieldDefinition('string', false, 'Type of checkout'),
            ],
            'abandoned_cart' => [
                'cart_id' => new FieldDefinition('string', false, 'Shopping cart identifier'),
                'total_value' => new FieldDefinition('float', false, 'Total cart value at abandonment'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'total_items' => new FieldDefinition('int', false, 'Total items in cart'),
                'abandonment_reason' => new FieldDefinition('string', false, 'Detected abandonment reason'),
            ],
            'checkout_abandon' => [
                'checkout_type' => new FieldDefinition('string', false, 'Type of checkout'),
                'step' => new FieldDefinition('int', false, 'Checkout step where abandonment occurred'),
                'total_value' => new FieldDefinition('float', false, 'Total checkout value'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
            ],

            // ── SaaS Lifecycle ────────────────────────────
            'sign_up' => [
                'method' => new FieldDefinition('string', false, 'Sign-up method (email, google, github, sso)', ['email', 'google', 'github', 'sso', 'apple', 'linkedin']),
                'user_id' => new FieldDefinition('string', false, 'User identifier (if available at event time)'),
                'referrer' => new FieldDefinition('string', false, 'Referral source'),
                'utm_source' => new FieldDefinition('string', false, 'UTM source parameter'),
                'utm_medium' => new FieldDefinition('string', false, 'UTM medium parameter'),
                'utm_campaign' => new FieldDefinition('string', false, 'UTM campaign parameter'),
                'plan' => new FieldDefinition('string', false, 'Initial plan selected'),
            ],
            'login' => [
                'method' => new FieldDefinition('string', false, 'Login method (email, oauth, sso, 2fa)'),
                'user_id' => new FieldDefinition('string', false, 'Authenticated user identifier'),
                'session_id' => new FieldDefinition('string', false, 'Session identifier'),
                'device_type' => new FieldDefinition('string', false, 'Device type (desktop, mobile, tablet)'),
            ],
            'logout' => [
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'session_id' => new FieldDefinition('string', false, 'Session identifier'),
                'session_duration' => new FieldDefinition('float', false, 'Session duration in seconds'),
            ],
            'start_trial' => [
                'plan' => new FieldDefinition('string', true, 'Trial plan name'),
                'trial_days' => new FieldDefinition('int', false, 'Trial duration in days'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'trial_type' => new FieldDefinition('string', false, 'Trial type (free, paid, extended)', ['free', 'paid', 'extended']),
            ],
            'trial_end' => [
                'plan' => new FieldDefinition('string', false, 'Plan that was trialed'),
                'converted' => new FieldDefinition('bool', false, 'Whether trial converted to paid'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'trial_days_used' => new FieldDefinition('int', false, 'Days used during trial'),
                'end_reason' => new FieldDefinition('string', false, 'Reason for trial ending'),
            ],
            'subscribe' => [
                'plan' => new FieldDefinition('string', true, 'Subscription plan name'),
                'value' => new FieldDefinition('float', true, 'Subscription amount'),
                'currency' => new FieldDefinition('string', true, 'Currency code (ISO 4217)'),
                'billing_cycle' => new FieldDefinition('string', false, 'Billing cycle (monthly, yearly)', ['monthly', 'yearly', 'quarterly', 'weekly']),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'payment_method' => new FieldDefinition('string', false, 'Payment method used'),
                'trial_converted' => new FieldDefinition('bool', false, 'Whether this is a trial conversion'),
            ],
            'plan_upgrade' => [
                'from_plan' => new FieldDefinition('string', true, 'Previous plan name'),
                'to_plan' => new FieldDefinition('string', true, 'New plan name'),
                'value' => new FieldDefinition('float', false, 'New plan price'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'revenue_impact' => new FieldDefinition('float', false, 'Monthly revenue impact (positive)'),
            ],
            'plan_downgrade' => [
                'from_plan' => new FieldDefinition('string', true, 'Previous plan name'),
                'to_plan' => new FieldDefinition('string', true, 'New plan name'),
                'value' => new FieldDefinition('float', false, 'New plan price'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'revenue_impact' => new FieldDefinition('float', false, 'Monthly revenue impact (negative)'),
                'reason' => new FieldDefinition('string', false, 'Reason for downgrade'),
            ],
            'cancellation' => [
                'plan' => new FieldDefinition('string', false, 'Cancelled plan name'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'reason' => new FieldDefinition('string', false, 'Cancellation reason'),
                'feedback' => new FieldDefinition('string', false, 'Free-text cancellation feedback'),
                'active_duration_days' => new FieldDefinition('int', false, 'Days as active subscriber'),
                'mrr_lost' => new FieldDefinition('float', false, 'Monthly recurring revenue lost'),
                'currency' => new FieldDefinition('string', false, 'Currency code (ISO 4217)'),
                'retain_offered' => new FieldDefinition('bool', false, 'Whether retention offer was made'),
            ],
            'feature_used' => [
                'feature_name' => new FieldDefinition('string', true, 'Name of the feature'),
                'feature_category' => new FieldDefinition('string', false, 'Feature category'),
                'usage_count' => new FieldDefinition('int', false, 'Number of uses in session'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
            ],
            'revenue_tracked' => [
                'amount' => new FieldDefinition('float', true, 'Revenue amount'),
                'currency' => new FieldDefinition('string', true, 'Currency code (ISO 4217)'),
                'source' => new FieldDefinition('string', false, 'Revenue source'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
                'revenue_type' => new FieldDefinition('string', false, 'Type (mrr, arr, one_time, expansion)', ['mrr', 'arr', 'one_time', 'expansion', 'contraction']),
            ],
            'plan_changed' => [
                'from_plan' => new FieldDefinition('string', false, 'Previous plan name'),
                'to_plan' => new FieldDefinition('string', false, 'New plan name'),
                'reason' => new FieldDefinition('string', false, 'Reason for change'),
                'user_id' => new FieldDefinition('string', false, 'User identifier'),
            ],

            // ── Engagement ────────────────────────────────
            'page_view' => [
                'page_title' => new FieldDefinition('string', false, 'Page title'),
                'page_location' => new FieldDefinition('string', true, 'Full URL of the page'),
                'page_referrer' => new FieldDefinition('string', false, 'Referrer URL'),
                'session_id' => new FieldDefinition('string', false, 'Session identifier'),
            ],
            'scroll_depth' => [
                'percent' => new FieldDefinition('int', true, 'Scroll depth percentage (0-100)'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
                'time_on_page' => new FieldDefinition('float', false, 'Time on page when scroll occurred'),
                'viewport_height' => new FieldDefinition('int', false, 'Viewport height in pixels'),
            ],
            'click' => [
                'element' => new FieldDefinition('string', true, 'Clicked element identifier or text'),
                'element_type' => new FieldDefinition('string', false, 'Element type (button, link, etc.)'),
                'page_location' => new FieldDefinition('string', false, 'Page URL where click occurred'),
                'target_url' => new FieldDefinition('string', false, 'Target URL (for links)'),
                'position' => new FieldDefinition('string', false, 'Element position (header, footer, body)'),
            ],
            'form_start' => [
                'form_id' => new FieldDefinition('string', false, 'Form identifier'),
                'form_name' => new FieldDefinition('string', false, 'Form name'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
            ],
            'form_submit' => [
                'form_id' => new FieldDefinition('string', false, 'Form identifier'),
                'form_name' => new FieldDefinition('string', false, 'Form name'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
                'success' => new FieldDefinition('bool', false, 'Whether submission was successful'),
                'error_message' => new FieldDefinition('string', false, 'Error message if submission failed'),
            ],
            'search' => [
                'search_term' => new FieldDefinition('string', true, 'Search query string'),
                'results_count' => new FieldDefinition('int', false, 'Number of results returned'),
                'category' => new FieldDefinition('string', false, 'Search category filter'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
            ],
            'share' => [
                'method' => new FieldDefinition('string', true, 'Share method (twitter, facebook, email, copy)', ['twitter', 'facebook', 'linkedin', 'email', 'copy', 'whatsapp', 'telegram']),
                'content_type' => new FieldDefinition('string', false, 'Type of shared content'),
                'item_id' => new FieldDefinition('string', false, 'Identifier of shared content'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
            ],
            'error' => [
                'error_message' => new FieldDefinition('string', true, 'Error message'),
                'error_type' => new FieldDefinition('string', false, 'Error type/classification'),
                'severity' => new FieldDefinition('string', false, 'Error severity (critical, warning, info)', ['critical', 'warning', 'info', 'debug']),
                'page_location' => new FieldDefinition('string', false, 'Page URL where error occurred'),
                'stack_trace' => new FieldDefinition('string', false, 'Error stack trace (sanitized)'),
            ],
            'time_on_page' => [
                'duration' => new FieldDefinition('float', true, 'Time spent on page in seconds'),
                'page_location' => new FieldDefinition('string', false, 'Page URL'),
                'engagement_type' => new FieldDefinition('string', false, 'Type of engagement (active, passive)'),
            ],

            default => [],
        };

        self::$schemas[$eventName] = $schema;

        return $schema;
    }

    /**
     * Reset the cached schemas (useful for testing).
     */
    public static function reset(): void
    {
        self::$schemas = [];
    }
}
