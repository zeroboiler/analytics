/**
 * ZeroBoiler Analytics — Svelte Composable: useIdentity
 *
 * Reactive identity management composable for Svelte/Inertia applications.
 * Manages the client-server identity link (client_id ↔ user_id) with
 * automatic detection of auth state changes, cookie-based client ID
 * persistence, and server synchronization.
 *
 * Reads identity data from Inertia page props (zbAnalytics.trackingId,
 * zbAnalytics.userId, zbAnalytics.authStateChanged) and provides reactive
 * stores for real-time identity state in Svelte components.
 *
 * @package ZeroBoiler Analytics
 * @version 193.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import { identify, getClientId, isInitialized, trackEvent } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Server-generated client tracking ID (from cookie).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const clientId = writable(null);

/**
 * Authenticated user ID (null when anonymous).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const userId = writable(null);

/**
 * Whether an auth state change was just detected (login/logout).
 * @type {import('svelte/store').Writable<boolean>}
 */
export const authStateChanged = writable(false);

/**
 * Previous user ID before auth state change (for tracking login/logout).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const previousUserId = writable(null);

/**
 * Whether identity is currently syncing with the server.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const syncing = writable(false);

/**
 * Timestamp of last identity sync (ISO string or null).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const lastSynced = writable(null);

/**
 * Number of identity links established (client_id ↔ user_id).
 * @type {import('svelte/store').Writable<number>}
 */
export const linkCount = writable(0);

// ─── Derived Stores ───────────────────────────────────────────────

/**
 * Whether the user is currently authenticated.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const isAuthenticated = derived(userId, (uid) => uid !== null && uid !== '');

/**
 * Whether the user just logged in (authStateChanged && userId).
 * @type {import('svelte/store').Readable<boolean>}
 */
export const justLoggedIn = derived(
    [authStateChanged, userId],
    ([$authChanged, $uid]) => $authChanged && $uid !== null,
);

/**
 * Whether the user just logged out (authStateChanged && !userId).
 * @type {import('svelte/store').Readable<boolean>}
 */
export const justLoggedOut = derived(
    [authStateChanged, userId, previousUserId],
    ([$authChanged, $uid, $prevUid]) => $authChanged && $uid === null && $prevUid !== null,
);

/**
 * Full identity snapshot for debugging/logging.
 * @type {import('svelte/store').Readable<object>}
 */
export const identitySnapshot = derived(
    [clientId, userId, authStateChanged, previousUserId, lastSynced, linkCount],
    ([$clientId, $userId, $authChanged, $prevUid, $lastSynced, $linkCount]) => ({
        clientId: $clientId,
        userId: $userId,
        authenticated: $userId !== null,
        authStateChanged: $authChanged,
        previousUserId: $prevUid,
        lastSynced: $lastSynced,
        linkCount: $linkCount,
    }),
);

// ─── Internal State ───────────────────────────────────────────────

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

/** @type {boolean} Whether initialized */
let initialized = false;

// ─── Composable ────────────────────────────────────────────────────

/**
 * Identity management composable for Svelte/Inertia applications.
 *
 * Provides reactive stores for client ID, user ID, and auth state changes.
 * Automatically detects Inertia page navigation for auth state transitions
 * and syncs identity links with the server.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoSync=true] - Automatically sync identity on auth change
 * @param {boolean} [options.trackAuthEvents=true] - Track login/logout analytics events
 * @param {string} [options.cookieName='zb_analytics_id'] - Client ID cookie name
 * @param {Function} [options.onLogin] - Callback on login: (userId, clientId) => void
 * @param {Function} [options.onLogout] - Callback on logout: (previousUserId, clientId) => void
 * @param {Function} [options.onIdentityLinked] - Callback on identity link: (userId, clientId) => void
 * @returns {{
 *   clientId: import('svelte/store').Writable<string|null>,
 *   userId: import('svelte/store').Writable<string|null>,
 *   authStateChanged: import('svelte/store').Writable<boolean>,
 *   previousUserId: import('svelte/store').Writable<string|null>,
 *   syncing: import('svelte/store').Writable<boolean>,
 *   lastSynced: import('svelte/store').Writable<string|null>,
 *   linkCount: import('svelte/store').Writable<number>,
 *   isAuthenticated: import('svelte/store').Readable<boolean>,
 *   justLoggedIn: import('svelte/store').Readable<boolean>,
 *   justLoggedOut: import('svelte/store').Readable<boolean>,
 *   identitySnapshot: import('svelte/store').Readable<object>,
 *   linkIdentity: (uid?: string) => Promise<void>,
 *   unlinkIdentity: () => void,
 *   getClientIdFromCookie: () => string|null,
 *   reset: () => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useIdentity } from '@zeroboiler/analytics/svelte';
 *
 * const {
 *     clientId, userId, isAuthenticated,
 *     justLoggedIn, justLoggedOut,
 *     linkIdentity, identitySnapshot,
 * } = useIdentity({
 *     onLogin: (uid, cid) => console.log(`User ${uid} linked to ${cid}`),
 *     onLogout: (prevUid) => console.log(`User ${prevUid} logged out`),
 * });
 * </script>
 *
 * {#if $isAuthenticated}
 *   <p>Logged in as user {$userId}</p>
 *   <p>Client ID: {$clientId}</p>
 * {:else}
 *   <p>Anonymous — Client ID: {$clientId}</p>
 * {/if}
 *
 * {#if $justLoggedIn}
 *   <p>Welcome back!</p>
 * {/if}
 * ```
 */
export function useIdentity(options = {}) {
    const autoSync = options.autoSync !== false;
    const trackAuthEvents = options.trackAuthEvents !== false;
    const cookieName = options.cookieName || 'zb_analytics_id';
    const onLogin = options.onLogin || null;
    const onLogout = options.onLogout || null;
    const onIdentityLinked = options.onIdentityLinked || null;

    /**
     * Initialize identity state from Inertia page props.
     */
    function initialize() {
        if (initialized) return;

        const analytics = page.props?.zbAnalytics;
        if (!analytics) return;

        clientId.set(analytics.trackingId || readClientIdFromCookie() || null);
        userId.set(analytics.userId || null);
        authStateChanged.set(analytics.authStateChanged || false);
        previousUserId.set(analytics.previousUserId || null);

        initialized = true;

        // Watch for page navigation (auth state changes)
        setupPageWatcher();

        // Handle immediate auth state change on load
        if (analytics.authStateChanged && analytics.userId) {
            handleLogin(analytics.userId);
        }
    }

    /**
     * Watch Inertia page navigation for auth state changes.
     */
    function setupPageWatcher() {
        if (pageUnsubscribe) return;

        pageUnsubscribe = page.subscribe(($page) => {
            const analytics = $page.props?.zbAnalytics;
            if (!analytics) return;

            const newClientId = analytics.trackingId || null;
            const newUserId = analytics.userId || null;
            const newPrevUserId = analytics.previousUserId || null;
            const authChanged = analytics.authStateChanged || false;

            // Detect auth state transition
            if (authChanged) {
                const oldUserId = /* capture before update */
                    (function() {
                        let v = null;
                        userId.subscribe(u => v = u)();
                        return v;
                    })();

                previousUserId.set(oldUserId);
                userId.set(newUserId);
                authStateChanged.set(true);

                if (newUserId && newUserId !== oldUserId) {
                    handleLogin(newUserId);
                } else if (!newUserId && oldUserId) {
                    handleLogout(oldUserId);
                }
            } else {
                // Normal navigation — just update stores
                clientId.set(newClientId);
                userId.set(newUserId);
                authStateChanged.set(false);
            }
        });
    }

    /**
     * Handle user login — link identity and fire analytics event.
     */
    async function handleLogin(uid) {
        const cid = /* capture */ (function() {
            let v = null;
            clientId.subscribe(c => v = c)();
            return v;
        })();

        // Fire analytics event
        if (trackAuthEvents && isInitialized()) {
            try {
                trackEvent('login', {
                    user_id: uid,
                    client_id: cid,
                    method: 'auto_detected',
                });
            } catch { /* silent */ }
        }

        // Auto-sync identity link
        if (autoSync) {
            await doLinkIdentity(uid);
        }

        // Callback
        if (onLogin) {
            try { onLogin(uid, cid); } catch { /* silent */ }
        }
    }

    /**
     * Handle user logout — fire analytics event.
     */
    function handleLogout(prevUid) {
        const cid = /* capture */ (function() {
            let v = null;
            clientId.subscribe(c => v = c)();
            return v;
        })();

        if (trackAuthEvents && isInitialized()) {
            try {
                trackEvent('logout', {
                    previous_user_id: prevUid,
                    client_id: cid,
                    method: 'auto_detected',
                });
            } catch { /* silent */ }
        }

        if (onLogout) {
            try { onLogout(prevUid, cid); } catch { /* silent */ }
        }
    }

    /**
     * Link client_id ↔ user_id via server API.
     */
    async function doLinkIdentity(uid) {
        if (!isInitialized()) return;

        syncing.set(true);
        try {
            await identify(uid);
            lastSynced.set(new Date().toISOString());
            linkCount.update(n => n + 1);

            if (onIdentityLinked) {
                const cid = getClientId();
                try { onIdentityLinked(uid, cid); } catch { /* silent */ }
            }
        } catch {
            // Silent
        } finally {
            syncing.set(false);
        }
    }

    /**
     * Manually link a user identity.
     *
     * @param {string} [uid] - User ID to link (reads from store if omitted)
     */
    async function linkIdentity(uid) {
        const targetUid = uid || (function() {
            let v = null;
            userId.subscribe(u => v = u)();
            return v;
        })();

        if (!targetUid) return;
        await doLinkIdentity(targetUid);
    }

    /**
     * Unlink identity (client-side reset).
     */
    function unlinkIdentity() {
        userId.set(null);
        previousUserId.set(null);
        authStateChanged.set(false);
    }

    /**
     * Read client ID from cookie.
     */
    function readClientIdFromCookie() {
        if (typeof document === 'undefined') return null;

        const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${cookieName}=([^;]*)`));
        return match ? decodeURIComponent(match[1]) : null;
    }

    /**
     * Reset all identity state.
     */
    function reset() {
        clientId.set(null);
        userId.set(null);
        authStateChanged.set(false);
        previousUserId.set(null);
        syncing.set(false);
        lastSynced.set(null);
        linkCount.set(0);
    }

    // ─── Initialize ─────────────────────────────────────────────

    if (typeof window !== 'undefined') {
        initialize();
    }

    return {
        clientId,
        userId,
        authStateChanged,
        previousUserId,
        syncing,
        lastSynced,
        linkCount,

        // Derived
        isAuthenticated,
        justLoggedIn,
        justLoggedOut,
        identitySnapshot,

        // Methods
        linkIdentity,
        unlinkIdentity,
        getClientIdFromCookie: readClientIdFromCookie,
        reset,
    };
}

export default useIdentity;
