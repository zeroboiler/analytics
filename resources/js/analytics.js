/**
 * ZeroBoiler Analytics — Client-Side Library
 *
 * Client-side analytics for Svelte/Inertia/Laravel apps.
 * Reads configuration from Inertia page props (zbAnalytics) and provides
 * a unified API for tracking events across GA4, GTM, Meta Pixel, Plausible, and PostHog.
 *
 * @package ZeroBoiler Analytics
 * @version 15.0.0
 */

let trackingId = null;
let config = null;
let initialized = false;
let apiBaseUrl = '/api/analytics';
let consentResolved = null; // null = not yet resolved, true = granted, false = denied

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
    apiBaseUrl = analytics.apiBase || '/api/analytics';
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

    // Initialize Amplitude
    if (analytics.amplitudeApiKey) {
        initAmplitude(analytics);
    }

    // Initialize Mixpanel
    if (analytics.mixpanelToken) {
        initMixpanel(analytics);
    }

    // Start batch flush timer
    startFlushTimer();

    // Flush pending events on page unload (prevents data loss on navigation)
    window.addEventListener('beforeunload', flushPendingOnUnload);

    // Auto-capture UTM parameters on init
    captureUTM();

    // Persist first-touch UTM to cookie (cross-session attribution)
    persistFirstTouchUTM(utmParams);

    // Auto-identify: link client ID to authenticated user when identityAutoLink is enabled
    if (analytics.identityAutoLink && analytics.userId && trackingId) {
        autoIdentify(analytics.userId, trackingId);
    }

    // Auth state change detection (v6.9.0) — stitch client ID to new user on login/logout
    if (analytics.authStateChanged && analytics.userId && trackingId) {
        // User just logged in — fire identify to link client_id ↔ user_id
        autoIdentify(analytics.userId, trackingId);
        // Also track the login event for lifecycle continuity
        trackEvent('login', {
            user_id: analytics.userId,
            auth_state_change: true,
            previous_user_id: analytics.previousUserId || null,
        }, { immediate: true });
    }
}

/**
 * Flush pending events on page unload using sendBeacon.
 *
 * Uses navigator.sendBeacon() for reliable delivery even during page unload.
 * Falls back to synchronous fetch when sendBeacon is unavailable.
 */
function flushPendingOnUnload() {
    if (eventQueue.length === 0) return;

    const events = [...eventQueue];
    eventQueue.length = 0;

    const payload = JSON.stringify({ events });

    // Use sendBeacon for reliable unload delivery
    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon(
            `${apiBaseUrl}/batch`,
            blob,
        );
    }
}

/**
 * Cleanup analytics listeners and timers.
 * Call this when your component unmounts or on app teardown.
 */
export function destroy() {
    window.removeEventListener('beforeunload', flushPendingOnUnload);
    if (flushTimer) {
        clearInterval(flushTimer);
        flushTimer = null;
    }
    eventQueue.length = 0;
    initialized = false;
    config = null;
    trackingId = null;
    apiBaseUrl = '/api/analytics';
}

/**
 * Check if analytics is initialized.
 */
export function isInitialized() {
    return initialized;
}

/**
 * Get the library version string.
 *
 * Useful for diagnostics, debugging, and API compatibility checks.
 *
 * @returns {string} Semantic version (e.g. '4.2.0')
 */
export function getVersion() {
      return '15.0.0';
}

/**
 * Get the current tracking ID (server-generated, cookie-stored).
 */
export function getTrackingId() {
    return trackingId;
}

/**
 * Get the configured API base URL.
 */
export function getApiBaseUrl() {
    return apiBaseUrl;
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

// ─── Amplitude Initialization ────────────────────────────────────────────

function initAmplitude() {
    // Amplitude SDK may be loaded via Blade middleware headScripts()
    // If the global amplitude object is available, set user identity
    if (typeof window !== 'undefined' && window.amplitude) {
        if (trackingId) {
            window.amplitude.setDeviceId(trackingId);
        }
    }
}

// ─── Mixpanel Initialization ───────────────────────────────────────────

function initMixpanel() {
    // Mixpanel SDK may be loaded via Blade middleware headScripts()
    // If the global mixpanel object is available, register super properties
    if (typeof window !== 'undefined' && window.mixpanel) {
        if (trackingId) {
            window.mixpanel.register({ zb_client_id: trackingId });
        }
    }
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

// ─── Priority-Aware Tracking (v2.66.0) ─────────────────────────────

/**
 * Track an event with an explicit priority override.
 *
 * Adds a `_priority` param to the event payload so the server-side
 * EventPriorityGate can respect the client-specified priority.
 *
 * @param {string} name - Event name
 * @param {Record<string, unknown>} [params={}] - Event parameters
 * @param {'critical'|'normal'|'low'|'background'} [priority] - Priority level
 * @returns {Promise<boolean>}
 *
 * @example
 * // Send a critical revenue event
 * await trackEventWithPriority('payment_completed', { amount: 99.99 }, 'critical');
 */
export async function trackEventWithPriority(name, params = {}, priority = 'normal') {
    if (!initialized) return false;

    const validPriorities = ['critical', 'normal', 'low', 'background'];
    const safePriority = validPriorities.includes(priority) ? priority : 'normal';

    return trackEvent(name, { ...params, _priority: safePriority }, { immediate: safePriority === 'critical' });
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
        await fetch(`${apiBaseUrl}/batch`, {
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
 * Auto-identify: silently send an identify event to link client ID to user ID.
 *
 * Called on init when identityAutoLink is enabled and the user is authenticated.
 * Uses a fire-and-forget sendBeacon-style approach for zero-latency impact.
 *
 * @param {string} userId - Authenticated user ID
 * @param {string} clientId - Server-generated tracking ID
 */
function autoIdentify(userId, clientId) {
    if (!initialized || !apiBaseUrl) return;

    const payload = JSON.stringify({
        name: 'identify',
        params: { user_id: userId, client_id: clientId },
    });

    // Use sendBeacon for non-blocking identify on page load
    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon(`${apiBaseUrl}/events`, blob);
    } else {
        // Fallback to fetch (fire-and-forget)
        fetch(`${apiBaseUrl}/events`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': clientId,
                Accept: 'application/json',
            },
            body: payload,
            keepalive: true,
        }).catch(() => {});
    }
}

/**
 * Send a single event directly (not batched).
 * @param {object} event
 * @returns {Promise<void>}
 */
async function sendEvent(event) {
    try {
        await fetch(`${apiBaseUrl}/events`, {
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

    // Push to Amplitude
    if (config?.amplitudeApiKey && window.amplitude) {
        window.amplitude.logEvent('Page View', params);
    }

    // Push to Mixpanel
    if (config?.mixpanelToken && window.mixpanel) {
        window.mixpanel.track('$pageview', params);
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

    // Amplitude e-commerce event
    if (config?.amplitudeApiKey && window.amplitude) {
        window.amplitude.logEvent(name, data);
    }

    // Mixpanel e-commerce event
    if (config?.mixpanelToken && window.mixpanel) {
        window.mixpanel.track(name, data);
    }

    // Server-side dispatch (immediate — don't batch ecommerce)
    await trackEvent(name, data, { immediate: true });
}

/**
 * Track an event targeting specific providers only.
 *
 * Allows fine-grained control over which analytics providers receive
 * a specific event. Useful when certain events should only go to
 * specific providers (e.g., purchase events to GA4 + Meta but not Plausible).
 *
 * @param {string} name - Event name
 * @param {Record<string, unknown>} [params={}] - Event parameters
 * @param {string[]} providers - Provider names: ['ga4', 'meta', 'posthog', 'plausible', 'gtm', 'webhook']
 * @param {object} [options] - Additional options
 * @param {boolean} [options.immediate=false] - Bypass batch queue
 * @returns {Promise<void>}
 *
 * @example
 * await trackEventWithProviders('purchase', { value: 99.99 }, ['ga4', 'meta']);
 */
export async function trackEventWithProviders(name, params = {}, providers = [], options = {}) {
    if (!initialized) return;

    const enrichedParams = {
        ...params,
        _target_providers: providers,
    };

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
 * Track an e-commerce event targeting specific providers only.
 *
 * @param {string} name - E-commerce event name (e.g. 'purchase', 'add_to_cart')
 * @param {object} data - Event data
 * @param {string[]} providers - Target providers
 * @returns {Promise<void>}
 *
 * @example
 * await trackEcommerceWithProviders('purchase', { transaction_id: 'ORD-1', value: 99.99 }, ['ga4', 'meta']);
 */
export async function trackEcommerceWithProviders(name, data = {}, providers = []) {
    if (!initialized) return;

    // Client-side pushes for enabled providers
    const ga4Targeted = providers.includes('ga4') && config?.ga4MeasurementId && window.gtag;
    const metaTargeted = providers.includes('meta') && config?.metaPixelId && window.fbq;

    if (ga4Targeted) {
        window.gtag('event', name, data);
    }

    if (metaTargeted) {
        const metaEvent = mapToMetaEvent(name);
        if (metaEvent) {
            const metaParams = mapToMetaParams(name, data);
            window.fbq('track', metaEvent, metaParams);
        }
    }

    // Server-side dispatch with provider targeting
    await trackEventWithProviders(name, data, providers, { immediate: true });
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
        select_item: null, // No Meta equivalent
        select_promotion: null, // No Meta equivalent
        view_promotion: null, // No Meta equivalent
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

// ─── High-Level E-Commerce Shorthands (v8.6.0) ───────────────────

/**
 * Track a purchase event with full e-commerce data.
 *
 * Convenience shorthand that formats data for GA4, Meta Pixel, PostHog,
 * and Plausible simultaneously with the correct parameter format for each.
 *
 * @param {object} order - Order data
 * @param {string} order.transaction_id - Unique transaction ID
 * @param {number} order.value - Total revenue
 * @param {string} [order.currency='USD'] - ISO 4217 currency code
 * @param {string} [order.coupon] - Coupon code applied
 * @param {number} [order.shipping] - Shipping cost
 * @param {number} [order.tax] - Tax amount
 * @param {Array} [order.items] - Array of item objects
 * @returns {Promise<void>}
 *
 * @example
 * await trackPurchase({
 *     transaction_id: 'ORD-12345',
 *     value: 149.99,
 *     currency: 'USD',
 *     tax: 12.50,
 *     shipping: 5.99,
 *     items: [
 *         { item_id: 'SKU-001', item_name: 'Widget', price: 49.99, quantity: 2 },
 *     ],
 * });
 */
export async function trackPurchase(order) {
    if (!initialized) return;

    const params = {
        transaction_id: order.transaction_id,
        value: order.value,
        currency: order.currency || config?.ecommerce?.currency || 'USD',
        coupon: order.coupon || undefined,
        shipping: order.shipping || undefined,
        tax: order.tax || undefined,
        items: order.items || [],
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'purchase', params);
    }

    // Meta Pixel Purchase
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'Purchase', {
            value: order.value,
            currency: params.currency,
            content_ids: order.items?.map((i) => i.item_id) || [],
            content_type: 'product',
            num_items: order.items?.reduce((sum, i) => sum + (i.quantity || 1), 0) || 0,
            ...(order.coupon ? { coupon: order.coupon } : {}),
        });
    }

    // PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('purchase', {
            ...params,
            $set: { last_purchase: new Date().toISOString() },
        });
    }

    // Plausible custom event
    if (config?.plausibleDomain && typeof window.plausible === 'function') {
        window.plausible('purchase', { props: { value: order.value, currency: params.currency } });
    }

    // Server-side dispatch
    await trackEvent('purchase', params, { immediate: true });
}

/**
 * Track a refund event.
 *
 * @param {object} refund - Refund data
 * @param {string} refund.transaction_id - Original transaction ID
 * @param {number} [refund.value] - Refund amount (partial refund)
 * @param {string} [refund.currency='USD'] - Currency code
 * @param {Array} [refund.items] - Refunded items
 * @returns {Promise<void>}
 *
 * @example
 * await trackRefund({ transaction_id: 'ORD-12345', value: 49.99, currency: 'USD' });
 */
export async function trackRefund(refund) {
    if (!initialized) return;

    const params = {
        transaction_id: refund.transaction_id,
        value: refund.value,
        currency: refund.currency || config?.ecommerce?.currency || 'USD',
        items: refund.items || [],
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'refund', params);
    }

    // PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('refund', params);
    }

    // Server-side dispatch
    await trackEvent('refund', params, { immediate: true });
}

/**
 * Track a view item event (product detail page view).
 *
 * @param {object} item - Item data
 * @param {string} item.item_id - Item ID/SKU
 * @param {string} [item.item_name] - Item name
 * @param {string} [item.item_category] - Item category
 * @param {string} [item.item_brand] - Item brand
 * @param {string} [item.item_variant] - Item variant
 * @param {number} [item.price] - Item price
 * @param {string} [item.currency] - Currency code
 * @returns {Promise<void>}
 *
 * @example
 * await trackViewItem({ item_id: 'SKU-001', item_name: 'Widget', price: 49.99 });
 */
export async function trackViewItem(item) {
    if (!initialized) return;

    const params = {
        item_id: item.item_id,
        item_name: item.item_name || null,
        item_category: item.item_category || null,
        item_brand: item.item_brand || null,
        item_variant: item.item_variant || null,
        price: item.price || null,
        currency: item.currency || config?.ecommerce?.currency || 'USD',
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'view_item', { items: [params] });
    }

    // Meta Pixel ViewContent
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'ViewContent', {
            content_ids: [item.item_id],
            content_name: item.item_name,
            content_type: 'product',
            currency: params.currency,
            value: item.price || 0,
        });
    }

    // PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$view_item', params);
    }

    // Server-side dispatch
    await trackEvent('view_item', params, { immediate: true });
}

/**
 * Track an add to cart event.
 *
 * @param {object} item - Cart item data
 * @param {string} item.item_id - Item ID/SKU
 * @param {string} [item.item_name] - Item name
 * @param {string} [item.item_category] - Item category
 * @param {number} [item.price] - Item price
 * @param {number} [item.quantity=1] - Quantity added
 * @param {string} [item.currency] - Currency code
 * @returns {Promise<void>}
 *
 * @example
 * await trackAddToCart({ item_id: 'SKU-001', item_name: 'Widget', price: 49.99, quantity: 2 });
 */
export async function trackAddToCart(item) {
    if (!initialized) return;

    const params = {
        item_id: item.item_id,
        item_name: item.item_name || null,
        item_category: item.item_category || null,
        price: item.price || null,
        quantity: item.quantity || 1,
        currency: item.currency || config?.ecommerce?.currency || 'USD',
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'add_to_cart', { items: [params], value: (item.price || 0) * (item.quantity || 1), currency: params.currency });
    }

    // Meta Pixel AddToCart
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'AddToCart', {
            content_ids: [item.item_id],
            content_name: item.item_name,
            content_type: 'product',
            currency: params.currency,
            value: (item.price || 0) * (item.quantity || 1),
        });
    }

    // PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('add_to_cart', params);
    }

    // Plausible custom event
    if (config?.plausibleDomain && typeof window.plausible === 'function') {
        window.plausible('add_to_cart');
    }

    // Server-side dispatch
    await trackEvent('add_to_cart', params, { immediate: true });
}

/**
 * Track a remove from cart event.
 *
 * @param {object} item - Cart item data
 * @param {string} item.item_id - Item ID/SKU
 * @param {string} [item.item_name] - Item name
 * @param {number} [item.price] - Item price
 * @param {number} [item.quantity=1] - Quantity removed
 * @param {string} [item.currency] - Currency code
 * @returns {Promise<void>}
 */
export async function trackRemoveFromCart(item) {
    if (!initialized) return;

    const params = {
        item_id: item.item_id,
        item_name: item.item_name || null,
        price: item.price || null,
        quantity: item.quantity || 1,
        currency: item.currency || config?.ecommerce?.currency || 'USD',
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'remove_from_cart', { items: [params] });
    }

    // PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('remove_from_cart', params);
    }

    // Server-side dispatch
    await trackEvent('remove_from_cart', params, { immediate: true });
}

/**
 * Track a begin checkout event.
 *
 * @param {object} checkout - Checkout data
 * @param {number} checkout.value - Cart total
 * @param {Array} [checkout.items] - Cart items
 * @param {string} [checkout.currency='USD'] - Currency code
 * @param {string} [checkout.coupon] - Coupon code
 * @returns {Promise<void>}
 */
export async function trackBeginCheckout(checkout) {
    if (!initialized) return;

    const params = {
        value: checkout.value,
        currency: checkout.currency || config?.ecommerce?.currency || 'USD',
        items: checkout.items || [],
        coupon: checkout.coupon || undefined,
    };

    // GA4 client-side
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'begin_checkout', params);
    }

    // Meta Pixel InitiateCheckout
    if (config?.metaPixelId && window.fbq) {
        window.fbq('track', 'InitiateCheckout', {
            value: checkout.value,
            currency: params.currency,
            content_ids: checkout.items?.map((i) => i.item_id) || [],
            num_items: checkout.items?.reduce((sum, i) => sum + (i.quantity || 1), 0) || 0,
            ...(checkout.coupon ? { coupon: checkout.coupon } : {}),
        });
    }

    // Plausible custom event
    if (config?.plausibleDomain && typeof window.plausible === 'function') {
        window.plausible('begin_checkout');
    }

    // Server-side dispatch
    await trackEvent('begin_checkout', params, { immediate: true });
}

/**
 * Track an item selection from a list (GA4 select_item).
 *
 * Part of the e-commerce product funnel — typically fired before
 * view_item or add_to_cart.
 *
 * @param {Array} items - Selected items
 * @param {string} [itemListId] - Item list identifier (e.g. 'related_products')
 * @param {string} [itemListName] - Item list name (e.g. 'Related Products')
 * @returns {Promise<void>}
 *
 * @example
 * await trackSelectItem(
 *     [{ item_id: 'SKU-001', item_name: 'Widget', price: 49.99 }],
 *     'related_products',
 *     'Related Products',
 * );
 */
export async function trackSelectItem(items = [], itemListId, itemListName) {
    if (!initialized) return;

    const params = {
        item_list_id: itemListId || null,
        item_list_name: itemListName || null,
        items,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'select_item', params);
    }

    // Server-side dispatch
    await trackEvent('select_item', params, { immediate: true });
}

/**
 * Track a promotion view impression (GA4 view_promotion).
 *
 * Use this when a promotion banner is displayed to the user.
 *
 * @param {object} promotion - Promotion data
 * @param {string} [promotion.promotion_id] - Promotion ID
 * @param {string} [promotion.promotion_name] - Promotion name (e.g. 'Summer Sale')
 * @param {string} [promotion.creative_name] - Creative name (e.g. 'hero_banner')
 * @param {string} [promotion.creative_slot] - Creative slot position
 * @param {string} [promotion.location_id] - Location ID
 * @returns {Promise<void>}
 *
 * @example
 * await trackPromotionView({
 *     promotion_id: 'PROMO-001',
 *     promotion_name: 'Summer Sale',
 *     creative_name: 'hero_banner',
 *     creative_slot: 'homepage_top',
 * });
 */
export async function trackPromotionView(promotion = {}) {
    if (!initialized) return;

    const params = {
        promotion_id: promotion.promotion_id || null,
        promotion_name: promotion.promotion_name || null,
        creative_name: promotion.creative_name || null,
        creative_slot: promotion.creative_slot || null,
        location_id: promotion.location_id || null,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'view_promotion', params);
    }

    // Server-side dispatch
    await trackEvent('view_promotion', params, { immediate: true });
}

/**
 * Track a promotion click/selection (GA4 select_promotion).
 *
 * Use this when a user clicks on a promotion banner or link.
 *
 * @param {object} promotion - Promotion data
 * @param {string} [promotion.promotion_id] - Promotion ID
 * @param {string} [promotion.promotion_name] - Promotion name
 * @param {string} [promotion.creative_name] - Creative name
 * @param {string} [promotion.creative_slot] - Creative slot position
 * @param {string} [promotion.location_id] - Location ID
 * @returns {Promise<void>}
 *
 * @example
 * await trackPromotionClick({
 *     promotion_id: 'PROMO-001',
 *     promotion_name: 'Summer Sale',
 *     creative_slot: 'sidebar',
 * });
 */
export async function trackPromotionClick(promotion = {}) {
    if (!initialized) return;

    const params = {
        promotion_id: promotion.promotion_id || null,
        promotion_name: promotion.promotion_name || null,
        creative_name: promotion.creative_name || null,
        creative_slot: promotion.creative_slot || null,
        location_id: promotion.location_id || null,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'select_promotion', params);
    }

    // Server-side dispatch
    await trackEvent('select_promotion', params, { immediate: true });
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
        await fetch(`${apiBaseUrl}/identify`, {
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
        await fetch(`${apiBaseUrl}/consent`, {
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

// ─── SaaS Conversion Tracking (v2.66.0) ──────────────────────────────

/**
 * Track a trial conversion event (trial → paid).
 *
 * @param {object} data - Conversion data
 * @param {string} data.plan - Converted plan name
 * @param {string} [data.trial_plan] - Trial plan name
 * @param {number} [data.trial_duration_days] - Days in trial
 * @param {string} [data.conversion_source] - Where conversion happened
 * @returns {Promise<void>}
 *
 * @example
 * await trackTrialConversion({
 *     plan: 'pro',
 *     trial_plan: 'free_trial',
 *     trial_duration_days: 14,
 *     conversion_source: 'pricing_page',
 * });
 */
export async function trackTrialConversion(data) {
    if (!initialized) return;

    await trackEvent('trial_converted', {
        plan: data.plan,
        trial_plan: data.trial_plan || null,
        trial_duration_days: data.trial_duration_days || null,
        conversion_source: data.conversion_source || null,
    }, { immediate: true });
}

/**
 * Track a subscription resume (win-back) event.
 *
 * @param {object} data - Resume data
 * @param {string} data.plan - Resumed plan
 * @param {string} [data.previous_plan] - Plan before cancellation
 * @param {number} [data.days_since_cancellation] - Days between cancel and resume
 * @param {string} [data.reactivation_source] - Win-back source
 * @returns {Promise<void>}
 *
 * @example
 * await trackSubscriptionResumed({
 *     plan: 'pro',
 *     previous_plan: 'pro',
 *     days_since_cancellation: 45,
 *     reactivation_source: 'win_back_email',
 * });
 */
export async function trackSubscriptionResumed(data) {
    if (!initialized) return;

    await trackEvent('subscription_resumed', {
        plan: data.plan,
        previous_plan: data.previous_plan || null,
        days_since_cancellation: data.days_since_cancellation || null,
        reactivation_source: data.reactivation_source || null,
    }, { immediate: true });
}

/**
 * Track a product milestone reached event.
 *
 * Used for activation scoring — tracks key moments that predict
 * trial-to-paid conversion.
 *
 * @param {string} milestone - Milestone identifier (e.g. 'first_project', 'login_100')
 * @param {object} [options] - Additional options
 * @param {string} [options.category] - Milestone category (activation, growth, engagement, retention)
 * @param {number} [options.value] - Numeric value
 * @returns {Promise<void>}
 *
 * @example
 * await trackMilestone('first_project', { category: 'activation' });
 * await trackMilestone('login_100', { category: 'retention', value: 100 });
 */
export async function trackMilestone(milestone, options = {}) {
    if (!initialized) return;

    await trackEvent('milestone_reached', {
        milestone,
        milestone_category: options.category || null,
        milestone_value: options.value || null,
    });
}

/**
 * Fetch SaaS conversion analytics summary from the server.
 *
 * @returns {Promise<object>} Conversion analytics data
 *
 * @example
 * const analytics = await fetchConversionSummary();
 * console.log(analytics.conversion.trial_conversion.rate);
 */
export async function fetchConversionSummary() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/conversion/summary`, {
            headers: {
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch conversion funnel data from the server.
 *
 * @returns {Promise<object|null>} Funnel data
 */
export async function fetchConversionFunnel() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/conversion/funnel`, {
            headers: {
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        return await response.json();
    } catch {
        return null;
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
 * @deprecated Use initInertiaPageViewTracker(options) instead.
 * This is a backward-compatible wrapper that delegates to the full implementation.
 *
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * // In your root Svelte component:
 * import { initInertiaPageViewTracker } from '@zeroboiler/analytics';
 * onMount(() => initInertiaPageViewTracker());
 */
export function initInertiaPageViewTrackerLegacy() {
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

/**
 * Initialize Inertia page view tracking with Svelte page store integration.
 *
 * Watches the Inertia Svelte page store for URL changes and auto-tracks
 * page_view events. Includes scroll depth reset on navigation and
 * optional automatic scroll depth tracking.
 *
 * This is the recommended method for Svelte + Inertia projects.
 * It combines page view tracking, scroll depth, and session tracking
 * into a single initialization call.
 *
 * @param {object} options - Configuration options
 * @param {object} options.page - The Inertia page object (from `$page` in Svelte)
 * @param {boolean} [options.scrollDepth=true] - Enable scroll depth tracking on each page
 * @param {boolean} [options.sessionTracking=true] - Track session_start on first navigation
 * @param {Function} [options.onPageView] - Callback after each page view is tracked
 * @returns {function} Cleanup function to remove all listeners
 *
 * @example
 * // In your root +layout.svelte:
 * import { page } from '@inertiajs/svelte';
 * import { initSveltePageTracker } from './analytics.js';
 *
 * $: if (page.props.zbAnalytics?.enabled) {
 *     const cleanup = initSveltePageTracker({
 *         page,
 *         onPageView: (url) => console.log('Tracked:', url),
 *     });
 * }
 */
export function initSveltePageTracker(options = {}) {
    if (!initialized) return () => {};

    const { scrollDepth = true, sessionTracking = true, onPageView } = options;
    const cleanups = [];
    let lastUrl = window.location.href;
    let sessionStarted = false;
    let scrollCleanup = null;

    // Track session start on first navigation
    if (sessionTracking && !sessionStarted) {
        sessionStarted = true;
        trackEvent('session_start', {
            entry_point: window.location.href,
            referrer: document.referrer,
        });
    }

    // Inertia navigate event listener
    const navigateHandler = (event) => {
        if (event.detail?.visit) {
            const url = window.location.href;
            if (url === lastUrl) return;

            lastUrl = url;

            // Reset scroll depth on navigation
            if (scrollCleanup) {
                scrollCleanup();
                scrollCleanup = null;
            }

            // Track the page view
            trackPageView(
                event.detail.page?.title || document.title,
                url,
                document.referrer,
            );

            // Re-initialize scroll depth for new page
            if (scrollDepth) {
                scrollCleanup = initScrollDepth();
            }

            // Fire callback
            if (typeof onPageView === 'function') {
                onPageView(url);
            }
        }
    };

    document.addEventListener('inertia:navigate', navigateHandler);
    cleanups.push(() => document.removeEventListener('inertia:navigate', navigateHandler));

    // Initialize scroll depth for initial page
    if (scrollDepth) {
        scrollCleanup = initScrollDepth();
        cleanups.push(() => {
            if (scrollCleanup) scrollCleanup();
        });
    }

    return () => {
        for (const cleanup of cleanups) {
            cleanup();
        }
        cleanups.length = 0;
    };
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
 * Initialize Web Vitals tracking with the Performance Observer API.
 *
 * Uses the native browser PerformanceObserver when available to capture
 * LCP, FID/INP, and CLS metrics. Falls back gracefully if the API
 * is not supported or if the web-vitals library is not loaded.
 *
 * Respects the performance config from Inertia props.
 *
 * @param {object} [options] - Override options
 * @param {boolean} [options.sendToServer] - Force server dispatch (overrides config)
 * @returns {function} Cleanup function to disconnect observers
 *
 * @example
 * const cleanup = initWebVitals();
 * // Or with web-vitals library:
 * import { onLCP, onCLS, onINP, onTTFB, onFCP } from 'web-vitals';
 * initWebVitals({ onLCP, onCLS, onINP, onTTFB, onFCP });
 */
export function initWebVitals(options = {}) {
    if (!initialized) return () => {};

    const perfConfig = config?.performance || {};
    const enabled = options.enabled !== undefined ? options.enabled : perfConfig.enabled;
    const sendToServer = options.sendToServer !== undefined ? options.sendToServer : perfConfig.sendToServer;

    if (!enabled) return () => {};

    const observers = [];

    function observeMetric(type, callback) {
        if (typeof PerformanceObserver === 'undefined') return;

        try {
            const observer = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    callback(entry);
                }
            });
            observer.observe({ type, buffered: true });
            observers.push(observer);
        } catch {
            // PerformanceObserver not supported for this type
        }
    }

    // Largest Contentful Paint (LCP)
    if (perfConfig.trackLCP !== false) {
        observeMetric('largest-contentful-paint', (entry) => {
            const value = entry.startTime;
            trackEvent('web_vitals', {
                metric_name: 'LCP',
                metric_value: Math.round(value),
                rating: value <= 2500 ? 'good' : value <= 4000 ? 'needs-improvement' : 'poor',
                page_location: window.location.href,
            }, { immediate: sendToServer });
        });
    }

    // First Input / Interaction to Next Paint (INP)
    if (perfConfig.trackFID !== false) {
        if (typeof PerformanceObserver !== 'undefined') {
            try {
                // Try INP first (modern browsers)
                const inpObserver = new PerformanceObserver((list) => {
                    for (const entry of list.getEntries()) {
                        const value = entry.duration;
                        trackEvent('web_vitals', {
                            metric_name: 'INP',
                            metric_value: Math.round(value),
                            rating: value <= 200 ? 'good' : value <= 500 ? 'needs-improvement' : 'poor',
                            page_location: window.location.href,
                        }, { immediate: sendToServer });
                    }
                });

                if (PerformanceObserver.supportedEntryTypes?.includes('event')) {
                    inpObserver.observe({ type: 'event', buffered: true });
                    observers.push(inpObserver);
                } else {
                    // Fallback: try first-input
                    const fiObserver = new PerformanceObserver((list) => {
                        for (const entry of list.getEntries()) {
                            const value = entry.processingStart - entry.startTime;
                            trackEvent('web_vitals', {
                                metric_name: 'FID',
                                metric_value: Math.round(value),
                                rating: value <= 100 ? 'good' : value <= 300 ? 'needs-improvement' : 'poor',
                                page_location: window.location.href,
                            }, { immediate: sendToServer });
                        }
                    });
                    fiObserver.observe({ type: 'first-input', buffered: true });
                    observers.push(fiObserver);
                }
            } catch {
                // Not supported
            }
        }
    }

    // Cumulative Layout Shift (CLS)
    if (perfConfig.trackCLS !== false) {
        observeMetric('layout-shift', (entry) => {
            if (!entry.hadRecentInput) {
                trackEvent('web_vitals', {
                    metric_name: 'CLS',
                    metric_value: Math.round(entry.value * 1000) / 1000,
                    rating: entry.value <= 0.1 ? 'good' : entry.value <= 0.25 ? 'needs-improvement' : 'poor',
                    page_location: window.location.href,
                }, { immediate: sendToServer });
            }
        });
    }

    // Time to First Byte (TTFB)
    if (perfConfig.trackTTFB !== false) {
        observeMetric('navigation', (entry) => {
            const value = entry.responseStart;
            trackEvent('web_vitals', {
                metric_name: 'TTFB',
                metric_value: Math.round(value),
                rating: value <= 800 ? 'good' : value <= 1800 ? 'needs-improvement' : 'poor',
                page_location: window.location.href,
            }, { immediate: sendToServer });
        });
    }

    // First Contentful Paint (FCP)
    if (perfConfig.trackFCP) {
        observeMetric('paint', (entry) => {
            if (entry.name === 'first-contentful-paint') {
                const value = entry.startTime;
                trackEvent('web_vitals', {
                    metric_name: 'FCP',
                    metric_value: Math.round(value),
                    rating: value <= 1800 ? 'good' : value <= 3000 ? 'needs-improvement' : 'poor',
                    page_location: window.location.href,
                }, { immediate: sendToServer });
            }
        });
    }

    return () => {
        for (const observer of observers) {
            observer.disconnect();
        }
        observers.length = 0;
    };
}

/**
 * Track a timing event using the Performance API.
 *
 * @param {string} name - Timing name
 * @returns {function} End function — call to stop the timer and record the event
 *
 * @example
 * const end = trackTiming('api_request');
 * await fetch('/api/data');
 * end();
 */
export function trackTiming(name) {
    if (!initialized || typeof performance === 'undefined') return () => {};

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

// ─── Session Lifecycle Tracking ────────────────────────────────────────

let sessionState = {
    active: false,
    id: null,
    startTime: null,
    eventCount: 0,
    pageViewCount: 0,
    lastActivity: null,
    idleTimer: null,
    visibilityHandler: null,
    cleanupFns: [],
};

/**
 * Initialize client-side session lifecycle tracking.
 *
 * Tracks session_start on initialization and session_end on:
 * - Tab visibility change (user switches away)
 * - Idle timeout (configurable, default 30 minutes)
 * - BeforeUnload event
 *
 * Session events include duration, event counts, and exit reason
 * for engagement analysis.
 *
 * @param {object} [options] - Override options
 * @param {number} [options.idleTimeout] - Idle timeout in seconds (default from config)
 * @returns {function} Cleanup function
 *
 * @example
 * const cleanup = initSessionTracking();
 */
export function initSessionTracking(options = {}) {
    if (!initialized || sessionState.active) return () => {};

    const autoTrackConfig = config?.autoTrack || {};
    const idleTimeout = options.idleTimeout || autoTrackConfig.idleTimeout || 1800;

    sessionState = {
        active: true,
        id: generateSessionId(),
        startTime: Date.now(),
        eventCount: 0,
        pageViewCount: 0,
        lastActivity: Date.now(),
        idleTimer: null,
        visibilityHandler: null,
        cleanupFns: [],
    };

    // Track session start
    trackEvent('session_start', {
        session_id: sessionState.id,
        page_path: window.location.pathname,
        referrer: document.referrer || null,
        source: utmParams.utm_source || 'direct',
    }, { immediate: true });

    // Visibility change handler — end session when tab is hidden
    sessionState.visibilityHandler = () => {
        if (document.visibilityState === 'hidden') {
            endSession('visibility');
        } else if (document.visibilityState === 'visible') {
            // Restart session if user comes back after a brief absence
            if (sessionState.active === false && Date.now() - (sessionState.lastActivity || 0) < 300000) {
                // Resume: track a new session_start
                sessionState.active = true;
                sessionState.startTime = Date.now();
                sessionState.eventCount = 0;
                sessionState.pageViewCount = 0;
                sessionState.id = generateSessionId();

                trackEvent('session_start', {
                    session_id: sessionState.id,
                    page_path: window.location.pathname,
                    referrer: null,
                    source: 'resume',
                }, { immediate: true });

                resetIdleTimer(idleTimeout);
            }
        }
    };

    document.addEventListener('visibilitychange', sessionState.visibilityHandler);

    // BeforeUnload handler — best-effort session end
    const beforeUnloadHandler = () => {
        if (sessionState.active) {
            endSession('unload');
        }
    };
    window.addEventListener('beforeunload', beforeUnloadHandler);

    // Reset idle timer on user activity
    const activityHandler = () => {
        sessionState.lastActivity = Date.now();
        resetIdleTimer(idleTimeout);
    };

    const activityEvents = ['mousedown', 'keydown', 'touchstart', 'scroll'];
    for (const eventName of activityEvents) {
        window.addEventListener(eventName, activityHandler, { passive: true });
    }

    sessionState.cleanupFns = [
        () => document.removeEventListener('visibilitychange', sessionState.visibilityHandler),
        () => window.removeEventListener('beforeunload', beforeUnloadHandler),
        ...activityEvents.map((eventName) => () => window.removeEventListener(eventName, activityHandler, { passive: true })),
        () => { if (sessionState.idleTimer) clearTimeout(sessionState.idleTimer); },
    ];

    resetIdleTimer(idleTimeout);

    return () => {
        if (sessionState.active) {
            endSession('cleanup');
        }
        for (const fn of sessionState.cleanupFns) {
            fn();
        }
        sessionState.active = false;
    };
}

/**
 * Reset the idle timer.
 */
function resetIdleTimer(idleTimeout) {
    if (sessionState.idleTimer) {
        clearTimeout(sessionState.idleTimer);
    }

    sessionState.idleTimer = setTimeout(() => {
        if (sessionState.active) {
            endSession('idle');
        }
    }, idleTimeout * 1000);
}

/**
 * End the current session and dispatch a session_end event.
 */
function endSession(reason) {
    if (!sessionState.active) return;

    const duration = Math.round((Date.now() - sessionState.startTime) / 1000);

    trackEvent('session_end', {
        session_id: sessionState.id,
        duration_seconds: duration,
        event_count: sessionState.eventCount,
        page_view_count: sessionState.pageViewCount,
        exit_page: window.location.pathname,
        end_reason: reason,
    }, { immediate: true });

    if (sessionState.idleTimer) {
        clearTimeout(sessionState.idleTimer);
        sessionState.idleTimer = null;
    }

    sessionState.active = false;
}

/**
 * Generate a short session ID.
 */
function generateSessionId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID().slice(0, 8);
    }

    return Math.random().toString(36).slice(2, 10);
}

/**
 * Record an event for session counting.
 */
export function recordSessionEvent() {
    if (sessionState.active) {
        sessionState.eventCount++;
        sessionState.lastActivity = Date.now();
    }
}

/**
 * Record a page view for session counting.
 */
export function recordSessionPageView() {
    if (sessionState.active) {
        sessionState.pageViewCount++;
        sessionState.lastActivity = Date.now();
    }
}

/**
 * Get the current session state (for debugging).
 */
export function getSessionState() {
    return { ...sessionState };
}

// ─── Auto-Init Everything ──────────────────────────────────────────────

let autoInitCleanup = null;

/**
 * Initialize all analytics tracking in one call.
 *
 * Reads configuration from the Inertia page props (config.autoTrack
 * and config.performance) and enables/disables each tracker
 * accordingly. Returns a cleanup function that tears down all
 * active trackers.
 *
 * @param {object} pageProps - Inertia page props containing `zbAnalytics`
 * @param {object} [options] - Override options for individual trackers
 * @param {boolean} [options.pageViews] - Override page view auto-tracking
 * @param {boolean} [options.scrollDepth] - Override scroll depth tracking
 * @param {boolean} [options.formTracking] - Override form tracking
 * @param {boolean} [options.errorTracking] - Override error tracking
 * @param {boolean} [options.linkTracking] - Override link tracking
 * @param {boolean} [options.sessionTracking] - Override session tracking
 * @param {boolean} [options.performanceTracking] - Override Web Vitals tracking
 * @param {number} [options.idleTimeout] - Override idle timeout (seconds)
 * @param {string[]} [options.errorIgnorePatterns] - Override error ignore patterns
 * @returns {function} Cleanup function to tear down all trackers
 *
 * @example
 * // Svelte root component:
 * import { initAll } from '../resources/js/analytics';
 * import { onDestroy } from 'svelte';
 *
 * const cleanup = initAll(page.props);
 * onDestroy(cleanup);
 *
 * // With overrides:
 * const cleanup = initAll(page.props, {
 *     linkTracking: true,
 *     performanceTracking: true,
 *     idleTimeout: 600,
 * });
 */
export function initAll(pageProps, options = {}) {
    // Cleanup previous auto-init if any
    if (autoInitCleanup) {
        autoInitCleanup();
        autoInitCleanup = null;
    }

    // Initialize the core library
    init(pageProps);

    if (!initialized) return () => {};

    const cleanups = [];
    const atConfig = config?.autoTrack || {};
    const perfConfig = config?.performance || {};

    // Resolve effective settings: explicit options > Inertia config > defaults
    const pageViews = options.pageViews ?? atConfig.pageViews ?? true;
    const scrollDepth = options.scrollDepth ?? atConfig.scrollDepth ?? true;
    const formTracking = options.formTracking ?? atConfig.formTracking ?? true;
    const errorTracking = options.errorTracking ?? atConfig.errorTracking ?? true;
    const linkTracking = options.linkTracking ?? atConfig.linkTracking ?? false;
    const sessionTracking = options.sessionTracking ?? atConfig.sessionTracking ?? true;
    const performanceTracking = options.performanceTracking ?? perfConfig.enabled ?? false;
    const idleTimeout = options.idleTimeout ?? atConfig.idleTimeout ?? 1800;
    const errorIgnorePatterns = options.errorIgnorePatterns ?? atConfig.errorIgnorePatterns ?? [];

    // 1. Session tracking (start first to count events)
    if (sessionTracking) {
        cleanups.push(initSessionTracking({ idleTimeout }));
    }

    // 2. Inertia page view auto-tracking
    if (pageViews) {
        const pvCleanup = initInertiaPageViewTracker();
        cleanups.push(pvCleanup);
    }

    // 3. Scroll depth tracking
    if (scrollDepth) {
        cleanups.push(initScrollDepth());
    }

    // 4. Form interaction tracking
    if (formTracking) {
        cleanups.push(initFormTracking());
    }

    // 5. JavaScript error tracking
    if (errorTracking) {
        cleanups.push(initErrorTracking({ ignorePatterns: errorIgnorePatterns }));
    }

    // 6. Link click tracking
    if (linkTracking) {
        cleanups.push(initLinkTracking());
    }

    // 7. Core Web Vitals tracking
    if (performanceTracking) {
        cleanups.push(initWebVitals());
    }

    autoInitCleanup = () => {
        for (const fn of cleanups) {
            try { fn(); } catch { /* best-effort cleanup */ }
        }
        cleanups.length = 0;
    };

    return autoInitCleanup;
}

/**
 * Cleanup all auto-initialized trackers.
 *
 * Call this in your component's onDestroy/cleanup lifecycle.
 * Also called automatically when initAll() is called again.
 */
export function destroyAll() {
    if (autoInitCleanup) {
        autoInitCleanup();
        autoInitCleanup = null;
    }
    destroy();
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

// getCookie is defined in the utility section below

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

// ─── Search Tracking ────────────────────────────────────────────────

/**
 * Track a search event.
 *
 * @param {string} searchTerm - The search query string
 * @param {object} [options] - Additional options
 * @param {number} [options.resultCount] - Number of results returned
 * @param {string} [options.category] - Search category (e.g. 'products', 'docs', 'blog')
 * @param {object} [options.params] - Additional params
 * @returns {Promise<void>}
 *
 * @example
 * await trackSearch('analytics dashboard', { resultCount: 12, category: 'products' });
 */
export async function trackSearch(searchTerm, options = {}) {
    if (!initialized) return;

    const params = {
        search_term: searchTerm.slice(0, 500),
        results_count: options.resultCount || null,
        search_category: options.category || null,
        ...options.params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'search', params);
    }

    // Push to PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$search', params);
    }

    // Server-side dispatch
    await trackEvent('search', params, { immediate: true });
}

// ─── Share Tracking ──────────────────────────────────────────────────

/**
 * Track a content share event.
 *
 * @param {string} method - Share method (e.g. 'twitter', 'facebook', 'linkedin', 'email', 'copy')
 * @param {string} contentType - Content type being shared (e.g. 'article', 'product', 'page')
 * @param {string} [contentId] - Optional content identifier
 * @param {object} [params] - Additional params
 * @returns {Promise<void>}
 *
 * @example
 * await trackShare('twitter', 'article', 'post-123');
 * await trackShare('copy', 'page', '/pricing');
 */
export async function trackShare(method, contentType, contentId = null, params = {}) {
    if (!initialized) return;

    const shareParams = {
        method,
        content_type: contentType,
        content_id: contentId || null,
        item_id: contentId || null,
        ...params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'share', shareParams);
    }

    // Push to Meta Pixel
    if (config?.metaPixelId && window.fbq) {
        window.fbq('trackCustom', 'Share', shareParams);
    }

    // Push to PostHog
    if (config?.posthogHost && window.posthog) {
        window.posthog.capture('$share', shareParams);
    }

    // Server-side dispatch
    await trackEvent('share', shareParams, { immediate: true });
}

// ─── File Download Tracking ─────────────────────────────────────────

/**
 * Track a file download event.
 *
 * @param {object} file - File information
 * @param {string} file.url - File URL
 * @param {string} [file.name] - File name
 * @param {string} [file.extension] - File extension (e.g. 'pdf', 'zip')
 * @param {string} [file.size] - File size in bytes
 * @param {object} [params] - Additional params
 * @returns {Promise<void>}
 *
 * @example
 * await trackFileDownload({ url: '/docs/manual.pdf', name: 'manual.pdf', extension: 'pdf', size: 2048576 });
 */
export async function trackFileDownload(file, params = {}) {
    if (!initialized) return;

    const fileParams = {
        file_url: file.url || '',
        file_name: file.name || null,
        file_extension: (file.extension || file.url?.split('.').pop() || '').toLowerCase(),
        file_size: file.size || null,
        link_url: file.url || '',
        ...params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'file_download', fileParams);
    }

    // Push to Meta Pixel
    if (config?.metaPixelId && window.fbq) {
        window.fbq('trackCustom', 'FileDownload', fileParams);
    }

    // Server-side dispatch
    await trackEvent('file_download', fileParams, { immediate: true });
}

/**
 * Initialize automatic file download tracking.
 *
 * Intercepts clicks on links to common file types (PDF, ZIP, DOC, XLS, etc.)
 * and automatically tracks them as file_download events.
 *
 * @param {object} [options] - Configuration options
 * @param {string[]} [options.extensions] - File extensions to track (default: common document types)
 * @param {boolean} [options.trackAll] - Track all downloads (not just listed extensions)
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initFileDownloadTracking({ extensions: ['pdf', 'zip', 'csv'] });
 */
export function initFileDownloadTracking(options = {}) {
    if (!initialized) return () => {};

    const defaultExtensions = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'png', 'jpg', 'svg', 'mp4', 'mp3'];
    const extensions = options.extensions || defaultExtensions;
    const trackAll = options.trackAll || false;

    function onClick(e) {
        const link = e.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href') || '';
        if (!href) return;

        // Check extension
        const extMatch = href.split('?')[0].split('#')[0].split('.').pop()?.toLowerCase();

        if (extMatch && (trackAll || extensions.includes(extMatch))) {
            trackFileDownload({
                url: href.slice(0, 2048),
                name: href.split('/').pop() || null,
                extension: extMatch,
            }, {
                link_text: link.textContent?.trim()?.slice(0, 200) || null,
            });
        }
    }

    document.addEventListener('click', onClick, true);

    return () => document.removeEventListener('click', onClick, true);
}

// ─── Video Play Tracking ────────────────────────────────────────────

/**
 * Track a video play event.
 *
 * @param {object} video - Video information
 * @param {string} video.title - Video title
 * @param {string} [video.url] - Video URL
 * @param {number} [video.duration] - Total video duration in seconds
 * @param {number} [video.percent] - Current playback percentage (0-100)
 * @param {string} [video.provider] - Video provider (e.g. 'youtube', 'vimeo', 'html5')
 * @param {object} [params] - Additional params
 * @returns {Promise<void>}
 *
 * @example
 * await trackVideoPlay({ title: 'Product Demo', url: '/videos/demo.mp4', duration: 120, percent: 0, provider: 'html5' });
 */
export async function trackVideoPlay(video, params = {}) {
    if (!initialized) return;

    const videoParams = {
        video_title: video.title || '',
        video_url: video.url || null,
        video_duration: video.duration || null,
        video_percent: video.percent != null ? video.percent : 0,
        video_provider: video.provider || 'html5',
        ...params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'video_play', videoParams);
    }

    // Push to Meta Pixel
    if (config?.metaPixelId && window.fbq) {
        window.fbq('trackCustom', 'VideoPlay', videoParams);
    }

    // Server-side dispatch
    await trackEvent('video_play', videoParams, { immediate: true });
}

// ─── Notification Tracking ──────────────────────────────────────────

/**
 * Track a notification interaction event.
 *
 * @param {string} type - Notification type (e.g. 'push', 'in_app', 'email', 'sms')
 * @param {string} action - User action (e.g. 'received', 'clicked', 'dismissed', 'opened')
 * @param {string} [notificationId] - Notification identifier
 * @param {object} [params] - Additional params
 * @returns {Promise<void>}
 *
 * @example
 * await trackNotification('push', 'clicked', 'notif-123', { campaign: 'weekly_digest' });
 */
export async function trackNotification(type, action, notificationId = null, params = {}) {
    if (!initialized) return;

    const notifParams = {
        notification_type: type,
        notification_action: action,
        notification_id: notificationId || null,
        ...params,
    };

    // Server-side dispatch
    await trackEvent('notification', notifParams, { immediate: true });
}

// ─── SaaS Lifecycle Tracking (Client-Side) ──────────────────────────

/**
 * Track a SaaS lifecycle event from the client.
 *
 * Convenience wrapper for common SaaS events with standard parameter formatting.
 * Use after server-side actions complete (e.g. trial signup, plan change)
 * to capture client-side context (UTM, device info, session data).
 *
 * @param {string} event - SaaS event name
 * @param {object} [params] - Event parameters
 * @returns {Promise<void>}
 *
 * @example
 * await trackSaaSEvent('start_trial', { plan_name: 'Pro', trial_days: 14 });
 * await trackSaaSEvent('plan_upgrade', { from_plan: 'Starter', to_plan: 'Pro' });
 * await trackSaaSEvent('feature_used', { feature_name: 'export_csv' });
 * await trackSaaSEvent('cancellation', { reason: 'too_expensive', plan_name: 'Pro' });
 */
export async function trackSaaSEvent(event, params = {}) {
    if (!initialized) return;

    // SaaS events are always dispatched immediately (important lifecycle events)
    await trackEvent(event, params, { immediate: true });
}

// ─── Outbound Click Tracking ─────────────────────────────────────────

/**
 * Track an outbound click event with full context.
 *
 * @param {string} url - Destination URL
 * @param {object} [options] - Additional options
 * @param {string} [options.linkText] - Link text content
 * @param {string} [options.linkId] - Link element ID
 * @param {string} [options.section] - Page section where the link appears
 * @returns {Promise<void>}
 *
 * @example
 * await trackOutboundClick('https://docs.example.com', { linkText: 'Documentation', section: 'navbar' });
 */
export async function trackOutboundClick(url, options = {}) {
    if (!initialized) return;

    const params = {
        link_url: url.slice(0, 2048),
        link_text: options.linkText || null,
        link_id: options.linkId || null,
        link_type: 'external',
        page_location: window.location.href,
        section: options.section || null,
        ...options.params,
    };

    // Push to GA4 via gtag (client-side)
    if (config?.ga4MeasurementId && window.gtag) {
        window.gtag('event', 'outbound_click', params);
    }

    // Server-side dispatch
    await trackEvent('outbound_click', params, { immediate: true });
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

        await fetch(`${apiBaseUrl}/events`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
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

        await fetch(`${apiBaseUrl}/identify`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
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
        await fetch(`${apiBaseUrl}/pageview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
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

// ─── Tracking Preference (GDPR Do-Not-Track) ──────────────────────────

/**
 * Opt the authenticated user out of all tracking.
 *
 * Persists the opt-out preference server-side. Even if consent is granted,
 * no events will be dispatched for this user after opting out.
 * Also suppresses the current anonymous client ID.
 *
 * @returns {Promise<boolean>} True if opt-out was successful
 *
 * @example
 * await optOutTracking();
 * // All tracking is now disabled for this user
 */
export async function optOutTracking() {
    if (!initialized) return false;

    try {
        const response = await fetch(`${apiBaseUrl}/opt-out`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        if (response.ok) {
            // Stop all client-side tracking immediately
            initialized = false;
            return true;
        }

        return false;
    } catch {
        return false;
    }
}

/**
 * Opt the authenticated user in to tracking.
 *
 * Overrides any previous opt-out preference and re-enables tracking.
 *
 * @returns {Promise<boolean>} True if opt-in was successful
 *
 * @example
 * await optInTracking();
 * // Tracking is re-enabled for this user
 */
export async function optInTracking() {
    if (trackingId === null) return false;

    try {
        const response = await fetch(`${apiBaseUrl}/opt-in`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        if (response.ok) {
            return true;
        }

        return false;
    } catch {
        return false;
    }
}

/**
 * Get the current tracking preference for the authenticated user.
 *
 * @returns {Promise<object|null>} Preference state or null on failure
 *
 * @example
 * const pref = await getTrackingPreference();
 * if (pref) {
 *     console.log('Tracking allowed:', pref.tracking_allowed);
 * }
 */
export async function getTrackingPreference() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/preference`, {
            headers: {
                Accept: 'application/json',
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── initAll: One-Call Setup ─────────────────────────────────────────────
// The initAll function is defined above (line ~1430) with full session tracking
// and Inertia config-driven settings. This is the canonical implementation.
// The JSDoc below is preserved for reference.

/**
 * Initialize analytics AND set up all auto-trackers in one call.
 *
 * Designed for Svelte/Inertia onMount — a single import and call gets you
 * page views, scroll depth, form tracking, error tracking, link tracking,
 * session tracking, and Web Vitals with one cleanup function.
 *
 * @param {object} pageProps - Inertia page props containing `zbAnalytics`
 * @param {object} [options] - Tracker configuration
 * @param {boolean} [options.pageViews=true] - Auto-track Inertia page views
 * @param {boolean} [options.scrollDepth=true] - Track scroll depth thresholds
 * @param {boolean} [options.formTracking=true] - Track form_start + form_submit
 * @param {boolean} [options.errorTracking=true] - Track JS errors + rejections
 * @param {boolean} [options.linkTracking=false] - Track outbound/internal link clicks
 * @param {string[]} [options.errorIgnorePatterns] - Error patterns to ignore
 * @param {boolean} [options.sessionTracking=true] - Track session start/end
 * @param {boolean} [options.performanceTracking=false] - Track Web Vitals
 * @returns {function} Cleanup function to remove all listeners
 *
 * @example
 * // Svelte/Inertia root component:
 * import { page } from '@inertiajs/svelte';
 * import { initAll } from '../resources/js/analytics';
 *
 * $: if (page.props.zbAnalytics) {
 *     cleanup = initAll(page.props);
 * }
 *
 * // On destroy:
 * // cleanup?.();
 */

// ─── Session Heartbeat ──────────────────────────────────────────────────

let heartbeatTimer = null;
let heartbeatIntervalSeconds = 60;

/**
 * Start a periodic session heartbeat.
 *
 * Tracks a `session_heartbeat` event at a configurable interval
 * (default: 60 seconds) while the session is active. Useful for
 * measuring actual time-on-site and detecting abandoned sessions.
 *
 * The heartbeat event includes session duration, event count since
 * last heartbeat, and current page path.
 *
 * @param {number} [intervalSeconds=60] - Heartbeat interval in seconds
 * @returns {function} Cleanup function to stop the heartbeat
 *
 * @example
 * // Start heartbeat with 30-second interval
 * const cleanupHeartbeat = initSessionHeartbeat(30);
 *
 * // On destroy:
 * // cleanupHeartbeat?.();
 */
export function initSessionHeartbeat(intervalSeconds = 60) {
    if (!initialized) return () => {};

    stopSessionHeartbeat();

    heartbeatIntervalSeconds = Math.max(10, Math.min(300, intervalSeconds));

    heartbeatTimer = setInterval(() => {
        if (!sessionState.active) return;

        const duration = Math.round((Date.now() - (sessionState.startTime || Date.now())) / 1000);

        trackEvent('session_heartbeat', {
            session_id: sessionState.id,
            duration_seconds: duration,
            events_since_start: sessionState.eventCount,
            page_views: sessionState.pageViewCount,
            page_path: window.location.pathname,
        }, { immediate: true });
    }, heartbeatIntervalSeconds * 1000);

    return () => {
        stopSessionHeartbeat();
    };
}

/**
 * Stop the session heartbeat timer.
 */
export function stopSessionHeartbeat() {
    if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
        heartbeatTimer = null;
    }
}

/**
 * Check if the session heartbeat is active.
 *
 * @returns {boolean}
 */
export function isHeartbeatActive() {
    return heartbeatTimer !== null;
}

// ─── Performance Budget ────────────────────────────────────────────

/**
 * Check if the analytics library has performance budget constraints.
 *
 * @returns {boolean}
 */
export function hasPerformanceBudget() {
    return initialized && config?.performanceBudget?.enabled === true;
}

/**
 * Get the configured performance budget limits.
 *
 * @returns {object|null} Performance budget config or null if not configured
 */
export function getPerformanceBudget() {
    return config?.performanceBudget || null;
}

/**
 * Estimate the payload size of an event in bytes.
 *
 * @param {string} name - Event name
 * @param {object} params - Event parameters
 * @returns {number} Approximate payload size in bytes
 */
export function estimatePayloadSize(name, params = {}) {
    return new Blob([JSON.stringify({ name, params })]).size;
}

/**
 * Check if an event would exceed the configured payload budget.
 *
 * @param {string} name - Event name
 * @param {object} params - Event parameters
 * @returns {boolean} True if the event exceeds the budget
 */
export function exceedsPayloadBudget(name, params = {}) {
    if (!hasPerformanceBudget()) return false;
    return estimatePayloadSize(name, params) > (config?.performanceBudget?.max_payload_bytes || 8192);
}

// ─── Consent-Aware Pre-Queue ─────────────────────────────────────

/**
 * Pre-queue for events fired before consent is resolved.
 *
 * When consent has not yet been resolved (user hasn't interacted with
 * the cookie banner), events are buffered in this queue. When consent
 * is granted, the queue is replayed. When denied, the queue is discarded.
 *
 * This prevents data loss for early-page events (page_view, scroll)
 * while ensuring GDPR compliance for events dispatched before consent.
 *
 * @type {Array<{name: string, params: object, options?: object}>}
 */
const consentPreQueue = [];
const MAX_CONSENT_PRE_QUEUE = 50;

/**
 * Queue an event before consent is resolved.
 *
 * Events are buffered until consentGranted() or consentDenied() is called.
 * Max 50 events are buffered; excess events are silently dropped.
 *
 * @param {string} name - Event name
 * @param {object} params - Event parameters
 * @param {object} [options] - Track options (immediate, consent_skip)
 */
function queueBeforeConsent(name, params, options = {}) {
    if (consentPreQueue.length >= MAX_CONSENT_PRE_QUEUE) {
        consentPreQueue.shift(); // Drop oldest
    }
    consentPreQueue.push({ name, params, options });
}

/**
 * Replay all queued events after consent is granted.
 *
 * Each queued event is dispatched through the normal trackEvent pipeline.
 * The pre-queue is cleared after replay.
 */
function replayConsentPreQueue() {
    const events = [...consentPreQueue];
    consentPreQueue.length = 0;

    for (const { name, params, options } of events) {
        trackEvent(name, params, { ...options, consent_skip: true });
    }
}

/**
 * Discard all queued events after consent is denied.
 *
 * Events are silently dropped without being dispatched.
 */
function discardConsentPreQueue() {
    consentPreQueue.length = 0;
}

/**
 * Signal that the user has granted consent.
 *
 * Replays all events that were queued before consent resolution.
 * Sets the internal consent state so future events dispatch normally.
 */
export function consentGranted() {
    consentResolved = true;
    replayConsentPreQueue();
}

/**
 * Signal that the user has denied consent.
 *
 * Discards all queued events and sets the internal consent state
 * so future events are silently dropped until consentGranted().
 */
export function consentDenied() {
    consentResolved = false;
    discardConsentPreQueue();
}

/**
 * Get the current consent resolution state.
 *
 * @returns {boolean|null} true = granted, false = denied, null = pending
 */
export function getConsentState() {
    return consentResolved;
}

/**
 * Get the count of events currently queued before consent.
 *
 * @returns {number}
 */
export function getConsentPreQueueCount() {
    return consentPreQueue.length;
}

/**
 * Clear the consent state (reset to pending/unresolved).
 *
 * Useful when the user opens the consent banner again
 * or when navigating to a new session.
 */
export function resetConsentState() {
    consentResolved = null;
}

// ─── Consent Purposes (GDPR Granular) ────────────────────────────────

/**
 * Get the consent purposes configuration from the server.
 *
 * Returns the granular GDPR consent purposes exposed via Inertia props.
 * Each purpose has a label, required flag, and default value.
 * 'necessary' is always required and cannot be denied.
 *
 * @returns {Object<string, {label: string, required: boolean, default: boolean}>}
 *
 * @example
 * const purposes = getConsentPurposes();
 * // {
 * //   necessary: { label: 'Necessary', required: true, default: true },
 * //   analytics: { label: 'Analytics', required: false, default: true },
 * //   marketing: { label: 'Marketing', required: false, default: false },
 * //   functional: { label: 'Functional', required: false, default: true },
 * // }
 */
export function getConsentPurposes() {
    return config?.consentPurposes || {};
}

/**
 * Get a flat list of consent purpose keys.
 *
 * @returns {string[]} Array of purpose keys (e.g. ['necessary', 'analytics', 'marketing', 'functional'])
 */
export function getConsentPurposeKeys() {
    return Object.keys(getConsentPurposes());
}

/**
 * Get consent purposes that the user can toggle (non-required).
 *
 * Useful for building consent banners — only non-required purposes
 * should show toggle switches.
 *
 * @returns {Object<string, {label: string, default: boolean}>}
 */
export function getOptionalConsentPurposes() {
    const purposes = getConsentPurposes();
    const optional = {};
    for (const [key, val] of Object.entries(purposes)) {
        if (!val.required) {
            optional[key] = { label: val.label, default: val.default };
        }
    }
    return optional;
}

/**
 * Build a consent signals object from purpose grants/denials.
 *
 * Maps granular purpose keys to Consent Mode v2 signals.
 * Automatically includes 'necessary' as granted.
 *
 * @param {Object<string, boolean>} grants - Purpose key → granted/denied
 * @returns {Object<string, 'granted'|'denied'>} Consent Mode signals
 *
 * @example
 * const signals = buildConsentSignals({
 *     analytics: true,
 *     marketing: false,
 *     functional: true,
 * });
 * // { analytics_storage: 'granted', ad_storage: 'denied', ... }
 */
export function buildConsentSignals(grants) {
    const purposes = getConsentPurposes();
    const mapping = {
        necessary: 'security_storage',
        analytics: 'analytics_storage',
        marketing: 'ad_storage',
        functional: 'functionality_storage',
        // Additional mappings for future purposes
        performance: 'functionality_storage',
        personalization: 'ad_personalization',
    };

    const signals = {};

    // Always grant required purposes
    for (const [key, purpose] of Object.entries(purposes)) {
        if (purpose.required) {
            const signal = mapping[key] || 'security_storage';
            signals[signal] = 'granted';
        }
    }

    // Map user grants to signals
    for (const [key, granted] of Object.entries(grants)) {
        const signal = mapping[key];
        if (signal) {
            signals[signal] = granted ? 'granted' : 'denied';
        }
    }

    // Ensure all 6 Consent Mode v2 signals are present
    const allSignals = [
        'analytics_storage', 'ad_storage', 'ad_user_data',
        'ad_personalization', 'functionality_storage', 'security_storage',
    ];
    for (const signal of allSignals) {
        if (!(signal in signals)) {
            // Default denied for unspecified signals
            signals[signal] = 'denied';
        }
    }

    return signals;
}

// ─── SaaS Journey Milestone Tracking ──────────────────────────────────

/**
 * Record a milestone hit in a SaaS journey.
 *
 * Tracks progression through configurable multi-step journeys (e.g.,
 * signup → trial → subscription). When all milestones in a journey
 * are completed, a `journey_completed` event is dispatched server-side
 * with full timing metadata.
 *
 * @param {string} journey - Journey name (e.g., 'acquisition', 'trial')
 * @param {string} milestone - Milestone identifier (e.g., 'signup_confirm')
 * @param {object} [params] - Additional event parameters
 * @returns {Promise<object|null>} Journey progress after hit, or null on failure
 *
 * @example
 * await trackJourneyMilestone('acquisition', 'signup_confirm', { plan: 'pro' });
 * await trackJourneyMilestone('trial', 'trial_start', { trial_days: 14 });
 */
export async function trackJourneyMilestone(journey, milestone, params = {}) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/journeys/${encodeURIComponent(journey)}/milestone`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
            body: JSON.stringify({ milestone, params }),
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Get progress for a specific journey.
 *
 * @param {string} journey - Journey name
 * @returns {Promise<object|null>} Journey progress or null on failure
 *
 * @example
 * const progress = await getJourneyProgress('trial');
 * // { journey: 'trial', completed: false, completed_milestones: ['trial_start'], ... }
 */
export async function getJourneyProgress(journey) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/journeys/${encodeURIComponent(journey)}/progress`, {
            headers: {
                Accept: 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
            },
        });

        if (!response.ok) return null;

        const data = await response.json();
        return data.progress || null;
    } catch {
        return null;
    }
}

/**
 * Get all registered journeys and their progress for the current client.
 *
 * @returns {Promise<{journeys: object, progress: object}|null>}
 *
 * @example
 * const result = await getAllJourneys();
 * // result.journeys = { acquisition: { label: 'Acquisition Funnel', milestones: [...] }, ... }
 * // result.progress = { acquisition: { completed: true, progress_percent: 100 }, ... }
 */
export async function getAllJourneys() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/journeys`, {
            headers: {
                Accept: 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Reset progress for a specific journey.
 *
 * @param {string} journey - Journey name
 * @returns {Promise<boolean>} True if reset was successful
 *
 * @example
 * await resetJourneyProgress('acquisition');
 */
export async function resetJourneyProgress(journey) {
    if (!initialized) return false;

    try {
        const response = await fetch(`${apiBaseUrl}/journeys/${encodeURIComponent(journey)}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
            },
        });

        return response.ok;
    } catch {
        return false;
    }
}

// ─── Forwarding Config ─────────────────────────────────────────────

/**
 * Check if event forwarding is configured.
 *
 * @returns {boolean}
 */
export function isForwardingEnabled() {
    return initialized && config?.forwarding?.enabled === true;
}

/**
 * Get the list of configured forwarder names.
 *
 * @returns {string[]} Array of forwarder names
 */
export function getForwarderNames() {
    if (!isForwardingEnabled()) return [];
    return Object.keys(config?.forwarding?.forwarders || {});
}

// ─── Version ───────────────────────────────────────────────────────

/**
 * Get the library version string.
 *
 * @returns {string} Semantic version (e.g. '2.62.0')
 */
export function _getInternalVersion() {
      return '15.0.0';
}

// ─── Inertia Page View Auto-Tracker (v2.96.0) ────────────────────

/**
 * Initialize automatic Inertia page view tracking.
 *
 * Hooks into the Inertia.js event system to automatically fire `trackPageView()`
 * on every SPA navigation. Handles both initial page load and subsequent
 * client-side navigations. Compatible with Svelte, Vue, and React Inertia adapters.
 *
 * Uses the proper Inertia event system via `page` store subscription for
 * framework-agnostic compatibility.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackInitial=true] - Track the initial page view immediately
 * @param {number} [options.delayMs=50] - Delay after navigation before tracking (ms)
 * @param {Function} [options.onTrack] - Callback after each page view is tracked (receives title, location)
 * @param {boolean} [options.enableScrollDepth=false] - Also enable scroll depth tracking
 * @returns {Function} Cleanup function to stop tracking
 *
 * @example
 * // Svelte
 * import { onMount } from 'svelte';
 * import { initInertiaPageViewTracker } from '../resources/js/analytics';
 *
 * onMount(() => {
 *     const cleanup = initInertiaPageViewTracker({ enableScrollDepth: true });
 *     return cleanup;
 * });
 *
 * // Vue
 * import { onMounted, onUnmounted } from 'vue';
 * import { initInertiaPageViewTracker } from '../resources/js/analytics';
 *
 * onMounted(() => {
 *     cleanup = initInertiaPageViewTracker();
 * });
 * onUnmounted(() => { cleanup?.(); });
 *
 * // React
 * import { useEffect } from 'react';
 * import { initInertiaPageViewTracker } from '../resources/js/analytics';
 *
 * useEffect(() => initInertiaPageViewTracker(), []);
 */
export function initInertiaPageViewTracker(options = {}) {
    const {
        trackInitial = true,
        delayMs = 50,
        onTrack = null,
        enableScrollDepth = false,
    } = options;

    if (!initialized) return () => {};

    const cleanupFns = [];

    // Track initial page view
    if (trackInitial) {
        trackPageView();
        if (onTrack) onTrack(document.title, window.location.href);
    }

    // Enable scroll depth if requested
    if (enableScrollDepth) {
        cleanupFns.push(initScrollDepthTracker());
    }

    // Track subsequent Inertia navigations
    // Try multiple Inertia event patterns for compatibility across adapters
    let previousUrl = window.location.href;

    // Pattern 1: inertia:navigate event (Inertia.js v1.x)
    const handleNavigate = () => {
        setTimeout(() => {
            const currentUrl = window.location.href;
            if (currentUrl !== previousUrl) {
                previousUrl = currentUrl;
                trackPageView();
                if (onTrack) onTrack(document.title, currentUrl);
            }
        }, delayMs);
    };
    window.addEventListener('inertia:navigate', handleNavigate);
    cleanupFns.push(() => window.removeEventListener('inertia:navigate', handleNavigate));

    // Pattern 2: inertia:success event (fired after successful navigation completes)
    const handleSuccess = () => {
        setTimeout(() => {
            trackPageView();
            if (onTrack) onTrack(document.title, window.location.href);
        }, delayMs);
    };
    window.addEventListener('inertia:success', handleSuccess);
    cleanupFns.push(() => window.removeEventListener('inertia:success', handleSuccess));

    // Pattern 3: PopState event fallback (covers browser back/forward)
    const handlePopState = () => {
        setTimeout(() => {
            trackPageView();
            if (onTrack) onTrack(document.title, window.location.href);
        }, delayMs);
    };
    window.addEventListener('popstate', handlePopState);
    cleanupFns.push(() => window.removeEventListener('popstate', handlePopState));

    return function cleanupInertiaPageViewTracker() {
        cleanupFns.forEach((fn) => {
            if (typeof fn === 'function') fn();
        });
    };
}

// ─── Svelte Tracker (Zero-Config Component) ────────────────────────

/**
 * Initialize analytics with auto-page-view tracking for Svelte/Inertia.
 *
 * A zero-config wrapper that calls `init()` and sets up automatic
 * page view tracking on Inertia navigation events. Returns a cleanup
 * function suitable for Svelte's `onMount()`.
 *
 * @param {object} pageProps - Inertia page props containing `zbAnalytics`
 * @param {object} [options] - Tracking options
 * @param {boolean} [options.trackPageViews=true] - Auto-track page views on navigation
 * @param {boolean} [options.enableAllAutoTrackers=false] - Also enable scroll, form, error, session tracking
 * @returns {Function} Cleanup function for Svelte `onMount`
 *
 * @example
 * // Svelte component:
 * import { onMount } from 'svelte';
 * import { page } from '@inertiajs/svelte';
 * import { initSvelteTracker } from '../resources/js/analytics';
 *
 * let cleanup;
 * $: if (page.props.zbAnalytics) {
 *     onMount(() => {
 *         cleanup = initSvelteTracker(page.props);
 *     });
 * }
 * // Cleanup on destroy is handled automatically by the returned function
 */
export function initSvelteTracker(pageProps, options = {}) {
    const { trackPageViews = true, enableAllAutoTrackers = false } = options;

    init(pageProps);

    const cleanupFns = [];

    if (trackPageViews) {
        // Track initial page view
        trackPageView();

        // Listen for Inertia `page` event (SPA navigation)
        const handleInertiaNavigate = () => {
            setTimeout(() => trackPageView(), 50);
        };
        window.addEventListener('inertia:navigate', handleInertiaNavigate);
        cleanupFns.push(() => window.removeEventListener('inertia:navigate', handleInertiaNavigate));
    }

    if (enableAllAutoTrackers) {
        cleanupFns.push(initScrollDepthTracker());
        cleanupFns.push(initFormTracker());
        cleanupFns.push(initErrorTracker());
        cleanupFns.push(initSessionTracker());
    }

    // Unified cleanup function
    return function cleanup() {
        cleanupFns.forEach((fn) => {
            if (typeof fn === 'function') fn();
        });
        destroy();
    };
}

// ─── Paid Ad Click Tracking ────────────────────────────────────────

/**
 * Track a paid advertisement click.
 *
 * @param {object} params - Ad click parameters
 * @param {string} params.platform - Ad platform (google, meta, tiktok, linkedin, twitter)
 * @param {string} params.campaignId - Campaign identifier
 * @param {string} params.adGroupId - Ad group identifier
 * @param {string} params.creativeId - Creative/copy identifier
 * @param {string} [params.placement] - Ad placement position
 * @param {string} [params.keyword] - Matched keyword
 * @param {number} [params.cost] - Cost-per-click
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackAdClick({ platform: 'google', campaignId: 'camp-001', adGroupId: 'grp-002', creativeId: 'crt-003' });
 */
export async function trackAdClick({ platform, campaignId, adGroupId, creativeId, placement, keyword, cost }) {
    return trackEvent('ad_click', {
        platform,
        campaign_id: campaignId,
        ad_group_id: adGroupId,
        creative_id: creativeId,
        ...(placement != null ? { placement } : {}),
        ...(keyword != null ? { keyword } : {}),
        ...(cost != null ? { cost } : {}),
    });
}

// ─── Content Engagement Tracking ───────────────────────────────────

/**
 * Track content engagement (article reading, video watching, etc.)
 *
 * @param {object} params - Content engagement parameters
 * @param {string} params.contentType - Type (article, video, document, podcast)
 * @param {string} params.contentId - Content identifier or slug
 * @param {string} [params.title] - Content title
 * @param {string} [params.author] - Content author
 * @param {string} [params.category] - Content category
 * @param {number} [params.engagementPercent] - Engagement depth 0-100
 * @param {number} [params.timeSpentSeconds] - Time spent in seconds
 * @param {boolean} [params.completed] - Whether user reached the end
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackContentEngagement({ contentType: 'article', contentId: 'blog-123', engagementPercent: 75, timeSpentSeconds: 180 });
 */
export async function trackContentEngagement({ contentType, contentId, title, author, category, engagementPercent, timeSpentSeconds, completed }) {
    return trackEvent('content_engagement', {
        content_type: contentType,
        content_id: contentId,
        ...(title != null ? { title } : {}),
        ...(author != null ? { author } : {}),
        ...(category != null ? { category } : {}),
        ...(engagementPercent != null ? { engagement_percent: engagementPercent } : {}),
        ...(timeSpentSeconds != null ? { time_spent_seconds: timeSpentSeconds } : {}),
        ...(completed != null ? { completed } : {}),
    });
}

// ─── Onboarding Step Tracking ──────────────────────────────────────

/**
 * Track a SaaS onboarding step for product activation funnel analysis.
 *
 * @param {object} params - Onboarding step parameters
 * @param {string} params.stepName - Step name (e.g. "profile_setup")
 * @param {number} params.stepIndex - Zero-based step order
 * @param {number} params.totalSteps - Total number of steps
 * @param {string} [params.method] - Entry method (invite, organic, paid)
 * @param {boolean} [params.completed] - Whether step was completed
 * @param {number} [params.durationSeconds] - Time to complete this step
 * @param {string} [params.skippedReason] - Reason for skipping
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackOnboardingStep({ stepName: 'profile_setup', stepIndex: 0, totalSteps: 5, completed: true, durationSeconds: 45 });
 */
export async function trackOnboardingStep({ stepName, stepIndex, totalSteps, method, completed, durationSeconds, skippedReason }) {
    return trackEvent('onboarding_step', {
        step_name: stepName,
        step_index: stepIndex,
        total_steps: totalSteps,
        ...(method != null ? { method } : {}),
        ...(completed != null ? { completed } : {}),
        ...(durationSeconds != null ? { duration_seconds: durationSeconds } : {}),
        ...(skippedReason != null ? { skipped_reason: skippedReason } : {}),
    });
}

// ─── Feature Impression Tracking ──────────────────────────────────

/**
 * Track when a user sees a feature, UI element, or upgrade prompt.
 *
 * @param {object} params - Feature impression parameters
 * @param {string} params.featureName - Feature or element name
 * @param {string} params.location - Where it appeared (dashboard, sidebar, modal, banner)
 * @param {string} [params.source] - Trigger source (auto, navigation, recommendation)
 * @param {string} [params.variant] - A/B test variant identifier
 * @param {string} [params.context] - Additional context (page URL, section name)
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackFeatureImpression({ featureName: 'export_csv', location: 'dashboard', source: 'auto' });
 */
export async function trackFeatureImpression({ featureName, location, source, variant, context }) {
    return trackEvent('feature_impression', {
        feature_name: featureName,
        location,
        ...(source != null ? { source } : {}),
        ...(variant != null ? { variant } : {}),
        ...(context != null ? { context } : {}),
    });
}

// ─── Checkout Step Tracking ───────────────────────────────────────

/**
 * Track an e-commerce checkout step for funnel analysis.
 *
 * @param {object} params - Checkout step parameters
 * @param {number} params.stepIndex - One-based checkout step number
 * @param {string} [params.stepName] - Step name
 * @param {string} [params.paymentMethod] - Payment method type
 * @param {number} [params.orderTotal] - Running order total
 * @param {string} [params.currency] - ISO 4217 currency code
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackCheckoutStep({ stepIndex: 1, stepName: 'shipping', orderTotal: 49.99, currency: 'USD' });
 */
export async function trackCheckoutStep({ stepIndex, stepName, paymentMethod, orderTotal, currency }) {
    return trackEvent('checkout_step', {
        step_index: stepIndex,
        ...(stepName != null ? { step_name: stepName } : {}),
        ...(paymentMethod != null ? { payment_method: paymentMethod } : {}),
        ...(orderTotal != null ? { order_total: orderTotal } : {}),
        ...(currency != null ? { currency } : {}),
    });
}

// ─── SaaS Subscription & Revenue Tracking ──────────────────────────

/**
 * Track a SaaS subscription lifecycle event.
 *
 * Unified helper for all subscription-related events: created, renewed,
 * upgraded, downgraded, cancelled, trial_start, trial_end.
 *
 * @param {object} params - Subscription event parameters
 * @param {'created'|'renewed'|'upgraded'|'downgraded'|'cancelled'|'trial_start'|'trial_end'} params.action - Subscription action
 * @param {string} [params.planName] - Plan name (e.g., "Pro", "Enterprise")
 * @param {number} [params.planPrice] - Plan price per billing cycle
 * @param {string} [params.billingCycle] - Billing cycle (monthly, yearly, lifetime)
 * @param {string} [params.currency] - ISO 4217 currency code (default from config)
 * @param {string} [params.reason] - Cancellation/downgrade reason
 * @param {object} [params.meta] - Additional metadata
 * @returns {Promise<boolean>}
 *
 * @example
 * // New subscription
 * await trackSubscriptionEvent({ action: 'created', planName: 'Pro', planPrice: 29, billingCycle: 'monthly' });
 *
 * // Cancellation with reason
 * await trackSubscriptionEvent({ action: 'cancelled', planName: 'Pro', reason: 'too_expensive' });
 */
export async function trackSubscriptionEvent({ action, planName, planPrice, billingCycle, currency, reason, meta }) {
    const eventMap = {
        created: 'subscribe',
        renewed: 'subscription_renewal',
        upgraded: 'plan_upgrade',
        downgraded: 'plan_downgrade',
        cancelled: 'cancellation',
        trial_start: 'start_trial',
        trial_end: 'trial_end',
    };

    const eventName = eventMap[action] || 'subscription';

    return trackEvent(eventName, {
        plan_name: planName || null,
        plan_price: planPrice || null,
        billing_cycle: billingCycle || null,
        currency: currency || null,
        reason: reason || null,
        action,
        ...meta,
    });
}

/**
 * Track a trial lifecycle event.
 *
 * Convenience wrapper around trackSubscriptionEvent for trial-specific events.
 * Automatically sets the action based on trial state.
 *
 * @param {object} params - Trial event parameters
 * @param {'start'|'active'|'converted'|'expired'} params.state - Trial state
 * @param {string} [params.planName] - Plan being trialed
 * @param {number} [params.trialDays] - Trial duration in days
 * @param {number} [params.daysUsed] - Days used so far
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackTrialEvent({ state: 'start', planName: 'Pro', trialDays: 14 });
 * await trackTrialEvent({ state: 'converted', planName: 'Pro', daysUsed: 7 });
 */
export async function trackTrialEvent({ state, planName, trialDays, daysUsed }) {
    return trackEvent(state === 'start' ? 'start_trial' : 'trial_end', {
        plan_name: planName || null,
        trial_days: trialDays || null,
        days_used: daysUsed || null,
        trial_state: state,
        outcome: state === 'converted' ? 'converted' : state,
    });
}

/**
 * Track a revenue event for SaaS billing.
 *
 * Use this for payment success/failure, invoice generation, credit applications,
 * and any financial transaction that doesn't fit purchase/refund.
 *
 * @param {object} params - Revenue event parameters
 * @param {'payment_succeeded'|'payment_failed'|'invoice'|'credit'} params.type - Revenue event type
 * @param {number} [params.amount] - Transaction amount
 * @param {string} [params.currency] - ISO 4217 currency code
 * @param {string} [params.planName] - Related plan name
 * @param {string} [params.invoiceId] - Invoice ID
 * @param {string} [params.paymentMethod] - Payment method (stripe, paypal, etc.)
 * @param {string} [params.failureReason] - Reason for payment failure
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackRevenueEvent({ type: 'payment_succeeded', amount: 29.00, currency: 'USD', planName: 'Pro' });
 * await trackRevenueEvent({ type: 'payment_failed', amount: 29.00, failureReason: 'card_expired' });
 */
export async function trackRevenueEvent({ type, amount, currency, planName, invoiceId, paymentMethod, failureReason }) {
    const eventMap = {
        payment_succeeded: 'payment_succeeded',
        payment_failed: 'payment_failed',
        invoice: 'invoice_generated',
        credit: 'credit_applied',
    };

    const eventName = eventMap[type] || 'revenue_tracked';

    return trackEvent(eventName, {
        amount: amount || null,
        currency: currency || null,
        plan_name: planName || null,
        invoice_id: invoiceId || null,
        payment_method: paymentMethod || null,
        failure_reason: failureReason || null,
        revenue_type: type,
    });
}

/**
 * Track a plan change event (upgrade or downgrade).
 *
 * Automatically calculates the price difference and direction.
 *
 * @param {object} params - Plan change parameters
 * @param {string} params.fromPlan - Previous plan name
 * @param {string} params.toPlan - New plan name
 * @param {number} [params.fromPrice] - Previous plan price
 * @param {number} [params.toPrice] - New plan price
 * @param {string} [params.currency] - Currency code
 * @param {string} [params.reason] - Change reason
 * @returns {Promise<boolean>}
 *
 * @example
 * await trackPlanChange({ fromPlan: 'Starter', toPlan: 'Pro', fromPrice: 9, toPrice: 29, currency: 'USD' });
 */
export async function trackPlanChange({ fromPlan, toPlan, fromPrice, toPrice, currency, reason }) {
    const direction = (toPrice || 0) > (fromPrice || 0) ? 'upgrade' : 'downgrade';
    const priceDiff = ((toPrice || 0) - (fromPrice || 0));

    return trackEvent(direction === 'upgrade' ? 'plan_upgrade' : 'plan_downgrade', {
        from_plan: fromPlan,
        to_plan: toPlan,
        from_price: fromPrice || null,
        to_price: toPrice || null,
        price_difference: priceDiff,
        currency: currency || null,
        reason: reason || null,
        direction,
    });
}

// ─── First-Touch UTM Cookie Persistence (v2.67.0) ───────────────────────

/**
 * Cookie name for first-touch attribution persistence.
 * Persists across sessions (365-day TTL) unlike sessionStorage UTM capture.
 */
const FIRST_TOUCH_COOKIE = 'zb_first_touch_utm';
const FIRST_TOUCH_TTL_DAYS = 365;

/**
 * Capture and persist first-touch UTM parameters in a long-lived cookie.
 *
 * Unlike captureUTM() which stores in sessionStorage (per-session),
 * this function writes to a cookie that persists for 365 days. The first
 * UTM parameters ever seen for this browser are preserved and never
 * overwritten, enabling true first-touch attribution analysis.
 *
 * Called automatically during init() when UTM params are present.
 * Safe to call multiple times — only persists on first encounter.
 *
 * @returns {object} First-touch UTM parameters (current or previously stored)
 *
 * @example
 * // Called automatically during init(), or manually:
 * const firstTouch = getFirstTouchUTM();
 * if (firstTouch.utm_source) {
 *     console.log('First acquired via:', firstTouch.utm_source);
 * }
 */
export function getFirstTouchUTM() {
    try {
        const stored = getCookie(FIRST_TOUCH_COOKIE);
        if (stored) {
            try {
                return JSON.parse(stored);
            } catch {
                return {};
            }
        }
    } catch {
        // Cookie access failed
    }

    return {};
}

/**
 * Capture first-touch UTM and persist to cookie (internal).
 * Called during init() when fresh UTM params are detected.
 */
function persistFirstTouchUTM(utm) {
    if (typeof document === 'undefined') return;

    // Only persist if we don't already have first-touch data
    const existing = getFirstTouchUTM();
    if (Object.keys(existing).length > 0) return;

    // Only persist if there's actual UTM data
    const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    const firstTouch = {};
    for (const key of keys) {
        if (utm[key]) {
            firstTouch[key] = utm[key];
        }
    }
    firstTouch.first_touch_timestamp = new Date().toISOString();
    firstTouch.first_touch_page = window.location.pathname + window.location.search;

    if (Object.keys(firstTouch).length <= 2) return; // Only timestamp + page, no UTM

    try {
        const expires = new Date();
        expires.setDate(expires.getDate() + FIRST_TOUCH_TTL_DAYS);
        document.cookie = `${FIRST_TOUCH_COOKIE}=${encodeURIComponent(JSON.stringify(firstTouch))};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
    } catch {
        // Cookie write failed
    }
}

/**
 * Get a cookie value by name.
 * @param {string} name
 * @returns {string|null}
 */
function getCookie(name) {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
}

/**
 * Get the full attribution context (first-touch + last-touch UTM).
 *
 * Returns both the long-lived first-touch cookie data and the
 * current session's UTM parameters for multi-touch attribution.
 *
 * @returns {object} { first_touch: object, last_touch: object }
 *
 * @example
 * const attribution = getAttributionContext();
 * await trackEvent('sign_up', {
 *     ...attribution.first_touch,
 *     ...attribution.last_touch,
 *     attribution_model: 'first_touch',
 * });
 */
export function getAttributionContext() {
    return {
        first_touch: getFirstTouchUTM(),
        last_touch: utmParams,
    };
}

/**
 * Clear first-touch UTM cookie (for testing or GDPR erasure).
 */
export function clearFirstTouchUTM() {
    try {
        document.cookie = `${FIRST_TOUCH_COOKIE}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
    } catch {
        // Cookie deletion failed
    }
}

// ─── Data Warehouse Export Helper (v2.67.0) ────────────────────────────

/**
 * Trigger a server-side data warehouse export.
 *
 * Requests the server to export analytics events to NDJSON or CSV
 * for data warehouse ingestion (Snowflake, BigQuery, Redshift).
 *
 * @param {object} [options] - Export options
 * @param {'ndjson'|'csv'} [options.format='ndjson'] - Output format
 * @param {string} [options.category] - Filter by event category
 * @param {string} [options.event] - Filter by event name
 * @returns {Promise<object|null>} Export result { path, format, events, bytes }
 *
 * @example
 * const result = await exportToDataWarehouse({ format: 'ndjson', category: 'saas' });
 * console.log(`Exported ${result.events} events to ${result.path}`);
 */
export async function exportToDataWarehouse(options = {}) {
    if (!initialized) return null;

    try {
        const params = new URLSearchParams();
        if (options.format) params.set('format', options.format);
        if (options.category) params.set('category', options.category);
        if (options.event) params.set('event', options.event);

        const response = await fetch(`${apiBaseUrl}/export/warehouse?${params}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Dashboard Data Helper (v2.67.0) ──────────────────────────────────

/**
 * Fetch the analytics dashboard overview from the server.
 *
 * Returns a unified payload with provider status, event catalog summary,
 * KPI metrics, health score, real-time stats, and active alerts.
 *
 * @returns {Promise<object|null>} Dashboard overview data
 *
 * @example
 * const dashboard = await fetchDashboardOverview();
 * console.log(dashboard.health_score.score);
 * console.log(dashboard.kpi.mrr);
 */
export async function fetchDashboardOverview() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/dashboard`, {
            headers: {
                ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                Accept: 'application/json',
            },
        });

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Click Heatmap Tracking (v2.69.0) ─────────────────────────────────

/**
 * Record a click for heatmap aggregation.
 *
 * Sends click coordinates to the server for grid-based heatmap data collection.
 * Coordinates are bucketed into grid cells on the server (default 50px) for
 * GDPR data minimization — exact pixel positions are never stored.
 *
 * @param {number} x - Click X coordinate (clientX)
 * @param {number} y - Click Y coordinate (clientY)
 * @param {object} [options] - Additional options
 * @param {string} [options.element] - Target element tag or selector
 * @param {number} [options.viewportWidth] - Viewport width in pixels
 * @returns {Promise<void>}
 *
 * @example
 * document.addEventListener('click', (e) => {
 *     recordHeatmapClick(e.clientX, e.clientY, {
 *         element: e.target.tagName.toLowerCase(),
 *         viewportWidth: window.innerWidth,
 *     });
 * });
 */
export async function recordHeatmapClick(x, y, options = {}) {
    if (!initialized) return;

    try {
        await fetch(`${apiBaseUrl}/heatmap/click`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Analytics-Client-Id': trackingId,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                x: Math.round(x),
                y: Math.round(y),
                url: window.location.pathname + window.location.search,
                element: options.element || null,
                viewport_width: options.viewportWidth || window.innerWidth,
            }),
        });
    } catch {
        // Silent fail — heatmap is non-critical
    }
}

/**
 * Initialize automatic click heatmap tracking.
 *
 * Records all clicks on the page for heatmap visualization.
 * Coordinates are sent to the server and bucketed into grid cells.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackAll=true] - Track all clicks (not just interactive elements)
 * @param {string[]} [options.ignoreSelectors] - CSS selectors to ignore
 * @returns {function} Cleanup function to remove listeners
 *
 * @example
 * const cleanup = initHeatmapTracking({
 *     ignoreSelectors: ['.no-heatmap', '#toolbar'],
 * });
 */
export function initHeatmapTracking(options = {}) {
    if (!initialized) return () => {};

    const { trackAll = true, ignoreSelectors = [] } = options;

    function onClick(e) {
        // Check if click target matches any ignore selector
        if (ignoreSelectors.length > 0) {
            for (const selector of ignoreSelectors) {
                if (e.target.closest(selector)) return;
            }
        }

        const tag = e.target.tagName?.toLowerCase() || 'unknown';
        const isInteractive = ['a', 'button', 'input', 'select', 'textarea', 'label'].includes(tag);

        if (!trackAll && !isInteractive) return;

        recordHeatmapClick(e.clientX, e.clientY, {
            element: tag,
            viewportWidth: window.innerWidth,
        });
    }

    document.addEventListener('click', onClick, true);

    return () => document.removeEventListener('click', onClick, true);
}

/**
 * Fetch heatmap data for a specific URL.
 *
 * @param {string} url - Page URL path (defaults to current page)
 * @returns {Promise<object|null>} Heatmap data with heat zones and hotspots
 *
 * @example
 * const heatmap = await fetchHeatmapData('/pricing');
 * if (heatmap) {
 *     console.log('Total clicks:', heatmap.total);
 *     console.log('Hotspots:', heatmap.hotspots);
 * }
 */
export async function fetchHeatmapData(url) {
    if (!initialized) return null;

    try {
        const pageUrl = url || window.location.pathname + window.location.search;
        const response = await fetch(`${apiBaseUrl}/heatmap/data?${new URLSearchParams({ url: pageUrl })}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Event Deconfliction Helper (v2.69.0) ─────────────────────────────

/**
 * Fetch event deconfliction analysis from the server.
 *
 * Detects event name collisions across providers, similar event names,
 * and reverse mapping conflicts. Useful for debugging multi-provider setups.
 *
 * @returns {Promise<object|null>} Deconfliction report with conflicts, warnings, and summary
 *
 * @example
 * const report = await fetchDeconflictionReport();
 * if (report?.summary.total_conflicts > 0) {
 *     console.warn('Event name conflicts detected:', report.conflicts);
 * }
 */
export async function fetchDeconflictionReport() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/deconfliction`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Schema Inference Helper (v2.69.0) ──────────────────────────────

/**
 * Fetch inferred event schemas from the server.
 *
 * The server scans all event class constructors and generates typed
 * parameter schemas. Useful for documentation generation and bootstrapping
 * schema validation.
 *
 * @param {object} [options] - Options
 * @param {boolean} [options.forceRefresh=false] - Force re-inference
 * @returns {Promise<object|null>} Inferred schemas with counts and errors
 *
 * @example
 * const inference = await fetchInferredSchemas();
 * if (inference) {
 *     console.log(`Inferred ${inference.inferred_count} event schemas`);
 * }
 */
export async function fetchInferredSchemas(options = {}) {
    if (!initialized) return null;

    try {
        const params = new URLSearchParams();
        if (options.forceRefresh) params.set('refresh', '1');

        const response = await fetch(`${apiBaseUrl}/schemas/infer?${params}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Rate Limit Status (v2.69.0) ────────────────────────────────────

/**
 * Fetch the analytics rate limit dashboard overview.
 *
 * @returns {Promise<object|null>} Rate limit dashboard with enabled status, limits, and counts
 *
 * @example
 * const dashboard = await fetchRateLimitDashboard();
 * console.log('Rate limited events:', dashboard.rate_limited_total);
 */
export async function fetchRateLimitDashboard() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/rate-limits`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Circuit Breaker Dashboard (v2.70.0) ──────────────────────────

/**
 * Fetch the circuit breaker dashboard for all analytics providers.
 *
 * Returns per-provider circuit state (closed/open/half_open),
 * failure counts, success counts, last failure time, and cooldown remaining.
 *
 * @returns {Promise<object|null>} Circuit breaker dashboard
 *
 * @example
 * const dashboard = await fetchCircuitBreakerDashboard();
 * if (dashboard) {
 *     Object.entries(dashboard.providers).forEach(([provider, info]) => {
 *         console.log(`${provider}: ${info.state} (${info.failures} failures)`);
 *     });
 * }
 */
export async function fetchCircuitBreakerDashboard() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/circuit-breaker`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch the circuit breaker summary (open/half-open/closed counts).
 *
 * @returns {Promise<object|null>} Summary with provider counts per state
 *
 * @example
 * const summary = await fetchCircuitBreakerSummary();
 * console.log(`Open circuits: ${summary.total_open}`);
 */
export async function fetchCircuitBreakerSummary() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/circuit-breaker/summary`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Compliance Audit Report (v2.70.0) ────────────────────────────

/**
 * Fetch a comprehensive compliance audit report.
 *
 * Covers PII exposure, consent coverage, retention policies,
 * data minimization, and processing transparency. Returns an
 * overall compliance score (0-100) and actionable recommendations.
 *
 * @returns {Promise<object|null>} Full compliance report
 *
 * @example
 * const report = await fetchComplianceReport();
 * if (report) {
 *     console.log(`Compliance score: ${report.overall_score}/100`);
 *     report.recommendations.forEach(r => console.warn('⚠️', r));
 * }
 */
export async function fetchComplianceReport() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/compliance`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch a quick compliance score (0-100).
 *
 * @returns {Promise<number|null>} Compliance score
 *
 * @example
 * const score = await fetchComplianceScore();
 * if (score !== null) {
 *     console.log(`Compliance: ${score}%`);
 * }
 */
export async function fetchComplianceScore() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/compliance/score`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        const data = await response.json();

        return data.score ?? null;
    } catch {
        return null;
    }
}

// ─── Recovery Service (v2.70.0) ────────────────────────────────────

/**
 * Fetch the DLQ recovery budget status.
 *
 * @returns {Promise<object|null>} Budget with remaining, max, used, resets_at
 *
 * @example
 * const budget = await fetchRecoveryBudget();
 * console.log(`Recovery budget: ${budget.remaining}/${budget.max} remaining`);
 */
export async function fetchRecoveryBudget() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/recovery/budget`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch the recovery pipeline health assessment.
 *
 * @returns {Promise<object|null>} Health with status, dlq_size, health_score
 *
 * @example
 * const health = await fetchRecoveryHealth();
 * console.log(`Recovery health: ${health.status} (${health.health_score}/100)`);
 */
export async function fetchRecoveryHealth() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/recovery/health`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch the recovery history summary (24h window).
 *
 * @returns {Promise<object|null>} History with recovered/failed counts and last recovery time
 *
 * @example
 * const history = await fetchRecoveryHistory();
 * console.log(`Recovered 24h: ${history.total_recovered_24h}, Failed: ${history.total_failed_24h}`);
 */
export async function fetchRecoveryHistory() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/recovery/history`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Revenue Forecasting (v2.81.0) ────────────────────────────────

/**
 * Revenue forecast summary data.
 *
 * @typedef {object} ForecastSummary
 * @property {number} current_mrr
 * @property {number} current_arr
 * @property {number} projected_mrr_30d
 * @property {number} projected_arr_30d
 * @property {number} mrr_growth_rate
 * @property {number} churn_rate
 * @property {number} net_revenue_retention
 * @property {number} ltv_estimate
 * @property {number} runway_months
 * @property {string} confidence
 */

/**
 * Daily forecast data point.
 *
 * @typedef {object} ForecastPoint
 * @property {string} date
 * @property {number} mrr
 * @property {number} arr
 * @property {number} churned_mrr
 * @property {number} net_new_mrr
 * @property {number} churn_rate
 */

/**
 * Fetch a full revenue forecast with daily data points.
 *
 * @param {object} [params] - Current revenue snapshot
 * @param {number} [params.mrr=0] - Current Monthly Recurring Revenue
 * @param {number} [params.arr=0] - Current Annual Recurring Revenue
 * @param {number} [params.churned_mrr_last_month=0] - MRR lost to churn last month
 * @param {number} [params.new_mrr_last_month=0] - New MRR from new customers
 * @param {number} [params.expansion_mrr_last_month=0] - MRR from expansion revenue
 * @param {number} [params.active_subscribers=0] - Current active subscriber count
 * @param {number} [params.churned_subscribers_last_month=0] - Subscribers lost last month
 * @returns {Promise<object|null>} Full forecast with summary, daily points, and assumptions
 *
 * @example
 * const forecast = await fetchRevenueForecast({
 *     mrr: 50000,
 *     churned_mrr_last_month: 1500,
 *     new_mrr_last_month: 8000,
 *     active_subscribers: 500,
 * });
 */
export async function fetchRevenueForecast(params = {}) {
    if (!initialized) return null;

    const query = new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)]),
    ).toString();

    try {
        const response = await fetch(`${apiBaseUrl}/forecast${query ? `?${query}` : ''}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch a quick revenue forecast summary (no daily breakdown).
 *
 * @param {object} [params] - Same as fetchRevenueForecast params
 * @returns {Promise<ForecastSummary|null>}
 */
export async function fetchForecastSummary(params = {}) {
    if (!initialized) return null;

    const query = new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)]),
    ).toString();

    try {
        const response = await fetch(`${apiBaseUrl}/forecast/summary${query ? `?${query}` : ''}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Project MRR at a specific future date.
 *
 * @param {number} daysOut - Number of days into the future (1-365)
 * @param {number} [mrr=0] - Current MRR
 * @returns {Promise<object|null>} Projected MRR/ARR with cumulative churn/growth
 */
export async function fetchRevenueProjection(daysOut, mrr = 0) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/project?days_out=${Math.min(365, Math.max(1, daysOut))}&mrr=${mrr}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Calculate Customer Lifetime Value (LTV).
 *
 * @param {number} arpu - Average Revenue Per User (monthly)
 * @param {number} churnRate - Monthly churn rate (0-1)
 * @param {number} [grossMargin=0.75] - Gross margin (0-1)
 * @returns {Promise<object|null>} LTV calculation with months and multiplier
 */
export async function fetchLTV(arpu, churnRate, grossMargin = 0.75) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/ltv?arpu=${arpu}&churn_rate=${churnRate}&gross_margin=${grossMargin}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Calculate LTV:CAC ratio.
 *
 * @param {number} ltv - Customer Lifetime Value
 * @param {number} cac - Customer Acquisition Cost
 * @returns {Promise<object|null>} Ratio with rating and recommendation
 */
export async function fetchLTVCACRatio(ltv, cac) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/ltv-cac?ltv=${ltv}&cac=${cac}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Calculate CAC payback period.
 *
 * @param {number} cac - Customer Acquisition Cost
 * @param {number} arpu - Average Revenue Per User (monthly)
 * @param {number} [grossMargin=0.75] - Gross margin (0-1)
 * @returns {Promise<object|null>} Payback in months with rating
 */
export async function fetchPaybackPeriod(cac, arpu, grossMargin = 0.75) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/payback?cac=${cac}&arpu=${arpu}&gross_margin=${grossMargin}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch runway estimate and path to profitability.
 *
 * @param {number} mrr - Current Monthly Recurring Revenue
 * @param {number} expenses - Monthly operating expenses
 * @param {number} [growthRate=0.05] - Monthly growth rate (0-1)
 * @param {number} [churnRate=0.03] - Monthly churn rate (0-1)
 * @returns {Promise<object|null>} Runway months, breakeven date, burn rate
 */
export async function fetchRunway(mrr, expenses, growthRate = 0.05, churnRate = 0.03) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/runway?mrr=${mrr}&expenses=${expenses}&growth_rate=${growthRate}&churn_rate=${churnRate}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch cohort retention curve projection.
 *
 * @param {number} [months=12] - Number of months to project (1-60)
 * @param {number} [churnRate=0.03] - Monthly churn rate (0-1)
 * @returns {Promise<object|null>} Retention curve data points
 */
export async function fetchCohortRetentionCurve(months = 12, churnRate = 0.03) {
    if (!initialized) return null;

    try {
        const response = await fetch(
            `${apiBaseUrl}/forecast/cohort-retention?months=${Math.min(60, months)}&churn_rate=${churnRate}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch MRR movement breakdown.
 *
 * @param {object} params - MRR movement components
 * @param {number} [params.new_mrr=0]
 * @param {number} [params.expansion_mrr=0]
 * @param {number} [params.contraction_mrr=0]
 * @param {number} [params.churned_mrr=0]
 * @param {number} [params.previous_mrr=0]
 * @returns {Promise<object|null>} Movement breakdown with net change
 */
export async function fetchMRRMovement(params = {}) {
    if (!initialized) return null;

    const query = new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)]),
    ).toString();

    try {
        const response = await fetch(`${apiBaseUrl}/forecast/mrr-movement${query ? `?${query}` : ''}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Churn Prediction (v2.81.0) ─────────────────────────────────

/**
 * Churn risk profile for a single user.
 *
 * @typedef {object} ChurnRiskProfile
 * @property {string} user_id
 * @property {number} overall_score
 * @property {'low'|'medium'|'high'|'critical'} risk_level
 * @property {Array<{name: string, weight: number, value: number, max_value: number, score: number}>} signals
 * @property {string} recommendation
 * @property {number} probability_percent
 */

/**
 * Score a single user's churn risk.
 *
 * @param {string} userId - The user to score
 * @param {object} [signals] - User behavior signals
 * @param {number} [signals.days_inactive=0]
 * @param {number} [signals.usage_decline_pct=0]
 * @param {number} [signals.support_tickets_30d=0]
 * @param {number} [signals.failed_payments_90d=0]
 * @param {number} [signals.feature_adoption_pct=100]
 * @param {boolean} [signals.contract_expiring_30d=false]
 * @param {number} [signals.billing_disputes=0]
 * @param {number} [signals.login_frequency_decline_pct=0]
 * @param {number} [signals.engagement_score=100]
 * @param {boolean} [signals.plan_downgrade_recent=false]
 * @returns {Promise<ChurnRiskProfile|null>} Risk profile with score and recommendation
 *
 * @example
 * const profile = await scoreChurnRisk('user-123', {
 *     days_inactive: 21,
 *     usage_decline_pct: 45,
 *     failed_payments_90d: 1,
 * });
 * console.log(`Risk: ${profile.risk_level} (${profile.overall_score}/100)`);
 */
export async function scoreChurnRisk(userId, signals = {}) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/churn/score`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ user_id: userId, ...signals }),
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Score multiple users and return ranked results.
 *
 * @param {Array<{user_id: string} & Record<string, unknown>>} users - Array of users with signals
 * @returns {Promise<object|null>} Ranked profiles with cohort summary
 */
export async function scoreChurnBatch(users) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/churn/score-batch`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ users }),
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch churn risk summary for a cohort.
 *
 * @param {Array<{user_id: string} & Record<string, unknown>>} users - Array of users
 * @returns {Promise<object|null>} Cohort-level risk distribution and top factors
 */
export async function fetchChurnCohortSummary(users) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/churn/cohort-summary`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ users }),
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch configured churn signal weights.
 *
 * @returns {Promise<object|null>} Signal weight configuration
 */
export async function fetchChurnWeights() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/churn/weights`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch configured churn risk thresholds.
 *
 * @returns {Promise<object|null>} Threshold configuration
 */
export async function fetchChurnThresholds() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/churn/thresholds`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── SaaS Readiness & Maturity (v2.83.0) ─────────────────────────

/**
 * Get the analytics maturity score from the Inertia page props.
 *
 * Returns the server-computed maturity score and grade that were
 * injected into the zbAnalytics config during the Inertia middleware
 * processing. This is a synchronous call — no API request needed.
 *
 * @returns {object|null} Maturity score and grade
 *
 * @example
 * const maturity = getMaturityScore();
 * console.log(`Score: ${maturity.score}/100, Grade: ${maturity.grade}`);
 */
export function getMaturityScore() {
    if (!initialized || !config?.maturity) return null;

    return {
        score: config.maturity.score,
        grade: config.maturity.grade,
    };
}

/**
 * Get the onboarding completion status from the Inertia page props.
 *
 * Returns the server-computed onboarding checklist with completion
 * percentage and gap list. Use this to guide instrumentation.
 *
 * @returns {object|null} Onboarding status with completion and gaps
 *
 * @example
 * const status = getOnboardingStatus();
 * console.log(`${status.completion}% complete, gaps: ${status.gaps.join(', ')}`);
 */
export function getOnboardingStatus() {
    if (!initialized || !config?.onboarding) return null;

    return {
        completion: config.onboarding.completion,
        gaps: config.onboarding.gaps,
    };
}

/**
 * Check if analytics instrumentation meets industry-standard SaaS criteria.
 *
 * Uses the maturity score from Inertia props to determine if the
 * current instrumentation is at least "good" level (score >= 60).
 *
 * @returns {boolean} True if instrumentation meets SaaS standard
 *
 * @example
 * if (!isSaaSReady()) {
 *     console.warn('Analytics instrumentation below SaaS standard. Missing:', getOnboardingStatus().gaps);
 * }
 */
export function isSaaSReady() {
    const maturity = getMaturityScore();
    if (!maturity) return false;

    return maturity.score >= 60;
}

/**
 * Get the event catalog summary from the server.
 *
 * Fetches a lightweight summary of the event catalog including
 * total count, category breakdown, and provider coverage.
 * Results are cached for 60 seconds to avoid repeated requests.
 *
 * @returns {Promise<object|null>} Catalog summary
 *
 * @example
 * const summary = await getEventCatalogSummary();
 * console.log(`${summary.total} events across ${Object.keys(summary.categories).length} categories`);
 */
export async function getEventCatalogSummary() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/catalog`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Get the SaaS funnel readiness assessment from the server.
 *
 * Fetches the funnel readiness score that indicates how well
 * the current instrumentation covers the SaaS conversion funnel
 * (signup → trial → conversion → retention → expansion).
 *
 * @returns {Promise<object|null>} Funnel readiness assessment
 *
 * @example
 * const readiness = await getFunnelReadiness();
 * console.log(`Funnel readiness: ${readiness.score}/100`);
 */
export async function getFunnelReadiness() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/funnel-readiness`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Funnel Readiness & Instrumentation Guidance (v2.84.0) ──────────

/**
 * Get the funnel readiness scores from Inertia props (synchronous).
 *
 * Returns the pre-computed signup, purchase, subscription, and overall
 * funnel readiness scores. No API request needed — data comes from
 * the Inertia middleware.
 *
 * @returns {object|null} Funnel readiness scores
 *
 * @example
 * const funnel = getFunnelReadinessFromProps();
 * console.log(`Signup funnel: ${funnel.signup}%, Purchase: ${funnel.purchase}%`);
 */
export function getFunnelReadinessFromProps() {
    if (!initialized || !config?.funnelReadiness) return null;
    return { ...config.funnelReadiness };
}

/**
 * Get the list of recommended events for instrumentation from Inertia props.
 *
 * Returns events from the starter instrumentation set that are not yet
 * tracked, sorted by priority. Use this to guide development of
 * analytics instrumentation.
 *
 * @returns {Array<object>} Recommended events to instrument
 *
 * @example
 * const events = getRecommendedEvents();
 * events.forEach(e => console.log(`Track ${e.name} (${e.priority})`));
 */
export function getRecommendedEvents() {
    if (!initialized || !config?.recommendedEvents) return [];
    return [...config.recommendedEvents];
}

/**
 * Get the client-side deduplication configuration from Inertia props.
 *
 * Returns the dedup window and enabled flag for tuning client-side
 * event debouncing. When dedup is enabled, the client should not
 * send the same event twice within the window.
 *
 * @returns {object|null} Dedup configuration
 *
 * @example
 * const dedup = getDedupConfig();
 * if (dedup.enabled) {
 *     console.log(`Dedup window: ${dedup.windowSeconds}s`);
 * }
 */
export function getDedupConfig() {
    if (!initialized || !config?.dedup) return null;
    return { ...config.dedup };
}

/**
 * Get the industry-standard instrumentation checklist from the server.
 *
 * Returns categorized events (critical/high/medium/low) that an
 * industry-standard SaaS product should instrument.
 *
 * @returns {Promise<object|null>} Industry standard event classification
 *
 * @example
 * const standard = await getIndustryStandard();
 * console.log(`${standard.critical.length} critical events required`);
 */
export async function getIndustryStandard() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/industry-standard`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Data Portability Events (v2.86.0) ─────────────────────────────────────

/**
 * Track a data export action.
 *
 * Tracks when users export data (CSV, JSON, PDF, etc.). Useful for
 * GDPR compliance monitoring and churn prediction (exports often
 * precede account cancellation).
 *
 * @param {string} format - Export format (csv, json, pdf, xlsx)
 * @param {object} [options] - Additional options
 * @param {string} [options.resource] - Type of data exported (reports, users, transactions)
 * @param {number} [options.recordCount] - Number of records exported
 * @param {object} [options.params={}] - Additional event parameters
 *
 * @example
 * await trackExport('csv', { resource: 'reports', recordCount: 150 });
 */
export async function trackExport(format, options = {}) {
    if (!initialized) return;

    const params = {
        format,
        resource: options.resource || undefined,
        record_count: options.recordCount || undefined,
        ...options.params,
    };

    await trackEvent('export', params);
}

/**
 * Track a data import action.
 *
 * Tracks when users import data. Useful for onboarding optimization
 * and identifying power users (high import counts signal active usage).
 *
 * @param {string} format - Import format (csv, json, xlsx)
 * @param {object} [options] - Additional options
 * @param {string} [options.resource] - Type of data imported (contacts, products, transactions)
 * @param {number} [options.recordCount] - Number of records imported
 * @param {boolean} [options.success] - Whether the import succeeded
 * @param {object} [options.params={}] - Additional event parameters
 *
 * @example
 * await trackImport('csv', { resource: 'contacts', recordCount: 500, success: true });
 */
export async function trackImport(format, options = {}) {
    if (!initialized) return;

    const params = {
        format,
        resource: options.resource || undefined,
        record_count: options.recordCount || undefined,
        success: options.success !== undefined ? options.success : undefined,
        ...options.params,
    };

    await trackEvent('import', params);
}

// ─── Quick-Start Events (v2.86.0) ────────────────────────────────────────

/**
 * Fetch the quick-start event set from the server.
 *
 * Returns the 12 essential events every SaaS should track on day one.
 * Use for onboarding guidance and instrumentation checklists.
 *
 * @returns {Promise<object|null>} Quick-start event set with funnel coverage
 *
 * @example
 * const quickStart = await getQuickStartEvents();
 * console.log(`${quickStart.count} essential events to instrument`);
 * console.log('Funnel coverage:', quickStart.funnel_coverage);
 */
export async function getQuickStartEvents() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/quick-start`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── SaaS Metrics Benchmarks (v2.90.0) ──────────────────────────────────

/**
 * Fetch all available benchmark metrics with thresholds.
 *
 * @param {object} [options] - Optional filters
 * @param {string} [options.category] - Filter by category (revenue, conversion, retention, engagement, funnel)
 * @returns {Promise<object|null>} Benchmark definitions keyed by metric name
 *
 * @example
 * const benchmarks = await fetchBenchmarks();
 * const revenue = await fetchBenchmarks({ category: 'revenue' });
 */
export async function fetchBenchmarks(options = {}) {
    if (!initialized) return null;

    try {
        const params = new URLSearchParams();
        if (options.category) params.set('category', options.category);

        const response = await fetch(`${apiBaseUrl}/benchmarks?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch benchmark thresholds for a specific metric.
 *
 * @param {string} metric - Metric name (e.g. 'monthly_churn_rate', 'trial_conversion_rate')
 * @returns {Promise<object|null>} Benchmark thresholds (p25, p50, p75, p90) and metadata
 *
 * @example
 * const churn = await fetchBenchmark('monthly_churn_rate');
 * console.log(churn.label, churn.benchmarks);
 */
export async function fetchBenchmark(metric) {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/benchmarks/${encodeURIComponent(metric)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Compare your SaaS metrics against industry benchmarks.
 *
 * @param {Object<string, number>} metrics - Key-value map of metric name → value
 * @returns {Promise<object|null>} Comparison results with grades and percentiles
 *
 * @example
 * const result = await compareBenchmarks({
 *     monthly_churn_rate: 3.5,
 *     trial_conversion_rate: 28,
 *     mrr_growth_rate: 12,
 *     net_revenue_retention: 110,
 * });
 * console.log(result.summary.overall_grade);
 */
export async function compareBenchmarks(metrics) {
    if (!initialized) return null;

    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(metrics)) {
            params.append(`metrics[${key}]`, String(value));
        }

        const response = await fetch(`${apiBaseUrl}/benchmarks/compare?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Get a full benchmark report card with grades, recommendations, and priorities.
 *
 * @param {Object<string, number>} metrics - Key-value map of metric name → value
 * @returns {Promise<object|null>} Report card with prioritized improvement recommendations
 *
 * @example
 * const card = await fetchBenchmarkReportCard({
 *     monthly_churn_rate: 6.2,
 *     trial_conversion_rate: 18,
 *     dau_mau_ratio: 15,
 * });
 * console.log(card.summary); // "Overall score: average (52/100)"
 * console.log(card.priorities); // ['monthly_churn_rate', 'trial_conversion_rate']
 */
export async function fetchBenchmarkReportCard(metrics) {
    if (!initialized) return null;

    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(metrics)) {
            params.append(`metrics[${key}]`, String(value));
        }

        const response = await fetch(`${apiBaseUrl}/benchmarks/report-card?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch quick-start benchmark targets for new SaaS products.
 *
 * Returns the 8 most impactful metrics with p75 (good) tier targets.
 * Use for onboarding dashboards and OKR-setting.
 *
 * @returns {Promise<object|null>} Quick-start metrics with target values
 *
 * @example
 * const targets = await fetchBenchmarkQuickStart();
 * targets.metrics.forEach(m => {
 *     console.log(`${m.label}: target ${m.target}${m.unit}`);
 * });
 */
export async function fetchBenchmarkQuickStart() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/benchmarks/quick-start`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

// ─── Server-Sent Events ──────────────────────────────────

/**
 * Fetch SSE endpoint capability info.
 *
 * @returns {Promise<Object|null>} SSE server capabilities and buffer info
 */
export async function fetchSSEInfo() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/sse/info`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Fetch SSE health check status.
 *
 * @returns {Promise<Object|null>} SSE health and buffer utilization
 */
export async function fetchSSEHealth() {
    if (!initialized) return null;

    try {
        const response = await fetch(`${apiBaseUrl}/sse/health`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null;

        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Connect to the real-time SSE analytics stream.
 *
 * Opens a persistent HTTP connection that receives events as they occur.
 * Uses the EventSource API with a polyfill-friendly fallback for
 * POST-based connections with filters.
 *
 * @param {Object} options - Connection options
 * @param {number} [options.cursor=0] - Resume from cursor
 * @param {string} [options.filter] - Event name filter (supports * wildcard)
 * @param {string} [options.category] - Category filter
 * @param {number} [options.heartbeat=30] - Heartbeat interval in seconds
 * @param {Function} [options.onEvent] - Callback for event messages
 * @param {Function} [options.onHeartbeat] - Callback for heartbeat messages
 * @param {Function} [options.onClose] - Callback when stream closes
 * @param {Function} [options.onError] - Callback for connection errors
 * @returns {Object} Connection handle with close() method
 *
 * @example
 * const conn = connectSSE({
 *     onEvent: (data) => console.log('Event:', data.event),
 *     filter: 'purchase*',
 * });
 * // Later:
 * conn.close();
 */
export function connectSSE(options = {}) {
    if (!initialized) {
        return { close: () => {}, active: false };
    }

    const cursor = options.cursor || 0;
    const filter = options.filter || '';
    const category = options.category || '';
    const heartbeat = Math.min(60, Math.max(5, options.heartbeat || 30));

    const params = new URLSearchParams({
        cursor: String(cursor),
        ...(filter && { filter }),
        ...(category && { category }),
        heartbeat: String(heartbeat),
    });

    const url = `${apiBaseUrl}/sse?${params}`;

    let active = true;
    let eventSource = null;

    // Try native EventSource first (simple GET-based SSE)
    if (typeof EventSource !== 'undefined') {
        eventSource = new EventSource(url);

        eventSource.addEventListener('event', (e) => {
            try {
                const data = JSON.parse(e.data);
                if (options.onEvent) options.onEvent(data);
            } catch {
                // Ignore parse errors
            }
        });

        eventSource.addEventListener('heartbeat', (e) => {
            try {
                const data = JSON.parse(e.data);
                if (options.onHeartbeat) options.onHeartbeat(data);
            } catch {
                // Ignore parse errors
            }
        });

        eventSource.addEventListener('close', (e) => {
            try {
                const data = JSON.parse(e.data);
                active = false;
                if (options.onClose) options.onClose(data);
            } catch {
                active = false;
            }
        });

        eventSource.onerror = (e) => {
            if (options.onError) options.onError(e);
        };
    }

    return {
        close: () => {
            active = false;
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
        },
        get active() {
            return active;
        },
    };
}

// ─── Config Export Helpers (v6.6.0) ─────────────────────────────────

/**
 * Fetch the full analytics configuration export (secrets redacted).
 *
 * Returns a redacted snapshot of all analytics configuration sections.
 * Useful for admin dashboards and debugging workflows.
 *
 * @returns {Promise<Object|null>} Redacted config object or null on error
 *
 * @example
 * const cfg = await fetchConfigExport();
 * console.log(cfg.queue.enabled); // true
 * console.log(cfg.ga4.api_secret); // '********'
 */
export async function fetchConfigExport() {
    if (!initialized) return null;

    try {
        const res = await fetch(`${apiBaseUrl}/config/export`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch {
        return null;
    }
}

/**
 * Fetch the provider/feature status summary.
 *
 * Returns enabled/disabled booleans for each provider and feature
 * without exposing any config values or secrets.
 *
 * @returns {Promise<Object|null>} Status summary or null on error
 *
 * @example
 * const status = await fetchConfigStatus();
 * console.log(status.providers.ga4); // true
 * console.log(status.features.queue); // true
 * console.log(status.version); // '6.6.0'
 */
export async function fetchConfigStatus() {
    if (!initialized) return null;

    try {
        const res = await fetch(`${apiBaseUrl}/config/status`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch {
        return null;
    }
}

/**
 * Fetch a single config section (secrets redacted).
 *
 * @param {string} section - Config section name (e.g. 'ga4', 'queue', 'identity', 'ecommerce')
 * @returns {Promise<Object|null>} Section config or null on error
 *
 * @example
 * const queueConfig = await fetchConfigSection('queue');
 * console.log(queueConfig.enabled); // true
 * console.log(queueConfig.queue); // 'analytics'
 */
export async function fetchConfigSection(section) {
    if (!initialized) return null;

    try {
        const res = await fetch(`${apiBaseUrl}/config/section/${encodeURIComponent(section)}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch {
        return null;
    }
}

/**
 * Get the geolocation enrichment status from Inertia props.
 *
 * Returns whether server-side geolocation enrichment is enabled
 * and which strategy is used (header, ip2country, maxmind).
 *
 * @returns {{ enabled: boolean, strategy: string }}
 *
 * @example
 * const geo = getGeolocationStatus();
 * if (geo.enabled) {
 *     console.log('Geolocation via', geo.strategy);
 * }
 */
export function getGeolocationStatus() {
    if (!initialized || !config?.geolocation) {
        return { enabled: false, strategy: 'header' };
    }
    return config.geolocation;
}

/**
 * Get the sampling configuration from Inertia props.
 *
 * Returns whether client-side event sampling is enabled,
 * the sampling rate, and whether deterministic mode is used.
 *
 * @returns {{ enabled: boolean, rate: number, deterministic: boolean }}
 *
 * @example
 * const sampling = getSamplingConfig();
 * if (sampling.enabled && sampling.rate < 1.0) {
 *     console.log(`Sampling at ${sampling.rate * 100}%`);
 * }
 */
export function getSamplingConfig() {
    if (!initialized || !config?.sampling) {
        return { enabled: false, rate: 1.0, deterministic: true };
    }
    return config.sampling;
}

/**
 * Get the regional consent detection status from Inertia props.
 *
 * @returns {{ enabled: boolean, gdprDefault: string }}
 *
 * @example
 * const rc = getRegionalConsentStatus();
 * if (rc.enabled && rc.gdprDefault === 'denied') {
 *     console.log('GDPR opt-in required for EU users');
 * }
 */
export function getRegionalConsentStatus() {
    if (!initialized || !config?.regionalConsent) {
        return { enabled: false, gdprDefault: 'denied' };
    }
    return config.regionalConsent;
}

// ─── Event Sparkline API (v7.2.0) ──────────────────────────────

/**
 * Fetch sparkline data for a single event.
 *
 * @param {string} eventName - Event name
 * @param {number} [points=24] - Number of data points
 * @param {number} [period=24] - Time period in hours
 * @returns {Promise<Object|null>}
 */
export async function fetchEventSparkline(eventName, points = 24, period = 24) {
    if (!initialized) return null;
    const params = new URLSearchParams();
    if (points) params.set('points', points);
    if (period) params.set('period', period);
    try {
        const res = await fetch(`${apiBaseUrl}/sparkline/${encodeURIComponent(eventName)}?${params}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch sparkline data for multiple events.
 *
 * @param {string[]} events - Event names
 * @param {number} [points=24] - Number of data points
 * @param {number} [period=24] - Time period in hours
 * @returns {Promise<Object|null>}
 */
export async function fetchEventSparklines(events, points = 24, period = 24) {
    if (!initialized) return null;
    const params = new URLSearchParams();
    params.set('events', events.join(','));
    if (points) params.set('points', points);
    if (period) params.set('period', period);
    try {
        const res = await fetch(`${apiBaseUrl}/sparklines?${params}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch sparkline dashboard summary.
 *
 * @param {number} [points=24] - Number of data points
 * @returns {Promise<Object|null>}
 */
export async function fetchSparklineDashboard(points = 24) {
    if (!initialized) return null;
    const params = points ? `?points=${points}` : '';
    try {
        const res = await fetch(`${apiBaseUrl}/sparkline/dashboard${params}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

// ─── Event Co-occurrence API (v7.2.0) ───────────────────────────

/**
 * Fetch top co-occurring event pairs.
 *
 * @param {number} [limit=20] - Maximum pairs
 * @returns {Promise<Array|null>}
 */
export async function fetchCooccurrenceTopPairs(limit = 20) {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/cooccurrence/top?limit=${limit}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch events that co-occur with a specific event.
 *
 * @param {string} eventName - Reference event
 * @param {number} [limit=10] - Maximum results
 * @returns {Promise<Object|null>}
 */
export async function fetchCooccurrenceWith(eventName, limit = 10) {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/cooccurrence/${encodeURIComponent(eventName)}?limit=${limit}`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch co-occurrence dashboard summary with clusters.
 *
 * @returns {Promise<Object|null>}
 */
export async function fetchCooccurrenceDashboard() {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/cooccurrence/dashboard`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

// ─── Event Signal Intelligence API (v7.7.0) ──────────────────────

/**
 * Fetch full signal intelligence report.
 *
 * @returns {Promise<Object|null>}
 *
 * @example
 * const report = await fetchSignalReport();
 * console.log(report.signal_score, report.grade);
 * // report.providers, report.anomalies, report.signal_to_noise
 */
export async function fetchSignalReport() {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/signal`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch composite signal score (0-100) with grade.
 *
 * @returns {Promise<Object|null>}
 *
 * @example
 * const { score, grade } = await fetchSignalScore();
 */
export async function fetchSignalScore() {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/signal/score`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch detected anomalies only.
 *
 * @returns {Promise<Array|null>}
 */
export async function fetchSignalAnomalies() {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/signal/anomalies`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

/**
 * Fetch provider health signals.
 *
 * @returns {Promise<Object|null>}
 */
export async function fetchSignalProviders() {
    if (!initialized) return null;
    try {
        const res = await fetch(`${apiBaseUrl}/signal/providers`, {
            headers: { Accept: 'application/json' },
        });
        return res.ok ? await res.json() : null;
    } catch { return null; }
}

// ─── Offline Event Buffer (v14.0.0) ──────────────────────────────

/**
 * Offline-first event buffer with localStorage persistence.
 *
 * When the browser is offline or API requests fail, events are buffered
 * to localStorage and automatically retried when connectivity is restored.
 * Respects storage quota and implements FIFO eviction when capacity is reached.
 *
 * @namespace offlineBuffer
 */

const OFFLINE_BUFFER_KEY = 'zb_analytics_offline_buffer';
const OFFLINE_BUFFER_MAX = 500;
const OFFLINE_BUFFER_MAX_SIZE_MB = 5;

/**
 * Check if the browser is currently offline.
 * @returns {boolean}
 */
export function isOffline() {
    if (typeof navigator === 'undefined') return false;
    return navigator.onLine === false;
}

/**
 * Save events to the offline buffer (localStorage).
 *
 * @param {Array} events - Events to buffer
 * @returns {boolean} Whether events were saved successfully
 */
export function saveToOfflineBuffer(events) {
    if (typeof localStorage === 'undefined') return false;

    try {
        const existing = loadOfflineBuffer();
        const merged = [...existing, ...events].slice(-OFFLINE_BUFFER_MAX);

        const serialized = JSON.stringify(merged);
        const sizeMB = new Blob([serialized]).size / (1024 * 1024);

        if (sizeMB > OFFLINE_BUFFER_MAX_SIZE_MB) {
            // FIFO eviction: keep only the newest events that fit
            const trimmed = merged.slice(-Math.floor(OFFLINE_BUFFER_MAX / 2));
            const trimmedSerialized = JSON.stringify(trimmed);
            const trimmedSizeMB = new Blob([trimmedSerialized]).size / (1024 * 1024);

            if (trimmedSizeMB > OFFLINE_BUFFER_MAX_SIZE_MB) {
                return false;
            }

            localStorage.setItem(OFFLINE_BUFFER_KEY, trimmedSerialized);
        } else {
            localStorage.setItem(OFFLINE_BUFFER_KEY, serialized);
        }

        return true;
    } catch {
        // localStorage full or unavailable
        return false;
    }
}

/**
 * Load events from the offline buffer.
 *
 * @returns {Array} Buffered events
 */
export function loadOfflineBuffer() {
    if (typeof localStorage === 'undefined') return [];

    try {
        const data = localStorage.getItem(OFFLINE_BUFFER_KEY);
        return data ? JSON.parse(data) : [];
    } catch {
        return [];
    }
}

/**
 * Clear all events from the offline buffer.
 */
export function clearOfflineBuffer() {
    if (typeof localStorage === 'undefined') return;
    try {
        localStorage.removeItem(OFFLINE_BUFFER_KEY);
    } catch { /* ignore */ }
}

/**
 * Get offline buffer status (size, event count).
 *
 * @returns {{eventCount: number, sizeKB: number}}
 */
export function offlineBufferStatus() {
    const events = loadOfflineBuffer();
    const serialized = JSON.stringify(events);
    const sizeKB = Math.round(new Blob([serialized]).size / 1024);

    return {
        eventCount: events.length,
        sizeKB,
    };
}

/**
 * Flush the offline buffer by sending all buffered events to the server.
 *
 * Called automatically when connectivity is restored.
 *
 * @returns {Promise<{sent: number, failed: number}>}
 */
export async function flushOfflineBuffer() {
    const buffered = loadOfflineBuffer();

    if (buffered.length === 0) {
        return { sent: 0, failed: 0 };
    }

    // Clear buffer immediately to prevent double-sends
    clearOfflineBuffer();

    // Split into batches for reliable delivery
    const batchSize = MAX_QUEUE_SIZE;
    let sent = 0;
    let failed = 0;

    for (let i = 0; i < buffered.length; i += batchSize) {
        const batch = buffered.slice(i, i + batchSize);

        try {
            const res = await fetch(`${apiBaseUrl}/batch`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Analytics-Client-Id': trackingId,
                    ...(getAuthToken() ? { Authorization: `Bearer ${getAuthToken()}` } : {}),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ events: batch }),
            });

            if (res.ok) {
                sent += batch.length;
            } else {
                // Re-buffer failed batch
                saveToOfflineBuffer(batch);
                failed += batch.length;
            }
        } catch {
            // Network error — re-buffer
            saveToOfflineBuffer(batch);
            failed += batch.length;
        }
    }

    return { sent, failed };
}

// ─── Offline Auto-Recovery (v14.0.0) ─────────────────────────────

let offlineListenerAttached = false;

/**
 * Attach online/offline event listeners for automatic buffer management.
 *
 * When the browser goes online, buffered events are automatically flushed.
 * When offline, failed API calls are automatically buffered.
 *
 * Call this once during initialization.
 */
export function enableOfflineRecovery() {
    if (typeof window === 'undefined' || offlineListenerAttached) return;

    window.addEventListener('online', async () => {
        if (!initialized) return;
        await flushOfflineBuffer();
    });

    offlineListenerAttached = true;
}

// ─── Enhanced sendEvent with Offline Fallback (v14.0.0) ────────

/**
 * Internal send that falls back to offline buffer on failure.
 * @param {object} event
 * @returns {Promise<void>}
 */
async function sendEventWithOfflineFallback(event) {
    try {
        await sendEvent(event);
    } catch {
        // Network error — buffer for later
        if (isOffline() || true) {
            saveToOfflineBuffer([event]);
        }
    }
}
