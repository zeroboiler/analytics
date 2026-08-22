<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchema;
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
    public function __construct(EventSchemaRegistry $registry, array $config){
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

        if ($this->enforceCatalogMembership && ! EventCatalog::has($event->name)) {
            if ($this->mode === 'strict') {
                $errors[] = "Event '{$event->name}' is not registered in the event catalog";
            } else {
                $warnings[] = "Event '{$event->name}' is not registered in the event catalog";
            }
        }

        $schema = $this->registry->get($event->name);
        if ($schema !== null) {
            $this->validateAgainstSchema($event, $schema, $errors, $warnings);
        }

        // Generic quality checks
        $this->validateEventQuality($event, $errors, $warnings);

        $totalChecks = count($errors) + count($warnings);
        $score = $totalChecks === 0 ? 1.0 : max(0.0, 1.0 - ($totalChecks * 0.1));

        return [
            'valid' => $this->mode === 'strict' ? empty($errors) : true,
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
     * Validate event parameters against a schema definition.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateAgainstSchema(
        AnalyticsEvent $event,
        EventSchema $schema,
        array &$errors,
        array &$warnings,
    ): void {
        $params = $event->params;

        foreach ($schema->requiredParams as $paramName => $paramDef) {
            if (! array_key_exists($paramName, $params)) {
                $errors[] = "Required parameter '{$paramName}' is missing for event '{$event->name}'";
            } elseif ($this->isEmptyValue($params[$paramName])) {
                $errors[] = "Required parameter '{$paramName}' is empty for event '{$event->name}'";
            } else {
                // Type check
                $typeError = $paramDef->validateType($params[$paramName]);
                if ($typeError !== null) {
                    $errors[] = "Parameter '{$paramName}': {$typeError}";
                }
            }
        }

        foreach ($schema->optionalParams as $paramName => $paramDef) {
            if (! array_key_exists($paramName, $params)) {
                continue;
            }

            $value = $params[$paramName];
            if ($value !== null) {
                $typeError = $paramDef->validateType($value);
                if ($typeError !== null) {
                    $errors[] = "Parameter '{$paramName}': {$typeError}";
                }
            }
        }

        // Warn about unrecognized parameters
        $knownParams = array_merge(
            array_keys($schema->requiredParams),
            array_keys($schema->optionalParams),
        );
        foreach (array_keys($params) as $key) {
            if (! in_array($key, $knownParams, true) && ! str_starts_with($key, '_')) {
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
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $event->name)) {
            $errors[] = "Event name '{$event->name}' contains invalid characters";
        }

        $jsonSize = strlen(json_encode($event->params));
        if ($jsonSize > 64000) {
            $errors[] = 'Event payload exceeds 64KB limit';
        } elseif ($jsonSize > 32000) {
            $warnings[] = 'Event payload is large (' . $jsonSize . ' bytes), consider reducing parameter count';
        }

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
}
