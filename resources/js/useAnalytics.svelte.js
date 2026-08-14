/**
 * ZeroBoiler Analytics — Svelte Composable: useAnalytics
 *
 * Main reactive analytics composable for Svelte/Inertia applications.
 * Provides a unified API for event tracking, consent management,
 * identity linking, and lifecycle-aware analytics in Svelte components.
 *
 * @package ZeroBoiler Analytics
 * @version 137.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import {
    init,
    destroy,
    isInitialized,
    getTrackingId,
    getVersion,
    trackEvent,
    trackPageView,
    trackEcommerce,
    trackEcommerceWithProviders,
    trackEventWithProviders,
    identify,
    updateConsent,
    getConsentState,
    flushQueue,
    trackDebounced,
    trackThrottled,
    trackScreenView,
} from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/** @type {import('svelte/store').Writable<boolean>} */
export const ready = writable(false);

/** @type {import('svelte/store').Writable<string|null>} */
export const trackingId = writable(null);

/** @type {import('svelte/store').Writable<string|null>} */
export const userId = writable(null);

/** @type {import('svelte/store').Writable<boolean>} */
export const authStateChanged = writable(false);

/**
 * Internal state for tracking whether we've initialized on this page.
 * Prevents double-initialization on Svelte re-renders.
 * @type {boolean}
 */
let hasInitialized = false;

/**
 * Inertia page change cleanup reference.
 * @type {Function|null}
 */
let pageDestroyFn = null;

// ─── Composable ─────────────────────────────────────────────────────

/**
 * Main analytics composable for Svelte components.
 *
 * Provides reactive stores for analytics state and methods for
 * tracking events, managing consent, and linking user identity.
 *
 * Automatically initializes on mount and handles Inertia page
 * navigation with page view tracking and auth state detection.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoInit=true] - Automatically initialize on mount
 * @param {boolean} [options.trackPageViews=true] - Track page views on navigation
 * @param {boolean} [options.autoIdentify=true] - Auto-identify on auth state change
 * @param {boolean} [options.autoFlush=true] - Flush event queue on page navigation
 * @param {boolean} [options.lifecycleAware=true] - Track lifecycle funnel events
 * @returns {{
 *   ready: import('svelte/store').Writable<boolean>,
 *   trackingId: import('svelte/store').Writable<string|null>,
 *   userId: import('svelte/store').Writable<string|null>,
 *   authStateChanged: import('svelte/store').Writable<boolean>,
 *   init: () => void,
 *   destroy: () => void,
 *   track: (name: string, params?: object) => Promise<void>,
 *   trackProviders: (name: string, params?: object, providers?: string[]) => Promise<void>,
 *   trackEcommerce: (name: string, data?: object) => Promise<void>,
 *   trackEcommerceProviders: (name: string, data?: object, providers?: string[]) => Promise<void>,
 *   trackPageView: (title?: string, location?: string, referrer?: string) => Promise<void>,
 *   trackScreenView: (screenName: string, options?: object) => Promise<void>,
 *   trackDebounced: (name: string, params?: object, options?: object) => void,
 *   trackThrottled: (name: string, params?: object, options?: object) => void,
 *   identify: (userId?: string|null) => Promise<void>,
 *   updateConsent: (signals: object) => Promise<void>,
 *   consent: () => object|null,
 *   flush: () => Promise<void>,
 *   version: () => string,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useAnalytics } from '@zeroboiler/analytics';
 *
 * const {
 *     ready,
 *     trackingId,
 *     userId,
 *     track,
 *     trackEcommerce,
 *     identify,
 *     updateConsent,
 * } = useAnalytics();
 * </script>
 *
 * {#if $ready}
 *   <p>Tracking: {$trackingId}</p>
 *   <p>User: {$userId ?? 'anonymous'}</p>
 *   <button on:click={() => track('button_clicked', { label: 'CTA' })}>
 *     Click Me
 *   </button>
 * {/if}
 * ```
 */
export function useAnalytics(options = {}) {
    const autoInit = options.autoInit !== false;
    const trackPageViews = options.trackPageViews !== false;
    const autoIdentify = options.autoIdentify !== false;
    const autoFlush = options.autoFlush !== false;
    const lifecycleAware = options.lifecycleAware !== false;

    /**
     * Initialize the analytics client.
     *
     * Reads configuration from Inertia page props and sets up
     * reactive stores. Safe to call multiple times (no-op after first).
     */
    function setup() {
        if (hasInitialized) return;

        const analytics = page.props?.zbAnalytics;
        if (!analytics?.enabled) {
            ready.set(false);
            return;
        }

        // Initialize the core analytics client
        init(page.props);

        // Set reactive stores
        trackingId.set(analytics.trackingId ?? null);
        userId.set(analytics.userId ?? null);
        authStateChanged.set(analytics.authStateChanged ?? false);
        ready.set(true);
        hasInitialized = true;

        // Auto-identify on auth state change
        if (autoIdentify && analytics.authStateChanged && analytics.userId) {
            doIdentify(analytics.userId);
        }

        // Track initial page view
        if (trackPageViews) {
            doTrackPageView();
        }

        // Watch for Inertia page navigation
        setupPageWatcher(trackPageViews, autoFlush, lifecycleAware, autoIdentify);
    }

    /**
     * Set up Inertia page navigation watcher.
     *
     * Tracks page views, flushes the event queue, detects auth state
     * changes, and updates lifecycle signals on every navigation.
     */
    function setupPageWatcher(shouldTrackPV, shouldFlush, isLifecycleAware, shouldAutoIdentify) {
        if (pageDestroyFn) return;

        const unsubscribe = page.subscribe(($page) => {
            const analytics = $page.props?.zbAnalytics;
            if (!analytics) return;

            // Update reactive stores
            trackingId.set(analytics.trackingId ?? null);
            const newUserId = analytics.userId ?? null;
            const prevUserId = analytics.previousUserId ?? null;

            // Detect auth state change
            const authChanged = analytics.authStateChanged ?? false;
            authStateChanged.set(authChanged);

            // Auto-identify on login
            if (shouldAutoIdentify && authChanged && newUserId && newUserId !== prevUserId) {
                doIdentify(newUserId);
            }

            userId.set(newUserId);

            // Track page view on navigation (skip initial — handled in setup)
            if (shouldTrackPV && hasInitialized) {
                doTrackPageView($page.component);
            }

            // Flush queued events
            if (shouldFlush) {
                flushQueue();
            }
        });

        pageDestroyFn = unsubscribe;
    }

    /**
     * Track a page view event with title and URL from the current page.
     */
    function doTrackPageView(component) {
        try {
            const title = typeof document !== 'undefined' ? document.title : '';
            const location = typeof window !== 'undefined' ? window.location.href : '';
            const referrer = typeof document !== 'undefined' ? document.referrer : '';

            trackPageView(title, location, referrer);
        } catch {
            // Silent — page view tracking should never break the app
        }
    }

    /**
     * Perform an identify call to link client_id ↔ user_id.
     */
    async function doIdentify(uid) {
        try {
            await identify(uid);
        } catch {
            // Silent
        }
    }

    /**
     * Cleanup analytics listeners and stores.
     */
    function teardown() {
        if (pageDestroyFn) {
            pageDestroyFn();
            pageDestroyFn = null;
        }
        destroy();
        ready.set(false);
        trackingId.set(null);
        userId.set(null);
        authStateChanged.set(false);
        hasInitialized = false;
    }

    // Auto-initialize on composable creation
    if (autoInit) {
        // Use tick-like delay to ensure page props are resolved
        if (typeof window !== 'undefined') {
            setup();
        }
    }

    return {
        // Reactive stores
        ready,
        trackingId,
        userId,
        authStateChanged,

        // Lifecycle methods
        init: setup,
        destroy: teardown,

        // Event tracking
        track: (name, params) => {
            return trackEvent(name, params);
        },
        trackProviders: (name, params, providers) => {
            return trackEventWithProviders(name, params, providers);
        },
        trackEcommerce: (name, data) => {
            return trackEcommerce(name, data);
        },
        trackEcommerceProviders: (name, data, providers) => {
            return trackEcommerceWithProviders(name, data, providers);
        },
        trackPageView: (title, location, referrer) => {
            return trackPageView(title, location, referrer);
        },
        trackScreenView: (screenName, opts) => {
            return trackScreenView(screenName, opts);
        },
        trackDebounced: (name, params, opts) => {
            return trackDebounced(name, params, opts);
        },
        trackThrottled: (name, params, opts) => {
            return trackThrottled(name, params, opts);
        },

        // Identity & Consent
        identify: (uid) => doIdentify(uid),
        updateConsent: (signals) => updateConsent(signals),
        consent: () => getConsentState(),

        // Queue
        flush: () => flushQueue(),

        // Meta
        version: () => getVersion(),
    };
}

/**
 * Convenience shorthand: create an analytics composable with default options.
 *
 * @example
 * ```svelte
 * <script>
 * import { analytics } from '@zeroboiler/analytics';
 * // Use $analytics.ready, $analytics.trackingId, etc.
 * </script>
 * ```
 */
export function analyticsStore(options = {}) {
    return useAnalytics(options);
}

export default useAnalytics;
