<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Server-Side Tag Manager for analytics providers.
 *
 * Enables runtime management of analytics provider configurations without
 * modifying config files or redeploying. Supports:
 * - Enable/disable individual providers at runtime
 * - Reorder provider dispatch priority
 * - Override provider settings (API keys, endpoints) per environment
 * - Scheduled provider activation/deactivation (e.g. maintenance windows)
 * - A/B test provider configurations
 * - Provider health monitoring with automatic failover
 *
 * All runtime overrides are cache-backed and survive across requests.
 * Config file values serve as defaults; runtime overrides take precedence.
 *
 * Configuration: `zeroboiler.analytics.tag_manager`
 *
 * @phpstan-type ProviderOverride array{enabled?: bool|null, priority?: int|null, settings?: array<string, mixed>|null, override_until?: \DateTimeImmutable|null, reason?: string|null}
 * @phpstan-type ProviderHealth array{status: 'healthy'|'degraded'|'down'|'unknown', last_check: string|null, consecutive_failures: int, avg_response_ms: float|null, failover_active: bool}
 *
 * @since 171.0.0
 */
final class AnalyticsProviderTagManager
{
    private const CACHE_PREFIX = 'zb_tag_manager_';

    private const DEFAULT_HEALTH_CHECK_INTERVAL = 300; // 5 minutes

    private const MAX_CONSECUTIVE_FAILURES = 5;

    private const FAILOVER_COOLDOWN = 3600; // 1 hour

    /** @var list<string> Known provider identifiers */
    private const PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
        'amplitude', 'mixpanel', 'tiktok', 'linkedin', 'webhook',
    ];

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, ProviderOverride> Runtime overrides keyed by provider name */
    private array $overrides = [];

    /** @var array<string, ProviderHealth> Provider health status */
    private array $healthStatus = [];

    /** @var int Max consecutive failures before auto-disable */
    private int $maxConsecutiveFailures;

    /** @var int Cooldown seconds before re-enabling a failed provider */
    private int $failoverCooldown;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly AnalyticsManager $manager,
    ){
        $tmConfig = $config->get('zeroboiler.analytics.tag_manager', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_consecutive_failures?: int, failover_cooldown?: int} $tmConfig */

        $this->enabled = (bool) ($tmConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($tmConfig['cache_ttl'] ?? 3600);
        $this->maxConsecutiveFailures = (int) ($tmConfig['max_consecutive_failures'] ?? self::MAX_CONSECUTIVE_FAILURES);
        $this->failoverCooldown = (int) ($tmConfig['failover_cooldown'] ?? self::FAILOVER_COOLDOWN);

        $this->loadOverrides();
        $this->loadHealthStatus();
    }

    // ── Provider Enable/Disable ───────────────────────────────────────

    /**
     * Enable a provider at runtime.
     *
     * @param  string  $provider  Provider identifier (ga4, meta_pixel, etc.)
     * @param  string|null  $reason  Optional reason for the change (audit trail)
     * @param  \DateTimeImmutable|null  $overrideUntil  Optional expiration time
     * @return bool True if the override was applied
     */
    public function enableProvider(string $provider, ?string $reason = null, ?\DateTimeImmutable $overrideUntil = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        $this->overrides[$provider] = [
            'enabled' => true,
            'priority' => null,
            'settings' => null,
            'override_until' => $overrideUntil,
            'reason' => $reason,
        ];

        $this->persistOverrides();

        Log::info('AnalyticsProviderTagManager: provider enabled', [
            'provider' => $provider,
            'reason' => $reason,
            'until' => $overrideUntil?->format('c'),
        ]);

        return true;
    }

    /**
     * Disable a provider at runtime.
     *
     * @param  string  $provider  Provider identifier
     * @param  string|null  $reason  Optional reason for the change
     * @param  \DateTimeImmutable|null  $overrideUntil  Optional auto-re-enable time
     * @return bool True if the override was applied
     */
    public function disableProvider(string $provider, ?string $reason = null, ?\DateTimeImmutable $overrideUntil = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        $this->overrides[$provider] = [
            'enabled' => false,
            'priority' => null,
            'settings' => null,
            'override_until' => $overrideUntil,
            'reason' => $reason,
        ];

        $this->persistOverrides();

        Log::info('AnalyticsProviderTagManager: provider disabled', [
            'provider' => $provider,
            'reason' => $reason,
            'until' => $overrideUntil?->format('c'),
        ]);

        return true;
    }

    /**
     * Check if a provider is effectively enabled (config + overrides).
     *
     * Accounts for expired overrides and auto-failover state.
     */
    public function isProviderActive(string $provider): bool
    {
        // Check for expired overrides first
        $this->expireOverrides($provider);

        // Check override
        if (isset($this->overrides[$provider]['enabled'])) {
            return $this->overrides[$provider]['enabled'] === true;
        }

        // Check health-based auto-failover
        $health = $this->healthStatus[$provider] ?? null;
        if ($health !== null && $health['failover_active']) {
            return false;
        }

        // Fall back to config default
        return $this->isProviderConfigEnabled($provider);
    }

    // ── Priority Management ───────────────────────────────────────────

    /**
     * Set dispatch priority for a provider.
     *
     * Lower values = higher priority (dispatched first).
     *
     * @param  string  $provider  Provider identifier
     * @param  int  $priority  Priority value (0-100, lower = first)
     * @return bool
     */
    public function setPriority(string $provider, int $priority): bool
    {
        if (! $this->enabled || ! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        $priority = max(0, min(100, $priority));

        $this->overrides[$provider]['priority'] = $priority;
        $this->persistOverrides();

        return true;
    }

    /**
     * Get the effective dispatch priority for a provider.
     *
     * @return int Priority value (0-100)
     */
    public function getPriority(string $provider): int
    {
        $this->expireOverrides($provider);

        if (isset($this->overrides[$provider]['priority'])) {
            return $this->overrides[$provider]['priority'];
        }

        // Default priorities from config
        $priorityConfig = $this->config->get('zeroboiler.analytics.provider_priorities', []);
        /** @var array<string, int> $priorityConfig */

        return (int) ($priorityConfig[$provider] ?? 50);
    }

    /**
     * Get all providers ordered by dispatch priority.
     *
     * @return list<string> Provider identifiers sorted by priority (ascending)
     */
    public function getOrderedProviders(): array
    {
        $active = array_filter(
            self::PROVIDERS,
            fn (string $p): bool => $this->isProviderActive($p),
        );

        usort($active, fn (string $a, string $b): int => $this->getPriority($a) <=> $this->getPriority($b));

        return $active;
    }

    // ── Settings Override ────────────────────────────────────────────

    /**
     * Override provider-specific settings at runtime.
     *
     * Useful for rotating API keys, changing endpoints, or A/B testing
     * different provider configurations.
     *
     * @param  string  $provider  Provider identifier
     * @param  array<string, mixed>  $settings  Key-value settings to override
     * @param  string|null  $reason  Audit reason
     * @return bool
     */
    public function overrideSettings(string $provider, array $settings, ?string $reason = null): bool
    {
        if (! $this->enabled || ! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        $this->overrides[$provider]['settings'] = $settings;
        if ($reason !== null) {
            $this->overrides[$provider]['reason'] = $reason;
        }

        $this->persistOverrides();

        return true;
    }

    /**
     * Get effective settings for a provider (config + runtime overrides).
     *
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(string $provider): array
    {
        $this->expireOverrides($provider);

        $baseSettings = $this->getProviderConfigSettings($provider);

        if (isset($this->overrides[$provider]['settings']) && is_array($this->overrides[$provider]['settings'])) {
            return array_merge($baseSettings, $this->overrides[$provider]['settings']);
        }

        return $baseSettings;
    }

    // ── Health Monitoring ────────────────────────────────────────────

    /**
     * Record a successful provider dispatch.
     *
     * Resets the consecutive failure counter.
     */
    public function recordSuccess(string $provider): void
    {
        $now = (new \DateTimeImmutable)->format('c');

        $health = $this->healthStatus[$provider] ?? [
            'status' => 'unknown',
            'last_check' => null,
            'consecutive_failures' => 0,
            'avg_response_ms' => null,
            'failover_active' => false,
        ];

        $health['consecutive_failures'] = 0;

        // Clear failover if provider is healthy again
        if ($health['failover_active']) {
            $health['failover_active'] = false;
            $health['status'] = 'healthy';
            Log::info('AnalyticsProviderTagManager: failover cleared', [
                'provider' => $provider,
            ]);
        }

        $health['status'] = 'healthy';
        $health['last_check'] = $now;

        $this->healthStatus[$provider] = $health;
        $this->persistHealthStatus();
    }

    /**
     * Record a failed provider dispatch.
     *
     * Increments failure counter and triggers auto-failover if threshold is reached.
     */
    public function recordFailure(string $provider, float $responseMs = 0.0): void
    {
        $now = (new \DateTimeImmutable)->format('c');

        $health = $this->healthStatus[$provider] ?? [
            'status' => 'unknown',
            'last_check' => null,
            'consecutive_failures' => 0,
            'avg_response_ms' => null,
            'failover_active' => false,
        ];

        $health['consecutive_failures']++;
        $health['last_check'] = $now;

        if ($responseMs > 0) {
            $prev = $health['avg_response_ms'] ?? $responseMs;
            $health['avg_response_ms'] = ($prev + $responseMs) / 2;
        }

        // Auto-failover threshold
        if ($health['consecutive_failures'] >= $this->maxConsecutiveFailures && ! $health['failover_active']) {
            $health['status'] = 'down';
            $health['failover_active'] = true;

            // Schedule auto-re-enable if not permanently disabled by override
            if (! isset($this->overrides[$provider]['enabled']) || $this->overrides[$provider]['enabled'] !== false) {
                $reEnableAt = (new \DateTimeImmutable)->modify("+{$this->failoverCooldown} seconds");
                $this->overrides[$provider] = [
                    'enabled' => false,
                    'priority' => null,
                    'settings' => null,
                    'override_until' => $reEnableAt,
                    'reason' => "Auto-failover after {$health['consecutive_failures']} consecutive failures",
                ];
                $this->persistOverrides();
            }

            Log::warning('AnalyticsProviderTagManager: auto-failover triggered', [
                'provider' => $provider,
                'consecutive_failures' => $health['consecutive_failures'],
                'cooldown_seconds' => $this->failoverCooldown,
            ]);
        } elseif ($health['consecutive_failures'] >= 3) {
            $health['status'] = 'degraded';
        }

        $this->healthStatus[$provider] = $health;
        $this->persistHealthStatus();
    }

    /**
     * Get health status for a specific provider.
     *
     * @return ProviderHealth
     */
    public function getHealth(string $provider): array
    {
        return $this->healthStatus[$provider] ?? [
            'status' => 'unknown',
            'last_check' => null,
            'consecutive_failures' => 0,
            'avg_response_ms' => null,
            'failover_active' => false,
        ];
    }

    /**
     * Get health status for all providers.
     *
     * @return array<string, ProviderHealth>
     */
    public function getAllHealth(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $provider) {
            $result[$provider] = $this->getHealth($provider);
        }

        return $result;
    }

    /**
     * Manually clear the failover state for a provider.
     */
    public function clearFailover(string $provider): bool
    {
        if (isset($this->healthStatus[$provider])) {
            $this->healthStatus[$provider]['failover_active'] = false;
            $this->healthStatus[$provider]['consecutive_failures'] = 0;
            $this->healthStatus[$provider]['status'] = 'healthy';
            $this->persistHealthStatus();
        }

        if (isset($this->overrides[$provider]) && ($this->overrides[$provider]['reason'] ?? '') === str_contains($this->overrides[$provider]['reason'] ?? '', 'Auto-failover')) {
            unset($this->overrides[$provider]);
            $this->persistOverrides();
        }

        return true;
    }

    // ── Bulk Operations ──────────────────────────────────────────────

    /**
     * Disable all providers (maintenance mode).
     *
     * @param  string|null  $reason  Audit reason
     * @return int Number of providers disabled
     */
    public function disableAll(?string $reason = null): int
    {
        $count = 0;
        foreach (self::PROVIDERS as $provider) {
            if ($this->isProviderActive($provider)) {
                $this->disableProvider($provider, $reason ?? 'Maintenance mode');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Restore all providers to config defaults.
     *
     * @return int Number of overrides cleared
     */
    public function restoreAll(): int
    {
        $count = count($this->overrides);
        $this->overrides = [];
        $this->persistOverrides();

        return $count;
    }

    /**
     * Clear all health data.
     */
    public function resetHealth(): void
    {
        $this->healthStatus = [];
        $this->persistHealthStatus();
    }

    // ── Summary & Dashboard ─────────────────────────────────────────

    /**
     * Get a comprehensive tag manager summary.
     *
     * @return array{enabled: bool, providers: int, active_providers: int, overrides: int, health_issues: int, ordered_providers: list<string>, provider_details: array<string, array{active: bool, priority: int, health: ProviderHealth, overridden: bool, settings_count: int}>}
     */
    public function summary(): array
    {
        $activeCount = 0;
        $healthIssues = 0;
        $providerDetails = [];

        foreach (self::PROVIDERS as $provider) {
            $isActive = $this->isProviderActive($provider);
            $health = $this->getHealth($provider);

            if ($isActive) {
                $activeCount++;
            }

            if (in_array($health['status'], ['degraded', 'down'], true)) {
                $healthIssues++;
            }

            $overridden = isset($this->overrides[$provider]);
            $settings = $this->getEffectiveSettings($provider);

            $providerDetails[$provider] = [
                'active' => $isActive,
                'priority' => $this->getPriority($provider),
                'health' => $health,
                'overridden' => $overridden,
                'settings_count' => count($settings),
            ];
        }

        return [
            'enabled' => $this->enabled,
            'providers' => count(self::PROVIDERS),
            'active_providers' => $activeCount,
            'overrides' => count($this->overrides),
            'health_issues' => $healthIssues,
            'ordered_providers' => $this->getOrderedProviders(),
            'provider_details' => $providerDetails,
        ];
    }

    /**
     * Get the audit trail of all overrides.
     *
     * @return list<array{provider: string, action: string, reason: string|null, override_until: string|null, applied_at: string}>
     */
    public function auditTrail(): array
    {
        $trail = [];
        foreach ($this->overrides as $provider => $override) {
            $trail[] = [
                'provider' => $provider,
                'action' => ($override['enabled'] ?? true) ? 'enabled' : 'disabled',
                'reason' => $override['reason'] ?? null,
                'override_until' => $override['override_until']?->format('c') ?? null,
                'applied_at' => $override['override_until']?->format('c') ?? 'persistent',
            ];
        }

        return $trail;
    }

    // ── Status ───────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all known provider identifiers.
     *
     * @return list<string>
     */
    public static function getProviders(): array
    {
        return self::PROVIDERS;
    }

    // ── Internal Helpers ─────────────────────────────────────────────

    /**
     * Check if a provider is enabled in the config file (default state).
     */
    private function isProviderConfigEnabled(string $provider): bool
    {
        $configMap = [
            'ga4' => 'zeroboiler.analytics.ga4.enabled',
            'gtm' => 'zeroboiler.analytics.gtm.enabled',
            'meta_pixel' => 'zeroboiler.analytics.meta_pixel.enabled',
            'plausible' => 'zeroboiler.analytics.plausible.enabled',
            'posthog' => 'zeroboiler.analytics.posthog.enabled',
            'amplitude' => 'zeroboiler.analytics.amplitude.enabled',
            'mixpanel' => 'zeroboiler.analytics.mixpanel.enabled',
            'tiktok' => 'zeroboiler.analytics.tiktok.enabled',
            'linkedin' => 'zeroboiler.analytics.linkedin.enabled',
            'webhook' => 'zeroboiler.analytics.webhook.enabled',
        ];

        $key = $configMap[$provider] ?? null;
        if ($key === null) {
            return false;
        }

        return (bool) $this->config->get($key, false);
    }

    /**
     * Get provider-specific settings from config.
     *
     * @return array<string, mixed>
     */
    private function getProviderConfigSettings(string $provider): array
    {
        $configMap = [
            'ga4' => 'zeroboiler.analytics.ga4',
            'gtm' => 'zeroboiler.analytics.gtm',
            'meta_pixel' => 'zeroboiler.analytics.meta_pixel',
            'plausible' => 'zeroboiler.analytics.plausible',
            'posthog' => 'zeroboiler.analytics.posthog',
            'amplitude' => 'zeroboiler.analytics.amplitude',
            'mixpanel' => 'zeroboiler.analytics.mixpanel',
            'tiktok' => 'zeroboiler.analytics.tiktok',
            'linkedin' => 'zeroboiler.analytics.linkedin',
            'webhook' => 'zeroboiler.analytics.webhook',
        ];

        $key = $configMap[$provider] ?? null;
        if ($key === null) {
            return [];
        }

        $settings = $this->config->get($key);
        if (! is_array($settings)) {
            return [];
        }

        return $settings;
    }

    /**
     * Expire time-limited overrides.
     */
    private function expireOverrides(string $provider): void
    {
        if (! isset($this->overrides[$provider]['override_until'])) {
            return;
        }

        $until = $this->overrides[$provider]['override_until'];
        if ($until instanceof \DateTimeImmutable && $until <= new \DateTimeImmutable) {
            Log::info('AnalyticsProviderTagManager: override expired', [
                'provider' => $provider,
            ]);
            unset($this->overrides[$provider]);
            $this->persistOverrides();
        }
    }

    /**
     * Load overrides from cache.
     */
    private function loadOverrides(): void
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . 'overrides');
        if (is_array($cached)) {
            $this->overrides = $cached;
        }
    }

    /**
     * Persist overrides to cache.
     */
    private function persistOverrides(): void
    {
        $this->cache->put(self::CACHE_PREFIX . 'overrides', $this->overrides, $this->cacheTtl);
    }

    /**
     * Load health status from cache.
     */
    private function loadHealthStatus(): void
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . 'health');
        if (is_array($cached)) {
            $this->healthStatus = $cached;
        }
    }

    /**
     * Persist health status to cache.
     */
    private function persistHealthStatus(): void
    {
        $this->cache->put(self::CACHE_PREFIX . 'health', $this->healthStatus, $this->cacheTtl);
    }
}
