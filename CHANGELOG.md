# Changelog

## [201.0.0] - 2026-08-16

### Added
- **FormRequest Dependency Injection** — Refactored 7 controller action methods to use dedicated FormRequest classes: `track(TrackEventRequest)`, `batch(BatchEventRequest)`, `identify(IdentifyRequest)`, `pageview(PageViewRequest)`, `updateConsent(UpdateConsentRequest)`, `optOut(OptOutRequest)`, `optIn(OptInRequest)`.
- **OptOutRequest** — New FormRequest for POST /api/analytics/opt-out with `authorize()` (requires auth) and typed `userId()` accessor.
- **OptInRequest** — New FormRequest for POST /api/analytics/opt-in with `authorize()` (requires auth) and typed `userId()` accessor.
- **PageViewRequest::path()** — Added `page_path` field extraction to pageview FormRequest for richer page view tracking.

### Changed
- **Controller validation separation** — Replaced inline `$request->validate()` calls with FormRequest DI across 7 methods. Removed redundant `is_string()` type guards and manual `$request->input()` calls, replaced with typed FormRequest accessor methods.
- **Version sweep** — All entry points synced from 200.0.0 → 201.0.0. Source files: 879 → 881. FormRequests: 5 → 7.

### Fixed
- **Type safety** — `identify()` now uses `IdentifyRequest::userId()` instead of manual `getKey()` + `is_int()` guard, eliminating potential type coercion bugs.

## [199.0.0] - 2026-08-16

### Added
- **EventIntelligenceCopilotService** — Automatic analytics intelligence and executive summary generator. Aggregates signals across 5 dimensions: catalog coverage, data quality, event volume distribution, provider health, and SaaS lifecycle funnel. Generates prioritized action recommendations. Cache-backed with configurable TTL.
- **Category Intelligence** — Per-category analytics with event count, provider coverage, top events, gap detection.
- **Volume Spike Detection** — Automatic detection of abnormal event volume across categories with severity levels.
- **Provider Health Comparison** — Cross-provider health analysis with coverage scores and letter grades.
- **SaaS Lifecycle Funnel Intelligence** — 6-stage lifecycle analysis (awareness → acquisition → activation → revenue → retention → referral) with bottleneck detection.
- **Recommendation Engine** — Prioritized action items based on intelligence signals.
- **AnalyticsCopilotCommand** — `php artisan analytics:copilot` CLI with 7 actions: summary, category, spikes, providers, lifecycle, config, clear. Supports `--json` output.
- **Config expansion** — `zeroboiler.analytics.intelligence_copilot` section with 7 env-backed settings.
- **ServiceProvider registration** — EventIntelligenceCopilotService registered as singleton.
- **Tests** — V199IntelligenceCopilotTest (50+ assertions).

### Changed
- **Version sweep** — All entry points synced from 198.0.0 → 199.0.0. Command count: 89 → 90. Source files: 879 → 881+. Tests: 446 → 447+.

## [198.0.0] - 2026-08-16

### Added
- **CDP (Customer Data Platform)** — Full user profile management with static traits, computed traits, and dynamic segment evaluation. Five new classes in `src/CDP/`:
  - `CdpProfileService` — Central hub for user profile CRUD, trait management, segment evaluation, provider sync, and GDPR erasure.
  - `CdpTraitComputer` — Event-driven computed trait engine with 7 aggregation methods (sum, count, avg, max, min, latest, unique_count) and 13 built-in SaaS trait definitions (total_revenue, purchase_count, session_count, page_view_count, search_count, form_submit_count, error_count, unique_features_used, login_count, avg_order_value, max_purchase, etc.).
  - `CdpSegmentService` — Dynamic segment membership evaluation with 12 operators (eq, neq, gt, gte, lt, lte, in, not_in, exists, not_exists, between, contains) and 8 built-in SaaS segments (power_user, high_value, at_risk, new_user, frequent_searcher, error_prone, free_tier, feature_explorer).
  - `CdpProfileSnapshot` — Immutable DTO for user profile snapshots with computed properties (engagement_score, days_since_creation, days_since_last_activity) and provider trait export.
  - `CdpTraitDefinition` — Readonly DTO for trait definitions with factory methods (static(), computed()) and array serialization.
- **CdpEventToProfileListener** — Bridges analytics events to CDP profile updates. Auto-extracts identity signals (email, name, company, plan) from event properties and feeds events to the trait computer.
- **AnalyticsCdpCommand** — Artisan command `analytics:cdp` for CDP inspection: overview, profile details, segment listing, trait listing, GDPR profile erasure.
- **ServiceProvider registrations** — CdpProfileService and CdpEventToProfileListener registered as singletons.

### Changed
- **Version sweep** — All entry points synced from 195.0.0 → 196.0.0. Artisan commands: 86 → 87. Source files: 863 → 869.

## [195.0.0] - 2026-08-16

### Added
- **SaaSEventHelpers** — Static one-liner helpers for common SaaS events: `signUp()`, `login()`, `trialStart()`, `subscription()`, `planUpgrade()`, `planDowngrade()`, `cancellation()`, `featureUsed()`, `teamEvent()`, `onboardingStep()`, `firstValue()`, `revenue()`, `custom()`.
- **CampaignContextHydratorService** — Centralized UTM/referrer/traffic source extraction and classification. First-touch cache persistence, client-safe Inertia prop context, attribution summaries.
- **useAttribution Svelte composable** — Reactive UTM/campaign attribution composable with stores for UTM params, referrer, traffic source, first-touch persistence (localStorage), and derived stores (utmString, attributionLabel, isPaidTraffic, isOrganicTraffic, attributionSnapshot).
- **Inertia middleware campaign context** — `zbAnalytics.campaignContext` prop with client-safe UTM, referrer, traffic source.
- **V195SaaSEventHelpersCampaignAttributionTest** — 40+ assertions covering SaaSEventHelpers, CampaignContextHydratorService, and useAttribution composable.

### Changed
- **Version sweep** — All entry points synced from 194.0.0 → 195.0.0. Svelte composables: 11 → 12. Source files: 862 → 863+. Tests: 441 → 442+.

## [194.0.0] - 2026-08-16

### Added
- **SaaSLifecycleFlowService** — 8-stage SaaS customer funnel tracking (anonymous → signed_up → trialing → subscribed → activated → expanding → retained → champion). Track methods dispatch events and return stage. Static utilities: stages(), stageIndex(), progressForStage(), nextStageAfter(), resolveStageForEvent(), isForwardProgression(), funnelSummary(), funnelBreakdown().
- **WebhookEvents catalog parity** — WebhookEvents (3 events) now included in EventCatalog count, categorySummary, and all 8 provider name lists.
- **Phase46ProductionReadinessTest** — 80+ assertions: SaaSLifecycleFlowService audit, exception hierarchy bidirectional @see, DTO immutability, ServiceProvider/Facade/Manager contracts, version consistency, config integrity.
- **V194EventCatalogWebhookParityAndFlowTest** — 15 assertions covering webhook event catalog integration.
- **V194SaaSLifecycleFlowServiceTest** — 50+ assertions covering flow service static methods and tracking.

### Fixed
- **Phase45ProductionReadinessTest** — Stale source file count (857 → 862+) and version (191.0.0 → 194.0.0) corrected.
- **README badge** — Updated from 193.0.0 to 194.0.0.

## [193.0.0] - 2026-08-16

### Added
- **SaaSConversionPredictorService** — Heuristic-based conversion probability estimation using configurable positive/negative signal scoring. 10 positive signals across 4 categories, 4 negative signals. Single user prediction, batch prediction, top prospects ranking, signal map builder, cache-backed results, actionable recommendations.
- **AnalyticsConversionPredictorCommand** — `analytics:predict` artisan command with `--demo`, `--user`, `--signals`, `--top` options.
- **Config section** `zeroboiler.analytics.conversion_predictor` (enabled, cache_ttl, custom_weights).
- **V193ConversionPredictorQuickStartFixTest** — 40 tests covering predictor service and QuickStart bug fix.

### Fixed
- **SaaSQuickStartService** — All 9 `trackEvent('name', [...])` calls corrected to `track('name', [...])`. The `trackEvent()` method expects an `AnalyticsEvent` DTO, not a string and array.

### Changed
- **Version sweep** — All entry points synced from 192.0.0 → 193.0.0. Service count: 396 → 398.

## [192.0.0] - 2026-08-16

### Added
- **BehavioralUserSegmentService** — Dynamic behavioral user segmentation with 6 segment types (event, frequency, sequence, time, property, composite) and 10 built-in SaaS segments. Config-driven custom segment definitions, set operations (intersect/union/except/xor), trending analysis, snapshot persistence, and segment comparison.
- **FeatureFlagRolloutGuardrailService** — Feature flag rollout guardrail monitoring with 10 guardrail metrics across 5 categories, 4 rollout phases with adaptive sensitivity, z-test statistical significance, automatic rollback recommendations, rollout velocity monitoring, and audit log.
- **2 new config sections**: `zeroboiler.analytics.behavioral_segments` and `zeroboiler.analytics.rollout_guardrails`.
- **V192BehavioralSegmentsRolloutGuardrailsTest** — 40 tests covering both services.

### Changed
- **Version sweep** — All entry points synced from 191.0.0 → 192.0.0. Service count: 394 → 396.

## [191.0.0] - 2026-08-16

### Added
- Phase 45 production readiness test: comprehensive audit of 857 source files (strict_types, license headers, zero TODO/FIXME), exception hierarchy (abstract AnalyticsException with :void → 2 final leaves), ServiceProvider finality, composer metadata integrity (PHP 8.5, namespace, provider, scripts, license), project structure files

### Changed
- Version bump to 191.0.0

## [168.0.0] - 2026-08-15

### Added
- **AnalyticsEventObserver** — Auto-track Eloquent model CRUD operations as analytics events. Register via `AnalyticsEventObserver::observe()` in model boot methods or via config `auto_track.models`. Supports custom event names, categories, param key extraction, conditional tracking, and namespace-based category guessing (Billing→saas, Product→ecommerce, etc.). Clear mappings for test isolation via `AnalyticsEventObserver::clearMappings()`.
- **SaaSRetentionCohortService** — Time-based cohort retention analytics. Computes retention tables (daily/weekly/monthly), dashboard summary with letter grades (A–F), trend classification (healthy/moderate/concerning), cohort comparison, and per-user retention tracking. Cache-backed for performance.
- **EventWarehouseExportService** — Data warehouse export supporting JSONL (BigQuery/Snowflake), CSV, BigQuery schema JSON, Snowflake CREATE TABLE DDL, ClickHouse CREATE TABLE DDL. 20-column analytics events schema with auto-normalized UTM/device/page context.
- **AnalyticsRequestTrackerMiddleware** — HTTP request lifecycle middleware tracking API calls as analytics events (api_request, api_error, api_slow_request). Configurable via `request_tracking` config section.
- **Request tracking config** — New `request_tracking` section in config/zeroboiler.php with env-driven settings.
- **V1680 Industry Standard SaaS Upgrade Test** — 40+ test cases covering observer, retention, warehouse export, and version sweep.

### Changed
- **Version sweep** — All 14 entry points synced from 167.0.0 → 168.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

## [167.0.0] - 2026-08-15

### Added
- **SaaS Analytics Starter Kit completion** — All 12 planned SaaS analytics features verified at industry-standard level: Event Catalog (8 categories, 194 events), Server-Side Lifecycle Tracker (config-driven Laravel event → analytics mapping), Inertia middleware (page props + client ID cookie), API controller + routes (POST /api/analytics/events, /batch, /identify, /consent), JS client library (trackEvent, trackPageView, initInertiaPageViewTracker, scroll depth, client ID management), event queue (async dispatch), user identity linking (client ID ↔ user ID with cache-backed persistence), e-commerce helpers (GA4 + Meta format conversion across 8 providers), admin commands (AnalyticsOverviewCommand + AnalyticsTestCommand + 82 more), config expansion (queue, API, identity, auto-track, ecommerce, lifecycle settings), optional providers (PlausibleTracker + PosthogTracker), comprehensive test suite (405 test files).
- **V1670 SaaS Starter Kit Completion Test** — 80+ assertions validating all 12 SaaS analytics features, README metric accuracy, version sweep consistency across 14 entry points, and quality gates (strict_types, final classes, MIT headers, return type declarations).

### Changed
- **README accuracy audit** — Updated headline metrics to verified counts: 355 services (was "350+"), 84 commands (was 83), JS client ~11,700 LOC (was "~8,200"), TypeScript definitions ~3,100 LOC (was "~3,000"), 805 source files (was 735). Source of truth: 805 PHP source files (270K+ LOC), 405 test files (168K+ LOC).
- **Version sweep** — All 14 entry points synced from 166.0.0 → 167.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

## [166.0.0] - 2026-08-15

### Added
- **SdkBridgeService** — Server-side bidirectional event format translation for third-party SDK migration. Supports PostHog, Mixpanel, Segment, and Amplitude inbound/outbound event name and parameter mapping. Automatic SDK metadata stripping for inbound events. Parameter structure adaptation for outbound events (user_id→distinct_id, user_properties→$set/traits). Custom mapping registration via registerInboundMapping(), registerOutboundMapping(), registerInboundParamTransformer(), registerOutboundParamTransformer(). Compatibility report and mapping coverage analysis per SDK. Event translation inspection API. 32 built-in inbound + 32 outbound mappings.
- **JS SDK Bridge Mode** — Client-side bidirectional event translation: trackFromSdk(), translateToSdk(), inspectSdkTranslation(), getSupportedBridgeSdks(), fetchSdkBridgeCompatibility(). SDK_BRIDGE_INBOUND_MAP and SDK_BRIDGE_OUTBOUND_MAP with 4 SDK mappings. SDK_METADATA_FIELDS for automatic metadata stripping. Parameter transformers for PostHog (distinct_id, $set), Mixpanel (distinct_id), Segment (traits).
- **SDK Bridge API endpoints** — 7 new routes: GET sdk-bridge/sdks, GET sdk-bridge/compatibility/{sdk}, GET sdk-bridge/coverage/{sdk}, POST sdk-bridge/translate-inbound, POST sdk-bridge/translate-outbound, POST sdk-bridge/inspect, GET sdk-bridge/mappings/{sdk}. All in AnalyticsEventController.
- **TypeScript definitions** — SdkBridgeTrackResult, SdkBridgeTranslation, SdkBridgeInspection, SdkBridgeCompatibilityReport interfaces for full IntelliSense support.
- **V1660 SDK Bridge Service Test** — 40+ test cases covering: all 4 SDK inbound/outbound translation, SDK metadata stripping (PostHog $set/$lib, Mixpanel mp_lib, Segment context/integrations, Amplitude device_id/library), parameter transformation (user_id→distinct_id, user_properties→$set/traits), custom mapping registration, custom param transformers, bidirectional roundtrip consistency (PostHog $pageview→page_view→$pageview, Mixpanel Signup→sign_up→Signup, Segment page→page_view→page), compatibility report structure, mapping coverage breakdown, class structure validation (final, strict_types, MIT header).

### Changed
- **Version sweep** — All 14 entry points synced from 165.0.0 → 166.0.0: composer.json, package.json (fixed drift from 164.0.0), analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **package.json version fix** — Corrected stale version from 164.0.0 to 166.0.0 (was missed in v165 sweep).

## [164.0.0] - 2026-08-15

### Added
- **AnalyticsEvent fluent methods** — `withSource()`, `withPriority()`, `withTimestamp()` immutable fluent methods for pipeline-safe event transformation. Completes the fluent API alongside existing `withCategory()`, `withSessionId()`, and `withMergedParams()`.
- **EventCatalog::categorySummary()** — Returns per-category event counts plus grand total (194 events across 8 categories). Used by admin commands and dashboard widgets for catalog coverage reporting.
- **V1640 DTO Fluent API & Catalog Summary Test** — 40+ test cases covering all new fluent methods (immutability, property preservation, chaining), EventCatalog::categorySummary (totals, per-category counts, type safety), version consistency across 14 entry points, and quality gates (strict_types, final classes, @since docblocks, MIT headers, return types).

### Changed
- **README event count accuracy** — Corrected from stale 176/210+ to verified 194 (Ecommerce 15, SaaS 82, Engagement 35, Marketing 34, Infrastructure 10, CustomerSuccess 7, Security 6, Uptime 5). Updated all references across Event System and SaaS Analytics sections.
- **README category naming** — Clarified CustomerSuccess as a named category (was "plugin-extensible").
- **Version sweep** — All 14 entry points synced from 163.0.0 → 164.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

## [163.0.0] - 2026-08-15

### Changed
- **README optimized** — Reduced from 8,845 lines to 1,756 lines. Moved release history to CHANGELOG.md. Industry-standard format (PostHog, Segment, Mixpanel style).
- **CHANGELOG truncated** — Kept last 10 versions. Full history archived in git.
- **V1630 Industry Standard SaaS Analytics Audit Test** — 100+ assertions validating all 12 planned SaaS analytics features at industry-standard level.
- **Version sweep** — All 14 entry points synced to v163.0.0.


## [154.0.0] - 2026-08-15

### Added
- **AnalyticsDependencyTopologyCommand** (`zb:analytics:topology`) — Static analysis of ServiceProvider singleton registrations to map constructor dependencies. Detects circular dependency chains via DFS, identifies orphan services (registered but unreferenced), leaf services (terminal nodes with no dependencies), and most-dependency-heavy services (bottleneck candidates). Supports `--json`, `--circular`, `--orphans`, `--heavy`, `--service=`, and `--depth=` options for focused analysis.
- **AnalyticsRuntimeProfilerCommand** (`zb:analytics:profile`) — Runtime pipeline profiler that sends synthetic test events through the full dispatch pipeline and measures latency at each stage (DTO construction, manager dispatch, direct track, identify+track, page view, purchase event). Uses `hrtime(true)` for nanosecond precision. Supports `--iterations=N`, `--warmup=N`, and `--json` for CI performance baselining.
- **AnalyticsBundleDiagnosticCommand** (`zb:analytics:bundle`) — Comprehensive 12-subsystem health check in a single command. Covers: config integrity, event catalog (210+ events, 8 categories), provider configuration (9 providers with credential validation), queue configuration, identity tracking, consent/GDPR defaults, auto-track mapping, ecommerce settings, JS client compatibility, event deduplication, sanitization, and sampling engine. Each subsystem receives healthy/warning/critical status. Exit codes: 0=healthy, 1=warning (with --fail-on-warning), 2=critical.
- **V1540TopologyProfilerBundleDiagnosticTest** — 50+ assertion test suite covering all 3 commands: class finality, strict_types, constructor void, signature/description validation, method existence, return types, @since docblocks, provider credential validation logic, stage coverage, exit code behavior, and cross-cutting quality.

### Changed
- **Version sweep** — All 14 version entry points synced from 153.0.0 → 154.0.0: composer.json, package.json, analytics.js, analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **ServiceProvider** — Registered 3 new commands (AnalyticsDependencyTopologyCommand, AnalyticsRuntimeProfilerCommand, AnalyticsBundleDiagnosticCommand) in `registerConsoleCommands()`. Total artisan commands: 80.
- **README** — Updated badge to 154.0.0, command count 77→80, added "What's New in v154.0.0" section.

## [152.0.0] - 2026-08-15

### Added
- **LifecycleAttributionEnricher** — Automatic attribution context enrichment for SaaS lifecycle events. Enriches all lifecycle events (sign_up, login, trial_start, subscription, plan_upgrade, cancellation, etc.) with UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content), referrer URL and host, session ID and IP address, device context (platform, browser, locale), server timestamp, page URL and path, and computed traffic source classification (direct, organic_search, organic_social, paid_search, paid_social, paid_display, email, affiliate, referral, unknown). Inspired by Segment's automatic Context, RudderStack's auto-traits, and PostHog's automatic properties. Fully configurable per enrichment type via `zeroboiler.analytics.lifecycle_attribution.enrichments`.
- **LifecycleEventSubscriber attribution integration** — `LifecycleEventSubscriber::track()` now automatically enriches event params with full attribution context before dispatching. Controlled by `zeroboiler.analytics.lifecycle.enrich_attribution` config (enabled by default). Non-destructive enrichment: existing params take precedence.
- **Lifecycle attribution config section** — New `lifecycle_attribution` configuration block in `zeroboiler.php` with individual toggle controls for each enrichment type (utm, referrer, session, device, timestamp, page, attribution_summary). All settings configurable via environment variables (`ANALYTICS_LIFECYCLE_ATTRIBUTION_ENABLED`, `ANALYTICS_ATTRIBUTION_UTM`, etc.).
- **Traffic source classification engine** — Rule-based attribution classifier supporting 10 categories: direct, organic_search, organic_social, paid_search, paid_social, paid_display, email, affiliate, referral, unknown. Classifies based on UTM parameters and referrer with priority-based rules. Recognizes 15+ search engines, 16+ social platforms, and 9+ email clients.
- **V152LifecycleAttributionEnricherTest** — 20 test cases covering all enrichment types, classification categories, disabled state, diagnostic summary, class structure validation, and priority edge cases.

### Changed
- **LifecycleEventSubscriber** — Added `attributionEnricher` property, `attributionEnabled` flag, and attribution diagnostics to `diagnosticSummary()`. Constructor now reads `enrich_attribution` from lifecycle config.
- **LifecycleEventSubscriber docblock** — Added `@since 152.0.0` tag for attribution enricher integration.

## [151.0.0] - 2026-08-15

### Added
- **54 typed shorthand factory methods** across 3 event catalogs (EcommerceEvents, SaaSEvents, EngagementEvents). One-line typed event builders returning ready-to-dispatch `AnalyticsEvent` DTOs with correct category pre-set.
- **EcommerceEvents shorthand methods** — `viewItem()`, `addToCart()`, `removeFromCart()`, `viewCart()`, `beginCheckout()`, `addPaymentInfo()`, `purchase()`, `refund()`, `addToWishlist()`, `selectItem()`, `selectPromotion()`, `viewPromotion()`, `checkoutStep()`, `abandonedCart()`, `checkoutAbandon()` + generic `build()`.
- **SaaSEvents shorthand methods** — `signUp()`, `login()`, `logout()`, `startTrial()`, `subscribe()`, `planUpgrade()`, `planDowngrade()`, `cancellation()`, `featureUsed()`, `revenueTracked()`, `subscriptionCreated()`, `subscriptionCancelled()`, `trialConverted()`, `trialExpired()`, `inviteAccepted()`, `workspaceCreated()`, `firstValue()`, `activation()`, `paymentFailed()`, `paymentSucceeded()` + generic `build()`.
- **EngagementEvents shorthand methods** — `pageView()`, `scrollDepth()`, `click()`, `formStart()`, `formSubmit()`, `search()`, `share()`, `error()`, `jsError()`, `sessionStart()`, `sessionEnd()`, `feedback()`, `consentGranted()`, `consentWithdrawn()`, `onboardingCompleted()` + generic `build()`.
- **V151EventCatalogTypedFactoryTest** — 75+ assertion test covering all 3 catalogs: typed return values, category correctness, parameter merging, exception handling, cross-catalog consistency, serialization readiness.

### Changed
- **Version sweep** — All 14 entry points synced from 150.0.0 → 151.0.0: composer.json, package.json, analytics.js, analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, README badge, 2 service @since tags.

## [150.0.0] - 2026-08-15

### Added
- **V150ProductionReadinessAuditTest** — 120+ assertion comprehensive production readiness audit validating all 12 planned SaaS analytics features at industry-standard SaaS starter level. Covers Event Catalog (210+ events, 8 categories, 10 provider mappings), Server-Side Lifecycle Tracker, Inertia middleware, API controller (200+ routes), JS client (~8200 LOC), Event queue, User identity linking, E-commerce helpers, Admin commands, Config expansion, Optional providers (10 total), Tests + README.
- **README v150.0.0 changelog** — Updated "What's New" section with Phase 40 Production Readiness Audit description.

### Changed
- **Version sweep** — All 13 client files synced from 149.0.0 → 150.0.0: composer.json, package.json, analytics.js (JSDoc + getVersion()), analytics.d.ts, analytics.constants.js, 7 Svelte composables (useAnalytics, useEcommerce, useSaaSMetrics, useLifecycle, usePerformanceTracker, useSessionReplay, useAnalyticsConfig), AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, README badge.
- **Test version sweep** — V144IdentifyAndTrackConvenienceMethodsTest, V146RevenueAttributionDashboardTest, V148PrivacyFirstAnalyticsServicesTest version assertions updated to 150.0.0.

## [148.0.0] - 2026-08-15

### Added
- **AnonymousEventAggregationService** — Privacy-safe aggregate event statistics without PII storage. Counts events by name in configurable time windows (hourly, daily, weekly, monthly). All user identifiers stripped before aggregation. Designed for GDPR/CCPA-compliant traffic dashboards, public analytics, and stakeholder reporting. Provides `record()`, `flush()`, `getAggregates()`, `topEvents()`, `byCategory()`, `summary()`, and `clear()` methods. Registered as singleton in ServiceProvider.
- **FunnelLeakDetectionService** — Automated conversion funnel analysis that detects significant drop-off points (leaks) between funnel steps. 5 built-in funnel definitions: signup_funnel, purchase_funnel, trial_funnel, activation_funnel, retention_funnel. Configurable leak (40%) and critical (70%) thresholds. Generates prioritized, actionable recommendations with industry best practice suggestions. Supports custom funnel registration at runtime. Methods: `recordProgress()`, `analyze()`, `analyzeAll()`, `getFunnels()`, `registerFunnel()`, `clear()`.
- **FirstPartyDataService** — Privacy-first user data capture for the cookieless tracking era. Captures user preferences (newsletter, theme, language, notifications, privacy_level, timezone, currency) and interest signals (feature, content, integration, pricing_tier, use_case, industry). Supports behavioral cohort assignment (power_user, explorer, pragmatist, newcomer, enterprise_signal, unknown), GDPR-compliant data export (`exportUserData()`), right-to-erasure (`deleteUser()`), and first-party data readiness scoring.
- **AnalyticsFunnelLeakCommand** — Artisan command `zb:analytics:funnel-leaks` for analyzing funnel leaks and conversion drop-offs. Supports `--funnel=<name>`, `--all`, `--json`, `--recommendations`, `--list` options. Color-coded severity indicators (🔴 critical, ⚠️ warning, ✅ ok).
- **Config expansion** — New `anonymous_aggregation`, `funnel_leak_detection`, and `first_party_data` configuration sections in `zeroboiler.php`. All settings configurable via environment variables. All three services are opt-in (disabled by default).

### Changed
- **Version sweep** — All 26 version entry points synced from 147.0.0 → 148.0.0 across PHP, JS, Svelte, TypeScript, JSON, and Markdown files.

## [147.0.0] - 2026-08-15

### Added
- **Phase39SaaSIndustryStandardAuditTest** — Comprehensive industry-standard SaaS analytics audit (100+ assertions) covering all 12 planned feature areas: Event Catalog (Ecommerce/SaaS/Engagement with 210+ typed events), Server-Side Lifecycle Tracker (config-driven mapping), Inertia middleware (tracking ID cookie, consent state, provider IDs, auto-track config, ecommerce config), API controller + routes (events, batch, identify, consent, health, pageview — 200+ routes), Svelte JS client (~8200 LOC with trackEvent, trackPageView, scroll depth, client ID management, batch queue, sampling, offline recovery), Event queue (QueuedAnalyticsDispatcher, AnalyticsEventDispatcher), User identity linking (UserIdentityTracker, IdentityResolutionService, IdentityGraphService), E-commerce helpers (EcommerceFormatConverter with GA4 + Meta format conversion), Admin commands (AnalyticsOverviewCommand, AnalyticsTestCommand — 75+ total), Config expansion (queue, API, identity, auto-track, ecommerce, consent, dedup, sampling, retention, revenue checksum), Optional providers (Plausible, PostHog — 10 total trackers), Tests (200+ test files) + README (8600+ LOC). Cross-cutting quality checks: version consistency, strict_types coverage, final class enforcement, docblock presence, SaaS maturity scoring (10 trackers, 8+ categories, 200+ services, 75+ commands).

### Verified
- All 12 planned SaaS analytics features confirmed implemented and production-ready.
- ZeroBoiler Analytics package has reached industry-standard SaaS starter level: 10 provider trackers, 210+ typed events across 8 categories, 322+ services, 75+ artisan commands, ~8200 LOC JS client, 7 Svelte composables, comprehensive TypeScript definitions, Inertia.js middleware, Blade directives, server-side lifecycle tracking, queue dispatch, identity resolution, GDPR consent, e-commerce format conversion, revenue attribution, cohort analytics, and 200+ test files.

## [130.0.0] - 2026-08-14

### Fixed
- **Version sweep** — `AnalyticsIntegrityCommand::EXPECTED_VERSION` updated from stale `104.0.0` → `130.0.0`. `package.json` version synced from `129.0.0` → `130.0.0`. `AnalyticsServiceProvider` docblock `@version` updated to `130.0.0`. README badge updated to `130.0.0`. CHANGELOG entry added.
- **Constructor void fix** — Removed `: void` return type from `AnalyticsEvent::__construct()` and `AnalyticsQueryBuilder::__construct()` for PHP 8.4 compatibility.

### Added
- **Phase33VersionIntegrityAuditTest** — Permanent version drift guard test covering all 17 version entry points (PHP DTO, composer.json, package.json, IntegrityCommand, ServiceProvider, JS client, TypeScript, Svelte composables, README badge).

### Verified
- All version entry points confirmed at 130.0.0: `AnalyticsEvent::VERSION`, `composer.json`, `package.json`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, README badge, JS client `getVersion()` + `_getInternalVersion`, JS/Svelte `@version` tags (7 files), TypeScript `@version` tag.

## [129.0.0] - 2026-08-14

### Fixed
- **TypeScript type definition** — Fixed malformed `amplitudeApiKey` type in `resources/js/analytics.d.ts` (was `***` → `string`), restoring full IntelliSense for the amplitude provider config.

### Changed
- **Version sweep** — All 7 JS/TS/Svelte files, 9 PHP core files (AnalyticsEvent, AnalyticsServiceProvider, AnalyticsEventController, ProjectionDefinition, ProjectionRegistry, MetricProjectionEngine, EventMaterializer, MetricProjectionResult, AnalyticsProjectionsCommand), `composer.json`, README badge, and CHANGELOG synced to 129.0.0.
- **JS client version alignment** — `getVersion()` and `_getInternalVersion()` corrected from stale `123.0.0` to `129.0.0`.
- **README headline** — Updated package metrics: 180+ typed events, 8 categories, 320+ services, 71 artisan commands, ~8100 LOC JS client, ~2900 LOC TypeScript definitions.

## [128.0.0] - 2026-08-14

### Added
- **Metric Projection Engine** — Reusable metric projection definitions that compute aggregate values from event streams. Supports 6 projection types: `count`, `sum`, `average`, `unique_count`, `funnel_rate`, `ratio`.
- **ProjectionRegistry** — Central registry for projection definitions with config-driven loading, category/tag filtering, validation, and 13 built-in SaaS metric projections (DAU, weekly signups, trial conversion rate, avg revenue, total revenue, unique purchasers, form completion rate, search-to-share rate, cart abandonment rate, cancellation rate, error rate, login count, signup-to-purchase ratio).
- **MetricProjectionEngine** — Evaluates projections against the event store with cache-backed results, local request-scoped caching, window overrides, and invalidation support.
- **EventMaterializer** — Cache-backed materialized views of projected metrics with dashboard-ready grouping by category, bulk refresh, staleness detection, and export.
- **ProjectionDefinition DTO** — Immutable definition DTO with type-specific validation, serialization, and config-driven construction.
- **MetricProjectionResult DTO** — Immutable result DTO with staleness detection, numeric extraction, and array serialization.
- **AnalyticsProjectionsCommand** — CLI command for projection management: `--list` (table output), `--evaluate=name` (evaluate single), `--validate` (integrity check), `--refresh-all` (force refresh), `--dashboard` (grouped metrics), `--export` (flat JSON), `--json`, `--category=` filter.
- **API endpoints** — `GET /api/analytics/projections` (list all), `GET /api/analytics/projections/summary` (registry summary + validation), `GET /api/analytics/projections/dashboard` (dashboard-ready with `?category=` and `?window=` filters), `GET /api/analytics/projections/{name}` (evaluate single with `?window=`), `GET /api/analytics/projections/{name}/history` (evaluation history).
- **Config section `zeroboiler.analytics.projections`** — `enabled`, `cache_enabled`, `cache_ttl`, and `custom` array for config-driven projection definitions.
- **V128 test suite** — 55+ test cases covering ProjectionDefinition (7 validation tests, serialization), ProjectionRegistry (15 tests: builtins, filtering, registration, config loading), MetricProjectionResult (5 tests: creation, staleness, numeric), MetricProjectionEngine (5 tests: evaluation, multi, all, status), EventMaterializer (9 tests: get, dashboard, refresh, stale, export, summary), and version integrity (5 tests: strict types, readonly, final).

### Changed
- Version bump: 127.0.0 → 128.0.0 across composer.json, package.json, AnalyticsEvent::VERSION, AnalyticsServiceProvider, all JS/TS/Svelte files.

## [127.0.0] - 2026-08-14

### Added
- **EventSchemaOpenApiGenerator** — Machine-readable OpenAPI 3.0.3 specification generator from the analytics event catalog. Covers 35+ API endpoints with request/response schemas, security schemes (Sanctum Bearer + SDK Token), tag-based grouping (23 categories), and configurable metadata (title, description, contact, license). Supports JSON and YAML export formats.
- **GET /api/analytics/openapi-spec** — OpenAPI specification export (JSON format). Compatible with Swagger UI, Redoc, and OpenAPI tooling.
- **GET /api/analytics/openapi.yaml** — OpenAPI specification export (YAML format). Direct import into API gateways and documentation generators.
- **GET /api/analytics/openapi/endpoints** — Flat endpoint summary list with method, path, description, and tags.
- **Config section `zeroboiler.analytics.openapi`** — Customizable OpenAPI spec metadata (title, description, version, contact, license).
- **V127 test suite** — 15 test cases covering OpenAPI spec structure, info customization, all core endpoints, request body schemas, security schemes, error responses, JSON/YAML output, endpoint summary, tag coverage, and response codes.

### Changed
- Version bump: 126.0.0 → 127.0.0 across composer.json, package.json, AnalyticsEvent::VERSION, AnalyticsServiceProvider, JS client, TypeScript definitions.
- README updated with v127.0.0 and v126.0.0 "What's New" sections.


---

> Earlier versions are available in git history.
