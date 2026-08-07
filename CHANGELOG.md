# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

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
- **SaaS Journey Milestone Tracker** — Multi-step journey tracking service (`SaaSJourneyService`) with configurable journeys (acquisition, trial, expansion, activation). Records milestone hits via cache persistence, dispatches step events on each milestone and completion events with full timing metadata (duration, step-level timings). Registered as singleton in ServiceProvider. 4 API endpoints: `POST /api/analytics/journeys/{name}/milestone`, `GET /api/analytics/journeys/{name}/progress`, `GET /api/analytics/journeys`, `DELETE /api/analytics/journeys/{name}`.
- **JS Journey Client** — 4 new exported functions: `trackJourneyMilestone()`, `getJourneyProgress()`, `getAllJourneys()`, `resetJourneyProgress()`. Full TypeScript definitions in `analytics.d.ts`.
- **Analytics Data Anonymization Service** — GDPR-compliant PII masking (`AnalyticsAnonymizationService`) with HMAC-SHA256 deterministic ID anonymization, type-aware field value masking (email, phone, IP, UUID, general string). Config-driven field rules: global fields, per-event rules (`event_rules`), per-category rules (`category_rules`). Supports wildcard field matching (`user_*`, `*_email`). Includes `auditTrail()` for compliance auditing. Registered as singleton in ServiceProvider.
- **Config expansion** — New `journeys` section (enabled, cache_ttl, definitions) and `anonymization` section (enabled, salt, global_fields, event_rules, category_rules) in `config/zeroboiler.php`.
- **Tests** — `SaaSJourneyServiceTest` (12 test cases: milestone recording, journey completion, idempotency, invalid inputs, progress tracking, reset, registration, disabled state, all-progress) and `AnalyticsAnonymizationServiceTest` (17 test cases: ID anonymization, value masking, type-aware anonymization, param anonymization, event/category rules, audit trail, disabled state, salt sensitivity).

## [2.58.0] - 2026-08-07

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
