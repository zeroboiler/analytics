# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

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
