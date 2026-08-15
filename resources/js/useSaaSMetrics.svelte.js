/**
 * ZeroBoiler Analytics — Svelte Composable: useSaaSMetrics
 *
 * Reactive SaaS metrics composable for Svelte/Inertia dashboards.
 * Provides real-time access to SaaS KPI, maturity score, onboarding
 * progress, funnel readiness, recommended events, and event costs
 * from Inertia page props (zbAnalytics).
 *
 * Designed for admin dashboards, onboarding checklists, and
 * product analytics widgets.
 *
 * @package ZeroBoiler Analytics
 * @version 148.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';

// ─── Reactive Stores ──────────────────────────────────────────────

/**
 * Analytics maturity score (0-100) with letter grade.
 * @type {import('svelte/store').Writable<{score: number, grade: string}>}
 */
export const maturityScore = writable({ score: 0, grade: 'N/A' });

/**
 * Onboarding checklist progress.
 * @type {import('svelte/store').Writable<{completion: number, gaps: string[]}>}
 */
export const onboarding = writable({ completion: 0, gaps: [] });

/**
 * Funnel readiness scores by funnel type.
 * @type {import('svelte/store').Writable<{signup: number, purchase: number, subscription: number, overall: number}>}
 */
export const funnelReadiness = writable({
    signup: 0,
    purchase: 0,
    subscription: 0,
    overall: 0,
});

/**
 * Recommended events for instrumentation guidance.
 * @type {import('svelte/store').Writable<Array<{name: string, category: string|null, priority: string}>>}
 */
export const recommendedEvents = writable([]);

/**
 * Whether the SaaS metrics composable has been initialized.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const initialized = writable(false);

/**
 * Raw zbAnalytics props reference.
 * @type {import('svelte/store').Writable<object>}
 */
export const analyticsProps = writable({});

// ─── Derived Stores ───────────────────────────────────────────────

/**
 * Whether analytics is enabled on the current page.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const isEnabled = derived(analyticsProps, ($props) => $props.enabled === true);

/**
 * Whether the package is in debug mode.
 * @type {import('svelte/store').Readable<boolean>}
 */
export const isDebugMode = derived(analyticsProps, ($props) => $props.debug === true);

/**
 * Number of untracked event gaps.
 * @type {import('svelte/store').Readable<number>}
 */
export const gapCount = derived(onboarding, ($onboarding) => $onboarding.gaps?.length || 0);

/**
 * Overall funnel readiness as a percentage label.
 * @type {import('svelte/store').Readable<string>}
 */
export const funnelReadinessLabel = derived(funnelReadiness, ($fr) => {
    const overall = Math.round(($fr.overall || 0) * 100);
    return `${overall}%`;
});

/**
 * Maturity grade badge color class (for Tailwind CSS).
 * @type {import('svelte/store').Readable<string>}
 */
export const maturityBadgeColor = derived(maturityScore, ($ms) => {
    const { grade } = $ms;
    if (grade === 'A' || grade === 'A+') return 'text-green-600';
    if (grade === 'B' || grade === 'B+') return 'text-blue-600';
    if (grade === 'C' || grade === 'C+') return 'text-yellow-600';
    return 'text-red-600';
});

/**
 * Priority-sorted recommended events.
 * @type {import('svelte/store').Readable<Array<{name: string, category: string|null, priority: string}>>}
 */
export const sortedRecommendedEvents = derived(recommendedEvents, ($events) => {
    return [...$events].sort((a, b) => {
        const priorityOrder = { critical: 0, high: 1, medium: 2, low: 3 };
        const aVal = priorityOrder[a.priority] ?? 4;
        const bVal = priorityOrder[b.priority] ?? 4;
        return aVal - bVal;
    });
});

/**
 * Onboarding completion as percentage string.
 * @type {import('svelte/store').Readable<string>}
 */
export const onboardingPercentageLabel = derived(onboarding, ($ob) => {
    return `${Math.round(($ob.completion || 0) * 100)}%`;
});

/**
 * Provider configuration summary.
 * @type {import('svelte/store').Readable<{ga4: boolean, gtm: boolean, meta: boolean, plausible: boolean, posthog: boolean}>}
 */
export const providerStatus = derived(analyticsProps, ($props) => ({
    ga4: !!$props.ga4MeasurementId,
    gtm: !!$props.gtmContainerId,
    meta: !!$props.metaPixelId,
    plausible: !!$props.plausibleDomain,
    posthog: !!$props.posthogHost,
}));

/**
 * Count of configured providers.
 * @type {import('svelte/store').Readable<number>}
 */
export const configuredProviderCount = derived(providerStatus, ($providers) => {
    return Object.values($providers).filter(Boolean).length;
});

/**
 * Feature flags that are enabled (from zbAnalytics.featureFlags if available).
 * @type {import('svelte/store').Readable<Array<string>>}
 */
export const enabledFeatures = derived(analyticsProps, ($props) => {
    return $props.featureFlags?.enabled || [];
});

// ─── Composable Function ─────────────────────────────────────────

/**
 * Initialize SaaS metrics stores from Inertia page props.
 *
 * Reads maturity, onboarding, funnel readiness, and recommended events
 * from zbAnalytics props and populates reactive stores.
 *
 * @param {object} [pageProps] - Inertia page props (defaults to current page)
 * @returns {{
 *   maturityScore, onboarding, funnelReadiness, recommendedEvents,
 *   initialized, analyticsProps, isEnabled, isDebugMode, gapCount,
 *   funnelReadinessLabel, maturityBadgeColor, sortedRecommendedEvents,
 *   onboardingPercentageLabel, providerStatus, configuredProviderCount,
 *   enabledFeatures, refresh
 * }}
 */
export function useSaaSMetrics(pageProps) {
    const props = pageProps || (typeof page !== 'undefined' ? page : null);
    const zbProps = props?.props?.zbAnalytics || props?.zbAnalytics || {};

    // Store raw props for derived computation
    analyticsProps.set(zbProps);

    // Maturity score
    if (zbProps.maturity) {
        maturityScore.set({
            score: zbProps.maturity.score || 0,
            grade: zbProps.maturity.grade || 'N/A',
        });
    }

    // Onboarding checklist
    if (zbProps.onboarding) {
        onboarding.set({
            completion: zbProps.onboarding.completion || 0,
            gaps: zbProps.onboarding.gaps || [],
        });
    }

    // Funnel readiness
    if (zbProps.funnelReadiness) {
        funnelReadiness.set({
            signup: zbProps.funnelReadiness.signup || 0,
            purchase: zbProps.funnelReadiness.purchase || 0,
            subscription: zbProps.funnelReadiness.subscription || 0,
            overall: zbProps.funnelReadiness.overall || 0,
        });
    }

    // Recommended events
    if (zbProps.recommendedEvents) {
        recommendedEvents.set(
            zbProps.recommendedEvents.map((e) => ({
                name: e.name,
                category: e.category || null,
                priority: e.priority || 'medium',
            }))
        );
    }

    initialized.set(true);

    /**
     * Refresh stores from current page props.
     * Useful after Inertia navigation when zbAnalytics may have changed.
     */
    function refresh() {
        const currentPage = typeof page !== 'undefined' ? get(page) : null;
        if (currentPage) {
            useSaaSMetrics(currentPage);
        }
    }

    return {
        // Stores
        maturityScore,
        onboarding,
        funnelReadiness,
        recommendedEvents,
        initialized,
        analyticsProps,

        // Derived
        isEnabled,
        isDebugMode,
        gapCount,
        funnelReadinessLabel,
        maturityBadgeColor,
        sortedRecommendedEvents,
        onboardingPercentageLabel,
        providerStatus,
        configuredProviderCount,
        enabledFeatures,

        // Methods
        refresh,
    };
}

/**
 * Get the current page props for use inside the composable.
 * @returns {object|null}
 */
function get(store) {
    let value;
    store.subscribe((v) => (value = v))();
    return value;
}

export default useSaaSMetrics;
