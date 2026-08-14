/**
 * ZeroBoiler Analytics — Svelte Composable: useAnalyticsConfig
 *
 * Provides reactive access to the analytics configuration from Inertia page props.
 * Automatically reacts to page navigation and prop changes.
 *
 * @package ZeroBoiler Analytics
 * @version 117.0.0
 */

import { derived } from 'svelte/store';
import { page } from '@inertiajs/svelte';

/**
 * Reactive analytics configuration store.
 *
 * Returns a derived Svelte store that automatically updates when
 * Inertia page props change. Use in any Svelte component to
 * access analytics config reactively.
 *
 * @returns {import('svelte/store').Readable<{
 *   enabled: boolean,
 *   trackingId: string|null,
 *   userId: string|null,
 *   ga4MeasurementId: string|null,
 *   gtmContainerId: string|null,
 *   metaPixelId: string|null,
 *   plausibleDomain: string|null,
 *   posthogHost: string|null,
 *   apiBase: string,
 *   apiEnabled: boolean,
 *   debug: boolean,
 *   version: string,
 *   maturity: { score: number, grade: string }|null,
 *   onboarding: { completion: number, gaps: string[] }|null,
 *   consent: object|null,
 *   consentPurposes: object,
 *   autoTrack: object,
 *   ecommerce: object,
 *   dedup: object,
 *   sampling: object,
 *   identityAutoLink: boolean,
 *   recommendedEvents: object[],
 *   funnelReadiness: object
 * }>}
 *
 * @example
 * ```svelte
 * <script>
 * import { useAnalyticsConfig } from '@zeroboiler/analytics';
 * const config = useAnalyticsConfig();
 * </script>
 *
 * {#if $config.enabled}
 *   <p>Tracking ID: {$config.trackingId}</p>
 *   <p>Version: {$config.version}</p>
 *   <p>Maturity: {$config.maturity?.grade ?? 'N/A'}</p>
 * {/if}
 * ```
 */
export function useAnalyticsConfig() {
    return derived(page, ($page) => {
        const analytics = $page.props?.zbAnalytics;

        if (!analytics) {
            return {
                enabled: false,
                trackingId: null,
                userId: null,
                ga4MeasurementId: null,
                gtmContainerId: null,
                metaPixelId: null,
                plausibleDomain: null,
                posthogHost: null,
                apiBase: '/api/analytics',
                apiEnabled: false,
                debug: false,
                version: '0.0.0',
                maturity: null,
                onboarding: null,
                consent: null,
                consentPurposes: {},
                autoTrack: {},
                ecommerce: {},
                dedup: {},
                sampling: {},
                identityAutoLink: false,
                recommendedEvents: [],
                funnelReadiness: {},
            };
        }

        return {
            enabled: analytics.enabled ?? false,
            trackingId: analytics.trackingId ?? null,
            userId: analytics.userId ?? null,
            ga4MeasurementId: analytics.ga4MeasurementId ?? null,
            gtmContainerId: analytics.gtmContainerId ?? null,
            metaPixelId: analytics.metaPixelId ?? null,
            plausibleDomain: analytics.plausibleDomain ?? null,
            posthogHost: analytics.posthogHost ?? null,
            apiBase: analytics.apiBase ?? '/api/analytics',
            apiEnabled: analytics.apiEnabled ?? false,
            debug: analytics.debug ?? false,
            version: analytics.version ?? '0.0.0',
            maturity: analytics.maturity ?? null,
            onboarding: analytics.onboarding ?? null,
            consent: analytics.consent ?? null,
            consentPurposes: analytics.consentPurposes ?? {},
            autoTrack: analytics.autoTrack ?? {},
            ecommerce: analytics.ecommerce ?? {},
            dedup: analytics.dedup ?? {},
            sampling: analytics.sampling ?? {},
            identityAutoLink: analytics.identityAutoLink ?? false,
            recommendedEvents: analytics.recommendedEvents ?? [],
            funnelReadiness: analytics.funnelReadiness ?? {},
        };
    });
}

/**
 * Reactive analytics consent state store.
 *
 * Derived from the Inertia page props consent object.
 * Useful for conditionally rendering consent banners or
 * gating analytics features based on consent state.
 *
 * @returns {import('svelte/store').Readable<{
 *   ad_storage: string,
 *   analytics_storage: string,
 *   ad_user_data: string,
 *   ad_personalization: string,
 *   functionality_storage: string,
 *   security_storage: string
 * }>}
 *
 * @example
 * ```svelte
 * <script>
 * import { useConsentState } from '@zeroboiler/analytics';
 * const consent = useConsentState();
 * </script>
 *
 * {#if $consent.analytics_storage === 'denied'}
 *   <ConsentBanner />
 * {/if}
 * ```
 */
export function useConsentState() {
    return derived(page, ($page) => {
        const analytics = $page.props?.zbAnalytics;
        const consent = analytics?.consent ?? {};

        return {
            ad_storage: consent.ad_storage ?? 'granted',
            analytics_storage: consent.analytics_storage ?? 'granted',
            ad_user_data: consent.ad_user_data ?? 'granted',
            ad_personalization: consent.ad_personalization ?? 'granted',
            functionality_storage: consent.functionality_storage ?? 'granted',
            security_storage: consent.security_storage ?? 'granted',
        };
    });
}

/**
 * Reactive analytics maturity store.
 *
 * Returns the computed analytics maturity score and grade
 * from the Inertia page props. Updates on every navigation.
 *
 * @returns {import('svelte/store').Readable<{
 *   score: number,
 *   grade: string
 * }>}
 *
 * @example
 * ```svelte
 * <script>
 * import { useMaturity } from '@zeroboiler/analytics';
 * const maturity = useMaturity();
 * </script>
 *
 * <div class="maturity-badge {$maturity.grade.toLowerCase()}">
 *   {Math.round($maturity.score)}% — {$maturity.grade}
 * </div>
 * ```
 */
export function useMaturity() {
    return derived(page, ($page) => {
        const analytics = $page.props?.zbAnalytics;
        const maturity = analytics?.maturity;

        return {
            score: maturity?.score ?? 0,
            grade: maturity?.grade ?? 'N/A',
        };
    });
}

/**
 * Reactive funnel readiness store.
 *
 * Returns per-funnel readiness scores from Inertia page props.
 * Useful for dashboards that show instrumentation coverage.
 *
 * @returns {import('svelte/store').Readable<{
 *   signup: number,
 *   purchase: number,
 *   subscription: number,
 *   overall: number
 * }>}
 */
export function useFunnelReadiness() {
    return derived(page, ($page) => {
        const analytics = $page.props?.zbAnalytics;
        const readiness = analytics?.funnelReadiness;

        return {
            signup: readiness?.signup ?? 0.0,
            purchase: readiness?.purchase ?? 0.0,
            subscription: readiness?.subscription ?? 0.0,
            overall: readiness?.overall ?? 0.0,
        };
    });
}
