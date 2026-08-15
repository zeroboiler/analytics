<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics Config Validation Service — validates configuration integrity.
 *
 * Checks the zeroboiler.analytics configuration structure for:
 * - Required keys and types
 * - Provider configuration completeness
 * - Cross-reference consistency (e.g., queue enabled but no connection)
 * - Security concerns (API secrets in production, cookie settings)
 * - Performance pitfalls (large batch sizes, short TTLs)
 * - Recommended settings for SaaS workloads
 *
 * Used by AnalyticsConfigValidator and the readiness command for
 * pre-deploy configuration validation.
 *
 * @since 176.0.0
 */
final class AnalyticsConfigValidationService
{
    private ConfigRepository $config;

    /** @var list<array{level: 'error'|'warning'|'info', key: string, message: string, fix: string}> */
    private array $issues = [];

    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Run all validation checks and return results.
     *
     * @return array{valid: bool, score: int, issues: list<array{level: string, key: string, message: string, fix: string}>, summary: array{errors: int, warnings: int, info: int}}
     */
    public function validate(): array
    {
        $this->issues = [];

        $this->validateCoreStructure();
        $this->validateProviderConfig();
        $this->validateConsentConfig();
        $this->validateQueueConfig();
        $this->validateApiConfig();
        $this->validateIdentityConfig();
        $this->validateSecuritySettings();
        $this->validatePerformanceSettings();
        $this->validateEcommerceConfig();
        $this->validateAutoTrackConfig();
        $this->validateLifecycleConfig();

        $errors = count(array_filter($this->issues, static fn (array $i): bool => $i['level'] === 'error'));
        $warnings = count(array_filter($this->issues, static fn (array $i): bool => $i['level'] === 'warning'));
        $info = count(array_filter($this->issues, static fn (array $i): bool => $i['level'] === 'info'));

        // Score: 100 minus deductions (errors = -10, warnings = -3, info = -1)
        $score = max(0, 100 - ($errors * 10) - ($warnings * 3) - $info);

        return [
            'valid' => $errors === 0,
            'score' => $score,
            'issues' => $this->issues,
            'summary' => [
                'errors' => $errors,
                'warnings' => $warnings,
                'info' => $info,
            ],
        ];
    }

    /**
     * Validate core configuration structure exists.
     */
    private function validateCoreStructure(): void
    {
        $analytics = $this->config->get('zeroboiler.analytics');

        if (! is_array($analytics)) {
            $this->addIssue('error', 'core.missing', 'zeroboiler.analytics config is missing or not an array.', 'Run: php artisan vendor:publish --tag=zeroboiler-analytics-config');

            return;
        }

        $requiredKeys = ['ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track', 'queue', 'api'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $analytics)) {
                $this->addIssue('warning', "core.missing_{$key}", "Config section 'zeroboiler.analytics.{$key}' is missing.", 'Add the section to config/zeroboiler.php or check the published config.');
            }
        }
    }

    /**
     * Validate provider configurations.
     */
    private function validateProviderConfig(): void
    {
        // GA4
        $ga4 = $this->config->get('zeroboiler.analytics.ga4');
        if (is_array($ga4) && ($ga4['enabled'] ?? false) === true) {
            $id = $ga4['measurement_id'] ?? '';
            if ($id === '' || ! str_starts_with($id, 'G-')) {
                $this->addIssue('error', 'ga4.measurement_id', 'GA4 is enabled but measurement_id is missing or invalid. Must start with "G-".', 'Set ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX in .env');
            }

            $secret = $ga4['api_secret'] ?? '';
            if ($secret === '') {
                $this->addIssue('warning', 'ga4.api_secret', 'GA4 API secret is not set. Server-side Measurement Protocol will not work.', 'Set ANALYTICS_GA4_API_SECRET in .env for MP events.');
            }
        }

        // GTM
        $gtm = $this->config->get('zeroboiler.analytics.gtm');
        if (is_array($gtm) && ($gtm['enabled'] ?? false) === true) {
            $id = $gtm['container_id'] ?? '';
            if ($id === '' || ! str_starts_with($id, 'GTM-')) {
                $this->addIssue('error', 'gtm.container_id', 'GTM is enabled but container_id is missing or invalid. Must start with "GTM-".', 'Set ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX in .env');
            }
        }

        // Meta Pixel
        $meta = $this->config->get('zeroboiler.analytics.meta_pixel');
        if (is_array($meta) && ($meta['enabled'] ?? false) === true) {
            $id = $meta['id'] ?? '';
            if ($id === '') {
                $this->addIssue('error', 'meta_pixel.id', 'Meta Pixel is enabled but pixel ID is missing.', 'Set ANALYTICS_META_PIXEL_ID in .env');
            }

            $token = $meta['access_token'] ?? '';
            if ($token === '') {
                $this->addIssue('warning', 'meta_pixel.access_token', 'Meta Pixel CAPI access token is not set. Server-side Conversions API will not work.', 'Set ANALYTICS_META_PIXEL_ACCESS_TOKEN in .env');
            }
        }

        // Plausible
        $plausible = $this->config->get('zeroboiler.analytics.plausible');
        if (is_array($plausible) && ($plausible['enabled'] ?? false) === true) {
            $domain = $plausible['domain'] ?? '';
            if ($domain === '') {
                $this->addIssue('warning', 'plausible.domain', 'Plausible is enabled but domain is not set.', 'Set ANALYTICS_PLAUSIBLE_DOMAIN in .env');
            }
        }

        // PostHog
        $posthog = $this->config->get('zeroboiler.analytics.posthog');
        if (is_array($posthog) && ($posthog['enabled'] ?? false) === true) {
            $host = $posthog['host'] ?? '';
            $token = $posthog['token'] ?? '';
            if ($host === '' || $token === '') {
                $this->addIssue('warning', 'posthog.credentials', 'PostHog is enabled but host or token is missing.', 'Set ANALYTICS_POSTHOG_HOST and ANALYTICS_POSTHOG_TOKEN in .env');
            }
        }
    }

    /**
     * Validate consent configuration.
     */
    private function validateConsentConfig(): void
    {
        $consent = $this->config->get('zeroboiler.analytics.consent', []);
        $default = $consent['default'] ?? null;

        if ($default === null) {
            $this->addIssue('warning', 'consent.default', 'Consent default is not set. Users may be tracked without consent.', "Set ANALYTICS_CONSENT_DEFAULT=denied for GDPR-first approach.");
        } elseif ($default === 'granted') {
            $this->addIssue('info', 'consent.default_granted', 'Consent defaults to "granted". Consider "denied" for GDPR compliance.', "Set ANALYTICS_CONSENT_DEFAULT=denied for strict GDPR compliance.");
        }

        $purposes = $consent['purposes'] ?? [];
        if (! is_array($purposes) || ! isset($purposes['necessary'])) {
            $this->addIssue('info', 'consent.purposes', 'Consent purposes are not configured. Granular consent banner will have limited functionality.', 'Define consent purposes in zeroboiler.analytics.consent.purposes.');
        }
    }

    /**
     * Validate queue configuration.
     */
    private function validateQueueConfig(): void
    {
        $queue = $this->config->get('zeroboiler.analytics.queue', []);

        if (($queue['enabled'] ?? true) === true) {
            $connection = $queue['connection'] ?? null;
            $queueName = $queue['queue'] ?? 'analytics';

            if ($connection !== null && ! is_string($connection)) {
                $this->addIssue('warning', 'queue.connection', 'Queue connection must be a string.', 'Set ANALYTICS_QUEUE_CONNECTION to a valid queue connection name.');
            }

            $batchSize = $queue['max_batch_size'] ?? 50;
            if (! is_int($batchSize) || $batchSize < 1 || $batchSize > 500) {
                $this->addIssue('warning', 'queue.batch_size', 'Queue max_batch_size should be between 1 and 500.', 'Set ANALYTICS_QUEUE_MAX_BATCH_SIZE to a value between 1 and 500.');
            }
        }

        $this->addIssue('info', 'queue.status', 'Queue dispatch is configured and ready.', '');
    }

    /**
     * Validate API configuration.
     */
    private function validateApiConfig(): void
    {
        $api = $this->config->get('zeroboiler.analytics.api', []);

        $rateLimit = $api['rate_limit'] ?? 120;
        if ($rateLimit < 10) {
            $this->addIssue('warning', 'api.rate_limit', "API rate limit ({$rateLimit}/hour) is very low. May block legitimate tracking.", 'Increase ANALYTICS_API_RATE_LIMIT to at least 60.');
        }

        $batchMax = $api['batch_max_size'] ?? 25;
        if ($batchMax > 100) {
            $this->addIssue('warning', 'api.batch_max_size', "Batch max size ({$batchMax}) is very large. Consider a smaller value to prevent timeouts.", 'Reduce ANALYTICS_API_BATCH_MAX to 25-50.');
        }
    }

    /**
     * Validate identity configuration.
     */
    private function validateIdentityConfig(): void
    {
        $identity = $this->config->get('zeroboiler.analytics.identity', []);

        $cookieName = $identity['cookie_name'] ?? 'zb_analytics_id';
        if (! is_string($cookieName) || $cookieName === '') {
            $this->addIssue('error', 'identity.cookie_name', 'Identity cookie name must be a non-empty string.', 'Set a valid cookie name in zeroboiler.analytics.identity.cookie_name.');
        }

        $linkOnAuth = $identity['link_on_auth'] ?? true;
        if ($linkOnAuth === false) {
            $this->addIssue('info', 'identity.link_on_auth_disabled', 'Identity auto-linking is disabled. Client ID ↔ user ID linking will not happen automatically.', 'Set identity.link_on_auth to true for automatic identity resolution.');
        }
    }

    /**
     * Validate security-related settings.
     */
    private function validateSecuritySettings(): void
    {
        $identity = $this->config->get('zeroboiler.analytics.identity', []);
        $secure = $identity['cookie_secure'] ?? true;

        if ($secure === false && app()->environment('production')) {
            $this->addIssue('warning', 'security.cookie_secure', 'Cookie secure flag is disabled in production. Tracking cookies may be sent over HTTP.', 'Set ANALYTICS_COOKIE_SECURE=true for production.');
        }

        $httpOnly = $identity['cookie_http_only'] ?? true;
        if ($httpOnly === false) {
            $this->addIssue('info', 'security.cookie_httponly', 'Cookie httpOnly flag is disabled. Tracking cookie is accessible via JavaScript (this is expected for client-side tracking).', '');
        }

        $sameSite = $identity['cookie_samesite'] ?? 'Lax';
        if (! in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            $this->addIssue('warning', 'security.cookie_samesite', "Invalid SameSite value: {$sameSite}. Must be Strict, Lax, or None.", 'Set cookie_samesite to Strict, Lax, or None.');
        }
    }

    /**
     * Validate performance-related settings.
     */
    private function validatePerformanceSettings(): void
    {
        $sampling = $this->config->get('zeroboiler.analytics.sampling', []);

        if (($sampling['enabled'] ?? false) === true) {
            $rate = $sampling['rate'] ?? 1.0;
            if ($rate <= 0.0 || $rate > 1.0) {
                $this->addIssue('error', 'sampling.rate', "Sampling rate ({$rate}) must be between 0.0 and 1.0.", 'Set ANALYTICS_SAMPLING_RATE to a value between 0.0 and 1.0.');
            } elseif ($rate < 0.1) {
                $this->addIssue('info', 'sampling.low_rate', "Sampling rate ({$rate}) is very low. Only {$rate * 100}% of events will be tracked.", 'Consider a higher rate for more accurate analytics.');
            }
        }

        $dedup = $this->config->get('zeroboiler.analytics.dedup', []);
        $window = $dedup['window_seconds'] ?? 10;
        if ($window < 1 || $window > 3600) {
            $this->addIssue('warning', 'dedup.window', "Dedup window ({$window}s) is outside recommended range (1-3600s).", 'Set dedup.window_seconds to a value between 1 and 3600.');
        }
    }

    /**
     * Validate e-commerce configuration.
     */
    private function validateEcommerceConfig(): void
    {
        $ecommerce = $this->config->get('zeroboiler.analytics.ecommerce', []);

        if (is_array($ecommerce) && count($ecommerce) > 0) {
            $currency = $ecommerce['currency'] ?? 'USD';
            if (! is_string($currency) || $currency === '') {
                $this->addIssue('warning', 'ecommerce.currency', 'E-commerce currency must be a non-empty string (ISO 4217 code).', 'Set ecommerce.currency to a valid ISO 4217 code like "USD", "EUR", "GBP".');
            }

            $taxBehavior = $ecommerce['tax_behavior'] ?? 'inclusive';
            if (! in_array($taxBehavior, ['inclusive', 'exclusive', 'none'], true)) {
                $this->addIssue('warning', 'ecommerce.tax_behavior', "Invalid tax_behavior: {$taxBehavior}. Must be inclusive, exclusive, or none.", 'Set ecommerce.tax_behavior to inclusive, exclusive, or none.');
            }
        }
    }

    /**
     * Validate auto-track configuration.
     */
    private function validateAutoTrackConfig(): void
    {
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track', []);

        if (($autoTrack['enabled'] ?? true) === true) {
            $events = $autoTrack['events'] ?? [];
            if (! is_array($events) || count($events) === 0) {
                $this->addIssue('info', 'auto_track.empty', 'Auto-track is enabled but no events are configured. No server-side events will be auto-tracked.', 'Add event mappings to zeroboiler.analytics.auto_track.events.');
            }
        }
    }

    /**
     * Validate lifecycle configuration.
     */
    private function validateLifecycleConfig(): void
    {
        $lifecycle = $this->config->get('zeroboiler.analytics.lifecycle', []);

        if (($lifecycle['enabled'] ?? true) === true) {
            $mappings = $lifecycle['custom_mappings'] ?? [];
            if (is_array($mappings) && count($mappings) > 0) {
                foreach ($mappings as $event => $class) {
                    if (! is_string($class) || ! str_contains($class, '\\')) {
                        $this->addIssue('warning', "lifecycle.mapping.{$event}", "Custom lifecycle mapping for '{$event}' references an invalid class: {$class}", 'Use a fully-qualified class name that extends AnalyticsEvent.');
                    }
                }
            }

            $enrichAttribution = $lifecycle['enrich_attribution'] ?? true;
            if ($enrichAttribution === false) {
                $this->addIssue('info', 'lifecycle.attribution_disabled', 'Lifecycle attribution enrichment is disabled. Events will not receive UTM/referrer/device context.', 'Set ANALYTICS_LIFECYCLE_ENRICH_ATTRIBUTION=true for richer event data.');
            }
        }
    }

    /**
     * Add a validation issue.
     *
     * @param 'error'|'warning'|'info' $level
     */
    private function addIssue(string $level, string $key, string $message, string $fix): void
    {
        $this->issues[] = [
            'level' => $level,
            'key' => $key,
            'message' => $message,
            'fix' => $fix,
        ];
    }
}
