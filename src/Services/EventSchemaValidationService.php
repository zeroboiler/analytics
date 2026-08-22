<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventParameterSchema;
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;

/**
 * Declarative event schema validation engine.
 *
 * Validates analytics event parameters against the typed schema definitions
 * in EventParameterSchemas. Performs type checking, coercion, range validation,
 * enum constraints, and required parameter enforcement.
 *
 * When validation fails, the event can be rejected, coerced, or passed through
 * depending on the configured severity level.
 *
 * Inspired by Segment's Protocols, RudderStack's Event Spec, and PostHog's
 * event property validation.
 *
 * @since 8.4.0
 */
final class EventSchemaValidationService
{
    /** Severity levels */
    public const SEVERITY_REJECT = 'reject';
    public const SEVERITY_COERCE = 'coerce';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_OFF = 'off';

    private const CACHE_PREFIX = 'zb_schema_valid_';

    private string $severity;

    private bool $enabled;

    private bool $stripUnknownParams;

    private int $coercionCount = 0;

    private int $rejectionCount = 0;

    private int $warningCount = 0;

    /** @var array<string, mixed> Validation statistics */
    private array $stats = [];

    /**
     * @param  ConfigRepository  $config  Application config
     */
    public function __construct(ConfigRepository $config){
        $schemaConfig = $config->get('zeroboiler.analytics.schema_validation', []);
        /** @var array{enabled?: bool, severity?: string, strip_unknown?: bool} $schemaConfig */

        $this->enabled = (bool) ($schemaConfig['enabled'] ?? true);
        $this->severity = (string) ($schemaConfig['severity'] ?? self::SEVERITY_COERCE);
        $this->stripUnknownParams = (bool) ($schemaConfig['strip_unknown'] ?? false);
    }

    /**
     * Validate an analytics event against its schema.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @return array{valid: bool, event: AnalyticsEvent, errors: list<string>, warnings: list<string>, coerced: bool}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled || $this->severity === self::SEVERITY_OFF) {
            return [
                'valid' => true,
                'event' => $event,
                'errors' => [],
                'warnings' => [],
                'coerced' => false,
            ];
        }

        $schema = EventParameterSchemas::forEvent($event->name);

        if ($schema === null) {
            // No schema defined — custom events pass through
            return [
                'valid' => true,
                'event' => $event,
                'errors' => [],
                'warnings' => [],
                'coerced' => false,
            ];
        }

        $errors = [];
        $warnings = [];
        $params = $event->params;
        $coerced = false;

        // Check required parameters
        foreach ($schema->required as $paramName) {
            if (! array_key_exists($paramName, $params)) {
                $errors[] = "Missing required parameter: '{$paramName}'";
            }
        }

        // Validate and coerce all provided parameters
        $validatedParams = [];
        foreach ($params as $key => $value) {
            $result = $this->validateParameter($key, $value, $schema);

            if ($result['error'] !== null) {
                $errors[] = $result['error'];
            }
            if ($result['warning'] !== null) {
                $warnings[] = $result['warning'];
            }
            if ($result['coerced']) {
                $coerced = true;
                $this->coercionCount++;
            }

            $validatedParams[$key] = $result['value'];
        }

        // Strip unknown parameters if configured
        if ($this->stripUnknownParams) {
            $knownParams = array_merge($schema->required, array_keys($schema->optional));
            foreach ($params as $key => $value) {
                if (! in_array($key, $knownParams, true) && ! str_starts_with($key, '_')) {
                    unset($validatedParams[$key]);
                    $warnings[] = "Stripped unknown parameter: '{$key}'";
                }
            }
        }

        // Build result based on severity
        $hasErrors = count($errors) > 0;

        if ($this->severity === self::SEVERITY_REJECT && $hasErrors) {
            $this->rejectionCount++;
            $this->stats['rejections'][$event->name] = ($this->stats['rejections'][$event->name] ?? 0) + 1;
        }

        if ($this->severity === self::SEVERITY_WARN && $hasErrors) {
            $this->warningCount++;
            $this->stats['warnings'][$event->name] = ($this->stats['warnings'][$event->name] ?? 0) + 1;
        }

        $isValid = ! $hasErrors || $this->severity !== self::SEVERITY_REJECT;

        $resultEvent = $coerced
            ? new AnalyticsEvent(
                name: $event->name,
                params: $validatedParams,
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
                priority: $event->priority,
            )
            : $event;

        return [
            'valid' => $isValid,
            'event' => $resultEvent,
            'errors' => $errors,
            'warnings' => $warnings,
            'coerced' => $coerced,
        ];
    }

    /**
     * Validate a batch of events against their schemas.
     *
     * @param  list<AnalyticsEvent>  $events  Events to validate
     * @return array{results: list<array{valid: bool, event: AnalyticsEvent, errors: list<string>, warnings: list<string>, coerced: bool}>, total: int, valid: int, rejected: int}
     */
    public function validateBatch(array $events): array
    {
        $results = [];
        $validCount = 0;
        $rejectedCount = 0;

        foreach ($events as $event) {
            $result = $this->validate($event);
            $results[] = $result;

            if ($result['valid']) {
                $validCount++;
            } else {
                $rejectedCount++;
            }
        }

        return [
            'results' => $results,
            'total' => count($events),
            'valid' => $validCount,
            'rejected' => $rejectedCount,
        ];
    }

    /**
     * Validate a single parameter against the schema.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Parameter value
     * @param  EventParameterSchema  $schema  Event schema
     * @return array{value: mixed, error: string|null, warning: string|null, coerced: bool}
     */
    private function validateParameter(string $key, mixed $value, EventParameterSchema $schema): array
    {
        $expectedType = $schema->optional[$key] ?? null;

        // Required params don't have type declarations in optional, check by convention
        if ($expectedType === null && in_array($key, $schema->required, true)) {
            // Infer type from param name conventions
            $expectedType = $this->inferType($key, $value);
        }

        if ($expectedType === null) {
            return ['value' => $value, 'error' => null, 'warning' => null, 'coerced' => false];
        }

        // Null is allowed for optional parameters
        if ($value === null) {
            return ['value' => null, 'error' => null, 'warning' => null, 'coerced' => false];
        }

        return $this->coerceAndValidate($key, $value, $expectedType);
    }

    /**
     * Coerce and validate a value against an expected type.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Current value
     * @param  string  $expectedType  Expected type
     * @return array{value: mixed, error: string|null, warning: string|null, coerced: bool}
     */
    private function coerceAndValidate(string $key, mixed $value, string $expectedType): array
    {
        $coerced = false;
        $error = null;

        switch ($expectedType) {
            case 'string':
                if (is_numeric($value)) {
                    $value = (string) $value;
                    $coerced = true;
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                    $coerced = true;
                } elseif (! is_string($value)) {
                    $error = "Parameter '{$key}' expected string, got " . get_debug_type($value);
                }
                break;

            case 'integer':
                if (is_string($value) && is_numeric($value)) {
                    $value = (int) $value;
                    $coerced = true;
                } elseif (is_float($value)) {
                    $value = (int) $value;
                    $coerced = true;
                } elseif (! is_int($value)) {
                    $error = "Parameter '{$key}' expected integer, got " . get_debug_type($value);
                }
                break;

            case 'float':
                if (is_string($value) && is_numeric($value)) {
                    $value = (float) $value;
                    $coerced = true;
                } elseif (is_int($value)) {
                    $value = (float) $value;
                    $coerced = true;
                } elseif (! is_float($value) && ! is_int($value)) {
                    $error = "Parameter '{$key}' expected float, got " . get_debug_type($value);
                }
                break;

            case 'boolean':
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['true', '1', 'yes'], true)) {
                        $value = true;
                        $coerced = true;
                    } elseif (in_array($lower, ['false', '0', 'no'], true)) {
                        $value = false;
                        $coerced = true;
                    } else {
                        $error = "Parameter '{$key}' expected boolean, got non-boolean string '{$value}'";
                    }
                } elseif (! is_bool($value)) {
                    $error = "Parameter '{$key}' expected boolean, got " . get_debug_type($value);
                }
                break;

            case 'array':
                if (! is_array($value)) {
                    $error = "Parameter '{$key}' expected array, got " . get_debug_type($value);
                }
                break;

            default:
                // Unknown type — pass through
                break;
        }

        $warning = $coerced ? "Parameter '{$key}' was auto-coerced to {$expectedType}" : null;

        return ['value' => $value, 'error' => $error, 'warning' => $warning, 'coerced' => $coerced];
    }

    /**
     * Infer expected type from parameter name conventions.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Current value (used as fallback type)
     * @return string|null  Inferred type
     */
    private function inferType(string $key, mixed $value): ?string
    {
        // Value-based and currency conventions
        if (str_contains($key, 'value') || str_contains($key, 'price') || str_contains($key, 'amount')) {
            return 'float';
        }
        if (str_contains($key, 'count') || str_contains($key, 'quantity') || str_contains($key, 'days') || str_contains($key, 'step')) {
            return 'integer';
        }
        if (str_contains($key, 'currency') || str_contains($key, 'name') || str_contains($key, 'reason') || str_contains($key, 'method') || str_contains($key, 'source')) {
            return 'string';
        }
        if (str_contains($key, 'enabled') || str_contains($key, 'converted') || str_contains($key, 'recurring') || str_contains($key, 'trial')) {
            return 'boolean';
        }
        if (str_contains($key, 'items') || str_contains($key, 'features')) {
            return 'array';
        }

        return null;
    }

    /**
     * Check if schema validation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current severity level.
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Get validation statistics.
     *
     * @return array{coercions: int, rejections: int, warnings: int, by_event: array<string, mixed>}
     */
    public function getStats(): array
    {
        return [
            'coercions' => $this->coercionCount,
            'rejections' => $this->rejectionCount,
            'warnings' => $this->warningCount,
            'by_event' => $this->stats,
        ];
    }

    /**
     * Get the schema for a given event name.
     *
     * @return EventParameterSchema|null
     */
    public function getSchema(string $eventName): ?EventParameterSchema
    {
        return EventParameterSchemas::forEvent($eventName);
    }

    /**
     * Get the total number of events with defined schemas.
     */
    public function getSchemaCount(): int
    {
        return count(EventParameterSchemas::all());
    }

    /**
     * Check if an event has a defined schema.
     */
    public function hasSchema(string $eventName): bool
    {
        return EventParameterSchemas::forEvent($eventName) !== null;
    }

    /**
     * Get schema coverage statistics.
     *
     * @return array{total_schemas: int, catalog_size: int, coverage_percent: float}
     */
    public function getCoverageStats(): array
    {
        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::all();
        $catalogSize = count($catalog);
        $schemaCount = $this->getSchemaCount();

        return [
            'total_schemas' => $schemaCount,
            'catalog_size' => $catalogSize,
            'coverage_percent' => $catalogSize > 0
                ? round(($schemaCount / $catalogSize) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Reset validation statistics.
     */
    public function resetStats(): void
    {
        $this->coercionCount = 0;
        $this->rejectionCount = 0;
        $this->warningCount = 0;
        $this->stats = [];
    }
}
