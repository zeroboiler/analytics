<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Cross-provider event dispatch consistency checker.
 *
 * Verifies that events dispatched to multiple analytics providers maintain
 * consistent naming, parameter structure, and identity linkage. Detects
 * configuration drift, missing mappings, and provider-specific anomalies.
 *
 * Inspired by Segment's Schema Validator and LaunchDarkly's event consistency checks.
 *
 * @since 10.8.0
 */
final class AnalyticsConsistencyService
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private CacheRepository $cache;

    private int $cacheTtl;

    /** @var list<string> Providers that require catalog mapping validation */
    private const MAPPED_PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible'];

    /** @var list<string> Required identity fields that must be present in all dispatched events */
    private const REQUIRED_IDENTITY_FIELDS = ['client_id'];

    public function __construct(
        AnalyticsManager $manager,
        ConfigRepository $config,
        CacheRepository $cache,
        int $cacheTtl = 300,
    ){
        $this->manager = $manager;
        $this->config = $config;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Run a full consistency check across all catalog events and providers.
     *
     * @return array{score: int, grade: string, checks: array<string, array{status: string, issues: list<string>, warnings: list<string>}>}
     */
    public function fullCheck(): array
    {
        $cacheKey = 'zb_consistency_full';
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $checks = [
            'catalog_integrity' => $this->checkCatalogIntegrity(),
            'provider_mapping' => $this->checkProviderMappings(),
            'identity_consistency' => $this->checkIdentityConsistency(),
            'config_validity' => $this->checkConfigValidity(),
            'naming_convention' => $this->checkNamingConvention(),
            'provider_config' => $this->checkProviderConfig(),
        ];

        $totalIssues = 0;
        $totalChecks = 0;

        foreach ($checks as $check) {
            $totalChecks++;
            $totalIssues += count($check['issues']);
        }

        // Score: 100 - (issues * 5), minimum 0
        $score = max(0, 100 - ($totalIssues * 5));
        $grade = $this->calculateGrade($score);

        $result = [
            'score' => $score,
            'grade' => $grade,
            'checks' => $checks,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Quick consistency check — returns only the score.
     */
    public function quickScore(): int
    {
        return $this->fullCheck()['score'];
    }

    /**
     * Check that all catalog events are internally consistent.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkCatalogIntegrity(): array
    {
        $issues = [];
        $warnings = [];
        $validation = EventCatalog::validate();

        if (! $validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $issues[] = "Catalog validation error: {$error}";
            }
        }

        foreach ($validation['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        foreach (EventCatalog::all() as $name => $entry) {
            $className = $entry['class'] ?? null;
            if ($className !== null) {
                $expectedSuffix = str_replace('_', '', ucwords($name, '_')) . 'Event';
                // Just verify class exists (can't autoload without Laravel)
                if (! class_exists($className)) {
                    $issues[] = "Event '{$name}' references non-existent class '{$className}'";
                }
            }
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check that all enabled providers have valid mappings for catalog events.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkProviderMappings(): array
    {
        $issues = [];
        $warnings = [];
        $enabledProviders = $this->getEnabledProviderKeys();

        if ($enabledProviders === []) {
            $issues[] = 'No analytics providers are enabled';

            return [
                'status' => 'fail',
                'issues' => $issues,
                'warnings' => $warnings,
            ];
        }

        $coreEvents = EventCatalog::coreSaaS();
        $allEvents = EventCatalog::all();

        foreach ($enabledProviders as $provider) {
            $providerMappingKey = $this->getProviderMappingKey($provider);
            $missingCore = [];

            foreach ($coreEvents as $eventName) {
                $entry = $allEvents[$eventName] ?? null;
                if ($entry === null) {
                    continue;
                }

                $mapped = $entry[$providerMappingKey] ?? null;

                // Meta and Plausible can be null (not all events map)
                if ($providerMappingKey === 'meta' || $providerMappingKey === 'plausible') {
                    continue;
                }

                if ($mapped === null) {
                    $missingCore[] = $eventName;
                }
            }

            if ($missingCore !== []) {
                $warnings[] = sprintf(
                    'Provider "%s" missing mappings for core events: %s',
                    $provider,
                    implode(', ', array_slice($missingCore, 0, 5))
                );
            }
        }

        foreach ($allEvents as $name => $entry) {
            if (($entry['meta'] ?? null) !== null && ! isset($entry['ga4'])) {
                $warnings[] = "Event '{$name}' has Meta mapping but no GA4 mapping";
            }
        }

        return [
            'status' => empty($issues) && empty($warnings) ? 'pass' : (empty($issues) ? 'warn' : 'fail'),
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check identity tracking configuration consistency.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkIdentityConsistency(): array
    {
        $issues = [];
        $warnings = [];
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, cookie_ttl?: int, link_on_auth?: bool, cache_prefix?: string, link_ttl?: int} $identityConfig */

        // Cookie name must be set
        $cookieName = $identityConfig['cookie_name'] ?? null;
        if (! is_string($cookieName) || $cookieName === '') {
            $issues[] = 'Identity cookie name is not configured';
        }

        // Cookie TTL should be reasonable (at least 30 days, at most 2 years)
        $cookieTtl = $identityConfig['cookie_ttl'] ?? 525600;
        if ($cookieTtl < 43200) {
            $warnings[] = sprintf('Identity cookie TTL (%d minutes) is very short; consider increasing for cross-session tracking', $cookieTtl);
        }
        if ($cookieTtl > 1051200) {
            $warnings[] = sprintf('Identity cookie TTL (%d minutes) exceeds 2 years; may not be respected by browsers', $cookieTtl);
        }

        // Link-on-auth should be enabled for identity stitching
        if (! ($identityConfig['link_on_auth'] ?? true)) {
            $warnings[] = 'Identity auto-linking is disabled; client_id ↔ user_id stitching will not occur on auth';
        }

        $cachePrefix = $identityConfig['cache_prefix'] ?? null;
        if (! is_string($cachePrefix) || $cachePrefix === '') {
            $warnings[] = 'Identity cache prefix is not configured; link cache may conflict with other keys';
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check overall configuration validity.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkConfigValidity(): array
    {
        $issues = [];
        $warnings = [];

        // Queue configuration
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, max_batch_size?: int} $queueConfig */
        if (($queueConfig['enabled'] ?? true) && empty($queueConfig['queue'] ?? '')) {
            $warnings[] = 'Queue is enabled but no queue name is configured (defaults to "analytics")';
        }

        $maxBatch = $queueConfig['max_batch_size'] ?? 50;
        if ($maxBatch > 500) {
            $warnings[] = sprintf('Queue max batch size (%d) is very large; consider reducing to avoid memory issues', $maxBatch);
        }

        // Consent defaults
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string} $consentConfig */
        $consentDefault = $consentConfig['default'] ?? 'granted';
        if ($consentDefault === 'granted') {
            $warnings[] = 'Consent default is "granted"; for GDPR compliance, consider setting to "denied"';
        }

        // Debug mode should not be enabled in production
        $debugConfig = $this->config->get('zeroboiler.analytics.debug', []);
        /** @var array{enabled?: bool} $debugConfig */
        if ($debugConfig['enabled'] ?? false) {
            $warnings[] = 'Debug mode is enabled; disable in production to prevent verbose logging';
        }

        // Sampling rate validation
        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        /** @var array{enabled?: bool, rate?: float} $samplingConfig */
        if (($samplingConfig['enabled'] ?? false)) {
            $rate = $samplingConfig['rate'] ?? 1.0;
            if ($rate <= 0.0 || $rate > 1.0) {
                $issues[] = sprintf('Sampling rate (%.2f) is outside valid range [0.0, 1.0]', $rate);
            }
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check that event names follow the snake_case naming convention.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkNamingConvention(): array
    {
        $issues = [];
        $warnings = [];
        $total = 0;
        $violations = 0;

        foreach (EventCatalog::all() as $name => $entry) {
            $total++;
            if ($name !== strtolower($name)) {
                $violations++;
                $issues[] = "Event name '{$name}' is not lowercase";
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $violations++;
                $issues[] = "Event name '{$name}' does not follow snake_case convention";
            }
        }

        if ($total > 0 && $violations > 0) {
            $warnings[] = sprintf('%d/%d event names violate naming convention (%.1f%%)', $violations, $total, ($violations / $total) * 100);
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check individual provider configuration for common issues.
     *
     * @return array{status: string, issues: list<string>, warnings: list<string>}
     */
    public function checkProviderConfig(): array
    {
        $issues = [];
        $warnings = [];

        // GA4
        if ($this->manager->ga4()->isEnabled()) {
            $ga4Id = $this->manager->ga4()->getMeasurementId();
            if ($ga4Id === '' || $ga4Id === 'G-') {
                $issues[] = 'GA4 is enabled but measurement ID is not configured';
            }
        }

        // GTM
        if ($this->manager->gtm()->isEnabled()) {
            $gtmId = $this->manager->gtm()->getContainerId();
            if ($gtmId === '' || $gtmId === 'GTM-') {
                $issues[] = 'GTM is enabled but container ID is not configured';
            }
        }

        // Meta Pixel
        if ($this->manager->meta()->isEnabled()) {
            $metaId = $this->manager->meta()->getPixelId();
            if ($metaId === '') {
                $issues[] = 'Meta Pixel is enabled but pixel ID is not configured';
            }
            $accessToken = $this->config->get('zeroboiler.analytics.meta_pixel.access_token', '');
            if ($accessToken === '') {
                $warnings[] = 'Meta Pixel is enabled but access token is missing; server-side CAPI will not work';
            }
        }

        // Plausible
        if ($this->manager->plausible()->isEnabled()) {
            $domain = $this->config->get('zeroboiler.analytics.plausible.domain', '');
            if ($domain === '') {
                $issues[] = 'Plausible is enabled but domain is not configured';
            }
        }

        // PostHog
        if ($this->manager->posthog()->isEnabled()) {
            $apiKey = $this->config->get('zeroboiler.analytics.posthog.api_key', '');
            if ($apiKey === '') {
                $issues[] = 'PostHog is enabled but API key is not configured';
            }
        }

        // Mixpanel
        if ($this->manager->mixpanel()->isEnabled()) {
            $token = $this->config->get('zeroboiler.analytics.mixpanel.token', '');
            if ($token === '') {
                $issues[] = 'Mixpanel is enabled but token is not configured';
            }
        }

        // Amplitude
        if ($this->manager->amplitude()->isEnabled()) {
            $apiKey = $this->config->get('zeroboiler.analytics.amplitude.api_key', '');
            if ($apiKey === '') {
                $issues[] = 'Amplitude is enabled but API key is not configured';
            }
        }

        return [
            'status' => empty($issues) ? 'pass' : 'fail',
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Invalidate the consistency check cache.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget('zb_consistency_full');
    }

    /**
     * Get enabled provider keys from config.
     *
     * @return list<string>
     */
    private function getEnabledProviderKeys(): array
    {
        $providers = [];

        if ($this->manager->ga4()->isEnabled()) {
            $providers[] = 'ga4';
        }

        if ($this->manager->meta()->isEnabled()) {
            $providers[] = 'meta';
        }

        if ($this->manager->posthog()->isEnabled()) {
            $providers[] = 'posthog';
        }

        if ($this->manager->plausible()->isEnabled()) {
            $providers[] = 'plausible';
        }

        if ($this->manager->mixpanel()->isEnabled()) {
            $providers[] = 'mixpanel';
        }

        if ($this->manager->amplitude()->isEnabled()) {
            $providers[] = 'amplitude';
        }

        return $providers;
    }

    /**
     * Get the catalog entry key for a provider name.
     */
    private function getProviderMappingKey(string $provider): string
    {
        return match ($provider) {
            'ga4' => 'ga4',
            'meta' => 'meta',
            'posthog' => 'posthog',
            'plausible' => 'plausible',
            default => $provider,
        };
    }

    /**
     * Calculate letter grade from a numeric score.
     */
    private function calculateGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'A-',
            $score >= 80 => 'B+',
            $score >= 75 => 'B',
            $score >= 70 => 'B-',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default => 'F',
        };
    }
}
