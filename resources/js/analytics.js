/**
 * ZeroBoiler Analytics — Client-Side Library
 *
 * Client-side analytics for Svelte/Inertia/Laravel apps.
 * Reads configuration from Inertia page props (zbAnalytics) and provides
 * a unified API for tracking events across GA4, GTM, Meta Pixel, Plausible, and PostHog.
 *
 * @package ZeroBoiler\Analytics
 * @version 1.0.0
 */

let trackingId = null;
let config = null;
let initialized = false;

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
 * Events are dispatched via POST to /api/analytics/events, which then
 * fans out to all configured server-side providers (GA4 MP, Meta CAPI, etc.).
 *
 * @param {string} name - Event name (e.g. 'button_click', 'tutorial_completed')
 * @param {object} params - Event parameters
 * @returns {Promise<void>}
 *
 * @example
 * await trackEvent('button_click', { element: 'buy_now', page: '/products' });
 */
export async function trackEvent(name, params = {}) {
    if (!initialized) return;

    try {
        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                Authorization: `Bearer ${getAuthToken()}`,
                Accept: 'application/json',
            },
            body: JSON.stringify({ name, params }),
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

    // Also send server-side for server-side providers
    await trackEvent('page_view', params);
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

    // Server-side dispatch
    await trackEvent(name, data);
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
                Authorization: `Bearer ${getAuthToken()}`,
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
                Authorization: `Bearer ${getAuthToken()}`,
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
