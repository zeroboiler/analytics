<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics-aware session recording bridge service.
 *
 * Provides consent-aware configuration for session recording tools
 * (Hotjar, LogRocket, FullStory, Clarity, etc.) that integrates with
 * the ZeroBoiler consent system. Enables recording only when consent
 * is granted and automatically suppresses recording for sensitive pages.
 *
 * @since 9.8.0
 */
final class SessionRecordingBridge
{
    /** @var array{hotjar?: array{site_id?: string, version?: int}, logrocket?: array{id?: string}, fullstory?: array{org?: string}, clarity?: array{project?: string}} */
    private array $integrations;

    private bool $enabled;

    private string $cachePrefix;

    private int $sessionTtl;

    /** @var list<string> Pages/URL patterns where recording is always suppressed */
    private array $excludedPatterns;

    /** @var list<string> Roles that are never recorded (admin, support) */
    private array $excludedRoles;

    /** @var bool Respect consent mode for session recording */
    private bool $consentAware;

    /** @var bool Mask PII in session recordings */
    private bool $maskPii;

    /** @var list<string> CSS selectors to mask in recordings */
    private array $maskSelectors;

    /** @var list<string> CSS selectors to block from recordings */
    private array $blockSelectors;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $recording = $config->get('zeroboiler.analytics.session_recording', []);
        /** @var array{enabled?: bool, integrations?: array<string, mixed>, cache_prefix?: string, session_ttl?: int, excluded_patterns?: list<string>, excluded_roles?: list<string>, consent_aware?: bool, mask_pii?: bool, mask_selectors?: list<string>, block_selectors?: list<string>} $recording */

        $this->enabled = (bool) ($recording['enabled'] ?? false);
        $this->integrations = (array) ($recording['integrations'] ?? []);
        $this->cachePrefix = (string) ($recording['cache_prefix'] ?? 'zb_recording_');
        $this->sessionTtl = (int) ($recording['session_ttl'] ?? 1800); // 30 minutes
        $this->excludedPatterns = (array) ($recording['excluded_patterns'] ?? [
            '/admin/*',
            '/billing/*',
            '/settings/*',
            '/api/*',
        ]);
        $this->excludedRoles = (array) ($recording['excluded_roles'] ?? ['admin', 'super_admin']);
        $this->consentAware = (bool) ($recording['consent_aware'] ?? true);
        $this->maskPii = (bool) ($recording['mask_pii'] ?? true);
        $this->maskSelectors = (array) ($recording['mask_selectors'] ?? [
            '[data-zb-mask]',
            '.masked',
        ]);
        $this->blockSelectors = (array) ($recording['block_selectors'] ?? [
            '[data-zb-block]',
            '.blocked',
        ]);
    }

    /**
     * Check if session recording is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && ! empty($this->integrations);
    }

    /**
     * Check if recording should be active for the current user.
     *
     * Respects consent state, excluded roles, and excluded pages.
     *
     * @param  array{consent?: array<string, string>, user_role?: string|null, current_url?: string|null}  $context
     */
    public function shouldRecord(array $context = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        // Consent check
        if ($this->consentAware) {
            $consent = $context['consent'] ?? [];
            $analyticsConsent = $consent['analytics_storage'] ?? 'granted';

            if ($analyticsConsent !== 'granted') {
                return false;
            }
        }

        // Role check
        $userRole = $context['user_role'] ?? null;
        if ($userRole !== null && in_array($userRole, $this->excludedRoles, true)) {
            return false;
        }

        // URL pattern check
        $url = $context['current_url'] ?? '';
        if ($url !== '' && $this->matchesExcludedPattern($url)) {
            return false;
        }

        return true;
    }

    /**
     * Get the enabled integrations as a list of provider names.
     *
     * @return list<string>
     */
    public function getEnabledProviders(): array
    {
        return array_keys($this->integrations);
    }

    /**
     * Get configuration for a specific integration.
     *
     * @return array<string, mixed>|null
     */
    public function getIntegrationConfig(string $provider): ?array
    {
        return $this->integrations[$provider] ?? null;
    }

    /**
     * Check if a specific integration is configured.
     */
    public function hasIntegration(string $provider): bool
    {
        return isset($this->integrations[$provider]);
    }

    /**
     * Generate the client-side configuration for session recording.
     *
     * Returns a structured config object that the JS client can use
     * to initialize recording tools conditionally.
     *
     * @param  array{consent?: array<string, string>, user_role?: string|null, current_url?: string|null}  $context
     * @return array{enabled: bool, providers: array<string, mixed>, maskPii: bool, maskSelectors: list<string>, blockSelectors: list<string>, consentAware: bool, excludedPatterns: list<string>}
     */
    public function getClientConfig(array $context = []): array
    {
        $active = $this->shouldRecord($context);

        $providers = [];
        foreach ($this->integrations as $name => $config) {
            $providers[$name] = [
                'enabled' => $active,
                'config' => $config,
            ];
        }

        return [
            'enabled' => $active,
            'providers' => $providers,
            'maskPii' => $this->maskPii,
            'maskSelectors' => $this->maskSelectors,
            'blockSelectors' => $this->blockSelectors,
            'consentAware' => $this->consentAware,
            'excludedPatterns' => $this->excludedPatterns,
        ];
    }

    /**
     * Check if consent-aware mode is enabled.
     */
    public function isConsentAware(): bool
    {
        return $this->consentAware;
    }

    /**
     * Get PII masking configuration.
     *
     * @return array{enabled: bool, maskSelectors: list<string>, blockSelectors: list<string>}
     */
    public function getPiiConfig(): array
    {
        return [
            'enabled' => $this->maskPii,
            'maskSelectors' => $this->maskSelectors,
            'blockSelectors' => $this->blockSelectors,
        ];
    }

    /**
     * Get all excluded URL patterns.
     *
     * @return list<string>
     */
    public function getExcludedPatterns(): array
    {
        return $this->excludedPatterns;
    }

    /**
     * Get all excluded roles.
     *
     * @return list<string>
     */
    public function getExcludedRoles(): array
    {
        return $this->excludedRoles;
    }

    /**
     * Get session recording statistics.
     *
     * @return array{enabled: bool, integrations: list<string>, consent_aware: bool, mask_pii: bool, excluded_pattern_count: int, excluded_role_count: int}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'integrations' => $this->getEnabledProviders(),
            'consent_aware' => $this->consentAware,
            'mask_pii' => $this->maskPii,
            'excluded_pattern_count' => count($this->excludedPatterns),
            'excluded_role_count' => count($this->excludedRoles),
        ];
    }

    /**
     * Check if a URL matches any excluded pattern (simple glob-style matching).
     */
    private function matchesExcludedPattern(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        foreach ($this->excludedPatterns as $pattern) {
            // Convert glob-style pattern to regex
            $regex = '#^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$#';

            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}
