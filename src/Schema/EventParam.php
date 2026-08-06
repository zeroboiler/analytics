<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Defines the type and constraints for a single event parameter.
 *
 * Used within EventSchema to describe required and optional
 * event parameters with type validation and sanitization.
 */
final readonly class EventParam
{
    /**
     * @param  string  $type  Expected type: 'string', 'int', 'float', 'bool', 'array'
     * @param  int|null  $maxLength  Maximum string length (for string type)
     * @param  int|float|null  $min  Minimum numeric value (for int/float type)
     * @param  int|float|null  $max  Maximum numeric value (for int/float type)
     * @param  string  $description  Human-readable description
     */
    public function __construct(
        public string $type = 'string',
        public ?int $maxLength = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public string $description = '',
    ) {}

    /**
     * Validate the value matches the expected type.
     *
     * Returns an error string if validation fails, null if valid.
     */
    public function validateType(mixed $value): ?string
    {
        return match ($this->type) {
            'string' => is_string($value) ? null : $this->typeError('string', $value),
            'int' => (is_int($value) || (is_string($value) && ctype_digit($value))) ? null : $this->typeError('integer', $value),
            'float' => (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) ? null : $this->typeError('numeric', $value),
            'bool' => is_bool($value) ? null : $this->typeError('boolean', $value),
            'array' => is_array($value) ? null : $this->typeError('array', $value),
            default => null,
        };
    }

    /**
     * Sanitize a value according to this param's constraints.
     */
    public function sanitize(mixed $value): mixed
    {
        return match ($this->type) {
            'string' => $this->sanitizeString($value),
            'int' => $this->sanitizeInt($value),
            'float' => $this->sanitizeFloat($value),
            'bool' => (bool) $value,
            'array' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    /**
     * Sanitize a string value: trim, strip control chars, enforce max length.
     */
    private function sanitizeString(mixed $value): string
    {
        $str = is_string($value) ? $value : (string) $value;

        // Strip null bytes and control characters (except tab, newline)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;

        if ($this->maxLength !== null && mb_strlen($str) > $this->maxLength) {
            $str = mb_substr($str, 0, $this->maxLength);
        }

        return $str;
    }

    /**
     * Sanitize an integer value: cast and clamp to range.
     */
    private function sanitizeInt(mixed $value): int
    {
        $int = is_int($value) ? $value : (int) $value;

        if ($this->min !== null && $int < $this->min) {
            $int = (int) $this->min;
        }

        if ($this->max !== null && $int > $this->max) {
            $int = (int) $this->max;
        }

        return $int;
    }

    /**
     * Sanitize a float value: cast and clamp to range.
     */
    private function sanitizeFloat(mixed $value): float
    {
        $float = is_float($value) || is_int($value) ? (float) $value : (float) $value;

        if ($this->min !== null && $float < $this->min) {
            $float = (float) $this->min;
        }

        if ($this->max !== null && $float > $this->max) {
            $float = (float) $this->max;
        }

        return $float;
    }

    /**
     * Format a type error message.
     */
    private function typeError(string $expected, mixed $value): string
    {
        $actual = get_debug_type($value);

        return "Expected '{$expected}', got '{$actual}'";
    }
}
