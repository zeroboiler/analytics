<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Analytics configuration validator.
 *
 * Validates the analytics config structure on boot to catch misconfigurations
 * early. Checks provider credentials, required settings, and cross-dependencies.
 * Returns a list of warnings and errors that can be surfaced via health endpoint
 * or admin commands.
 *
 * Designed for production-grade SaaS deployments where misconfigured analytics
 * can silently lose tracking data.
 */
final class AnalyticsConfigValidator
{
    /** @var list<array{level: 'error'|'warning'|'info', message: string, config_key: string}> */
    private array $issues = [];

    private readonly ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
: void {
        $this->config = $config;
    }

    /**
     * Run all validation checks.
     *
     * @return array{valid: bool, errors: int, warnings: int, info: int, issues: list<array{level: string, message: string, config_key: string}>}
     */
    public function validate(): array
    {
        $this->issues = [];

        $this->validateGa4();
        $this->validateGtm();
        $this->validateMetaPixel();
        $this->validatePlausible();
        $this->validatePosthog();
        $this->validateWebhook();
        $this->validateQueue();
        $this->validateIdentity();
        $this->validateConsent();
        $this->validateSampling();
        $this->validateRetention();
        $this->validateApi();
        $this->validateReplay();

        $errors = count(array_filter($this->issues, fn (array $i): bool => $i['level'] === 'error'));
        $warnings = count(array_filter($this->issues, fn (array $i): bool => $i['level'] === 'warning'));
        $info = count(array_filter($this->issues, fn (array $i): bool => $i['level'] === 'info'));

        return [
            'valid' => $errors === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'issues' => $this->issues,
        ];
    }

    /**
     * Check if the configuration is valid (no errors).
     */
    public function isValid(): bool
    {
        return $this->validate()['valid'];
    }

    /**
     * Get only error-level issues.
     *
     * @return list<array{level: string, message: string, config_key: string}>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->validate()['issues'],
            fn (array $i): bool => $i['level'] === 'error',
        ));
    }

    /**
     * Get only warning-level issues.
     *
     * @return list<array{level: string, message: string, config_key: string}>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->validate()['issues'],
            fn (array $i): bool => $i['level'] === 'warning',
        ));
    }

    /**
     * Get the full validation result with all issues.
     *
     * @return array{valid: bool, errors: int, warnings: int, info: int, issues: list<array{level: string, message: string, config_key: string}>}
     */
    public function result(): array
    {
        return $this->validate();
    }

    // ── Provider Validators ─────────────────────────────────────────

    private function validateGa4(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.ga4.enabled', false);
        $id = $this->config->get('zeroboiler.analytics.ga4.measurement_id', '');
        $secret = $this->config->get('zeroboiler.analytics.ga4.api_secret', '');

        if (! $enabled) {
            return;
        }

        if ($id === '' || $id === null) {
            $this->addIssue('error', 'GA4 is enabled but measurement_id is empty', 'ga4.measurement_id');
        } elseif (! str_starts_with((string) $id, 'G-')) {
            $this->addIssue('warning', 'GA4 measurement_id should start with "G-"', 'ga4.measurement_id');
        }

        if ($secret === '' || $secret === null) {
            $this->addIssue('info', 'GA4 api_secret is empty — server-side MP will not work', 'ga4.api_secret');
        }
    }

    private function validateGtm(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.gtm.enabled', false);
        $id = $this->config->get('zeroboiler.analytics.gtm.container_id', '');

        if (! $enabled) {
            return;
        }

        if ($id === '' || $id === null) {
            $this->addIssue('error', 'GTM is enabled but container_id is empty', 'gtm.container_id');
        } elseif (! str_starts_with((string) $id, 'GTM-')) {
            $this->addIssue('warning', 'GTM container_id should start with "GTM-"', 'gtm.container_id');
        }
    }

    private function validateMetaPixel(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.meta_pixel.enabled', false);
        $id = $this->config->get('zeroboiler.analytics.meta_pixel.id', '');
        $token = $this->config->get('zeroboiler.analytics.meta_pixel.access_token', '');

        if (! $enabled) {
            return;
        }

        if ($id === '' || $id === null) {
            $this->addIssue('error', 'Meta Pixel is enabled but pixel id is empty', 'meta_pixel.id');
        }

        if ($token === '' || $token === null) {
            $this->addIssue('info', 'Meta Pixel access_token is empty — CAPI will not work', 'meta_pixel.access_token');
        }
    }

    private function validatePlausible(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.plausible.enabled', false);
        $domain = $this->config->get('zeroboiler.analytics.plausible.domain', '');

        if (! $enabled) {
            return;
        }

        if ($domain === '' || $domain === null) {
            $this->addIssue('error', 'Plausible is enabled but domain is empty', 'plausible.domain');
        }
    }

    private function validatePosthog(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.posthog.enabled', false);
        $key = $this->config->get('zeroboiler.analytics.posthog.api_key', '');

        if (! $enabled) {
            return;
        }

        if ($key === '' || $key === null) {
            $this->addIssue('error', 'PostHog is enabled but api_key is empty', 'posthog.api_key');
        }
    }

    private function validateWebhook(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.webhook.enabled', false);
        $url = $this->config->get('zeroboiler.analytics.webhook.url', '');

        if (! $enabled) {
            return;
        }

        if ($url === '' || $url === null) {
            $this->addIssue('error', 'Webhook is enabled but url is empty', 'webhook.url');
        } elseif (! str_starts_with((string) $url, 'https://')) {
            $this->addIssue('warning', 'Webhook URL should use HTTPS for security', 'webhook.url');
        }

        $sign = $this->config->get('zeroboiler.analytics.webhook.sign', false);
        $secret = $this->config->get('zeroboiler.analytics.webhook.secret', '');

        if ($sign && ($secret === '' || $secret === null)) {
            $this->addIssue('warning', 'Webhook signing is enabled but secret is empty', 'webhook.secret');
        }
    }

    private function validateQueue(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.queue.enabled', true);
        $name = $this->config->get('zeroboiler.analytics.queue.queue', 'analytics');

        if (! $enabled) {
            $this->addIssue('info', 'Queue dispatch is disabled — all events will be synchronous', 'queue.enabled');
        }

        if ($name !== null && ! is_string($name)) {
            $this->addIssue('error', 'Queue name must be a string', 'queue.queue');
        }
    }

    private function validateIdentity(): void
    {
        $ttl = $this->config->get('zeroboiler.analytics.identity.cookie_ttl', 525600);

        if ($ttl !== null && ((int) $ttl) < 1440) {
            $this->addIssue('warning', 'Identity cookie TTL is less than 1 day — tracking IDs may expire frequently', 'identity.cookie_ttl');
        }

        $samesite = $this->config->get('zeroboiler.analytics.identity.cookie_samesite', 'Lax');
        $validSameSite = ['Strict', 'Lax', 'None'];

        if (! in_array($samesite, $validSameSite, true)) {
            $this->addIssue('error', "Invalid cookie_samesite value: {$samesite}. Must be Strict, Lax, or None", 'identity.cookie_samesite');
        }
    }

    private function validateConsent(): void
    {
        $default = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        $validDefaults = ['granted', 'denied'];

        if (! in_array($default, $validDefaults, true)) {
            $this->addIssue('error', "Invalid consent default: {$default}. Must be 'granted' or 'denied'", 'consent.default');
        }

        if ($default === 'denied') {
            $this->addIssue('info', 'Consent defaults to denied — no events will be tracked until user grants consent', 'consent.default');
        }
    }

    private function validateSampling(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.sampling.enabled', false);

        if (! $enabled) {
            return;
        }

        $rate = (float) $this->config->get('zeroboiler.analytics.sampling.rate', 1.0);

        if ($rate <= 0.0 || $rate > 1.0) {
            $this->addIssue('error', "Sampling rate must be between 0.0 and 1.0, got: {$rate}", 'sampling.rate');
        } elseif ($rate < 0.1) {
            $this->addIssue('warning', "Sampling rate is very low ({$rate}) — more than 90% of events will be dropped", 'sampling.rate');
        }
    }

    private function validateRetention(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.retention.enabled', false);
        $days = (int) $this->config->get('zeroboiler.analytics.retention.days', 90);

        if (! $enabled) {
            return;
        }

        if ($days < 1) {
            $this->addIssue('error', 'Retention days must be at least 1', 'retention.days');
        } elseif ($days < 30) {
            $this->addIssue('warning', "Retention period is very short ({$days} days) — analytics data will be lost quickly", 'retention.days');
        }
    }

    private function validateApi(): void
    {
        $throttle = (int) $this->config->get('zeroboiler.analytics.api.throttle', 60);

        if ($throttle < 1) {
            $this->addIssue('error', 'API throttle rate must be at least 1 request per minute', 'api.throttle');
        } elseif ($throttle > 1000) {
            $this->addIssue('warning', "API throttle rate is very high ({$throttle}/min) — consider adding auth", 'api.throttle');
        }
    }

    private function validateReplay(): void
    {
        $enabled = $this->config->get('zeroboiler.analytics.replay.enabled', true);
        $maxAttempts = (int) $this->config->get('zeroboiler.analytics.replay.max_attempts', 3);

        if (! $enabled) {
            $this->addIssue('info', 'Event replay queue is disabled — failed events will be lost', 'replay.enabled');
        }

        if ($maxAttempts < 1) {
            $this->addIssue('error', 'Replay max_attempts must be at least 1', 'replay.max_attempts');
        } elseif ($maxAttempts > 10) {
            $this->addIssue('warning', "Replay max_attempts is high ({$maxAttempts}) — failed events will be retried many times", 'replay.max_attempts');
        }
    }

    /**
     * Add a validation issue.
     *
     * @param  'error'|'warning'|'info'  $level
     */
    private function addIssue(string $level, string $message, string $configKey): void
    {
        $this->issues[] = [
            'level' => $level,
            'message' => $message,
            'config_key' => $configKey,
        ];
    }

    /**
     * Get the total number of issues found.
     */
    public function issueCount(): int
    {
        return count($this->issues);
    }

    /**
     * Check if there are any issues at the given level.
     */
    public function hasIssues(string $level = 'error'): bool
    {
        return count(array_filter(
            $this->issues,
            fn (array $i): bool => $i['level'] === $level,
        )) > 0;
    }
}
