# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

## [2.27.0] - 2026-08-06

### Added
- **AnalyticsConfig expansion** — 25+ new typed config accessors: dedup, GDPR, attribution, profile, funnels, alerts, inbound webhook, pipeline (auto_metadata, schema_enrichment). Summary expanded to 22 config sections (from 14).
- **Alert + Funnel API routes** — `POST /api/analytics/alerts/evaluate`, `GET /api/analytics/alerts`, `GET /api/analytics/funnels`, `POST /api/analytics/funnels/compare`, `GET /api/analytics/funnels/drop-off`, `GET /api/analytics/funnels/chart` now registered in ServiceProvider (previously only in routes file).
- **JS client `getVersion()`** — Programmatic version export for diagnostics and API compatibility checks.
- **V27ConfigRoutesVersionConsistencyTest** — 35+ new test cases covering version string consistency (manager, facade, composer, JS), config accessor completeness (all new sections), route registration completeness (23 public + authenticated routes), controller version strings (3 endpoints), accessor return types (bool/int/string/array), event catalog integrity, and service provider binding verification.

### Fixed
- **Facade `directDispatch` return type** — Corrected from `void` to `bool` matching AnalyticsManager::directDispatch() actual return type.
- **Controller version strings** — `catalog()`, `health()`, and `stats()` endpoints updated from stale `2.24.0` to `2.27.0`.
- **JS client JSDoc version** — Updated from `2.26.0` to `2.27.0`.

### Changed
- Version bump to 2.27.0
- AnalyticsConfig summary() now returns 22 sections (was 14) — includes dedup, gdpr, attribution, profile, funnels, alerts, inbound_webhook, stream

## [2.23.0] - 2026-08-06

### Added
- **AnalyticsStatsService** — Dashboard aggregation service combining real-time event counts from EventAggregationService with dispatch/failure counters from AnalyticsMetrics. Provides `summary()`, `byCategory()`, and `byProvider()` (with success rates) for admin dashboards and monitoring.
- **InboundWebhookService** — Receive analytics events from external sources (Stripe webhooks, payment processors, partner integrations) via `POST /api/analytics/webhook/inbound`. Supports single events and batch payloads (up to 50 events). HMAC-SHA256 signature verification with configurable enforcement. Payload size limits (64KB default). All inbound events are tagged with `_source: webhook_inbound` for tracing.
- **EventMetadataEnricher** — New pipeline filter that auto-attaches session ID, page URL, referrer, and ISO-8601 timestamp to all events passing through the API pipeline. Metadata keys are prefixed with `_` to avoid collision with user params. Existing params are never overwritten.
- **SchemaEnricher** — New pipeline filter that validates events against the EventSchemaRegistry. Attaches `_schema_valid` flag and `_schema_errors` to event params. Non-blocking by default (attaches warning flags); supports strict mode that drops invalid events.
- **GET /api/analytics/stats** — Public endpoint returning aggregated analytics statistics (total events, top events, per-category breakdowns, per-provider metrics, replay queue status). Powers admin dashboards without authentication.
- **POST /api/analytics/webhook/inbound** — Public endpoint for receiving external analytics events with HMAC-SHA256 signature verification. Returns 200 (ok), 207 (partial), 400 (error), or 503 (disabled).
- **Pipeline config expansion** — New `auto_metadata` (default: true) and `schema_enrichment` (default: false) pipeline settings for configuring the enhanced API pipeline.
- **Inbound webhook config section** — `inbound_webhook` with 5 environment variables: `ANALYTICS_INBOUND_WEBHOOK_ENABLED`, `ANALYTICS_INBOUND_WEBHOOK_SECRET`, `ANALYTICS_INBOUND_WEBHOOK_REQUIRE_SIGNATURE`, `ANALYTICS_INBOUND_WEBHOOK_MAX_PAYLOAD`, `ANALYTICS_INBOUND_WEBHOOK_MAX_EVENTS`.
- **JS session heartbeat** — `initSessionHeartbeat(intervalSeconds)`, `stopSessionHeartbeat()`, `isHeartbeatActive()` exports. Periodically tracks `session_heartbeat` events with session duration, event count, and page path. Configurable interval (10–300 seconds, default: 60).
- **2 new service bindings** in AnalyticsServiceProvider: AnalyticsStatsService, InboundWebhookService.
- **V23StatsInboundMetadataTest** — 40+ new test cases covering EventMetadataEnricher (5 tests), SchemaEnricher (5 tests), AnalyticsStatsService (4 tests), InboundWebhookService (13 tests), pipeline integration (4 tests), version consistency (3 tests), and config expansion (3 tests).

### Changed
- Version bump to 2.23.0
- API controller constructor now accepts AnalyticsStatsService, InboundWebhookService, and EventSchemaRegistry as optional dependencies
- `buildPipeline()` enhanced to chain SchemaEnricher → UtmEnricher → EventMetadataEnricher in the correct order
- Health, catalog, and stats endpoint version strings updated to 2.23.0
- JS client version string updated to 2.23.0
- Inertia middleware version consistency maintained

## [2.22.0] - 2026-08-06

### Added
- **AnalyticsProfileService** — Per-user analytics profile aggregation stored in cache. Tracks event counts, lifetime value (LTV), first/last seen timestamps, funnel completion, engagement score (0–100), current plan, and user traits. Engagement score is calculated from event frequency (log scale), diversity, revenue activity, and funnel steps.
- **AttributionService** — First-touch and multi-touch UTM attribution tracking. Captures UTM parameters from requests, persists first-touch attribution (immutable after first visit), and maintains a rolling touch history (max 20 touchpoints). Provides attribution summary with source/medium/campaign aggregation and chronological journey.
- **EventInterceptorRegistry** — Global before/after event hooks. Register interceptors to modify, filter, or observe events before/after dispatch. Before-interceptors can cancel dispatch by returning null. After-interceptors receive a success flag and are exception-safe.
- **GdprErasureService** — GDPR "right to be forgotten" orchestration. Deletes analytics profile, attribution data, and tracking preferences in a single call. Designed to complement AnalyticsManager::resetIdentity() for complete provider-side erasure.
- **AnalyticsManager enhancements** — `interceptBefore()`, `interceptAfter()`, `interceptors()`, `getProfile()`, `getProfileSummary()` convenience methods. `directDispatch()` now returns `bool` (true if at least one provider succeeded).
- **New API endpoints** — `GET /api/analytics/profile` (authenticated, returns user profile summary), `DELETE /api/analytics/data` (authenticated, erases all user analytics data for GDPR compliance).
- **New config sections** — `attribution` (first-touch TTL, touch history TTL, max history, enabled toggle), `profile` (TTL, enabled toggle).
- **4 new service bindings** in AnalyticsServiceProvider: AnalyticsProfileService, AttributionService, GdprErasureService.
- **Facade proxy methods** — `interceptBefore()`, `interceptAfter()`, `interceptors()`, `getProfile()`, `getProfileSummary()`.
- **V22ProfileAttributionInterceptorTest** — 50+ new test cases covering EventInterceptorRegistry (7 tests), AnalyticsProfileService (13 tests), AttributionService (11 tests), GdprErasureService (6 tests), manager integration (7 tests), UtmAttribution (5 tests), config expansion (4 tests), service provider registration (2 tests).

### Changed
- Version bump to 2.22.0
- `directDispatch()` return type changed from `void` to `bool` — tracks whether at least one provider accepted the event
- Facade `@method` annotations updated with new return types for `directDispatch` and new methods
- Health/catalog endpoint version strings updated to 2.22.0
- JS client version string updated to 2.22.0

## [2.20.0] - 2026-08-06

### Added
- **EventDeduplicationService** — Cache-based event deduplication using SHA-256 fingerprints (name + client ID + user ID + params hash). Configurable dedup window (default: 10s) and max recent events (default: 500). Can be disabled globally via `ANALYTICS_DEDUP_ENABLED`.
- **DeviceContextService** — Zero-dependency User-Agent parser with browser, browser version, OS, OS version, device type (mobile/tablet/desktop/bot/unknown), and device brand detection. Supports Chrome, Firefox, Safari, Edge, Opera, Brave, Vivaldi, Arc, Samsung Browser, UCBrowser, Lynx, and IE.
- **IpAnonymizationService** — GDPR-compliant IP masking for IPv4 (configurable octet preservation) and IPv6 (configurable bit preservation). Supports `::ffff:` mapped addresses. Controlled via `zeroboiler.analytics.gdpr` config section.
- **SaasFunnelService** — Complete SaaS lifecycle funnel tracking across 5 funnels: Signup (5 steps), Trial (4 steps), Conversion (4 steps), Retention (4 steps), Expansion (4 steps). Each step produces a `funnel_{name}_{step}` event with funnel metadata for downstream funnel analytics.
- **New config sections** — `dedup` (event deduplication toggle), `gdpr` (IP anonymization settings), `funnels` (funnel tracking toggle)
- **4 new service bindings** in AnalyticsServiceProvider: EventDeduplicationService, DeviceContextService, IpAnonymizationService, SaasFunnelService
- **30+ new test cases** in V20DedupDeviceIpFunnelTest covering all new services

### Changed
- Version bump to 2.20.0
- Facade proxy methods: added isTrackingAllowed(), optOut(), optIn(), suppressClient(), transferClientToUser()
- Health/catalog endpoint version strings updated to 2.20.0
- JS client version string updated to 2.20.0

## [2.19.0] - 2026-08-06

### Added
- **6 Dedicated Cohort Typed Event Classes** — CohortAssignedEvent, CohortRetentionEvent, CohortChurnEvent, CohortConversionEvent, CohortMigrationEvent, CohortEngagementEvent with typed constructors replacing generic CustomEvent references
- **All 49 events now have typed classes** — Previously 6 cohort events used CustomEvent; now every event in the catalog has a dedicated typed class with enforced parameters
- **V219CohortTypedClassesTest** — 30+ new test cases covering all 6 cohort event constructors, parameter validation, engagement rate calculation, null/empty filtering, catalog integration (typed class verification, no CustomEvent, readonly final, subclass check), and unified catalog cross-validation

### Changed
- **SaaSEvents catalog** — All 6 cohort event entries now reference dedicated typed classes instead of CustomEvent
- **JS client** — Removed duplicate `initAll()` export at EOF; consolidated to single canonical implementation with full session tracking, config-driven Inertia settings, and proper cleanup
- **README** — Added "6 Dedicated Cohort Typed Classes" to SaaS Analytics features, expanded API Reference table to all 13 endpoints, added `zb:analytics:dashboard` command documentation, added v2.19.0 upgrade guide, updated Health Response version to 2.19.0
- Version bump to 2.19.0 — complete typed event coverage across the entire 49-event feature matrix

## [2.18.0] - 2026-08-06

### Added
- **Comprehensive SaaS starter test suite** — 60+ new test cases across 4 test files covering the complete 49-event feature matrix
- **V218SaasStarterComprehensiveTest** — Event catalog completeness, category structure, cross-provider mappings (GA4/Meta/PostHog/Plausible), typed class resolution, search, and required-key validation
- **V218EngagementEventClassesTest** — All 20 typed engagement event class constructors and DTO conversion (ClickEvent, ScrollDepthEvent, FormStartEvent, FormSubmitEvent, SearchEvent, ShareEvent, ErrorEvent, PageViewEvent, SessionStartEvent, SessionEndEvent, WebVitalsEvent, JSErrorEvent, OutboundClickEvent, TimingEvent, ScreenViewEvent, AbTestExposureEvent, NotificationEvent, CampaignAttributionEvent, TimeOnPageEvent)
- **V218EcommerceEventClassesTest** — All 12 typed e-commerce event class constructors, monetary param typing, and cross-provider param validation (ViewItemEvent through ViewPromotionEvent)
- **V218SaasEventClassesTest** — All 17 typed SaaS event class constructors including 6 cohort events (CohortAssignedEvent through CohortEngagementEvent), monetary param validation
- **`@dataProvider` data provider** for event entry key validation (name, class, ga4, meta, category)
- Total test files: 61, total test cases: 150+

### Changed
- Version bump to 2.18.0 — industry-standard SaaS analytics package
- All 12 roadmap items fully implemented and production-tested
- Full feature matrix: 49 events, 6 providers, 12 config sections, 57+ JS client exports, 6 admin commands

## [2.17.0] - 2026-08-06

### Added
- **`initAll()` — One-Call Setup** — Initialize analytics AND all auto-trackers (page views, scroll depth, form tracking, error tracking, link tracking, session tracking, Web Vitals) with a single function call. Returns a combined cleanup function for Svelte `onMount` compatibility. Recommended for Svelte/Inertia apps.
- **Dynamic API base URL** — JS client now reads `apiBase` from Inertia `zbAnalytics` props. All fetch URLs use the configured base URL instead of hardcoded `/api/analytics`. New `getApiBaseUrl()` export.
- **EventSchemaRegistry expansion** — Added schemas for `select_item`, `select_promotion`, `view_promotion` e-commerce events with GA4 provider mappings and typed parameter definitions.
- **`AnalyticsConfig::trackingPreferenceTtl()`** — Type-safe accessor for tracking preference cache TTL configuration. Added to `summary()` output.
- **20 typed engagement event classes** — All engagement events now have dedicated typed classes (JSErrorEvent, OutboundClickEvent, TimingEvent, SessionStartEvent, SessionEndEvent, WebVitalsEvent) instead of CustomEvent references.
- **Event catalog count** — Total events now 49 (12 e-commerce + 17 SaaS + 20 engagement)
- **`V33EngagementExpansionTest.php`** — 30+ new test cases covering all 20 engagement event classes, catalog completeness verification (total counts, category counts, typed class validation, GA4 mappings), and manager dispatch integration.

### Changed
- **JS client** — All API fetch URLs now use dynamic `${apiBaseUrl}` variable (was hardcoded `/api/analytics`). Configurable via `ANALYTICS_API_BASE_URL` → Inertia props → JS client.
- **README** — Updated event catalog reference with full engagement events table (20 entries including WebVitals, JSError, Timing, Session, OutboundClick). Updated feature list to 49 events and 50+ schemas. Added `initAll()` to quick start and JS API reference. Updated Svelte example to recommend `initAll()` as primary setup method.

## [2.16.0] - 2026-08-06

### Added
- **TrackingPreferenceService** — Per-user GDPR opt-out/opt-in with persistent cache storage (7-day TTL)
- **AnalyticsManager::optOut()** / **optIn()** — Persist user tracking preferences (beyond consent)
- **AnalyticsManager::isTrackingAllowed()** — Combined preference + consent check for tracking decisions
- **AnalyticsManager::suppressClient()** — Suppress anonymous client tracking before authentication
- **AnalyticsManager::transferClientToUser()** — Transfer client suppression to authenticated user on login
- **POST /api/analytics/opt-out** — API endpoint for per-user tracking opt-out
- **POST /api/analytics/opt-in** — API endpoint for per-user tracking opt-in
- **GET /api/analytics/preference** — Check tracking preference status (combined consent + opt-out)
- **EventCatalog::allPosthogNames()** — PostHog-compatible event names ($signup, $identify, etc.)
- **EventCatalog::allPlausibleNames()** — Plausible-compatible event names (filtered, pageview mapping)
- **EventCatalog::byProvider()** expanded — Now includes `posthog` and `plausible` provider keys
- **Config: `tracking_preference`** section — Cache TTL configuration for tracking preferences
- **JS client: `optOutTracking()`** — Client-side opt-out with immediate tracking stop
- **JS client: `optInTracking()`** — Client-side opt-in to restore tracking
- **JS client: `getTrackingPreference()`** — Fetch current preference state from server
- Fixed broken template literals in JS client (`***` → backticks)
- 20+ new tests covering TrackingPreferenceService and EventCatalog provider expansion

## [2.15.0] - 2026-08-06

### Added
- **SelectItemEvent** — GA4 `select_item` event for product list selection (e-commerce funnel)
- **SelectPromotionEvent** — GA4 `select_promotion` event for promotion click tracking
- **ViewPromotionEvent** — GA4 `view_promotion` event for promotion impression tracking
- **EcommerceEvents catalog** expanded to 12 events (from 9)
- **AnalyticsManager** convenience methods: `selectItem()`, `selectPromotion()`, `viewPromotion()`
- **EcommerceAnalyticsService** methods: `selectItem()`, `selectPromotion()`, `viewPromotion()`
- **EventTransformer** Plausible event mapping (`toPlausibleEventName()`, `toPlausibleEventMap()`, `transformForPlausible()`)
- **JS client** promotion tracking: `trackSelectItem()`, `trackPromotionView()`, `trackPromotionClick()`
- 3 new test suites covering events, catalog expansion, and Plausible transformer

## [2.14.0] - 2026-08-06

### Fixed
- **minimum-stability** changed from `dev` to `stable` for production readiness
- **Repository paths** fixed from `/tmp/*` to relative `../response`, `../dto`, `../value-objects`
- Added `sort-packages: true` to composer config
- Added `keywords` to composer.json for better discoverability
- Version bump to 2.14.0

## [2.13.0] - 2025-08-06

### Added
- **AnalyticsConfig** — Type-safe, single-entry-point config accessor with 60+ typed methods (no raw array access)
- **AnalyticsEventNameRule** — Laravel validation rule for analytics event names (format, catalog, strict whitelist modes)
- **EventTransformer** — Centralized cross-provider event format conversion (GA4 ↔ Meta, SaaS → PostHog)
- **AnalyticsRateLimiter** — Per-client rate limiting using Laravel's RateLimiter (client ID/IP based)
- **WebhookSignatureValidator** — HMAC-SHA256 webhook signature validation (X-ZB-Signature + X-Hub-Signature-256)
- **AnalyticsDashboardCommand** (`zb:analytics:dashboard`) — Export dashboard data as structured JSON/table
- 70+ new tests covering all Support layer classes

## [2.12.0] - 2025-08-06

### Added
- GA4 event tracking with typed parameters
- GTM container integration
- Meta Pixel event dispatch
- `AnalyticsHealthService` for provider health monitoring
- `EventDebounceFilter` for deduplication and throttling
- Client-side auto-tracking helpers (pageview, timing, performance)
- Session lifecycle tracking (start, end, heartbeat)
- Config-driven provider setup and feature flags
- MIT license

## [2.11.0] - 2025-07-01

### Added
- Client auto-tracking, performance events, session lifecycle
- MIT license
