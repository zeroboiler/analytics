<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Validates analytics configuration completeness and correctness.
 *
 * Performs a comprehensive check of all analytics config sections,
 * detecting missing values, invalid types, insecure defaults, and
 * cross-section dependency issues. Useful for CI/CD gates, health
 * checks, and onboarding diagnostics.
 *
 * Usage:
 *   $validator = new AnalyticsConfigValidator($config);
 *   $report = $validator->validate();
 *   if ($report['score'] < 80) {
 *       // warn about incomplete config
 *   }
 *
 * @since 238.0.0
 */
final class AnalyticsConfigValidator
{
    private ConfigRepository $config;

    /** @var list<array{section: string, key: string, severity: 'error'|'warning'|'info', message: string}> */
    private array $issues = [];

    /** @var list<array{section: string, key: string, status: 'present'|'missing'|'invalid'|'insecure'}> */
    private array $findings = [];

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /**
     * Run all validation checks and return a report.
     *
     * @return array{score: int, grade: string, issues: list<array{section: string, key: string, severity: string, message: string}>, findings: list<array{section: string, key: string, status: string}>, section_scores: array<string, int>, summary: array{total: int, errors: int, warnings: int, info: int}}
     */
    public function validate(): array
    {
        $this->issues = [];
        $this->findings = [];

        // Required sections
        $this->validateSection('providers.ga4', $this->ga4Checks());
        $this->validateSection('providers.gtm', $this->gtmChecks());
        $this->validateSection('providers.meta', $this->metaChecks());
        $this->validateSection('providers.plausible', $this->plausibleChecks());
        $this->validateSection('providers.posthog', $this->posthogChecks());
        $this->validateSection('consent', $this->consentChecks());
        $this->validateSection('api', $this->apiChecks());
        $this->validateSection('identity', $this->identityChecks());
        $this->validateSection('queue', $this->queueChecks());
        $this->validateSection('debug', $this->debugChecks());
        $this->validateSection('sampling', $this->samplingChecks());
        $this->validateSection('auto_track', $this->autoTrackChecks());

        // Cross-section validation
        $this->validateCrossSection();

        return $this->buildReport();
    }

    /**
     * Get validation checks for the GA4 provider section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function ga4Checks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_GA4_ENABLED',
                'severity' => 'info',
                'message' => 'GA4 is not explicitly enabled — defaults to false',
            ],
            'measurement_id' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_GA4_MEASUREMENT_ID',
                'severity' => 'warning',
                'message' => 'GA4 measurement ID is missing — server-side tracking disabled',
            ],
            'api_secret' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_GA4_API_SECRET',
                'severity' => 'warning',
                'message' => 'GA4 API secret is missing — Measurement Protocol disabled',
            ],
        ];
    }

    /**
     * Get validation checks for the GTM provider section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function gtmChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_GTM_ENABLED',
                'severity' => 'info',
                'message' => 'GTM is not explicitly enabled — defaults to false',
            ],
            'container_id' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_GTM_CONTAINER_ID',
                'severity' => 'warning',
                'message' => 'GTM container ID is missing — dataLayer pushes disabled',
            ],
        ];
    }

    /**
     * Get validation checks for the Meta Pixel provider section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function metaChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_META_ENABLED',
                'severity' => 'info',
                'message' => 'Meta Pixel is not explicitly enabled — defaults to false',
            ],
            'pixel_id' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_META_PIXEL_ID',
                'severity' => 'warning',
                'message' => 'Meta Pixel ID is missing — CAPI and client tracking disabled',
            ],
            'access_token' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_META_ACCESS_TOKEN',
                'severity' => 'warning',
                'message' => 'Meta CAPI access token is missing — server-side events disabled',
            ],
        ];
    }

    /**
     * Get validation checks for the Plausible provider section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function plausibleChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_PLAUSIBLE_ENABLED',
                'severity' => 'info',
                'message' => 'Plausible is not explicitly enabled — defaults to false',
            ],
            'domain' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_PLAUSIBLE_DOMAIN',
                'severity' => 'warning',
                'message' => 'Plausible domain is missing — tracking disabled',
            ],
        ];
    }

    /**
     * Get validation checks for the PostHog provider section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function posthogChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_POSTHOG_ENABLED',
                'severity' => 'info',
                'message' => 'PostHog is not explicitly enabled — defaults to false',
            ],
            'api_key' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_POSTHOG_API_KEY',
                'severity' => 'warning',
                'message' => 'PostHog API key is missing — event ingestion disabled',
            ],
            'host' => [
                'required' => true,
                'type' => 'string',
                'env' => 'ANALYTICS_POSTHOG_HOST',
                'severity' => 'info',
                'message' => 'PostHog host is missing — defaults to app.posthog.com',
            ],
        ];
    }

    /**
     * Get validation checks for the consent section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function consentChecks(): array
    {
        return [
            'default' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_CONSENT_DEFAULT',
                'severity' => 'warning',
                'message' => 'Consent default is not set — events may fire before consent is collected',
            ],
            'mode' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_CONSENT_MODE',
                'severity' => 'info',
                'message' => 'Consent mode not set — defaults to basic mode',
            ],
        ];
    }

    /**
     * Get validation checks for the API section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function apiChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_API_ENABLED',
                'severity' => 'info',
                'message' => 'API endpoint is not explicitly enabled',
            ],
            'rate_limit' => [
                'required' => false,
                'type' => 'int',
                'env' => 'ANALYTICS_API_RATE_LIMIT',
                'severity' => 'info',
                'message' => 'API rate limit not set — defaults to 60 requests/min',
            ],
        ];
    }

    /**
     * Get validation checks for the identity section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function identityChecks(): array
    {
        return [
            'cookie_name' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_IDENTITY_COOKIE_NAME',
                'severity' => 'info',
                'message' => 'Identity cookie name not set — defaults to zb_analytics_id',
            ],
            'cookie_ttl' => [
                'required' => false,
                'type' => 'int',
                'env' => 'ANALYTICS_IDENTITY_COOKIE_TTL',
                'severity' => 'info',
                'message' => 'Identity cookie TTL not set — defaults to 525600 minutes (1 year)',
            ],
            'cookie_samesite' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_IDENTITY_COOKIE_SAMESITE',
                'severity' => 'info',
                'message' => 'Identity cookie SameSite not set — defaults to Lax',
            ],
        ];
    }

    /**
     * Get validation checks for the queue section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function queueChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_QUEUE_ENABLED',
                'severity' => 'info',
                'message' => 'Queue dispatch not explicitly enabled — events processed synchronously',
            ],
            'connection' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_QUEUE_CONNECTION',
                'severity' => 'info',
                'message' => 'Queue connection not set — defaults to default',
            ],
            'queue' => [
                'required' => false,
                'type' => 'string',
                'env' => 'ANALYTICS_QUEUE_NAME',
                'severity' => 'info',
                'message' => 'Queue name not set — defaults to analytics',
            ],
        ];
    }

    /**
     * Get validation checks for the debug section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function debugChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_DEBUG_ENABLED',
                'severity' => 'info',
                'message' => 'Debug mode not set',
            ],
        ];
    }

    /**
     * Get validation checks for the sampling section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function samplingChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_SAMPLING_ENABLED',
                'severity' => 'info',
                'message' => 'Sampling not enabled — all events tracked',
            ],
            'rate' => [
                'required' => false,
                'type' => 'float',
                'env' => 'ANALYTICS_SAMPLING_RATE',
                'severity' => 'info',
                'message' => 'Sampling rate not set — defaults to 1.0 (100%)',
            ],
        ];
    }

    /**
     * Get validation checks for the auto_track section.
     *
     * @return array<string, array{required: bool, type: string, env: string, severity: string, message: string}>
     */
    private function autoTrackChecks(): array
    {
        return [
            'enabled' => [
                'required' => false,
                'type' => 'bool',
                'env' => 'ANALYTICS_AUTO_TRACK_ENABLED',
                'severity' => 'info',
                'message' => 'Server-side auto-tracking not explicitly configured',
            ],
        ];
    }

    /**
     * Validate a single config section against its checks.
     *
     * @param  string  $section  Dot-notation config section
     * @param  array<string, array{required: bool, type: string, env: string, severity: string, message: string}>  $checks
     */
    private function validateSection(string $section, array $checks): void
    {
        $sectionPresent = [];

        foreach ($checks as $key => $check) {
            $dotKey = "zeroboiler.analytics.{$section}.{$key}";
            $value = $this->config->get($dotKey);
            $hasValue = $value !== null;

            $sectionPresent[$key] = $hasValue;

            if (! $hasValue && $check['required']) {
                $this->issues[] = [
                    'section' => $section,
                    'key' => $key,
                    'severity' => $check['severity'],
                    'message' => $check['message'],
                ];
                $this->findings[] = [
                    'section' => $section,
                    'key' => $key,
                    'status' => 'missing',
                ];
            } elseif ($hasValue && $this->isInvalidType($value, $check['type'])) {
                $this->issues[] = [
                    'section' => $section,
                    'key' => $key,
                    'severity' => 'warning',
                    'message' => "Expected type {$check['type']}, got " . get_debug_type($value),
                ];
                $this->findings[] = [
                    'section' => $section,
                    'key' => $key,
                    'status' => 'invalid',
                ];
            } else {
                $this->findings[] = [
                    'section' => $section,
                    'key' => $key,
                    'status' => $hasValue ? 'present' : 'missing',
                ];
            }
        }
    }

    /**
     * Validate cross-section dependencies.
     */
    private function validateCrossSection(): void
    {
        // If GA4 is enabled but no measurement_id → error
        $ga4Enabled = $this->config->get('zeroboiler.analytics.providers.ga4.enabled', false);
        $ga4Id = $this->config->get('zeroboiler.analytics.providers.ga4.measurement_id');
        if ($ga4Enabled && (! is_string($ga4Id) || $ga4Id === '')) {
            $this->issues[] = [
                'section' => 'cross-section',
                'key' => 'ga4.enabled_without_id',
                'severity' => 'error',
                'message' => 'GA4 is enabled but measurement_id is missing — no events will be sent',
            ];
        }

        // If Meta is enabled but no pixel_id → error
        $metaEnabled = $this->config->get('zeroboiler.analytics.providers.meta.enabled', false);
        $metaId = $this->config->get('zeroboiler.analytics.providers.meta.pixel_id');
        if ($metaEnabled && (! is_string($metaId) || $metaId === '')) {
            $this->issues[] = [
                'section' => 'cross-section',
                'key' => 'meta.enabled_without_id',
                'severity' => 'error',
                'message' => 'Meta Pixel is enabled but pixel_id is missing — no events will be sent',
            ];
        }

        // If no provider is enabled at all → info
        $anyEnabled = $ga4Enabled
            || $metaEnabled
            || $this->config->get('zeroboiler.analytics.providers.gtm.enabled', false)
            || $this->config->get('zeroboiler.analytics.providers.plausible.enabled', false)
            || $this->config->get('zeroboiler.analytics.providers.posthog.enabled', false);

        if (! $anyEnabled) {
            $this->issues[] = [
                'section' => 'cross-section',
                'key' => 'no_providers_enabled',
                'severity' => 'info',
                'message' => 'No analytics providers are enabled — events will not be dispatched',
            ];
        }

        // If queue is enabled but connection/queue not set → warning
        $queueEnabled = $this->config->get('zeroboiler.analytics.queue.enabled', false);
        if ($queueEnabled) {
            $connection = $this->config->get('zeroboiler.analytics.queue.connection');
            $queue = $this->config->get('zeroboiler.analytics.queue.queue');
            if ($connection === null || $queue === null) {
                $this->issues[] = [
                    'section' => 'cross-section',
                    'key' => 'queue.incomplete_config',
                    'severity' => 'warning',
                    'message' => 'Queue dispatch enabled but connection/queue not configured — may fail',
                ];
            }
        }

        // Debug mode should not be enabled in production
        $debugEnabled = $this->config->get('zeroboiler.analytics.debug.enabled', false);
        $appEnv = $this->config->get('app.env', 'production');
        if ($debugEnabled && $appEnv === 'production') {
            $this->issues[] = [
                'section' => 'cross-section',
                'key' => 'debug.in_production',
                'severity' => 'error',
                'message' => 'Analytics debug mode is enabled in production environment',
            ];
        }
    }

    /**
     * Check if a value matches the expected type.
     */
    private function isInvalidType(mixed $value, string $type): bool
    {
        return match ($type) {
            'bool' => ! is_bool($value),
            'string' => ! is_string($value),
            'int' => ! is_int($value),
            'float' => ! is_float($value) && ! is_int($value),
            'array' => ! is_array($value),
            default => false,
        };
    }

    /**
     * Build the validation report with score and grade.
     *
     * @return array{score: int, grade: string, issues: list<array{section: string, key: string, severity: string, message: string}>, findings: list<array{section: string, key: string, status: string}>, section_scores: array<string, int>, summary: array{total: int, errors: int, warnings: int, info: int}}
     */
    private function buildReport(): array
    {
        $errors = array_filter($this->issues, fn (array $i): bool => $i['severity'] === 'error');
        $warnings = array_filter($this->issues, fn (array $i): bool => $i['severity'] === 'warning');
        $infos = array_filter($this->issues, fn (array $i): bool => $i['severity'] === 'info');

        // Score: 100 minus penalties
        $score = 100;
        $score -= (count($errors) * 20);   // -20 per error
        $score -= (count($warnings) * 5);  // -5 per warning
        $score -= (count($infos) * 1);     // -1 per info
        $score = max(0, $score);

        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };

        // Section scores
        $sectionScores = [];
        foreach ($this->findings as $finding) {
            $section = $finding['section'];
            if (! isset($sectionScores[$section])) {
                $sectionScores[$section] = ['total' => 0, 'present' => 0];
            }
            $sectionScores[$section]['total']++;
            if ($finding['status'] === 'present') {
                $sectionScores[$section]['present']++;
            }
        }

        $sectionPercent = [];
        foreach ($sectionScores as $section => $counts) {
            $sectionPercent[$section] = $counts['total'] > 0
                ? (int) round(($counts['present'] / $counts['total']) * 100)
                : 0;
        }

        return [
            'score' => $score,
            'grade' => $grade,
            'issues' => array_values($this->issues),
            'findings' => array_values($this->findings),
            'section_scores' => $sectionPercent,
            'summary' => [
                'total' => count($this->issues),
                'errors' => count($errors),
                'warnings' => count($warnings),
                'info' => count($infos),
            ],
        ];
    }

    /**
     * Quick check — is the config minimally viable for production?
     *
     * Returns true if no errors exist (warnings and info are acceptable).
     */
    public function isProductionReady(): bool
    {
        $report = $this->validate();

        return $report['summary']['errors'] === 0;
    }
}
