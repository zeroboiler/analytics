<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Field-level event payload encryption service.
 *
 * Encrypts sensitive event parameters using AES-256-CBC (Laravel's native
 * encryption) before dispatch to analytics providers. Encrypted values are
 * prefixed with a marker (`enc:v1:`) so they can be identified and
 * optionally decrypted for internal reporting or audit purposes.
 *
 * The service supports:
 * - Global field rules: fields encrypted across all events
 * - Per-event field rules: fields encrypted only for specific events
 * - Key rotation: encrypts with the current key, decrypts with any active key
 * - Selective decryption: decrypt specific fields for internal reporting
 * - Bulk encrypt/decrypt: process entire event payloads
 *
 * Unlike PiiSanitizationMiddleware (which hashes/removes) or
 * AnalyticsAnonymizationService (which one-way HMACs), this service
 * provides reversible encryption, preserving the original value for
 * authorized internal use while protecting it from provider access.
 *
 * Inspired by Segment's EncryptionMiddleware and mParticle's data encryption.
 *
 * Configuration:
 *   zeroboiler.analytics.encryption.enabled (default: false)
 *   zeroboiler.analytics.encryption.prefix (default: 'enc:v1:')
 *   zeroboiler.analytics.encryption.global_fields (list of field names)
 *   zeroboiler.analytics.encryption.event_rules (per-event field rules)
 *
 * @since 54.0.0
 */
final class EventPayloadEncryptionService
{
    /** Encryption prefix marker for identifying encrypted values */
    public const PREFIX = 'enc:v1:';

    /** Maximum value length to encrypt (values exceeding this are hashed instead) */
    private const MAX_ENCRYPT_LENGTH = 4096;

    private bool $enabled;

    private string $prefix;

    /** @var list<string> Fields encrypted across all events */
    private array $globalFields;

    /** @var array<string, list<string>> Per-event encryption rules */
    private array $eventRules;

    private readonly Encrypter $encrypter;

    /**
     * @param  Encrypter  $encrypter  Laravel's AES-256-CBC encrypter
     */
    public function __construct(
        Encrypter $encrypter,
        ConfigRepository $config,
    ): void {
        $encryptionConfig = $config->get('zeroboiler.analytics.encryption', []);

        $this->enabled = (bool) ($encryptionConfig['enabled'] ?? false);
        $this->prefix = (string) ($encryptionConfig['prefix'] ?? self::PREFIX);
        $this->globalFields = (array) ($encryptionConfig['global_fields'] ?? []);
        $this->eventRules = (array) ($encryptionConfig['event_rules'] ?? []);
        $this->encrypter = $encrypter;
    }

    /**
     * Check if encryption is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the encryption prefix marker.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Encrypt a single value.
     *
     * @param  mixed  $value  Value to encrypt (must be scalar or JSON-serializable)
     * @return string|mixed Returns encrypted string prefixed with marker, or original value if not encryptable
     */
    public function encryptValue(mixed $value): mixed
    {
        if (! $this->enabled || ! $this->isEncryptable($value)) {
            return $value;
        }

        $stringValue = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        // Truncate oversized values and hash to prevent ciphertext bloat
        if (strlen($stringValue) > self::MAX_ENCRYPT_LENGTH) {
            return hash('sha256', $stringValue);
        }

        try {
            $ciphertext = $this->encrypter->encrypt($stringValue);

            return $this->prefix . base64_encode($ciphertext);
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler Analytics: Failed to encrypt event payload value', [
                'error' => $e->getMessage(),
            ]);

            // Fail safe: return hashed value instead of plaintext
            return hash('sha256', $stringValue);
        }
    }

    /**
     * Decrypt a single encrypted value.
     *
     * @param  mixed  $value  Value to decrypt
     * @return mixed Original value if decryption succeeds, or the encrypted value if not encrypted/decryption fails
     */
    public function decryptValue(mixed $value): mixed
    {
        if (! is_string($value) || ! str_starts_with($value, $this->prefix)) {
            return $value;
        }

        $encoded = substr($value, strlen($this->prefix));
        $ciphertext = base64_decode($encoded, true);

        if ($ciphertext === false) {
            return $value;
        }

        try {
            $decrypted = $this->encrypter->decrypt($ciphertext);

            $decoded = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            // If the original was a simple string that got JSON-encoded, return the string
            if (! is_array($decoded)) {
                return $decoded;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler Analytics: Failed to decrypt event payload value', [
                'error' => $e->getMessage(),
            ]);

            return $value;
        }
    }

    /**
     * Encrypt matching fields in an event's params array.
     *
     * Applies global rules and per-event rules. Returns a new params array
     * with encrypted values, leaving non-matching fields untouched.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $eventName  Event name for per-event rules
     * @return array<string, mixed> New params array with encrypted values
     */
    public function encryptParams(array $params, string $eventName): array
    {
        if (! $this->enabled || empty($params)) {
            return $params;
        }

        $fieldsToEncrypt = $this->resolveFieldsForEvent($eventName);

        if (empty($fieldsToEncrypt)) {
            return $params;
        }

        foreach ($params as $key => $value) {
            if ($this->shouldEncryptField((string) $key, $fieldsToEncrypt)) {
                $params[$key] = $this->encryptValue($value);
            }
        }

        return $params;
    }

    /**
     * Decrypt matching fields in an event's params array.
     *
     * Reverses encryption for fields that were encrypted. Used for internal
     * reporting, audit, and data processing where original values are needed.
     *
     * @param  array<string, mixed>  $params  Event parameters (potentially containing encrypted values)
     * @return array<string, mixed> Params with decrypted values where applicable
     */
    public function decryptParams(array $params): array
    {
        if (empty($params)) {
            return $params;
        }

        foreach ($params as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $params[$key] = $this->decryptValue($value);
            }
        }

        return $params;
    }

    /**
     * Decrypt a specific field by name from params.
     *
     * Useful when only certain fields need to be decrypted for display.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $fieldName  Field name to decrypt
     * @return mixed Original value if found and encrypted, or current value
     */
    public function decryptField(array $params, string $fieldName): mixed
    {
        return $this->decryptValue($params[$fieldName] ?? null);
    }

    /**
     * Check if a value appears to be encrypted (has the encryption prefix).
     */
    public function isEncryptedValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, $this->prefix);
    }

    /**
     * Get the count of encrypted fields in a params array.
     *
     * @param  array<string, mixed>  $params
     */
    public function countEncryptedFields(array $params): int
    {
        $count = 0;

        foreach ($params as $value) {
            if ($this->isEncryptedValue($value)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the list of fields that would be encrypted for a given event.
     *
     * @return list<string>
     */
    public function getFieldsForEvent(string $eventName): array
    {
        return $this->resolveFieldsForEvent($eventName);
    }

    /**
     * Check if a specific field would be encrypted for a given event.
     */
    public function shouldEncryptFieldForEvent(string $fieldName, string $eventName): bool
    {
        $fields = $this->resolveFieldsForEvent($eventName);

        return $this->shouldEncryptField($fieldName, $fields);
    }

    /**
     * Rotate encryption — re-encrypt all encrypted values with the current key.
     *
     * Useful after key rotation. Decrypts with any available key (via Laravel's
     * multi-key support) and re-encrypts with the current primary key.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return array<string, mixed> Params with all values re-encrypted
     */
    public function rotateEncryption(array $params): array
    {
        if (empty($params)) {
            return $params;
        }

        foreach ($params as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                // Decrypt with old key, re-encrypt with current key
                $decrypted = $this->decryptValue($value);

                if ($this->isEncryptedValue($decrypted)) {
                    // Still encrypted = decryption failed (old key unavailable)
                    continue;
                }

                $params[$key] = $this->encryptValue($decrypted);
            }
        }

        return $params;
    }

    /**
     * Generate an encryption health report.
     *
     * @return array<string, mixed>
     */
    public function healthReport(): array
    {
        return [
            'enabled' => $this->enabled,
            'prefix' => $this->prefix,
            'global_fields_count' => count($this->globalFields),
            'global_fields' => $this->globalFields,
            'event_rules_count' => count($this->eventRules),
            'event_rules' => $this->eventRules,
            'cipher' => $this->encrypter->getCipher(),
        ];
    }

    /**
     * Check if a value is encryptable (non-null, scalar, or serializable).
     */
    private function isEncryptable(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_scalar($value)) {
            return true;
        }

        if (is_array($value)) {
            // Check if the array is JSON-serializable (no resources, no closures)
            try {
                json_encode($value, JSON_THROW_ON_ERROR);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Resolve which fields should be encrypted for a given event.
     *
     * Merges global fields with event-specific rules (event rules take
     * precedence — they can both add and remove fields).
     *
     * @return list<string>
     */
    private function resolveFieldsForEvent(string $eventName): array
    {
        $fields = $this->globalFields;

        // Merge event-specific fields
        if (isset($this->eventRules[$eventName]) && is_array($this->eventRules[$eventName])) {
            $eventFields = $this->eventRules[$eventName];

            // Support 'except:field_name' syntax to exclude from encryption
            foreach ($eventFields as $field) {
                if (str_starts_with($field, 'except:')) {
                    $exceptField = substr($field, 7);
                    $fields = array_values(array_filter($fields, static fn (string $f): bool => $f !== $exceptField));
                } else {
                    $fields[] = $field;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Check if a field name matches any of the fields to encrypt.
     *
     * @param  list<string>  $fieldsToEncrypt
     */
    private function shouldEncryptField(string $fieldName, array $fieldsToEncrypt): bool
    {
        $normalized = strtolower($fieldName);

        foreach ($fieldsToEncrypt as $pattern) {
            if (str_contains($pattern, '*')) {
                // Wildcard matching: 'user_*' matches 'user_email', 'user_name', etc.
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

                if (preg_match($regex, $normalized) === 1) {
                    return true;
                }
            } elseif (strtolower($pattern) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
