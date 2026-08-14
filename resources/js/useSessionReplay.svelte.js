/**
 * ZeroBoiler Analytics — Svelte Composable: useSessionReplay
 *
 * Reactive Svelte composable for session replay and screen recording
 * analytics integration. Bridges client-side session recording events
 * with the ZeroBoiler analytics pipeline.
 *
 * Supports: PostHog session replay, Hotjar-style event capture,
 * and custom session recording provider integration via analytics API.
 *
 * @package ZeroBoiler Analytics
 * @version 137.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import { trackEvent, isInitialized, getTrackingId, getVersion } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Whether session replay recording is currently active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const recordingActive = writable(false);

/**
 * Current recording session ID (assigned by the recording provider).
 * @type {import('svelte/store').Writable<string|null>}
 */
export const recordingSessionId = writable(null);

/**
 * Recording provider name (e.g. 'posthog', 'hotjar', 'custom').
 * @type {import('svelte/store').Writable<string>}
 */
export const recordingProvider = writable('none');

/**
 * Session replay quality settings.
 * @type {import('svelte/store').Writable<{quality: number, fps: number, maxDuration: number}>}
 */
export const recordingSettings = writable({
    quality: 0.5,
    fps: 1,
    maxDuration: 300,
});

/**
 * Number of recording events captured in the current session.
 * @type {import('svelte/store').Writable<number>}
 */
export const eventCount = writable(0);

/**
 * Whether session replay is available/enabled based on config and consent.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const sessionReplayAvailable = writable(false);

/**
 * Error state for session replay initialization.
 * @type {import('svelte/store').Writable<string|null>}
 */
export const recordingError = writable(null);

// ─── Internal State ─────────────────────────────────────────────────

/** @type {number|null} */
let eventCaptureTimer = null;

/** @type {number|null} */
let durationTimer = null;

/** @type {number} */
let sessionDuration = 0;

/** @type {Function|null} */
let domObserverCleanup = null;

// ─── Constants ─────────────────────────────────────────────────────

/** Default recording settings */
const DEFAULT_SETTINGS = {
    quality: 0.5,       // 0.0-1.0 capture quality
    fps: 1,             // 1 frame per second (lightweight)
    maxDuration: 300,   // 5 minutes max recording
};

/** Sensitive elements to exclude from DOM snapshots */
const SENSITIVE_SELECTORS = [
    '[type="password"]',
    '[data-sensitive="true"]',
    '.zb-no-record',
    'input[name*="card"]',
    'input[name*="cvv"]',
    'input[name*="ssn"]',
    'input[name*="social"]',
];

/** Maximum events before auto-flush */
const MAX_EVENTS_BEFORE_FLUSH = 50;

/** DOM mutation batch interval (ms) */
const DOM_BATCH_INTERVAL = 2000;

// ─── DOM Snapshot Utilities ──────────────────────────────────────────

/**
 * Create a sanitized DOM snapshot for session replay.
 *
 * Removes sensitive elements, scripts, and iframes before capturing.
 * Returns a lightweight JSON representation of visible DOM structure.
 *
 * @param {Document} doc - Document to snapshot
 * @param {object} options - Snapshot options
 * @param {number} [options.maxDepth=3] - Max DOM traversal depth
 * @param {boolean} [options.includeText=true] - Include text content
 * @returns {object} Sanitized DOM snapshot
 */
function createSanitizedSnapshot(doc, options = {}) {
    const maxDepth = options.maxDepth ?? 3;
    const includeText = options.includeText !== false;

    function serializeNode(node, depth) {
        if (depth > maxDepth) return null;

        // Skip sensitive elements
        if (node.nodeType === Node.ELEMENT_NODE) {
            for (const selector of SENSITIVE_SELECTORS) {
                if (node.matches && node.matches(selector)) {
                    return { tag: '[redacted]', depth };
                }
            }

            // Skip non-visible elements
            const style = window.getComputedStyle(node);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return null;
            }
        }

        // Skip scripts, styles, iframes
        if (node.nodeType === Node.ELEMENT_NODE) {
            const tag = node.tagName.toLowerCase();
            if (['script', 'style', 'link', 'iframe', 'noscript'].includes(tag)) {
                return null;
            }
        }

        const result = {
            tag: node.nodeType === Node.ELEMENT_NODE ? node.tagName.toLowerCase() : 'text',
            depth,
        };

        if (node.nodeType === Node.ELEMENT_NODE) {
            // Capture key attributes (id, class, data-testid)
            if (node.id) result.id = node.id;
            if (node.className && typeof node.className === 'string') {
                const classes = node.className.split(' ').filter(c => !c.startsWith('zb-'));
                if (classes.length > 0) result.class = classes.slice(0, 5);
            }
            const testId = node.getAttribute('data-testid');
            if (testId) result.testid = testId;

            // Capture dimensions
            const rect = node.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0) {
                result.bounds = {
                    x: Math.round(rect.x),
                    y: Math.round(rect.y),
                    w: Math.round(rect.width),
                    h: Math.round(rect.height),
                };
            }
        }

        if (includeText && node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent?.trim();
            if (text && text.length < 100) {
                result.text = text;
            }
        }

        return result;
    }

    // Serialize body only (skip head)
    const body = doc.body;
    if (!body) return { tag: 'empty', children: [] };

    const children = [];
    const walker = doc.createTreeWalker(body, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    let currentNode = walker.nextNode();
    let count = 0;
    const maxNodes = 200; // Limit snapshot size

    while (currentNode && count < maxNodes) {
        // Calculate depth from body
        let depth = 0;
        let parent = currentNode.parentElement;
        while (parent && parent !== body) {
            depth++;
            parent = parent.parentElement;
        }

        const serialized = serializeNode(currentNode, depth);
        if (serialized) {
            children.push(serialized);
            count++;
        }
        currentNode = walker.nextNode();
    }

    return { tag: 'root', childCount: count, children };
}

/**
 * Observe DOM mutations and batch-capture them as replay events.
 *
 * Uses MutationObserver to detect DOM changes and batches them
 * into periodic snapshot events rather than sending individual mutations.
 *
 * @param {function} onBatch - Callback with batched mutation count
 * @returns {function} Cleanup function
 */
function observeDOMMutations(onBatch) {
    if (typeof MutationObserver === 'undefined') {
        return () => {};
    }

    let mutationCount = 0;
    let batchTimer = null;

    const observer = new MutationObserver((mutations) => {
        mutationCount += mutations.length;

        if (!batchTimer) {
            batchTimer = setTimeout(() => {
                if (mutationCount > 0) {
                    onBatch(mutationCount);
                    mutationCount = 0;
                }
                batchTimer = null;
            }, DOM_BATCH_INTERVAL);
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'data-state', 'aria-expanded', 'hidden'],
    });

    return () => {
        observer.disconnect();
        if (batchTimer) {
            clearTimeout(batchTimer);
            batchTimer = null;
        }
    };
}

// ─── Composable ─────────────────────────────────────────────────────

/**
 * Session replay analytics composable for Svelte.
 *
 * Provides reactive stores and methods for session recording
 * integration with ZeroBoiler analytics. Captures DOM snapshots,
 * user interactions, and error events for replay analysis.
 *
 * Session replay events are dispatched through the standard analytics
 * pipeline to all configured providers (PostHog, etc.).
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoStart=false] - Auto-start recording on mount
 * @param {boolean} [options.captureDOM=false] - Capture DOM mutation snapshots
 * @param {boolean} [options.captureErrors=true] - Capture JS errors during recording
 * @param {boolean} [options.captureClicks=true] - Capture click events during recording
 * @param {number} [options.quality=0.5] - Recording quality (0.0-1.0)
 * @param {number} [options.maxDuration=300] - Max recording duration in seconds
 * @param {number} [options.snapshotIntervalMs=5000] - Interval between DOM snapshots
 * @returns {{
 *   recordingActive: import('svelte/store').Writable<boolean>,
 *   recordingSessionId: import('svelte/store').Writable<string|null>,
 *   recordingProvider: import('svelte/store').Writable<string>,
 *   recordingSettings: import('svelte/store').Writable<object>,
 *   eventCount: import('svelte/store').Writable<number>,
 *   sessionReplayAvailable: import('svelte/store').Writable<boolean>,
 *   recordingError: import('svelte/store').Writable<string|null>,
 *   start: () => void,
 *   stop: () => void,
 *   pause: () => void,
 *   resume: () => void,
 *   captureSnapshot: () => void,
 *   captureEvent: (type: string, data?: object) => void,
 *   sessionDuration: import('svelte/store').Readable<number>,
 *   isActive: () => boolean,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useSessionReplay } from '@zeroboiler/analytics';
 *
 * const {
 *     recordingActive,
 *     recordingSessionId,
 *     eventCount,
 *     sessionDuration,
 *     start,
 *     stop,
 * } = useSessionReplay({ autoStart: true, captureDOM: true });
 * </script>
 *
 * <div class="replay-indicator" class:recording={$recordingActive}>
 *   {#if $recordingActive}
 *     <span class="dot recording-dot"></span>
 *     <span>Recording — {$eventCount} events — {Math.floor($sessionDuration)}s</span>
 *     <button on:click={stop}>Stop</button>
 *   {/if}
 * </div>
 * ```
 */
export function useSessionReplay(options = {}) {
    const autoStart = options.autoStart === true;
    const captureDOM = options.captureDOM === true;
    const captureErrors = options.captureErrors !== false;
    const captureClicks = options.captureClicks !== false;
    const quality = options.quality ?? 0.5;
    const maxDuration = options.maxDuration ?? 300;
    const snapshotIntervalMs = options.snapshotIntervalMs ?? 5000;

    // ── Reactive duration store ──────────────────────────────

    /** @type {import('svelte/store').Writable<number>} */
    const _duration = writable(0);
    const sessionDuration = derived(_duration, (d) => d);

    // ── Check availability from config ───────────────────────

    function checkAvailability() {
        try {
            const analytics = page.props?.zbAnalytics;
            const sessionRecording = analytics?.sessionRecording;

            if (sessionRecording?.enabled && sessionRecording.providers?.length > 0) {
                sessionReplayAvailable.set(true);
                recordingProvider.set(sessionRecording.providers[0] ?? 'custom');
                return true;
            }
        } catch {
            // Ignore
        }

        // Fallback: check if PostHog or main analytics is initialized
        if (isInitialized()) {
            sessionReplayAvailable.set(true);
            recordingProvider.set('analytics');
            return true;
        }

        sessionReplayAvailable.set(false);
        return false;
    }

    // ── Generate session ID ───────────────────────────────────

    function generateSessionId() {
        return 'sr_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 8);
    }

    // ── Start Recording ─────────────────────────────────────

    function start() {
        if (!isInitialized()) {
            recordingError.set('Analytics not initialized — call init() first');
            return;
        }

        if (recordingActive) return;

        const sessionId = generateSessionId();
        recordingSessionId.set(sessionId);
        recordingActive.set(true);
        eventCount.set(0);
        sessionDuration = 0;
        _duration.set(0);
        recordingError.set(null);
        recordingSettings.set({ quality, fps: 1, maxDuration });

        // Track session replay start event
        trackEvent('session_replay_start', {
            session_replay_id: sessionId,
            provider: recordingProvider,
            quality,
            max_duration: maxDuration,
        }, { immediate: true });

        // Start duration timer
        durationTimer = setInterval(() => {
            sessionDuration++;
            _duration.set(sessionDuration);

            // Auto-stop at max duration
            if (sessionDuration >= maxDuration) {
                stop();
            }
        }, 1000);

        // DOM mutation observer
        if (captureDOM) {
            domObserverCleanup = observeDOMMutations((mutationCount) => {
                captureEvent('dom_mutation', { mutation_count: mutationCount });
            });

            // Periodic full DOM snapshots
            eventCaptureTimer = setInterval(() => {
                captureSnapshot();
            }, snapshotIntervalMs);
        }

        // Error capture during recording
        if (captureErrors) {
            const errorHandler = (event) => {
                captureEvent('js_error', {
                    message: event.message,
                    filename: event.filename,
                    lineno: event.lineno,
                    colno: event.colno,
                });
            };
            window.addEventListener('error', errorHandler);
            // Store reference for cleanup
            window._zb_sr_error_handler = errorHandler;
        }

        // Click capture during recording
        if (captureClicks) {
            const clickHandler = (event) => {
                const target = event.target;
                if (!target) return;

                const selector = getMinimalSelector(target);
                captureEvent('click', {
                    selector,
                    x: event.clientX,
                    y: event.clientY,
                    target_tag: target.tagName?.toLowerCase(),
                    target_id: target.id || undefined,
                });
            };
            document.addEventListener('click', clickHandler, { passive: true, capture: true });
            window._zb_sr_click_handler = clickHandler;
        }
    }

    // ── Stop Recording ──────────────────────────────────────

    function stop() {
        if (!recordingActive) return;

        // Final snapshot before stopping
        if (captureDOM) {
            captureSnapshot();
        }

        // Track session replay stop event
        const sid = typeof recordingSessionId === 'function' ? null : recordingSessionId;
        trackEvent('session_replay_stop', {
            session_replay_id: typeof recordingSessionId.subscribe === 'function' ? null : sid,
            duration_seconds: sessionDuration,
            event_count: typeof eventCount.subscribe === 'function' ? 0 : eventCount,
        }, { immediate: true });

        // Cleanup
        recordingActive.set(false);
        recordingSessionId.set(null);

        if (durationTimer) {
            clearInterval(durationTimer);
            durationTimer = null;
        }

        if (eventCaptureTimer) {
            clearInterval(eventCaptureTimer);
            eventCaptureTimer = null;
        }

        if (domObserverCleanup) {
            domObserverCleanup();
            domObserverCleanup = null;
        }

        // Remove error handler
        if (window._zb_sr_error_handler) {
            window.removeEventListener('error', window._zb_sr_error_handler);
            delete window._zb_sr_error_handler;
        }

        // Remove click handler
        if (window._zb_sr_click_handler) {
            document.removeEventListener('click', window._zb_sr_click_handler, true);
            delete window._zb_sr_click_handler;
        }

        sessionDuration = 0;
        _duration.set(0);
    }

    // ── Pause Recording ────────────────────────────────────

    function pause() {
        if (!recordingActive) return;

        if (durationTimer) {
            clearInterval(durationTimer);
            durationTimer = null;
        }

        if (eventCaptureTimer) {
            clearInterval(eventCaptureTimer);
            eventCaptureTimer = null;
        }

        if (domObserverCleanup) {
            domObserverCleanup();
            domObserverCleanup = null;
        }

        trackEvent('session_replay_pause', {
            duration_seconds: sessionDuration,
        }, { immediate: true });
    }

    // ── Resume Recording ─────────────────────────────────────

    function resume() {
        if (!recordingActive) return;

        durationTimer = setInterval(() => {
            sessionDuration++;
            _duration.set(sessionDuration);

            if (sessionDuration >= maxDuration) {
                stop();
            }
        }, 1000);

        if (captureDOM) {
            domObserverCleanup = observeDOMMutations((mutationCount) => {
                captureEvent('dom_mutation', { mutation_count: mutationCount });
            });

            eventCaptureTimer = setInterval(() => {
                captureSnapshot();
            }, snapshotIntervalMs);
        }

        trackEvent('session_replay_resume', {
            duration_seconds: sessionDuration,
        }, { immediate: true });
    }

    // ── Capture DOM Snapshot ────────────────────────────────

    function captureSnapshot() {
        if (!recordingActive) return;

        try {
            const snapshot = createSanitizedSnapshot(document, {
                maxDepth: 3,
                includeText: false,
            });

            eventCount.update(n => n + 1);

            trackEvent('session_replay_snapshot', {
                snapshot: snapshot,
                duration_seconds: sessionDuration,
                url: typeof window !== 'undefined' ? window.location.pathname : '',
            });
        } catch {
            // Snapshot capture should never break the app
        }
    }

    // ── Capture Custom Event ──────────────────────────────────

    function captureEvent(type, data = {}) {
        if (!recordingActive) return;

        eventCount.update(n => n + 1);

        trackEvent('session_replay_event', {
            replay_event_type: type,
            ...data,
            duration_seconds: sessionDuration,
        });
    }

    // ── Check Active State ───────────────────────────────────

    function isActive() {
        let active = false;
        const unsub = recordingActive.subscribe(a => { active = a; });
        unsub();
        return active;
    }

    // ── Auto-start ──────────────────────────────────────────

    if (autoStart && typeof window !== 'undefined') {
        // Delay to allow page props to resolve
        setTimeout(() => {
            if (checkAvailability()) {
                start();
            }
        }, 200);
    } else {
        checkAvailability();
    }

    return {
        // Stores
        recordingActive,
        recordingSessionId,
        recordingProvider,
        recordingSettings,
        eventCount,
        sessionReplayAvailable,
        recordingError,

        // Actions
        start,
        stop,
        pause,
        resume,
        captureSnapshot,
        captureEvent,

        // Derived
        sessionDuration,

        // Helpers
        isActive,
    };
}

// ─── Utility: Minimal CSS Selector ──────────────────────────────────

/**
 * Generate a minimal, privacy-safe CSS selector for an element.
 *
 * Returns a concise selector using id, data-testid, or tag + class
 * (max 2 classes). Never includes sensitive attributes or text content.
 *
 * @param {Element} element - DOM element
 * @returns {string} Minimal CSS selector
 */
function getMinimalSelector(element) {
    if (!element || !element.tagName) return 'unknown';

    // Prefer data-testid
    const testId = element.getAttribute('data-testid');
    if (testId) return `[data-testid="${testId}"]`;

    // Prefer id (unless auto-generated UUID-like)
    if (element.id && !element.id.startsWith('zb_')) {
        return `#${element.id}`;
    }

    // Tag + up to 2 classes
    const tag = element.tagName.toLowerCase();
    const classes = element.className
        ? element.className.split(' ').filter(c => !c.startsWith('zb-')).slice(0, 2)
        : [];

    if (classes.length > 0) {
        return `${tag}.${classes.join('.')}`;
    }

    return tag;
}

/**
 * Convenience shorthand: create a session replay composable with default options.
 *
 * @example
 * ```svelte
 * import { sessionReplay } from '@zeroboiler/analytics';
 * const { recordingActive, start, stop } = sessionReplay({ autoStart: true });
 * ```
 */
export function sessionReplay(options = {}) {
    return useSessionReplay(options);
}

export default useSessionReplay;
