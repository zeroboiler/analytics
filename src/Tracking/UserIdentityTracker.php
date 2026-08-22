<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Links user identities with client-side tracking IDs.
 *
 * When a user logs in or registers, this tracker associates their
 * authenticated user ID with their client tracking ID (from cookie/header).
 * This enables cross-device user identification in analytics providers.
 *
 * Supports cache-backed persistent identity linking with configurable TTL,
 * max links per user/client, and automatic linking on authentication events.
 *
 * @since 1.0.0
 */
final class UserIdentityTracker
{
    private QueuedAnalyticsDispatcher $queue;

    private string $cookieName;

    /** @var \Illuminate\Contracts\Cache\Repository */
    private mixed $cache;

    private string $cachePrefix;

    private int $linkTtl;

    private int $maxLinksPerUser;

    private int $maxLinksPerClient;

    private bool $autoLink;

    /**
     * @param  \Illuminate\Contracts\Cache\Repository  $cache  Laravel cache store
     */
    public function __construct(
        QueuedAnalyticsDispatcher $queue,
        mixed $cache = null,
        string $cookieName = 'zb_analytics_id',
        string $cachePrefix = 'zb_identity_',
        int $linkTtl = 7776000,
        int $maxLinksPerUser = 50,
        int $maxLinksPerClient = 10,
        bool $autoLink = true,
    ){
        $this->queue = $queue;
        $this->cache = $cache ?? app('cache')->store();
        $this->cookieName = $cookieName;
        $this->cachePrefix = $cachePrefix;
        $this->linkTtl = $linkTtl;
        $this->maxLinksPerUser = $maxLinksPerUser;
        $this->maxLinksPerClient = $maxLinksPerClient;
        $this->autoLink = $autoLink;
    }

    /**
     * Link a client ID to a user ID with cache-backed persistence.
     *
     * Stores the bidirectional mapping in cache so future events from
     * either identity can be resolved to the other. Enforces max links
     * per user and per client to prevent unbounded growth.
     *
     * Returns true if the link was successfully stored, false if limits exceeded.
     */
    public function linkClientIdToUser(string $clientId, string $userId): bool
    {
        if ($clientId === '' || $userId === '') {
            return false;
        }

        // Check max links per user
        $userLinksKey = "{$this->cachePrefix}user:{$userId}";
        $userLinks = $this->cache->get($userLinksKey, []);
        if (! is_array($userLinks)) {
            $userLinks = [];
        }

        // Check max links per client
        $clientLinksKey = "{$this->cachePrefix}client:{$clientId}";
        $clientLinks = $this->cache->get($clientLinksKey, []);
        if (! is_array($clientLinks)) {
            $clientLinks = [];
        }

        // Enforce max links per user
        if (count($userLinks) >= $this->maxLinksPerUser && ! in_array($clientId, $userLinks, true)) {
            return false;
        }

        // Enforce max links per client
        if (count($clientLinks) >= $this->maxLinksPerClient && ! in_array($userId, $clientLinks, true)) {
            return false;
        }

        // Store bidirectional mapping
        if (! in_array($clientId, $userLinks, true)) {
            $userLinks[] = $clientId;
        }
        if (! in_array($userId, $clientLinks, true)) {
            $clientLinks[] = $userId;
        }

        $this->cache->put($userLinksKey, $userLinks, $this->linkTtl);
        $this->cache->put($clientLinksKey, $clientLinks, $this->linkTtl);

        // Also store direct lookup key
        $directKey = "{$this->cachePrefix}link:{$clientId}:{$userId}";
        $this->cache->put($directKey, true, $this->linkTtl);

        return true;
    }

    /**
     * Resolve the user ID associated with a given client ID.
     *
     * Looks up the cache-backed identity graph to find all user IDs
     * linked to the given client ID. Returns the most recently linked
     * user ID, or null if no link exists.
     *
     * @return list<string> All user IDs linked to this client ID
     */
    public function resolveIdentity(string $clientId): array
    {
        if ($clientId === '') {
            return [];
        }

        $clientLinksKey = "{$this->cachePrefix}client:{$clientId}";
        $userIds = $this->cache->get($clientLinksKey, []);

        return is_array($userIds) ? $userIds : [];
    }

    /**
     * Resolve the primary user ID for a client ID (most recent link).
     *
     * @return string|null The most recently linked user ID, or null
     */
    public function resolvePrimaryUserId(string $clientId): ?string
    {
        $userIds = $this->resolveIdentity($clientId);

        if ($userIds === []) {
            return null;
        }

        return $userIds[array_key_last($userIds)] ?? null;
    }

    /**
     * Resolve all client IDs associated with a given user ID.
     *
     * @return list<string> All client IDs linked to this user ID
     */
    public function resolveClientIds(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        $userLinksKey = "{$this->cachePrefix}user:{$userId}";
        $clientIds = $this->cache->get($userLinksKey, []);

        return is_array($clientIds) ? $clientIds : [];
    }

    /**
     * Check if auto-linking is enabled.
     *
     * When true, the tracker automatically links client IDs to user IDs
     * on login/register events without requiring explicit linkClientIdToUser() calls.
     */
    public function isAutoLinkEnabled(): bool
    {
        return $this->autoLink;
    }

    /**
     * Link a user ID with a client tracking ID.
     *
     * Sends an 'identify' event to all providers so they can associate
     * future events from this client_id with the user_id.
     * Also persists the link in cache when auto_link is enabled.
     */
    public function identify(string $userId, string $clientId): void
    {
        $event = new AnalyticsEvent(
            name: 'identify',
            params: [
                'user_id' => $userId,
                'client_id' => $clientId,
            ],
            clientId: $clientId,
            userId: $userId,
        );

        $this->queue->dispatch($event);

        // Auto-link when enabled
        if ($this->autoLink) {
            $this->linkClientIdToUser($clientId, $userId);
        }
    }

    /**
     * Track user identity on login.
     *
     * Call this from your LoginController after successful authentication,
     * or use the ServerSideTracker auto-track (which calls this automatically).
     */
    public function onLogin(Authenticatable $user, Request $request): void
    {
        $authId = $user->getAuthIdentifier();
        $userId = is_int($authId) || is_string($authId) ? (string) $authId : '';
        $clientId = $this->extractClientId($request);

        if ($clientId === null) {
            Log::debug('UserIdentityTracker: no client ID found on login', [
                'user_id' => $userId,
            ]);

            return;
        }

        $this->identify($userId, $clientId);
    }

    /**
     * Track user identity on registration.
     *
     * Call this from your RegisterController after successful registration,
     * or use the ServerSideTracker auto-track.
     */
    public function onRegister(Authenticatable $user, Request $request): void
    {
        $authId = $user->getAuthIdentifier();
        $userId = is_int($authId) || is_string($authId) ? (string) $authId : '';
        $clientId = $this->extractClientId($request);

        if ($clientId === null) {
            Log::debug('UserIdentityTracker: no client ID found on register', [
                'user_id' => $userId,
            ]);

            return;
        }

        $this->identify($userId, $clientId);

        // Also set the user_id on the GA4 tracker for all future events
        $this->setUserOnTrackers($userId);
    }

    /**
     * Clear user identity on logout.
     */
    public function onLogout(Authenticatable $user, Request $request): void
    {
        $authId = $user->getAuthIdentifier();
        $userId = is_int($authId) || is_string($authId) ? (string) $authId : '';

        $event = new AnalyticsEvent(
            name: 'logout',
            params: [
                'user_id' => $userId,
            ],
            clientId: $this->extractClientId($request),
        );

        $this->queue->dispatch($event);
    }

    /**
     * Extract the client ID from the request header or cookie.
     */
    private function extractClientId(Request $request): ?string
    {
        // Check X-Analytics-Client-Id header first
        $header = $request->header('X-Analytics-Client-Id');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Fall back to cookie
        $cookie = $request->cookie($this->cookieName);

        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    /**
     * Set user_id on all tracker instances for future events in this request.
     */
    private function setUserOnTrackers(string $userId): void
    {
        // The GA4 tracker already reads user_id from the event DTO,
        // so we don't need to set it on the tracker itself.
        // This method is a hook for any future tracker-level user association.
    }
}
