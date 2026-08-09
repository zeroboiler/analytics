<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event naming convention validator and enforcer.
 *
 * Enforces consistent event naming across the analytics platform.
 * Validates against configurable rules including:
 * - Format: snake_case (default), camelCase, or custom regex
 * - Max length
 * - Reserved prefixes ($, zb_, amp_)
 * - Required prefixes for custom events
 * - Disallowed patterns
 *
 * Configuration is read from `zeroboiler.analytics.governance.naming`.
 *
 * @since 1.0.0
 */
final class EventNamingConventionService
{
    private readonly string $format;

    private readonly int $maxLength;

    private readonly int $minLength;

    /** @var list<string> Disallowed patterns (regex) */
    private readonly array $disallowedPatterns;

    /** @var list<string> Required prefixes for custom (non-catalog) events */
    private readonly array $customPrefixes;

    /** @var list<string> Reserved prefixes that cannot be used */
    private readonly array $reservedPrefixes;

    /** @var string|null Custom regex pattern (overrides format) */
    private readonly ?string $customPattern;

    public function __construct(ConfigRepository $config): void
    {
        $namingConfig = $config->get('zeroboiler.analytics.governance.naming', []);
        /** @var array{format?: string, max_length?: int, min_length?: int, disallowed_patterns?: list<string>, custom_prefixes?: list<string>, reserved_prefixes?: list<string>, custom_pattern?: string|null} $namingConfig */

        $this->format = (string) ($namingConfig['format'] ?? 'snake_case');
        $this->maxLength = (int) ($namingConfig['max_length'] ?? 100);
        $this->minLength = (int) ($namingConfig['min_length'] ?? 2);
        $this->disallowedPatterns = $namingConfig['disallowed_patterns'] ?? [];
        $this->customPrefixes = $namingConfig['custom_prefixes'] ?? [];
        $this->reservedPrefixes = $namingConfig['reserved_prefixes'] ?? ['$', 'zb_', 'amp_', 'firebase_', 'ga_'];
        $this->customPattern = $namingConfig['custom_pattern'] ?? null;
    }

    /**
     * Validate an event name against naming conventions.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, normalized: string}
     */
    public function validate(string $name): array
    {
        $errors = [];
        $warnings = [];

        // Length check
        $length = strlen($name);
        if ($length < $this->minLength) {
            $errors[] = "Event name must be at least {$this->minLength} characters, got {$length}";
        }

        if ($length > $this->maxLength) {
            $errors[] = "Event name must not exceed {$this->maxLength} characters, got {$length}";
        }

        // Format check
        if ($this->customPattern !== null) {
            if (! preg_match($this->customPattern, $name)) {
                $errors[] = "Event name does not match the custom naming pattern";
            }
        } else {
            $formatErrors = $this->validateFormat($name);
            $errors = array_merge($errors, $formatErrors);
        }

        // Reserved prefix check
        foreach ($this->reservedPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $errors[] = "Event name uses reserved prefix '{$prefix}'";
            }
        }

        // Disallowed pattern check
        foreach ($this->disallowedPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                $errors[] = "Event name matches disallowed pattern '{$pattern}'";
            }
        }

        // Custom event prefix warning
        if (! EventCatalog::has($name) && ! empty($this->customPrefixes)) {
            $hasPrefix = false;
            foreach ($this->customPrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $hasPrefix = true;
                    break;
                }
            }

            if (! $hasPrefix) {
                $warnings[] = "Custom event '{$name}' should use one of the configured prefixes: " . implode(', ', $this->customPrefixes);
            }
        }

        // Informational warnings
        if (str_contains($name, '__')) {
            $warnings[] = "Event name contains double underscore '__' — consider using single underscore";
        }

        if (preg_match('/\d{4,}/', $name)) {
            $warnings[] = "Event name contains 4+ consecutive digits — avoid embedding dates or IDs in event names";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => $this->normalize($name),
        ];
    }

    /**
     * Normalize an event name to the configured format.
     *
     * Converts camelCase → snake_case (default), PascalCase → snake_case, etc.
     */
    public function normalize(string $name): string
    {
        if ($this->customPattern !== null) {
            return $name; // No normalization for custom patterns
        }

        return match ($this->format) {
            'snake_case' => $this->toSnakeCase($name),
            'camelCase' => $this->toCamelCase($name),
            default => $name,
        };
    }

    /**
     * Calculate naming compliance score across the entire catalog.
     *
     * Returns a percentage (0-100) of catalog events that pass naming validation.
     */
    public function catalogComplianceScore(): float
    {
        $catalogEvents = EventCatalog::names();
        $total = count($catalogEvents);

        if ($total === 0) {
            return 100.0;
        }

        $passing = 0;
        foreach ($catalogEvents as $name) {
            $result = $this->validate($name);
            if ($result['valid']) {
                $passing++;
            }
        }

        return round(($passing / $total) * 100, 2);
    }

    /**
     * Get naming compliance details for all catalog events.
     *
     * @return list<array{name: string, valid: bool, errors: list<string>, warnings: list<string>, normalized: string}>
     */
    public function catalogComplianceDetails(): array
    {
        $results = [];

        foreach (EventCatalog::names() as $name) {
            $result = $this->validate($name);
            $results[] = [
                'name' => $name,
                'valid' => $result['valid'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
                'normalized' => $result['normalized'],
            ];
        }

        return $results;
    }

    /**
     * Get the configured naming format.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get naming convention configuration summary.
     *
     * @return array{format: string, max_length: int, min_length: int, reserved_prefixes: list<string>, custom_prefixes: list<string>}
     */
    public function summary(): array
    {
        return [
            'format' => $this->format,
            'max_length' => $this->maxLength,
            'min_length' => $this->minLength,
            'reserved_prefixes' => $this->reservedPrefixes,
            'custom_prefixes' => $this->customPrefixes,
        ];
    }

    /**
     * Validate event name against the configured format.
     *
     * @return list<string>
     */
    private function validateFormat(string $name): array
    {
        $errors = [];

        switch ($this->format) {
            case 'snake_case':
                if (! preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name)) {
                    $errors[] = "Event name must be snake_case (e.g., 'add_to_cart'), got '{$name}'";
                }
                break;

            case 'camelCase':
                if (! preg_match('/^[a-z][a-zA-Z0-9]*$/', $name)) {
                    $errors[] = "Event name must be camelCase (e.g., 'addToCart'), got '{$name}'";
                }
                break;

            default:
                // No format validation
                break;
        }

        return $errors;
    }

    /**
     * Convert a string to snake_case.
     */
    private function toSnakeCase(string $name): string
    {
        // Handle camelCase / PascalCase
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $result);

        return strtolower($result ?? $name);
    }

    /**
     * Convert a string to camelCase.
     */
    private function toCamelCase(string $name): string
    {
        // Handle snake_case
        $parts = explode('_', $name);
        $result = $parts[0];

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $result .= ucfirst($parts[$i]);
        }

        return $result;
    }
}
