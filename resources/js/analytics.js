/**
 * ZeroBoiler Analytics — Client-Side Library
 *
 * Client-side analytics for Svelte/Inertia/Laravel apps.
 * Reads configuration from Inertia page props (zbAnalytics) and provides
 * a unified API for tracking events across GA4, GTM, Meta Pixel, Plausible, and PostHog.
 *
 * @package ZeroBoiler Analytics
 * @version 2.7.0
 */

let trackingId = null;
let config = null;
let initialized = false;

// ─── Batch Queue ─────────────────────────────────────────────────────

const eventQueue = [];
let flushTimer = null;
const FLUSH_INTERVAL = 5000; // 5 seconds
const MAX_QUEUE_SIZE = 25;

/**
 * Initialize the analytics library.
 *
 * Call this once when your app boots — typically in the root layout/component
 * after Inertia has resolved the initial page props.
 *
 * @param {object} pageProps - Inertia page props containing `zbAnalytics`
 *
 * @example
 * // Svelte/Inertia:
 * import { page } from '@inertiajs/svelte';
 * import { init, trackPageView } from '@zeroboiler/analytics';
 *
 * $: if (page.props.zbAnalytics) {
 *     init(page.props);
 * }
 */
export function init(pageProps) {
    const analytics = pageProps?.zbAnalytics;

    if (!analytics?.enabled) {
        return;
    }

    config = analytics;
    trackingId = analytics.trackingId;
    initialized = true;

    // Initialize GA4 gtag
    if (analytics.ga4MeasurementId) {
        initGA4(analytics);
    }

    // Initialize GTM dataLayer
    if (analytics.gtmContainerId) {
        initGTM(analytics);
    }

    // Initialize Meta Pixel
    if (analytics.metaPixelId) {
        initMetaPixel(analytics);
    }

    // Initialize Plausible (server-side, but inject script for pageviews)
    if (analytics.plausibleDomain) {
        initPlausible(analytics);
    }

    // Initialize PostHog
    if (analytics.posthogHost) {
        initPostHog(analytics);
    }

    // Start batch flush timer
    startFlushTimer();

    // Auto-capture UTM parameters on init
    captureUTM();
}

/**
 * Cleanup analytics listeners and timers.
 * Call this when your component unmounts or on app teardown.
 */
export function destroy() {
    if (flushTimer) {
        clearInterval(flushTimer);
        flushTimer = null;
    }
    eventQueue.length = 0;
    initialized = false;
    config = null;
    trackingId = null;
}

/**
 * Check if analytics is initialized.
 */
export function isInitialized() {
    return initialized;
}

/**
 * Get the current tracking ID (server-generated, cookie-stored).
 */
export function getTrackingId() {
    return trackingId;
}

// ─── GA4 Initialization ─────────────────────────────────────────────────

function initGA4(analytics) {
    // gtag.js may already be loaded via Blade middleware; avoid double-init
    if (typeof window !== 'undefined' && window.dataLayer && window.dataLayer.length > 0) {
        return;
    }

    window.dataLayer = window.dataLayer || [];

    function gtag() {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer.push(arguments);
    }

    window.gtag = gtag;

    gtag('js', new Date());

    const consent = analytics.consent || {};
    gtag('consent', 'default', {
        ad_storage: consent.ad_storage || 'granted',
        analytics_storage: consent.analytics_storage || 'granted',
        ad_user_data: consent.ad_user_data || 'granted',
        ad_personalization: consent.ad_personalization || 'granted',
        functionality_storage: consent.functionality_storage || 'granted',
        security_storage: consent.security_storage || 'granted',
    });

    gtag('config', analytics.ga4MeasurementId, {
        send_page_view: false,
    });
}

// ─── GTM Initialization ──────────────────────────────────────────────────

function initGTM(analytics) {
    // GTM dataLayer should already be available via Blade middleware
    if (typeof window !== 'undefined' && !window.dataLayer) {
        window.dataLayer = [];
    }
}

// ─── Meta Pixel Initialization ───────────────────────────────────────────

function initMetaPixel(analytics) {
    // Meta Pixel may already be loaded via Blade middleware; avoid double-init
    if (typeof window !== 'undefined' && window.fbq) {
        return;
    }

    const pixelId = analytics.metaPixelId;

    // eslint-disable-next-line
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s);}(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

    window.fbq('init', pixelId);
    window.fbq('track', 'PageView');
}

// ─── Plausible Initialization ───────────────────────────────────────────

function initPlausible() {
    // Plausible is primarily server-side; client script may be loaded via Blade
    // No additional client init needed — server handles event dispatch
}

// ─── PostHog Initialization ─────────────────────────────────────────────

function initPostHog() {
    // PostHog client script may be loaded via Blade middleware
    // Server-side capture handles the heavy lifting
}

// ─── Event Tracking ──────────────────────────────────────────────────────

/**
 * Track a custom event to the server-side API.
 *
 * Events are queued and flushed in batches for performance.
 * Set `immediate: true` to bypass batching.
 *
 * @param {string} name - Event name (e.g. 'button_click', 'tutorial_completed')
 * @param {object} params - Event parameters
 * @param {object} [options] - Additional options
 * @param {boolean} [options.immediate=false] - Bypass batch queue
 * @returns {Promise<void>}
 *
 * @example
 * await trackEvent('button_click', { element: 'buy_now', page: '/products' });
 */
export async function trackEvent(name, params = {}, options = {}) {
    if (!initialized) return;

    // Auto-attach UTM parameters if captured
    const enrichedParams = { ...params };
    if (Object.keys(utmParams).length > 0) {
        // Only attach UTM if not already present in params
        for (const [key, value] of Object.entries(utmParams)) {
            if (!(key in enrichedParams)) {
                enrichedParams[key] = value;
            }
        }
    }

    const event = { name, params: enrichedParams };

    if (options.immediate) {
        return sendEvent(event);
    }

    eventQueue.push(event);

    if (eventQueue.length >= MAX_QUEUE_SIZE) {
        await flushQueue();
    }
}

/**
 * Flush the batch event queue.
 *
 * Sends all queued events to the server in a single batch request.
 * @returns {Promise<void>}
 */
export async function flushQueue() {
    if (eventQueue.length === 0) return;

    const events = [...eventQueue];
    eventQueue.length = 0;

    try {
        await fetch('/api/analytics/batch', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({ events }),
        });
    } catch {
        // Re-queue failed events (up to max size)
        for (const event of events) {
            if (eventQueue.length < MAX_QUEUE_SIZE) {
                eventQueue.push(event);
            }
        }
    }
}

/**
 * Start the automatic flush timer.
 */
function startFlushTimer() {
    if (flushTimer) clearInterval(flushTimer);
    flushTimer = setInterval(() => flushQueue(), FLUSH_INTERVAL);
}

/**
 * Send a single event directly (not batched).
 * @param {object} event
 * @returns {Promise<void>}
 */
async function sendEvent(event) {
    try {
        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({ name: event.name, params: event.params }),
        });
    } catch {
        // Silent fail — don't break the UI for analytics
    }
}

/**
 * Track a page view event.
 *
 * @param {string} title - Page title
 * @param {string} location - Full URL
 * @param {string} referrer - Referrer URL
 * @returns {Promise<void>}
 */
export async function trackPageView(title = '', location = '', referrer = '') {
    if (!initialized) return;

    const params = {
        page_title: title || document.title,
        page_location: location || window.location.href,
        page_referrer: referrer || document.referrer,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'page_view', params);
    }

    // Push to Meta Pixel
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'PageView');
    }

    // Push to Plausible
    if (config?.plausibleDomain && typeof window.plausible === 'function') {
        window.plausible('pageview');
    }

    // Push to PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$pageview', params);
    }

    // Also send server-side for server-side providers
    await trackEvent('page_view', params);
}

/**
 * Track a screen view event (for multi-page / SPA navigation).
 *
 * Use this to track navigation between distinct screens within a
 * single-page app (e.g. "Dashboard", "Settings", "Billing").
 * Complements page_view which tracks URL-based navigation.
 *
 * @param {string} screenName - Screen or view name
 * @param {object} [options] - Additional options
 * @param {string} [options.screenClass] - Optional screen class/type
 * @param {object} [options.params] - Additional parameters
 * @returns {Promise<void>}
 *
 * @example
 * await trackScreenView('Dashboard', { screenClass: 'main' });
 * await trackScreenView('Settings', { params: { tab: 'billing' } });
 */
export async function trackScreenView(screenName, options = {}) {
    if (!initialized) return;

    const params = {
        screen_name: screenName,
        screen_class: options.screenClass || null,
        ...options.params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'screen_view', params);
    }

    // Push to PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$screenview', params);
    }

    // Server-side dispatch
    await trackEvent('screen_view', params, { immediate: true });
}

/**
 * Track an A/B test exposure event.
 *
 * @param {string} experimentId - The experiment identifier
 * @param {string} variantId - The variant assigned to this user
 * @param {object} [params] - Additional parameters
 * @returns {Promise<void>}
 *
 * @example
 * await trackAbTestExposure('pricing_redesign_v2', 'variant_a', { experiment_name: 'Pricing Redesign' });
 */
export async function trackAbTestExposure(experimentId, variantId, params = {}) {
    if (!initialized) return;

    const enrichedParams = {
        experiment_id: experimentId,
        variant_id: variantId,
        ...params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'ab_test_exposure', enrichedParams);
    }

    // Push to PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$feature_flag_called', {
            $feature_flag: experimentId,
            $feature_flag_variant: variantId,
            ...params,
        });
    }

    // Server-side dispatch
    await trackEvent('ab_test_exposure', enrichedParams, { immediate: true });
}

/**
 * Track an e-commerce event.
 *
 * Formats the event name and data for both GA4 and Meta conventions.
 *
 * @param {string} name - Event name (e.g. 'purchase', 'add_to_cart', 'begin_checkout')
 * @param {object} data - Event data
 * @param {Array} [data.items] - Array of item objects
 * @param {string} [data.transaction_id] - Transaction ID (for purchase/refund)
 * @param {number} [data.value] - Revenue value
 * @param {string} [data.currency] - Currency code (ISO 4217)
 * @param {string} [data.coupon] - Coupon code
 * @returns {Promise<void>}
 *
 * @example
 * await trackEcommerce('purchase', {
 *     transaction_id: 'TXN-12345',
 *     value: 99.99,
 *     currency: 'USD',
 *     items: [{ item_id: 'SKU-001', item_name: 'Widget', price: 49.99, quantity: 2 }],
 * });
 */
export async function trackEcommerce(name, data = {}) {
    if (!initialized) return;

    // GA4 client-side push
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', name, data);
    }

    // Meta Pixel equivalent events
    if (config?.metaPixelId && window.fbq) {
        const metaEvent = mapToMetaEvent(name);
        if (metaEvent) {
            const metaParams = mapToMetaParams(name, data);
            window.fbq('track', metaEvent, metaParams);
        }
    }

    // Server-side dispatch (immediate — don't batch ecommerce)
    await trackEvent(name, data, { immediate: true });
}

/**
 * Map GA4 e-commerce event names to Meta Pixel equivalents.
 */
function mapToMetaEvent(name) {
    const mapping = {
        view_item: 'ViewContent',
        add_to_cart: 'AddToCart',
        remove_from_cart: null, // No Meta equivalent
        begin_checkout: 'InitiateCheckout',
        add_payment_info: 'AddPaymentInfo',
        purchase: 'Purchase',
        refund: null, // No standard Meta equivalent
        view_cart: null, // No Meta equivalent
    };

    return mapping[name] || null;
}

/**
 * Map GA4 e-commerce params to Meta Pixel params.
 */
function mapToMetaParams(name, data) {
    const params = { value: data.value, currency: data.currency || 'USD' };

    if (data.items?.length > 0) {
        params.contents = data.items.map((item) => ({
            id: item.item_id,
            quantity: item.quantity || 1,
            item_price: item.price,
            name: item.item_name,
            category: item.item_category,
        }));
    }

    if (name === 'purchase' && data.transaction_id) {
        params.content_ids = data.items?.map((i) => i.item_id) || [];
    }

    return params;
}

/**
 * Track a wishlist event (add to wishlist).
 *
 * Maps to GA4 'add_to_wishlist' and Meta 'AddToWishlist'.
 *
 * @param {object} item - Item data
 * @param {string} item.item_id - Item ID/SKU
 * @param {string} [item.item_name] - Item name
 * @param {string} [item.item_category] - Item category
 * @param {number} [item.price] - Item price
 * @param {string} [item.currency] - Currency code
 * @returns {Promise<void>}
 *
 * @example
 * await trackWishlist({
 *     item_id: 'SKU-001',
 *     item_name: 'Widget',
 *     item_category: 'Gadgets',
 *     price: 49.99,
 * });
 */
export async function trackWishlist(item) {
    if (!initialized) return;

    const params = {
        item_id: item.item_id,
        item_name: item.item_name || null,
        item_category: item.item_category || null,
        price: item.price || null,
        currency: item.currency || 'USD',
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'add_to_wishlist', params);
    }

    // Push to Meta Pixel
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'AddToWishlist', {
            content_ids: [item.item_id],
            content_name: item.item_name,
            content_type: 'product',
            currency: item.currency || 'USD',
            value: item.price || 0,
        });
    }

    // Server-side dispatch
    await trackEvent('add_to_wishlist', params, { immediate: true });
}

/**
 * Identify a user (link client ↔ user identity).
 *
 * Call this after login/register to associate the client tracking ID
 * with the authenticated user ID across all analytics providers.
 *
 * @param {string|null} [userId] - Authenticated user ID (null = use current)
 * @returns {Promise<void>}
 */
export async function identify(userId = null) {
    if (!initialized) return;

    try {
        await fetch('/api/analytics/identify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({ client_id: trackingId }),
        });
    } catch {
        // Silent fail
    }
}

/**
 * Update consent signals (GDPR).
 *
 * @param {object} signals - Consent signals
 * @param {'granted'|'denied'} signals.analytics_storage
 * @param {'granted'|'denied'} signals.ad_storage
 * @param {'granted'|'denied'} signals.ad_user_data
 * @param {'granted'|'denied'} signals.ad_personalization
 * @param {'granted'|'denied'} signals.functionality_storage
 * @param {'granted'|'denied'} signals.security_storage
 * @returns {Promise<void>}
 *
 * @example
 * updateConsent({
 *     analytics_storage: 'granted',
 *     ad_storage: 'denied',
 * });
 */
export async function updateConsent(signals) {
    if (!initialized) return;

    // Update GA4 consent client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('consent', 'update', signals);
    }

    // Update server-side consent
    try {
        await fetch('/api/analytics/consent', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({ signals }),
        });
    } catch {
        // Silent fail
    }
}

// ─── Scroll Depth Tracker ───────────────────────────────────────────────

/**
 * Initialize scroll depth tracking.
 *
 * Fires scroll_depth events at 25%, 50%, 75%, and 90% thresholds.
 * Each threshold fires at most once per page view.
 *
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initScrollDepth();
 * // On page navigation:
 * cleanup();
 */
export function initScrollDepth() {
    if (!initialized) return () => {};

    const thresholds = [25, 50, 75, 90];
    const fired = new Set();

    function onScroll() {
        const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
        if (scrollHeight <= 0) return;

        const percentage = Math.round((window.scrollY / scrollHeight) * 100);

        for (const threshold of thresholds) {
            if (percentage >= threshold && !fired.has(threshold)) {
                fired.add(threshold);
                trackEvent('scroll_depth', {
                    percent: threshold,
                    page_location: window.location.href,
                });
            }
        }

        // Cleanup when all thresholds are hit
        if (thresholds.every((t) => fired.has(t))) {
            window.removeEventListener('scroll', onScroll, { passive: true });
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });

    return () => window.removeEventListener('scroll', onScroll, { passive: true });
}

// ─── Inertia Page View Tracker ───────────────────────────────────────────

/**
 * Initialize automatic page view tracking for Inertia.js.
 *
 * Listens to Inertia navigation events and auto-tracks page_view
 * on every successful navigation.
 *
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * // In your root Svelte component:
 * import { initInertiaPageViewTracker } from '@zeroboiler/analytics';
 * onMount(() => initInertiaPageViewTracker());
 */
export function initInertiaPageViewTracker() {
    if (!initialized) return () => {};

    // Inertia emits 'navigate' events on the page when navigating
    // This works with @inertiajs/core >= 1.0
    const handler = (event) => {
        // Only track on complete navigation (not on partial/failed)
        if (event.detail?.visit) {
            trackPageView(
                event.detail.page?.title || document.title,
                window.location.href,
                document.referrer,
            );
        }
    };

    document.addEventListener('inertia:navigate', handler);

    return () => document.removeEventListener('inertia:navigate', handler);
}

// ─── Auto Form Tracking ────────────────────────────────────────────────

/**
 * Initialize automatic form interaction tracking.
 *
 * Tracks form_start when a user interacts with a form and form_submit on submit.
 * Supports forms with data-analytics-form attribute for custom form names.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackStart=true] - Track form_start events
 * @param {boolean} [options.trackSubmit=true] - Track form_submit events
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initFormTracking();
 *
 * // In HTML:
 * // <form data-analytics-form="contact" ...>
 */
export function initFormTracking(options = {}) {
    if (!initialized) return () => {};

    const { trackStart = true, trackSubmit = true } = options;
    const trackedForms = new WeakSet();

    function onFormInteract(e) {
        const form = e.target.closest('form');
        if (!form || trackedForms.has(form)) return;

        const formName = form.dataset.analyticsForm || form.id || form.action || 'unknown';
        trackedForms.add(form);

        if (trackStart) {
            trackEvent('form_start', {
                form_name: formName,
                form_id: form.id || null,
                form_action: form.action || null,
                page_location: window.location.href,
            });
        }
    }

    function onFormSubmit(e) {
        const form = e.target.closest('form');
        if (!form) return;

        const formName = form.dataset.analyticsForm || form.id || form.action || 'unknown';

        if (trackSubmit) {
            trackEvent('form_submit', {
                form_name: formName,
                form_id: form.id || null,
                form_method: form.method?.toUpperCase() || 'GET',
                page_location: window.location.href,
            }, { immediate: true });
        }
    }

    document.addEventListener('focusin', onFormInteract, true);
    document.addEventListener('submit', onFormSubmit, true);

    return () => {
        document.removeEventListener('focusin', onFormInteract, true);
        document.removeEventListener('submit', onFormSubmit, true);
    };
}

// ─── Auto Error Tracking ──────────────────────────────────────────────

/**
 * Initialize automatic JavaScript error tracking.
 *
 * Captures unhandled errors and unhandled promise rejections.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackErrors=true] - Track JS errors
 * @param {boolean} [options.trackRejections=true] - Track unhandled promise rejections
 * @param {string[]} [options.ignorePatterns] - Regex patterns for errors to ignore
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initErrorTracking({
 *     ignorePatterns: ['ResizeObserver', 'Non-Error promise rejection'],
 * });
 */
export function initErrorTracking(options = {}) {
    if (!initialized) return () => {};

    const {
        trackErrors = true,
        trackRejections = true,
        ignorePatterns = [],
    } = options;

    function shouldIgnore(message) {
        if (!message) return false;
        return ignorePatterns.some((pattern) => new RegExp(pattern).test(message));
    }

    function onError(event) {
        if (!trackErrors) return;

        const message = event.message || event.error?.message || 'Unknown error';
        if (shouldIgnore(message)) return;

        trackEvent('js_error', {
            error_message: message,
            error_source: event.filename || null,
            error_line: event.lineno || null,
            error_col: event.colno || null,
            page_location: window.location.href,
        }, { immediate: true });
    }

    function onUnhandledRejection(event) {
        if (!trackRejections) return;

        const message = event.reason?.message || String(event.reason) || 'Unhandled rejection';
        if (shouldIgnore(message)) return;

        trackEvent('js_error', {
            error_message: message,
            error_type: 'unhandled_rejection',
            page_location: window.location.href,
        }, { immediate: true });
    }

    window.addEventListener('error', onError);
    window.addEventListener('unhandledrejection', onUnhandledRejection);

    return () => {
        window.removeEventListener('error', onError);
        window.removeEventListener('unhandledrejection', onUnhandledRejection);
    };
}

// ─── Performance Tracking ──────────────────────────────────────────────

/**
 * Track a Web Vitals metric.
 *
 * @param {string} metricName - Metric name (e.g. 'LCP', 'FID', 'CLS', 'INP')
 * @param {number} value - Metric value
 * @param {object} [params] - Additional params
 *
 * @example
 * // Use with web-vitals library:
 * import { onLCP, onFID, onCLS, onINP } from 'web-vitals';
 * onLCP(metric => trackPerformance('LCP', metric.value));
 * onFID(metric => trackPerformance('FID', metric.value));
 * onCLS(metric => trackPerformance('CLS', metric.value, { rating: metric.rating }));
 */
export async function trackPerformance(metricName, value, params = {}) {
    if (!initialized) return;

    await trackEvent('web_vitals', {
        metric_name: metricName,
        metric_value: Math.round(value * 100) / 100,
        page_location: window.location.href,
        ...params,
    });
}

/**
 * Track a timing event using the Performance API.
 *
 * @param {string} name - Timing name
 * @returns {void}
 */
export function trackTiming(name) {
    if (!initialized || typeof performance === 'undefined') return;

    const startMark = `zb_${name}_start`;

    performance.mark(startMark);

    return () => {
        const endMark = `zb_${name}_end`;
        performance.mark(endMark);

        try {
            performance.measure(name, startMark, endMark);
            const entries = performance.getEntriesByName(name);
            const duration = entries[entries.length - 1]?.duration || 0;

            trackEvent('timing', {
                timing_name: name,
                timing_duration_ms: Math.round(duration),
                page_location: window.location.href,
            });

            performance.clearMarks(startMark);
            performance.clearMarks(endMark);
            performance.clearMeasures(name);
        } catch {
            // Performance API not supported
        }
    };
}

// ─── GTM Data Layer Push ─────────────────────────────────────────────────

/**
 * Push data to the GTM dataLayer (client-side).
 *
 * @param {object} data - Data to push
 *
 * @example
 * pushToDataLayer({ event: 'custom_event', category: 'engagement' });
 */
export function pushToDataLayer(data) {
    if (typeof window === 'undefined') return;

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(data);
}

// ─── Event Catalog ───────────────────────────────────────────────────

let cachedCatalog = null;

/**
 * Fetch the event catalog from the server.
 *
 * Returns the full event catalog with names, categories, and
 * cross-provider mappings. Results are cached for the session.
 *
 * @param {object} [options] - Fetch options
 * @param {boolean} [options.forceRefresh=false] - Bypass cache
 * @returns {Promise<object|null>} Catalog data or null on failure
 *
 * @example
 * const catalog = await fetchEventCatalog();
 * if (catalog) {
 *     console.log('Available events:', catalog.names);
 * }
 */
export async function fetchEventCatalog(options = {}) {
    if (!initialized) return null;

    if (!options.forceRefresh && cachedCatalog) {
        return cachedCatalog;
    }

    try {
        const baseUrl = config?.apiBase || '/api/analytics';
        const response = await fetch(`${baseUrl}/catalog`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        cachedCatalog = await response.json();

        return cachedCatalog;
    } catch {
        return null;
    }
}

/**
 * Get the cached event catalog (without fetching).
 *
 * @returns {object|null} Cached catalog or null
 */
export function getCachedCatalog() {
    return cachedCatalog;
}

/**
 * Clear the cached event catalog.
 */
export function clearCatalogCache() {
    cachedCatalog = null;
}

// ─── Helpers ────────────────────────────────────────────────────────────

/**
 * Get the authentication token from localStorage/cookie.
 * Adapt this to match your auth token storage strategy.
 */
function getAuthToken() {
    // Sanctum SPA auth typically uses localStorage or a meta tag
    if (typeof window === 'undefined') return '';

    // Check for token in common storage locations
    const token =
        localStorage.getItem('auth_token') ||
        localStorage.getItem('access_token') ||
        getCookie('auth_token') ||
        getMetaContent('csrf-token') ||
        '';

    return token;
}

/**
 * Get a cookie value by name.
 */
function getCookie(name) {
    if (typeof document === 'undefined') return '';

    const match = document.cookie.match(new RegExp(`(^| )${name}=([^;]+)`));
    return match ? match[2] : '';
}

/**
 * Get content of a meta tag by name.
 */
function getMetaContent(name) {
    if (typeof document === 'undefined') return '';

    const meta = document.querySelector(`meta[name="${name}"]`);
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Generate a UUID v4 for client-side identifiers.
 * @returns {string}
 */
function generateUUID() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;

        return v.toString(16);
    });
}

// ─── UTM Campaign Tracking ───────────────────────────────────────────

let utmParams = {};
let utmCaptured = false;

/**
 * Capture UTM parameters from the current URL.
 *
 * Call this once on app initialization to capture attribution data.
 * Parameters are automatically attached to all tracked events.
 *
 * @returns {object} Captured UTM parameters
 *
 * @example
 * // Auto-capture on init:
 * const utm = captureUTM();
 * if (utm.utm_source) {
 *     trackEvent('campaign_visit', utm);
 * }
 */
export function captureUTM() {
    if (utmCaptured || typeof window === 'undefined') return utmParams;

    const urlParams = new URLSearchParams(window.location.search);

    const utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    for (const key of utmKeys) {
        const value = urlParams.get(key);
        if (value) {
            utmParams[key] = value;
        }
    }

    if (Object.keys(utmParams).length > 0) {
        utmCaptured = true;
        // Store in sessionStorage for page navigation persistence
        try {
            sessionStorage.setItem('zb_utm', JSON.stringify(utmParams));
        } catch {
            // Storage not available
        }
    } else {
        // Restore from sessionStorage if available
        try {
            const stored = sessionStorage.getItem('zb_utm');
            if (stored) {
                utmParams = JSON.parse(stored);
                utmCaptured = true;
            }
        } catch {
            // Storage not available
        }
    }

    return utmParams;
}

/**
 * Get currently captured UTM parameters.
 *
 * @returns {object} UTM parameters object
 */
export function getUTMParams() {
    return utmParams;
}

/**
 * Clear captured UTM parameters.
 */
export function clearUTMParams() {
    utmParams = {};
    utmCaptured = false;

    try {
        sessionStorage.removeItem('zb_utm');
    } catch {
        // Storage not available
    }
}

/**
 * Check if UTM parameters were captured.
 */
export function hasUTMParams() {
    return Object.keys(utmParams).length > 0;
}

// ─── Link Click Tracking ─────────────────────────────────────────────

/**
 * Initialize automatic link click tracking.
 *
 * Tracks clicks on `<a>` tags with meaningful context (URL, text, link type).
 * Supports data-analytics-link attribute for custom link names.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackExternal=true] - Track external (cross-origin) links
 * @param {boolean} [options.trackInternal=false] - Track internal (same-origin) links
 * @param {string} [options.externalPrefix='outbound'] - Prefix for external link events
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initLinkTracking({ trackExternal: true, trackInternal: false });
 *
 * // In HTML:
 * // <a href="https://docs.example.com" data-analytics-link="docs_link">Documentation</a>
 */
export function initLinkTracking(options = {}) {
    if (!initialized) return () => {};

    const {
        trackExternal = true,
        trackInternal = false,
        externalPrefix = 'outbound',
    } = options;

    function onClick(e) {
        const link = e.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href') || '';
        const isExternal = href.startsWith('http') && !href.includes(window.location.hostname);

        if (isExternal && !trackExternal) return;
        if (!isExternal && !trackInternal) return;

        const linkName = link.dataset.analyticsLink || link.textContent?.trim()?.slice(0, 100) || href;

        trackEvent(isExternal ? `${externalPrefix}_click` : 'internal_click', {
            link_name: linkName,
            link_url: href.slice(0, 2048),
            link_type: isExternal ? 'external' : 'internal',
            link_text: link.textContent?.trim()?.slice(0, 200) || null,
            page_location: window.location.href,
        });
    }

    document.addEventListener('click', onClick, true);

    return () => document.removeEventListener('click', onClick, true);
}

// ─── User Properties & Alias ─────────────────────────────────────────

/**
 * Set user properties / traits on the authenticated user.
 *
 * Sends user traits (name, email, plan, etc.) to the server which
 * propagates them to all analytics providers (PostHog $set, GA4 user properties).
 *
 * @param {object} properties - User traits
 * @param {string|null} [userId] - Optional user ID override
 * @returns {Promise<void>}
 *
 * @example
 * await setUserProperties({ name: 'John', email: 'john@example.com', plan: 'pro' });
 */
export async function setUserProperties(properties, userId = null) {
    if (!initialized) return;

    try {
        const body = { properties };
        if (userId) body.user_id = userId;

        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: *** ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({
                name: 'set_user_properties',
                params: body,
            }),
        });
    } catch {
        // Silent fail
    }
}

/**
 * Alias one identity to another (merge anonymous → authenticated).
 *
 * Call this after signup to merge the anonymous session profile
 * with the newly authenticated user profile.
 *
 * @param {string} previousId - Previous identifier (e.g. anonymous tracking ID)
 * @param {string} newId - New identifier (e.g. authenticated user ID)
 * @returns {Promise<void>}
 *
 * @example
 * await alias(trackingId, authenticatedUserId);
 */
export async function alias(previousId, newId) {
    if (!initialized) return;

    await trackEvent('alias', {
        previous_id: previousId,
        new_id: newId,
    }, { immediate: true });
}

/**
 * Identify the current user and optionally set user traits.
 *
 * Convenience wrapper that calls /api/analytics/identify with
 * client ID and optional user traits for enriched profiles.
 *
 * @param {object} [traits] - User traits to set alongside identification
 * @returns {Promise<void>}
 *
 * @example
 * await identifyWithTraits({ name: 'John', plan: 'pro' });
 */
export async function identifyWithTraits(traits = {}) {
    if (!initialized) return;

    try {
        const body = { client_id: trackingId };
        if (Object.keys(traits).length > 0) {
            body.traits = traits;
        }

        await fetch('/api/analytics/identify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: *** ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });
    } catch {
        // Silent fail
    }
}

// ─── Server-Side Page View ────────────────────────────────────────────

/**
 * Track a page view via the server-side API endpoint.
 *
 * Unlike trackPageView() which pushes to client-side providers,
 * this sends the page view to the server for server-side processing
 * (useful when client-side scripts are blocked by ad blockers).
 *
 * @param {object} [options] - Page view options
 * @param {string} [options.title] - Page title
 * @param {string} [options.location] - Page URL
 * @param {string} [options.referrer] - Referrer URL
 * @returns {Promise<void>}
 *
 * @example
 * await trackServerPageView({ title: 'Pricing', location: '/pricing' });
 */
export async function trackServerPageView(options = {}) {
    if (!initialized) return;

    try {
        await fetch('/api/analytics/pageview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: *** ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({
                title: options.title || null,
                location: options.location || window.location.href,
                referrer: options.referrer || document.referrer,
            }),
        });
    } catch {
        // Silent fail
    }
}
