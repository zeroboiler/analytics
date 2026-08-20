/**
 * ZeroBoiler Analytics — Svelte Composable: useClickTracking
 *
 * Reactive click tracking composable for Svelte/Inertia applications.
 * Monitors element clicks via data attributes or CSS selectors and fires
 * `click` analytics events with element context, label, and metadata.
 * Supports CTA conversion tracking, navigation clicks, and custom selectors.
 *
 * @package ZeroBoiler Analytics
 * @version 272.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Last tracked click event data.
 * @type {import('svelte/store').Writable<object|null>}
 */
export const lastClick = writable(null);

/**
 * Total clicks tracked in current session/page.
 * @type {import('svelte/store').Writable<number>}
 */
export const clickCount = writable(0);

/**
 * Whether click tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

/**
 * Recent click history (last N clicks).
 * @type {import('svelte/store').Writable<object[]>}
 */
export const clickHistory = writable([]);

/**
 * Clicks grouped by label (for quick heatmap data).
 * @type {import('svelte/store').Writable<Object<string, number>>}
 */
export const clicksByLabel = writable({});

// ─── Internal State ────────────────────────────────────────────────

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

/** @type {number} Maximum history size */
const MAX_HISTORY = 50;

// ─── Composable ───────────────────────────────────────────────────

/**
 * Click tracking composable for Svelte components.
 *
 * Monitors clicks on elements matching configured selectors and fires
 * `click` analytics events. Supports declarative tracking via
 * `data-zb-click` attributes or imperative tracking via custom selectors.
 *
 * @param {object} [options] - Configuration options
 * @param {string} [options.selector='[data-zb-click]'] - CSS selector for tracked elements
 * @param {string} [options.eventName='click'] - Analytics event name
 * @param {boolean} [options.trackCTA=true] - Auto-detect CTA-like elements
 * @param {boolean} [options.trackExternal=true] - Track outbound link clicks
 * @param {boolean} [options.trackDownload=false] - Track file download clicks
 * @param {number} [options.maxHistory=50] - Maximum click history size
 * @param {Function} [options.onClick] - Callback on tracked click (data) => void
 * @param {Function} [options.shouldTrack] - Filter function (element, event) => boolean
 * @returns {{
 *   lastClick: import('svelte/store').Writable<object|null>,
 *   clickCount: import('svelte/store').Writable<number>,
 *   clickHistory: import('svelte/store').Writable<object[]>,
 *   clicksByLabel: import('svelte/store').Writable<Object<string, number>>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   trackElement: (element: HTMLElement, label?: string) => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useClickTracking } from '@zeroboiler/analytics/svelte';
 *
 * const { clickCount, lastClick, start, trackElement } = useClickTracking({
 *     selector: '[data-zb-click], .cta-button',
 *     trackCTA: true,
 *     onClick: (data) => console.log('Tracked:', data.label),
 * });
 * </script>
 *
 * <p>Clicks tracked: {$clickCount}</p>
 * <button data-zb-click="signup-cta" use:trackElement>Sign Up</button>
 * ```
 */
export function useClickTracking(options = {}) {
    const selector = options.selector || '[data-zb-click]';
    const eventName = options.eventName || 'click';
    const trackCTA = options.trackCTA !== false;
    const trackExternal = options.trackExternal !== false;
    const trackDownload = options.trackDownload || false;
    const maxHistory = options.maxHistory || MAX_HISTORY;
    const onClick = options.onClick || null;
    const shouldTrack = options.shouldTrack || null;

    // Override global maxHistory
    // (per-instance, but shared stores are module-level)

    /** @type {Function} Bound click handler */
    let handler = null;

    // ─── Element Metadata Extraction ───────────────────────────

    /**
     * Extract tracking metadata from a clicked element.
     *
     * @param {HTMLElement} element - The clicked element
     * @param {Event} event - The native click event
     * @returns {object} Tracking payload
     */
    function extractMetadata(element, event) {
        const label =
            element.dataset.zbClick ||
            element.dataset.analyticsLabel ||
            element.getAttribute('aria-label') ||
            element.textContent?.trim().slice(0, 100) ||
            element.tagName.toLowerCase();

        const href = element.getAttribute('href') || element.closest('a')?.getAttribute('href') || null;
        const isExternal = href && (href.startsWith('http://') || href.startsWith('https://')) &&
            !href.includes(window.location.hostname);
        const isDownload = trackDownload && href && (href.match(/\.(pdf|zip|docx?|xlsx?|csv|png|jpe?g|gif|svg|mp[34]|webm|webp)(\?|$)/i) ||
                element.hasAttribute('download'));

        // Determine element role
        let role = element.dataset.zbClickRole || 'link';
        if (element.tagName === 'BUTTON' || element.closest('button')) role = 'button';
        if (element.tagName === 'INPUT' || element.tagName === 'SELECT') role = 'input';
        if (isDownload) role = 'download';
        if (isExternal) role = 'external_link';
        if (trackCTA) {
            const text = (element.textContent || '').toLowerCase();
            if (/sign\s*up|register|get\s*started|start\s*(free|trial)|subscribe|buy|purchase|upgrade|book|schedule|demo|pricing/.test(text)) {
                role = 'cta';
            }
        }

        return {
            label,
            element_tag: element.tagName.toLowerCase(),
            element_role: role,
            element_id: element.id || null,
            element_class: element.className?.split(' ').filter(Boolean).slice(0, 5) || [],
            href: isExternal || isDownload ? href : null,
            is_external: isExternal,
            is_download: isDownload,
            page_path: window.location.pathname,
            page_url: window.location.href,
            client_id: getClientId(),
            timestamp: new Date().toISOString(),
        };
    }

    // ─── Click Handler ─────────────────────────────────────────

    function handleClick(event) {
        const target = event.target;
        const element = target.closest(selector);

        if (!element) {
            // If CTA tracking is on, check for CTA-like elements
            if (trackCTA) {
                const ctaElement = target.closest('button, a, [role="button"]');
                if (ctaElement && isCTAElement(ctaElement)) {
                    processClick(ctaElement, event);
                    return;
                }
            }

            // Check for external links
            if (trackExternal) {
                const link = target.closest('a[href^="http"]');
                if (link && isExternalLink(link)) {
                    processClick(link, event);
                    return;
                }
            }

            return;
        }

        processClick(element, event);
    }

    function isCTAElement(element) {
        const text = (element.textContent || '').toLowerCase();
        return /sign\s*up|register|get\s*started|start\s*(free|trial)|subscribe|buy|purchase|upgrade|book|schedule|demo|pricing|contact|join/.test(text);
    }

    function isExternalLink(element) {
        const href = element.getAttribute('href') || '';
        return href.startsWith('http') && !href.includes(window.location.hostname);
    }

    function processClick(element, event) {
        // Filter check
        if (shouldTrack && !shouldTrack(element, event)) return;

        const metadata = extractMetadata(element, event);

        // Fire analytics event
        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch {
                // Silent — click tracking should never break the app
            }
        }

        // Update stores
        lastClick.set(metadata);
        clickCount.update(n => n + 1);
        clickHistory.update(history => {
            const updated = [metadata, ...history].slice(0, maxHistory);
            return updated;
        });
        clicksByLabel.update(byLabel => {
            const updated = { ...byLabel };
            updated[metadata.label] = (updated[metadata.label] || 0) + 1;
            return updated;
        });

        // Callback
        if (onClick) {
            try { onClick(metadata); } catch { /* silent */ }
        }
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof document === 'undefined') return;

        handler = handleClick;
        document.addEventListener('click', handler, { passive: true, capture: true });
        tracking.set(true);
    }

    function stop() {
        if (typeof document === 'undefined') return;

        if (handler) {
            document.removeEventListener('click', handler, { capture: true });
            handler = null;
        }
        tracking.set(false);
    }

    function reset() {
        lastClick.set(null);
        clickCount.set(0);
        clickHistory.set([]);
        clicksByLabel.set({});
    }

    /**
     * Imperatively track a click on a specific element.
     *
     * @param {HTMLElement} element - The element to track
     * @param {string} [overrideLabel] - Optional label override
     */
    function trackElement(element, overrideLabel) {
        const metadata = extractMetadata(element, new Event('click'));
        if (overrideLabel) {
            metadata.label = overrideLabel;
        }

        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch { /* silent */ }
        }

        lastClick.set(metadata);
        clickCount.update(n => n + 1);
        clickHistory.update(history => [metadata, ...history].slice(0, maxHistory));
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
        lastClick,
        clickCount,
        clickHistory,
        clicksByLabel,
        tracking,
        start,
        stop,
        reset,
        trackElement,
    };
}

export default useClickTracking;
