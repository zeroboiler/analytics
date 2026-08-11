# Changelog

All notable changes to the package will be documented in this file.

## [11.0.0] - 2026-08-11

### Added

- **`AnalyticsConsentComplianceService`** — GDPR Consent Mode v2 compliance validation service. 10-dimensional compliance check suite: consent signal coverage, GDPR purpose configuration, default consent state (denied=GDPR-safe), consent logging (Article 7 audit trail), TTL validation (90-day minimum), regional consent detection (EU geo-targeting), provider consent gating, consent version hash integrity, cookie privacy attributes, and data erasure support (Article 17). Cache-backed with 5-minute TTL. Generates GDPR Article 30 audit reports with processing activities, legal basis, data categories, and retention periods.
- **`AnalyticsSmokeRunnerCommand`** (`zb:analytics:smoke`) — 20-check comprehensive pipeline smoke test for CI/CD and pre-deployment validation. Checks: version integrity, event catalog validation, catalog categories (5/5), provider coverage, provider configuration, GA4/GTM/Meta connectivity, GDPR consent compliance, e-commerce format conversion, consent state management, analytics metrics, facade accessibility, health check, identity resolution, queue dispatch, pipeline filters (7 components), GDPR services (6 services), admin commands (5 commands), Inertia middleware, API controller, test fake. Supports `--skip-providers`, `--skip-consent`, `--json`, `--verbose` flags. Outputs pass/warn/fail/summary with elapsed time.
- **`V1100FullSaaSPipelineSmokeTest`** — End-to-end SaaS analytics pipeline test. 70+ assertions covering: SaaS user journey (10-step: signup → email verify → login → trial → subscription → feature use → upgrade → payment → invoice → cancellation), e-commerce funnel (5-step: view → cart → checkout → purchase → refund), engagement events (8 events: page view, scroll, form, search, share, error, click), consent mode v2 compliance (grant/deny/propagation/history), identity resolution (client ↔ user linking), multi-provider dispatch, e-commerce format conversion (GA4/Meta), pipeline processing (UTM/timestamp/metadata enrichers), GDPR compliance (reset/opt-out/opt-in), catalog integrity (5 categories, 100+ events, no duplicates), DTO round-trips, queue dispatch readiness, facade proxy, AnalyticsFake assertions (tracked/not/trackedTimes/callback/reset).
- **`V1100ConsentComplianceServiceTest`** — 18 test cases covering: compliance check structure, score percentage, GDPR-safe default scoring, audit report (Article 30), consent mode v2 signal check, default consent state validation, consent logging check, TTL validation (90-day), regional consent check, provider consent gating validation, cache invalidation, check field validation (status/severity/message).

### Changed

- **Version sweep** — 10.9.0 → 11.0.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composables (useAnalytics, useAnalyticsConfig), TypeScript definitions (analytics.d.ts), ServiceProvider docblock, README badge, AnalyticsIntegrityCommand::EXPECTED_VERSION, CHANGELOG.
- **LOC** — ~131K PHP source, 6K+ JS client, 221 test files.

## [10.9.0] - 2026-08-11

### Added

- **`trackSaaSIdentity()` convenience method** — Combines `identify()` + `setUserProperties()` + identity resolution in a single call. Links `client_id ↔ user_id`, sets user traits (name, email, plan, company), and persists the identity link via `IdentityResolutionService`. Designed for login/signup flows.
- **Facade docblock expansion** — Added `@method` annotations for `mixpanel()`, `amplitude()`, and `trackSaaSIdentity()` on the `Analytics` facade, enabling IDE autocompletion for the full tracker and SaaS identity API surface.
- **V109SaaSIdentityAndFacadeTest** — 23 test cases, 150+ assertions covering version consistency, event catalog coverage (100+ events), all SaaS lifecycle methods, tracker accessors, Facade docblock completeness, e-commerce format conversion, funnel tracking, orchestration, B2B groups, PLG scoring, time-series analytics, health checks, GDPR compliance methods, and DTO strict types.

### Changed

- **Version sweep** — 10.8.0 → 10.9.0 across composer.json, package.json, AnalyticsEvent::VERSION, and AnalyticsIntegrityCommand::EXPECTED_VERSION.

## [10.8.0] - 2026-08-11

### Added

- **LifecycleEventMapper expansion** — New config-driven lifecycle mappings for `sla.breach` (→ `SlaBreachEvent`), `feature.adopted` (→ `FeatureAdoptedEvent`), and `revenue.expansion` (→ `ExpansionRevenueEvent`). Coverage for SLA violations, feature adoption tracking, and upsell/cross-sell revenue growth signals.
- **V108LifecycleExpansionAndVersionSweepTest** — 35+ assertions covering version consistency, new catalog entries, provider coverage, e-commerce format conversion, and cross-category integration.

### Changed

- **Version sweep** — 10.5.0 → 10.8.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composables (useAnalytics, useAnalyticsConfig), ServiceProvider docblock, README badge, AnalyticsIntegrityCommand::EXPECTED_VERSION, AnalyticsConsistencyService docblock, EventNormalizationService docblock, MixpanelAmplitudeParityTest docblock, V107 test version assertions.

## [10.7.0] - 2026-08-11

### Added

- **SaaS Industry Standard Comprehensive Test Suite** — Extensive test coverage across all analytics components with 100+ new assertions validating event tracking, normalization, consistency checks, provider mappings, and consent handling.

### Changed

- **Version sweep** — 10.6.0 → 10.7.0 across all version references.

## [10.6.0] - 2026-08-11

### Added

- **Mixpanel & Amplitude Event Catalog Parity** — Every catalog entry across all 5 categories (Ecommerce, SaaS, Engagement, Security, Uptime) now includes native `mixpanel` and `amplitude` event name fields. Mixpanel uses Title Case convention (e.g. `Add to Cart`, `Sign Up`); Amplitude uses Past Tense (e.g. `Added to Cart`, `Signed Up`).
- **EventCatalog aggregate methods** — `allMixpanelNames()`, `allAmplitudeNames()`, `mixpanelNameFor()`, `amplitudeNameFor()` for provider-specific lookups.
- **EventTransformer provider support** — `saasToMixpanelEventMap()` (80+ mappings) and `saasToAmplitudeEventMap()` (80+ mappings). `transformForProvider()` now supports `'mixpanel'` and `'amplitude'`.
- **byProvider() expanded** — Returns 6 providers: ga4, meta, posthog, plausible, mixpanel, amplitude.
- **Category-level helpers** — `EcommerceEvents::mixpanelNames()`, `SaaSEvents::amplitudeNames()`, etc. on all 5 catalog classes.
- **Tracker auto-transform** — `MixpanelTracker::track()` and `AmplitudeTracker::track()` auto-transform event names via `EventTransformer::transformForProvider()`.
- **Test** — `MixpanelAmplitudeParityTest` with 35+ assertions.

### Changed

- **Version sweep** — 10.5.0 → 10.6.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composables, TypeScript definitions, ServiceProvider, README badge, CHANGELOG.

## [10.5.0] - 2026-08-11

### Added

- **Event Normalization Service** (`EventNormalizationService`) — Provider-agnostic event normalization. Convert a single `AnalyticsEvent` into all provider-specific formats (GA4, GTM, Meta, PostHog, Plausible, Mixpanel, Amplitude, Webhook) in one call. Segment-inspired unified event model. Batch normalization, provider name resolution, target provider discovery, per-event normalization stats, catalog coverage report.
- **Analytics Consistency Service** (`AnalyticsConsistencyService`) — Cross-provider event dispatch consistency checker. 6-dimension check suite: catalog integrity, provider mappings, identity consistency, config validity, naming convention, provider config. Composite scoring (0-100) with letter grading. Cache-backed.
- **Config `event_templates` section** — Default currency, auto UTM attach, auto user ID attach, provider params for SaaS event templates.

### Changed

- **Refactor** — Removed unused `AnalyticsManager` from `EventNormalizationService`. Fixed `request()` global helper anti-pattern. Removed dead ecommerce branch in `normalizeForMeta`.
- **Version sweep** — 10.4.0 → 10.5.0.

## [10.4.0] - 2026-08-11

### Added

- **AnalyticsFake** (`AnalyticsFacadeTest`, `AnalyticsFakeTest`) — Industry-standard test fake for analytics event assertions. `Analytics::fake()`, `Analytics::assertTracked()`, `Analytics::assertNotTracked()`, `Analytics::trackedEvents()`. Laravel-style testing pattern.
- **`WithAnalyticsFake` trait** — Auto-setup/teardown for `Analytics::fake()` in Pest tests.

### Changed

- **Version sweep** — 10.3.0 → 10.4.0.

## [10.3.0] - 2026-08-11

### Added

- **Event Timeline Service** (`EventTimelineService`) — Chronological user journey timelines with session grouping, funnel annotation, and gap detection for churn-risk identification. Cache-backed with configurable TTL and max entries. Config section: `timeline`.
- **Timeline API endpoints** — `GET /api/analytics/timeline/{clientId}`, `GET .../summary`, `GET .../sessions`, `DELETE .../{clientId}`.

### Changed

- **Version sweep** — 10.2.0 → 10.3.0. Fixed 97 test files with stale version references.

## [10.2.0] - 2026-08-11

### Added

- **9-Provider Full Client Coverage** — JS client now supports all 9 providers: GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, Webhook, and SaaS internal. Full e-commerce shorthands (`trackPurchase`, `trackRefund`, `trackViewItem`, `trackAddToCart`, `trackRemoveFromCart`, `trackBeginCheckout`, `trackSelectItem`, `trackPromotionView`, `trackWishlist`).
- **Catalog summary expansion** — `EventCatalog::summary()` now includes billing, security, uptime, expansion, and GDPR event counts.
- **Event Catalog billing events** — `InvoiceGeneratedEvent`, `PaymentFailedEvent`, `PaymentSucceededEvent`.

### Changed

- **Version sweep** — 10.1.0 → 10.2.0.

## [10.1.0] - 2026-08-11

### Changed

- **Production readiness** — Manual code review verified. PHP 8.5 syntax compliance, strict types, return type declarations across all files.
- **Version sweep** — 10.0.0 → 10.1.0.

## [10.0.0] - 2026-08-11

### Added

- **Mixpanel Tracker** (`MixpanelTracker`) — Server-side tracking via Mixpanel `/track` API endpoint. Config section: `mixpanel`. Supports identity, event properties, and super properties.
- **Amplitude Tracker** (`AmplitudeTracker`) — Server-side tracking via Amplitude V2 HTTP API. Config section: `amplitude`. Supports device ID, user ID, event properties, and platform identification.
- **8-Provider Architecture** — GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, Webhook. Full `AnalyticsManager` integration with per-provider enable/disable.
- **PHP 8.5 Compatibility** — `:void` return types added to 16 constructors. All services use named arguments, readonly properties, and intersection types.

### Changed

- **Breaking** — `AnalyticsManager` constructor now requires `ConfigRepository` (or resolves from container). No longer accepts individual provider configs.

## [9.9.0] - 2026-08-11

### Added

- **Security Event Category** (`SecurityEvents`) — `LoginAttemptEvent`, `SuspiciousActivityEvent`, `DataAccessAuditEvent`, `RateLimitExceededEvent`, `MfaChallengeEvent`. Full GA4/Meta/PostHog/Plausible/Mixpanel/Amplitude catalog mappings.
- **Uptime Event Category** (`UptimeEvents`) — `ApiLatencyEvent`, `DeploymentEvent`, `ErrorSpikeEvent`, `ServiceDownEvent`, `ServiceUpEvent`. Full provider mappings.
- **Security & Uptime Lifecycle Mappings** — Config-driven auto-track for `security.login_attempt`, `security.suspicious_activity`, `security.data_access_audit`, `security.rate_limit_exceeded`, `security.mfa_challenge`, `uptime.service_up`, `uptime.service_down`, `uptime.deployment`, `uptime.api_latency`, `uptime.error_spike`.

### Changed

- **Version sweep** — 9.8.0 → 9.9.0.

## [9.8.0] - 2026-08-11

### Added

- **Cross-Domain Tracking Service** (`CrossDomainTrackingService`) — Multi-domain visitor stitching for SaaS apps with multiple properties (app, docs, blog). Linker parameter decoration (`_zbclid`), auth-based client ID linking, transitive identity cluster resolution, bidirectional link graph with cache-backed storage. Config-driven domain list with wildcard support and exclusions. GDPR-aware link clearing via `clearLinks()`.
- **Session Recording Bridge** (`SessionRecordingBridge`) — Consent-aware integration with Hotjar, LogRocket, FullStory, Microsoft Clarity. Recording suppression based on consent state, user role, and URL pattern matching. PII masking (`data-zb-mask`, `.masked`) and content blocking (`data-zb-block`, `.blocked`) via CSS selectors. Multi-provider configuration with per-integration settings.
- **Event Schema Export Service** (`EventSchemaExportService`) — Auto-generate JSON Schema (Draft 2020-12), TypeScript type definitions, and OpenAPI 3.1 operations from the event catalog. TypeScript includes `ZbEventName` union type, per-event typed interfaces, category-specific types, and priority enum. JSON Schema includes `$defs` for every catalog event with parameter types and required fields.
- **Analytics Rate Limiter Service** (`AnalyticsRateLimiterService`) — Redis-backed three-tier rate limiting using Laravel's `RateLimiter` facade. Global (10K/min), per-client (300/min), per-user (600/min) for single events. Separate batch limits (5K/min global, 100/min per-client). Max batch size enforcement. Configurable decay window.
- **Schema Export Command** (`AnalyticsSchemaExportCommand`) — `php artisan zb:analytics:export-schema --format=json|typescript|openapi --output=- --pretty`. Writes to stdout or file.
- **Inertia middleware updates** — `zbAnalytics.crossDomain` prop for cross-domain linker config, `zbAnalytics.sessionRecording` prop for consent-aware recording config.
- **API endpoints:** 12 new endpoints for cross-domain tracking, session recording, schema export, and advanced rate limiting.
- **Config sections:** `cross_domain`, `session_recording`, `schema_export`, `rate_limit` in `zeroboiler.php`.
- **TypeScript types:** `CrossDomainConfig`, `SessionRecordingConfig` interfaces added to `analytics.d.ts`.
- **Test suite** — `V980CrossDomainSchemaExportRateLimitTest.php` with 30+ tests.

### Changed

- **Version sweep** — 9.7.0 → 9.8.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), README badge, CHANGELOG.

## [9.4.0] - 2026-08-11

### Added

- **Provider Fallback Service** (`ProviderFallbackService`) — Multi-provider failover strategy integrated with circuit breaker. When a primary analytics provider fails, events are automatically redirected to configured fallback providers via ordered chain evaluation. Features: `resolveProvider()`, `getFallbackChain()`, `hasFallbackChain()`, `recordFallback()`, `getFallbackCount()`, `validate()` (circular dependency detection, chain depth validation, invalid provider detection), `healthSummary()` (per-provider status with circuit breaker states), `stats()`, `resetCounters()`, `getCachedCounts()`, `clearCachedCounts()`. Cache-backed fallback counters for cross-process visibility. Config-driven chains.
- **Event Catalog Factory** (`EventCatalogFactory`) — Fluent factory for creating catalog-aware `AnalyticsEvent` DTOs. Static methods: `create()` (catalog-validated), `raw()` (no validation), `event()` (direct shorthand), `critical()` (critical-priority shorthand). Instance methods: `withClientId()`, `withUserId()`, `withIdentity()`, `withTimestamp()`, `withPriority()`, `mergeParams()`, `build()`. Catalog helpers: `getCatalogEntry()`, `getCategory()`, `getGa4Name()`, `getMetaName()`, `isInCatalog()`. Static category helpers: `ecommerceEventNames()`, `saasEventNames()`, `engagementEventNames()`, `catalogSize()`.
- **AnalyticsEvent source tracking** — New `source` property on `AnalyticsEvent` DTO to track event origin (api|server|client|webhook|replay|batch). Properly serialized in `toArray()` and deserialized in `fromArray()`.
- **Priority in toArray()** — `AnalyticsEvent::toArray()` now includes the `priority` field for complete serialization.
- **API endpoints:**
  - Fallback: `GET /api/analytics/fallback`, `GET .../chains`, `GET .../validate`, `GET .../health`, `POST .../reset-counts`
- **Config: `fallback` section** — New `zeroboiler.analytics.fallback` with enabled flag, max chain depth (default: 3), cache prefix, and per-provider chains configuration.
- **Service registration** — `ProviderFallbackService` registered as singleton in `AnalyticsServiceProvider`.
- **Test suite** — `V940FallbackFactorySourceTest.php` with 50+ tests covering ProviderFallbackService (resolve, chains, validation, health, counters), EventCatalogFactory (create, raw, identity, priority, build, catalog helpers, static shortcuts), and AnalyticsEvent source field (constructor, toArray, fromArray, priority serialization).

### Changed

- **Version sweep** — 9.3.0 → 9.4.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), IntegrityCommand::EXPECTED_VERSION, README badge, CHANGELOG.

## [9.3.0] - 2026-08-11

### Added

- **Event Idempotency Service** (`EventIdempotencyService`) — Server-side deduplication for analytics event dispatches. Prevents duplicate events using idempotency keys (SHA-256 fingerprinting of event name + client ID + user ID + params hash). Client-supplied idempotency keys take priority. Cache-backed O(1) lookup with configurable TTL (default: 1 hour). Hit/miss statistics tracking with duplicate rate calculation. Key invalidation support. Static `generateClientKey()` helper for frontend use.
- **Privacy Manifest Service** (`PrivacyManifestService`) — GDPR Article 30 Records of Processing Activities (RoPA) generation. All registered catalog events classified into GDPR data categories (identifier, behavioral, financial, technical, contractual, legal, statistical, transactional). Legal basis mapping per event category. Retention period defaults per category. Third-party data flow documentation for all 5 providers. Data subject rights implementation status. Cross-border data transfer assessment (SCCs, adequacy decisions). Cache-backed manifest generation.
- **Event Annotation Service** (`EventAnnotationService`) — Deployment markers and event tagging. Annotation types: deployment, debug, experiment, release, custom. Auto-attach annotations from config (deployment version, environment, debug flag, release tag). Cache-backed storage with configurable max annotations per event. Full CRUD API.
- **API endpoints** — 13 new endpoints:
  - Idempotency: `GET /api/analytics/idempotency`, `POST .../invalidate`, `POST .../reset-stats`
  - Privacy Manifest: `GET /api/analytics/privacy-manifest`, `GET .../summary`, `GET .../classify/{eventName}`, `POST .../invalidate`
  - Annotations: `GET /api/analytics/annotations/stats`, `POST ...`, `POST .../auto-attach`, `GET .../{eventId}`, `DELETE .../{eventId}`, `DELETE .../{eventId}/{key}`
- **Config: `idempotency` section** — New `zeroboiler.analytics.idempotency` with configurable enabled flag, TTL, max keys, and cache prefix.
- **Config: `privacy_manifest` section** — New `zeroboiler.analytics.privacy_manifest` with cache TTL, controller/DPO email, legal basis defaults, and retention defaults.
- **Config: `annotations` section** — New `zeroboiler.analytics.annotations` with cache TTL, max annotations per event, and auto-attach toggles.
- **Service registration** — All 3 new services registered as singletons in `AnalyticsServiceProvider`.

### Changed

- **Version sweep** — 9.2.0 → 9.3.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), README badge, CHANGELOG.

## [9.2.0] - 2026-08-10

### Added

- **SaaS Lifecycle Observer** (`SaaSLifecycleObserver`) — Real-time SaaS health monitoring service that tracks trial activation scores (0-100 with weighted step progression), churn risk indicators (weighted scoring with diminishing returns), expansion revenue momentum, feature adoption depth, session engagement metrics, and conversion funnel progress (7 stages). Cache-backed with configurable TTL. GDPR-compliant with `forget()` method. Aggregate metrics API for admin dashboards.
- **Analytics Readiness Score** (`AnalyticsReadinessScoreService`) — Comprehensive 8-dimension self-assessment scoring system (0-100) evaluating Provider Configuration, Event Catalog Coverage, Identity Tracking, Consent Compliance, Queue Infrastructure, E-commerce Tracking, SaaS Lifecycle Tracking, and Client-Side Integration. Returns letter grades (A+ through F), actionable recommendations sorted by priority, and `isReady()` quick check.
- **Config: `lifecycle_observer` section** — New `zeroboiler.analytics.lifecycle_observer` with configurable enabled flag and cache TTL.
- **Config: `readiness_score` section** — New `zeroboiler.analytics.readiness_score` with configurable enabled flag and passing threshold (default: 60).
- **Test suite** — `V920SaaSLifecycleAndReadinessTest.php` with 20+ tests covering lifecycle observer activation tracking, churn risk computation, expansion momentum, funnel progress, activation score assessment, churn risk assessment, GDPR erasure, static helpers, aggregate metrics, and readiness score computation across all 8 dimensions.

### Changed

- **Version sweep** — 9.1.0 → 9.2.0 across package.json, JS client (getVersion + _getInternalVersion + @version), README badge, and CHANGELOG.

## [9.0.0] - 2026-08-10

### Added

- **Event Delivery Confirmation System** (`EventDeliveryConfirmationService`) — Industry-standard event delivery monitoring inspired by Segment's delivery confirmation, Mixpanel's event verification, and Amplitude's event monitoring dashboard. Features per-provider delivery success/failure tracking, response latency measurement (p50, p95, p99), composite reliability score (0-100) with A-F grading, event delivery receipt recording, provider outage detection via consecutive failure spike, SLA monitoring with configurable target, and cache-backed storage with configurable TTL and retention.
- **Analytics Delivery Command** (`zb:analytics:delivery`) — New artisan command displaying delivery dashboard with per-provider health, response time percentiles, outage status, and SLA compliance. Supports `--json`, `--provider=`, `--receipt=<eventId>`, `--clear`.
- **Delivery Confirmation API endpoints** — `GET /api/analytics/delivery` (full dashboard), `GET /api/analytics/delivery/score` (reliability score), `GET /api/analytics/delivery/receipt/{eventId}` (per-event receipt check), `GET /api/analytics/delivery/{provider}/response-times` (latency percentiles), `GET /api/analytics/delivery/{provider}/recent` (delivery history), `GET /api/analytics/delivery/{provider}/outage` (outage detection), `DELETE /api/analytics/delivery` (clear stats).
- **Config: `delivery_confirmation` section** — New `zeroboiler.analytics.delivery_confirmation` with configurable enabled flag, cache TTL, retention window, outage threshold, and SLA target.
- **Test suite** — `V900EventDeliveryConfirmationTest.php` with comprehensive tests covering service instantiation, disabled state, success/failure recording, counter management, response time stats, receipt tracking, reliability scoring, grade calculation, SLA compliance, outage detection, consecutive failure reset, dashboard aggregation, and stats clearing.

### Changed

- **Version sweep** — 8.9.0 → 9.0.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), README badge, IntegrityCommand::EXPECTED_VERSION, CHANGELOG.

## [8.9.0] - 2026-08-10

### Added

- **Analytics Guard Rails Engine** (`TrackingGuardRailsService`) — Tracking quality monitoring system inspired by Amplitude Compass, Mixpanel Data Governance, and Segment Protocols. Computes a composite quality score (0-100) across 6 dimensions: Schema Compliance (25%), Naming Convention (20%), Coverage Completeness (20%), Provider Coverage (15%), Identity Linking (10%), Consent Compliance (10%). Features: `check()`, `quickScore()`, `violations()`, `validateEventName()`, `coreEventCoverage()`, `clearCache()`. Cache-backed with configurable TTL and minimum event threshold.
- **Guard Rails Command** (`zb:analytics:guard-rails`) — New artisan command displaying composite quality score, per-dimension breakdowns with visual progress bars, violation alerts, and actionable recommendations. Supports `--json`, `--violations`, `--quick`, `--clear-cache`.
- **Guard Rails API endpoints** — `GET /analytics/guard-rails` (full report), `GET /analytics/guard-rails/score` (quick score), `GET /analytics/guard-rails/violations` (violations only with severity filter), `GET /analytics/guard-rails/coverage` (core event coverage), `GET /analytics/guard-rails/validate-name` (single event name validation).
- **Config: `guard_rails` section** — New `zeroboiler.analytics.guard_rails` with configurable enabled flag, cache TTL, and minimum events threshold for ramp-up protection.
- **Test suite** — `V890GuardRailsServiceTest.php` with 16 tests covering service instantiation, disabled state, full check computation, provider coverage scoring, coverage completeness tracking, naming convention validation, event name validation suggestions, quick score, violation filtering, consent compliance scoring, composite score verification, ramp-up deferred scoring, and cache clearing.

### Changed

- **Version sweep** — 8.8.0 → 8.9.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), README badge, IntegrityCommand::EXPECTED_VERSION.

## [8.8.0] - 2026-08-10

### Added

- **Event Correlation Heatmap Service** (`EventCorrelationHeatmapService`) — Computes pairwise Jaccard similarity correlation matrix across tracked events within user sessions. Produces structured data for dashboard heatmap chart rendering. Features: `computeHeatmap()`, `getTopCorrelations()`, `getEventCorrelations()`, `getChartData()`, `getStats()`, `recordCoOccurrence()`. Configurable minimum co-occurrences, max events, Jaccard threshold, and excluded events list.
- **Analytics Health Monitor Dashboard Service** (`AnalyticsHealthMonitorService`) — Unified health monitoring for the entire analytics stack. Aggregates health data from 6 subsystems (providers, queue, config, pipeline, consent, rate limiting) into a composite score (0-100) with A-F grading. Features: `getDashboardData()`, `getScore()`, `getGrade()`, `getDimensionScore()`, `isHealthy()`, `isDegraded()`, `isCritical()`, `getHistory()`, `recordDataPoint()`, `invalidateCache()`.
- **Health Monitor Command** (`zb:analytics:health-monitor`) — New artisan command displaying composite health score, per-dimension breakdowns, alerts, and optional time-series history. Supports `--json`, `--record`, `--history`, `--points=N`.
- **Config: `correlation_heatmap` section** — New `zeroboiler.analytics.correlation_heatmap` with configurable cache TTL, min co-occurrences, max events, Jaccard threshold, and excluded events.
- **Config: `health_monitor` section** — New `zeroboiler.analytics.health_monitor` with configurable dimension weights (providers, queue, config, pipeline, consent, rate_limiting), cache TTL, and enabled dimensions list.

### Changed

- **Version sweep** — 8.7.0 → 8.8.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), README badge, IntegrityCommand::EXPECTED_VERSION, CHANGELOG.

## [8.7.0] - 2026-08-10

### Added

- **Cross-Device Identity Graph Service** (`IdentityGraphService`) — Builds and maintains a graph of identity relationships between client IDs, user IDs, device fingerprints, and session IDs. Enables cross-device user stitching with confidence scoring (1.0 for explicit login/register, 0.8 for device match, 0.5 for IP/UA match, 0.3 for session inference).
- **Device Fingerprint Service** (`DeviceFingerprintService`) — Server-side device fingerprint generation from HTTP request headers (User-Agent, Accept-Language, Sec-CH-Platform, viewport dimensions). SHA-256 hashed — no raw headers stored. GDPR-safe by default (IP excluded).
- **Identity Graph API endpoints** — `GET /api/analytics/identity-graph/user/{userId}`, `POST .../link`, `POST .../infer`, `POST .../merge`, `POST .../same-user`, `GET .../fingerprint` — full CRUD for cross-device identity management.
- **Identity graph config section** — `zeroboiler.analytics.identity_graph` with configurable TTL, max clients/devices per user, confidence thresholds for stitching and merging.
- **Device fingerprint config section** — `zeroboiler.analytics.device_fingerprint` with configurable hash algorithm, components, and IP inclusion toggle.
- **Event enrichment integration** — `IdentityGraphService::enrichEvent()` auto-attaches `_identity_user_id`, `_identity_device_id`, and `_identity_confidence` to events from the pipeline.
- **Identity graph service registration** — Both services registered as singletons in `AnalyticsServiceProvider`.
- **Test suite** — `V870IdentityGraphServiceTest.php` with 17 tests covering explicit linking, inference, graph retrieval, same-user detection, user merging, fingerprinting, stats, and event enrichment.
- Full version sweep to 8.7.0 across all entry points.

## [8.6.0] - 2026-08-10

### Added

- **High-level e-commerce shorthands** — `trackPurchase()`, `trackRefund()`, `trackViewItem()`, `trackAddToCart()`, `trackRemoveFromCart()`, `trackBeginCheckout()` on the Analytics facade for zero-config SaaS e-commerce tracking.
- **Dual-provider e-commerce push** — PostHog and Plausible providers now support full e-commerce event parameter conversion (items, currency, value, transaction_id, coupon).

### Fixed

- Duplicate `initInertiaPageViewTracker` declaration in JS client
- `getCookie()` helper duplication
- Bearer token template literal in streaming endpoint
- Full version sweep to 8.6.0 across all entry points (composer.json, package.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, ServiceProvider, README badge, IntegrityCommand::EXPECTED_VERSION)

## [8.0.0] - 2026-08-10

### Added

- **EventSessionizer** — Session-aware event aggregation for real-time SaaS dashboards. Groups events by client ID + session ID with per-session metrics: event counts, unique events, session duration estimation, engagement scoring (0-100), and conversion detection. Cache-backed with automatic TTL expiry. Supports session indexing, client aggregation stats, and explicit session termination.
- **EventFunnelAggregator** — Automated funnel completion tracking across sessions. Five built-in funnels (signup, activation, purchase, subscription, expansion) with configurable custom funnels. Step-by-step conversion rates, drop-off rates, cumulative rates.
- **EventClassificationEnricher** — Pipeline stage auto-enriching events with catalog metadata: `_zb_category`, `_zb_provider_map`, `_zb_event_class`, `_zb_priority`. Priority inference for custom events using name pattern heuristics.
- **AnalyticsReportCommand** — Scheduled report generator (`php artisan analytics:report`) with sections: health, catalog, funnels, sessions, saas. Supports `--format=json` and `--section=` for targeted reporting.
- **Session API** — `GET /api/analytics/sessions/{clientId}`, session detail, stats, and end endpoints.
- **Funnel API** — `GET /api/analytics/funnels/aggregated/{funnelName}`, all aggregated reports, and funnel definitions.
- **Config: `sessionizer`** — Session TTL, max sessions per client, cache prefix.
- **Config: `funnel_definitions`** — Custom funnel definitions (steps, conversion_event, time_window).
- **Config: `classification`** — Toggle for auto-classification enrichment.

### Changed

- **Version sweep** — 7.9.0 → 8.0.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), README badge, CHANGELOG.

## [7.8.0] - 2026-08-10

### Added

- **EventPluginRegistry** — Third-party package event discovery and registration system. Allows other Laravel packages to register their analytics events with the ZeroBoiler event catalog at runtime. Features: `registerPlugin()` for runtime registration, config-driven loading via `zeroboiler.analytics.event_plugins`, event validation against AnalyticsEvent contract, `summary()` for dashboard data, `eventsByPlugin()` / `eventsByCategory()` for grouped views, `unregisterPlugin()` for cleanup. Registered as singleton in ServiceProvider.
- **EventCatalog::allWithPlugins()** — New static method that merges plugin-registered events into the built-in catalog. Built-in events take precedence on name conflicts. Accepts optional plugin events array from `EventPluginRegistry::catalogEvents()`.
- **AnalyticsIntegrityCommand** — New `zb:analytics:integrity` artisan command. Comprehensive integrity check covering: version consistency (composer.json, AnalyticsEvent::VERSION), event catalog completeness (core SaaS lifecycle, ecommerce, engagement events), config integrity (consent, auto-track, queue, providers), and plugin registry health (validation, name conflicts). Supports `--json` (machine-readable output), `--verbose` (individual check details), `--fix` (future auto-fix). Designed for CI pipelines and pre-release validation.
- **Config: `event_plugins` section** — New `zeroboiler.analytics.event_plugins` with `enabled`, `debug`, `plugins` settings. All configurable via environment variables.

### Changed

- **Version sweep** — 7.6.0/7.7.0 → 7.8.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [7.7.0] - 2026-08-10

### Added

- **EventSignalIntelligenceService** — Pipeline observability layer for monitoring event dispatch patterns across all providers. Detects anomalies (staleness, high failure rates, dispatch rate spikes), computes signal-to-noise ratio, and provides dispatch balance scoring. Inspired by Datadog Signal Intelligence and Honeycomb BubbleUp.
- **AnalyticsSignalIntelligenceCommand** — New `zb:analytics:signal` console command for signal intelligence reporting.
- **Signal Intelligence API** — New client-side functions: `fetchSignalReport()`, `fetchSignalScore()`, `fetchSignalAnomalies()`, `fetchSignalProviderHealth()` in JS client and Svelte composable.
- **Signal Intelligence composable** — New `useSignalIntelligence()` in Svelte composable.

### Changed

- **Version sweep** — 7.6.0 → 7.7.0 across JS client, Svelte composable, TypeScript definitions.

## [7.6.0] - 2026-08-10

### Added

- **CohortWaterfallService** — Revenue flow decomposition by cohort period. Visualizes how users flow through signup → trial → conversion → active → renewing → expansion → contraction → churn stages. Produces waterfall-style data structures suitable for dashboard chart rendering. Features: per-cohort stage analysis with drop-off rates and cumulative conversion rates, NRR computation, expansion/contraction/churned MRR breakdown, cohort comparison with delta analysis, actionable insights generation (low conversion warnings, churn alerts, NRR status, expansion/contraction ratios), quick summary endpoint. Cache-backed, config-driven. Registered as singleton in ServiceProvider.
- **FunnelDropoffIntelligenceService** — Smart funnel analysis with bottleneck detection, anomaly detection, time-to-convert analysis, and actionable recommendations. Features: per-step drop-off counts/rates/cumulative conversion rates, bottleneck severity classification (low/moderate/high/critical), anomaly detection via spike multiplier (>2x previous step drop-off), time-based UX recommendations, period comparison with improved/degraded/unchanged classification, funnel step count thresholds. Cache-backed, config-driven. Registered as singleton in ServiceProvider.
- **Config: `cohort_waterfall` section** — New `zeroboiler.analytics.cohort_waterfall` with `enabled`, `cache_ttl`, `granularity` (weekly/monthly), `currency`, `projection_months` settings. All configurable via environment variables.
- **Config: `funnel_intelligence` section** — New `zeroboiler.analytics.funnel_intelligence` with `enabled`, `cache_ttl`, `bottleneck_threshold` (default 50% drop-off), `anomaly_threshold` (default 2.0x spike multiplier) settings.
- **API endpoints: Cohort Waterfall** — 4 new REST endpoints: `POST /api/analytics/cohort-waterfall` (full report), `POST /api/analytics/cohort-waterfall/summary` (quick summary), `POST /api/analytics/cohort-waterfall/compare` (period comparison), `GET /api/analytics/cohort-waterfall/stages` (default stages).
- **API endpoints: Funnel Intelligence** — 2 new REST endpoints: `POST /api/analytics/funnel-intelligence` (full analysis with bottlenecks/anomalies/recommendations), `POST /api/analytics/funnel-intelligence/compare` (period comparison).
- **V750CohortWaterfallFunnelIntelligenceTest** — 35+ Pest test cases covering: version consistency (7.5.0 across 7 entry points), PHP 8.5 patterns (final class, strict types, return types, docblocks), CohortWaterfallService report with stage-level data/drop-off/NRR/insights, quickSummary, compare with delta analysis, stages defaults, empty cohorts handling, FunnelDropoffIntelligenceService full analysis with bottleneck detection/critical severity/anomaly detection/recommendations, comparePeriods with improved/degraded classification, empty steps handling, config sections, route registration, ServiceProvider singleton registration, event catalog integrity, NRR formula verification.

### Changed

- **Version sweep** — 7.4.0 → 7.6.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), TypeScript definitions (@version), ServiceProvider (@version), README badge.

## [7.0.0] - 2026-08-10

### Added
- `pestphp/pest-plugin-type-coverage` to require-dev

### Changed
- Version bump to 7.6.0

## [7.0.0] - 2026-08-10

### Added

- **EventDataMartService** — Pre-aggregated OLAP-style event rollup cubes for instant dashboard queries. Materializes raw analytics events into time-binned summary cells stored in the Laravel cache, enabling fast top-N queries without scanning raw event streams. Supports 5 granularity levels (minute, hour, day, week, month) and 6 aggregation dimensions (event_name, category, provider, client_id, user_id, source). Features: unique client tracking with HyperLogLog-inspired probabilistic counting fallback, dimension cardinality limits, batch ingestion, category filtering, cube export, dimension comparison for drift detection, and full clear. Registered as singleton in ServiceProvider.
- **AnalyticsInsightEngineService** — Automated analytics insight generation combining data mart rollups, catalog coverage analysis, and health signals. Inspired by Amplitude Compass and Mixpanel Signal. Generates structured insight reports with severity levels (info, warning, critical), covering: category distribution analysis, core SaaS catalog completeness, revenue event coverage, provider mapping coverage (GA4/Meta/PostHog), growth signals, GDPR compliance gaps, and data mart freshness. Quick health assessment for dashboards. Registered as singleton in ServiceProvider.
- **AnalyticsInsightsCommand** — New `php artisan analytics:insights` console command. Generates comprehensive insight reports combining data mart status, category distribution, top events, catalog coverage, and health signals. Supports three output formats: table (default), json, summary. Severity filtering with `--severity` flag. Ideal for daily cron jobs and monitoring.
- **Config: `data_mart` section** — New `zeroboiler.analytics.data_mart` with `enabled`, `cache_ttl`, `default_granularity`, `max_dimensions`, `auto_dimensions`, `tracked_categories` settings. All configurable via environment variables.
- **Config: `insight_engine` section** — New `zeroboiler.analytics.insight_engine` with `enabled`, `cache_ttl`, `top_movers_count`, `drift_threshold`, `growth_threshold`, `decline_threshold` settings.
- **API endpoints: Event Data Mart** — 8 new REST endpoints: `GET /api/analytics/data-mart/summary`, `/top/{dimension}`, `/by-category`, `/by-event`, `/by-provider`, `/export`, `/compare`, `DELETE /api/analytics/data-mart`. Public, rate-limited.
- **API endpoints: Insight Engine** — 4 new REST endpoints: `GET /api/analytics/insights` (full report), `/insights/latest` (cached), `/insights/health` (quick), `/insights/severity/{severity}` (filtered). Public, rate-limited.
- **V700DataMartInsightEngineTest** — 30+ Pest test cases covering: EventDataMartService instantiation, strict types, final class, supported granularities/dimensions, event ingestion with cache cell updates, batch ingestion, unique client tracking, disabled state, category filtering, summary output, data queries, cube export, dimension comparison, clear. AnalyticsInsightEngineService instantiation, strict types, report generation, disabled state, severity filtering, quick health assessment, latest cached report, insight structure validation. Version consistency (7.0.0 across all entry points), config sections, controller methods, routes, ServiceProvider registration.

### Changed

- **Version sweep** — 6.9.0 → 7.0.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composables (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.9.0] - 2026-08-10

### Added

- **EventDataMartService** — OLAP-style pre-aggregated event rollup cubes for instant dashboard queries. Materializes raw analytics events into time-binned summary tables stored in the Laravel cache, inspired by Amplitude/Mixpanel/PostHog data marts. Features: multi-granularity support (minute/hour/day/week/month), configurable auto-dimensions (event_name, category, provider, client_id, user_id), cardinality-limited cells, unique client tracking with HyperLogLog-inspired probabilistic counting cap, batch ingestion, dimension comparison for anomaly detection, full cube export, summary statistics. Registered as singleton in ServiceProvider.
- **Config: `data_mart` section** — New `zeroboiler.analytics.data_mart` with `enabled`, `cache_ttl`, `default_granularity`, `max_dimensions`, `auto_dimensions`, `tracked_categories` settings.

### Changed

- **Version sweep** — 6.9.0 → 7.0.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composable (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.9.0] - 2026-08-10

### Added

- **SaaSEventTemplateService** — Industry-standard SaaS event template service providing pre-configured templates for authentication (signup, login, logout with UTM attribution), subscription lifecycle (create with MRR/ARR, upgrade/downgrade with revenue impact, cancellation with churn context), trial management (start, convert with TTV, expire), revenue tracking (MRR movement framework, provider-optimized purchase with GA4+Meta+PostHog params), onboarding milestones (step completion with progress percent, flow completion), feature adoption (first use, power user milestones), account management (email verification, profile update), and e-commerce shortcuts (view item, add to cart with cross-provider params). Registered as singleton in ServiceProvider.
- **Catalog-Aware API Validation** — `TrackEventRequest` now validates event names against the EventCatalog in strict mode (`zeroboiler.analytics.validation.strict`). Invalid names receive fuzzy suggestions using Levenshtein distance and Jaccard word overlap scoring. Added `priority` parameter validation (critical|normal|low|background) to `TrackEventRequest`.
- **Auth State Change Detection** — Inertia middleware (`HandleInertiaAnalytics`) detects authentication state changes (login/logout) mid-session via session-stored previous user ID comparison. Exposes `authStateChanged` and `previousUserId` Inertia props for client-side identity stitching.
- **JS Client Auth Stitching** — JS client auto-detects auth state changes from Inertia props and fires identify + login events to stitch client_id ↔ user_id on login.
- **Config: `event_templates` section** — New `zeroboiler.analytics.event_templates` with `default_currency`, `auto_utm_attach`, `auto_user_id_attach`, `include_provider_params` settings.
- **V690SaaSEventTemplatesAndStrictValidationTest** — 35+ Pest test cases covering: SaaSEventTemplateService signup/login/logout, subscription with MRR/ARR calculation, plan upgrade/downgrade with revenue impact, cancellation with churn context, trial start/convert/expire, MRR movement (new/expansion/churn), revenue with provider-optimized params, onboarding step completion with progress, onboarding completion, feature first use and power user milestones, view item and add to cart with GA4+Meta params, UTM extraction (present/null), DTO creation without dispatch. TrackEventRequest catalog-aware validation, priority parameter, custom messages, accessor methods. EventCatalog integration for all template events, version consistency (6.9.0), catalog validation, revenue/GDPR events.

### Changed

- **Version sweep** — 6.8.0 → 6.9.0 across AnalyticsEvent::VERSION, JS client (getVersion + @version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.8.0] - 2026-08-10

### Added

- **CheckoutFlowTracker** — Multi-step checkout funnel tracking service with 5-step flow (cart_review → shipping_info → payment_info → order_review → confirmation). Features: step-level event dispatch (begin_checkout, checkout_step, purchase, checkout_abandon), step timing analysis, cart value computation via EcommerceFormatConverter, cache-backed state persistence, abandonment scoring integration. Registered as singleton in ServiceProvider.
- **SaaSKpiCalculatorService** — Industry-standard SaaS metrics computation service aligned with OpenView/Bessemer/KeyBanc benchmarks. Computes: MRR (mixed billing cycles), ARR, ARPU, churn rate (customer + revenue), LTV (ARPU ÷ churn), LTV:CAC ratio, payback period, Net Revenue Retention (NRR), Gross Revenue Retention (GRR), Quick Ratio, Rule of 40, trial-to-paid conversion rate, activation rate. Full `computeDashboard()` method with health assessment (healthy/warning/critical). Registered as singleton in ServiceProvider.
- **ProviderEventValidator** — Provider-specific event parameter validator. Validates GA4 items schema (required fields, max 25 items, ISO 4217 currency, numeric values, transaction_id for purchases), Meta Pixel (content_ids types, num_items consistency, content_type), PostHog (reserved $properties detection, $currency format), Plausible (no spaces in names, max length). `validateAll()` for cross-provider validation with per-provider error reports.
- **Config: `checkout_tracking` section** — New `zeroboiler.analytics.checkout_tracking` with `enabled`, `cache_ttl`, `currency` settings.
- **Config: `saas_kpi_calc` section** — New `zeroboiler.analytics.saas_kpi_calc` with `enabled`, `cache_ttl`, benchmark targets (MRR goal, churn warning, LTV:CAC target, Quick Ratio target, Rule of 40 target).
- **V68CheckoutKpiProviderValidatorTest** — 35+ Pest test cases covering: CheckoutFlowTracker start/advance/complete/abandon flow, step ordering validation, disabled state, step timing computation, funnel steps definition, step constants. SaaSKpiCalculatorService MRR (mixed billing cycles), ARR, ARPU, churn rate, LTV, LTV:CAC, payback, NRR, GRR, Quick Ratio, Rule of 40, trial conversion, activation rate, full dashboard computation, health assessment, benchmarks. ProviderEventValidator GA4 items schema, Meta Pixel params, PostHog reserved properties, Plausible event names, cross-provider validation.

### Changed

- **Version sweep** — 6.7.0 → 6.8.0 across composer.json, JS client (getVersion + _getInternalVersion + @version), Svelte composables (@version), CHANGELOG, README badge.

## [6.7.0] - 2026-08-10

### Added

- **V670SaaSStarterProductionReadinessTest** — Comprehensive production readiness test suite with 50+ Pest test cases validating: event catalog completeness (90+ events), cross-provider coverage (GA4, Meta, PostHog, Plausible), industry standard readiness (100% score), lifecycle event mapper defaults, e-commerce format conversion (GA4↔Meta↔PostHog), SaasRevenueEventBuilder (subscription, plan upgrade, cancellation + buildEvent), SaaS analytics service full lifecycle, EventBuilder priority delegation, GDPR compliance events, funnel templates (signup, trial, checkout), version consistency (7 entry points), PHP 8.5 strict types enforcement across all source files, B2B team events, privacy-safe events, SaaS acquisition/monetization events, DAU/MAU events, product health events, enterprise compliance events (GDPR, SOC2, ISO27001), and revenue events.

### Changed

- **Version sweep** — 6.6.0 → 6.7.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion + @version), Svelte composables (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.6.0] - 2026-08-10

### Added

- **CohortRevenueAttributionService** — Correlates cohort membership with revenue events to produce industry-standard LTV-by-cohort analysis, cumulative revenue curves, payback period estimation, and cohort-based revenue attribution. Cache-backed, no database required. Supports weekly (YYYY-WXX), monthly (YYYY-MM), and yearly (YYYY) cohort formats with configurable churn-based decay modeling.
- **Config: `cohort_revenue` section** — New `zeroboiler.analytics.cohort_revenue` with `enabled`, `cache_ttl`, `monthly_churn_rate`, `arpu`, `max_cohorts`, `projection_months`, `currency` settings.
- **Methods**: `recordRevenue()`, `recordCohortMember()`, `matrix()`, `compare()`, `projectLtv()`, `byType()`, `topCohorts()`, `summary()`, `healthScore()`, `getCohort()`, `cohortIds()`, `revenueByEvent()`, `clear()`.
- **V660CohortRevenueAttributionTest** — 30+ Pest test cases validating: service instantiation, config section, PHP 8.5 patterns (final class, strict types, return types), revenue recording, cohort member deduplication, matrix structure, cohort comparison, LTV projection curves, revenue by type, top cohort ranking, health score, individual cohort lookups, revenue by event breakdown, end-to-end lifecycle flow, LTV formula verification, retention decay, and ServiceProvider registration.

### Changed

- **Version sweep** — 6.5.0 → 6.6.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + @version), Svelte composables (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG.

## [6.5.0] - 2026-08-10

### Added

- **AnalyticsConfigExportService** — Runtime config export service with automatic secret redaction. Provides `exportRedacted()`, `exportStatusSummary()`, `exportSection()`, and `diff()` for config drift detection between deployments.
- **Config Export API endpoints** — Three new REST endpoints: `GET /api/analytics/config/export` (full redacted config), `GET /api/analytics/config/status` (provider/feature toggle summary), `GET /api/analytics/config/section/{name}` (single section).
- **Inertia props expansion** — Three new props injected into `page.props.zbAnalytics`: `sampling` (rate control), `geolocation` (enrichment status), `regionalConsent` (GDPR region detection).
- **JS client: config export helpers** — `fetchConfigExport()`, `fetchConfigStatus()`, `fetchConfigSection()` async functions for admin dashboards.
- **JS client: props accessors** — `getGeolocationStatus()`, `getSamplingConfig()`, `getRegionalConsentStatus()` synchronous accessors.
- **TypeScript definitions** — New interfaces: `SamplingConfig`, `GeolocationConfig`, `RegionalConsentConfig`. Added to `ZbAnalyticsProps`.
- **Config: config_export section** — New `zeroboiler.analytics.config_export` with `enabled`, `expose_secrets`, `cache_ttl` settings.
- **Config: expanded aliases documentation** — Categorized alias examples for authentication, SaaS lifecycle, e-commerce, engagement, and custom events.

### Changed

- **Version sweep** — 6.4.0 → 6.5.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + @version), Svelte composables (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.4.0] - 2026-08-09

### Added

- **SaasRevenueEventBuilder** — Static convenience service for building provider-optimized SaaS revenue and subscription events. Provides factory methods for `subscription()`, `planUpgrade()`, `planDowngrade()`, `cancellation()`, `trialStart()`, `trialConversion()`, `paymentSucceeded()`, `paymentFailed()`. Each method returns GA4, Meta Pixel, and PostHog parameter arrays. Includes `buildEvent()` cross-provider factory that creates dispatchable `AnalyticsEvent` instances.
- **Config: subscription lifecycle toggles** — Added `subscription.resumed` and `subscription.paused` lifecycle event toggles in the config file (previously supported by LifecycleEventMapper but not exposed in the published config).
- **V640IndustryStandardSaaSUpgradeTest** — 40+ Pest test cases validating: event catalog completeness (90+ events), lifecycle event mapper defaults, e-commerce format conversion (GA4↔Meta items/contents/purchase), SaasRevenueEventBuilder (all 8 factory methods + buildEvent), config section completeness (30+ required sections), version consistency (5 entry points), strict types enforcement across all source files, queue job serialization, cross-provider coverage, identity linking, GDPR consent compliance, and end-to-end SaaS lifecycle flow (signup → trial → subscribe → upgrade → cancellation).

### Changed

- **Version sweep** — 6.3.0 → 6.4.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + @version), Svelte composables (@version), TypeScript definitions (@version), ServiceProvider (@version), CHANGELOG, README badge.

## [6.2.0] - 2026-08-09

### Added

- **AARRRFrameworkService** — Unified AARRR (Pirate Metrics) framework service for measuring SaaS growth across five pillars: Acquisition, Activation, Retention, Revenue, and Referral. Provides weighted health scoring (0-100), coverage analysis per pillar, weakest/strongest pillar detection, unmapped event discovery, and cache-backed dashboard summary.
- **AnalyticsQuickSetupCommand** — `zb:analytics:setup` console command for quick project configuration analysis. Supports `--env` (print required .env variables), `--aarrr` (AARRR framework analysis), `--catalog` (event catalog summary), and `--fix` (common configuration issue detection).
- **AARRR config section** — New `zeroboiler.analytics.aarrr` configuration with `enabled` and `cache_ttl` settings.
- **V62AARRRFrameworkServiceTest** — 25+ Pest test cases covering: pillar definitions, weight validation, health scoring, cache behavior, weakest/strongest pillar detection, coverage analysis, unmapped events, dashboard summary, catalog integration, and score grading.

### Changed

- **Version sweep** — 6.1.0 → 6.2.0 across composer.json, README badge, CHANGELOG.
- **ServiceProvider** — Registered `AnalyticsQuickSetupCommand` in the console commands list.

## [6.1.0] - 2026-08-09

### Added

- **EventParameterSchema** — Immutable readonly value object representing a single event's parameter schema (name, category, required params, optional params with types, itemParams flag).
- **EventParameterSchemas** — Static registry with 65+ typed parameter schemas covering all Ecommerce (15 events), SaaS Lifecycle (50+ events), and Engagement (30+ events) categories. Provides `forEvent()`, `validate()`, `byCategory()`, `hasSchema()`, `schemaEventNames()`, and `count()` methods.
- **Runtime parameter validation** — `EventParameterSchemas::validate()` checks required parameters, type-checks optional parameters (string, integer, float, boolean, array), and returns descriptive error messages. Custom events without schemas bypass validation.
- **V61EventParameterSchemasTest** — 25+ test cases covering: schema coverage for all ecommerce, engagement, and core SaaS events; schema count threshold; category validation; specific schema structure validation (purchase, refund, sign_up, plan_upgrade, page_view, search, share); null handling for optional params; unknown event passthrough; type mismatch detection; category grouping; toArray serialization; itemParams consistency; and three end-to-end funnel validations (SaaS lifecycle, e-commerce purchase, engagement flow, and cohort analytics).

### Changed

- **Version sweep** — 6.0.0 → 6.1.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + @version), Svelte composables (@version), TypeScript definitions (@version), README badge, CHANGELOG.

## [5.9.0] - 2026-08-09

### Added

- **V59IndustryStandardSaaSUpgradeTest** — 35+ test cases validating industry-standard SaaS analytics readiness: version integrity, event catalog coverage (90+ events across Ecommerce/SaaS/Engagement categories), cross-provider format conversion (GA4↔Meta items/contents), lifecycle event mapper config-driven mappings, API controller method completeness, Inertia middleware SaaS props, Consent Mode v2 GDPR compliance, identity client ID ↔ user ID linking, optional providers (Plausible, PostHog), admin commands (Overview, Test, Diagnostics, Health), PHP 8.5 `declare(strict_types=1)` enforcement across all 340+ source files, config section completeness (22 required sections), JS client batch queue and sendBeacon implementation, provider health monitor, event routing configuration, and end-to-end SaaS funnel flow validation (signup → trial → subscription → upgrade → cancellation).

### Changed

- **Version sweep** — 5.7.0 → 5.9.0 across all 102 files (PHP source, JS client, Svelte composables, config, routes, README, CHANGELOG, 100+ test files).
- **README** — Added v5.9.0 changelog entry, updated TOC, fixed version badge.

## [5.8.0] - 2026-08-09

### Fixed
- Replace static Eloquent call in `AnalyticsGateService::resolvePlan()` with `(new $model)->newQuery()->find()` pattern for testability

## [5.8.0] - 2026-08-09

### Added

- **ProviderHealthMonitor** — Per-provider dispatch health monitoring with sliding window success/failure tracking and computed health scores (0-100). Unhealthy providers (score < 50) are flagged and can be bypassed during routing. Integrates with ProviderCircuitBreaker for coordinated failover. `isHealthy()`, `getScore()`, `getStatus()`, `summary()`, `activeProviders()`, `providersByHealth()`, `recordSuccess()`, `recordFailure()`, `reset()`.
- **Config section: `routing`** — Declarative event → provider routing rules. Supports exact match (`purchase`), wildcard prefix (`add_to_*`), and suffix (`*_click`) patterns. Events with no matching rules fall through to all enabled providers.
- **Config section: `provider_health`** — Provider health monitor settings: `enabled`, `window_duration`, `unhealthy_threshold`.
- **API: Event Routing** — 6 new endpoints: `GET /api/analytics/routing` (summary), `GET /routing/rules` (list), `POST /routing/rules` (add), `DELETE /routing/rules/{pattern}` (remove), `POST /routing/match` (match event), `POST /routing/test` (test pattern).
- **API: Provider Health** — 3 new endpoints: `GET /api/analytics/provider-health` (all providers), `GET /provider-health/{provider}` (detail), `POST /provider-health/reset` (reset stats).
- **JS client: `trackEventWithProviders()`** — Target specific providers for a single event. Pass provider names as third argument (e.g., `['ga4', 'meta']`).
- **JS client: `trackEcommerceWithProviders()`** — Provider-targeted e-commerce event dispatch with client-side GA4/Meta push filtering.
- **V570EventRoutingAndVersionSweepTest** — 14 test cases covering version sweep, routing pattern matching, runtime rule management, provider health tracking, config sections, routes, and backward compatibility.

### Changed

- **Version sweep** — 5.6.0 → 5.8.0 across all PHP source files, JS client, Svelte composables, TypeScript definitions, test files (436+ version assertions), composer.json, and README badge.
- **Route count** — 459+ → 468+ registered API routes.

## [5.6.0] - 2026-08-09

### Added
- `@since 1.0.0` annotations on all 343 source files with class/interface/trait/enum declarations
- Phase 2-3-4 production test: glob-based `@since` verification across entire source tree

### Changed
- README version badge updated to 5.6.0

## [5.0.0] - 2026-08-09

### Added

- **AnalyticsDataService** — Cache-backed time-series analytics data for dashboard queries. Provides DAU/MAU, stickiness ratio, daily/monthly revenue, top events, provider dispatch stats, conversion funnels, retention metrics, and cohort analysis. No database required — uses the application cache driver. `getDAU()`, `getMAU()`, `getStickiness()`, `getDailyRevenue()`, `getMonthlyRevenue()`, `getRevenueBySource()`, `getTopEvents()`, `getProviderStats()`, `getFunnelConversion()`, `getRetentionMetrics()`, `getDashboardSummary()`.
- **EventTaxonomyService** — Tag-based event classification beyond the existing category system. Events are auto-classified into tags by domain: `revenue`, `conversion`, `acquisition`, `authentication`, `engagement`, `onboarding`, `retention`, `billing`, `compliance`, `analytics`. Supports multi-dimensional filtering (AND/OR logic), tag groups, tag hierarchy, and search. `getTags()`, `addTags()`, `removeTags()`, `getEventsWithTag()`, `getEventsWithAllTags()`, `getEventsWithAnyTag()`, `getTagSummary()`, `autoClassify()`.
- **AnalyticsEventOccurred** — Laravel event dispatched after every analytics event is tracked. Contains the analytics event DTO, provider dispatch results, and request context. Enables application code and third-party packages to react to analytics events without modifying core dispatch logic. Config-gated via `broadcast.enabled`.
- **TenantAnalyticsContext** — Multi-tenant analytics context for workspace-aware event tracking. Supports manual, subdomain, header-based, or callback-based tenant resolution. Provides tenant-scoped event counting, revenue tracking, and context enrichment via `eventContext()`. Safe `withinTenant()` scope automatically restores previous context. `setTenant()`, `clearTenant()`, `getTenantId()`, `eventContext()`, `withinTenant()`, `incrementTenantEventCount()`, `getTenantStats()`, `recordTenantRevenue()`.
- **Config section: `data_service`** — Dashboard analytics data settings: `enabled`, `cache_ttl`, `daily_ttl`.
- **Config section: `taxonomy`** — Event taxonomy settings: `enabled`, `cache_ttl`, `auto_classify`, `tags` (config-driven event → tags mapping).
- **Config section: `tenant`** — Multi-tenant analytics settings: `enabled`, `resolver` (manual/subdomain/header/callback), `header`, `subdomain_prefix`, `cache_ttl`, `auto_tag_events`.
- **Config section: `broadcast`** — Event broadcasting settings: `enabled`, `exclude_events`.
- **Dashboard API endpoints** — `GET /api/analytics/dashboard` (full summary), `GET /dashboard/dau`, `GET /dashboard/mau`, `GET /dashboard/stickiness`, `GET /dashboard/revenue`, `GET /dashboard/top-events`, `GET /dashboard/providers`, `GET /dashboard/funnel/{funnelName}`.
- **Event Taxonomy API endpoints** — `GET /api/analytics/taxonomy/tags`, `GET /taxonomy/groups`, `GET /taxonomy/summary`, `POST /taxonomy/classify`, `GET /taxonomy/event/{eventName}`, `GET /taxonomy/tag/{tagName}/events`.
- **Multi-Tenant API endpoints** — `GET /api/analytics/tenant/{tenantId}/stats`, `GET /tenant/{tenantId}/revenue`.
- **ServiceProvider registrations** — `AnalyticsDataService`, `EventTaxonomyService`, `TenantAnalyticsContext` registered as singletons.
- **V500IndustryStandardUpgradeTest** — 28 test cases covering all v5.0.0 features: version sweep, data service DAU/MAU/stickiness/revenue/providers/funnel, taxonomy auto-classification/tag-groups/filtering, tenant context scoping, Laravel event structure, config sections, route additions, and service layer size checks.

### Changed

- **Version bump** — 4.6.0 → 5.0.0 across all PHP source files (33 files), JS client (3 version strings), Svelte composables, TypeScript definitions, tests (81 files), composer.json, README badge. Full @version docblock sweep.
- **Route count** — 130+ → 150+ registered API routes.
- **PHP requirement** — Now requires PHP 8.5+ (Laravel 13 compatibility).
- **LOC** — 150K+ → 160K+ across 340+ PHP source files and 165+ test files.

## [4.6.0] - 2026-08-09

### Added

- **AnalyticsAIService** — AI-powered analytics intelligence with z-score anomaly detection, smart event suggestions, trend analysis (linear regression), and automated insight generation. All self-contained — no external AI API required. `detectAnomaly()`, `detectBatchAnomalies()`, `generateInsights()`, `analyzeTrend()`, `suggestEvents()`.
- **EventExperimentTracker** — A/B test experiment tracking with statistical significance calculation using two-proportion z-test. Cache-backed experiment lifecycle management. `createExperiment()`, `trackEvent()`, `calculateSignificance()`, `completeExperiment()`, `pauseExperiment()`, `resumeExperiment()`.
- **SaaSQuickStartService** — One-call SaaS event tracking setup. `trackSignUp()`, `trackLogin()`, `trackTrialStart()`, `trackTrialConversion()`, `trackSubscription()`, `trackPlanUpgrade()`, `trackCancellation()`, `trackPurchase()`, `trackFeatureUsed()`, `trackError()`, `trackOnboardingSequence()`.
- **Config section: `ai`** — AI intelligence settings: `enabled`, `cache_ttl`, `anomaly_threshold` (z-score), `anomaly_window`, `rolling_window`.
- **Config section: `experiment`** — A/B test settings: `enabled`, `cache_ttl`, `significance_threshold` (default 95%), `min_sample_size` per variant.
- **ServiceProvider registrations** — `AnalyticsAIService`, `EventExperimentTracker`, `SaaSQuickStartService` registered as singletons.
- **V460IndustryStandardSaaSUpgradeTest** — 40+ test cases covering all new services, version consistency sweep, config expansion, and catalog integrity.

### Changed

- **Version bump** — 4.5.0 → 4.6.0 across all PHP source files, JS client, Svelte composables, TypeScript definitions, composer.json, README badge. Full @version docblock sweep (27 files updated).
- **Stale VERSION constants** — `SessionReplayService`, `AdvancedPIIDetector`, `AnalyticsHealthCheckService` constants updated to 4.6.0.

## [4.5.0] - 2026-08-09

### Added

- **AnalyticsConfigAuditService** — Safe, masked dump of analytics configuration for debugging, admin dashboards, and compliance audits. Recursively masks sensitive values (API keys, secrets, tokens). URL masking preserves domain. `audit()`, `summary()`, `diff()`, `saveSnapshot()`, `loadSnapshot()`.
- **EventCatalogValidator** — Catalog-aware event validation service. Validates incoming events against the registered EventCatalog with structured error messages for invalid names, format violations, and length issues. `validate()`, `validateBatch()`, `isCatalogEvent()`, `getCategory()`, `catalogStats()`, `suggest()`.
- **Config Audit API endpoints** — `GET /api/analytics/config/audit` (full masked dump), `GET /api/analytics/config/summary` (provider/feature status), `POST /api/analytics/config/snapshot` (save snapshot), `GET /api/analytics/config/snapshot/{label}` (load snapshot), `POST /api/analytics/config/diff` (compare against snapshot).
- **Catalog Validation API endpoints** — `POST /api/analytics/catalog/validate` (validate event), `GET /api/analytics/catalog/stats` (catalog statistics), `GET /api/analytics/catalog/suggest?q=pur&limit=5` (fuzzy search).
- **ServiceProvider registrations** — `AnalyticsConfigAuditService` and `EventCatalogValidator` registered as singletons, injected into `AnalyticsEventController`.
- **V450ConfigAuditCatalogValidatorTest** — 25 test cases covering config audit (masking, summary, diff, snapshot), catalog validator (validation, batch, stats, suggestions, membership), version consistency across all files, route/controller/registration checks, and README documentation.

### Changed

- **Version bump** — 4.4.0 → 4.5.0 across `AnalyticsEvent::VERSION`, composer.json, ServiceProvider docblock, README badge, JS client header, JS `getVersion()`, TypeScript `@version`, Svelte composables `@version`.

### Fixed

- **JS client `getVersion()`** — Now correctly returns `'4.5.0'` (was stale at `'4.2.0'`).
- **Duplicate docblock** — Removed orphaned `trackSaaSAcquisition` docblock in `AnalyticsManager.php` that appeared between `cancellation()` and `healthCheck()`.

## [4.4.0] - 2026-08-09

### Added

- **EventCostTracker** — Per-provider analytics cost estimation service. Supports free tiers, per-event pricing, and tiered cost models. Provides projected monthly cost estimates, per-provider cost breakdown, free tier remaining, and most-expensive-provider detection. `report()`, `providerCost()`, `cliSummary()`, `isWithinFreeTier()`, `mostExpensiveProvider()`. Cache-backed, reads from AnalyticsMetrics.
- **NotificationWebhookService** — Multi-channel alert notification delivery to Slack (Block Kit), Discord (embeds), Microsoft Teams (Adaptive Cards), PagerDuty (Events API v2), and generic HTTP webhooks. Smart filtering by severity threshold and event name pattern. Rate limiting per webhook. Retry with exponential backoff. Delivery tracking with success/failure stats. `sendAlert()`, `sendCustom()`, `testWebhook()`, `deliveryStats()`, `getWebhooks()`.
- **AnalyticsCostReportCommand** — `zb:analytics:cost-report` artisan command with full cost table, per-provider view, JSON output, free tier status, budget recommendations. `--json`, `--provider=ga4` options.
- **Config sections** — `cost_tracking` (enabled, currency, provider pricing overrides) and `notification_webhooks` (enabled, rate_limit_seconds, webhooks with url/channel/severity/events/secret/retries config).
- **API endpoints** — Cost tracking (GET /api/analytics/cost, GET /api/analytics/cost/{provider}) and Notification webhooks (GET /api/analytics/notifications/webhooks, GET /api/analytics/notifications/stats, POST /api/analytics/notifications/test/{name}, POST /api/analytics/notifications/send).
- **ServiceProvider registrations** — EventCostTracker and NotificationWebhookService registered as singletons. AnalyticsCostReportCommand registered.
- **V440CostTrackingNotificationsTest** — 20 test cases covering EventCostTracker (report, providers, free tier, CLI, pricing) and NotificationWebhookService (enabled, empty webhooks, disabled, stats, unknown webhook).
- **Version bump** — 4.3.0 → 4.4.0 across AnalyticsEvent::VERSION, composer.json, ServiceProvider docblock, README badge.

### Changed
- **README** — Added v4.4.0 section with full feature documentation, pricing table, code examples, API reference, and channel format guide.

## [3.6.0] - 2026-08-09

### Added

- **GrowthMetricsService** — Product-level growth analytics: activation rate, time-to-activate, D30 stickiness, per-feature stickiness, engagement velocity (events/user/day), cohort health (D1/D7/D30 retention), composite growth score (0-100, A-F grade). `activationMetrics()`, `stickinessMetrics()`, `engagementVelocity()`, `cohortHealth()`, `dashboard()`, `cliSummary()`. Cache-backed, no database required
- **OnboardingWizardService** — Guided 6-step onboarding for analytics instrumentation: Core Setup → Acquisition → Activation → Revenue → Retention → Growth. Progress tracking, config readiness checklist, event recommendations, quick-start checklist, readiness grade (A-F). `getSteps()`, `getDetailedProgress()`, `getRecommendations()`, `getConfigChecklist()`, `getReadinessGrade()`, `getQuickStartChecklist()`, `getState()`
- **WeeklyDigestService** — Automated weekly analytics digest: event overview, provider health, SaaS funnel metrics, retention & engagement, e-commerce (conditional), growth insights with alerts. Cache-backed for 7 days. `generate()`, `latest()`, `cliSummary()`, `currentIsoWeek()`
- **EventStreamService API** — Added `getEventCount()`, `getTotalCount()`, `getRecentEvents()` methods for ring-buffer query compatibility
- **ServiceProvider registrations** — All three new services registered as singletons

### Changed

- Version bump: 3.5.0 → 3.6.0 (`AnalyticsHealthCheckService::VERSION`, README badge, composer.json)
- README: Added v3.6.0 section with full feature documentation

## [3.4.0] - 2026-08-09

### Added (v3.4.0 — SaaS Starter Level Upgrade)

- **EventCollection DTO** — Typed immutable collection for batch event operations. `fromArray()`, `fromEvents()`, `empty()`, `add()`, `addMany()`, `merge()`, `byName()`, `filter()`, `map()`, `names()`, `groupByName()`, `isEmpty()`, `take()`, `skip()`, `toArray()`. Implements `Countable` and `IteratorAggregate`
- **AnalyticsEventDispatcher** — Unified, consent/priority/sampling/queue-aware event dispatch service. Single entry point replacing direct `AnalyticsManager::trackEvent()` calls. Config-driven via `zeroboiler.analytics.dispatcher`. `dispatch()`, `dispatchCollection()`, `dispatchBatch()`, `getConfig()`
- **usePlausible() Svelte composable** — Provider-specific Plausible Analytics tracking. `trackCustomEvent()`, `trackPageView()`, `trackOutboundLink()`. Dual dispatch: client-side Plausible script + server-side API
- **usePostHog() Svelte composable** — Provider-specific PostHog tracking. `trackEvent()`, `identify()`, `setProperties()`, `reset()`, `capturePageView()`, `isFeatureEnabled()`. Full PostHog client API coverage
- **useEngagement() Svelte composable** — UX analytics: `trackScrollDepth()` (25/50/75/100% thresholds with cleanup), `trackFormInteraction()`, `trackSearch()`, `trackShare()`, `trackError()`. Efficient passive event listeners
- **Dispatcher config section** — New `zeroboiler.analytics.dispatcher` config with `consent_aware`, `dedup_enabled`, `sampling_rate`, `debug` options
- **Comprehensive test suite** — 25+ tests covering EventCollection, AnalyticsEventDispatcher (consent bypass, dedup, queue, batch, immediate), Svelte composable exports, version consistency
- **ServiceProvider registration** — `AnalyticsEventDispatcher` registered as singleton with `AnalyticsManager` and `QueuedAnalyticsDispatcher` dependencies

### Changed
- Version bump: 3.3.1 → 3.4.0 across all PHP (`AnalyticsEvent::VERSION`, `AnalyticsHealthCheckService::VERSION`), JS (`analytics.js`, `useAnalytics.svelte.js`, `analytics.d.ts`), `composer.json`, and README

## [3.3.1] - 2026-08-09

### Fixed
- **AnalyticsInsightAggregator constructor** — Fixed syntax error: `) void {` → `): void {` (PHP parse error)
- **Phase 5 version test** — Updated hardcoded version check from `2.95.0` to `3.3.0`
- **Console command count** — Updated from 9 to 10 in Phase 2-3-4 and Phase 5 tests (missing `AnalyticsBehavioralCommand`)
- **README version badge** — Updated from `3.1.0` to `3.3.0`

### Added
- **Phase 2-3-4 finality checks** — Added v3.1 (EventRulesEngine, UserPropertiesStore, RetentionCalculator, BehavioralCohortBuilder), v3.2 (IdentityResolutionService, EventDebounceService), and v3.3 (EventOrchestrationService, AnalyticsInsightAggregator) to finality tests
- **Phase 5 v3.1-v3.3 service audit** — Deep return-type and finality checks for all new services
- **AnalyticsBehavioralCommand finality check** — Added to Phase 5 console command tests

## [3.3.0] - 2026-08-09

### Added (v3.3.0)

See [3.2.0] for previous changes.

## [3.0.0] - 2026-08-09

### Added
- **EventContext DTO** — Immutable readonly DTO for HTTP request → analytics event context resolution. Client/user identity, device info, UTM params, referrer, session, locale, geolocation, consent state. `fromRequest()`, `toParams()`, `with()`, `identity()`, `hasUser()`, `hasClientId()`, `hasUtm()`, `hasConsent()`
- **HasEventSchema Trait** — Reusable schema-aware validation trait for event classes. Required params, type checking (`string/int/float/bool/array`), max param enforcement. `validateParams()`, `isValid()`, `buildEvent()`, type-safe param extractors with defaults
- **EventContextResolver Service** — Centralized config-driven context resolution. Client ID from cookie, user ID from auth, UTM from query, device detection (browser/OS/type), Inertia props builder, cookie config accessor, UUID v4 client ID generation
- **V300EventContextSchemaTraitTest** — 30+ tests covering EventContext, EventCatalog, and HasEventSchema

### Changed
- **Version 3.0.0** — Complete version consistency sweep across 50+ source files: AnalyticsManager, ServiceProvider, config, JS client, TypeScript definitions, all controllers, all services, routes, README, CHANGELOG
- **No breaking changes** — All existing APIs remain backward compatible

## [2.98.0] - 2026-08-08

### Added
- **EventBuilder** — Fluent, type-safe builder for constructing analytics events with catalog-aware validation, provider name resolution, `dispatch()` and `dispatchAsync()` shortcuts, static factory methods (`purchase()`, `signUp()`, `pageView()`)
- **SessionReplayService** — Cache-based session event recording with ring buffer, timeline reconstruction, session summaries (revenue/error flags), per-user session indexing, and TTL management
- **AdvancedPIIDetector** — Regex-based PII detection for 14 built-in patterns (email, phone, credit card, SSN, IBAN, JWT, IP, address), field name heuristics for 30+ PII field patterns, configurable confidence threshold, and `redact()` method with first/last character preservation
- **Config: session_replay** — `ANALYTICS_SESSION_REPLAY_ENABLED`, `ANALYTICS_SESSION_REPLAY_MAX_EVENTS`, `ANALYTICS_SESSION_REPLAY_TTL`
- **Config: pii_detection** — `ANALYTICS_PII_DETECTION_ENABLED`, `ANALYTICS_PII_DETECTION_THRESHOLD`, custom patterns support
- **V98EventBuilderPIISessionReplayTest** — Test cases covering EventBuilder, AdvancedPIIDetector, and SessionReplayService

### Changed
- Version bump to 2.98.0 across 23 source files, test files, JS/TS client, and documentation

## [2.97.0] - 2026-08-08

### Added
- **AnalyticsHealthCheckService** — Comprehensive diagnostic service checking 12 subsystems: providers, catalog, AARRR coverage, identity, queue, GDPR, consent, lifecycle, auto-track, dedup, API, and pipeline
- **Health Check API Endpoints** — `GET /api/analytics/health-check` (full diagnostic) and `GET /api/analytics/ping` (lightweight monitoring)
- **AnalyticsManager::healthCheck()** and **AnalyticsManager::ping()** — Programmatic convenience methods
- **Facade annotations** — `healthCheck()`, `ping()`, `maturityScore()`, `onboardingChecklist()`, `funnelReadiness()` documented
- **V97HealthCheckDiagnosticTest** — 25 test cases covering all health check subsystems, recommendations, version consistency

### Changed
- Version bump to 2.97.0 across all source files

## [2.96.0] - 2026-08-08

### Added
- **SaaS Lifecycle Convenience Methods** — `AnalyticsManager::signUp()`, `::login()` (auto identity linking), `::trialStart()`, `::subscription()`, `::planUpgrade()`, `::cancellation()` — one-liner SaaS event dispatch
- **SaaS Acquisition Funnel Shortcut** — `AnalyticsManager::trackSaaSAcquisition()` fires signup → trial → subscribe in one call
- **Inertia Page View Auto-Tracker** — `initInertiaPageViewTracker()` with `inertia:navigate`, `inertia:success`, `popstate` hooks, scroll depth option, and cleanup
- **TypeScript `InertiaPageViewTrackerOptions`** interface for the new auto-tracker
- **V96SaasStarterUpgradeTest** — 16 test cases covering all new SaaS convenience methods, funnel shortcuts, event catalog validation, and version consistency
- **Facade annotations** — All 7 new methods documented with `@method` annotations

### Changed
- Version bump to 2.96.0 across composer.json, AnalyticsEvent::VERSION, AnalyticsManager::version(), JS client (3 locations), TypeScript definitions
- **Fixed `initSvelteTracker`** — Event listeners now use proper `addEventListener`/`removeEventListener` pattern instead of broken return-value cleanup
- Overview command features list updated with SaaS lifecycle convenience methods

## [2.94.0] - 2026-08-08

### Added
- **SchemaDrivenEventBuilder** — Schema-driven event builder service that validates parameters against EventPropertySchema and EventSchemaRegistry for type coercion, required field enforcement, and validation
- **SchemaDiffReporter** — Schema coverage and diff reporter comparing EventCatalog, EventPropertySchema, and EventSchemaRegistry for gaps, mismatches, and coverage statistics
- **EventPropertySchema::registerBuiltInSchemas()** — Full catalog schema coverage with typed property schemas for all e-commerce, SaaS, engagement, and lifecycle events
- **AnalyticsSchemaExportCommand** — Artisan command (`zb:analytics:schema:export`) to export event schemas as JSON, TypeScript, or summary with optional coverage report
- **V94SchemaDrivenBuilderDiffTest** — Comprehensive test cases for SchemaDrivenEventBuilder, SchemaDiffReporter, EventPropertySchema, and AnalyticsSchemaExportCommand

### Changed
- Version bump to 2.94.0 across all source files (composer.json, AnalyticsEvent::VERSION, AnalyticsManager::version())
- Phase 2-3-4 production readiness tests updated: new services added to finality checks, console commands count updated from 7 to 9
- Phase 5 production readiness tests expanded with v2.94 schema service audit block

## [2.93.0] - 2026-08-08

### Added
- **FunnelProgressTracker** — Cache-persisted funnel progress tracker with completion percentage, step timing, automatic advancement/regression detection, and `funnel_step`/`funnel_completed` event dispatch
- **AnalyticsManager::funnelProgress()** — Convenience method delegating to FunnelProgressTracker for stateful funnel tracking
- **Funnel progress config** — New `funnel_progress` config section with `ANALYTICS_FUNNEL_PROGRESS_ENABLED` toggle and customizable `known_funnels` list
- **Facade** — `Analytics::funnelProgress()` documented in Facade `@method` annotations
- **V93FunnelProgressTrackerTest** — 22 test cases covering FunnelProgressTracker structure, method signatures, functional behavior (advancement, regression, completion, duplicate prevention), config integration, AnalyticsManager delegation, ServiceProvider registration, and version consistency

### Changed
- Version bump to 2.93.0 across all source files, config, JS client, TypeScript definitions, and 100+ test file assertions

## [2.91.0] - 2026-08-08

### Fixed
- **Version consistency sweep** — All 158 hardcoded `2.90.0` assertions across 60+ test files updated to `2.91.0`
- **Stale version guard** — V43 stale version reference check updated to detect removed `2.90.0` instead of `2.91.0`
- **License header** — `FeatureFlagIntegrationService` updated to use standard ZeroBoiler license header format

### Changed
- **README** — Added v2.91.0 changelog section and TOC entry

## [2.90.0] - 2026-08-08

### Added
- **5 new SaaS lifecycle events** — Expanded event catalog with critical SaaS lifecycle events:
  - `AccountDeletedEvent` — GDPR right-to-erasure tracking with reason, method, account age, and last plan
  - `SubscriptionCreatedEvent` — Subscription creation with plan, value, currency, billing cycle, and acquisition source
  - `SubscriptionCancelledEvent` — Subscription cancellation with full context (reason, flow, effective date, retention offer status)
  - `TrialExpiredEvent` — Trial lapse without conversion with plan, trial length, feature usage, and last activity
  - `PlanChangedEvent` — General-purpose plan transition with from/to plan, direction, reason, price difference, and currency
- **EventCatalog::gdprEvents()** — Returns GDPR-related events for compliance tracking (PII events, consent, account deletion)
- **EventCatalog::billingEvents()** — Updated billing events list with new subscription_created, subscription_cancelled, and plan_changed
- **LifecycleEventMapper** — Added `account.deleted` → `AccountDeletedEvent` mapping with high priority (95)
- **Lifecycle config** — Added toggle entries for `account.deleted`, `subscription.created`, `subscription.cancelled`, `trial.expired`
- **V90SaaSLifecycleEventsTest** — 27 test cases covering all 5 new event classes, catalog integration, version consistency, GDPR events helper, and billing events helper

### Changed
- Version bump to 2.90.0 across all files (AnalyticsEvent::VERSION, AnalyticsManager::version(), composer.json, JS client, TypeScript definitions, config catalog_version, ServiceProvider docblocks, 50+ controller/service version strings, 50+ test files)

## [2.89.1] - 2026-08-08

### Changed
- Phase 2-3-4 production readiness audit confirmed — all source files verified



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
