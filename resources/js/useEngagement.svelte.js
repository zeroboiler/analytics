/**
 * ZeroBoiler Analytics — useEngagement Unified Svelte Composable
 *
 * Single entry point for all client-side engagement tracking: click, form,
 * search, share, error, and scroll depth. Initializes all sub-composables
 * from Inertia page props and provides a unified reactive interface.
 *
 * @since 272.0.0
 * @package ZeroBoiler Analytics
 */

import { derived, writable } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import {
    initClickTracking,
    clickCount,
    lastClick,
    resetClickTracking,
} from './useClickTracking.svelte.js';
import {
    initFormTracking,
    formCount,
    lastFormEvent,
    resetFormTracking,
} from './useFormTracking.svelte.js';
import {
    initSearchTracking,
    searchCount,
    lastSearch,
    resetSearchTracking,
} from './useSearchTracking.svelte.js';
import {
    initShareTracking,
    shareCount,
    lastShare,
    resetShareTracking,
} from './useShareTracking.svelte.js';
import {
    initErrorTracking,
    errorCount,
    lastError,
    resetErrorTracking,
} from './useErrorTracking.svelte.js';
import {
    initScrollDepth as initScroll,
    scrollDepth,
    maxScrollDepth,
    resetScrollDepth,
} from './useScrollDepth.svelte.js';

/**
 * Whether the engagement suite has been initialized.
 */
export const isInitialized = writable(false);

/**
 * Configuration flags from Inertia props.
 */
export const config = writable({
    clickTracking: true,
    formTracking: true,
    searchTracking: true,
    shareTracking: true,
    errorTracking: true,
    scrollDepth: true,
    scrollDepthThresholds: [25, 50, 75, 90, 100],
});

/**
 * Total engagement interactions (all categories combined).
 */
export const totalInteractions = derived(
    [clickCount, formCount, searchCount, shareCount, errorCount],
    ([$clicks, $forms, $searches, $shares, $errors]) => {
        return $clicks + $forms + $searches + $shares + $errors;
    },
);

/**
 * Most recent engagement event across all categories.
 */
export const lastEngagementEvent = derived(
    [lastClick, lastFormEvent, lastSearch, lastShare, lastError],
    ([$click, $form, $search, $share, $error]) => {
        const events = [$click, $form, $search, $share, $error].filter(Boolean);
        if (events.length === 0) return null;
        return events.sort((a, b) =>
            new Date(b.timestamp || 0) - new Date(a.timestamp || 0),
        )[0];
    },
);

/**
 * Engagement score (0–100) based on interaction breadth and depth.
 *
 * Scoring heuristic:
 * - Click interactions: 10 points each (max 30)
 * - Form interactions: 15 points each (max 30)
 * - Search interactions: 10 points each (max 15)
 * - Share interactions: 20 points each (max 20)
 * - Scroll depth: proportional (max 5, based on max depth %)
 *
 * Capped at 100.
 */
export const engagementScore = derived(
    [clickCount, formCount, searchCount, shareCount, maxScrollDepth],
    ([$clicks, $forms, $searches, $shares, $maxScroll]) => {
        let score = 0;

        // Click breadth
        score += Math.min($clicks * 10, 30);

        // Form engagement
        score += Math.min($forms * 15, 30);

        // Search activity
        score += Math.min($searches * 10, 15);

        // Sharing behavior
        score += Math.min($shares * 20, 20);

        // Scroll depth (percentage of page read)
        score += ($maxScroll / 100) * 5;

        return Math.min(Math.round(score), 100);
    },
);

/**
 * Per-category engagement breakdown.
 */
export const engagementBreakdown = derived(
    [clickCount, formCount, searchCount, shareCount, errorCount],
    ([$clicks, $forms, $searches, $shares, $errors]) => ({
        clicks: $clicks,
        forms: $forms,
        searches: $searches,
        shares: $shares,
        errors: $errors,
    }),
);

/**
 * Initialize all engagement tracking from Inertia page props.
 *
 * Reads `zbAnalytics.autoTrack` from Inertia props to determine which
 * sub-composables to activate. Falls back to enabling all if props are
 * not available.
 *
 * @param {object} zbProps - The `page.props.zbAnalytics` object from Inertia
 *
 * @example
 * import { initEngagement } from '../resources/js/useEngagement.svelte.js';
 *
 * // In your root Svelte component:
 * $effect(() => {
 *     if (page.props.zbAnalytics) {
 *         initEngagement(page.props.zbAnalytics);
 *     }
 * });
 */
export function initEngagement(zbProps) {
    if (!zbProps) {
        return;
    }

    const autoTrack = zbProps.autoTrack || {};
    const cfg = {
        clickTracking: autoTrack.link_tracking ?? true,
        formTracking: autoTrack.form_tracking ?? true,
        searchTracking: true,
        shareTracking: true,
        errorTracking: autoTrack.error_tracking ?? true,
        scrollDepth: autoTrack.scroll_depth ?? true,
        scrollDepthThresholds: zbProps.scrollDepthThresholds || [25, 50, 75, 90, 100],
    };

    config.set(cfg);

    // Initialize each sub-composable
    if (cfg.clickTracking) {
        initClickTracking(zbProps);
    }

    if (cfg.formTracking) {
        initFormTracking(zbProps);
    }

    if (cfg.searchTracking) {
        initSearchTracking(zbProps);
    }

    if (cfg.shareTracking) {
        initShareTracking(zbProps);
    }

    if (cfg.errorTracking) {
        initErrorTracking(zbProps);
    }

    if (cfg.scrollDepth) {
        initScroll(cfg.scrollDepthThresholds);
    }

    isInitialized.set(true);
}

/**
 * Reset all engagement tracking state.
 *
 * Useful when navigating between pages in a SPA where you want
 * * to reset counters (e.g., on page change).
 */
export function resetEngagement() {
    resetClickTracking();
    resetFormTracking();
    resetSearchTracking();
    resetShareTracking();
    resetErrorTracking();
    resetScrollDepth();
    isInitialized.set(false);
}

/**
 * Get a snapshot of the current engagement state.
 *
 * Returns a plain object suitable for analytics event tracking
 * * or debugging. Not reactive — captures point-in-time values.
 *
 * @returns {object} Engagement snapshot
 */
export function getEngagementSnapshot() {
    let clicks = 0, forms = 0, searches = 0, shares = 0, errors = 0;
    let lastClickVal = null, lastFormVal = null, lastSearchVal = null;
    let lastShareVal = null, lastErrorVal = null, scrollVal = 0, maxScrollVal = 0;

    const unsubClick = clickCount.subscribe((v) => { clicks = v; });
    const unsubForm = formCount.subscribe((v) => { forms = v; });
    const unsubSearch = searchCount.subscribe((v) => { searches = v; });
    const unsubShare = shareCount.subscribe((v) => { shares = v; });
    const unsubError = errorCount.subscribe((v) => { errors = v; });
    const unsubLastClick = lastClick.subscribe((v) => { lastClickVal = v; });
    const unsubLastForm = lastFormEvent.subscribe((v) => { lastFormVal = v; });
    const unsubLastSearch = lastSearch.subscribe((v) => { lastSearchVal = v; });
    const unsubLastShare = lastShare.subscribe((v) => { lastShareVal = v; });
    const unsubLastError = lastError.subscribe((v) => { lastErrorVal = v; });
    const unsubScroll = scrollDepth.subscribe((v) => { scrollVal = v; });
    const unsubMaxScroll = maxScrollDepth.subscribe((v) => { maxScrollVal = v; });

    unsubClick();
    unsubForm();
    unsubSearch();
    unsubShare();
    unsubError();
    unsubLastClick();
    unsubLastForm();
    unsubLastSearch();
    unsubLastShare();
    unsubLastError();
    unsubScroll();
    unsubMaxScroll();

    return {
        clicks,
        forms,
        searches,
        shares,
        errors,
        scroll_depth: scrollVal,
        max_scroll_depth: maxScrollVal,
        total_interactions: clicks + forms + searches + shares + errors,
        last_click: lastClickVal,
        last_form: lastFormVal,
        last_search: lastSearchVal,
        last_share: lastShareVal,
        last_error: lastErrorVal,
    };
}

export default {
    isInitialized,
    config,
    clickCount,
    formCount,
    searchCount,
    shareCount,
    errorCount,
    scrollDepth,
    maxScrollDepth,
    totalInteractions,
    lastEngagementEvent,
    engagementScore,
    engagementBreakdown,
    initEngagement,
    resetEngagement,
    getEngagementSnapshot,
};
