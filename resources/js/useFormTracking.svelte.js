/**
 * ZeroBoiler Analytics — Svelte Composable: useFormTracking
 *
 * Reactive form interaction tracking composable for Svelte/Inertia applications.
 * Monitors form focus (form_start) and submission (form_submit) events
 * and provides reactive stores for form engagement metrics.
 *
 * @package ZeroBoiler Analytics
 * @version 269.0.0
 */

import { writable, derived } from 'svelte/store';
import { onDestroy } from 'svelte';
import { trackEvent, isInitialized, getClientId } from './analytics.js';

// ─── Store State ───────────────────────────────────────────────────

/**
 * Forms that have been started (focused) in the current page.
 * @type {import('svelte/store').Writable<string[]>}
 */
export const startedForms = writable([]);

/**
 * Forms that have been submitted in the current page.
 * @type {import('svelte/store').Writable<string[]>}
 */
export const submittedForms = writable([]);

/**
 * Form completion rate (submitted / started).
 * @type {import('svelte/store').Readable<number>}
 */
export const completionRate = derived(
    [startedForms, submittedForms],
    ([$started, $submitted]) => {
        if ($started.length === 0) return 0;
        return Math.round(($submitted.length / $started.length) * 100) / 100;
    },
);

/**
 * Whether form tracking is active.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const tracking = writable(false);

// ─── Internal State ────────────────────────────────────────────────

/** @type {Set<string>} Forms already tracked as started */
const startedSet = new Set();

/** @type {Set<string>} Forms already tracked as submitted */
const submittedSet = new Set();

/** @type {boolean} Cleanup registered */
let cleanupRegistered = false;

// ─── Composable ───────────────────────────────────────────────────

/**
 * Form tracking composable for Svelte components.
 *
 * Monitors form interactions (first focus = form_start, submit = form_submit)
 * and fires corresponding analytics events. Uses data attributes for
 * naming: `data-zb-form` or falls back to form id/action.
 *
 * @param {object} [options] - Configuration options
 * @param {string} [options.selector='form'] - CSS selector for tracked forms
 * @param {boolean} [options.trackStart=true] - Track form_start events
 * @param {boolean} [options.trackSubmit=true] - Track form_submit events
 * @param {Function} [options.onFormStart] - Callback (formName, metadata) => void
 * @param {Function} [options.onFormSubmit] - Callback (formName, metadata) => void
 * @param {Function} [options.shouldTrack] - Filter function (formElement) => boolean
 * @returns {{
 *   startedForms: import('svelte/store').Writable<string[]>,
 *   submittedForms: import('svelte/store').Writable<string[]>,
 *   completionRate: import('svelte/store').Readable<number>,
 *   tracking: import('svelte/store').Writable<boolean>,
 *   start: () => void,
 *   stop: () => void,
 *   reset: () => void,
 *   trackFormStart: (formEl: HTMLFormElement, name?: string) => void,
 *   trackFormSubmit: (formEl: HTMLFormElement, name?: string) => void,
 * }}
 *
 * @example
 * ```svelte
 * <script>
 * import { useFormTracking } from '@zeroboiler/analytics/svelte';
 *
 * const { completionRate, startedForms, submittedForms } = useFormTracking({
 *     onFormStart: (name) => console.log(`Form started: ${name}`),
 * });
 * </script>
 *
 * <p>Form completion rate: {Math.round($completionRate * 100)}%</p>
 * <form data-zb-form="contact">...</form>
 * ```
 */
export function useFormTracking(options = {}) {
    const selector = options.selector || 'form';
    const trackStart = options.trackStart !== false;
    const trackSubmit = options.trackSubmit !== false;
    const onFormStart = options.onFormStart || null;
    const onFormSubmit = options.onFormSubmit || null;
    const shouldTrack = options.shouldTrack || null;

    /** @type {Function|null} Bound focus handler */
    let focusHandler = null;
    /** @type {Function|null} Bound submit handler */
    let submitHandler = null;

    // ─── Helpers ──────────────────────────────────────────────

    function getFormName(form) {
        return (
            form.dataset.zbForm ||
            form.dataset.analyticsForm ||
            form.id ||
            form.action ||
            form.name ||
            'unnamed_form'
        );
    }

    function getFormMetadata(form) {
        return {
            form_name: getFormName(form),
            form_id: form.id || null,
            form_method: form.method?.toUpperCase() || 'GET',
            form_action: form.action || null,
            form_fields: Array.from(form.elements).length,
            page_path: window.location.pathname,
            page_url: window.location.href,
            client_id: getClientId(),
            timestamp: new Date().toISOString(),
        };
    }

    // ─── Handlers ─────────────────────────────────────────────

    function handleFocusIn(event) {
        if (!trackStart) return;

        const form = event.target.closest(selector);
        if (!form) return;
        if (shouldTrack && !shouldTrack(form)) return;

        const name = getFormName(form);

        if (startedSet.has(name)) return;
        startedSet.add(name);

        const metadata = getFormMetadata(form);

        // Fire analytics
        if (isInitialized()) {
            try {
                trackEvent('form_start', metadata);
            } catch { /* silent */ }
        }

        // Update stores
        startedForms.update(forms => [...forms, name]);

        // Callback
        if (onFormStart) {
            try { onFormStart(name, metadata); } catch { /* silent */ }
        }
    }

    function handleSubmit(event) {
        if (!trackSubmit) return;

        const form = event.target.closest(selector);
        if (!form) return;
        if (shouldTrack && !shouldTrack(form)) return;

        const name = getFormName(form);
        const metadata = getFormMetadata(form);

        // Fire analytics
        if (isInitialized()) {
            try {
                trackEvent('form_submit', metadata);
            } catch { /* silent */ }
        }

        // Track submission
        if (!submittedSet.has(name)) {
            submittedSet.add(name);
            submittedForms.update(forms => [...forms, name]);
        }

        // Callback
        if (onFormSubmit) {
            try { onFormSubmit(name, metadata); } catch { /* silent */ }
        }
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    function start() {
        if (typeof document === 'undefined') return;

        focusHandler = handleFocusIn;
        submitHandler = handleSubmit;

        document.addEventListener('focusin', focusHandler, true);
        document.addEventListener('submit', submitHandler, true);
        tracking.set(true);
    }

    function stop() {
        if (typeof document === 'undefined') return;

        if (focusHandler) {
            document.removeEventListener('focusin', focusHandler, true);
            focusHandler = null;
        }
        if (submitHandler) {
            document.removeEventListener('submit', submitHandler, true);
            submitHandler = null;
        }
        tracking.set(false);
    }

    function reset() {
        startedSet.clear();
        submittedSet.clear();
        startedForms.set([]);
        submittedForms.set([]);
    }

    /**
     * Imperatively track a form_start event.
     *
     * @param {HTMLFormElement} formEl - The form element
     * @param {string} [overrideName] - Optional name override
     */
    function trackFormStart(formEl, overrideName) {
        const metadata = getFormMetadata(formEl);
        const name = overrideName || metadata.form_name;

        if (isInitialized()) {
            try {
                trackEvent('form_start', { ...metadata, form_name: name });
            } catch { /* silent */ }
        }

        if (!startedSet.has(name)) {
            startedSet.add(name);
            startedForms.update(forms => [...forms, name]);
        }
    }

    /**
     * Imperatively track a form_submit event.
     *
     * @param {HTMLFormElement} formEl - The form element
     * @param {string} [overrideName] - Optional name override
     */
    function trackFormSubmit(formEl, overrideName) {
        const metadata = getFormMetadata(formEl);
        const name = overrideName || metadata.form_name;

        if (isInitialized()) {
            try {
                trackEvent('form_submit', { ...metadata, form_name: name });
            } catch { /* silent */ }
        }

        if (!submittedSet.has(name)) {
            submittedSet.add(name);
            submittedForms.update(forms => [...forms, name]);
        }
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
        startedForms,
        submittedForms,
        completionRate,
        tracking,
        start,
        stop,
        reset,
        trackFormStart,
        trackFormSubmit,
    };
}

export default useFormTracking;
