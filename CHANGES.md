# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

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
