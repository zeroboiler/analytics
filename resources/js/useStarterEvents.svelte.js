/**
 * ZeroBoiler Analytics — useStarterEvents Svelte Composable
 *
 * Reactive access to the SaaS Starter Events instrumentation payload
 * from Inertia page props. Provides derived stores for gaps, coverage,
 * category counts, and priority-sorted event lists.
 *
 * @since 271.0.0
 * @package ZeroBoiler Analytics
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';

/**
 * Raw starter events payload from Inertia props.
 */
export const starterEvents = writable(null);

/**
 * Reactive lifecycle health summary.
 */
export const lifecycleHealth = writable({
    valid: true,
    errors: 0,
    warnings: 0,
    info: 0,
    total_issues: 0,
});

/**
 * Sorted events by priority index (lowest = highest priority).
 */
export const sortedEvents = derived(starterEvents, ($events) => {
    if (!$events?.events) return [];
    return [...$events.events].sort((a, b) => (a.priority_index ?? 999) - (b.priority_index ?? 999));
});

/**
 * Events that have gaps (not in catalog).
 */
export const gapEvents = derived(starterEvents, ($events) => {
    if (!$events?.events) return [];
    return $events.events.filter((e) => e.is_gap);
});

/**
 * Coverage percentage.
 */
export const coverage = derived(starterEvents, ($events) => {
    return $events?.coverage ?? 0;
});

/**
 * Number of gap events.
 */
export const gapCount = derived(starterEvents, ($events) => {
    return $events?.gapCount ?? 0;
});

/**
 * Whether all starter events are covered (100%).
 */
export const isFullyCovered = derived(coverage, ($coverage) => $coverage >= 100);

/**
 * Whether lifecycle mappings are valid (no errors).
 */
export const isLifecycleValid = derived(lifecycleHealth, ($health) => $health.valid);

/**
 * Initialize the composable from Inertia page props.
 *
 * Call this once in your root layout or App.svelte.
 *
 * @param {object} zbProps - The `page.props.zbAnalytics` object
 *
 * @example
 * import { initStarterEvents } from '../resources/js/useStarterEvents.svelte.js';
 *
 * // In your root Svelte component:
 * $effect(() => {
 *     if (page.props.zbAnalytics) {
 *         initStarterEvents(page.props.zbAnalytics);
 *     }
 * });
 */
export function initStarterEvents(zbProps) {
    if (zbProps?.starterEvents) {
        starterEvents.set(zbProps.starterEvents);
    }

    if (zbProps?.lifecycleHealth) {
        lifecycleHealth.set(zbProps.lifecycleHealth);
    }
}

/**
 * Reset all stores to defaults.
 */
export function resetStarterEvents() {
    starterEvents.set(null);
    lifecycleHealth.set({
        valid: true,
        errors: 0,
        warnings: 0,
        info: 0,
        total_issues: 0,
    });
}

export default {
    starterEvents,
    lifecycleHealth,
    sortedEvents,
    gapEvents,
    coverage,
    gapCount,
    isFullyCovered,
    isLifecycleValid,
    initStarterEvents,
    resetStarterEvents,
};
