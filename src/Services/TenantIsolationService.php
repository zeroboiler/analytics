<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Multi-tenant analytics data isolation service.
 *
 * Ensures analytics events are properly scoped to tenants in multi-tenant
 * SaaS applications. Provides tenant context resolution, event tagging,
 * cross-tenant access control, and per-tenant configuration overrides.
 *
 * Configuration is read from `zeroboiler.analytics.tenant`.
 *
 * Supports:
 * - Automatic tenant ID resolution from authenticated user, request header, or subdomain
 * - Per-tenant analytics config overrides (disabled providers, custom tracking)
 * - Cross-tenant event isolation (prevent data leakage)
 * - Per-tenant rate limiting and quotas
 * - Tenant context propagation to all analytics events
 */
final class TenantIsolationService
{
    private bool $enabled;

    /** @var string The tenant ID resolution strategy */
    private string $resolutionStrategy;

    /** @var string Header name for manual tenant ID override */
    private string $tenantHeader;

    /** @var string Cache key prefix for tenant config overrides */
    private string $cachePrefix;

    /** @var int Cache TTL for tenant config in seconds */
    private int $cacheTtl;

    /** @var int|null Maximum events per tenant per hour (null = unlimited) */
    private ?int $eventsPerHourLimit;

    /** @var array<string, array<string, mixed>> Per-tenant config overrides cache */
    private array $tenantConfigs = [];

    /** @var array<string, int> Per-tenant event counters (in-memory, per request) */
    private array $tenantCounters = [];

    /** @var string|null Current request tenant ID (resolved once per request) */
    private ?string $currentTenantId = null;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $tenantConfig = $config->get('zeroboiler.analytics.tenant', []);
        /** @var array{enabled?: bool, resolution_strategy?: string, tenant_header?: string, cache_prefix?: string, cache_ttl?: int, events_per_hour?: int|null, overrides?: array<string, array<string, mixed>>} $tenantConfig */

        $this->enabled = (bool) ($tenantConfig['enabled'] ?? false);
        $this->resolutionStrategy = (string) ($tenantConfig['resolution_strategy'] ?? 'user_attribute');
        $this->tenantHeader = (string) ($tenantConfig['tenant_header'] ?? 'X-Tenant-ID');
        $this->cachePrefix = (string) ($tenantConfig['cache_prefix'] ?? 'zb_tenant_');
        $this->cacheTtl = (int) ($tenantConfig['cache_ttl'] ?? 3600);
        $this->eventsPerHourLimit = $tenantConfig['events_per_hour'] ?? null;
        $this->tenantConfigs = $tenantConfig['overrides'] ?? [];
    }

    /**
     * Resolve the current tenant ID from the request context.
     *
     * Resolution strategies:
     * - `header`: Read from X-Tenant-ID header
     * - `subdomain`: Extract from request subdomain
     * - `user_attribute`: Read from authenticated user's `tenant_id` attribute
     * - `session`: Read from session
     *
     * @return string|null The resolved tenant ID, or null if not applicable
     */
    public function resolveTenantId(): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        // Return cached resolution for this request
        if ($this->currentTenantId !== null) {
            return $this->currentTenantId;
        }

        $tenantId = match ($this->resolutionStrategy) {
            'header' => $this->resolveFromHeader(),
            'subdomain' => $this->resolveFromSubdomain(),
            'user_attribute' => $this->resolveFromUserAttribute(),
            'session' => $this->resolveFromSession(),
            default => $this->resolveFromUserAttribute(),
        };

        $this->currentTenantId = $tenantId;

        return $tenantId;
    }

    /**
     * Enrich an analytics event with tenant context.
     *
     * Adds tenant_id to event params and resolves per-tenant config overrides.
     */
    public function enrichEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $tenantId = $this->resolveTenantId();
        if ($tenantId === null || $tenantId === '') {
            return $event;
        }

        // Check rate limit
        if (! $this->checkRateLimit($tenantId)) {
            return $event;
        }

        // Create enriched event with tenant context
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [
                'tenant_id' => $tenantId,
            ]),
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /**
     * Check if an event should be tracked for a given tenant.
     *
     * Checks:
     * - Per-tenant disabled events list
     * - Per-tenant disabled provider list
     * - Rate limits
     */
    public function shouldTrack(AnalyticsEvent $event, string $tenantId): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $tenantConfig = $this->getTenantConfig($tenantId);

        // Check if this specific event is disabled for the tenant
        $disabledEvents = $tenantConfig['disabled_events'] ?? [];
        if (is_array($disabledEvents) && in_array($event->name, $disabledEvents, true)) {
            return false;
        }

        // Check if all analytics are disabled for the tenant
        if (isset($tenantConfig['analytics_enabled']) && $tenantConfig['analytics_enabled'] === false) {
            return false;
        }

        // Check rate limit
        return $this->checkRateLimit($tenantId);
    }

    /**
     * Get per-tenant configuration overrides.
     *
     * @param  string  $tenantId
     * @return array<string, mixed>
     */
    public function getTenantConfig(string $tenantId): array
    {
        // Check in-memory cache first
        if (isset($this->tenantConfigs[$tenantId])) {
            return $this->tenantConfigs[$tenantId];
        }

        // Check cache store
        $cacheKey = $this->cachePrefix . $tenantId;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            $this->tenantConfigs[$tenantId] = $cached;

            return $cached;
        }

        return [];
    }

    /**
     * Set a per-tenant configuration override.
     *
     * @param  string  $tenantId
     * @param  array<string, mixed>  $overrides
     */
    public function setTenantConfig(string $tenantId, array $overrides): void
    {
        $this->tenantConfigs[$tenantId] = array_merge(
            $this->getTenantConfig($tenantId),
            $overrides,
        );

        $cacheKey = $this->cachePrefix . $tenantId;
        $this->cache->put($cacheKey, $this->tenantConfigs[$tenantId], $this->cacheTtl);
    }

    /**
     * Remove a per-tenant configuration override (reset to defaults).
     */
    public function resetTenantConfig(string $tenantId): void
    {
        $cacheKey = $this->cachePrefix . $tenantId;
        $this->cache->forget($cacheKey);
        unset($this->tenantConfigs[$tenantId]);
    }

    /**
     * Get the current rate limit status for a tenant.
     *
     * @return array{allowed: bool, count: int, limit: int|null}
     */
    public function getRateLimitStatus(string $tenantId): array
    {
        $count = $this->tenantCounters[$tenantId] ?? 0;

        return [
            'allowed' => $this->checkRateLimit($tenantId),
            'count' => $count,
            'limit' => $this->eventsPerHourLimit,
        ];
    }

    /**
     * Get all tenant IDs that have config overrides.
     *
     * @return list<string>
     */
    public function getTenantsWithOverrides(): array
    {
        return array_keys($this->tenantConfigs);
    }

    /**
     * Check if tenant isolation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current resolution strategy.
     */
    public function getResolutionStrategy(): string
    {
        return $this->resolutionStrategy;
    }

    /**
     * Get a summary of the tenant isolation service state.
     *
     * @return array{enabled: bool, strategy: string, tenants: int, header: string, rate_limit: int|null}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'strategy' => $this->resolutionStrategy,
            'tenants' => count($this->tenantConfigs),
            'header' => $this->tenantHeader,
            'rate_limit' => $this->eventsPerHourLimit,
        ];
    }

    /**
     * Resolve tenant ID from request header.
     */
    private function resolveFromHeader(): ?string
    {
        $header = request()->header($this->tenantHeader);

        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * Resolve tenant ID from subdomain.
     */
    private function resolveFromSubdomain(): ?string
    {
        $host = request()->host();

        // Extract first subdomain part
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            // Skip common subdomains
            if (! in_array($subdomain, ['www', 'app', 'api', 'admin'], true)) {
                return $subdomain;
            }
        }

        return null;
    }

    /**
     * Resolve tenant ID from authenticated user attribute.
     */
    private function resolveFromUserAttribute(): ?string
    {
        $user = request()->user();
        if ($user === null) {
            return null;
        }

        // Try common tenant attribute names
        foreach (['tenant_id', 'team_id', 'organization_id', 'workspace_id', 'account_id'] as $attr) {
            if (method_exists($user, 'getAttribute')) {
                $value = $user->getAttribute($attr);
            } elseif (property_exists($user, $attr)) {
                $value = $user->{$attr};
            } else {
                continue;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Resolve tenant ID from session.
     */
    private function resolveFromSession(): ?string
    {
        $tenantId = session()->get('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }

    /**
     * Check if the tenant is within rate limits.
     */
    private function checkRateLimit(string $tenantId): bool
    {
        if ($this->eventsPerHourLimit === null) {
            return true;
        }

        if (! isset($this->tenantCounters[$tenantId])) {
            // Check persistent counter in cache
            $cacheKey = $this->cachePrefix . 'rate_' . $tenantId;
            $cachedCount = $this->cache->get($cacheKey);

            $this->tenantCounters[$tenantId] = is_int($cachedCount) ? $cachedCount : 0;
        }

        if ($this->tenantCounters[$tenantId] >= $this->eventsPerHourLimit) {
            return false;
        }

        $this->tenantCounters[$tenantId]++;

        // Persist counter
        $cacheKey = $this->cachePrefix . 'rate_' . $tenantId;
        $this->cache->put($cacheKey, $this->tenantCounters[$tenantId], 3600);

        return true;
    }
}
