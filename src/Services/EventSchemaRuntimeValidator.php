<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventParameterSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Runtime event parameter validation against catalog schemas.
 *
 * Validates dispatched events against their registered parameter schemas
 * in the EventSchemaRegistry. Checks required parameters, value types,
 * string length, numeric ranges, and regex patterns.
 *
 * Provides a quality gate before event dispatch, preventing malformed
 * events from reaching analytics providers.
 *
 * Configuration is read from `zeroboiler.analytics.schema_validation`.
 *
 * @since 21.0.0
 */
final class EventSchemaRuntimeValidator
{
    private bool $enabled;

    /** @var 'strict'|'warn'|'off' How to handle validation failures */
    private string $mode;

    private bool $enforceCatalogMembership;

    private EventSchemaRegistry $registry;

    /**
     * @param  EventSchemaRegistry  $registry
     * @param  array<string, mixed>  $config  zeroboiler.analytics.schema_validation
     */
    public function __construct(EventSchemaRegistry $registry, array $config): void
    {
        $this->registry = $registry;
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->mode = (string) ($config['mode'] ?? 'warn');
        $this->enforceCatalogMembership = (bool) ($config['enforce_catalog_membership'] ?? true);
    }

    /**
     * Validate an event against its schema.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, score: float}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'errors' => [], 'warnings' => [], 'score' => 1.0];
        }

        $errors = [];
        $warnings = [];

        // Check catalog membership
        if ($this->enforceCatalogMembership && ! EventCatalog::has($event->name)) {
            if ($this->mode === 'strict') {
                $errors[] = "Event '{$event->name}' is not registered in the event catalog";
            } else {
                $warnings[] = "Event '{$event->name}' is not registered in the event catalog";
            }
        }

        // Check against parameter schema
        $schema = $this->registry->getSchema($event->name);
        if ($schema !== null) {
            $this->validateAgainstSchema($event, $schema, $errors, $warnings);
        }

        // Generic quality checks
        $this->validateEventQuality($event, $errors, $warnings);

        $totalChecks = count($errors) + count($warnings);
        $score = $totalChecks === 0 ? 1.0 : max(0.0, 1.0 - ($totalChecks * 0.1));

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'score' => round($score, 4),
        ];
    }

    /**
     * Batch-validate multiple events.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{valid: bool, total: int, passed: int, failed: int, results: list<array{valid: bool, errors: list<string>, warnings: list<string>, score: float}>}
     */
    public function validateBatch(array $events): array
    {
        $results = [];
        $failed = 0;

        foreach ($events as $event) {
            $result = $this->validate($event);
            $results[] = $result;
            if (! $result['valid']) {
                $failed++;
            }
        }

        return [
            'valid' => $failed === 0,
            'total' => count($events),
            'passed' => count($events) - $failed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Get the validation mode.
     *
     * @return 'strict'|'warn'|'off'
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Check if validation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Validate event parameters against a parameter schema.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateAgainstSchema(
        AnalyticsEvent $event,
        EventParameterSchema $schema,
        array &$errors,
        array &$warnings,
    ): void {
        $params = $event->params;
        $requiredParams = $schema->requiredParams();
        $optionalParams = $schema->optionalParams();

        // Check required parameters
        foreach ($requiredParams as $paramName) {
            if (! array_key_exists($paramName, $params)) {
                $errors[] = "Required parameter '{$paramName}' is missing for event '{$event->name}'";
            } elseif ($this->isEmptyValue($params[$paramName])) {
                $errors[] = "Required parameter '{$paramName}' is empty for event '{$event->name}'";
            }
        }

        // Validate parameter types and constraints
        $allParams = array_merge($requiredParams, $optionalParams);
        foreach ($allParams as $paramName) {
            if (! array_key_exists($paramName, $params)) {
                continue;
            }

            $value = $params[$paramName];
            $paramDef = $schema->getParam($paramName);

            if ($paramDef === null) {
                continue;
            }

            // Type check
            if ($paramDef->type !== null && ! $this->validateType($value, $paramDef->type)) {
                $errors[] = "Parameter '{$paramName}' expected type '{$paramDef->type}', got " . gettype($value);
            }

            // String length check
            if (is_string($value) && $paramDef->maxLength !== null && strlen($value) > $paramDef->maxLength) {
                $errors[] = "Parameter '{$paramName}' exceeds max length of {$paramDef->maxLength}";
            }

            // Numeric range check
            if (is_numeric($value) && $paramDef->min !== null && $value < $paramDef->min) {
                $errors[] = "Parameter '{$paramName}' value {$value} is below minimum {$paramDef->min}";
            }
            if (is_numeric($value) && $paramDef->max !== null && $value > $paramDef->max) {
                $errors[] = "Parameter '{$paramName}' value {$value} exceeds maximum {$paramDef->max}";
            }

            // Regex pattern check
            if (is_string($value) && $paramDef->pattern !== null) {
                $pattern = $paramDef->pattern;
                if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $value) !== 1) {
                    $errors[] = "Parameter '{$paramName}' does not match required pattern '{$paramDef->pattern}'";
                }
            }
        }

        // Warn about unrecognized parameters
        foreach (array_keys($params) as $key) {
            if (! in_array($key, $allParams, true) && ! str_starts_with($key, '_')) {
                $warnings[] = "Unregistered parameter '{$key}' for event '{$event->name}'";
            }
        }
    }

    /**
     * Perform generic event quality checks.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateEventQuality(AnalyticsEvent $event, array &$errors, array &$warnings): void
    {
        // Check event name format
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $event->name)) {
            $errors[] = "Event name '{$event->name}' contains invalid characters";
        }

        // Check for excessively large parameter payloads
        $jsonSize = strlen(json_encode($event->params));
        if ($jsonSize > 64000) {
            $errors[] = 'Event payload exceeds 64KB limit';
        } elseif ($jsonSize > 32000) {
            $warnings[] = 'Event payload is large (' . $jsonSize . ' bytes), consider reducing parameter count';
        }

        // Check parameter count
        $paramCount = count($event->params);
        if ($paramCount > 50) {
            $warnings[] = "Event has {$paramCount} parameters, consider reducing complexity";
        }

        // Warn about empty params for known events that typically have params
        if ($paramCount === 0 && in_array($event->name, ['purchase', 'add_to_cart', 'sign_up', 'search'], true)) {
            $warnings[] = "Event '{$event->name}' has no parameters — typical usage requires parameters";
        }
    }

    /**
     * Check if a value is considered empty.
     */
    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    /**
     * Validate a value against an expected type.
     */
    private function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double', 'number' => is_int($value) || is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'null' => $value === null,
            default => true, // Unknown types pass validation
        };
    }
}
