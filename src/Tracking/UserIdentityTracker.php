<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
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
 */
class UserIdentityTracker
{
    private QueuedAnalyticsDispatcher $queue;

    private string $cookieName;

    public function __construct(
        QueuedAnalyticsDispatcher $queue,
        string $cookieName = 'zb_analytics_id',
    ) {
        $this->queue = $queue;
        $this->cookieName = $cookieName;
    }

    /**
     * Link a user ID with a client tracking ID.
     *
     * Sends an 'identify' event to all providers so they can associate
     * future events from this client_id with the user_id.
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
