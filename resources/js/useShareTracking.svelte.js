/**
 * ZeroBoiler Analytics — Svelte Composable: useShareTracking
 *
 * Reactive share tracking composable for Svelte/Inertia applications.
 * Monitors share button clicks, Web Share API usage, and social share intents.
 * Fires `share` analytics events with share method, content type, and metadata.
 *
 * @package ZeroBoiler Analytics
 * @version 271.0.0
 */

import { writable } from 'svelte/store';
import { onDestroy } from 'svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Last share event tracked.
 * @type {import('svelte/store').Writable<object|null>}
 */
export const lastShare = writable(null);

/**
 * Total shares tracked in current session.
 * @type {import('svelte/store').Writable<number>}
 */
export const shareCount = writable(0);

/**
 * Share history (recent shares).
 * @type {import('svelte/store').Writable<object[]>}
 */
export const shareHistory = writable([]);

/**
 * Whether share tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

/**
 * Shares grouped by method/platform.
 * @type {import('svelte/store').Writable<Object<string, number>>}
 */
export const sharesByMethod = writable({});

// ─── Internal State ────────────────────────────────────────────────

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

/** @type {number} Maximum history size */
const MAX_HISTORY = 30;

// ─── Social Platform Detection ─────────────────────────────────────

const SOCIAL_PATTERNS = {
    twitter: /twitter\.com\/intent|twitter|x\.com/i,
    facebook: /facebook\.com\/sharer|facebook/i,
    linkedin: /linkedin\.com\/sharing|linkedin/i,
    reddit: /reddit\.com\/submit|reddit/i,
    whatsapp: /wa\.me|whatsapp/i,
    telegram: /t\.me\/share|telegram/i,
    email: /^mailto:/i,
    copy: /copy|clipboard/i,
};

/**
 * Detect social platform from a URL or method string.
 *
 * @param {string} urlOrMethod - URL or method identifier
 * @returns {string|null} Platform name or null
 */
function detectPlatform(urlOrMethod) {
    for (const [platform, pattern] of Object.entries(SOCIAL_PATTERNS)) {
        if (pattern.test(urlOrMethod)) return platform;
    }
    return null;
}

// ─── Composable ───────────────────────────────────────────────────

/**
 * Share tracking composable for Svelte components.
 *
 * Monitors share button clicks (via `data-zb-share` attribute),
 * Web Share API usage, and social link clicks. Fires `share` analytics events.
 *
 * @param {object} [options] - Configuration options
 * @param {string} [options.selector='[data-zb-share], [data-analytics-share]'] - CSS selector
 * @param {boolean} [options.interceptWebShareApi=false] - Intercept navigator.share() calls
 * @param {boolean} [options.trackSocialLinks=true] - Track social link clicks
 * @param {number} [options.maxHistory=30] - Maximum share history size
 * @param {string} [options.eventName='share'] - Analytics event name
 * @param {Function} [options.onShare] - Callback (metadata) => void
 * @returns {{
 *   lastShare: import('svelte/store').Writable<object|null>,
 *   shareCount: import('svelte/store').Writable<number>,
 *   shareHistory: import('svelte/store').Writable<object[]>,
 *   sharesByMethod: import('svelte/store').Writable<Object<string, number>>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   trackShare: (method: string, contentType?: string, contentId?: string, params?: object) => void,
 *   trackWebShare: (shareData: ShareData) => Promise<void>,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useShareTracking } from '@zeroboiler/analytics/svelte';
 *
 * const { shareCount, trackShare, trackWebShare } = useShareTracking({
 *     onShare: (data) => console.log(`Shared via: ${data.share_method}`),
 * });
 *
 * async function handleNativeShare() {
 *     await trackWebShare({
 *         title: document.title,
 *         text: 'Check this out!',
 *         url: window.location.href,
 *     });
 * }
 * </script>
 *
 * <button data-zb-share="twitter" data-content-type="article" data-content-id="42">
 *     Share on Twitter
 * </button>
 * <p>Shares: {$shareCount}</p>
 * ```
 */
export function useShareTracking(options = {}) {
    const selector = options.selector || '[data-zb-share], [data-analytics-share]';
    const interceptWebShareApi = options.interceptWebShareApi || false;
    const trackSocialLinks = options.trackSocialLinks !== false;
    const maxHistory = options.maxHistory || MAX_HISTORY;
    const eventName = options.eventName || 'share';
    const onShare = options.onShare || null;

    /** @type {Function|null} Bound click handler */
    let clickHandler = null;
    /** @type {Function|null} Original navigator.share */
    let originalShare = null;

    // ─── Helpers ──────────────────────────────────────────────

    function buildMetadata(method, contentType, contentId, extra) {
        const platform = detectPlatform(method);

        return {
            share_method: platform || method,
            share_platform: platform || null,
            content_type: contentType || null,
            content_id: contentId || null,
            page_path: window.location.pathname,
            page_url: window.location.href,
            page_title: document.title || null,
            client_id: getClientId(),
            timestamp: new Date().toISOString(),
            ...extra,
        };
    }

    function processShare(method, contentType, contentId, extra) {
        const metadata = buildMetadata(method, contentType, contentId, extra);
        const effectiveMethod = metadata.share_method;

        // Fire analytics
        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch { /* silent */ }
        }

        // Update stores
        lastShare.set(metadata);
        shareCount.update(n => n + 1);
        shareHistory.update(history => {
            const updated = [metadata, ...history].slice(0, maxHistory);
            return updated;
        });
        sharesByMethod.update(byMethod => {
            const updated = { ...byMethod };
            updated[effectiveMethod] = (updated[effectiveMethod] || 0) + 1;
            return updated;
        });

        // Callback
        if (onShare) {
            try { onShare(metadata); } catch { /* silent */ }
        }
    }

    // ─── Handlers ─────────────────────────────────────────────

    function handleClick(event) {
        // 1. Check for data-zb-share elements
        const shareEl = event.target.closest(selector);
        if (shareEl) {
            const method = shareEl.dataset.zbShare || shareEl.dataset.analyticsShare || 'unknown';
            const contentType = shareEl.dataset.contentType || shareEl.dataset.analyticsContentType || null;
            const contentId = shareEl.dataset.contentId || shareEl.dataset.analyticsContentId || null;
            const href = shareEl.getAttribute('href') || '';

            processShare(method, contentType, contentId, { share_url: href || null });
            return;
        }

        // 2. Check for social link clicks
        if (trackSocialLinks) {
            const link = event.target.closest('a[href]');
            if (link) {
                const href = link.getAttribute('href') || '';
                const platform = detectPlatform(href);
                if (platform && !href.startsWith('mailto:')) {
                    const contentType = link.dataset.contentType || null;
                    const contentId = link.dataset.contentId || null;
                    processShare(platform, contentType, contentId, { share_url: href });
                }
            }
        }
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof document === 'undefined') return;

        clickHandler = handleClick;
        document.addEventListener('click', clickHandler, { passive: true, capture: true });

        // Intercept Web Share API
        if (interceptWebShareApi && typeof navigator !== 'undefined' && navigator.share) {
            originalShare = navigator.share.bind(navigator);
            navigator.share = async function (shareData) {
                const method = shareData.url ? detectPlatform(shareData.url) || 'native_share' : 'native_share';
                processShare(method, null, null, {
                    share_title: shareData.title || null,
                    share_text: shareData.text || null,
                    share_url: shareData.url || null,
                });
                return originalShare(shareData);
            };
        }

        tracking.set(true);
    }

    function stop() {
        if (typeof document === 'undefined') return;

        if (clickHandler) {
            document.removeEventListener('click', clickHandler, { capture: true });
            clickHandler = null;
        }

        // Restore original navigator.share
        if (originalShare && typeof navigator !== 'undefined') {
            navigator.share = originalShare;
            originalShare = null;
        }

        tracking.set(false);
    }

    function reset() {
        lastShare.set(null);
        shareCount.set(0);
        shareHistory.set([]);
        sharesByMethod.set({});
    }

    /**
     * Imperatively track a share event.
     *
     * @param {string} method - Share method/platform (e.g., 'twitter', 'email', 'copy')
     * @param {string} [contentType] - Type of shared content (e.g., 'article', 'product')
     * @param {string} [contentId] - ID of the shared content
     * @param {object} [params] - Additional parameters
     */
    function trackShare(method, contentType, contentId, params) {
        processShare(method, contentType || null, contentId || null, params || {});
    }

    /**
     * Use the Web Share API and automatically track the share.
     *
     * @param {ShareData} shareData - Data to share
     * @returns {Promise<void>}
     */
    async function trackWebShare(shareData) {
        const method = shareData.url ? detectPlatform(shareData.url) || 'native_share' : 'native_share';

        processShare(method, null, null, {
            share_title: shareData.title || null,
            share_text: shareData.text || null,
            share_url: shareData.url || null,
        });

        // Actually invoke the share dialog
        if (typeof navigator !== 'undefined' && navigator.share) {
            const fn = originalShare || navigator.share;
            try {
                await fn(shareData);
            } catch (err) {
                // User cancelled or API error — share was already tracked
                if (err.name !== 'AbortError') {
                    // Track share failure silently
                }
            }
        }
    }

    // ─── Cleanup ────────────────────────────────────────────────

    function cleanup() {
        stop();
    }

    // ─── Auto-start ─────────────────────────────────────────────

    if (typeof window !== 'undefined') {
        start();
    }

    if (!cleanupRegistered) {
        cleanupRegistered = true;
        onDestroy(cleanup);
    }

    return {
        lastShare,
        shareCount,
        shareHistory,
        sharesByMethod,
        tracking,
        start,
        stop,
        reset,
        trackShare,
        trackWebShare,
    };
}

export default useShareTracking;
