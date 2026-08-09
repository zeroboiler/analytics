/**
 * ZeroBoiler Analytics — TypeScript Type Definitions
 *
 * Type definitions for the ZeroBoiler Analytics JS client library.
 * Provides full IntelliSense/auto-complete support for Svelte/Inertia/Laravel apps.
 *
 * @package ZeroBoiler Analytics
 * @version 3.3.0
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
    consentVersion?: string;
    ecommerce?: EcommerceConfig;
    consentLogEnabled?: boolean;
    version?: string;
    subscriptionTiers?: Record<string, SubscriptionTier>;
    identityAutoLink?: boolean;
    maturity?: MaturityScore;
    onboarding?: OnboardingStatus;
    funnelReadiness?: FunnelReadiness;
    recommendedEvents?: RecommendedEvent[];
    dedup?: DedupConfig;
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

/** E-commerce defaults from server config */
export interface EcommerceConfig {
    currency: string;
    brand: string;
    taxBehavior: string;
    shippingDefault: number;
}

/** Subscription tier definition from server config */
export interface SubscriptionTier {
    name?: string;
    price?: number;
    billing_cycle?: string;
    features?: string[];
}

/** Analytics maturity score from server */
export interface MaturityScore {
    score: number;
    grade: string;
}

/** Onboarding completion status from server */
export interface OnboardingStatus {
    completion: number;
    gaps: string[];
}

/** Funnel readiness scores from server (v2.84.0) */
export interface FunnelReadiness {
    signup: number;
    purchase: number;
    subscription: number;
    overall: number;
}

/** Recommended event for client-side instrumentation (v2.84.0) */
export interface RecommendedEvent {
    name: string;
    category: string | null;
    priority: 'critical' | 'high' | 'medium' | 'low';
}

/** Event deduplication configuration from server (v2.84.0) */
export interface DedupConfig {
    enabled: boolean;
    windowSeconds: number;
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

/** Options for initInertiaPageViewTracker */
export interface InertiaPageViewTrackerOptions {
    trackInitial?: boolean;
    delayMs?: number;
    onTrack?: (title: string, location: string) => void;
    enableScrollDepth?: boolean;
}

/** Initialize automatic Inertia page view tracking */
export function initInertiaPageViewTracker(options?: InertiaPageViewTrackerOptions): () => void;

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

// ─── SaaS Subscription & Revenue Tracking (v2.66.0) ────────────────

/** Subscription action types */
export type SubscriptionAction = 'created' | 'renewed' | 'upgraded' | 'downgraded' | 'cancelled' | 'trial_start' | 'trial_end';

/** Subscription event parameters */
export interface SubscriptionEventParams {
    action: SubscriptionAction;
    planName?: string;
    planPrice?: number;
    billingCycle?: string;
    currency?: string;
    reason?: string;
    meta?: Record<string, unknown>;
}

/** Track a SaaS subscription lifecycle event */
export function trackSubscriptionEvent(params: SubscriptionEventParams): Promise<boolean>;

/** Trial state types */
export type TrialState = 'start' | 'active' | 'converted' | 'expired';

/** Trial event parameters */
export interface TrialEventParams {
    state: TrialState;
    planName?: string;
    trialDays?: number;
    daysUsed?: number;
}

/** Track a trial lifecycle event */
export function trackTrialEvent(params: TrialEventParams): Promise<boolean>;

/** Revenue event types */
export type RevenueEventType = 'payment_succeeded' | 'payment_failed' | 'invoice' | 'credit';

/** Revenue event parameters */
export interface RevenueEventParams {
    type: RevenueEventType;
    amount?: number;
    currency?: string;
    planName?: string;
    invoiceId?: string;
    paymentMethod?: string;
    failureReason?: string;
}

/** Track a revenue event for SaaS billing */
export function trackRevenueEvent(params: RevenueEventParams): Promise<boolean>;

/** Plan change parameters */
export interface PlanChangeParams {
    fromPlan: string;
    toPlan: string;
    fromPrice?: number;
    toPrice?: number;
    currency?: string;
    reason?: string;
}

/** Track a plan change event (upgrade or downgrade) */
export function trackPlanChange(params: PlanChangeParams): Promise<boolean>;

// ─── Event Priority (v2.66.0) ──────────────────────────────────────

/** Event priority levels for SaaS analytics gate */
export type EventPriority = 'critical' | 'normal' | 'low' | 'background';

/** Priority-aware tracking options */
export interface PriorityTrackOptions {
    /** Override the default priority for this event */
    priority?: EventPriority;
    /** Skip the priority gate check entirely */
    bypassGate?: boolean;
}

/** Track an event with priority override */
export function trackEventWithPriority(
    eventName: string,
    params?: Record<string, unknown>,
    priority?: EventPriority,
): Promise<boolean>;

// ─── First-Touch UTM Attribution (v2.67.0) ──────────────────────────

/** First-touch UTM parameters persisted in a cookie */
export interface FirstTouchUTM {
    utm_source?: string;
    utm_medium?: string;
    utm_campaign?: string;
    utm_term?: string;
    utm_content?: string;
    first_touch_timestamp?: string;
    first_touch_page?: string;
}

/** Full attribution context (first-touch + last-touch) */
export interface AttributionContext {
    first_touch: FirstTouchUTM;
    last_touch: Record<string, string>;
}

/** Get first-touch UTM parameters from cookie */
export function getFirstTouchUTM(): FirstTouchUTM;

/** Get full attribution context (first-touch + last-touch) */
export function getAttributionContext(): AttributionContext;

/** Clear first-touch UTM cookie */
export function clearFirstTouchUTM(): void;

// ─── Data Warehouse Export (v2.67.0) ─────────────────────────────────

/** Data warehouse export options */
export interface DataWarehouseExportOptions {
    format?: 'ndjson' | 'csv';
    category?: string;
    event?: string;
}

/** Data warehouse export result */
export interface DataWarehouseExportResult {
    path: string;
    format: string;
    events: number;
    bytes: number;
}

/** Trigger a server-side data warehouse export */
export function exportToDataWarehouse(options?: DataWarehouseExportOptions): Promise<DataWarehouseExportResult | null>;

// ─── Dashboard Overview (v2.67.0) ────────────────────────────────────

/** Dashboard overview data */
export interface DashboardOverview {
    version: string;
    providers: Record<string, boolean>;
    catalog: {
        total: number;
        ecommerce: number;
        saas: number;
        engagement: number;
        with_ga4: number;
        with_meta: number;
        with_posthog: number;
        with_plausible: number;
    };
    kpi: Record<string, unknown> | null;
    health_score: Record<string, unknown> | null;
    realtime: Record<string, unknown> | null;
    alerts: unknown[];
    metrics: {
        dispatched: number;
        failed: number;
        filtered: number;
        providers: Record<string, unknown>;
    };
}

/** Fetch the analytics dashboard overview from the server */
export function fetchDashboardOverview(): Promise<DashboardOverview | null>;

// ─── v2.70.0 Types ──────────────────────────────────────────────────

/** Circuit breaker state per provider */
export interface CircuitBreakerProviderState {
    state: 'closed' | 'open' | 'half_open';
    failures: number;
    successes: number;
    last_failure: number | null;
    cooldown_remaining: number | null;
}

/** Circuit breaker dashboard response */
export interface CircuitBreakerDashboard {
    enabled: boolean;
    failure_threshold: number;
    success_threshold: number;
    cooldown_seconds: number;
    providers: Record<string, CircuitBreakerProviderState>;
}

/** Compliance audit report sections */
export interface ComplianceReport {
    generated_at: string;
    overall_score: number;
    pii_exposure: {
        score: number;
        total_events_analyzed: number;
        pii_events: string[];
        pii_risk_by_category: Record<string, string>;
        pii_fields_detected: string[];
        anonymization_enabled: boolean;
        ip_anonymization_enabled: boolean;
    };
    consent_coverage: {
        score: number;
        total_events_mapped: number;
        unmapped_events: string[];
        purpose_breakdown: Record<string, number>;
        default_consent: string;
        granular_consent_enabled: boolean;
    };
    retention: {
        score: number;
        categories: Record<string, { default_days: number; pii_risk: string; configured_days: number | null; policy_active: boolean }>;
        global_retention_days: number | null;
        archive_action: string;
    };
    data_minimization: {
        score: number;
        enabled: boolean;
        global_allowlist_count: number;
        strip_params_count: number;
        audit_logging: boolean;
        strategy: string;
    };
    processing_transparency: {
        score: number;
        providers_configured: number;
        providers_total: number;
        pipeline_steps: string[];
        middleware_registered: string[];
        data_export_available: boolean;
        dsar_available: boolean;
    };
    recommendations: string[];
}

/** Recovery budget response */
export interface RecoveryBudget {
    remaining: number;
    max: number;
    used: number;
    resets_at: string;
}

/** Recovery health response */
export interface RecoveryHealth {
    status: 'healthy' | 'degraded' | 'critical';
    dlq_size: number;
    budget_remaining: number;
    recovery_rate_24h: number | null;
    health_score: number;
}

/** Recovery history response */
export interface RecoveryHistory {
    total_recovered_24h: number;
    total_failed_24h: number;
    last_recovery: string | null;
    budget: RecoveryBudget;
}

/** Fetch circuit breaker dashboard for all providers */
export function fetchCircuitBreakerDashboard(): Promise<CircuitBreakerDashboard | null>;

/** Fetch circuit breaker summary (open/half-open/closed counts) */
export function fetchCircuitBreakerSummary(): Promise<{ enabled: boolean; total_open: number; total_half_open: number; total_closed: number; providers: string[] } | null>;

/** Fetch comprehensive compliance audit report */
export function fetchComplianceReport(): Promise<ComplianceReport | null>;

/** Fetch quick compliance score (0-100) */
export function fetchComplianceScore(): Promise<number | null>;

// ─── Revenue Forecasting (v2.81.0) ──────────────────────────────────

/** Revenue forecast summary */
export interface ForecastSummary {
    current_mrr: number;
    current_arr: number;
    projected_mrr_30d: number;
    projected_arr_30d: number;
    mrr_growth_rate: number;
    churn_rate: number;
    net_revenue_retention: number;
    ltv_estimate: number;
    runway_months: number;
    confidence: 'high' | 'medium' | 'low';
}

/** Daily forecast data point */
export interface ForecastPoint {
    date: string;
    mrr: number;
    arr: number;
    churned_mrr: number;
    net_new_mrr: number;
    churn_rate: number;
}

/** Full forecast response */
export interface RevenueForecastResponse {
    status: string;
    summary: ForecastSummary;
    daily: ForecastPoint[];
    assumptions: Record<string, unknown>;
}

/** LTV calculation result */
export interface LTVResult {
    ltv: number;
    ltv_months: number;
    arpu_annual: number;
    churn_multiplier: number;
}

/** LTV:CAC ratio result */
export interface LTVCACResult {
    ratio: number;
    rating: 'excellent' | 'healthy' | 'underperforming' | 'critical' | 'unknown';
    recommendation: string;
}

/** Payback period result */
export interface PaybackResult {
    months: number;
    rating: 'excellent' | 'healthy' | 'acceptable' | 'concerning' | 'critical';
    target_months: number;
}

/** Runway estimation result */
export interface RunwayResult {
    runway_months: number;
    breakeven_date: string | null;
    burn_rate: number;
    path_to_profitability: string;
}

/** MRR movement breakdown */
export interface MRRMovement {
    new: number;
    expansion: number;
    contraction: number;
    churn: number;
    net_change: number;
    previous_mrr: number;
    new_mrr: number;
}

/** Revenue projection at a specific date */
export interface RevenueProjection {
    date: string;
    projected_mrr: number;
    projected_arr: number;
    cumulative_churn: number;
    cumulative_growth: number;
}

/** Cohort retention curve point */
export interface CohortRetentionPoint {
    month: number;
    retention_rate: number;
    estimated_subscribers: number;
    estimated_mrr: number;
}

/** Revenue forecast parameters */
export interface ForecastParams {
    mrr?: number;
    arr?: number;
    churned_mrr_last_month?: number;
    new_mrr_last_month?: number;
    expansion_mrr_last_month?: number;
    active_subscribers?: number;
    churned_subscribers_last_month?: number;
}

/** Fetch full revenue forecast with daily data points */
export function fetchRevenueForecast(params?: ForecastParams): Promise<RevenueForecastResponse | null>;

/** Fetch quick revenue forecast summary */
export function fetchForecastSummary(params?: ForecastParams): Promise<ForecastSummary | null>;

/** Project MRR at a specific future date */
export function fetchRevenueProjection(daysOut: number, mrr?: number): Promise<RevenueProjection | null>;

/** Calculate Customer Lifetime Value */
export function fetchLTV(arpu: number, churnRate: number, grossMargin?: number): Promise<LTVResult | null>;

/** Calculate LTV:CAC ratio */
export function fetchLTVCACRatio(ltv: number, cac: number): Promise<LTVCACResult | null>;

/** Calculate CAC payback period */
export function fetchPaybackPeriod(cac: number, arpu: number, grossMargin?: number): Promise<PaybackResult | null>;

/** Fetch runway estimate */
export function fetchRunway(mrr: number, expenses: number, growthRate?: number, churnRate?: number): Promise<RunwayResult | null>;

/** Fetch cohort retention curve */
export function fetchCohortRetentionCurve(months?: number, churnRate?: number): Promise<{ curve: CohortRetentionPoint[] } | null>;

/** Fetch MRR movement breakdown */
export function fetchMRRMovement(params?: { new_mrr?: number; expansion_mrr?: number; contraction_mrr?: number; churned_mrr?: number; previous_mrr?: number }): Promise<MRRMovement | null>;

// ─── Churn Prediction (v2.81.0) ───────────────────────────────────

/** Churn risk signal */
export interface ChurnRiskSignal {
    name: string;
    weight: number;
    value: number;
    max_value: number;
    score: number;
}

/** Churn risk profile for a single user */
export interface ChurnRiskProfile {
    user_id: string;
    overall_score: number;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    signals: ChurnRiskSignal[];
    recommendation: string;
    probability_percent: number;
}

/** Churn user signals for scoring */
export interface ChurnUserSignals {
    days_inactive?: number;
    usage_decline_pct?: number;
    support_tickets_30d?: number;
    failed_payments_90d?: number;
    feature_adoption_pct?: number;
    contract_expiring_30d?: boolean;
    billing_disputes?: number;
    login_frequency_decline_pct?: number;
    engagement_score?: number;
    plan_downgrade_recent?: boolean;
}

/** Batch churn scoring result */
export interface ChurnBatchResult {
    ranked: ChurnRiskProfile[];
    summary: {
        total: number;
        critical: number;
        high: number;
        medium: number;
        low: number;
        avg_score: number;
    };
    at_risk_count: number;
}

/** Cohort churn risk summary */
export interface ChurnCohortSummary {
    total_users: number;
    risk_distribution: {
        low: number;
        medium: number;
        high: number;
        critical: number;
    };
    avg_risk_score: number;
    estimated_monthly_churn_revenue: number;
    top_risk_factors: Array<{ signal: string; avg_score: number }>;
}

/** Score a single user's churn risk */
export function scoreChurnRisk(userId: string, signals?: ChurnUserSignals): Promise<ChurnRiskProfile | null>;

/** Score multiple users and return ranked results */
export function scoreChurnBatch(users: Array<{ user_id: string } & ChurnUserSignals>): Promise<ChurnBatchResult | null>;

/** Fetch churn cohort risk summary */
export function fetchChurnCohortSummary(users: Array<{ user_id: string } & ChurnUserSignals>): Promise<ChurnCohortSummary | null>;

/** Fetch configured churn signal weights */
export function fetchChurnWeights(): Promise<{ status: string; weights: Record<string, number> } | null>;

/** Fetch configured churn risk thresholds */
export function fetchChurnThresholds(): Promise<{ status: string; thresholds: { medium: number; high: number; critical: number } } | null>;

/** Fetch DLQ recovery budget status */
export function fetchRecoveryBudget(): Promise<RecoveryBudget | null>;

/** Fetch recovery pipeline health assessment */
export function fetchRecoveryHealth(): Promise<RecoveryHealth | null>;

/** Fetch recovery history summary (24h) */
export function fetchRecoveryHistory(): Promise<RecoveryHistory | null>;

// ─── SaaS Readiness & Maturity (v2.83.0) ─────────────────────────

/** Get the analytics maturity score from Inertia props (synchronous) */
export function getMaturityScore(): MaturityScore | null;

/** Get the onboarding completion status from Inertia props (synchronous) */
export function getOnboardingStatus(): OnboardingStatus | null;

/** Check if analytics instrumentation meets industry-standard SaaS criteria */
export function isSaaSReady(): boolean;

/** Get the event catalog summary from the server */
export function getEventCatalogSummary(): Promise<EventCatalog | null>;

/** Get the SaaS funnel readiness assessment from the server */
export function getFunnelReadiness(): Promise<{ status: string; score: number; gaps: string[]; funnel_steps: Record<string, unknown> } | null>;

/** Get the industry-standard instrumentation checklist from the server */
export function getIndustryStandard(): Promise<{ status: string; critical: EventEntry[]; high: EventEntry[]; medium: EventEntry[]; low: EventEntry[]; all: EventEntry[]; count: number } | null>;

// ─── SaaS Metrics Benchmarks (v2.90.0) ──────────────────────────────────

/** Benchmark metric definition */
interface BenchmarkMetric {
    label: string;
    unit: string;
    p25: number;
    p50: number;
    p75: number;
    p90: number;
    direction: 'higher_better' | 'lower_better';
    category: string;
}

/** Benchmark comparison result for a single metric */
interface BenchmarkComparison {
    metric: string;
    label: string;
    unit: string;
    value: number;
    grade: string;
    percentile: number;
    gap: number;
    direction: string;
    benchmarks: { p25: number; p50: number; p75: number; p90: number };
    category: string;
}

/** Benchmark batch comparison result */
interface BenchmarkBatchResult {
    results: Record<string, BenchmarkComparison>;
    summary: {
        total: number;
        excellent: number;
        good: number;
        average: number;
        poor: number;
        overall_score: number;
        overall_grade: string;
        strongest: string | null;
        weakest: string | null;
    };
}

/** Benchmark report card */
interface BenchmarkReportCard {
    score: number;
    grade: string;
    metrics: Record<string, {
        metric: string;
        label: string;
        value: number;
        unit: string;
        grade: string;
        percentile: number;
        gap: number;
        recommendation: string;
    }>;
    priorities: string[];
    summary: string;
}

/** Quick-start benchmark target */
interface BenchmarkQuickStartMetric {
    metric: string;
    label: string;
    target: number;
    unit: string;
    category: string;
}

/** Fetch all available benchmark metrics with optional category filter */
export function fetchBenchmarks(options?: { category?: string }): Promise<{
    status: string;
    version: string;
    total: number;
    categories: string[];
    by_category: Record<string, string[]>;
    benchmarks: Record<string, BenchmarkMetric>;
} | null>;

/** Fetch benchmark thresholds for a specific metric */
export function fetchBenchmark(metric: string): Promise<{
    status: string;
    version: string;
    metric: string;
    label: string;
    unit: string;
    p25: number;
    p50: number;
    p75: number;
    p90: number;
    direction: string;
    category: string;
} | null>;

/** Compare metrics against industry benchmarks */
export function compareBenchmarks(metrics: Record<string, number>): Promise<BenchmarkBatchResult | null>;

/** Get full benchmark report card with grades and recommendations */
export function fetchBenchmarkReportCard(metrics: Record<string, number>): Promise<BenchmarkReportCard | null>;

/** Fetch quick-start benchmark targets for new SaaS products */
export function fetchBenchmarkQuickStart(): Promise<{
    status: string;
    version: string;
    metrics: BenchmarkQuickStartMetric[];
    summary: {
        enabled: boolean;
        total_metrics: number;
        categories: string[];
        industry: string;
        version: string;
    };
} | null>;

// ─── Server-Sent Events ──────────────────────────────────

/** SSE server capability info */
export interface SSEInfo {
    status: string;
    version: string;
    sse: {
        supported: boolean;
        endpoint: string;
        max_connection_seconds: number;
        min_heartbeat_seconds: number;
        default_heartbeat_seconds: number;
        max_heartbeat_seconds: number;
        supports_cursor_resume: boolean;
        supports_filtering: boolean;
        supports_category_filter: boolean;
    };
    buffer: {
        size: number;
        current_count: number;
        cursor: number;
    };
}

/** SSE health check response */
export interface SSEHealth {
    status: string;
    sse: string;
    buffer_utilization: number;
}

/** SSE event data from the stream */
export interface SSEEventData {
    id: number;
    event: string;
    params: Record<string, unknown>;
    client_id: string | null;
    user_id: string | null;
    provider: string | null;
    timestamp: string;
    dispatched: boolean;
}

/** SSE heartbeat message */
export interface SSEHeartbeat {
    cursor: number;
    timestamp: string;
}

/** SSE close message */
export interface SSEClose {
    reason: string;
    cursor: number;
}

/** SSE event callback types */
export type SSEEventCallback = (data: SSEEventData) => void;
export type SSEHeartbeatCallback = (data: SSEHeartbeat) => void;
export type SSECloseCallback = (data: SSEClose) => void;

/** Options for connecting to the SSE stream */
export interface SSEConnectOptions {
    /** Resume from cursor (default: 0) */
    cursor?: number;
    /** Event name filter (supports * wildcard) */
    filter?: string;
    /** Category filter (ecommerce|saas|engagement) */
    category?: string;
    /** Heartbeat interval in seconds (default: 30) */
    heartbeat?: number;
    /** Callback for event messages */
    onEvent?: SSEEventCallback;
    /** Callback for heartbeat messages */
    onHeartbeat?: SSEHeartbeatCallback;
    /** Callback when the stream closes */
    onClose?: SSECloseCallback;
    /** Callback on connection errors */
    onError?: (error: Event) => void;
}

/** SSE connection handle (returned by connectSSE) */
export interface SSEConnection {
    /** Close the SSE connection */
    close: () => void;
    /** Whether the connection is currently active */
    active: boolean;
}

/** Fetch SSE endpoint capability info */
export function fetchSSEInfo(): Promise<SSEInfo | null>;

/** Fetch SSE health check */
export function fetchSSEHealth(): Promise<SSEHealth | null>;

/**
 * Connect to the real-time SSE analytics stream.
 *
 * Opens a persistent HTTP connection that receives events as they occur.
 * Returns a connection handle with a close() method.
 *
 * @param options - Connection options including callbacks
 * @returns SSE connection handle
 *
 * @example
 * const conn = connectSSE({
 *     onEvent: (data) => console.log('Event:', data.event, data.params),
 *     onHeartbeat: (data) => console.log('Heartbeat:', data.cursor),
 *     onClose: (data) => console.log('Stream closed:', data.reason),
 *     filter: 'purchase*',
 * });
 *
 * // Later: close the connection
 * conn.close();
 */
export function connectSSE(options?: SSEConnectOptions): SSEConnection;

// ─── Feature Adoption ──────────────────────────────────────

/** Feature adoption profile for a user */
export interface FeatureAdoptionProfile {
    total_features: number;
    features: Record<string, {
        first_used: string;
        last_used: string;
        use_count: number;
        context: Record<string, unknown>;
    }>;
    streaks: Record<string, string[]>;
    last_activity: string | null;
}

/** Feature adoption funnel step */
export interface FeatureAdoptionFunnelStep {
    feature: string;
    adopted: number;
    adoption_rate: number;
    drop_off: number;
}
