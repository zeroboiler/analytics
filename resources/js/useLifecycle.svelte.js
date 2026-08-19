/**
 * ZeroBoiler Analytics — Svelte Composable: useLifecycle
 *
 * Reactive Svelte composable for SaaS lifecycle analytics.
 * Provides real-time activation scores, churn risk, funnel progress,
 * and feature adoption depth from server-side lifecycle signals.
 *
 * Reads lifecycle data from Inertia page props (zbAnalytics.lifecycle)
 * or fetches from the analytics API endpoint.
 *
 * @package ZeroBoiler Analytics
 * @version 259.0.0
 */

import { writable, derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Activation score (0-100) with grade.
 * @type {import('svelte/store').Writable<{score: number, grade: string, steps: string[], signals: string[]}>}
 */
export const activationScore = writable({
    score: 0,
    grade: 'N/A',
    steps: [],
    signals: [],
});

/**
 * Churn risk assessment (0-100, higher = more risk).
 * @type {import('svelte/store').Writable<{riskScore: number, riskLevel: string, indicators: string[], recommendation: string}>}
 */
export const churnRisk = writable({
    riskScore: 0,
    riskLevel: 'low',
    indicators: [],
    recommendation: '',
});

/**
 * Funnel progress through SaaS signup/activation funnel.
 * @type {import('svelte/store').Writable<{steps: string[], completionPct: number, currentStep: string|null, stepsRemaining: number}>}
 */
export const funnelProgress = writable({
    steps: [],
    completionPct: 0,
    currentStep: null,
    stepsRemaining: 0,
});

/**
 * Feature adoption depth tracking.
 * @type {import('svelte/store').Writable<{featuresUsed: string[], adoptionCount: number, adoptionDepth: number}>}
 */
export const featureAdoption = writable({
    featuresUsed: [],
    adoptionCount: 0,
    adoptionDepth: 0,
});

/**
 * Session engagement metrics.
 * @type {import('svelte/store').Writable<{sessionCount: number, avgSessionsPerDay: number, lastLoginAt: number|null}>}
 */
export const sessionEngagement = writable({
    sessionCount: 0,
    avgSessionsPerDay: 0,
    lastLoginAt: null,
});

/**
 * Expansion momentum score (0-100).
 * @type {import('svelte/store').Writable<{momentum: number, eventCount: number, totalValue: number}>}
 */
export const expansionMomentum = writable({
    momentum: 0,
    eventCount: 0,
    totalValue: 0,
});

/**
 * Whether lifecycle data is loaded.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const lifecycleLoaded = writable(false);

// ─── Constants ─────────────────────────────────────────────────────

/** SaaS signup funnel steps in order */
const SAAS_FUNNEL_STEPS = [
    'sign_up',
    'email_verified',
    'first_login',
    'trial_start',
    'first_feature',
    'team_created',
    'integration_connected',
    'subscription',
    'plan_upgrade',
    'activated',
];

/** Activation score weight table */
const ACTIVATION_WEIGHTS = {
    trial_start: 0,
    login: 15,
    feature_used: 20,
    form_submit: 25,
    add_to_cart: 30,
    subscription: 80,
    plan_upgrade: 90,
    trial_converted: 100,
};

/** Churn risk indicator weights */
const CHURN_WEIGHTS = {
    support_ticket: 25,
    feature_limit_reached: 20,
    billing_retry: 35,
    downgrade_visit: 15,
    reduced_usage: 30,
    error: 10,
};

// ─── Helper Functions ────────────────────────────────────────────────

/**
 * Convert a numeric score to a letter grade.
 * @param {number} score - 0-100 score
 * @returns {string} Letter grade (A, B, C, D, F)
 */
function scoreToGrade(score) {
    if (score >= 90) return 'A';
    if (score >= 75) return 'B';
    if (score >= 50) return 'C';
    if (score >= 25) return 'D';
    return 'F';
}

/**
 * Determine churn risk level from score.
 * @param {number} riskScore - 0-100 risk score
 * @returns {string} Risk level label
 */
function riskLevel(riskScore) {
    if (riskScore >= 75) return 'critical';
    if (riskScore >= 50) return 'high';
    if (riskScore >= 25) return 'medium';
    return 'low';
}

/**
 * Generate churn risk recommendation based on score.
 * @param {number} riskScore - 0-100 risk score
 * @returns {string} Human-readable recommendation
 */
function churnRecommendation(riskScore) {
    if (riskScore >= 75) return 'Immediate intervention recommended. Trigger re-engagement campaign.';
    if (riskScore >= 50) return 'High churn risk. Consider reaching out with personalized support.';
    if (riskScore >= 25) return 'Moderate risk. Monitor closely and proactively engage.';
    return 'Low risk. Continue normal engagement patterns.';
}

/**
 * Interpret activation signals for user feedback.
 * @param {number} score - Activation score
 * @param {string[]} completedSteps - Completed activation steps
 * @returns {string[]} Human-readable signal descriptions
 */
function interpretSignals(score, completedSteps) {
    const signals = [];
    if (score >= 80) {
        signals.push('Strong activation — user is fully engaged with core features');
    } else if (score >= 50) {
        signals.push('Moderate activation — user has engaged with several features');
    } else if (completedSteps.length > 0) {
        signals.push('Early activation — user has started the activation journey');
    } else {
        signals.push('No activation signals detected yet');
    }

    if (completedSteps.includes('trial_converted')) {
        signals.push('Trial converted successfully');
    } else if (completedSteps.includes('subscription')) {
        signals.push('Subscribed without trial conversion — consider trial offering');
    }

    if (!completedSteps.includes('first_feature')) {
        signals.push('First feature engagement not detected — consider onboarding guidance');
    }

    return signals;
}

// ─── Composable ─────────────────────────────────────────────────────

/**
 * SaaS lifecycle analytics composable.
 *
 * Provides reactive stores for activation, churn risk, funnel progress,
 * feature adoption, and session engagement metrics.
 *
 * Data is sourced from:
 * 1. Inertia page props (zbAnalytics.lifecycle) — updated on navigation
 * 2. API fetch (GET /api/analytics/lifecycle/{identity}) — for real-time data
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoFetch=false] - Auto-fetch lifecycle data from API
 * @param {boolean} [options.reactiveToPageProps=true] - Update stores on Inertia navigation
 * @param {string|null} [options.userId=null] - Override user ID for API fetch
 * @param {number} [options.refreshIntervalMs=0] - Auto-refresh interval (0 = disabled)
 * @returns {{
 *   activationScore: import('svelte/store').Writable,
 *   churnRisk: import('svelte/store').Writable,
 *   funnelProgress: import('svelte/store').Writable,
 *   featureAdoption: import('svelte/store').Writable,
 *   sessionEngagement: import('svelte/store').Writable,
 *   expansionMomentum: import('svelte/store').Writable,
 *   lifecycleLoaded: import('svelte/store').Writable,
 *   fetch: (identity?: string|null) => Promise<void>,
 *   refresh: () => Promise<void>,
 *   activationGrade: import('svelte/store').Readable<string>,
 *   churnLevel: import('svelte/store').Readable<string>,
 *   funnelCompletion: import('svelte/store').Readable<number>,
 *   funnelStepNames: string[],
 *   isActive: (threshold?: number) => boolean,
 *   isAtRisk: (threshold?: number) => boolean,
 *   stopAutoRefresh: () => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useLifecycle } from '@zeroboiler/analytics';
 *
 * const {
 *     activationScore,
 *     churnRisk,
 *     funnelProgress,
 *     fetch: fetchLifecycle,
 *     activationGrade,
 *     churnLevel,
 * } = useLifecycle({ autoFetch: true });
 * </script>
 *
 * <div class="lifecycle-dashboard">
 *   <div class="activation">
 *     <h2>Activation: {$activationGrade}</h2>
 *     <p>{$activationScore.score}/100</p>
 *     {#each $activationScore.steps as step}
 *       <span class="step-badge">{step}</span>
 *     {/each}
 *   </div>
 *
 *   <div class="churn-risk" class:high-risk={$churnRisk.riskScore >= 50}>
 *     <h2>Churn Risk: {$churnLevel}</h2>
 *     <p>{$churnRisk.recommendation}</p>
 *   </div>
 *
 *   <div class="funnel">
 *     <h2>Funnel: {$funnelProgress.completionPct}%</h2>
 *     <progress value={$funnelProgress.completionPct} max="100" />
 *   </div>
 * </div>
 * ```
 */
export function useLifecycle(options = {}) {
    const autoFetch = options.autoFetch ?? false;
    const reactiveToPageProps = options.reactiveToPageProps !== false;
    let refreshTimer = null;

    // ── Derived Stores ──────────────────────────────────────────

    /** Activation letter grade (reactive). */
    const activationGrade = derived(activationScore, ($activation) => {
        return $activation.grade;
    });

    /** Churn risk level label (reactive). */
    const churnLevel = derived(churnRisk, ($churn) => {
        return $churn.riskLevel;
    });

    /** Funnel completion percentage (reactive). */
    const funnelCompletion = derived(funnelProgress, ($funnel) => {
        return $funnel.completionPct;
    });

    // ── API Fetch ──────────────────────────────────────────────

    /**
     * Fetch lifecycle data from the analytics API.
     *
     * Calls GET /api/analytics/lifecycle?identity={identity}
     * and updates all reactive stores with the response data.
     *
     * @param {string|null} identity - User ID or client ID (defaults to current user)
     */
    async function fetchLifecycle(identity) {
        try {
            const analytics = page.props?.zbAnalytics;
            const apiBase = analytics?.apiBase ?? '/api/analytics';
            const uid = identity || analytics?.userId || null;

            const url = uid
                ? `${apiBase}/lifecycle?identity=${encodeURIComponent(uid)}`
                : `${apiBase}/lifecycle`;

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) return;

            const data = await response.json();
            updateStoresFromData(data);
            lifecycleLoaded.set(true);
        } catch {
            // Silent — lifecycle fetch should never break the app
        }
    }

    /**
     * Refresh lifecycle data for the current user.
     */
    async function refresh() {
        const analytics = page.props?.zbAnalytics;
        await fetchLifecycle(analytics?.userId ?? null);
    }

    /**
     * Update reactive stores from API response data.
     * @param {object} data - Lifecycle API response
     */
    function updateStoresFromData(data) {
        // Activation score
        if (data.activation_score !== undefined) {
            activationScore.update((prev) => ({
                score: Math.min(100, data.activation_score || 0),
                grade: scoreToGrade(data.activation_score || 0),
                steps: data.activation_steps || prev.steps,
                signals: interpretSignals(data.activation_score || 0, data.activation_steps || []),
            }));
        }

        // Churn risk
        if (data.churn_risk_score !== undefined) {
            churnRisk.update((prev) => ({
                riskScore: Math.min(100, data.churn_risk_score || 0),
                riskLevel: riskLevel(data.churn_risk_score || 0),
                indicators: data.churn_indicators || prev.indicators,
                recommendation: churnRecommendation(data.churn_risk_score || 0),
            }));
        }

        // Funnel progress
        if (data.funnel_progress !== undefined) {
            const steps = data.funnel_progress || [];
            funnelProgress.set({
                steps,
                completionPct: Math.round((steps.length / SAAS_FUNNEL_STEPS.length) * 100),
                currentStep: data.funnel_current_step || null,
                stepsRemaining: SAAS_FUNNEL_STEPS.length - steps.length,
            });
        }

        // Feature adoption
        if (data.features_used !== undefined) {
            featureAdoption.set({
                featuresUsed: data.features_used || [],
                adoptionCount: data.feature_adoption_count || 0,
                adoptionDepth: data.feature_adoption_depth || (data.features_used || []).length,
            });
        }

        // Session engagement
        if (data.session_count !== undefined) {
            sessionEngagement.set({
                sessionCount: data.session_count || 0,
                avgSessionsPerDay: data.avg_sessions_per_day || 0,
                lastLoginAt: data.last_login_at || null,
            });
        }

        // Expansion momentum
        if (data.expansion_momentum !== undefined) {
            expansionMomentum.set({
                momentum: data.expansion_momentum || 0,
                eventCount: data.expansion_event_count || 0,
                totalValue: data.total_expansion_value || 0,
            });
        }
    }

    // ── Page Props Watcher ──────────────────────────────────────

    if (reactiveToPageProps) {
        page.subscribe(($page) => {
            const analytics = $page.props?.zbAnalytics;
            if (!analytics) return;

            // Check for lifecycle data in page props
            const lc = analytics.lifecycle;
            if (lc) {
                updateStoresFromData(lc);
                lifecycleLoaded.set(true);
            }
        });
    }

    // ── Auto-Fetch ──────────────────────────────────────────────

    if (autoFetch && typeof window !== 'undefined') {
        // Initial fetch after short delay (wait for page props)
        setTimeout(() => {
            fetchLifecycle(options.userId ?? null);
        }, 100);

        // Auto-refresh
        if (options.refreshIntervalMs && options.refreshIntervalMs > 0) {
            refreshTimer = setInterval(() => {
                refresh();
            }, options.refreshIntervalMs);
        }
    }

    /**
     * Stop auto-refresh timer.
     */
    function stopAutoRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    // ── Helper Methods ──────────────────────────────────────────

    /**
     * Check if the user is considered "activated".
     * @param {number} [threshold=70] - Activation score threshold
     * @returns {boolean}
     */
    function isActive(threshold) {
        const t = threshold ?? 70;
        let score = 0;
        const unsub = activationScore.subscribe(s => { score = s.score; });
        unsub();
        return score >= t;
    }

    /**
     * Check if the user is at risk of churning.
     * @param {number} [threshold=50] - Churn risk score threshold
     * @returns {boolean}
     */
    function isAtRisk(threshold) {
        const t = threshold ?? 50;
        let riskScore = 0;
        const unsub = churnRisk.subscribe(r => { riskScore = r.riskScore; });
        unsub();
        return riskScore >= t;
    }

    return {
        // Writable stores
        activationScore,
        churnRisk,
        funnelProgress,
        featureAdoption,
        sessionEngagement,
        expansionMomentum,
        lifecycleLoaded,

        // Actions
        fetch: fetchLifecycle,
        refresh,
        stopAutoRefresh,

        // Derived
        activationGrade,
        churnLevel,
        funnelCompletion,

        // Constants
        funnelStepNames: SAAS_FUNNEL_STEPS,

        // Helpers
        isActive,
        isAtRisk,
    };
}

export default useLifecycle;
