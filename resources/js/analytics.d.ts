/**
 * ZeroBoiler Analytics — TypeScript Type Definitions
 *
 * Comprehensive type definitions for the ZeroBoiler Analytics JS client library.
 * Provides full IntelliSense support for Svelte, Vue, React, and vanilla TS projects.
 *
 * @package ZeroBoiler Analytics
 * @version 7.3.0
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
 * Get the configured API base URL.
 */
export function getApiBaseUrl(): string;

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
