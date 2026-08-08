# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

## [2.89.0] - 2026-08-08

### Added
- **AnalyticsReadinessCommand** — Artisan command `zb:analytics:readiness` for production readiness checks. Runs a comprehensive checklist validating provider configuration, consent defaults, queue setup, identity tracking, event validation, debug mode, event replay, deduplication, PII sanitization, consent logging, GDPR IP anonymization, UTM attribution, health score, error tracking, and performance budget. Supports `--json` output and `--no-cache` force-refresh. Returns exit code 0 (ready) or 1 (not ready). The readiness service already existed but had no CLI entry point — config documentation referenced `zb:analytics:readiness` without an actual command.
- **V89ReadinessCommandVersionTest** — 17 test cases covering command class structure, strict types, license header, #[Override] attribute, service injection, return types, ServiceProvider registration, and comprehensive version consistency (2.89.0) across 15+ files with stale version detection.
- Command registered in ServiceProvider (8 total artisan commands now).

### Changed
- Version bump to 2.89.0 across AnalyticsEvent::VERSION, AnalyticsManager::version(), composer.json, JS client (getVersion + _getInternalVersion), TypeScript definitions, config catalog_version, and 50+ controller/service version strings.
- All 50+ hardcoded VERSION assertions across 20+ test files updated to match 2.89.0.

## [2.88.0] - 2026-08-08

### Changed
- Phase 2-3-4 production readiness: expanded Phase234ProductionTest from 6 to 40+ comprehensive assertions covering strict types, license headers, return types, final classes, #[Override], TrackerInterface compliance, DTO readonly, event catalog integrity, config sections, Facade @method docs, ServiceProvider bindings, and version consistency
- Fixed all hardcoded VERSION assertions across 15+ test files to match current version (2.88.0)
- Updated Phase5ProductionReadinessTest version assertions from 2.82.0 to 2.88.0
- Removed legacy CHANGES.md (use CHANGELOG.md)
- Version bump to 2.88.0 across composer.json, AnalyticsEvent::VERSION, AnalyticsManager::version(), ServiceProvider docblocks

## [2.87.0] - 2026-08-08

## [2.82.0] - 2026-08-08

### Added
- **SubscriptionMetricsCalculator** — Subscription metric calculations (MRR, ARR, churn rate, net retention)
- **Phase5ProductionReadinessTest** — 50+ new production readiness tests (DTO completeness, return type audit, config completeness, Facade @method verification, ServiceProvider binding audit, license headers, Macroable removal check)

### Changed
- Version bump to 2.82.0 across AnalyticsEvent, composer.json, AnalyticsManager::version(), AnalyticsServiceProvider docblock
- Fix ProductionReadinessTest: ConsentState tests now use correct API (isGranted/hasAnalyticsConsent instead of non-existent property accessors)
- Fix ProductionReadinessTest: Facade test now correctly expects `final` (not "not final")
- Fix V81ForecastChurnVersionTest: VERSION assertion updated to 2.82.0

## [2.81.0] - 2026-08-08

### Added
- **AnalyticsInsightsService** — Automated event intelligence engine. Generates insights from event data: trending event detection (z-score based), anomaly detection (statistical threshold), funnel drop-off analysis (per-step conversion gaps), conversion opportunity identification (high-intent non-converting events), user flow analysis (most common multi-step paths). Configurable insight types, max count, anomaly threshold, trend window, and cache TTL.
- **FunnelVelocityService** — Time-based funnel velocity analysis. Measures per-step and per-transition timing metrics (avg, median, p75, p90), identifies bottleneck steps (highest drop-off rate) and slowest transitions, calculates overall funnel conversion rate and total completion time. Supports built-in funnels (checkout, signup, trial, activation) and custom funnels. Includes funnel comparison method.
- **EventImpactService** — Event impact scoring using point-biserial correlation. Measures which events most strongly correlate with conversion, retention, and revenue outcomes. Produces composite impact scores with category labels (high/moderate/low/minimal impact). Includes `conversionDrivers()` and `retentionDrivers()` convenience methods.
- **AnalyticsInsight DTO** — Immutable data transfer object for insight data with type, severity, confidence, source, metadata, and a static `fromArray()` constructor.
- **FunnelVelocityReport DTO** — Immutable data transfer object for funnel velocity results with per-step metrics, transition data, completion stats, bottleneck identification, and optional metadata.
- **EcommerceEvents**: `abandoned_cart` and `checkout_abandon` event catalog entries — new e-commerce abandonment tracking events.
- **AbandonedCartEvent / CheckoutAbandonEvent** — Dedicated event classes with typed properties (cart items, totals, step reached, time spent) and `toAnalyticsEvent()` conversion.
- **EventCatalog**: `conversionEvents()` and `abandonedEvents()` helper methods — curated event sets for CRO dashboards and abandonment recovery campaigns.
- **Config sections**: `insights` (enabled, cache_ttl, min_events_for_trend, anomaly_threshold, max_insights, trend_window_hours), `funnel_velocity` (enabled, percentile_window), `event_impact` (enabled, min_sample_size, conversion_events, retention_events).
- **Service provider registrations** — AnalyticsInsightsService, FunnelVelocityService, and EventImpactService registered as singletons.

### Changed
- Version bump to 2.82.0 across AnalyticsEvent, composer.json, JS client (header + getVersion + _getInternalVersion), AnalyticsManager, AnalyticsServiceProvider, and all 188 controller endpoint version strings.

## [2.81.0] - 2026-08-08

### Added
- **RevenueForecastService** — SaaS revenue forecasting engine with MRR trend projection, 90-day forecast, LTV calculation, LTV:CAC ratio, CAC payback period, runway estimation, cohort retention curves, and MRR movement breakdown (new/expansion/contraction/churn). Configurable growth rate, churn rate, and forecast horizon. Cached for performance.
- **ChurnPredictionService** — Weighted scoring model for user churn risk prediction. Evaluates 10 configurable signals (days inactive, usage decline, support tickets, failed payments, feature adoption, contract expiration, billing disputes, login frequency, engagement score, plan downgrades). Classifies users as low/medium/high/critical risk with actionable recommendations.
- **9 revenue forecasting API endpoints** — `GET /api/analytics/forecast`, `forecast/summary`, `forecast/project`, `forecast/ltv`, `forecast/ltv-cac`, `forecast/payback`, `forecast/runway`, `forecast/cohort-retention`, `forecast/mrr-movement`
- **5 churn prediction API endpoints** — `POST /api/analytics/churn/score`, `churn/score-batch`, `churn/cohort-summary`, `GET churn/weights`, `churn/thresholds`
- **Config sections**: `forecasting` (enabled, cache_ttl, monthly_churn_rate, growth_rate, horizon_days, historical_window_days, avg_revenue_per_account), `churn_prediction` (enabled, cache_ttl, high/medium/critical risk thresholds, inactive_days_threshold, 10 configurable signal weights)
- **JS client functions** — Revenue forecasting API helpers (`fetchRevenueForecast`, `fetchRevenueForecastSummary`, `fetchRevenueProject`, `fetchLtv`, `fetchLtvCacRatio`, `fetchPaybackPeriod`, `fetchRunway`, `fetchCohortRetention`, `fetchMrrMovement`), Churn prediction helpers (`fetchChurnScore`, `fetchChurnScoreBatch`, `fetchChurnCohortSummary`, `fetchChurnWeights`, `fetchChurnThresholds`)
- **TypeScript definitions** — `ForecastPoint`, `ForecastSummary`, `LtvResult`, `LtvCacResult`, `PaybackResult`, `RunwayResult`, `MrrMovement`, `ChurnRiskProfile`, `ChurnSignal`, `ChurnThresholds` interfaces

### Changed
- Version bump to 2.81.0 across AnalyticsEvent, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, AnalyticsManager, AnalyticsServiceProvider, and controller endpoint version string.
- AnalyticsEventController: Added `$this->config` property to fix missing ConfigRepository reference used by revenue forecasting and churn prediction endpoints.

## [2.80.0] - 2026-08-08

### Changed
- Added `final` to `Facades\Analytics`

### Added
- Phase 2-3-4 production test suite (`Phase234ProductionTest.php`)

## [2.76.0] - 2026-08-08

### Added
- EventCatalog: `billingEvents()`, `productGrowthEvents()`, `allLifecycleEvents()` — AARRR lifecycle framework helpers
- LifecycleEventMapper: 7 new conversion & expansion mappings (38 total)
- Config: `identity.link_on_auth`, `api.prefix`, `api.middleware`, `api.auth_middleware`
- Inertia: `subscriptionTiers` and `identityAutoLink` page props
- JS Client: Auto-identify via sendBeacon on init
- TypeScript: `SubscriptionTier` interface, new config props
- Test: V76SaaSStarterIndustryStandardTest (36 assertions)

## [2.73.0] - 2026-08-08

### Added
- **EventCatalog::withPosthogMapping()** — Filter events by category that have PostHog mappings. Useful for identifying events that can be sent to PostHog without additional transformation.
- **EventCatalog::withPlausibleMapping()** — Filter events by category that have Plausible mappings.
- **EventCatalog::providerCount()** — Get the count of events that have a specific provider mapping.
- **EventCatalog::providerCoverage()** — Comprehensive breakdown of event coverage per provider with counts. Returns event lists and counts for ga4, meta, posthog, plausible.
- **AnalyticsManager::flushMetrics()** — Flush all accumulated metrics and return a pre-flush snapshot. Useful for admin dashboards, testing, and periodic metric collection.
- **Inertia middleware ecommerce props** — `zbAnalytics.ecommerce` now exposes `currency`, `brand`, `taxBehavior`, `shippingDefault` to the JS client for client-side e-commerce tracking.
- **Inertia middleware consent log flag** — `zbAnalytics.consentLogEnabled` exposed to the JS client for consent banner display decisions.
- **Inertia middleware version prop** — `zbAnalytics.version` now exposes the package version string for client-side feature detection.
- **V73SaaSStarterIndustryStandardTest** — Comprehensive test suite covering all 12 SaaS starter feature areas, version consistency, cross-cutting quality checks (strict types, license headers, final classes, readonly DTOs).

### Changed
- Version 2.72.0 → 2.73.0 across AnalyticsManager, AnalyticsEvent, AnalyticsServiceProvider, composer.json.
- All config section keys verified (40+ sections) for industry-standard SaaS starter coverage.

## [2.67.0] - 2026-08-07

### Added
- **DataWarehouseExportService** — ETL export service for NDJSON and CSV formats. Supports filtering by category, event name, and date range. Compatible with Snowflake COPY INTO, BigQuery load jobs, Redshift COPY, and AWS Athena. Methods: `addEvent()`, `addEvents()`, `filterByCategory()`, `filterByEvent()`, `filterFrom()`, `filterTo()`, `exportToString()`, `exportToFile()`, `summary()`, `clear()`. Registered as singleton.
- **EventPropertySchema** — Runtime type-safe property validation per event. Validates type (string/int/float/bool/array), required/optional, enum constraints, format (email/url/currency/uuid/iso_date), range (min/max). Built-in schemas for purchase, view_item, add_to_cart, sign_up, trial_start, subscription, plan_upgrade, cancellation, page_view, click, form_submit, search, error. Custom schema registration via `defineProperty()` and `defineGlobalRule()`.
- **AnalyticsDashboardDataProvider** — Unified health dashboard aggregation. Returns provider status, event catalog summary, KPI metrics, health score, real-time stats, and active alerts in a single `overview()` call. Public-safe `publicOverview()` strips sensitive data.
- **JS First-Touch UTM Cookie Persistence** — Cross-session attribution via 365-day cookie. Unlike `captureUTM()` (sessionStorage), `persistFirstTouchUTM()` writes to `zb_first_touch_utm` cookie. New exports: `getFirstTouchUTM()`, `getAttributionContext()`, `clearFirstTouchUTM()`. Auto-called during `init()`.
- **JS Data Warehouse Export Helper** — `exportToDataWarehouse()` triggers server-side NDJSON/CSV export via `POST /api/analytics/export/warehouse`.
- **JS Dashboard Helper** — `fetchDashboardOverview()` fetches unified dashboard data via `GET /api/analytics/dashboard`.
- **TypeScript definitions** — New interfaces: `FirstTouchUTM`, `AttributionContext`, `DataWarehouseExportOptions`, `DataWarehouseExportResult`, `DashboardOverview`.
- **Config sections** — `data_warehouse` (format, output_path, include_fields, include_headers, null_value) and `property_schema` (enabled, reject_invalid, log_violations, register_builtins).
- **2 new API routes** — `POST /api/analytics/export/warehouse`, `GET /api/analytics/dashboard`.
- **2 new controller endpoints** — `exportWarehouse()` with date range and category/event filtering, `dashboardOverview()` for unified dashboard data.
- **V67DataWarehouseSchemaDashboardTest** — 35+ test cases covering DataWarehouseExportService (12), EventPropertySchema (16), AnalyticsDashboardDataProvider (6), and version consistency (2).

### Changed
- Version 2.66.0 → 2.67.0 across all codebase files (AnalyticsManager, composer.json, JS client, TS definitions, controller endpoints)

## [2.66.0] - 2026-08-07

### Added
- SaaS Conversion Analytics service (trial conversion, activation scoring, win-back tracking, funnel analysis)
- 3 new SaaS events: TrialConverted, SubscriptionResumed, MilestoneReached
- EcommerceFormatConverter: GA4→Meta view_item, add_to_cart, begin_checkout, add_payment_info, add_to_wishlist
- Universal ga4ToMetaAuto() converter for all e-commerce events
- 4 conversion analytics API endpoints (summary, funnel, activation score, time-to-convert)
- 5 JS client conversion tracking functions
- 42 new test cases (V66SaaSConversionEcommerceTest)

### Changed
- Version 2.65.0 → 2.66.0 across all codebase files

## [2.65.0] - 2026-08-07

### Added
- **Plausible ecommerce format conversion** — `EcommerceFormatConverter` now supports GA4 → Plausible conversion for purchase, refund, add_to_cart, and begin_checkout events. Plausible custom events use flat string props. New methods: `ga4ToPlausiblePurchase()`, `ga4ToPlausibleRefund()`, `ga4ToPlausibleAddToCart()`, `ga4ToPlausibleBeginCheckout()`, `buildPlausiblePurchase()`. Completes 6-provider ecommerce format support.
- **EventClassificationService** — Classifies events into four revenue impact tiers: critical (revenue transactions), monetization (conversion funnel), engagement (product usage), operational (auth/infra). Provides `classify()`, `isRevenueImpacting()`, `isDroppable()`, `classifyBatch()`, `getEventsInTier()`, `tierToPriorityMap()`, `getDispatchPriority()`. Custom overrides supported.
- **SubscriptionMetricsCalculator** — Pure calculation service for SaaS business metrics: MRR, ARR, churn rate, revenue churn, net revenue retention (NRR), ARPU/ARPPU, customer lifetime value (CLV), CLV:CAC ratio, runway, and month-over-month growth. All stateless with typed return arrays. Includes `dashboardSummary()` for single-call dashboard computation.
- **JS SaaS revenue tracking helpers** — 4 new client-side functions: `trackSubscriptionEvent()` (7 subscription actions), `trackTrialEvent()` (4 trial states), `trackRevenueEvent()` (4 billing event types), `trackPlanChange()` (auto-detects upgrade/downgrade). Full TypeScript definitions with interfaces.
- **TypeScript definitions** — New interfaces: `SubscriptionAction`, `SubscriptionEventParams`, `TrialState`, `TrialEventParams`, `RevenueEventType`, `RevenueEventParams`, `PlanChangeParams`.
- **V65PlausibleClassificationMetricsTest** — 65+ test cases covering Plausible ecommerce conversion (8 tests), EventClassificationService (20 tests), and SubscriptionMetricsCalculator (20+ tests).

### Changed
- **Version unification to 2.65.0** — All version strings across AnalyticsManager, composer.json, JS client, TypeScript definitions, controller endpoints, and service classes.
- Total source files: 212 (was 210)
- Total test files: 108 (was 107)

## [2.61.0] - 2026-08-07

### Added
- **6 new typed event classes** — AdClickEvent (paid ad clicks with platform/campaign/creative tracking), ContentEngagementEvent (article/video/document depth tracking), OnboardingStepEvent (SaaS product activation funnel), CheckoutStepEvent (multi-step e-commerce checkout funnel), ImpressionEvent (feature discovery and A/B test exposure), WorkspaceCreatedEvent (multi-tenant workspace creation). Total catalog now 76 events (13 ecommerce + 39 SaaS + 24 engagement).
- **AnalyticsTelemetryService** — Self-monitoring provider connectivity probes with cached results. Sends lightweight HTTP checks to GA4 debug endpoint, PostHog API, Plausible, and webhook URLs. Config-driven via `zeroboiler.analytics.telemetry`. Registered as singleton in ServiceProvider.
- **EventCatalog::searchByProvider()** — Reverse-lookup events by provider event name. Given a GA4/Meta/PostHog/Plausible event name, find all catalog events that map to it. Useful for incoming webhook normalization.
- **EventCatalog::summary()** — Structured summary of the event catalog with per-category and per-provider coverage counts.
- **JS `initSvelteTracker()`** — Zero-config Svelte/Inertia wrapper that calls `init()` + sets up auto page view tracking with `inertia:navigate` listener. Returns cleanup function for Svelte `onMount()`. Supports `enableAllAutoTrackers` option.
- **6 new JS client functions** — `trackAdClick()`, `trackContentEngagement()`, `trackOnboardingStep()`, `trackFeatureImpression()`, `trackCheckoutStep()` — all with destructured params and snake_case conversion.
- **TypeScript definitions** — Full IntelliSense for all new JS functions: `SvelteTrackerOptions`, `AdClickParams`, `ContentEngagementParams`, `OnboardingStepParams`, `FeatureImpressionParams`, `CheckoutStepParams` interfaces.
- **Telemetry config section** — New `zeroboiler.analytics.telemetry` with `enabled`, `cache_ttl`, `cache_prefix` env vars.
- **10 new AnalyticsConfig accessors** — `telemetryEnabled()`, `telemetryCacheTtl()`, `telemetryCachePrefix()`, `anonymizationEnabled()`, `scheduledReportEnabled()`, `scheduledReportOutputPath()`, `journeysEnabled()`, `journeysCacheTtl()`.
- **V61EventCatalogExpansionTelemetryTest** — 45+ test cases covering all 6 new event constructors, catalog integration (76 events), category counts, provider mappings, PHP 8.5 compliance (final readonly), version consistency (6 files), and filesystem integrity.

### Changed
- **Version unification to 2.61.0** — All version strings across AnalyticsManager, composer.json, JS client (v2.61.0), TypeScript definitions, 50+ controller endpoints, AnalyticsEventRouter, EventSourceTagger, EventForwardingService, EventAliasResolver, EventCacheService, EventEnvelopeService, EventExporterService.
- Total source files: 210 (was 203)
- Total test files: 103 (was 102)

## [2.60.0] - 2026-08-07

### Fixed
- Added missing `timestamp` property to `AnalyticsEvent` DTO (nullable `DateTimeImmutable`), resolving undefined property access in `EventContextEvent::toArray()`.
- Added missing `:void` return type to `EventContextEvent` constructor.
- Marked `AnalyticsEvent` as `final readonly` for production immutability guarantee.

### Added
- Added `CONTRIBUTING.md` with architecture overview and code standards.

## [2.59.0] - 2026-08-07

## [2.58.2] - 2026-08-07

### Fixed
- Added missing `:void` return type to 72 constructor declarations across services, events, middleware, pipeline, and tracking components for PHP 8.5 strict compliance.
- Fixed duplicate `:void` declarations from initial automated fix.

## [2.58.1] - 2026-08-07

### Fixed
- Added missing `:void` return type to 72 constructor declarations.

## [Unreleased]

### Added
- **EventPriority enum** — Four-level event priority system (critical, normal, low, background) with weight-based comparison, filter bypass, sampling, budget, and deferrability flags. Critical events (purchase, sign_up, subscription) always bypass sampling and rate limits.
- **EventPriorityGate service** — Config-driven priority gate that evaluates events before dispatch. Per-priority rate limits (events/minute window), global budget threshold, cache-backed counters. 20+ built-in priority overrides for known event names. Custom overrides via `zeroboiler.analytics.priority.overrides` config. Registered as singleton in ServiceProvider.
- **PriorityAwareFilter** — EventPipeline-compatible filter that drops low-priority events when rate limits or budget thresholds are exceeded. Tracks dropped count for diagnostics.
- **Priority config section** — New `zeroboiler.analytics.priority` with enabled toggle, per-level rate limits (env: ANALYTICS_PRIORITY_RATE_*), custom overrides, cache TTL/prefix, budget-aware mode, and budget threshold.
- **7 AnalyticsConfig accessors** — `priorityEnabled()`, `priorityRateLimit()`, `priorityCacheTtl()`, `priorityCachePrefix()`, `priorityBudgetAware()`, `priorityBudgetThreshold()`, `priorityOverrides()`.
- **JS `trackEventWithPriority()`** — Client-side function that attaches `_priority` param and sends critical events immediately. Validates priority values against allowed set.
- **TypeScript definitions** — `EventPriority` type alias, `PriorityTrackOptions` interface, `trackEventWithPriority()` function signature.
- **V64EventPriorityGateTest** — 35+ test cases covering EventPriority enum (weights, bypasses, sampling, fromString), EventPriorityGate (priority resolution, rate limiting, custom overrides, budget checks, diagnostics), PriorityAwareFilter (pass/drop, dropped count, delegation), version consistency (8 files), and PHP 8.5 compliance.

### Changed
- **Version unification to 2.64.0** — All version strings across AnalyticsManager, composer.json, JS client (v2.64.0), TypeScript definitions.

## [2.61.0] - 2026-08-07

### Added
- **JS Consent Purposes API** — Four new client-side functions for GDPR consent banner integration: `getConsentPurposes()`, `getConsentPurposeKeys()`, `getOptionalConsentPurposes()`, and `buildConsentSignals()`. These read the `consentPurposes` Inertia prop (injected by `HandleInertiaAnalytics`) and provide a purpose → Consent Mode v2 signal mapper. Supports 6 Consent Mode signals (`analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `security_storage`) with automatic `necessary` grant enforcement and `denied` defaults for unspecified signals.
- **TypeScript definitions** — `ConsentPurpose` interface, `ZbAnalyticsConfig.consentPurposes` field, and full type declarations for all 4 new consent functions.
- **V58VersionConsistencyConsentPurposesTest** — 35+ test cases covering version unification across all source files, consent purposes config structure, JS/TS export completeness, catalog integrity, and source file counts.

### Changed
- **Version unification** — All version strings across 75+ locations (AnalyticsManager, AnalyticsEventController, 6 service files, JS client, TypeScript definitions, composer.json) unified to `2.58.0`. Eliminated stale `2.52.0`, `2.54.0`, and `2.57.0` references.

## [2.54.0] - 2026-08-07

### Fixed
- **Event name consistency** — Renamed `end_trial` to `trial_end` across SaaSEvents catalog key, EventTransformer (PostHog + Plausible maps), EventTaxonomyService, EventSchemaRegistry, and test files. The catalog key now matches the canonical event name, eliminating a `validateCatalog()` warning about mismatched name fields.

### Changed
- README test file count updated to 96+ (from 93+)

## [2.51.0] - 2026-08-07

### Added
- **AnalyticsEventRouter** — Config-driven event routing service that filters which providers receive specific events. Supports exact match, prefix wildcard (`add_to_*`), suffix wildcard (`*_click`), and catch-all (`*`) patterns. Events matching a rule are dispatched only to the listed providers. Unmatched events fall through to all enabled providers.
- **Config section `routing`** — New `zeroboiler.analytics.routing` config with `enabled` toggle and `rules` map. Supported provider names: `ga4`, `gtm`, `meta`, `plausible`, `posthog`, `webhook`.
- **Facade proxy methods** — Added `selectItem()`, `selectPromotion()`, `viewPromotion()`, `subscriptionRenewal()` to `@method` annotations for full IDE auto-complete coverage
- **AnalyticsConfig accessors** — `routingEnabled()`, `routingRules()` for type-safe routing config access
- **V47EventRouterFacadeVersionTest** — 30+ test cases covering AnalyticsEventRouter (pattern matching, wildcard rules, runtime rule management, summary, fall-through dispatch), Facade proxy completeness, version consistency across all 9 files, config section coverage, source file counts, and class architecture validation

### Changed
- Version bump to 2.47.0
- AnalyticsManager::version() returns '2.47.0'
- Composer version updated to 2.47.0
- JS client version string updated to 2.47.0 (5 occurrences)
- TypeScript definitions version updated to 2.47.0
- EventSourceTagger::_version updated to 2.47.0
- Controller version strings updated to 2.47.0 (38 occurrences)
- EventForwardingService version strings updated to 2.47.0 (3 occurrences)
- All 51 version references now consistently 2.47.0 (previously 2.45.0 in manager/source tagger/forwarding/controller and 2.46.0 in composer)

## [2.46.0] - 2026-08-07

### Added
- **LifecycleEventMapper expansion**: 20 new event mappings across 4 new categories (account lifecycle, B2B/team, billing, integrations), bringing total from 15 to 35 default mappings
- Account lifecycle events: `account.activated`, `account.deactivated`, `account.email_verified`, `account.password_changed`, `account.password_reset`, `account.profile_updated`
- B2B / Team lifecycle events: `team.created`, `team.member_joined`, `team.member_removed`, `team.role_changed`, `team.invite_sent`
- Billing lifecycle events: `billing.payment_succeeded`, `billing.payment_failed`, `billing.payment_method_added`, `billing.invoice_generated`, `billing.credit_applied`
- Integration lifecycle events: `integration.connected`, `integration.failed`
- Subscription renewal mapping: `subscription.renewal`
- Feature limit reached mapping: `feature.limit_reached`
- Param extractors for all new events with correct constructor argument mapping (team events, payment events, integration events, role changes, invites)
- `V46ComprehensiveLifecycleTest` — 15 tests validating all 35 mappings, 12 category coverage, config toggles per category, registration volume, and target class validity
- Lifecycle config toggles for all 20 new event keys in `config/zeroboiler.php`

### Changed
- Lifecycle config `events` array reorganized with category comments for discoverability
- Param extractors now use match expressions for per-class constructor mapping instead of reflection-only fallbacks

## [2.45.0] - 2026-08-07

### Fixed
- **Version consistency**: Updated all 41 controller endpoints, EventSourceTagger, EventForwardingService (Segment payload), JS client, TypeScript definitions, AnalyticsManager, and composer.json from 2.43.0/2.44.0 → 2.45.0
- Eliminated stale 2.43.0 version references that persisted through v2.44.0

### Added
- `V45ConfigCoverageVersionIntegrityTest` — comprehensive test suite validating all 60+ AnalyticsConfig accessors, summary() completeness (55+ sections), version consistency across 6 file types, CHANGELOG integrity, PHP 8.5 return type compliance, and class immutability
- Full accessor coverage tests for GA4, GTM, Meta Pixel, Plausible, PostHog, Webhook, Pipeline, Lifecycle, GDPR, Identity, API, Auto-Track, E-commerce, Revenue, Track Links, Queue config sections

### Changed
- Version consistency bump to 2.45.0 across all PHP source, JS client, TS definitions, and test files
- Updated V43 and V44 test version assertions to match 2.45.0

## [2.44.0] - 2026-08-07

### Added
- `AnalyticsConfig` expansion with 8 new attribute accessors: `attributionModel()`, `attributionSessionWindowDays()`, `attributionCacheTtl()`, `attributionFirstTouchTtl()`, `attributionTouchHistoryTtl()`, `attributionMaxTouchHistory()`, `referralEnabled()`, `referralParamName()`, `referralTtl()`, `referralTrackConversions()`
- Attribution config section with first-touch/multi-touch model, session window, and touch history TTL
- Referral tracking config section with configurable param name and conversion tracking
- `V44ConfigIntegrityTest` — config integrity, AnalyticsConfig expansion, attribution fix validation

### Fixed
- Attribution model and referral config accessors now have proper type-safe return declarations

## [2.43.0] - 2026-08-07

### Added
- `EventForwardingService` — forward analytics events to external platforms (Segment, Mixpanel, Amplitude, custom webhooks) with configurable timeout, retries, and rate limiting
- `PerformanceBudgetService` — enforce limits on event payload size, rate per session, and daily quotas with configurable max payload bytes, max params count, and per-user/per-day caps
- `AnalyticsConfig` expanded with forwarding and performance budget accessors
- `V43ForwardingBudgetAttributionTest` — comprehensive tests for forwarding, budget, and attribution features

## [2.42.0] - 2026-08-07

### Added
- Event forwarding config section (`forwarding`) with per-forwarder enable/disable, timeout, retries, and rate limiting
- Performance budget config section (`performance_budget`) with payload size, param count, session, and daily limits
- UTM attribution service and config expansion
- 68 events comprehensive README update with GDPR consent purposes documentation
- `V42SaaSStarterFinalTest` — comprehensive production readiness test suite

### Changed
- Version consistency bump to 2.42.0 across all controller endpoints and service files

## [2.41.0] - 2026-08-07

### Added
- **Account Lifecycle Events**: `AccountActivatedEvent`, `AccountDeactivatedEvent`, `PasswordChangedEvent`, `PasswordResetEvent`, `ProfileUpdatedEvent`, `EmailVerifiedEvent` — 6 new typed event classes for SaaS account management tracking
- **B2B / Team Events**: `TeamCreatedEvent`, `TeamMemberJoinedEvent`, `TeamMemberRemovedEvent`, `RoleChangedEvent` — 4 new typed event classes for multi-tenant SaaS collaboration tracking
- **Billing Events**: `PaymentFailedEvent`, `PaymentSucceededEvent`, `PaymentMethodAddedEvent`, `InvoiceGeneratedEvent`, `CreditAppliedEvent` — 5 new typed event classes for payment and billing lifecycle tracking
- `ConsentLogService` — granular GDPR consent tracking with audit trail, per-purpose consent management, DSAR export, and cache-backed history
- `consent.purposes` config section (necessary, analytics, marketing, functional) with required/default flags for consent banners
- `consent.log_enabled` and `consent.log_ttl` config options for consent audit logging
- `AnalyticsConfig::consentPurposes()`, `consentLogEnabled()`, `consentLogTtl()` accessors
- Inertia middleware `consentPurposes` prop exposure for frontend consent banner integration
- Full PostHog event name mappings for all 15 new events
- SaaS event catalog expanded from 20 → 35 events

### Changed
- Version consistency bump to 2.41.0 across AnalyticsManager, 26 controller endpoints, JS client, TS definitions, EventSourceTagger, and 18 test files

## [2.40.0] - 2026-08-07

### Added
- `subscription.renewal` auto-track mapping in `ServerSideTracker`
- `ecommerce.shipping_default` config option for default shipping value in e-commerce events
- `revenue` config section (`currency`, `billing_cycle_default`) for SaaS revenue tracking defaults
- `AnalyticsConfig::ecommerceShippingDefault()`, `revenueCurrency()`, `revenueBillingCycleDefault()` accessors

### Changed
- Version consistency bump to 2.40.0 across all 26 controller endpoints, AnalyticsManager, JS client, TS definitions, EventSourceTagger, and 17 test files

## [2.39.0] - 2026-08-07

### Fixed
- Added missing `:void` return type to `SubscriptionRenewalEvent` constructor (PHP 8.5 compliance)

## [2.38.0] - 2026-08-07

### Added
- JS client SaaS starter completeness (8 new convenience trackers, full TS parity)
- Version consistency across all 26 controller endpoints + tests

## [2.37.0] - 2026-08-07

### Added
- `subscription_renewal` event with full PostHog mapping
- `AnalyticsConfig` expanded accessors (8 new sections)

## [2.36.0] - 2026-08-07

### Fixed
- Removed deprecated `setAccessible(true)` calls in tests (PHP 8.5 compliance)

## [2.35.0] - 2026-08-07

### Added
- TypeScript type definitions, `sendBeacon` unload flush

## [1.0.0] - 2026-08-01

### Added
- Multi-provider analytics tracking (GA4, GTM, Meta Pixel, Plausible, PostHog)
- Event pipeline with middleware (PII sanitization, consent gating, schema validation, deduplication)
- SaaS event tracking (subscription, revenue, cohort, feature usage, invite, trial)
- Ecommerce event tracking (purchase, add to cart, checkout, refund, wishlist, item views)
- Engagement event tracking (page view, click, scroll, form, session, web vitals, errors)
- Real-time aggregation, anomaly detection, funnel analytics
- GDPR erasure, data retention policies, tenant isolation
- Queue-based event replay and dead letter queue
- Inertia.js integration, UTM attribution, revenue attribution
- CLI commands for analytics testing, export, and revenue reporting
- Config-driven architecture with `AnalyticsConfig`
- PHP 8.5 attributes, readonly DTOs, final service classes
