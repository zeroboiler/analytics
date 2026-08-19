/**
 * ZeroBoiler Analytics — Svelte Composable: useAttribution
 *
 * Reactive campaign attribution composable for Svelte/Inertia applications.
 * Provides real-time reactive stores for UTM parameters, referrer data,
 * traffic source classification, first-touch persistence, and attribution
 * context for SaaS funnel tracking.
 *
 * Reads campaign data from Inertia page props (zbAnalytics.campaignContext)
 * and provides derived stores for conversion tracking and attribution reporting.
 *
 * @package ZeroBoiler Analytics
 * @version 260.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Current UTM parameters from the URL or server context.
 * @type {import('svelte/store').Writable<{source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null}>}
 */
export const utm = writable({
    source: null,
    medium: null,
    campaign: null,
    term: null,
    content: null,
});

/**
 * HTTP referrer URL.
 * @type {import('svelte/store').Writable<string|null>}
 */
export const referrer = writable(null);

/**
 * Classified traffic source (direct, organic_search, paid_social, etc.).
 * @type {import('svelte/store').Writable<string>}
 */
export const trafficSource = writable('direct');

/**
 * Whether the current visit has UTM parameters.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const hasUtm = writable(false);

/**
 * First-touch attribution data persisted in localStorage.
 * @type {import('svelte/store').Writable<{utm: object, referrer: string|null, traffic_source: string, timestamp: string|null}|null>}
 */
export const firstTouch = writable(null);

/**
 * Whether first-touch attribution is persisted.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const hasFirstTouch = writable(false);

/**
 * Whether attribution context has been loaded from server props.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const loaded = writable(false);

// ─── Derived Stores ───────────────────────────────────────────────

/**
 * Full UTM string for display/debugging: "source/medium/campaign".
 * @type {import('svelte/store').Readable<string>}
 */
export const utmString = derived(utm, ($utm) => {
    const parts = [$utm.source, $utm.medium, $utm.campaign].filter(Boolean);
    return parts.join('/') || 'direct';
});

/**
 * Attribution summary label for UI display.
 * @type {import('svelte/store').Readable<string>}
 */
export const attributionLabel = derived(
    [trafficSource, utm],
    ([$trafficSource, $utm]) => {
        if ($utm.source) return $utm.source;
        if ($trafficSource !== 'direct') return $trafficSource;
        return 'Direct Traffic';
    },
);

/**
 * Whether this is a paid traffic visit.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const isPaidTraffic = derived(trafficSource, ($source) =>
    $source === 'paid_search' || $source === 'paid_social' || $source === 'affiliate',
);

/**
 * Whether this is an organic visit (search or social).
 * @type {import('svelte/store').Readable<boolean>}
 */
export const isOrganicTraffic = derived(trafficSource, ($source) =>
    $source === 'organic_search' || $source === 'organic_social',
);

/**
 * Attribution snapshot for debugging/analytics.
 * @type {import('svelte/store').Readable<object>}
 */
export const attributionSnapshot = derived(
    [utm, referrer, trafficSource, hasUtm, firstTouch, hasFirstTouch],
    ([$utm, $referrer, $trafficSource, $hasUtm, $firstTouch, $hasFirstTouch]) => ({
        current: {
            utm: $utm,
            referrer: $referrer,
            traffic_source: $trafficSource,
            has_utm: $hasUtm,
        },
        first_touch: $hasFirstTouch ? $firstTouch : null,
        attribution_label: $utm.source || ($trafficSource !== 'direct' ? $trafficSource : 'direct'),
    }),
);

// ─── Internal State ───────────────────────────────────────────────

const STORAGE_KEY = 'zb_analytics_first_touch';

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

/** @type {boolean} Whether initialized */
let initialized = false;

// ─── Composable ───────────────────────────────────────────────────

/**
 * Reactive campaign attribution composable for Svelte/Inertia applications.
 *
 * Provides reactive stores for UTM parameters, traffic source classification,
 * first-touch attribution persistence, and attribution context reporting.
 *
 * Automatically reads campaign context from Inertia page props and persists
 * first-touch data to localStorage for cross-session attribution tracking.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.persistFirstTouch=true] - Persist first-touch to localStorage
 * @param {boolean} [options.autoTrack=true] - Auto-track attribution events
 * @param {string} [options.storageKey='zb_analytics_first_touch'] - localStorage key
 * @param {Function} [options.onFirstTouch] - Callback when first-touch is captured: (context) => void
 * @returns {{
 *   utm: import('svelte/store').Writable<object>,
 *   referrer: import('svelte/store').Writable<string|null>,
 *   trafficSource: import('svelte/store').Writable<string>,
 *   hasUtm: import('svelte/store').Writable<boolean>,
 *   firstTouch: import('svelte/store').Writable<object|null>,
 *   hasFirstTouch: import('svelte/store').Writable<boolean>,
 *   loaded: import('svelte/store').Writable<boolean>,
 *   utmString: import('svelte/store').Readable<string>,
 *   attributionLabel: import('svelte/store').Readable<string>,
 *   isPaidTraffic: import('svelte/store').Readable<boolean>,
 *   isOrganicTraffic: import('svelte/store').Readable<boolean>,
 *   attributionSnapshot: import('svelte/store').Readable<object>,
 *   persistFirstTouch: () => void,
 *   clearFirstTouch: () => void,
 *   trackAttribution: () => void,
 *   reset: () => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useAttribution } from '@zeroboiler/analytics/svelte';
 *
 * const {
 *     utm, trafficSource, isPaidTraffic,
 *     attributionLabel, attributionSnapshot,
 * } = useAttribution();
 * </script>
 *
 * <p>Traffic source: {$attributionLabel}</p>
 * <p>UTM: {$utmString}</p>
 * {#if $isPaidTraffic}
 *   <span class="badge-paid">Paid</span>
 * {/if}
 * ```
 */
export function useAttribution(options = {}) {
    const persistFirstTouchFlag = options.persistFirstTouch !== false;
    const autoTrack = options.autoTrack !== false;
    const storageKey = options.storageKey || STORAGE_KEY;
    const onFirstTouch = options.onFirstTouch || null;

    /**
     * Read UTM params from the current browser URL.
     */
    function readUtmFromUrl() {
        if (typeof window === 'undefined') return { source: null, medium: null, campaign: null, term: null, content: null };

        const params = new URLSearchParams(window.location.search);
        return {
            source: params.get('utm_source'),
            medium: params.get('utm_medium'),
            campaign: params.get('utm_campaign'),
            term: params.get('utm_term'),
            content: params.get('utm_content'),
        };
    }

    /**
     * Initialize attribution state from Inertia props or URL.
     */
    function initialize() {
        if (initialized) return;

        // First, try reading from Inertia page props
        const analytics = page.props?.zbAnalytics;
        const campaignContext = analytics?.campaignContext;

        if (campaignContext) {
            utm.set(campaignContext.utm || { source: null, medium: null, campaign: null, term: null, content: null });
            referrer.set(campaignContext.referrer || null);
            trafficSource.set(campaignContext.traffic_source || 'direct');
            hasUtm.set(campaignContext.has_utm || false);
        } else {
            // Fallback: read from URL
            const urlUtm = readUtmFromUrl();
            utm.set(urlUtm);
            hasUtm.set(Object.values(urlUtm).some(v => v !== null));
            referrer.set(typeof document !== 'undefined' ? document.referrer || null : null);
            trafficSource.set('direct'); // Will be updated by server on next request
        }

        // Load first-touch from localStorage
        loadFirstTouchFromStorage(storageKey);

        loaded.set(true);
        initialized = true;

        // Watch for page navigation
        setupPageWatcher(storageKey, persistFirstTouchFlag, autoTrack, onFirstTouch);

        // Auto-persist first touch if this is a UTM visit
        if (persistFirstTouchFlag && !getHasFirstTouchFromStorage(storageKey)) {
            persistFirstTouchData(storageKey, onFirstTouch);
        }
    }

    /**
     * Watch Inertia page navigation for updated campaign context.
     */
    function setupPageWatcher(key, shouldPersist, shouldAutoTrack, firstTouchCb) {
        if (pageUnsubscribe) return;

        pageUnsubscribe = page.subscribe(($page) => {
            const analytics = $page.props?.zbAnalytics;
            const campaignContext = analytics?.campaignContext;

            if (campaignContext) {
                utm.set(campaignContext.utm || { source: null, medium: null, campaign: null, term: null, content: null });
                referrer.set(campaignContext.referrer || null);
                trafficSource.set(campaignContext.traffic_source || 'direct');
                hasUtm.set(campaignContext.has_utm || false);
            }

            // Persist first touch on UTM visit
            if (shouldPersist && !getHasFirstTouchFromStorage(key) && hasUtmFromStore()) {
                persistFirstTouchData(key, firstTouchCb);
            }
        });
    }

    /**
     * Check if current UTM store has values.
     */
    function hasUtmFromStore() {
        let has = false;
        utm.subscribe($u => {
            has = Object.values($u).some(v => v !== null && v !== '');
        })();
        return has;
    }

    /**
     * Persist first-touch attribution data to localStorage.
     */
    function persistFirstTouchData(key, firstTouchCb) {
        if (typeof window === 'undefined') return;

        let currentUtm = { source: null, medium: null, campaign: null, term: null, content: null };
        utm.subscribe($u => currentUtm = $u)();

        let currentReferrer = null;
        referrer.subscribe($r => currentReferrer = $r)();

        let currentTrafficSource = 'direct';
        trafficSource.subscribe($t => currentTrafficSource = $t)();

        const touchData = {
            utm: currentUtm,
            referrer: currentReferrer,
            traffic_source: currentTrafficSource,
            timestamp: new Date().toISOString(),
            landing_url: typeof window !== 'undefined' ? window.location.href : '',
            client_id: getClientId(),
        };

        try {
            localStorage.setItem(key, JSON.stringify(touchData));
            firstTouch.set(touchData);
            hasFirstTouch.set(true);

            // Auto-track first-touch attribution event
            if (shouldAutoTrack && isInitialized()) {
                trackEvent('first_touch', touchData);
            }

            if (firstTouchCb) {
                try { firstTouchCb(touchData); } catch { /* silent */ }
            }
        } catch {
            // localStorage unavailable
        }
    }

    function shouldAutoTrack() { return autoTrack; }

    /**
     * Load first-touch data from localStorage.
     */
    function loadFirstTouchFromStorage(key) {
        if (typeof window === 'undefined') return;

        try {
            const raw = localStorage.getItem(key);
            if (raw) {
                const data = JSON.parse(raw);
                firstTouch.set(data);
                hasFirstTouch.set(true);
            }
        } catch {
            // Invalid or unavailable
        }
    }

    /**
     * Check if first-touch exists in localStorage.
     */
    function getHasFirstTouchFromStorage(key) {
        if (typeof window === 'undefined') return false;
        try {
            return localStorage.getItem(key) !== null;
        } catch {
            return false;
        }
    }

    /**
     * Manually persist current attribution as first-touch.
     */
    function doPersistFirstTouch() {
        persistFirstTouchData(storageKey, onFirstTouch);
    }

    /**
     * Clear persisted first-touch data.
     */
    function doClearFirstTouch() {
        if (typeof window === 'undefined') return;
        try {
            localStorage.removeItem(storageKey);
            firstTouch.set(null);
            hasFirstTouch.set(false);
        } catch { /* silent */ }
    }

    /**
     * Track an attribution context event.
     */
    function doTrackAttribution() {
        if (!isInitialized()) return;

        let snapshot = {};
        attributionSnapshot.subscribe($s => snapshot = $s)();

        trackEvent('attribution_context', snapshot);
    }

    /**
     * Reset all attribution state.
     */
    function doReset() {
        utm.set({ source: null, medium: null, campaign: null, term: null, content: null });
        referrer.set(null);
        trafficSource.set('direct');
        hasUtm.set(false);
        firstTouch.set(null);
        hasFirstTouch.set(false);
        loaded.set(false);
        initialized = false;

        if (pageUnsubscribe) {
            pageUnsubscribe();
            pageUnsubscribe = null;
        }
    }

    // ─── Initialize ─────────────────────────────────────────────

    if (typeof window !== 'undefined') {
        initialize();
    }

    return {
        // Stores
        utm,
        referrer,
        trafficSource,
        hasUtm,
        firstTouch,
        hasFirstTouch,
        loaded,

        // Derived
        utmString,
        attributionLabel,
        isPaidTraffic,
        isOrganicTraffic,
        attributionSnapshot,

        // Methods
        persistFirstTouch: doPersistFirstTouch,
        clearFirstTouch: doClearFirstTouch,
        trackAttribution: doTrackAttribution,
        reset: doReset,
    };
}

/**
 * Convenience shorthand: create an attribution composable with default options.
 *
 * @example
 * ```svelte
 * <script>
 * import { attribution } from '@zeroboiler/analytics';
 * // Use $attribution.utm, $attribution.trafficSource, etc.
 * </script>
 * ```
 */
export function attributionStore(options = {}) {
    return useAttribution(options);
}

export default useAttribution;
