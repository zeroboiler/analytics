<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Support\Facades\Log;

/**
 * Automatic type coercion for analytics event parameters.
 *
 * Converts incoming parameter values to their expected types before
 * validation runs. This ensures that form data, query strings, and
 * third-party payloads are normalized to correct PHP types.
 *
 * Supported coercions:
 * - string → int (via (int) cast, rejects non-numeric)
 * - string → float (via (float) cast, rejects non-numeric)
 * - string → bool ("true"/"1"/"yes" → true, "false"/"0"/"no" → false)
 * - string → array (JSON decode or comma-split)
 * - int/float → string
 * - bool → string ("true"/"false")
 * - array → string (JSON encode)
 * - numeric string → int|float (intelligent: "42" → 42, "42.5" → 42.5)
 *
 * Coercion is non-destructive: if a value already matches the target type,
 * it is returned unchanged. If coercion fails, the original value is
 * preserved (fail-open, with optional warning logging).
 *
 * @see \ZeroBoiler\Analytics\Services\EventFieldValidator
 *
 * @since 125.0.0
 */
final class EventFieldCoercer
{
    private bool $debug;

    private bool $strict;

    /** @var list<array{field: string, from: string, to: string, original: mixed, coerced: mixed}> */
    private array $coercionLog = [];

    /**
     * @param  bool  $debug  Log coercion details for troubleshooting
     * @param  bool  $strict  If true, throw on coercion failure; if false, preserve original
     */
    public function __construct(bool $debug = false, bool $strict = false): void
    {
        $this->debug = $debug;
        $this->strict = $strict;
    }

    /**
     * Coerce a single value to the target type.
     *
     * @param  mixed  $value  The value to coerce
     * @param  'string'|'int'|'float'|'bool'|'array'|'numeric'  $targetType  Expected type
     * @return mixed The coerced value
     *
     * @throws \InvalidArgumentException If strict mode and coercion fails
     */
    public function coerce(mixed $value, string $targetType): mixed
    {
        // Already correct type — short-circuit
        if ($this->isType($value, $targetType)) {
            return $value;
        }

        $original = $value;
        $result = match ($targetType) {
            'string' => $this->toString($value),
            'int' => $this->toInt($value),
            'float' => $this->toFloat($value),
            'bool' => $this->toBool($value),
            'array' => $this->toArray($value),
            'numeric' => $this->toNumeric($value),
            default => $value,
        };

        if ($result !== $original) {
            $this->coercionLog[] = [
                'field' => '',
                'from' => get_debug_type($original),
                'to' => $targetType,
                'original' => $original,
                'coerced' => $result,
            ];
        }

        return $result;
    }

    /**
     * Coerce all parameters in an event according to the field rules.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  array<string, array{type?: string, coerce?: bool}>  $fieldRules  Field rules with type info
     * @return array{params: array<string, mixed>, coercions: list<array{field: string, from: string, to: string, original: mixed, coerced: mixed}>}
     */
    public function coerceParams(array $params, array $fieldRules): array
    {
        $coercions = [];

        foreach ($params as $field => $value) {
            $rule = $fieldRules[$field] ?? null;

            if ($rule === null) {
                continue;
            }

            $shouldCoerce = $rule['coerce'] ?? true;
            $targetType = $rule['type'] ?? null;

            if (! $shouldCoerce || $targetType === null) {
                continue;
            }

            $original = $value;
            $coerced = $this->coerce($value, $targetType);

            if ($coerced !== $original) {
                $params[$field] = $coerced;
                $coercions[] = [
                    'field' => $field,
                    'from' => get_debug_type($original),
                    'to' => $targetType,
                    'original' => $original,
                    'coerced' => $coerced,
                ];
            }
        }

        return [
            'params' => $params,
            'coercions' => $coercions,
        ];
    }

    /**
     * Coerce a value to string.
     */
    private function toString(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Coerce a value to integer.
     */
    private function toInt(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $this->logWarning("Cannot coerce {type} to int", $value);

        if ($this->strict) {
            throw new \InvalidArgumentException(
                "Cannot coerce value of type " . get_debug_type($value) . ' to int'
            );
        }

        return $value;
    }

    /**
     * Coerce a value to float.
     */
    private function toFloat(mixed $value): mixed
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        $this->logWarning("Cannot coerce {type} to float", $value);

        if ($this->strict) {
            throw new \InvalidArgumentException(
                "Cannot coerce value of type " . get_debug_type($value) . ' to float'
            );
        }

        return $value;
    }

    /**
     * Coerce a value to boolean.
     *
     * Truthy: "true", "1", "yes", "on", 1, true
     * Falsy: "false", "0", "no", "off", 0, false, ""
     */
    private function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));

            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }

        $this->logWarning("Cannot coerce {type} to bool", $value);

        if ($this->strict) {
            throw new \InvalidArgumentException(
                "Cannot coerce value of type " . get_debug_type($value) . ' to bool'
            );
        }

        return $value;
    }

    /**
     * Coerce a value to array.
     *
     * Strategies: JSON decode → comma-split → wrap in array.
     */
    private function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            // Try JSON decode
            if (
                ($trimmed[0] ?? '') === '['
                || ($trimmed[0] ?? '') === '{'
            ) {
                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (\JsonException) {
                    // Fall through to comma-split
                }
            }

            // Comma-separated values
            if ($trimmed !== '') {
                return explode(',', $trimmed);
            }

            return [];
        }

        $this->logWarning("Cannot coerce {type} to array", $value);

        if ($this->strict) {
            throw new \InvalidArgumentException(
                "Cannot coerce value of type " . get_debug_type($value) . ' to array'
            );
        }

        return $value;
    }

    /**
     * Intelligently coerce to int or float.
     *
     * "42" → 42 (int), "42.5" → 42.5 (float), "42.0" → 42 (int).
     */
    private function toNumeric(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            // Check for decimal point
            if (str_contains($value, '.') && ! str_ends_with($value, '.0')) {
                return (float) $value;
            }

            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $this->logWarning("Cannot coerce {type} to numeric", $value);

        if ($this->strict) {
            throw new \InvalidArgumentException(
                "Cannot coerce value of type " . get_debug_type($value) . ' to numeric'
            );
        }

        return $value;
    }

    /**
     * Check if a value already matches the target type.
     */
    private function isType(mixed $value, string $targetType): bool
    {
        return match ($targetType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_int($value) || is_float($value),
            default => false,
        };
    }

    /**
     * Log a coercion warning.
     *
     * @param  string  $message  Message with {type} placeholder
     * @param  mixed  $value  The value that failed coercion
     */
    private function logWarning(string $message, mixed $value): void
    {
        if (! $this->debug) {
            return;
        }

        Log::debug(
            'ZeroBoiler Analytics Coercion: ' . str_replace(
                '{type}',
                get_debug_type($value),
                $message
            ),
            ['value' => $value],
        );
    }

    /**
     * Get all coercion operations performed since instantiation or last reset.
     *
     * @return list<array{field: string, from: string, to: string, original: mixed, coerced: mixed}>
     */
    public function getCoercionLog(): array
    {
        return $this->coercionLog;
    }

    /**
     * Get the count of coercions performed.
     */
    public function coercionCount(): int
    {
        return count($this->coercionLog);
    }

    /**
     * Reset the coercion log.
     */
    public function reset(): void
    {
        $this->coercionLog = [];
    }

    /**
     * Get a diagnostic summary.
     *
     * @return array{total_coercions: int, types: array<string, int>, strict: bool, debug: bool}
     */
    public function summary(): array
    {
        $types = [];
        foreach ($this->coercionLog as $entry) {
            $key = "{$entry['from']}→{$entry['to']}";
            $types[$key] = ($types[$key] ?? 0) + 1;
        }

        return [
            'total_coercions' => count($this->coercionLog),
            'types' => $types,
            'strict' => $this->strict,
            'debug' => $this->debug,
        ];
    }
}
