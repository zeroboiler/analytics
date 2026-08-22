<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventFieldValidator;

/**
 * Pipeline validation stage for config-driven field validation and coercion.
 *
 * Validates event parameters against per-event field rules defined in config:
 * - Required field checks
 * - Type enforcement (string, int, float, bool, array, numeric)
 * - Value range constraints (min/max)
 * - Enum constraints (whitelist of allowed values)
 * - Regex pattern matching
 * - Structural format validation (email, url, uuid, currency_code, iso_date)
 * - Automatic type coercion before validation
 * - Default values for missing fields
 *
 * Runs after catalog membership check (priority 15) but before schema validation
 * (priority 20), allowing this lightweight config-driven stage to catch common
 * issues before the heavier schema-based validation runs.
 *
 * Note: AnalyticsEvent is readonly, so coerced params are returned in the
 * result metadata. The caller (pipeline or manager) is responsible for
 * creating a new event with coerced params if validation passes.
 *
 * @see \ZeroBoiler\Analytics\Services\EventFieldValidator
 * @see \ZeroBoiler\Analytics\Services\EventFieldCoercer
 *
 * @since 125.0.0
 */
final class FieldValidationStage implements ValidationStageInterface
{
    private EventFieldValidator $validator;

    /**
     * @param  EventFieldValidator  $validator  Config-driven field validator
     */
    public function __construct(EventFieldValidator $validator){
        $this->validator = $validator;
    }

    public function name(): string
    {
        return 'field_validation';
    }

    public function priority(): int
    {
        return 15;
    }

    public function enabled(): bool
    {
        return $this->validator->isEnabled();
    }

    /**
     * Validate event fields against configured rules.
     *
     * Returns coerced params in the metrics so the caller can create
     * a new immutable event with updated parameters if needed.
     *
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int, coerced: int, coerced_params?: array<string, mixed>}}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->validator->isEnabled()) {
            return [
                'passed' => true,
                'errors' => [],
                'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1, 'coerced' => 0],
            ];
        }

        $result = $this->validator->validate($event);

        $errors = [];
        foreach ($result['errors'] as $error) {
            $errors[] = [
                'code' => "field.{$error['rule']}",
                'message' => $error['message'],
                'field' => $error['field'],
                'severity' => 'error',
            ];
        }

        $metrics = [
            'checked' => 1,
            'failed' => $result['valid'] ? 0 : 1,
            'skipped' => 0,
            'coerced' => $result['coercions'],
        ];

        // Include coerced params in metrics for caller to use if needed
        if ($result['coercions'] > 0) {
            $metrics['coerced_params'] = $result['coerced_params'];
        }

        return [
            'passed' => $result['valid'],
            'errors' => $errors,
            'metrics' => $metrics,
        ];
    }

    /**
     * Get a human-readable description of what this stage validates.
     */
    public function description(): string
    {
        return 'Validates event parameters against config-driven field rules (required, type, min/max, enum, regex, format) with automatic type coercion.';
    }

    /**
     * Get the underlying validator instance.
     */
    public function validator(): EventFieldValidator
    {
        return $this->validator;
    }
}
