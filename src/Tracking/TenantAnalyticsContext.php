<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Multi-tenant analytics context for workspace-aware event tracking.
 *
 * Provides tenant isolation for analytics events in multi-tenant SaaS
 * applications. Events are automatically tagged with the current tenant
 * context, and analytics data can be queried per-tenant.
 *
 * Supports tenant identification from:
 * - Authenticated user's workspace/team relationship
 * - Subdomain matching
 * - Custom tenant resolver callback
 * - Explicit tenant ID in event params
 *
 * @version 5.0.0
 */
final class TenantAnalyticsContext
{
    /** @var string Current tenant ID (null = unscoped) */
    private ?string $currentTenantId = null;

    /** @var string Current tenant name (null = unscoped) */
    private ?string $currentTenantName = null;

    /** @var array<string, string|null> Tenant metadata */
    private array $tenantMeta = [];

    private CacheRepository $cache;

    /** @var int TTL for tenant-specific data (seconds) */
    private int $ttl;

    /** @var callable|null Custom tenant resolver */
    private $tenantResolver = null;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $ttl  TTL for tenant data (seconds)
     */
    public function __construct(CacheRepository $cache, int $ttl = 3600): void
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    /**
     * Set the current tenant context.
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  string|null  $tenantName  Human-readable tenant name
     * @param  array<string, string|null>  $meta  Additional metadata (plan, region, etc.)
     */
    public function setTenant(string $tenantId, ?string $tenantName = null, array $meta = []): void
    {
        $this->currentTenantId = $tenantId;
        $this->currentTenantName = $tenantName;
        $this->tenantMeta = $meta;
    }

    /**
     * Clear the current tenant context (return to unscoped).
     */
    public function clearTenant(): void
    {
        $this->currentTenantId = null;
        $this->currentTenantName = null;
        $this->tenantMeta = [];
    }

    /**
     * Get the current tenant ID.
     *
     * @return string|null
     */
    public function getTenantId(): ?string
    {
        return $this->currentTenantId;
    }

    /**
     * Get the current tenant name.
     *
     * @return string|null
     */
    public function getTenantName(): ?string
    {
        return $this->currentTenantName;
    }

    /**
     * Check if a tenant context is currently active.
     */
    public function hasTenant(): bool
    {
        return $this->currentTenantId !== null;
    }

    /**
     * Get the tenant metadata.
     *
     * @return array<string, string|null>
     */
    public function getTenantMeta(): array
    {
        return $this->tenantMeta;
    }

    /**
     * Get a specific metadata value.
     *
     * @param  string  $key  Metadata key
     * @return string|null
     */
    public function getMetaValue(string $key): ?string
    {
        return $this->tenantMeta[$key] ?? null;
    }

    /**
     * Set a custom tenant resolver callback.
     *
     * The resolver is called when no tenant is explicitly set.
     * It should return a tenant ID string or null.
     *
     * @param  callable(): (string|null)  $resolver
     */
    public function setResolver(callable $resolver): void
    {
        $this->tenantResolver = $resolver;
    }

    /**
     * Resolve the tenant ID using the custom resolver or return current.
     *
     * @return string|null
     */
    public function resolveTenant(): ?string
    {
        if ($this->currentTenantId !== null) {
            return $this->currentTenantId;
        }

        if ($this->tenantResolver !== null) {
            $resolved = ($this->tenantResolver)();

            if (is_string($resolved) && $resolved !== '') {
                $this->currentTenantId = $resolved;

                return $resolved;
            }
        }

        return null;
    }

    /**
     * Build tenant context params for event enrichment.
     *
     * Returns an array suitable for merging into event params
     * to tag the event with tenant context.
     *
     * @return array{tenant_id?: string, tenant_name?: string, ...array<string, string|null>}
     */
    public function eventContext(): array
    {
        if ($this->currentTenantId === null) {
            return [];
        }

        $context = [
            'tenant_id' => $this->currentTenantId,
        ];

        if ($this->currentTenantName !== null) {
            $context['tenant_name'] = $this->currentTenantName;
        }

        foreach ($this->tenantMeta as $key => $value) {
            if ($value !== null) {
                $context["tenant_{$key}"] = $value;
            }
        }

        return $context;
    }

    /**
     * Execute a callback within a specific tenant context.
     *
     * Automatically restores the previous tenant context after the
     * callback completes, even if an exception is thrown.
     *
     * @param  string  $tenantId  Tenant ID for the scope
     * @param  string|null  $tenantName  Tenant name
     * @param  callable(): T  $callback  Callback to execute
     * @return T
     *
     * @template T
     */
    public function withinTenant(string $tenantId, ?string $tenantName, callable $callback): mixed
    {
        $previousId = $this->currentTenantId;
        $previousName = $this->currentTenantName;
        $previousMeta = $this->tenantMeta;

        try {
            $this->setTenant($tenantId, $tenantName);

            return $callback();
        } finally {
            $this->currentTenantId = $previousId;
            $this->currentTenantName = $previousName;
            $this->tenantMeta = $previousMeta;
        }
    }

    /**
     * Increment a per-tenant event counter.
     *
     * @param  string  $eventName  Event name
     * @param  int  $count  Increment amount
     */
    public function incrementTenantEventCount(string $eventName, int $count = 1): void
    {
        if ($this->currentTenantId === null) {
            return;
        }

        $today = date('Y-m-d');
        $key = "zb_tenant_evt_{$this->currentTenantId}_{$today}_{$eventName}";

        $this->cache->increment($key, $count);
        $this->cache->increment("zb_tenant_evt_total_{$this->currentTenantId}_{$eventName}", $count);
    }

    /**
     * Get per-tenant event statistics.
     *
     * @param  string  $tenantId  Tenant ID
     * @return array{tenant_id: string, total_events: int, top_events: list<array{name: string, count: int}>}
     */
    public function getTenantStats(string $tenantId): array
    {
        $totalKey = "zb_tenant_total_{$tenantId}";
        $total = (int) ($this->cache->get($totalKey) ?? 0);

        return [
            'tenant_id' => $tenantId,
            'total_events' => $total,
            'top_events' => [], // Populated from event prefix scanning when available
        ];
    }

    /**
     * Record tenant revenue for aggregation.
     *
     * @param  string  $tenantId  Tenant ID
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  Currency code
     */
    public function recordTenantRevenue(string $tenantId, float $amount, string $currency = 'USD'): void
    {
        $month = date('Y-m');
        $key = "zb_tenant_rev_{$tenantId}_{$month}_{$currency}";
        $current = (float) ($this->cache->get($key) ?? 0);

        $this->cache->put($key, $current + $amount, $this->ttl * 24 * 32);
    }

    /**
     * Get monthly revenue for a tenant.
     *
     * @param  string  $tenantId  Tenant ID
     * @param  string  $currency  Currency code
     * @return array{tenant_id: string, amount: float, currency: string, month: string}
     */
    public function getTenantRevenue(string $tenantId, string $currency = 'USD'): array
    {
        $month = date('Y-m');
        $key = "zb_tenant_rev_{$tenantId}_{$month}_{$currency}";

        return [
            'tenant_id' => $tenantId,
            'amount' => (float) ($this->cache->get($key) ?? 0),
            'currency' => $currency,
            'month' => $month,
        ];
    }
}
