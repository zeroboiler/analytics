<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Scans event parameters for personally identifiable information (PII).
 *
 * Detects common PII patterns: email addresses, phone numbers, credit card numbers,
 * social security numbers, IP addresses in unexpected fields, and disallowed keys
 * (password, token, secret, api_key, etc.).
 *
 * Priority 30 (runs after schema validation).
 *
 * @since 69.0.0
 */
final class PiiScanningStage implements ValidationStageInterface
{
    /** @var list<string> Keys that are never allowed in event params */
    private const DISALLOWED_KEYS = [
        'password', 'password_confirmation', 'token', 'secret',
        'api_key', 'apikey', 'access_token', 'refresh_token',
        'credit_card', 'ssn', 'social_security',
    ];

    /** @var list<string> Regex patterns for PII detection */
    private const PII_PATTERNS = [
        'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        'phone' => '/\+?[\d\s\-\(\)]{7,15}/',
        'credit_card' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'ipv4' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
    ];

    private bool $enabled;

    /** @var list<string> Additional keys to disallow */
    private array $extraDisallowedKeys;

    /** @var list<string> Patterns to skip */
    private array $skipPatterns;

    /**
     * @param  array{enabled?: bool, extra_disallowed_keys?: list<string>, skip_patterns?: list<string>}  $config
     */
    public function __construct(array $config = []){
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->extraDisallowedKeys = (array) ($config['extra_disallowed_keys'] ?? []);
        $this->skipPatterns = (array) ($config['skip_patterns'] ?? []);
    }

    public function name(): string
    {
        return 'pii_scanning';
    }

    public function priority(): int
    {
        return 30;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'passed' => true,
                'errors' => [],
                'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1],
            ];
        }

        $errors = [];
        $checked = 0;
        $failed = 0;
        $allDisallowed = array_merge(self::DISALLOWED_KEYS, $this->extraDisallowedKeys);

        $checked++;
        foreach (array_keys($event->params) as $key) {
            if (in_array(strtolower((string) $key), $allDisallowed, true)) {
                $failed++;
                $errors[] = [
                    'code' => 'pii_disallowed_key',
                    'message' => "Parameter key '{$key}' is a disallowed PII field",
                    'field' => (string) $key,
                    'severity' => 'error',
                ];
            }
        }

        // Scan string values for PII patterns
        $checked++;
        foreach ($event->params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            // Skip patterns (e.g., known-safe fields)
            $shouldSkip = false;
            foreach ($this->skipPatterns as $pattern) {
                if (str_contains((string) $key, $pattern)) {
                    $shouldSkip = true;
                    break;
                }
            }
            if ($shouldSkip) {
                continue;
            }

            foreach (self::PII_PATTERNS as $piiType => $pattern) {
                if (preg_match($pattern, $value)) {
                    $errors[] = [
                        'code' => 'pii_detected',
                        'message' => "Potential {$piiType} detected in parameter '{$key}'",
                        'field' => (string) $key,
                        'severity' => 'warning',
                    ];
                }
            }
        }

        return [
            'passed' => $failed === 0,
            'errors' => $errors,
            'metrics' => [
                'checked' => $checked,
                'failed' => $failed,
                'skipped' => 0,
            ],
        ];
    }

    public function description(): string
    {
        return 'Scans event parameters for PII: emails, phones, credit cards, SSNs, and disallowed sensitive keys';
    }
}
