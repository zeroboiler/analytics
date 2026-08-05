/**
 * ZeroBoiler Analytics — Client-side library for Svelte/Inertia apps.
 *
 * Reads config from Inertia page props (zbAnalytics) and provides
 * a clean API for tracking events, page views, and ecommerce actions.
 *
 * @module zeroboiler-analytics
 */

let analytics = null;
let trackingId = null;
let initialized = false;
let authToken = null;
const scrollMilestones = [25, 50, 75, 90];
const firedScrollMilestones = new Set();

/**
 * Get the auth token from localStorage (for Sanctum SPA).
 */
function getAuthToken() {
    if (!authToken) {
        try {
            const user = JSON.parse(localStorage.getItem('user') || '{}');
            authToken = user?.token || null;
        } catch {
            authToken = null;
        }
    }
    return authToken;
}

/**
 * Common headers for API requests.
 */
function apiHeaders() {
    const headers = {
        'Content-Type': 'application/json',
    };
    if (trackingId) {
        headers['X-Analytics-Client-Id'] = trackingId;
    }
    const token = getAuthToken();
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }
    return headers;
}

/**
 * Initialize the analytics client.
 *
 * Call this once when your app boots, passing the Inertia page props.
 *
 * @param {object} pageProps - Inertia page.props object
 * @param {object} [options] - Additional options
 * @param {Function} [options.getToken] - Custom function to retrieve auth token
 */
export function init(pageProps, options = {}) {
    if (initialized) return;

    analytics = pageProps?.zbAnalytics || pageProps?.props?.zbAnalytics;
    if (!analytics?.enabled) return;

    trackingId = analytics.trackingId;

    if (options.getToken) {
        authToken = options.getToken();
    }

    // Initialize GA4 gtag
    if (analytics.ga4MeasurementId) {
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', analytics.ga4MeasurementId, {
            send_page_view: false, // We handle page views manually
        });
        window.zbGtag = gtag;
    }

    // Initialize GTM (dataLayer already set by server-side scripts)
    // GTM handles its own initialization via the container snippet

    // Initialize Meta Pixel
    if (analytics.metaPixelId) {
        window.fbq = window.fbq || function () {
            window.fbq.callMethod
                ? window.fbq.callMethod.apply(window.fbq, arguments)
                : window.fbq.queue.push(arguments);
        };
        window.fbq.queue = [];
        window.fbq('init', analytics.metaPixelId);
        window.fbq('track', 'PageView');
        // Load pixel script
        const script = document.createElement('script');
        script.async = true;
        script.src = `https://connect.facebook.net/en_US/fbevents.js`;
        document.head.appendChild(script);
    }

    // Fire initial page view
    trackPageView();

    initialized = true;
}

/**
 * Track a custom event via server-side API + client-side providers.
 *
 * @param {string} name - Event name
 * @param {object} [params={}] - Event parameters
 */
export async function trackEvent(name, params = {}) {
    if (!analytics?.enabled) return;

    // Client-side GA4
    if (analytics.ga4MeasurementId && window.zbGtag) {
        const ga4Params = { ...params };
        if (analytics.userId) ga4Params.user_id = analytics.userId;
        window.zbGtag('event', name, ga4Params);
    }

    // Client-side Meta Pixel
    if (analytics.metaPixelId && window.fbq) {
        window.fbq('trackCustom', name, params);
    }

    // Server-side API (fire-and-forget)
    try {
        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: apiHeaders(),
            body: JSON.stringify({ name, params }),
        });
    } catch {
        // Silently fail — analytics should never break the app
    }
}

/**
 * Track a page view event.
 */
export async function trackPageView() {
    if (!analytics?.enabled) return;

    const params = {
        page_title: document.title,
        page_location: window.location.href,
        page_referrer: document.referrer,
    };

    // Client-side GA4
    if (analytics.ga4MeasurementId && window.zbGtag) {
        window.zbGtag('event', 'page_view', params);
    }

    // Server-side API
    try {
        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: apiHeaders(),
            body: JSON.stringify({ name: 'page_view', params }),
        });
    } catch {
        // Silent
    }
}

/**
 * Track an ecommerce event (GA4 + Meta Pixel formats).
 *
 * @param {string} name - Event name (e.g. 'purchase', 'add_to_cart')
 * @param {object} data - Ecommerce data
 * @param {Array} [data.items] - Line items [{item_id, item_name, price, quantity}]
 * @param {number} [data.value] - Transaction value
 * @param {string} [data.currency='USD'] - Currency code
 * @param {object} [data.meta] - Additional Meta Pixel-specific params
 */
export async function trackEcommerce(name, data = {}) {
    if (!analytics?.enabled) return;

    const ga4Params = { ...data };
    if (analytics.userId) ga4Params.user_id = analytics.userId;

    // Client-side GA4
    if (analytics.ga4MeasurementId && window.zbGtag) {
        window.zbGtag('event', name, ga4Params);
    }

    // Client-side Meta Pixel (different event name mapping)
    if (analytics.metaPixelId && window.fbq) {
        const metaMap = {
            add_to_cart: 'AddToCart',
            remove_from_cart: null, // No Meta equivalent
            begin_checkout: 'InitiateCheckout',
            add_payment_info: 'AddPaymentInfo',
            purchase: 'Purchase',
            view_item: 'ViewContent',
            view_cart: null,
        };
        const metaName = metaMap[name] || data.meta?.event_name || name;
        if (metaName) {
            const metaParams = {
                value: data.value,
                currency: data.currency || 'USD',
                contents: (data.items || []).map(item => ({
                    id: item.item_id,
                    quantity: item.quantity || 1,
                    item_price: item.price,
                })),
            };
            window.fbq('track', metaName, metaParams);
        }
    }

    // Server-side API
    try {
        await fetch('/api/analytics/events', {
            method: 'POST',
            headers: apiHeaders(),
            body: JSON.stringify({ name, params: data }),
        });
    } catch {
        // Silent
    }
}

/**
 * Link a client ID to an authenticated user.
 *
 * @param {string} userId
 */
export async function identify(userId) {
    if (!analytics?.enabled) return;

    try {
        await fetch('/api/analytics/identify', {
            method: 'POST',
            headers: apiHeaders(),
            body: JSON.stringify({ client_id: trackingId, user_id: userId }),
        });
    } catch {
        // Silent
    }
}

/**
 * Update consent state from the frontend.
 *
 * @param {object} signals - { analytics_storage: 'granted', ad_storage: 'denied', ... }
 */
export function updateConsent(signals) {
    if (!analytics?.enabled) return;

    // Update GA4 Consent Mode v2
    if (analytics.ga4MeasurementId && window.zbGtag) {
        window.zbGtag('consent', 'update', signals);
    }

    // Send to server-side
    try {
        fetch('/api/analytics/consent', {
            method: 'POST',
            headers: apiHeaders(),
            body: JSON.stringify({ signals }),
        });
    } catch {
        // Silent
    }
}

/**
 * Initialize scroll depth tracking.
 * Fires events at 25%, 50%, 75%, 90% thresholds.
 */
export function initScrollDepth() {
    if (!analytics?.enabled) return;

    window.addEventListener('scroll', () => {
        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        if (docHeight <= 0) return;

        const percent = Math.round((scrollTop / docHeight) * 100);

        for (const milestone of scrollMilestones) {
            if (percent >= milestone && !firedScrollMilestones.has(milestone)) {
                firedScrollMilestones.add(milestone);
                trackEvent('scroll_depth', {
                    percent: milestone,
                    page_path: window.location.pathname,
                    page_title: document.title,
                });
            }
        }
    }, { passive: true });
}

/**
 * Auto-track page views on Inertia navigation.
 * Call this once after init() in your Svelte app's root.
 */
export function initInertiaPageViewTracker() {
    if (!analytics?.enabled) return;

    // For Inertia.js — listen to the navigate event
    if (window.Inertia) {
        window.Inertia.on('navigate', () => {
            // Reset scroll milestones on navigation
            firedScrollMilestones.clear();
            // Small delay to let the new page render
            setTimeout(() => trackPageView(), 100);
        });
    }
}

/**
 * Check if analytics is currently enabled.
 */
export function isEnabled() {
    return analytics?.enabled || false;
}

/**
 * Get the current tracking ID.
 */
export function getTrackingId() {
    return trackingId;
}
