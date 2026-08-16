/**
 * ZeroBoiler Analytics — TypeScript Type Definitions
 *
 * Comprehensive type definitions for the ZeroBoiler Analytics JS client library.
 * Provides full IntelliSense support for Svelte, Vue, React, and vanilla TS projects.
 *
 * @package ZeroBoiler Analytics
 * @version 195.0.0
 */

// ─── Inertia Page Props ───────────────────────────────────────────────

/**
 * Analytics configuration injected into Inertia page props via HandleInertiaAnalytics middleware.
 * Available as `page.props.zbAnalytics` in Svelte/Vue/React components.
 */
export interface ZbAnalyticsProps {
  enabled: boolean;
  consent: ConsentState;
  trackingId: string;
  userId: string | null;
  ga4MeasurementId?: string;
  gtmContainerId?: string;
  metaPixelId?: string;
  plausibleDomain?: string;
  posthogHost?: string;
  amplitudeApiKey?: string;
  mixpanelToken?: string;
  tiktokPixelId?: string;
  linkedinPartnerId?: string;
  trackLinks: TrackLinksConfig;
  device: DeviceContext;
  apiBase: string;
  apiEnabled: boolean;
  consentPurposes: Record<string, ConsentPurpose>;
  debug: boolean;
  autoTrack: AutoTrackConfig;
  performance: PerformanceConfig;
  ecommerce: EcommerceConfig;
  consentLogEnabled: boolean;
  consentVersion: string;
  version: string;
  subscriptionTiers: Record<string, SubscriptionTier>;
  identityAutoLink: boolean;
  authStateChanged?: boolean;
  previousUserId?: string | null;
  maturity: { score: number; grade: string };
  onboarding: { completion: number; gaps: string[] };
  funnelReadiness: {
    signup: number;
    purchase: number;
    subscription: number;
    overall: number;
  };
  recommendedEvents: RecommendedEvent[];
  dedup: DedupConfig;
  sampling: SamplingConfig;
  geolocation: GeolocationConfig;
  regionalConsent: RegionalConsentConfig;
  crossDomain: CrossDomainConfig;
  sessionRecording: SessionRecordingConfig;
  observability: ObservabilityConfig;
}

export interface ConsentState {
  ad_storage: ConsentValue;
  analytics_storage: ConsentValue;
  ad_user_data: ConsentValue;
  ad_personalization: ConsentValue;
  functionality_storage: ConsentValue;
  security_storage: ConsentValue;
}

export type ConsentValue = 'granted' | 'denied';

export interface TrackLinksConfig {
  enabled: boolean;
  trackExternal: boolean;
  trackInternal: boolean;
  externalPrefix: string;
}

export interface DeviceContext {
  userAgent: string;
  ip: string;
  locale: string;
}

export interface ConsentPurpose {
  label: string;
  required: boolean;
  default: boolean;
}

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

export interface EcommerceConfig {
  currency: string;
  brand: string;
  taxBehavior: string;
  shippingDefault: number;
}

export interface SubscriptionTier {
  name?: string;
  price?: number;
  billing_cycle?: string;
  features?: string[];
}

export interface RecommendedEvent {
  name: string;
  category?: string;
  priority: string;
}

export interface DedupConfig {
  enabled: boolean;
  windowSeconds: number;
}

export interface SamplingConfig {
  enabled: boolean;
  rate: number;
  deterministic: boolean;
}

export interface GeolocationConfig {
  enabled: boolean;
  strategy: string;
}

export interface RegionalConsentConfig {
  enabled: boolean;
  gdprDefault: string;
}

export interface CrossDomainConfig {
  enabled: boolean;
  domains: string[];
  linkerParam: string;
  autoLinker: boolean;
}

export interface SessionRecordingConfig {
  enabled: boolean;
  providers: Record<string, { enabled: boolean; config: Record<string, unknown> }>;
  maskPii: boolean;
  maskSelectors: string[];
  blockSelectors: string[];
  consentAware: boolean;
  excludedPatterns: string[];
}

// ─── Core Functions ─────────────────────────────────────────────────────

/**
 * Initialize the analytics library with Inertia page props.
 */
export function init(pageProps: Record<string, unknown>): void;

/**
 * Cleanup analytics listeners and timers.
 */
export function destroy(): void;

/**
 * Check if analytics is initialized.
 */
export function isInitialized(): boolean;

/**
 * Get the library version string.
 */
export function getVersion(): string;

/**
 * Get the current tracking ID (server-generated, cookie-stored).
 */
export function getTrackingId(): string | null;

/**
 * Get the client ID (alias for getTrackingId — standard analytics SDK name).
 */
export function getClientId(): string | null;

/**
 * Set the client ID (override the server-generated tracking ID).
 *
 * Use this when you have an external client ID management system
 * or need to restore a previously persisted client ID.
 */
export function setClientId(clientId: string): void;

/**
 * Reset the analytics client to its initial state.
 *
 * Clears all internal state: tracking ID, config, queues, timers,
 * consent state, and initialization flag. Useful for test isolation,
 * multi-tenant session switching, or full logout / data erasure flows.
 *
 * After calling reset(), you must call init() or initFullStack() again.
 */
export function reset(): void;

/**
 * Get the configured API base URL.
 */
export function getApiBaseUrl(): string;

// ─── Debounced & Throttled Tracking (v16.0.0) ─────────────────────────

/**
 * Track an event with debouncing — only fires once the caller stops
 * invoking for the specified delay.
 */
export function trackDebounced(
  name: string,
  params?: Record<string, unknown>,
  options?: { delay?: number; immediate?: boolean },
): void;

/**
 * Track an event with throttling — fires at most once per interval.
 */
export function trackThrottled(
  name: string,
  params?: Record<string, unknown>,
  options?: { interval?: number; trailing?: boolean },
): void;

/**
 * Clear all debounce and throttle timers.
 */
export function clearDebounceAndThrottleTimers(): void;

// ─── Event Tracking ────────────────────────────────────────────────────

/**
 * Track a custom event to the server-side API.
 * Events are queued and flushed in batches for performance.
 */
export function trackEvent(
  name: string,
  params?: Record<string, unknown>,
  options?: TrackEventOptions,
): Promise<void>;

export interface TrackEventOptions {
  immediate?: boolean;
}

/**
 * Track an event with an explicit priority override.
 */
export function trackEventWithPriority(
  name: string,
  params?: Record<string, unknown>,
  priority?: PriorityLevel,
): Promise<boolean>;

export type PriorityLevel = 'critical' | 'normal' | 'low' | 'background';

/**
 * Flush the batch event queue.
 */
export function flushQueue(): Promise<void>;

/**
 * Track a page view event.
 */
export function trackPageView(
  title?: string,
  location?: string,
  referrer?: string,
): Promise<void>;

/**
 * Track a screen view event (for SPA navigation).
 */
export function trackScreenView(
  screenName: string,
  options?: ScreenViewOptions,
): Promise<void>;

export interface ScreenViewOptions {
  screenClass?: string;
  params?: Record<string, unknown>;
}

/**
 * Track an A/B test exposure event.
 */
export function trackAbTestExposure(
  experimentId: string,
  variantId: string,
  params?: Record<string, unknown>,
): Promise<void>;

// ─── E-Commerce Tracking ───────────────────────────────────────────────

/**
 * Track an e-commerce event (GA4 + Meta format).
 */
export function trackEcommerce(
  name: string,
  data?: EcommerceEventData,
): Promise<void>;

export interface EcommerceEventData {
  transaction_id?: string;
  value?: number;
  currency?: string;
  items?: EcommerceItem[];
  coupon?: string;
  shipping?: number;
  tax?: number;
  [key: string]: unknown;
}

export interface EcommerceItem {
  item_id?: string;
  item_name?: string;
  item_brand?: string;
  item_category?: string;
  item_variant?: string;
  price?: number;
  quantity?: number;
  index?: number;
  item_list_id?: string;
  item_list_name?: string;
  [key: string]: unknown;
}

/**
 * Track an event targeting specific providers.
 */
export function trackEventWithProviders(
  name: string,
  params?: Record<string, unknown>,
  providers?: string[],
  options?: TrackEventOptions,
): Promise<void>;

/**
 * Track an e-commerce event targeting specific providers.
 */
export function trackEcommerceWithProviders(
  name: string,
  data?: EcommerceEventData,
  providers?: string[],
): Promise<void>;

/**
 * Track add-to-wishlist event.
 */
export function trackWishlist(item: EcommerceItem): Promise<void>;

// ─── High-Level E-Commerce Shorthands (v8.6.0) ───────────────────

/**
 * Track a purchase event with full e-commerce data across all providers.
 */
export function trackPurchase(order: PurchaseData): Promise<void>;

export interface PurchaseData {
  transaction_id: string;
  value: number;
  currency?: string;
  coupon?: string;
  shipping?: number;
  tax?: number;
  items?: EcommerceItem[];
}

/**
 * Track a refund event.
 */
export function trackRefund(refund: RefundData): Promise<void>;

export interface RefundData {
  transaction_id: string;
  value?: number;
  currency?: string;
  items?: EcommerceItem[];
}

/**
 * Track a view item event (product detail page view).
 */
export function trackViewItem(item: EcommerceItem): Promise<void>;

/**
 * Track an add to cart event.
 */
export function trackAddToCart(item: EcommerceItem): Promise<void>;

/**
 * Track a remove from cart event.
 */
export function trackRemoveFromCart(item: EcommerceItem): Promise<void>;

/**
 * Track a begin checkout event.
 */
export function trackBeginCheckout(checkout: CheckoutData): Promise<void>;

export interface CheckoutData {
  value: number;
  items?: EcommerceItem[];
  currency?: string;
  coupon?: string;
}

/**
 * Track select item event.
 */
export function trackSelectItem(
  items?: EcommerceItem[],
  itemListId?: string,
  itemListName?: string,
): Promise<void>;

/**
 * Track promotion view event.
 */
export function trackPromotionView(promotion?: PromotionData): Promise<void>;

export interface PromotionData {
  promotion_id?: string;
  promotion_name?: string;
  creative_name?: string;
  creative_slot?: string;
  items?: EcommerceItem[];
  [key: string]: unknown;
}

/**
 * Track promotion click event.
 */
export function trackPromotionClick(promotion?: PromotionData): Promise<void>;

/**
 * Track checkout step event.
 */
export function trackCheckoutStep(options: {
  stepIndex: number;
  stepName: string;
  paymentMethod?: string;
  orderTotal?: number;
  currency?: string;
}): Promise<void>;

// ─── Identity & Consent ────────────────────────────────────────────────

/**
 * Link a client ID to a user.
 */
export function identify(userId?: string | null): Promise<void>;

/**
 * Update consent signals (GDPR Consent Mode v2).
 */
export function updateConsent(signals: Partial<ConsentState>): Promise<void>;

/**
 * Grant full consent.
 */
export function consentGranted(): void;

/**
 * Deny all optional consent.
 */
export function consentDenied(): void;

/**
 * Get current consent state.
 */
export function getConsentState(): ConsentState | null;

/**
 * Get consent pre-queue count.
 */
export function getConsentPreQueueCount(): number;

/**
 * Reset consent state.
 */
export function resetConsentState(): void;

/**
 * Get consent purposes from server config.
 */
export function getConsentPurposes(): Record<string, ConsentPurpose> | null;

/**
 * Get consent purpose keys.
 */
export function getConsentPurposeKeys(): string[];

/**
 * Get optional consent purposes.
 */
export function getOptionalConsentPurposes(): Record<string, ConsentPurpose> | null;

/**
 * Build consent signals from purpose grants.
 */
export function buildConsentSignals(
  grants: Record<string, boolean>,
): ConsentState;

/**
 * Set user properties for identity resolution.
 */
export function setUserProperties(
  properties: Record<string, unknown>,
  userId?: string | null,
): Promise<void>;

/**
 * Alias an old identity to a new one.
 */
export function alias(previousId: string, newId: string): Promise<void>;

/**
 * Identify with traits (user properties merge).
 */
export function identifyWithTraits(
  traits: Record<string, unknown>,
): Promise<void>;

// ─── SaaS Event Tracking ───────────────────────────────────────────────

/**
 * Track a SaaS event with structured data.
 */
export function trackSaaSEvent(
  event: string,
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Track trial conversion event.
 */
export function trackTrialConversion(
  data: { planName?: string; trialDays?: number; revenue?: number; currency?: string },
): Promise<void>;

/**
 * Track subscription resumed event.
 */
export function trackSubscriptionResumed(
  data: { planName?: string; billingCycle?: string; currency?: string },
): Promise<void>;

/**
 * Track milestone reached event.
 */
export function trackMilestone(
  milestone: string,
  options?: Record<string, unknown>,
): Promise<void>;

/**
 * Track a subscription event (created, renewed, cancelled, etc.).
 */
export function trackSubscriptionEvent(options: {
  action: string;
  planName?: string;
  planPrice?: number;
  billingCycle?: string;
  currency?: string;
  reason?: string;
  meta?: Record<string, unknown>;
}): Promise<void>;

/**
 * Track a trial state event.
 */
export function trackTrialEvent(options: {
  state: string;
  planName?: string;
  trialDays?: number;
  daysUsed?: number;
}): Promise<void>;

/**
 * Track a revenue event.
 */
export function trackRevenueEvent(options: {
  type: string;
  amount: number;
  currency?: string;
  planName?: string;
  invoiceId?: string;
  paymentMethod?: string;
  failureReason?: string;
}): Promise<void>;

/**
 * Track a plan change event.
 */
export function trackPlanChange(options: {
  fromPlan: string;
  toPlan: string;
  fromPrice?: number;
  toPrice?: number;
  currency?: string;
  reason?: string;
}): Promise<void>;

// ─── Auto-Tracking Initializers ────────────────────────────────────────

/**
 * Initialize scroll depth tracking (25%, 50%, 75%, 90%).
 */
export function initScrollDepth(): void;

/**
 * Initialize Inertia page view tracking.
 */
export function initInertiaPageViewTracker(options?: {
  scrollDepth?: boolean;
}): void;

/**
 * Initialize Inertia page view tracking with Svelte page store integration.
 * Combines page view tracking, scroll depth reset, and session tracking.
 */
export function initSveltePageTracker(options?: {
  page?: unknown;
  scrollDepth?: boolean;
  sessionTracking?: boolean;
  onPageView?: (url: string) => void;
}): () => void;

/**
 * Initialize form tracking (start/submit).
 */
export function initFormTracking(options?: {
  selector?: string;
  params?: Record<string, unknown>;
}): void;

/**
 * Initialize JS error tracking.
 */
export function initErrorTracking(options?: {
  captureErrors?: boolean;
  captureUnhandled?: boolean;
  source?: string;
  ignorePatterns?: string[];
}): void;

/**
 * Initialize Core Web Vitals tracking.
 */
export function initWebVitals(options?: {
  onMetric?: (metric: WebVitalMetric) => void;
  sendToServer?: boolean;
}): void;

/**
 * Initialize element visibility tracking using IntersectionObserver (v27.0.0).
 *
 * Tracks elements with data-zb-track="visibility".
 *
 * @returns Cleanup function to disconnect the observer.
 */
export function initElementVisibilityTracker(options?: {
  threshold?: number;
  rootMargin?: string;
  trackOnce?: boolean;
  selector?: string;
}): () => void;

/**
 * Initialize text copy tracking (v27.0.0).
 *
 * Fires copy_text events when users copy text from elements with
 * data-zb-track="copy".
 *
 * @returns Cleanup function to remove event listener.
 */
export function initCopyTracking(options?: {
  maxLength?: number;
  selector?: string;
}): () => void;

/**
 * Initialize element hover tracking (v27.0.0).
 *
 * Fires hover events when users hover over elements with
 * data-zb-track="hover" for a minimum duration.
 *
 * @returns Cleanup function to remove event listeners.
 */
export function initHoverTracking(options?: {
  minDurationMs?: number;
  selector?: string;
}): () => void;

export interface WebVitalMetric {
  name: string;
  value: number;
  rating: 'good' | 'needs-improvement' | 'poor';
  delta: number;
  id: string;
  navigationType?: string;
}

/**
 * Initialize session tracking with idle detection.
 */
export function initSessionTracking(options?: {
  idleTimeout?: number;
  heartbeat?: number;
}): void;

/**
 * Initialize link click tracking.
 */
export function initLinkTracking(options?: {
  trackExternal?: boolean;
  trackInternal?: boolean;
  externalPrefix?: string;
  selector?: string;
}): void;

/**
 * Initialize file download tracking.
 */
export function initFileDownloadTracking(options?: {
  selector?: string;
  extensions?: string[];
}): void;

/**
 * Initialize heatmap click tracking.
 */
export function initHeatmapTracking(options?: {
  sampleRate?: number;
  maxPerUrl?: number;
  ignoreElements?: string[];
}): void;

/**
 * Initialize all auto-trackers in one call.
 */
export function initAll(
  pageProps: Record<string, unknown>,
  options?: {
    pageViews?: boolean;
    scrollDepth?: boolean;
    formTracking?: boolean;
    errorTracking?: boolean;
    linkTracking?: boolean;
    sessionTracking?: boolean;
    webVitals?: boolean;
    fileDownloads?: boolean;
    heatmap?: boolean;
  },
): void;

/**
 * Destroy all auto-trackers.
 */
export function destroyAll(): void;

// ─── Engagement Tracking ───────────────────────────────────────────────

/**
 * Track a search event.
 */
export function trackSearch(
  searchTerm: string,
  options?: {
    resultsCount?: number;
    category?: string;
    params?: Record<string, unknown>;
  },
): Promise<void>;

/**
 * Track a share event.
 */
export function trackShare(
  method: string,
  contentType: string,
  contentId?: string | null,
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Track a file download event.
 */
export function trackFileDownload(
  file: { url: string; name?: string; extension?: string; size?: number },
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Track a video play event.
 */
export function trackVideoPlay(
  video: { url?: string; title?: string; duration?: number },
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Track a notification event.
 */
export function trackNotification(
  type: string,
  action: string,
  notificationId?: string | null,
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Track an outbound click event.
 */
export function trackOutboundClick(
  url: string,
  options?: {
    linkText?: string;
    linkElement?: string;
    params?: Record<string, unknown>;
  },
): Promise<void>;

/**
 * Track an ad click event.
 */
export function trackAdClick(options: {
  platform: string;
  campaignId: string;
  adGroupId: string;
  creativeId: string;
  placement: string;
  keyword?: string;
  cost?: number;
}): Promise<void>;

/**
 * Track AI agent / automation tool access events (v90.0.0).
 */
export function trackAiAgentAccess(options: {
  agent: string;
  action: string;
  resource?: string;
  outcome?: string;
  sessionId?: string;
}): Promise<void>;

/**
 * Track content engagement event.
 */
export function trackContentEngagement(options: {
  contentType: string;
  contentId: string;
  title?: string;
  author?: string;
  category?: string;
  engagementPercent?: number;
  timeSpentSeconds?: number;
  completed?: boolean;
}): Promise<void>;

/**
 * Track onboarding step event.
 */
export function trackOnboardingStep(options: {
  stepName: string;
  stepIndex: number;
  totalSteps: number;
  method?: string;
  completed?: boolean;
  durationSeconds?: number;
  skippedReason?: string;
}): Promise<void>;

/**
 * Track feature impression event.
 */
export function trackFeatureImpression(options: {
  featureName: string;
  location?: string;
  source?: string;
  variant?: string;
  context?: Record<string, unknown>;
}): Promise<void>;

/**
 * Track performance metric event.
 */
export function trackPerformance(
  metricName: string,
  value: number,
  params?: Record<string, unknown>,
): Promise<void>;

// ─── Session Tracking ──────────────────────────────────────────────────

/**
 * Start session heartbeat.
 */
export function initSessionHeartbeat(intervalSeconds?: number): void;

/**
 * Stop session heartbeat.
 */
export function stopSessionHeartbeat(): void;

/**
 * Check if heartbeat is active.
 */
export function isHeartbeatActive(): boolean;

/**
 * Record a session event.
 */
export function recordSessionEvent(): void;

/**
 * Record a session page view.
 */
export function recordSessionPageView(): void;

/**
 * Get session state.
 */
export function getSessionState(): {
  sessionId: string | null;
  isActive: boolean;
  startedAt: number | null;
  lastActivity: number | null;
  pageViews: number;
  events: number;
};

// ─── Timing ────────────────────────────────────────────────────────────

/**
 * Track timing of an operation. Returns an end() function.
 */
export function trackTiming(name: string): { end: (params?: Record<string, unknown>) => Promise<void> };

// ─── GTM Data Layer ─────────────────────────────────────────────────────

/**
 * Push data to the GTM data layer.
 */
export function pushToDataLayer(data: Record<string, unknown>): void;

// ─── UTM & Attribution ────────────────────────────────────────────────

/**
 * Capture UTM parameters from the current URL.
 */
export function captureUTM(): Record<string, string>;

/**
 * Get captured UTM parameters.
 */
export function getUTMParams(): Record<string, string>;

/**
 * Clear captured UTM parameters.
 */
export function clearUTMParams(): void;

/**
 * Check if UTM parameters are present.
 */
export function hasUTMParams(): boolean;

/**
 * Get first-touch UTM (persisted in cookie).
 */
export function getFirstTouchUTM(): Record<string, string> | null;

/**
 * Get full attribution context (first-touch + current UTM).
 */
export function getAttributionContext(): {
  firstTouch: Record<string, string> | null;
  current: Record<string, string>;
};

/**
 * Clear first-touch UTM cookie.
 */
export function clearFirstTouchUTM(): void;

// ─── Tracking Preferences & GDPR ─────────────────────────────────────────

/**
 * Opt out of all analytics tracking.
 */
export function optOutTracking(): Promise<void>;

/**
 * Opt in to analytics tracking.
 */
export function optInTracking(): Promise<void>;

/**
 * Get tracking preference status.
 */
export function getTrackingPreference(): Promise<{
  optedOut: boolean;
  optedOutAt: string | null;
  optedInAt: string | null;
}>;

// ─── Data Portability ──────────────────────────────────────────────────

/**
 * Track a data export action.
 */
export function trackExport(
  format: string,
  options?: {
    resource?: string;
    recordCount?: number;
    params?: Record<string, unknown>;
  },
): Promise<void>;

/**
 * Track a data import action.
 */
export function trackImport(
  format: string,
  options?: {
    resource?: string;
    recordCount?: number;
    success?: boolean;
    params?: Record<string, unknown>;
  },
): Promise<void>;

// ─── Server-Side API Calls ─────────────────────────────────────────────

/**
 * Send a server-side page view.
 */
export function trackServerPageView(options?: {
  title?: string;
  location?: string;
  referrer?: string;
}): Promise<void>;

// ─── Performance Budget ────────────────────────────────────────────────

/**
 * Check if performance budget is configured.
 */
export function hasPerformanceBudget(): boolean;

/**
 * Get performance budget config.
 */
export function getPerformanceBudget(): {
  maxPayloadBytes: number;
  maxEventsPerMinute: number;
  maxBatchSize: number;
} | null;

/**
 * Estimate event payload size in bytes.
 */
export function estimatePayloadSize(name: string, params?: Record<string, unknown>): number;

/**
 * Check if an event exceeds the payload budget.
 */
export function exceedsPayloadBudget(name: string, params?: Record<string, unknown>): boolean;

// ─── Journey Tracking ──────────────────────────────────────────────────

/**
 * Record a journey milestone.
 */
export function trackJourneyMilestone(
  journey: string,
  milestone: string,
  params?: Record<string, unknown>,
): Promise<void>;

/**
 * Get journey progress.
 */
export function getJourneyProgress(journey: string): Promise<Record<string, unknown> | null>;

/**
 * Get all journeys.
 */
export function getAllJourneys(): Promise<Record<string, unknown> | null>;

/**
 * Reset journey progress.
 */
export function resetJourneyProgress(journey: string): Promise<void>;

// ─── Event Forwarding ─────────────────────────────────────────────────

/**
 * Check if event forwarding is enabled.
 */
export function isForwardingEnabled(): boolean;

/**
 * Get forwarder names.
 */
export function getForwarderNames(): string[];

// ─── Event Catalog ──────────────────────────────────────────────────────

/**
 * Fetch the full event catalog from the server.
 */
export function fetchEventCatalog(options?: {
  category?: string;
  provider?: string;
}): Promise<CatalogResponse | null>;

export interface CatalogResponse {
  total: number;
  categories: Record<string, CategoryInfo>;
  events: CatalogEvent[];
}

export interface CategoryInfo {
  count: number;
  description?: string;
}

export interface CatalogEvent {
  name: string;
  category: string;
  ga4?: string;
  meta?: string;
  posthog?: string;
  plausible?: string;
  description?: string;
  priority?: string;
}

/**
 * Get cached catalog.
 */
export function getCachedCatalog(): CatalogResponse | null;

/**
 * Clear catalog cache.
 */
export function clearCatalogCache(): void;

// ─── SaaS Maturity & Onboarding ────────────────────────────────────────

/**
 * Get SaaS analytics maturity score from Inertia props.
 */
export function getMaturityScore(): { score: number; grade: string };

/**
 * Get onboarding completion status from Inertia props.
 */
export function getOnboardingStatus(): { completion: number; gaps: string[] };

/**
 * Check if SaaS is ready.
 */
export function isSaaSReady(): boolean;

/**
 * Fetch event catalog summary from server.
 */
export function getEventCatalogSummary(): Promise<Record<string, unknown> | null>;

/**
 * Fetch funnel readiness scores from server.
 */
export function getFunnelReadiness(): Promise<Record<string, unknown> | null>;

/**
 * Get funnel readiness from Inertia props (no network call).
 */
export function getFunnelReadinessFromProps(): {
  signup: number;
  purchase: number;
  subscription: number;
  overall: number;
};

/**
 * Get recommended events from Inertia props.
 */
export function getRecommendedEvents(): RecommendedEvent[];

/**
 * Get dedup configuration from Inertia props.
 */
export function getDedupConfig(): DedupConfig | null;

/**
 * Fetch industry-standard instrumentation checklist.
 */
export function getIndustryStandard(): Promise<Record<string, unknown> | null>;

// ─── Benchmarks ────────────────────────────────────────────────────────

/**
 * Fetch all benchmark metrics.
 */
export function fetchBenchmarks(options?: {
  category?: string;
}): Promise<Record<string, unknown> | null>;

/**
 * Fetch a specific benchmark metric.
 */
export function fetchBenchmark(metric: string): Promise<Record<string, unknown> | null>;

/**
 * Compare metrics against benchmarks.
 */
export function compareBenchmarks(
  metrics: Record<string, number>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch benchmark report card.
 */
export function fetchBenchmarkReportCard(
  metrics: Record<string, number>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch quick-start benchmark targets.
 */
export function fetchBenchmarkQuickStart(): Promise<Record<string, unknown> | null>;

// ─── Revenue Forecasting ────────────────────────────────────────────────

/**
 * Fetch revenue forecast.
 */
export function fetchRevenueForecast(
  params?: Record<string, unknown>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch forecast summary.
 */
export function fetchForecastSummary(
  params?: Record<string, unknown>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch revenue projection.
 */
export function fetchRevenueProjection(
  daysOut?: number,
  mrr?: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch LTV calculation.
 */
export function fetchLTV(
  arpu: number,
  churnRate: number,
  grossMargin?: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch LTV:CAC ratio.
 */
export function fetchLTVCACRatio(
  ltv: number,
  cac: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch payback period.
 */
export function fetchPaybackPeriod(
  cac: number,
  arpu: number,
  grossMargin?: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch runway estimate.
 */
export function fetchRunway(
  mrr: number,
  expenses: number,
  growthRate?: number,
  churnRate?: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch cohort retention curve.
 */
export function fetchCohortRetentionCurve(
  months?: number,
  churnRate?: number,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch MRR movement breakdown.
 */
export function fetchMRRMovement(
  params?: Record<string, unknown>,
): Promise<Record<string, unknown> | null>;

// ─── Churn Prediction ───────────────────────────────────────────────────

/**
 * Score churn risk for a single user.
 */
export function scoreChurnRisk(
  userId: string,
  signals?: Record<string, number>,
): Promise<Record<string, unknown> | null>;

/**
 * Score churn risk for a batch of users.
 */
export function scoreChurnBatch(
  users: Array<{ userId: string; signals?: Record<string, number> }>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch churn cohort summary.
 */
export function fetchChurnCohortSummary(
  users: Array<{ userId: string; signals?: Record<string, number> }>,
): Promise<Record<string, unknown> | null>;

/**
 * Fetch churn signal weights.
 */
export function fetchChurnWeights(): Promise<Record<string, unknown> | null>;

/**
 * Fetch churn thresholds.
 */
export function fetchChurnThresholds(): Promise<Record<string, unknown> | null>;

// ─── Conversion Analytics ──────────────────────────────────────────────

/**
 * Fetch conversion summary from server.
 */
export function fetchConversionSummary(): Promise<Record<string, unknown> | null>;

/**
 * Fetch conversion funnel data from server.
 */
export function fetchConversionFunnel(): Promise<Record<string, unknown> | null>;

// ─── Dashboard ──────────────────────────────────────────────────────────

/**
 * Fetch dashboard overview from server.
 */
export function fetchDashboardOverview(): Promise<Record<string, unknown> | null>;

// ─── Heatmap ────────────────────────────────────────────────────────────

/**
 * Record a heatmap click.
 */
export function recordHeatmapClick(
  x: number,
  y: number,
  options?: {
    url?: string;
    element?: string;
    selector?: string;
    params?: Record<string, unknown>;
  },
): Promise<void>;

/**
 * Fetch heatmap data for a URL.
 */
export function fetchHeatmapData(url: string): Promise<Record<string, unknown> | null>;

// ─── Compliance & Health ──────────────────────────────────────────────

/**
 * Fetch deconfliction report.
 */
export function fetchDeconflictionReport(): Promise<Record<string, unknown> | null>;

/**
 * Fetch inferred schemas.
 */
export function fetchInferredSchemas(options?: {
  eventName?: string;
}): Promise<Record<string, unknown> | null>;

/**
 * Fetch rate limit dashboard.
 */
export function fetchRateLimitDashboard(): Promise<Record<string, unknown> | null>;

/**
 * Fetch circuit breaker dashboard.
 */
export function fetchCircuitBreakerDashboard(): Promise<Record<string, unknown> | null>;

/**
 * Fetch circuit breaker summary.
 */
export function fetchCircuitBreakerSummary(): Promise<Record<string, unknown> | null>;

/**
 * Fetch compliance report.
 */
export function fetchComplianceReport(): Promise<Record<string, unknown> | null>;

/**
 * Fetch compliance score.
 */
export function fetchComplianceScore(): Promise<Record<string, unknown> | null>;

/**
 * Fetch recovery budget.
 */
export function fetchRecoveryBudget(): Promise<Record<string, unknown> | null>;

/**
 * Fetch recovery health.
 */
export function fetchRecoveryHealth(): Promise<Record<string, unknown> | null>;

/**
 * Fetch recovery history.
 */
export function fetchRecoveryHistory(): Promise<Record<string, unknown> | null>;

// ─── Data Warehouse ────────────────────────────────────────────────────

/**
 * Export analytics data to data warehouse.
 */
export function exportToDataWarehouse(options?: {
  format?: string;
  events?: string[];
  startDate?: string;
  endDate?: string;
}): Promise<Record<string, unknown> | null>;

// ─── SSE (Server-Sent Events) ──────────────────────────────────────────

/**
 * Fetch SSE endpoint info.
 */
export function fetchSSEInfo(): Promise<Record<string, unknown> | null>;

/**
 * Fetch SSE health status.
 */
export function fetchSSEHealth(): Promise<Record<string, unknown> | null>;

/**
 * Connect to real-time SSE stream.
 */
export function connectSSE(options?: SSEConnectOptions): SSEConnection;

export interface SSEConnectOptions {
  cursor?: number;
  filter?: string;
  category?: string;
  heartbeat?: number;
  onEvent?: (data: Record<string, unknown>) => void;
  onHeartbeat?: (data: Record<string, unknown>) => void;
  onClose?: (data: Record<string, unknown>) => void;
  onError?: (e: Event) => void;
}

export interface SSEConnection {
  close: () => void;
  readonly active: boolean;
}

// ─── Quick-Start Events ────────────────────────────────────────────────

/**
 * Fetch the quick-start event set from the server.
 */
export function getQuickStartEvents(): Promise<Record<string, unknown> | null>;

// ─── Svelte Helpers ────────────────────────────────────────────────────

/**
 * Initialize Svelte-specific tracker with auto-bindings.
 */
export function initSvelteTracker(
  pageProps: Record<string, unknown>,
  options?: Record<string, unknown>,
): void;

// ─── Internal (Diagnostic) ───────────────────────────────────────────────

/**
 * @internal Get internal version string for diagnostics.
 */
export function _getInternalVersion(): string;

// ─── Config Export (v6.6.0) ──────────────────────────────────────────

/**
 * Fetch the full analytics configuration export (secrets redacted).
 */
export function fetchConfigExport(): Promise<Record<string, unknown> | null>;

/**
 * Fetch the provider/feature status summary.
 */
export function fetchConfigStatus(): Promise<Record<string, unknown> | null>;

/**
 * Fetch a single config section (secrets redacted).
 */
export function fetchConfigSection(section: string): Promise<Record<string, unknown> | null>;

/**
 * Get the geolocation enrichment status from Inertia props.
 */
export function getGeolocationStatus(): GeolocationConfig;

/**
 * Get the sampling configuration from Inertia props.
 */
export function getSamplingConfig(): SamplingConfig;

/**
 * Get the regional consent detection status from Inertia props.
 */
export function getRegionalConsentStatus(): RegionalConsentConfig;

/**
 * Event Sparkline Data type (v7.2.0).
 */
export interface SparklineData {
  event: string;
  data: number[];
  min: number;
  max: number;
  avg: number;
  trend: 'up' | 'down' | 'flat';
  points: number;
}

/**
 * Co-occurrence pair type (v7.2.0).
 */
export interface CooccurrencePair {
  event_a: string;
  event_b: string;
  count: number;
  correlation: number;
}

/**
 * Fetch sparkline data for a single event (v7.2.0).
 */
export function fetchEventSparkline(eventName: string, points?: number, period?: number): Promise<SparklineData | null>;

/**
 * Fetch sparkline data for multiple events (v7.2.0).
 */
export function fetchEventSparklines(events: string[], points?: number, period?: number): Promise<Record<string, SparklineData> | null>;

/**
 * Fetch sparkline dashboard summary (v7.2.0).
 */
export function fetchSparklineDashboard(points?: number): Promise<Record<string, unknown> | null>;

/**
 * Fetch co-occurrence top pairs (v7.2.0).
 */
export function fetchCooccurrenceTopPairs(limit?: number): Promise<CooccurrencePair[] | null>;

/**
 * Fetch events co-occurring with a specific event (v7.2.0).
 */
export function fetchCooccurrenceWith(eventName: string, limit?: number): Promise<Record<string, unknown> | null>;

/**
 * Fetch co-occurrence dashboard summary (v7.2.0).
 */
export function fetchCooccurrenceDashboard(): Promise<Record<string, unknown> | null>;

// ─── Signal Intelligence (v7.7.0) ───────────────────────────────────

/**
 * Signal Intelligence report type.
 */
export interface SignalIntelligenceReport {
  signal_score: number;
  grade: string;
  providers: Record<string, ProviderSignal>;
  categories: Record<string, CategorySignal>;
  anomalies: SignalAnomaly[];
  staleness_summary: { stale: string[]; healthy: string[] };
  signal_to_noise: number;
  dispatch_balance: number;
  recommendations: string[];
  computed_at: string;
}

/**
 * Provider health signal type.
 */
export interface ProviderSignal {
  name: string;
  status: 'healthy' | 'degraded' | 'stale' | 'down';
  events_dispatched: number;
  events_failed: number;
  failure_rate: number;
  last_dispatch_at: string | null;
  staleness_seconds: number | null;
  anomaly_score: number;
  health_decay: number;
}

/**
 * Category coverage signal type.
 */
export interface CategorySignal {
  name: string;
  events: number;
  percentage: number;
  top_events: string[];
  trend: 'stable' | 'rising' | 'falling' | 'flat';
}

/**
 * Signal anomaly type.
 */
export interface SignalAnomaly {
  type: string;
  provider: string | null;
  message: string;
  severity: 'info' | 'warning' | 'critical';
  detected_at: string;
  context: Record<string, unknown>;
}

/**
 * Fetch full signal intelligence report (v7.7.0).
 */
export function fetchSignalReport(): Promise<SignalIntelligenceReport | null>;

/**
 * Fetch composite signal score (v7.7.0).
 */
export function fetchSignalScore(): Promise<{ score: number; grade: string; computed_at: string } | null>;

/**
 * Fetch detected anomalies only (v7.7.0).
 */
export function fetchSignalAnomalies(): Promise<{ anomalies: SignalAnomaly[] } | null>;

/**
 * Fetch provider health signals (v7.7.0).
 */
export function fetchSignalProviders(): Promise<{ providers: Record<string, ProviderSignal> } | null>;

// ─── Offline Event Buffer (v14.0.0) ──────────────────────────────

/**
 * Check if the browser is currently offline.
 */
export function isOffline(): boolean;

/**
 * Save events to the offline buffer (localStorage).
 */
export function saveToOfflineBuffer(events: Record<string, unknown>[]): boolean;

/**
 * Load events from the offline buffer.
 */
export function loadOfflineBuffer(): Record<string, unknown>[];

/**
 * Clear all events from the offline buffer.
 */
export function clearOfflineBuffer(): void;

/**
 * Get offline buffer status (size, event count).
 */
export function offlineBufferStatus(): { eventCount: number; sizeKB: number };

/**
 * Flush the offline buffer by sending all buffered events to the server.
 */
export function flushOfflineBuffer(): Promise<{ sent: number; failed: number }>;

/**
 * Attach online/offline event listeners for automatic buffer management.
 */
export function enableOfflineRecovery(): void;

// ─── Observability Config ──────────────────────────────────────────────

/**
 * Observability configuration for client-side dispatch monitoring.
 * @since v18.0.0
 */
export interface ObservabilityConfig {
  enabled: boolean;
  slowDispatchMs: number;
}

// ─── Product-Market Fit Scoring (v61.0.0) ────────────────────────────────

/**
 * Analytics signals used to compute PMF score.
 */
export interface PMFSignals {
  activation_rate?: number;
  retention_week2?: number;
  feature_depth_score?: number;
  organic_growth_rate?: number;
  nps_proxy?: number;
}

/**
 * PMF score result with grade, breakdown, and recommendations.
 */
export interface PMFScoreResult {
  status: string;
  score: number;
  grade: string;
  grade_label: string;
  breakdown: Record<string, number>;
  signals_received: string[];
  recommendations: string[];
}

/**
 * Compact PMF summary for dashboard display.
 */
export interface PMFSummaryResult {
  status: string;
  pmf_score: number;
  pmf_grade: string;
  pmf_grade_label: string;
  readiness: {
    signals_count: number;
    max_signals: number;
    coverage: number;
  };
  top_signal: string | null;
  weakest_signal: string | null;
}

/**
 * Compute the Product-Market Fit score from analytics signals.
 * POST /api/analytics/pmf/score
 */
export function computePMFScore(signals?: PMFSignals): Promise<PMFScoreResult | null>;

/**
 * Get a compact PMF summary for dashboard display.
 * GET /api/analytics/pmf/summary
 */
export function getPMFSummary(params?: PMFSignals): Promise<PMFSummaryResult | null>;

// ─── First-Value Detection (v61.0.0) ───────────────────────────────────

/**
 * A single milestone in the first-value detection system.
 */
export interface FirstValueMilestone {
  achieved: boolean;
  label: string;
  weight: number;
  category: string;
}

/**
 * First-value score result for a user.
 */
export interface FirstValueScoreResult {
  status: string;
  user_id: string;
  score: number;
  max_score: number;
  percentage: number;
  milestones: Record<string, FirstValueMilestone>;
}

/**
 * Get the first-value achievement score for a user.
 * GET /api/analytics/first-value/score/{userId}
 */
export function getFirstValueScore(userId: string): Promise<FirstValueScoreResult | null>;

/**
 * Reset first-value milestones for a user.
 * POST /api/analytics/first-value/reset/{userId}
 */
export function resetFirstValue(userId: string, milestone?: string): Promise<boolean>;

// ─── Intelligence Gateway (v71.0.0) ─────────────────────────────────────

/**
 * Intelligence dashboard alert.
 */
export interface IntelligenceAlert {
  severity: 'critical' | 'warning' | 'info';
  source: string;
  message: string;
}

/**
 * Provider health entry in the intelligence dashboard.
 */
export interface IntelligenceProviderHealth {
  enabled: boolean;
  configured: boolean;
  healthy: boolean;
  latency_ms?: number | null;
  last_check?: string | null;
}

/**
 * Full intelligence dashboard payload.
 */
export interface IntelligenceDashboard {
  timestamp: string;
  version: string;
  provider_health: {
    providers: Record<string, IntelligenceProviderHealth>;
    enabled_count: number;
    total: number;
  };
  catalog_coverage: {
    total: number;
    by_category: Record<string, number>;
    industry_standard_coverage: number;
    starter_coverage: number;
    essential_coverage: number;
    instrumented: number;
    gap_count: number;
    top_gaps: string[];
  };
  anomaly_summary: {
    enabled: boolean;
    recent_anomalies: number;
    severity_breakdown: Record<string, number>;
    last_checked: string | null;
    status: string;
  };
  funnel_health: {
    signup_to_trial: number | null;
    trial_to_paid: number | null;
    signup_to_paid: number | null;
    status: string;
    events_tracked: string[];
  };
  churn_signals: {
    enabled: boolean;
    risk_level: string;
    signal_events: string[];
    retention_signals_count: number;
    status: string;
  };
  revenue_health: {
    billing_events_tracked: number;
    revenue_events: string[];
    ecommerce_currency: string;
    subscription_tiers: number;
    status: string;
  };
  pipeline_health: {
    queue_enabled: boolean;
    queue_connection: string | null;
    auto_utm: boolean;
    auto_timestamp: boolean;
    sampling_enabled: boolean;
    sampling_rate: number;
    pii_enabled: boolean;
    consent_log_enabled: boolean;
    validation_strict: boolean;
    status: string;
  };
  data_quality: {
    dedup_window: number;
    pii_strategy: string;
    pii_custom_fields: number;
    sampling_deterministic: boolean;
    quality_score: number;
  };
  fallback_status: {
    enabled: boolean;
    providers: Record<string, string>;
    status: string;
  };
  budget_utilization: {
    enabled: boolean;
    utilization_percent: number | null;
    remaining_percent: number | null;
    status: string;
  };
  privacy_compliance: {
    consent_default: string;
    consent_log_enabled: boolean;
    consent_log_ttl: number;
    gdpr_compliant: boolean;
    status: string;
  };
  transformation_status: {
    enabled: boolean;
    cache_ttl: number;
    strict: boolean;
    mapping_count: number;
    status: string;
  };
  overall_score: number;
  overall_grade: string;
  alerts: IntelligenceAlert[];
}

/**
 * Intelligence heartbeat payload for uptime monitoring.
 */
export interface IntelligenceHeartbeat {
  status: 'healthy' | 'degraded' | 'critical';
  version: string;
  timestamp: string;
  enabled_providers: number;
  total_providers: number;
  catalog_events: number;
  score: number;
  grade: string;
}

/**
 * Intelligence gateway request options.
 */
export interface IntelligenceDashboardOptions {
  include?: string[];
  exclude?: string[];
}

/**
 * Intelligence monitor options.
 */
export interface IntelligenceMonitorOptions {
  interval?: number;
  onUpdate?: (heartbeat: IntelligenceHeartbeat) => void;
  onAlert?: (heartbeat: IntelligenceHeartbeat) => void;
  alertThreshold?: number;
}

/**
 * Fetch the full analytics intelligence dashboard payload.
 * GET /api/analytics/intelligence
 */
export function getIntelligenceDashboard(options?: IntelligenceDashboardOptions): Promise<IntelligenceDashboard | null>;

/**
 * Fetch a lightweight analytics heartbeat for uptime monitoring.
 * GET /api/analytics/intelligence/heartbeat
 */
export function getIntelligenceHeartbeat(): Promise<IntelligenceHeartbeat | null>;

/**
 * Start a real-time intelligence monitor with configurable polling.
 * Returns a cleanup function.
 */
export function startIntelligenceMonitor(options?: IntelligenceMonitorOptions): () => void;

// ─── Svelte Composable: useAnalytics ─────────────────────────────────

import { Writable, Readable } from 'svelte/store';

/**
 * Options for the useAnalytics Svelte composable.
 */
export interface UseAnalyticsOptions {
  autoInit?: boolean;
  trackPageViews?: boolean;
  autoIdentify?: boolean;
  autoFlush?: boolean;
  lifecycleAware?: boolean;
}

/**
 * Return type for the useAnalytics Svelte composable.
 */
export interface UseAnalyticsReturn {
  ready: Writable<boolean>;
  trackingId: Writable<string | null>;
  userId: Writable<string | null>;
  authStateChanged: Writable<boolean>;
  init: () => void;
  destroy: () => void;
  track: (name: string, params?: Record<string, unknown>) => Promise<void>;
  trackProviders: (name: string, params?: Record<string, unknown>, providers?: string[]) => Promise<void>;
  trackEcommerce: (name: string, data?: Record<string, unknown>) => Promise<void>;
  trackEcommerceProviders: (name: string, data?: Record<string, unknown>, providers?: string[]) => Promise<void>;
  trackPageView: (title?: string, location?: string, referrer?: string) => Promise<void>;
  trackScreenView: (screenName: string, options?: ScreenViewOptions) => Promise<void>;
  trackDebounced: (name: string, params?: Record<string, unknown>, options?: { delay?: number; immediate?: boolean }) => void;
  trackThrottled: (name: string, params?: Record<string, unknown>, options?: { interval?: number; trailing?: boolean }) => void;
  identify: (userId?: string | null) => Promise<void>;
  updateConsent: (signals: Partial<ConsentState>) => Promise<void>;
  consent: () => ConsentState | null;
  flush: () => Promise<void>;
  version: () => string;
}

/**
 * Main Svelte analytics composable.
 * Provides reactive stores and methods for event tracking,
 * consent management, and identity linking.
 */
export function useAnalytics(options?: UseAnalyticsOptions): UseAnalyticsReturn;

/**
 * Convenience shorthand for useAnalytics with default options.
 */
export function analyticsStore(options?: UseAnalyticsOptions): UseAnalyticsReturn;

// ─── Svelte Composable: useLifecycle ────────────────────────────────

/**
 * Activation score data.
 */
export interface ActivationScoreData {
  score: number;
  grade: string;
  steps: string[];
  signals: string[];
}

/**
 * Churn risk data.
 */
export interface ChurnRiskData {
  riskScore: number;
  riskLevel: 'critical' | 'high' | 'medium' | 'low';
  indicators: string[];
  recommendation: string;
}

/**
 * Funnel progress data.
 */
export interface FunnelProgressData {
  steps: string[];
  completionPct: number;
  currentStep: string | null;
  stepsRemaining: number;
}

/**
 * Feature adoption data.
 */
export interface FeatureAdoptionData {
  featuresUsed: string[];
  adoptionCount: number;
  adoptionDepth: number;
}

/**
 * Session engagement data.
 */
export interface SessionEngagementData {
  sessionCount: number;
  avgSessionsPerDay: number;
  lastLoginAt: number | null;
}

/**
 * Expansion momentum data.
 */
export interface ExpansionMomentumData {
  momentum: number;
  eventCount: number;
  totalValue: number;
}

/**
 * Options for the useLifecycle Svelte composable.
 */
export interface UseLifecycleOptions {
  autoFetch?: boolean;
  reactiveToPageProps?: boolean;
  userId?: string | null;
  refreshIntervalMs?: number;
}

/**
 * Return type for the useLifecycle Svelte composable.
 */
export interface UseLifecycleReturn {
  activationScore: Writable<ActivationScoreData>;
  churnRisk: Writable<ChurnRiskData>;
  funnelProgress: Writable<FunnelProgressData>;
  featureAdoption: Writable<FeatureAdoptionData>;
  sessionEngagement: Writable<SessionEngagementData>;
  expansionMomentum: Writable<ExpansionMomentumData>;
  lifecycleLoaded: Writable<boolean>;
  fetch: (identity?: string | null) => Promise<void>;
  refresh: () => Promise<void>;
  stopAutoRefresh: () => void;
  activationGrade: Readable<string>;
  churnLevel: Readable<string>;
  funnelCompletion: Readable<number>;
  funnelStepNames: string[];
  isActive: (threshold?: number) => boolean;
  isAtRisk: (threshold?: number) => boolean;
}

/**
 * SaaS lifecycle analytics composable.
 * Provides reactive stores for activation scores, churn risk,
 * funnel progress, feature adoption, and session engagement.
 */
export function useLifecycle(options?: UseLifecycleOptions): UseLifecycleReturn;

// ─── Svelte Composable: useAnalyticsConfig ──────────────────────────

/**
 * Reactive analytics configuration from Inertia page props.
 */
export function useAnalyticsConfig(): Readable<{
  enabled: boolean;
  trackingId: string | null;
  userId: string | null;
  ga4MeasurementId: string | null;
  gtmContainerId: string | null;
  metaPixelId: string | null;
  plausibleDomain: string | null;
  posthogHost: string | null;
  amplitudeApiKey: string | null;
  mixpanelToken: string | null;
  tiktokPixelId: string | null;
  linkedinPartnerId: string | null;
  apiBase: string;
  apiEnabled: boolean;
  debug: boolean;
  version: string;
  maturity: { score: number; grade: string } | null;
  onboarding: { completion: number; gaps: string[] } | null;
  consent: Record<string, unknown> | null;
  consentPurposes: Record<string, ConsentPurpose>;
  autoTrack: Record<string, unknown>;
  ecommerce: Record<string, unknown>;
  dedup: Record<string, unknown>;
  sampling: Record<string, unknown>;
  identityAutoLink: boolean;
  recommendedEvents: RecommendedEvent[];
  funnelReadiness: { signup: number; purchase: number; subscription: number; overall: number };
}>;

/**
 * Reactive consent state store.
 */
export function useConsentState(): Readable<ConsentState>;

/**
 * Reactive maturity score store.
 */
export function useMaturity(): Readable<{ score: number; grade: string }>;

/**
 * Reactive funnel readiness store.
 */
export function useFunnelReadiness(): Readable<{
  signup: number;
  purchase: number;
  subscription: number;
  overall: number;
}>;

// ─── Svelte Composable: usePerformanceTracker ────────────────────────

/**
 * Performance tracker return type.
 */
export interface UsePerformanceTrackerReturn {
  webVitals: Writable<Record<string, { value: number; rating: string }>>;
  performanceScore: Writable<{ score: number; rating: string; timestamp: number }>;
  performanceLabel: Readable<string>;
  isTracking: Writable<boolean>;
  start: () => void;
  stop: () => void;
  getMetrics: () => Record<string, { value: number; rating: string }>;
}

/**
 * Performance tracker options.
 */
export interface UsePerformanceTrackerOptions {
  enabled?: boolean;
  autoScore?: boolean;
  scoreDelayMs?: number;
}

/**
 * Svelte composable for Core Web Vitals performance tracking.
 */
export function usePerformanceTracker(options?: UsePerformanceTrackerOptions): UsePerformanceTrackerReturn;

// ─── Lifecycle API Response ───────────────────────────────────────────

/**
 * API response for lifecycle data (GET /api/analytics/lifecycle).
 */
export interface LifecycleApiResponse {
  activation_score?: number;
  activation_steps?: string[];
  churn_risk_score?: number;
  churn_indicators?: string[];
  funnel_progress?: string[];
  funnel_current_step?: string | null;
  features_used?: string[];
  feature_adoption_count?: number;
  feature_adoption_depth?: number;
  session_count?: number;
  avg_sessions_per_day?: number;
  last_login_at?: number | null;
  first_login_at?: number | null;
  expansion_momentum?: number;
  expansion_event_count?: number;
  total_expansion_value?: number;
  updated_at?: number;
}

// ─── SaaS Event Shortcut Helpers (v98.0.0) ───────────────────────────

/**
 * Options for trackSignUp shortcut.
 */
export interface TrackSignUpOptions {
  method?: string;
  extra?: Record<string, unknown>;
}

/**
 * Options for trackTrialStart shortcut.
 */
export interface TrackTrialStartOptions {
  plan?: string;
  durationDays?: number;
  extra?: Record<string, unknown>;
}

/**
 * Options for trackSubscription shortcut.
 */
export interface TrackSubscriptionOptions {
  plan?: string;
  value?: number;
  currency?: string;
  billingCycle?: string;
  extra?: Record<string, unknown>;
}

/**
 * Options for trackPlanUpgrade shortcut.
 */
export interface TrackPlanUpgradeOptions {
  fromPlan?: string;
  toPlan?: string;
  valueDifference?: number;
  extra?: Record<string, unknown>;
}

/**
 * Options for trackCancellation shortcut.
 */
export interface TrackCancellationOptions {
  reason?: string;
  plan?: string;
  lostRevenue?: number;
  extra?: Record<string, unknown>;
}

/**
 * Track a user sign-up event with method attribution.
 */
export function trackSignUp(options?: TrackSignUpOptions): Promise<void>;

/**
 * Track a trial start event with plan and duration.
 */
export function trackTrialStart(options?: TrackTrialStartOptions): Promise<void>;

/**
 * Track a subscription creation event with billing context.
 */
export function trackSubscription(options?: TrackSubscriptionOptions): Promise<void>;

/**
 * Track a plan upgrade event with transition details.
 */
export function trackPlanUpgrade(options?: TrackPlanUpgradeOptions): Promise<void>;

/**
 * Track a subscription cancellation event with reason.
 */
export function trackCancellation(options?: TrackCancellationOptions): Promise<void>;

/**
 * Track a feature usage event.
 */
export function trackFeatureUsed(feature: string, extra?: Record<string, unknown>): Promise<void>;

// ─── SaaS Account Lifecycle Shorthand Types (v101.0.0) ──────────────

/** Options for trackAccountActivated. */
export interface TrackAccountActivatedOptions {
  method?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackAccountDeactivated. */
export interface TrackAccountDeactivatedOptions {
  reason?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackEmailVerified. */
export interface TrackEmailVerifiedOptions {
  method?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackAccountDeleted. */
export interface TrackAccountDeletedOptions {
  reason?: string;
  extra?: Record<string, unknown>;
}

/**
 * Track an account activation event.
 */
export function trackAccountActivated(options?: TrackAccountActivatedOptions): Promise<void>;

/**
 * Track an account deactivation event.
 */
export function trackAccountDeactivated(options?: TrackAccountDeactivatedOptions): Promise<void>;

/**
 * Track an email verification event.
 */
export function trackEmailVerified(options?: TrackEmailVerifiedOptions): Promise<void>;

/**
 * Track an account deletion event.
 */
export function trackAccountDeleted(options?: TrackAccountDeletedOptions): Promise<void>;

/**
 * Track a first value / aha moment event.
 */
export function trackFirstValue(valueEvent: string, extra?: Record<string, unknown>): Promise<void>;

/**
 * Track a growth milestone event.
 */
export function trackGrowthMilestone(milestone: string, extra?: Record<string, unknown>): Promise<void>;

// ─── Unified Category Dispatchers (v126.0.0) ──────────────────────────

/**
 * Track an e-commerce event by name (client-side).
 * Resolves against the known catalog and auto-computes value for single-item events.
 */
export function trackEcommerceEvent(
  eventName: string,
  params?: Record<string, unknown>,
  options?: { currency?: string; value?: number; transaction_id?: string; items?: Record<string, unknown>[] },
): Promise<boolean>;

/**
 * Track a SaaS lifecycle event by name (client-side).
 */
export function trackSaaSLifecycle(
  eventName: string,
  params?: Record<string, unknown>,
): Promise<boolean>;

/**
 * Track an engagement event by name (client-side).
 */
export function trackEngagement(
  eventName: string,
  params?: Record<string, unknown>,
): Promise<boolean>;

/**
 * Track any event from any category (client-side cross-category dispatcher).
 */
export function trackByCategory(
  eventName: string,
  params?: Record<string, unknown>,
): Promise<boolean>;

// ─── Session Replay Composable Types (v98.0.0) ────────────────────────

/** Options for useSessionReplay composable. */
export interface SessionReplayOptions {
  /** Automatically start recording on mount */
  autoStart?: boolean;
  /** Capture DOM mutation snapshots */
  captureDOM?: boolean;
  /** Capture JS errors during recording */
  captureErrors?: boolean;
  /** Capture click events during recording */
  captureClicks?: boolean;
  /** Recording quality (0.0-1.0) */
  quality?: number;
  /** Max recording duration in seconds */
  maxDuration?: number;
  /** Interval between DOM snapshots (ms) */
  snapshotIntervalMs?: number;
}

/** Return type of useSessionReplay composable. */
export interface SessionReplayAPI {
  recordingActive: import('svelte/store').Writable<boolean>;
  recordingSessionId: import('svelte/store').Writable<string | null>;
  recordingProvider: import('svelte/store').Writable<string>;
  recordingSettings: import('svelte/store').Writable<{
    quality: number;
    fps: number;
    maxDuration: number;
  }>;
  eventCount: import('svelte/store').Writable<number>;
  sessionReplayAvailable: import('svelte/store').Writable<boolean>;
  recordingError: import('svelte/store').Writable<string | null>;
  start: () => void;
  stop: () => void;
  pause: () => void;
  resume: () => void;
  captureSnapshot: () => void;
  captureEvent: (type: string, data?: Record<string, unknown>) => void;
  sessionDuration: import('svelte/store').Readable<number>;
  isActive: () => boolean;
}

/**
 * Session replay analytics composable for Svelte.
 *
 * @param options - Configuration options
 * @returns SessionReplayAPI
 */
export declare function useSessionReplay(options?: SessionReplayOptions): SessionReplayAPI;

/**
 * Convenience shorthand for useSessionReplay.
 */
export declare function sessionReplay(options?: SessionReplayOptions): SessionReplayAPI;

// ─── Event Name Constants (v100.0.0) ──────────────────────────────────

/**
 * E-commerce event name constants for type-safe event tracking.
 */
export const EcommerceEvents: Readonly<{
  VIEW_ITEM: 'view_item';
  ADD_TO_CART: 'add_to_cart';
  REMOVE_FROM_CART: 'remove_from_cart';
  VIEW_CART: 'view_cart';
  BEGIN_CHECKOUT: 'begin_checkout';
  ADD_PAYMENT_INFO: 'add_payment_info';
  PURCHASE: 'purchase';
  REFUND: 'refund';
  ADD_TO_WISHLIST: 'add_to_wishlist';
  SELECT_ITEM: 'select_item';
  SELECT_PROMOTION: 'select_promotion';
  VIEW_PROMOTION: 'view_promotion';
  CHECKOUT_STEP: 'checkout_step';
  ABANDONED_CART: 'abandoned_cart';
  CHECKOUT_ABANDON: 'checkout_abandon';
}>;

/**
 * SaaS lifecycle event name constants for type-safe event tracking.
 */
export const SaaSEvents: Readonly<{
  // Authentication
  SIGN_UP: 'sign_up';
  LOGIN: 'login';
  LOGOUT: 'logout';
  EMAIL_VERIFIED: 'email_verified';
  // Subscription
  SUBSCRIPTION_CREATED: 'subscription_created';
  SUBSCRIPTION_RENEWAL: 'subscription_renewal';
  CANCELLATION: 'cancellation';
  SUBSCRIPTION_CANCELLED: 'subscription_cancelled';
  SUBSCRIPTION_PAUSED: 'subscription_paused';
  SUBSCRIPTION_RESUMED: 'subscription_resumed';
  SUBSCRIPTION_VALUE_CHANGED: 'subscription_value_changed';
  // Plans
  PLAN_UPGRADE: 'plan_upgrade';
  PLAN_DOWNGRADE: 'plan_downgrade';
  PLAN_CHANGED: 'plan_changed';
  // Trial
  TRIAL_START: 'start_trial';
  TRIAL_END: 'trial_ended';
  TRIAL_CONVERTED: 'trial_converted';
  TRIAL_EXPIRED: 'trial_expired';
  // Billing
  PAYMENT_SUCCEEDED: 'payment_succeeded';
  PAYMENT_FAILED: 'payment_failed';
  PAYMENT_METHOD_ADDED: 'payment_method_added';
  PAYMENT_METHOD_UPDATED: 'payment_method_updated';
  PAYMENT_METHOD_REMOVED: 'payment_method_removed';
  INVOICE_GENERATED: 'invoice_generated';
  BILLING_RETRY: 'billing_retry';
  CREDIT_APPLIED: 'credit_applied';
  // Account
  ACCOUNT_ACTIVATED: 'account_activated';
  ACCOUNT_DEACTIVATED: 'account_deactivated';
  ACCOUNT_DELETED: 'account_deleted';
  PASSWORD_CHANGED: 'password_changed';
  PASSWORD_RESET: 'password_reset';
  PASSWORD_RESET_REQUESTED: 'password_reset_requested';
  PROFILE_UPDATED: 'profile_updated';
  // Onboarding
  ONBOARDING_STARTED: 'onboarding_started';
  // Feature
  FEATURE_USED: 'feature_used';
  FEATURE_LIMIT_REACHED: 'feature_limit_reached';
  FEATURE_ADOPTED: 'feature_adopted';
  // Team / B2B
  TEAM_CREATED: 'team_created';
  TEAM_MEMBER_JOINED: 'team_member_joined';
  TEAM_MEMBER_REMOVED: 'team_member_removed';
  ROLE_CHANGED: 'role_changed';
  INVITE_SENT: 'invite_sent';
  INVITE_ACCEPTED: 'invite_accepted';
  WORKSPACE_CREATED: 'workspace_created';
  // Growth
  MILESTONE_REACHED: 'milestone_reached';
  EXPANSION_REVENUE: 'expansion_revenue';
  FIRST_VALUE: 'first_value';
  USAGE_QUOTA_REACHED: 'usage_quota_reached';
  // Integrations
  INTEGRATION_CONNECTED: 'integration_connected';
  INTEGRATION_FAILED: 'integration_failed';
  INTEGRATION_USED: 'integration_used';
  // GDPR
  DATA_SUBJECT_ACCESS_REQUEST: 'data_subject_access_request';
  DATA_ERASURE_COMPLETED: 'data_erasure_completed';
}>;

/**
 * Engagement event name constants for type-safe event tracking.
 */
export const EngagementEvents: Readonly<{
  PAGE_VIEW: 'page_view';
  SCROLL_DEPTH: 'scroll_depth';
  CLICK: 'click';
  FORM_START: 'form_start';
  FORM_SUBMIT: 'form_submit';
  SEARCH: 'search';
  SHARE: 'share';
  ERROR: 'error';
  FILE_DOWNLOAD: 'file_download';
  VIDEO_PLAY: 'video_play';
  OUTBOUND_CLICK: 'outbound_click';
  NOTIFICATION: 'notification';
  CONTENT_ENGAGEMENT: 'content_engagement';
  ONBOARDING_STEP: 'onboarding_step';
  ONBOARDING_COMPLETED: 'onboarding_completed';
  SESSION_START: 'session_start';
  SESSION_END: 'session_end';
  SCREEN_VIEW: 'screen_view';
  TIME_ON_PAGE: 'time_on_page';
  TIMING: 'timing';
  AB_TEST_EXPOSURE: 'ab_test_exposure';
  CAMPAIGN_ATTRIBUTION: 'campaign_attribution';
  AD_CLICK: 'ad_click';
  CONSENT_GRANTED: 'consent_granted';
  CONSENT_WITHDRAWN: 'consent_withdrawn';
  GOAL_CONVERSION: 'goal_conversion';
  COPY_TEXT: 'copy_text';
  ELEMENT_VISIBILITY: 'element_visibility';
  HOVER: 'hover';
  FEATURE_REQUEST: 'feature_request';
  FEEDBACK: 'feedback';
  PERFORMANCE_SCORE: 'performance_score';
  WEB_VITALS: 'web_vitals';
  CLIENT_ERROR: 'client_error';
}>;

/**
 * Security event name constants for type-safe event tracking.
 */
export const SecurityEvents: Readonly<{
  LOGIN_ATTEMPT: 'login_attempt';
  MFA_CHALLENGE: 'mfa_challenge';
  RATE_LIMIT_EXCEEDED: 'rate_limit_exceeded';
  SUSPICIOUS_ACTIVITY: 'suspicious_activity';
  DATA_ACCESS_AUDIT: 'data_access_audit';
  AI_AGENT_ACCESS: 'ai_agent_access';
}>;

/**
 * Uptime/service health event name constants.
 */
export const UptimeEvents: Readonly<{
  API_LATENCY: 'api_latency';
  DEPLOYMENT: 'deployment';
  ERROR_SPIKE: 'error_spike';
  SERVICE_DOWN: 'service_down';
  SERVICE_UP: 'service_up';
}>;

/**
 * Infrastructure/platform event name constants.
 */
export const InfrastructureEvents: Readonly<{
  DEPLOYMENT_ROLLED_BACK: 'deployment_rolled_back';
  ERROR_BUDGET_BURNED: 'error_budget_burned';
  EXPERIMENT_EXPOSED: 'experiment_exposed';
  FEATURE_FLAG_EVALUATED: 'feature_flag_evaluated';
  INCIDENT_STARTED: 'incident_started';
  INCIDENT_RESOLVED: 'incident_resolved';
  MAINTENANCE_STARTED: 'maintenance_started';
  MAINTENANCE_ENDED: 'maintenance_ended';
  PIPELINE_FAILURE: 'pipeline_failure';
  SLO_BREACH: 'slo_breach';
}>;

/**
 * Marketing analytics event name constants for type-safe event tracking.
 * Covers email campaigns, lead generation, content marketing, social media,
 * paid advertising, webinars, SMS/push, affiliate/referral, and attribution.
 */
export const MarketingEvents: Readonly<{
  // Email Marketing
  EMAIL_SENT: 'email_sent';
  EMAIL_DELIVERED: 'email_delivered';
  EMAIL_OPENED: 'email_opened';
  EMAIL_CLICKED: 'email_clicked';
  EMAIL_BOUNCED: 'email_bounced';
  EMAIL_UNSUBSCRIBED: 'email_unsubscribed';
  EMAIL_MARKED_SPAM: 'email_marked_spam';
  // Lead Generation
  LEAD_CAPTURED: 'lead_captured';
  LEAD_QUALIFIED: 'lead_qualified';
  LEAD_SCORE_CHANGED: 'lead_score_changed';
  // Content Marketing
  BLOG_VIEW: 'blog_view';
  CONTENT_DOWNLOADED: 'content_downloaded';
  NEWSLETTER_SUBSCRIBED: 'newsletter_subscribed';
  // Social Media
  SOCIAL_SHARE: 'social_share';
  SOCIAL_FOLLOW: 'social_follow';
  SOCIAL_COMMENT: 'social_comment';
  SOCIAL_MENTION: 'social_mention';
  // Paid Advertising
  AD_IMPRESSION: 'ad_impression';
  AD_CLICK: 'ad_click';
  AD_CONVERSION: 'ad_conversion';
  // Webinars & Events
  WEBINAR_REGISTERED: 'webinar_registered';
  WEBINAR_ATTENDED: 'webinar_attended';
  WEBINAR_ENGAGEMENT: 'webinar_engagement';
  // SMS & Push
  SMS_SENT: 'sms_sent';
  SMS_DELIVERED: 'sms_delivered';
  SMS_CLICKED: 'sms_clicked';
  PUSH_NOTIFICATION_SENT: 'push_notification_sent';
  PUSH_NOTIFICATION_OPENED: 'push_notification_opened';
  // Affiliate & Referral
  REFERRAL_LINK_SHARED: 'referral_link_shared';
  REFERRAL_CONVERSION: 'referral_conversion';
  AFFILIATE_SIGNUP: 'affiliate_signup';
  AFFILIATE_COMMISSION: 'affiliate_commission';
  // Marketing Attribution
  ATTRIBUTION_TOUCHPOINT: 'attribution_touchpoint';
  CAMPAIGN_RESPONSE: 'campaign_response';
}>;

/**
 * All event names from all categories.
 */
export const AllEventNames: Readonly<
  typeof EcommerceEvents &
  typeof SaaSEvents &
  typeof EngagementEvents &
  typeof MarketingEvents &
  typeof SecurityEvents &
  typeof UptimeEvents &
  typeof InfrastructureEvents &
  typeof CustomerSuccessEvents
>;

/** Union type of all valid event names. */
export type EventName = (typeof AllEventNames)[keyof typeof AllEventNames];

/** Union type of e-commerce event names. */
export type EcommerceEventName = (typeof EcommerceEvents)[keyof typeof EcommerceEvents];

/** Union type of SaaS event names. */
export type SaaSEventName = (typeof SaaSEvents)[keyof typeof SaaSEvents];

/** Union type of engagement event names. */
export type EngagementEventName = (typeof EngagementEvents)[keyof typeof EngagementEvents];

/** Union type of marketing event names. */
export type MarketingEventName = (typeof MarketingEvents)[keyof typeof MarketingEvents];

/** Union type of security event names. */
export type SecurityEventName = (typeof SecurityEvents)[keyof typeof SecurityEvents];

/** Union type of uptime event names. */
export type UptimeEventName = (typeof UptimeEvents)[keyof typeof UptimeEvents];

/** Union type of infrastructure event names. */
export type InfrastructureEventName = (typeof InfrastructureEvents)[keyof typeof InfrastructureEvents];

/** Customer success analytics event names. */
export const CustomerSuccessEvents: Readonly<{
  readonly SUPPORT_TICKET_CREATED: 'support_ticket_created';
  readonly NPS_SUBMITTED: 'nps_submitted';
  readonly HEALTH_SCORE_CHANGED: 'health_score_changed';
  readonly RENEWAL_REMINDER_SENT: 'renewal_reminder_sent';
  readonly CHURN_INTERVIEW: 'churn_interview';
  readonly CUSTOMER_REVIEW: 'customer_review';
  readonly ONBOARDING_CALL_COMPLETED: 'onboarding_call_completed';
}>;

/** Union type of customer success event names. */
export type CustomerSuccessEventName = (typeof CustomerSuccessEvents)[keyof typeof CustomerSuccessEvents];

/**
 * Type guard: check if a string is a valid event name.
 */
export function isValidEventName(name: string): name is EventName;

/**
 * Get event names by category.
 */
export function getEventNamesByCategory(category: 'ecommerce' | 'saas' | 'engagement' | 'marketing' | 'security' | 'uptime' | 'infrastructure' | 'customer_success'): readonly string[];

/**
 * Get total count of all events across all categories.
 */
export function getTotalEventCount(): number;

// ─── Client-Side Sampling Engine (v102.0.0) ─────────────────────────

/**
 * Get sampling decision for a specific event name.
 *
 * @param name - Event name to check
 * @returns Sampling decision details
 *
 * @example
 * const decision = getSamplingDecision('button_click');
 * console.log(decision.sampled); // true or false
 */
export function getSamplingDecision(name: string): {
    sampled: boolean;
    rate: number;
    deterministic: boolean;
    enabled: boolean;
};

// ─── Event Debug Logger (v102.0.0) ─────────────────────────────────

/**
 * Debug log entry for tracked events.
 */
export interface DebugEventLogEntry {
    timestamp: number;
    event: string;
    params: Record<string, unknown>;
    action: 'queued' | 'immediate' | 'sampled_out' | 'consent_blocked';
    meta: Record<string, unknown>;
    trackingId: string | null;
}

/**
 * Debug event log stats.
 */
export interface DebugEventLogStats {
    total: number;
    queued: number;
    immediate: number;
    sampled_out: number;
    consent_blocked: number;
}

/**
 * Get the debug event log buffer (most recent first).
 *
 * @param limit - Maximum entries to return (default 50)
 * @returns Array of debug log entries
 */
export function getDebugEventLog(limit?: number): DebugEventLogEntry[];

/**
 * Clear the debug event log buffer.
 */
export function clearDebugEventLog(): void;

/**
 * Get debug event log statistics.
 *
 * @returns Counts by action type
 */
export function getDebugEventLogStats(): DebugEventLogStats;

// ─── Privacy Action Tracking (v108.0.0) ─────────────────────────

/**
 * Supported privacy action types for trackPrivacyAction().
 */
export type PrivacyActionType =
    | 'consent_granted'
    | 'consent_withdrawn'
    | 'consent_changed'
    | 'data_access_request'
    | 'data_erasure_request'
    | 'data_portability_request'
    | 'opt_out'
    | 'opt_in'
    | 'cookie_preferences_saved'
    | 'do_not_sell';

/**
 * Options for trackPrivacyAction().
 */
export interface PrivacyActionOptions {
    purpose?: string;
    method?: string;
    grantedPurposes?: string[];
    deniedPurposes?: string[];
    extra?: Record<string, unknown>;
}

/**
 * Options for trackConsentUpdate().
 */
export interface ConsentUpdateOptions {
    newlyGranted?: string[];
    newlyDenied?: string[];
    allGranted?: string[];
    allDenied?: string[];
    source?: string;
}

/**
 * Track a user-initiated privacy action event.
 *
 * @param action - Privacy action type
 * @param options - Action context
 *
 * @example
 * await trackPrivacyAction('consent_granted', {
 *     method: 'banner',
 *     grantedPurposes: ['analytics', 'functional'],
 *     deniedPurposes: ['marketing'],
 * });
 */
export function trackPrivacyAction(action: PrivacyActionType, options?: PrivacyActionOptions): Promise<void>;

/**
 * Track a batch consent update with before/after state.
 *
 * @param options - Consent change context
 *
 * @example
 * await trackConsentUpdate({
 *     newlyGranted: ['marketing'],
 *     allGranted: ['necessary', 'analytics', 'functional', 'marketing'],
 *     source: 'settings_page',
 * });
 */
export function trackConsentUpdate(options?: ConsentUpdateOptions): Promise<void>;

// ─── SaaS Lifecycle Shorthands — v119.0.0 Additions ──────────────

/** Options for trackLogin shortcut. */
export interface TrackLoginOptions {
  method?: string;
  userId?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackLogout shortcut. */
export interface TrackLogoutOptions {
  method?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackPlanDowngrade shortcut. */
export interface TrackPlanDowngradeOptions {
  fromPlan?: string;
  toPlan?: string;
  valueDifference?: number;
  extra?: Record<string, unknown>;
}

/** Options for trackTrialConverted shortcut. */
export interface TrackTrialConvertedOptions {
  plan?: string;
  value?: number;
  currency?: string;
  trialDays?: number;
  extra?: Record<string, unknown>;
}

/** Options for trackTrialExpired shortcut. */
export interface TrackTrialExpiredOptions {
  plan?: string;
  trialDays?: number;
  extra?: Record<string, unknown>;
}

/** Options for trackPaymentFailed shortcut. */
export interface TrackPaymentFailedOptions {
  reason?: string;
  amount?: number;
  currency?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackSubscriptionPaused shortcut. */
export interface TrackSubscriptionPausedOptions {
  plan?: string;
  reason?: string;
  extra?: Record<string, unknown>;
}

/** Options for trackInvoiceGenerated shortcut. */
export interface TrackInvoiceGeneratedOptions {
  invoiceId?: string;
  amount?: number;
  currency?: string;
  billingCycle?: string;
  extra?: Record<string, unknown>;
}

/** Track a user login event with method attribution. */
export function trackLogin(options?: TrackLoginOptions): Promise<void>;

/** Track a user logout event. */
export function trackLogout(options?: TrackLogoutOptions): Promise<void>;

/** Track a plan downgrade event with transition details. */
export function trackPlanDowngrade(options?: TrackPlanDowngradeOptions): Promise<void>;

/** Track a trial conversion event (trial → paid). */
export function trackTrialConverted(options?: TrackTrialConvertedOptions): Promise<void>;

/** Track a trial expired event (trial ended without conversion). */
export function trackTrialExpired(options?: TrackTrialExpiredOptions): Promise<void>;

/** Track a payment failed event with billing context. */
export function trackPaymentFailed(options?: TrackPaymentFailedOptions): Promise<void>;

/** Track a subscription paused event. */
export function trackSubscriptionPaused(options?: TrackSubscriptionPausedOptions): Promise<void>;

/** Track an invoice generated event. */
export function trackInvoiceGenerated(options?: TrackInvoiceGeneratedOptions): Promise<void>;

/** Get all supported category names. */
export function getCategoryNames(): readonly string[];

// ─── SDK Bridge Mode (v166.0.0) ─────────────────────────────────

/** Result of trackFromSdk with translation metadata. */
export interface SdkBridgeTrackResult {
  /** Whether the event was tracked successfully. */
  tracked?: boolean;
  /** Error message if the operation failed. */
  error?: string;
  /** Bridge translation metadata. */
  _bridge: {
    sdk: string;
    original_event: string;
    translated_event: string;
  };
}

/** Result of translateToSdk — translated event for a target SDK. */
export interface SdkBridgeTranslation {
  /** Translated event name in the target SDK format. */
  event: string;
  /** Translated event properties in the target SDK format. */
  properties: Record<string, unknown>;
  /** Target SDK identifier. */
  sdk: string;
  /** Error message if translation failed. */
  error?: string;
}

/** Result of inspectSdkTranslation — translation preview. */
export interface SdkBridgeInspection {
  original: string;
  translated_name: string;
  translated_params: Record<string, unknown>;
  has_mapping: boolean;
  source: 'explicit_mapping' | 'passthrough';
  sdk: string;
}

/** Result of fetchSdkBridgeCompatibility — server-side compatibility report. */
export interface SdkBridgeCompatibilityReport {
  total?: number;
  mapped?: number;
  unmapped?: number;
  unmapped_events?: string[];
  mapped_events?: string[];
  warnings?: string[];
  coverage_percent?: number;
  by_category?: Record<string, { total: number; mapped: number; percent: number }>;
  error?: string;
}

/**
 * Translate an inbound event from a third-party SDK to ZeroBoiler format and track it.
 *
 * @param sdk - Source SDK identifier ('posthog', 'mixpanel', 'segment', 'amplitude')
 * @param eventName - Event name in the source SDK format
 * @param params - Event parameters in the source SDK format
 * @param options - Additional track options (immediate, priority)
 */
export function trackFromSdk(
  sdk: string,
  eventName: string,
  params?: Record<string, unknown>,
  options?: Record<string, unknown>,
): Promise<SdkBridgeTrackResult>;

/**
 * Translate a ZeroBoiler event to a target SDK format for dual-dispatch.
 *
 * @param sdk - Target SDK identifier
 * @param eventName - ZeroBoiler event name
 * @param params - ZeroBoiler event parameters
 */
export function translateToSdk(
  sdk: string,
  eventName: string,
  params?: Record<string, unknown>,
): SdkBridgeTranslation;

/**
 * Fetch SDK bridge compatibility report from server.
 *
 * @param sdk - Target SDK identifier
 */
export function fetchSdkBridgeCompatibility(sdk: string): Promise<SdkBridgeCompatibilityReport>;

/**
 * Get the list of SDKs supported by the bridge.
 */
export function getSupportedBridgeSdks(): string[];

/**
 * Inspect how a specific event would be translated for a target SDK.
 *
 * @param sdk - Target SDK identifier
 * @param eventName - ZeroBoiler event name
 * @param params - Event parameters
 */
export function inspectSdkTranslation(
  sdk: string,
  eventName: string,
  params?: Record<string, unknown>,
): SdkBridgeInspection;
