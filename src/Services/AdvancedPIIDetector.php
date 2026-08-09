<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Advanced PII detection patterns for analytics event sanitization.
 *
 * Provides regex-based detection of personally identifiable information
 * in event parameters. Used by AnalyticsAnonymizationService to identify
 * and redact PII fields before dispatching to analytics providers.
 *
 * Detection is opt-in per field — only fields matching known PII patterns
 * are flagged. Custom patterns can be added via configuration.
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService
 */
final class AdvancedPIIDetector
{
    /**
     * Version for internal tracking.
     */
    public const VERSION = '4.6.0';

    /**
     * Built-in regex patterns for common PII types.
     *
     * Each pattern maps a PII type to its regex and a confidence score.
     * Higher confidence = more likely to be PII (1.0 = definitive).
     *
     * @var array<string, array{pattern: string, confidence: float, description: string}>
     */
    private const PATTERNS = [
        'email' => [
            'pattern' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            'confidence' => 1.0,
            'description' => 'Email address',
        ],
        'phone_us' => [
            'pattern' => '/\+?1?[2-9]\d{2}[.\-]?\d{3}[.\-]?\d{4}/',
            'confidence' => 0.7,
            'description' => 'US phone number',
        ],
        'phone_intl' => [
            'pattern' => '/\+\d{1,3}[\s\-]?\(?\d{1,4}\)?[\s\-]?\d{1,4}[\s\-]?\d{1,9}/',
            'confidence' => 0.6,
            'description' => 'International phone number',
        ],
        'ipv4' => [
            'pattern' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            'confidence' => 0.8,
            'description' => 'IPv4 address',
        ],
        'ipv6' => [
            'pattern' => '/(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}/',
            'confidence' => 0.9,
            'description' => 'IPv6 address (full)',
        ],
        'credit_card_visa' => [
            'pattern' => '/\b4[0-9]{12}(?:[0-9]{3})?\b/',
            'confidence' => 0.7,
            'description' => 'Visa card number',
        ],
        'credit_card_mc' => [
            'pattern' => '/\b5[1-5][0-9]{14}\b/',
            'confidence' => 0.7,
            'description' => 'Mastercard number',
        ],
        'credit_card_amex' => [
            'pattern' => '/\b3[47][0-9]{13}\b/',
            'confidence' => 0.7,
            'description' => 'Amex card number',
        ],
        'ssn' => [
            'pattern' => '/\b(?!000|666|9\d{2})\d{3}[- ]?(?!00)\d{2}[- ]?(?!0000)\d{4}\b/',
            'confidence' => 0.8,
            'description' => 'US Social Security Number',
        ],
        'date_of_birth' => [
            'pattern' => '/\b(19|20)\d{2}[-\/](0[1-9]|1[0-2])[-\/](0[1-9]|[12]\d|3[01])\b/',
            'confidence' => 0.4,
            'description' => 'Date (YYYY-MM-DD or YYYY/MM/DD) — low confidence',
        ],
        'iban' => [
            'pattern' => '/\b[A-Z]{2}\d{2}[A-Z0-9]{4,30}\b/',
            'confidence' => 0.6,
            'description' => 'IBAN (International Bank Account Number)',
        ],
        'jwt_token' => [
            'pattern' => '/\beyJ[A-Za-z0-9-_]+\.eyJ[A-Za-z0-9-_]+\.[A-Za-z0-9-_]+\b/',
            'confidence' => 1.0,
            'description' => 'JWT token',
        ],
        'api_key_generic' => [
            'pattern' => '/\b(sk|pk|api|key|secret|token)[_-]?[A-Za-z0-9]{16,}\b/',
            'confidence' => 0.5,
            'description' => 'Generic API key pattern',
        ],
        'address_street' => [
            'pattern' => '/\d+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr)\b/i',
            'confidence' => 0.6,
            'description' => 'Street address pattern',
        ],
        'zip_code_us' => [
            'pattern' => '/\b\d{5}(?:-\d{4})?\b/',
            'confidence' => 0.3,
            'description' => 'US ZIP code — low confidence',
        ],
    ];

    /**
     * Field name patterns that suggest the field contains PII.
     *
     * Used to flag parameter keys that likely contain PII based on their name,
     * even before checking the value content.
     *
     * @var list<string>
     */
    private const PII_FIELD_PATTERNS = [
        'email', 'e_mail', 'mail',
        'phone', 'mobile', 'telephone', 'cell',
        'first_name', 'last_name', 'fullname', 'full_name', 'name',
        'address', 'street', 'city', 'state', 'zip', 'postal', 'zipcode', 'postcode',
        'ssn', 'social_security', 'socialSecurity',
        'credit_card', 'creditcard', 'cc_number', 'card_number',
        'date_of_birth', 'dob', 'birthday',
        'ip_address', 'ip', 'ipAddress',
        'user_agent', 'userAgent',
        'password', 'secret', 'token',
        'account_number', 'accountNumber', 'iban', 'routing',
    ];

    /** @var array<string, array{pattern: string, confidence: float, description: string}> */
    private array $customPatterns = [];

    /** @var float Minimum confidence threshold for detection */
    private float $confidenceThreshold;

    /**
     * @param  float  $confidenceThreshold  Minimum confidence (0.0-1.0) for PII detection
     * @param  array<string, array{pattern: string, confidence?: float, description?: string}>  $customPatterns  Additional PII patterns
     */
    public function __construct(
        float $confidenceThreshold = 0.5,
        array $customPatterns = [],
    ): void {
        $this->confidenceThreshold = $confidenceThreshold;

        foreach ($customPatterns as $name => $pattern) {
            $this->customPatterns[$name] = [
                'pattern' => $pattern['pattern'],
                'confidence' => $pattern['confidence'] ?? 0.7,
                'description' => $pattern['description'] ?? "Custom: {$name}",
            ];
        }
    }

    /**
     * Scan a string value for PII patterns.
     *
     * Returns all detected PII types with their matched values, positions,
     * and confidence scores. Only matches above the confidence threshold
     * are returned.
     *
     * @param  string  $value  The value to scan
     * @return list<array{type: string, match: string, offset: int, confidence: float, description: string}>
     */
    public function scan(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $results = [];
        $allPatterns = array_merge(self::PATTERNS, $this->customPatterns);

        foreach ($allPatterns as $type => $config) {
            $pattern = $config['pattern'];
            $confidence = $config['confidence'];

            if ($confidence < $this->confidenceThreshold) {
                continue;
            }

            if (preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $results[] = [
                        'type' => $type,
                        'match' => $match[0],
                        'offset' => $match[1],
                        'confidence' => $confidence,
                        'description' => $config['description'],
                    ];
                }
            }
        }

        // Sort by offset
        usort($results, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $results;
    }

    /**
     * Check if a string value contains any PII above the confidence threshold.
     */
    public function containsPII(string $value): bool
    {
        return $this->scan($value) !== [];
    }

    /**
     * Check if a field name suggests it contains PII based on naming convention.
     *
     * @param  string  $fieldName  Parameter key name
     * @return array{is_pii: bool, matched_pattern: string|null, confidence: float}
     */
    public function isPIIField(string $fieldName): array
    {
        $normalizedName = strtolower(str_replace(['-', ' ', '_'], '', $fieldName));

        foreach (self::PII_FIELD_PATTERNS as $pattern) {
            $normalizedPattern = strtolower(str_replace(['-', ' ', '_'], '', $pattern));

            if ($normalizedName === $normalizedPattern || str_contains($normalizedName, $normalizedPattern)) {
                // Exact match = high confidence, partial match = medium
                $isExact = $normalizedName === $normalizedPattern;

                return [
                    'is_pii' => true,
                    'matched_pattern' => $pattern,
                    'confidence' => $isExact ? 1.0 : 0.7,
                ];
            }
        }

        return [
            'is_pii' => false,
            'matched_pattern' => null,
            'confidence' => 0.0,
        ];
    }

    /**
     * Scan an entire event params array for PII.
     *
     * Checks both field names (naming convention) and values (regex patterns).
     * Returns a structured report of all PII findings.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{pii_fields: list<string>, pii_values: array<string, list<array{type: string, match: string, confidence: float}>>, total_detections: int, has_pii: bool}
     */
    public function scanParams(array $params): array
    {
        $piiFields = [];
        $piiValues = [];
        $totalDetections = 0;

        foreach ($params as $key => $value) {
            // Check field name
            $fieldCheck = $this->isPIIField($key);

            if ($fieldCheck['is_pii']) {
                $piiFields[] = $key;
            }

            // Check field value (only scan string values)
            if (is_string($value) && $value !== '') {
                $valueScan = $this->scan($value);

                if ($valueScan !== []) {
                    $piiValues[$key] = $valueScan;
                    $totalDetections += count($valueScan);
                }
            }
        }

        return [
            'pii_fields' => $piiFields,
            'pii_values' => $piiValues,
            'total_detections' => $totalDetections,
            'has_pii' => $piiFields !== [] || $piiValues !== [],
        ];
    }

    /**
     * Redact PII from a string value.
     *
     * Replaces detected PII patterns with the specified mask character.
     *
     * @param  string  $value  The value to redact
     * @param  string  $mask  Mask character (default: *)
     * @return string  Redacted value
     */
    public function redact(string $value, string $mask = '*'): string
    {
        $detections = $this->scan($value);

        if ($detections === []) {
            return $value;
        }

        // Process from longest match first to avoid partial replacements
        usort($detections, fn (array $a, array $b): int => strlen($b['match']) <=> strlen($a['match']));

        $redacted = $value;

        foreach ($detections as $detection) {
            $match = $detection['match'];
            $offset = $detection['offset'];
            $length = strlen($match);

            // Preserve first and last character for readability
            if ($length <= 2) {
                $replacement = str_repeat($mask, $length);
            } else {
                $replacement = $match[0] . str_repeat($mask, $length - 2) . $match[$length - 1];
            }

            $redacted = substr($redacted, 0, $offset) . $replacement . substr($redacted, $offset + $length);
        }

        return $redacted;
    }

    /**
     * Get all available PII patterns (built-in + custom).
     *
     * @return array<string, array{pattern: string, confidence: float, description: string, source: string}>
     */
    public function getPatterns(): array
    {
        $result = [];

        foreach (self::PATTERNS as $name => $config) {
            $result[$name] = array_merge($config, ['source' => 'builtin']);
        }

        foreach ($this->customPatterns as $name => $config) {
            $result[$name] = array_merge($config, ['source' => 'custom']);
        }

        return $result;
    }

    /**
     * Get the list of PII field name patterns.
     *
     * @return list<string>
     */
    public function getFieldPatterns(): array
    {
        return self::PII_FIELD_PATTERNS;
    }

    /**
     * Get the current confidence threshold.
     */
    public function getConfidenceThreshold(): float
    {
        return $this->confidenceThreshold;
    }
}
