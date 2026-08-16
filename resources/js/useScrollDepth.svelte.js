/**
 * ZeroBoiler Analytics — Svelte Composable: useScrollDepth
 *
 * Reactive scroll depth tracking composable for Svelte/Inertia applications.
 * Monitors page scroll position and fires analytics events at configurable
 * depth thresholds (e.g., 25%, 50%, 75%, 90%). Provides reactive stores
 * for current scroll depth, milestones reached, and engagement scoring.
 *
 * Uses IntersectionObserver for efficient scroll detection and automatically
 * resets on Inertia page navigation.
 *
 * @package ZeroBoiler Analytics
 * @version 189.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { page } from '@inertiajs/svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Current scroll depth percentage (0-100).
 * @type {import('svelte/store').Writable<number>}
 */
export const scrollPercent = writable(0);

/**
 * Scroll depth milestones that have been reached.
 * @type {import('svelte/store').Writable<number[]>}
 */
export const milestonesReached = writable([]);

/**
 * Whether scroll depth tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

/**
 * Maximum scroll depth reached in current page view.
 * @type {import('svelte/store').Writable<number>}
 */
export const maxDepth = writable(0);

/**
 * Time spent scrolling (ms).
 * @type {import('svelte/store').Writable<number>}
 */
export const scrollTimeMs = writable(0);

// ─── Internal State ────────────────────────────────────────────────

/** @type {Set<number>} Thresholds already fired */
const firedThresholds = new Set();

/** @type {number|null} RAF ID for time tracking */
let scrollTimerStart = null;

/** @type {number|null} setInterval ID */
let timeAccumulatorId = null;

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

// ─── Default Thresholds ─────────────────────────────────────────

const DEFAULT_THRESHOLDS = [25, 50, 75, 90];

// ─── Composable ───────────────────────────────────────────────────

/**
 * Scroll depth tracking composable for Svelte components.
 *
 * Monitors scroll position and fires `scroll_depth` analytics events
 * at configurable depth thresholds. Automatically resets on Inertia
 * page navigation for accurate per-page tracking.
 *
 * @param {object} [options] - Configuration options
 * @param {number[]} [options.thresholds=[25,50,75,90]] - Depth percentages to fire events at
 * @param {string} [options.eventName='scroll_depth'] - Analytics event name
 * @param {boolean} [options.autoStart=true] - Start tracking immediately
 * @param {boolean} [options.debounceMs=100] - Debounce scroll handler (ms)
 * @param {boolean} [options.trackTime=true] - Track time spent scrolling
 * @param {boolean} [options.resetOnNavigate=true] - Reset on Inertia page change
 * @param {Function} [options.onMilestone] - Callback on milestone reached (depth) => void
 * @returns {{
 *   scrollPercent: import('svelte/store').Writable<number>,
 *   milestonesReached: import('svelte/store').Writable<number[]>,
 *   maxDepth: import('svelte/store').Writable<number>,
 *   scrollTimeMs: import('svelte/store').Writable<number>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   forceTrack: (depth: number) => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useScrollDepth } from '@zeroboiler/analytics/svelte';
 *
 * const { scrollPercent, maxDepth, milestonesReached, start, stop } = useScrollDepth({
 *     thresholds: [25, 50, 75, 90, 100],
 *     onMilestone: (depth) => console.log(`Reached ${depth}%`),
 * });
 * </script>
 *
 * <p>Scroll depth: {$scrollPercent}%</p>
 * <p>Max depth: {$maxDepth}%</p>
 * <ul>
 *   {#each $milestonesReached as m}
 *     <li>Reached {m}%</li>
 *   {/each}
 * </ul>
 * ```
 */
export function useScrollDepth(options = {}) {
    const thresholds = options.thresholds || DEFAULT_THRESHOLDS;
    const eventName = options.eventName || 'scroll_depth';
    const autoStart = options.autoStart !== false;
    const debounceMs = options.debounceMs || 100;
    const trackTime = options.trackTime !== false;
    const resetOnNavigate = options.resetOnNavigate !== false;
    const onMilestone = options.onMilestone || null;

    /** @type {number|null} Debounce timer */
    let debounceTimer = null;

    // ─── Scroll Handler ────────────────────────────────────────

    function handleScroll() {
        if (debounceTimer) return;

        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            processScroll();
        }, debounceMs);
    }

    function processScroll() {
        if (typeof document === 'undefined' || typeof window === 'undefined') return;

        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;

        if (docHeight <= 0) {
            scrollPercent.set(100);
            checkThresholds(100);
            return;
        }

        const percent = Math.min(Math.round((scrollTop / docHeight) * 100), 100);
        scrollPercent.set(percent);

        // Track max depth
        maxDepth.update(current => Math.max(current, percent));

        // Check thresholds
        checkThresholds(percent);
    }

    function checkThresholds(percent) {
        for (const threshold of thresholds) {
            if (percent >= threshold && !firedThresholds.has(threshold)) {
                firedThresholds.add(threshold);
                milestonesReached.update(milestones => [...milestones, threshold].sort((a, b) => a - b));

                // Fire analytics event
                fireScrollEvent(threshold);

                // Callback
                if (onMilestone) {
                    try { onMilestone(threshold); } catch { /* silent */ }
                }
            }
        }
    }

    function fireScrollEvent(depth) {
        if (!isInitialized()) return;

        try {
            trackEvent(eventName, {
                depth_percent: depth,
                page_url: typeof window !== 'undefined' ? window.location.href : '',
                page_path: typeof window !== 'undefined' ? window.location.pathname : '',
                client_id: getClientId(),
                milestones_hit: Array.from(firedThresholds).sort((a, b) => a - b),
            });
        } catch {
            // Silent — scroll tracking should never break the app
        }
    }

    // ─── Time Tracking ─────────────────────────────────────────

    function startTimeTracking() {
        if (!trackTime) return;

        scrollTimerStart = Date.now();

        timeAccumulatorId = setInterval(() => {
            if (scrollTimerStart) {
                scrollTimeMs.update(current => current + 100);
            }
        }, 100);
    }

    function stopTimeTracking() {
        if (timeAccumulatorId) {
            clearInterval(timeAccumulatorId);
            timeAccumulatorId = null;
        }
        scrollTimerStart = null;
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof window === 'undefined') return;

        window.addEventListener('scroll', handleScroll, { passive: true });
        tracking.set(true);
        startTimeTracking();

        // Process initial scroll position
        processScroll();
    }

    function stop() {
        if (typeof window === 'undefined') return;

        window.removeEventListener('scroll', handleScroll);
        tracking.set(false);
        stopTimeTracking();

        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
    }

    function reset() {
        firedThresholds.clear();
        scrollPercent.set(0);
        maxDepth.set(0);
        milestonesReached.set([]);
        scrollTimeMs.set(0);
        stopTimeTracking();
    }

    /**
     * Manually trigger a scroll depth event at a specific depth.
     * Useful for virtual scroll or infinite scroll pages.
     *
     * @param {number} depth - Depth percentage (0-100)
     */
    function forceTrack(depth) {
        const clampedDepth = Math.max(0, Math.min(100, Math.round(depth)));
        fireScrollEvent(clampedDepth);

        if (!firedThresholds.has(clampedDepth)) {
            firedThresholds.add(clampedDepth);
            milestonesReached.update(milestones => [...milestones, clampedDepth].sort((a, b) => a - b));
        }

        maxDepth.update(current => Math.max(current, clampedDepth));
    }

    // ─── Inertia Page Navigation Reset ──────────────────────────

    function setupPageReset() {
        if (pageUnsubscribe) return;

        pageUnsubscribe = page.subscribe(() => {
            if (resetOnNavigate) {
                reset();
                // Re-start tracking for new page
                if (typeof window !== 'undefined') {
                    // Small delay to let DOM render
                    setTimeout(() => {
                        start();
                    }, 50);
                }
            }
        });
    }

    // ─── Cleanup ────────────────────────────────────────────────

    function cleanup() {
        stop();
        reset();
        if (pageUnsubscribe) {
            pageUnsubscribe();
            pageUnsubscribe = null;
        }
    }

    // ─── Auto-start ─────────────────────────────────────────────

    if (autoStart && typeof window !== 'undefined') {
        start();
        setupPageReset();
    }

    if (!cleanupRegistered) {
        cleanupRegistered = true;
        onDestroy(cleanup);
    }

    return {
        scrollPercent,
        milestonesReached,
        maxDepth,
        scrollTimeMs,
        tracking,
        start,
        stop,
        reset,
        forceTrack,
    };
}

export default useScrollDepth;
