# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

## [Unreleased]

## [2.58.0] - 2026-08-07

### Added
- **JS Consent Purposes API** — Four new client-side functions for GDPR consent banner integration: `getConsentPurposes()`, `getConsentPurposeKeys()`, `getOptionalConsentPurposes()`, and `buildConsentSignals()`. These read the `consentPurposes` Inertia prop (injected by `HandleInertiaAnalytics`) and provide a purpose → Consent Mode v2 signal mapper with automatic `necessary` grant enforcement.
- **TypeScript ConsentPurpose type** — New `ConsentPurpose` interface with `label`, `required`, `default` fields. Added to `ZbAnalyticsConfig.consentPurposes` and all 4 new function declarations.
- **V58VersionConsistencyConsentPurposesTest** — 35+ test cases.

### Changed
- **Version unification to 2.58.0** — All 75+ version strings (AnalyticsManager, AnalyticsEventController 65 instances, 6 service files, JS client, TypeScript, composer.json) unified. Eliminated stale 2.52.0, 2.54.0, 2.57.0 references.
- Total test files: 100 (was 99)

## [2.57.0] - 2026-08-07

### Added
- **Event Schema Validation API** — 4 new REST endpoints for schema registry access: `GET /api/analytics/schemas` (all schemas with optional ?category filter and ?compact mode), `GET /api/analytics/schemas/summary` (category counts, parameter type distribution, avg params/event), `GET /api/analytics/schemas/{eventName}` (single schema with parameter type/maxLength details), `POST /api/analytics/schemas/validate` (validate event payload against schema with optional ?sanitize=1). All endpoints expose the existing EventSchemaRegistry via JSON API for client-side validation, documentation generation, and admin dashboards.
- **AnalyticsScheduledReportCommand** — Artisan command (`analytics:report:schedule`) for generating periodic analytics reports via Laravel Scheduler. Supports `--period` (hourly/daily/weekly/monthly), `--format` (json/table), `--output` (write to disk), and `--all` (all periods). Table format displays top events, provider breakdown, and key metrics. Designed for cron-based or scheduled delivery.
- **Config: `scheduled_reports`** — New config section with `enabled`, `output_path`, `auto_archive`, and `archive_days` settings. Example scheduler integration documented in config comments.
- **4 new routes** — Schema Validation API routes registered in both `routes/analytics.php` and `AnalyticsServiceProvider`.
- **V57SchemaApiScheduledReportTest** — 30+ test cases covering EventSchemaRegistry (catalog coverage, category grouping, validation, type checking, missing params, parameter listing), AnalyticsScheduledReportCommand (class structure, signature, method signatures, return types), controller schema methods (existence, return types, parameter signatures), config integrity, version consistency (6 files), route registration, filesystem integrity, and source file counts.

### Changed
- Version bump to 2.57.0
- AnalyticsManager::version() returns '2.57.0' (was '2.56.0')
- Composer version updated to 2.57.0
- JS client version string updated to 2.57.0 (was 2.56.0)
- TypeScript definitions version updated to 2.57.0 (was 2.56.0)
- EventSourceTagger::_version updated to 2.57.0 (was 2.56.0)
- EventEnvelopeService::summary() version updated to 2.57.0 (was 2.56.0)
- All 21 controller endpoint version strings updated to 2.57.0
- Total source files: 195 (was 194)
- Total test files: 96 (was 95)
- Total config sections: 50+ (was 48+)

## [2.56.0] - 2026-08-07

### Added
- **EventContextEvent DTO** — Fully-qualified analytics event with rich context envelope. Wraps a base AnalyticsEvent with session, device, geolocation, identity, UTM attribution, referrer, and consent context. Supports `fromEvent()` shorthand, `toArray()` serialization, `flattenedParams()` for provider dispatch (underscore-prefixed keys), `hasContext()` and `hasFullIdentity()` checks.
- **EventEnvelopeService** — Builds context-rich event envelopes from HTTP requests. Auto-enriches events with session, device (User-Agent parsing), geolocation (header strategy), identity (user_id/client_id from auth + cookie), UTM params, referrer, consent state, and metadata. Config-driven section toggles via `zeroboiler.analytics.envelope`. Supports `build()` (from request) and `buildFromEvent()` (server-side/queue) methods.
- **ConsentAwareFilter** — Granular consent-aware pipeline filter. Evaluates each event against consent purposes (analytics, functional, marketing, necessary) before dispatch. Events mapped to purposes via configurable rules — page_view→analytics, purchase→analytics+functional, error→necessary. Supports per-user granular consent via ConsentLogService, global ConsentState fallback, purpose→signal mapping (purposeToSignalMap), `isPermitted()` check for client-side gating, and custom purpose mapping overrides.
- **JS client consent-aware pre-queue** — Buffers events fired before consent resolution. `consentGranted()` replays queued events, `consentDenied()` discards them. Max 50 events buffered with LRU eviction. New exports: `consentGranted()`, `consentDenied()`, `getConsentState()`, `getConsentPreQueueCount()`, `resetConsentState()`.
- **3 new API endpoints** — `GET /api/analytics/consent/purposes` (purpose definitions + event→purpose mapping), `GET /api/analytics/consent/envelope-info` (envelope service status + active sections), `GET /api/analytics/consent/history` (authenticated, per-user consent change audit trail).
- **2 new config sections** — `envelope` (9 toggles for context section enrichment), `consent_purposes` (enabled + strict mode for granular consent filtering).
- **2 new service bindings** in AnalyticsServiceProvider: EventEnvelopeService (with 7 optional service dependencies), ConsentAwareFilter (with ConsentLogService).
- **V56EventEnvelopeConsentPreQueueTest** — 45+ test cases covering EventContextEvent DTO (7 tests: construction, fromEvent, toArray, flattenedParams, hasContext, hasFullIdentity), EventEnvelopeService (5 tests: build, disabled, activeSections, summary), ConsentAwareFilter (15 tests: disabled, necessary, identify, denied/granted analytics, denied functional, granted ecommerce, denied marketing, fail-open, purposeToSignalMap, getRequiredPurposes, isPermitted, setPurposeMapping, getPurposeMap, per-user consent lookup), config integrity (2 tests), version consistency (5 tests), route registration, filesystem integrity (strict types, docblocks, final classes), JS client consent exports, TypeScript definitions.

### Changed
- Version bump to 2.56.0
- AnalyticsManager::version() returns '2.56.0' (was '2.55.0')
- Composer version updated to 2.56.0
- JS client version string updated to 2.56.0 (was 2.54.0)
- TypeScript definitions version updated to 2.56.0 (was 2.54.0)
- EventSourceTagger::_version updated to 2.56.0 (was 2.52.0)
- All 13 controller endpoint version strings updated to 2.56.0
- Total source files: 194 (was 191)
- Total test files: 95 (was 93)
- Total config sections: 48+ (was 46+)

## [2.50.0] - 2026-08-07

### Added
- **PostHog e-commerce format conversion** — `EcommerceFormatConverter::ga4ToPosthogProperties()`, `ga4ToPosthogPurchase()`, `ga4ToPosthogRefund()`, `buildPosthogPurchase()` for full GA4→PostHog item/transaction parameter transformation
- **3-provider purchase builder** — `EcommerceFormatConverter::buildPurchaseEvent()` now supports `posthog` provider alongside `ga4` and `meta`
- **PostHog catalog mapping table** — `EventCatalog::allPosthogMappings()` returns complete event→PostHog name mapping, `posthogNameFor()` for single lookups
- **V50SaaSStarterIndustryStandardTest** — Comprehensive 10-section test suite validating event catalog completeness, cross-provider mappings, e-commerce format conversion, config structure, filesystem integrity, JS client exports, and source file metrics

### Changed
- Version bump to 2.50.0
- `EventCatalog::allPosthogNames()` refactored to use shared `posthogNameFor()` method
- `EcommerceFormatConverter::buildPurchaseEvent()` type union expanded to `'ga4'|'meta'|'posthog'`
- JS client version string updated to 2.50.0
- TypeScript definitions version updated to 2.50.0
- All version consistency tests updated to 2.50.0

## [2.49.0] - 2026-08-07

### Added
- **EventAliasResolver** — Maps 100+ common event name aliases/abbreviations to canonical catalog names. Supports CamelCase→snake_case (AddToCart→add_to_cart), abbreviations (signup→sign_up, addtocart→add_to_cart, pageview→page_view), PostHog convention stripping ($signup→sign_up), Plausible variants, custom config aliases. Runtime add/remove alias management, batch resolution, reverse lookup (getAliasesFor). Config-driven via `zeroboiler.analytics.aliases`.
- **EventCacheService** — High-performance L1 (in-memory) + L2 (Laravel cache) layered event lookup cache. Caches catalog entries, event names, total counts, GA4↔Meta format conversions. LRU eviction policy, configurable TTL, warmUp() for pre-loading, flush/flushMemory, detailed stats (hits, misses, hit_rate). Config-driven via `zeroboiler.analytics.event_cache`.
- **AnalyticsManager::resolveEventName()** — Convenience method for alias resolution via the Facade
- **AnalyticsManager::trackWithAlias()** — Track events using potentially aliased names (resolves then dispatches)
- **Facade proxy methods** — `resolveEventName()`, `trackWithAlias()` added to Analytics facade
- **6 new AnalyticsConfig accessors** — aliases(), eventCacheEnabled(), eventCacheMemoryMaxItems(), eventCacheMemoryTtl(), eventCacheTtl(), eventCachePrefix()
- **2 new config sections** — `aliases` (custom event name mappings), `event_cache` (L1/L2 cache configuration with env vars)
- **V49EventAliasResolverCacheTest** — 50+ test cases covering EventAliasResolver (resolution, alias management, batch, config, PostHog, CamelCase), EventCacheService (memory cache, L2, warmUp, flush, stats, ecommerce caching, resolve-and-get), version consistency, config integrity, architecture validation

### Changed
- Version bump to 2.49.0
- AnalyticsManager::version() returns '2.49.0'
- Composer version updated to 2.49.0
- JS client version string updated to 2.49.0
- TypeScript definitions version updated to 2.49.0
- All 38+ controller endpoint version strings updated to 2.49.0
- EventSourceTagger::_version updated to 2.49.0
- EventForwardingService version strings updated to 2.49.0
- AnalyticsEventRouter version string updated to 2.49.0
- All version consistency tests updated to 2.49.0
- Total source files: 191 (was 189)
- Total test files: 93 (was 92)
- Total config sections: 44+ (was 42+)

## [2.42.0] - 2026-08-07

### Added
- **V42SaaSStarterFinalTest** — 45+ production-readiness test cases covering full event catalog integrity (68 events, typed classes, no CustomEvent, no duplicates, GA4/Meta mapping coverage), cross-provider mapping completeness (PostHog, Plausible, GA4→Meta), EcommerceFormatConverter bidirectional (GA4↔Meta item format), ConsentLogService GDPR purposes (4 purposes, necessary always granted), source file counts (183 src, 87 tests, 2400+ JS LOC), config section coverage (40+ sections), ServiceProvider binding count (50+), middleware/pipeline class counts, architecture validation (AnalyticsManager final, DTOs readonly, all trackers implement TrackerInterface), PHP 8.5 declare(strict_types=1) compliance across all source files, README documentation completeness, JS client feature parity
- **Comprehensive README update** — All stale numbers corrected: API endpoints 50+ (was 26), JS client ~2500 LOC (was ~1200), config sections 40+ (was 22), source files 183 (was 166), test files 87 (was 86), AnalyticsConfig typed methods 100+ (was 90+)
- **Event Catalog Reference complete** — All 35 SaaS events now documented in README reference table (16 were previously missing: AccountActivatedEvent, AccountDeactivatedEvent, PasswordChangedEvent, PasswordResetEvent, ProfileUpdatedEvent, EmailVerifiedEvent, TeamCreatedEvent, TeamMemberJoinedEvent, TeamMemberRemovedEvent, RoleChangedEvent, PaymentFailedEvent, PaymentSucceededEvent, PaymentMethodAddedEvent, InvoiceGeneratedEvent, CreditAppliedEvent, InviteSentEvent, IntegrationConnectedEvent, SubscriptionRenewalEvent)
- **GDPR features documented** — ConsentLogService and consent purposes feature added to Identity & GDPR section, Inertia props consent purposes documented
- **Health response version updated** — README example JSON now shows v2.42.0

### Changed
- Version bump to 2.42.0
- AnalyticsManager::version() returns '2.42.0' (was '2.41.0')
- Composer version updated to 2.42.0
- JS client version string updated to 2.42.0
- TypeScript definitions version updated to 2.42.0
- EventSourceTagger::_version updated to 2.42.0
- Controller version strings updated to 2.42.0 on all endpoints (26 occurrences)
- README architecture section updated (50+ API endpoints, 183 source files, 87 test files)
- README SaaS Lifecycle Events table header updated (35 events)
- README upgrade guide: v2.42.0 section added

## [2.41.0] - 2026-08-07

### Fixed
- Removed deprecated `setAccessible(true)` calls in test files (PHP 8.5 compliance)

### Added
- **TypeScript type definitions** — `resources/js/analytics.d.ts` with full IntelliSense/auto-complete support for all 50+ exported functions. Covers ZbAnalyticsConfig, ConsentSignals, TrackLinksConfig, DeviceContext, AutoTrackConfig, PerformanceConfig, AnalyticsEvent, EventCatalog, Ga4Item, EcommerceData, PromotionData, ScreenViewOptions, SessionState, and all exported function signatures. Extends `@inertiajs/core` PageProps interface with `zbAnalytics` property.
- **`flushPendingOnUnload()`** — Uses `navigator.sendBeacon()` to reliably flush batched events on page unload/navigate-away. Prevents data loss when users close tabs or navigate during the 5-second flush interval. Automatically registered in `init()` and cleaned up in `destroy()`.
- **AnalyticsOverviewCommand feature list updated** — Now includes all 40+ features including TypeScript types, sendBeacon unload flush, and event replay queue.
- **V35TypeScriptClientUpgradeTest** — 30+ new test cases covering TypeScript type definitions file existence and content, JS client version consistency (2.35.0), sendBeacon unload flush implementation, version string alignment (manager, composer, JS, source tagger), event catalog integrity (52 events), all config sections, all PHP source files, test file count, and no stale version references.

### Changed
- Version bump to 2.35.0
- AnalyticsManager::version() returns '2.35.0' (was '2.34.0')
- Composer version updated to 2.35.0
- JS client version string updated to 2.35.0 (was 2.30.0 — fixed stale version)
- Controller version strings updated to 2.35.0 on all endpoints (29 occurrences)
- EventSourceTagger::_version updated to 2.35.0
- All test version assertions updated to 2.35.0 (27 occurrences across 15 test files)

## [2.34.0] - 2026-08-07

### Added
- Total event count: 52 (was 49) — 12 e-commerce + 19 SaaS + 21 engagement
- Total config sections: 36 (was 31)
- AnalyticsConfig summary() now includes referral, broadcast (extended), retention_policy (extended) sections
- **TenantIsolationService** — Multi-tenant analytics data isolation for B2B SaaS. Automatic tenant ID resolution from authenticated user attribute, request header (X-Tenant-ID), subdomain, or session. Per-tenant config overrides (disabled events, analytics enabled toggle), per-tenant rate limiting (events per hour), tenant context propagation to all events. Config-driven via `zeroboiler.analytics.tenant`.
- **DataRetentionPolicyService** — GDPR-compliant data retention management. Per-category retention periods (engagement: 30d, SaaS: 90d, ecommerce: 365d), PII category tracking, automatic event expiry checking, configurable auto-expire, retention clamping (1 day min, 10 year max), retention recording and summary reporting. Config-driven via `zeroboiler.analytics.retention`.
- **AnalyticsGateService** — Feature-flag-style analytics access control for tiered SaaS plans. 12 features (events, pageviews, ecommerce, cohorts, funnels, predictions, export, broadcast, alerts, profile, attribution, multi_tenant) with dependency enforcement. 4 plan tiers (Free, Starter, Pro, Enterprise) with per-feature enablement. Per-user and per-tenant overrides with cache-backed resolution. Plan auto-detection from user model attribute. Config-driven via `zeroboiler.analytics.gate`.
- **7 new API endpoints** — `GET /api/analytics/broadcast` (broadcast channel info), `GET /api/analytics/tenant` (tenant isolation status + rate limit), `POST /api/analytics/tenant/config` (per-tenant config update, auth), `GET /api/analytics/retention` (retention policy summary), `GET /api/analytics/gate` (feature gate status for current user), `GET /api/analytics/gate/definitions` (feature + plan tier definitions for client-side).
- **15 new AnalyticsConfig accessors** — broadcastEnabled(), broadcastChannelPrefix(), broadcastPrivateChannels(), broadcastValueThreshold(), tenantEnabled(), tenantResolutionStrategy(), tenantHeader(), tenantEventsPerHour(), retentionPolicyEnabled(), retentionPolicyAutoExpire(), retentionPolicyPiiCategories(), gateEnabled(), gateDefaultPlan(), gatePlanAttribute(). Summary expanded to 31 sections (was 27).
- **4 new service bindings** in AnalyticsServiceProvider: EventBroadcasterService, TenantIsolationService, DataRetentionPolicyService, AnalyticsGateService.
- **V30EnterpriseFeaturesTest** — 50+ new test cases covering EventBroadcasterService (6 tests: disabled default, enabled config, no broadcast when disabled, value threshold filter, critical events always broadcast, channel naming), TenantIsolationService (7 tests: disabled default, enabled config, event enrichment passthrough, set/get config, reset config, shouldTrack with disabled events, blocks disabled events), DataRetentionPolicyService (8 tests: disabled default, allows all when disabled, enabled with defaults, expiry date, period clamping, PII category, summary, record event), AnalyticsGateService (13 tests: disabled default, enabled with default plan, global override, pro plan features, enterprise plan features, unknown feature, event-by-category, available features, feature definitions, plan tiers, user override, summary), integration checks (5 tests: all services final, strict types, files exist, provider bindings, routes registered, config accessors).

### Changed
- Version bump to 2.30.0
- AnalyticsManager::version() returns '2.30.0' (was '2.29.0')
- Controller version strings updated to 2.30.0 on all endpoints
- Composer version updated to 2.30.0
- JS client version string updated to 2.30.0
- Total service count: 42+ services registered in ServiceProvider (was 38+)
- Total API endpoints: 37 public + 11 authenticated (was 31 + 10)
- Total config sections: 31 (was 27)
- Total PHP source files: 153 (was 149)

## [2.28.0] - 2026-08-06

### Added
- **LifecycleEventMapper** — Config-driven lifecycle event mapping service that automatically maps Laravel auth events, subscription lifecycle events, trial events, feature usage, e-commerce, and engagement events to typed ZeroBoiler analytics events. Supports 15 built-in mappings across 6 lifecycle categories (auth, subscription, trial, feature, ecommerce, engagement) with priority ordering, conditional filtering, custom param extractors, and config-driven enable/disable toggles. Custom mappings can be merged with or replace defaults.
- **EventCorrelationService** — Event pattern detection and predictive analytics service. Detects frequent event sequences (n-gram patterns), calculates transition probabilities, predicts next events, computes conversion rates for arbitrary event sequences, builds co-occurrence matrices, and tracks user journeys. All analysis is performed in-memory with optional cache persistence. Useful for identifying common user flows, optimizing conversion paths, and proactive analytics.
- **5 new API endpoints** — `GET /api/analytics/lifecycle` (lifecycle mapping configuration), `GET /api/analytics/correlation/patterns` (frequent event patterns), `GET /api/analytics/correlation/transitions` (top event transitions), `GET /api/analytics/correlation/predict` (next-event prediction), `GET /api/analytics/correlation/summary` (correlation analysis summary).
- **2 new config sections** — `lifecycle` (enabled, override_defaults, events toggles, custom_mappings) and `correlation` (enabled, cache settings, max_pattern_length, max_journeys_per_user).
- **11 new AnalyticsConfig accessors** — lifecycleEnabled(), lifecycleOverrideDefaults(), lifecycleEvents(), lifecycleCustomMappings(), correlationEnabled(), correlationCacheEnabled(), correlationCacheTtl(), correlationMaxPatternLength(), correlationMaxJourneysPerUser(). summary() now includes 24 sections (was 22).
- **2 new service bindings** in AnalyticsServiceProvider: LifecycleEventMapper, EventCorrelationService.
- **V28LifecycleCorrelationTest** — 30+ new test cases covering LifecycleEventMapper (construction, config, toggles, custom mappings, override_defaults, summary, registration), EventCorrelationService (recording, journeys, transitions, pattern detection, conversion rates, prediction, correlation matrix, clear, cache), and version/config consistency.

### Changed
- Version bump to 2.28.0
- AnalyticsManager::version() returns '2.28.0' (was '2.27.0')
- Controller version strings updated to 2.28.0 on lifecycle and correlation endpoints
- Total service count: 35+ services registered in ServiceProvider
- Total API endpoints: 28 public + 10 authenticated
- Total config sections: 24 (was 22)

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
