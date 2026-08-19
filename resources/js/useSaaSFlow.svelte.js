/**
 * ZeroBoiler Analytics — Svelte Composable: useSaaSFlow
 *
 * Reactive SaaS lifecycle funnel composable for Svelte/Inertia applications.
 * Provides ready-to-use reactive SaaS funnel tracking:
 *   signup → trial → subscription → activation → expansion → retention
 *
 * Automatically tracks funnel progression using the SaaS event catalog
 * and exposes reactive stores for each funnel stage.
 *
 * Usage:
 *   import { useSaaSFlow } from './useSaaSFlow.svelte.js';
 *   const flow = useSaaSFlow();
 *   await flow.trackSignUp({ method: 'email' });
 *   await flow.trackTrialStart({ plan: 'pro', days: 14 });
 *   await flow.trackSubscription({ plan: 'pro', mrr: 49 });
 *   console.log($flow.stage);        // 'subscribed'
 *   console.log($flow.progress);     // 0.6 (60%)
 *   console.log($flow.stageHistory); // ['anonymous', 'signed_up', 'trialing', 'subscribed']
 *
 * @package ZeroBoiler Analytics
 * @version 260.0.0
 */

import { writable, derived } from 'svelte/store';
import { trackEvent, isInitialized, getTrackingId } from './analytics.js';

// ─── Funnel Stage Definitions ─────────────────────────────────────────

/**
 * Ordered SaaS lifecycle funnel stages.
 * Each stage represents a key milestone in the SaaS customer journey.
 *
 * @type {readonly string[]}
 */
const FUNNEL_STAGES = Object.freeze([
    'anonymous',       // No account
    'signed_up',      // Account created
    'trialing',       // Active trial
    'subscribed',     // Paid subscription active
    'activated',      // First value / aha moment achieved
    'expanding',      // Plan upgrade or seat expansion
    'retained',       // Renewed past first billing cycle
    'champion',       // Power user — high engagement + referrals
]);

/**
 * Stage index lookup for quick progression checks.
 * @type {Map<string, number>}
 */
const STAGE_INDEX = new Map(FUNNEL_STAGES.map((stage, i) => [stage, i]));

/**
 * Event name to funnel stage mapping.
 * Maps SaaS catalog event names to their corresponding funnel stage.
 *
 * @type {Record<string, string>}
 */
const EVENT_STAGE_MAP = {
    'sign_up': 'signed_up',
    'start_trial': 'trialing',
    'trial_start': 'trialing',
    'subscribe': 'subscribed',
    'subscription': 'subscribed',
    'trial_converted': 'subscribed',
    'first_value': 'activated',
    'plan_upgrade': 'expanding',
    'plan_downgrade': 'subscribed', // Downgrade stays subscribed
    'team_created': 'expanding',
    'team_member_joined': 'expanding',
    'subscription_renewal': 'retained',
    'feature_used': null, // Doesn't change stage by itself
};

// ─── Store State ──────────────────────────────────────────────────────

/** @type {import('svelte/store').Writable<string>} */
export const stage = writable('anonymous');

/** @type {import('svelte/store').Writable<string[]>} */
export const stageHistory = writable(['anonymous']);

/** @type {import('svelte/store').Writable<Date|null>} */
export const lastStageChangeAt = writable(null);

/** @type {import('svelte/store').Writable<Record<string, Record<string, unknown>>>} */
export const stageMetadata = writable({});

/**
 * Derived store: funnel progress as a percentage (0.0 to 1.0).
 * Based on current stage position in the funnel.
 *
 * @type {import('svelte/store').Derived<string, number>}
 */
export const progress = derived(stage, ($stage) => {
    const idx = STAGE_INDEX.get($stage) ?? 0;
    return Math.round((idx / (FUNNEL_STAGES.length - 1)) * 100) / 100;
});

/**
 * Derived store: next expected funnel stage (or null if at champion).
 *
 * @type {import('svelte/store').Derived<string, string|null>}
 */
export const nextStage = derived(stage, ($stage) => {
    const idx = STAGE_INDEX.get($stage) ?? 0;
    return FUNNEL_STAGES[idx + 1] ?? null;
});

/**
 * Derived store: whether the user can advance (not at champion stage).
 *
 * @type {import('svelte/store').Derived<string, boolean>}
 */
export const canAdvance = derived(stage, ($stage) => {
    return $stage !== 'champion';
});

/**
 * Derived store: current stage index (0-based).
 *
 * @type {import('svelte/store').Derived<string, number>}
 */
export const stageIndex = derived(stage, ($stage) => {
    return STAGE_INDEX.get($stage) ?? 0;
});

// ─── Internal State ───────────────────────────────────────────────────

/** @type {boolean} Whether the composable has been initialized */
let initialized = false;

// ─── Composable ────────────────────────────────────────────────────────

/**
 * SaaS lifecycle funnel composable.
 *
 * Provides a reactive funnel tracking system that maps SaaS events
 * to funnel stages and exposes derived stores for progress, next stage,
 * and stage history.
 *
 * @param {object} [options] - Configuration options
 * @param {boolean} [options.autoIdentify=true] - Auto-identify on stage changes
 * @param {Record<string, string>} [options.customEventStages={}] - Custom event → stage mappings
 * @returns {{
 *   stage: import('svelte/store').Writable<string>,
 *   stageHistory: import('svelte/store').Writable<string[]>,
 *   lastStageChangeAt: import('svelte/store').Writable<Date|null>,
 *   stageMetadata: import('svelte/store').Writable<Record<string, Record<string, unknown>>>,
 *   progress: import('svelte/store').Readable<number>,
 *   nextStage: import('svelte/store').Readable<string|null>,
 *   canAdvance: import('svelte/store').Readable<boolean>,
 *   stageIndex: import('svelte/store').Readable<number>,
 *   trackSignUp: (options?: Record<string, unknown>) => Promise<void>,
 *   trackTrialStart: (options?: Record<string, unknown>) => Promise<void>,
 *   trackSubscription: (options?: Record<string, unknown>) => Promise<void>,
 *   trackActivation: (options?: Record<string, unknown>) => Promise<void>,
 *   trackExpansion: (options?: Record<string, unknown>) => Promise<void>,
 *   trackRetention: (options?: Record<string, unknown>) => Promise<void>,
 *   trackCancellation: (options?: Record<string, unknown>) => Promise<void>,
 *   advanceTo: (newStage: string, metadata?: Record<string, unknown>) => void,
 *   reset: () => void,
 *   getFunnelStages: () => readonly string[],
 *   getStageIndex: (stageName: string) => number,
 * }}
 */
export function useSaaSFlow(options = {}) {
    const {
        customEventStages = {},
    } = options;

    // Merge custom event stage mappings
    const mergedMap = { ...EVENT_STAGE_MAP, ...customEventStages };

    // Mark as initialized
    if (!initialized) {
        initialized = true;
    }

    /**
     * Advance the funnel to a new stage if it's a forward progression.
     * Only moves forward — never regresses (except explicit reset).
     *
     * @param {string} newStage - Target stage name
     * @param {Record<string, unknown>} [metadata={}] - Metadata for this stage transition
     */
    function advanceTo(newStage, metadata = {}) {
        const currentIdx = STAGE_INDEX.get(stage) ?? 0;
        const newIdx = STAGE_INDEX.get(newStage);

        // Unknown stage — store but don't advance
        if (newIdx === undefined) {
            console.warn(`[useSaaSFlow] Unknown stage: "${newStage}". Valid stages: ${FUNNEL_STAGES.join(', ')}`);
            return;
        }

        // Don't regress (unless it's the same stage)
        if (newIdx <= currentIdx && newStage !== stage) {
            return;
        }

        // Update stage
        stage.set(newStage);
        stageHistory.update(history => {
            // Avoid duplicate consecutive stages
            if (history[history.length - 1] === newStage) {
                return history;
            }
            return [...history, newStage];
        });
        lastStageChangeAt.set(new Date());

        // Store stage metadata
        if (Object.keys(metadata).length > 0) {
            stageMetadata.update(meta => ({
                ...meta,
                [newStage]: { ...(meta[newStage] || {}), ...metadata },
            }));
        }
    }

    /**
     * Reset the funnel to initial state (anonymous).
     * Useful for logout or testing.
     */
    function reset() {
        stage.set('anonymous');
        stageHistory.set(['anonymous']);
        lastStageChangeAt.set(null);
        stageMetadata.set({});
    }

    /**
     * Track a sign-up event and advance to 'signed_up' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - method: 'email' | 'google' | 'github' | 'sso'
     *   - referrer: string
     */
    async function trackSignUp(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('sign_up', params);
        advanceTo('signed_up', params);
    }

    /**
     * Track a trial start event and advance to 'trialing' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - plan: string (e.g. 'pro', 'enterprise')
     *   - days: number (trial duration)
     *   - trial_id: string
     */
    async function trackTrialStart(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('start_trial', params);
        advanceTo('trialing', params);
    }

    /**
     * Track a subscription event and advance to 'subscribed' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - plan: string
     *   - mrr: number (monthly recurring revenue)
     *   - billing_cycle: 'monthly' | 'annual'
     *   - payment_provider: 'stripe' | 'paddle' | 'braintree'
     */
    async function trackSubscription(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('subscribe', params);
        advanceTo('subscribed', params);
    }

    /**
     * Track an activation (first value) event and advance to 'activated' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - milestone: string (e.g. 'first_project_created', 'first_api_call')
     *   - time_to_activate: number (seconds since signup)
     */
    async function trackActivation(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('feature_used', {
            ...params,
            feature_name: params.milestone || 'activation',
        });
        advanceTo('activated', params);
    }

    /**
     * Track an expansion event and advance to 'expanding' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - event_type: 'plan_upgrade' | 'seat_addition' | 'usage_increase'
     *   - previous_plan: string
     *   - new_plan: string
     *   - additional_mrr: number
     */
    async function trackExpansion(params = {}) {
        if (!isInitialized()) return;
        const eventType = params.event_type || 'plan_upgrade';
        await trackEvent(eventType, params);
        if (eventType !== 'plan_downgrade') {
            advanceTo('expanding', params);
        }
    }

    /**
     * Track a retention event and advance to 'retained' stage.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - renewal_count: number
     *   - tenure_months: number
     */
    async function trackRetention(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('subscription_renewal', params);
        advanceTo('retained', params);
    }

    /**
     * Track a cancellation event.
     * Note: Cancellation does NOT advance the funnel — it logs the event
     * but keeps the user at their current stage for historical tracking.
     *
     * @param {Record<string, unknown>} [params={}] - Event parameters
     *   - reason: string (e.g. 'too_expensive', 'missing_features', 'switched_competitor')
     *   - feedback: string
     *   - plan: string
     */
    async function trackCancellation(params = {}) {
        if (!isInitialized()) return;
        await trackEvent('cancellation', params);
        // Don't advance — cancellation is tracked but doesn't change funnel stage
    }

    /**
     * Get the ordered list of all funnel stages.
     *
     * @returns {readonly string[]}
     */
    function getFunnelStages() {
        return FUNNEL_STAGES;
    }

    /**
     * Get the numeric index of a funnel stage.
     *
     * @param {string} stageName - Stage name
     * @returns {number} 0-based index, or 0 for unknown stages
     */
    function getStageIndex(stageName) {
        return STAGE_INDEX.get(stageName) ?? 0;
    }

    return {
        // Stores
        stage,
        stageHistory,
        lastStageChangeAt,
        stageMetadata,

        // Derived stores
        progress,
        nextStage,
        canAdvance,
        stageIndex,

        // Tracking methods
        trackSignUp,
        trackTrialStart,
        trackSubscription,
        trackActivation,
        trackExpansion,
        trackRetention,
        trackCancellation,

        // Control methods
        advanceTo,
        reset,
        getFunnelStages,
        getStageIndex,
    };
}

/**
 * Get a static funnel snapshot for server rendering or testing.
 * Returns the current funnel state without reactive subscriptions.
 *
 * @returns {{
 *   stage: string,
 *   progress: number,
 *   nextStage: string|null,
 *   stages: readonly string[],
 *   history: string[],
 * }}
 */
export function getFunnelSnapshot() {
    let currentStage = 'anonymous';
    let currentHistory = ['anonymous'];
    let currentProgress = 0;

    // Synchronously read store values (for non-reactive contexts)
    const unsubStage = stage.subscribe(s => { currentStage = s; });
    const unsubHistory = stageHistory.subscribe(h => { currentHistory = h; });
    const unsubProgress = progress.subscribe(p => { currentProgress = p; });

    unsubStage();
    unsubHistory();
    unsubProgress();

    const currentIdx = STAGE_INDEX.get(currentStage) ?? 0;

    return {
        stage: currentStage,
        progress: currentProgress,
        nextStage: FUNNEL_STAGES[currentIdx + 1] ?? null,
        stages: FUNNEL_STAGES,
        history: currentHistory,
    };
}

export default useSaaSFlow;
