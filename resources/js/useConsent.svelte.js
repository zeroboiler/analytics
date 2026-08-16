/**
 * ZeroBoiler Analytics — Svelte Composable: useConsent
 *
 * Reactive GDPR/Consent Mode v2 management composable for Svelte/Inertia apps.
 * Reads consent state from Inertia page props, provides reactive stores
 * for granular consent purposes, and exposes methods to grant/withdraw consent
 * with automatic server synchronization.
 *
 * Supports Consent Mode v2 (analytics_storage, ad_storage, ad_user_data,
 * ad_personalization, functionality_storage, personalization_storage,
 * security_storage) with automatic gtag consent API calls when GA4 is enabled.
 *
 * @package ZeroBoiler Analytics
 * @version 190.0.0
 */

import { writable, derived, readonly } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import { updateConsent, getConsentState, isInitialized, trackEvent, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Master consent state: 'pending' (not yet decided), 'granted', 'denied'.
 * @type {import('svelte/store').Writable<string>}
 */
export const consentState = writable('pending');

/**
 * Individual consent purposes with granted/denied status.
 * Keys: necessary, analytics, marketing, functional, ad_user_data,
 * ad_personalization, functionality_storage, personalization_storage, security_storage.
 * @type {import('svelte/store').Writable<Record<string, boolean>>}
 */
export const purposes = writable({});

/**
 * Whether consent banner should be visible.
 * True when consentState is 'pending' and banner is enabled.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const showBanner = writable(false);

/**
 * Timestamp of last consent update (ISO string or null).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const lastUpdated = writable(null);

/**
 * Whether consent is being synced with the server.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const syncing = writable(false);

// ─── Derived Stores ───────────────────────────────────────────────

/**
 * Whether analytics consent is granted.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const analyticsGranted = derived(purposes, (p) => p.analytics !== false);

/**
 * Whether marketing consent is granted.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const marketingGranted = derived(purposes, (p) => p.marketing === true);

/**
 * Whether all non-necessary consent purposes are granted.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const allGranted = derived(purposes, (p) => {
    const keys = Object.keys(p).filter(k => k !== 'necessary');
    return keys.length > 0 && keys.every(k => p[k] === true);
});

/**
 * Whether all tracking is effectively denied (analytics or master denied).
 * @type {import('svelte/store').Readable<boolean>}
 */
export const allDenied = derived(
    [consentState, purposes],
    ([$consentState, $purposes]) => $consentState === 'denied' || $purposes.analytics === false,
);

// ─── Internal State ───────────────────────────────────────────────

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

/** @type {boolean} Whether initialized from props */
let initialized = false;

// ─── Composable ──────────────────────────────────────────────────

/**
 * GDPR Consent Mode v2 management composable.
 *
 * Provides reactive consent state management with automatic server
 * synchronization and gtag consent API integration for GA4.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoShowBanner=true] - Show banner when consent is pending
 * @param {boolean} [options.defaultConsent=null] - Override default consent (true/false/null)
 * @param {string[]} [options.requiredPurposes=['necessary']] - Purposes that cannot be denied
 * @param {Function} [options.onConsentChange] - Callback on consent change (state, purposes) => void
 * @returns {{
 *   consentState: import('svelte/store').Writable<string>,
 *   purposes: import('svelte/store').Writable<Record<string, boolean>>,
 *   showBanner: import('svelte/store').Writable<boolean>,
 *   lastUpdated: import('svelte/store').Writable<string|null>,
 *   syncing: import('svelte/store').Writable<boolean>,
 *   analyticsGranted: import('svelte/store').Readable<boolean>,
 *   marketingGranted: import('svelte/store').Readable<boolean>,
 *   allGranted: import('svelte/store').Readable<boolean>,
 *   allDenied: import('svelte/store').Readable<boolean>,
 *   grant: (purposeNames?: string[]) => Promise<void>,
 *   deny: (purposeNames?: string[]) => Promise<void>,
 *   grantAll: () => Promise<void>,
 *   denyAll: () => Promise<void>,
 *   setPurpose: (purpose: string, granted: boolean) => Promise<void>,
 *   dismissBanner: () => void,
 *   reset: () => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useConsent } from '@zeroboiler/analytics/svelte';
 *
 * const {
 *     consentState, showBanner, purposes,
 *     grantAll, denyAll, grant, dismissBanner,
 *     analyticsGranted,
 * } = useConsent();
 * </script>
 *
 * {#if $showBanner}
 *   <div class="consent-banner">
 *     <p>We use analytics to improve your experience.</p>
 *     <button on:click={grantAll}>Accept All</button>
 *     <button on:click={denyAll}>Deny All</button>
 *     <button on:click={() => grant(['analytics'])}>Analytics Only</button>
 *     <button on:click={dismissBanner}>Dismiss</button>
 *   </div>
 * {/if}
 *
 * {#if $analyticsGranted}
 *   <p>Analytics tracking is active</p>
 * {/if}
 * ```
 */
export function useConsent(options = {}) {
    const autoShowBanner = options.autoShowBanner !== false;
    const defaultConsent = options.defaultConsent ?? null;
    const requiredPurposes = options.requiredPurposes || ['necessary'];
    const onConsentChange = options.onConsentChange || null;

    /**
     * Initialize consent state from Inertia page props.
     */
    function initialize() {
        if (initialized) return;

        const analytics = page.props?.zbAnalytics;
        if (!analytics) return;

        const consent = analytics.consent || {};
        const state = consent.state || (defaultConsent === true ? 'granted' : defaultConsent === false ? 'denied' : 'pending');

        consentState.set(state);
        purposes.set(consent.purposes || {
            necessary: true,
            analytics: state === 'granted',
            marketing: false,
            functional: true,
        });

        // Show banner if consent is pending and auto-show is enabled
        showBanner.set(state === 'pending' && autoShowBanner);

        initialized = true;

        // Watch for Inertia page prop updates
        setupPageWatcher();
    }

    /**
     * Watch Inertia page props for consent state changes.
     */
    function setupPageWatcher() {
        if (pageUnsubscribe) return;

        pageUnsubscribe = page.subscribe(($page) => {
            const analytics = $page.props?.zbAnalytics;
            if (!analytics?.consent) return;

            purposes.set(analytics.consent.purposes || {});
            consentState.set(analytics.consent.state || 'pending');
            lastUpdated.set(new Date().toISOString());
        });
    }

    /**
     * Call gtag consent API when GA4 is available.
     */
    function updateGtagConsent(consentMap) {
        if (typeof window === 'undefined') return;

        // eslint-disable-next-line no-undef
        if (typeof gtag === 'function') {
            for (const [key, value] of Object.entries(consentMap)) {
                try {
                    // eslint-disable-next-line no-undef
                    gtag('consent', 'update', { [key]: value ? 'granted' : 'denied' });
                } catch {
                    // gtag may not be available
                }
            }
        }
    }

    /**
     * Synchronize consent state to the server.
     */
    async function syncToServer() {
        if (!isInitialized()) return;

        syncing.set(true);
        try {
            const currentPurposes = {};
            purposes.subscribe(p => Object.assign(currentPurposes, p))();

            await updateConsent(currentPurposes);
            lastUpdated.set(new Date().toISOString());
        } catch {
            // Silent — consent sync should not break the app
        } finally {
            syncing.set(false);
        }
    }

    /**
     * Grant one or more consent purposes.
     *
     * @param {string[]} [purposeNames] - Purposes to grant (all if empty)
     */
    async function grant(purposeNames) {
        const targets = purposeNames || Object.keys(requirePurposesToSet());
        const updates = {};

        for (const name of targets) {
            updates[name] = true;
        }

        purposes.update(current => ({ ...current, ...updates }));

        // Fire consent event
        fireConsentEvent('granted', targets);

        // Update gtag
        const gtagMap = consentPurposeToGtagMap(targets, true);
        updateGtagConsent(gtagMap);

        // Sync to server
        await syncToServer();

        // Callback
        if (onConsentChange) {
            try {
                onConsentChange('granted', updates);
            } catch { /* silent */ }
        }
    }

    /**
     * Deny one or more consent purposes.
     * Cannot deny required purposes (necessary).
     *
     * @param {string[]} [purposeNames] - Purposes to deny (all non-required if empty)
     */
    async function deny(purposeNames) {
        const targets = purposeNames || Object.keys(requirePurposesToSet()).filter(p => !requiredPurposes.includes(p));
        const updates = {};

        for (const name of targets) {
            if (!requiredPurposes.includes(name)) {
                updates[name] = false;
            }
        }

        purposes.update(current => ({ ...current, ...updates }));

        // Fire consent event
        fireConsentEvent('withdrawn', targets);

        // Update gtag
        const gtagMap = consentPurposeToGtagMap(targets, false);
        updateGtagConsent(gtagMap);

        // Sync to server
        await syncToServer();

        // Callback
        if (onConsentChange) {
            try {
                onConsentChange('denied', updates);
            } catch { /* silent */ }
        }
    }

    /**
     * Grant all consent purposes.
     */
    async function grantAll() {
        consentState.set('granted');
        showBanner.set(false);
        await grant();
    }

    /**
     * Deny all non-necessary consent purposes.
     */
    async function denyAll() {
        consentState.set('denied');
        showBanner.set(false);
        await deny();
    }

    /**
     * Set a single consent purpose.
     *
     * @param {string} purpose - Purpose name
     * @param {boolean} granted - Whether to grant
     */
    async function setPurpose(purpose, granted) {
        if (requiredPurposes.includes(purpose) && !granted) return;

        purposes.update(current => ({ ...current, [purpose]: granted }));

        const gtagMap = consentPurposeToGtagMap([purpose], granted);
        updateGtagConsent(gtagMap);

        fireConsentEvent(granted ? 'granted' : 'withdrawn', [purpose]);
        await syncToServer();

        // Update master state
        const currentPurposes = {};
        purposes.subscribe(p => Object.assign(currentPurposes, p))();
        const optionalKeys = Object.keys(currentPurposes).filter(k => !requiredPurposes.includes(k));
        consentState.set(optionalKeys.every(k => currentPurposes[k]) ? 'granted' : 'denied');

        if (onConsentChange) {
            try {
                onConsentChange(granted ? 'granted' : 'denied', { [purpose]: granted });
            } catch { /* silent */ }
        }
    }

    /**
     * Dismiss the consent banner without changing consent.
     */
    function dismissBanner() {
        showBanner.set(false);
    }

    /**
     * Reset consent state to pending (for re-prompting).
     */
    function reset() {
        consentState.set('pending');
        purposes.set({
            necessary: true,
            analytics: false,
            marketing: false,
            functional: true,
        });
        showBanner.set(autoShowBanner);
        lastUpdated.set(null);
    }

    /**
     * Get all purposes that can be configured (non-required).
     */
    function requirePurposesToSet() {
        const all = {};
        purposes.subscribe(p => Object.assign(all, p))();
        return all;
    }

    /**
     * Fire a consent analytics event.
     */
    function fireConsentEvent(action, purposeNames) {
        if (!isInitialized()) return;

        try {
            trackEvent(action === 'granted' ? 'consent_granted' : 'consent_withdrawn', {
                purposes: purposeNames,
                client_id: getClientId(),
                consent_state: action === 'granted' ? 'granted' : 'denied',
            });
        } catch {
            // Silent
        }
    }

    /**
     * Map consent purposes to gtag consent API keys.
     */
    function consentPurposeToGtagMap(purposeNames, granted) {
        const purposeToGtag = {
            analytics: 'analytics_storage',
            marketing: 'ad_storage',
            ad_user_data: 'ad_user_data',
            ad_personalization: 'ad_personalization',
            functional: 'functionality_storage',
            personalization: 'personalization_storage',
            security: 'security_storage',
        };

        const gtagMap = {};
        for (const name of purposeNames) {
            const gtagKey = purposeToGtag[name];
            if (gtagKey) {
                gtagMap[gtagKey] = granted ? 'granted' : 'denied';
            }
        }

        return gtagMap;
    }

    // ─── Initialize ─────────────────────────────────────────────

    if (typeof window !== 'undefined') {
        initialize();
    }

    return {
        consentState,
        purposes,
        showBanner,
        lastUpdated,
        syncing,

        // Derived (read-only)
        analyticsGranted,
        marketingGranted,
        allGranted,
        allDenied,

        // Methods
        grant,
        deny,
        grantAll,
        denyAll,
        setPurpose,
        dismissBanner,
        reset,
    };
}

export default useConsent;
