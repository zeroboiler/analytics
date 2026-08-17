<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Immutable DTO representing the result of event property validation.
 *
 * Contains the event name, overall validity flag, list of violations (blocking),
 * and list of warnings (non-blocking). Provides convenience methods for checking
 * validity, counting issues, and converting to structured arrays.
 *
 * @since 231.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventPropertyTypeValidator
 * @see \ZeroBoiler\Analytics\Services\PropertyViolation
 */
final readonly class PropertyValidationResult
{
    /**
     * @param  string  $eventName  The validated event name
     * @param  bool  $valid  Whether the event passed validation (no violations)
     * @param  list<PropertyViolation>  $violations  Blocking violations (errors)
     * @param  list<PropertyViolation>  $warnings  Non-blocking warnings
     */
    public function __construct(
        public string $eventName,
        public bool $valid,
        public array $violations = [],
        public array $warnings = [],
    ): void {}

    /**
     * Check if validation passed with no violations.
     */
    public function passed(): bool
    {
        return $this->valid;
    }

    /**
     * Check if validation failed (has violations).
     */
    public function failed(): bool
    {
        return ! $this->valid;
    }

    /**
     * Get the total number of violations.
     */
    public function violationCount(): int
    {
        return count($this->violations);
    }

    /**
     * Get the total number of warnings.
     */
    public function warningCount(): int
    {
        return count($this->warnings);
    }

    /**
     * Get the total number of issues (violations + warnings).
     */
    public function issueCount(): int
    {
        return $this->violationCount() + $this->warningCount();
    }

    /**
     * Check if there are any type mismatch violations.
     */
    public function hasTypeMismatches(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->code === EventPropertyTypeValidator::CODE_TYPE_MISMATCH) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if there are any missing required parameter violations.
     */
    public function hasMissingRequired(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->code === EventPropertyTypeValidator::CODE_MISSING_REQUIRED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get violations for a specific parameter.
     *
     * @return list<PropertyViolation>
     */
    public function violationsForParam(string $paramName): array
    {
        return array_values(array_filter(
            $this->violations,
            fn (PropertyViolation $v): bool => $v->param === $paramName,
        ));
    }

    /**
     * Get only the error-level violations (filters out non-error severity).
     *
     * @return list<PropertyViolation>
     */
    public function errorsOnly(): array
    {
        return array_values(array_filter(
            $this->violations,
            fn (PropertyViolation $v): bool => $v->isError(),
        ));
    }

    /**
     * Convert to a compact array representation.
     *
     * @return array{event_name: string, valid: bool, violation_count: int, warning_count: int, violations: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'valid' => $this->valid,
            'violation_count' => $this->violationCount(),
            'warning_count' => $this->warningCount(),
            'violations' => array_map(
                fn (PropertyViolation $v): array => $v->toArray(),
                $this->violations,
            ),
            'warnings' => array_map(
                fn (PropertyViolation $v): array => $v->toArray(),
                $this->warnings,
            ),
        ];
    }

    /**
     * Create from an array (round-trip deserialization).
     *
     * @param  array{event_name: string, valid: bool, violations?: list<array<string, mixed>>, warnings?: list<array<string, mixed>>}  $data
     */
    public static function fromArray(array $data): self
    {
        $violations = [];
        $warnings = [];

        foreach ($data['violations'] ?? [] as $violationData) {
            if (is_array($violationData)) {
                $violations[] = PropertyViolation::fromArray($violationData);
            }
        }

        foreach ($data['warnings'] ?? [] as $warningData) {
            if (is_array($warningData)) {
                $warnings[] = PropertyViolation::fromArray($warningData);
            }
        }

        return new self(
            eventName: is_string($data['event_name'] ?? null) ? $data['event_name'] : '',
            valid: (bool) ($data['valid'] ?? true),
            violations: $violations,
            warnings: $warnings,
        );
    }
}
