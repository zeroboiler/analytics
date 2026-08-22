<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * GDPR-compliant data anonymization service for analytics events.
 *
 * Provides deterministic anonymization of user identifiers and event
 * parameters. Uses HMAC-SHA256 with a configurable salt for consistent
 * anonymization — the same input always produces the same anonymized output.
 *
 * Supports field-level anonymization rules configured per event name
 * or applied globally to sensitive fields (email, phone, ip, name).
 *
 * Anonymized IDs preserve the ability to correlate events across sessions
 * without exposing PII.
 *
 * Configuration:
 *   zeroboiler.analytics.anonymization.enabled (default: false)
 *   zeroboiler.analytics.anonymization.salt (default: 'zb_anon_salt')
 *   zeroboiler.analytics.anonymization.global_fields (list of field names to anonymize)
 *   zeroboiler.analytics.anonymization.event_rules (per-event field rules)
 *
 * @see \ZeroBoiler\Analytics\DTO\AnalyticsEvent
 *
 * @since 1.0.0
 */
final class AnalyticsAnonymizationService
{
    /** @var list<string> Fields that are always anonymized when the service is enabled */
    private const DEFAULT_GLOBAL_FIELDS = [
        'email',
        'phone',
        'ip_address',
        'user_agent',
        'full_name',
        'first_name',
        'last_name',
        'address',
        'credit_card',
    ];

    /** @var int Default prefix length for anonymized strings */
    private const PREFIX_LENGTH = 3;

    private bool $enabled;

    private string $salt;

    /** @var list<string> */
    private array $globalFields;

    /** @var array<string, list<string>> Per-event field anonymization rules */
    private array $eventRules;

    /** @var array<string, list<string>> Per-category field anonymization rules */
    private array $categoryRules;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $anonConfig = $config->get('zeroboiler.analytics.anonymization', []);
        /** @var array{enabled?: bool, salt?: string, global_fields?: list<string>, event_rules?: array<string, list<string>>, category_rules?: array<string, list<string>>} $anonConfig */
        $this->enabled = (bool) ($anonConfig['enabled'] ?? false);
        $this->salt = (string) ($anonConfig['salt'] ?? 'zb_anon_salt');
        $this->globalFields = $anonConfig['global_fields'] ?? self::DEFAULT_GLOBAL_FIELDS;
        $this->eventRules = $anonConfig['event_rules'] ?? [];
        $this->categoryRules = $anonConfig['category_rules'] ?? [];
    }

    /**
     * Anonymize a user ID to a deterministic pseudonymous identifier.
     *
     * Uses HMAC-SHA256 to generate a consistent 16-character hex string
     * from the user ID. The same ID always produces the same anonymized output.
     *
     * @param  string  $userId  The user ID to anonymize
     * @return string 16-character hex pseudonymous ID (prefix: "anon_")
     *
     * @example
     * $service->anonymizeId('user_12345'); // 'anon_a3f7b2c1e9d04567'
     */
    public function anonymizeId(string $userId): string
    {
        if ($userId === '') {
            return 'anon_0000000000000000';
        }

        $hash = hash_hmac('sha256', $userId, $this->salt);

        return 'anon_' . substr($hash, 0, 16);
    }

    /**
     * Anonymize a value with partial masking.
     *
     * Preserves the first N characters and replaces the rest with
     * asterisks. Useful for displaying partial identifiers in dashboards.
     *
     * @param  string  $value  The value to mask
     * @param  int  $prefixLength  Number of characters to preserve (default: 3)
     * @return string Masked value
     *
     * @example
     * $service->maskValue('john@example.com'); // 'joh*********************'
     * $service->maskValue('1234567890');       // '123*******'
     */
    public function maskValue(string $value, int $prefixLength = self::PREFIX_LENGTH): string
    {
        $length = mb_strlen($value);

        if ($length <= $prefixLength) {
            return $value;
        }

        $prefix = mb_substr($value, 0, $prefixLength);
        $maskedLength = $length - $prefixLength;

        return $prefix . str_repeat('*', $maskedLength);
    }

    /**
     * Anonymize an event's parameters in-place.
     *
     * Scans all parameter keys against global rules, per-event rules,
     * and per-category rules. Matching fields are anonymized using
     * the appropriate strategy.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $eventName  Event name for per-event rules
     * @param  string|null  $category  Event category for per-category rules
     * @return array<string, mixed> Anonymized parameters
     */
    public function anonymizeParams(array $params, string $eventName, ?string $category = null): array
    {
        if (! $this->enabled || empty($params)) {
            return $params;
        }

        $fieldsToAnonymize = $this->resolveFields($eventName, $category);

        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach ($fieldsToAnonymize as $fieldPattern) {
                if ($this->fieldMatches($key, $fieldPattern)) {
                    $params[$key] = $this->anonymizeFieldValue($value);
                    break;
                }
            }
        }

        return $params;
    }

    /**
     * Anonymize a single field value.
     *
     * Detects the type of value (email, ID, general string) and
     * applies the appropriate anonymization strategy.
     *
     * @param  string  $value
     * @return string
     */
    public function anonymizeFieldValue(string $value): string
    {
        // Email: preserve domain, mask local part
        if (str_contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $parts = explode('@', $value);
            $local = $parts[0] ?? '';
            $domain = $parts[1] ?? '';

            return $this->maskValue($local, 2) . '@' . $domain;
        }

        // Phone numbers: mask middle digits
        if (preg_match('/^[\d\s\-\+\(\)]{7,20}$/', $value)) {
            $digits = preg_replace('/\D/', '', $value);
            $len = strlen($digits);

            if ($len <= 4) {
                return $this->maskValue($value, 2);
            }

            return substr($digits, 0, 2) . str_repeat('*', $len - 4) . substr($digits, -2);
        }

        // IP addresses: mask last octet
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $parts = explode('.', $value);

            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
            }
        }

        // UUIDs: anonymize deterministically
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return $this->anonymizeId($value);
        }

        // General string: prefix masking
        return $this->maskValue($value);
    }

    /**
     * Check if anonymization is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of global anonymization fields.
     *
     * @return list<string>
     */
    public function getGlobalFields(): array
    {
        return $this->globalFields;
    }

    /**
     * Get the per-event anonymization rules.
     *
     * @return array<string, list<string>>
     */
    public function getEventRules(): array
    {
        return $this->eventRules;
    }

    /**
     * Get the per-category anonymization rules.
     *
     * @return array<string, list<string>>
     */
    public function getCategoryRules(): array
    {
        return $this->categoryRules;
    }

    /**
     * Get an anonymization audit trail for an event.
     *
     * Returns a list of fields that would be anonymized, with the
     * original and anonymized values. Useful for debugging and
     * compliance auditing.
     *
     * @param  array<string, mixed>  $params
     * @param  string  $eventName
     * @param  string|null  $category
     * @return list<array{field: string, original: string, anonymized: string}>
     */
    public function auditTrail(array $params, string $eventName, ?string $category = null): array
    {
        $fields = $this->resolveFields($eventName, $category);
        $trail = [];

        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach ($fields as $pattern) {
                if ($this->fieldMatches($key, $pattern)) {
                    $trail[] = [
                        'field' => $key,
                        'original' => $value,
                        'anonymized' => $this->anonymizeFieldValue($value),
                    ];
                    break;
                }
            }
        }

        return $trail;
    }

    /**
     * Resolve all fields that should be anonymized for a given event.
     *
     * Merges global fields, event-specific rules, and category rules.
     *
     * @param  string  $eventName
     * @param  string|null  $category
     * @return list<string>
     */
    private function resolveFields(string $eventName, ?string $category): array
    {
        $fields = $this->globalFields;

        // Add category-level fields
        if ($category !== null && isset($this->categoryRules[$category])) {
            $fields = array_merge($fields, $this->categoryRules[$category]);
        }

        // Add event-level fields
        if (isset($this->eventRules[$eventName])) {
            $fields = array_merge($fields, $this->eventRules[$eventName]);
        }

        return array_values(array_unique($fields));
    }

    /**
     * Check if a field key matches a pattern.
     *
     * Supports exact match and wildcard suffix (e.g., 'user_*').
     *
     * @param  string  $field
     * @param  string  $pattern
     */
    private function fieldMatches(string $field, string $pattern): bool
    {
        // Exact match
        if ($field === $pattern) {
            return true;
        }

        // Wildcard suffix: 'user_*' matches 'user_email', 'user_name'
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return str_starts_with($field, $prefix);
        }

        // Wildcard prefix: '*_email' matches 'user_email', 'billing_email'
        if (str_starts_with($pattern, '*')) {
            $suffix = substr($pattern, 1);

            return str_ends_with($field, $suffix);
        }

        // Contains match: 'email' matches 'user_email', 'billing_email'
        return str_contains($field, $pattern);
    }
}
