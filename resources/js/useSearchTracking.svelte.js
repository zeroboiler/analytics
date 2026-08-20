/**
 * ZeroBoiler Analytics — Svelte Composable: useSearchTracking
 *
 * Reactive in-app search tracking composable for Svelte/Inertia applications.
 * Monitors search inputs and fires `search` analytics events with query terms,
 * result counts, and search context. Supports debouncing and result correlation.
 *
 * @package ZeroBoiler Analytics
 * @version 271.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Last search query tracked.
 * @type {import('svelte/store').Writable<object|null>}
 */
export const lastSearch = writable(null);

/**
 * Total searches tracked in current session.
 * @type {import('svelte/store').Writable<number>}
 */
export const searchCount = writable(0);

/**
 * Search history (recent queries).
 * @type {import('svelte/store').Writable<object[]>}
 */
export const searchHistory = writable([]);

/**
 * Whether search tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

// ─── Internal State ────────────────────────────────────────────────

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

/** @type {number} Maximum history size */
const MAX_HISTORY = 30;

/** @type {Map<string, number>} Debounce timers per input */
const debounceTimers = new Map();

// ─── Composable ───────────────────────────────────────────────────

/**
 * Search tracking composable for Svelte components.
 *
 * Monitors search inputs (identified by `data-zb-search` or `type="search"`)
 * and fires `search` analytics events on meaningful queries. Supports
 * debouncing, minimum query length, and result count tracking.
 *
 * @param {object} [options] - Configuration options
 * @param {string} [options.selector='[data-zb-search], input[type="search"]'] - CSS selector
 * @param {number} [options.debounceMs=1000] - Debounce delay before firing search event
 * @param {number} [options.minLength=2] - Minimum query length to track
 * @param {number} [options.maxHistory=30] - Maximum search history size
 * @param {string} [options.eventName='search'] - Analytics event name
 * @param {Function} [options.onSearch] - Callback (query, metadata) => void
 * @param {Function} [options.shouldTrack] - Filter function (query, inputElement) => boolean
 * @param {string} [options.searchContext] - App context (e.g., 'docs', 'help', 'global')
 * @returns {{
 *   lastSearch: import('svelte/store').Writable<object|null>,
 *   searchCount: import('svelte/store').Writable<number>,
 *   searchHistory: import('svelte/store').Writable<object[]>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   trackSearch: (query: string, resultsCount?: number, context?: object) => void,
 *   trackSearchResults: (query: string, resultsCount: number, context?: object) => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useSearchTracking } from '@zeroboiler/analytics/svelte';
 *
 * const { lastSearch, searchCount, trackSearch } = useSearchTracking({
 *     debounceMs: 800,
 *     searchContext: 'docs',
 *     onSearch: (query) => console.log(`Searched: ${query}`),
 * });
 *
 * // When results arrive from API:
 * $: if ($lastSearch && apiResults.length >= 0) {
 *     trackSearchResults($lastSearch.query, apiResults.length);
 * }
 * </script>
 *
 * <input type="search" data-zb-search placeholder="Search docs..." />
 * <p>Searches: {$searchCount}</p>
 * ```
 */
export function useSearchTracking(options = {}) {
    const selector = options.selector || '[data-zb-search], input[type="search"]';
    const debounceMs = options.debounceMs || 1000;
    const minLength = options.minLength || 2;
    const maxHistory = options.maxHistory || MAX_HISTORY;
    const eventName = options.eventName || 'search';
    const onSearch = options.onSearch || null;
    const shouldTrack = options.shouldTrack || null;
    const searchContext = options.searchContext || null;

    /** @type {Function|null} Bound input handler */
    let inputHandler = null;
    /** @type {Function|null} Bound keydown handler */
    let keydownHandler = null;

    // ─── Helpers ──────────────────────────────────────────────

    function getSearchSource(input) {
        return (
            input.dataset.zbSearchSource ||
            input.dataset.analyticsSearch ||
            input.getAttribute('name') ||
            input.getAttribute('aria-label') ||
            'default'
        );
    }

    function buildMetadata(query, resultsCount, input, extra) {
        return {
            search_term: query,
            search_term_length: query.length,
            results_count: resultsCount ?? null,
            search_source: input ? getSearchSource(input) : searchContext || 'imperative',
            search_context: searchContext || null,
            page_path: window.location.pathname,
            page_url: window.location.href,
            client_id: getClientId(),
            timestamp: new Date().toISOString(),
            ...extra,
        };
    }

    function processSearch(query, input) {
        const trimmed = query.trim();

        if (trimmed.length < minLength) return;
        if (shouldTrack && !shouldTrack(trimmed, input)) return;

        const metadata = buildMetadata(trimmed, null, input);

        // Fire analytics
        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch { /* silent */ }
        }

        // Update stores
        lastSearch.set({ query: trimmed, metadata, resultsCount: null });
        searchCount.update(n => n + 1);
        searchHistory.update(history => {
            const updated = [{ query: trimmed, metadata }, ...history].slice(0, maxHistory);
            return updated;
        });

        // Callback
        if (onSearch) {
            try { onSearch(trimmed, metadata); } catch { /* silent */ }
        }
    }

    // ─── Handlers ─────────────────────────────────────────────

    function handleInput(event) {
        const input = event.target;
        if (!input.matches(selector)) return;

        const inputId = input.id || input.name || 'search';

        // Clear existing timer
        if (debounceTimers.has(inputId)) {
            clearTimeout(debounceTimers.get(inputId));
        }

        // Set new debounced timer
        debounceTimers.set(inputId, setTimeout(() => {
            debounceTimers.delete(inputId);
            processSearch(input.value, input);
        }, debounceMs));
    }

    function handleKeydown(event) {
        if (event.key !== 'Enter') return;

        const input = event.target;
        if (!input.matches(selector)) return;

        // Immediate fire on Enter (cancel debounce)
        const inputId = input.id || input.name || 'search';
        if (debounceTimers.has(inputId)) {
            clearTimeout(debounceTimers.get(inputId));
            debounceTimers.delete(inputId);
        }

        processSearch(input.value, input);
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof document === 'undefined') return;

        inputHandler = handleInput;
        keydownHandler = handleKeydown;

        document.addEventListener('input', inputHandler, true);
        document.addEventListener('keydown', keydownHandler, true);
        tracking.set(true);
    }

    function stop() {
        if (typeof document === 'undefined') return;

        if (inputHandler) {
            document.removeEventListener('input', inputHandler, true);
            inputHandler = null;
        }
        if (keydownHandler) {
            document.removeEventListener('keydown', keydownHandler, true);
            keydownHandler = null;
        }

        // Clear all debounce timers
        for (const timer of debounceTimers.values()) {
            clearTimeout(timer);
        }
        debounceTimers.clear();

        tracking.set(false);
    }

    function reset() {
        lastSearch.set(null);
        searchCount.set(0);
        searchHistory.set([]);
    }

    /**
     * Imperatively track a search event.
     *
     * @param {string} query - The search query
     * @param {number} [resultsCount] - Number of results returned
     * @param {object} [context] - Additional context data
     */
    function trackSearch(query, resultsCount, context) {
        const trimmed = query.trim();
        if (trimmed.length < minLength) return;

        const metadata = buildMetadata(trimmed, resultsCount ?? null, null, context);

        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch { /* silent */ }
        }

        lastSearch.set({ query: trimmed, metadata, resultsCount: resultsCount ?? null });
        searchCount.update(n => n + 1);
        searchHistory.update(history => {
            const updated = [{ query: trimmed, metadata }, ...history].slice(0, maxHistory);
            return updated;
        });
    }

    /**
     * Track search results after they arrive from the API.
     * Updates the last search with result count and fires a follow-up event.
     *
     * @param {string} query - The original search query
     * @param {number} resultsCount - Number of results
     * @param {object} [context] - Additional context
     */
    function trackSearchResults(query, resultsCount, context) {
        const trimmed = query.trim();
        if (trimmed.length < minLength) return;

        const metadata = buildMetadata(trimmed, resultsCount, null, {
            ...context,
            search_phase: 'results',
        });

        if (isInitialized()) {
            try {
                trackEvent(`${eventName}_results`, metadata);
            } catch { /* silent */ }
        }

        // Update last search with result count
        lastSearch.update(prev => {
            if (prev && prev.query === trimmed) {
                return { ...prev, resultsCount, metadata };
            }
            return prev;
        });
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
        lastSearch,
        searchCount,
        searchHistory,
        tracking,
        start,
        stop,
        reset,
        trackSearch,
        trackSearchResults,
    };
}

export default useSearchTracking;
