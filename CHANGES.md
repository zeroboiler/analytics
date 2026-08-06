# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

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
