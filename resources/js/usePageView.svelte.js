/**
 * ZeroBoiler Analytics — Svelte Composable: usePageView
 *
 * Automatic page view tracking composable for Svelte/Inertia applications.
 * Watches Inertia page navigation and fires page_view analytics events
 * with title, URL, referrer, and route metadata. Supports virtual page views
 * for SPAs with hash-based routing and provides engagement metrics.
 *
 * @package ZeroBoiler Analytics
 * @version 198.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { page } from '@inertiajs/svelte';
import { trackEvent, trackPageView, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Current page view data (title, url, referrer, route).
 * @type {import('svelte/store').Writable<{title: string, url: string, referrer: string, route: string|null, component: string|null}>}
 */
export const currentPage = writable({
    title: '',
    url: '',
    referrer: '',
    route: null,
    component: null,
});

/**
 * Previous page view data.
 * @type {import('svelte/store').Writable<{title: string, url: string, referrer: string, route: string|null, component: string|null}|null>}
 */
export const previousPage = writable(null);

/**
 * Total page views tracked in current session.
 * @type {import('svelte/store').Writable<number>}
 */
export const pageViewCount = writable(0);

/**
 * Whether page view tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

/**
 * Session start timestamp.
 * @type {import('svelte/store').Writable<number|null>}
 */
export const sessionStart = writable(null);

/**
 * Average time between page views (ms).
 * @type {import('svelte/store').Writable<number>}
 */
export const avgTimeBetweenViews = writable(0);

// ─── Internal State ────────────────────────────────────────────────

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

/** @type {number|null} Last page view timestamp */
let lastPageViewTime = null;

/** @type {number[]} Page view time intervals for averaging */
const timeIntervals = [];

/** @type {boolean} First page tracked */
let firstPageTracked = false;

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

// ─── Composable ───────────────────────────────────────────────────

/**
 * Automatic page view tracking composable.
 *
 * Watches Inertia page navigation and fires `page_view` analytics events
 * with page metadata. Supports debouncing, virtual page views, and
 * engagement metrics calculation.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoTrack=true] - Automatically track page views
 * @param {boolean} [options.trackInitial=true] - Track the initial page view
 * @param {number} [options.debounceMs=300] - Debounce rapid navigations
 * @param {boolean} [options.includeReferrer=true] - Include document referrer
 * @param {boolean} [options.trackEngagement=false] - Track engagement metrics (time_on_page)
 * @param {string} [options.eventName='page_view'] - Analytics event name
 * @param {Function} [options.onPageView] - Callback on page view: (pageData) => void
 * @param {Function} [options.shouldTrack] - Filter: (pageData) => boolean
 * @returns {{
 *   currentPage: import('svelte/store').Writable<object>,
 *   previousPage: import('svelte/store').Writable<object|null>,
 *   pageViewCount: import('svelte/store').Writable<number>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   sessionStart: import('svelte/store').Writable<number|null>,
 *   avgTimeBetweenViews: import('svelte/store').Writable<number>,
 *   trackVirtualPageView: (url: string, title?: string) => void,
 *   startTracking: () => void,
 *   stopTracking: () => void,
 *   reset: () => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { usePageView } from '@zeroboiler/analytics/svelte';
 *
 * const {
 *     currentPage, pageViewCount, avgTimeBetweenViews,
 *     trackVirtualPageView,
 * } = usePageView({
 *     trackEngagement: true,
 *     shouldTrack: (page) => !page.url.includes('/admin'),
 * });
 * </script>
 *
 * <p>Current page: {$currentPage.title}</p>
 * <p>Route: {$currentPage.route}</p>
 * <p>Page views: {$pageViewCount}</p>
 * <p>Avg time between views: {$avgTimeBetweenViews}ms</p>
 *
 * <button on:click={() => trackVirtualPageView('/virtual/modal', 'Settings Modal')}>
 *   Track virtual page view
 * </button>
 * ```
 */
export function usePageView(options = {}) {
    const autoTrack = options.autoTrack !== false;
    const trackInitial = options.trackInitial !== false;
    const debounceMs = options.debounceMs || 300;
    const includeReferrer = options.includeReferrer !== false;
    const trackEngagement = options.trackEngagement || false;
    const eventName = options.eventName || 'page_view';
    const onPageView = options.onPageView || null;
    const shouldTrack = options.shouldTrack || null;

    /** @type {number|null} Debounce timer */
    let debounceTimer = null;

    /**
     * Extract page data from current Inertia page.
     */
    function getPageData($page) {
        const title = typeof document !== 'undefined' ? document.title : '';
        const url = typeof window !== 'undefined' ? window.location.href : '';
        const referrer = includeReferrer && typeof document !== 'undefined' ? document.referrer : '';

        return {
            title,
            url,
            referrer,
            route: $page.url?.toString() || null,
            component: $page.component || null,
        };
    }

    /**
     * Track a page view event.
     */
    function doTrackPageView(pageData) {
        if (!isInitialized()) return;

        // Filter check
        if (shouldTrack && !shouldTrack(pageData)) return;

        // Calculate time between views
        const now = Date.now();
        if (lastPageViewTime) {
            const interval = now - lastPageViewTime;
            timeIntervals.push(interval);

            // Keep only last 20 intervals for averaging
            if (timeIntervals.length > 20) {
                timeIntervals.shift();
            }

            const avg = timeIntervals.reduce((sum, t) => sum + t, 0) / timeIntervals.length;
            avgTimeBetweenViews.set(Math.round(avg));
        }

        lastPageViewTime = now;

        // Update stores
        previousPage.update(prev => {
            const current = {};
            currentPage.subscribe(c => Object.assign(current, c))();
            return current;
        });

        currentPage.set(pageData);
        pageViewCount.update(n => n + 1);

        if (!firstPageTracked) {
            sessionStart.set(now);
            firstPageTracked = true;
        }

        // Fire analytics event via core library (handles batching + consent)
        try {
            trackPageView(pageData.title, pageData.url, pageData.referrer);
        } catch {
            // Fallback: direct API call
            try {
                trackEvent(eventName, {
                    page_title: pageData.title,
                    page_location: pageData.url,
                    page_referrer: pageData.referrer,
                    page_route: pageData.route,
                    client_id: getClientId(),
                    session_page_count: pageViewCount,
                });
            } catch {
                // Silent — page view tracking should never break the app
            }
        }

        // Callback
        if (onPageView) {
            try { onPageView(pageData); } catch { /* silent */ }
        }
    }

    /**
     * Debounced page navigation handler.
     */
    function handlePageNavigation($page) {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            const pageData = getPageData($page);
            doTrackPageView(pageData);
        }, debounceMs);
    }

    /**
     * Track a virtual page view (for modals, overlays, hash routes).
     *
     * @param {string} url - Virtual page URL
     * @param {string} [title] - Virtual page title
     */
    function trackVirtualPageView(url, title) {
        const referrer = typeof window !== 'undefined' ? window.location.href : '';

        doTrackPageView({
            title: title || url,
            url,
            referrer,
            route: url,
            component: null,
        });
    }

    /**
     * Start page view tracking.
     */
    function startTracking() {
        if (typeof window === 'undefined') return;

        tracking.set(true);

        // Track initial page if configured
        if (trackInitial) {
            const pageData = getPageData(
                typeof page !== 'undefined' ? /* get current page value */
                    (function() {
                        let v = {};
                        page.subscribe(p => v = p)();
                        return v;
                    })() : {}
            );
            doTrackPageView(pageData);
        }

        // Watch Inertia navigation
        if (!pageUnsubscribe) {
            pageUnsubscribe = page.subscribe(($page) => {
                if ($page.props?.zbAnalytics) {
                    handlePageNavigation($page);
                }
            });
        }
    }

    /**
     * Stop page view tracking.
     */
    function stopTracking() {
        tracking.set(false);

        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }

        // Track engagement time before stopping
        if (trackEngagement && lastPageViewTime && isInitialized()) {
            const timeOnPage = Date.now() - lastPageViewTime;
            try {
                trackEvent('time_on_page', {
                    duration_ms: timeOnPage,
                    page_url: typeof window !== 'undefined' ? window.location.href : '',
                });
            } catch { /* silent */ }
        }
    }

    /**
     * Reset all tracking state.
     */
    function reset() {
        currentPage.set({ title: '', url: '', referrer: '', route: null, component: null });
        previousPage.set(null);
        pageViewCount.set(0);
        avgTimeBetweenViews.set(0);
        sessionStart.set(null);
        lastPageViewTime = null;
        timeIntervals.length = 0;
        firstPageTracked = false;
    }

    /**
     * Cleanup on component destroy.
     */
    function cleanup() {
        stopTracking();
        if (pageUnsubscribe) {
            pageUnsubscribe();
            pageUnsubscribe = null;
        }
    }

    // ─── Auto-start ─────────────────────────────────────────────

    if (autoTrack && typeof window !== 'undefined') {
        startTracking();
    }

    if (!cleanupRegistered) {
        cleanupRegistered = true;
        onDestroy(cleanup);
    }

    return {
        currentPage,
        previousPage,
        pageViewCount,
        tracking,
        sessionStart,
        avgTimeBetweenViews,

        // Methods
        trackVirtualPageView,
        startTracking,
        stopTracking,
        reset,
    };
}

export default usePageView;
