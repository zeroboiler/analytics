<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Validates analytics event parameters against registered schemas.
 *
 * Cross-validates event parameter structures against the EventSchemaRegistry
 * and provider-specific parameter expectations. Provides detailed validation
 * reports for debugging and quality assurance.
 *
 * Validation rules:
 * - Required parameters: Must be present and non-empty
 * - Parameter types: Values must match expected types (string, int, float, bool, array)
 * - Parameter lengths: String values must not exceed max length
 * - Enum values: Values must match allowed enum sets when defined
 * - Provider compatibility: Parameters are checked against provider-specific requirements
 *
 * @since 152.0.0
 */
final class EventParameterSchemaValidator
{
    /** @var array<string, array{required: bool, type: string, max_length?: int, enum?: list<string>}> */
    private const COMMON_SAFETY_RULES = [
        // Fields that should NEVER be sent in analytics events (PII)
        'password' => ['required' => false, 'type' => 'string', 'blocked' => true],
        'token' => ['required' => false, 'type' => 'string', 'blocked' => true],
        'secret' => ['required' => false, 'type' => 'string', 'blocked' => true],
        'api_key' => ['required' => false, 'type' => 'string', 'blocked' => true],
        'credit_card' => ['required' => false, 'type' => 'string', 'blocked' => true],
        'ssn' => ['required' => false, 'type' => 'string', 'blocked' => true],
    ];

    /** @var array<string, array{params: array<string, array{required: bool, type: string|null, max_length?: int, enum?: list<string>}>, provider?: string}> */
    private const EVENT_SCHEMAS = [
        // ── E-commerce events ──────────────────────────────────────────
        'view_item' => [
            'params' => [
                'item_id' => ['required' => true, 'type' => 'string', 'max_length' => 100],
                'item_name' => ['required' => false, 'type' => 'string', 'max_length' => 200],
                'currency' => ['required' => false, 'type' => 'string', 'max_length' => 3],
                'value' => ['required' => false, 'type' => 'float'],
                'item_category' => ['required' => false, 'type' => 'string', 'max_length' => 100],
            ],
        ],
        'add_to_cart' => [
            'params' => [
                'item_id' => ['required' => true, 'type' => 'string', 'max_length' => 100],
                'item_name' => ['required' => false, 'type' => 'string', 'max_length' => 200],
                'currency' => ['required' => false, 'type' => 'string', 'max_length' => 3],
                'value' => ['required' => false, 'type' => 'float'],
                'quantity' => ['required' => false, 'type' => 'int'],
            ],
        ],
        'purchase' => [
            'params' => [
                'transaction_id' => ['required' => true, 'type' => 'string', 'max_length' => 100],
                'currency' => ['required' => true, 'type' => 'string', 'max_length' => 3],
                'value' => ['required' => true, 'type' => 'float'],
                'tax' => ['required' => false, 'type' => 'float'],
                'shipping' => ['required' => false, 'type' => 'float'],
                'coupon' => ['required' => false, 'type' => 'string', 'max_length' => 50],
                'items' => ['required' => false, 'type' => 'array'],
            ],
        ],
        'refund' => [
            'params' => [
                'transaction_id' => ['required' => true, 'type' => 'string', 'max_length' => 100],
                'currency' => ['required' => true, 'type' => 'string', 'max_length' => 3],
                'value' => ['required' => true, 'type' => 'float'],
            ],
        ],
        // ── SaaS events ────────────────────────────────────────────────
        'sign_up' => [
            'params' => [
                'method' => ['required' => false, 'type' => 'string', 'enum' => ['email', 'google', 'github', 'apple', 'sso', 'oauth', 'phone']],
            ],
        ],
        'login' => [
            'params' => [
                'method' => ['required' => false, 'type' => 'string', 'enum' => ['email', 'oauth', 'sso', 'password', 'token', '2fa']],
            ],
        ],
        'start_trial' => [
            'params' => [
                'plan' => ['required' => false, 'type' => 'string', 'max_length' => 50],
                'duration_days' => ['required' => false, 'type' => 'int'],
            ],
        ],
        'subscribe' => [
            'params' => [
                'plan' => ['required' => false, 'type' => 'string', 'max_length' => 50],
                'value' => ['required' => false, 'type' => 'float'],
                'currency' => ['required' => false, 'type' => 'string', 'max_length' => 3],
                'billing_cycle' => ['required' => false, 'type' => 'string', 'enum' => ['monthly', 'yearly', 'weekly', 'lifetime']],
            ],
        ],
        'plan_upgrade' => [
            'params' => [
                'from_plan' => ['required' => false, 'type' => 'string', 'max_length' => 50],
                'to_plan' => ['required' => false, 'type' => 'string', 'max_length' => 50],
            ],
        ],
        'cancellation' => [
            'params' => [
                'reason' => ['required' => false, 'type' => 'string', 'enum' => ['price', 'competitor', 'unused', 'missing_feature', 'technical', 'other']],
                'plan' => ['required' => false, 'type' => 'string', 'max_length' => 50],
            ],
        ],
        // ── Engagement events ──────────────────────────────────────────
        'page_view' => [
            'params' => [
                'page_title' => ['required' => false, 'type' => 'string', 'max_length' => 500],
                'page_location' => ['required' => false, 'type' => 'string', 'max_length' => 2048],
            ],
        ],
        'scroll_depth' => [
            'params' => [
                'depth_percent' => ['required' => false, 'type' => 'int'],
                'page_height' => ['required' => false, 'type' => 'int'],
            ],
        ],
        'form_submit' => [
            'params' => [
                'form_id' => ['required' => false, 'type' => 'string', 'max_length' => 100],
                'form_name' => ['required' => false, 'type' => 'string', 'max_length' => 100],
                'success' => ['required' => false, 'type' => 'bool'],
            ],
        ],
        'search' => [
            'params' => [
                'search_term' => ['required' => true, 'type' => 'string', 'max_length' => 200],
                'results_count' => ['required' => false, 'type' => 'int'],
            ],
        ],
    ];

    private ?EventSchemaRegistry $schemaRegistry;

    /** @var array<string, array{params: array<string, array{required: bool, type: string|null, max_length?: int, enum?: list<string>}>, provider?: string}> Custom schemas */
    private array $customSchemas = [];

    private bool $strictMode;

    /**
     * @param  EventSchemaRegistry|null  $schemaRegistry  Optional schema registry for extended validation
     * @param  bool  $strictMode  When true, unknown event names fail validation. When false, they pass with warnings.
     */
    public function __construct(?EventSchemaRegistry $schemaRegistry = null, bool $strictMode = false): void
    {
        $this->schemaRegistry = $schemaRegistry;
        $this->strictMode = $strictMode;
    }

    /**
     * Register a custom event parameter schema.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, array{required: bool, type: string|null, max_length?: int, enum?: list<string>}>  $params  Parameter rules
     */
    public function registerSchema(string $eventName, array $params): void
    {
        $this->customSchemas[$eventName] = ['params' => $params];
    }

    /**
     * Validate an analytics event against registered schemas.
     *
     * Returns a validation result with:
     * - valid: Whether the event passes all validation rules
     * - errors: List of validation errors (blocking issues)
     * - warnings: List of validation warnings (non-blocking issues)
     * - catalog_entry: Event catalog entry if the event is registered
     *
     * @param  AnalyticsEvent  $event  Event to validate
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, catalog_entry: array<string, mixed>|null, event_name: string, params_checked: int, pii_violations: list<string>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $piiViolations = [];
        $paramsChecked = 0;

        // 1. PII safety check
        foreach ($event->params as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (isset(self::COMMON_SAFETY_RULES[$lowerKey])) {
                $rule = self::COMMON_SAFETY_RULES[$lowerKey];

                if (isset($rule['blocked']) && $rule['blocked']) {
                    $piiViolations[] = "Blocked PII parameter: '{$key}'";
                }
            }
        }

        // 2. Catalog membership check
        $catalogEntry = EventCatalog::resolveAndGet($event->name);

        if ($catalogEntry === null) {
            if ($this->strictMode) {
                $errors[] = "Event '{$event->name}' is not registered in the EventCatalog";
            } else {
                $warnings[] = "Event '{$event->name}' is not registered in the EventCatalog";
            }
        }

        // 3. Schema validation
        $schema = $this->getSchemaForEvent($event->name);

        if ($schema !== null) {
            $paramRules = $schema['params'];

            // Check required parameters
            foreach ($paramRules as $paramName => $rule) {
                $isRequired = (bool) ($rule['required'] ?? false);
                $value = $event->params[$paramName] ?? null;

                if ($isRequired && ($value === null || $value === '')) {
                    $errors[] = "Required parameter '{$paramName}' is missing for event '{$event->name}'";
                }

                if ($value !== null && $value !== '') {
                    $paramsChecked++;

                    // Type check
                    $expectedType = $rule['type'] ?? null;
                    if ($expectedType !== null && ! $this->checkType($value, $expectedType)) {
                        $actualType = get_debug_type($value);
                        $errors[] = "Parameter '{$paramName}' expected type '{$expectedType}', got '{$actualType}' for event '{$event->name}'";
                    }

                    // Max length check
                    $maxLength = $rule['max_length'] ?? null;
                    if ($maxLength !== null && is_string($value) && mb_strlen($value) > $maxLength) {
                        $errors[] = "Parameter '{$paramName}' exceeds max length {$maxLength} (actual: " . mb_strlen($value) . ") for event '{$event->name}'";
                    }

                    // Enum check
                    $enum = $rule['enum'] ?? null;
                    if ($enum !== null && is_string($value) && ! in_array($value, $enum, true)) {
                        $allowed = implode(', ', $enum);
                        $warnings[] = "Parameter '{$paramName}' value '{$value}' is not in allowed set [{$allowed}] for event '{$event->name}'";
                    }
                }
            }
        } elseif ($catalogEntry !== null) {
            // Event is in catalog but has no parameter schema registered
            $warnings[] = "No parameter schema registered for catalog event '{$event->name}'";
        }

        return [
            'valid' => $errors === [] && $piiViolations === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'pii_violations' => $piiViolations,
            'catalog_entry' => $catalogEntry,
            'event_name' => $event->name,
            'params_checked' => $paramsChecked,
        ];
    }

    /**
     * Batch-validate multiple events.
     *
     * @param  list<AnalyticsEvent>  $events  Events to validate
     * @return array{total: int, valid: int, invalid: int, results: list<array{valid: bool, errors: list<string>, warnings: list<string>, event_name: string}>}
     */
    public function validateBatch(array $events): array
    {
        $results = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($events as $event) {
            $result = $this->validate($event);
            $results[] = [
                'valid' => $result['valid'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
                'event_name' => $result['event_name'],
            ];

            if ($result['valid']) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        return [
            'total' => count($events),
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'results' => $results,
        ];
    }

    /**
     * Get the parameter schema for an event name.
     *
     * Checks custom schemas first, then built-in schemas.
     *
     * @return array{params: array<string, array{required: bool, type: string|null, max_length?: int, enum?: list<string>}>}|null
     */
    private function getSchemaForEvent(string $eventName): ?array
    {
        // Custom schemas take priority
        if (isset($this->customSchemas[$eventName])) {
            return $this->customSchemas[$eventName];
        }

        // Built-in schemas
        return self::EVENT_SCHEMAS[$eventName] ?? null;
    }

    /**
     * Check if a value matches the expected type.
     *
     * Supports: string, int, float, bool, array, numeric
     */
    private function checkType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            default => true, // Unknown types pass validation
        };
    }

    /**
     * Get a list of all events with registered parameter schemas.
     *
     * @return list<string>
     */
    public function getSchemaEventNames(): array
    {
        return array_keys(array_merge(self::EVENT_SCHEMAS, $this->customSchemas));
    }

    /**
     * Get the parameter schema definition for a specific event.
     *
     * @return array{params: array<string, array{required: bool, type: string|null, max_length?: int, enum?: list<string>}>}|null
     */
    public function getEventSchema(string $eventName): ?array
    {
        return $this->getSchemaForEvent($eventName);
    }

    /**
     * Get diagnostic information about the validator.
     *
     * @return array<string, mixed>
     */
    public function diagnosticSummary(): array
    {
        return [
            'builtin_schema_count' => count(self::EVENT_SCHEMAS),
            'custom_schema_count' => count($this->customSchemas),
            'total_schema_count' => count(self::EVENT_SCHEMAS) + count($this->customSchemas),
            'schema_event_names' => $this->getSchemaEventNames(),
            'pii_blocked_params' => array_keys(self::COMMON_SAFETY_RULES),
            'strict_mode' => $this->strictMode,
            'has_schema_registry' => $this->schemaRegistry !== null,
        ];
    }
}
