/**
 * ZeroBoiler Analytics — Svelte Performance Tracker Composable
 *
 * Reactive Svelte store for tracking Core Web Vitals and performance scores.
 * Integrates with the main analytics client to provide real-time performance
 * data to Svelte components.
 *
 * @package ZeroBoiler Analytics
 * @version 41.0.0
 */

import { writable, derived } from 'svelte/store';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Individual Web Vitals metric values (reactive).
 * @type {import('svelte/store').Writable<Object<string, {value: number, rating: string}>>}
 */
export const webVitals = writable({});

/**
 * Overall performance score (reactive).
 * @type {import('svelte/store').Writable<{score: number, rating: string, timestamp: number}>}
 */
export const performanceScore = writable({ score: 0, rating: 'unknown', timestamp: 0 });

/**
 * Whether the performance tracker is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const isTracking = writable(false);

/**
 * Derived store: performance rating as human-readable label.
 */
export const performanceLabel = derived(performanceScore, ($score) => {
    if ($score.score >= 90) return '🟢 Excellent';
    if ($score.score >= 50) return '🟡 Needs Improvement';
    if ($score.score > 0) return '🔴 Poor';
    return '⚪ Not measured';
});

// ─── Internal State ─────────────────────────────────────────────────

let cleanupFn = null;
let scoreTimeout = null;

// ─── Composable ─────────────────────────────────────────────────────

/**
 * Svelte composable for performance tracking.
 *
 * Automatically tracks Core Web Vitals (LCP, INP, CLS, TTFB, FCP) using
 * the Performance Observer API and updates reactive stores.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.enabled=true] - Enable tracking
 * @param {boolean} [options.autoScore=true] - Auto-compute performance score
 * @param {number} [options.scoreDelayMs=3000] - Delay before computing score (ms)
 * @returns {{ webVitals, performanceScore, performanceLabel, isTracking, start, stop, getMetrics }}
 *
 * @example
 * ```svelte
 * <script>
 *   import { usePerformanceTracker } from './usePerformanceTracker.svelte.js';
 *
 *   const { performanceScore, performanceLabel } = usePerformanceTracker();
 * </script>
 *
 * <div>
 *   Performance: {performanceLabel} ({performanceScore.score}/100)
 * </div>
 * ```
 */
export function usePerformanceTracker(options = {}) {
    const enabled = options.enabled !== false;
    const autoScore = options.autoScore !== false;
    const scoreDelayMs = options.scoreDelayMs || 3000;

    function start() {
        if (typeof window === 'undefined' || typeof PerformanceObserver === 'undefined') {
            return;
        }

        isTracking.set(true);

        const observers = [];

        // LCP
        try {
            const lcpObserver = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    const value = entry.startTime;
                    const rating = value <= 2500 ? 'good' : value <= 4000 ? 'needs-improvement' : 'poor';
                    webVitals.update(prev => ({
                        ...prev,
                        LCP: { value: Math.round(value), rating },
                    }));
                }
            });
            lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
            observers.push(lcpObserver);
        } catch { /* not supported */ }

        // INP / FID
        try {
            const inpObserver = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    const value = entry.duration;
                    const rating = value <= 200 ? 'good' : value <= 500 ? 'needs-improvement' : 'poor';
                    webVitals.update(prev => ({
                        ...prev,
                        INP: { value: Math.round(value), rating },
                    }));
                }
            });

            if (PerformanceObserver.supportedEntryTypes?.includes('event')) {
                inpObserver.observe({ type: 'event', buffered: true });
                observers.push(inpObserver);
            } else {
                // FID fallback
                const fidObserver = new PerformanceObserver((list) => {
                    for (const entry of list.getEntries()) {
                        const value = entry.processingStart - entry.startTime;
                        const rating = value <= 100 ? 'good' : value <= 300 ? 'needs-improvement' : 'poor';
                        webVitals.update(prev => ({
                            ...prev,
                            FID: { value: Math.round(value), rating },
                        }));
                    }
                });
                fidObserver.observe({ type: 'first-input', buffered: true });
                observers.push(fidObserver);
            }
        } catch { /* not supported */ }

        // CLS
        try {
            const clsObserver = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) {
                        const value = entry.value;
                        const rating = value <= 0.1 ? 'good' : value <= 0.25 ? 'needs-improvement' : 'poor';
                        webVitals.update(prev => ({
                            ...prev,
                            CLS: { value: Math.round(value * 1000) / 1000, rating },
                        }));
                    }
                }
            });
            clsObserver.observe({ type: 'layout-shift', buffered: true });
            observers.push(clsObserver);
        } catch { /* not supported */ }

        // TTFB
        try {
            const ttfbObserver = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    const value = entry.responseStart;
                    const rating = value <= 800 ? 'good' : value <= 1800 ? 'needs-improvement' : 'poor';
                    webVitals.update(prev => ({
                        ...prev,
                        TTFB: { value: Math.round(value), rating },
                    }));
                }
            });
            ttfbObserver.observe({ type: 'navigation', buffered: true });
            observers.push(ttfbObserver);
        } catch { /* not supported */ }

        // FCP
        try {
            const fcpObserver = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (entry.name === 'first-contentful-paint') {
                        const value = entry.startTime;
                        const rating = value <= 1800 ? 'good' : value <= 3000 ? 'needs-improvement' : 'poor';
                        webVitals.update(prev => ({
                            ...prev,
                            FCP: { value: Math.round(value), rating },
                        }));
                    }
                }
            });
            fcpObserver.observe({ type: 'paint', buffered: true });
            observers.push(fcpObserver);
        } catch { /* not supported */ }

        // Auto-score after delay
        if (autoScore) {
            scoreTimeout = setTimeout(() => {
                computeScore();
            }, scoreDelayMs);

            // Also compute on page hide
            const onHidden = () => computeScore();
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') onHidden();
            });
        }

        cleanupFn = () => {
            for (const observer of observers) {
                observer.disconnect();
            }
            if (scoreTimeout) clearTimeout(scoreTimeout);
        };
    }

    function stop() {
        if (cleanupFn) {
            cleanupFn();
            cleanupFn = null;
        }
        isTracking.set(false);
    }

    function getMetrics() {
        let metrics = {};
        const unsubscribe = webVitals.subscribe(v => { metrics = v; });
        unsubscribe();
        return metrics;
    }

    function computeScore() {
        const metrics = getMetrics();
        const weights = { LCP: 0.25, INP: 0.30, CLS: 0.25, TTFB: 0.20 };
        const thresholds = {
            LCP: [2500, 4000],
            INP: [200, 500],
            FID: [100, 300],
            CLS: [0.1, 0.25],
            TTFB: [800, 1800],
        };

        let totalWeight = 0;
        let weightedScore = 0;

        for (const [metric, weight] of Object.entries(weights)) {
            const metricData = metrics[metric];
            if (!metricData) continue;

            const value = metricData.value;
            const [good, poor] = thresholds[metric] || [0, Infinity];
            let score = 0;

            if (value <= good) score = 3;
            else if (value <= poor) score = 2;
            else score = 1;

            weightedScore += score * weight;
            totalWeight += weight;
        }

        if (totalWeight > 0) {
            const normalized = Math.round((weightedScore / (3 * totalWeight)) * 100);
            const rating = normalized >= 90 ? 'good' : normalized >= 50 ? 'needs-improvement' : 'poor';

            performanceScore.set({
                score: normalized,
                rating,
                timestamp: Date.now(),
            });
        }
    }

    // Auto-start if enabled
    if (enabled) {
        start();
    }

    return {
        webVitals,
        performanceScore,
        performanceLabel,
        isTracking,
        start,
        stop,
        getMetrics,
    };
}

export default usePerformanceTracker;
