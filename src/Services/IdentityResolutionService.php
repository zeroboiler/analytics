<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Persistent identity resolution service.
 *
 * Manages the bidirectional mapping between anonymous client IDs and
 * authenticated user IDs. Stores link history in cache for cross-session
 * identity resolution. GDPR-compliant: supports erasure of all links
 * for a given user.
 *
 * Typical flow:
 *   1. Anonymous user browses (client_id in cookie)
 *   2. User signs up / logs in
 *   3. resolve() is called → persists client_id ↔ user_id link
 *   4. Future events from either identity can be correlated
 *   5. On GDPR erasure request → forgetUser() removes all links
 *
 * @see \ZeroBoiler\Analytics\Tracking\UserIdentityTracker
 *
 * @since 1.0.0
 */
final class IdentityResolutionService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $linkTtl;

    private int $maxLinksPerUser;

    private int $maxLinksPerClient;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $identityConfig = $config->get('zeroboiler.analytics.identity', []);
        /** @var array{cache_prefix?: string, link_ttl?: int, max_links_per_user?: int, max_links_per_client?: int} $identityConfig */

        $this->cachePrefix = (string) ($identityConfig['cache_prefix'] ?? 'zb_identity_');
        $this->linkTtl = (int) ($identityConfig['link_ttl'] ?? 7776000); // 90 days
        $this->maxLinksPerUser = (int) ($identityConfig['max_links_per_user'] ?? 50);
        $this->maxLinksPerClient = (int) ($identityConfig['max_links_per_client'] ?? 10);
    }

    /**
     * Resolve (link) a client ID with a user ID.
     *
     * Stores the bidirectional mapping in cache. If the client was already
     * linked to a different user (e.g. shared device), the old link is
     * preserved for historical correlation but new events will use the
     * current user ID.
     *
     * @param  string  $clientId  Anonymous client tracking ID (UUID)
     * @param  string  $userId  Authenticated user ID
     * @return array{linked: bool, previous_user_id: string|null, total_client_links: int, total_user_links: int}
     */
    public function resolve(string $clientId, string $userId): array
    {
        $previousUserId = $this->getUserIdForClient($clientId);

        // Store client → user mapping
        $this->cache->put(
            $this->clientKey($clientId),
            $userId,
            $this->linkTtl,
        );

        // Store user → client mapping (append to set)
        $clientLinks = $this->getUserClientLinks($userId);

        if (! in_array($clientId, $clientLinks, true)) {
            // Enforce max links per user
            if (count($clientLinks) >= $this->maxLinksPerUser) {
                // Remove oldest entry (FIFO)
                array_shift($clientLinks);
            }

            $clientLinks[] = $clientId;
            $this->cache->put(
                $this->userKey($userId),
                $clientLinks,
                $this->linkTtl,
            );
        }

        // Store client → user history (append to set for multi-user devices)
        $userLinks = $this->getClientUserLinks($clientId);

        if (! in_array($userId, $userLinks, true)) {
            if (count($userLinks) >= $this->maxLinksPerClient) {
                array_shift($userLinks);
            }

            $userLinks[] = $userId;
            $this->cache->put(
                $this->clientHistoryKey($clientId),
                $userLinks,
                $this->linkTtl,
            );
        }

        return [
            'linked' => true,
            'previous_user_id' => $previousUserId,
            'total_client_links' => count($this->getUserClientLinks($userId)),
            'total_user_links' => count($this->getClientUserLinks($clientId)),
        ];
    }

    /**
     * Get the user ID associated with a client ID.
     *
     * @param  string  $clientId
     * @return string|null User ID or null if no link exists
     */
    public function getUserIdForClient(string $clientId): ?string
    {
        $userId = $this->cache->get($this->clientKey($clientId));

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Get all client IDs associated with a user ID.
     *
     * @param  string  $userId
     * @return list<string> Client IDs linked to this user (most recent last)
     */
    public function getClientIdsForUser(string $userId): array
    {
        return $this->getUserClientLinks($userId);
    }

    /**
     * Check if a client ID is linked to any user.
     */
    public function isClientLinked(string $clientId): bool
    {
        return $this->getUserIdForClient($clientId) !== null;
    }

    /**
     * Check if a user has any linked client IDs.
     */
    public function isUserLinked(string $userId): bool
    {
        return count($this->getUserClientLinks($userId)) > 0;
    }

    /**
     * Get the primary client ID for a user (most recently linked).
     *
     * @param  string  $userId
     * @return string|null
     */
    public function getPrimaryClientIdForUser(string $userId): ?string
    {
        $links = $this->getUserClientLinks($userId);

        return empty($links) ? null : $links[array_key_last($links)];
    }

    /**
     * Forget (unlink) a specific client ID.
     *
     * Removes the client → user mapping and the reverse user → client entry.
     *
     * @param  string  $clientId
     * @return bool True if a link was removed
     */
    public function forgetClient(string $clientId): bool
    {
        $userId = $this->getUserIdForClient($clientId);

        if ($userId === null) {
            return false;
        }

        // Remove client → user mapping
        $this->cache->forget($this->clientKey($clientId));

        // Remove client from user's link list
        $this->removeClientFromUser($clientId, $userId);

        // Remove client history
        $this->cache->forget($this->clientHistoryKey($clientId));

        return true;
    }

    /**
     * Forget (unlink) all client IDs for a user (GDPR erasure).
     *
     * Removes all identity links associated with the given user ID.
     * Call this from GdprErasureService when processing data subject requests.
     *
     * @param  string  $userId
     * @return int Number of links removed
     */
    public function forgetUser(string $userId): int
    {
        $clientIds = $this->getUserClientLinks($userId);

        foreach ($clientIds as $clientId) {
            // Remove client → user mapping for each linked client
            $this->cache->forget($this->clientKey($clientId));

            // Remove client history entries that reference this user
            $this->removeUserFromClientHistory($clientId, $userId);
        }

        // Remove user → clients mapping
        $this->cache->forget($this->userKey($userId));

        return count($clientIds);
    }

    /**
     * Get identity resolution statistics.
     *
     * @return array{total_resolved_users: int, total_linked_clients: int}
     */
    public function stats(): array
    {
        // These are approximate counts since we can't enumerate all cache keys
        // In production, use a dedicated counter or database for accurate stats
        return [
            'total_resolved_users' => 0,
            'total_linked_clients' => 0,
        ];
    }

    /**
     * Get a summary of identity links for a specific user.
     *
     * @param  string  $userId
     * @return array{user_id: string, linked_clients: int, primary_client_id: string|null, first_linked: string|null}
     */
    public function identitySummary(string $userId): array
    {
        $clientIds = $this->getUserClientLinks($userId);

        return [
            'user_id' => $userId,
            'linked_clients' => count($clientIds),
            'primary_client_id' => empty($clientIds) ? null : $clientIds[array_key_last($clientIds)],
            'first_linked' => empty($clientIds) ? null : $clientIds[0],
        ];
    }

    /**
     * Generate the cache key for client → user mapping.
     */
    private function clientKey(string $clientId): string
    {
        return $this->cachePrefix . 'client_' . $clientId;
    }

    /**
     * Generate the cache key for user → client links array.
     */
    private function userKey(string $userId): string
    {
        return $this->cachePrefix . 'user_' . $userId;
    }

    /**
     * Generate the cache key for client → user history array.
     */
    private function clientHistoryKey(string $clientId): string
    {
        return $this->cachePrefix . 'client_history_' . $clientId;
    }

    /**
     * Get client link list for a user from cache.
     *
     * @return list<string>
     */
    private function getUserClientLinks(string $userId): array
    {
        $links = $this->cache->get($this->userKey($userId));

        if (is_array($links)) {
            return array_values(array_filter(
                $links,
                fn (mixed $v): bool => is_string($v) && $v !== '',
            ));
        }

        return [];
    }

    /**
     * Get user link history for a client from cache.
     *
     * @return list<string>
     */
    private function getClientUserLinks(string $clientId): array
    {
        $links = $this->cache->get($this->clientHistoryKey($clientId));

        if (is_array($links)) {
            return array_values(array_filter(
                $links,
                fn (mixed $v): bool => is_string($v) && $v !== '',
            ));
        }

        return [];
    }

    /**
     * Remove a client ID from a user's link list.
     */
    private function removeClientFromUser(string $clientId, string $userId): void
    {
        $links = $this->getUserClientLinks($userId);
        $filtered = array_values(array_filter(
            $links,
            fn (string $id): bool => $id !== $clientId,
        ));

        if (! empty($filtered)) {
            $this->cache->put($this->userKey($userId), $filtered, $this->linkTtl);
        } else {
            $this->cache->forget($this->userKey($userId));
        }
    }

    /**
     * Remove a user ID from a client's history list.
     */
    private function removeUserFromClientHistory(string $clientId, string $userId): void
    {
        $history = $this->getClientUserLinks($clientId);
        $filtered = array_values(array_filter(
            $history,
            fn (string $id): bool => $id !== $userId,
        ));

        if (! empty($filtered)) {
            $this->cache->put($this->clientHistoryKey($clientId), $filtered, $this->linkTtl);
        } else {
            $this->cache->forget($this->clientHistoryKey($clientId));
        }
    }
}
