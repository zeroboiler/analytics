<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Immutable DTO representing a single property validation violation.
 *
 * Each violation captures the error code, message, severity, affected parameter,
 * and optional expected/actual type information for type mismatches.
 *
 * @since 231.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\PropertyValidationResult
 */
final readonly class PropertyViolation
{
    /**
     * @param  string  $code  Machine-readable error code (e.g., 'type_mismatch', 'missing_required')
     * @param  string  $message  Human-readable error description
     * @param  string  $severity  Severity level: 'error', 'warning', or 'info'
     * @param  string|null  $param  Affected parameter name (null for global violations)
     * @param  string|null  $expected  Expected type (for type mismatch violations)
     * @param  string|null  $actual  Actual type received (for type mismatch violations)
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'error',
        public ?string $param = null,
        public ?string $expected = null,
        public ?string $actual = null,
    ){}

    /**
     * Check if this violation is an error (not warning or info).
     */
    public function isError(): bool
    {
        return $this->severity === EventPropertyTypeValidator::SEVERITY_ERROR;
    }

    /**
     * Check if this violation is a warning.
     */
    public function isWarning(): bool
    {
        return $this->severity === EventPropertyTypeValidator::SEVERITY_WARNING;
    }

    /**
     * Convert to array representation.
     *
     * @return array{code: string, message: string, severity: string, param: string|null, expected: string|null, actual: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'param' => $this->param,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }

    /**
     * Create from an array (round-trip deserialization).
     *
     * @param  array{code: string, message: string, severity?: string, param?: string|null, expected?: string|null, actual?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: is_string($data['code'] ?? null) ? $data['code'] : '',
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : 'error',
            param: isset($data['param']) && is_string($data['param']) ? $data['param'] : null,
            expected: isset($data['expected']) && is_string($data['expected']) ? $data['expected'] : null,
            actual: isset($data['actual']) && is_string($data['actual']) ? $data['actual'] : null,
        );
    }
}
