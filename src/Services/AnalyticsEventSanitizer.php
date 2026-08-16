<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Production-grade event parameter sanitization service.
 *
 * Validates, cleans, and normalizes event parameters before they reach
 * providers. Prevents malformed data, enforces naming conventions,
 * truncates oversized values, strips dangerous content, and ensures
 * parameter schema compliance.
 *
 * All rules are config-driven via `zeroboiler.analytics.sanitization`.
 * Can be used standalone or injected into the event pipeline.
 *
 * Inspired by Segment's event validation, Mixpanel's event quality checks,
 * and the GA4 parameter validation rules.
 *
 * @since 12.0.0
 */
final class AnalyticsEventSanitizer
{
    /** @var array{enabled: bool, max_param_count: int, max_key_length: int, max_value_length: int, strict_naming: bool, strip_html: bool, strip_null_bytes: bool, normalize_booleans: bool, truncate_strings: bool, disallowed_keys: list<string>, max_event_name_length: int, reserved_prefixes: list<string>} */
    private array $config;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(ConfigRepository $config): void
    {
        $raw = $config->get('zeroboiler.analytics.sanitization', []);
        /** @var array{enabled?: bool, max_param_count?: int, max_key_length?: int, max_value_length?: int, strict_naming?: bool, strip_html?: bool, strip_null_bytes?: bool, normalize_booleans?: bool, truncate_strings?: bool, disallowed_keys?: list<string>, max_event_name_length?: int, reserved_prefixes?: list<string>} $raw */

        $this->config = [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'max_param_count' => (int) ($raw['max_param_count'] ?? 100),
            'max_key_length' => (int) ($raw['max_key_length'] ?? 100),
            'max_value_length' => (int) ($raw['max_value_length'] ?? 500),
            'strict_naming' => (bool) ($raw['strict_naming'] ?? false),
            'strip_html' => (bool) ($raw['strip_html'] ?? true),
            'strip_null_bytes' => (bool) ($raw['strip_null_bytes'] ?? true),
            'normalize_booleans' => (bool) ($raw['normalize_booleans'] ?? true),
            'truncate_strings' => (bool) ($raw['truncate_strings'] ?? true),
            'disallowed_keys' => (array) ($raw['disallowed_keys'] ?? ['password', 'token', 'secret', 'api_key', 'credit_card']),
            'max_event_name_length' => (int) ($raw['max_event_name_length'] ?? 100),
            'reserved_prefixes' => (array) ($raw['reserved_prefixes'] ?? ['_zb_', '_ga_', '_fb_', '_meta_', '_sentry_']),
        ];
    }

    /**
     * Check if sanitization is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Sanitize an analytics event in-place.
     *
     * Returns the same event instance with cleaned parameters.
     * Collects errors internally — call `getErrors()` after sanitization.
     *
     * @param  AnalyticsEvent  $event  The event to sanitize
     * @return AnalyticsEvent The sanitized event
     */
    public function sanitize(AnalyticsEvent $event): AnalyticsEvent
    {
        $this->errors = [];

        if (! $this->config['enabled']) {
            return $event;
        }

        $params = $event->toArray();
        $name = $params['name'] ?? '';
        $eventParams = $params['params'] ?? [];

        // Validate event name
        $sanitizedName = $this->sanitizeEventName((string) $name);
        if ($sanitizedName !== $name) {
            $this->errors[] = "Event name sanitized: '{$name}' → '{$sanitizedName}'";
        }

        // Validate param count
        if (count($eventParams) > $this->config['max_param_count']) {
            $this->errors[] = "Parameter count exceeded ({$this->config['max_param_count']}): " . count($eventParams);
            $eventParams = array_slice($eventParams, 0, $this->config['max_param_count'], true);
        }

        // Process each parameter
        $cleaned = [];
        foreach ($eventParams as $key => $value) {
            $result = $this->sanitizeParam((string) $key, $value);
            if ($result['allowed']) {
                $cleaned[$result['key']] = $result['value'];
            } else {
                $this->errors[] = "Disallowed parameter: '{$key}'";
            }
        }

        return new AnalyticsEvent(
            name: $sanitizedName,
            params: $cleaned,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Sanitize an event name.
     *
     * Enforces snake_case naming, length limit, and removes invalid characters.
     *
     * @param  string  $name  Raw event name
     * @return string Sanitized event name
     */
    public function sanitizeEventName(string $name): string
    {
        // Strip null bytes
        if ($this->config['strip_null_bytes']) {
            $name = str_replace("\0", '', $name);
        }

        // Strip HTML
        if ($this->config['strip_html']) {
            $name = strip_tags($name);
        }

        // Trim whitespace
        $name = trim($name);

        // Enforce snake_case in strict mode
        if ($this->config['strict_naming']) {
            $name = $this->toSnakeCase($name);
        }

        // Truncate to max length
        if ($this->config['truncate_strings'] && mb_strlen($name) > $this->config['max_event_name_length']) {
            $name = mb_substr($name, 0, $this->config['max_event_name_length']);
        }

        return $name;
    }

    /**
     * Sanitize a single event parameter.
     *
     * @param  string  $key  Parameter key
     * @param  mixed  $value  Parameter value
     * @return array{allowed: bool, key: string, value: mixed}
     */
    public function sanitizeParam(string $key, mixed $value): array
    {
        // Check disallowed keys
        $normalisedKey = strtolower($key);
        foreach ($this->config['disallowed_keys'] as $disallowed) {
            if (str_contains($normalisedKey, strtolower($disallowed))) {
                return ['allowed' => false, 'key' => $key, 'value' => null];
            }
        }

        // Sanitize key
        $sanitizedKey = $this->sanitizeKey($key);

        // Sanitize value recursively
        $sanitizedValue = $this->sanitizeValue($value);

        return ['allowed' => true, 'key' => $sanitizedKey, 'value' => $sanitizedValue];
    }

    /**
     * Sanitize a parameter key.
     *
     * Strips null bytes, HTML, enforces length limits.
     *
     * @param  string  $key  Raw key
     * @return string Sanitized key
     */
    public function sanitizeKey(string $key): string
    {
        if ($this->config['strip_null_bytes']) {
            $key = str_replace("\0", '', $key);
        }

        if ($this->config['strip_html']) {
            $key = strip_tags($key);
        }

        $key = trim($key);

        if ($this->config['truncate_strings'] && mb_strlen($key) > $this->config['max_key_length']) {
            $key = mb_substr($key, 0, $this->config['max_key_length']);
        }

        return $key;
    }

    /**
     * Sanitize a parameter value recursively.
     *
     * Handles strings, arrays, booleans, numbers, and null.
     * Strips HTML, null bytes, normalizes booleans, truncates strings.
     *
     * @param  mixed  $value  Raw value
     * @return mixed Sanitized value
     */
    public function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if ($this->config['strip_null_bytes']) {
                $value = str_replace("\0", '', $value);
            }

            if ($this->config['strip_html']) {
                $value = strip_tags($value);
            }

            $value = trim($value);

            if ($this->config['truncate_strings'] && mb_strlen($value) > $this->config['max_value_length']) {
                $value = mb_substr($value, 0, $this->config['max_value_length']);
            }

            return $value;
        }

        if (is_bool($value) && $this->config['normalize_booleans']) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            $cleaned = [];
            foreach ($value as $k => $v) {
                $result = $this->sanitizeParam((string) $k, $v);
                if ($result['allowed']) {
                    $cleaned[$result['key']] = $result['value'];
                }
            }

            return $cleaned;
        }

        if ($value === null) {
            return null;
        }

        // Convert unknown types to string
        return (string) $value;
    }

    /**
     * Validate an event without modifying it.
     *
     * Returns a validation report with any issues found.
     *
     * @param  AnalyticsEvent  $event  Event to validate
     * @return array{valid: bool, warnings: list<string>, errors: list<string>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $data = $event->toArray();
        $name = (string) ($data['name'] ?? '');
        $params = (array) ($data['params'] ?? []);

        // Check event name
        if ($name === '') {
            $errors[] = 'Event name is empty';
        } elseif ($this->config['strict_naming'] && $name !== $this->toSnakeCase($name)) {
            $warnings[] = "Event name '{$name}' is not snake_case";
        } elseif (mb_strlen($name) > $this->config['max_event_name_length']) {
            $errors[] = "Event name exceeds max length ({$this->config['max_event_name_length']})";
        }

        // Check param count
        if (count($params) > $this->config['max_param_count']) {
            $warnings[] = "Parameter count (" . count($params) . ") exceeds max ({$this->config['max_param_count']})";
        }

        // Check each param
        foreach ($params as $key => $value) {
            $keyStr = (string) $key;
            $normalisedKey = strtolower($keyStr);

            // Disallowed keys
            foreach ($this->config['disallowed_keys'] as $disallowed) {
                if (str_contains($normalisedKey, strtolower($disallowed))) {
                    $errors[] = "Disallowed parameter key: '{$keyStr}'";
                    continue 2;
                }
            }

            // Key length
            if (mb_strlen($keyStr) > $this->config['max_key_length']) {
                $warnings[] = "Parameter key '{$keyStr}' exceeds max length";
            }

            // Reserved prefixes (warn only — these may be internal)
            foreach ($this->config['reserved_prefixes'] as $prefix) {
                if (str_starts_with($normalisedKey, strtolower($prefix))) {
                    $warnings[] = "Parameter key '{$keyStr}' uses reserved prefix '{$prefix}'";
                }
            }

            // Value length for strings
            if (is_string($value) && mb_strlen($value) > $this->config['max_value_length']) {
                $warnings[] = "Value for '{$keyStr}' exceeds max length ({$this->config['max_value_length']})";
            }
        }

        return [
            'valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * Get sanitization errors from the last `sanitize()` call.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if the last sanitization produced any errors.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Get the current configuration (for diagnostics).
     *
     * @return array{enabled: bool, max_param_count: int, max_key_length: int, max_value_length: int, strict_naming: bool, strip_html: bool, strip_null_bytes: bool, normalize_booleans: bool, truncate_strings: bool, disallowed_keys: list<string>, max_event_name_length: int, reserved_prefixes: list<string>}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Convert a string to snake_case.
     */
    private function toSnakeCase(string $input): string
    {
        // Replace spaces, hyphens, dots with underscores
        $result = preg_replace('/[\s\-\.]+/', '_', $input);
        // Lowercase
        $result = strtolower((string) ($result ?? $input));
        // Remove double underscores
        $result = preg_replace('/_+/', '_', $result);

        return trim((string) ($result ?? $input), '_');
    }
}
