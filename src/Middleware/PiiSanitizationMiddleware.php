<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface;

/**
 * Sanitizes PII (Personally Identifiable Information) from event parameters.
 *
 * Automatically detects and hashes or removes sensitive fields from event
 * data before dispatch to providers. Configurable field detection patterns
 * and sanitization strategies (hash, remove, mask).
 *
 * @see \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface
 */
final class PiiSanitizationMiddleware implements AnalyticsMiddlewareInterface
{
    /** @var list<string> */
    private readonly array $piiFields;

    /** @var list<string> */
    private readonly array $piiPatterns;

    private readonly string $strategy;

    /** @var int */
    private readonly int $priority;

    /**
     * PII field names to detect and sanitize.
     */
    private const DEFAULT_PII_FIELDS = [
        'email', 'user_email', 'email_address', 'mail',
        'phone', 'phone_number', 'mobile', 'telephone',
        'address', 'street', 'postal_code', 'zip', 'zipcode',
        'full_name', 'first_name', 'last_name', 'name',
        'ssn', 'social_security', 'ip_address', 'credit_card',
        'password', 'secret', 'token', 'api_key',
    ];

    /**
     * Regex patterns for detecting PII-like values in any field.
     */
    private const DEFAULT_PII_PATTERNS = [
        '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', // email
        '/^\+?\d{7,15}$/', // phone
        '/^\d{3}-\d{2}-\d{4}$/', // SSN
    ];

    /**
     * Sanitization strategies.
     */
    public const STRATEGY_HASH = 'hash';
    public const STRATEGY_REMOVE = 'remove';
    public const STRATEGY_MASK = 'mask';

    /**
     * @param  list<string>|null  $piiFields  Custom PII field names (null = use defaults)
     * @param  list<string>|null  $piiPatterns  Custom regex patterns (null = use defaults)
     * @param  string  $strategy  Sanitization strategy: hash, remove, or mask
     * @param  int  $priority  Middleware priority (lower = runs first)
     */
    public function __construct(
        ?array $piiFields = null,
        ?array $piiPatterns = null,
        string $strategy = self::STRATEGY_HASH,
        int $priority = 50,
    ) {
        $this->piiFields = $piiFields ?? self::DEFAULT_PII_FIELDS;
        $this->piiPatterns = $piiPatterns ?? self::DEFAULT_PII_PATTERNS;
        $this->strategy = $strategy;
        $this->priority = $priority;
    }

    #[\Override]
    public function process(AnalyticsEvent $event): AnalyticsEvent
    {
        $params = $event->params;

        $params = $this->sanitizeKnownFields($params);
        $params = $this->sanitizeByPattern($params);

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }

    #[\Override]
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Sanitize known PII fields by name.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeKnownFields(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($key) && $this->isPiiField($key)) {
                $params[$key] = $this->sanitizeValue($value);
            }
        }

        return $params;
    }

    /**
     * Scan all string values against PII patterns and sanitize matches.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeByPattern(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($value) && $this->matchesPiiPattern($value) && ! $this->isPiiField((string) $key)) {
                $params[$key] = $this->sanitizeValue($value);
            }
        }

        return $params;
    }

    /**
     * Check if a field name is a known PII field.
     */
    private function isPiiField(string $fieldName): bool
    {
        $normalized = strtolower($fieldName);

        foreach ($this->piiFields as $piiField) {
            if ($normalized === $piiField || str_contains($normalized, $piiField)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a value matches any PII pattern.
     */
    private function matchesPiiPattern(string $value): bool
    {
        foreach ($this->piiPatterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a value according to the configured strategy.
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return match ($this->strategy) {
                self::STRATEGY_REMOVE => null,
                default => $value,
            };
        }

        return match ($this->strategy) {
            self::STRATEGY_HASH => $this->hashValue($value),
            self::STRATEGY_REMOVE => null,
            self::STRATEGY_MASK => $this->maskValue($value),
            default => $value,
        };
    }

    /**
     * Hash a PII value using SHA-256.
     */
    private function hashValue(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * Mask a PII value, showing only first and last characters.
     */
    private function maskValue(string $value): string
    {
        $length = strlen($value);

        if ($length <= 2) {
            return '**';
        }

        $visible = min(3, (int) floor($length * 0.25));

        return substr($value, 0, $visible)
            . str_repeat('*', $length - ($visible * 2))
            . substr($value, -$visible);
    }
}
