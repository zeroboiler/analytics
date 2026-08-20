/**
 * ZeroBoiler Analytics — Svelte Composable: useErrorTracking
 *
 * Reactive error tracking composable for Svelte/Inertia applications.
 * Captures unhandled JavaScript errors, unhandled promise rejections,
 * and Svelte component errors. Provides reactive stores for error
 * metrics and supports error filtering, grouping, and reporting.
 *
 * @package ZeroBoiler Analytics
 * @version 272.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Last captured error.
 * @type {import('svelte/store').Writable<object|null>}
 */
export const lastError = writable(null);

/**
 * Total errors captured in current session.
 * @type {import('svelte/store').Writable<number>}
 */
export const errorCount = writable(0);

/**
 * Recent error history.
 * @type {import('svelte/store').Writable<object[]>}
 */
export const errorHistory = writable([]);

/**
 * Whether error tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

/**
 * Errors grouped by error message (for spotting recurring issues).
 * @type {import('svelte/store').Writable<Object<string, number>>}
 */
export const errorsByMessage = writable({});

/**
 * Most frequent error messages (derived, top 5).
 * @type {import('svelte/store').Readable<string[]>}
 */
export const topErrors = derived(errorsByMessage, ($byMessage) => {
    return Object.entries($byMessage)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5)
        .map(([message]) => message);
});

// ─── Internal State ────────────────────────────────────────────────

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

/** @type {number} Maximum history size */
const MAX_HISTORY = 50;

// ─── Composable ───────────────────────────────────────────────────

/**
 * Error tracking composable for Svelte components.
 *
 * Captures unhandled errors and unhandled promise rejections,
 * fires `error` analytics events, and provides reactive error metrics.
 * Supports pattern-based filtering and rate limiting.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.trackErrors=true] - Track unhandled errors
 * @param {boolean} [options.trackRejections=true] - Track unhandled promise rejections
 * @param {string[]} [options.ignorePatterns=[]] - Regex patterns for errors to ignore
 * @param {number} [options.maxHistory=50] - Maximum error history size
 * @param {string} [options.eventName='error'] - Analytics event name
 * @param {number} [options.rateLimitMs=5000] - Minimum ms between same-error events
 * @param {Function} [options.onError] - Callback (metadata) => void
 * @returns {{
 *   lastError: import('svelte/store').Writable<object|null>,
 *   errorCount: import('svelte/store').Writable<number>,
 *   errorHistory: import('svelte/store').Writable<object[]>,
 *   errorsByMessage: import('svelte/store').Writable<Object<string, number>>,
 *   topErrors: import('svelte/store').Readable<string[]>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   trackError: (error: Error|string, context?: object) => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useErrorTracking } from '@zeroboiler/analytics/svelte';
 *
 * const { errorCount, topErrors, trackError } = useErrorTracking({
 *     ignorePatterns: ['ResizeObserver', 'Non-Error promise rejection'],
 *     onError: (err) => console.error('Analytics captured:', err.error_message),
 * });
 *
 * // Track a caught error explicitly:
 * try {
 *     riskyOperation();
 * } catch (e) {
 *     trackError(e, { context: 'risky_operation' });
 * }
 * </script>
 *
 * {#if $errorCount > 0}
 *   <p class="error-badge">{$errorCount} errors captured</p>
 * {/if}
 * ```
 */
export function useErrorTracking(options = {}) {
    const trackErrors = options.trackErrors !== false;
    const trackRejections = options.trackRejections !== false;
    const ignorePatterns = options.ignorePatterns || [];
    const maxHistory = options.maxHistory || MAX_HISTORY;
    const eventName = options.eventName || 'error';
    const rateLimitMs = options.rateLimitMs || 5000;
    const onError = options.onError || null;

    /** @type {Function|null} Bound error handler */
    let errorHandler = null;
    /** @type {Function|null} Bound rejection handler */
    let rejectionHandler = null;
    /** @type {Map<string, number>} Rate limit tracker */
    const lastFired = new Map();

    // ─── Helpers ──────────────────────────────────────────────

    function shouldIgnore(message) {
        if (!message) return false;
        return ignorePatterns.some((pattern) => new RegExp(pattern).test(message));
    }

    function isRateLimited(message) {
        const now = Date.now();
        const last = lastFired.get(message);
        if (last && (now - last) < rateLimitMs) return true;
        lastFired.set(message, now);
        return false;
    }

    function extractErrorData(error) {
        if (error instanceof Error) {
            return {
                error_message: error.message || 'Unknown error',
                error_type: error.name || 'Error',
                error_stack: error.stack || null,
            };
        }

        if (typeof error === 'string') {
            return {
                error_message: error,
                error_type: 'StringError',
                error_stack: null,
            };
        }

        if (error && typeof error === 'object') {
            return {
                error_message: error.message || error.error?.message || String(error),
                error_type: error.name || error.error?.name || 'ObjectError',
                error_stack: error.stack || error.error?.stack || null,
            };
        }

        return {
            error_message: String(error),
            error_type: 'Unknown',
            error_stack: null,
        };
    }

    // ─── Processing ──────────────────────────────────────────

    function processError(error, source, extra) {
        const errorData = extractErrorData(error);
        const message = errorData.error_message;

        if (shouldIgnore(message)) return;
        if (isRateLimited(message)) return;

        const metadata = {
            ...errorData,
            error_source: source,
            page_path: window.location.pathname,
            page_url: window.location.href,
            client_id: getClientId(),
            timestamp: new Date().toISOString(),
            ...extra,
        };

        // Fire analytics
        if (isInitialized()) {
            try {
                trackEvent(eventName, metadata);
            } catch { /* silent — error tracking must never break the app */ }
        }

        // Update stores
        lastError.set(metadata);
        errorCount.update(n => n + 1);
        errorHistory.update(history => {
            const updated = [metadata, ...history].slice(0, maxHistory);
            return updated;
        });
        errorsByMessage.update(byMessage => {
            const updated = { ...byMessage };
            // Truncate key for grouping (strip line numbers)
            const key = message.slice(0, 120);
            updated[key] = (updated[key] || 0) + 1;
            return updated;
        });

        // Callback
        if (onError) {
            try { onError(metadata); } catch { /* silent */ }
        }
    }

    // ─── Handlers ─────────────────────────────────────────────

    function handleWindowError(event) {
        if (!trackErrors) return;

        const error = event.error || event.message || 'Unknown error';
        processError(error, event.filename || null, {
            error_line: event.lineno || null,
            error_col: event.colno || null,
        });
    }

    function handleUnhandledRejection(event) {
        if (!trackRejections) return;

        const reason = event.reason;
        const error = reason instanceof Error ? reason : (reason?.message || String(reason));
        processError(error, 'unhandled_rejection', {
            error_type: 'PromiseRejection',
        });
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof window === 'undefined') return;

        errorHandler = handleWindowError;
        rejectionHandler = handleUnhandledRejection;

        window.addEventListener('error', errorHandler);
        window.addEventListener('unhandledrejection', rejectionHandler);
        tracking.set(true);
    }

    function stop() {
        if (typeof window === 'undefined') return;

        if (errorHandler) {
            window.removeEventListener('error', errorHandler);
            errorHandler = null;
        }
        if (rejectionHandler) {
            window.removeEventListener('unhandledrejection', rejectionHandler);
            rejectionHandler = null;
        }
        tracking.set(false);
    }

    function reset() {
        lastError.set(null);
        errorCount.set(0);
        errorHistory.set([]);
        errorsByMessage.set({});
        lastFired.clear();
    }

    /**
     * Imperatively track an error.
     *
     * @param {Error|string} error - The error to track
     * @param {object} [context] - Additional context
     */
    function trackError(error, context) {
        processError(error, 'manual', context || {});
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
        lastError,
        errorCount,
        errorHistory,
        errorsByMessage,
        topErrors,
        tracking,
        start,
        stop,
        reset,
        trackError,
    };
}

export default useErrorTracking;
