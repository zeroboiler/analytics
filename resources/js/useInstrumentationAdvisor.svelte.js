/**
 * ZeroBoiler Analytics — Svelte Composable: useInstrumentationAdvisor
 *
 * Reactive instrumentation advisor for Svelte/Inertia applications.
 * Reads zbAnalytics page props (recommendedEvents, onboarding, maturity,
 * funnelReadiness) and provides actionable guidance for developers to
 * close analytics instrumentation gaps.
 *
 * This composable bridges the server-side SaaS Starter Instrumentation Service
 * with the client-side developer experience, enabling real-time guidance
 * directly in the app UI.
 *
 * Usage:
 *   import { useInstrumentationAdvisor } from './useInstrumentationAdvisor.svelte.js';
 *   const advisor = useInstrumentationAdvisor();
 *   console.log($advisor.gaps);          // untracked event names
 *   console.log($advisor.maturityGrade); // 'A+' → 'D'
 *   console.log($advisor.suggestions);   // actionable items
 *
 * @package ZeroBoiler Analytics
 * @version 266.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';

// ─── Grade Thresholds ──────────────────────────────────────────────────

/**
 * Score → grade mapping thresholds.
 * Matches server-side EventPriorityCalculator::maturityScore() grades.
 *
 * @type {Array<{min: number, max: number, grade: string, label: string, color: string}>}
 */
const GRADE_THRESHOLDS = [
    { min: 95, max: 100, grade: 'A+', label: 'Industry Leading', color: '#10b981' },
    { min: 85, max: 94.9, grade: 'A', label: 'Excellent', color: '#22c55e' },
    { min: 75, max: 84.9, grade: 'B+', label: 'Very Good', color: '#84cc16' },
    { min: 65, max: 74.9, grade: 'B', label: 'Good', color: '#eab308' },
    { min: 55, max: 64.9, grade: 'C+', label: 'Adequate', color: '#f59e0b' },
    { min: 45, max: 54.9, grade: 'C', label: 'Needs Improvement', color: '#f97316' },
    { min: 35, max: 44.9, grade: 'D', label: 'Below Average', color: '#ef4444' },
    { min: 0, max: 34.9, grade: 'F', label: 'Critical Gaps', color: '#dc2626' },
];

/**
 * Priority-ordered SaaS starter event groups for instrumentation guidance.
 * Events are grouped by impact tier and ordered within each tier.
 *
 * @type {Array<{tier: string, events: Array<{name: string, label: string, impact: string}>}>}
 */
const INSTRUMENTATION_TIERS = [
    {
        tier: 'Identity (Must Track)',
        events: [
            { name: 'sign_up', label: 'Sign Up', impact: 'critical' },
            { name: 'login', label: 'Login', impact: 'critical' },
        ],
    },
    {
        tier: 'Activation',
        events: [
            { name: 'start_trial', label: 'Trial Start', impact: 'high' },
            { name: 'feature_used', label: 'Feature Used', impact: 'high' },
            { name: 'page_view', label: 'Page View', impact: 'medium' },
        ],
    },
    {
        tier: 'Revenue',
        events: [
            { name: 'subscribe', label: 'Subscription', impact: 'critical' },
            { name: 'plan_upgrade', label: 'Plan Upgrade', impact: 'high' },
            { name: 'purchase', label: 'Purchase', impact: 'high' },
            { name: 'view_item', label: 'View Item', impact: 'medium' },
            { name: 'add_to_cart', label: 'Add to Cart', impact: 'medium' },
            { name: 'cancellation', label: 'Cancellation', impact: 'high' },
            { name: 'refund', label: 'Refund', impact: 'medium' },
            { name: 'trial_converted', label: 'Trial Converted', impact: 'high' },
        ],
    },
    {
        tier: 'Engagement & Retention',
        events: [
            { name: 'form_start', label: 'Form Start', impact: 'medium' },
            { name: 'form_submit', label: 'Form Submit', impact: 'medium' },
            { name: 'click', label: 'Click', impact: 'low' },
            { name: 'search', label: 'Search', impact: 'medium' },
            { name: 'scroll_depth', label: 'Scroll Depth', impact: 'low' },
            { name: 'share', label: 'Share', impact: 'low' },
            { name: 'error', label: 'Error', impact: 'medium' },
        ],
    },
];

// ─── Store State ──────────────────────────────────────────────────────

/**
 * Raw zbAnalytics props from the Inertia page.
 * @type {import('svelte/store').Writable<object|null>}
 */
export const analyticsProps = writable(null);

/**
 * Current maturity score (0-100).
 * @type {import('svelte/store').Writable<number>}
 */
export const maturityScore = writable(0);

/**
 * Current maturity grade (A+ through F).
 * @type {import('svelte/store').Writable<string>}
 */
export const maturityGrade = writable('N/A');

/**
 * Maturity label (e.g. 'Industry Leading', 'Needs Improvement').
 * @type {import('svelte/store').Writable<string>}
 */
export const maturityLabel = writable('Unknown');

/**
 * Maturity grade color (hex string for UI rendering).
 * @type {import('svelte/store').Writable<string>}
 */
export const maturityColor = writable('#6b7280');

/**
 * List of untracked event names (instrumentation gaps).
 * @type {import('svelte/store').Writable<string[]>}
 */
export const gaps = writable([]);

/**
 * Onboarding completion percentage (0.0 to 1.0).
 * @type {import('svelte/store').Writable<number>}
 */
export const onboardingCompletion = writable(0.0);

/**
 * Recommended next events to instrument (from server).
 * @type {import('svelte/store').Writable<Array<{name: string, category: string|null, priority: string|null}>>}
 */
export const recommendedEvents = writable([]);

/**
 * Funnel readiness scores.
 * @type {import('svelte/store').Writable<{signup: number, purchase: number, subscription: number, overall: number}>}
 */
export const funnelReadiness = writable({
    signup: 0.0,
    purchase: 0.0,
    subscription: 0.0,
    overall: 0.0,
});

// ─── Derived Stores ───────────────────────────────────────────────────

/**
 * Priority-ordered instrumentation suggestions.
 * Combines server-recommended events with client-side tier definitions
 * to produce actionable suggestions with implementation hints.
 *
 * @type {import('svelte/store').Derived<unknown[], Array<{
 *   name: string,
 *   label: string,
 *   tier: string,
 *   impact: string,
 *   isGap: boolean,
 *   category: string|null,
 *   priority: string|null,
 *   hint: string,
 * }>>}
 */
export const suggestions = derived(
    [gaps, recommendedEvents],
    ([$gaps, $recommendedEvents]) => {
        const gapSet = new Set($gaps);
        const suggestionsList = [];

        for (const tierGroup of INSTRUMENTATION_TIERS) {
            for (const event of tierGroup.events) {
                const isGap = gapSet.has(event.name);
                const serverRec = $recommendedEvents.find(
                    (rec) => rec.name === event.name,
                );

                suggestionsList.push({
                    name: event.name,
                    label: event.label,
                    tier: tierGroup.tier,
                    impact: event.impact,
                    isGap,
                    category: serverRec?.category ?? null,
                    priority: serverRec?.priority ?? null,
                    hint: getHintForEvent(event.name, isGap),
                });
            }
        }

        // Sort: gaps first, then by impact priority
        const impactOrder = { critical: 0, high: 1, medium: 2, low: 3 };
        suggestionsList.sort((a, b) => {
            // Gaps come first
            if (a.isGap && !b.isGap) return -1;
            if (!a.isGap && b.isGap) return 1;
            // Then by impact
            return (impactOrder[a.impact] ?? 4) - (impactOrder[b.impact] ?? 4);
        });

        return suggestionsList;
    },
);

/**
 * Count of critical + high impact gaps.
 *
 * @type {import('svelte/store').Derived<unknown[], number>}
 */
export const criticalGapCount = derived(suggestions, ($suggestions) => {
    return $suggestions.filter(
        (s) => s.isGap && (s.impact === 'critical' || s.impact === 'high'),
    ).length;
});

/**
 * Whether the analytics instrumentation is production-ready
 * (score >= 75, no critical gaps).
 *
 * @type {import('svelte/store').Derived<unknown[], boolean>}
 */
export const isProductionReady = derived(
    [maturityScore, criticalGapCount],
    ([$maturityScore, $criticalGapCount]) => {
        return $maturityScore >= 75 && $criticalGapCount === 0;
    },
);

/**
* Quick summary for dashboard display.
*
* @type {import('svelte/store').Derived<unknown[], object>}
*/
export const summary = derived(
    [maturityScore, maturityGrade, maturityLabel, maturityColor, gaps, onboardingCompletion, criticalGapCount, isProductionReady, funnelReadiness],
    ([$maturityScore, $maturityGrade, $maturityLabel, $maturityColor, $gaps, $onboardingCompletion, $criticalGapCount, $isProductionReady, $funnelReadiness]) => ({
        score: $maturityScore,
        grade: $maturityGrade,
        label: $maturityLabel,
        color: $maturityColor,
        gapCount: $gaps.length,
        criticalGaps: $criticalGapCount,
        onboardingPct: Math.round($onboardingCompletion * 100),
        isProductionReady: $isProductionReady,
        funnel: $funnelReadiness,
    }),
);

// ─── Internal State ───────────────────────────────────────────────────

/** @type {Function|null} Page unsubscribe */
let pageUnsubscribe = null;

/** @type {boolean} Whether the composable has been initialized */
let initialized = false;

// ─── Hint Generator ───────────────────────────────────────────────────

/**
 * Instrumentation hints for each SaaS starter event.
 * Provides copy-paste ready guidance for developers.
 *
 * @type {Record<string, Record<string, string>>}
 */
const EVENT_HINTS = {
    sign_up: {
        gap: 'Fire on Illuminate\Auth\Events\Registered — SaaSEventHelpers::signUp($method)',
        done: '✅ Sign-up tracking is active',
    },
    login: {
        gap: 'Auto-tracked via auth.login lifecycle mapping — or call SaaSEventHelpers::login($method)',
        done: '✅ Login tracking is active',
    },
    start_trial: {
        gap: 'Fire when user activates trial: SaaSEventHelpers::trialStart($plan, $days)',
        done: '✅ Trial start tracking is active',
    },
    subscribe: {
        gap: 'Fire on subscription.created: SaaSEventHelpers::subscription($plan, $mrr)',
        done: '✅ Subscription tracking is active',
    },
    plan_upgrade: {
        gap: 'Fire on plan change: SaaSEventHelpers::planUpgrade($from, $to, $delta)',
        done: '✅ Plan upgrade tracking is active',
    },
    cancellation: {
        gap: 'Fire on subscription.cancelled: SaaSEventHelpers::cancellation($plan, $reason)',
        done: '✅ Cancellation tracking is active',
    },
    feature_used: {
        gap: 'Fire on key feature interactions: SaaSEventHelpers::featureUsed($name)',
        done: '✅ Feature usage tracking is active',
    },
    page_view: {
        gap: 'Enable via config: client_auto_track.page_views = true, or use usePageView() composable',
        done: '✅ Page view auto-tracking is active',
    },
    scroll_depth: {
        gap: 'Enable via config: client_auto_track.scroll_depth = true, or call initScrollDepth()',
        done: '✅ Scroll depth auto-tracking is active',
    },
    click: {
        gap: 'Fire on CTA/nav clicks: trackEvent("click", { target, element_id })',
        done: '✅ Click tracking is active',
    },
    form_start: {
        gap: 'Enable via config: client_auto_track.form_tracking = true, or use initFormTracking()',
        done: '✅ Form tracking is active',
    },
    form_submit: {
        gap: 'Enable via config: client_auto_track.form_tracking = true, or use initFormTracking()',
        done: '✅ Form tracking is active',
    },
    search: {
        gap: 'Fire on search: trackSearch(query, { category, result_count })',
        done: '✅ Search tracking is active',
    },
    share: {
        gap: 'Fire on share: trackShare(method, contentType, contentId)',
        done: '✅ Share tracking is active',
    },
    error: {
        gap: 'Enable via config: client_auto_track.error_tracking = true, or call initErrorTracking()',
        done: '✅ Error auto-tracking is active',
    },
    view_item: {
        gap: 'Fire on product/pricing page: trackViewItem({ item_id, item_name, price })',
        done: '✅ View item tracking is active',
    },
    add_to_cart: {
        gap: 'Fire on plan/item selection: trackAddToCart({ item_id, item_name, price })',
        done: '✅ Add to cart tracking is active',
    },
    purchase: {
        gap: 'Fire on payment success: trackPurchase({ transaction_id, value, currency, items })',
        done: '✅ Purchase tracking is active',
    },
    refund: {
        gap: 'Fire on refund: trackRefund({ transaction_id, value, currency })',
        done: '✅ Refund tracking is active',
    },
    trial_converted: {
        gap: 'Fire when trial user subscribes: trackTrialConversion({ trial_plan, new_plan })',
        done: '✅ Trial conversion tracking is active',
    },
};

/**
 * Get the instrumentation hint for an event.
 *
 * @param {string} eventName - Event name
 * @param {boolean} isGap - Whether this event is an instrumentation gap
 * @returns {string} Human-readable hint
 */
function getHintForEvent(eventName, isGap) {
    const hints = EVENT_HINTS[eventName];

    if (!hints) {
        return isGap
            ? `Track "${eventName}" via trackEvent("${eventName}", params)`
            : `✅ "${eventName}" tracking is active`;
    }

    return isGap ? hints.gap : hints.done;
}

// ─── Composable ───────────────────────────────────────────────────────

/**
 * Reactive instrumentation advisor composable.
 *
 * Watches Inertia page props for zbAnalytics and extracts
 * instrumentation guidance (maturity score, gaps, suggestions, funnel readiness).
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoWatch=true] - Automatically watch Inertia page
 * @param {number} [options.refreshIntervalMs=0] - Polling interval (0 = event-driven only)
 * @returns {{
 *   analyticsProps: import('svelte/store').Writable<object|null>,
 *   maturityScore: import('svelte/store').Writable<number>,
 *   maturityGrade: import('svelte/store').Writable<string>,
 *   maturityLabel: import('svelte/store').Writable<string>,
 *   maturityColor: import('svelte/store').Writable<string>,
 *   gaps: import('svelte/store').Writable<string[]>,
 *   onboardingCompletion: import('svelte/store').Writable<number>,
 *   recommendedEvents: import('svelte/store').Writable<Array>,
 *   funnelReadiness: import('svelte/store').Writable<object>,
 *   suggestions: import('svelte/store').Readable<Array>,
 *   criticalGapCount: import('svelte/store').Readable<number>,
 *   isProductionReady: import('svelte/store').Readable<boolean>,
 *   summary: import('svelte/store').Readable<object>,
 *   refresh: () => void,
 *   getGrade: (score: number) => {grade: string, label: string, color: string},
 *   getTierSummary: () => Array<{tier: string, total: number, tracked: number, gaps: string[]}>,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useInstrumentationAdvisor } from '@zeroboiler/analytics/svelte';
 *
 * const { maturityGrade, summary, suggestions, criticalGapCount } = useInstrumentationAdvisor();
 * </script>
 *
 * <div class="p-4">
 *   <h2>Analytics: {$maturityGrade} ({$summary.score}/100)</h2>
 *   {#if $criticalGapCount > 0}
 *     <p class="text-red-500">{$criticalGapCount} critical gaps remaining</p>
 *   {/if}
 *   <ul>
 *     {#each $suggestions as s}
 *       <li class:bgbg-red-50={s.isGap}>
 *         {s.label}: {s.hint}
 *       </li>
 *     {/each}
 *   </ul>
 * </div>
 * ```
 */
export function useInstrumentationAdvisor(options = {}) {
    const autoWatch = options.autoWatch !== false;
    const refreshIntervalMs = options.refreshIntervalMs || 0;

    /** @type {number|null} Refresh timer */
    let refreshTimer = null;

    /**
     * Compute the grade for a given score.
     *
     * @param {number} score - Maturity score (0-100)
     * @returns {{grade: string, label: string, color: string}}
     */
    function getGrade(score) {
        for (const threshold of GRADE_THRESHOLDS) {
            if (score >= threshold.min && score <= threshold.max) {
                return {
                    grade: threshold.grade,
                    label: threshold.label,
                    color: threshold.color,
                };
            }
        }

        return { grade: 'N/A', label: 'Unknown', color: '#6b7280' };
    }

    /**
     * Extract and update all stores from Inertia page props.
     */
    function refresh() {
        let props = null;

        // Read current Inertia page props
        if (typeof page !== 'undefined') {
            page.subscribe((p) => { props = p; })();
        }

        const zb = props?.props?.zbAnalytics;

        if (!zb) {
            analyticsProps.set(null);
            return;
        }

        analyticsProps.set(zb);

        // Maturity
        const score = zb.maturity?.score ?? 0;
        const gradeInfo = getGrade(score);

        maturityScore.set(score);
        maturityGrade.set(zb.maturity?.grade ?? gradeInfo.grade);
        maturityLabel.set(gradeInfo.label);
        maturityColor.set(gradeInfo.color);

        // Onboarding gaps
        const onboarding = zb.onboarding ?? {};
        gaps.set(onboarding.gaps ?? []);
        onboardingCompletion.set(onboarding.completion ?? 0.0);

        // Recommended events
        recommendedEvents.set(zb.recommendedEvents ?? []);

        // Funnel readiness
        const fr = zb.funnelReadiness ?? {};
        funnelReadiness.set({
            signup: fr.signup ?? 0.0,
            purchase: fr.purchase ?? 0.0,
            subscription: fr.subscription ?? 0.0,
            overall: fr.overall ?? 0.0,
        });
    }

    /**
     * Get a tier-by-tier summary of instrumentation status.
     *
     * @returns {Array<{tier: string, total: number, tracked: number, gaps: string[]}>}
     */
    function getTierSummary() {
        let currentGaps = [];
        gaps.subscribe((g) => { currentGaps = g; })();

        const gapSet = new Set(currentGaps);

        return INSTRUMENTATION_TIERS.map((tierGroup) => {
            const eventNames = tierGroup.events.map((e) => e.name);
            const tierGaps = eventNames.filter((name) => gapSet.has(name));

            return {
                tier: tierGroup.tier,
                total: tierGroup.events.length,
                tracked: tierGroup.events.length - tierGaps.length,
                gaps: tierGaps,
            };
        });
    }

    // ─── Auto-watch Inertia ──────────────────────────────────────

    if (autoWatch && typeof page !== 'undefined' && !initialized) {
        initialized = true;

        // Initial refresh
        refresh();

        // Watch for navigation
        if (!pageUnsubscribe) {
            pageUnsubscribe = page.subscribe(() => {
                refresh();
            });
        }

        // Optional polling refresh
        if (refreshIntervalMs > 0 && !refreshTimer) {
            refreshTimer = setInterval(refresh, refreshIntervalMs);
        }
    }

    return {
        // Stores
        analyticsProps,
        maturityScore,
        maturityGrade,
        maturityLabel,
        maturityColor,
        gaps,
        onboardingCompletion,
        recommendedEvents,
        funnelReadiness,

        // Derived
        suggestions,
        criticalGapCount,
        isProductionReady,
        summary,

        // Methods
        refresh,
        getGrade,
        getTierSummary,
    };
}

/**
 * Get a static snapshot of the instrumentation advisor state.
 * Useful for SSR, testing, or non-reactive contexts.
 *
 * @returns {{
 *   score: number,
 *   grade: string,
 *   label: string,
 *   color: string,
 *   gapCount: number,
 *   criticalGaps: number,
 *   onboardingPct: number,
 *   isProductionReady: boolean,
 *   tierSummary: Array<{tier: string, total: number, tracked: number, gaps: string[]}>,
 * }}
 */
export function getAdvisorSnapshot() {
    let currentScore = 0;
    let currentGrade = 'N/A';
    let currentLabel = 'Unknown';
    let currentColor = '#6b7280';
    let currentGaps = [];
    let currentOnboarding = 0.0;
    let currentCriticalGaps = 0;
    let currentReady = false;

    const unsub1 = maturityScore.subscribe((s) => { currentScore = s; });
    const unsub2 = maturityGrade.subscribe((g) => { currentGrade = g; });
    const unsub3 = maturityLabel.subscribe((l) => { currentLabel = l; });
    const unsub4 = maturityColor.subscribe((c) => { currentColor = c; });
    const unsub5 = gaps.subscribe((g) => { currentGaps = g; });
    const unsub6 = onboardingCompletion.subscribe((o) => { currentOnboarding = o; });
    const unsub7 = criticalGapCount.subscribe((c) => { currentCriticalGaps = c; });
    const unsub8 = isProductionReady.subscribe((r) => { currentReady = r; });

    unsub1();
    unsub2();
    unsub3();
    unsub4();
    unsub5();
    unsub6();
    unsub7();
    unsub8();

    // Build tier summary
    const gapSet = new Set(currentGaps);
    const tierSummary = INSTRUMENTATION_TIERS.map((tierGroup) => {
        const eventNames = tierGroup.events.map((e) => e.name);
        const tierGaps = eventNames.filter((name) => gapSet.has(name));

        return {
            tier: tierGroup.tier,
            total: tierGroup.events.length,
            tracked: tierGroup.events.length - tierGaps.length,
            gaps: tierGaps,
        };
    });

    return {
        score: currentScore,
        grade: currentGrade,
        label: currentLabel,
        color: currentColor,
        gapCount: currentGaps.length,
        criticalGaps: currentCriticalGaps,
        onboardingPct: Math.round(currentOnboarding * 100),
        isProductionReady: currentReady,
        tierSummary,
    };
}

export default useInstrumentationAdvisor;
