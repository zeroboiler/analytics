/**
 * ZeroBoiler Analytics — Svelte 5 Composables
 *
 * Reactive analytics hooks using Svelte 5 runes ($state, $derived, $effect).
 * Provides type-safe, auto-initializing analytics for Svelte/Inertia/Laravel apps.
 *
 * @package ZeroBoiler Analytics
 * @version 3.3.0
 */

import { tick } from 'svelte';
import {
    init,
    destroy,
    isInitialized,
    trackEvent,
    trackPageView,
    trackScreenView,
    trackEcommerce,
    trackIdentify,
    updateConsent,
    flushQueue,
    getTrackingId,
    getVersion,
} from './analytics.js';

// ─── Reactive State ──────────────────────────────────────────────────

/**
 * Core analytics state — automatically synced with Inertia page props.
 *
 * @type {{ initialized: boolean, version: string, trackingId: string|null, consent: object, enabled: boolean, userId: string|null, debug: boolean }}
 */
let state = $state({
    initialized: false,
    version: '0.0.0',
    trackingId: null,
    consent: {},
    enabled: false,
    userId: null,
    debug: false,
});

/**
 * Derived flag: is analytics available and consent granted?
 */
let isReady = $derived(
    state.initialized && state.enabled && state.consent?.analytics_storage !== 'denied'
);

/**
 * Derived flag: is the user currently authenticated?
 */
let isAuthenticated = $derived(state.userId !== null && state.userId !== '');

// ─── Core Composable ────────────────────────────────────────────────

/**
 * Initialize analytics from Inertia page props.
 *
 * Call this in your root layout or top-level component.
 * Automatically reacts to page prop changes (e.g. login/logout).
 *
 * @param {import('svelte/store').Readable<{props?: {zbAnalytics?: object}}>} page - Inertia page store
 *
 * @example
 * // +page.svelte (SvelteKit/Inertia)
 * <script>
 *   import { page } from '@inertiajs/svelte';
 *   import { useAnalytics } from '@zeroboiler/analytics';
 *   const { isReady, trackingId } = useAnalytics(page);
 * </script>
 *
 * {#if isReady}
 *   <p>Tracking: {trackingId}</p>
 * {/if}
 */
export function useAnalytics(page) {
    // Reactive sync with Inertia page props
    $effect(() => {
        const props = page.props?.zbAnalytics;

        if (!props?.enabled) {
            state.initialized = false;
            state.enabled = false;
            return;
        }

        // Initialize the underlying analytics library
        init(page.props);

        // Sync reactive state
        state.initialized = isInitialized();
        state.version = getVersion();
        state.trackingId = getTrackingId();
        state.consent = props.consent || {};
        state.enabled = props.enabled;
        state.userId = props.userId || null;
        state.debug = props.debug || false;
    });

    // Cleanup on component destroy
    return {
        get initialized() { return state.initialized; },
        get version() { return state.version; },
        get trackingId() { return state.trackingId; },
        get consent() { return state.consent; },
        get enabled() { return state.enabled; },
        get userId() { return state.userId; },
        get debug() { return state.debug; },
        get isReady() { return isReady; },
        get isAuthenticated() { return isAuthenticated; },
    };
}

// ─── Event Tracking Composable ─────────────────────────────────────

/**
 * Event tracking composable with automatic client ID attachment.
 *
 * @returns {{ track: TrackFunction, trackCritical: TrackFunction, trackPage: TrackPageFunction, trackScreen: TrackScreenFunction }}
 *
 * @example
 * const { track, trackPage, trackScreen } = useTrackEvents();
 *
 * track('button_click', { element: 'buy_now' });
 * trackPage('Pricing', '/pricing');
 * trackScreen('Dashboard');
 *
 * @typedef {function(string, Record<string, unknown>=, {immediate?: boolean}=): Promise<void>} TrackFunction
 * @typedef {function(string?, string?, string?): Promise<void>} TrackPageFunction
 * @typedef {function(string, {screenClass?: string, params?: Record<string, unknown>}?): Promise<void>} TrackScreenFunction
 */
export function useTrackEvents() {
    /**
     * Track a custom analytics event.
     * Automatically attaches tracking ID and user context.
     *
     * @param {string} name - Event name
     * @param {Record<string, unknown>} [params={}] - Event parameters
     * @param {{immediate?: boolean}} [options={}] - Options
     */
    async function track(name, params = {}, options = {}) {
        if (!isReady) return;

        await trackEvent(name, {
            ...params,
            _client_id: state.trackingId,
            _user_id: state.userId,
            _version: state.version,
        }, options);
    }

    /**
     * Track a critical event — bypasses batch queue for immediate dispatch.
     *
     * @param {string} name - Event name
     * @param {Record<string, unknown>} [params={}] - Event parameters
     */
    async function trackCritical(name, params = {}) {
        await track(name, params, { immediate: true });
    }

    /**
     * Track a page view with auto-detected title/location.
     *
     * @param {string} [title] - Page title (auto-detected from document.title)
     * @param {string} [location] - Page URL (auto-detected from window.location)
     * @param {string} [referrer] - Referrer URL
     */
    async function trackPage(title, location, referrer) {
        if (!isReady) return;

        await trackPageView(title, location, referrer);
    }

    /**
     * Track a screen view (SPA navigation).
     *
     * @param {string} screenName - Screen name
     * @param {{screenClass?: string, params?: Record<string, unknown>}} [options={}]
     */
    async function trackScreen(screenName, options = {}) {
        if (!isReady) return;

        await trackScreenView(screenName, options);
    }

    return {
        track,
        trackCritical,
        trackPage,
        trackScreen,
    };
}

// ─── E-Commerce Composable ──────────────────────────────────────────

/**
 * E-commerce tracking composable with GA4/Meta/PostHog format helpers.
 *
 * @returns {{ trackViewItem: TrackItemFunction, trackAddToCart: TrackItemFunction, trackPurchase: TrackPurchaseFunction, trackRefund: TrackRefundFunction, trackBeginCheckout: TrackItemFunction }}
 *
 * @example
 * const { trackPurchase } = useEcommerce();
 *
 * trackPurchase('ORD-123', 99.99, 'USD', [
 *     { item_id: 'SKU-1', item_name: 'Widget', price: 49.99, quantity: 2 }
 * ]);
 *
 * @typedef {function(object, string?): Promise<void>} TrackItemFunction
 * @typedef {function(string, number, string, object[]): Promise<void>} TrackPurchaseFunction
 * @typedef {function(string, number, string, object[]): Promise<void>} TrackRefundFunction
 */
export function useEcommerce() {
    async function trackViewItem(item, currency = 'USD') {
        if (!isReady) return;

        await trackEvent('view_item', {
            currency,
            value: item.price || 0,
            items: [item],
        });
    }

    async function trackAddToCart(item, currency = 'USD') {
        if (!isReady) return;

        await trackEvent('add_to_cart', {
            currency,
            value: (item.price || 0) * (item.quantity || 1),
            items: [item],
        });
    }

    async function trackBeginCheckout(items, currency = 'USD') {
        if (!isReady) return;

        const totalValue = items.reduce((sum, item) => sum + (item.price || 0) * (item.quantity || 1), 0);

        await trackEvent('begin_checkout', {
            currency,
            value: totalValue,
            items,
        });
    }

    async function trackPurchase(transactionId, value, currency, items, options = {}) {
        if (!isReady) return;

        await trackEvent('purchase', {
            transaction_id: transactionId,
            value,
            currency,
            items,
            ...options,
        }, { immediate: true });
    }

    async function trackRefund(transactionId, value, currency, items = []) {
        if (!isReady) return;

        await trackEvent('refund', {
            transaction_id: transactionId,
            value,
            currency,
            items,
        });
    }

    return {
        trackViewItem,
        trackAddToCart,
        trackBeginCheckout,
        trackPurchase,
        trackRefund,
    };
}

// ─── SaaS Lifecycle Composable ──────────────────────────────────────

/**
 * SaaS lifecycle event tracking composable.
 *
 * Covers signup, trial, subscription, plan changes, and cancellation.
 *
 * @returns {{ trackSignUp: TrackSaaSFunction, trackLogin: TrackSaaSFunction, trackTrialStart: TrackSaaSFunction, trackSubscribe: TrackSubscribeFunction, trackPlanUpgrade: TrackPlanFunction, trackCancellation: TrackCancelFunction }}
 *
 * @example
 * const { trackSignUp, trackSubscribe, trackPlanUpgrade } = useSaaSLifecycle();
 *
 * trackSignUp('email');
 * trackSubscribe('pro', 29.99, 'USD', 'monthly');
 * trackPlanUpgrade('free', 'pro');
 *
 * @typedef {function(string?, Record<string, unknown>?): Promise<void>} TrackSaaSFunction
 * @typedef {function(string, number, string, string?, Record<string, unknown>?): Promise<void>} TrackSubscribeFunction
 * @typedef {function(string, string, number?, Record<string, unknown>?): Promise<void>} TrackPlanFunction
 * @typedef {function(string?, string?, Record<string, unknown>?): Promise<void>} TrackCancelFunction
 */
export function useSaaSLifecycle() {
    async function trackSignUp(method = null, params = {}) {
        if (!isReady) return;

        await trackEvent('sign_up', { method, ...params });
    }

    async function trackLogin(method = null, params = {}) {
        if (!isReady) return;

        await trackEvent('login', { method, ...params });
    }

    async function trackTrialStart(planName = null, trialDays = null, params = {}) {
        if (!isReady) return;

        await trackEvent('start_trial', { plan_name: planName, trial_days: trialDays, ...params });
    }

    async function trackSubscribe(planName, amount, currency = 'USD', billingCycle = null, params = {}) {
        if (!isReady) return;

        await trackEvent('subscribe', {
            plan_name: planName,
            value: amount,
            currency,
            billing_cycle: billingCycle,
            ...params,
        }, { immediate: true });
    }

    async function trackPlanUpgrade(fromPlan, toPlan, priceDifference = null, params = {}) {
        if (!isReady) return;

        await trackEvent('plan_upgrade', {
            from_plan: fromPlan,
            to_plan: toPlan,
            price_difference: priceDifference,
            ...params,
        });
    }

    async function trackPlanDowngrade(fromPlan, toPlan, params = {}) {
        if (!isReady) return;

        await trackEvent('plan_downgrade', {
            from_plan: fromPlan,
            to_plan: toPlan,
            ...params,
        });
    }

    async function trackCancellation(planName = null, reason = null, params = {}) {
        if (!isReady) return;

        await trackEvent('cancellation', {
            plan_name: planName,
            reason,
            ...params,
        });
    }

    async function trackFeatureUsed(featureName, params = {}) {
        if (!isReady) return;

        await trackEvent('feature_used', {
            feature_name: featureName,
            ...params,
        });
    }

    return {
        trackSignUp,
        trackLogin,
        trackTrialStart,
        trackSubscribe,
        trackPlanUpgrade,
        trackPlanDowngrade,
        trackCancellation,
        trackFeatureUsed,
    };
}

// ─── Consent Composable ─────────────────────────────────────────────

/**
 * GDPR consent management composable.
 *
 * Provides reactive consent state and methods to update consent signals.
 *
 * @returns {{ consent: object, isAnalyticsGranted: boolean, isAdGranted: boolean, grant: GrantFunction, deny: DenyFunction, update: UpdateConsentFunction }}
 *
 * @example
 * const { isAnalyticsGranted, grant, deny, update } = useConsent();
 *
 * if (!isAnalyticsGranted) {
 *     update({ analytics_storage: 'granted' });
 * }
 *
 * @typedef {function(Record<string, string>): void} GrantFunction
 * @typedef {function(string[]): void} DenyFunction
 * @typedef {function(Record<string, 'granted'|'denied'>): void} UpdateConsentFunction
 */
export function useConsent() {
    let isAnalyticsGranted = $derived(
        state.consent?.analytics_storage === 'granted'
    );

    let isAdGranted = $derived(
        state.consent?.ad_storage === 'granted'
    );

    let isFunctionalityGranted = $derived(
        state.consent?.functionality_storage === 'granted'
    );

    /**
     * Grant consent for specific purposes.
     *
     * @param {Record<string, string>} purposes - e.g. { analytics_storage: 'granted', ad_storage: 'granted' }
     */
    function grant(purposes) {
        const signals = {};
        for (const [key, value] of Object.entries(purposes)) {
            signals[key] = 'granted';
        }
        updateConsent(signals);
        state.consent = { ...state.consent, ...signals };
    }

    /**
     * Deny consent for specific purposes.
     *
     * @param {string[]} purposes - e.g. ['analytics_storage', 'ad_storage']
     */
    function deny(purposes) {
        const signals = {};
        for (const purpose of purposes) {
            signals[purpose] = 'denied';
        }
        updateConsent(signals);
        state.consent = { ...state.consent, ...signals };
    }

    /**
     * Update consent signals.
     *
     * @param {Record<string, 'granted'|'denied'>} signals
     */
    function update(signals) {
        updateConsent(signals);
        state.consent = { ...state.consent, ...signals };
    }

    return {
        get consent() { return state.consent; },
        get isAnalyticsGranted() { return isAnalyticsGranted; },
        get isAdGranted() { return isAdGranted; },
        get isFunctionalityGranted() { return isFunctionalityGranted; },
        grant,
        deny,
        update,
    };
}

// ─── Debug Composable ────────────────────────────────────────────────

/**
 * Debug/development analytics composable.
 *
 * @returns {{ isDebug: boolean, eventCount: number, lastEvent: object|null, reset: function(): void }}
 */
export function useAnalyticsDebug() {
    let eventCount = $state(0);
    let lastEvent = $state(null);

    let isDebug = $derived(state.debug);

    // Wrap trackEvent to capture debug info
    async function debugTrack(name, params = {}, options = {}) {
        eventCount++;
        lastEvent = {
            name,
            params,
            timestamp: new Date().toISOString(),
            trackingId: state.trackingId,
            userId: state.userId,
        };

        await trackEvent(name, params, options);
    }

    function reset() {
        eventCount = 0;
        lastEvent = null;
    }

    return {
        get isDebug() { return isDebug; },
        get eventCount() { return eventCount; },
        get lastEvent() { return lastEvent; },
        debugTrack,
        reset,
    };
}

// ─── Cleanup ────────────────────────────────────────────────────────

/**
 * Cleanup all analytics state. Call in onDestroy or onUnmounted.
 */
export function cleanupAnalytics() {
    state = $state({
        initialized: false,
        version: '0.0.0',
        trackingId: null,
        consent: {},
        enabled: false,
        userId: null,
        debug: false,
    });
    destroy();
}
