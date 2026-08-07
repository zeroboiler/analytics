/**
 * ZeroBoiler Analytics — TypeScript Type Definitions
 *
 * Type definitions for the ZeroBoiler Analytics JS client library.
 * Provides full IntelliSense/auto-complete support for Svelte/Inertia/Laravel apps.
 *
 * @package ZeroBoiler Analytics
 * @version 2.63.0
 */

// ─── Core Types ────────────────────────────────────────────────────────────

/** Single consent purpose configuration */
export interface ConsentPurpose {
    label: string;
    required: boolean;
    default: boolean;
}

/** Analytics configuration passed via Inertia page props */
export interface ZbAnalyticsConfig {
    enabled: boolean;
    consent: ConsentSignals;
    trackingId: string;
    userId: string | null;
    ga4MeasurementId?: string;
    gtmContainerId?: string;
    metaPixelId?: string;
    plausibleDomain?: string;
    posthogHost?: string;
    apiBase: string;
    apiEnabled: boolean;
    debug: boolean;
    trackLinks: TrackLinksConfig;
    device: DeviceContext;
    autoTrack: AutoTrackConfig;
    performance: PerformanceConfig;
    consentPurposes?: Record<string, ConsentPurpose>;
}

/** Consent signals (GDPR Consent Mode v2) */
export interface ConsentSignals {
    analytics_storage: 'granted' | 'denied';
    ad_storage: 'granted' | 'denied';
    ad_user_data: 'granted' | 'denied';
    ad_personalization: 'granted' | 'denied';
    functionality_storage: 'granted' | 'denied';
    security_storage: 'granted' | 'denied';
}

/** Link click tracking configuration */
export interface TrackLinksConfig {
    enabled: boolean;
    trackExternal: boolean;
    trackInternal: boolean;
    externalPrefix: string;
}

/** Device context from server */
export interface DeviceContext {
    userAgent: string;
    ip: string;
    locale: string;
}

/** Auto-tracking configuration (config-driven) */
export interface AutoTrackConfig {
    pageViews: boolean;
    scrollDepth: boolean;
    formTracking: boolean;
    errorTracking: boolean;
    linkTracking: boolean;
    sessionTracking: boolean;
    idleTimeout: number;
    errorIgnorePatterns: string[];
}

/** Performance / Web Vitals configuration */
export interface PerformanceConfig {
    enabled: boolean;
    trackLCP: boolean;
    trackFID: boolean;
    trackCLS: boolean;
    trackINP: boolean;
    trackTTFB: boolean;
    trackFCP: boolean;
    sendToServer: boolean;
}

/** Performance budget configuration */
export interface PerformanceBudgetConfig {
    enabled: boolean;
    max_payload_bytes: number;
    max_params_count: number;
    max_events_per_session: number;
    max_events_per_user_per_day: number;
    max_events_per_page_view: number;
    max_param_value_length: number;
    drop_oversized: boolean;
    warn_only: boolean;
}

/** Event tracking options */
export interface TrackEventOptions {
    /** Bypass batch queue and send immediately */
    immediate?: boolean;
}

/** Analytics event structure */
export interface AnalyticsEvent {
    name: string;
    params: Record<string, unknown>;
}

/** Batch flush result */
export interface FlushResult {
    success: boolean;
    count: number;
}

// ─── Init Options ──────────────────────────────────────────────────────────

/** Options for initAll() — override individual trackers */
export interface InitAllOptions {
    pageViews?: boolean;
    scrollDepth?: boolean;
    formTracking?: boolean;
    errorTracking?: boolean;
    linkTracking?: boolean;
    sessionTracking?: boolean;
    performanceTracking?: boolean;
    idleTimeout?: number;
    errorIgnorePatterns?: string[];
}

/** Options for initFormTracking() */
export interface FormTrackingOptions {
    trackStart?: boolean;
    trackSubmit?: boolean;
}

/** Options for initErrorTracking() */
export interface ErrorTrackingOptions {
    trackErrors?: boolean;
    trackRejections?: boolean;
    ignorePatterns?: string[];
}

/** Options for initLinkTracking() */
export interface LinkTrackingOptions {
    trackExternal?: boolean;
    trackInternal?: boolean;
    externalPrefix?: string;
}

/** Options for initFileDownloadTracking() */
export interface FileDownloadTrackingOptions {
    extensions?: string[];
    trackAll?: boolean;
}

/** Options for initWebVitals() */
export interface WebVitalsOptions {
    enabled?: boolean;
    sendToServer?: boolean;
}

/** Options for initSessionTracking() */
export interface SessionTrackingOptions {
    idleTimeout?: number;
}

/** Options for fetchEventCatalog() */
export interface CatalogFetchOptions {
    forceRefresh?: boolean;
}

/** Event catalog response from server */
export interface EventCatalog {
    status: string;
    version: string;
    total: number;
    categories: {
        ecommerce: EventCategorySection;
        saas: EventCategorySection;
        engagement: EventCategorySection;
    };
    names: string[];
}

/** Event category section in the catalog */
export interface EventCategorySection {
    count: number;
    events: Record<string, EventEntry>;
}

/** Single event entry in the catalog */
export interface EventEntry {
    name: string;
    class: string;
    ga4: string;
    meta: string | null;
    category: string;
}

/** GA4 e-commerce item */
export interface Ga4Item {
    item_id: string;
    item_name?: string;
    item_category?: string;
    price: number;
    quantity: number;
    item_variant?: string;
    item_brand?: string;
    index?: number;
    location_id?: string;
}

/** E-commerce event data */
export interface EcommerceData {
    transaction_id?: string;
    value?: number;
    currency?: string;
    coupon?: string;
    items?: Ga4Item[];
    [key: string]: unknown;
}

/** Screen view options */
export interface ScreenViewOptions {
    screenClass?: string;
    params?: Record<string, unknown>;
}

/** Promotion data */
export interface PromotionData {
    promotion_id?: string;
    promotion_name?: string;
    creative_name?: string;
    creative_slot?: string;
    location_id?: string;
}

/** Server-side page view options */
export interface ServerPageViewOptions {
    title?: string;
    location?: string;
    referrer?: string;
}

/** Search tracking options */
export interface SearchOptions {
    resultCount?: number;
    category?: string;
    params?: Record<string, unknown>;
}

/** Share tracking params */
export interface ShareParams {
    [key: string]: unknown;
}

/** File download data */
export interface FileDownloadData {
    url: string;
    name?: string;
    extension?: string;
    size?: number;
}

/** Video play data */
export interface VideoPlayData {
    title: string;
    url?: string;
    duration?: number;
    percent?: number;
    provider?: string;
}

/** Outbound click options */
export interface OutboundClickOptions {
    linkText?: string;
    linkId?: string;
    section?: string;
    params?: Record<string, unknown>;
}

/** Tracking preference state */
export interface TrackingPreference {
    tracking_allowed: boolean;
    opted_out_at?: string;
}

// ─── Session State ─────────────────────────────────────────────────────────

/** Session tracking state (for debugging) */
export interface SessionState {
    active: boolean;
    id: string | null;
    startTime: number | null;
    eventCount: number;
    pageViewCount: number;
    lastActivity: number | null;
    idleTimer: ReturnType<typeof setTimeout> | null;
    visibilityHandler: ((this: Document, ev: Event) => void) | null;
    cleanupFns: (() => void)[];
}

// ─── Page Props Extension ──────────────────────────────────────────────────

/** Extend Inertia PageProps to include zbAnalytics */
declare module '@inertiajs/core' {
    interface PageProps {
        zbAnalytics?: ZbAnalyticsConfig;
    }
}

// ─── Exported Functions ───────────────────────────────────────────────────

/** Initialize the analytics library from Inertia page props */
export function init(pageProps: Record<string, unknown>): void;

/** Cleanup analytics listeners and timers */
export function destroy(): void;

/** Check if analytics is initialized */
export function isInitialized(): boolean;

/** Get the library version string */
export function getVersion(): string;

/** Get the current tracking ID (server-generated, cookie-stored) */
export function getTrackingId(): string | null;

/** Get the configured API base URL */
export function getApiBaseUrl(): string;

/** Track a custom event */
export function trackEvent(
    name: string,
    params?: Record<string, unknown>,
    options?: TrackEventOptions,
): Promise<void>;

/** Flush the batch event queue */
export function flushQueue(): Promise<void>;

/** Track a page view event */
export function trackPageView(
    title?: string,
    location?: string,
    referrer?: string,
): Promise<void>;

/** Track a screen view event (SPA navigation) */
export function trackScreenView(
    screenName: string,
    options?: ScreenViewOptions,
): Promise<void>;

/** Track an A/B test exposure event */
export function trackAbTestExposure(
    experimentId: string,
    variantId: string,
    params?: Record<string, unknown>,
): Promise<void>;

/** Track an e-commerce event with cross-provider formatting */
export function trackEcommerce(
    name: string,
    data?: EcommerceData,
): Promise<void>;

/** Track a wishlist event */
export function trackWishlist(item: Ga4Item): Promise<void>;

/** Track an item selection from a list */
export function trackSelectItem(
    items?: Ga4Item[],
    itemListId?: string,
    itemListName?: string,
): Promise<void>;

/** Track a promotion view impression */
export function trackPromotionView(promotion?: PromotionData): Promise<void>;

/** Track a promotion click/selection */
export function trackPromotionClick(promotion?: PromotionData): Promise<void>;

/** Track a search event */
export function trackSearch(
    searchTerm: string,
    options?: SearchOptions,
): Promise<void>;

/** Track a content share event */
export function trackShare(
    method: string,
    contentType: string,
    contentId?: string | null,
    params?: ShareParams,
): Promise<void>;

/** Track a file download event */
export function trackFileDownload(
    file: FileDownloadData,
    params?: Record<string, unknown>,
): Promise<void>;

/** Initialize automatic file download tracking */
export function initFileDownloadTracking(
    options?: FileDownloadTrackingOptions,
): () => void;

/** Track a video play event */
export function trackVideoPlay(
    video: VideoPlayData,
    params?: Record<string, unknown>,
): Promise<void>;

/** Track a notification interaction event */
export function trackNotification(
    type: string,
    action: string,
    notificationId?: string | null,
    params?: Record<string, unknown>,
): Promise<void>;

/** Track a SaaS lifecycle event from the client */
export function trackSaaSEvent(
    event: string,
    params?: Record<string, unknown>,
): Promise<void>;

/** Track an outbound click event */
export function trackOutboundClick(
    url: string,
    options?: OutboundClickOptions,
): Promise<void>;

/** Identify a user (link client ↔ user identity) */
export function identify(userId?: string | null): Promise<void>;

/** Update consent signals (GDPR) */
export function updateConsent(signals: Partial<ConsentSignals>): Promise<void>;

/** Initialize scroll depth tracking */
export function initScrollDepth(): () => void;

/** Initialize automatic Inertia page view tracking */
export function initInertiaPageViewTracker(): () => void;

/** Initialize automatic form interaction tracking */
export function initFormTracking(options?: FormTrackingOptions): () => void;

/** Initialize automatic JavaScript error tracking */
export function initErrorTracking(options?: ErrorTrackingOptions): () => void;

/** Track a Web Vitals metric */
export function trackPerformance(
    metricName: string,
    value: number,
    params?: Record<string, unknown>,
): Promise<void>;

/** Initialize Web Vitals tracking with PerformanceObserver */
export function initWebVitals(options?: WebVitalsOptions): () => void;

/** Track a timing event using Performance API */
export function trackTiming(name: string): () => void;

/** Initialize client-side session lifecycle tracking */
export function initSessionTracking(options?: SessionTrackingOptions): () => void;

/** Record an event for session counting */
export function recordSessionEvent(): void;

/** Record a page view for session counting */
export function recordSessionPageView(): void;

/** Get the current session state (for debugging) */
export function getSessionState(): SessionState;

/** Initialize all analytics tracking in one call */
export function initAll(
    pageProps: Record<string, unknown>,
    options?: InitAllOptions,
): () => void;

/** Cleanup all auto-initialized trackers */
export function destroyAll(): void;

/** Push data to the GTM dataLayer (client-side) */
export function pushToDataLayer(data: Record<string, unknown>): void;

/** Fetch the event catalog from the server */
export function fetchEventCatalog(
    options?: CatalogFetchOptions,
): Promise<EventCatalog | null>;

/** Get the cached event catalog (without fetching) */
export function getCachedCatalog(): EventCatalog | null;

/** Clear the cached event catalog */
export function clearCatalogCache(): void;

/** Set user properties / traits on the authenticated user */
export function setUserProperties(
    properties: Record<string, unknown>,
    userId?: string | null,
): Promise<void>;

/** Alias one identity to another (merge anonymous → authenticated) */
export function alias(previousId: string, newId: string): Promise<void>;

/** Identify the current user and optionally set user traits */
export function identifyWithTraits(
    traits?: Record<string, unknown>,
): Promise<void>;

/** Track a page view via the server-side API endpoint */
export function trackServerPageView(options?: ServerPageViewOptions): Promise<void>;

/** Opt the authenticated user out of all tracking */
export function optOutTracking(): Promise<boolean>;

/** Opt the authenticated user in to tracking */
export function optInTracking(): Promise<boolean>;

/** Get the current tracking preference for the authenticated user */
export function getTrackingPreference(): Promise<TrackingPreference | null>;

/** Capture UTM parameters from the current URL */
export function captureUTM(): Record<string, string>;

/** Get currently captured UTM parameters */
export function getUTMParams(): Record<string, string>;

/** Clear captured UTM parameters */
export function clearUTMParams(): void;

/** Check if UTM parameters were captured */
export function hasUTMParams(): boolean;

/** Initialize automatic link click tracking */
export function initLinkTracking(options?: LinkTrackingOptions): () => void;

/** Initialize session heartbeat tracking */
export function initSessionHeartbeat(
    intervalSeconds?: number,
): () => void;

/** Stop the session heartbeat timer */
export function stopSessionHeartbeat(): void;

/** Check if the session heartbeat is active */
export function isHeartbeatActive(): boolean;

/** Check if the analytics library has performance budget constraints */
export function hasPerformanceBudget(): boolean;

/** Get the configured performance budget limits */
export function getPerformanceBudget(): PerformanceBudgetConfig | null;

/** Estimate the payload size of an event in bytes */
export function estimatePayloadSize(name: string, params?: Record<string, unknown>): number;

/** Check if an event would exceed the configured payload budget */
export function exceedsPayloadBudget(name: string, params?: Record<string, unknown>): boolean;

/** Check if event forwarding is configured */
export function isForwardingEnabled(): boolean;

/** Get the list of configured forwarder names */
export function getForwarderNames(): string[];

/** Signal that the user has granted consent (replays pre-queued events) */
export function consentGranted(): void;

/** Signal that the user has denied consent (discards pre-queued events) */
export function consentDenied(): void;

/** Get the current consent resolution state */
export function getConsentState(): boolean | null;

/** Get the count of events currently queued before consent */
export function getConsentPreQueueCount(): number;

/** Clear the consent state (reset to pending/unresolved) */
export function resetConsentState(): void;

// ─── Consent Purposes (GDPR Granular) ────────────────────────────────

/** Get the consent purposes configuration from the server */
export function getConsentPurposes(): Record<string, ConsentPurpose>;

/** Get a flat list of consent purpose keys */
export function getConsentPurposeKeys(): string[];

/** Get consent purposes that the user can toggle (non-required) */
export function getOptionalConsentPurposes(): Record<string, { label: string; default: boolean }>;

/** Build consent signals from granular purpose grants/denials */
export function buildConsentSignals(grants: Record<string, boolean>): Record<string, 'granted' | 'denied'>;

// ─── SaaS Journey Milestone Tracking ──────────────────────────────────

/** Record a milestone hit in a SaaS journey */
export function trackJourneyMilestone(journey: string, milestone: string, params?: object): Promise<object | null>;

/** Get progress for a specific journey */
export function getJourneyProgress(journey: string): Promise<object | null>;

/** Get all registered journeys and their progress */
export function getAllJourneys(): Promise<{ journeys: object; progress: object } | null>;

/** Reset progress for a specific journey */
export function resetJourneyProgress(journey: string): Promise<boolean>;

// ─── Svelte Tracker (Zero-Config Component) ────────────────────────

/** Options for initSvelteTracker */
export interface SvelteTrackerOptions {
    /** Auto-track page views on Inertia navigation (default: true) */
    trackPageViews?: boolean;
    /** Enable all auto-trackers: scroll, form, error, session (default: false) */
    enableAllAutoTrackers?: boolean;
}

/**
 * Initialize analytics with auto-page-view tracking for Svelte/Inertia.
 * Returns a cleanup function for Svelte's `onMount()`.
 */
export function initSvelteTracker(pageProps: object, options?: SvelteTrackerOptions): () => void;

// ─── Paid Ad Click Tracking ────────────────────────────────────────

/** Ad click parameters */
export interface AdClickParams {
    platform: string;
    campaignId: string;
    adGroupId: string;
    creativeId: string;
    placement?: string;
    keyword?: string;
    cost?: number;
}

/** Track a paid advertisement click */
export function trackAdClick(params: AdClickParams): Promise<boolean>;

// ─── Content Engagement Tracking ───────────────────────────────────

/** Content engagement parameters */
export interface ContentEngagementParams {
    contentType: string;
    contentId: string;
    title?: string;
    author?: string;
    category?: string;
    engagementPercent?: number;
    timeSpentSeconds?: number;
    completed?: boolean;
}

/** Track content engagement (article reading, video watching, etc.) */
export function trackContentEngagement(params: ContentEngagementParams): Promise<boolean>;

// ─── Onboarding Step Tracking ──────────────────────────────────────

/** Onboarding step parameters */
export interface OnboardingStepParams {
    stepName: string;
    stepIndex: number;
    totalSteps: number;
    method?: string;
    completed?: boolean;
    durationSeconds?: number;
    skippedReason?: string;
}

/** Track a SaaS onboarding step for product activation funnel analysis */
export function trackOnboardingStep(params: OnboardingStepParams): Promise<boolean>;

// ─── Feature Impression Tracking ──────────────────────────────────

/** Feature impression parameters */
export interface FeatureImpressionParams {
    featureName: string;
    location: string;
    source?: string;
    variant?: string;
    context?: string;
}

/** Track when a user sees a feature, UI element, or upgrade prompt */
export function trackFeatureImpression(params: FeatureImpressionParams): Promise<boolean>;

// ─── Checkout Step Tracking ───────────────────────────────────────

/** Checkout step parameters */
export interface CheckoutStepParams {
    stepIndex: number;
    stepName?: string;
    paymentMethod?: string;
    orderTotal?: number;
    currency?: string;
}

/** Track an e-commerce checkout step for funnel analysis */
export function trackCheckoutStep(params: CheckoutStepParams): Promise<boolean>;
