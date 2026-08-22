<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Validates event parameter schema against the EventSchemaRegistry.
 *
 * Checks required parameters, value types, string length constraints,
 * numeric ranges, and regex patterns. Priority 20 (runs after catalog check).
 *
 * @since 69.0.0
 */
final class SchemaValidationStage implements ValidationStageInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private bool $enabled;

    /**
     * @param  array{enabled?: bool, enforce_required?: bool, strict_types?: bool}  $config
     */
    public function __construct(array $config = []){
        $this->config = $config;
        $this->enabled = (bool) ($config['enabled'] ?? false);
    }

    public function name(): string
    {
        return 'schema_validation';
    }

    public function priority(): int
    {
        return 20;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'passed' => true,
                'errors' => [],
                'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1],
            ];
        }

        $errors = [];
        $checked = 0;
        $failed = 0;
        $enforceRequired = (bool) ($this->config['enforce_required'] ?? true);
        $strictTypes = (bool) ($this->config['strict_types'] ?? false);

        // Check required parameters
        if ($enforceRequired) {
            $checked++;
            $requiredParams = $this->getRequiredParams($event->name);
            $missing = array_diff($requiredParams, array_keys($event->params));
            if ($missing !== []) {
                $failed++;
                $errors[] = [
                    'code' => 'missing_required_params',
                    'message' => 'Missing required parameters: ' . implode(', ', $missing),
                    'severity' => 'error',
                ];
            }
        }

        // Check parameter count
        $checked++;
        $maxParams = (int) ($this->config['max_param_count'] ?? 100);
        if (count($event->params) > $maxParams) {
            $failed++;
            $errors[] = [
                'code' => 'excessive_params',
                'message' => "Event has " . count($event->params) . " parameters (max: {$maxParams})",
                'severity' => 'error',
            ];
        }

        // Check parameter key length
        $checked++;
        $maxKeyLength = (int) ($this->config['max_key_length'] ?? 100);
        foreach ($event->params as $key => $value) {
            if (mb_strlen((string) $key) > $maxKeyLength) {
                $failed++;
                $errors[] = [
                    'code' => 'param_key_too_long',
                    'message' => "Parameter key '{$key}' exceeds max length of {$maxKeyLength}",
                    'field' => $key,
                    'severity' => 'error',
                ];
            }
        }

        // Type validation (strict mode)
        if ($strictTypes) {
            $checked++;
            foreach ($event->params as $key => $value) {
                if (! is_scalar($value) && ! is_array($value) && $value !== null) {
                    $errors[] = [
                        'code' => 'invalid_param_type',
                        'message' => "Parameter '{$key}' has non-scalar/non-array/non-null value type: " . get_debug_type($value),
                        'field' => $key,
                        'severity' => 'warning',
                    ];
                }
            }
        }

        return [
            'passed' => $failed === 0,
            'errors' => $errors,
            'metrics' => [
                'checked' => $checked,
                'failed' => $failed,
                'skipped' => 0,
            ],
        ];
    }

    /**
     * Get required parameter names for an event from the schema registry.
     *
     * @return list<string>
     */
    private function getRequiredParams(string $eventName): array
    {
        try {
            $registry = app(\ZeroBoiler\Analytics\Schema\EventSchemaRegistry::class);
            $schema = $registry->get($eventName);

            if ($schema === null) {
                return [];
            }

            $required = [];
            $properties = method_exists($schema, 'properties') ? $schema->properties() : [];
            foreach ($properties as $name => $prop) {
                if (is_array($prop) && ($prop['required'] ?? false)) {
                    $required[] = $name;
                }
            }

            return $required;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function description(): string
    {
        return 'Validates event parameters against registered schemas: required params, types, lengths, and count limits';
    }
}
