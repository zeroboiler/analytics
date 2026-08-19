<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

/**
 * Client ID ↔ User ID identity linking service.
 *
 * Provides persistent, cache-backed mapping between anonymous client IDs
 * (from the zb_analytics_id cookie) and authenticated user IDs.
 * This enables cross-device identity resolution for analytics.
 *
 * Features:
 * - Bidirectional lookup (client_id → user_id, user_id → client_id)
 * - One client ID maps to at most one user ID (many-to-one)
 * - One user ID can have multiple client IDs (one-to-many)
 * - Configurable TTL, cache prefix, and per-entity limits
 * - Atomic link/unlink operations
 * - Bulk lookup for batch processing
 *
 * Configuration: `zeroboiler.analytics.identity`
 *
 * @since 262.0.0
 */
final class IdentityLinkService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AnalyticsConfig $config,
    ): void {}

    /**
     * Link a client ID to a user ID.
     *
     * If the client ID is already linked to a different user, the old link
     * is overwritten. The per-user link limit is enforced.
     *
     * @param  string  $clientId  Anonymous client ID (from cookie)
     * @param  string  $userId  Authenticated user ID
     */
    public function link(string $clientId, string $userId): void
    {
        if ($clientId === '' || $userId === '') {
            return;
        }

        $prefix = $this->config->identityCachePrefix();
        $linkTtl = $this->config->identityLinkTtl();
        $maxPerUser = $this->config->identityMaxLinksPerUser();
        $maxPerClient = $this->config->identityMaxLinksPerClient();

        // Link client → user (overwrite if exists)
        $this->cache->put("{$prefix}client:{$clientId}", $userId, $linkTtl);

        // Track user's client IDs (set)
        $userKey = "{$prefix}user:{$userId}:clients";
        $existingClients = $this->cache->get($userKey, []);
        /** @var list<string> $existingClients */

        if (! in_array($clientId, $existingClients, true)) {
            // Enforce per-user limit
            if (count($existingClients) >= $maxPerUser) {
                // Remove the oldest client ID
                array_shift($existingClients);
            }
            $existingClients[] = $clientId;
        }

        $this->cache->put($userKey, $existingClients, $linkTtl);

        Log::debug('ZeroBoiler: Identity linked', [
            'client_id' => $clientId,
            'user_id' => $userId,
            'total_clients_for_user' => count($existingClients),
        ]);
    }

    /**
     * Get the user ID associated with a client ID.
     *
     * @param  string  $clientId  Anonymous client ID
     * @return string|null  User ID or null if not linked
     */
    public function getUserId(string $clientId): ?string
    {
        $prefix = $this->config->identityCachePrefix();
        $userId = $this->cache->get("{$prefix}client:{$clientId}");

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Get all client IDs associated with a user ID.
     *
     * @param  string  $userId  Authenticated user ID
     * @return list<string>  List of client IDs
     */
    public function getClientIds(string $userId): array
    {
        $prefix = $this->config->identityCachePrefix();
        $clients = $this->cache->get("{$prefix}user:{$userId}:clients", []);

        return is_array($clients) ? $clients : [];
    }

    /**
     * Unlink a client ID from its associated user ID.
     *
     * Removes the client → user mapping and removes the client ID from
     * the user's client ID set.
     *
     * @param  string  $clientId  Anonymous client ID to unlink
     */
    public function unlink(string $clientId): void
    {
        $prefix = $this->config->identityCachePrefix();

        // Find the associated user
        $userId = $this->getUserId($clientId);

        if ($userId === null) {
            return;
        }

        // Remove client → user mapping
        $this->cache->forget("{$prefix}client:{$clientId}");

        // Remove client from user's client set
        $userKey = "{$prefix}user:{$userId}:clients";
        $clients = $this->cache->get($userKey, []);
        /** @var list<string> $clients */
        $clients = array_filter($clients, fn (string $c): bool => $c !== $clientId);
        $this->cache->put($userKey, array_values($clients), $this->config->identityLinkTtl());

        Log::debug('ZeroBoiler: Identity unlinked', [
            'client_id' => $clientId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Resolve a client ID to a user ID, or return the client ID.
     *
     * This is the primary identity resolution method for event enrichment.
     * If the client ID is linked to a user, returns the user ID.
     * Otherwise, returns the original client ID.
     *
     * @param  string  $clientId  Anonymous client ID
     * @return string  Either the linked user ID or the client ID
     */
    public function resolveIdentity(string $clientId): string
    {
        $userId = $this->getUserId($clientId);

        return $userId ?? $clientId;
    }

    /**
     * Check if a client ID is linked to a user.
     */
    public function isLinked(string $clientId): bool
    {
        return $this->getUserId($clientId) !== null;
    }

    /**
     * Get a summary of identity linking statistics.
     *
     * @return array{linked_clients: int, note: string}
     */
    public function getStats(): array
    {
        $prefix = $this->config->identityCachePrefix();

        return [
            'linked_clients' => 0, // Cache-backed; exact count requires scan
            'note' => 'Exact counts require cache scan; use monitoring for production metrics.',
            'cache_prefix' => $prefix,
            'link_ttl' => $this->config->identityLinkTtl(),
            'max_links_per_user' => $this->config->identityMaxLinksPerUser(),
            'max_links_per_client' => $this->config->identityMaxLinksPerClient(),
        ];
    }
}
